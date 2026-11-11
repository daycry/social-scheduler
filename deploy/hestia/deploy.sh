#!/usr/bin/env bash
# Despliegue repetible de Social Scheduler en HestiaCP (un solo comando), con parada corta (A-10).
#
#   bash deploy/hestia/deploy.sh                    # despliega
#   bash deploy/hestia/deploy.sh --dry-run          # lista los pasos sin ejecutar nada
#   bash deploy/hestia/deploy.sh --force-unverified # despliega aunque el commit no tenga la CI verde (o no haya gh)
#   bash deploy/hestia/deploy.sh rollback [--dry-run]  # vuelve a la revision, vendor y build anteriores
#
# Variables (todas opcionales):
#   APP_DIR          raiz de la app (por defecto: dos niveles por encima de este script)
#   BRANCH           rama a desplegar (por defecto: main)
#   PHP_BIN          binario PHP CLI (por defecto: php; p. ej. php8.4). Debe coincidir con PHP_BIN de la unidad systemd
#   COMPOSER / NPM   comandos composer y npm (por defecto: composer, npm)
#   CI_CHECK         check de GitHub que debe estar en verde (por defecto: tests, el job de .github/workflows/ci.yml)
#   BACKUP_PASSFILE  frase de cifrado del volcado previo a migrate (obligatoria salvo SKIP_BACKUP=1; ver deploy/backup/README.md)
#   SKIP_BACKUP      1 = no hacer el volcado previo (solo en un primer despliegue sin datos)
#   SKIP_BUILD       1 = no compilar assets (solo si public/build/manifest.json llega por otro medio)
#   WORKER_UNITS     unidades systemd a reiniciar tras queue:restart (p. ej. scheduler-worker@media scheduler-studio)
#
# Orden: fetch -> comprobar CI -> COMPILAR en una carpeta aparte (la app sigue sirviendo el codigo actual) ->
# artisan down -> volcado de la BD -> codigo nuevo y cambio de vendor/build (mv, segundos) -> migrate -> caches -> up.
# Solo la ventana down -> up es mantenimiento. Si algo falla antes de cambiar el codigo se vuelve a levantar;
# si falla despues se queda en mantenimiento y se indica `deploy.sh rollback`.
set -eEuo pipefail

DRY_RUN=0
FORCE_UNVERIFIED=0
MODE=deploy
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --force-unverified) FORCE_UNVERIFIED=1 ;;
        rollback) MODE=rollback ;;
        -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"
CI_CHECK="${CI_CHECK:-tests}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
SKIP_BUILD="${SKIP_BUILD:-0}"
MANIFEST="public/build/manifest.json"
# storage/logs ya esta ignorado por git: aqui viven la compilacion en curso y la version anterior (mismo disco: mv instantaneo).
STATE_DIR="$APP_DIR/storage/logs/.deploy"
STAGE_DIR="$STATE_DIR/stage"
PREV_DIR="$STATE_DIR/previous"
STATE_FILE="$STATE_DIR/last-deploy"
# Composer corre con el mismo PHP que la app (su shebang usaria el php por defecto del sistema).
COMPOSER_RUN="$PHP_BIN \$(command -v $COMPOSER)"
TARGET_REV="origin/$BRANCH"
CODE_CHANGED=0
DOWN=0

# ---------------------------------------------------------------- pasos (cada uno: "etiqueta|orden")
# check_ci, build_stage, swap_build, record_deploy y rollback_code son funciones de este script (abajo).
if [ "$MODE" = "deploy" ]; then
    STEPS=(
        "fetch|git fetch --prune origin"
        "ci|check_ci"
        "build|build_stage"
        "down|$PHP_BIN artisan down --retry=30 --refresh=15"
    )
    if [ "$SKIP_BACKUP" != "1" ]; then
        STEPS+=("dump|bash $APP_DIR/deploy/backup/backup.sh --db-only")
    fi
    STEPS+=(
        "code|git checkout $BRANCH && git merge --ff-only \$TARGET_REV && record_deploy"
        "swap|swap_build"
        "discover|$PHP_BIN artisan package:discover --ansi"
        "migrate|$PHP_BIN artisan migrate --force"
        "cache|$PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache && $PHP_BIN artisan event:cache"
        "link|$PHP_BIN artisan storage:link"
        "queue|$PHP_BIN artisan queue:restart"
        "up|$PHP_BIN artisan up"
    )
    SMOKE=(
        "test -f $MANIFEST"
        "$PHP_BIN artisan app:health --skip-scheduler"
    )
else
    STEPS=(
        "down|$PHP_BIN artisan down --retry=30 --refresh=15"
        "code|rollback_code"
        "discover|$PHP_BIN artisan package:discover --ansi"
        "cache|$PHP_BIN artisan optimize:clear && $PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache && $PHP_BIN artisan event:cache"
        "queue|$PHP_BIN artisan queue:restart"
        "up|$PHP_BIN artisan up"
    )
    SMOKE=("$PHP_BIN artisan app:health --skip-scheduler")
fi

