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
| Publicadores | `Meta/Instagram/`, `Meta/Facebook/`, `Google/YouTubePublisher.php`, `TikTok/TikTokPublisher.php` |

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

**Textos guardados** (`saved_texts`, `App\Models\SavedText`): fragmentos y grupos de hashtags por usuario (`user_id` con `UserScope`) con `brand_id` opcional (nulo = «Todas las marcas»; al borrar la marca se borran los suyos). `SavedTextService` hace el CRUD y `forBrand()` devuelve al compositor (prop `savedTexts` de `PostController::compose`) los de la marca más los globales. `SavedTextController` sirve `settings/saved-texts*` (la página `SavedTexts/Index` y el CRUD); la `SavedTextPolicy` responde 403 al editar o borrar un texto ajeno (el controlador carga sin `UserScope` para poder auditarlo, `access.denied`) y `SavedTextRequest` exige que la marca sea del usuario. En el cliente, `useComposer` registra el «campo activo» (`registerField`/`insertIntoActive`; cada `TextEditor` se registra al recibir el foco) y `Composer/SavedTextsMenu.vue` inserta en el cursor de ese campo. No hay herramienta MCP: no son un campo del post.

Limitación conocida (anterior a esta iniciativa, fuera de alcance): dos destinos de la **misma cuenta** con el **mismo texto efectivo** pueden confundirse en `Publisher::findPublished()`, que identifica lo ya publicado por el contenido. Si hace falta publicar dos veces en una cuenta, usa textos distintos.

## Recuperación (`PublishRecovery::run()`)

Se ejecuta al inicio de cada pasada del despachador:

- `failOrphans`: cuenta eliminada → `fallida`.
- `failInactiveProcessing`: destino `procesando` con cuenta sin reconectar más de `PUBLISH_RECONNECT_GRACE_HOURS` (72 h) → `fallida` (`cuenta_no_disponible`).
- `failExpired`: vencidas con más de `PUBLISH_MAX_LATENESS_HOURS` (24 h) de retraso → `fallida` (vencida).
- `resumeStuck` y `repoll`: retoma colgadas y procesos asíncronos.

## Política de cuota

`reschedule` (por defecto): aplaza hasta que la red libere cupo; falla con motivo `cuota` si el aplazamiento superaría `max_lateness_hours`. `fail`: falla al instante. En YouTube la cuota se cuenta localmente (`YouTubeQuota`): 10.000 ud/día, 1.600 por subida.

## Sondeo y subidas largas

- Instagram: contenedor → espera `status_code` → `media_publish`. Imagen y story publican en el acto si el contenedor ya está `FINISHED`; si no pasan a `procesando`.
- YouTube: subida reanudable en trozos de 8 MiB leídos en streaming (`MediaChunkReader`), nunca el vídeo entero en memoria.
- TikTok: subida por pasadas con presupuesto (`TIKTOK_UPLOAD_BUDGET` 80 s, marca dura `TIKTOK_PASS_SECONDS` 100 s). El estado vive en `progress`.
- Plazos: `PUBLISH_POLL_MAX_MINUTES` (30) para el procesado; `PUBLISH_UPLOAD_MAX_MINUTES` (240) para la subida.

## Estado final del borrador de TikTok

Por defecto el vídeo va a la bandeja de TikTok. El destino queda `publicada` con la nota "enviado a la bandeja de TikTok" (o "revisa tus borradores" si pasa `TIKTOK_INBOX_GRACE_MINUTES` sin confirmación). ⚠️ Decisión de producto por confirmar: ver [configuración](../referencia/configuracion.md).

## Avisos

`PublishFailed` (base de datos + correo, cola `poll`) y `ReconnectRequired`. La UI los lista en `/notifications`.
