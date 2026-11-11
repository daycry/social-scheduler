# Contrato previo de vídeo, audio y timeline

2026-11-04. T-11 conserva todo su alcance: cortar/dividir, pistas visuales y de audio, mezcla, overlays, transiciones, reproducción sincronizada y exportaciones MP4/GIF medidas. La tarea permanece abierta hasta probar el flujo completo web/API/MCP. El trabajo independiente de T-10 no autoriza su prueba de memoria pendiente.

## Documento y compatibilidad

`schema_version: 1` conserva su significado y validación. Las funciones temporales usan `schema_version: 2`, la misma geometría tipada y `timeline: {fps, audio_tracks}`. `fps` admite 12,15,24,25,30,50,60. Los documentos antiguos solo se convierten a v2 al activar las funciones temporales. Ningún guardado, copia, plantilla o exportación descarta campos temporales.

Una capa visual puede añadir `timing: {start, duration}`, en segundos locales de su página. Ambos valores son finitos: inicio no negativo, duración positiva representable y final dentro de la página. Si no hay timing, ocupa toda la página. Los hijos de grupos usan también el reloj de la página; su visibilidad efectiva se interseca con la del grupo. Las ventanas son semiabiertas: incluye el inicio y excluye el final.

Desagrupar conserva esa intersección, la fase del origen y la envolvente. La intersección puede ser menor que un fotograma: se preserva sin ampliarla ni borrar audio válido. Un hijo sin intersección queda invisible. Las operaciones de corte manual siguen exigiendo un fotograma a cada lado; esa restricción de edición no se usa para rechazar una ventana heredada más corta. Dividir rechaza resultados por encima de las 500 capas por página,64 pistas o2MiB; no devuelve un documento imposible de guardar.

Vídeo conserva `trimStart`, `trimEnd`, `volume`, `muted` y añade `playbackRate` (0,25–4), `loop`, `sourceOffset` y `gainPoints`. Trim y offset pertenecen al reloj de origen; duración y start al reloj de salida. Un vídeo corto conserva su último fotograma hasta finalizar su ventana, con audio silenciado tras agotar el origen. Loop repite solamente el intervalo recortado. El offset permite dividir un bucle sin cambiar su fase.

`gainPoints`, opcional, contiene 2–64 puntos `{time,value}` de tiempo local del clip y ganancia0–1; tiempos estrictamente crecientes, primero0 y último igual a la duración del clip. Interpolación lineal; sin puntos, ganancia1. El volumen se multiplica por esa envolvente. Dividir un clip corta e interpola su envolvente en la frontera, conservando el nivel y el origen. Fade-in/out se expresan con estos puntos; no se inventan fundidos en el punto de división.

`timeline.audio_tracks` contiene hasta64 pistas independientes con ID global único, `asset_id`, `start`, `duration`, trim/offset/rate/loop, volumen/muted y gainPoints. Start es tiempo global del proyecto; duración al menos un fotograma, final dentro del proyecto. El recurso debe estar listo, registrado en la misma marca y ser audio o vídeo con audio disponible. No se aceptan URLs, rutas ni filtros ejecutables dentro del documento.

Cada página puede declarar `transition: {type,duration}` hacia la siguiente: cut/duración0; fade, slide_left o slide_right/duración positiva al menos un fotograma y como máximo la mitad de cada página adyacente. La última solo admite cut o ausencia. Las transiciones solapan las páginas; duración total = suma de duraciones menos solapamientos. El audio de esas páginas cruza linealmente, incluso si la transición visual es un desplazamiento. Las pistas globales no se reinician al cambiar de página.

Los tiempos se convierten a fotogramas al compilar la exportación. La tolerancia de medida es un fotograma, conservando orden, cantidad y duración del resultado. La interfaz muestra el reloj real y permite play/pause/seek; el seek no cambia ni guarda visibilidad/opacidad transitorias como si fueran decisiones de edición.

Los límites derivados de restar tiempos tienen tolerancia numérica de1e−9s, común a PHP/JS; no convierte un clip apreciablemente menor en un fotograma válido. Los bordes de frame se redondean sobre tiempos acumulados del proyecto, no sumando duraciones ya redondeadas de cada página.

## Recursos y canales

La biblioteca amplía la media existente con audio validado por contenido y ffprobe; mantiene propiedad, registro por marca, R2/local y límites de subida configurados. Se permiten MP3, WAV, M4A/AAC y Ogg compatibles, con errores explícitos de códec/formato y de preview. No se habilita publicar un archivo de audio solo como si fuera una imagen/vídeo social.

