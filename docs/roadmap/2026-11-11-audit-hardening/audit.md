# Auditoría integral de la implementación (2026-11-11)

Auditoría de solo lectura sobre `feature/creative-social-suite` (HEAD `2f3b33d` + retro), hecha con cinco revisores independientes: backend, frontend, funcional, tests/CI/operación y producto. Nada se ha comprobado contra proveedores reales. Cada hallazgo lleva un ID estable que el plan referencia.

Leyenda: **Imp.** Alto/Medio/Bajo · **Esf.** S/M/L/XL · **Dep.** `Ya` = implementable ahora, `U` = depende del usuario o de un tercero, `V` = hay que verificarlo antes (sin red durante la auditoría).

## A. Riesgo inmediato (seguridad, pérdida de datos, funciones que fallarían)

| ID | Hallazgo | Evidencia | Propuesta | Imp. | Esf. | Dep. |
|---|---|---|---|---|---|---|
| A-01 | `composer audit` da 11 avisos de seguridad en Symfony (http-foundation, http-kernel, routing, serializer 7.4.21; mime 7.4.19) | `composer.lock` | `composer update "symfony/*" laravel/framework` (13.37 → 13.42) y repetir Pest y E2E | Alto | S | Ya |
| A-02 | `main` no está protegida: se puede fusionar con la CI en rojo (estuvo 6 días en rojo sin verse) | `gh api …/branches/main/protection` → «Branch not protected» | Proteger `main` con PR obligatoria y estado requerido `CI / tests`; avisos de fallos de Actions | Alto | S | U |
| A-03 | No hay copias de seguridad ni prueba de restauración (BD, `storage/app`, `.env`/`APP_KEY`) | 0 resultados en `docs/` y `deploy/` | `mysqldump` diario + copia de media fuera del servidor, cifrada; restauración probada; `APP_KEY` en gestor de secretos | Alto | M | Ya + U |
| A-04 | Versión de la API de LinkedIn `202511` por defecto: LinkedIn retira cada versión a los ~12 meses, así que puede estar retirada | `config/social.php:148` | Confirmar versiones activas, subir el valor y avisar en `app:health` cuando esté cerca de caducar | Alto | S | V |
| A-05 | El vídeo de Pinterest se carga entero en memoria (`Storage::get`) con tope de 2 GB y timeout de 60 s | `app/Connectors/Pinterest/PinterestPublisher.php:48`, `config/media_specs.php:251` | Subida en streaming (recurso multipart) o por pasos; bajar el tope | Alto | M | Ya |
| A-06 | En Hestia ningún worker consume la cola `studio`: MP4/GIF no se procesan. En systemd falta `scheduler-worker@media` | `deploy/hestia/README.md:86-92`, `deploy/systemd/README.md:20` | Añadir el cron de `studio` en Hestia y `media` en systemd; unificar la doc | Alto | S | Ya |
| A-07 | Cada autoguardado del estudio (1,2 s) crea una `DesignRevision` completa y filas en `studio_font_usages`, sin tope ni poda; una tipografía del historial ya no se puede borrar | `app/Services/Studio/StudioService.php:335`, `resources/js/Pages/Studio/Editor.vue:380`, `StudioFonts.php:183` | Revisión con intervalo mínimo o al cerrar sesión + retención (últimas N y diarias); podar también `studio_font_usages` | Alto | M | Ya |
| A-08 | Tablas sin poda: `audit_logs`, `publish_attempts`, `notifications`, `studio_ai_jobs`, `studio_image_jobs`, `canva_jobs`, `studio_design_exports`, `studio_av_steps` | no hay `Prunable` en `app/`; `routes/console.php` | `MassPrunable` con `config/retention.php` y `model:prune` diario | Alto | S | Ya |
| A-09 | La comprobación de salud no ve: worker caído por cola, snapshots atrasados, falta de ffmpeg/Chromium/Python; `/health` da 200 con jobs fallidos; `app:queue-probe` no está programado | `app/Support/HealthCheck.php:34-38,100` | Antigüedad del job más viejo por cola, frescura de `CollectSnapshots`, check `native`, `/health?strict=1` → 503, programar la sonda, correo al admin en `Queue::failing` | Alto | M | Ya |
| A-10 | Despliegue in situ: mantenimiento durante `composer install` + `npm ci` + build, sin copia antes de `migrate`, vuelta atrás manual, despliega `main` sin mirar la CI | `deploy/hestia/deploy.sh:41-60,136-143` | Compilar antes de `down` (o releases con symlink atómico), `mysqldump` previo, comprobar que el commit está en verde | Alto | M-L | Ya |
| A-11 | Analítica de TikTok siempre vacía: los scopes `user.info.stats` y `video.list` están fijos fuera de la petición | `config/social.php:197`, `app/Connectors/TikTok/Display.php:19-23` | `TIKTOK_EXTRA_SCOPES` y reconectar | Alto | S | Ya + U |
| A-12 | Referencias a media en JSON sin FK (`post_targets.media`, `post_series.payload`, `autolist_items.media`, `studio_design_exports.media_ids`); el borrado recorre todas las filas en PHP dentro de una transacción y no comprueba autolistas | `app/Models/MediaAsset.php:76-101`, `MediaUploadService.php:333-340` | Tabla `post_target_media` con `restrictOnDelete`; mientras tanto, añadir `autolist_items` a la comprobación | Alto | M | Ya |

