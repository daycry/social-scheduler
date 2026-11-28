# Stack técnico

Última actualización: 2026-10-31.

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
| HTTP a las redes | Cliente `Http` de Laravel para los conectores de publicación y analítica | `app/Connectors/` |
| Login social | Laravel Socialite para entrar con Google y Facebook | `composer.json`, `app/Http/Controllers/Auth/SocialLoginController.php` |
| Gráficos | SVG propio (`LineChart.vue`), sin Chart.js/ECharts | tasks.md T-37 |
| Vídeo | `ffprobe` (paquete `ffmpeg`) | `config/media.php` |
| Imágenes grandes (Bluesky) | `ffmpeg` (respaldo: GD) | `config/media.php` |
| Tests | Pest 4 sobre SQLite en memoria; estilo con Pint | `composer.json`, `pint.json` |
| E2E | Playwright con Chromium en escritorio, móvil de 375 px y móvil de 360 px | `package.json`, `playwright.config.ts`, `playwright.roadmap.config.ts` |

Pruebas PHP: `composer test` y `vendor/bin/pint --test`. CI en `.github/workflows/ci.yml` ejecuta ambos comandos bajo PHP 8.4.

Las dos configuraciones de Playwright usan SQLite, sesiones, media y caché desechables por ejecución. `playwright.roadmap.config.ts` levanta el router de T-43 en `localhost:8081` (`E2E_ROADMAP_PORT`, script `npm run test:e2e:roadmap`); `playwright.config.ts` levanta el general en `E2E_PORT` o un puerto libre mediante `tests/e2e/isolated/serve.cjs`, que entrega a PHP un manifiesto explícito de variables ficticias. El general usa tres usuarios para los escenarios mutables y otro de solo lectura; no carga la cuenta demo ni el `.env` real. Excluye `tests/e2e/roadmap/` y sus propias guardias `tests/e2e/isolated/` mediante `testIgnore`. La QA general del 2026-10-31 pasa 502 casos y omite los dos previstos del shell de escritorio en móvil; T-77 sigue abierta por los smokes reales T-30/T-64.

La [guía de T-43](guias/pruebas-t43.md) recoge el comando aislado, las dependencias de vídeo/PDF y los archivos de evidencia. La QA independiente del 2026-10-31 pasa 57 tests: doce E2E, tres bloques API y cuatro guardas en cada uno de los tres proyectos. El [informe](roadmap/2026-10-19-social-scheduler/testing/report.md) conserva el JSON del gate; los manuales con cuentas y VPS reales siguen separados.
