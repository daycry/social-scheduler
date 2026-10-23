# Planificación avanzada: mejores horas, biblioteca, papelera y autolistas

Herramientas de planificación sobre el calendario. Todo se calcula por usuario y marca (`UserScope`) y usa `PostService` para crear o mover publicaciones.

| Pieza | Ruta |
|---|---|
| Mejores horas | `app/Services/BestTimesService.php`, `BestTimesController`, `resources/js/Pages/BestTimes/Index.vue` |
| Biblioteca de plantillas | `app/Services/LibraryService.php`, `LibraryController`, `app/Models/PostTemplate.php` |
| Papelera | `app/Services/TrashService.php`, `TrashController`, `php artisan posts:purge-trash` |
| Autolistas | `app/Services/AutolistService.php`, `app/Jobs/GenerateAutolistPosts.php`, `app/Models/Autolist*.php` |

## Mejores horas
Mapa de calor 7×24 por red con el engagement medio de las publicaciones propias, en la zona horaria de la marca. Usa la **última** lectura de cada destino (`MAX(date)` resuelto en SQL, una fila por destino) y una caché corta (`SOCIAL_BEST_TIMES_CACHE_MINUTES`). Con menos de `SOCIAL_BEST_TIMES_MIN_POSTS` publicaciones con datos responde «no disponible» en lugar de un mapa engañoso. El MCP lo expone como `get_best_times`.

## Papelera
Borrar una publicación es un *soft delete*. Al enviarla a la papelera, los destinos `programada` pasan a `borrador` y un `procesando` huérfano se cierra como fallido: **nada pendiente sobrevive** en la papelera. Restaurar devuelve un borrador (se reprograma a mano) y se hace en una sola transacción. El despachador, la recuperación (`PublishRecovery`) y el repoll solo consideran publicaciones vivas, aíslan cada destino con `try/catch` (un fallo no corta la pasada de todos) y las transiciones usan `withTrashed()` donde operan sobre destinos ya existentes. `posts:purge-trash` elimina lo que lleva más de 30 días y se salta lo que esté en vuelo.

## Autolistas
Cola de contenido (plantillas o texto + media) con franjas semanales (día ISO + hora local de la marca). `generate()` crea publicaciones programadas de los próximos `SOCIAL_AUTOLISTS_HORIZON_DAYS` días y es idempotente:

- La franja se identifica por **fecha y hora locales** (`autolist_slots.local_date` / `local_time`, única por lista) además del instante UTC: si cambias la zona horaria de la marca, las franjas futuras sin publicar se realinean al nuevo instante en vez de duplicarse.
- El cursor apunta al **último elemento usado** (`last_position` + `last_item_id`), no a un índice: borrar elementos no desplaza la cola. Un elemento cuya plantilla se borró y no tiene texto ni media propios se elimina de la cola.
- Si una cuenta de destino está en `requiere_reconexion` (causa temporal), la franja **no** se registra ni avanza el cursor (`deferred`); se reintenta en la siguiente generación mientras sea futura. Una media inválida o una cuenta desconectada sí omite la franja con motivo.
- El job programado solo reparte: lanza un `GenerateAutolistPosts` por lista activa.