## B. Funciones incompletas o asimétricas

| ID | Hallazgo | Evidencia | Propuesta | Imp. | Esf. | Dep. |
|---|---|---|---|---|---|---|
| B-01 | LinkedIn y Pinterest sin lector de analítica: panel, mejores horas, hashtags e informes vacíos | `app/Providers/AppServiceProvider.php:95,98` | Pinterest: `InsightsReader` con `/v5/user_account/analytics` y `/v5/pins/{id}/analytics` (scopes ya pedidos). LinkedIn: estadísticas de página tras `community_management` | Alto | M | Pinterest Ya · LinkedIn U |
| B-02 | Inbox de Facebook y Ads apagados por defecto (`META_EXTRA_SCOPES` vacío) | `config/social.php:61`, `app/Connectors/Meta/Comments.php:24`, `AdsService.php:29` | Activar el caso de uso en Meta, rellenar la variable y reconectar; explicarlo en la UI | Alto | S | U |
| B-03 | Aviso de caducidad de conexión solo para LinkedIn y solo en Conexiones; Meta (90 días) avisa cuando ya ha caducado | `ConnectionController.php:84`, `Jobs/RefreshSocialAccount.php:79` | Generalizar a toda caducidad conocida, con notificación y correo N días antes | Medio | S | Ya |
| B-04 | Tableros de Pinterest: solo los 25 primeros, una vez, sin refresco | `PinterestConnector.php:22,83,112` | Paginar con `bookmark` y botón de refresco | Medio | S | Ya |
| B-05 | Primer comentario no implementado en Threads ni Bluesky | `ThreadsPublisher.php:33`, `BlueskyPublisher.php:33` | Implementar `FirstCommenter` reutilizando `ThreadsComments`/`BlueskyComments` | Medio | M | Ya |
| B-06 | Competidores solo en Instagram | `app/Services/CompetitorService.php:36` | Fuentes para Bluesky (`getProfile`/`getAuthorFeed`) y YouTube (`channels.list`) | Medio | M | Ya |
| B-07 | Canva: sin verificación JWKS del `correlation_jwt` | `app/Services/Canva/CanvaDesigns.php:134` | Leer y cachear `/rest/v1/connect/keys` y validar RS256 | Medio | S | Ya |
| B-08 | Canva: PDF solo como enlace de 24 h; Brand Templates y Autofill fijados a `false` | `CanvaJobs.php:161,227`, `CanvaCapabilities.php:29` | Tipo PDF en la biblioteca; flujo de Autofill según capacidades | Medio | M-L | Ya + U |
| B-09 | API v1 muy por detrás del MCP: faltan listar/borrar/cancelar/publicar posts, marcas, cuentas, media, inbox, informes, mejores horas, competidores, SmartLinks, sitios | `route:list` (`api/v1/*`) | Endpoints REST sobre los servicios existentes y ampliar la OpenAPI | Medio | L | Ya |
| B-10 | MCP sin herramientas para SmartLinks, Ads, autolistas, alta/baja de competidores, ocultar comentarios, textos guardados, presets y papelera | `app/Mcp/Tools/*` | Herramientas finas sobre los servicios | Bajo-Medio | M | Ya |
| B-11 | Threads guarda los scopes pedidos como concedidos: si el usuario quita los de respuestas, el inbox falla | `ThreadsConnector.php:103` | Tratar el error de permiso como «reconecta» y retirar el scope | Medio | S | Ya |
| B-12 | Facebook sin publicación de varias fotos ni stories | `config/media_specs.php:86` | Dos tipos de contenido nuevos | Medio | M | Ya |
| B-13 | Solo los fallos de publicación notifican; informes programados, MP4/GIF, IA, Canva e importaciones se quedan en su panel | `SendScheduledReport.php:101-105`, `RunStudioAiJob.php:27`, `RunCanvaJob.php:42` | Notificación en BD y correo opcional | Bajo-Medio | S | Ya |
| B-14 | LinkedIn y Pinterest no aparecen en la tabla de cobertura del inbox | `InboxService.php:36,63-71` | Añadir filas; `CommentSource` de LinkedIn tras la aprobación | Bajo | S-M | Ya / U |
| B-15 | Series: fechas perdidas sin recuperar, ocurrencias sin heredar aprobación; publicación manual sin correo ni recordatorio | `CONTINUE-HERE.md`, M09, M10 | Relleno de lo perdido (opcional), herencia de aprobación, recordatorio | Bajo | M | Ya |
| B-16 | Carpetas del estudio: no se renombran ni borran (la ruta existe) ni se mueve un diseño | `resources/js/Pages/Studio/Index.vue:118,179` | Menú de carpeta y «Mover a carpeta» | Medio | M | Ya |
| B-17 | Opciones de publicación: sin portada de vídeo en LinkedIn, sin búsqueda de lugares en IG, sin `place` en FB, `user_tags` sin editor web | ledger de la suite | Implementar por partes | Bajo | M | Ya |
| B-18 | Estudio: sin PPTX, sin transcripción/traducción de subtítulos, sin miniaturas por diseño | C16, C18, C01 | Piezas independientes | Bajo | L | Ya / U |
| B-19 | Inventario desfasado (C24, M11, C22) | `reference-inventory.md` | Corregirlo | Bajo | S | Ya |
| B-20 | Rutas con nombre repetido entre web y API (`studio.folders.*`) y nombres autogenerados en datasets | `routes/studio.php:37-39`, `routes/studio-api.php:25,46-47` | Nombres únicos (`api.studio.*`) | Medio | S | Ya |

