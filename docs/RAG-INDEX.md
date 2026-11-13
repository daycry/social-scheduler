# Índice para IA / RAG

Última actualización: 2026-11-10. Cada fila: documento, qué responde y palabras clave.

| Documento | Responde a | Palabras clave |
|---|---|---|
| [README.md](README.md) | Mapa de la documentación | índice, estado |
| [guias/estudio-creativo.md](guias/estudio-creativo.md) | Diseños editables por marca, guardado, exportación y borrador | Studio, Fabric, páginas, revisión, kit, PNG, SVG, PDF |
| [guias/estudio-api-mcp.md](guias/estudio-api-mcp.md) | API v1, herramientas Studio, permisos (incluidos `canva:*` y `studio:ai`) y renderer | designs:read, designs:write, paginación, Chromium, media, jobs |
| [Auditoría de métricas](roadmap/2026-11-03-creative-social-suite/testing/metrics/audit-2026-11-03.md) | Exactitud, cobertura, frescura y contraste Meta local | MetricCoverage, ComparisonQuality, YouTube, snapshots, pares cuenta/día |
| [Inventario suite](roadmap/2026-11-03-creative-social-suite/reference-inventory.md) | Qué está implementado y qué falta para la paridad solicitada | Canva, Metricool, REA, premium, matriz, límites externos |
| [guias/audio-instagram.md](guias/audio-instagram.md) | Añadir, preescuchar y quitar audio de catálogo en Reels desde web y MCP | `instagram_audio`, `search_instagram_audio`, `get_instagram_audio`, volúmenes, Meta |
| [modulos/estudio.md](modulos/estudio.md) | Mapa del Studio: editor, colaboración, tipografías, audio, datasets, exportaciones | Studio, roles, `studio`, MP4, GIF, datasets |
| [modulos/estudio-ia.md](modulos/estudio-ia.md) | IA de imagen del estudio, proveedores y cuotas | `studio:ai`, `gpt-image-2.5-sunburst`, `gemini-nano-banana-2.1`, `OPENAI_API_KEY`, `GEMINI_API_KEY` |
| [modulos/canva.md](modulos/canva.md) | Canva por marca: alta, plan, origen, cola media y límites | `CANVA_CLIENT_ID`, `canva:read`, `download_host_pattern`, JWKS |
| [guias/linkedin-pinterest.md](guias/linkedin-pinterest.md) | Apps, scopes, interruptores y tablero obligatorio | `LINKEDIN_COMMUNITY_MANAGEMENT`, `LINKEDIN_R_MEMBER_SOCIAL`, `pinterest_board_id` |
| [modulos/media-por-destino.md](modulos/media-por-destino.md) | Media propia, alt y portada por destino | `targets[].media`, `TargetMedia`, `effective_media` |
| [modulos/workflow-publicaciones.md](modulos/workflow-publicaciones.md) | Aprobaciones, series, lotes, colaboradores, manual, reintento | `posts:approve`, `Idempotency-Key`, `batch_posts` |
| [modulos/sitios.md](modulos/sitios.md) | Sitios publicados en `/s/{slug}` | `Site`, `sites:read`, `manage_site`, CSP |
| [arquitectura.md](arquitectura.md) | Capas, flujos, estados de publicación, aislamiento por usuario | `UserScope`, `PostStatus`, colas, scheduler |
| [stack.md](stack.md) | Versiones, dependencias y configuraciones de pruebas | Laravel 13, Inertia, Pest, DomPDF, Sanctum, Playwright, SQLite desechable |
| [modulos/conexiones.md](modulos/conexiones.md) | OAuth de Meta/Google/TikTok, refresco, reconexión | `ConnectorRegistry`, `RefreshTokens`, `requiere_reconexion` |
| [modulos/publicacion.md](modulos/publicacion.md) | Cola, idempotencia, reintentos, recuperación | `PublishPostTarget`, `findPublished`, `max_lateness_hours`, `QuotaPolicy` |
| [modulos/media.md](modulos/media.md) | Subida y validación de media por red y tipo | `MediaUploadService`, `media_specs`, ffprobe |
| [modulos/calendario.md](modulos/calendario.md) | Calendario y reprogramación | `CalendarService`, `posts.reschedule` |
| [modulos/analitica.md](modulos/analitica.md) | Snapshots diarios, backfill, dashboards | `CollectSnapshots`, `Days`, `Calculator`, zona Pacífico |
| [modulos/informes.md](modulos/informes.md) | PDF/CSV, constructor de informes, envío programado y baja, descarga firmada | `ReportService`, `PdfRenderer`, DomPDF |
| [modulos/mcp.md](modulos/mcp.md) | Servidor MCP (64 herramientas), API keys, abilities y conjuntos predefinidos | `SchedulerServer`, `msk_`, Sanctum |
| [modulos/planificacion-avanzada.md](modulos/planificacion-avanzada.md) | Mejores horas, biblioteca, papelera, autolistas | `BestTimesService`, `TrashService`, `AutolistService` |
| [modulos/smartlinks.md](modulos/smartlinks.md) | Link in bio, SafeUrl, denuncia | `SmartLinkService`, `SafeUrl` |
| [modulos/inbox.md](modulos/inbox.md) | Comentarios IG/FB/Threads/YouTube/Bluesky, triaje, presupuesto | `InboxService`, `Comments` |
| [modulos/ads.md](modulos/ads.md) | Meta Ads solo lectura | `Ads`, `AdsService` |
| [modulos/competidores.md](modulos/competidores.md) | Business Discovery, comparativa | `CompetitorService` |
| [modulos/asistente-ia.md](modulos/asistente-ia.md) | Sugerir texto con Claude, topes | `AiAssistant` |
| [modulos/despliegue.md](modulos/despliegue.md) | HestiaCP, cron, systemd, R2/CORS, health | `deploy.sh`, `scheduler-worker@`, `app:health` |
| [referencia/decisiones-por-red.md](referencia/decisiones-por-red.md) | Qué se verificó en el spike y decisión G1 | TikTok borrador, Direct Post, YouTube En producción |
| [referencia/configuracion.md](referencia/configuracion.md) | Todas las variables del `.env.example` | `PUBLISH_QUOTA_POLICY`, `META_OAUTH_METHOD` |
| [referencia/pendientes-manuales.md](referencia/pendientes-manuales.md) | Qué debe hacer una persona | M-01..M-05, M-F6, `/privacy`, `/terms` |
| [guias/desarrollo-local.md](guias/desarrollo-local.md) | Puesta en marcha con Laragon | `composer`, `npm`, ngrok, tests |
| [guias/pruebas-t43.md](guias/pruebas-t43.md) | Ejecutar los E2E aislados del roadmap, localizar evidencias y distinguirlos de la batería general | `playwright.roadmap.config.ts`, T-43, SQLite, `e2e-sec-roadmap-`, ffmpeg, pdftotext |
| [QA general](roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.md) | Regresión de 504 casos en tres tamaños, 502 pasados, dos skips previstos y manuales abiertos | T-77, Playwright, frontera de mes, SHA256, qa-gate |
| [guias/login-social-google-meta.md](guias/login-social-google-meta.md) | Activar el login con Google y Facebook | `GOOGLE_LOGIN_*`, `META_LOGIN_*`, redirect URIs |
| [guias/bluesky-app-password.md](guias/bluesky-app-password.md) | Crear la app password de Bluesky y conectarla | app password, email verificado, vídeo, revocar |
| [guias/threads-app-meta.md](guias/threads-app-meta.md) | Crear la app de Meta para Threads y conectarla | THREADS_APP_ID, THREADS_APP_SECRET, tester, URI de redirección, App Review |
| [usuario/guia-de-usuario.md](usuario/guia-de-usuario.md) | Uso de la app | marcas, conectar, programar, informes |
| [knowledge/README.md](knowledge/README.md) | ADR y gotchas con su estado canónico | ADR-001, ADR-002, ADR-003, ADR-004, ADR-005 |

- [Ajustes del compositor](guias/ajustes-compositor.md) · paneles globales y por red, colaboradores, IA, título de Facebook y errores; inventario observado y pendientes.
