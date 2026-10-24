---
design: "metricool-parity-2"
titulo: "Paridad con Metricool, segunda ola: primer comentario (C-01b) e importación de media (C-04)"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-24"
actualizado: "2026-10-24"
spec: spec.md                 # enlace hacia atrás (misma carpeta)
evaluacion: "evaluation.md"
plan: improvement-plan.md
adr: "docs/knowledge/candidates/pending/2026-10-24-primer-comentario-columnas-y-job-propio.md (sha256 1cfc1351…bfa90) · docs/knowledge/candidates/pending/2026-10-24-importacion-media-pipeline-unico-por-pasadas.md (sha256 183d9553…be2df1)"   # candidatos pending; los decide knowledge-curator
opcion_elegida: "O1"          # O1 en las dos piezas: C-01b = A1 · C-04 = B1 (validadas por el usuario el 2026-10-24)
generacion:
  inicio: 2026-10-24T14:09:41Z
  fin: 2026-10-24T14:16:32Z
  fuente: medido              # pasada 1; la pasada 2 abre su propio marcador
  tokens_reales: { entrada: 44, salida: 34176, cache_creacion: 95256, cache_lectura: 1529984 }
  eur: null                   # sin rates fiables: ejecuta rates-verify
  horas_ia: 0.43
  duracion: 7m
  ratio_usado: 300000         # default no calibrado
  pasada_2: { inicio: 2026-10-24T14:21:38Z, fin: 2026-10-24T14:23:19Z, fuente: medido, tokens_reales: { entrada: 26, salida: 8201, cache_creacion: 244674, cache_lectura: 1330743 }, horas_ia: 0.84, eur: null }
---

# Diseño: paridad con Metricool, segunda ola (C-01b y C-04)

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) (go con D1–D5 confirmadas) · **Plan:** [`improvement-plan.md`](improvement-plan.md) · **ADR:** dos candidatos en `docs/knowledge/candidates/pending/`, pendientes del Curator

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | **C-01b: A1**, columnas en `post_targets` y job `PostFirstComment`. **C-04: B1**, un solo pipeline URL/Drive por pasadas de 80 s con `Range` y `UrlGuard` |
| **Validada por el usuario** | 2026-10-24, en la puerta de la Fase 2-a de `/dev-cycle` (`elegida: O1` en las dos piezas) |

Este diseño abre opciones **solo para las dos piezas de complejidad Alta**. Cada pieza tiene su propia elección: **C-01b** (opciones A1–A3) y **C-04** (opciones B1–B3). C-01a, C-02 y C-03 no tienen opciones; su impacto va en §5.

## 1. Contexto y restricciones

**C-01b. Primer comentario por destino (IG, FB y YT).** La spec pide un comentario propio justo después de publicar. Un fallo no degrada el destino, nunca se duplica el comentario y se puede reintentar desde la UI y el MCP (CA-04..CA-07).

Hoy:

- `PublishPostTarget` (`app/Jobs/PublishPostTarget.php`) es `ShouldBeUnique` y reclama la fila con `claim()` (`lockForUpdate`).
- Antes de cualquier reintento, `PublishPostTarget` consulta la red con `Publisher::findPublished`.
- Todas las transiciones a `Published` pasan por `PublishOutcomes::published()` (`app/Services/PublishOutcomes.php:38`). La llaman tres sitios:
  - `PublishPostTarget::apply()`;
  - `PublishPostTarget::reconcile()`;
  - `PollPublishStatus.php:125`, que es el camino de YouTube, Reels y vídeo.
- Para comentar en Meta ya hay `Comments::fetch()` y `Comments::reply()` (`app/Connectors/Meta/Comments.php`), y `normalize()` ya calcula `is_own`.
- `PublishRecovery` (`app/Services/PublishRecovery.php`) retoma cada minuto lo que se quedó colgado.
- El campo `post_targets.progress` (JSON) lo sobrescriben `published()`, `processing()` y `PublishPostTarget:115`.

**C-04. Media desde una URL o desde Google Drive.** La spec pide una descarga en segundo plano, una defensa SSRF y que el resultado sea un `MediaAsset` normal (CA-14..CA-18).

Hoy:

