# T12 C3 — escena, editor y exportación estática

Contrato previo a código. Se apoya en C1/registryC2 y G5 aceptados; no modifica sus defaults ni reduce las32configuraciones/11familias. Esta integración inicial cubre las diez variantes de barras/columnas. AV y otras familias necesitan fases posteriores antes de cerrar T12.

## Interfaces y escena compartida

`createBarChartScene(chart, chartResources, {width=800,height=500,signal}={})` será async: normaliza ChartSpec, valida dimensiones160–8000 y solicita `chartResources.prepare(spec.binding,{signal})` antes de llamar una sola vez a G5 con el área de trazado. El recurso pertenece al contexto autorizado; nunca se acepta proyección o geometría del documento. Abort anterior no inicia trabajo y abort tardío no publica una escena.

Devuelve datos propios inmutables: width/height, plot{x,y,width,height}, primitives, tabla accesible de categorías/series/valores originales y warnings. Primitivas tipadas rect/line/text, con IDs únicos, coordenadas finitas y cajas dentro de la capa; no HTML, SVG/XML libre ni FabricJSON. Tabla conserva row_id, tipo/label de categoría y column_id/value/raw/percentage, null y cero distintos, orden y duplicados. No se persiste nada derivado en documento.

**Excepción posterior VL1/VL2,2026-11-07:** el [contrato de etiquetas](t12-chart-value-labels-contract.md) autoriza hasta dos pases geométricos lineales del mismo handle sólo para outside cuando hay que reservar espacio según signos/etiquetas reales. Prepare/proyección/lectura/parseo siguen una vez; none/inside un pase y none sin cambio de bytes. No aumenta cuotas, objetos, caché ni trabajo por fotograma. Esta excepción explícita sustituye únicamente la restricción de una llamada G5 en ese caso; D y los contadores deben comprobar el coste real.

El trazado incluye rejilla, ejes y línea cero, barras, títulos/subtítulo/fuente, categorías, valores y leyenda con los controles C1. Multicolor asocia leyenda a categorías, las otras variantes a series; no etiquetar una paleta categórica como una sola serie. La leyenda visual puede limitarse a12entradas con aviso/resumen y tabla completa. Categorías visuales pueden muestrearse según espacio y acortarse a category_label_limit, con avisos; datos originales siguen íntegros. No ocultar ni transformar barras/nulos/valores para mejorar el aspecto.

Texto usa fuente real, con font_id cuando corresponda. Se ajusta uniformemente a su caja (sin deformar relación horizontal/vertical), y se avisa si la densidad/tamaño fuerza compactar anotaciones. Plot≥40px por lado; una configuración válida no se vuelve inexportable sólo por fuente grande o muchas etiquetas. Etiquetas raw conservan lexema en tabla/tooltips; percentage sólo proporcional. Densidad excesiva genera aviso, nunca cambia datos. La cota de objetos dibujados no excederá100+filas+2*filas*series; sumar con capas ordinarias para50k/página y200k/documento.

`chart-renderer.js` construye FabricGroup propio FixedLayout desde primitivas. Textos son objetos de texto con escape normal de Fabric, nunca carga de markup. El grupo conserva studioType=chart y studioChart=configuración clonada; hijos locales respecto al centro de la capa. Serializer guarda sólo propiedades comunes y chart. Desagrupar sólo afecta grupos ordinarios. SVG/PNG/PDF y preview usan esta misma factory.

## Legibilidad de ticks numéricos (QA28, 2026-11-06)

Sólo el texto visual del eje se redondea. Se parte de tres cifras significativas y se aumenta la precisión hasta17 cuando es necesario para conservar signo, orden, etiquetas distintas y valores leídos finitos, con error máximo del0,5% de la separación al tick vecino más próximo. Cero se representa como `0`; un valor no nulo nunca se convierte en cero. Se elige la notación decimal o científica más corta del valor redondeado; no se truncan etiquetas ni se usa un número fijo de decimales. Si la separación extrema requiere precisión completa, se conserva y el ajuste tipográfico continúa avisando de densidad.

