---
spec: metricool-composer-parity
descripcion: Audio por red en web y MCP: catálogo de Instagram, música recomendada para fotos TikTok y capacidades del resto.
estado: aprobada
creado: 2026-10-31
actualizado: 2026-11-01
evaluacion: no-solicitada
design: improvement-plan.md
plan: improvement-plan.md
---

# Audio por red desde web y MCP

El usuario autoriza implementar las opciones de audio de Metricool y concreta «creo que con eso ya sería suficiente», permitiendo mejoras relacionadas útiles. Después pide revisar Facebook y «cualquier red social de las que tenemos»: Instagram, Facebook, TikTok, YouTube, Bluesky y Threads. Esta entrega añade las capacidades de audio verificadas de nuestras conexiones y describe las limitaciones restantes. El [inventario amplio de funciones ajenas al audio queda como backlog](full-parity-backlog.md), incluido el conector nuevo de X; no se declara paridad completa.

## Contexto comprobado

El Reel de ejemplo ya tenía audio dentro de su vídeo adjunto en Metricool; no había pista seleccionada en el campo de audio del post. Instagram mostraba una canción identificada. Los hechos y la inferencia de reconocimiento están separados en [discovery.md](discovery.md). La nueva capacidad permite además elegir expresamente una pista nativa antes de publicar.

## Dentro

- Reels de Instagram de cuentas profesionales elegibles conectadas mediante Facebook, aprovechando el User token que conserva el conector actual.
- Buscar música y audio original en el catálogo autorizado para terceros; filtro Todos/Música/Audio original, tendencias sin texto, título/artista/duración y preescucha cuando Meta la devuelve.
- Seleccionar, cambiar y quitar audio; guardar y reabrir la misma selección por destino.
- Volumen de la pista y del vídeo original entre 1 y 100, mejora relacionada soportada por el contrato oficial.
- Web y MCP con los mismos datos y validación. El agente puede buscar, consultar, elegir, crear/editar y recuperar el post sin completar la selección en la web.
- Publicar `audio_configuration` en el contenedor REELS, revalidando disponibilidad de pista antes de crearlo; mantener errores y checkpoints, sin publicación silenciosa sin audio ni repetición al sondear.
- Mensajes claros para cuenta no elegible, falta de credenciales, búsqueda fallida, pista retirada y ausencia de preescucha.
- TikTok: opción explícita `auto_add_music` para fotos/carruseles con publicación directa; TikTok elige la música recomendada. No se recuerda entre posts, no se acepta para vídeos ni envíos a la bandeja.
- Las seis redes anuncian en web/MCP los formatos que conservan audio incluido en el vídeo, el selector de catálogo disponible y la música recomendada de fotos cuando corresponda. Comprobar los contratos de cada red por separado; no tratar una recomendación musical como una API de asociación de pista.

## Fuera de esta entrega

- Inventar un selector nativo para un contrato que no lo expone o no se ha podido verificar. La guía oficial de Facebook tiene recomendaciones musicales, pero no se ha confirmado un contrato de asociación al publicar; el Reel de Facebook inspeccionado en esta cuenta de Metricool no muestra selector de audio. La selección concreta de catálogo de TikTok Business utiliza una integración distinta de nuestra Content Posting API: no se da por implementada por añadir `auto_add_music`.
- Música nativa para fotos/carruseles/Stories de Instagram. Para YouTube, Bluesky y Threads los contratos de publicación consultados admiten el vídeo, sin selector de catálogo de canciones en esta integración.
- Garantizar el reconocimiento automático del sonido que ya lleva un vídeo.
- Recortar/desplazar una pista o previsualizar su mezcla final con el vídeo; descargar música para mezclarla como sustituto de la asociación nativa.
- Resto de funciones del inventario amplio. Claude Desktop y las pruebas del VPS continúan aplazados o a cargo del usuario, según las decisiones existentes.

## Datos y restricciones

`targets[].options.instagram_audio`: `null` o un objeto con `audio_id` (cadena numérica del catálogo), `audio_volume` y `video_volume` (enteros opcionales 1–100, por defecto 100). No aceptar nombres, URLs de Reel ni URLs de preescucha como identificadores. No persistir URLs temporales ni secretos en opciones, logs o auditoría.

El catálogo usa una cuenta explícita del usuario. Web comprueba también la marca. MCP exige `brands:read` para consultar; crear/editar conserva `posts:write`. No consultar cuentas de terceros ni seguir URLs de paginación arbitrarias. Las URLs de preescucha y arte deben ser HTTPS del proveedor, sin credenciales ni token de acceso.

TikTok: `targets[].options.auto_add_music` es un booleano opcional (omitido/desactivado = sin solicitud de música recomendada). No se pasa como opción de vídeo ni de otra red. `list_accounts` anuncia `audio.embedded_video_types`, `catalog_types`, `recommended_music_types` y una explicación de esta conexión; no son promesas sobre todas las funciones de la aplicación nativa.

## Criterios de aceptación