- `MediaUploadService::finalize()` (`app/Services/MediaUploadService.php:119`) comprueba el tamaño, el tipo real (con `MediaProbe`) y el tamaño declarado.
- En producción, `MEDIA_DISK=local` (`docs/modulos/despliegue.md:204`). La subida local va por PHP-FPM y Nginx la corta a 512 MB (`deploy/hestia/nginx-overrides.conf:15`).
- Los workers de cron usan `--timeout=120` y `retry_after` vale 180 s (`config/queue.php`).
- Ya existe un patrón de trabajo por pasadas con presupuesto y continuación: la subida reanudable de YouTube (`YouTubePublisher.php:308-436`, `beginPass` / `budgetLeft` / `upload_budget_seconds` = 80).
- No hay ningún guardia SSRF en el código: `grep` de `NO_PRIV_RANGE`, `CURLOPT_RESOLVE` y `allow_redirects` vacío.

**Verificado en la documentación oficial (2026-10-24):**

- `commentThreads.insert` exige `youtube.force-ssl` y cuesta 50 unidades de cuota.
- `files.get?alt=media` de Drive admite `Range`.
- `drive.file` es un scope **no sensible**; `drive.readonly` es restringido.

Así se cierran tres de los ⚠️ de la spec.

**Restricciones que fijan el espacio de soluciones**

- ADR-001: Laravel 13 monolítico, cola `database` en MySQL y sin Redis. ADR-005: el texto efectivo se resuelve al leer, y Publicar ahora no cambia `PublishPostTarget`.
- La spec ya decidió (heredado de C-06) dos cosas:
  - un **job `PostFirstComment` aparte** que se encola tras `Published`;
  - **el fallo del comentario no marca el destino como fallido**.
- La spec ya decidió para C-04 un **job en la cola `media`, por tramos y con presupuesto de 80 s**, que termina en `finalize()` (D5 confirmada).
- `MEDIA_DISK=local` en producción. Un solo usuario, la UI en español y paridad con el MCP en los campos nuevos del post.
- No se reabre nada de `PublishPostTarget`: el comentario va **después**, nunca dentro de la publicación.

## 2. Opciones (2-3)

### Pieza C-01b: dónde vive el estado del comentario y cómo se ejecuta

**Común a las tres opciones.**

- **Contrato del conector.** Una interfaz nueva, `App\Connectors\Contracts\FirstCommenter`, con dos métodos:
  - `comment(account, externalId, text): string`, que devuelve el id del comentario;
  - `findOwnComment(account, externalId, text): ?string`.
- **Quién la implementa.**
  - `InstagramPublisher` y `FacebookPublisher` la implementan sobre `Comments`: buscan con `fetch()` + `is_own` + el texto normalizado.
  - `YouTubePublisher` la implementa con `commentThreads.list?videoId=…` filtrado por `authorChannelId` y `commentThreads.insert`.
  - El job pide la pieza con `ConnectorRegistry::commenterFor()`. `Publisher` no cambia y TikTok queda fuera por tipo.
- **Encolado.** En `PublishOutcomes::published()`, si el destino tiene `first_comment` y aún no hay comentario, se marca `pendiente` y se despacha `PostFirstComment` con `DB::afterCommit`. Es el único punto de entrada, así que cubre los tres caminos a `Published`.
- **Estados del comentario:** `pendiente` · `comentando` · `publicado` · `fallido` · `no_admitido`, en el enum nuevo `FirstCommentStatus`. Son independientes de `PostStatus`.

#### A1: columnas en `post_targets` + job `PostFirstComment` con reclamo propio (lo que dice la spec)

Migración aditiva en `post_targets`:

- `first_comment` (text, nulo);
- `first_comment_status`;
- `first_comment_attempts`;
- `first_comment_external_id`;
- `first_comment_error`;
- `first_comment_at` (marca del reclamo, para detectar un comentario colgado). Es una columna más que en la spec.

**Job `PostFirstComment`.**

- Implementa `ShouldBeUnique` con `uniqueId = "fc:{targetId}"` y va en la cola `poll` (`publishing.comment_queue`).
- Copia el `claim()` de la publicación: en una transacción con `lockForUpdate`, solo pasa de `pendiente` (o de `comentando` colgado más de `stuck_after_minutes`) a `comentando`, con `attempts + 1`.

**Idempotencia, en tres capas.**

