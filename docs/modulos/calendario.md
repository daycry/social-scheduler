# Calendario

Vista semana/mes y lista de publicaciones por marca, con el estado por red.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/CalendarService.php` (`build(User, filtros)`) |
| Controlador | `app/Http/Controllers/CalendarController.php` |
| Vista | `resources/js/Pages/Calendar/Index.vue` |
| Rutas | `GET calendar`, `PATCH posts/{post}/reschedule`, `POST posts/{post}/publish-now` |

`CalendarService::build()` calcula el rango según la vista (`range()`), desplaza el ancla (`shift()`) y serializa cada publicación (`item()`) en la zona horaria de la marca. Se puede filtrar por red.

Reprogramar arrastrando solo se permite mientras el destino sea `borrador` o `programada` (`PostStatus::isPending()`). Las herramientas MCP `get_calendar` y `reschedule_post` usan la misma capa de servicios.

**Publicar ahora.** Cada publicación con `can_publish_now` (borrador o programada, con destinos y sin nada en vuelo ni publicado) ofrece «Publicar ahora» en la semana, el mes y la lista. Pide confirmación en un diálogo accesible (foco en Cancelar, Escape cancela) y envía `POST posts/{post}/publish-now`, que usa `PostService::publishNow()` con las mismas defensas contra el doble despacho que el compositor (ver [publicación](publicacion.md)). La tarjeta muestra «Enviando…» y el calendario se refresca cada pocos segundos hasta que el destino deja de estar `programada`. La herramienta MCP equivalente es `publish_post_now`.

**Indicador de nota (CA-28).** `CalendarService::item()` envía `has_notes` (verdadero si las notas internas del post no están vacías) y la semana, el mes y la lista muestran junto a la publicación una insignia con el texto accesible «Nota» (el icono es decorativo; el `title` dice «Esta publicación tiene notas internas»). El contenido de las notas no viaja al calendario, solo el indicador.

## Cuadrícula de Instagram (C-02)

Vista de solo lectura del perfil de una cuenta de Instagram: `GET instagram/grid` (`instagram.grid`, `?brand_id=&account_id=`), `InstagramGridController`, `GridService` y `Pages/Instagram/Grid.vue`; la entrada «Cuadrícula» está en el módulo Planificación de `resources/js/navigation.js`. Un `account_id` de otro usuario responde 404; una cuenta propia de otra marca cae a la primera de la marca actual.

- **Orden.** `GridService::build()` pone arriba los destinos `programada` de la cuenta (la fecha más tardía primero) y debajo lo publicado (el orden de Graph, el más reciente primero). Las Stories no entran y un Reel solo con `options.share_to_feed` distinto de `false`. La miniatura de lo programado es `MediaAsset::publicUrl()` de la primera media; un vídeo sin miniatura usa `<video preload="metadata">`.
- **Lectura de lo publicado.** `Connectors\Meta\Instagram\InstagramGrid::read()` llama a `GET /{ig}/media` (campos `media_type`, `media_url`, `thumbnail_url`, `permalink`, `timestamp` y `children`) con `limit=24` y hasta 3 páginas, por `GraphClient` (solo `instagram_basic`, sin permisos nuevos). En un carrusel se toma su primera pieza. Un error de Graph (`ConnectorException`) no se lanza a la página: vuelve como `error`, conserva lo leído y se muestra un aviso.
- **Caché y consumo de Graph.** Lo publicado se guarda en la caché de Laravel `ig-grid:{cuenta}` durante 10 minutos (`GridService::CACHE_MINUTES`) y **no se persiste**. Un fallo no se cachea. Lo programado sale siempre de la base de datos. Consumo: como máximo 3 peticiones a Graph por cuenta y por ventana de 10 minutos, y solo si alguien abre la cuadrícula; sin visitas no hay lecturas.
- **Límites.** Las URLs `media_url`/`thumbnail_url` son del CDN de Meta y **caducan**, por eso no se guardan y la caché es corta; los posts fijados del perfil no se reflejan; las teselas se recortan en vertical (3:4) y pueden diferir del recorte del perfil; una publicación recién hecha tarda hasta 10 minutos en aparecer.

Mejores horas, papelera y autolistas: ver [planificacion-avanzada.md](planificacion-avanzada.md).
