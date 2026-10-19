# Social Scheduler

Programacion y analitica de redes sociales (uso personal). Laravel 13 + Inertia, cola `database`, workers systemd y media en Cloudflare R2.
Plan y ledger: `docs/roadmap/2026-10-19-social-scheduler/`.

## Requisitos

- PHP >= 8.3 (produccion 8.4/8.5), Composer 2, Node 22
- MySQL >= 8.0 / MariaDB >= 10.6 (necesario para `SKIP LOCKED` en la cola `database`). Local verificado: MySQL 8.4.3 (Laragon).

## Puesta en marcha local (Laragon, `https://metricool.test`)

```bash
composer install
cp .env.example .env && php artisan key:generate
# crear BD: CREATE DATABASE metricool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
npm install && npm run build
```

El host virtual de Laragon debe apuntar a `public/` (recargar Apache desde Laragon tras crear el proyecto).

## Calidad

```bash
composer test            # Pest (sqlite en memoria)
vendor/bin/pint --test   # estilo
```

CI: `.github/workflows/ci.yml` ejecuta Pint y la suite en cada push/PR.

## Despliegue y operacion

Ver `deploy/hestia/README.md` (HestiaCP) y `deploy/systemd/` (workers de cola).