Valores/posiciones de ticks, dominio, barras, datos, categorías, series y lexemas originales permanecen iguales. En el fixture800×500 con fuente18 y dominio[-100,100], los ticks son `-100,-66.7,-33.3,0,33.3,66.7,100`; la prueba nativa comprobará al menos12px efectivos antes de aceptar legibilidad. Las pruebas puras de texto no sustituyen esa comprobación.

## Origen seguro del render estático (diagnóstico QA28)

El navegador del renderizador necesita un contexto seguro para verificar el SHA de los datasets mediante Web Crypto. Su origen sintético será `https://studio-renderer.invalid`; todas las respuestas seguirán procediendo del mapa cerrado de recursos y ninguna solicitud continuará hacia la red. No requiere servidor HTTP, certificados externos ni flags de navegador que relajen seguridad. No se cambia la CSP ni se omite la verificación SHA.

La sonda con el registry real confirmó que el mismo JSON291B falla en HTTP por ausencia de `crypto.subtle`, mientras HTTPS verifica el SHA y resuelve cuatro filas. Los controles de fuentes distinguen FontFaceURL (bloqueado por la CSP original) del camino usado realmente, fetch→ArrayBuffer→FontFace, que funciona con esa CSP. El cambio mínimo aplicado es el protocolo del origen. El entrypoint real de Node pasó SVG/PNG/PDF con esos mismos datos y una fuente privada; la aceptación integrada todavía exige la matriz completa, no sólo estas pruebas focales.

## Canvas, fotogramas y ciclo de vida

### Lectura de datos independiente de la vista previa

Pulsar Ver datos al abrir el diálogo debe mostrar la tabla de la revisión fijada aunque el debounce de200ms arranque la vista previa durante esa lectura. Las generaciones y señales de cancelación de la tabla y la vista previa son independientes. Cambiar estilo o reconstruir el preview no descarta una tabla solicitada. Una nueva lectura reemplaza la anterior; cerrar, desmontar o cambiar marca/fuente/recurso cancela la lectura y rechaza su resultado tardío. Antes de publicar se contrasta también la identidad id/revision/SHA de la referencia actual. Se conservan tipos/lexemas, paginación, control de foco y cancelación real.

El defecto quedó reproducido con Vue compilado y reloj controlado: viewData pendiente, tick200ms, resolución de tabla original; antes del fix dataTable=null. La regresión mantiene la pulsación temprana y no espera al preview para ocultar la carrera. Build y validación reales posteriores a la corrección son puertas distintas del test puro.

Nuevo parámetro opcional `chartResources` en createStudioCanvas, elementToFabric, renderStudioPage(options), createTimelinePreview(options) y renderStudioFrame(sexto argumento options). Las llamadas anteriores sin charts mantienen comportamiento. Cada llamada propaga el mismo contexto por grupos y escenas; no resolver por fotograma. Espera tipográfica reconoce chart.style.font_id y registry.style(element), con peso/estilo reales. No liberar registry del editor al disponer una escena temporal.

Canvas expone `addChart(chart, patch={})`; obtiene recurso/fuente, valida documento de capa, crea grupo y sólo al éxito inserta/selecciona/emite un cambio. Patches chart reconstruyen grupo en el índice original, manteniendo identidad/transformación/selección. Comprobar generación de página, epoch por elemento/operación y que el original siga vivo antes de publicar; respuesta tardía no restaura capa borrada ni configuración anterior. Cargas fallidas dejan escena/documento previos. Undo/reopen/paste/duplicate mantienen semántica y vuelven a resolver en el contexto de marca vigente.

## UI y fuente de datos

StudioDatasetsDialog ofrece selección explícita sólo de revisión guardada, completa y SHA disponible. No insertar desde borrador dirty ni SHA vacío tras guardar. Puede emitir select-chart con referencia fija y columnas/eligibilidad numérica; no incrustar tabla52MB en payload del documento. Campos numéricos exigen número/null; se deriva elegibilidad sin convertir texto. Si faltan, orientar al editor de datos existente.

