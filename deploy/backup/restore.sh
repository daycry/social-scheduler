#!/usr/bin/env bash
# Restaura un respaldo hecho con backup.sh (A-03). PELIGROSO: sobrescribe la BD y/o storage/app.
#
#   bash deploy/backup/restore.sh --db db-AAAAMMDDTHHMMSSZ.sql.gz.enc [--media media-....tar.gz.enc] [--yes]
#   bash deploy/backup/restore.sh --media media-....tar.gz.enc [--media-target DIR]
#
# Variables: APP_DIR, ENV_FILE, BACKUP_PASSFILE (la misma frase que cifro el respaldo), MYSQL_BIN (por defecto: mysql).
# Por seguridad la BD de destino debe estar VACIA (--force para sobrescribir). Sin --yes pide escribir "restaurar".
# Para probar sin tocar produccion: ENV_FILE con una BD de pruebas y --media-target en una carpeta temporal.
set -euo pipefail
umask 077

DB=""; MEDIA=""; MEDIA_TARGET=""; YES=0; FORCE=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --db) DB="${2:?falta el fichero}"; shift 2 ;;
        --media) MEDIA="${2:?falta el fichero}"; shift 2 ;;
        --media-target) MEDIA_TARGET="${2:?falta la carpeta}"; shift 2 ;;
        --yes) YES=1; shift ;;
        --force) FORCE=1; shift ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $1" >&2; exit 2 ;;
    esac
done
if [ -z "$DB" ] && [ -z "$MEDIA" ]; then echo "Indica --db y/o --media." >&2; exit 2; fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
MEDIA_TARGET="${MEDIA_TARGET:-$APP_DIR/storage/app}"

if [ -z "${BACKUP_PASSFILE:-}" ] || [ ! -r "$BACKUP_PASSFILE" ]; then
    echo "!! BACKUP_PASSFILE debe apuntar a la frase de cifrado." >&2; exit 2
fi
if [ -n "$DB" ] && [ ! -r "$DB" ]; then echo "!! No se puede leer $DB" >&2; exit 2; fi
if [ -n "$MEDIA" ] && [ ! -r "$MEDIA" ]; then echo "!! No se puede leer $MEDIA" >&2; exit 2; fi
[ -r "$ENV_FILE" ] || { echo "!! No se puede leer $ENV_FILE" >&2; exit 2; }

# Git Bash en Windows: openssl nativo no entiende rutas /c/...
PASS_PATH="$BACKUP_PASSFILE"
command -v cygpath >/dev/null 2>&1 && PASS_PATH="$(cygpath -m "$BACKUP_PASSFILE")"

env_get() {
    local value
    value="$(grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '\r' || true)"
    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}
decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$PASS_PATH"; }

echo "Se va a restaurar:"
if [ -n "$DB" ]; then echo "  BD    : $(env_get DB_DATABASE) en $(env_get DB_HOST) <- $DB"; fi
if [ -n "$MEDIA" ]; then echo "  media : $MEDIA_TARGET <- $MEDIA"; fi
if [ "$YES" -ne 1 ]; then
    read -r -p "Escribe 'restaurar' para continuar: " answer
    [ "$answer" = "restaurar" ] || { echo "Cancelado."; exit 1; }
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ -n "$DB" ]; then
    {
        echo "[client]"
        echo "user=\"$(env_get DB_USERNAME)\""
        echo "password=\"$(env_get DB_PASSWORD)\""
        echo "host=\"$(env_get DB_HOST)\""
        echo "port=\"$(env_get DB_PORT)\""
    } > "$WORK/my.cnf"
    mysql_run() { "$MYSQL_BIN" --defaults-extra-file="$WORK/my.cnf" "$@"; }
    tables="$(mysql_run -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "$(env_get DB_DATABASE)")"
    if [ "${tables:-0}" != "0" ] && [ "$FORCE" -ne 1 ]; then
        echo "!! La BD de destino no esta vacia ($tables tablas). Vaciala o usa --force." >&2
        exit 1
    fi
    echo "-> restaurando la BD"
    decrypt < "$DB" | gunzip -c | mysql_run "$(env_get DB_DATABASE)"
fi

if [ -n "$MEDIA" ]; then
    echo "-> restaurando la media en $MEDIA_TARGET"
    mkdir -p "$MEDIA_TARGET"
    decrypt < "$MEDIA" | gunzip -c | tar -C "$MEDIA_TARGET" -xf -
fi

echo "== Restauracion completada. Ejecuta: php artisan migrate --force && php artisan app:health =="
