# Social Scheduler — documentación

Aplicación web personal para programar publicaciones y consultar analítica en Instagram, Facebook, YouTube, TikTok, Bluesky y Threads. Laravel 13 + Inertia/Vue, cola `database` y media en Cloudflare R2.

Última actualización: 2026-10-31

## Por dónde empezar

| Si quieres… | Lee |
|---|---|
| Entender cómo encaja todo | [Arquitectura](arquitectura.md) y [Stack](stack.md) |
| Saber qué se decidió por red (Meta, YouTube, TikTok, Bluesky) | [Decisiones por red](referencia/decisiones-por-red.md) |
| Rellenar el `.env` | [Configuración](referencia/configuracion.md) |
| Levantar el proyecto en tu máquina | [Desarrollo local con Laragon](guias/desarrollo-local.md) |
| Ejecutar la validación T-43 con SQLite desechable y conectores simulados | [Pruebas aisladas de T-43](guias/pruebas-t43.md) |
| Revisar la regresión general en los tres tamaños (502 pasados y dos omitidos previstos) | [Informe de QA](roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.md) · [PDF](roadmap/2026-10-26-metricool-parity-3/testing/attempt-2/report.pdf) |
| Usar la aplicación (incluye doble factor y passkeys) | [Guía de usuario](usuario/guia-de-usuario.md) |
| Operar el doble factor y las passkeys (RP ID, `sodium`, rotar claves) | [Despliegue → Seguridad de cuenta](modulos/despliegue.md#seguridad-de-cuenta-2fa-y-passkeys) |
| Activar el login con Google y Facebook (apps, redirect URIs, modos) | [Guía de login social](guias/login-social-google-meta.md) |
| Conectar un asistente (Claude, Codex, OpenCode) sin API key y desplegar el OAuth del MCP | [Servidor MCP](modulos/mcp.md#autorización-oauth-21-sin-api-key) y [Despliegue → OAuth del MCP](modulos/despliegue.md#oauth-del-mcp-nginxhestia-y-redirect-uris) |
| Activar «Google Drive» en el compositor y reconectar YouTube para el primer comentario | [Guía de Google Cloud](guias/google-cloud-picker-drive.md) |
| Conectar Bluesky con una app password (alta, email verificado para vídeo, revocación) | [Guía de Bluesky](guias/bluesky-app-password.md) |
| Conectar Threads con una app de Meta (ID y secreto de Threads, URI de redirección, tester y App Review) | [Guía de Threads](guias/threads-app-meta.md) |
| Activar el buscador de GIFs (clave de Giphy, límites, atribución «Powered by GIPHY» y términos) | [Guía de Giphy](guias/giphy-api-key.md) |
| Saber qué falta por hacer a mano | [Pendientes manuales](referencia/pendientes-manuales.md) |

## Módulos (documentación técnica)

| Módulo | Página | Código principal |
|---|---|---|
| Conexiones OAuth por red | [conexiones.md](modulos/conexiones.md) | `app/Connectors/`, `app/Services/SocialAccountService.php` |
| Publicación y cola | [publicacion.md](modulos/publicacion.md) | `app/Jobs/PublishPostTarget.php`, `app/Services/PostService.php`, `app/Services/SavedTextService.php` |
| Media, validación e importación (URL y Drive) | [media.md](modulos/media.md) | `app/Services/Media*.php`, `app/Services/Media/`, `app/Jobs/ImportRemoteMedia.php`, `config/media*.php` |
| Calendario y cuadrícula de Instagram | [calendario.md](modulos/calendario.md) | `app/Services/CalendarService.php`, `app/Services/GridService.php` |
| Analítica, snapshots y campañas | [analitica.md](modulos/analitica.md) | `app/Analytics/`, `app/Jobs/CollectSnapshots.php`, `app/Services/CampaignAnalytics.php` |
| Informes PDF/CSV | [informes.md](modulos/informes.md) | `app/Services/ReportService.php` |
| Servidor MCP y API keys | [mcp.md](modulos/mcp.md) | `app/Mcp/`, `routes/ai.php` |
| Planificación avanzada (mejores horas, biblioteca, papelera, autolistas) | [planificacion-avanzada.md](modulos/planificacion-avanzada.md) | `app/Services/{BestTimes,Library,Trash,Autolist}Service.php` |
| SmartLinks | [smartlinks.md](modulos/smartlinks.md) | `app/Services/SmartLinkService.php`, `app/Support/SafeUrl.php` |
| Inbox de comentarios | [inbox.md](modulos/inbox.md) | `app/Services/InboxService.php`, `app/Connectors/Meta/Comments.php` |
| Meta Ads (lectura) | [ads.md](modulos/ads.md) | `app/Connectors/Meta/Ads.php` |
| Competidores de Instagram | [competidores.md](modulos/competidores.md) | `app/Services/CompetitorService.php` |
| Asistente IA | [asistente-ia.md](modulos/asistente-ia.md) | `app/Services/AiAssistant.php` |
| Despliegue (HestiaCP, systemd, R2) y operación de 2FA/passkeys | [despliegue.md](modulos/despliegue.md) | `deploy/`, `config/passkeys.php` |

## Memoria técnica y planificación

- Decisiones: [ADR-001 stack](knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md) y [ADR-002 PDF con DomPDF](knowledge/adr/ADR-002-pdf-dompdf.md) siguen como propuestas. [ADR-003 2FA y passkeys](knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md), [ADR-004 OAuth del MCP y login social](knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md) y [ADR-005 publicación por destino](knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md) están aceptadas. El [índice de conocimiento](knowledge/README.md) conserva su estado y procedencia.
- Roadmap de la iniciativa (gestionado por otros agentes, solo enlazado): [roadmap](roadmap/README.md) · [spec](roadmap/2026-10-19-social-scheduler/spec.md) · [diseño](roadmap/2026-10-19-social-scheduler/design.md) · [informe del spike](roadmap/2026-10-19-social-scheduler/spike-report.md) · [ledger de tareas](roadmap/2026-10-19-social-scheduler/tasks.md) · [plan de pruebas](roadmap/2026-10-19-social-scheduler/test-plan.md).
- Índice para IA/RAG: [RAG-INDEX.md](RAG-INDEX.md).

## Estado del proyecto

La ejecución local final de Pest del 2026-10-31 pasa **2.461 tests y 14.549 aserciones**, incluidos los tres refuerzos literales de Bluesky. Su [JUnit](roadmap/2026-10-19-social-scheduler/testing/raw/phpunit-final-p3.xml) conserva el resultado; la evidencia y los comandos constan en el [ledger principal, T-43](roadmap/2026-10-19-social-scheduler/tasks.md#t-43--validación-final-suite-e2e-pruebas-manuales-y-revisión-de-seguridad).

La revisión focalizada de aislamiento, cifrado y API keys pasa 111 tests y 573 aserciones tras corregir el flash de una API key en sesión. Esa comprobación no acredita DAST ni una auditoría global de ocho dimensiones. `ApiKeyController` cifra el secreto temporal antes de guardarlo en sesión y lo descifra solo para mostrarlo una vez.

El [roadmap principal](roadmap/2026-10-19-social-scheduler/tasks.md) registra **33/43 tareas completadas** tras la aprobación del diseño y las secciones de T-41 por el usuario. La [tercera ola de paridad](roadmap/2026-10-26-metricool-parity-3/tasks.md) mantiene **74/77**: sus smokes reales de Bluesky y Threads siguen pendientes. La QA independiente de T-43 pasa **57 tests en tres proyectos**, sin fallos, flaky ni omitidos; el [informe de QA](roadmap/2026-10-19-social-scheduler/testing/report.md) conserva el veredicto y las evidencias. Las comprobaciones reales pendientes mantienen abiertas sus tareas.

Las comprobaciones con cuentas y servidor reales siguen en [Pendientes manuales](referencia/pendientes-manuales.md). Cada `tasks.md` conserva el estado canónico de su iniciativa.

## Categorías omitidas

- `docs/security-scan/`: evidencia local de `nemesis`, excluida de git y de publicación documental porque puede contener datos sensibles.
- Referencia de API HTTP pública: la app no expone API REST propia; la única interfaz máquina a máquina es el MCP ([mcp.md](modulos/mcp.md)).
- Confluence: no se publica (opt-in no configurado).
