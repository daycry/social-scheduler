# Diagnóstico: búsquedas de Aipocalipsis y Libre

Fecha: 2026-11-01. Solicitud: el usuario no encuentra «Libre» de Aipocalipsis y otras canciones. Investigación con `custom-agents:debug-root-cause`; no se publica contenido ni se modifican conexiones o credenciales.

## Reproducción real

Consultas GET directas a `ig_audio`, usando el User token cifrado de la cuenta Instagram ya conectada `marblepartyrace` (ID local 2). El token se envía en Authorization y no se imprime ni se incorpora a esta evidencia. No se presupone que sea la misma conexión usada en Metricool.

| search_query | audio_type | Audios recibidos | ¿Libre de Aipocalipsis? |
|---|---|---:|---|
| `aipocalipsis` | `music` | 2 | No |
| `aipocalipsis` | `original_sound` | 10 | No |
| `Libre` | `music` | 10 | No |
| `Libre` | `original_sound` | 10 | No |
| `aipocalipsis libre` | `music` | 0 | No |
| `aipocalipsis libre` | `original_sound` | 0 | No |

Las seis respuestas contienen únicamente la clave superior `audio`: no incluyen `paging`, cursores ni URL de página siguiente. Todos los `audio_id` recibidos son cadenas válidas para nuestra normalización: ninguno se descarta por forma. Los dos resultados musicales para `aipocalipsis` son «Evidence» (Dhruv Goel) y «Apocalyptic Apathy» (Austin Woodward & Zack Ledbetter). Buscar `Libre` devuelve coincidencias aproximadas ajenas a la pista, por ejemplo «Quirky Bossa (Vocals)» (Scott Dugdale); el proveedor no asegura coincidencia exacta con el título solicitado.

Primera ejecución en sandbox: fallo de red `TransientPublishException`, código interno `red`; no se cuenta como respuesta de catálogo. La repetición con acceso de red del host completó las seis consultas anteriores correctamente. El helper local está bajo `storage/framework/testing/roadmap-continuation/inspect-instagram-catalog.php` (ignorado por Git).

## Aislamiento e hipótesis

Hipótesis comprobada: «La pista falta porque el compositor recorta o filtra los resultados de Meta». La consulta directa, que evita controlador, MCP y Vue, tampoco devuelve la pista. Se descarta esa causa para estas consultas y esta cuenta. `InstagramAudio::search` consulta `music` y `original_sound` cuando el filtro es Todos; normaliza y combina los resultados sin filtrar por título/artista. La interfaz muestra la lista recibida sin un límite adicional de filas.

No hay evidencia de páginas omitidas en estas respuestas. No se añaden parámetros de paginación no documentados ni consultas repetidas para intentar ampliar un catálogo cuya respuesta no ofrece continuación.

Conclusión acotada: **la pista no está entre los resultados que entrega Meta a esta conexión**. No demuestra que falte en Instagram nativo, en otra cuenta, en Metricool o que exista una prohibición de licencia específica. El API no devuelve el motivo de ausencia; quedan sin distinguir restricciones del catálogo e indexación/ranking de búsqueda.

## Contraste y siguiente dato necesario

La [documentación de Meta](https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api/) indica que el catálogo está autorizado para uso mediante terceros y puede diferir del de la aplicación nativa. La copia oficial obtenida el 2026-10-31 está en `storage/framework/testing/meta-audio-doc.html`; el lector web volvió a responder 429 en esta consulta. El [centro de ayuda de Metricool](https://help.metricool.com/es/como-anadir-audio-a-tus-instagram-reels-desde-metricool-2o2ks), consultado el 2026-11-01, confirma la misma limitación y propone añadir el audio dentro de Instagram cuando no está en su biblioteca.

Se preguntó al usuario si la pista aparece en Metricool o en Instagram y se solicitó su enlace de audio para identificarla sin confundir versiones. Ese dato permite contrastar la pista exacta y, si hay un ID válido para el API, consultar sus metadatos; no garantiza elegibilidad para asociarla.

Control adicional: el identificador público `1436876720937897` del antiguo ejemplo «Botón automático» tampoco pudo recuperarse mediante `InstagramAudio::get` con esta cuenta (`InvalidArgumentException`). No es el identificador de «Libre» y no se usa para concluir nada sobre esa canción. Un ID de página pública no se trata automáticamente como ID utilizable del catálogo.

Esta primera investigación no cambió código de producción: las seis consultas concretas carecían de cursor. Sus conclusiones no acreditaban el funcionamiento general de la búsqueda. La verificación inicial de fuentes corresponde al código anterior a T-10; T-06/CA-10 siguen abiertos para la publicación real.

## Revisión ampliada solicitada por el usuario

El usuario precisó que el problema era el buscador, incluida su posible paginación. La consulta de control `Dhruv Goel` sí devolvió `paging.cursors.after` sin `paging.next`: 25 pistas en la primera página y 25 distintas en la segunda. El servicio descartaba ese cursor. Se confirmó un defecto de nuestra aplicación y se corrigió en web/MCP. La prueba del servicio corregido recuperó 50 pistas distintas usando su cursor cifrado.

También se reprodujo que escribir texto sin pulsar Enter/Buscar dejaba visibles las tendencias o resultados de la consulta anterior. Se añade búsqueda al escribir, con invalidación inmediata de respuestas atrasadas. [Corrección, pruebas y evidencia actual](search-pagination/report.md). La conclusión inicial sobre las consultas de Aipocalipsis/Libre se conserva como dato concreto; ya no se usa para descartar defectos del buscador.
