# Publicación y cola

Un `Post` tiene uno o varios `PostTarget` (uno por cuenta/red). Cada destino se publica de forma independiente y con idempotencia.

## Piezas

| Pieza | Ruta |
|---|---|
| Alta, edición, programación | `app/Services/PostService.php`, `app/Http/Controllers/PostController.php` |
| Despachador (cada minuto) | `app/Jobs/DispatchDuePosts.php` |
| Publicador de un destino | `app/Jobs/PublishPostTarget.php` |
| Sondeo de procesos asíncronos | `app/Jobs/PollPublishStatus.php` |
| Recuperación | `app/Services/PublishRecovery.php` |
| Resultados y avisos | `app/Services/PublishOutcomes.php`, `app/Notifications/PublishFailed.php` |
| Política de cuota | `app/Services/QuotaPolicy.php` |
| Historial | `app/Models/PublishAttempt.php` (tabla `publish_attempts`) |
| Publicadores | `Meta/Instagram/`, `Meta/Facebook/`, `Google/YouTubePublisher.php`, `TikTok/TikTokPublisher.php`, `Bluesky/BlueskyPublisher.php` |

## Garantías (CA-06 / CA-07)

`PublishPostTarget` implementa `ShouldBeUnique` por destino y reclama la fila con `lockForUpdate`. Persiste el estado `publicando` **antes** de llamar a la red. Antes de reintentar, o si ya hay referencia externa, llama a `Publisher::findPublished()` y solo publica si la red no tiene constancia. Así un timeout no produce duplicados.

Clasificación de errores (`app/Connectors/Exceptions/`):

| Excepción | Efecto |
|---|---|
| `TransientPublishException` | vuelve a `programada` con backoff `[60, 300, 900]` s; hasta `PUBLISH_MAX_ATTEMPTS` (4) |
| `PermanentPublishException` | `fallida` con motivo y aviso `PublishFailed` (una sola vez) |
| `QuotaExceededException` | según `PUBLISH_QUOTA_POLICY` |
| `ReconnectRequiredException` | el destino espera la reconexión; se conserva |

Un fallo inesperado deja el destino `publicando`; `PublishRecovery` lo retoma tras `PUBLISH_STUCK_AFTER_MINUTES` (10).

## Publicar ahora

`PostService::publishNow()` (y `create`/`update` con `publish_now: true`) publica sin esperar a la hora programada, con la misma validación de contenido y cuentas que al programar pero sin exigir fecha futura: la fecha la pone el servidor (`now()`) y no se acepta junto con `scheduled_at`. Los destinos quedan `programada` y, **después del commit** (`DB::afterCommit`, porque la cola `database` tiene `after_commit=false`), se encolan los vencidos de ese post con `DispatchDuePosts::dueTargets($post)`, la misma consulta que usa el despachador del minuto.

No hay defensa nueva ni cambios en `PublishPostTarget` (ADR-005, opción O1): un segundo despacho del mismo destino lo descarta `ShouldBeUnique` si el job sigue en cola, y el `claim()` con `lockForUpdate` sale sin publicar si el destino ya está `publicando` o en un estado final. Un post en vuelo o publicado se rechaza con el mismo error que `update`. Durante unos segundos el destino se ve `programada`, hasta que el worker lo reclama.

Se usa desde el compositor (menú de la barra de acciones), desde el calendario y la lista (`POST posts/{post}/publish-now`, con confirmación) y desde el MCP (`publish_post_now`).

## Compositor y texto por red

El compositor (`resources/js/Pages/Posts/Compose.vue`, pantalla completa) escribe un **texto común** y permite «Editar por red»: cada destino puede tener un texto propio (`post_targets.text`; `null` = vinculado al común). Lo que se publica es el **texto efectivo** (`PostTarget::effectiveText()`), resuelto al leer, de modo que cambiar el texto común actualiza todos los destinos vinculados y deja intactos los propios. Los límites de caracteres y hashtags de cada red se validan sobre el texto efectivo.

