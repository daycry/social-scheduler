# Stack técnico

Fuente: `composer.json`, `package.json` y [ADR-001](knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md).

| Pieza | Elección | Evidencia |
|---|---|---|
| Lenguaje | PHP >= 8.3 (producción 8.4/8.5) | `composer.json` |
| Framework | Laravel `^13.17` | `composer.json` |
| Frontend | Vue 3 + Inertia (`inertiajs/inertia-laravel ^2.0`, `@inertiajs/vue3 ^2`), Vite 8, Tailwind 3 | `package.json` |
| Rutas en JS | `tightenco/ziggy ^2.0` | `composer.json` |
| Auth web | Laravel Breeze 2.4 (registro, login, reset, verificación) | `composer.json` (dev) |
| API keys / MCP | `laravel/sanctum ^4.0`, `laravel/mcp ~1.0.2` | `composer.json` |
| Base de datos | MariaDB >= 10.6 o MySQL >= 8.0 (`SKIP LOCKED`) | `deploy/hestia/README.md` |
| Cola y caché | Driver `database` (sin Redis en v1) | `.env.example`: `QUEUE_CONNECTION=database` |
| Media | `league/flysystem-aws-s3-v3` contra Cloudflare R2 | `config/filesystems.php` |
| PDF | `barryvdh/laravel-dompdf ^3.1` ([ADR-002](knowledge/adr/ADR-002-pdf-dompdf.md)) | `config/reports.php` |
| HTTP a las redes | Cliente `Http` de Laravel (sin Socialite ni `google/apiclient`) | `app/Connectors/` |
| Gráficos | SVG propio (`LineChart.vue`), sin Chart.js/ECharts | tasks.md T-37 |
| Vídeo | `ffprobe` (paquete `ffmpeg`) | `config/media.php` |
| Imágenes grandes (Bluesky) | `ffmpeg` (respaldo: GD) | `config/media.php` |
| Tests | Pest 4 sobre SQLite en memoria; estilo con Pint | `composer.json`, `pint.json` |

Pruebas: `composer test` y `vendor/bin/pint --test`. CI en `.github/workflows/ci.yml` (según README del repo).

Playwright **no** está instalado: no hay `playwright.config` ni `tests/e2e/` (ver [pendientes](referencia/pendientes-manuales.md)).
