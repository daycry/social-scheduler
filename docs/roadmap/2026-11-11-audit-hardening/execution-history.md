# Historial de ejecución — audit-hardening

## Archivado 2026-11-12 — Revisión de dos lentes — intento 1: Fase 1 (T-04, T-05) — gaps corregidos (bytes sin cambios)

SHA-256 del bloque: `150f4204bcb645f0ab80a8efa3773196171f4b1fa107a2a2338b1b67f4968e8b`.

## Revisión de dos lentes — intento 1: Fase 1 (T-04, T-05) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | La fusión de autoguardados nunca se activaba desde la UI: el editor envía `kit_revision` en cada PATCH y la condición lo excluía | T-04 | `$autosave` ya no depende de `kit_revision`; un autoguardado con una revisión de kit ya podada conserva el kit actual en vez de fallar | `DesignRevisionRetentionTest` «the real editor payload (with kit_revision) coalesces autosaves through HTTP»: rojo `[1,2,3,4]` → verde `[1,4]` |
| 2 | Critical (latente) | La fusión reescribía el número de una revisión fijada por una exportación | T-04 | Los números no se reutilizan: la nueva revisión es una fila nueva y solo se borra la anterior de autoguardado si no la fija una exportación no terminada (`DesignRevision::pinnedByExport`) ni es la `kit_revision` del editor | test «does not reuse the number of a revision pinned by an unfinished export»: rojo (la fila 2 desaparecía) → verde |
| 3 | Important | La fusión sobrescribía puntos de control explícitos | T-04 | Columna `autosave` en `studio_design_revisions` (migración `2026_12_16_090000`, reversible); solo se sustituye una fila de autoguardado del mismo autor; Guardar ahora, Ctrl+S, restaurar y kit nunca | test «an autosave never rewrites an explicit checkpoint»: rojo `[1,3]` → verde `[1,2,3]` |
| 4 | Important | Falso positivo de snapshots (cuentas no activas y recién conectadas) | T-05 | Solo cuentas `activa` (como `CollectSnapshots`) con antigüedad ≥ 24 h + umbral | test «snapshots ignore inactive accounts and give a new account a grace period» |
| 5 | Important | Falso positivo de Chromium bajo PHP-FPM (`clear_env`) | T-05 | Por HTTP solo se comprueba con `STUDIO_CHROMIUM_PATH`; la comprobación completa queda en la CLI | test «native is cached ... Chromium is not guessed outside the CLI» |
| 6 | Minor | `native` lanzaba procesos en cada petición HTTP | T-05 | `/health` cachea `native` `HEALTH_NATIVE_CACHE_SECONDS` (300 s); la CLI siempre comprueba y refresca | mismo test: 3 procesos en dos `run(http)` |
| 7 | Minor | La poda no excluía revisiones fijadas por exportaciones no terminadas | T-04 | `prunable()` excluye con `NOT EXISTS` las fijadas por exportaciones con estado distinto de `ready` | test «pruning keeps revisions pinned by unfinished exports»: rojo → verde |
| 8 | Minor | Poda sin lotes eficientes | T-04 | `DesignRevision::pruneAll` calcula una vez los ids a podar y borra por lotes; notificaciones por lotes (`App\Support\Retention`) con índice `notifications_read_at_prune` | tests «computes the keep set once» (1 consulta de ventana, rojo 4) y «notifications prune deletes in batches» |

Estado: T-04 y T-05 siguen en `en-revision` hasta la re-revisión. Pest focal 57/57; Pest completo y Pint: ver el resumen de la entrega.


## Archivado 2026-11-12 — Revisión de dos lentes — intento 1: Fase 1 (T-06) — gaps corregidos (bytes sin cambios)

SHA-256 del bloque: `1f576eff786c6e04637ff8a48e1f322181c06d0f1f10796bb82adf2a033392a3`.