### Contratos sin verificar contra la API real (orden de riesgo para los smokes)

1. LinkedIn: versión (A-04) y escape del comentario (`LinkedInPublisher.php:156`, «A confirmar»).
2. Pinterest: las apps nuevas solo escriben en sandbox y `PINTEREST_API_HOST` apunta a producción (`config/social.php:171`); `image_url` exige que la URL firmada de R2 sea pública.
3. Modelos de IA por defecto `gpt-image-2.5-sunburst` y `gemini-nano-banana-2.1` (`config/studio.php:54,62`).
4. Threads: códigos de error, token en cabecera y corte del día de las métricas.
5. Bluesky: host del AppView, `presentation: gif` y `aud` del vídeo.
6. Canva entero, incluido el patrón del host de descarga.
7. Inbox de YouTube/Threads/Bluesky y `location_id`/`user_tags` de Instagram.

## C. Backend: refactor y coherencia

| ID | Hallazgo | Evidencia | Propuesta | Imp. | Esf. |
|---|---|---|---|---|---|
| C-01 | Instagram y Threads duplican ~14 métodos y ya divergen ante un contenedor fallido | `ThreadsPublisher.php:144-347`, `InstagramPublisher.php:161-441` | `ContainerPublishFlow` común con ganchos por red | Medio-Alto | M |
| C-02 | TikTok y YouTube duplican el presupuesto de subida | `TikTokPublisher.php:369-387`, `YouTubePublisher.php:607-625` | `UploadPassBudget` sobre `StepBudget` | Medio | S |
| C-03 | `YouTubePublisher` (930 líneas) hace de cliente, subida, comentarios y cuota | `YouTubePublisher.php`, `YouTubeComments.php:20` | `YouTubeClient` + `YouTubeResumableUpload` | Medio | M |
| C-04 | Controladores web y API del estudio con la misma validación y orquestación | `StudioController.php`, `StudioApiController.php` | FormRequests compartidos y orquestación en el servicio | Medio | M |
| C-05 | `PostService` (1095 líneas; `plan` de 142) | `app/Services/PostService.php:782,934` | `PostPlanner`, `PostScheduling`, `PostSignature`; `PostService` como fachada | Medio | L |
| C-06 | `SiteService` (661), `StudioAvState` (803), `StudioRenderer::render` (178), `RemoteMediaImporter::run` (143) | ficheros citados | `SiteBlockNormalizer`, `SiteAccess`, `SitePublicInteractions`; partir `render` por fases | Medio | M |
| C-07 | Inbox: Meta no implementa `CommentSource`; redes y `can_hide` escritos a mano en 6 sitios | `InboxService.php:36-70`, `InboxController.php`, `ListComments.php:50` | Meta como `CommentSource` + capacidad `HidesComments`; derivar listas | Medio | M |
| C-08 | Propiedad comprobada de tres formas (12 Policies frente a 87 comprobaciones manuales; 88 `abort()` en servicios) | `PostApiController.php:29,60`, `LibraryService.php:29…` | Policies o `Ownership::assert()` con excepción de dominio | Medio | M |
| C-09 | 458 `withoutUserScope()`, 198 seguidos de `where('user_id')` | censo grep | Scope `ownedBy()` en `BelongsToUser` y migración mecánica | Medio | M |
| C-10 | Transacción con E/S: `lockForUpdate` mientras sube a almacenamiento; borrado de fichero antes del commit | `StudioImages.php:157-185`, `StudioFonts.php:181-186` | Subir fuera de la transacción; borrar en `afterCommit` | Medio | S |
| C-11 | `Period::make` repetido 11 veces con tres formas de error | `AnalyticsApiController.php:22`, `ExportController.php:54`, `GetStats.php:53` | `Period::fromInput()` con `ValidationException` | Medio | S |
| C-12 | Panel por marca sin caché (los datos cambian una vez al día) | `DashboardController.php:33,71` | `Cache::flexible` con versión por marca | Medio | S |
| C-13 | Sin `preventLazyLoading` ni tests de recuento de consultas en estudio y sitios | `AppServiceProvider.php:108` | Activarlo fuera de producción + tests | Medio | S |
| C-14 | 27 migraciones con fecha futura (hasta `2026_12_10`) | `database/migrations` | Convención con test, o `schema:dump` | Medio | S |
| C-15 | Config muerta o mágica: `reports.timeout` sin uso; ~25 límites de ritmo con números fijos | `config/reports.php:18`, `AppServiceProvider.php:137-177` | Usar config; `config/rate_limits.php` | Bajo | S |
| C-16 | Colas fijas en código (`onQueue('media')`); `retry_after` de redis 90 < timeout 110; `media` concentra importación, Canva, IA y render | `Jobs/Studio/*.php:20`, `config/queue.php:77` | Colas por config, `retry_after` 180, cola propia para IA/render | Bajo-Medio | S |
| C-17 | 73 `app(X::class)` y 17 `= new` en constructores | censo grep | Inyección por constructor | Bajo | S |
| C-18 | Duplicación menor: contador diario, escape LIKE, bloqueo del estado OAuth | `SiteService.php:550`, `SmartLinkService.php:395`, `OAuthController.php:52` | `DailyCounter`, macro `whereLikeEscaped`, `OAuthStateStore` | Bajo | S |

