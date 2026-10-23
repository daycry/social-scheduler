---
spec: post-composer
descripcion: Rediseño del compositor de publicaciones de Social Scheduler para acercarlo al de Metricool, con identidad propia. Incluye editor a pantalla completa, texto por red, presets persistentes, primer comentario, panel de errores y barra de acciones, notas internas y mejores horas.
estado: aprobada          # borrador | aprobada | implementada | obsoleta
creado: 2026-10-23
actualizado: 2026-10-23
evaluacion: evaluation.md
design: n/a               # se decidirá tras elegir el alcance; C-03 y C-06 justifican pasar por architect
plan: pendiente
generacion:
  inicio: 2026-10-23T10:43:57Z
  fin: 2026-10-23T10:56:46Z
  fuente: estimado        # la redacción de esta spec está dentro de la ventana medida de evaluation.md (un solo marcador, sin solapes); ver su bloque generacion
  tokens_reales: { entrada: 0, salida: 0, cache_creacion: 0, cache_lectura: 0 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.00          # contabilizadas en evaluation.md
  duracion: 0m            # contabilizada en evaluation.md
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Nuevo compositor de publicaciones

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Plan de implementación:** pendiente (handoff a `planner` cuando el usuario elija el alcance)

> **Terminología:**
>
> - **Destino** (`PostTarget`): una cuenta más un tipo de contenido. Es la unidad que se publica, se reintenta y se consulta.
> - **Plantilla**: el texto común del post (`posts.text`). Un destino **vinculado** publica la plantilla. Uno **desvinculado** publica su propio texto.
> - **Texto efectivo** de un destino: su texto propio si está desvinculado; si no, la plantilla. Es lo que se valida, se previsualiza, se publica y se compara en la idempotencia.
> - **Preset**: una opción que el compositor aplica por defecto. Es **global** si vale para todo el post (acortador, por ejemplo) y **por red** si es propia de una red (privacidad en YouTube, por ejemplo).
> - **Interruptor persistente**: un preset booleano que recuerda su última posición por marca y red, como en Metricool (fuentes públicas §1.4). Los campos de texto no persisten.

## Contexto y objetivo

El compositor actual (`resources/js/Pages/Posts/Compose.vue`, 321 líneas) es una página con tarjetas:

- la lista de cuentas con casilla y un `<select>` de tipo;
- un `textarea` con contador total;
- la media;
- un `datetime-local`;
- los botones «Programar» y «Guardar borrador»;
- una previsualización genérica.

La validación en vivo ya existe (`brands.posts.validate` → `PostService::validate` → `MediaValidator` con `config/media_specs.php`). Los errores se muestran en cada cuenta.

El objetivo es acercarlo al compositor de Metricool, con identidad propia: sin copiar textos, iconos ni colores. Las fuentes son la observación de la interfaz ([`metricool-analisis-ui.md`](../../referencia/metricool-analisis-ui.md), tabla final) y el centro de ayuda ([`metricool-analisis-fuentes-publicas.md`](../../referencia/metricool-analisis-fuentes-publicas.md) §1.2–§1.8 y §5).

**Contexto de uso:** uso personal, un solo usuario y varias marcas. Hay 4 redes conectables: Instagram, Facebook, YouTube y TikTok (`app/Connectors/Network.php`). No hay equipos, roles ni planes. Por eso la aprobación, el envío a revisión y las funciones de pago de terceros no tienen sentido aquí.

Recon del repo:

- `PostController`, `PostRequest` y `PostService::plan()/fill()`;
- `PublishRequestFactory` y los publicadores de `app/Connectors/{Meta,Google,TikTok}`;
- `Meta/Comments.php`, `BestTimesService`, `LibraryService` y `app/Mcp/Tools/PostPayload.php`;
- `config/social.php` (scopes) y `config/media_specs.php`;
- los tests `tests/Feature/Posts/Compose/ComposeTest.php` y `tests/e2e/{flows,pages}.spec.ts`.

## Características propuestas (para que el usuario elija)

| ID | Característica | Resumen | Esfuerzo con margen |
|---|---|---|---|
| C-01 | Editor a pantalla completa y contadores por red | Ruta propia sin el layout de la app, cabecera con la marca y redes en iconos con el tipo en un desplegable, editor grande con barra de iconos (media, emoji, IA, contador de hashtags) y contador por red de caracteres y hashtags. Pide confirmación al salir con cambios | 33,6h |
| C-02 | Previsualización por red, móvil y escritorio | Maqueta propia de Instagram, Facebook, YouTube y TikTok por tipo, conmutador móvil/escritorio y aviso de «aproximada» | 24h |
| C-03 | Texto por red («Edit by network») | Plantilla común más texto propio por destino, con desvincular y volver a vincular. Afecta a la validación, la publicación, la idempotencia, la biblioteca y el MCP | 38,4h |
| C-04 | Presets por red e interruptores persistentes | Opciones reales de cada API: YouTube (categoría, tags, «hecho para niños», notificar a suscriptores, etiqueta IA), TikTok (comentarios, Duet, Stitch, divulgación comercial, etiqueta IA y publicación directa) e Instagram (compartir en el feed). Los interruptores recuerdan su posición por marca y red | 32,4h |
| C-05 | Generador UTM y acortador de URL | Modal UTM, solo en el cliente. Acortador propio opcional, aplicado al publicar | 22,8h (UTM solo: 4,8h) |
| C-06 | Primer comentario | Un comentario por destino que se publica justo después del post en Instagram, Facebook y YouTube. TikTok no lo permite por API | 33,6h |
| C-07 | Ubicación y colaboradores de Instagram | Tras un *spike* de 4h que decide si la API lo permite con nuestros permisos | 24h |
| C-08 | Panel único de errores y barra de acciones | Errores agregados con el icono de la red, selector de fecha propio con la zona de la marca y botón dividido: Programar (por defecto), Guardar borrador, Guardar en biblioteca y **Publicar ahora** | 32,4h |
| C-09 | Notas internas del post | Notas que no se publican, editables incluso con el post ya publicado, con indicador en el calendario y visibles por MCP | 12h |
| C-10 | Mejores horas en el calendario y el selector de fecha | Sombreado de la vista semanal por red y sugerencia de las próximas mejores franjas al elegir la hora | 19,2h |
| C-11 | Cierre | E2E del flujo completo, axe, regresión del MCP, documentación y smoke real en las 4 redes | 14,4h |

Coste y riesgo por característica en [`evaluation.md`](evaluation.md). **MVP recomendado:** C-01 + C-08 + C-03 + C-04 + C-11.

## Decisiones de diseño

> **Decisiones del usuario (2026-10-23):** alcance = **MVP + quick wins** (C-01, C-08, C-03, C-04, C-11, C-09 notas y C-05a UTM); compositor como **página a pantalla completa**; texto por red como **un post con N destinos**; **aplazados** C-07 (ubicación y colaboradores de Instagram), C-05b (acortador propio), primer comentario en YouTube y toda la segunda ola (C-10 mejores horas, C-02 previsualización por red, C-06 primer comentario). Como C-06 queda fuera, «qué se puede editar por red» se limita al **texto**.

| Decisión | Elección | Motivo |
|---|---|---|
| Forma del compositor (**decidido por el usuario 2026-10-23**) | **Página propia a pantalla completa** (`brands.posts.create` y `posts.edit`), con un layout mínimo `ComposerLayout` (sin la barra de navegación de la app). No es un modal sobre el calendario. | Conserva los enlaces directos, el botón Atrás, el `afterSave` actual y los E2E existentes (`/brands/{id}/posts/create`). Un modal Inertia sobre el calendario obligaría a rehacer la navegación y el estado. |
| Modelo de «Edit by network» (**decidido por el usuario 2026-10-23**) | **Un `Post` con N destinos.** Cada `PostTarget` tiene un `text` propio nullable: `null` significa vinculado a la plantilla. Al guardar **no** se crea un post por red, a diferencia de Metricool. | Encaja con el modelo actual: el calendario sigue mostrando una tarjeta y la papelera, la biblioteca y el MCP siguen operando sobre un post. Lo propone también la idea 3 de fuentes públicas §5. |
| Qué se puede editar por red | **Texto y primer comentario.** La media por red queda fuera. | La media por red multiplica la validación y la UI. Hoy la media es del post (`post_media`) y los tipos ya filtran lo que admite cada red. |
| Persistencia de presets | **Interruptores por (marca, red)** en una tabla `composer_presets` (o JSON en `brands`; lo decide el diseño). Se escriben al guardar un post y se aplican como valor inicial al añadir esa red. Los campos de texto (título, tags, enlace) **no** persisten. | Es la regla de Metricool (fuentes §1.4). Con un solo usuario, la clave natural es la marca. |
| «Auto publish» | **Solo TikTok**: es el `direct_post` por cuenta que ya existe (`brands.connections.direct-post`), expuesto como interruptor en el compositor. Instagram y Facebook publican siempre solos. | No tenemos un flujo de «notificación para publicar a mano» en Instagram, y no se propone crearlo. |
| Contadores | **Por red y por tipo**, con los máximos de `config/media_specs.php`, que el controlador expone a la vista. Se cuenta igual que el servidor: **puntos de código** (`mb_strlen` en PHP y `[...text].length` en JS), no unidades UTF-16. Hashtags: tope 30 en Instagram (fuentes §1.6), que se valida también en el servidor. | Así el contador y el error del servidor no discrepan con los emojis, a diferencia de lo que avisa Metricool (fuentes §1.6). |
| Acciones del botón principal | **Programar** (por defecto), **Guardar borrador**, **Guardar en biblioteca** (también con el post sin guardar, vía `LibraryService::store`) y **Publicar ahora**. **Enviar a revisión** y **recurrente** quedan **fuera**. | Un solo usuario no necesita aprobación. La recurrencia es otra iniciativa (fuentes §5, idea 13). |
| Publicar ahora | Fecha igual a ahora, la misma validación de contenido (sin exigir una fecha futura) y **despacho inmediato** del job de cada destino, sin esperar al `dispatch-due-posts` de cada minuto. | Mismo camino de publicación e idempotencia que una programada. |
| Primer comentario: cuándo y cómo | **Paso aparte tras `Published`**: un job `PostFirstComment` por destino, con su propio estado e intentos. **Un fallo del comentario no marca el post como fallido**: se avisa y se puede reintentar. Antes de comentar se comprueba si ya existe un comentario propio con ese texto (idempotencia). | Fuentes §5, idea 6. El post ya está en la red: degradarlo a «error» por el comentario sería falso. |
| Acortador | **Opcional (C-05b).** Si se hace: dominio de la app, tabla `short_links`, ruta pública de redirección con límite de peticiones y acortado **al publicar**, de forma determinista por destino y URL (un reintento genera el mismo enlace). | La vista previa conserva la URL real (fuentes §1.4). El determinismo es necesario porque la idempotencia de Instagram y Facebook compara el texto publicado (`InstagramPublisher.php:395-408`). |
| Notas internas | **Texto plano con saltos de línea** (sin editor enriquecido), hasta 5.000 caracteres, en `posts.notes`. Se guardan con el post y también con un endpoint propio, que permite editarlas cuando el post ya no es editable. | Un editor enriquecido añade una dependencia y coste. El usuario lee las notas, no las publica. |
| Emoji | **Selector propio con una lista curada por categorías y buscador** (sin dependencia), más un aviso de que el sistema ofrece el suyo (Win + .). | Evita una dependencia pesada para un uso secundario. |
| Identidad propia | Paleta, iconos y textos propios (Tailwind del proyecto, `NetworkIcon` y `Icon`). No se copian textos, iconos ni maquetación literal de Metricool. | Requisito del usuario. |
| Paridad con el MCP | Todo campo nuevo del post (texto por destino, primer comentario, opciones nuevas y notas) entra en `PostPayload` y en `get_post`, con las mismas reglas que la web (`PostRequest`). | Ya es así hoy (`PostPayload::validatedPayload` reutiliza `PostRequest::rules()`). |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| Máximo de texto por tipo | `config/media_specs.php` `*.text.max` | IG 2.200 · FB 63.206 · YT 5.000 · TikTok 2.200/4.000 | Sin cambios, expuesto a la vista |
| Máximo de hashtags | `config/media_specs.php` `*.text.max_hashtags` (nuevo) | — | **IG 30**. El resto sin tope, salvo lo que verifique el plan (⚠️ YouTube ignora los hashtags si hay más de 60) |
| Máximo del primer comentario | `config/media_specs.php` `*.first_comment.max` (nuevo) | — | **IG 2.200 · FB 8.000 · YT 10.000** (fuentes §1.6). TikTok: no admitido |
| Scope de YouTube para comentar | `config/social.php` `google.scopes` | `youtube`, `youtube.readonly`, `yt-analytics.readonly` | ⚠️ `youtube.force-ssl` si `commentThreads.insert` lo exige (ver la evaluación de C-06). Obliga a reconectar |
| Notas | `posts.notes` | — | `text`, nullable, máx. 5.000 |
| Mejores horas | `social.best_times.min_posts` | 10 | Sin cambios: por debajo, «sin datos suficientes» |

## Arquitectura y componentes

**Se reutiliza:**

- `PostService` (crear, actualizar, validar, programar y transicionar), `MediaValidator` y `media_specs`;
- `PublishPostTarget`, `PublishOutcomes`, `PublishRequestFactory` y los publicadores;
- `Meta/Comments.php` (ya publica respuestas con `instagram_manage_comments` y `pages_manage_engagement`);
- `BestTimesService::heatmap`, `LibraryService::store`, `AiSuggest`, `MediaUploader`, `NetworkIcon` y `StatusBadge`.

**Nuevo o modificado:**

| Capa | Pieza | C-XX |
|---|---|---|
| Vue | `Layouts/ComposerLayout.vue`. `Pages/Posts/Compose.vue` se divide en `Components/Composer/*`: `ComposerHeader`, `NetworkPicker`, `EditorToolbar`, `TextEditor`, `NetworkCounters`, `EmojiPicker`, `UnsavedGuard` | C-01 |
| Vue | `Components/Composer/Preview/*`: una maqueta por red, con un conmutador de dispositivo | C-02 |
| Vue | `NetworkTabs` (Plantilla, una pestaña por destino, desvincular y vincular) | C-03 |
| Vue | `PresetsPanel` (global y por red) | C-04 |
| Vue | `UtmModal` | C-05 |
| Vue | `FirstCommentModal` | C-06 |
| Vue | `ErrorsPanel`, `ComposerFooter` (fecha y botón dividido) y `DateTimePicker` (zona de la marca, sin días pasados, sugerencias de mejores horas) | C-08 y C-10 |
| Vue | `NotesPanel` | C-09 |
| Vue | Capa de mejores horas en `Calendar/Index.vue` | C-10 |
| BD | `post_targets.text` (nullable) | C-03 |
| BD | `post_targets.first_comment` (nullable), estado e intentos del comentario (`first_comment_status`, o en `progress`; lo decide el diseño) | C-06 |
| BD | `composer_presets` | C-04 |
| BD | `short_links` | C-05b |
| BD | `posts.notes` | C-09 |
| PHP | `PostRequest`: `targets.*.text`, `targets.*.first_comment`, opciones nuevas, `notes` y `action` (o `publish_now`) | C-03, C-04, C-06, C-08, C-09 |
| PHP | `PostService::plan()`: valida el texto efectivo por destino, los hashtags y el primer comentario. `fill()` detecta los cambios por destino (texto propio) y reinicia ese intento | C-01, C-03, C-06 |
| PHP | `PublishRequestFactory::make()`: usa el texto efectivo del destino; con acortador, el texto acortado de forma determinista | C-03, C-05b |
| PHP | `YouTubePublisher::openSession()`: categoría, tags del usuario **más** la etiqueta interna de idempotencia, `selfDeclaredMadeForKids`, `notifySubscribers` y etiqueta IA (⚠️). `TikTokPublisher::postInfo()`: `disable_duet`, `disable_stitch`, `brand_content_toggle`, `brand_organic_toggle` y etiqueta IA (⚠️) | C-04 |
| PHP | Job `PostFirstComment` y un método `comment()` por publicador (IG y FB con `GraphClient`, YT con `commentThreads.insert`) | C-06 |
| PHP | `InstagramPublisher::containerParams()`: `collaborators` y `location_id` (⚠️ según el spike) | C-07 |
| PHP | Publicar ahora en `PostService` (o `schedule` con `now`) y despacho inmediato de `PublishPostTarget` | C-08 |
| PHP | `PATCH posts/{post}/notes` | C-09 |
| PHP | Mejores horas en las props de `CalendarController` (por red elegida) y un endpoint JSON con las próximas franjas | C-10 |
| MCP | `PostPayload` (esquema), `GetPost` (salida) | Todas las que añaden campos |

## Flujo (paso a paso)

1. Desde el calendario, la marca o una franja del calendario (C-10), se abre el compositor a pantalla completa con la marca en la cabecera.
2. El usuario activa redes pulsando sus iconos. Junto a cada una elige el tipo, y se aplican los presets persistentes de esa marca y red (C-04).
3. Escribe la plantilla. Los contadores por red se actualizan en vivo, y la validación en vivo (debounce de 500 ms, como hoy) alimenta el panel de errores (C-08).
4. Si quiere un texto distinto en una red, la desvincula en su pestaña, edita su texto y puede volver a vincularla. Al volver a vincular, pierde el texto propio tras confirmarlo (C-03).
5. Opcionalmente: primer comentario (C-06), UTM (C-05), colaboradores o ubicación (C-07), notas (C-09).
6. Elige la fecha en el selector, con la zona de la marca y sugerencias de mejores horas (C-10), y pulsa la acción del botón dividido (C-08). Programar y Publicar ahora están deshabilitados mientras haya errores.
7. Al guardar se aplica el mismo `afterSave` que hoy (calendario o compositor). Al publicarse un destino, si tiene primer comentario, se encola `PostFirstComment`.

## Alcance

- **Dentro (según lo que elija el usuario de C-01…C-11):**
  - las 4 redes actuales y sus tipos (`ContentType`);
  - la web y el MCP, con paridad de campos;
  - la edición de posts existentes con las mismas reglas de «editable» que hoy (`postProps.editable`), salvo las notas.
- **Fuera (siguientes specs o descartado):**
  - integraciones de terceros: Canva, Adobe Express, Google Drive, stock de imágenes y vídeos, GIFs;
  - buscador de hashtags populares (es de pago en Metricool y no hay API pública equivalente);
  - textos guardados (fuentes §5, idea 10; candidata a una spec pequeña aparte);
  - envío a revisión y aprobación (un solo usuario);
  - publicación recurrente y duplicar en otras marcas;
  - media distinta por red, alt text y editor de imagen o vídeo;
  - SmartLinks y Campaign Dashboards como presets;
  - etiquetar personas y productos de Instagram;
  - primer comentario en TikTok (la API no lo permite) y en Stories;
  - notificación para publicar a mano (el «Auto publish» desactivado de Instagram);
  - vista de *feed* de Instagram (cuadrícula);
  - redes nuevas (X, Threads, LinkedIn, Pinterest, Bluesky, Google Business).

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| Texto efectivo de un destino por encima del máximo del tipo | Error en el panel con el icono de la red y la cuenta («Instagram · cuenta: 2.340/2.200 caracteres»). Programar y Publicar ahora quedan deshabilitados. Borrador y biblioteca siguen permitidos |
| Más de 30 hashtags en Instagram | Error igual, en el cliente y en el servidor |
| Primer comentario en una red o tipo que no lo admite (TikTok, Story) | El control no se ofrece. Si llega por MCP, error de validación del destino |
| Falla el primer comentario tras publicar | El destino sigue «Publicada». Se marca «comentario fallido» con el motivo, llega un aviso in-app y se ofrece reintentar. Nunca se repite un comentario ya puesto |
| Falta el scope de YouTube para comentar | El control avisa «Reconecta YouTube para poder comentar». Si llega por MCP, error de validación |
| Una opción por red no está disponible para la cuenta (TikTok en modo borrador, privacidad no ofrecida por `creator_info`) | Se oculta o deshabilita con la explicación. El publicador mantiene su regla actual: nunca abre más de lo pedido (`TikTokPublisher.php:468-473`) |
| Salir con cambios sin guardar | Confirmación propia (y `beforeunload` en el navegador) |
| Desvincular y volver a vincular un destino con texto propio | Confirmación: «Se perderá el texto propio de esta red» |
| Publicar ahora con una cuenta que requiere reconexión | Error de validación, el mismo que al programar |
| Editar un post ya en vuelo o publicado | Igual que hoy: no editable, salvo las notas (C-09) |
| Mejores horas sin datos suficientes | Mensaje «Aún no hay datos suficientes (n/10 publicaciones)», sin sombreado ni sugerencias |

## Criterios de aceptación

Agrupados por característica. Solo aplican los de las características aprobadas.

**C-01: Editor y contadores**

- [ ] [GWT] CA-01 — Dado una marca con cuentas de Instagram y Facebook, Cuando abro «Nueva publicación», Entonces el compositor ocupa la pantalla completa (sin la navegación de la app), muestra la marca en la cabecera y una pastilla con icono por cuenta que se activa y desactiva con un clic y con el teclado.
- [ ] [GWT] CA-02 — Dado Instagram y Facebook activos, Cuando escribo un texto con 3 hashtags y un emoji, Entonces veo un contador por red («n / 2.200» y «3 / 30 #» en Instagram; «n / 63.206» en Facebook) y el recuento coincide con el del servidor (puntos de código).
- [ ] [GWT] CA-03 — Dado Instagram activo, Cuando el texto tiene 31 hashtags, Entonces aparece un error de Instagram en el panel y el servidor rechaza programar con el mismo mensaje.
- [ ] [GWT] CA-04 — Dado cambios sin guardar, Cuando pulso Cerrar, Entonces se pide confirmación; si cancelo, sigo en el compositor con los cambios.
- [ ] [GWT] CA-05 — Dado el editor, Cuando uso la barra de iconos, Entonces puedo insertar un emoji en la posición del cursor, abrir la IA (`AiSuggest`) y añadir media, todo operable con el teclado y con nombre accesible.

**C-02: Previsualización**

- [ ] [GWT] CA-06 — Dado Instagram (Reel) y YouTube (Short) activos, Cuando cambio la red en la previsualización, Entonces veo la maqueta propia de cada red con el **texto efectivo** de ese destino y la primera media, y un conmutador móvil/escritorio cambia el ancho de la maqueta.
- [ ] CA-07 — La previsualización muestra un aviso permanente de que es aproximada.

**C-03: Texto por red**

- [ ] [GWT] CA-08 — Dado Instagram y Facebook activos, Cuando desvinculo Facebook y escribo un texto propio, Entonces la previsualización y el contador de Facebook usan ese texto, los de Instagram la plantilla, y al guardar cada destino publica su texto efectivo.
- [ ] [GWT] CA-09 — Dado Facebook desvinculado, Cuando lo vuelvo a vincular y confirmo, Entonces su texto propio se borra y vuelve a usar la plantilla.
- [ ] CA-10 — Editar solo el texto propio de un destino reinicia el intento de **ese** destino (como hoy `fill()` con el contenido), pero no el de los demás. Lo cubre un test Pest.
- [ ] CA-11 — La idempotencia de Instagram y Facebook (`findPublished`, que compara la descripción) usa el texto efectivo del destino. Un test con reintento tras un timeout no duplica.
- [ ] CA-12 — `create_post`, `update_post` y `get_post` del MCP aceptan y devuelven el texto por destino con las mismas reglas que la web. Guardar en biblioteca y usar la plantilla conserva los textos propios.

**C-04: Presets por red**

- [ ] [GWT] CA-13 — Dado YouTube activo, Cuando marco «Hecho para niños: no», la categoría «Educación», tags y «No notificar a suscriptores» y programo, Entonces la petición de subida lleva esos valores y **conserva** la etiqueta interna de idempotencia en `tags`. Se comprueba con `Http::fake`.
- [ ] [GWT] CA-14 — Dado TikTok en publicación directa, Cuando desactivo Duet y Stitch y marco «Contenido de marca propia», Entonces `post_info` lleva `disable_duet`, `disable_stitch` y `brand_organic_toggle`. Con la cuenta en modo borrador, esas opciones no se ofrecen.
- [ ] [GWT] CA-15 — Dado que en la marca A guardé un post de YouTube con «No notificar a suscriptores», Cuando abro un post nuevo en la marca A y activo YouTube, Entonces ese interruptor aparece igual. En la marca B no cambia. Los campos de texto (título, tags) aparecen vacíos.
- [ ] CA-16 — La etiqueta de contenido IA se ofrece **solo** en las redes cuya API la acepte según el spike de C-04 (⚠️ ver evaluación). Si una red no la admite, no se muestra.

**C-05: UTM y acortador**

- [ ] [GWT] CA-17 — Dado el modal UTM, Cuando relleno la URL, `source`, `medium` y `campaign` y pulso Añadir, Entonces se inserta en el cursor la URL con los parámetros codificados. Sin los obligatorios, Añadir está deshabilitado.
- [ ] [GWT] CA-18 — (Solo C-05b) Dado el acortador activo, Cuando se publica un destino con `https://ejemplo.com/x`, Entonces la red recibe `https://<dominio>/s/<código>` y la previsualización muestra la URL original. Un reintento usa el mismo código, y el enlace redirige con 302 y cuenta el clic.

**C-06: Primer comentario**

- [ ] [GWT] CA-19 — Dado un post de Instagram con primer comentario, Cuando se publica, Entonces se crea un comentario de la propia cuenta con ese texto en la publicación. Si el job se repite, no se crea un segundo comentario. Se comprueba con `Http::fake`.
- [ ] [GWT] CA-20 — Dado que la red rechaza el comentario, Cuando termina el job, Entonces el destino sigue «Publicada», el compositor muestra «Comentario fallido: motivo» con Reintentar y llega un aviso in-app.
- [ ] CA-21 — El primer comentario respeta el máximo por red (IG 2.200, FB 8.000, YT 10.000) y no se ofrece en TikTok ni en Stories.

**C-07: Ubicación y colaboradores (condicionado al spike)**

- [ ] CA-22 — El informe del spike (`testing/spike-c07.md`) documenta, con la versión de Graph usada (`v23.0`), si `collaborators` y `location_id` funcionan con los permisos actuales y cómo se obtiene el id de ubicación.
- [ ] [GWT] CA-23 — Dado el spike en positivo y un Reel de Instagram con 2 colaboradores, Cuando se publica, Entonces el contenedor lleva `collaborators` con esos usuarios. Si superan el máximo de la API, hay error de validación.

**C-08: Errores y barra de acciones**

- [ ] [GWT] CA-24 — Dado errores en dos destinos y una fecha pasada, Cuando miro el panel de errores, Entonces veo una sola lista con el icono y la cuenta de cada error, Programar y Publicar ahora deshabilitados, y Guardar borrador habilitado.
- [ ] [GWT] CA-25 — Dado un post válido, Cuando elijo «Publicar ahora», Entonces cada destino pasa a «Publicando» sin esperar al siguiente minuto del planificador. Se comprueba con `Queue::fake`: el job se encola en la misma petición.
- [ ] [GWT] CA-26 — Dado un post nuevo sin guardar, Cuando elijo «Guardar en biblioteca» con un nombre, Entonces se crea la plantilla con el texto, la media y los destinos, sin crear un post.
- [ ] [GWT] CA-27 — Dado el selector de fecha, Cuando lo abro, Entonces muestra la zona de la marca, no deja elegir días pasados y guarda la hora local de la marca (el mismo formato que hoy, `Y-m-d\TH:i`).

**C-09: Notas**

- [ ] [GWT] CA-28 — Dado un post publicado (no editable), Cuando escribo una nota y la guardo, Entonces se guarda sin tocar los destinos. El calendario muestra un indicador de nota en la tarjeta y `get_post` del MCP la devuelve.

**C-10: Mejores horas**

- [ ] [GWT] CA-29 — Dado una marca con al menos 10 publicaciones de Instagram con métricas, Cuando elijo Instagram en la capa «Mejores horas» de la vista semanal, Entonces las franjas se sombrean según su engagement medio en la zona de la marca, con una leyenda y el valor accesible para lectores de pantalla.
- [ ] [GWT] CA-30 — Dado el selector de fecha con redes activas que tienen datos, Cuando lo abro, Entonces veo hasta 3 próximas franjas sugeridas (futuras) y pulsar una fija la fecha.
- [ ] CA-31 — Sin datos suficientes se muestra el motivo y no hay sombreado ni sugerencias.

**C-11: Cierre (transversal)**

- [ ] CA-32 — La suite Pest y los E2E existentes siguen en verde, con `flows.spec.ts` adaptado al nuevo compositor. axe no da violaciones serias ni críticas en el compositor ni en el calendario.
- [ ] CA-33 — `docs/usuario/guia-de-usuario.md`, `docs/modulos/publicacion.md` y `docs/modulos/mcp.md` describen lo nuevo. El smoke real (una publicación por red con las opciones aprobadas) queda registrado en `testing/`.

## Pruebas

- **Pest (Feature):**
  - `PostService` con el texto efectivo, los hashtags, el primer comentario, publicar ahora, las notas y los presets;
  - los publicadores con `Http::fake` (las opciones de YouTube y TikTok, el comentario en IG, FB y YT, y `collaborators`);
  - el job `PostFirstComment` (idempotencia y fallo que no degrada el destino);
  - los presets persistentes;
  - el MCP (`PostPayload` y `GetPost`).
- **Pest (Unit):** el contador de hashtags y de puntos de código en el servidor; la generación de UTM si se comparte; el código del acortador.
- **Playwright:**
  - un bloque por cada criterio `[GWT]` aprobado;
  - `pages.spec.ts` con axe en el compositor y el calendario;
  - `flows.spec.ts` adaptado.
- **Manual:** smoke en las 4 redes reales con las opciones aprobadas (C-11) y el spike de C-07.

## Referencias

- `docs/referencia/metricool-analisis-ui.md`: el compositor observado y la tabla de comparación (líneas 7-61 y 81-96).
- `docs/referencia/metricool-analisis-fuentes-publicas.md`: §1.3 Edit by network, §1.4 presets, §1.5 opciones por red, §1.6 límites, §1.7 acciones, §1.8 mejores horas, §5 ideas 1-7, 9, 11 y 16.
- `resources/js/Pages/Posts/Compose.vue` (estado actual), `app/Http/Controllers/PostController.php` y `app/Http/Requests/PostRequest.php`.
- `app/Services/PostService.php`: `plan()` en 294-394 y `fill()` en 396-458, que reinicia el intento al cambiar el contenido.
- `app/Services/PublishRequestFactory.php:34-40`: hoy usa `$post->text` para todos los destinos.
- `app/Connectors/Meta/Instagram/InstagramPublisher.php:309-320` (parámetros del contenedor) y `:395-408` (idempotencia por descripción).
- `app/Connectors/Google/YouTubePublisher.php:263-277`: categoría fija, `tags` con el marcador del destino y `selfDeclaredMadeForKids=false`.
- `app/Connectors/TikTok/TikTokPublisher.php:464-480`: `postInfo` con solo `privacy_level` y `disable_comment`.
- `app/Connectors/Meta/Comments.php:21-23` (scopes de comentarios) y `config/social.php:34-64` (scopes pedidos).
- `app/Services/BestTimesService.php` (mapa 7×24 y `min_posts`) y `app/Services/LibraryService.php::store`.
- `app/Mcp/Tools/PostPayload.php`: el MCP reutiliza `PostRequest::rules()`.
- Iniciativa anterior, como formato: `docs/roadmap/2026-10-22-social-login-mcp-oauth/`.

## Supuestos

- Un solo usuario y varias marcas. No hace falta concurrencia entre editores ni bloqueo optimista del post.
- Las 4 redes y los tipos actuales no cambian durante la iniciativa.
- `instagram_manage_comments` y `pages_manage_engagement`, ya pedidos (`config/social.php`), bastan para el primer comentario en Instagram y Facebook ⚠️. Se verifica con `Http::fake` en el desarrollo y en el smoke real.
- ⚠️ `commentThreads.insert` de YouTube exige probablemente el scope `youtube.force-ssl`, que hoy no se pide. Si se confirma, hay que reconectar las cuentas de YouTube. Lo verifica el plan en la documentación de Google antes de C-06.
- ⚠️ Los campos de la etiqueta IA (YouTube `status.containsSyntheticMedia` y TikTok `post_info.is_aigc`) y su disponibilidad se verifican en la documentación vigente antes de C-04. **Instagram:** no se conoce ningún campo público en la Graph API, así que es incógnita y probablemente no se ofrezca.
- ⚠️ Los máximos de la API de Instagram para `collaborators` (se cree que 3; Metricool muestra 5) y la obtención de `location_id` (búsqueda de Páginas, que puede exigir una *feature* con app review) se deciden en el spike de C-07.
- La publicación directa de TikTok sigue sujeta a la auditoría de TikTok (decisión G1, `config/social.php`). Las opciones de C-04 solo actúan en publicación directa, aunque ayudan a cumplir las directrices de la UX que pide esa auditoría ⚠️.
- La previsualización es aproximada por diseño. No se persigue fidelidad píxel a píxel con cada red.