## Revisión de dos lentes — intento 1: Fase 1 (T-06) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | El criterio exigía bloquear el borrado de media usada por un export y estaba marcado sin implementarlo | T-06 | `MediaAsset::usedByExport()` (parte de `usedByPosts`): export `ready` de los últimos `studio.export_media_guard_days` días (30, `STUDIO_EXPORT_MEDIA_GUARD_DAYS`, 0 = no bloquea), con `whereJsonContains` filtrado por usuario, estado y fecha, sin cargar filas; los exports históricos o no listos no bloquean. Documentado en `media-por-destino.md` y `configuracion.md` | test «la media de un export listo reciente bloquea…» (rojo: no lanzaba `DomainException`) |
| 2 | Important | La migración descartaba en silencio ids y portadas huérfanos también en destinos sin publicar | T-06 | Un destino no publicado con huérfanos pasa a `fallida` (`error_code` `media_inexistente`, mensaje «Falta el archivo X…») y se registra en el log con los ids; las portadas huérfanas se anotan igual; solo en destinos publicados se descarta. Nota: un reintento de ese destino usaría la lista sin el archivo perdido, por eso el mensaje pide editarlo | test «la migración no descarta en silencio…» con programada [A, B] y B borrada (rojo: seguía `programada`) |
| 3 | Minor | Índice de series/autolistas en otra transacción y descarte silencioso de ids inexistentes | T-06 | `SeriesService::create/update` y `AutolistService::addItem` guardan en `DB::transaction`; el hook del modelo lanza `PostValidationException` (media inexistente o de otro usuario) y la transacción revierte | test «una serie o un elemento de autolista con media inexistente…» (rojo: no lanzaba; 0 filas tras el error) |
| 4 | Minor | La migración hacía un UPDATE por fila | T-06 | `whereIn(...)->update` para `has_own_media`, inserciones por lotes y existencia en bloque por chunk en destinos, series y autolistas (solo los destinos rotos, raros, se actualizan uno a uno por su mensaje) | tests de migración (ida, huérfanos y vuelta) verdes |
| 5 | Minor | Sin verificación en MySQL | T-06 | Revisión a mano: nombres de índice ≤ 58 caracteres, FK del mismo tipo que `id`, `dropColumn` de JSON sin índice. Verificación real anotada como pendiente del usuario en el ledger y en `docs/referencia/pendientes-manuales.md` | documentación |
| 6 | Important | Borrar una cuenta en MySQL puede chocar con las FK `restrict` por el orden de las cascadas | T-06 | `User::deleting` vacía explícitamente `post_media`, `post_target_media`, `post_series_media` y `autolist_item_media` de la media del usuario (y las portadas) antes de borrarlo; lo usa `ProfileController::destroy` y cualquier otro borrado | test «borrar un usuario con posts, media por destino, series y autolistas…» (verde en SQLite, que no reproduce el orden de InnoDB; la comprobación en MySQL queda en pendientes manuales) |

Verificación: `MediaDeleteReferencesTest` 11/11; Pest focal (Posts, Media, Workflow, Parity, Studio, Sites, Auth, Profile, Mcp, Library, Retention, Isolation) y Pint; Pest completo y Playwright, del coordinador. T-06 sigue `en-revision`.


## Archivado 2026-11-12 — Revisión de dos lentes — intento 1: Fase 1 (T-07, T-08) — gaps corregidos (bytes sin cambios)

SHA-256 del bloque: `58fe0b844628b9c51ea9de7d7132788218fbb23cf688436164d5fb69fb9942cf`.

## Revisión de dos lentes — intento 1: Fase 1 (T-07, T-08) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | Con disco R2/S3 el vídeo se enviaba truncado: `readStream` devuelve el socket HTTP, `fstat` da 0 y el multipart medía solo sus cabeceras | T-07 | Tamaño real por `Filesystem::size` y stream decorado con tamaño fijo (`KnownSizeStream`): Guzzle declara bien el `Content-Length` (S3 no admite `chunked`) y envía el vídeo completo, sin cargarlo en memoria | `PinterestVideoUploadRobustnessTest` «un stream remoto sin tamano conocido envia el video completo»: stream con `fstat` 0 y cuerpo leído de la petición; rojo antes del arreglo |
| 2 | Important | El paso podía superar el job: registrar (15 s) + subir (100 s) > 110 s; la recuperación registraba otro media | T-07 | La subida usa `min(upload_timeout, StepBudget::remaining())`; sin 30 s (`http_timeout` + `upload_min_seconds`) de presupuesto lanza `TransientPublishException` (`sin_tiempo`) antes de registrar; la URL prefirmada se guarda en el progreso y un reintento reutiliza el media registrado | tests «la subida acota su timeout…» (rojo: 100), «sin presupuesto suficiente… sin registrar» y «un reintento reutiliza el media ya registrado» |
| 3 | Minor | El test de memoria no era fiable y se perdió la aserción de bytes | T-07 | `memory_reset_peak_usage()` y medición solo del envío; `PinterestConnectorTest` vuelve a comprobar `VIDEOBYTES` leyendo el cuerpo durante la petición | `PinterestVideoStreamingTest`, `PinterestConnectorTest` |
| 4 | Minor | Vídeos ya programados de de más del tope | T-07 | Se revalida al publicar (tamaño del asset y real del disco): `PermanentPublishException` `video_grande` «El video supera 512 MB para Pinterest.», sin registrar medias | test «un video ya programado por encima del tope falla de forma permanente…» (rojo) |
| 5 | Minor | Una versión futura de `LINKEDIN_API_VERSION` no avisaba | T-08 | Antigüedad negativa ⇒ aviso «es futura (aun no existe)» | `LinkedInVersionHealthTest` «una version futura… avisa» (rojo → verde) |
| 6 | Minor | Documentación de TikTok y de `app:health` incompleta | T-08 | Un scope no aprobado bloquea conectar y reconectar TODAS las cuentas de TikTok; se revierte vaciando la variable y `php artisan config:cache`; `linkedin_version` en `docs/modulos/despliegue.md`; Pinterest: presupuesto del paso y reintento | `decisiones-por-red.md`, `configuracion.md`, `despliegue.md`, `linkedin-pinterest.md` |

