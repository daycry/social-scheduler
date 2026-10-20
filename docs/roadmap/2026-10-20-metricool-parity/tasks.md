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
- **Archivos**: `resources/js/Layouts/**`, `resources/js/Components/**`, `resources/js/navigation.js`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Support/CurrentBrand.php`, `tests/Feature/Ui/ShellTest.php`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `npm run build` → exit 0

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
- **Archivos**: `app/Services/BestTimesService.php`, `app/Http/Controllers/BestTimesController.php`, `app/Mcp/Tools/GetBestTimes.php`, `app/Mcp/Servers/SchedulerServer.php`, `config/social.php`, `routes/parity.php`, `routes/web.php`, `resources/js/Pages/BestTimes/Index.vue`, `tests/Feature/Parity/BestTimesTest.php`
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
- **Archivos**: `database/migrations/2026_10_28_090100_add_soft_deletes_to_posts.php`, `app/Models/Post.php`, `app/Policies/PostPolicy.php`, `app/Services/TrashService.php`, `app/Http/Controllers/TrashController.php`, `app/Console/Commands/PurgeTrash.php`, `routes/console.php`, `routes/parity.php`, `resources/js/Pages/Trash/Index.vue`, `tests/Feature/Parity/TrashTest.php`, `tests/Feature/Posts/PostsTest.php`, `tests/Feature/Publishing/RevisionFase3Intento2Test.php`
- **Cubre (tests)**: PA-06
- **Verificación**:
  - `vendor/bin/pest tests/Feature/Parity/TrashTest.php` → verde
  - salida real: vendor/bin/pest tests/Feature/Parity/TrashTest.php → 8 passed; tests antiguos de borrado (PostsTest, RevisionFase3Intento2Test) actualizados a soft delete
- **Notas**: Decisión: restaurar un post cuya hora ya venció lo devuelve a borrador (unschedule) para no publicar de golpe; la purga se salta destinos en vuelo (Publishing, o Processing con cuenta activa).

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
- **Archivos**: `database/migrations/2026_10_28_090200_create_smart_links_tables.php`, `app/Models/SmartLink.php`, `app/Models/SmartLinkBlock.php`, `app/Policies/SmartLinkPolicy.php`, `app/Support/SafeUrl.php`, `app/Services/SmartLinkService.php`, `app/Http/Controllers/SmartLinkController.php`, `app/Http/Controllers/PublicSmartLinkController.php`, `resources/views/smartlinks/show.blade.php`, `routes/parity.php`, `resources/js/Pages/SmartLinks/Index.vue`, `tests/Feature/Parity/SmartLinkTest.php`
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
- **Archivos**: `database/migrations/2026_10_28_090300_create_competitors_tables.php`, `app/Models/Competitor.php`, `app/Models/CompetitorSnapshot.php`, `app/Policies/CompetitorPolicy.php`, `app/Connectors/Meta/BusinessDiscovery.php`, `app/Connectors/Meta/CompetitorNotFound.php`, `app/Services/CompetitorService.php`, `app/Jobs/CollectCompetitorSnapshot.php`, `app/Jobs/CollectCompetitorSnapshots.php`, `app/Http/Controllers/CompetitorController.php`, `routes/console.php`, `routes/parity.php`, `config/social.php`, `resources/js/Pages/Competitors/Index.vue`, `tests/Feature/Parity/CompetitorTest.php`
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
- **Archivos**: `app/Models/Autolist*.php`, `app/Services/AutolistService.php`, `app/Jobs/GenerateAutolistPosts.php`, `app/Http/Controllers/AutolistController.php`, `app/Policies/AutolistPolicy.php`, `database/migrations/2026_10_29_090000_create_autolists_tables.php`, `resources/js/Pages/Autolists/Index.vue`, `routes/parity.php`, `routes/console.php`, `config/social.php`, `.env.example`, `tests/Feature/Parity/AutolistTest.php`
- **Cubre (tests)**: PA-—
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
- **Archivos**: `app/Connectors/Meta/Comments.php`, `app/Services/InboxService.php`, `app/Jobs/SyncInboxComments.php`, `app/Jobs/SyncAccountComments.php`, `app/Models/InboxComment.php`, `app/Policies/InboxCommentPolicy.php`, `app/Http/Controllers/InboxController.php`, `app/Mcp/**`, `database/migrations/2026_10_29_100000_create_inbox_comments_table.php`, `resources/js/Pages/Inbox/Index.vue`, `routes/parity.php`, `routes/console.php`, `config/social.php`, `tests/Feature/Parity/InboxTest.php`
- **Cubre (tests)**: PA-—
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
- **Archivos**: `app/Connectors/Meta/Ads.php`, `app/Services/AdsService.php`, `app/Http/Controllers/AdsController.php`, `resources/js/Pages/Ads/Index.vue`, `routes/parity.php`, `config/social.php`, `tests/Feature/Parity/AdsTest.php`
- **Cubre (tests)**: PA-—
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
- **Archivos**: `app/Services/AiAssistant.php`, `app/Http/Controllers/AiAssistantController.php`, `resources/js/Components/AiSuggest.vue`, `routes/parity.php`, `config/social.php`, `.env.example`, `tests/Feature/Parity/AiAssistantTest.php`
- **Cubre (tests)**: PA-—
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
- **Archivos**: `playwright.config.ts`, `tests/e2e/**`, `package.json`, `package-lock.json`, `.gitignore`
- **Cubre (tests)**: PA-—
- **Verificación**:
  - `php artisan test` → verde y E2E documentado por qa

**Criterios de aceptación**
- [x] PA-12: batería E2E (Playwright, desktop 1440x900 y móvil 375x812) y axe (WCAG 2 A/AA) sin violaciones critical/serious

**Evidencia** (`npm run test:e2e` contra http://127.0.0.1:8080): `123 passed, 1 skipped (3.2m)` — la omitida es la prueba de la barra lateral de escritorio en el proyecto móvil. Cubre: portada, login, registro, recuperar, privacy, terms, dashboard (+4 redes), calendario (semana/mes/lista), crear publicación, marcas (+editar), conexiones, informes, api-keys, avisos, perfil, mejores horas, biblioteca, papelera, smartlinks, competidores, autolistas, inbox y Meta Ads (status<400, sin errores de consola/pageerror, sin desbordamiento horizontal, con h1); flujos registro→verificar correo, marca→borrador→lista→biblioteca→papelera→restaurar→usar plantilla, SmartLink público sin cookies con redirección del clic y conteo, y API key crear/revocar; axe `@axe-core/playwright` 4.10.2 en 29 páginas × 2 viewports (56 checks): 0 violaciones critical/serious. Los datos de prueba llevan prefijo `e2e-` y se limpian (también al arrancar). `php artisan test` 767 verdes, `pint --test` ok, `npm run build` ok.
