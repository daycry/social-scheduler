# Despliegue: HestiaCP, systemd y almacenamiento de media

Fuente de verdad operativa: `deploy/hestia/README.md` y `deploy/systemd/README.md`. Esta página resume y enlaza. Todo lo marcado **[VPS]** requiere acceso root y sigue pendiente (M-04).

## Este despliegue

| Dato | Valor |
|---|---|
| Dominio | `social-scheduler.daycry.ovh` |
| `APP_URL` | `https://social-scheduler.daycry.ovh` |
| `WEBAUTHN_RP_ID` / `WEBAUTHN_ORIGINS` | `social-scheduler.daycry.ovh` / `https://social-scheduler.daycry.ovh` (las passkeys quedan atadas a este dominio: cambiarlo las invalida) |
| Servidor MCP (Claude, Codex, OpenCode) | `https://social-scheduler.daycry.ovh/mcp` |
| Comandos de Hestia | `<DOMINIO>` = `social-scheduler.daycry.ovh` |

URIs de redirección que hay que registrar en cada proveedor:

| App | URI |
|---|---|
| Meta (conectores, Facebook Login for Business) | `https://social-scheduler.daycry.ovh/connections/meta/callback` |
| Google (conectores, YouTube) | `https://social-scheduler.daycry.ovh/connections/google/callback` |
| TikTok (Login Kit) | `https://social-scheduler.daycry.ovh/connections/tiktok/callback` |
| Google (login) | `https://social-scheduler.daycry.ovh/auth/google/callback` |
| Meta (login, app Consumidor) | `https://social-scheduler.daycry.ovh/auth/facebook/callback` |

Con la URL de ngrok de desarrollo ya registrada, añade estas **sin quitar** las de ngrok mientras sigas probando en local.

## Requisitos del servidor