El **tipo de publicación** de cada red se elige en un desplegable (listbox accesible, `Composer/ContentTypeSelect.vue`) junto a la pastilla de la cuenta activa, con icono, nombre y subtítulo por tipo. El servidor expone en `contentTypes` el `label`, la `description` (`ContentType::description()`) y las `limitations` de cada tipo ofrecido; si una red solo ofrece un tipo se muestra como etiqueta fija.

Las **opciones por red** (YouTube y TikTok) viajan en `options` de cada destino y las normaliza `PostService::plan`. Los interruptores se recuerdan por marca y red en `composer_presets` (`ComposerPresetService`) y se usan como valores iniciales al activar la red: solo los escribe el compositor web, nunca el MCP. Las **notas internas** (`posts.notes`) nunca se publican. El generador de enlaces con UTM es un diálogo del cliente sin modelo ni endpoint.

**Hashtags sugeridos** (C-04a, D5: **sin IA**): `HashtagStats::suggest(User, Brand, ?Network)` extrae los `#tags` del texto publicado de cada destino de la marca (el texto propio del destino manda sobre el del post; misma extracción `App\Support\Hashtags` que los *facets* de Bluesky, Unicode y sin distinguir mayúsculas), los cruza con la **última lectura de métricas** de cada destino (`post_metric_snapshots`, interacciones como en `BestTimesService::engagement()`) y los ordena por rendimiento: interacciones medias de los posts que lo usan, que equivale a la mejora sobre la media de la marca (`lift_pct`). Solo salen los usados **al menos 2 veces** (`social.hashtags.min_uses`), hasta 15 (`limit`), dentro de `social.hashtags.days` (365) y con caché de 60 minutos por usuario, marca y red. Una marca sin histórico devuelve `available: false` y «Aún no hay datos suficientes…». `HashtagController` (`GET brands/{brand}/hashtags`, `throttle:60,1`, `network` opcional; marca ajena = 404 por el `UserScope`) lo sirve al compositor y el MCP lo expone como `suggest_hashtags`; nunca hay llamada a Anthropic ni a otro servicio. En el cliente, `Composer/HashtagSuggestions.vue` (botón **Hashtags** de la barra) muestra los chips; al pulsar uno se inserta `#tag` en el cursor del campo activo con `insertIntoActive(text, {pad: true})` (`padForInsert`: un espacio a cada lado solo si pega con una palabra) y se avisa si algún destino supera su máximo (`limits[tipo].max_hashtags`, Instagram 30; el de Threads se añadirá con la red).

**Textos guardados** (`saved_texts`, `App\Models\SavedText`): fragmentos y grupos de hashtags por usuario (`user_id` con `UserScope`) con `brand_id` opcional (nulo = «Todas las marcas»; al borrar la marca se borran los suyos). `SavedTextService` hace el CRUD y `forBrand()` devuelve al compositor (prop `savedTexts` de `PostController::compose`) los de la marca más los globales. `SavedTextController` sirve `settings/saved-texts*` (la página `SavedTexts/Index` y el CRUD); la `SavedTextPolicy` responde 403 al editar o borrar un texto ajeno (el controlador carga sin `UserScope` para poder auditarlo, `access.denied`) y `SavedTextRequest` exige que la marca sea del usuario. En el cliente, `useComposer` registra el «campo activo» (`registerField`/`insertIntoActive`; cada `TextEditor` se registra al recibir el foco) y `Composer/SavedTextsMenu.vue` inserta en el cursor de ese campo. No hay herramienta MCP: no son un campo del post.

**Primer comentario** (C-01b, Instagram, Facebook y YouTube; TikTok y las Stories no lo admiten). Vive en columnas de `post_targets` (`first_comment`, `first_comment_status`, `first_comment_attempts`, `first_comment_external_id`, `first_comment_error`, `first_comment_at`) y su estado, el enum `FirstCommentStatus` (`pendiente` · `comentando` · `publicado` · `fallido` · `no_admitido`), es **independiente de `PostStatus`**: un fallo del comentario nunca degrada un destino publicado.

