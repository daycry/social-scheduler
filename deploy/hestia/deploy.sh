#!/usr/bin/env bash
# Despliegue repetible de Social Scheduler en HestiaCP (un solo comando).
#
#   bash deploy/hestia/deploy.sh            # despliega
#   bash deploy/hestia/deploy.sh --dry-run  # lista los pasos sin ejecutar nada
#
# Variables (todas opcionales):
#   APP_DIR     raiz de la app (por defecto: dos niveles por encima de este script)
#   BRANCH      rama a desplegar (por defecto: main)
#   PHP_BIN     binario PHP (por defecto: php)
#   COMPOSER    comando composer (por defecto: composer)
#   WORKER_UNITS  unidades systemd a reiniciar tras queue:restart (por defecto: vacio)
set -euo pipefail

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER="${COMPOSER:-composer}"

STEPS=(
    "git fetch --prune origin"
    "git checkout $BRANCH"
    "git pull --ff-only origin $BRANCH"
    "$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader"
    "$PHP_BIN artisan down --retry=30 --refresh=15"
    "$PHP_BIN artisan migrate --force"
    "$PHP_BIN artisan config:cache"
    "$PHP_BIN artisan route:cache"
    "$PHP_BIN artisan view:cache"
    "$PHP_BIN artisan event:cache"
    "$PHP_BIN artisan storage:link"
    "$PHP_BIN artisan queue:restart"
    "$PHP_BIN artisan up"
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
    fi
    echo "[dry-run] Nada se ha modificado."
    exit 0
fi

cd "$APP_DIR"

# Si algo falla tras `down`, la app no debe quedarse en mantenimiento.
trap '"$PHP_BIN" artisan up >/dev/null 2>&1 || true' ERR

for step in "${STEPS[@]}"; do
    echo "-> $step"
    eval "$step"
done

if [ -n "${WORKER_UNITS:-}" ]; then
    echo "-> sudo systemctl restart $WORKER_UNITS"
    # shellcheck disable=SC2086
    sudo systemctl restart $WORKER_UNITS
fi

echo "== Deploy completado =="