print_steps() {
    local i=1 step
    for step in "${STEPS[@]}"; do
        printf '  %2d. [%s] %s\n' "$i" "${step%%|*}" "${step#*|}"
        i=$((i + 1))
    done
    if [ -n "${WORKER_UNITS:-}" ]; then
        printf '  %2d. [workers] sudo systemctl restart %s\n' "$i" "$WORKER_UNITS"
        i=$((i + 1))
    fi
    for step in "${SMOKE[@]}"; do
        printf '  %2d. [smoke] %s\n' "$i" "$step"
        i=$((i + 1))
    done
}

echo "== Social Scheduler $MODE (rama: $BRANCH, dir: $APP_DIR) =="

if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] Pasos que se ejecutarian:"
    print_steps
    if [ "$MODE" = "deploy" ]; then
        echo "[dry-run] La app solo esta en mantenimiento entre [down] y [up]; compilar ([build]) y comprobar la CI ([ci]) van antes."
    fi
    echo "[dry-run] Nada se ha modificado."
    exit 0
fi

cd "$APP_DIR"

# ---------------------------------------------------------------- funciones de los pasos
# El commit a desplegar debe tener el check `$CI_CHECK` en verde (A-10: antes se desplegaba main sin mirar la CI).
check_ci() {
    local state
    if [ "$FORCE_UNVERIFIED" -eq 1 ]; then
        echo "!! --force-unverified: se despliega $TARGET_REV sin comprobar la CI." >&2
        return 0
    fi
    state="$(gh api "repos/{owner}/{repo}/commits/$TARGET_REV/check-runs?per_page=100" \
        --jq "[.check_runs[] | select(.name == \"$CI_CHECK\") | (.conclusion // \"pendiente\")] | if length == 0 then \"sin-check\" else .[0] end")" \
        || { echo "!! No se pudo consultar la CI con gh (usa --force-unverified si es deliberado)." >&2; return 1; }
    if [ "$state" != "success" ]; then
        echo "!! El commit $TARGET_REV no tiene la CI en verde (check '$CI_CHECK': $state). Se cancela el despliegue." >&2
        echo "!! Si es una urgencia deliberada: bash deploy/hestia/deploy.sh --force-unverified" >&2
        return 1
    fi
    echo "   CI en verde para $TARGET_REV"
}

# Compila el commit objetivo en una carpeta aparte, con la app todavia sirviendo el codigo actual.
build_stage() {
    local stage="$STAGE_DIR/tree"
    rm -rf "$STAGE_DIR"
    mkdir -p "$stage"
    git archive "$TARGET_REV" | tar -x -C "$stage"
    # Solo para que el build encuentre la configuracion; es un enlace, no una copia del secreto.
    ln -s "$APP_DIR/.env" "$stage/.env"
    (
        cd "$stage"
        # --no-scripts: package:discover se ejecuta tras el cambio, ya con el codigo definitivo.
        eval "$COMPOSER_RUN install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts"
        if [ "$SKIP_BUILD" != "1" ]; then
            $NPM ci
            $NPM run build
        fi
    )
    rm -f "$stage/.env"
    if [ "$SKIP_BUILD" = "1" ]; then
        test -f "$APP_DIR/$MANIFEST"
    else
        test -f "$stage/$MANIFEST"
    fi
}

# Guarda de donde venimos (para `rollback`) una vez que el codigo ya cambio.
record_deploy() {
    mkdir -p "$STATE_DIR"
    {
        echo "prev_rev=$PREV_REV"
        echo "new_rev=$(git rev-parse HEAD)"
        echo "date=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "dump=$(sed -n 's/^db=//p' "${BACKUP_DIR:-$APP_DIR/storage/backups}/last-backup" 2>/dev/null | tail -n1 || true)"
    } > "$STATE_FILE"
    CODE_CHANGED=1
}

# Intercambio de vendor y public/build: dos `mv` en el mismo disco; el anterior queda en $PREV_DIR para rollback.
swap_build() {
    rm -rf "$PREV_DIR"
    mkdir -p "$PREV_DIR"
    [ ! -d vendor ] || mv vendor "$PREV_DIR/vendor"
    mv "$STAGE_DIR/tree/vendor" vendor
    if [ "$SKIP_BUILD" != "1" ]; then
        [ ! -d public/build ] || mv public/build "$PREV_DIR/build"
        mv "$STAGE_DIR/tree/public/build" public/build
    fi
    rm -rf "$STAGE_DIR"
}

# Vuelve a la revision anterior y recupera el vendor y el build que acabamos de retirar.
rollback_code() {
    local prev_rev
    [ -r "$STATE_FILE" ] || { echo "!! No hay $STATE_FILE: no hay despliegue previo que deshacer." >&2; return 1; }
    prev_rev="$(sed -n 's/^prev_rev=//p' "$STATE_FILE")"
    [ -n "$prev_rev" ] && [ "$prev_rev" != "desconocido" ] || { echo "!! La revision anterior no esta registrada." >&2; return 1; }
    echo "   volviendo a $prev_rev (git reset --keep: no pisa cambios locales)"
    git reset --keep "$prev_rev"
    if [ -d "$PREV_DIR/vendor" ]; then rm -rf vendor; mv "$PREV_DIR/vendor" vendor; fi
    if [ -d "$PREV_DIR/build" ]; then rm -rf public/build; mv "$PREV_DIR/build" public/build; fi
    echo "   La BD NO se revierte sola. Si el despliegue anadio migraciones incompatibles, restaura el volcado previo:" >&2
    echo "   Volcado previo: $(sed -n 's/^dump=//p' "$STATE_FILE") (en la carpeta de respaldos; restore.sh sobre BD vacia o con --force; pierde lo escrito desde el despliegue)." >&2
}

# ---------------------------------------------------------------- preflight (antes de tocar nada)
preflight() {
    local missing=0 bin
    for bin in git tar "$PHP_BIN" "$COMPOSER"; do
        command -v "$bin" >/dev/null 2>&1 || { echo "!! Falta el binario: $bin" >&2; missing=1; }
    done
    if [ "$MODE" = "deploy" ]; then
        if [ "$SKIP_BUILD" != "1" ]; then
            command -v "$NPM" >/dev/null 2>&1 || { echo "!! Falta el binario: $NPM (o usa SKIP_BUILD=1)" >&2; missing=1; }
        else
            echo "!! SKIP_BUILD=1: solo se comprueba que exista $MANIFEST, no que corresponda a este commit." >&2
        fi
        if [ "$FORCE_UNVERIFIED" -ne 1 ]; then
            command -v gh >/dev/null 2>&1 || { echo "!! Falta gh para comprobar la CI (o usa --force-unverified)." >&2; missing=1; }
        fi
        if [ "$SKIP_BACKUP" != "1" ]; then
            [ -n "${BACKUP_PASSFILE:-}" ] && [ -r "${BACKUP_PASSFILE:-}" ] \
                || { echo "!! BACKUP_PASSFILE no apunta a un fichero legible: sin copia previa no se migra (SKIP_BACKUP=1 para omitirla)." >&2; missing=1; }
        else
            echo "!! SKIP_BACKUP=1: no habra volcado previo a migrate." >&2
        fi
    fi
    [ "$missing" -eq 0 ] || exit 2
}
preflight

PREV_REV="$(git rev-parse HEAD 2>/dev/null || echo desconocido)"

on_failure() {
    local code=$?
    trap - ERR
    echo "!! $MODE fallido (exit $code) en: ${CURRENT_STEP:-?}" >&2
    if [ "$DOWN" -eq 1 ] && [ "$CODE_CHANGED" -eq 0 ]; then
        echo "!! El codigo no llego a cambiar: se vuelve a levantar la app." >&2
        "$PHP_BIN" artisan up >/dev/null 2>&1 || true
    elif [ "$DOWN" -eq 1 ] && [ "$MODE" = "deploy" ]; then
        echo "!! La app queda en MANTENIMIENTO (codigo nuevo con vendor/esquema posiblemente a medias)." >&2
        echo "!! Corrige y reejecuta el deploy, o vuelve atras: bash deploy/hestia/deploy.sh rollback" >&2
        echo "!! Las migraciones ya aplicadas NO se revierten solas (revisa migrate:status; el volcado previo esta en storage/backups)." >&2
    elif [ "$DOWN" -eq 1 ]; then
        echo "!! El rollback no termino: la app sigue en MANTENIMIENTO. Revisa el paso fallido y repite el rollback." >&2
    fi
    if [ "$CODE_CHANGED" -eq 1 ]; then
        # Los workers deben cargar siempre el codigo vigente.
        "$PHP_BIN" artisan queue:restart >/dev/null 2>&1 || true
    fi
    exit "$code"
}
trap on_failure ERR

for step in "${STEPS[@]}"; do
    tag="${step%%|*}"
    cmd="${step#*|}"
    CURRENT_STEP="[$tag] $cmd"
    echo "-> $CURRENT_STEP"
    case "$tag" in
        fetch) eval "$cmd"; TARGET_REV="$(git rev-parse "origin/$BRANCH")" ;;
        code) [ "$MODE" = "rollback" ] && CODE_CHANGED=1; eval "$cmd" ;;
        *) eval "$cmd" ;;
    esac
    case "$tag" in
        down) DOWN=1 ;;
        up) DOWN=0 ;;
    esac
done

if [ -n "${WORKER_UNITS:-}" ]; then
    CURRENT_STEP="systemctl restart"
    echo "-> sudo systemctl restart $WORKER_UNITS"
    # shellcheck disable=SC2086
    sudo systemctl restart $WORKER_UNITS || echo "!! No se pudieron reiniciar los workers: revisalos a mano." >&2
fi

# Smoke tras reiniciar workers: un fallo aqui falla el despliegue, pero ya no deja nada a medias.
SMOKE_FAILED=0
for step in "${SMOKE[@]}"; do
    echo "-> [smoke] $step"
    if ! eval "$step"; then
        echo "!! Smoke fallido: $step" >&2
        SMOKE_FAILED=1
    fi
done

if [ "$SMOKE_FAILED" -ne 0 ]; then
    echo "== $MODE aplicado pero el smoke ha fallado (app arriba, workers reiniciados): revisar =="
    exit 1
fi

echo "== $MODE completado =="