- **Alta y máximo.** `PostRequest` (`targets.*.first_comment`) y `PostService::plan()` validan contra `media_specs.types.*.first_comment.max` (Instagram 2.200, Facebook 8.000, YouTube 10.000); un tipo sin entrada (TikTok, Stories) lo rechaza. Cambiar solo el comentario **no** llama a `freshAttempt()`. No se guarda en la biblioteca ni en las autolistas.
- **Encolado.** `PublishOutcomes::published()` es el único punto de entrada: deja el comentario `pendiente` en la misma transacción y despacha `PostFirstComment` con `DB::afterCommit`. `PublishPostTarget` no cambia.
- **Job `PostFirstComment`** (cola `publishing.comment_queue`, por defecto `poll`): `ShouldBeUnique` (`fc:{id}`) y reclamo con `lockForUpdate` (pasa a `comentando` y persiste antes de llamar a la red). Idempotencia en tres capas: si ya hay `first_comment_external_id` no hace nada; **siempre** busca el comentario propio (`FirstCommenter::findOwnComment`, por autor y texto normalizado) antes de crear; crea y guarda el id en la misma pasada. La única ventana de duplicado es «la red aceptó y el worker murió antes de guardar el id»: el siguiente intento la cierra con la búsqueda. Transitorio: vuelve a `pendiente` y se libera con backoff `[60, 300]` s (tope `PUBLISH_COMMENT_MAX_ATTEMPTS`, 3); permanente o reconexión: `fallido` con el motivo y aviso in-app (`FirstCommentFailed`, canal `database`); comentarios cerrados, vídeo privado o «para niños»: `no_admitido`, sin aviso.
- **Conectores.** Contrato `Connectors\Contracts\FirstCommenter` (`comment()`, `findOwnComment()`), aparte de `Publisher`; `ConnectorRegistry::commenterFor()` lo devuelve si el publicador de la red lo implementa (Instagram y Facebook sobre `Meta/Comments`; YouTube con `commentThreads`, 50 unidades de cuota por comentario y 1 por búsqueda). TikTok no lo implementa.
- **YouTube y reconexión.** Comentar exige el scope `youtube.force-ssl` (`config/social.php`). Las cuentas conectadas antes no lo tienen: `YouTubePublisher::canComment()` es falso, el compositor deshabilita el campo y pide reconectar el canal (en Conexiones, «+ Conectar» en la tarjeta de YouTube actualiza la cuenta por su identificador), y el job deja `fallido` («Reconecta el canal…») sin llamar a la API. Publicar no se ve afectado.
- **Recuperación.** El paso 6 de `PublishRecovery::run()` (`comments`) relanza los `comentando` colgados y los `pendiente` sin job vivo desde hace más de `PUBLISH_STUCK_AFTER_MINUTES`.
- **Editar y reintentar (solo web).** `FirstCommentService::update()` cambia el texto de un comentario `fallido` (`PATCH posts/{post}/targets/{target}/first-comment`, valida el máximo, no toca `text` ni los intentos) y `retry()` (`POST …/first-comment/retry`) lo pasa a `pendiente`, reinicia los intentos y despacha el job con el texto vigente; ambas con `throttle` y la política del post. Es la única edición admitida en un destino publicado. El MCP no lo expone: `create_post`/`update_post` aceptan `targets[].first_comment` (mismas reglas, vía `PostRequest::rules()`) y `get_post` devuelve `first_comment`, `first_comment_status` y `first_comment_error`.
- **Marca de envío y búsqueda inconclusa.** `first_comment_sent_at` se escribe **antes** del POST de creación y `retry()` no la borra: es la constancia de que un envío pudo llegar a la red aunque diera timeout. Si `findOwnComment()` lanza `CommentSearchInconclusiveException` (la búsqueda acotada no llegó al final de la lista), el job solo crea si la marca es nula; con marca no crea a ciegas: backoff y, agotado, `fallido` con las dos salidas. Si la búsqueda concluye (llega al final), manda la red: adopta el comentario si está y lo crea si no.
- **Salidas del usuario (solo en `fallido`, con `throttle` y la política del post; 404 para destinos ajenos).** `POST …/first-comment/published` (`FirstCommentService::markPublished()`) cierra como `publicado` y limpia el error **sin llamar a la red ni encolar**; no guarda `first_comment_external_id`, así que un «publicado» marcado a mano no tiene id y no se distingue en datos de uno verificado salvo por ese campo nulo. `retry(force: true)` (`force` en `…/first-comment/retry`) despacha `PostFirstComment($id, true)`: `force` salta la guarda de búsqueda inconclusa solo para el envío anterior al clic (`first_comment_sent_at` < `requestedAt` del job). Si la pasada forzada escribe una marca nueva y da timeout o se libera con `release()`, las pasadas siguientes del mismo job aplican la regla normal (marca + inconclusa), de modo que un clic produce como máximo un POST a ciegas.
- **UI.** `Composer/FirstCommentField.vue` (contador por red, campo activo para textos guardados) dentro de `TargetOptions.vue`; `TargetStatusPanel.vue` muestra el estado con Editar y Reintentar cuando está `fallido`.

