# T-11: recursos privados de audio

2026-11-05. Subbloque de recursos web/API/MCP verificado sobre el trabajo sin commit de `feature/creative-social-suite`, base `94e17d7`. T-11 permanece en progreso y el total global sigue en7/23. La interfaz de pistas, mezcla, exportación MP4/GIF y el smoke real del VPS/R2 continúan pendientes.

## Resultado disponible

La marca inicia un ticket de subida con nombre, MIME y tamaño. Los bytes se transfieren por PUT local o URLs S3/R2 prefirmadas, con modalidad single o multipart. No se reciben archivos mediante JSON base64 ni URLs arbitrarias. Se valida tamaño exacto y se crea una copia privada, sin capacidad de subida, antes de sondearla y declararla lista. Una URL antigua no puede cambiar el archivo que utiliza un diseño.

El servicio conserva propiedad y registro de marca, ofrece listado paginado con búsqueda literal, consulta de estado y lectura privada por rangos. Web, API y `manage_studio_audio` comparten el servicio; el catálogo MCP contiene39herramientas. La consulta requiere `designs:read` y las mutaciones `designs:write`. Audio queda fuera del selector social y los18tipos de publicación lo rechazan como adjunto aislado.

Los límites públicos son100MiB y3600s. FFprobe recibe solo archivos propios locales y demuxers/protocolos cerrados. Las copias remotas usan trozos de1MiB. El bloque máximo de asignación nativa es64MiB; no se presenta como un techo total de RAM. Las consultas, lectura y limpieza remotas tienen tiempos acotados y no repiten peticiones de forma oculta. Los valores predeterminados de los consumidores anteriores de `MediaChunkReader` se mantienen.

## Evidencia

| Verificación | Resultado | Artefacto |
|---|---|---|
| Suite PHP completa | 2714/2714;16100aserciones;0fallos/errores/skips | [JUnit](raw/phpunit-full.xml) |
| Audio focal y recuperación | 39/39;266aserciones | [JUnit](raw/phpunit-audio.xml) |
| Recurso en navegador | 3/3; desktop,375px,360px en Chromium | [JSON real](raw/playwright-audio-resources.json) |
| Gate E2E | VERDE;0failed/flaky/skipped/interrupted | [Gate](raw/qa-gate.json) |
| OpenAPI | 0errores/0rompedores;3avisos opcionales previos | [Lint](raw/openapi-lint.json), [diff](raw/openapi-diff.json) |
| Integridad | 25fuentes revisadas sin cambios y9artefactos con SHA256 | [Manifiesto](manifest.json), [patch revisado](raw/reviewed.diff) |

Los formatos comprimidos se generaron con FFmpeg a partir de un tono propio y se sondearon realmente: MP3, M4A/AAC, ADTS/AAC, Ogg/Vorbis y Ogg/Opus; también WAV/PCM16kHz. Los tests verifican bytes inalterados, duración y códec. Los datasets que ya pasaban se registran como caracterización, sin inventar un RED.

La prueba E2E transfiere un WAV de un segundo por los endpoints reales del servidor desechable y verifica206, rango/bytes/MIME exactos y decodificación/play/pausa de HTMLAudio con la política normal de autoplay. El elemento nativo es instrumentación de prueba: **no acredita un control del editor ni mezcla de pistas**. La ejecución fue del orquestador tras la revisión independiente. No se afirma QA independiente ni aceptación audiovisual global.

## Revisiones y correcciones

Scope previo:128archivos dentro,0fuera/avisos/exclusiones. Primer manifiesto:23fuentes. Al acotar metadatos se declaró explícitamente `MediaChunkReader.php`; el segundo incluye24 y el tercero25 con la prueba adicional de aborto. La baseline de13piezas compartidas se reconstruyó contra HEAD/T-10 y el snapshot previo de T-11; `MediaChunkReader` añade una baseline HEAD. Selector C=false/D=true. Sin Jira ni promoción de conocimiento.

| Intento | Hallazgo | Corrección y prueba |
|---|---|---|
| 1 | B1=A1: reintentar validación cerraba un multipart ya consumido | Checkpoint de `upload_id` separado; recuperación de confirmación perdida solo con objeto completo propio. RED911db9/fc570e. |
| 1 | B2: crash dejaba un `.bin` local sin limpieza | Limpieza de UUID viejos dentro del directorio propio; conserva recientes, ajenos y enlaces. RED911db9. |
| 1 | D1: HEAD heredaba600s, superior al lock180s | HEAD con5s o tiempo restante del presupuesto25s; COPY20s, DELETE/ABORT5s, sin reintentos ocultos. D2 verifica el transporte SDK y defaults anteriores. |
| 2 | Delta de limpieza perdía seguimiento si fallaba borrar bytes | El audio no listo se limpia bajo lock antes del commit de retirada; sin red dentro de DBtx. RED95dd34. |
| 2 | B3=A2: la retirada omitía abortar multipart abierto | Abort antes de quitar bytes/filas; fallo conserva sesión, `NoSuchUpload` es idempotente. RED2/2,504cc8; GREEN39/39. |
| 3 | A3/B3 sin gaps de corrección; D2 preservada | B3 repite éxito, fallo/reintento y sesión ya cerrada con SDK simulado. Timeout5s/retries0; no red dentro de transacción. |

La sonda D2 confirma HEAD5s, HEAD24,9s y GET24,8s sin retries para audio; las llamadas anteriores conservan HEAD600s/connect3s y sus reintentos configurados. B3 confirma `CreateMultipartUpload → AbortMultipartUpload → DeleteObject`, registro retirado y sesión cerrada; ante aborto fallido conserva la fila y su upload_id para un intento posterior.

## Límites y siguiente trabajo

Las pruebas de R2 usan SDK y transporte en memoria con bytes propios; no acceden al bucket del usuario. No se modificaron `.env`, credenciales, conexiones, cron ni workers del VPS. No se ejecutó ONNX ni el E2E de quitar fondo; la aceptación de memoria T-10 sigue pendiente.

Sigue integrar la biblioteca/pistas en el editor, el reloj y mezcla de audio, trim/rate/loop/envolventes y sus mediciones reales; después el fotograma temporal en exportaciones estáticas y MP4/GIF por tramos con pruebas de frames/espectro. El editor actual conserva su preview visual verificado anteriormente, pero todavía no reproduce `timeline.audio_tracks`; no dar T-11 por terminada con este informe.