1. Si ya hay `first_comment_external_id`, el job no hace nada.
2. **Siempre** llama a `findOwnComment()` antes de comentar. Si encuentra el comentario, guarda su id y termina.
3. Si no lo encuentra, llama a `comment()` y guarda el id en la misma pasada.

La ventana de duplicado queda reducida a «la red aceptó, pero el worker murió antes de guardar el id». El siguiente intento la cierra con `findOwnComment()`.

**Errores.**

| Error | Estado | Qué pasa |
|---|---|---|
| Transitorio | Vuelve a `pendiente` | Se reencola con backoff `[60, 300]`. Tope de 3 intentos |
| Permanente o reconexión | `fallido` | Lleva el motivo en español y un aviso in-app (`FirstCommentFailed`, canal `database`) |
| Comentarios cerrados, vídeo privado o «hecho para niños» | `no_admitido` | Sin aviso de error |

**Recuperación.** `PublishRecovery::run()` suma un paso 6, que relanza dos casos:

- los destinos con el comentario en `comentando` y colgados;
- los que están en `pendiente` sin job vivo desde hace más de 10 minutos.

**Reintentar desde la UI.**

- La ruta es `POST posts/{post}/targets/{target}/first-comment/retry`.
- La atiende `FirstCommentService::retry()`, que solo actúa si el destino está `Published` y el comentario en `fallido`. Pone el comentario en `pendiente`, reinicia los intentos y despacha el job.
- Usa `throttle` y la policy del post.
- `TargetStatusPanel.vue` muestra el estado y el botón «Reintentar».

**MCP.**

- La regla `targets.*.first_comment` se añade en `PostRequest`. `PostPayload::validatedPayload()` ya reutiliza `PostRequest::rules()`, así que la paridad de validación sale sola.
- El máximo por red se valida en `PostService::plan()` contra `media_specs.types.*.first_comment.max`, como el texto.
- `GetPost` devuelve `first_comment`, `first_comment_status` y `first_comment_error`.
- `PostService::fill()` **no** trata un cambio de `first_comment` como cambio de publicación: no llama a `freshAttempt()` ni a `reconcile`.

| Criterio | Valoración |
|---|---|
| Complejidad | Media. Un patrón que ya existe (`claim()` + consultar antes de actuar + recuperación) aplicado a una sola columna de estado |
| Riesgo | Bajo-Medio. La lista de comentarios de Meta podría tardar en mostrar el recién creado: se mitiga con el backoff de 60 s antes de reintentar. Además, `post_targets` crece en 6 columnas |
| Coste relativo | **S** |
| Reversibilidad | Alta. Las columnas son nulas y aditivas; quitar la función es borrar el despacho en `published()` |

#### A2: tabla aparte `post_target_comments` (1:1 hoy, 1:N mañana) + el mismo job

Nueva tabla `post_target_comments`:

- `post_target_id` único con `cascadeOnDelete`, más `user_id`;
- `body`, `status`, `attempts`, `external_id`, `error` y `claimed_at`;
- modelo `PostTargetComment` con `UserScope`.

El job, la idempotencia, la recuperación y el reintento son los de A1, pero sobre la tabla nueva.

El precio está en todo lo que lee o copia destinos, que tiene que cargar la relación:

- `PostService::plan()`, `fill()` y `lockEditableTargets()`;
- `GetPost`;
- las props de Inertia del compositor;
- `LibraryService` y `AutolistService`, que copian destinos.

Abre la puerta a varios comentarios o a un hilo por destino, y a guardar el historial de intentos. **Contradice la decisión de la spec** («`post_targets.first_comment`, con su estado…»), así que habría que enmendarla.

| Criterio | Valoración |
|---|---|
| Complejidad | Media-Alta. Una relación nueva en todas las lecturas y copias de destinos, y la sincronización en `fill()` |
| Riesgo | Medio. Si se olvida cargar la relación en alguna copia (biblioteca, autolistas, duplicar), el comentario se pierde sin aviso |
| Coste relativo | **M** |
| Reversibilidad | Media. Es una tabla aparte, fácil de borrar, pero los cambios en `fill()` y las copias se dispersan |

#### A3: texto en una columna y el estado en `post_targets.progress.first_comment` (JSON), sin job propio en la cola

Solo se añade la columna `first_comment`. El estado, los intentos y el id externo van en `progress.first_comment`.

