#!/usr/bin/env bash
# Copia de seguridad de Social Scheduler: volcado de la BD + copia de storage/app, ambos CIFRADOS,
# con copia opcional a un destino externo (A-03).
#
#   bash deploy/backup/backup.sh             # volcado + media
#   bash deploy/backup/backup.sh --db-only   # solo la BD (lo usa deploy.sh antes de migrate)
#   bash deploy/backup/backup.sh --dry-run   # lista los pasos sin ejecutar nada
#
# Variables (en el entorno; la contrasena de la BD se lee de .env y nunca se imprime):
#   APP_DIR              raiz de la app (por defecto: dos niveles por encima de este script)
#   ENV_FILE             .env del que leer DB_* (por defecto: $APP_DIR/.env)
#   BACKUP_DIR           carpeta local de respaldos (por defecto: $APP_DIR/storage/backups; fuera de storage/app)
#   BACKUP_PASSFILE      OBLIGATORIO: fichero (chmod 600, fuera del repo) con la frase de cifrado
#   BACKUP_DEST          destino externo opcional: ruta montada, usuario@host:ruta (rsync) o rclone:remoto:ruta
#   BACKUP_KEEP_DAYS     dias que se conservan los respaldos locales (por defecto 14; 0 = no borrar)
#   MYSQLDUMP_BIN        binario mysqldump (por defecto: mysqldump)
# El APP_KEY NO se incluye: guardalo en un gestor de secretos (ver deploy/backup/README.md).
set -euo pipefail
umask 077

DB_ONLY=0
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --db-only) DB_ONLY=1 ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/storage/backups}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DB_FILE="db-$STAMP.sql.gz.enc"
MEDIA_FILE="media-$STAMP.tar.gz.enc"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] Pasos que se ejecutarian:"
    echo "   1. leer DB_* de $ENV_FILE (sin imprimirlos)"
    echo "   2. $MYSQLDUMP_BIN --single-transaction | gzip | openssl enc (aes-256-cbc, pbkdf2) -> $BACKUP_DIR/$DB_FILE"
    [ "$DB_ONLY" -eq 1 ] || echo "   3. tar storage/app | gzip | openssl enc -> $BACKUP_DIR/$MEDIA_FILE"
    echo "   4. escribir $BACKUP_DIR/last-backup (marca de frescura para app:backup-verify)"
    [ -z "${BACKUP_DEST:-}" ] || echo "   5. copiar a BACKUP_DEST"
    echo "   6. borrar respaldos locales con mas de $BACKUP_KEEP_DAYS dias"
    echo "[dry-run] Nada se ha modificado."
    exit 0
fi

if [ -z "${BACKUP_PASSFILE:-}" ] || [ ! -r "$BACKUP_PASSFILE" ] || [ ! -s "$BACKUP_PASSFILE" ]; then
    echo "!! BACKUP_PASSFILE debe apuntar a un fichero legible con la frase de cifrado." >&2
    exit 2
fi
for bin in "$MYSQLDUMP_BIN" openssl gzip tar; do
    command -v "$bin" >/dev/null 2>&1 || { echo "!! Falta el binario: $bin" >&2; exit 2; }
done
[ -r "$ENV_FILE" ] || { echo "!! No se puede leer $ENV_FILE" >&2; exit 2; }

# Git Bash en Windows: openssl nativo no entiende rutas /c/...
PASS_PATH="$BACKUP_PASSFILE"
command -v cygpath >/dev/null 2>&1 && PASS_PATH="$(cygpath -m "$BACKUP_PASSFILE")"

# Lee una clave de .env sin ejecutarlo (quita comillas y CR).
env_get() {
    local value
    value="$(grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '\r' || true)"
    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

case "$(env_get DB_CONNECTION)" in
    mysql|mariadb) ;;
    *) echo "!! backup.sh solo soporta DB_CONNECTION=mysql|mariadb." >&2; exit 2 ;;
esac

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; rm -f "$BACKUP_DIR"/*.part' EXIT
# La contrasena viaja en un fichero 600 temporal, no en la linea de comandos (se veria en `ps`).
{
    echo "[client]"
    echo "user=\"$(env_get DB_USERNAME)\""
    echo "password=\"$(env_get DB_PASSWORD)\""
    echo "host=\"$(env_get DB_HOST)\""
    echo "port=\"$(env_get DB_PORT)\""
} > "$WORK/my.cnf"

mkdir -p "$BACKUP_DIR"
encrypt() { openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASS_PATH"; }

echo "-> volcado de la BD -> $DB_FILE"
"$MYSQLDUMP_BIN" --defaults-extra-file="$WORK/my.cnf" --single-transaction --quick --routines --no-tablespaces \
    "$(env_get DB_DATABASE)" | gzip -c | encrypt > "$BACKUP_DIR/$DB_FILE.part"
mv "$BACKUP_DIR/$DB_FILE.part" "$BACKUP_DIR/$DB_FILE"

if [ "$DB_ONLY" -eq 0 ]; then
    echo "-> copia de storage/app -> $MEDIA_FILE"
    tar -C "$APP_DIR/storage/app" -cf - . | gzip -c | encrypt > "$BACKUP_DIR/$MEDIA_FILE.part"
    mv "$BACKUP_DIR/$MEDIA_FILE.part" "$BACKUP_DIR/$MEDIA_FILE"
fi

# Marca de frescura: solo se escribe cuando todo lo anterior ha ido bien.
{
    echo "timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "db=$DB_FILE"
    [ "$DB_ONLY" -eq 1 ] || echo "media=$MEDIA_FILE"
} > "$BACKUP_DIR/last-backup.tmp"
mv "$BACKUP_DIR/last-backup.tmp" "$BACKUP_DIR/last-backup"

if [ -n "${BACKUP_DEST:-}" ]; then
    echo "-> copia al destino externo"
    files=("$BACKUP_DIR/$DB_FILE" "$BACKUP_DIR/last-backup")
    [ "$DB_ONLY" -eq 1 ] || files+=("$BACKUP_DIR/$MEDIA_FILE")
    case "$BACKUP_DEST" in
        rclone:*) rclone copy "${files[@]}" "${BACKUP_DEST#rclone:}" ;;
        *:*) rsync -a "${files[@]}" "$BACKUP_DEST/" ;;
        *) mkdir -p "$BACKUP_DEST"; cp -p "${files[@]}" "$BACKUP_DEST/" ;;
    esac
fi

if [ "$BACKUP_KEEP_DAYS" -gt 0 ]; then
    # Rotacion local (y del destino si es una ruta); la del almacenamiento remoto va por su ciclo de vida.
    find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.enc' -o -name 'media-*.enc' \) -mtime "+$BACKUP_KEEP_DAYS" -delete
    case "${BACKUP_DEST:-}" in
        ""|rclone:*|*:*) ;;
        *) find "$BACKUP_DEST" -maxdepth 1 -type f \( -name 'db-*.enc' -o -name 'media-*.enc' \) -mtime "+$BACKUP_KEEP_DAYS" -delete ;;
    esac
fi

echo "== Respaldo completado ($STAMP) =="