## D. Frontend: rendimiento, piezas comunes y UX

| ID | Hallazgo | Evidencia | Propuesta | Imp. | Esf. |
|---|---|---|---|---|---|
| D-01 | pdf-lib (430 KB) se carga al abrir el editor | `ExportDialog.vue:6`, `CaptionsPanel.vue:4`, `Studio/exports.js:1` | `await import('pdf-lib')`; mover `downloadBlob` | Alto | S |
| D-02 | Ocho diálogos del editor con import estático (~1,1 MB al abrir) | `Pages/Studio/Editor.vue:8-19` | `defineAsyncComponent` | Alto | S |
| D-03 | Once copias de lector XSRF + `fetch`; cuatro con regex sin anclar | `AiSuggest.vue:25`, `useWorkflow.js:6`, `Studio/api.js:1`… | Un `http.js` con error tipado y cancelación | Alto | M |
| D-04 | 25 `confirm()` nativos en acciones destructivas | `Brands/Index.vue:9`, `Sites/Edit.vue:119`… | `ConfirmDialog` + `useConfirm()` | Alto | M |
| D-05 | Cuatro patrones de diálogo; trampa de foco incompleta en `UtmModal` | `Modal.vue`, `StudioDialog.vue:14`, `UtmModal.vue:79` | Un `BaseDialog` sobre `<dialog>` nativo | Medio | M |
| D-06 | Flash por página con dos estilos; errores que se pierden y mensajes que nunca se ven | `Reports/Index.vue:291`, `Sites/Edit.vue:152`, `StudioController.php:59`, `ApiKeyController.php:67` | `flash` en `HandleInertiaRequests` + banner global | Medio | M |
| D-07 | Listas cortadas sin avisar (papelera y biblioteca 200, avisos 100, informes 20) | `TrashController.php:24`, `LibraryController.php:30`… | Paginación común | Medio | M |
| D-08 | Sondeos sin tope ni backoff (Reports) o que se solapan (Canva) | `Reports/Index.vue:165-169`, `Canva/Index.vue:107-110` | `usePolling` con tope, backoff y pausa con pestaña oculta | Medio | S |
| D-09 | Seis pantallas muestran la hora del navegador y no la de la marca | `Inbox/Index.vue:83`, `Trash/Index.vue:18`… | `format.js` global con zona | Medio | S |
| D-10 | Errores de formulario sin `aria-describedby`/`aria-invalid` (80 `InputError`) | `Components/InputError.vue` | `FormField` o prop `id` | Medio | M |
| D-11 | `Editor.vue` con 139 declaraciones de nivel superior | `Editor.vue:30-212` | `useStudioSave`, `useStudioHistory`, `useTimelinePlayback`, `usePresentation`, `useChartFlow`, `EditorToolbar` | Medio | L |
| D-12 | Páginas con 3-4 funciones (`Sites/Edit`, `Reports/Index`, `Calendar/Index`, `SmartLinks/Index`, `useComposer.js`) | ficheros citados | Subcomponentes y composables por función | Medio | L |
| D-13 | Plantillas en una línea (hasta 3.376 caracteres); sin ESLint ni Prettier | `Inspector.vue`, `StudioChartsDialog.vue`, `Editor.vue:669` | Prettier + `eslint-plugin-vue` con `--check` en CI | Medio | S |
| D-14 | Sin tipos: 0 `.ts` en `resources/js`, 250 props sin forma, sin `vue-tsc` | `tsconfig.json` | JSDoc + `checkJs` o `lang="ts"` progresivo; tipos generados desde PHP | Medio | L |
| D-15 | `compileTimeline()` dos veces y `JSON.stringify` por cambio | `Editor.vue:178,180,198` | Un `computed` y `some()` | Bajo | S |
| D-16 | `ai.status` pedido dos veces y `call()` copiado | `AiSuggest.vue:36`, `ScheduleSection.vue:36` | `useAiStatus()` | Bajo | S |
| D-17 | `role="tab"` sin flechas ni `tabpanel` | `Calendar/Index.vue:223`, `StudioCollabPanel.vue:155`, `ApiKeys/Index.vue:129` | Patrón de `NetworkTabs` | Bajo | S |
| D-18 | 14 `<img>` de rejilla sin `loading="lazy"` | `StudioAiPanel.vue:177`, `Brands/Index.vue:41`… | Añadirlo | Bajo | S |
| D-19 | URL de datasets construidas a mano | `Studio/dataset-client.js:8-9` | Ziggy | Bajo | S |
| D-20 | Código muerto: `NavLink`, `ResponsiveNavLink`, `concurrently`, `dotenv`, `react` extraneous | `package.json:18-19` | Borrar y `npm prune` | Bajo | S |
| D-21 | Ortografía y terminología («dejara», «¿», «Email»/«correo») | `ApiKeys/Index.vue:35`, `Reports/Index.vue:95` | Repaso de textos | Bajo | S |