El ticket de audio se inicia con nombre/MIME/tamaño en `design-audio` de la marca; límite100MiB y duración3600s como máximos públicos, reducibles por configuración. Los bytes viajan por PUT binario local o URLs prefirmadas single/multipart; no por JSON base64. La finalización comprueba tamaño real, contenido y sondeo nativo antes de listo. GET/listado usan designs:read; iniciar/subir/completar designs:write. Las peticiones siempre verifican usuario y registro de marca, incluso para media pendiente. Repetir complete sobre listo devuelve el mismo resultado; un PUT local posterior no sobrescribe un audio listo. El catálogo social excluye audio; sus validadores siguen rechazándolo como adjunto social aislado.

El tamaño real del audio debe coincidir exactamente con el declarado; una transferencia truncada no se considera lista. El sondeo nativo tiene15s, copia remota25s en trozos1MiB y límite64MiB por bloque nativo (no es un límite total de RAM). Motor ausente/timeout conserva el archivo pendiente para revalidar; contenido incompatible se marca inválido y elimina sus bytes. La transferencia/finalización por recurso usa lock180s y errores409 por concurrencia, sin retener transacciones de BD durante red/proceso.

Antes del sondeo se crea un snapshot privado con clave propia que nunca tuvo URL de subida. Se valida esa copia y solo entonces se publica como recurso listo: reutilizar una URL antigua no cambia el audio de un diseño. La asignación temporal queda rastreada para limpiar un proceso interrumpido; los registros de marca de audio no listo se retiran al abandonar su subida. El comando existente de limpieza conserva el seguimiento de la clave de entrada hasta caducar sus firmas y limpia los objetos recreados por replay. R2 se valida con SDK simulado y bytes propios en desarrollo; no se declara un smoke de bucket real.

`manage_studio_audio` comparte esos servicios: start/list/get/complete. start entrega ticket y URLs de transferencia binaria; no acepta rutas locales, URLs arbitrarias ni contenido base64. La descarga es el proxy privado de recursos existente, con rangos y autorización por marca; la firma de subida no se convierte en URL de escucha pública.

Web/API/MCP usan los mismos servicios. `create_design`, `update_design`, `get_design`, `manage_design` y el contrato OpenAPI admiten v2; las operaciones visuales conservan sus metadatos. `export_design` añade MP4/GIF y seguimiento real, manteniendo la revisión esperada, páginas seleccionadas, permisos y descargas privadas. Audio, timeline y vídeo no se anuncian como operativos hasta completar sus endpoints, interfaz y render real.

## Render audiovisual

Las exportaciones estáticas v2 toman un fotograma por página elegida. `frame_times`, opcional, es una lista de hasta100objetos estrictos `{page_id,time}`: ID seleccionado y sin duplicados, segundo local finito con0<=time<duración de página. Las páginas omitidas usan0. Se persiste en `options.frame_times` cuando se proporciona, tanto al registrar una exportación del navegador como al solicitar render web/API/MCP. La lista evita ambigüedad de claves numéricas al decodificar JSON en PHP. Los documentos v1 conservan sus pósters previos y rechazan una lista no vacía.

Ese fotograma respeta ventanas semiabiertas, visibilidad heredada de grupos y fase/trim/rate/loop del vídeo; prepara solo fuentes y medios presentes en él. No modifica el documento ni sus capas. La interfaz permite elegir segundos locales y parte del cabezal actual para las páginas donde esté situado. El resultado de PNG/JPG/WebP/SVG/PDF es una página estática; las transiciones entre páginas y la mezcla de audio corresponden al render audiovisual del proyecto completo. No se fuerza una conversión a imagen del documento original guardado.

Frame visual determinista con el mismo adaptador Canvas y fuentes privadas. Composición y mezcla mediante FFmpeg: entradas locales propias preparadas por el servidor, comandos mediante arrays, protocolos/formats permitidos y sin argumentos/filtros/rutas suministrados por el usuario. Mapa cerrado en Chromium. Frames, audio y resultado pertenecen a un intento privado.

### Motor nativo por tramos: contrato interno

El motor compila una revisión v2 validada sobre un único reloj entero: fotogramas del proyecto y muestras PCM estéreo48000Hz. Cada tramo cubre como máximo dos segundos de fotogramas; sus muestras se deducen de los bordes globales acumulados. Las ventanas de grupos recortan el intervalo efectivo sin reiniciar fase ni envolvente. Los fades de páginas usan los mismos solapamientos del reloj visual; las pistas globales continúan durante las transiciones. Cambiar velocidad cambia el tono según el reloj del origen, tanto en vídeo temporal como en pistas independientes; no se aplica conservación de pitch en preview/export.

Los recursos de ese motor son snapshots locales del intento con nombres generados `assets/{id}.bin` y fuentes `fonts/{id}.woff2`. No viajan como vídeos/audio base64 ni se admiten rutas o URLs del consumidor. Chromium solo lee un mapa cerrado en loopback con token aleatorio y Range/backpressure; las peticiones a otros orígenes se abortan.

