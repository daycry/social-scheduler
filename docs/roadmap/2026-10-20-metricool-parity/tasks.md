---
verificacion: obligatoria
---

# Checklist de Tareas — Paridad funcional y de experiencia con Metricool

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-20 |
| **Spec** | [`spec.md`](./spec.md) |
| **Plan** | vía rápida (sin `improvement-plan.md`): el alcance es la tabla de decisiones de la spec |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> **Reparto.** P1 (T-01..T-05) la implementa el «agente UI» en paralelo; este implementer solo ejecuta P2 (T-06..T-10) y no toca Layouts, Components, páginas existentes, `HandleInertiaRequests` ni `lang/`.

## Resumen de progreso


| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|---|---|---|---|
| Fase 1 — P1 — UI: portada, auth, shell, estados vacíos y ajustes (agente UI) | 5 | 5 | 100% | — | — | — | — |
| Fase 2 — P2 — Backend y páginas: mejores horas, biblioteca, papelera, SmartLinks, competidores | 5 | 5 | 100% | — | — | — | — |
| Fase 3 — P3 — Autolistas, inbox, Meta Ads solo lectura y asistente IA | 4 | 4 | 100% | — | — | — | — |
| Fase 4 — P4 — QA E2E y accesibilidad | 1 | 1 | 100% | — | — | — | — |
| **TOTAL** | **15** | **15** | **100%** | — | — | — | — |

---


## Fase 1 — P1 — UI: portada, auth, shell, estados vacíos y ajustes (agente UI)

**Estado**: completado · **Estimado**: —


### T-01 — Portada pública (Welcome)

- **Descripción**: Portada con propuesta de valor, módulos, FAQ, CTA y enlaces legales. Asignada a «agente UI» (no la implementa el implementer de P2).
- **Changelog**: Nueva portada pública en español con módulos, preguntas frecuentes y acceso a registro.
- **Estado**: completado
- **Asignada a**: agente UI
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Welcome.vue`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `npm run build` → exit 0
  - lectura: Welcome.vue contiene secciones de módulos, FAQ y CTA
  - salida real: `npm run build` → `built` (exit 0); `npm run test:e2e` → portada, privacidad y términos: 184 passed, 2 skipped (con axe)

**Criterios de aceptación**
- [x] PA-01/PA-02: sin texto de Laravel; enlaces a /privacy y /terms

**Evidencia**: Welcome con módulos, FAQ, CTA y enlaces a /privacy y /terms; `npx playwright test` (portada, privacidad, términos: status<400, sin errores de consola, sin desbordamiento, h1) y axe sin violaciones críticas/serias.


### T-02 — Autenticación en español

- **Descripción**: Login, registro, recuperar y verificar email en español con identidad propia. Asignada a «agente UI».
- **Changelog**: Pantallas de acceso, registro y recuperación de contraseña en español.
- **Estado**: completado
- **Asignada a**: agente UI
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Auth/**`, `lang/**`, `tests/Feature/Auth/TransactionalMailTest.php`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `php artisan test --filter=Auth` → verde
  - salida real: `php artisan test --filter=Auth` → `85 passed (484 assertions)`

**Criterios de aceptación**
- [x] PA-01: ninguna pantalla de auth con textos en inglés de Breeze

**Evidencia**: login/registro/recuperar en español; correos de verificación y reset en español con el nombre de la app (`lang/es.json`, `TransactionalMailTest`); E2E de registro → aviso de verificar correo.


### T-03 — Shell con selector de marca y barra lateral por módulo

- **Descripción**: Barra superior con módulos, selector de marca persistente, campana, menú de usuario y lateral contextual, responsive. Asignada a «agente UI».
- **Changelog**: Nuevo panel con selector de marca persistente, navegación por módulos y diseño adaptable a móvil.
- **Estado**: completado
- **Asignada a**: agente UI
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: frontend
- **Archivos**: `resources/js/Layouts/**`, `resources/js/Components/**`, `resources/js/navigation.js`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Support/CurrentBrand.php`, `tests/Feature/Ui/ShellTest.php`, `resources/js/Pages/Dashboard/Partials/NetworkTabs.vue`, `resources/js/Pages/Calendar/Index.vue`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `npm run build` → exit 0
  - salida real: `npm run build` → `built` (exit 0); `php artisan test --filter=Shell` → `11 passed (174 assertions)`

**Criterios de aceptación**
- [x] PA-03: selector de marca persistente; responsive 360 px

**Evidencia**: shell con módulos Analítica/Planificación/Informes/Conexiones/SmartLinks/Inbox (si existe la ruta), laterales con Mejores horas, Biblioteca, Autolistas, Papelera, Competidores y Meta Ads; cabecera sin desbordar a 375 px (logo compacto, selector de marca recortado); contador de Inbox sin leer en `HandleInertiaRequests` (`unreadInbox`, 1 COUNT). E2E: 0 desbordamiento en todas las páginas a 375 px.


### T-04 — Estados vacíos con CTA de conexión

- **Descripción**: Cada red sin cuenta muestra estado vacío con CTA que inicia el OAuth. Asignada a «agente UI».
- **Changelog**: Las redes sin conectar muestran un aviso con botón para conectarlas.
- **Estado**: completado
- **Asignada a**: agente UI
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: T-03
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Dashboard/**`, `resources/js/Pages/{BestTimes,Library,Trash,SmartLinks,Competitors}/Index.vue`, `app/Http/Controllers/LibraryController.php`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `php artisan test --filter=Dashboard` → verde
  - salida real: `php artisan test --filter=Dashboard` → `12 passed (144 assertions)`