`PollPublishStatus` y `PublishPostTarget` comentan **en la misma pasada** en que el destino llega a `Published`, si les queda presupuesto. Si no les queda, despachan `PostFirstComment`.

| Criterio | Valoración |
|---|---|
| Complejidad | Alta. Mete efectos del comentario en el núcleo de publicación (rompe «no tocar `PublishPostTarget`») |
| Riesgo | **Alto**. `published()`, `processing()` y `PublishPostTarget:115` sobrescriben o vacían `progress`, así que el estado del comentario se perdería y se duplicaría el comentario. Tampoco se puede consultar con índice para la recuperación |
| Coste relativo | S (migración) pero M (pruebas de regresión del núcleo) |
| Reversibilidad | Baja. Queda entrelazado con la máquina de estados de la publicación |

### Pieza C-04: descarga remota (URL y Drive)

**Común a las tres opciones: guardia SSRF `UrlGuard` (`app/Services/Media/UrlGuard.php`).** Se aplica en el endpoint y en **cada** pasada del job.

**Qué URLs admite.**

- Solo `http` y `https`, en los puertos 80 y 443.
- Sin `user:pass@`.
- El host se normaliza a IDN.

**Resolución DNS.**

- Se resuelven A y AAAA con `dns_get_record`. **Todas** las IPs deben ser públicas, no basta con la primera.
- Se rechazan los rangos privados, reservados y de *loopback* (`FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE`). Además se bloquean de forma explícita:
  - `0/8`, `100.64/10`, `169.254/16`, `192.0.0/24`, `198.18/15` y `224/4`–`240/4`;
  - `::1`, `fc00::/7` y `fe80::/10`;
  - la IPv4 incrustada en `::ffff:0:0/96`, `64:ff9b::/96` y `2002::/16`.
- También se rechazan los literales de IP en notación decimal u octal.

**Conexión fijada a la IP.**

- La conexión se fija a la IP validada con `CURLOPT_RESOLVE` (`host:puerto:ip`), contra el *DNS rebinding*.
- Se limitan los protocolos a HTTP y HTTPS (`CURLOPT_PROTOCOLS` y `CURLOPT_REDIR_PROTOCOLS`) y no se usa ningún proxy.

**Redirecciones.** Con `allow_redirects=false`, se siguen a mano hasta 5, y cada `Location` vuelve a pasar por `UrlGuard`.

**Tamaño.**

- El primer tramo lee `Content-Type` y `Content-Length`: el tipo debe estar en `media.allowed_mimes` y la longitud por debajo de `media.max_bytes`.
- Los primeros bytes se comprueban con `finfo` antes de seguir.
- Se cuentan los bytes reales y se corta en `max_bytes` aunque `Content-Length` mienta.
- `finalize()` vuelve a comprobar el tipo real.

**Endpoint.** Lleva `throttle` y un máximo de 3 importaciones vivas por usuario.

#### B1: un solo pipeline `RemoteMediaImporter` con fuentes intercambiables, por pasadas con `Range` y punto de control en disco (lo que dice la spec)

**Piezas.**

- **Interfaz `RemoteSource`** (`app/Services/Media/`), con dos implementaciones:
  - `UrlSource`, que pasa por `UrlGuard`;
  - `DriveFileSource`, con el host fijo `www.googleapis.com`, `files.get?alt=media` y `Authorization: Bearer`. El `fileId` se valida con `[A-Za-z0-9_-]+`.
- **Job `ImportRemoteMedia`** en la cola `media`.
  - Implementa `ShouldBeUnique` (`uniqueId = asset id`) y `ShouldBeEncrypted`, porque el token de Drive viaja en el payload.
  - Tiene `$timeout = 110`.

**Una pasada.**

1. Lee el **punto de control**. Como `MEDIA_DISK=local`, es el tamaño del fichero parcial en el disco, `media/{user}/{ulid}.part`. No hace falta un contador aparte, que podría desincronizarse.
2. Pide `Range: bytes={offset}-` con `If-Range` (el ETag o el `Last-Modified` de la primera pasada, guardados en `media_assets.import_state`).
3. Escribe en modo *append* con un *sink* en streaming.
4. Corta al agotar `import_budget_seconds` (80 s).
5. Se vuelve a despachar a sí mismo (`delay` de 1 s).