## E. Tests, CI, seguridad operativa y repositorio

| ID | Hallazgo | Evidencia | Propuesta | Imp. | Esf. |
|---|---|---|---|---|---|
| E-01 | Fuera de la CI: E2E, tests de Node, typecheck, auditorías, lint de OpenAPI, ledger-lint y parte de `tests/studio_runtime` | `.github/workflows/ci.yml:41-52` | Pasos nuevos y E2E troceado nocturno | Alto | M |
| E-02 | E2E en serie (1,5 h): `workers: 1`, un `php -S`, tres proyectos repiten todo | `playwright.config.ts:13-14,35-38`, `serve.cjs:22` | Servidor + SQLite + puerto por worker; móvil solo en `@responsive`; `--shard` | Alto | L |
| E-03 | Puerto 8080 fijo | `tests/e2e/isolated/runtime.ts:4-6`, `serve.cjs:9,25` | `E2E_PORT` o puerto libre | Medio | S |
| E-04 | Test intermitente con servidor HTTP real y `usleep` | `tests/Feature/Media/UrlSourceRealHandlerTest.php:384` | Hacerlo determinista o marcarlo integración | Medio | S |
| E-05 | Sin HSTS; sin CSP global ni Permissions-Policy en la app Inertia | `deploy/hestia/nginx-overrides.conf:19-25` | HSTS; CSP `Report-Only` con nonce de Vite y luego estricta | Medio | M |
| E-06 | Rotación de `APP_KEY` sin documentar para tokens de redes y Canva; sin comando de recifrado | `docs/modulos/despliegue.md:415` | Documentar y `app:reencrypt` | Medio | M |
| E-07 | `Http::preventStrayRequests` solo en 73 de 307 ficheros | `tests/Pest.php:6-10` | Global en `TestCase::setUp` | Medio | S |
| E-08 | 9 tests omitidos: el camino nativo MP4/GIF nunca corre en CI | `StudioAvProcessTest:122`, `StudioAvQueueTest:439`… | Job nocturno con ffmpeg + Chromium + `STUDIO_AV_NATIVE_TEST=1` | Medio | M |
| E-09 | Sin tests: `RequestPostApproval`, `api.approvals.pending`, `api.collaborators.index`, `workflow.collaborators.index` | `app/Mcp/Tools/RequestPostApproval.php:13`, `routes/post-workflow-api.php:11,15` | Tests de permisos y acceso cruzado | Medio | S |
| E-10 | Tests lentos (Bluesky 41 s, 10 s por caso; causa sin verificar) | junit de `testing/t11` | `--profile`, simular DNS y reloj, `--parallel` | Medio | S |
| E-11 | Tests de Node sin script; uno exige `--expose-gc` | `package.json:5-9` | `test:unit` y `test:browser` | Medio | S |
| E-12 | `storage/framework/testing` ocupa 7,6 GB | `tests/e2e/global-teardown.ts:3-6` | Purgar ejecuciones antiguas | Medio | S |
| E-13 | `docs/roadmap` pesa 211 MB (208 de `testing/`); `.gitignore` solo cubre un nivel; ledger de 795 KB | `.gitignore` | Ignorar `**/testing/**/{results.json,*.zip,raw/*.xml}`; evidencias como artefactos; archivar el ledger | Medio | M |
| E-14 | Dependencias: `laravel/mcp` y `laravel/passkeys` fijadas; `web-auth/webauthn-lib` usada sin declarar; Inertia 2→3, Tailwind 3→4 | `composer.json:14-15`, `PasskeyGateway.php` | Declarar webauthn-lib; plan de mayores | Medio | M |
| E-15 | CI doble por commit, sin `concurrency`, `timeout-minutes` ni caché de composer | `ci.yml:3-6` | `push: [main]` + PR, cancelación, caché, jobs paralelos | Medio | S |
| E-16 | E2E frágil: 32 `waitForTimeout`, 97 `.first()`, 151 `getByText` | `a11y.spec.ts:126`, `composer.spec.ts:473,669` | `expect.poll`, `getByRole`/`getByTestId` | Bajo | M |
| E-17 | Tres configs de Playwright fuera de la batería (roadmap/T-43, audio, presets) | `playwright.roadmap.config.ts` | Integrarlas o borrarlas | Bajo | S |
| E-18 | Doc contradictoria: latido 600 s frente a 180 s; docroot de Hestia | `despliegue.md:192,227`, `deploy/hestia/README.md:33,60` | Unificar y verificar en el VPS | Bajo | S |
| E-19 | Fakes HTTP escritos a mano, sin fixtures grabados | `tests/Fixtures` | Fixtures por red y test de contrato | Bajo | M |
| E-20 | `app:health` no avisa de `APP_DEBUG=true` en producción | `HealthCheck.php` | Check adicional | Bajo | S |