Nuevo diálogo de gráficos recibe el contexto de marca, chartResources, fuentes/kit y selección guardada. Permite10tipos, categoría, hasta12series, precisión explícita, rango/pasos/huecos/paleta y TODOS los campos style C1. Vista previa mediante renderStudioPage/factory real, sin SVG paralelo. Debounce/ticket/abort y dispose de escena para evitar fugas y respuestas tardías; contexto compartido sigue vivo. Errores y avisos visibles, controles etiquetados y foco/modal/scroll accesibles en375/768/1440. No sustituir dato inválido silenciosamente.

Editor posee un registry por marca/diseño y lo pasa a canvas, preview y ExportDialog. Cerrar/cambiar contexto lo libera. Añadir/editar entra en history/autosave existentes, con una acción atómica; Inspector excluye relleno genérico del chart y ofrece editar datos/configuración. ExportDialog recibe prop opcional chartResources y lo pasa a exportDesign. No descargar otra tabla por cambio de paleta. Actualizar de revisión antigua a nueva es elección explícita y deshacible.

## Exportación estática del servidor

StudioChartFiles::materialize(User,Brand,document,directory):array recopila referencias únicas y autoriza usuario/marca/revisión/SHA;32refs/64M antes de abrir archivos. Reutiliza modelos/semántica existentes, con consultas agrupadas o deduplicadas. Copia en streaming el JSON canónico ORIGINAL verificado a directory/datasets, con tamaño/hash esperados, sin cargar52MB en PHP ni reserializar números. Validación de selección ya se hizo al guardar; el SHA fija esos mismos bytes. Archivo ausente/corrupto503, tenant/revisión404 y SHA incoherente422; históricos archivados legibles. No acepta rutas o URLs del documento.

Directory sólo intento propio studio-exports/user/export/UUID, no ruta arbitraria; nombres controlados id/revision/SHA. Escritura exclusiva/temporal, limpieza sólo de archivos creados si falla y ningún overwrite de export previo. Descriptor PHP→Node pequeño `{id,revision,sha256,bytes,name}`, nombre relativo datasets/…json. Mantener2MiB documento/90MBstdin y límites de medios existentes; datos separados≤64M. No interpolar JSON de tabla en HTML/script.

render.mjs valida campos, nombre, raíz real propia (sin symlink/escape), tamaño y SHA. Sirve bytes únicamente desde mapa cerrado del origen sintético existente, sin socket ni red externa; convierte a descriptor browser `{id,revision,sha256,bytes,url}`. render-entry construye registry offline explícito y lo pasa por exportDesign→exportPage→renderStudioFrame→renderStudioPage; finalmente dispone sólo su contexto. El registry incorpora opción adicional `offline:false` por defecto, true forzado en render aislado incluso con descriptors=[]; con true una referencia ausente falla antes de fetch, sin fallback web. Ampliación compatible y con RED propio; resto C2 permanece.

AV no recibe tablas en su manifiesto8MiB: snapshot/journal/limpieza/retry y propagación de datasets tendrán contrato propio en la fase siguiente. No acreditar MP4/GIF de charts por terminar exportación estática.

## C3: vida de caché durante cambios de revisión

Los32recursos/64Mcanónicos de C2 acotan caché e inflight, no toda la historia de ediciones del usuario. Al necesitar espacio para una referencia nueva, el registry puede expulsar recursos COMPLETADOS por LRU, manteniendo intactos snapshots/handles ya entregados al consumidor. No abortar peticiones inflight para hacer sitio a otra; si el presupuesto sigue ocupado por inflight, rechazar y cancelar correctamente la nueva respuesta como D1. Toda descarga nueva sigue validando tamaño/SHA y parser. Las preparaciones G5 propias siguen válidas después de expulsar su tabla de la caché; cambiar paleta no refetch/parse. Volver a resolver una tabla expulsada puede recargarla explícitamente.

Esto permite pasar de una revisión grande completa a otra y de referencias históricas antiguas a la actual sin rechazar documentos válidos por el historial de la sesión. Los límites siguen32/64M, sin parámetros públicos que permitan subirlos. Las pruebas de política de bytes pueden ejecutar la misma fuente con constantes menores sólo en un loader de test, documentando la escala y sin atribuirle estrés52MB o memoriaRSS. Se conserva la prueba de pending33M+rechazos correctamente abortados. La vida de referencias externas prestadas pertenece al consumidor; no se anuncia64M como límite de memoria del proceso.