- [x] CA-01 — Buscar por texto o tendencias, filtrar y mostrar resultados normalizados; error no equivale a catálogo vacío.
- [x] CA-02 — Preescuchar una pista cuando hay URL; mostrar alternativa o ausencia cuando no existe; no reproducir automáticamente.
- [x] CA-03 — Seleccionar/cambiar/quitar, ajustar ambos volúmenes y guardar/reabrir conserva los valores exactos por destino; cambiar a otro formato no arrastra audio incompatible.
- [x] CA-04 — Web y MCP rechazan forma inválida, formatos incompatibles y cuentas no elegibles; mantienen vídeos con audio incrustado sin exigir selección de catálogo.
- [x] CA-05 — MCP anuncia capacidades y herramientas de búsqueda/consulta y permite crear, editar y leer la selección con las mismas reglas.
- [x] CA-06 — La creación del contenedor envía el JSON oficial, comprueba la pista y conserva checkpoint; el sondeo no vuelve a crear el contenedor ni a asociar audio.
- [x] CA-07 — Una pista rechazada no produce envío sin audio; un fallo temporal mantiene su semántica de reintento.
- [x] CA-08 — Propiedad de usuario/marca y abilities impiden consultas ajenas; las respuestas no filtran tokens ni URLs peligrosas.
- [x] CA-09 — Pruebas focales y de regresión, build, revisión independiente y QA local con evidencias; no presentar mocks como publicación real.
- [ ] CA-10 — Smoke real con cuenta/pista propia y comprobación del resultado final. Queda a cargo del usuario y explícitamente pendiente hasta recibir evidencia.
- [x] CA-11 — Las seis redes anuncian capacidades y explicación coherentes con sus formatos ofrecidos, sin prometer selección universal de catálogo; web y MCP comparten la información.
- [x] CA-12 — TikTok conserva la elección de música recomendada por web/MCP, la envía como `post_info.auto_add_music` solo para fotos Direct Post y la limpia al cambiar a vídeo; valores incompatibles se rechazan.
- [x] CA-13 — El buscador conserva cursores de Meta aunque no haya `paging.next`, permite continuar por web/MCP sin reiniciar tipos agotados y carga más pistas sin perder ni duplicar las anteriores. El cursor está ligado a cuenta, texto, filtro y caducidad. Escribir actualiza la búsqueda con espera breve; texto/filtro/cierre invalidan resultados atrasados. Evidencia de seguimiento en T-10.

## Ampliación visual autorizada (2026-11-01)

El usuario pide una estética más parecida a Metricool, con acabado cuidado en Global presets, Facebook presets, Instagram presets y las demás redes. Se organiza la programación/notas en Ajustes globales y las opciones actuales en paneles por red, con cabeceras, iconos, cuenta, tipo, plegado accesible y controles coherentes. Se conservan valores y lógica de publicación; no se amplía la búsqueda de música, que el usuario dejó como estaba. T-11 es el ledger de esta entrega.

- [x] CA-14 — Paneles de ajustes globales y por red cuidados, plegables con teclado, sin perder valores; navegación por errores abre el panel afectado. Sin cambios de opciones, payload ni permisos. Diseño verificado en escritorio y móviles, guardado/reapertura, axe y regresión de presets/audio.

## Referencias

- [Contrato oficial de Meta](https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api/), obtenido con HTTP 200 el 2026-10-31 desde el host, tras el HTTP 429 del lector web.
- [Función de referencia en Metricool](https://help.metricool.com/es/como-anadir-audio-a-tus-instagram-reels-desde-metricool-2o2ks).
- [Plan](improvement-plan.md), [ledger](tasks.md), [matriz inicial/backlog](feature-matrix.md).

No se ha solicitado presupuesto. Las pruebas de API real no están autorizadas como publicaciones automáticas durante el desarrollo.

Evidencia local de los criterios marcados: [informe de QA](testing/report.md). CA-10 continúa pendiente del usuario.

CA-13 y la regresión actual: [corrección del buscador, QA y evidencia real](testing/search-pagination/report.md).

## Opciones adicionales autorizadas (T-12, 2026-11-01)

Tras observar la pestaña real de Metricool, el usuario pide colaboradores, contenido generado con IA y una zona de errores equivalente. Se añaden colaboradores e indicación de IA por destino de Instagram (imagen, Reel y carrusel), título independiente para vídeo/Reel de Facebook y errores plegables con contador, icono y enlaces de corrección. Web y MCP comparten validación. No se retoma la investigación del catálogo musical.

- [x] CA-15 · Colaboradores normalizados y distintos, máximo 3 según el contrato de Meta; IA booleana por publicación, no preferencia recordada. Persistencia y limpieza por formato; ambos campos solo en el contenedor padre de carrusel. Título de Facebook máximo 255, enviado a videos o al finish del Reel. Errores plegables y enlaces accesibles. Inventario visible con implementado/pendiente, pruebas web/MCP/publicador/UI y revisión independiente.

Referencias verificadas el 2026-11-01: [contenedores de Instagram](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media/), [changelog de IA](https://developers.facebook.com/docs/instagram-platform/changelog/), [Reels de Facebook](https://developers.facebook.com/docs/video-api/guides/reels-publishing/). La ayuda de Metricool anuncia hasta cinco colaboradores; esta integración respeta los tres documentados por Meta para este endpoint. [Inventario y uso](../../guias/ajustes-compositor.md), [QA de T-11/T-12](testing/presets-ui/report.md).