**Respuestas del servidor remoto.**

| Respuesta | Qué hace el job |
|---|---|
| `206` | Sigue |
| `200` en un tramo de reanudación | El fichero remoto cambió: se reinicia desde 0 una sola vez y, si vuelve a pasar, el asset queda `invalido` |
| Sin `Accept-Ranges` | Se intenta en una sola pasada. Si no termina en el presupuesto, el asset queda `invalido` con «El servidor no permite descargas por partes; descárgalo y súbelo» |

**Al terminar.** Se renombra `.part` al nombre final, `declared_size` pasa a los bytes reales y se llama a `finalize()`. Así se reutilizan tal cual `MediaProbe` y las comprobaciones de tamaño y tipo.

**Campos nuevos en `media_assets`.**

- `source`: `upload`, `url` o `drive`.
- `source_ref`: la URL sin la *query* sensible, o el `fileId`.
- `import_state` (JSON): bytes, total, validador, URL final y número de pasadas.

`toPublicArray()` expone `bytes` y `total` para la barra de progreso.

**Drive.**

- **En el navegador** (`useDrivePicker.js`), GIS `initTokenClient` pide `drive.file` y el Picker filtra imagen y vídeo.
- **En el endpoint:**
  1. Verifica el token con `tokeninfo`: `aud` debe ser `GOOGLE_CLIENT_ID` y debe incluir `drive.file`.
  2. Pide los metadatos con `files.get?fields=name,mimeType,size` y valida el tipo y el tamaño **antes** de crear el asset.
  3. Encola el job. El token no se guarda en `media_assets` y caduca en 1 h.
- **Si el token caduca** (`401` a mitad), el asset queda `invalido` con «Vuelve a elegir el fichero en Drive».

**MCP.** `ImportMediaFromUrl` llama al mismo servicio y devuelve el asset `pendiente`. `list_media` ya lista el estado.

**Hestia.** Una línea de cron nueva:

```text
flock -n ss-media.lock … queue:work database --queue=media --stop-when-empty --max-time=280 --timeout=120 --tries=3
```

| Criterio | Valoración |
|---|---|
| Complejidad | Alta pero contenida. El patrón de pasadas ya existe (subida de YouTube), y el punto de control en el disco local es trivial |
| Riesgo | Medio. SSRF (mitigado con fijar la IP y revisar cada salto) y servidores sin `Range` (error claro). Con R2, el *append* no sirve y haría falta *multipart* |
| Coste relativo | **M** (es la base de la evaluación: 40,5h) |
| Reversibilidad | Alta. Columnas aditivas, una cola nueva y la subida directa intacta |

#### B2: pipelines separados. URL en el servidor (como B1) y Drive en el navegador, que descarga y vuelve a subir

- La URL se trata como en B1.
- Para Drive, el navegador descarga con `fetch` (googleapis admite CORS) usando el token de GIS, y sube el `Blob` por el flujo de `MediaController::store/upload`, que ya existe. El token **nunca toca el servidor**.

| Criterio | Valoración |
|---|---|
| Complejidad | Media. Drive no necesita `DriveFileSource` ni tramos en el servidor |
| Riesgo | **Alto en vídeo.** Con `MEDIA_DISK=local`, la subida va por PHP-FPM y Nginx la corta a **512 MB** (`nginx-overrides.conf:15`): un vídeo de Drive de 1–4 GB no entra. Además, la pestaña tiene que seguir abierta y el usuario paga el ancho de banda dos veces |
| Coste relativo | **S** para Drive, M en total (dos caminos que mantener) |
| Reversibilidad | Alta |

#### B3: un solo pipeline con un worker `media` de vida larga, sin tramos

Una conexión de cola nueva, `database-media`, con `retry_after` de 3.700 s. El worker es `--queue=media --timeout=3600` en su propia línea de cron con `flock`. `ImportRemoteMedia` descarga el fichero entero en una sola ejecución. La reanudación con `Range` solo se usa si la ejecución se cae.

| Criterio | Valoración |
|---|---|
| Complejidad | Media. Sin bucle de pasadas, y los servidores sin `Range` funcionan |
| Riesgo | Medio-Alto. Contradice la decisión de la spec (presupuesto de 80 s por pasada) y la regla del despliegue `--timeout` < `retry_after` de una conexión compartida. Un `deploy.sh` (`queue:restart`) a mitad de descarga la corta. Un job colgado bloquea la cola una hora por `flock` |
| Coste relativo | **S-M** |
| Reversibilidad | Media. Una conexión de cola y una política de worker propias que habría que documentar y mantener aparte |

