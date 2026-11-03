---
estado: en-progreso
creado: 2026-10-31
actualizado: 2026-11-03
plan: improvement-plan.md
verificacion: obligatoria
---

# Ledger canónico — compositor y audio por red

Fuente única de progreso. Entrega autorizada por «creo que con eso ya sería suficiente». El inventario amplio queda como backlog; sus funciones no se dan por implementadas. Sin Jira configurado.

## Resumen de progreso

| Fase | Completadas | Total | Progreso |
|---|---:|---:|---|
| Fase 1 — Compositor y audio | 12 | 13 | 92 % |

T-01–05 y T-07–12 completadas con verificación local. T-13 corrige el refresco al crear y está verificada localmente; T-06 (smoke real) sigue abierta. [QA actual del refresco](testing/composer-refresh/report.md). La [QA de presets](testing/presets-ui/report.md), T-10 y sus evidencias son históricos. Evidencia inicial: [informe de QA](testing/report.md) y [manifiesto con hashes](testing/validation-summary.json); sus hashes corresponden al código anterior a T-10.

## Fase 1 — Audio por red

### T-01 — Contrato y alcance

- **Estado**: completado
- **Archivos**: `docs/roadmap/2026-10-31-metricool-composer-parity/spec.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/full-parity-backlog.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/improvement-plan.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/discovery.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/feature-matrix.md`
- **Verificación**: consulta HTTP oficial de Meta desde el host → HTTP 200, 14.697 bytes; endpoints `ig_audio`, metadata por ID y `audio_configuration`, User token y volúmenes 1–100 confirmados. El lector web había devuelto 429, ya resuelto. La publicación de ejemplo fue inspeccionada en Edge y su MP4 con ffprobe/volumedetect (detalles en discovery).
- TDD n/a: investigación/documentación.

**Criterios de aceptación**
- [x] Contrato primario verificado y alcance acotado por la última instrucción del usuario.
- [x] Separar audio incluido en vídeo de selección nativa; no acreditar publicación real nueva.

### T-02 — Catálogo seguro compartido

- **Estado**: completado
- **Archivos**: `app/Connectors/Meta/Instagram/InstagramAudio.php`, `app/Http/Controllers/InstagramAudioController.php`, `routes/web.php`, `tests/Feature/Instagram/InstagramAudioTest.php`
- **Verificación**: `php vendor/bin/pest tests/Feature/Instagram/InstagramAudioTest.php --colors=never` → 6 pasados, 35 aserciones. Integrado después en la regresión de 372 tests/2.655 aserciones, sin fallos.
- RED: `InstagramAudioTest::search uses the account User token and returns only safe catalog fields` falló con HTTP 404 en lugar de 200 antes de implementar · 2026-10-31.

**Criterios de aceptación**
- [x] Buscar música/audio original, recuperar metadatos y preescucha; error diferenciado de resultado vacío.
- [x] Propiedad de usuario/marca, credenciales vigentes y URLs seguras; tokens solo en cabecera, sin exposición.
- [x] Revisión independiente y QA final.

### T-03 — Validación, persistencia y publicador

- **Estado**: completado
- **Archivos**: `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php`, `tests/Feature/Publishing/InstagramAudioOptionsTest.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramAudioPublishTest.php`
- **Verificación**: los tres ficheros de tests T-02/T-03 juntos → 20 pasados, 62 aserciones; incluidos después en 372 tests/2.655 aserciones sin fallos.
- RED: `InstagramAudioOptionsTest::other formats cannot silently ignore selected audio` no lanzó `PostValidationException`; `InstagramAudioPublishTest::creates the container with official audio JSON and preserves the checkpoint` falló por ausencia de `audio_configuration` antes de implementar · 2026-10-31. Un error inicial del fixture (`options` reservado por Pest) se corrigió antes de acreditar este rojo.

**Criterios de aceptación**
- [x] Campo tipado por destino, solo Reels elegibles; guardar/reabrir/quitar y valores incompatibles.
- [x] JSON oficial, pista comprobada antes de crear contenedor, sin degradar a publicación sin audio; checkpoint y reintento preservados.
- [x] Revisión independiente y QA final.

### T-04 — Web y MCP

