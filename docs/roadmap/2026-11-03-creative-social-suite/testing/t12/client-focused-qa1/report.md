# T12: cliente de gráficos, diez variantes completas

**AUTOMÁTICO: FOCAL-PASS. VISUAL: NO-PASS (VL1 pendiente). Casos realmente ejecutados: 3/3; registros del JSON original: 3.** Tres viewports previstos:1440,375 y768px. Un worker, cero reintentos, fail-fast1. T12 sigue abierta y el roadmap conserva8/23 tareas cerradas. No se reconstruye una matriz con resultados anteriores.

## Qué comprueba el escenario original

Diez variantes de barras/columnas por viewport con todos sus controles, dataset fijado a revisión1/SHA, título Unicode, edición/deshacer, guardar/reabrir, exportaciones y eliminación por historial entre variantes. La fixture conserva cuatro filas, categorías repetidas y numérica, null y cero distintos. Son48barras semánticas por caso y144en el grupo completo,96positivas y48ceros.

El grupo PASS exige30SVG y30PNG entregados,30observadores DOM y30XML independientes;30verificaciones raster,30comparaciones de glifos contra TTF original/Arial y30comparaciones de preview render800×500 con el recorte del PNG exportado. Conserva150PNG deterministas:30exportados,30capturas UI,30preview render y60referencias de fuente. Los oráculos leen los archivos originales una sola vez; finalize comprueba recibo/SHA sin repetir raster.

XML conserva234formas:144barras y90ejes. Raster exige color central RGB≤3, amplitud≤2 y ocupación de paleta≥100píxeles. Glifos IoU≥.8 y ventaja sobre Arial≥.03; preview12millones de píxeles, máximoRGB≤4 ymedia≤.25. Se comprueban seis conjuntos de siete ticks legibles≥12px, sólo para column/bar. No se atribuye ese check a los proporcionales.

Tres subidas TTF originales; cada SVG embebe la WOFF2 registrada. Respuestas privadas de fuente con cardinalidad dinámica≥1por caso, archivos1:1, status200/MIME/firma/bytes/SHA y TODAS coincidentes con metadata. A1/B1 del validador se corrigió antes de esta ejecución, con RED negativo y positivo de múltiples respuestas idénticas; los71checks puros yAST5 no se suman como pruebas nativas.

## Recursos y cierre

Runtime ficticio aislado, loopback ybuild7actual62fuentes/77assets, sin recompilar. Sin .env ni servicios reales. Job Windows3GiB COMMIT agregado, preflight físico6GiB y piso3GiB cada100ms,Node512MiB/PHP256MiB,900s totales incluyendo startup/oráculos/cierre. Núcleo266.324s,COMMIT754782208B,mínimo físico6269128704B;fallo de recursos:None. Global266.9880751000019s. Clausura0.4968267000222113s. PID30920terminado,procesos propios vacíos y8080IPv4/IPv6libres. 1360fuentes protegidas verificadas antes/después y tras PDF/inspección; este informe se genera después en otro Job.

## Resultado y límites

Los tres cuerpos completos y sus recibos independientes han pasado. Glifo:IoU mínimo1.0, margen mínimo0.7174959871589085. Preview:errorRGB máximo0, media máxima0.0.

Cero PDF de diseño, materialización nativa, archivo de tabla raw/percentage, axe o recibo dedicado native-font-readiness acreditados por esta tanda. El PDF adjunto es un informe, distinto de exportar PDF del diseño. No acredita otras familias/configuraciones, AV, Docs, presentaciones, pizarras, XLSX, el roadmap completo ni auditoría globalClaudeCode. Los resultados servidor/consumidor/denso y sus fechas/builds conservan sus alcances propios.

Se preservan todos los inputs originales por caso. Para las figuras se deduplican únicamente PNG exportados conSHAidéntico entre viewports, según export-equivalence.json; se muestran todas las imágenes distintas y una captura UI por viewport. La selección de figuras no sustituye los30oráculos ni declara inspección manual de todos los150PNG.

## Hallazgo visual VL1: etiqueta oculta en apilados

**Important, pendiente de corrección.** Los resultados automáticos3/3 y sus30observaciones independientes son reales, pero no comprueban la oclusión de todas las etiquetas de valores. Esta revisión visual impide aceptar el aspecto del grupo completo.

En column-stacked, fila r0, el valor10 de la primera serie desaparece bajo el segmento verde de la segunda. El observador original registra la tinta del texto, pero el rectángulo posterior cubre el99,9976% de su caja. Los180píxeles interiores del PNG original son verdes (22,128,71),sin píxeles del texto. La lectura de chart-scene.js:156-167 confirma que se intercalan barra y etiqueta dentro del bucle: la barra siguiente se dibuja encima. Una etiqueta «fuera» también puede ocupar otro segmento.

La observación posterior usa exclusivamente el PNG y el recibo DOM originales, con susSHA; no se repitió el navegador ni el raster de referencia. Se adjunta raw/visual-labels-finding.json. Hay que corregir la disposición/orden de etiquetas y añadir una regresión de su visibilidad compuesta; también revisar apilados proporcionales y orientación horizontal. No basta conservar el texto oculto en el SVG o en la tabla.

Este informe es una revisión explícita del primer PDF generado antes de detectar VL1. El primer PDF permanece privado e inmutable, sin aceptación visual. El JSON de ejecución copiado en el namespace de informe es byteidéntico al original y sólo permite la generación posterior del documento; no representa otra ejecución de tests.

## Evidencias visuales

### images/column-export-1440-375-768.png

![images/column-export-1440-375-768.png](images/column-export-1440-375-768.png)

### images/bar-export-1440-375-768.png

![images/bar-export-1440-375-768.png](images/bar-export-1440-375-768.png)

### images/column-grouped-export-1440-375-768.png

![images/column-grouped-export-1440-375-768.png](images/column-grouped-export-1440-375-768.png)

### images/bar-grouped-export-1440-375-768.png

![images/bar-grouped-export-1440-375-768.png](images/bar-grouped-export-1440-375-768.png)

### images/column-stacked-export-1440-375-768.png

![images/column-stacked-export-1440-375-768.png](images/column-stacked-export-1440-375-768.png)

### images/bar-stacked-export-1440-375-768.png

![images/bar-stacked-export-1440-375-768.png](images/bar-stacked-export-1440-375-768.png)

### images/column-proportional-export-1440-375-768.png

![images/column-proportional-export-1440-375-768.png](images/column-proportional-export-1440-375-768.png)

### images/bar-proportional-export-1440-375-768.png

![images/bar-proportional-export-1440-375-768.png](images/bar-proportional-export-1440-375-768.png)

### images/column-multicolor-export-1440-375-768.png

![images/column-multicolor-export-1440-375-768.png](images/column-multicolor-export-1440-375-768.png)

### images/bar-multicolor-export-1440-375-768.png

![images/bar-multicolor-export-1440-375-768.png](images/bar-multicolor-export-1440-375-768.png)

### images/1440-column-preview-ui.png

![images/1440-column-preview-ui.png](images/1440-column-preview-ui.png)

### images/375-column-preview-ui.png

![images/375-column-preview-ui.png](images/375-column-preview-ui.png)

### images/768-column-preview-ui.png

![images/768-column-preview-ui.png](images/768-column-preview-ui.png)