Limitación conocida (anterior a esta iniciativa, fuera de alcance): dos destinos de la **misma cuenta** con el **mismo texto efectivo** pueden confundirse en `Publisher::findPublished()`, que identifica lo ya publicado por el contenido. Si hace falta publicar dos veces en una cuenta, usa textos distintos.

## Recuperación (`PublishRecovery::run()`)

Se ejecuta al inicio de cada pasada del despachador:

- `failOrphans`: cuenta eliminada → `fallida`.
- `failInactiveProcessing`: destino `procesando` con cuenta sin reconectar más de `PUBLISH_RECONNECT_GRACE_HOURS` (72 h) → `fallida` (`cuenta_no_disponible`).
- `failExpired`: vencidas con más de `PUBLISH_MAX_LATENESS_HOURS` (24 h) de retraso → `fallida` (vencida).
- `resumeStuck` y `repoll`: retoma colgadas y procesos asíncronos.
- `resumeComments` (clave `comments`): relanza los primeros comentarios colgados o pendientes sin job vivo (ver «Primer comentario»).

## Política de cuota

`reschedule` (por defecto): aplaza hasta que la red libere cupo; falla con motivo `cuota` si el aplazamiento superaría `max_lateness_hours`. `fail`: falla al instante. En YouTube la cuota se cuenta localmente (`YouTubeQuota`): 10.000 ud/día, 1.600 por subida.

## Sondeo y subidas largas

- Instagram: contenedor → espera `status_code` → `media_publish`. Imagen y story publican en el acto si el contenedor ya está `FINISHED`; si no pasan a `procesando`.
- YouTube: subida reanudable en trozos de 8 MiB leídos en streaming (`MediaChunkReader`), nunca el vídeo entero en memoria.
- TikTok: subida por pasadas con presupuesto (`TIKTOK_UPLOAD_BUDGET` 80 s, marca dura `TIKTOK_PASS_SECONDS` 100 s). El estado vive en `progress`.
- Plazos: `PUBLISH_POLL_MAX_MINUTES` (30) para el procesado; `PUBLISH_UPLOAD_MAX_MINUTES` (240) para la subida.

## Bluesky

`BlueskyPublisher` (`Publisher` + `StatusPoller`) escribe un `app.bsky.feed.post` en el repositorio de la cuenta. No cambia `PublishPostTarget` ni `PollPublishStatus`.