## 3. Criterios de decisión

1. **No duplicar efectos externos ni degradar lo ya publicado** (C-01b), por encima del coste. Un comentario repetido en la red no se deshace solo.
2. **Seguridad del servidor** (C-04): ninguna opción puede abrir SSRF. Fijar la IP y validar cada salto no es negociable.
3. **Encajar en la operación actual de Hestia**: workers de cron a 120 s, `retry_after` de 180 s y `MEDIA_DISK=local`. Pesa más que la simplicidad del código.
4. **Respetar las decisiones vigentes** de la spec, ADR-001 y ADR-005: no tocar `PublishPostTarget` ni reabrir la máquina de estados de la publicación.
5. **Reversibilidad > ampliabilidad futura.** Ni los varios comentarios por destino ni R2 están en el alcance.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto, C-01b: A1, columnas en `post_targets` y job con reclamo propio.**

- Cumple el criterio 1 con el mismo patrón probado de la publicación: reclamo, consulta antes de actuar y recuperación.
- No toca el núcleo (criterio 4).
- Es lo que ya fija la spec y es lo más reversible.
- A2 solo compensa si se quieren varios comentarios por destino, y eso está fuera de alcance.

**Recomendación del arquitecto, C-04: B1, un solo pipeline por pasadas con el punto de control en disco.**

- Es la única opción que importa vídeos de 4 GB de Drive y de una URL con los workers de 120 s (criterio 3), sin conexiones de cola especiales.
- Comparte `UrlGuard` y `finalize()` entre las dos fuentes.
- El disco local convierte el punto de control en el tamaño del `.part`.

**C-01b: A1, columnas en `post_targets` + job `PostFirstComment` con reclamo propio.** Validada por el usuario el 2026-10-24.

- **Criterio 1.** La idempotencia en tres capas (id guardado, `findOwnComment()` antes de `comment()`, `ShouldBeUnique` + `lockForUpdate`) y la recuperación en `PublishRecovery` cierran la ventana de duplicado sin degradar el destino.
- **Criterio 4.** El único punto de enganche es `PublishOutcomes::published()`, así que `PublishPostTarget` no cambia.
- **Criterio 5.** Las columnas nulas y aditivas se quitan sin migrar datos.
- **Decisión adicional del usuario (2026-10-24): el texto de un primer comentario en `fallido` se puede editar antes de Reintentar.**
  - Es la única edición admitida en un destino `Published`: no pasa por `lockEditableTargets()`.
  - Va en `FirstCommentService::update()`, con la ruta `PATCH posts/{post}/targets/{target}/first-comment`. Solo actúa si el comentario está en `fallido`, valida con el máximo de `media_specs` y no toca `text` ni el intento de publicación.
  - «Reintentar» envía el texto vigente.
  - En el MCP, `update_post` sobre un post publicado rechaza `first_comment` igual que hoy. Editar el comentario fallido por MCP queda fuera, salvo que se pida (Preguntas abiertas).

**C-04: B1, un solo pipeline URL/Drive por pasadas de 80 s con `Range`, punto de control en disco y `UrlGuard`.** Validada por el usuario el 2026-10-24.

- **Criterio 3.** Es la única opción que importa vídeos de 4 GB con los workers de 120 s y la `retry_after` actual.
- **Criterio 2.** `UrlGuard` fija la IP validada en cada pasada y en cada salto.
- **Criterio 5.** La subida directa queda intacta y todo lo nuevo es aditivo.

Descartadas:

- **A3**: el estado en `progress` se pierde y obliga a tocar el núcleo de publicación.
- **B2**: el tope de 512 MB de la subida local deja fuera los vídeos de Drive.
- **B3**: contradice la regla del despliegue y la decisión de la spec. Un `queue:restart` corta las descargas.
- **A2**: queda como alternativa válida si el usuario quiere varios comentarios por destino más adelante.

## 5. Impacto en módulos y ficheros