Preflight de canvas recorre documento validado y usa registry.resolve(ref).table.rows.length para la cota de objetos; sin crear todas las escenas ni reproyectar. Fuente/parseo deduplicados por registry y ningún trabajo nuevo por fotograma. No cachear autorización o presupuesto basándose únicamente en identidad de un documento externo mutable.

## Puertas

TDD real y baselines antes de editar; tests puros de escena/transformación/semántica/abort/carreras, fixtures de diez variantes, negativos/null/ceros, fuentes/Unicode, leyendas, densidad y presupuesto. Feature aislados para materialización/permisos/SHA/corrupción/traversal/cleanup/pinning. No build, Chromium ni exports nativos en implementadores sin señal root; no .env/DB/proveedores reales. Tras revisión A/B+C/D según selector, root coordina nuevo build guardado y QA real sobre UI375/768/1440 y SVG/PNG/PDF con observador independiente, fuente privada, guardado/reapertura/undo y revisión fija. Hasta entonces no se anuncian gráficos usables ni se cierra T12.

### Presupuesto del trabajo exportado

exportDesign comprueba una sola vez las páginas SELECCIONADAS juntas antes de que el render de fotograma reduzca la vista a una página. La cota200k se aplica al trabajo completo, no a cada frame aislado. No resolver/materializar referencias de páginas no exportadas: el servidor sólo entrega sus descriptores seleccionados. El documento guardado ya cumple200k en C1; el preflight de export no modifica el documento/timeline usados para pintar ni sus tiempos globales de captions. Usa sólo una vista de páginas para contar recursos/objetos. Las pruebas de exceso deben respetar1000filas por dataset,500capas por página y2MiB del documento para demostrar el fallo por el presupuesto correcto.

### Preflight y publicación durante una edición

El LRU de caché no sustituye32referencias/64Mcanónicos únicos del documento/trabajo actual. El preflight comprueba el número de referencias ANTES de I/O y suma bytes originales verificados independientemente de qué entradas siga reteniendo la caché. No reserializar para medir bytes: PHP yJS pueden escapar U+2028/U+2029 de forma distinta. El registry añade método compatible `describe(reference,{signal})` que entrega datos propios `{id,revision,sha256,bytes,row_count,column_count}` procedentes de bytes/tamaño/SHA/parser realmente verificados, no sólo Content-Length declarado. Puede reutilizar metadatos ligeros ya verificados y no debe conservar tablas adicionales ni crecer sin cota. Una tabla expulsada no invalida ni elimina automáticamente preparaciones G5 propias; conservar metadata asociada a preparaciones permite cambiar sólo estilo sin volver a descargar/parsear. La caché de preparaciones mantiene su cota500completadas y las garantías de inflight/dispose.

Para fuentes frías independientes, el preflight puede describir hasta cuatro referencias concurrentes, manteniendo cuotas de cache/inflight64M y32. Comprobar error/abort/presupuesto al recibir cada resultado, detener nuevas tareas pendientes al fallar y no cancelar consumidores ajenos de una promesa compartida. No Promise.all32 sin cota. La prueba de latencia usa respuestas locales demoradas y declara su carácter simulado; no promete tiempos de producción.

El resultado async de una validación no es un permiso de publicar después de otro await: volver a comprobar versión vigente y publicar dentro de la misma continuación síncrona, o una sección de commit equivalente. Dos inserciones concurrentes deben volver a calcular documento completo actualizado y permitir sólo lo que cabe en500capas/50kprimitivas. Conservar guardias de objeto/selección/página y merge de transformaciones confirmadas mientras llegan datos. Una selección mixta chart+vídeo modifica o reconstruye sólo lo necesario: vídeos conservan su registro de reproducción/liberación; no dejar elementos retirados en el pool ni nuevas fuentes sin liberar.

Al remapear datos de otra tabla/revisión, las columnas ausentes deben mostrarse y poder retirarse/reasignarse explícitamente, sin IDs seleccionados invisibles ni adivinar valores o resetear estilo. La tabla accesible distingue columna ausente de celda null. Cancelar deja intacta la capa guardada.