Verificación: filtro `Pinterest|LinkedIn|TikTok|HealthCheckTest|InfraTest|QueueProbe` en verde; Pint. Pest completo y Playwright, del coordinador. T-07 y T-08 siguen `en-revision`.


## Archivado 2026-11-12 — Revisión de dos lentes — intento 1: Fase 2 (T-12, T-13) — gaps corregidos (bytes sin cambios)

SHA-256 del bloque: `f86dd5b72cb59d8a7f6d0d70c11fbe87dee23b22f3e6ef89564347eff454109f`.

## Revisión de dos lentes — intento 1: Fase 2 (T-12, T-13) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | La evidencia citada solo se buscaba en `.md` (el dry-run borraría runRoots citados en `run-root.txt`, `results.json`, `playwright.json`…) | T-12 | `readDocs` lee TODOS los ficheros de texto de `docs/` y salta binarios por extensión o por NUL | test rojo→verde «la evidencia citada se busca en todos los ficheros de texto…» (`purge-testing.test.mjs`); dry-run real 260 |
| 2 | Important | Sin `docs/` la protección se desactivaba en silencio y se resolvía desde el cwd | T-12 | `docs` se resuelve desde el propio script (`DOCS_DIR`); si falta o no se lee, `purge` lanza y no borra (fail-closed); el teardown avisa | test «sin docs legible la purga aborta…» |
| 3 | Important | El teardown no validaba `E2E_PURGE_KEEP`/`E2E_PURGE_MIN_AGE_DAYS` (NaN/vacío → no conserva nada) | T-12 | `resolveOptions` en el módulo (teardown y CLI): inválido, vacío o <1 → defecto (5 / 2) con aviso; mínimo 1 | test «keep y minAgeDays inválidos…» |
| 4 | Important | `roadmap.spec.ts:130,436` con 8081 fijo | T-13 | `baseURL: info.project.use.baseURL` (sale de `E2E_ROADMAP_PORT`); `grep 808` en `tests/e2e/roadmap` sin coincidencias | test «roadmap.spec no fija ningún puerto 808x»; `E2E_ROADMAP_PORT=18981`: API-03 verde; `E2E_ROADMAP_PORT=18982`: E2E-02 verde (en la primera pasada E2E-02, primer test con el servidor frío, agotó los 90 s; repetido a solas pasa en 4,7 s, no relacionado con el puerto) |
| 5 | Minor | La purga no corría si fallaba el borrado de usuarios | T-12 | `deleteE2eUsers()` en su propio try/catch | test «el teardown borra usuarios y purga en bloques independientes» |
| 6 | Minor | TOCTOU de 8080 entre ejecuciones simultáneas | T-13 | Reservar atómicamente no es viable (el puerto debe conocerse en la config antes de arrancar el servidor, y `serve.cjs` es un proceso aparte). Se elige SIEMPRE un puerto libre del SO salvo `E2E_PORT` (nada necesita 8080: Pest usa 8080 como dato literal, y el vector de passkeys lo graba `record-passkey-vector.ts` con su propio `page.route`), y `global-setup` llama a `verifyRunIdentity` (`/__general-isolation-proof`, su `storage` debe ser el runRoot de ESA ejecución) antes de crear usuarios o lanzar tests | tests «el puerto E2E por defecto es libre y no el 8080» y «verifyRunIdentity acepta … y rechaza el de otra» |
| 7 | Minor | `safety.test.cjs` y `http-server.test.cjs` fuera del script | T-12 | `npm run test:unit` los incluye (sin navegador) | test «test:unit incluye los tests de Node de tests/e2e/isolated»; `npm run test:unit` 642/642 |
| 8 | Minor | `pruebas-t43.md:61` desfasada (URL fija 8080) | T-13 | Describe `http://localhost:<puerto>` y la comprobación de identidad; también `README`, `stack.md`, `desarrollo-local.md`, `tests/e2e/roadmap/README.md` | test «la guía de T-43 describe el puerto actual…» |

Cifras del dry-run: varían con la antigüedad de las ejecuciones; hoy (2026-11-12) «borraría 260 · conservado 22». T-15 anotada: la CI aún no ejecuta los tests de Node. Estado: T-12 y T-13 siguen `en-revision`. Pruebas: `npm run test:unit` 642/642; E2E: `roadmap.spec.ts` (API-03 y E2E-02) y un caso corto de `pages.spec.ts` (valida la comprobación de identidad de `global-setup`), todo con `E2E_PURGE=0`, sin batería completa ni Pest completo.