Impacto con las opciones recomendadas, A1 y B1:

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `database/migrations/*_add_first_comment_to_post_targets.php` | 6 columnas nulas en `post_targets` + índice `(first_comment_status, first_comment_at)` | Nuevo |
| `app/Models/PostTarget.php` | `fillable`/`casts` del comentario; enum `App\Enums\FirstCommentStatus` | Modificado / nuevo |
| `app/Connectors/Contracts/FirstCommenter.php`, `app/Connectors/ConnectorRegistry.php` | Interfaz y `commenterFor()` | Nuevo / modificado |
| `app/Connectors/Meta/Comments.php`, `Meta/Instagram/InstagramPublisher.php`, `Meta/Facebook/FacebookPublisher.php`, `Google/YouTubePublisher.php` | `comment()` y `findOwnComment()`; en YouTube `commentThreads.insert/list` y sus 50 unidades en `YouTubeQuota` | Modificado |
| `app/Jobs/PostFirstComment.php` | Job único, con reclamo propio, búsqueda antes de comentar y backoff | Nuevo |
| `app/Services/PublishOutcomes.php` | En `published()`: marca `pendiente` y despacha con `afterCommit` | Modificado |
| `app/Services/PublishRecovery.php` | Paso 6: comentarios colgados o perdidos | Modificado |
| `app/Services/FirstCommentService.php`, `routes/web.php`, `app/Notifications/FirstCommentFailed.php` | Editar el comentario `fallido` (`PATCH …/first-comment`), Reintentar y aviso in-app | Nuevo / modificado |
| `app/Http/Requests/PostRequest.php`, `app/Services/PostService.php` (`plan()`, `fill()`) | Regla y máximo por tipo; `fill()` no reinicia el intento de publicación | Modificado |
| `config/media_specs.php`, `config/social.php`, `config/publishing.php` | `first_comment.max`; `youtube.force-ssl`; `comment_queue` y backoff | Modificado |
| `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Tools/GetPost.php` | `targets.*.first_comment` en el esquema; estado en la salida | Modificado |
| `resources/js/Components/Composer/{NetworkTabs,TargetOptions,TargetStatusPanel}.vue` | Campo, contador, estado y Reintentar | Modificado |
| `database/migrations/*_add_source_to_media_assets.php`, `app/Models/MediaAsset.php` | `source`, `source_ref` e `import_state`; progreso en `toPublicArray()` | Nuevo / modificado |
| `app/Services/Media/{UrlGuard,RemoteSource,UrlSource,DriveFileSource,RemoteMediaImporter}.php` | Guardia SSRF, fuentes y pasadas | Nuevo |
| `app/Jobs/ImportRemoteMedia.php` | Cola `media`, único y cifrado; se reencola por pasadas | Nuevo |
| `app/Services/MediaUploadService.php` | Se reutiliza `finalize()`; solo se fija `declared_size` antes de llamarlo | Modificado (mínimo) |
| `app/Http/Controllers/MediaController.php`, `routes/web.php` | `importUrl` e `importDrive` (con `tokeninfo` y metadatos) y `throttle` | Modificado |
| `app/Mcp/Tools/ImportMediaFromUrl.php`, `app/Mcp/Servers/SchedulerServer.php` | Herramienta nueva | Nuevo / modificado |
| `config/media.php`, `config/queue.php` (`names`), `config/services.php` | `import_budget_seconds`, `import_queue`, cola `media` en `QUEUE_NAMES` y claves del Picker | Modificado |
| `resources/js/Components/Composer/MediaSection.vue`, `resources/js/Composables/useDrivePicker.js` | «Desde URL», «Google Drive» y sondeo | Modificado / nuevo |
| `docs/modulos/despliegue.md`, `deploy/hestia/README.md` | Cuarta línea de cron (`ss-media`) y configuración de Google Cloud | Modificado |

**Notas de impacto en las otras piezas (sin opciones):**

