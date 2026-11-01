# Audio por red: capacidades y límites comprobados

Fecha de consulta: 2026-10-31. Se distinguen capacidades implementadas, contratos no verificados y funciones de la app nativa. Los smokes reales siguen pendientes.

| Red | Vídeo con sonido incluido | Nueva opción en nuestra conexión | Límite o diferencia |
|---|---|---|---|
| Instagram | Conservado en formatos de vídeo admitidos | Catálogo Meta para Reels: búsqueda/preescucha/selección y volúmenes | No fotos ni Stories; requiere acceso elegible. No garantiza reconocimiento automático. |
| Facebook | Conservado en vídeo/Reel admitido | Información del audio del vídeo | Hay recomendaciones oficiales de canciones, pero no se ha confirmado un contrato para asociarlas al publicar. No hay selector en el Reel de esta cuenta de Metricool inspeccionado. |
| TikTok | Conservado en vídeos | Solicitar música recomendada para fotos/carruseles Direct Post | TikTok elige la canción. Selección concreta Top 100 de cuentas Business en Metricool requiere una integración Business distinta de nuestra Content Posting API; no está implementada aquí. |
| YouTube | Conservado en vídeo/Short | Información del audio del vídeo | `videos.insert` documenta subida del archivo y metadatos, sin selección de una canción de Shorts. |
| Bluesky | Conservado en vídeo admitido | Información del audio del vídeo | El contrato oficial de `app.bsky.embed.video` contiene el blob MP4, subtítulos y metadatos visuales; no selector musical. |
| Threads | Conservado en vídeo/carrusel admitido | Información del audio del vídeo | El contrato de publicación consultado usa `video_url` y opciones del post, sin selector de catálogo musical. |

Las capacidades `audio.*` de `list_accounts` describen estas conexiones y los formatos ofrecidos, no las funciones completas de las apps nativas. Una red deshabilitada no anuncia tipos de audio utilizables. Las fotos de TikTok requieren dominio verificado y la opción automática exige publicación directa.

## Fuentes primarias

- [Instagram Audio API](https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api/): HTTP directo 200, 14.697 bytes, antes de implementar. Catálogo, metadata, configuración de asociación y volúmenes.
- [Facebook Reels Publishing](https://developers.facebook.com/docs/video-api/guides/reels-publishing/): HTTP directo 200, 25.435 bytes. Enlaza [Music Recommendations](https://developers.facebook.com/documentation/video-api/guides/music-recommendations/) (200, 2.885 bytes): títulos/artistas/arte recomendados; esa respuesta no incluye una configuración de asociación de pista. No se convierte esta ausencia en la afirmación de que ninguna API de Facebook pueda hacerlo.
- [TikTok Photo Content Posting](https://developers.tiktok.com/docs/en/content-posting-api-reference-photo-post): `post_info.auto_add_music` añade música recomendada solo con Direct Post. [Metricool TikTok music](https://help.metricool.com/how-to-add-music-to-your-tiktok-posts-from-metricool-kap4e) distingue música concreta Business de selección automática para fotos.
- [TikTok API for Business — Discovery](https://business-api.tiktok.com/gateway/docs/index?doc_id=1825119005427714&language=ENGLISH): descubrimiento de pistas comerciales es una integración con acceso y autorización distintos; no acredita que nuestro OAuth Content Posting pueda elegirlas.
- [YouTube videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert): propiedades de creación y subida de vídeo.
- [Lexicon oficial Bluesky video](https://github.com/bluesky-social/social-app/blob/main/lexicons/app/bsky/embed/video.json): contrato del embed de vídeo; no se cambian límites de tamaño/duración de nuestra aplicación en esta entrega.
- [Threads posts](https://developers.facebook.com/docs/threads/posts/): HTTP directo 200, 21.013 bytes; tipos y parámetros de publicación.

## Comprobación de Metricool Facebook

Se abrió en la sesión existente de Edge el Reel de Facebook del 27 de octubre a las 12:00, enlace publicado `https://facebook.com/reel/1124902503575588`. El panel **Facebook presets** muestra **Title**, sin **Add audio**. No se modificó el formulario ni se guardó/publicó nada. Es evidencia de esa cuenta/post y vista, no un inventario universal de todas las variantes de Metricool.

Las copias completas de páginas oficiales quedan fuera del repositorio, en `storage/framework/testing/`, como material temporal de investigación. No se conservan cookies, claves ni consultas de API con credenciales.