| Tipo | Contenido | Límites (reglas en `config/media_specs.php`, topes en `config/social.php`) |
|---|---|---|
| `bluesky_post` | texto y de 0 a 4 imágenes | **300 grafemas** (un emoji compuesto cuenta 1) y 3000 bytes UTF-8; imagen: tope `BLUESKY_IMAGE_MAX_BYTES` (**2 MB** por defecto) |
| `bluesky_video` | 1 MP4 con texto opcional | tope `BLUESKY_VIDEO_MAX_BYTES` (**50 MB** por defecto), hasta 3 min |

- **Texto y facets.** `Facets::build()` genera enlaces (con esquema), hashtags (sin números puros, 64 grafemas como máximo) y menciones (`@handle`, solo si `resolveHandle` lo resuelve; si no quedan como texto plano). Los índices son **bytes UTF-8** (`byteStart`/`byteEnd`), no caracteres. `langs` sale de `BLUESKY_DEFAULT_LANGS` (`es`). El conteo por grafemas es `App\Support\TextLength` (PHP) y `resources/js/Composables/textLength.js` (`Intl.Segmenter`): `MediaValidator` lo aplica a compositor y MCP, así que el mensaje de 301 grafemas es el mismo en los dos.
- **Imágenes.** `uploadBlob` por imagen con su `aspectRatio`. Una imagen por encima del tope se recomprime en una **copia derivada** JPEG (`ImageDownscaler`: calidad 85→55 y, si hace falta, reducción de tamaño; cacheada en el disco de media por media, tope y versión de reglas; la original no se toca). Si no baja del tope, **falla el guardado** con un mensaje claro; si se puede, se avisa de la recompresión.
- **Vídeo.** `getUploadLimits` (cupo diario y email verificado; `canUpload` falso → fallo permanente con mensaje claro; cupo agotado → `QuotaExceededException` y la política de cuota), token de servicio (`getServiceAuth`), subida del MP4 en **una sola petición en streaming** (`MediaChunkReader`) a `video.bsky.app` con timeout `BLUESKY_UPLOAD_TIMEOUT` (80 s, dentro de los 85 s del paso) y el destino pasa a `procesando(jobId)`. `poll` consulta `getJobStatus`: `JOB_STATE_COMPLETED` con el *blob* → `createRecord` con `embed.video` y `publicada` con el enlace `bsky.app`; `JOB_STATE_FAILED` → fallo con su código. Un vídeo mayor que el tope se **rechaza al guardar**: «Bluesky solo admite vídeos de hasta 50 MB en esta instalación; este pesa X MB.»
- **Idempotencia.** La `rkey` es una TID (`Tid::generate()`, 13 caracteres base32-sortable, monótona) generada y guardada con `checkpoint()` en `progress.rkey` **antes** de `createRecord`. Un reintento la reutiliza y `findPublished()` hace `getRecord` (`RecordNotFound` → no existe). Además, ante cualquier error de `createRecord` (timeout con la respuesta perdida, 5xx o `rkey` repetida: el error exacto del PDS no está verificado) se comprueba con `getRecord` antes de fallar. Un fallo de red al comprobar es transitorio: nunca se publica a ciegas.
- **Errores** (`BlueskyErrors`): sesión inválida tras renovar → reconexión; 429/5xx/red → transitorio; cuenta suspendida o rechazo del contenido → permanente con mensaje.
- ⚠️ Sin verificar hasta el smoke real (T-30): el `aud` de `getServiceAuth` para subir vídeo (se usa `did:web:` + host del PDS), el error del PDS ante una `rkey` repetida y el host de lectura pública de métricas.

## Estado final del borrador de TikTok

Por defecto el vídeo va a la bandeja de TikTok. El destino queda `publicada` con la nota "enviado a la bandeja de TikTok" (o "revisa tus borradores" si pasa `TIKTOK_INBOX_GRACE_MINUTES` sin confirmación). ⚠️ Decisión de producto por confirmar: ver [configuración](../referencia/configuracion.md).

## Avisos

`PublishFailed` (base de datos + correo, cola `poll`), `ReconnectRequired` y `FirstCommentFailed` (solo base de datos). La UI los lista en `/notifications`.
