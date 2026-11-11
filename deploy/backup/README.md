# Copias de seguridad y restauración (A-03)

Sin respaldos, perder el VPS o la BD borra publicaciones, cuentas y tokens cifrados. Este directorio trae:

| Fichero | Qué hace |
|---|---|
| `backup.sh` | `mysqldump` + copia de `storage/app`, ambos comprimidos y **cifrados** (AES-256-CBC, PBKDF2 de 600000 iteraciones) con una frase que vive fuera del repo; copia opcional a un destino externo; rotación local |
| `restore.sh` | Descifra y restaura sobre una BD **vacía** (con confirmación explícita) y/o `storage/app` |
| `php artisan app:backup-verify` | Falla (exit 1) si el último respaldo falta, está incompleto o tiene más de 26 h (`BACKUP_MAX_AGE_HOURS`) |

## Configuración [VPS]

1. Genera la frase de cifrado **fuera del repo y del disco que respaldas**: `umask 077; openssl rand -base64 48 > ~/.backup-passphrase`. Guarda una copia en el gestor de secretos: sin ella el respaldo no se puede abrir.
2. Elige el destino externo (`BACKUP_DEST`):
   - ruta montada (disco o NFS aparte): `/mnt/backups/scheduler`
   - rsync/SSH: `usuario@host:/ruta` (clave sin contraseña con permiso solo de escritura en esa ruta)
   - rclone (R2, S3, B2…): `rclone:remoto:bucket/scheduler` (credenciales en `~/.config/rclone`, nunca en el script)
3. Cron diario del usuario del dominio (Hestia → Cron), por ejemplo a las 03:10:

   ```bash
   10 3 * * * BACKUP_PASSFILE=$HOME/.backup-passphrase BACKUP_DEST=rclone:r2:scheduler-backups bash /home/USUARIO/web/DOMINIO/app/deploy/backup/backup.sh >> $HOME/backup.log 2>&1
   ```

4. Opcional: `BACKUP_HEALTH=true` en `.env` para que `app:health` avise (WARN) si el respaldo tiene más de 26 h.

Variables del script (`backup.sh --help`): `BACKUP_DIR` (por defecto `storage/backups`, fuera de `storage/app`), `BACKUP_KEEP_DAYS` (14), `MYSQLDUMP_BIN`, `ENV_FILE`. La contraseña de la BD se lee de `.env` y pasa a `mysqldump` por un fichero temporal `600`; los scripts no la imprimen ni la registran.

## Rotación

- **Local:** `backup.sh` borra los `db-*.enc` y `media-*.enc` con más de `BACKUP_KEEP_DAYS` días (14). El destino que es una ruta se poda igual.
- **Remoto (R2/S3/B2/rsync):** no se borra desde el script (una clave de solo escritura no debe poder borrar). Pon una regla de ciclo de vida en el bucket: p. ej. 30 días de diarios; guarda un mensual 12 meses copiándolo a otro prefijo.
- **Integridad:** cada fichero cifrado lleva un `.hmac` (HMAC-SHA256 con una subclave derivada de la frase; CBC solo no autentica). `restore.sh` lo verifica **antes** de descifrar y se niega si no coincide (fichero alterado, corrupto o frase equivocada). Los respaldos anteriores, sin `.hmac` y con 10000 iteraciones, siguen restaurándose con un aviso.
- **Marcas de frescura:** `last-backup` (respaldo completo; es la que mira `app:backup-verify`) se escribe **solo cuando todo, incluida la copia externa, ha ido bien**; si la copia externa falla, `backup.sh` sale con código distinto de 0 y `app:backup-verify` acaba avisando por antigüedad. `backup.sh --db-only` (volcado previo de `deploy.sh`) escribe su propia marca `last-db-backup` y no pisa la del completo.
- Cada respaldo es independiente (volcado completo y copia completa): para restaurar solo hace falta el par `db-*` y `media-*` de la misma marca de tiempo.

## Restaurar (probado con una BD de pruebas)

```bash
export BACKUP_PASSFILE=$HOME/.backup-passphrase
# 1. Trae los ficheros del destino externo (rclone copyto / rsync / cp) a una carpeta local.
# 2. BD vacía (la restauración se niega si tiene tablas; --force para sobrescribir) y el mismo APP_KEY en .env.
bash deploy/backup/restore.sh --db db-AAAAMMDDTHHMMSSZ.sql.gz.enc --media media-AAAAMMDDTHHMMSSZ.tar.gz.enc
php artisan migrate --force && php artisan app:health
```

Para ensayar sin tocar producción: apunta `ENV_FILE` a un `.env` con una BD de pruebas y usa `--media-target /tmp/prueba`. Pide escribir `restaurar` (o `--yes`).

Prueba automática: `tests/Feature/Console/BackupVerifyTest.php` hace el viaje de ida y vuelta (cifrado, marca de frescura, restauración de BD y media) con un `mysqldump`/`mysql` falsos. **La prueba contra MySQL real y el destino externo es del usuario** ([pendientes manuales](../../docs/referencia/pendientes-manuales.md)).

## `APP_KEY` y secretos

El respaldo **no incluye `.env` ni `APP_KEY`**, a propósito: el secreto no debe viajar junto a lo que cifra. Pero la BD guarda tokens de redes, secretos 2FA y credenciales cifrados con `APP_KEY`: **sin la misma `APP_KEY` una restauración deja todo eso ilegible** (habría que reconectar todas las cuentas).

- Guarda `APP_KEY` (y `PASSKEYS_USER_HANDLE_SECRET`, `APP_PREVIOUS_KEYS`, la frase de cifrado del respaldo y las claves de las redes) en un gestor de secretos (Bitwarden, 1Password, un vault) con copia fuera del VPS.
- Al rotar `APP_KEY`, sigue [Rotar `APP_KEY`](../../docs/modulos/despliegue.md#rotar-app_key-app_previous_keys) y guarda la nueva **antes** de desplegarla.
- Comprueba cada trimestre que puedes abrir un respaldo con lo guardado (restauración de ensayo).
