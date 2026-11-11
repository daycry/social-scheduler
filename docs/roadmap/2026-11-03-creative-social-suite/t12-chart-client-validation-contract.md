# T12: validación focal del cliente de gráficos

Contrato adoptado por root el 2026-11-07 para E2E28.01 íntegro, tres viewports 1440×1000, 375×812 y 768×1024. Se conserva el objetivo completo del roadmap: esta tanda cubre diez variantes de barras/columnas y no acredita otras familias ni cierra T12. Propuesta de origen: `storage/framework/testing/t12-chart-client-plan1/proposal.md`, SHA256 `753d333d338aceaeab0a0a593211285d896f35c54974c54b7d9e49d9ad87c57c`. Root leyó la propuesta completa; se adopta sin recortar tipos, controles, fuentes, preview ni viewports.

## Fuentes y ejecución

Nuevo operador `storage/framework/testing/t12-chart-client-qa1/`, cinco fuentes: `run.py`, `playwright.config.ts`, `recount.py`, `probe-pure.py`, `probe-ast.cjs`. Reutiliza infraestructura Q revisada, sin editar producto/spec/helpers/Q. Salida única `out/client-execution1`; selector fijo `(?:^|\s)E2E28\.01(?:\s|$)`. Un worker, retries0, maxFailures1, timeout240s/test y global850s. Revisión fresca antes de preparar y autorizar una ejecución real; no retry automático, merge de resultados ni matriz completa. Build7 debe seguir vigente 62inputs/77assets, sin recompilar.

Conservar las diez variantes originales, dataset fijado a revision1/SHA, título Unicode, controles, edición/deshacer, guardado/reapertura, exportaciones reales y eliminación por history entre variantes. JSON original y un blob oficial: exactamente tres tests, una ejecución passed/retry0 por test y cero errores/skips/flaky/interrupted. Un fallo o interrupción es NO-PASS con evidencia preservada.

## Cardinalidades y umbrales

Fixture original 4filas/3columnas, JSON289bytes UTF8; s0=[10,20,null,0], s1=[20,40,null,0], categorías duplicadas/Unicode y categoría numérica2026 tipada. Null se omite y cero permanece semántico. Column/bar y multicolor tienen3barras; grouped/stacked/proportional tienen6. Total48barras/caso,144grupo (96positivas+48ceros;108raw/36percentage).

| Evidencia del grupo completo | Requisito |
|---|---|
| 30SVG/30PNG/30deliveries | Originales del navegador, bytes/SHA/formato exactos; Blob conserva límite de cabeceras, native_response:null, sin GET sintético |
| 30DOM+30XML independientes | 48barras+30ejes porcaso,234formas grupo; IDs barra/ejes separados, geometría<=.025 y matrices<=.0001 |
| 30PNG independientes | CentroRGB<=3, amplitud<=2, ocupación>=100píxeles; ceros sin muestra central positiva; sin dense_raster para esta fixture |
| 30previewUI+30previewRender+30crop | 30comparaciones reales,800×500=400000píxeles/caso,12millonesgrupo; máximoRGB<=4/media<=.25, sin cambiar crop/fixture |
| 60PNG referencia+30glyphJSON | OriginalTTF yArial porvariante; IoU>=.8 y margenArial>=.03, sin rebajar tolerancias |
| 3subidas TTF+3metadata+3fontresponses | 30WOFF2embebidos enSVG; respuestas WOFF2 dinámicas>=1/caso, archivos1:1 conentries,200/MIMEfont/woff2/firmawOF2/bytes/SHA coincidentes conmetadata |
| 150PNG deterministas | 30export+30previewUI+30previewRender+60referencias; captura de fallo no cuenta como PASS |
| 6checks explícitos de ticks | Sólo column ybar×3, siete etiquetas[-100,-66.7,-33.3,0,33.3,66.7,100] y>=12px efectivos; no atribuir estas aserciones a los proporcionales |
| Red/viewports/cleanup | issues=[],expectedHTTP=[],intentionalAbort=[],hold-cleanup=[]; nativos/HTTP ajenos ausentes o vacíos |

Conservar identidades, valores, stack/proporciones, paleta, escala, zero/ejes, orden/gaps/grosor, contención, categorías/leyendas y tinta Canvas finita dentro de bandas. observeSvg espera FontFace y carga efectiva de texto privado; no reemplazar tinta por BBox ni excluir ticks/títulos/fuentes. Cada input/oráculo guarda SHA y procedencia. Los mains globales Q de12casos/36pares/33glifos/30preview no se ejecutan; se usan sólo las funciones específicas del grupo.

## Límites de afirmación

No hay PDF de diseño, procedencia PDF, materialización nativa, archivo documental persistido, tabla raw/percentage archivada, axe ni recibo dedicado native-font-readiness. Son cero artefactos de esta tanda. Guardar/reabrir compara chart en memoria y los oráculos usan tabla fuente, sin acreditar tabla accesible por archivo. Las comprobaciones reales de FontFace/observeSvg no se convierten en un recibo adicional server/native.

## Observación, recursos y cierre

`recount.py --observe` sólo después de corePASS real y una sola vez para los30pares XML/PNG/glifo/preview. Recibo inmutable con resultados, tiempos reales, hashes de módulos y todos los inputs/outputs. Finalize comprueba cobertura e integridad y no vuelve a decodificar/rasterizar. Deriva, faltantes, excesos, oráculo fallido, timeout o aborto invalidan el resultado.

Job Windows3GiB COMMIT agregado/killOnClose/sinbreakaway; preflight físico>=6GiB antes UUID/seed/server, piso>=3GiB cada100ms,Node512/PHP256. Plazo900s TOTAL desde startup incluyendo oráculos/gates/cierre/finalize. Global850s no amplía900. Cierre inmediato con tiempo restante, core muerto, procesos propios vacíos y8080IPv4/IPv6libres. Datos ficticios/loopback/sin.env ni servicios reales. Root conserva único slot pesado/build/PDF/docs.

Estimación300-700s sin medición actual; riesgo real de superar240s/test o900total. No reducir contenido ni ampliar límites para conseguir verde. Si falla, conservar resultado y diagnosticar antes de otra decisión. No ejecutar ni preparar durante escritura/revisión de fuentes.

## Preparación y evidencia pura requerida

Primero probe significativo con RED de contador ausente; luego GREEN sobre estructura original y negativos: SVG/PNG/tipo/proyecto/barra/eje faltante/extra, orden categorical/subserie, metadata/WOFF2/SHA/ficheros de respuestas divergentes, glyph/preview fuera umbral, recibo obsoleto/modificado, blob/results/retry/aborto/red incorrectos. Probes no crean runtime ni colección. Tras fuentes congeladas y A/B/C/D según selector, root prepara una vez: colección3/storage0/sentinel ausente; manifiesto completo actual incluye evidencias consumidor3/title6/Dense y fuentes. Ningún PASS puro sustituye resultado nativo. T12 abierta,8/23cerradas.
