# Despliegue: HestiaCP, systemd y R2

Fuente de verdad operativa: `deploy/hestia/README.md` y `deploy/systemd/README.md`. Esta página resume y enlaza. Todo lo marcado **[VPS]** requiere acceso root y sigue pendiente (M-04).

## Requisitos del servidor

- HestiaCP con Nginx + PHP-FPM 8.4/8.5 y MariaDB >= 10.6 / MySQL >= 8.0 (`SKIP LOCKED` para varios workers).
- Extensiones PHP: `mbstring intl pdo_mysql bcmath gd curl xml zip` y, para las passkeys, `openssl` y `sodium` (ver [Seguridad de cuenta](#seguridad-de-cuenta-2fa-y-passkeys)). Composer 2 y Node 22.
- `ffprobe` en el PATH de PHP-FPM y de los workers.
- Bucket R2 con CORS (`PUT`, `Content-Type`, exponer `ETag`).

## Pasos

1. Dominio, TLS y BD con los comandos `v-add-web-domain`, `v-add-letsencrypt-domain`, `v-add-database` (plantilla `laravel`, docroot `public/`).
2. Ajustes de Nginx (`deploy/hestia/nginx-overrides.conf`) y PHP-FPM (`upload_max_filesize=64M`, `memory_limit=256M`, `max_execution_time=120`).
3. Primera instalación: clonar, `cp .env.production.example .env`, `chmod 600 .env`, `composer install --no-dev`, `key:generate`, `migrate --force`.
4. Cron de Hestia cada minuto: `cd .../app && php artisan schedule:run`.
5. Workers systemd (abajo).
6. Despliegues repetibles: `bash deploy/hestia/deploy.sh` (`--dry-run` lista pasos).

`deploy.sh` ejecuta: `artisan down` → pull → composer → `npm ci && npm run build` (exige `public/build/manifest.json`) → migrate → caches → `queue:restart` → `up` → reinicio de `WORKER_UNITS` → smoke con `app:health --skip-scheduler`. Si falla antes de cambiar el código, la app vuelve a subir; si falla después, se queda en mantenimiento.

## Workers systemd

Unidad plantilla `deploy/systemd/scheduler-worker@.service`; la instancia es la cola.

```bash
sudo systemctl enable --now scheduler-worker@publish scheduler-worker@poll scheduler-worker@metrics scheduler-worker@reports
```

Parámetros: `--tries=3 --timeout=120 --max-time=3600 --max-jobs=500`, `Restart=always`, sandboxing (`ProtectSystem=strict`, escribe solo en `storage/` y `bootstrap/cache`). `--timeout` (120 s) debe ser mayor que el job más largo y menor que `retry_after` (180 s).

Operación: `systemctl status scheduler-worker@publish`, `journalctl -u scheduler-worker@publish -f`, `php artisan queue:failed`, `php artisan app:queue-probe --wait=60`.

Plan B sin systemd: cron con `queue:work --stop-when-empty` y `flock` (latencia máxima 1 min). Ver `deploy/systemd/README.md`.

## Healthcheck

- `php artisan app:health` (`--json`, `--skip-scheduler`): BD, cola, disco y latido del scheduler (falla si tiene más de 180 s).
- `GET /health` con cabecera `X-Health-Token: <HEALTHCHECK_TOKEN>`: 200 (también degradado), 404 sin token. Limitado a 30/min. Configurar en UptimeRobot o Healthchecks.io cada 5 min.
- `/up`: solo prueba que PHP responde.

## TikTok: media pública

Las fotos usan `PULL_FROM_URL`: TikTok descarga la URL sin firma desde `TIKTOK_VERIFIED_DOMAIN`, que debe ser el dominio público del bucket (`R2_URL`). Toda la media del bucket queda accesible por URL (claves ULID impredecibles, no listables). Mitigación recomendada: bucket o dominio separado solo para TikTok, o regla WAF que limite a un prefijo, más una regla de ciclo de vida que lo borre a las 24 h.

## Proxies de confianza

En Hestia (Nginx + PHP-FPM sin proxy delante) deja `TRUSTED_PROXIES` vacío. Detrás de ngrok en desarrollo usa `TRUSTED_PROXIES=*` y `APP_URL=https://<dominio-ngrok>`; si no, `route()` genera `http://` y el redirect OAuth no coincide.

## Seguridad de cuenta: 2FA y passkeys

Resumen de operación del doble factor (TOTP) y las passkeys ([ADR-003](../knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md)). Lo que ve el usuario está en la [guía de usuario](../usuario/guia-de-usuario.md#seguridad-de-la-cuenta-doble-factor-y-passkeys).

### Antes de desplegar: `sodium`

Las passkeys con clave EdDSA necesitan la extensión `sodium`. Compruébalo en el PHP que usa el dominio en Hestia (el de PHP-FPM, no solo el de la consola):

```bash
php -m | grep -i sodium        # debe imprimir: sodium
php -r "var_dump(extension_loaded('sodium'));"
```

Si falta y no puedes activarla (paquete `php8.4-sodium` o equivalente), la alternativa es `composer require paragonie/sodium_compat` y volver a desplegar. En el PHP local (8.3.33) está presente. La comprobación en el servidor es manual y sigue pendiente (M-04 del plan de seguridad de cuenta).

### Variables de entorno

| Variable | Qué es | Producción (Hestia) |
|---|---|---|
| `WEBAUTHN_RP_ID` | Dominio al que se atan las passkeys, sin esquema ni puerto | el dominio definitivo (ej. `app.midominio.com`) |
| `WEBAUTHN_ORIGINS` | Orígenes completos que pueden completar la ceremonia (coma) | `https://app.midominio.com` |
| `PASSKEYS_USER_HANDLE_SECRET` | Secreto propio del que sale el identificador de usuario de cada passkey nueva | `php -r "echo bin2hex(random_bytes(32));"`; si falta cae en `APP_KEY` y la app avisa en el log y en `php artisan about` |
| `APP_PREVIOUS_KEYS` | Claves de aplicación anteriores | vacía; solo se rellena al rotar `APP_KEY` |

Si `WEBAUTHN_RP_ID` / `WEBAUTHN_ORIGINS` no se definen **o están vacías** se usan el host y el origen de `APP_URL` (una variable vacía cuenta como no definida). Con valores incoherentes (un RP ID que no es el dominio de la app o un origen que no coincide) el login con passkey **falla cerrado**: devuelve un error neutro y no deja entrar.

### El RP ID es para siempre (por entorno)

Cada entorno tiene su RP ID y sus passkeys; **no son transferibles entre dominios**:

- **Hestia (producción):** el dominio definitivo. Si cambia (otro dominio o un subdominio distinto), **todas las passkeys dejan de valer** y cada usuario debe registrarlas de nuevo (siguen entrando con contraseña y, si lo tienen, el doble factor). Decide el dominio antes de que los usuarios creen passkeys.
- **Local con Laragon:** `https://metricool.test` → RP ID `metricool.test`.
- **E2E:** `http://localhost:8080` → RP ID `localhost` (WebAuthn no admite una IP; ver [desarrollo local](../guias/desarrollo-local.md#pruebas-e2e-playwright)).
- **ngrok (pruebas manuales con dispositivos reales):** el RP ID es el dominio de ngrok. Con un dominio gratuito que cambia, **rotar el dominio de ngrok invalida las passkeys creadas con el anterior**: son passkeys de pruebas y se borran; con el dominio nuevo hay que actualizar `APP_URL`, `WEBAUTHN_RP_ID` y `WEBAUTHN_ORIGINS` (y `TRUSTED_PROXIES=*`) y volver a registrarlas. Un dominio ngrok reservado evita el problema.

### Rotar `APP_KEY` (`APP_PREVIOUS_KEYS`)

Los secretos TOTP se guardan cifrados con `APP_KEY`. Si hay que rotarla, **antes** de cambiarla copia la clave actual a `APP_PREVIOUS_KEYS` (separadas por comas si hay varias) y después genera la nueva. Con `APP_PREVIOUS_KEYS` Laravel descifra con las anteriores y cifra con la nueva. Sin esto, los usuarios con doble factor no podrían superar el reto ni desactivarlo. Rotar `APP_KEY` o `PASSKEYS_USER_HANDLE_SECRET` **no** invalida las passkeys ya registradas.

### Un usuario pierde el móvil: `user:two-factor-reset`

Si un usuario perdió el móvil **y** sus códigos de recuperación, y no tiene una passkey, el operador desactiva su doble factor desde la consola del servidor:

```bash
php artisan user:two-factor-reset correo@ejemplo.com           # pide confirmación
php artisan user:two-factor-reset correo@ejemplo.com --force   # sin confirmación
```

El comando solo desactiva el 2FA (no cambia la contraseña ni las API keys del MCP) y deja `auth.2fa.reset_by_operator` en `audit_logs`. Comprueba antes la identidad del usuario por otro canal. Después, el usuario entra con su contraseña y puede volver a activar el doble factor.

## Estado de verificación

| Elemento | Estado |
|---|---|
| `deploy.sh --dry-run`, `app:health`, `app:queue-probe` | verificado en local |
| Dominio/TLS/BD/cron/Nginx en Hestia | pendiente (M-04) |
| `sodium` en el PHP de Hestia, RP ID/orígenes y passkey real en producción | pendiente (M-04 de seguridad de cuenta) |
| `sodium` en el PHP local | verificado (`php -m`) |
| `systemd-analyze verify` y reinicio del worker | pendiente (VPS) |
| Disco `r2` contra Cloudflare R2 real | pendiente (credenciales) |
