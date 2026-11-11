# T12: tinta de etiquetas y longitud del JSON

Estado: bloque tinta/HTTP revisado A/B/D; integración aún NO-PASS. No cierra T12 ni acredita las diez variantes completas. Los intentos reales1/2/3 conservan cada uno un fallo desktop y once casos no ejecutados. El tercer intento detectó que fonts.ready no garantiza cargar la cara CSS del SVG cuando ya hay una FontFace de igual familia; la carga explícita de fuentes efectivas conserva las verificaciones failclosed y pasó regresión nativa6/6. Nueva matriz pendiente tras revisión y build actuales.

## Observación tipográfica independiente

El intento2 falla sobre `bar.svg` porque el oráculo interpreta el rectángulo tipográfico como tinta. Para la categoría r0, getBBox transformado empieza en104,578958; la banda empieza en107 y tolera hasta106,25. La tinta de los glifos está entre109,124127 y120,693649, dentro de la banda107–184,2.

La causa se confirmó con el SVG real, la medición nativa previa del mismo texto/fuente/style, los contornos cmap/glyf del TTF y los píxeles de la vista previa. Replay de la comprobación original: BBox rechaza; sólo tinta acepta con la misma tolerancia0,75; desplazar tinta77,2px a otra fila rechaza. No demuestra un defecto del renderer en este fixture.

El observador conserva x/y/width/height de getBBox y matrix para los controles estructurales y la referencia de glifos. Añade una propiedad independiente `ink` calculada con CanvasTextMetrics.actualBoundingBoxLeft/Right/Ascent/Descent, fuente efectiva cargada, línea base de cada tspan y transformación CTM. Une los límites transformados de todos los tramos.

El oráculo valida la banda categórica con esa tinta, conservando0,75px, IDs, texto, filas, orden de series, gaps, signos y tamaños de barras. Espacios y texto vacío no simulan píxeles. Métricas ausentes, no finitas o fuente no lista fallan explícitamente; no se vuelve silenciosamente a getBBox. Las fixtures sintéticas incorporan tinta explícita en una adaptación nueva; las evidencias de correction2/3 no se reescriben.

## Cuerpo JSON y cabecera exacta

Las respuestas originales entregaron200,289bytes y el mismo SHA; la política de red registróERR_ABORTED sin identidad/query/tiempo suficientes para asociar retrospectivamente cada evento. No se oculta esa limitación.

La primera sonda mínima quedó parcial por esperar response.finished sin límite ante ese estado; su salida1 y sus datos quedan conservados. La segunda completó16 observaciones: omitir reader.cancel después de done no eliminó el aviso. Esa hipótesis se descartó, sin cambiar la aplicación.

La tercera completó16 observaciones en esta build:2transportes por4opcionesfetch por2métodos de lectura. Los8cuerpos con Content-Length terminaron sin aviso; los4sin longitud leídos con arrayBuffer también; los4sin longitud leídos manualmente notificaronERR_ABORTED pese a EOF, SHA y lexema original correctos. No hubo cancel/release/abort/dispose, y las opciones default/signal/redirect:error no cambiaron ese patrón. Es diagnóstico local, no una afirmación general sobre navegadores ni un PASS de aplicación.

La revisión canónica ya conserva json_bytes y SHA calculados de los mismos chunks escritos; read valida tamaño/SHA antes de responder. El exportador actual comparte flags, orden y gramática con el escritor. Se propaga json_bytes internamente desde la revisión ya autorizada al controlador y se añade Content-Length string únicamente a JSON. El callback de chunks no cambia; no se concatena ni se vuelve a leer el cuerpo para calcularlo. CSV/TSV mantienen su longitud dinámica y MCP conserva su filtro de campos.

Las pruebas exigen header=bytes entregados=json_bytes y SHA igual a revisión, con Unicode, escapes, TAB/LF, slash,±0.0, enteros y null. Cubren revisión histórica/vacía, auth401, otro actor/marca404, revisión inexistente404 y corrupción de tamaño/contenido503. No se sustituye el lector acotado por arrayBuffer, no se permite seguir redirects y no se añaden allowlists de errores.

## Puerta de aceptación

RED y GREEN focales antes/después del cambio, vecinos pequeños, lint y hashes de fuentes. El caso52MB no se repite como vecino pequeño ni se atribuye estrés grande a esta tanda. Revisión fresca del bloque focal preserva la tabla completa anterior y sus rebatidos; C/D según selector. La compilación1 puede conservarse si sus inputsJS no cambian.

Después se refrescan baseline/manifiesto actual y señal root para UNA pasada nueva de12casos en375/768/1440, con guard3GiBCOMMIT/preflight6/piso3/100ms/deadline900/PHP256/Node512, worker1/retries0/failfast1 y reporter real. Continúan la política estricta de red, observadores de SVG/PNG/PDF/fuentes, axe, pinning, actor/marca, cancelación real y12kbarras.

La aceptación requiere cerrar procesos/puertos, informe PDF separado, inspección de todas figuras/páginas y SHA trasPDF. Los resultados anteriores y las sondas se mantienen con sus límites. VPS, modelos, R2 real, AV y otras configuraciones quedan fuera de esta tanda.
