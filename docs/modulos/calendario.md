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

Mejores horas, papelera y autolistas: ver [planificacion-avanzada.md](planificacion-avanzada.md).
