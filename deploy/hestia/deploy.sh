#!/usr/bin/env bash
# Despliegue repetible de Social Scheduler en HestiaCP (un solo comando).
#
#   bash deploy/hestia/deploy.sh            # despliega
#   bash deploy/hestia/deploy.sh --dry-run  # lista los pasos sin ejecutar nada
#
# Variables (todas opcionales):
#   APP_DIR       raiz de la app (por defecto: dos niveles por encima de este script)
#   BRANCH        rama a desplegar (por defecto: main)
#   PHP_BIN       binario PHP CLI (por defecto: php; p. ej. php8.4). Debe coincidir con PHP_BIN de la unidad systemd
#   COMPOSER      comando composer (por defecto: composer)
#   NPM           comando npm (por defecto: npm)
#   SKIP_BUILD    1 = no compilar assets (solo si public/build/manifest.json llega por otro medio)
#   WORKER_UNITS  unidades systemd a reiniciar tras queue:restart (por defecto: vacio)
#
# Orden: la app entra en mantenimiento ANTES de tocar codigo, vendor o esquema. Si algo falla
# antes de cambiar el codigo se vuelve a levantar; si falla despues, se queda en mantenimiento
# (codigo nuevo sobre esquema/vendor a medias no debe servirse) y se indica como recuperar.
set -euo pipefail

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"
SKIP_BUILD="${SKIP_BUILD:-0}"
MANIFEST="public/build/manifest.json"
# Composer corre con el mismo PHP que la app (su shebang usaria el php por defecto del sistema).
COMPOSER_RUN="$PHP_BIN \$(command -v $COMPOSER)"

STEPS=(
    "$PHP_BIN artisan down --retry=30 --refresh=15"
    "git fetch --prune origin"
    "git checkout $BRANCH"
    "git pull --ff-only origin $BRANCH"
    "$COMPOSER_RUN install --no-dev --no-interaction --prefer-dist --optimize-autoloader"
)
if [ "$SKIP_BUILD" != "1" ]; then
    STEPS+=("$NPM ci" "$NPM run build")
fi
STEPS+=(
    "test -f $MANIFEST"
    "$PHP_BIN artisan migrate --force"
    "$PHP_BIN artisan config:cache"
    "$PHP_BIN artisan route:cache"
    "$PHP_BIN artisan view:cache"
    "$PHP_BIN artisan event:cache"
    "$PHP_BIN artisan storage:link"
    "$PHP_BIN artisan queue:restart"
    "$PHP_BIN artisan up"
)
# Tras `up` (el reinicio de workers va siempre antes del smoke, aunque este avise).
SMOKE=(
    "test -f $MANIFEST"
    "$PHP_BIN artisan app:health --skip-scheduler"
)

echo "== Social Scheduler deploy (rama: $BRANCH, dir: $APP_DIR) =="

if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] Pasos que se ejecutarian:"
    i=1
    for step in "${STEPS[@]}"; do
        printf '  %2d. %s\n' "$i" "$step"
        i=$((i + 1))
    done
    if [ -n "${WORKER_UNITS:-}" ]; then
        printf '  %2d. sudo systemctl restart %s\n' "$i" "$WORKER_UNITS"
        i=$((i + 1))
    fi
    for step in "${SMOKE[@]}"; do
        printf '  %2d. [smoke] %s\n' "$i" "$step"
        i=$((i + 1))
    done
    echo "[dry-run] Nada se ha modificado."
    exit 0
fi

cd "$APP_DIR"

# Un binario que falte se detecta ANTES de entrar en mantenimiento.
preflight() {
    local missing=0 bin
    for bin in git "$PHP_BIN" "$COMPOSER"; do
        command -v "$bin" >/dev/null 2>&1 || { echo "!! Falta el binario: $bin" >&2; missing=1; }
    done
    if [ "$SKIP_BUILD" != "1" ]; then
        command -v "$NPM" >/dev/null 2>&1 || { echo "!! Falta el binario: $NPM (o usa SKIP_BUILD=1)" >&2; missing=1; }
    else
        echo "!! SKIP_BUILD=1: solo se comprueba que exista $MANIFEST, no que corresponda a este commit." >&2
    fi
    [ "$missing" -eq 0 ] || exit 2
}
preflight

PREV_REV="$(git rev-parse HEAD 2>/dev/null || echo desconocido)"
DOWN=0
CODE_CHANGED=0

on_failure() {
    local code=$?
    trap - ERR
    echo "!! Deploy fallido (exit $code) en: ${CURRENT_STEP:-?}" >&2
    # El codigo cambio si HEAD ya no es el de partida (checkout o pull) o el arbol tiene cambios.
    if [ "$(git rev-parse HEAD 2>/dev/null)" != "$PREV_REV" ] || ! git diff --quiet HEAD 2>/dev/null; then
        CODE_CHANGED=1
    fi
    if [ "$DOWN" -eq 1 ] && [ "$CODE_CHANGED" -eq 0 ]; then
        echo "!! El codigo no llego a cambiar: se vuelve a levantar la app." >&2
        "$PHP_BIN" artisan up >/dev/null 2>&1 || true
    elif [ "$DOWN" -eq 1 ]; then
        echo "!! La app queda en MANTENIMIENTO (codigo nuevo con vendor/esquema a medias)." >&2
        echo "!! Corrige y reejecuta el deploy, o vuelve a $PREV_REV sin dejar HEAD suelto:" >&2
        echo "!!   git checkout $BRANCH && git reset --hard $PREV_REV" >&2
        echo "!!   $PHP_BIN \$(command -v $COMPOSER) install --no-dev --optimize-autoloader && $NPM ci && $NPM run build" >&2
        echo "!!   $PHP_BIN artisan optimize:clear && $PHP_BIN artisan optimize && $PHP_BIN artisan up" >&2
        echo "!! Las migraciones ya aplicadas NO se revierten solas (revisa migrate:status)." >&2
    fi
    # Los workers deben cargar siempre el codigo vigente.
    "$PHP_BIN" artisan queue:restart >/dev/null 2>&1 || true
    exit "$code"
}
trap on_failure ERR

for step in "${STEPS[@]}"; do
    CURRENT_STEP="$step"
    echo "-> $step"
    eval "$step"
    case "$step" in
        *"artisan down"*) DOWN=1 ;;
        *"artisan up"*) DOWN=0 ;;
        "git pull"*) CODE_CHANGED=1 ;;
    esac
done

if [ -n "${WORKER_UNITS:-}" ]; then
    CURRENT_STEP="systemctl restart"
    echo "-> sudo systemctl restart $WORKER_UNITS"
    # shellcheck disable=SC2086
    sudo systemctl restart $WORKER_UNITS || echo "!! No se pudieron reiniciar los workers: revisalos a mano." >&2
fi

# Smoke tras reiniciar workers: un fallo aqui falla el deploy, pero ya no deja nada a medias.
SMOKE_FAILED=0
for step in "${SMOKE[@]}"; do
    echo "-> [smoke] $step"
    if ! eval "$step"; then
        echo "!! Smoke fallido: $step" >&2
        SMOKE_FAILED=1
    fi
done

if [ "$SMOKE_FAILED" -ne 0 ]; then
    echo "== Deploy aplicado pero el smoke ha fallado (app arriba, workers reiniciados): revisar =="
    exit 1
fi

echo "== Deploy completado =="