## F. Funciones nuevas propuestas

**[D]** = requiere decisión del usuario.

| ID | Propuesta | Se apoya en | Bloqueos | Valor | Esf. |
|---|---|---|---|---|---|
| F-01 | Registro de actividad visible | `AuditLog` (ya se guarda) | — | Alto | S |
| F-02 | Mejor hora en un clic en compositor y calendario | `BestTimesService` | — | Alto | S |
| F-03 | Duplicar post (borrador / otra marca) | `PostService`, `LibraryService` | — | Alto | S |
| F-04 | Estado del sistema en la UI (colas, cron, tokens, cuotas) | `AppHealth`, `QueueProbe`, `QuotaPolicy` | Depende de A-09 | Alto | S-M |
| F-05 | Objetivos y alertas (seguidores, engagement, caídas, token) | `AccountSnapshot`, `Calculator`, notificaciones | — | Alto | M |
| F-06 | Webhooks salientes firmados + eventos de dominio | `PublishOutcomes`, `UrlGuard` | — | Alto | M |
| F-07 | PWA instalable + push | iconos ya presentes | VAPID, HTTPS | Alto | M |
| F-08 | Adaptar contenido entre redes con IA + alt text en el compositor | `AiAssistant`, `StudioAiText::altText` | `ANTHROPIC_API_KEY` | Alto | S-M |
| F-09 | Importación masiva de posts por CSV | `BatchService`, `posts/validate` | — | Alto | M |
| F-10 | Copia de seguridad y exportación completa (ZIP) | `ExportController`, `MediaStorage` | — | Alto | M |
| F-11 | Búsqueda global / paleta de comandos | `UserScope` | — | Alto | M |
| F-12 | Resumen semanal por correo/in-app (IA opcional) | `ReportSchedule`, `AggregateAnalytics` | SMTP | Alto | M |
| F-13 | Mastodon y Telegram | patrón `Bluesky/`, `CredentialConnectionController` | API sin verificar hoy | Medio | S-M |
| F-14 | Autolistas desde RSS | `AutolistService`, `UrlGuard` | — | Medio | M |
| F-15 | Acortador propio con clics | `SmartLink` | — | Medio | M |
| F-16 | Recordatorio de publicación manual por correo/push | `ManualPublishService` | SMTP / F-07 | Medio | S |
| F-17 | Calendario de fechas señaladas | `CalendarService` | datos propios | Medio | S-M |
| F-18 | Feed .ics del calendario | `CalendarService` | — | Medio | S |
| F-19 | Marca de agua automática y exportación en lote a los formatos del post | kit de marca, variantes | — | Medio | M |
| F-20 | Reciclado inteligente de mejores posts | `PostMetricSnapshot`, `Autolist` | — | Medio | M |
| F-21 | Resumen y borradores del inbox con IA **[D]** | `InboxService` | envía comentarios de terceros | Medio | M |
| F-22 | Plan de contenido en lenguaje natural (lote) | `proposeSchedule`, `BatchService` | clave IA | Medio | L |
| F-23 | Animaciones por objeto; más plantillas | `StudioTimeline` | — | Medio | L |
| F-24 | Atajos globales, preferencias de notificación, onboarding guiado | varios | — | Medio | S |
| F-25 | Modo oscuro | Tailwind | toca toda la UI | Medio | L |
| F-26 | ASR de subtítulos **[D]**, PPTX, Flows **[D]** | estudio / inbox | proveedor, política | Bajo-Medio | L |
| F-27 | X **[D]** (pago por uso), Google Business Profile **[D]** (aprobación incierta), Twitch **[D]** (solo métricas) | `Network.php` | coste / aprobación | Medio | M-L |

## Bloqueado por el usuario o por terceros

- Smokes reales CS-M-01..08 y los M-xx anteriores (credenciales y cuentas).
- Auditoría de TikTok (Direct Post y scopes de analítica).
- Meta: `pages_read_user_content`, `ads_read`, revisión para DMs y hashtags; renovar acceso cada 90 días.
- LinkedIn: Community Management y `r_member_social`. Pinterest: acceso estándar.
- Protección de `main` y merge de las PRs #15, #16, #18 y #19.
- Despliegue en el VPS (workers, R2, dominio verificado, copias).
- Decisiones: X, GBP, Twitch, proveedor de ASR, envío de comentarios a la IA, Flows.

## Lo que está bien (sin hallazgos)

Timeouts en todas las llamadas HTTP; cadena de plazos de jobs 110 < 120 < 180; índices; validación de post compartida entre web, API y MCP; sin código muerto del arnés antiguo en `app/`; sondeos del frontend limpiados al desmontar; rate limits en login, 2FA, passkeys, MCP y las 101 rutas `api/*`; API keys con abilities y caducidad; sin tokens en logs; `.env.production.example` seguro; `npm audit` con 0 avisos.