Antes de mezclar, FFmpeg normaliza una sola vez cada origen de audio utilizado a `audio-sources/{id}.pcm`: estéreo float32 little-endian48000Hz, en disco privado. La decodificación es continua desde el principio, para preservar priming y el reloj de resampling; no se buscan ventanas directamente en MP3/AAC comprimidos. La duración/probe del origen se contrasta con metadata; el audio de vídeo más corto se completa con silencio hasta la duración visual. Los profiles incluyen ID, tamaño/hash y cantidad de muestras; se checkpointan en preparación, no se normaliza otra vez por cada tramo/pista. Esta etapa no materializa el fichero completo en RAM.

La mezcla lee por offsets de muestras únicamente ventanas activas de PCM, como máximo ocho segundos de origen más los vecinos de interpolación, con cachéLRU16MiB compartida por pistas de la misma ventana. Se suma por muestra con volumen/envolventes/fades y saturación a[-1,1]; después FFmpeg codifica AAC. Un origen/perfil incompleto falla explícitamente. La normalización trata los errores de decodificación como fatales (`-xerror`/`-err_detect explode`): no rellena un M4A truncado solo porque su cabecera aún declare la duración original. El padding solo se acepta cuando el demux/decode completo ha sido válido. Los intermediarios PCM del intento tienen8GiB de presupuesto agregado, igual que los snapshots; no se oculta el coste de disco de normalizar audio largo. La integración de jobs separará normalización por recurso, render/mezcla por tramo y unión, conservando los checkpoints válidos al reintentar.

Cada tramo visual se codifica y sondea antes de publicarse. MP4 usa H264/yuv420p; los tamaños impares se completan con una fila/columna negra hasta el siguiente tamaño par, sin estirar el lienzo. GIF se codifica con paleta por fotograma: la unión conserva datos LZW comprimidos y paletas locales, elimina cabeceras/loops duplicados y asigna delays sobre el reloj acumulado de centésimas. No concatena varios GIF completos ni recodifica toda la animación. La cuantización de GIF puede diferir del reloj ideal hasta media centésima; los reproductores pueden imponer sus propios mínimos de delay.

Límites del motor de esta fase:16MP por frame,2GiB por snapshot,8GiB de snapshots por intento,64MB por fotograma PNG o GIF comprimido,512MiB por tramo visual y8GiB por resultado. Audio nativo usa stdout acotado a4MiB por ventana. Los procesos FFmpeg de tramo/unión tienen80s como máximo y los probes límites propios; **esto no demuestra todavía un presupuesto total de fase ni un límite OS/RSS de todos los descendientes**. La integración de jobs debe imponer ese presupuesto antes de habilitar MP4/GIF para usuarios. Son límites explícitos de fallo, nunca truncamientos silenciosos del diseño.

Este motor y sus pruebas nativas son un subbloque interno de T-11. Los endpoints web/API/MCP siguen ofreciendo solamente los formatos estáticos hasta completar preparación privada/reanudación, progreso/cancelación/reintento, registro y borrador; no se anuncia soporte audiovisual operativo por cerrar el motor. La unión final verifica hashes, orden, cantidad y dimensiones de los tramos, duración, pistas y paquetes; los tests de aceptación decodifican también frames/píxeles y sonido reales.

Render por tramos reanudables para respetar el timeout de workers existentes; progreso, cancelación/error, idempotencia y limpieza de parciales. Una exportación no pasa a ready hasta validar su archivo completo. Un fallo no deja un MP4 incompleto como descargable ni modifica el original. Los presupuestos de tiempo/memoria/almacenamiento son explícitos y configurables; no se acorta el documento en silencio para hacer pasar un smoke.

MP4: H.264 y AAC cuando haya audio, dimensiones/fps/duración verificadas con ffprobe, normalización de formato y faststart. GIF: animación real con paleta, duración y cambio de fotogramas comprobados; no tiene audio por formato. El resultado queda registrado como recurso de su marca y puede generar un borrador con validación por red; no se publica automáticamente.

## Puertas de aceptación

### Opciones de la exportación audiovisual

La exportación selecciona una lista no vacía de páginas, sin IDs desconocidos/repetidos, y conserva su orden explícito. Si no hay selección, usa todo el documento. La última página exportada termina con cut; el resto conserva su transición hacia la siguiente página elegida. Si una transición deja de ser válida por la duración de la nueva página adyacente, se rechaza la selección con explicación, sin acortarla silenciosamente. Nada de esto altera la revisión original.