- HestiaCP con Nginx + PHP-FPM 8.4/8.5 y MariaDB >= 10.6 / MySQL >= 8.0 (`SKIP LOCKED` para varios workers).
- Extensiones PHP: `mbstring intl pdo_mysql bcmath gd curl xml zip` y, para las passkeys, `openssl` y `sodium` (ver [Seguridad de cuenta](#seguridad-de-cuenta-2fa-y-passkeys)). Composer 2 y Node 22.
- `ffprobe` en el PATH de PHP-FPM y de los workers.
- Almacenamiento de media: **disco local del servidor** (este despliegue, ver [Almacenamiento de media](#almacenamiento-de-media)) o un bucket R2 con CORS (`PUT`, `Content-Type`, exponer `ETag`).

## Pasos

1. Dominio, TLS y BD con los comandos `v-add-web-domain`, `v-add-letsencrypt-domain`, `v-add-database` (plantilla `laravel`, docroot `public/`).
2. Ajustes de Nginx (`deploy/hestia/nginx-overrides.conf`) y PHP-FPM (`upload_max_filesize=512M`, `post_max_size=512M`, `memory_limit=256M`, `max_execution_time=300`; con media local las subidas pasan por PHP).
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

## Almacenamiento de media

**Este despliegue usa el disco local del servidor** (`MEDIA_DISK=local`, decisión del 2026-10-23). R2 sigue soportado: para usarlo, `MEDIA_DISK=r2` y las variables `R2_*`.

### Qué disco hace qué

Laravel tiene dos discos locales. La media de la app va **siempre** en `local`:

| | `local` (media e informes) | `public` |
|---|---|---|
| Carpeta | `storage/app/private` | `storage/app/public` |
| Acceso desde Internet | Solo con **URL firmada y temporal** por la ruta `/storage/...` de la app (12 h por defecto, `MEDIA_PUBLIC_URL_TTL`; la firma depende de `APP_KEY`) | Cualquiera, por URL, para siempre: `php artisan storage:link` (lo ejecuta `deploy.sh`) publica `public/storage` |
| Uso | Fotos y vídeos de las publicaciones, borradores incluidos, e informes PDF | Nada sensible |

Nunca pongas `MEDIA_DISK=public`: toda la media (también la no publicada y los informes con métricas) quedaría accesible sin caducidad. `storage:link` solo expone `storage/app/public`, no `private`.

### Cómo funciona con disco local

| Paso | Con `local` | Con `r2` |
|---|---|---|
| Subida | El navegador envía el fichero a la app (`PUT media/{asset}/upload`) y PHP lo escribe en `storage/app/private/media/...` | El navegador sube directo a R2 con URL prefirmada (multipart para ficheros grandes); PHP no recibe el binario |
| Validación (`ffprobe`) | Lee el fichero del disco | Descarga solo los extremos del fichero |
| Publicación en Instagram, Facebook, YouTube | La red descarga la media de la URL firmada de la app | La red descarga de la URL firmada de R2 |
| Vídeo de TikTok | Lo sube la app (`FILE_UPLOAD`) | Igual |
| **Fotos/carrusel de TikTok** | **No disponible**: TikTok exige una URL fija sin firma en un dominio verificado (`PULL_FROM_URL`). Deja `TIKTOK_VERIFIED_DOMAIN` vacío y la app no ofrece ese tipo | Disponible con `TIKTOK_VERIFIED_DOMAIN` = dominio público del bucket |

### Requisitos con disco local

- **Tamaño de subida.** El fichero pasa por Nginx y PHP-FPM: sube a la vez `client_max_body_size` (Nginx, `nginx-overrides.conf`, 512m) y `upload_max_filesize`/`post_max_size` (PHP del dominio en Hestia, 512M), y `max_execution_time=300` para vídeos grandes.
- **Cloudflare (plan gratuito) corta las peticiones de más de 100 MB.** Con el proxy activo, un vídeo de más de 100 MB falla al subirlo. Para vídeos mayores: subir sin proxy (nube gris) o pasar a R2, que sube directo sin atravesar Cloudflare.
- **Cloudflare no debe cachear ni retar `/storage/*`**: Meta y Google descargan la media de ahí. Va en la misma regla de *Bypass* y de omisión del WAF que `/mcp` y `/oauth/*` (ver [Cloudflare delante](#cloudflare-delante)).
- **Espacio en disco y copias.** La media vive en el VPS: inclúyela en las copias de seguridad de Hestia (`storage/app/private`). `app:media-prune` (cada hora) borra las subidas abandonadas o inválidas.
- **Despliegues.** `storage/` no está en git: `deploy.sh` (pull, composer, build, migrate, caches) no toca la media.

### Comprobación

```bash
php artisan app:health      # «storage» debe salir OK
php artisan tinker --execute='echo config("filesystems.media");'   # local
```

Sube una imagen desde el compositor y comprueba que aparece en `storage/app/private/media/<usuario>/`.

## TikTok: media pública

Solo aplica con R2 (con disco local las fotos de TikTok no están disponibles, ver arriba). Las fotos usan `PULL_FROM_URL`: TikTok descarga la URL sin firma desde `TIKTOK_VERIFIED_DOMAIN`, que debe ser el dominio público del bucket (`R2_URL`). Toda la media del bucket queda accesible por URL (claves ULID impredecibles, no listables). Mitigación recomendada: bucket o dominio separado solo para TikTok, o regla WAF que limite a un prefijo, más una regla de ciclo de vida que lo borre a las 24 h.

## Proxies de confianza

| Escenario | `TRUSTED_PROXIES` |
|---|---|
| Hestia sin nada delante (Nginx + PHP-FPM directo) | vacío |
| **Cloudflare delante (este despliegue)** | los rangos de Cloudflare separados por comas ([ips-v4](https://www.cloudflare.com/ips-v4) e [ips-v6](https://www.cloudflare.com/ips-v6)); nunca `*` |
| ngrok en desarrollo | `*`, con `APP_URL=https://<dominio-ngrok>` (si no, `route()` genera `http://` y el redirect OAuth no coincide) |

Con Cloudflare y sin sus rangos, `request()->ip()` sería la IP de Cloudflare: todos los clientes compartirían los límites por IP (login, 2FA, registro de clientes OAuth, `/oauth/token`) y la auditoría guardaría IPs de Cloudflare. Con `*` cualquiera podría falsear su IP con `X-Forwarded-For` si alcanza el servidor sin pasar por Cloudflare. Cloudflare publica cambios en sus rangos muy de vez en cuando: revísalos al renovar el servidor.

## Cloudflare delante

`social-scheduler.daycry.ovh` va con el proxy de Cloudflare activado (nube naranja). Ajustes de la zona `daycry.ovh`:

| Ajuste | Valor | Por qué |
|---|---|---|
| SSL/TLS → modo | **Full (strict)** | Cloudflare habla HTTPS con el servidor y valida su certificado (Let's Encrypt de Hestia). Con «Flexible» la app recibiría HTTP y entraría en bucle de redirecciones |
| Always Use HTTPS | activado | |
| Rocket Loader | **desactivado** | Reescribe los `<script type="module">` de Vite e Inertia y rompe la app |
| Email Address Obfuscation | desactivado | Altera el HTML de las páginas de Inertia |
| Auto Minify / caché de HTML | desactivados | La app sirve HTML dinámico con CSRF y sesión |
| Bot Fight Mode / Super Bot Fight Mode | **desactivado**, o con regla de omisión | Bloquea a los clientes MCP (Claude, Codex, OpenCode) y a los servidores de Anthropic, Meta, Google y TikTok, que no son navegadores |
| Reglas de caché | *Bypass* para `/mcp*`, `/oauth/*`, `/.well-known/oauth-*`, `/auth/*`, `/connections/*`, `/storage/*`, `/up`, `/health` | Respuestas por usuario o con tokens: nunca deben cachearse |
| WAF | regla de omisión (*Skip*) de los retos gestionados para las mismas rutas | Un reto JavaScript rompe el flujo OAuth y las llamadas máquina a máquina |

Comprobaciones tras activar el proxy (deben dar lo mismo que sin Cloudflare):

```bash
curl -sI https://social-scheduler.daycry.ovh/up | head -1                     # 200
curl -si -X POST https://social-scheduler.daycry.ovh/mcp -H 'Accept: application/json, text/event-stream'   -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}' | grep -i 'www-authenticate'
curl -s https://social-scheduler.daycry.ovh/.well-known/oauth-authorization-server | head -c 120
```

Y en la app, **Perfil → Seguridad** o la auditoría deben mostrar tu IP real, no una de Cloudflare.

Let's Encrypt de Hestia sigue funcionando con el proxy activo (Cloudflare deja pasar `/.well-known/acme-challenge/` por HTTP). Si la emisión falla, desactiva el proxy un momento, emite el certificado y vuelve a activarlo.

Con disco local la media se sirve por `/storage/*` del propio dominio (incluido en la regla de *Bypass* y en la omisión del WAF) y las subidas de más de 100 MB las corta Cloudflare (plan gratuito). Con R2, la media va por su propio dominio (`R2_URL`) y debe ser accesible sin retos para que TikTok la descargue (`PULL_FROM_URL`).

## OAuth del MCP, Nginx/Hestia y redirect URIs

Resumen operativo del servidor OAuth del MCP y del login social ([ADR-004](../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)). Instalación por cliente: [servidor MCP](mcp.md). Pasos manuales de Google y Meta: [guía de login social](../guias/login-social-google-meta.md).

### Nginx/Hestia

- **`Authorization` hasta PHP-FPM.** Sin la cabecera, `/mcp` no ve ningún Bearer (API key ni OAuth) y responde siempre 401. Comprueba que la plantilla de Hestia pasa `HTTP_AUTHORIZATION` a PHP-FPM y que `deploy/hestia/nginx-overrides.conf` no la pisa. Cierra la parte pendiente de M-F6-2.
- **`/.well-known/oauth-*` hasta PHP.** Las plantillas de Hestia sirven `/.well-known/acme-challenge` desde disco; ese `location` no debe tragarse el resto de `/.well-known/`. Compruébalo tras desplegar con el `curl` de abajo.
- **HTTPS.** `APP_URL` en HTTPS: los metadatos, `resource` y `WWW-Authenticate` se generan con ella. En Hestia sin proxy delante, `TRUSTED_PROXIES` vacío.

### Verificación con `curl`

```bash
# 1) Sin Bearer: 401 con WWW-Authenticate y resource_metadata (si falta, no llega la cabecera o el .well-known)
curl -si -X POST <APP_URL>/mcp -H 'Accept: application/json, text/event-stream' \
  -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}' | head -n 12
# esperado: HTTP/2 401  y  www-authenticate: Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/mcp"

# 2) Metadatos: 200 y JSON (scopes_supported ["mcp:use"], code_challenge_methods_supported ["S256"])
curl -s <APP_URL>/.well-known/oauth-protected-resource/mcp
curl -s <APP_URL>/.well-known/oauth-authorization-server

# 3) Registro de clientes: 201 con loopback (400 invalid_redirect_uri con una URL pública)
curl -si -X POST <APP_URL>/oauth/register -H 'Content-Type: application/json' \
  -d '{"client_name":"prueba","redirect_uris":["http://127.0.0.1:53111/callback"]}' | head -n 1
```

Cada registro de prueba deja un cliente en `oauth_clients`; `php artisan mcp:oauth-prune` (programado a diario) limpia los antiguos sin uso.

### Redirect URIs de los proveedores de login

Se registran en cada app con credenciales **propias del login** (distintas de las de los conectores de redes):

`<APP_URL>` es la URL pública con esquema, p. ej. `https://midominio.com` (Google: `https://midominio.com/auth/google/callback`).

| Proveedor | Redirect URI | Variables |
|---|---|---|
| Google | `<APP_URL>/auth/google/callback` | `GOOGLE_LOGIN_CLIENT_ID`, `GOOGLE_LOGIN_CLIENT_SECRET` |
| Facebook | `<APP_URL>/auth/facebook/callback` | `META_LOGIN_APP_ID`, `META_LOGIN_APP_SECRET` |

Con las variables vacías el proveedor queda desactivado: no hay botón en el login y `/auth/{proveedor}/redirect` da 404. Las variables del OAuth del MCP (`MCP_OAUTH_TOKEN_DAYS`, `MCP_OAUTH_CODE_TTL`, `MCP_OAUTH_REDIRECT_ALLOWED`) están en `.env.example`.

La batería E2E arranca su servidor con `APP_ENV=e2e` y un proveedor social **falso**. Nunca pongas `APP_ENV=e2e` en un servidor real: el proveedor falso solo se registra con ese entorno **y** un `APP_URL` local **definido de forma explícita** (`localhost`, `127.0.0.1`; si `APP_URL` falta, el valor por defecto `http://localhost` no cuenta) y, cuando hay petición, un Host también local, pero no hay motivo para dejarlo a mano.

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
| `Authorization` y `/.well-known/oauth-*` en Nginx/Hestia (el `curl` de arriba) | pendiente (M-07 del plan de pruebas de login social y OAuth) |
| Redirect URIs y login real con Google y Facebook | pendiente (M-01 y M-02 del mismo plan) |
| `systemd-analyze verify` y reinicio del worker | pendiente (VPS) |
| Disco `r2` contra Cloudflare R2 real | pendiente (credenciales) |
