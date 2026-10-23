# Metricool: análisis de la interfaz observada (2026-10-23)

Observación de la app web de Metricool (`app.metricool.com`) con la cuenta del usuario, en un perfil de Edge aparte y por CDP local (herramientas `rea` y Playwright conectados a `127.0.0.1:9222`). Solo se miró la interfaz: navegar por secciones, abrir menús y paneles del compositor y cerrarlos sin guardar. No se publicó, programó ni guardó nada; los borradores vacíos se descartaron con su propio aviso («Your changes will be lost»). No se extrajo código, endpoints internos ni datos de la cuenta.

Complementa a [`metricool-analisis-fuentes-publicas.md`](metricool-analisis-fuentes-publicas.md) (centro de ayuda, API y MCP públicos). Las capturas no se versionan: contienen datos de la cuenta.

## Compositor de publicaciones («Create new post»)

Se abre a pantalla completa (diálogo) desde **Planning → Create post**. Dos columnas: editor a la izquierda y previsualización a la derecha.

### Cabecera

| Elemento | Observado |
|---|---|
| Título | «Create new post» + chip con la marca (avatar y nombre) |
| Redes | Un icono por cuenta conectada; se activa o desactiva con un clic. La selección se recuerda entre aperturas |
| Tipo por red | Junto a cada red activa, un desplegable con el tipo: en Facebook «Post» (publicación estándar), «Reel» y «Story» (ambos «Automatic posting»), cada uno con icono y subtítulo |
| «+» | Lista para conectar más redes: X, Bluesky, Threads, LinkedIn, Google Business Profile, Pinterest, TikTok personal y business, YouTube. Las de pago llevan un diamante |
| Notes | Panel lateral «Post notes» con editor enriquecido (negrita, cursiva, listas, alineación, emoji) y botón Save. Notas internas, no se publican |
| Close | Cierra; si hay cambios pide confirmación «Your changes will be lost, are you sure?» (Cancel / Accept) |

### Editor

- Área de texto grande. Contador por red en la esquina: `0 / 16192` con el icono de Facebook; con Instagram aparecen `0 / 30 #` (hashtags) y `0 / 2200`.
- Con dos o más redes aparece el botón **«EDIT BY NETWORK»** encima del editor.
- Barra de iconos (orden observado, con Instagram activo aparece el de hashtags):

| Icono | Qué abre |
|---|---|
| Imagen | Menú: Add image, Add video, Adobe Express, Google Drive, Canva, Stock images ›, Stock videos ›, GIFs gallery › |
| Emoji | Selector con buscador, «Frequently used» y categorías |
| `#` (solo con Instagram/TikTok) | «Hashtag searcher»: lista de hashtags populares con su número de usos; los ya añadidos salen en gris. La búsqueda es de pago |
| Bocadillo | Modal **«Add first comment»**: texto del primer comentario, con emoji y textos guardados, contador, Cancel/Accept |
| Ubicación | «Add location» con buscador. Aviso: solo Facebook e Instagram, y en Instagram exige el modo de publicación automática |
| Enlace | **«URL Generator for campaigns»** (UTM): URL de la web*, Source*, Medium*, Name of the campaign*, Search term, Content. Botones Clean / Add |
| Varita | Modal **«Text generator with AI»** con créditos («5 of 5»): tema, tono (p. ej. Positive), idioma, red para optimizar y «Generate text» |
| Marcador | Textos o hashtags guardados (no llegó a abrirse en la observación; ver fuentes públicas §1.10) |

### Paneles bajo el editor

- **Global presets** (acordeón): «Use URL shortener» (interruptor con ayuda) y **SmartLinks** (añadir el post a un SmartLink). Con Instagram aparece además **«Auto publish»** (interruptor) en la cabecera del panel.
- **Instagram presets** (acordeón, uno por red activa): colaboradores («Add collaborator») y «AI-generated content».
- **Panel de errores** (rojo, plegable): lista agregada con el icono de cada red, p. ej. «Post → Content length should be at least 1 character or 1 media file», «Add at least 1 image or video», «Publish date can't be a past date». Se recalcula mientras se edita.

### Barra inferior

