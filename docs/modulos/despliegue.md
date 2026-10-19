# Despliegue: HestiaCP, systemd y R2

Fuente de verdad operativa: `deploy/hestia/README.md` y `deploy/systemd/README.md`. Esta página resume y enlaza. Todo lo marcado **[VPS]** requiere acceso root y sigue pendiente (M-04).

## Requisitos del servidor

- HestiaCP con Nginx + PHP-FPM 8.4/8.5 y MariaDB >= 10.6 / MySQL >= 8.0 (`SKIP LOCKED` para varios workers).
- Extensiones PHP: `mbstring intl pdo_mysql bcmath gd curl xml zip`. Composer 2 y Node 22.
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

## Estado de verificación

| Elemento | Estado |
|---|---|
| `deploy.sh --dry-run`, `app:health`, `app:queue-probe` | verificado en local |
| Dominio/TLS/BD/cron/Nginx en Hestia | pendiente (M-04) |
| `systemd-analyze verify` y reinicio del worker | pendiente (VPS) |
| Disco `r2` contra Cloudflare R2 real | pendiente (credenciales) |