**Criterios de aceptación**
- [x] PA-04: estado vacío con CTA por red

**Evidencia**: Dashboard/Competidores/Mejores horas/Biblioteca/Papelera/SmartLinks usan `FeatureEmpty` con CTA; `php artisan test` verde.


### T-05 — Ajustes de marca

- **Descripción**: Página de ajustes de marca (nombre, zona horaria, avatar, redes). Asignada a «agente UI».
- **Changelog**: Nueva página de ajustes de marca con nombre, zona horaria y avatar.
- **Estado**: completado
- **Asignada a**: agente UI
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: T-03
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Brands/**`, `app/Http/Controllers/BrandController.php`, `app/Http/Requests/BrandRequest.php`, `app/Models/Brand.php`, `database/migrations/2026_10_29_090000_add_avatar_to_brands.php`, `tests/Feature/Ui/BrandSettingsTest.php`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `php artisan test --filter=Brand` → verde
  - salida real: `php artisan test --filter=Brand` → `37 passed (177 assertions)`

**Criterios de aceptación**
- [x] Ajustes editables y persistidos

**Evidencia**: nombre, descripción, zona horaria y avatar (imagen de la media del usuario, migración `add_avatar_to_brands`, validada contra media propia) editables; `BrandSettingsTest` (3 tests). Pendiente menor: el selector de marca del shell sigue mostrando la inicial, no el avatar.


## Fase 2 — P2 — Backend y páginas: mejores horas, biblioteca, papelera, SmartLinks, competidores

**Estado**: completado · **Estimado**: —


### T-06 — Mejores horas para publicar (PA-05)

- **Descripción**: Mapa de calor 7x24 por red: agrega `post_metric_snapshots` por día-semana y hora de `published_at` en la zona de la marca, con mínimo de N publicaciones y engagement medio. Herramienta MCP `get_best_times`.
- **Changelog**: Nueva sección «Mejores horas» con mapa de calor semanal por red a partir de tus propias métricas.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. 6h · real ≈0.15h (estimado; meter degradó por falta de marcador, P2 hecha en conjunto)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/BestTimesService.php`, `app/Http/Controllers/BestTimesController.php`, `app/Mcp/Tools/GetBestTimes.php`, `app/Mcp/Servers/SchedulerServer.php`, `config/social.php`, `routes/parity.php`, `routes/web.php`, `resources/js/Pages/BestTimes/Index.vue`, `tests/Feature/Parity/BestTimesTest.php`, `config/app.php`, `phpunit.xml`
- **Cubre (tests)**: PA-05
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/BestTimesTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/BestTimesTest.php → 5 passed (54 assertions); suite completa 724 passed

**Criterios de aceptación**
- [x] Agrega por día-semana x hora en la zona de la marca y por red
- [x] Devuelve `available=false` si hay menos de N publicaciones
- [x] Aislamiento por usuario (marca ajena -> 404 / error MCP)
- [x] MCP `get_best_times` con ability stats:read


### T-07 — Biblioteca de plantillas (PA-06)

- **Descripción**: Guardar un post/borrador como plantilla reutilizable (texto, media y redes) y «usar» crea un borrador.
- **Changelog**: Guarda publicaciones como plantillas y créalas de nuevo como borrador con un clic.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. 6h · real ≈0.1h (estimado)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `database/migrations/2026_10_28_090000_create_post_templates_tables.php`, `app/Models/PostTemplate.php`, `app/Policies/PostTemplatePolicy.php`, `app/Services/LibraryService.php`, `app/Http/Controllers/LibraryController.php`, `routes/parity.php`, `resources/js/Pages/Library/Index.vue`, `tests/Feature/Parity/LibraryTest.php`
- **Cubre (tests)**: PA-06
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/LibraryTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/LibraryTest.php → 7 passed

**Criterios de aceptación**
- [x] Guardar desde un post copia texto, media y destinos
- [x] «Usar» crea un borrador (nunca programado) con los destinos válidos
- [x] Plantillas aisladas por usuario


### T-08 — Papelera con restaurar y purga a 30 días (PA-06)

- **Descripción**: Borrar un Post lo envía a la papelera (soft delete; los destinos en vuelo no se borran: `lockEditableTargets`), restaurar (vuelve a borrador si ya venció) y comando diario de purga a 30 días.
- **Changelog**: Las publicaciones borradas van a una papelera, se pueden restaurar y se eliminan a los 30 días.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. 6h · real ≈0.1h (estimado)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `database/migrations/2026_10_28_090100_add_soft_deletes_to_posts.php`, `app/Models/Post.php`, `app/Policies/PostPolicy.php`, `app/Services/TrashService.php`, `app/Http/Controllers/TrashController.php`, `app/Console/Commands/PurgeTrash.php`, `routes/console.php`, `routes/parity.php`, `resources/js/Pages/Trash/Index.vue`, `tests/Feature/Parity/TrashTest.php`, `tests/Feature/Posts/PostsTest.php`, `tests/Feature/Publishing/RevisionFase3Intento2Test.php`, `app/Services/PostService.php`, `app/Services/PublishRecovery.php`, `app/Services/PublishRequestFactory.php`, `docs/modulos/planificacion-avanzada.md`, `docs/modulos/calendario.md`
- **Cubre (tests)**: PA-06
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/TrashTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/TrashTest.php → 8 passed; tests antiguos de borrado (PostsTest, RevisionFase3Intento2Test) actualizados a soft delete
- **Notas**: Decisión: restaurar un post cuya hora ya venció lo devuelve a borrador (unschedule) para no publicar de golpe; la purga se salta destinos en vuelo (Publishing, o Processing con cuenta activa). Revisión intento 1 (B1/B8): al enviar a la papelera los destinos `programada` pasan a `borrador` (restaurar siempre devuelve borrador), la recuperación y el despacho excluyen posts borrados y aíslan cada destino, y restore+unschedule van en una transacción. `TrashTest` → 12 passed.

**Criterios de aceptación**
- [x] Borrar = soft delete; en vuelo/publicada sigue rechazada
- [x] Restaurar devuelve el post sin programar si la fecha ya pasó
- [x] `posts:purge-trash` elimina >30 días y deja el resto
- [x] Los posts en papelera no se publican


### T-09 — SmartLinks: página «link in bio» (PA-07)

- **Descripción**: Microsite por marca (slug único, título, bio, avatar, bloques enlace/cabecera, tema). Página pública `/l/{slug}` sin sesión ni cookies y con Open Graph; `/l/{slug}/go/{block}` cuenta clics agregados por día sin datos personales; URLs solo http/https.
- **Changelog**: Crea tu página de enlaces en bio con visitas y clics contados sin guardar datos personales.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. 10h · real ≈0.15h (estimado)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `database/migrations/2026_10_28_090200_create_smart_links_tables.php`, `app/Models/SmartLink.php`, `app/Models/SmartLinkBlock.php`, `app/Policies/SmartLinkPolicy.php`, `app/Support/SafeUrl.php`, `app/Services/SmartLinkService.php`, `app/Http/Controllers/SmartLinkController.php`, `app/Http/Controllers/PublicSmartLinkController.php`, `resources/views/smartlinks/show.blade.php`, `routes/parity.php`, `resources/js/Pages/SmartLinks/Index.vue`, `tests/Feature/Parity/SmartLinkTest.php`, `resources/views/legal/privacy.blade.php`, `routes/auth.php`, `resources/js/Pages/Auth/Login.vue`, `docs/modulos/smartlinks.md`
- **Cubre (tests)**: PA-07
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/SmartLinkTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/SmartLinkTest.php → 10 passed
- **Notas**: Decisión: un SmartLink por marca; rutas públicas sin EncryptCookies/StartSession/ShareErrors/CSRF (incl. PreventRequestForgery de Laravel 13)/HandleInertiaRequests; contadores diarios agregados (smart_link_views / smart_link_clicks), sin IP ni agente.

**Criterios de aceptación**
- [x] Página pública sin login ni Set-Cookie, con meta Open Graph
- [x] Clic redirige y cuenta; sin IP ni datos personales en BD
- [x] URL `javascript:`/`data:` rechazada al guardar y al redirigir
- [x] Gestión aislada por usuario; slug único


### T-10 — Competidores de Instagram (PA-08)

- **Descripción**: Business Discovery de Instagram: añadir @usuario Business/Creator, snapshot diario de seguidores/publicaciones (cola `metrics`, `$timeout` < 120) y comparativa con la cuenta propia.
- **Changelog**: Compara tu cuenta de Instagram con la de otros perfiles profesionales y sigue su evolución diaria.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. 8h · real ≈0.15h (estimado)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `database/migrations/2026_10_28_090300_create_competitors_tables.php`, `app/Models/Competitor.php`, `app/Models/CompetitorSnapshot.php`, `app/Policies/CompetitorPolicy.php`, `app/Connectors/Meta/BusinessDiscovery.php`, `app/Connectors/Meta/CompetitorNotFound.php`, `app/Services/CompetitorService.php`, `app/Jobs/CollectCompetitorSnapshot.php`, `app/Jobs/CollectCompetitorSnapshots.php`, `app/Http/Controllers/CompetitorController.php`, `routes/console.php`, `routes/parity.php`, `config/social.php`, `resources/js/Pages/Competitors/Index.vue`, `tests/Feature/Parity/CompetitorTest.php`, `app/Connectors/Meta/Insights.php`, `tests/Feature/Connectors/Meta/Insights/MetaInsightsTest.php`, `docs/modulos/competidores.md`
- **Cubre (tests)**: PA-08
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/CompetitorTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/CompetitorTest.php → 9 passed; pint --test passed; php artisan test → 724 passed; npm run build → exit 0
- **Notas**: Business Discovery implementada según la doc oficial conocida (fields=business_discovery.username(X){...}, Bearer, lectura); no se pudo consultar Context7/WebFetch desde este agente. Error de cuenta inexistente/no profesional: code 110 / subcode 2207013 -> CompetitorNotFound.

**Criterios de aceptación**
- [x] Consulta `business_discovery.username(X){...}` con Bearer y lectura
- [x] Snapshot diario idempotente en la cola `metrics` con `$timeout` < 120
- [x] Usuario inexistente/no profesional -> error legible, sin crear
- [x] Aislamiento por usuario; sin llamadas reales en tests


## Fase 3 — P3 — Autolistas, inbox, Meta Ads solo lectura y asistente IA

**Estado**: completado · **Estimado**: —


### T-11 — Autolistas (PA-09)

- **Descripción**: Colas con franjas semanales que generan publicaciones programadas.
- **Changelog**: Colas de contenido recurrente con franjas semanales.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real 0.49h (medido)
- **Supervisión**: est. — · real —
- **Dependencias**: T-07
- **Tipo**: backend
- **Archivos**: `app/Models/Autolist*.php`, `app/Services/AutolistService.php`, `app/Jobs/GenerateAutolistPosts.php`, `app/Http/Controllers/AutolistController.php`, `app/Policies/AutolistPolicy.php`, `database/migrations/2026_10_29_090000_create_autolists_tables.php`, `resources/js/Pages/Autolists/Index.vue`, `routes/parity.php`, `routes/console.php`, `config/social.php`, `.env.example`, `tests/Feature/Parity/AutolistTest.php`, `database/migrations/2026_11_02_090000_autolist_slots_local_key_and_item_cursor.php`, `docs/modulos/planificacion-avanzada.md`
- **Cubre (tests)**: PA-09
- **Verificación**:
  - `php artisan test tests/Feature/Parity/AutolistTest.php` → 9 passed (72 assertions)

**Criterios de aceptación**
- [x] PA-09: franjas semanales (día+hora en la zona de la marca), cola (plantillas o texto+media), reciclado opcional y job diario idempotente con PostService


### T-12 — Inbox de comentarios IG/FB (PA-10)

- **Descripción**: Listar, responder y ocultar comentarios de publicaciones propias (scopes de comentarios).
- **Changelog**: Responde y oculta comentarios de Instagram y Facebook desde un único buzón.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real 0.41h (medido)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Comments.php`, `app/Services/InboxService.php`, `app/Jobs/SyncInboxComments.php`, `app/Jobs/SyncAccountComments.php`, `app/Models/InboxComment.php`, `app/Policies/InboxCommentPolicy.php`, `app/Http/Controllers/InboxController.php`, `app/Mcp/**`, `database/migrations/2026_10_29_100000_create_inbox_comments_table.php`, `resources/js/Pages/Inbox/Index.vue`, `routes/parity.php`, `routes/console.php`, `config/social.php`, `tests/Feature/Parity/InboxTest.php`, `app/Mcp/Tools/ListComments.php`, `app/Mcp/Tools/ReplyComment.php`, `docs/modulos/inbox.md`
- **Cubre (tests)**: PA-10
- **Verificación**:
  - `php artisan test tests/Feature/Parity/InboxTest.php` → 13 passed (127 assertions); `tests/Feature/Mcp` + `tests/Feature/Parity` → 103 passed

**Criterios de aceptación**
- [x] PA-10: leer comentarios de publicaciones propias de IG/FB (Graph v23, Bearer), sincronización en cola poll, responder y ocultar/mostrar, filtros red/no leídos, aislamiento por usuario, sin DMs


### T-13 — Meta Ads solo lectura (PA-11)

- **Descripción**: Cuentas publicitarias, campañas y métricas básicas con `ads_read`.
- **Changelog**: Consulta tus campañas y métricas de Meta Ads en modo lectura.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real 0.2h (medido)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Connectors/Meta/Ads.php`, `app/Services/AdsService.php`, `app/Http/Controllers/AdsController.php`, `resources/js/Pages/Ads/Index.vue`, `routes/parity.php`, `config/social.php`, `tests/Feature/Parity/AdsTest.php`, `docs/modulos/ads.md`
- **Cubre (tests)**: PA-11
- **Verificación**:
  - `php artisan test tests/Feature/Parity/AdsTest.php` → 8 passed (159 assertions)

**Criterios de aceptación**
- [x] PA-11: scope ads_read; cuentas publicitarias (me/adaccounts), campañas e insights básicos (gasto, impresiones, clics, CTR, CPC) por periodo, solo lectura; sin permiso -> no disponible con instrucción


### T-14 — Asistente IA opcional

- **Descripción**: Generación de textos tras el flag `AI_ASSISTANT` con Claude API si hay clave.
- **Changelog**: Asistente opcional para proponer textos de publicaciones.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real 0.08h (medido)
- **Supervisión**: est. — · real —
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/AiAssistant.php`, `app/Http/Controllers/AiAssistantController.php`, `resources/js/Components/AiSuggest.vue`, `routes/parity.php`, `config/social.php`, `.env.example`, `tests/Feature/Parity/AiAssistantTest.php`, `docs/modulos/asistente-ia.md`
- **Cubre (tests)**: PA-12 (calidad y privacidad del asistente opcional)
- **Verificación**:
  - `php artisan test` → 761 passed (5705 assertions); `vendor/bin/pint --test app routes config database tests` → passed; `npm run build` → built

**Criterios de aceptación**
- [x] Desactivado por defecto: sin AI_ASSISTANT_ENABLED y ANTHROPIC_API_KEY la UI no muestra «Sugerir texto»; con ambos usa Messages API (claude-sonnet-5-5) por Http, límite diario por usuario y sin datos personales


## Fase 4 — P4 — QA E2E y accesibilidad

**Estado**: completado · **Estimado**: —


### T-15 — QA E2E y accesibilidad (PA-12)

- **Descripción**: Suite verde, revisión adversarial, E2E en navegador de los flujos principales y revisión de accesibilidad.
- **Changelog**: Revisión final de calidad y accesibilidad de toda la aplicación.
- **Estado**: completado
- **Asignada a**: implementer
- **Tiempo humano**: est. 0h · real —
- **Tiempo IA (ejec.)**: est. —h · real —
- **Supervisión**: est. — · real —
- **Dependencias**: T-01..T-14
- **Tipo**: qa
- **Archivos**: `playwright.config.ts`, `tests/e2e/**`, `package.json`, `package-lock.json`, `.gitignore`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/referencia/configuracion.md`, `.env.example`, `docs/roadmap/2026-10-20-metricool-parity/.claude/**`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `php artisan test` → verde y E2E documentado por qa

**Criterios de aceptación**
- [x] PA-12: batería E2E (Playwright, desktop 1440x900, móvil 375x812 y móvil 360x740) y axe (WCAG 2 A/AA) sin violaciones critical/serious

**Evidencia (inicial, antes de la revisión)** (`npm run test:e2e` contra http://127.0.0.1:8080): `123 passed, 1 skipped (3.2m)` — la omitida es la prueba de la barra lateral de escritorio en el proyecto móvil. Cubre: portada, login, registro, recuperar, privacy, terms, dashboard (+4 redes), calendario (semana/mes/lista), crear publicación, marcas (+editar), conexiones, informes, api-keys, avisos, perfil, mejores horas, biblioteca, papelera, smartlinks, competidores, autolistas, inbox y Meta Ads (status<400, sin errores de consola/pageerror, sin desbordamiento horizontal, con h1); flujos registro→verificar correo, marca→borrador→lista→biblioteca→papelera→restaurar→usar plantilla, SmartLink público sin cookies con redirección del clic y conteo, y API key crear/revocar; axe `@axe-core/playwright` 4.10.2 en 29 páginas × 2 viewports (56 checks): 0 violaciones critical/serious. Los datos de prueba llevan prefijo `e2e-` y se limpian (también al arrancar). `php artisan test` 767 verdes, `pint --test` ok, `npm run build` ok.

**Evidencia (tras la revisión intento 1)**: `npm run test:e2e` → `184 passed, 2 skipped` (desktop, mobile 375 y mobile-360, con axe: 0 violaciones critical/serious y 0 px de desbordamiento horizontal); `php artisan test` → `787 passed (5845 assertions)`; `vendor/bin/pint --test` → `passed`; `npm run build` → `built`.

---

## Revisión de dos lentes — intento 1: Paridad (T-01..T-15) — 1 Critical, 5 Important, 14 Minor

Corrección aplicada por el implementer tras la revisión (4 lentes + crítica de diseño). Cada gap se verificó contra el código antes de corregirlo; ninguno resultó incorrecto. Salidas completas de la verificación al final de la sección.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Critical | Un post en la papelera tumba el despachador: `PublishRecovery` → `PublishOutcomes::failed` → `PostService::transition` hace `findOrFail` sin papelera y el `ModelNotFoundException` aborta `DispatchDuePosts` cada minuto para todos | T-08 | Todas las consultas de recuperación/despacho/repoll filtran destinos de posts vivos (`livePosts()`); `transition` y `PublishRequestFactory` usan `withTrashed()` sobre destinos ya existentes; cada destino en recovery va aislado (`guard()` con `try/catch` + log) y `failInactiveProcessing` captura `Throwable`; al enviar a la papelera, `PostService::delete` pasa los destinos `programada` a `borrador` y cierra como fallido el `procesando` huérfano | `TrashTest`: 12 passed — escenarios huérfano (cuenta borrada) y `procesando` con cuenta desconectada con un post vivo que SÍ se despacha, repoll que ignora la papelera y transición con `withTrashed` |
| B2 | Important | Cambiar la zona horaria de la marca duplica franjas de autolistas | T-11 | Migración `2026_11_02_090000`: `autolist_slots.local_date/local_time` con unicidad por (lista, fecha local, hora local); `fillSlot` reconoce la franja por clave local y `realign()` mueve (reschedule) la publicación futura sin publicar al nuevo instante en vez de crear otra | `AutolistTest` B2: 2 posts y 2 slots tras cambiar a America/New_York, horas 15:00/23:30 UTC |
| B3 | Important | Cuenta en `requiere_reconexion` quema franjas y consume elementos | T-11 | Causa temporal: `fillSlot` devuelve `deferred` sin registrar slot ni avanzar el cursor; `generate()` lo cuenta y reintenta en la siguiente pasada mientras la franja sea futura | `AutolistTest` B3: 0 slots/cursor 0 con la cuenta en reconexión; al reactivarla se crean los 2 posts |
| B4 | Important | Inbox sin paginación | T-12 | `Comments::fetch` sigue `paging.next` (tope `SOCIAL_INBOX_MAX_PAGES`) y los hilos de respuestas (`..._MAX_REPLY_PAGES`), con orden explícito más recientes primero. Se descarta `since`: una respuesta nueva a un comentario antiguo no cambia la fecha del padre (se perdería) y la sync ya es idempotente por `upsert` | `InboxTest` B4: orden `p2, p1r2, p1r1, p1`, `after` enviado en comentarios y respuestas, tope respetado |
| B5 | Important | `SyncAccountComments` sin presupuesto de tiempo | T-12 | `InboxService::syncBatch` con presupuesto (`SOCIAL_INBOX_BUDGET_SECONDS`=60 < `timeout` 90); el job es `ShouldBeUniqueUntilProcessing`, recibe `offset` y se reencola como `RefreshPostMetrics` | `InboxTest` B5: con presupuesto agotado lee 1 publicación y encola `offset=1`; con presupuesto normal y `offset=1` termina sin reencolar |
| A1 | Important | T-15 completada antes de la revisión; cabecera/resumen incoherentes | T-15 | T-15 se reabrió durante la corrección y se cierra solo ahora, con la verificación final en verde (evidencia nueva abajo); cabecera de fase, resumen y estado del plan coherentes | `ledger-lint` 0 incoherencias · 0 avisos |
| B6 | Minor | Respuesta propia sin id guarda un `own-…` duplicable | T-12 | `reply()` no guarda nada si Meta no devuelve id (la sync la trae con su id real); con id reutiliza la fila si la sync ya la creó | `InboxTest` B6/B7 |
| B7 | Minor | Carrera reply/sync → 500 tras publicar | T-12 | `upsert` y `reply` capturan `UniqueConstraintViolationException` (se actualiza/reutiliza) y cualquier error al persistir se registra sin llegar al usuario; el comentario se marca leído antes | `InboxTest` B6/B7: una sola fila `c2r` |
| B8 | Minor | Restaurar: restore y unschedule en transacciones separadas | T-08 | `TrashService::restore` hace `restore()` + `unschedule` en UNA transacción (y los destinos ya vuelven a borrador al borrar) | `TrashTest`: restaurar devuelve `borrador` |
| B9 | Minor | Cursor de autolista por índice; plantilla borrada deja huecos | T-11 | Cursor por último elemento (`last_position` + `last_item_id`, con relleno en la migración); `nextItem()` elimina de la cola los elementos sin contenido (plantilla borrada sin texto/media) | `AutolistTest` B9: tras borrar el usado y la plantilla, el siguiente es C y la plantilla desaparece |
| B10 | Minor | Competidores: el `media_count` propio no se obtiene | T-10 | `Insights` pide `followers_count,media_count` de la cuenta IG y lo guarda como `video_count` (stock), que la comparativa ya lee | `CompetitorTest` B10 (`own.media` = 87) y `MetaInsightsTest` |
| B11 | Minor | Mejores horas carga todo el histórico de snapshots | T-06 | Último snapshot por destino en SQL (`MAX(date)` + `joinSub`, una fila por destino) y caché corta `SOCIAL_BEST_TIMES_CACHE_MINUTES` | `BestTimesTest` B11 (6 passed): usa la última lectura y sirve desde caché |
| B12 | Minor | Autolistas: un solo job para todas las listas | T-11 | El job programado reparte y ejecuta un `GenerateAutolistPosts` por lista | `AutolistTest` B12: 2 jobs con su `autolistId` |
| C1 | Minor | SmartLinks sin aviso legal/denuncia ni forma de cerrar el registro | T-09 | Página pública con aviso, «Denunciar esta página» (`SMARTLINKS_REPORT_EMAIL`), `rel="nofollow noopener"`; `REGISTRATION_ENABLED` (true por defecto) que quita las rutas de registro y el enlace «Crear cuenta»; documentado `false` en producción | `SmartLinkTest` C1 y registro: passed |
| C2 | Minor | `SafeUrl` admite Unicode de control/formato, `%00` y hosts de escritura mixta | T-09 | Rechaza `\p{Cc}\p{Cf}\p{Zl}\p{Zp}`, `%00`/`%2500`, punycode inválido y etiquetas con más de una escritura (cirílico+latín…); permite IDN de una sola escritura | `SmartLinkTest` C2: 12 rechazadas, 5 aceptadas |
| C3 | Minor | MCP: texto de terceros sin marcar y `reply_comment` sin cautela | T-12 | `list_comments`: `untrusted: true` por comentario y aviso en la descripción; `reply_comment`: exige confirmación humana del texto exacto | `InboxTest` MCP: passed |
| C4 | Minor | IA: sin tope global y refund negativo en cambio de día | T-14 | `AI_ASSISTANT_GLOBAL_DAILY_LIMIT` (200; 0 = sin tope); la reserva devuelve las claves del día en que se hizo y nunca baja de cero | `AiAssistantTest`: 9 passed (tope global, cambio de día) |
| A2 | Minor | Móvil solo a 375 px | T-15 | Proyecto Playwright `mobile-360` (360×740) además de `mobile` | E2E: 184 passed, 2 skipped, 0 desbordamiento en ambos móviles |
| A3/A4 | Minor | `Cubre (tests)` de T-11..T-14 vacío; sin salida real en la `Verificación` de T-01..T-05 | T-01..T-05, T-11..T-14 | `Cubre` completado (PA-09, PA-10, PA-11; T-14 → PA-12) y salida real pegada en cada `Verificación` | `ledger-lint` 0/0 |
| Otros | Minor | `InboxController` aceptaba `network` sin validar; `BrandRequest` admitía media no lista; política de privacidad desactualizada | T-12, T-05 | `readAll` valida `network` (array → 422); `BrandRequest` exige `status = listo`; `/privacy` menciona comentarios de terceros, competidores, Meta Ads, SmartLinks y, con la IA activa, Anthropic | `InboxTest`, `BrandSettingsTest`: passed |
| Diseño | Diseño | Crítica de diseño: SmartLinks sin vista previa; calendario con h1 «Planificación» y rango largo; chips móviles solo «Resumen»; selector de marca sin avatar | T-03, T-09 | SmartLinks: vista previa en vivo, prefijo `/l/` y ayuda, color swatch+hex, «Ver página pública», clics por bloque con cuota; Calendario: h1 «Calendario» y rango `Intl.DateTimeFormat('es-ES').formatRange` («19–25 oct 2026»); chips con las 4 redes (conectar si falta); avatar de marca en el selector del shell (URL temporal desde `HandleInertiaRequests`) | `npm run build` ok; E2E con axe: 0 violaciones critical/serious |

**Verificación final (tras la corrección)**

- `php artisan test` → `787 passed (5845 assertions)`
- `vendor/bin/pint --test` → `passed`
- `npm run build` → `built`
- `npm run test:e2e` (con axe, desktop 1440×900, mobile 375×812 y mobile-360 360×740) → `184 passed, 2 skipped`
- `ledger-lint.py` → `0 incoherencias · 0 avisos`

## Revisión de dos lentes — intento 2: Paridad — 0 Critical, 0 Important, 7 Minor

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Minor | `AutolistService::realign`: la búsqueda `slot_at = $at OR clave local` sin orden encontraba la franja vecina y chocaba con `unique(autolist_id, slot_at)` al cambiar de zona | T-11 | `fillSlot` busca primero SOLO por la clave local de la franja (y por instante solo si no hay); `realign()` aparca a la ocupante del instante destino en un instante libre lejano para no chocar, y su propia pasada la realinea | `RevisionIntento2Test` N1: Madrid → London con franjas 10:00 y 11:00 en UNA pasada (10:00/11:00 UTC, 2 posts); reproductor `rv2_realign.php` OK |
| N2 | Minor | La migración `2026_11_02_090000` rellenaba el cursor con `min(cursor,count)-1`, ignorando `items[cursor % count]` en listas recicladas | T-11 | Migración original corregida (recicladas: `(cursor-1) % count`) + migración correctora `2026_11_03_090000_fix_autolist_cursor_recycled` (ya aplicada con `migrate --force` en la BD local) para las BD donde la original ya corrió | `RevisionIntento2Test` N2: cursor 4 sobre A,B,C → último usado A (siguiente B) |
| N3 | Minor | `Comments::fetch` no comprobaba el presupuesto entre páginas de comentarios ni de respuestas | T-12 | `fetch(..., ?float $deadline)` comprueba que quepa otra llamada (`social.http_timeout`) antes de cada página y de cada página de respuestas; corta devolviendo lo leído (que `syncBatch` guarda) | `InboxTest` N3: sin hueco → 1 página y 0 de respuestas; con tiempo → pagina |
| N4 | Minor | El reencolado con offset de `SyncAccountComments` se descartaba por el bloqueo de unicidad | T-12 | `uniqueId` = `cuenta:offset`; `Cache::lock` por cuenta (el que llega tarde hace `release(30)`; el lock se libera siempre en `finally`) | `InboxTest` N4: ids distintos por offset; con el lock tomado no lee y se libera; sin él procesa y libera |
| N5 | Minor | `SafeUrl` rechazaba combinaciones legítimas (latín+han/kana/hangul), ZWJ en rutas y `%2500` | T-09 | Regla «highly restrictive» UTS #39 (Latin+Han+Hiragana+Katakana, Latin+Han+Bopomofo, Latin+Han+Hangul); ZWJ permitido solo en ruta/query/fragmento (nunca en el host); solo `%00` es nulo. Documentado que los homógrafos de una escritura (todo cirílico) no se bloquean; la página pública muestra «Destino: host» (punycode) bajo cada botón | `SmartLinkTest` N5 + C2 + C1 (14 passed); `rv2_safeurl.php`: sony…ストア, abc漢字, 한국漢字, emoji ZWJ y `%2500` SAFE; `%00`, ZWJ en host y latín+cirílico rej |
| N6 | Minor | `failInactiveProcessing` trataba como carrera cualquier `Throwable` | T-08 | `InvalidPostTransition` → notice (carrera); otro `Throwable` → `report($e)` + `Log::error` con clase y mensaje; la pasada continúa | `RevisionFase3Intento3Test` N6: el error real se reporta y el segundo destino se cierra (`abandoned` = 1) |
| N7 | Minor | El avatar del selector llevaba una URL temporal nueva en cada navegación (sin caché) | T-03 | Ruta autorizada `brands.avatar` (`/brands/{brand}/avatar?v=<media>`) que redirige a la imagen (URL temporal de 120 min) con `Cache-Control: private, max-age=3600`; el shell usa esa URL estable | `BrandSettingsTest` N7: `avatar_url` idéntico entre peticiones, cabecera `private, max-age=3600`, ajeno/sin avatar → 404 |
| Cosm. | Minor | Comentario de `accent` en `SmartLinks/Index.vue` incoherente | T-09 | Comentario alineado con su comportamiento (color del formulario si es #RRGGBB válido; si no, el por defecto) | `npm run build` ok |
| E2E | Minor | Datos `e2e-*` sobrantes en la BD local | T-15 | Sin restos en la BD local; `globalTeardown` (`tests/e2e/global-teardown.ts`) borra las marcas `e2e-` al terminar la batería | tras la batería: marcas `e2e-` = 0 |

**Verificación (intento 2)**

- `php artisan test` → `794 passed (5888 assertions)`
- `vendor/bin/pint --test` → `passed`
- `npm run build` → `built`
- `npm run test:e2e` → `184 passed, 2 skipped`

Sin gaps pendientes: bucle de revisión de la paridad cerrado en el intento 2
