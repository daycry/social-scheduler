# Social Scheduler

<!-- Tests y calidad -->

[![CI](https://github.com/daycry/social-scheduler/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/daycry/social-scheduler/actions/workflows/ci.yml)
[![Code Style](https://img.shields.io/badge/code%20style-Laravel%20Pint-f05340.svg)](https://laravel.com/docs/pint)
[![Tests](https://img.shields.io/badge/tests-Pest%201896-22c55e.svg)](tests)
[![E2E](https://img.shields.io/badge/E2E-Playwright%20·%203%20viewports-2ead33.svg)](tests/e2e)
[![Accesibilidad](https://img.shields.io/badge/a11y-axe%20WCAG%202.1%20AA-7c3aed.svg)](tests/e2e/a11y.spec.ts)

<!-- Stack -->

[![PHP Version](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-777bb4.svg?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-13-ff2d20.svg?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3%20+%20Inertia-42b883.svg?logo=vuedotjs&logoColor=white)](https://inertiajs.com)
[![MySQL](https://img.shields.io/badge/MySQL-%E2%89%A5%208.0-4479a1.svg?logo=mysql&logoColor=white)](https://dev.mysql.com)
[![MCP](https://img.shields.io/badge/MCP-OAuth%202.1%20+%20API%20keys-111827.svg)](docs/modulos/mcp.md)

<!-- Comunidad -->

[![GitHub stars](https://img.shields.io/github/stars/daycry/social-scheduler)](https://github.com/daycry/social-scheduler/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/daycry/social-scheduler)](https://github.com/daycry/social-scheduler/network/members)
[![GitHub issues](https://img.shields.io/github/issues/daycry/social-scheduler)](https://github.com/daycry/social-scheduler/issues)
[![Last commit](https://img.shields.io/github/last-commit/daycry/social-scheduler)](https://github.com/daycry/social-scheduler/commits)

Programación y analítica de redes sociales para uso personal, con identidad propia: planifica publicaciones en Instagram, Facebook, YouTube y TikTok, mide su rendimiento, genera informes y se maneja también desde agentes de IA por MCP.

Laravel 13 + Inertia (Vue 3), cola `database`, workers systemd y media en Cloudflare R2, desplegado en HestiaCP.

---

## Estadísticas

| | |
|---|---|
| Código de aplicación | 265 ficheros PHP · ~25.300 líneas en `app/` |
| Interfaz | 95 componentes Vue (Inertia) |
| Base de datos | 44 migraciones |
| Tests unitarios y de integración | **1.896 tests Pest** en 177 ficheros (SQLite en memoria) |
| Tests E2E | **345 tests Playwright** en 7 specs × 3 tamaños (escritorio, móvil 375 px y 360 px), con axe |
| Servidor MCP | 22 herramientas (marcas, cuentas, calendario, publicaciones —con «publicar ahora», notas y campañas—, importación de media por URL, estadísticas, informes, comentarios) |
| Documentación | 82 documentos en `docs/` · 5 ADR · 6 iniciativas en el roadmap |

## Funcionalidades

- **Publicación** en Instagram (post, carrusel, reel, story), páginas de Facebook, YouTube (vídeo y short) y TikTok, con cola con reintentos y calendario.
- **Compositor a pantalla completa**: texto común o propio por red, tipo de publicación junto a cada red, contadores y errores en vivo, opciones por red recordadas por marca, notas internas, generador de enlaces con UTM, «Publicar ahora» y guardado en biblioteca.
- **Analítica e informes**: dashboards por marca, mejores horas para publicar, informes PDF y comparativa con competidores.
- **Primer comentario** automático al publicar (Instagram, Facebook y YouTube), textos guardados reutilizables, **campañas** con su panel de analítica y **cuadrícula de Instagram** con las programadas.
- **Importar media** desde una URL pública o desde Google Drive (Picker): descarga en segundo plano por tramos, con progreso y cancelación ([guía de Google Cloud](docs/guias/google-cloud-picker-drive.md)).
- **Bandeja de comentarios**, biblioteca de media, enlaces inteligentes y asistente de IA.
- **Seguridad de cuenta**:
  - doble factor con app de autenticación (TOTP) y códigos de recuperación;
  - passkeys (WebAuthn) para entrar sin contraseña;
  - login con Google y Facebook (solo cuentas existentes; Google se vincula si verifica el email, Facebook a mano desde el perfil; el doble factor se pide igual).
- **Servidor MCP** en `/mcp` para Claude Code, Claude Desktop, Codex y OpenCode:
  - OAuth 2.1 (PKCE, registro dinámico de clientes y pantalla de consentimiento) para conectar sin copiar ninguna clave;
  - API keys `msk_` con permisos predefinidos (solo lectura, publicar o todo), revocables desde la app.

## Requisitos

- PHP ≥ 8.3 (producción 8.4/8.5) con `sodium`, Composer 2, Node 22.
- MySQL ≥ 8.0 o MariaDB ≥ 10.6 (la cola `database` necesita `SKIP LOCKED`). Local verificado: MySQL 8.4.3 (Laragon).

## Puesta en marcha local (Laragon, `https://metricool.test`)

```bash
composer install
cp .env.example .env && php artisan key:generate
# crear BD: CREATE DATABASE metricool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
npm install && npm run build
```

El host virtual de Laragon debe apuntar a `public/`. Para OAuth de las redes y passkeys hace falta HTTPS público (ngrok): ver [`docs/guias/desarrollo-local.md`](docs/guias/desarrollo-local.md).

## Calidad

```bash
composer test            # Pest (SQLite en memoria)
vendor/bin/pint --test   # estilo
npx tsc --noEmit -p .    # tipos de los tests E2E
npm run test:e2e         # Playwright: levanta su propio servidor en localhost:8080
```

CI (`.github/workflows/ci.yml`) ejecuta Pint y la suite Pest en cada push y PR.

## Documentación

| Tema | Dónde |
|---|---|
| Índice general | [`docs/README.md`](docs/README.md) |
| Arquitectura y stack | [`docs/arquitectura.md`](docs/arquitectura.md), [`docs/stack.md`](docs/stack.md) |
| Guía de usuario | [`docs/usuario/guia-de-usuario.md`](docs/usuario/guia-de-usuario.md) |
| Módulos (publicación, analítica, informes, MCP…) | [`docs/modulos/`](docs/modulos) |
| Conectar agentes de IA (MCP) | [`docs/modulos/mcp.md`](docs/modulos/mcp.md) |
| Login con Google y Facebook | [`docs/guias/login-social-google-meta.md`](docs/guias/login-social-google-meta.md) |
| Variables de entorno | [`docs/referencia/configuracion.md`](docs/referencia/configuracion.md) |
| Decisiones de arquitectura (ADR) | [`docs/knowledge/adr/`](docs/knowledge/adr) |
| Roadmap y ledgers | [`docs/roadmap/`](docs/roadmap/README.md) |
| Cambios | [`CHANGELOG.md`](CHANGELOG.md) · [`CHANGELOG.es.md`](CHANGELOG.es.md) |

## Despliegue y operación

Ver [`docs/modulos/despliegue.md`](docs/modulos/despliegue.md), [`deploy/hestia/README.md`](deploy/hestia/README.md) (HestiaCP y Nginx) y [`deploy/systemd/`](deploy/systemd) (workers de cola).
