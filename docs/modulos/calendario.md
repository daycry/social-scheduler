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

**Vistas guardadas (C-03a).** El menú «Vistas» guarda los filtros actuales (marca, red, estado y vista semana/mes/lista; un solo valor cada uno) con un nombre, y permite aplicarlas, renombrarlas, borrarlas y fijar una **por defecto** (como mucho una por usuario). Piezas: `CalendarView` (tabla `calendar_views`, `UserScope`, nombre único por usuario), `CalendarViewService`, `CalendarViewController` y `CalendarViewRequest` (la marca debe ser del usuario; red, estado y vista, valores conocidos), `resources/js/Components/Calendar/ViewsMenu.vue`. Rutas: `POST calendar/views`, `PUT|DELETE calendar/views/{view}`. En la web una vista se aplica con `GET calendar?saved_view=<id>` (el parámetro `view` ya es semana/mes/lista): sus filtros mandan sobre los de la URL y la fecha se conserva; la vista por defecto se abre cuando no hay filtros elegidos: la marca (`brand_id`) y la vista (`view`) NO cuentan como filtros —los enlaces del menú los llevan siempre—, y mandan sobre los de la vista por defecto; sí cuentan red, estado, búsqueda y fecha. Como mucho 50 vistas por usuario y las rutas de vistas llevan throttle (60/min). Una marca, red o estado que ya no existen se descartan al aplicar (la vista nunca rompe el calendario) y la vista de otro usuario responde 404. Al borrar la vista abierta se vuelve al calendario sin ella. MCP: `list_calendar_views` (`posts:read`) y `get_calendar` con `view=<id>` (además de week|month|list) devuelven lo mismo que la web; una vista ajena responde como inexistente.

**Indicador de nota (CA-28).** `CalendarService::item()` envía `has_notes` (verdadero si las notas internas del post no están vacías) y la semana, el mes y la lista muestran junto a la publicación una insignia con el texto accesible «Nota» (el icono es decorativo; el `title` dice «Esta publicación tiene notas internas»). El contenido de las notas no viaja al calendario, solo el indicador.

## Cuadrícula de Instagram (C-02)

Vista de solo lectura del perfil de una cuenta de Instagram: `GET instagram/grid` (`instagram.grid`, `?brand_id=&account_id=`), `InstagramGridController`, `GridService` y `Pages/Instagram/Grid.vue`; la entrada «Cuadrícula» está en el módulo Planificación de `resources/js/navigation.js`. Un `account_id` de otro usuario responde 404; una cuenta propia de otra marca cae a la primera de la marca actual.

- **Orden.** `GridService::build()` pone arriba los destinos `programada` de la cuenta (la fecha más tardía primero) y debajo lo publicado (el orden de Graph, el más reciente primero). Las Stories no entran y un Reel solo con `options.share_to_feed` distinto de `false`. La miniatura de lo programado es `MediaAsset::publicUrl()` de la primera media; un vídeo sin miniatura usa `<video preload="metadata">`.
- **Lectura de lo publicado.** `Connectors\Meta\Instagram\InstagramGrid::read()` llama a `GET /{ig}/media` (campos `media_type`, `media_url`, `thumbnail_url`, `permalink`, `timestamp` y `children`) con `limit=24` y hasta 3 páginas, por `GraphClient` (solo `instagram_basic`, sin permisos nuevos). En un carrusel se toma su primera pieza. Un error de Graph (`ConnectorException`) no se lanza a la página: vuelve como `error`, conserva lo leído y se muestra un aviso.
- **Caché y consumo de Graph.** Lo publicado se guarda en la caché de Laravel `ig-grid:{cuenta}` durante 10 minutos (`config/social.php` `instagram.grid_cache_minutes`) y **no se persiste**; se piden hasta 30 elementos (`instagram.grid_published`) y se descartan los Reels con «compartir en el feed» desactivado (`is_shared_to_feed=false`). Un fallo de Graph se recuerda 2 minutos (`instagram.grid_error_cache_minutes`) para no repetir llamadas lentas en cada visita; sus mensajes hablan de leer, no de publicar. Los destinos en `publicando`/`procesando` siguen apareciendo, y el selector solo ofrece cuentas activas. Lo programado sale siempre de la base de datos. Consumo: como máximo 3 peticiones a Graph por cuenta y por ventana de 10 minutos, y solo si alguien abre la cuadrícula; sin visitas no hay lecturas.
- **Límites.** Las URLs `media_url`/`thumbnail_url` son del CDN de Meta y **caducan**, por eso no se guardan y la caché es corta; los posts fijados del perfil no se reflejan; las teselas se recortan en vertical (3:4) y pueden diferir del recorte del perfil; una publicación recién hecha tarda hasta 10 minutos en aparecer.

Mejores horas, papelera y autolistas: ver [planificacion-avanzada.md](planificacion-avanzada.md).
