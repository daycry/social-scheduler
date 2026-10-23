# Desarrollo local con Laragon

Entorno verificado: Laragon con MySQL 8.4.3, host `https://metricool.test`.

## Requisitos

- PHP >= 8.3, Composer 2, Node 22.
- MySQL >= 8.0 o MariaDB >= 10.6.
- `ffprobe` (ffmpeg) en el PATH si vas a subir vídeos.

## Puesta en marcha

```bash
composer install
cp .env.example .env && php artisan key:generate
# CREATE DATABASE metricool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
npm install && npm run build
```

El host virtual de Laragon debe apuntar a `public/`; recarga Apache desde Laragon tras crear el proyecto. En local `MEDIA_DISK=local`, así que no necesitas R2.

## Procesos en segundo plano

La cola es `database`, así que sin worker nada se publica. En local:

```bash
php artisan queue:work database --queue=publish,poll,metrics,reports
php artisan schedule:work          # sustituye al cron de producción
```

`php artisan app:queue-probe --wait=60` encola un job por cola y comprueba que se procesan. `php artisan app:health` revisa BD, cola, disco y latido del scheduler.

## OAuth en local: ngrok

Meta, Google y TikTok exigen HTTPS y redirect URIs registrados. Con ngrok:

1. `APP_URL=https://<dominio-ngrok>` y `TRUSTED_PROXIES=*` en `.env` (si no, `route()` genera `http://` y el redirect no coincide).
2. Registra `${APP_URL}/connections/{meta,google,tiktok}/callback` en cada app y el dominio en "Dominios de la app" de Meta.
3. Guía de credenciales: [configuración](../referencia/configuracion.md) y [decisiones por red](../referencia/decisiones-por-red.md).

## Passkeys y doble factor en local

Con Laragon (`https://metricool.test`) el RP ID de las passkeys sale de `APP_URL` (`metricool.test`) y no hay que definir nada más; para el doble factor basta una app de autenticación o el TOTP calculado en los E2E. Una passkey creada en un entorno **no vale en otro** (cada RP ID tiene las suyas): con ngrok el RP ID es el dominio de ngrok y rotarlo invalida las passkeys de pruebas. `sodium` viene en el PHP de Laragon (`php -m | grep -i sodium`). Variables, rotación de `APP_KEY` (`APP_PREVIOUS_KEYS`), `PASSKEYS_USER_HANDLE_SECRET`, `WEBAUTHN_RP_ID` y el comando `user:two-factor-reset`: ver [despliegue → seguridad de cuenta](../modulos/despliegue.md#seguridad-de-cuenta-2fa-y-passkeys).

## Calidad

```bash
composer test            # Pest, SQLite en memoria
vendor/bin/pint --test   # estilo
npm run build            # compila assets
```

Los tests usan conectores simulados: no tocan las redes. Estructura de `tests/Feature/`: `Analytics`, `Auth`, `Backfill`, `Calendar`, `Connections`, `Connectors`, `Dashboard`, `Infra`, `Isolation`, `Jobs`, `Mcp`, `Media`, `Notifications`, `Posts`, `Publishing`, `Reports`, `Snapshots`.

## Pruebas E2E (Playwright)

La batería está en `tests/e2e` (proyectos `desktop`, `mobile` 375 px y `mobile-360`). Necesita `npm run build` y las credenciales del usuario demo (`DEMO_USER_*` en `.env`, sembrado con `php artisan db:seed --class=DemoSeeder`).

```bash
npm run test:e2e                                  # arranca el servidor de pruebas y corre los 3 proyectos
npx playwright test tests/e2e/security.spec.ts    # solo 2FA y passkeys
E2E_NO_SERVER=1 npm run test:e2e                  # el servidor ya está en marcha (ver abajo)
E2E_JSON_REPORT=ruta/results.json npm run test:e2e   # además escribe el informe json
npx tsc --noEmit -p .                             # tipos de tests/e2e
```

**Servidor de pruebas.** Playwright lo lanza solo (`webServer` de `playwright.config.ts`) en `http://localhost:8080` con variables del proceso, sin tocar el `.env`:

| Variable | Valor E2E | Por qué |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | `McpGuard` acepta el `Origin` del navegador si es `APP_URL` (el E2E de API keys hace «Probar conexión») |
| `WEBAUTHN_RP_ID` | `localhost` | WebAuthn no admite una IP como RP ID: por eso `localhost` y no `127.0.0.1` |
| `WEBAUTHN_ORIGINS` | `http://localhost:8080` | origen exacto que firma el autenticador |
| `SESSION_SECURE_COOKIE` | `false` | la cookie de sesión viaja por http |
| `TRUSTED_PROXIES` | vacío | sin proxies de confianza: anula el `*` que el `.env` pone para ngrok |
| `APP_ENV` | `e2e` | registra el proveedor social **falso** (`FakeSocialiteProvider`) para los E2E del login con Google y Facebook; solo con un `APP_URL` local |
| `GOOGLE_LOGIN_CLIENT_ID`, `GOOGLE_LOGIN_CLIENT_SECRET`, `META_LOGIN_APP_ID`, `META_LOGIN_APP_SECRET` | valores inventados (`e2e-…`) | activan los botones sociales sin tocar las credenciales reales del `.env` |

`php artisan serve` descarta las variables del entorno cuando existe `.env` salvo con `--no-reload`, que es lo que usa el `webServer`. Para arrancarlo a mano (y usar `E2E_NO_SERVER=1`), con las mismas variables:

```bash
APP_ENV=e2e GOOGLE_LOGIN_CLIENT_ID=e2e-google-id GOOGLE_LOGIN_CLIENT_SECRET=e2e-google-secret META_LOGIN_APP_ID=e2e-meta-id META_LOGIN_APP_SECRET=e2e-meta-secret APP_URL=http://localhost:8080 WEBAUTHN_RP_ID=localhost WEBAUTHN_ORIGINS=http://localhost:8080 SESSION_SECURE_COOKIE=false TRUSTED_PROXIES= php artisan serve --host=localhost --port=8080 --no-reload
```

**Datos de prueba.** `global-setup.ts` crea un usuario `e2e-sec-<proyecto>@example.test` por proyecto (contraseña aleatoria en `tests/e2e/.auth/`, ignorado por git) para el 2FA y las passkeys; `global-teardown.ts` los borra con sus datos. El usuario demo solo se usa para el resto de la batería y nunca para 2FA/passkeys. Las marcas y demás datos creados llevan el prefijo `e2e-` y se limpian. El TOTP se calcula en Node (RFC 6238) a partir del secreto que muestra la pantalla; las passkeys usan el autenticador virtual de Chromium (CDP).

## Convenciones

- Toda consulta del dominio pasa por `UserScope`; en jobs usa `withoutUserScope()` de forma explícita.
- La lógica va en `app/Services/`, no en controladores ni herramientas MCP.
- Los límites de media por red viven en `config/media_specs.php`: edítalo y sube `version` cuando una red cambie un límite.
