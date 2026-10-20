# Calendario

Vista semana/mes y lista de publicaciones por marca, con el estado por red.

| Pieza | Ruta |
|---|---|
| Servicio | `app/Services/CalendarService.php` (`build(User, filtros)`) |
| Controlador | `app/Http/Controllers/CalendarController.php` |
| Vista | `resources/js/Pages/Calendar/Index.vue` |
| Rutas | `GET calendar`, `PATCH posts/{post}/reschedule` |

`CalendarService::build()` calcula el rango según la vista (`range()`), desplaza el ancla (`shift()`) y serializa cada publicación (`item()`) en la zona horaria de la marca. Se puede filtrar por red.

Reprogramar arrastrando solo se permite mientras el destino sea `borrador` o `programada` (`PostStatus::isPending()`). Las herramientas MCP `get_calendar` y `reschedule_post` usan la misma capa de servicios.

Mejores horas, papelera y autolistas: ver [planificacion-avanzada.md](planificacion-avanzada.md).