- **Estado**: completado
- **Archivos**: `resources/js/Components/Composer/InstagramAudioField.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Composables/composerOptions.js`, `app/Http/Controllers/PostController.php`, `app/Mcp/Servers/SchedulerServer.php`, `app/Mcp/Tools/ListAccounts.php`, `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/SearchInstagramAudio.php`, `app/Mcp/Tools/GetInstagramAudio.php`, `tests/Feature/Mcp/Tools/McpInstagramAudioTest.php`, `tests/Feature/Mcp/Tools/McpBlueskyThreadsTest.php`, `tests/Feature/Mcp/Tools/McpCalendarViewsTest.php`, `tests/Feature/Mcp/Tools/McpCommentNotesTest.php`, `tests/Feature/Mcp/Tools/McpHashtagsTest.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`, `tests/e2e/instagram-audio.spec.ts`
- Los ocho recuentos de herramientas se actualizan por el cambio real de 25 a 27; no se relajan las pruebas de paginación ni de herramientas existentes.
- **Verificación**: `McpInstagramAudioTest` → 3 pasados, 25 aserciones. Primer E2E verde de escritorio: buscar/elegir/volúmenes/guardar/reabrir/quitar y errores/cerrar → 2 pasados, 23 s. Prueba ampliada final de audio: 15/15 en escritorio y dos móviles; incluye preescucha y cambio de formato.
- RED MCP: `McpInstagramAudioTest::agent can discover search and refresh a track without exposing credentials` falló con HTTP 400 al llamar a `search_instagram_audio`, aún inexistente · 2026-10-31.
- RED UI: Playwright desktop `Audio Reel: search select volumes save reopen and remove` falló esperando «Añadir audio», aún inexistente · 2026-10-31. Evidencia: `storage/framework/testing/t43/run-general-284c493e-8f1e-423f-90f3-39de007eb779/results.json`.
- Iteraciones QA conservadas: `run-general-1ab2080a-b269-44c4-a459-d966d2bd21f5` falló por glob del mock que no interceptaba metadata `/audio_id`; corregido con ruta completa. `run-general-6c0e27ee-40b1-4bf0-84e6-2cd3e18a447b` midió contraste durante el fundido del modal cerrado; se espera a que quede oculto antes de medir, sin excluir la regla de contraste.

**Criterios de aceptación**
- [x] Buscador, filtros, cambio/quitar, volúmenes, preescucha separada, sin autoplay y con errores claros.
- [x] MCP read-only con abilities, selección en payload y capacidades por cuenta; 27 herramientas visibles.
- [x] Revisión independiente y QA ampliado con evidencia final.

### T-05 — Verificación y documentación

