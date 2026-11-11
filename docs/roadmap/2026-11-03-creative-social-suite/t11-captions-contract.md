# Subtítulos de T-11: contrato previo

2026-11-05. Capacidad de C18 en implementación; este contrato no declara aceptación. El texto temporal existente no acredita un flujo de subtítulos. La primera QA independiente terminó con 62/63 pruebas correctas y no cubrió subtítulos. No cambia las restricciones ONNX/VPS ni los requisitos del resto del roadmap.

## Comportamiento esperado

El editor permitirá añadir y editar subtítulos temporales, importar SRT/WebVTT y descargar el resultado como texto de subtítulos. La importación muestra errores concretos; no sustituye silenciosamente pistas ni elimina texto ajeno. Permite editar contenido/inicio/fin, añadir/eliminar cues, desplazamiento temporal, selección en el cabezal y estilo común. Undo/redo, autosave, reapertura e historial conservarán la pista.

La pista es global al proyecto, no una copia de textos pegada a cada página. Se compone después de mezclar las escenas para que una transición entre páginas no duplique ni atenúe el mismo subtítulo. Preview, fotograma estático y render MP4/GIF utilizan el mismo texto, estilo y regla temporal `[start,end)`. Un fotograma de página usa su tiempo global equivalente. La ausencia de pista conserva el resultado de documentos existentes.

El contorno de los subtítulos se pinta detrás del relleno para conservar el color y el interior de los glifos. Arial22normal con relleno blanco y contorno negro2 sobre negro debe conservar texto visible, no solo un emoji. Preview y salidas comparten este orden, incluido SVG. No se cambia el estilo canónico ni el tamaño admitido para ocultar la pérdida de legibilidad.

Importar sin indicar modo añade cues a la pista existente; sustituirla exige `mode:"replace"` explícito en web/API/MCP. Los IDs generados al importar evitan colisiones con páginas, elementos recursivos, audio y, al añadir, cues existentes. No se renombran silenciosamente IDs que el usuario haya enviado explícitamente en una operación tipada.

La generación automática por voz y traducción pertenecen también al alcance completo de la suite. La integración de modelos/proveedores se ejecutará en T-14 sobre este contrato de cues, con sus trabajos, permisos, costes y pruebas reales; no se dará por implementada por admitir un archivo importado. Esta separación se reflejará expresamente en matriz/ledger antes de cerrar tareas, sin borrar el requisito.

## Datos y validación

Se añadirá un campo opcional de subtítulos al documento temporal v2. Propuesta de estructura: `timeline.captions: {style,cues}`; documentos v1 continúan sin alterar su semántica. Añadir la pista desde un v1 requiere activar explícitamente el modo temporal. El contrato definitivo y OpenAPI se fijarán antes de escribir handlers.

Cada cue tiene identificador estable, `start`, `end` y texto plano Unicode. Tiempos finitos, `0 <= start < end <= duración efectiva`; IDs únicos, orden determinista y límites de tamaño total/documento. Se admitirán hasta5000cues y como máximo8activos simultáneos, con validación mediante barrido temporal. No se recorrerá toda la pista por cada frame: se compilará un índice reutilizable con consultas temporales acotadas y seek correcto. Los límites no recortan datos silenciosamente.

El estilo permite tipografía del sistema o `font_id` propio de la marca, tamaño, color, fondo/contorno, alineación y posición segura. Tamaños y geometría siguen la escala del render; la selección de fuentes privadas participa en autorización, uso histórico, snapshots y recuperación. Nunca acepta URLs de fuentes o recursos arbitrarios.

SRT/WebVTT se procesa como texto, con normalización explícita de BOM/saltos de línea y errores de formato/tiempo. No ejecuta HTML, CSS, etiquetas ni URLs del archivo. Ajustes del formato no soportados se comunicarán, sin presentarlos como conservados. La exportación preserva Unicode/múltiples líneas y tiempos, sin revelar rutas privadas. Límites propuestos:512KiB de entrada de subtítulos,500caracteres porcue, manteniendo el límite global del documento.

Los separadores entre cues admiten dos o más LF consecutivos tras normalizar CRLF, sin depender de que su cantidad sea par. Los LF de un texto canónico se conservan en el documento y en render. Si SRT/WebVTT no puede representar un texto sin ambigüedad —línea vacía inicial, final o interior—, su exportación falla explícitamente con el cue afectado, sin recortar ni mutar el texto, descargar un archivo corrupto o invalidar el documento. Las líneas de contenido no vacías, Unicode, espacios significativos y TAB continúan conservándose. Es una restricción de ese formato de intercambio, no una reducción del texto admitido por el editor.

## Interfaz, API y MCP

Los tres canales comparten validación/operaciones. El servicio recibe texto SRT/WebVTT o cues tipados y genera una nueva revisión mediante el control optimista existente; otro usuario/marca no puede leer ni modificar la pista o sus fuentes. Cambiar el documento después de solicitar un render no altera el archivo de la revisión fijada.