- **Cancel** a la izquierda.
- **Fecha y hora**: selector propio con hora (12 h, AM/PM), zona horaria de la marca (Europe/Madrid) y calendario mensual; los días pasados están deshabilitados.
- **Schedule** (deshabilitado mientras hay errores) + desplegable con:
  - **Save as draft**: guardar y publicar más tarde;
  - **Save in posts library** (pago): guardar para reutilizar;
  - **Send to review** (pago): elegir revisores;
  - **Schedule**: guardar y programar (opción por defecto, marcada);
  - **Publish now**: publicar con la fecha y hora actuales;
  - **Recurring schedule** (pago): recurrencia o duplicar en otras marcas.

### Previsualización

- Columna derecha con un icono por red seleccionada para cambiar de vista, y conmutador móvil / escritorio.
- Maqueta de la red (Facebook: avatar, nombre, fecha, botones Me gusta / Comentar / Compartir).
- Aviso fijo: las previsualizaciones son aproximadas.

## Resto de la aplicación (recorrido por secciones)

| Sección | Observado |
|---|---|
| Barra superior | Analytics, Reporting, Inbox, Planning, SmartLinks, Ads; «Upgrade your plan», selector de marca, notificaciones y menú |
| Planning · Calendar | Semana por horas con tarjetas (iconos de red, hora, texto recortado, tipo). Fondo con intensidad por **mejores horas** de la red elegida (porcentajes por hora). Buscador, «This week», rango de fechas, filtros, menú, «Best times» por red, vista de cuadrícula, «Create view» (pago) y «Create post». Zona horaria visible arriba a la derecha. Aviso de cupo: «3 out of your 20 available posts» |
| Planning · pestañas | Calendar, List, Posts library (pago), Autolists, Deleted posts, Flows (nuevo, pago) |
| Deleted posts | Tabla Fecha / Contenido / Redes / Estado / Fecha de borrado / Borrado por; estado vacío con icono |
| Autolists | Presentación con tres tarjetas: llenado desde RSS, modo circular (repetir al acabar), franjas semanales fijas. Botón «New autolist» |
| Flows | Beta de pago: respuestas automáticas a comentarios y DMs por palabra clave, secuencias de mensajes y métricas por flujo |
| Analytics | Menú lateral por red (Summary, Facebook, Instagram, TikTok, YouTube, LinkedIn, «More connections») y enlaces a Reporting, Reports, Hashtag Tracker y Brand settings. Por red: pestañas (IG: Community, Demographics, Account, Posts, Reels, Competitors; FB: Page overview, Posts, Reels, Competitors), periodo principal y de comparación, KPIs en tarjetas de color, gráfico de crecimiento y avisos de métricas que ha cambiado Meta |
| Reporting | Informes, «cross-brand analytics», Campaign dashboards, Metricool Studio y conexión con Looker Studio |
| Inbox | Lista de conversaciones con filtro por red (FB/IG) y panel «Please select a conversation» |
| SmartLinks | Microsite «link in bio» con botones personalizables y analítica de visitas y clics |
| Ads | Meta Ads, Google Ads y TikTok Ads con «Connect» |

Patrón común: cada módulo de pago muestra una página de presentación con tres tarjetas y un CTA de mejora de plan; en el plan gratuito aparece además un tutorial flotante («Planner – The basics»).

## Comparación con el compositor actual de Social Scheduler

| Metricool | Social Scheduler hoy (`resources/js/Pages/Posts/Compose.vue`) |
|---|---|
| Diálogo a pantalla completa con previsualización por red y móvil/escritorio | Página con formulario en tarjetas y una previsualización genérica |
| Selección de redes con iconos en la cabecera y tipo en desplegable | Lista de cuentas con casilla y `<select>` de tipo |
| Editor grande con barra de iconos y contadores por red (caracteres y hashtags) | `textarea` con contador total de caracteres |
| «Edit by network» | Un único texto para todas las redes |
| Primer comentario, ubicación, UTM, hashtags, textos guardados, emoji | Sin equivalentes en el editor (IA sí, con `AiSuggest`) |
| Global presets (acortador, SmartLinks, auto publish) y presets por red | Opciones por red sueltas (reel al feed, enlace, YouTube, TikTok) |
| Panel único de errores con icono de red, en vivo | Errores por cuenta en vivo (validación ya existe) |
| Barra inferior: Cancel, fecha con zona, Schedule + menú (borrador, biblioteca, revisión, publicar ahora, recurrente) | Fecha `datetime-local`, «Programar», «Guardar borrador» y biblioteca en el lateral |
| Notas internas del post | No hay |
| Mejores horas en el calendario | Existe en analítica (`GetBestTimes`), no en el calendario ni el compositor |

Estas diferencias son la entrada para la spec del nuevo compositor (ciclo completo).