- **C-01a (textos guardados).** El campo del primer comentario de A1 es un `textarea` más del destino. `SavedTextsMenu` tiene que poder insertar en el cursor de ese campo. Conviene registrar «campo activo» en `useComposer.js`, no por componente.
- **C-02 (cuadrícula).** No le afecta. Que `InstagramGrid` reutilice `GraphClient` es independiente de `FirstCommenter`.
- **C-03 (campañas).** Comparte `PostRequest`, `PostService::plan()`/`fill()`, `PostPayload` y `GetPost` con A1. Hay que hacerlas en serie, como ya dice la evaluación. A1 solo añade campos **por destino** y C-03 añade un campo **por post**, así que no chocan en el esquema.
- **Biblioteca y autolistas.** Con A1, `first_comment` es un atributo más del destino. Si se quiere que la biblioteca lo conserve, entra en el mapeo de `LibraryService.php:33`. Es una pregunta abierta, no está en la spec.

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Comentario duplicado tras un timeout entre la respuesta de la red y guardar el id | Baja | Alto | `findOwnComment()` siempre antes de `comment()`; backoff de 60 s para que la lista de Meta se ponga al día; `ShouldBeUnique` + reclamo con `lockForUpdate` |
| La lista de comentarios de Meta no muestra aún el comentario recién creado | Media | Medio | Primer reintento a 60 s como mínimo; prueba con `Http::fake` del caso «creado pero no listado» |
| YouTube: el vídeo llega a `Published` antes de admitir comentarios, o es privado o para niños | Media | Bajo | El despacho solo sale de `published()` (después de `PollPublishStatus`); `no_admitido` sin aviso de error; la UI avisa al escribir |
| Al reconectar YouTube con `force-ssl` se invalidan los tokens actuales | Alta (es seguro) | Medio | D2 aceptada. Hay que documentar el paso. Sin el scope, el control se desactiva con «Reconecta el canal para comentar» (`Comments::hasScopes`-like) |
| SSRF por *DNS rebinding* o por una redirección a la red interna | Media | Alto | `CURLOPT_RESOLVE` a la IP validada en cada pasada y en cada salto; tabla de IPs en Pest (CA-15); revisión de seguridad propia |
| Servidor remoto sin `Range` o fichero remoto que cambia entre pasadas | Media | Medio | `If-Range` + validador; un solo reinicio y después `invalido` con un mensaje en español |
| Token de Drive caducado (1 h) si la cola `media` tarda en empezar | Baja | Bajo | Worker cada minuto; un vídeo de 4 GB tarda unos 6 min a 100 Mbit/s; en un `401`, `invalido` con «Vuelve a elegir el fichero en Drive» |
| El token de Drive persiste cifrado en `failed_jobs` | Baja | Bajo | `ShouldBeEncrypted`; caduca en 1 h; `queue:prune-failed` ya corre a 720 h. Se puede no registrar el payload en `failed()` |
| Disco local lleno por importaciones a medias | Baja | Medio | `app:media-prune` ya borra los `pendiente` de más de 24 h; incluir los `.part` huérfanos |
| B1 asume `MEDIA_DISK=local`: con R2, el *append* no sirve | Baja (decisión del 2026-10-23) | Medio | Afirmarlo en el importador (error claro si `isS3()`). La variante R2 (*multipart* por pasada) queda fuera de alcance |
| C-01b y C-03 tocan los mismos ficheros del post | Alta | Bajo | Hacerlas en serie (orden de la evaluación) |

## 7. Preguntas abiertas

- ~~¿A1 + B1 o una variante?~~ **Cerrada el 2026-10-24:** A1 y B1.
- ~~¿Se puede editar el primer comentario `fallido` antes de Reintentar?~~ **Cerrada el 2026-10-24: sí** (ver §4). Queda abierto si también se edita desde el MCP; la propuesta es que no en esta iteración.
- ¿La biblioteca (`LibraryService`) y las autolistas deben conservar el primer comentario de cada destino?
- Puertos admitidos en una URL: ¿solo 80 y 443 (propuesta) o también 8080 y 8443?
- ⚠️ Pendiente de verificar en la documentación de Meta antes del plan: en los Reels de Facebook, el comentario va sobre el id del vídeo. Y el IG de un post con los comentarios desactivados devuelve un error concreto que hay que mapear a `no_admitido`.

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-24 | Diseño creado (`borrador`). Opciones A1–A3 (C-01b) y B1–B3 (C-04) presentadas al usuario; recomendadas A1 y B1 |
| 2026-10-24 | `aprobado`: el usuario elige A1 (C-01b) y B1 (C-04), y permite editar el primer comentario `fallido` antes de reintentar. Dos candidatos de decisión propuestos vía `knowledge-curation.py` (pending, para el Curator) |