La UI tendrá panel de subtítulos con listado/búsqueda, cue activo, importación/exportación y propiedades de estilo. El estado de error aparece junto a la operación. API/MCP permitirán importar, consultar, editar/reemplazar y exportar sin terminar pasos manuales en el editor. Se documentarán abilities y la paginación si los listados son grandes. No se publica en redes al crear subtítulos.

## Evidencia necesaria

- RED/GREEN de parseo/errores/Unicode/intervalos, límites y operaciones puras; contratos PHP/JS equivalentes y ownership/revisión/fuentes.
- Render real y medición de píxeles antes/dentro/después del cue, incluyendo el cruce de una transición; tamaños escalados y estilo/fuente propios. Sin duplicación ni pérdida al cambiar páginas o buscar otro instante.
- E2E en los tres viewports: importar/editar/undo/guardar/reabrir, preview y salida MP4/GIF/fotograma, descargar SRT/WebVTT, API/MCP y errores. Sin ONNX ni cuentas externas para este bloque manual.
- Revisión adversarial fresca y QA final con criterios actualizados. La pasada63 anterior se conservará como snapshot de las funciones previas, sin atribuirle subtítulos que no ejecutó.

## Contrato fijado antes de los handlers

La pista opcional es `timeline.captions: {style,cues}`. Su ausencia se conserva en documentos anteriores; `clear` elimina la propiedad. Para una pista explícita, un estilo vacío se normaliza a `{fontFamily:"Arial"}` en PHP y JavaScript, evitando que PHP serialice el objeto vacío como una lista. Los demás valores omitidos usan defaults de render, sin insertarlos en documentos existentes.

El estilo admite exclusivamente `fontFamily`, `font_id`, `fontSize`, `fontWeight`, `fontStyle`, `fill`, `background`, `stroke`, `strokeWidth`, `textAlign`, `lineHeight`, `position` y `margin`. Colores hexadecimales de seis cifras; tamaño 1–1000 unidades del lienzo, contorno 0–20, interlineado 0.5–5, posición `top|center|bottom` y margen 0–0.25. Defaults de render: Arial,32,normal,normal,#ffffff,sin fondo,#000000,2,center,1.2,bottom,0.05. Una fuente privada requiere propiedad y correspondencia de familia.

Texto de 1–500 puntos Unicode por cue, sin controles salvo LF/TAB. Entrada máxima 512 KiB, documento máximo 2 MiB, 5000 cues y ocho simultáneos; en un límite coincidente se procesa el final antes del inicio. Los tiempos globales se conservan sin redondear dentro de la duración efectiva de audio/vídeo. Orden determinista por inicio, final e ID. Exportar redondea a milisegundos y rechaza intervalos colapsados. Se rechazan markup, ajustes WebVTT, STYLE y REGION; NOTE se ignora con advertencia. UTF-8 inválido falla.

`PATCH /designs/{design}/captions` recibe `action=import|replace|upsert|delete|offset|style|clear`, revisión esperada obligatoria y activación temporal explícita para v1. La lectura pagina 50 cues por defecto, máximo 100, y permite fijar revisión; exportar SRT/WebVTT utiliza una revisión concreta. Web, API y MCP comparten el mismo servicio y permisos por acción. Estas exportaciones de texto no amplían los siete formatos multimedia de `export_design`.

La selección de páginas utiliza las ventanas de origen de audio: no recorta los cues canónicos por los puntos de dominancia de las páginas. Ejemplo: página A de dos segundos con fade de medio segundo y cue 1.8–1.95; exportar A sola conserva el cue entero. Se unen ventanas contiguas con el mismo desplazamiento; cortes y reordenación recortan y remapean cada ocurrencia sin alterar el documento. En una transición de salida entre ventanas de origen disjuntas, la propiedad visual cambia en su punto medio para evitar duplicación o atenuación del subtítulo. La composición global se aplica una vez después del blend. La salida estática usa objetos temporales de Fabric y fuentes embebidas también en SVG/PDF.

## Empaquetado estático multipágina

La aceptación de subtítulos también debe descargar e inspeccionar las páginas SVG/PNG, no solo el tamaño del ZIP. Se encontró que el PHP local carece de ZipArchive; T-07 se reabre hasta verificar el empaquetado. El servidor conserva ZipArchive cuando exista y utiliza un fallback Python estándar con ZIP64 cuando falte. Este cambio no requiere paquetes ni modelo de imágenes, ni modifica php.ini. Debe conservar orden/nombres/bytes de páginas, creación exclusiva, lectura por bloques y límites existentes (100 páginas,64.000.000 bytes por página y8GiB de archivo), con directorio propio y limpieza de parciales tras fallo. Un error no se presenta como una descarga lista. La aceptación requiere archivador real y recorrido público; el bridge con Node simulado solo prueba la integración del empaquetado.
