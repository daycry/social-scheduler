# Metricool: análisis de fuentes públicas

> **Fecha de consulta:** 2026-10-23. **Alcance:** solo páginas públicas (centro de ayuda, web comercial, documentación pública de la API y fichas de directorios MCP). No se ha iniciado sesión, no se han usado credenciales, no se ha llamado a la API y no se ha extraído código.
> **Finalidad:** contexto de producto para **Social Scheduler**, un clon funcional con identidad propia. Los textos están resumidos con nuestras palabras. Cada dato lleva la URL de la que sale.
> **Fiabilidad:** las fuentes de Metricool (`help.metricool.com`, `metricool.com`, `app.metricool.com`) son **oficiales**. Las de terceros (Scalekit, Glama, Hackceleration, Tygart Media, Nexla y un PR de GitHub) son **secundarias** y van marcadas con *(terceros)*. Los límites cambian a menudo, así que conviene volver a comprobarlos antes de usarlos como requisito.

Abreviatura usada en las URLs: `HC` = `https://help.metricool.com`.

---

## 1. Centro de ayuda: módulos y compositor

### 1.1 Mapa de módulos

Fuente: [HC portada](https://help.metricool.com/).

| Módulo | Subsecciones relevantes |
|---|---|
| Primeros pasos | — |
| Cuenta y facturación | Cuenta y acceso, Marcas y usuarios, Planes, Legal |
| Conexión de redes | Gestión de conexiones, por red, resolución de problemas |
| **Planning** | Primeros pasos, Crear y programar, Optimizar la programación, Publicar por red, Colaboración y aprobación, Herramientas de creación (Canva, Drive…), **Flows**, Directrices y límites, Errores |
| Analítica | Métricas, métricas por red, problemas |
| Reporting | Informes, Campaign Dashboards, Metricool Studio, Hashtag Tracker, Data Studio (Looker) |
| API e integraciones | **Metricool MCP**, integraciones con la API, herramientas externas |
| SmartLinks | Link in bio con CTR |
| Inbox | Mensajes y comentarios unificados |
| Ads | Campañas de Meta, Google y TikTok Ads |
| App móvil, afiliados, marca blanca (agencias e integradores) | — |

### 1.2 Flujo del compositor (Planning → Create post)

Fuente: [HC Schedule from the calendar](https://help.metricool.com/how-to-schedule-content-from-the-calendar-q8ymv).

1. Se abre desde **Create new post** o al pulsar una franja del calendario. Las redes elegidas aparecen en color.
2. El calendario sombrea las **mejores horas**: más oscuro significa mejor. Hacen falta al menos 100 seguidores.
3. Texto con enlaces y @menciones, y media propia, de Pexels, vídeo o GIF. Cada adjunto tiene un menú de tres puntos y un editor de imagen y vídeo integrado ([HC edit media](https://help.metricool.com/edit-images-and-videos-while-scheduling-your-content-wputz)).
4. **Global presets** y, debajo, **presets por red**.
5. Fecha, hora y acción final.
6. **Vista previa** en escritorio y móvil, y vista de *feed* para Instagram ([HC content planning guide](https://help.metricool.com/content-planning-full-guide-faqs-qc5e1)).

Cada marca tiene su **zona horaria** propia. Si no se elige ninguna, se usa la de la cuenta. El compositor **no admite texto enriquecido** (negrita, cursiva, listas) ([HC character limits](https://help.metricool.com/character-limit-for-each-social-network-6e93w)).

Horizonte de programación ([HC calendar](https://help.metricool.com/how-to-schedule-content-from-the-calendar-q8ymv)):

| Tipo | Horizonte |
|---|---|
| Programación manual | Sin límite |
| Publicaciones recurrentes | 180 días |
| Programación generada por IA | 90 días |

### 1.3 «Edit by network»: textos distintos por red

Fuente: [HC Multiposting: edit per social network](https://help.metricool.com/multiposting-edit-per-social-network-85out).

- Tras elegir las redes se pulsa **Edit by network**. Aparece una pestaña **Template** (plantilla común) y una pestaña por red.
- En la pestaña de una red, **Edit content** desvincula esa red de la plantilla. Se puede cambiar texto, menciones, media, emojis, hashtags, primer comentario, enlaces, alt text, etiquetas, portada y ubicación. **Use template** vuelve a vincularla.
- Al guardar se crea **una publicación por red**, independientes entre sí en el calendario. Esto lo confirma también la [guía de planificación](https://help.metricool.com/content-planning-full-guide-faqs-qc5e1).
- Desde el mismo compositor se puede programar o **enviar a revisión**.

### 1.4 Presets globales

Fuentes: [HC calendar](https://help.metricool.com/how-to-schedule-content-from-the-calendar-q8ymv), [HC Planning settings](https://help.metricool.com/how-planning-settings-work-jsu0i), [HC links](https://help.metricool.com/scheduling-content-with-links-6kqha).

| Preset | Comportamiento |
|---|---|
| **Use URL shortener** | Acorta **todos** los enlaces `https://` en el momento de publicar, por lo que la vista previa muestra la URL original. Usa el dominio `t.mtrbio.com`. Según Metricool, no rastrea nada. |
| **SmartLinks** | Al publicarse, el post se añade al SmartLink elegido. Es un campo manual que empieza vacío en cada post. |
| **Campaign Dashboards** | Asocia el post a un dashboard de campaña. Campo manual, empieza vacío. |
| **Auto publish** | Interruptor por red (Instagram, TikTok). Si está desactivado, llega una **notificación** para publicar a mano desde el móvil ([HC manual via notification](https://help.metricool.com/how-to-manually-publish-via-notification-5ds3m)). |

**Regla de persistencia:** todo lo que es un *interruptor* conserva su última posición en los posts siguientes. Los campos de texto se reinician. No existe la acción «guardar como predeterminado» ([HC Planning settings](https://help.metricool.com/how-planning-settings-work-jsu0i)).

### 1.5 Presets y opciones por red

Fuentes principales: [HC options by network](https://help.metricool.com/scheduling-and-posting-options-by-social-network-cwmb3) y los artículos de cada red citados en cada fila.

| Red | Formatos | Opciones específicas |
|---|---|---|
| **Instagram** ([HC](https://help.metricool.com/schedule-and-post-on-instagram-6b6q5)) | Post (hasta 10 elementos en carrusel), Reel (3 s a 15 min), Trial Reel, Story | Auto publish o notificación. **Colaboradores** (hasta 5; deben aceptar, y si no, se publica sin colaboración). **Etiquetas de producto** ([HC](https://help.metricool.com/tag-instagram-products-k9r9f)). Ubicación. Primer comentario (solo post). Alt text (solo post). Etiquetar personas. **Portada de Reel** (fotograma o imagen, se recomiendan 1080×1800). **Show Reel on Feed**. Audio de biblioteca o renombrar «Original audio». **Etiqueta de contenido IA**. Promocionar. Búsqueda de hashtags (de pago). Las Stories no admiten ninguna de estas opciones. |
| **Facebook** | Hasta 35 imágenes, vídeo, Reel (3–90 s), GIF, Story | Ubicación, menciones (solo Páginas), alt text, título, primer comentario, portada, promocionar. Carrusel multienlace: no. |
| **TikTok** ([HC](https://help.metricool.com/schedule-and-post-on-tiktok-bkepi)) | Vídeo (3 s a 10 min) o carrusel de hasta 35 fotos | Auto publish o notificación. **Privacidad**: Public, Friends u Only me (solo cuentas personales). Interruptores de **Comments, Duet y Stitch**. **Divulgación comercial**: «Your brand» o «Branded content». Portada. Música (Top 100 en cuentas Business). **Etiqueta IA** (solo vídeo). Primer comentario. Sin menciones ni alt text. La API no admite saltos de línea. |
| **YouTube** ([HC](https://help.metricool.com/schedule-and-publish-on-youtube-gof0k)) | Short (hasta 3 min, vertical o cuadrado) o vídeo largo | **Título** (100 caracteres), descripción (5.000), **privacidad** (Public, Unlisted o Private), **categoría**, **tags**, **Made for kids**, **miniatura** (1280×720; en Shorts, 1080×1920), **playlist** (con botón *Refresh*), **notificar a suscriptores** (solo al subir), primer comentario, **etiqueta IA** (solo web). |
| **Pinterest** ([HC](https://help.metricool.com/schedule-and-post-on-pinterest-sbiz3)) | 1 imagen o 1 vídeo | **Elegir o crear tablero**, título, **enlace**, **nuevo formato de Pin** (interruptor persistente), portada de vídeo con fotograma. |
| **LinkedIn** ([HC](https://help.metricool.com/schedule-and-publish-on-linkedin-2seid)) | Hasta 20 imágenes, vídeo, GIF, **documento** (PDF, PPT, DOC), encuesta | **Vista previa de enlace** (solo con un enlace y como máximo una imagen). **Carrusel de imágenes como PDF**. Primer comentario. Menciones con el slug de la Página. Portada de vídeo. **Encuestas** ([HC](https://help.metricool.com/how-to-schedule-polls-and-carousels-on-linkedin-uvkp3)). |
| **X** ([HC](https://help.metricool.com/schedule-and-publish-on-x-twitter-klho8)) | Hasta 4 imágenes, o 1 vídeo, o 1 GIF. **Hilos de hasta 80 posts**. Encuestas ([HC](https://help.metricool.com/how-to-schedule-polls-on-x-twitter-m41uy)) | **Control de respuestas**: todos, seguidores, seguidos, mencionados o verificados. Alt text, etiquetar en imágenes, portada de vídeo. Requiere un **add-on de pago** desde Starter. |
| **Threads** ([HC](https://help.metricool.com/schedule-and-post-on-threads-pj8pq)) | Hasta 20 imágenes o vídeos, hilos de 80 posts, **Ghost post** (desaparece a las 24 h) | Control de respuestas (todos, seguidos, mencionados, seguidores), **spoiler** en imagen, **un solo hashtag** por post. |
| **Bluesky** ([HC](https://help.metricool.com/schedule-and-publish-on-bluesky-0zzn8)) | Hasta 4 imágenes, 1 vídeo o 1 GIF. Hilos de 80 posts | **Idiomas del texto**, alt text, menciones con autocompletado. |
| **Google Business** ([HC](https://help.metricool.com/schedule-and-post-on-google-business-profile-rgq1m)) | Post (texto con 1 imagen o 1 vídeo) o Foto | Un enlace genera un botón fijo «More info». Sin menciones, alt text, primer comentario ni ubicación. |

**Etiqueta de contenido generado por IA** (cumplimiento de la AI Act de la UE): es un interruptor **por red**, no global. Está disponible en Instagram (imagen, Reel y carrusel), TikTok (solo vídeo) y YouTube (vídeo, solo web). No existe en Facebook, LinkedIn, X ni Pinterest ([HC AI label](https://help.metricool.com/how-to-label-ai-generated-content-for-eu-ai-act-compliance-8a1j8)).

**Ubicación:** solo Instagram y Facebook ([HC location](https://help.metricool.com/how-to-add-a-location-to-my-post-ocjt1), [HC options](https://help.metricool.com/scheduling-and-posting-options-by-social-network-cwmb3)).

### 1.6 Límites de caracteres, primer comentario y hashtags

Fuente: [HC Character limit for each social network](https://help.metricool.com/character-limit-for-each-social-network-6e93w).

| Red | Texto | Primer comentario | Hashtags |
|---|---|---|---|
| Facebook | 16.192 | 8.000 | — |
| Instagram | 2.200 | 2.200 | **30** por post, aunque Instagram recomienda 3–5 ([HC IG](https://help.metricool.com/schedule-and-post-on-instagram-6b6q5)) |
| Threads | 500 | no admite | **1** por post ([HC Threads](https://help.metricool.com/schedule-and-post-on-threads-pj8pq)) |
| X | 280 (25.000 con X Premium). Cada enlace cuenta 23 caracteres | — | — |
| Bluesky | 300 | — | — |
| LinkedIn | 3.000 | 1.250 | — |
| Pinterest | 800 (la tabla general dice 500) | — | — |
| TikTok | 2.000 en auto publish, 2.200 en publicación manual | 150 (páginas profesionales) | — |
| Google Business | 1.500 | — | — |
| YouTube | 5.000 de descripción y 100 de título | 10.000 | — |

Notas:

- Fuera de Instagram y Threads, Metricool no documenta límites de hashtags ni de menciones.
- El contador de Metricool puede no coincidir con el de la red cuando hay emojis o Unicode ([HC](https://help.metricool.com/character-limit-for-each-social-network-6e93w)).

Límites del CSV ([HC CSV](https://help.metricool.com/how-to-schedule-posts-in-batch-with-a-csv-file-in-metricool-3zwxj)):

| Campo | Límite |
|---|---|
| Título de Pin | 100 |
| Título de TikTok | 90 |
| Título de documento de LinkedIn | 58 |
| Opciones de encuesta | 25 en X, 30 en LinkedIn |

Cupos diarios de las redes en una ventana móvil de 24 h ([HC daily limits](https://help.metricool.com/daily-posting-limits-on-social-media-sh58j)):

| Red | Cupo |
|---|---|
| Instagram | 50 posts; un carrusel cuenta como uno |
| Facebook | 30 Reels |
| Threads | 250 posts y 1.000 respuestas |
| YouTube | 10 en el plan Free y 20 en los de pago (lo fija Metricool por cuota) |
| TikTok | Sin cupo publicado; se recomienda dejar al menos 5 min entre vídeos |

Los reintentos fallidos también pueden contar para el cupo de la red.

### 1.7 Acciones del botón Schedule y del post guardado

Fuentes: [HC calendar](https://help.metricool.com/how-to-schedule-content-from-the-calendar-q8ymv), [HC duplicate](https://help.metricool.com/how-to-duplicate-posts-in-metricool-17prr), [HC approval](https://help.metricool.com/how-to-send-posts-for-review-with-metricools-approval-system-yvdr0).

| Acción | Detalle |
|---|---|
| **Schedule** | Guarda el post y lo publica a la hora elegida. |
| **Save draft** | Lo guarda como borrador, sin publicar. |
| **Save to library** / Send to Library | Lo guarda como idea o plantilla sin fecha. |
| **Send to review** | Abre el flujo de aprobación. Solo en los planes Advanced y Custom. |
| **Publish now** | Lo publica de inmediato. |
| *Con el post ya guardado:* **Duplicate** | Abre el compositor con una copia. En lote: **Duplicate as draft** o **Duplicate and schedule**. Si una copia tiene errores de validación, queda como borrador. |
| *Con el post ya guardado:* **Duplicate to another brand** | Copia el post en otras marcas, individualmente o en lote. **No ajusta la zona horaria** de la marca de destino. |
| *Con el post ya guardado:* **Delete** | Lo borra solo en Metricool, no en la red. Hay papelera para recuperarlo ([HC](https://help.metricool.com/how-to-recover-deleted-posts-in-metricool-byy42)). |

**Flujo de aprobación** ([HC approval](https://help.metricool.com/how-to-send-posts-for-review-with-metricools-approval-system-yvdr0)):

- **Revisores:** miembros del equipo o **correos externos**, que revisan sin cuenta a través de un portal.
- **Criterios de aprobación:**
  - basta con una aprobación;
  - deben aprobar todos;
  - se aprueba solo si nadie rechaza.
  - En los tres casos, **un rechazo siempre bloquea** la publicación.
- **Estados:** Pending review (amarillo), Approved (verde) y Rejected (rojo).
- **Caducidad:** si un post sigue pendiente a su hora, tiene **3 h de gracia**. Pasado ese plazo, no se publica.
- **Edición:** editar un post ya enviado reinicia la revisión y obliga a reenviarlo.
- **Comentarios:** los revisores pueden comentar sin rechazar.
- **Avisos:** un interruptor controla el email a los revisores. El remitente recibe un aviso cuando hay rechazo.
- **Rol Content Creator:** siempre debe pasar por aprobación.
- **Envío en lote:** desde la vista Lista.
- **Otros artículos relacionados:**
  - bandeja «Review requests» ([HC](https://help.metricool.com/how-to-manage-content-approval-from-review-requests-formerly-my-tasks-c1xn8));
  - compartir el calendario con clientes ([HC](https://help.metricool.com/how-to-share-your-content-calendar-with-clients-or-collaborators-sy0yt));
  - compartir un post por enlace ([HC](https://help.metricool.com/how-to-share-posts-with-a-link-dhelx)).

### 1.8 Mejores horas

Fuente: [HC Best time to post](https://help.metricool.com/best-time-to-post-on-social-media-in-metricool-w7ll9).

- **Redes:** Facebook, Instagram, TikTok, X, LinkedIn y YouTube.
- **Visualización:** mapa de calor con los días en columnas y las franjas en filas, y una vista opcional en porcentaje.
- **Origen de los datos:**
  - **Instagram:** API de Meta si la cuenta está enlazada a una Página y tiene al menos 100 seguidores. Si no, estimaciones de estudios propios.
  - **Facebook:** Meta retiró el soporte en la API el 24-09-2024. Las conexiones nuevas reciben horas genéricas.
  - **X:** actividad de seguidores en los últimos 7 días; requiere al menos 300 activos. Solo en los planes Advanced y Custom.
  - **TikTok:** API en cuentas Business con al menos 100 seguidores.
  - **LinkedIn y YouTube:** solo estudios genéricos.
- **Historial:** no guarda histórico, así que todas las semanas muestran lo mismo.
- **Retardo:** los datos pueden tardar hasta 7 días en aparecer tras conectar la cuenta.
- **Usos en la programación:** las recurrentes pueden usar «Use best times» ([HC recurring](https://help.metricool.com/how-to-schedule-recurring-posts-in-metricool-r63b1)), y las autolistas recomiendan consultarlas.

### 1.9 Autolistas, recurrencia y CSV

Autolistas ([HC autolist](https://help.metricool.com/schedule-content-from-an-autolist-fxehk)):

- **Qué son:** colas ordenadas de posts que se publican en franjas semanales. Cada franja es una hora más los días de la semana en que aplica.
- **Gestión:** se reordenan arrastrando, y cada post se puede pausar.
- **Repetición:** con **Repeat** activado, la lista es circular. Con Repeat desactivado, el post sale de la lista al publicarse.
- **Límites:** 200 posts por lista y 10 adjuntos por post. Desde una autolista no se pueden etiquetar personas ni productos de Instagram.
- **Origen del contenido:** alta manual, IA, CSV, **RSS** ([HC RSS](https://help.metricool.com/how-to-link-an-rss-feed-to-an-autolist-ank6m)) o API ([HC](https://help.metricool.com/add-content-to-an-autolist-using-the-api-q3wl0)).

Publicaciones recurrentes ([HC recurring](https://help.metricool.com/how-to-schedule-recurring-posts-in-metricool-r63b1)):

- **Modos:** días de la semana con una duración, fechas sueltas, o un *prompt* en lenguaje natural.
- **Límites:** 180 días y 200 posts.
- **Ejecución:** se generan en segundo plano, con vista previa editable.
- **Restricción:** los posts generados no se pueden enviar a revisión.
- **Plan:** Advanced y Custom.

CSV masivo ([HC CSV](https://help.metricool.com/how-to-schedule-posts-in-batch-with-a-csv-file-in-metricool-3zwxj)):

- **Plantilla fija** con:
  - texto, fecha, hora, columna **Draft**, marca;
  - un booleano por red;
  - hasta 10 URLs de imagen con su alt text;
  - acortador y primer comentario;
  - columnas propias de cada red: tablero y título de Pin, tipo de post de IG, privacidad, categoría y playlist de YouTube, privacidad y Duet/Stitch de TikTok, encuestas de X y LinkedIn, control de respuestas de Threads…
- **Varias marcas:** una fila por marca.
- **Tamaño recomendado:** unos 50 posts por fichero.

### 1.10 Biblioteca de posts, textos guardados y notas

- **Posts Library** ([HC](https://help.metricool.com/posts-library-u05cj)):
  - Guarda ideas y plantillas sin fecha. Es compartida por marca, con un tope de **250** por marca.
  - Tiene vista lista y vista **tablero** con estados de flujo editables: «In progress», «Done» y «Unassigned», hasta 30 columnas.
  - Filtros por miembro asignado, red, notas y campaña.
  - Al programar desde la biblioteca se crea una copia independiente.
  - Solo en planes de pago.
- **Saved texts** ([HC](https://help.metricool.com/how-to-save-texts-zedge)):
  - Fragmentos reutilizables con título, tags y texto, insertables en el post o en el primer comentario.
  - Opción «para todas las marcas».
  - Solo el autor puede editarlos.
- **Notas en el calendario** ([HC](https://help.metricool.com/how-to-add-notes-in-the-planning-4aueg)) y **calendarios sociales** de efemérides ([HC](https://help.metricool.com/how-to-use-social-calendars-in-metricool-5uy70)).
- **UTM Builder** en el compositor ([HC](https://help.metricool.com/utm-builder-while-planning-your-content-qm0de)).
- **Reutilizar contenido** desde Analítica, con **Reuse content** ([HC guide](https://help.metricool.com/content-planning-full-guide-faqs-qc5e1)).

### 1.11 IA en el compositor

Fuente: [HC AI Text Generator](https://help.metricool.com/how-to-create-posts-with-metricools-ai-text-generator-sv0j8).

- **Entrada:** tema, tono (formal, informal, creativo…), idioma y red objetivo opcional.
- **Retoques posteriores:** añadir hashtags o CTA, alargar o acortar, y **convertir en hilo** de 5 posts para X y Threads.
- **Instrucciones personalizadas** por marca y por red, en Brand settings → AI Configuration ([HC](https://help.metricool.com/how-to-set-up-ai-prompt-customization-in-metricool-bl6y7)).
- **Créditos mensuales:**

  | Plan | Créditos |
  |---|---|
  | Free | 5 |
  | Starter | 20 por marca |
  | Advanced | 35 por marca |

  No son acumulables.
- **Alt text con IA** ([HC](https://help.metricool.com/how-to-add-alt-text-to-your-images-using-ai-mkl42)).

### 1.12 Flows (automatizaciones de respuesta)

Fuentes: [HC Flows](https://help.metricool.com/flows-edzxn), [HC What are Flows?](https://help.metricool.com/what-are-flows-nfody).

- **Modelo:** disparador → acción, atado a **un post** y a **una red**.
- **Instagram:**
  - comentario → DM;
  - DM con palabra clave → respuesta;
  - respuesta a story → DM.
- **TikTok:** respuesta instantánea a comentarios y moderación.
- **X:** Auto DM, Auto Repost/Unpost y Comment → DM.
- **Restricciones:**
  - un post no puede tener dos automatizaciones activas;
  - se clonan para reutilizarlas;
  - solo funcionan en la web.
- **Plan:** todos los de pago, «gratis por tiempo limitado». No está en el plan Free.

---

## 2. API pública de Metricool

### 2.1 Disponibilidad, autenticación y documentación

| Aspecto | Dato | Fuente |
|---|---|---|
| Plan | Solo **Advanced** y **Custom**. El token no aparece en otros planes | [HC MCP vs API](https://help.metricool.com/mcp-vs-api-access-what-is-the-difference-5y3ib), [HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af), [pricing](https://metricool.com/pricing/) |
| URL base | `https://app.metricool.com/api` | [apidocs](https://app.metricool.com/resources/apidocs/index.html) |
| Autenticación | Cabecera **`X-Mc-Auth: <userToken>`**. Con `Authorization: Bearer` se obtiene un 401 | [apidocs](https://app.metricool.com/resources/apidocs/index.html), [HC errors](https://help.metricool.com/common-questions-and-errors-when-using-the-api-8x9nq), [Tygart Media](https://tygartmedia.com/metricool-api-guide/) *(terceros)* |
| Parámetros obligatorios | `userId` (cuenta) y `blogId` (marca) en la *query* de **todas** las llamadas. Un mismo token sirve para todas las marcas | [apidocs](https://app.metricool.com/resources/apidocs/index.html), [HC errors](https://help.metricool.com/common-questions-and-errors-when-using-the-api-8x9nq) |
| Obtención del token | Account Settings → API. Regenerarlo invalida el anterior al instante | [HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af) |
| Ámbito del token | Sin OAuth, sin *scopes* y sin caducidad documentada | [Scalekit](https://www.scalekit.com/blog/metricool-mcp-vs-api) *(terceros)* |
| Documentación | Página Swagger UI pública, guía PDF (actualizada el 04-09-2024) | [apidocs](https://app.metricool.com/resources/apidocs/index.html), [PDF](https://static.metricool.com/API+DOC/API+English.pdf) |
| OpenAPI descargable sin login | `https://app.metricool.com/api/swagger.json` y `https://app.metricool.com/api/swagger.yaml`. Se citan las URLs pero **no se han descargado** | [apidocs](https://app.metricool.com/resources/apidocs/index.html) |
| Cobertura | El PDF afirma que «todos los datos de pantalla» tienen endpoint. Para los no listados, recomienda mirar las llamadas del navegador | [PDF](https://static.metricool.com/API+DOC/API+English.pdf), [HC errors](https://help.metricool.com/common-questions-and-errors-when-using-the-api-8x9nq) |

### 2.2 Grupos de endpoints (según las fuentes públicas)

| Grupo | Ejemplos y capacidades | Fuente |
|---|---|---|
| **Admin / marcas** | `GET /admin/simpleProfiles` lista las marcas y sus `blogId`. También hay información de usuario, suscripción y permisos | [apidocs](https://app.metricool.com/resources/apidocs/index.html), [Nexla](https://docs.nexla.com/user-guides/connectors/metricool_api) *(terceros)* |
| **Scheduler / posts (v2)** | `POST`/`GET /v2/scheduler/posts`. `GET`/`PUT`/`PATCH`/`DELETE /v2/scheduler/posts/{id}`. Envío a revisión en lote. `PUT …/{id}/approvals` para aprobar o rechazar. `GET …/approvals-config`. `GET …/instagram-properties` | [HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af) |
| **Media** | `GET /actions/normalize/image/url?url=…` aloja el fichero y devuelve el `mediaId` que se usa en el post. Las URLs privadas o temporales se descartan sin error | [HC errors](https://help.metricool.com/common-questions-and-errors-when-using-the-api-8x9nq) |
| **Analítica** | v2 por red y formato (p. ej. `/v2/analytics/reels/instagram?from&to`). Series heredadas (`/stats/timeline/...`) | [PDF](https://static.metricool.com/API+DOC/API+English.pdf) |
| **Analítica por red, según el catálogo de Nexla** | Posts, reels y stories de FB e IG, competidores, posts de TikTok, LinkedIn, X y YouTube | [Nexla](https://docs.nexla.com/user-guides/connectors/metricool_api) *(terceros)* |
| **Otros (Nexla)** | SmartLinks (búsqueda por *slug*), boost de posts de Facebook, generación de texto con IA, creación de Flows | [Nexla](https://docs.nexla.com/user-guides/connectors/metricool_api) *(terceros)* |
| **Autolistas** | Añadir contenido a una autolista | [HC](https://help.metricool.com/add-content-to-an-autolist-using-the-api-q3wl0) |
| **Inbox** | Comentarios accesibles por API, no por MCP | [HC MCP vs API](https://help.metricool.com/mcp-vs-api-access-what-is-the-difference-5y3ib) |
| **Uso** | `GET /v2/settings/users/{userId}/api-usage-stats?from&to&aggregation` | [HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af) |

**Cuerpo de un post** ([HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af), [PDF](https://static.metricool.com/API+DOC/API+English.pdf)):

- **Obligatorios:**
  - `publicationDate {dateTime, timezone}`, en el futuro;
  - `text`;
  - `providers[{network}]`, con al menos uno.
- **Opcionales:**
  - `draft`, `autoPublish`, `firstCommentText`, `shortener`, `saveExternalMediaFiles`;
  - un objeto por red (`facebookData`, `instagramData`…).

### 2.3 Límites de uso

- Metricool **no publica límites de tasa** oficiales. Lo confirman [HC basic guide](https://help.metricool.com/basic-guide-for-api-integration-r97af) y [HC errors](https://help.metricool.com/common-questions-and-errors-when-using-the-api-8x9nq).
- El uso de la API se consulta en Account Settings → API o con el endpoint de estadísticas de uso.
- Una fuente de terceros indica un 429 con `Retry-After` y recomienda espaciar las llamadas en torno a 1 s ([Tygart Media](https://tygartmedia.com/metricool-api-guide/) *(terceros)*). **Sin confirmación oficial.**
- Siguen aplicando los cupos de cada red (sección 1.6) y la política de uso razonable ([HC fair use](https://help.metricool.com/fair-use-policy-for-social-media-scheduling-e3tnm)).

---

## 3. Servidor MCP oficial de Metricool

### 3.1 Conexión

| Aspecto | Dato | Fuente |
|---|---|---|
| Endpoint remoto | `https://ai.metricool.com/mcp` (HTTP *streamable*) | [HC connect](https://help.metricool.com/how-to-connect-metricools-mcp-eqp9h), [HC schedule via LLM](https://help.metricool.com/schedule-and-edit-posts-through-an-llm-with-metricools-mcp-p8y4b) |
| Autenticación A | **OAuth 2.1** con consentimiento en el navegador. Funciona en todos los planes, también Free. Algunos clientes usan DCR | [HC connect](https://help.metricool.com/how-to-connect-metricools-mcp-eqp9h), [Scalekit](https://www.scalekit.com/blog/metricool-mcp-vs-api) *(terceros)* |
| Autenticación B | Token: cabecera `X-Mc-Auth` (n8n) o variables `METRICOOL_USER_TOKEN` y `METRICOOL_USER_ID`. Va por la API, así que exige **Advanced** | [HC FAQs](https://help.metricool.com/faqs-about-the-metricool-mcp-1i3w0), [HC limits](https://help.metricool.com/mcp-limits-and-plan-requirements-h72jg) |
| Clientes | Claude (conector oficial del directorio), Claude Code, Claude Desktop (vía `mcp-remote`), ChatGPT, Cursor, Gemini CLI, Mistral, Make, Zapier, n8n, TypingMind, Smithery | [HC connect](https://help.metricool.com/how-to-connect-metricools-mcp-eqp9h), [metricool.com MCP](https://metricool.com/metricool-mcp-claude/) |
| Ámbito | Acceso completo, «como iniciar sesión». No hay modo de solo lectura ni concesión por marca | [HC connect](https://help.metricool.com/how-to-connect-metricools-mcp-eqp9h), [Scalekit](https://www.scalekit.com/blog/metricool-mcp-vs-api) *(terceros)* |
| Confirmación | Tras crear o editar, el modelo devuelve un **enlace al post en el planner** (`plannerUrl`) | [HC schedule via LLM](https://help.metricool.com/schedule-and-edit-posts-through-an-llm-with-metricools-mcp-p8y4b) |
| Fuera del MCP | Inbox, comentarios, SmartLinks, reseñas de Google Business, autolistas, Canva/Drive y gestión de campañas de Ads (la analítica de Ads sí está) | [HC limits](https://help.metricool.com/mcp-limits-and-plan-requirements-h72jg) |
| Código abierto | Repo `metricool/mcp-metricool` (Python, PyPI, `uvx`) con una versión anterior de las herramientas, en snake_case | [metricool.com MCP](https://metricool.com/metricool-mcp-claude/), [Glama](https://glama.ai/mcp/servers/metricool/mcp-metricool/schema) *(terceros)* |

> Una ficha de terceros cita `mcp.metricool.ai/mcp`, pero la documentación oficial usa `ai.metricool.com/mcp`.

### 3.2 Herramientas del servidor alojado (camelCase)

La documentación oficial **no publica la tabla de herramientas** del servidor alojado. Solo describe capacidades: ver la configuración de marca, consultar métricas y sus datos, ver posts programados, mejores horas, crear y editar posts, y crear o enviar a revisión ([HC limits](https://help.metricool.com/mcp-limits-and-plan-requirements-h72jg)).

Los nombres y parámetros siguientes vienen de la ficha de [Scalekit](https://www.scalekit.com/connectors/metricoolmcp) *(terceros)*. Coinciden con [Hackceleration](https://hackceleration.com/resources/integrations/claude/metricool-social-media-management) *(terceros)* y con un PR que listó las herramientas en vivo y contó 9 ([GitHub PR](https://github.com/andresiletsky-bit/strw-factory/pull/29) *(terceros)*). El directorio de Anthropic muestra 7, porque no incluye las dos de revisión.

| Herramienta | Propósito | Parámetros principales (\* = obligatorio) |
|---|---|---|
| `getBrandSettings` | Lista las marcas (id, zona horaria, redes conectadas) | ninguno |
| `getAnalyticsAvailableMetrics` | Métricas disponibles para una red y un conector | `network` (instagram, facebook, twitter, youtube, tiktok, linkedin, pinterest, twitch, bluesky, threads, googleBusinessProfile, metaAds, tiktokAds…) y `connector` (evolution, posts, reels, stories, competitors, hashtags, campaigns, videos) |
| `getAnalyticsDataByMetrics` | Datos de un rango por IDs de métrica (campos de Data Studio, p. ej. `IGPO01`) | `brandId`\*, `from`\*, `to`\* (ISO 8601) y `metrics[]` |
| `getBestTimeToPostByNetwork` | Franjas día/hora con puntuación | `brandId`\*, `socialNetwork`\* (twitter, facebook, instagram, linkedin, youtube, tiktok), `fromDate`\*, `toDate`\*, `timezone`\* (IANA) |
| `getScheduledPosts` | Posts programados de una marca | `brandId`\*, `fromDate`\*, `toDate`\*, `timezone`\* y `extendedRange` (±1 día) |
| `createScheduledPost` | Crea un post en una o varias redes | `blogId`\*, `date`\* (ISO con *offset*), `info`\* (JSON en texto: `providers`, `text`, `publicationDate`, `media`, datos por red…) y `mediaFiles[]` (ficheros adjuntos en ChatGPT) |
| `updateScheduledPost` | Edita texto, fecha o red. Exige el **contenido completo** más los cambios | `blogId`\*, `id`\*, `uuid`\*, `info`\* y `mediaFiles[]` |
| `createScheduledPostForReview` | Crea un post y lo envía a aprobación | `blogId`\*, `date`\*, `info`\*, `reviewers`\* (emails separados por comas), `approvalSystem`\* (`any`, `all` u `optional`) y `notify` |
| `sendScheduledPostForReview` | Envía a aprobación un post ya existente | `blogId`\*, `id`\*, `uuid`\*, `info`\*, `reviewers`\*, `approvalSystem`\* y `notify` |

Comportamientos observados ([GitHub PR](https://github.com/andresiletsky-bit/strw-factory/pull/29) *(terceros)*):

- `autoPublish` vale `true` por defecto dentro de `info`. Con `false`, la red no publica sola: llega un aviso al móvil.
- Las herramientas de revisión fallan con «requires a plan with team management» si el plan no incluye equipos.
- Las herramientas de revisión indican que el error se transmita **sin reintentar**.
- La respuesta incluye `id`, `uuid`, `plannerUrl` y `text`.

### 3.3 Herramientas del paquete de código abierto (snake_case, versión previa)

Fuentes: [Glama schema](https://glama.ai/mcp/servers/metricool/mcp-metricool/schema) *(terceros)*, [metricool.com MCP](https://metricool.com/metricool-mcp-claude/). En total son 27–28 herramientas.

- **Marcas:** `get_brands` y `get_brands_complete`.
- **Lectura por red:** `get_{instagram_posts|instagram_reels|instagram_stories|facebook_posts|facebook_reels|facebook_stories|tiktok_videos|linkedin_posts|x_posts|youtube_videos|pinterest_pins|thread_posts|bluesky_posts|twitch_videos}`. Todas reciben `init_date`, `end_date` y `blog_id`.
- **Ads:** `get_facebookads_campaigns`, `get_googleads_campaigns` y `get_tiktokads_campaigns`.
- **Competidores:** `get_network_competitors` y `get_network_competitors_posts`, con `network`, `limit` y `timezone`.
- **Planner:**
  - `get_pinterest_boards`;
  - `get_scheduled_posts` (`start`, `end`, `timezone`, `extendedRange`);
  - `get_best_time_to_post` (`provider`, `start`, `end`, `timezone`);
  - `post_schedule_post` y `update_schedule_post` (`date`, `blog_id`, `info`).
- **Analítica:** `get_metrics` (`network`) y `get_analytics` (`blog_id`, `start`, `end`, `timezone`, `network`, `metric[]`).

Campos de `info` en `post_schedule_post`:

| Campo | Valor por defecto o contenido |
|---|---|
| `autoPublish` | `true` |
| `draft` | `false` |
| `descendants` | posts del hilo |
| `firstCommentText` | — |
| `media` | — |
| `mediaAltText` | — |
| `providers` | — |
| `publicationDate` | — |
| `shortener` | — |
| `smartLinkData` | — |
| `text` | — |
| `twitterData`, `instagramData`, `youtubeData`… | un objeto por red |

Reglas incluidas en las descripciones de las herramientas:

- Instagram, TikTok, Pinterest y YouTube exigen media.
- Pinterest exige tablero. YouTube exige título y audiencia.
- X tiene un tope de 280 caracteres y Bluesky de 300. No se convierte un post en hilo automáticamente.
- Ante un error, el texto no se modifica.
- Antes de editar hay que pedir confirmación al usuario.

---

## 4. Planes y límites (contexto)

Fuentes: [pricing](https://metricool.com/pricing/), [HC Free vs paid](https://help.metricool.com/main-differences-between-free-and-paid-plans-bl0v9). Precios mensuales con facturación anual, sin IVA.

| | Free | Starter | Advanced | Custom |
|---|---|---|---|---|
| Precio | 0 € | 16 € (5 marcas) / 29 € (10) | 43 € (15) / 69 € (25) / 130 € (50) | A medida |
| Marcas | 1 | hasta 10 | hasta 50 | 50+ |
| Posts al mes | **20 publicados** (los posts multirred cuentan uno por red) | Ilimitados con *fair use* | Ilimitados con *fair use* | Ilimitados con *fair use* |
| Historial de analítica | **30 días**, con marca de agua | Ilimitado (X: 30 días) | Ilimitado | Ilimitado |
| Competidores | 5 | 100 | 100 | 100 |
| LinkedIn / X | No / No | Sí / add-on | Sí / add-on | Sí / add-on |
| Biblioteca, SmartLinks, informes | No | Sí | Sí | Sí |
| Equipo y roles, **aprobaciones**, **API**, recurrentes, Data Studio | No | No | **Sí** | Sí |
| Créditos de IA al mes | 5 | 20 por marca | 35 por marca | A medida |
| MCP | Sí | Sí | Sí | Sí |
| Marca blanca | No | No | No | Sí |

Otros datos:

- Cada marca admite un perfil por red.
- Al bajar de plan, las marcas sobrantes se ocultan, no se borran ([HC Free vs paid](https://help.metricool.com/main-differences-between-free-and-paid-plans-bl0v9)).

---

## 5. Ideas para Social Scheduler

Las ideas parten de lo que ya existe en Social Scheduler: `Post` con varios `PostTarget`, publicadores de IG, FB, YouTube y TikTok, mejores horas como mapa de calor 7×24, biblioteca (`PostTemplate`), papelera, autolistas, SmartLinks, Inbox y un MCP con `create_post`, `update_post`, etc.; ver [publicacion.md](../modulos/publicacion.md), [planificacion-avanzada.md](../modulos/planificacion-avanzada.md) y [mcp.md](../modulos/mcp.md).

**Cómo leer la tabla:**

- Las ideas están ordenadas por **valor / esfuerzo**: primero las de mucho valor y poco esfuerzo.
- El esfuerzo es una **estimación cualitativa** (bajo, medio o alto) sin validar con el evaluator.

| # | Idea (en qué se inspira) | Valor | Esfuerzo | Notas para nuestro diseño |
|---|---|---|---|---|
| 1 | **Validación de límites por red en el compositor**: contador de caracteres por red, 30 hashtags en IG, 1 en Threads, enlace de X = 23, media obligatoria, título obligatorio en YouTube (§1.6, §3.3) | Alto | Bajo | Una tabla de reglas por red en config y un validador compartido por la UI, `PostService` y el MCP, para que el error sea el mismo en los tres. |
| 2 | **Acciones del botón Schedule**: programar, borrador, publicar ahora, guardar en biblioteca, duplicar y duplicar como borrador (§1.7) | Alto | Bajo | Casi todo existe por separado. Unificarlo en un *split button* y añadir «Duplicate as draft» y «Duplicate and schedule» en lote. |
| 3 | **Edit by network**: plantilla común con desvinculación y revinculación por red (§1.3) | Alto | Medio | Encaja con `PostTarget`: un `override` opcional de texto, media o primer comentario por destino y un indicador «usa plantilla». Al guardar, el resultado es igual que hoy: un destino por red. |
| 4 | **Presets por red** en la UI y en el MCP: privacidad, categoría, tags, Made for kids, playlist y notificar suscriptores en YouTube; privacidad, comments, Duet, Stitch y divulgación comercial en TikTok; tipo (post, reel o story), share to feed y portada en IG (§1.5) | Alto | Medio | Un objeto `settings` JSON por destino validado por red, igual que `youtubeData` o `tiktokData` en Metricool. Priorizar las 4 redes que ya publicamos. |
| 5 | **Persistencia de interruptores** («si es un interruptor, persiste») (§1.4) | Medio | Bajo | Guardar la última posición de cada interruptor por usuario y marca. |
| 6 | **Primer comentario** con límite por red (§1.5, §1.6) | Medio-alto | Medio | Publicar el comentario tras confirmar el post (IG, FB, YouTube). Si falla, se registra un `PublishAttempt` aparte, sin marcar el post como fallido. |
| 7 | **Etiqueta de contenido IA** por red (IG, TikTok, YouTube) para la AI Act (§1.5) | Medio-alto | Bajo-medio | Un interruptor por destino que se envía en el campo nativo de cada API. Ya es un requisito regulatorio en la UE. |
| 8 | **Flujo de aprobación**: revisores internos y externos por email, tres criterios (any, all, optional), estados, 3 h de gracia, edición que reinicia la revisión (§1.7) | Alto (agencias) | Alto | Nuevo estado `en_revision` en `Post`, tabla de revisores y portal con enlace firmado para externos. El despachador **no** publica sin aprobación. En el MCP, exponer `send_post_for_review` (§3.2). |
| 9 | **Mejores horas integradas en el compositor**: sombreado en el calendario y opción «usar mejor hora» al programar o en recurrentes (§1.8) | Medio | Bajo | Ya tenemos `BestTimesService`. Falta mostrarlo en el selector de hora y exponer `get_best_times` por red con rango de fechas, como `getBestTimeToPostByNetwork`. |
| 10 | **Textos guardados**: fragmentos con tags, por marca o globales, insertables en el post y en el primer comentario (§1.10) | Medio | Bajo | Una tabla sencilla y un selector en el compositor. |
| 11 | **Acortador de URL y UTM Builder** (§1.4, §1.10) | Medio | Medio | Acortar al publicar, no al guardar, para que la vista previa conserve la URL real. Se puede reutilizar el dominio de SmartLinks. |
| 12 | **Biblioteca con tablero de estados** (In progress, Done…) y filtros (§1.10) | Medio | Medio | Extender `PostTemplate` con un estado configurable y una persona asignada. |
| 13 | **Recurrencia**: días de la semana × duración o fechas sueltas, con tope de 180 días y 200 posts y vista previa editable (§1.9) | Medio | Medio | Generar posts independientes, como Metricool, con el mismo patrón idempotente que las autolistas. |
| 14 | **Importación CSV** con columna Draft y columnas por red (§1.9) | Medio | Medio | Previsualizar los errores antes de importar y reutilizar el validador de la idea 1. |
| 15 | **Duplicar a otra marca** con aviso de zona horaria (§1.7) | Medio | Bajo | Mejorar lo que hace Metricool: **avisar** u ofrecer convertir la hora a la zona de la marca de destino. |
| 16 | **Vista previa por red** (móvil y escritorio) y vista de *feed* de IG (§1.2) | Medio | Medio-alto | Componentes de previsualización por red. Es costoso de mantener fiel a la red real. |
| 17 | **Autolistas con RSS** (§1.9) | Bajo-medio | Medio | Una fuente RSS que alimente la cola actual. |
| 18 | **Flows**: comentario con palabra clave → DM (§1.12) | Medio | Alto | Requiere webhooks de Meta y permisos de mensajería, con riesgo de revisión de la app. Mejor dejarlo para después del Inbox. |
| 19 | **MCP**: añadir `plannerUrl` en las respuestas, `info` con datos por red, `create_post_for_review` y `get_best_times` con rango (§3.2) | Medio | Bajo | La URL al post en la respuesta es una confirmación tangible muy útil en un chat. |

**Recomendación de orden:**

1. Ideas **1, 2, 5, 9 y 19**: victorias rápidas con poco esfuerzo.
2. Ideas **3 y 4**: el núcleo del compositor «por red».
3. Ideas **6 y 7**.
4. Idea **8**: la aprobación, que es el mayor diferenciador para agencias.
5. El resto, según demanda.
