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

## Calidad

```bash
composer test            # Pest, SQLite en memoria
vendor/bin/pint --test   # estilo
npm run build            # compila assets
```

Los tests usan conectores simulados: no tocan las redes. Estructura de `tests/Feature/`: `Analytics`, `Auth`, `Backfill`, `Calendar`, `Connections`, `Connectors`, `Dashboard`, `Infra`, `Isolation`, `Jobs`, `Mcp`, `Media`, `Notifications`, `Posts`, `Publishing`, `Reports`, `Snapshots`.

## Convenciones

- Toda consulta del dominio pasa por `UserScope`; en jobs usa `withoutUserScope()` de forma explícita.
- La lógica va en `app/Services/`, no en controladores ni herramientas MCP.
- Los límites de media por red viven en `config/media_specs.php`: edítalo y sube `version` cuando una red cambie un límite.
