# Social Scheduler

<!-- Tests y calidad -->

[![CI](https://github.com/daycry/social-scheduler/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/daycry/social-scheduler/actions/workflows/ci.yml)
[![Code Style](https://img.shields.io/badge/code%20style-Laravel%20Pint-f05340.svg)](https://laravel.com/docs/pint)
[![Tests](https://img.shields.io/badge/tests-Pest%202585-22c55e.svg)](tests)
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

Programación, diseño y analítica de redes sociales por marca: crea diseños editables, prepara publicaciones en Instagram, Facebook, YouTube, TikTok, Bluesky y Threads, mide su rendimiento, genera informes y automatiza desde API/MCP.

Laravel 13 + Inertia (Vue 3), cola `database`, workers systemd y media en Cloudflare R2, preparado para el despliegue en HestiaCP.

---

## Estadísticas

| | |
|---|---|
| Código de aplicación | 344 ficheros PHP en `app/` |
| Interfaz | 113 componentes Vue (Inertia) |
| Base de datos | 48 migraciones |
| Tests unitarios y de integración | **2.585 tests Pest**, 15.376 aserciones, en 247 ficheros de tests (SQLite en memoria y almacenamiento aislado; 2026-11-03). Verificación posterior de límites Studio: 55/55, incluyendo dos casos Unicode nuevos |
| Tests E2E | T-43: **57 tests pasados**; batería general: **502 pasados y 2 omitidos previstos** de 504, en 3 tamaños con SQLite aislada (2026-10-31). Ver [QA de T-43](docs/roadmap/2026-10-19-social-scheduler/testing/report.md), [evidencia general](docs/roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/raw/results.json) y [guía](docs/guias/pruebas-t43.md). |
| Servidor MCP | 36 herramientas: núcleo social y nueve del estudio; descubrimiento paginado de 30 en 30. [API v1/MCP](docs/guias/estudio-api-mcp.md) |
| Estudio y métricas: QA actual | **18/18 E2E**, tres tamaños de pantalla, cero fallos/flaky/omitidos; **29 pruebas frontend** y 3 guardas del runner. [Informe](docs/roadmap/2026-11-03-creative-social-suite/testing/qa-final/report.md) |
| Documentación | [Índice general](docs/README.md) y [RAG](docs/RAG-INDEX.md) · 5 ADR · 10 iniciativas en el roadmap |

## Funcionalidades

- **Estudio por marca**: lienzo, texto, formas, capas, grupos, páginas, filtros, recursos, kit, carpetas, 14 plantillas, autosave y revisiones. PNG/JPEG/WebP/SVG/PDF reales y borrador adjunto desde el diseño. [Guía](docs/guias/estudio-creativo.md).
- **Calidad de métricas**: cobertura y fechas visibles, engagement con observaciones compatibles y comparaciones sin porcentajes engañosos cuando faltan muestras. La [matriz de paridad](docs/roadmap/2026-11-03-creative-social-suite/reference-inventory.md) mantiene abiertas las funciones avanzadas restantes.
- **Publicación** en Instagram (post, carrusel, reel, story), páginas de Facebook, YouTube (vídeo y short), TikTok, Bluesky (app password; texto, imágenes y vídeo) y Threads (texto, imagen, vídeo y carrusel), con cola con reintentos y calendario.
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
npx playwright test -c playwright.roadmap.config.ts # T-43: SQLite desechable en localhost:8081
```

CI (`.github/workflows/ci.yml`) ejecuta Pint y la suite Pest en cada push y PR.

La batería general (`npm run test:e2e`) usa un servidor propio en `localhost:8080`, SQLite y almacenamiento desechables y cuatro usuarios de prueba. La QA de T-77 del 2026-10-31 pasa 502 casos y omite los dos previstos del shell de escritorio en móvil, sin fallos ni flaky. La [guía de T-43](docs/guias/pruebas-t43.md) explica qué configuración elegir y dónde quedan las evidencias.

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
| Pruebas aisladas del roadmap | [`docs/guias/pruebas-t43.md`](docs/guias/pruebas-t43.md) |
| Decisiones de arquitectura (ADR) | [`docs/knowledge/adr/`](docs/knowledge/adr) |
| Roadmap y ledgers | [`docs/roadmap/`](docs/roadmap/README.md) |
| Cambios | [`CHANGELOG.md`](CHANGELOG.md) · [`CHANGELOG.es.md`](CHANGELOG.es.md) |

## Despliegue y operación

Ver [`docs/modulos/despliegue.md`](docs/modulos/despliegue.md), [`deploy/hestia/README.md`](deploy/hestia/README.md) (HestiaCP y Nginx) y [`deploy/systemd/`](deploy/systemd) (workers de cola).