El audio original de cada vídeo sigue la página seleccionada y su reloj local. Las pistas globales siguen los intervalos originales de las páginas exportadas: una secuencia contigua de páginas del proyecto usa un único desplazamiento de origen, para no introducir saltos de fase por redondeos al recompilar. Al saltar/reordenar páginas se inicia otro intervalo de origen; solamente esas fronteras cruzan audio según la transición elegida. Los fades internos entre páginas consecutivas no duplican ni reinician la pista global. Los recortes de una pista conservan su envolvente y offset/rate/loop mediante el reloj original, no mediante una copia modificada del documento.

Los documentos v1 se convierten únicamente en memoria a un reloj30fps para estos formatos nuevos, sin guardar una conversión ni cambiar su exportación estática. Un trimStart antiguo fuera del origen mantiene el último fotograma disponible y audio silenciado, conservando su compatibilidad de póster. Los recursos no seleccionados no se cargan para ajustar esa compatibilidad.

`scale` admite0,1–4 y `quality`0,1–1; ambos son números finitos estrictos. Dimensiones de render = redondeo de tamaño original×escala, seguido del padding par para MP4/GIF. El límite audiovisual16MP se aplica al resultado con padding. La escala se aplica al viewport de escenas/texto/vector antes de rasterizar, no ampliando un bitmap ya pequeño. La transición conserva sus desplazamientos escalados y el reloj/duración no cambia.

La calidad MP4 determina CRF16–40 de forma monótona (quality1→16,quality0,1→40; el valor por defecto0,94 mantieneCRF18). GIF usa16–256colores por fotograma, sin audio; conserva el reloj de centésimas y las garantías de unión ya verificadas. Cada tramo incluye escala/calidad usadas; la unión rechaza mezclar tramos de opciones distintas. Estas opciones internas no anuncian endpoints o UI terminados; siguen pendientes los jobs y el resto de integración pública.

1. RED/GREEN para v1/v2, tiempos inválidos/no finitos, IDs/propiedad, división con sourceOffset y envolventes, reloj común y transición sin saltos ni huecos.
2. Navegador real: texto/capas/fuentes conservados al cortar/reabrir, audio y vídeo sincronizados, pause/seek y cero mutación por preview; escritorio y dos móviles sin desbordamiento.
3. FFmpeg real con fixtures propias: tonos conocidos, vídeo con movimiento, overlays y transiciones; duración/fps/frames/códecs y energía/espectro de la mezcla medidos. Un mock de proceso no acredita esta puerta.
4. Errores/cancelación/reintento, recursos grandes sin JSON base64 masivo, autorización web/API/MCP y privacidad. Revisión A/B/C/D y QA antes de cerrar.

Fuentes primarias consultadas: [documentación de filtros FFmpeg](https://github.com/FFmpeg/FFmpeg/blob/master/doc/filters.texi) y ayuda del FFmpeg9.0.2 instalado (`amix`, `xfade`); [Web Audio](https://webaudio.github.io/web-audio-api/) para la programación de audio, documento en evolución, no garantía de implementación de un navegador concreto. Los smokes miden los binarios/navegadores reales de esta instalación.


## Diálogo audiovisual — contrato de interfaz previo

El selector ofrecerá los cinco formatos estáticos y MP4/GIF. Los estáticos conservarán render local, fotograma por página y transparencia donde proceda. MP4/GIF usarán revisión guardada y cola del servidor, con escala/calidad, páginas seleccionadas y orden explícito; no enviarán frame_times ni transparencia. El tamaño mostrado reflejará el padding par del motor. GIF indicará que no contiene sonido.

Preparar un trabajo bloqueará solo el guardado/envío inicial, sin mantener bloqueado el editor durante el worker. El historial del diseño reutilizará su endpoint paginado, una consulta por página cada2s mientras existan trabajos activos, sin solapar consultas. Tras5min o un error se pausará el seguimiento, con botón para volver a consultar sin crear otro trabajo. Cerrar/cambiar formato detendrá consultas y descartará respuestas tardías; el trabajo seguirá en el servidor. Al reabrir o recargar se recuperará el historial real, sin depender del estado temporal del navegador.

Cada fila mostrará formato, revisión, estado, progreso y error seguro. Cancelar y reintentar se ofrecerán según can_cancel/can_retry del DTO; se cercará la lectura pendiente antes de mutar para que un polling antiguo no restituya un estado previo. Las acciones reutilizarán CSRF y rutas web autenticadas. Reintentar conservará el mismo ID/revisión/opciones mediante el servicio compartido. Descarga firmada y crear borrador serán acciones explícitas cuando exista resultado ready; no se crearán borradores automáticamente al consultar o completar el render.

Verificación prevista: cliente/monitor con reloj y respuestas diferidas; E2E en tres viewports con cola real y MP4/GIF descargados, orden de páginas comprobado en píxeles, cancelación/reintento y recuperación tras recargar, borrador de la marca; regresión de fotogramas/estáticos y controles anteriores. No sustituye QA integral del padre, inspección audiovisual ni las pruebas del VPS.