- **Estado**: completado
- **Archivos**: `docs/guias/audio-instagram.md`, `docs/modulos/mcp.md`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/roadmap/README.md`, `CONTINUE-HERE.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/testing/**`
- **Verificación**: build Vite pasa; regresión focal servidor → 372 tests, 2.655 aserciones sin fallos. Verificación final cerrada: 2.491 tests PHP/14.671 aserciones, 12 tests Node, 15 E2E, build y tipado correctos; tercer intento de revisión sin gaps.
- TDD n/a: documentación e informes. Los cambios de código y sus pruebas se registran en T-02/T-04.

**Criterios de aceptación**
- [x] Build, tipado, regresión y QA local con evidencias actuales.
- [x] Revisión adversarial independiente sin gaps importantes pendientes.
- [x] Guía y punto de continuación actualizados; smoke real explícitamente abierto.

### T-06 — Smoke real del usuario

- **Estado**: borrador
- **Archivos**: `docs/roadmap/2026-10-31-metricool-composer-parity/testing/smoke-real.md`
- **Verificación**: pendiente del usuario; no se ejecuta durante el desarrollo ni se sustituye por mocks.
- TDD n/a: comprobación manual de integración real.

**Criterios de aceptación**
- [ ] Buscar una pista con cuenta propia elegible; guardar/reabrir por web/MCP conserva la identidad y ambos volúmenes.
- [ ] Publicar una sola vez y comprobar pista nativa correcta en Instagram; reintento no duplica.
- [ ] Registrar permisos/cuenta no elegible y pista rechazada sin publicación silenciosa sin audio.

### T-07 — Capacidad de audio de las seis redes

- **Estado**: completado
- **Archivos**: `app/Services/AudioCapabilities.php`, `app/Mcp/Tools/ListAccounts.php`, `app/Http/Controllers/PostController.php`, `resources/js/Components/Composer/TargetOptions.vue`, `tests/Feature/Mcp/Tools/McpInstagramAudioTest.php`, `docs/roadmap/2026-10-31-metricool-composer-parity/testing/audio-by-network.md`
- **Verificación**: `McpInstagramAudioTest` ampliado y `TikTokAudioOptionsTest` → 8 pasados, 56 aserciones; incluidos en regresión posterior de 447 tests/3.025 aserciones sin fallos.
- RED: `McpInstagramAudioTest::all six networks describe supported audio without promising a universal catalog` falló por ausencia de clave `audio` en `list_accounts` antes de implementar · 2026-10-31.

**Criterios de aceptación**
- [x] Las seis redes describen audio del vídeo, catálogo y música automática de foto según su conexión/formato.
- [x] Las indicaciones se comparten entre web y MCP; no se promete asociación nativa en contratos no verificados.
- [x] Revisión independiente final.

### T-08 — Música recomendada de TikTok en fotos y carruseles

- **Estado**: completado
- **Archivos**: `app/Connectors/TikTok/TikTokPublisher.php`, `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Servers/SchedulerServer.php`, `resources/js/Composables/composerOptions.js`, `resources/js/Components/Composer/PresetsPanel.vue`, `tests/Feature/Publishing/TikTokAudioOptionsTest.php`, `tests/e2e/tiktok-audio.spec.ts`, `playwright.audio.config.ts`, `tsconfig.json`
- **Verificación**: `TikTokAudioOptionsTest` y `McpInstagramAudioTest` ampliados → 8 pasados/56 aserciones. Regresión servidor posterior: 447 pasados/3.025 aserciones. E2E final de audio 15/15; dominio ficticio verificado solo en suite aislada de audio, sin cambiar `.env` ni habilitarlo globalmente en otras pruebas.
- RED: `TikTokAudio::photo direct post sends the explicit recommended music choice` falló porque no se envió `auto_add_music` antes de implementar · 2026-10-31.

**Criterios de aceptación**
- [x] Booleano explícito, solo fotos Direct Post, conservado en web/MCP y enviado en `post_info`.
- [x] Vídeos/modo bandeja no ignoran una petición incompatible; cambio de formato elimina el ajuste; no se recuerda entre posts.
- [x] Revisión independiente y E2E final.

### T-09 — Evitar tormenta de reintentos de creator_info

- **Estado**: completado
- **Archivos**: `resources/js/Composables/tiktokCreator.js`, `resources/js/Components/Composer/TargetOptions.vue`, `tests/frontend/tiktok-creator.test.mjs`, `tests/e2e/tiktok-audio.spec.ts`
- **Verificación**: `node --test --experimental-test-isolation=none tests/frontend/tiktok-creator.test.mjs` → 12 pasados; E2E final → 15 pasados en 3 tamaños, incluidos fallo estable y botón de reintento que recupera la cuenta.
- RED: prueba mínima con `watchEffect` y proveedor 503 simulado hizo **6 peticiones en 100 ms**, en lugar de una · 2026-10-31. No se cuenta como rojo el `spawn EPERM` previo del sandbox ni una opción del runner no admitida por Node 22.
- Debug-root-cause, fases 1–3: traza `run-general-a280d5d5-5be1-45bd-bf3c-8cf9f3221c9c` muestra 30 respuestas 500 y 304 respuestas 429 en un minuto a `tiktok-creator`. El fake del E2E no daba `creator_info`; el fallo de proveedor expuso una causa de producto independiente: el `watchEffect` lee `state` dentro de `loadTiktokCreator` y reacciona a su error, que no se cacheaba. Prueba mínima 503 aislada confirma el bucle sin servidor ni rate limiter. Se descartan música, subida y configuración de foto: el fallo ocurre antes de interactuar con ellas.
- Debug-root-cause, fase 4: cachear el error, reintento explícito y bloqueo de duplicados mientras carga. GREEN de la prueba mínima y E2E de recuperación. La fixture positiva ahora simula `creator_info`; la negativa confirma una única llamada fallida y una segunda al pulsar Reintentar. Regresión completa servidor: 2.491 tests/14.671 aserciones, sin fallos, errores ni omitidos.

**Criterios de aceptación**
- [x] Un error no vuelve a lanzar la consulta automáticamente ni satura proveedor/rate limiter.
- [x] El usuario puede reintentar y recuperar los permisos; no se pierde el contenido del post.
- [x] Revisión independiente final y regresión completa.

### T-10 — Corregir paginación y actualización del buscador

- **Estado**: completado
- **Archivos**: `app/Connectors/Meta/Instagram/InstagramAudio.php`, `app/Http/Controllers/InstagramAudioController.php`, `app/Mcp/Tools/SearchInstagramAudio.php`, `resources/js/Components/Composer/InstagramAudioField.vue`, `tests/Feature/Instagram/InstagramAudioTest.php`, `tests/Feature/Mcp/Tools/McpInstagramAudioTest.php`, `tests/e2e/instagram-audio.spec.ts`, `docs/guias/audio-instagram.md`, `docs/modulos/mcp.md`, `docs/knowledge/README.md`, `docs/knowledge/candidates/pending/2026-11-01-instagram-audio-cursor-sin-next.md`
- **Verificación**: PHP completo 2.495 tests/14.715 aserciones; focal 14/122; Node 12/12; Playwright 21/21 con qa-gate VERDE; Vite y TypeScript pasan. Revisión A+B+D de T-10 sin gaps. Consultas reales: 25+25=50 pistas distintas. [Informe y manifiesto](testing/search-pagination/report.md).
- RED: `InstagramAudio::cursor-only pagination keeps both catalog positions and never follows provider URLs` falló porque `next_cursor` era null (esperado string) · 2026-11-01.
- RED UI: `Audio Reel: typing searches and loading more preserves results with retry and filter reset` falló esperando la pista de la consulta escrita sin Enter/Buscar; solo seguían las tendencias iniciales · 2026-11-01. Run `run-general-1ea65c4a-a754-4826-8058-d00c1990a220`.
- Iteraciones no contadas como rojo de producto: bundle viejo antes de recompilar (`f29666b2`); selectores del test demasiado amplios/estrictos (`cb2469e1`, `4a65f524`). Fakes HTTP acumulados en la fixture PHP se corrigieron antes de acreditar el GREEN.
- Debug fases 1–3: búsqueda real `Dhruv Goel` → 25 pistas y `paging.cursors.after` sin `paging.next`; segunda consulta GET al mismo `ig_audio` con `after` → 25 pistas distintas. Hipótesis confirmada: `InstagramAudio::search` descartaba toda la paginación y el consumidor no podía continuar. Las consultas previas de Aipocalipsis/Libre no contenían cursor; no justificaban generalizar a todo el buscador. Aumentar `limit` de 10 a 50 no altera los 10 resultados de `Libre`.

**Criterios de aceptación**
- [x] Continuación explícita por cursor en web/MCP, incluidos cursores sin URL next; no volver al inicio de un tipo agotado.
- [x] Cursor cifrado ligado a cuenta, búsqueda, filtro y caducidad; no seguir URLs externas ni exponer tokens.
- [x] Cargar más conserva y deduplica las pistas anteriores; fallo recuperable sin perder resultados.
- [x] Escribir actualiza la búsqueda con espera breve; consulta/filtro nuevo y cierre invalidan resultados antiguos y cursores.
- [x] Pruebas RED/GREEN, regresión, build/tipado, revisión independiente y QA final actualizados.

### T-11 — Paneles globales y por red con acabado cuidado

- **Estado**: completado
- **Archivos**: `resources/js/Components/Composer/PresetSection.vue`, `resources/js/Components/Composer/GlobalPresets.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/PresetsPanel.vue`, `resources/js/Components/Composer/InstagramAudioField.vue`, `resources/js/Components/Composer/ErrorsPanel.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/css/app.css`, `tests/e2e/composer-presets.spec.ts`, `playwright.presets.config.ts`, `tsconfig.json`, `docs/roadmap/2026-10-31-metricool-composer-parity/testing/presets-ui/**`, `docs/guias/ajustes-compositor.md`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/roadmap/README.md`, `CONTINUE-HERE.md`
- **Verificación**: ejecutada. PHP **2.511 tests/14.763 aserciones**, E2E **54/54** (1440/375/360), cero fallos/flaky/skips/errores; build y tipado exit 0. Revisión A+B+D intento 2 sin gaps pendientes; qa-gate VERDE. [Informe, capturas y manifiesto](testing/presets-ui/report.md). Sin publicaciones/invitaciones reales.
- RED: `Presets: global and network panels keep values and reveal collapsed validation fields` falló esperando la región «Ajustes globales», inexistente · 2026-11-01. Run `run-general-676aa9a6-9ff2-4c9d-bf24-7945413b6cff`.

**Criterios de aceptación**
- [x] Panel de Ajustes globales para programación/notas, seguido de ajustes por red con icono, cuenta y tipo de publicación.
- [x] Paneles plegables accesibles con teclado; plegar/reabrir no borra valores y los enlaces de error abren el panel afectado.
- [x] Campos e interruptores con separación y estilo coherentes; sin nuevas opciones ni cambios de payload/defaults/permisos.
- [x] Sin desbordamiento en escritorio 1440 y móviles 375/360; controles y textos legibles, evidencia visual y axe.
- [x] Guardar/reabrir conserva ajustes; siguen pasando los escenarios de presets y audio. Build y tipado correctos; revisión independiente sin gaps importantes.

TDD n/a para colores/espaciados; el comportamiento nuevo de plegado y navegación por errores se verifica con prueba RED antes de implementar.

### T-12 — Opciones observadas: colaboradores, IA y revisión de errores

- **Estado**: completado
- **Archivos**: `app/Connectors/Meta/Instagram/InstagramPostOptions.php`, `app/Connectors/Meta/Facebook/FacebookPostOptions.php`, `app/Connectors/Meta/Facebook/FacebookPublisher.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php`, `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php`, `app/Http/Controllers/PostController.php`, `app/Mcp/Tools/ListAccounts.php`, `app/Mcp/Tools/PostPayload.php`, `resources/js/Components/Composer/InstagramCollaboratorsField.vue`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/PresetsPanel.vue`, `resources/js/Components/Composer/ErrorsPanel.vue`, `resources/js/Composables/composerOptions.js`, `resources/js/Composables/useComposer.js`, `tests/Feature/Publishing/InstagramPresetsOptionsTest.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramPresetsPublishTest.php`, `tests/Feature/Mcp/Tools/McpInstagramAudioTest.php`, `tests/e2e/composer-presets.spec.ts`, `tests/e2e/composer.spec.ts`
- **Verificación**: ejecutada. PHP **2.511 tests/14.763 aserciones**, E2E **54/54** (1440/375/360), cero fallos/flaky/skips/errores; build y tipado exit 0. Revisión A+B+D intento 2 sin gaps pendientes; qa-gate VERDE. [Informe, capturas y manifiesto](testing/presets-ui/report.md). Sin publicaciones/invitaciones reales.
- RED UI: faltaba «Añadir colaborador» (`run-general-fe30f7d0-3674-49bd-a213-ced9690d5b23`) y summary del panel de errores (`run-general-621792c3-175a-4b28-97bc-080c89ae8e7d`) · 2026-11-01. RED Facebook: título ausente en payload finish, esperado Title.
- RED: `InstagramPresetsOptions::web and service normalize persist and clear collaborators and AI` guardó options null, y la prueba de booleano inválido no devolvió error · 2026-11-01. `InstagramPresetsPublish::collaborators and AI reach only the final media container` falló por ausencia de ambos campos en imagen/Reel/carrusel. GREEN focal posterior: 19 tests/99 aserciones (servicio, web, MCP y publicador).

**Criterios de aceptación**
- [x] Inventario explícito de todos los controles visibles de Global/Instagram y zona de errores; diferenciar implementado y pendiente sin declarar paridad total.
- [x] Colaboradores por destino en imagen/Reel/carrusel: normalizar usuarios, limitar por contrato, rechazar formas/formatos incompatibles; guardar/reabrir y enviar JSON solo al contenedor correcto.
- [x] Indicación de IA de Instagram por publicación en web/MCP, booleana validada, persistida y enviada al contenedor; no a hijos de carrusel ni como preferencia recordada de otras publicaciones.
- [x] Título independiente de vídeo/Reel de Facebook, validado y enviado en el paso correcto; se conserva y no se arrastra a otro formato.
- [x] Zona de errores plegable, contador e identificación de cuenta/red; enlaces de corrección accesibles en móvil sin perder validación ni acciones de borrador.
- [x] Pruebas de servidor, MCP, publicador y UI; revisión independiente y QA final con evidencias.


### T-13 — Refrescar el compositor después de guardar

- **Estado**: completado
- **Archivos**: `resources/js/Composables/useComposer.js`, `tests/e2e/composer-refresh.spec.ts`, `docs/roadmap/2026-10-31-metricool-composer-parity/spec.md`, `docs/roadmap/2026-10-31-metricool-composer-parity/testing/composer-refresh/**`, `docs/roadmap/README.md`, `CONTINUE-HERE.md`
- **Verificación**: ejecutada. **21 E2E pasados** en escritorio 1440 y móviles 375/360, cero fallos/flaky/skips/errores; qa-gate VERDE. Build y tipado exit 0. Revisión A/B intento 2 sin gaps. [Informe y evidencia](testing/composer-refresh/report.md). PHP y publicaciones reales no ejecutados en T-13.
- RED: `composer-refresh.spec.ts::create retains attachments on errors and refreshes local state after saving` falló esperando Desde URL vacío tras un POST correcto y redirección a edición; conservaba `https://example.invalid/siguiente-imagen.jpg` · 2026-11-03. Run `run-general-35f223e7-6edb-4ffc-b8e4-d32262b5d154`. Las iteraciones previas ajustaron fixtures (scope del usuario y tipo Foto) y no cuentan como RED funcional.

**Criterios de aceptación**
- [x] Después de crear correctamente, reconstruir el compositor desde la respuesta del servidor, descartando entradas y previsualizaciones temporales anteriores.
- [x] Mantener los adjuntos realmente guardados en el borrador y conservar todo lo escrito cuando hay errores de validación.
- [x] Una publicación nueva no arrastra texto ni adjuntos anteriores; edición y guardado de presets mantienen sus flujos existentes.
- [x] E2E en escritorio y móviles, build y tipado correctos; registrar evidencia y resultado del qa-gate.

## Revisión de dos lentes — intento 1: Instagram (T-01–T-05) — corregir reconexión

Lentes A+B+D. Selector C=false, D=true por ruta `SchedulerServer.php`. Scope-check exit 0, 40 archivos declarados, sin exclusiones ni avisos. Revisores frescos `audio_review_a`, `audio_review_b`, `audio_review_d`; no escribieron código. A verificó CA-01..08; CA-09 estaba en curso y CA-10 pendiente previsto. D sin hallazgos (10 tests/46 aserciones ejecutados). B ejecutó 23 tests/87 aserciones y reprodujo B1 fuera de la cobertura inicial. Sin Jira configurado; no hay operaciones remotas.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | Fila del índice aún describía borrador amplio y ausencia de código | T-05 | Corregida al alcance de audio y estado en curso | `docs/roadmap/README.md` |
| B1 | Important | Caducidad local de User token se trataba como contenido permanentemente inválido | T-03 | Separar validación de forma y credenciales; el publicador preserva `ReconnectRequiredException` | RED de `expired User access requires reconnect...` devolvió Permanent en lugar de Reconnect; GREEN posterior |
| O1 | Important | Mensaje de error de catálogo de Meta podía persistir una URL firmada o token en el error del destino | T-03 | Sanear texto preservando tipo de excepción, código y retryAt, sin excepción anterior con texto crudo | RED de `provider messages cannot persist secrets...` mostró texto de prueba; GREEN posterior |

Verificación de B1/O1: `InstagramAudioPublishTest` + `InstagramAudioTest` → **12 pasados, 50 aserciones**, sin fallos. T-03 permanece en revisión hasta el segundo intento. La ampliación a las demás redes es posterior a este intento y requiere revisión final.

## Revisión de dos lentes — intento 2: Audio por red (T-01–T-09) — corregir error vacío

Lentes A+B+D con revisores frescos `audio_final_a`, `audio_final_b`, `audio_final_d`. Scope-check exit 0, 53 archivos declarados, sin avisos. A confirma B1/O1, CA-11/12 y alcance; D sin hallazgos y verifica ausencia de N+1 y una sola consulta fallida con reintento explícito. No se reabren criterios aprobados. Sin Jira; no hay operaciones remotas.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A2 | Minor | Guía aún excluía TikTok sin distinguir la nueva música recomendada | T-05 | Bloque específico TikTok, tabla por red y límites de selección concreta Business | `docs/guias/audio-instagram.md` actualizado |
| B2 | Important | Mensaje de error vacío/falso reactivaba la consulta de creator_info | T-09 | Detectar presencia de error y normalizar mensaje; una respuesta 200 mal formada también pasa a error estable | RED de mensajes `""`, false, 0: 8 consultas/100 ms; respuesta `{}`: mensaje no textual. RED adicional de 200 con null/false/0/vacío/lista/objeto sin contrato: bucle o datos inválidos. GREEN: **12 tests Node pasados**, 0 fallos |

La regresión PHP completa terminó con **2.491 tests, 14.671 aserciones, 0 fallos/errores/skips** (`testing/raw/phpunit.xml`). El E2E anterior terminó con **15 pasados, 0 fallos/flaky/skips**, en `run-general-1eca4b10-bcc2-48b0-aa94-d322ef3b1e6f/results.json`. Tras la última corrección JS se repite solo la verificación frontend/E2E, no la suite PHP cuyo código no cambió. T-09 permanece en revisión hasta el tercer intento.


## Revisión de dos lentes — intento 3: Audio por red (T-01–T-09) — sin gaps pendientes

Lentes A+B+D, revisores frescos `audio_close_a`, `audio_close_b`, `audio_close_d`, de solo lectura. C=false; D=true por el selector determinista sobre `SchedulerServer.php`. Se transfirió la tabla previa; se verificaron las correcciones sin reabrir lo aprobado. No hay Jira configurado ni operaciones remotas. El candidato de conocimiento permanece en `candidates/pending/`, a disposición del knowledge-curator.

| Criterio | Veredicto | Evidencia |
|---|---|---|
| A: alcance de seis redes, guía TikTok y A2 corregido | ✓ | Spec CA-01–09/11–12, guía y `testing/audio-by-network.md`; CA-10/T-06 continúa pendiente |
| A: T-09 y propuesta de conocimiento trazables | ✓ | RED/GREEN en intento 2 y candidato pendiente con evidencia de 12 casos |
| B: B2 corregido, errores falsos y respuestas 200 mal formadas estables | ✓ | 12 tests Node pasados, ejecutados independientemente por B |
| B: recuperación manual y contrato creator_info | ✓ | `tiktokCreator.js`, botón de reintento y contrato de `ConnectionController`; sin defectos |
| D: no se añaden consultas o bucles automáticos | ✓ | Guardia de estado y validación constante; 12 tests Node pasados por D; sin hallazgos |
| QA final: suite actual de audio | ✓ | `testing/raw/playwright.json`: 15 pasados, 0 fallos/flaky/skips; `testing/raw/qa-gate.json`: VERDE |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| — | — | Ninguno pendiente; B1/O1/A1/A2/B2 corregidos | T-02–05, T-07–09 | No requiere cambio adicional | [Informe final](testing/report.md) |

Resultado: **0 Critical, 0 Important, 0 Minor pendientes**. Código validado localmente; T-02–05 y T-07–09 completadas. T-06 sigue abierta: la simulación no acredita publicación real. El manifiesto de validación fija hashes de las fuentes y las evidencias de estas ejecuciones.


## Revisión de dos lentes — intento 1: T-10 buscador/paginación — sin gaps pendientes

Nuevo ciclo de corrección solicitado por el usuario tras la entrega inicial. Revisores frescos de solo lectura `search_page_a`, `search_page_b`, `search_page_d`; lentes A+B+D. Selector C=false, D=true por `SchedulerServer.php` en el diff agregado. El selector no detecta un nuevo flujo de prompts o una ruta de autenticación. Model-tier no disponible por instalación parcial sin agents/: se utilizó el rol reviewer disponible. Sin Jira configurado ni operaciones remotas. La propuesta de conocimiento queda pendiente del knowledge-curator.

| Criterio T-10 / CA-13 | Veredicto | Evidencia |
|---|---|---|
| A: continuación web/MCP y tipos independientes | ✓ | Servicio, controlador, MCP y pruebas; focal independiente 14 tests/122 aserciones |
| A: cursor ligado a cuenta/texto/filtro/caducidad y endpoint fijo | ✓ | Validación antes de Meta; rechazos de cursor alterado, incompatible o caducado |
| A: append/dedup/reintento, búsqueda al escribir e invalidación | ✓ | Componente y dos nuevos E2E; lectura del RED original |
| A: alcance, documentación y verificación declarada | ✓ | Scope-check exit 0 sin extras/avisos; ledger-lint limpio; reporte anterior diferenciado |
| B: corrección y carreras | ✓ | 4 tests/44 aserciones y arnés JS del componente real; respuesta atrasada descartada aunque el transporte ignore abort |
| D: carga y consultas acotadas | ✓ | 4 tests/44 aserciones y arnés Node: ráfaga de escritura una consulta, cerrar cero, fallo sin reintento automático; máximo dos consultas por página |
| QA integral posterior al veredicto A | ✓ | 21 E2E pasados, 0 fallos/flaky/skips/errores; qa-gate VERDE; suite PHP 2.495/14.715 |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| — | — | Ninguno pendiente | T-10 | No requiere cambio adicional | `testing/search-pagination/report.md` y `validation-summary.json` |

Resultado: **0 Critical, 0 Important, 0 Minor pendientes**. T-10 completada, CA-13 acreditado. T-06/CA-10 de publicación real siguen abiertos. Las consultas reales del catálogo son de lectura y no se presentan como publicación aprobada. El nuevo manifiesto de T-10 fija las fuentes/build actuales; el inicial permanece como histórico.


## Revisión de dos lentes — intento 1: T-11 estética — corregir calendario recortado

Lentes A+B+D, revisores frescos `presets_ui_a`, `presets_ui_b`, `presets_ui_d`, sobre snapshot de 11 fuentes (review.patch/review-sources.json). A y D sin gaps de sus lentes; A confirmó GREEN focal 2/2 y dejó QA integral pendiente. B confirmó el siguiente defecto con componentes reales compilados en memoria y Chromium elevado, sin servidor ni datos reales.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Important | overflow-hidden recorta calendario personalizado | T-11 | Quitar clipping del panel y conservar radios en cabecera; limitar el input datetime nativo en móvil | Sección terminaba y=454 y diálogo y=638: 184px recortados, Listo no recibía clic. Al anular solo overflow, hit test vuelve a true. RED E2E de receivesClicks=false en run-general-6ba040d6-3b17-4d15-9ac4-bc5f9c29989d |

La primera regresión integral `run-general-53c42511-5e09-4760-9ced-89b54dab41cf` terminó con 44 pasados, 2 fallidos y 5 no ejecutados: enlaces de error inaccesibles por geometría móvil 360. Se conserva como rojo, no se da por QA aprobada. T-11/T-12 siguen abiertas hasta la revisión y QA del código corregido y ampliado.


## Revisión de dos lentes — intento 2: T-11/T-12 — sin gaps pendientes

Lentes A+B+D; revisores frescos de solo lectura `presets_final_a`, `presets_final_b`, `presets_final_d`. Se entregó la tabla del intento 1 y se revisaron correcciones y ampliación autorizada, sin reabrir lo aprobado sin evidencia. Snapshot completo de 27 fuentes y 4.053 líneas en `testing/presets-ui/review-attempt2.patch`; los tres revisores comprobaron 27/27 hashes. Scope-check exit 0, sin extras/exclusiones/avisos; C=false y D=true por `SchedulerServer.php` del agregado. Model-tier no disponible por instalación parcial: rol reviewer disponible sin override. Sin Jira configurado ni operaciones remotas. Los candidatos de conocimiento siguen pendientes del curator.

| Criterio | Veredicto | Evidencia |
|---|---|---|
| A: T-11 paneles/teclado/valores y correcciones | ✓ | Eliminado clipping, min-w-0 en formulario, hit-test de Listo y tests de persistencia; documentación leída |
| A: T-12 inventario, colaboradores, IA, título y errores | ✓ | Contratos compartidos, tests semánticos web/MCP/publicador/UI; JUnit 2.511/14.763 sin errores/fallos/skips |
| A: alcance y verificaciones declaradas | ✓ | 27 hashes, scope-check, build leído y exit 0 de tipado registrado; T-06 sigue abierta |
| B: corrección de límites, persistencia y poda | ✓ | 29 límites PHP puros PASS y Node sobre pruneOptions real PASS, sin DB/HTTP |
| B: padre/hijos de carrusel y título en finish | ✓ | Lectura de publicadores y pruebas de contenedores; sin defectos identificados |
| D: coste acotado y ausencia de N+1 | ✓ | Máximo 3 colaboradores, ninguna petición extra; 100.000 iteraciones puras en 266,1 ms |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | Guía generalizaba bloqueo de Publicar ahora a fecha pasada | T-12 | Guía distingue errores de contenido y fecha: Publicar ahora usa fecha del servidor | `docs/guias/ajustes-compositor.md`, releído por A |
| B1 anterior | Important | Calendario recortado por overflow-hidden | T-11 | Sin clipping y formulario min-w-0 | CSS, hit-test y focal móvil 360 3/3 |

Resultado de revisión: **0 Critical, 0 Important, 0 Minor pendientes**. Los tres revisores mantuvieron explícitamente pendiente el resultado E2E integral que estaba ejecutándose; la revisión no se presenta como QA final. No se publicaron posts ni invitaciones reales.

QA posterior al intento 2: **54 E2E pasados**, cero fallos/flaky/skips/errores; qa-gate **VERDE**. PHP **2.511/14.763**, build y tipado correctos. Evidencia actual en `testing/presets-ui/`; T-11/T-12 completadas y CA-14/15 acreditados. Solo T-06/CA-10 queda pendiente del usuario.


## Revisión de dos lentes — intento 1: T-13 — corregir preservación ante rechazo

Lentes A+B, revisores frescos `refresh_review_a` y `refresh_review_b`, de solo lectura. A confirmó criterios y RED, con QA aún pendiente. B ejecutó un arnés puro de callbacks/defaults/biblioteca/notas sin defectos. La sospecha preliminar de regex con doble barra fue rebatida con bytes y Node: `POST:123` sí coincide; no es gap. El orquestador detectó O1 durante pruebas de la primera alternativa.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| O1 | Important | Basar reconstrucción solo en errors podía descartar trabajo rechazado si la respuesta carecía de errores temporales | T-13 | Retirar preserveState:errors; detectar el post realmente creado. Con post=null se conserva el alta y PUT sigue intacto | Trace de run-general-e2facb8e-def3-422f-8cb2-a255c4fb08ad: rechazo vuelve con post=null/errors={} y un segundo envío acababa vacío; la alternativa se retiró antes del cierre |

## Revisión de dos lentes — intento 2: T-13 — sin gaps pendientes

Revisores frescos `refresh_final_a`, `refresh_final_b`; tabla anterior transferida, sin reabrir lo rebatido sin evidencia nueva. C=false/D=false, scope-check exit 0 sin extras/avisos. Sin Jira configurado ni publicaciones remotas de revisión. La instalación parcial no aporta model-tier; se usó el rol reviewer disponible, sin override. Sin candidatos nuevos de conocimiento.

| Criterio | Veredicto | Evidencia |
|---|---|---|
| A: alta correcta reconstruye; rechazo conserva el trabajo | ✓ | Callback y contrato del controlador leídos; 4 combinaciones reales de post/errors ejecutadas PASS |
| A: alcance exacto, edición preservada y test completo | ✓ | PUT intacto, test de alta/edición/rechazo/nueva publicación; RED leído; sin gaps |
| B: O1 corregido independientemente de errors/flash | ✓ | Función real extraída, 27 combinaciones PASS |
| B: callbacks y PUT conservados | ✓ | Arnés puro PASS, sin BD/HTTP; flujo de biblioteca/notas sin cambios |

Resultado: **0 Critical, 0 Important, 0 Minor pendientes**. Ambos revisores dejaron explícitamente pendiente la QA integral; no se usa su revisión como resultado E2E. GREEN focal del código final: 1/1 escritorio, chunk `2a82fb`. QA posterior de 21 casos en curso.

QA posterior al intento 2 de T-13: **21/21 pasados**, cero fallos/flaky/skips/errores, qa-gate VERDE; build y tipado correctos. T-13 completada, CA-16 acreditado; T-06/CA-10 sigue pendiente del usuario.
