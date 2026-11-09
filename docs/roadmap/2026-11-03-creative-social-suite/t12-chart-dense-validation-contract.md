# T12: validación focal de gráficos densos

Plan de evidencia para E2E28.04 íntegro, tres viewports1440/375/768, un worker/cero retries/maxFailures1. Namespace privado `t12-chart-dense-qa1/out/dense-execution1`, salida única; no client/server/consumer, merge ni PASS global. SOURCE_DONE conserva históricamente las cinco fuentes previas; BUILD7_REVISION.json registra la adaptación explícita del runner y de la expectativa de wiring a build7, tres auxiliares idénticos y baselines preservados. BUILD7_WIRING.json acredita el control actualizado1/1; GREEN43 original no se repite ni reetiqueta. Todavía sin colección/preparación/nativos. El build7 está verificado62inputs/77assets; revisión fresca del contador/oráculos/operador antes de preparar, y rechazo de cualquier deriva posterior.

Fixture original1000filas×12series/13columnas, valores1+(r+c)%8,12000barras por caso. No sustituirla por menos filas ni afirmar estrés52MB/LRU completo. El documento guardado respeta2MiB y los límites de capas.

| Evidencia total | Criterio |
|---|---|
| JSON original3tests y un blob | Cada test un resultado passed, proyectos/selector04 exactos,0errors/skips/flaky/retries/interrupted |
| 3SVG+3PNG y3recibos de entrega | bytes/SHA iguales a archivos reales; Blob declara límites de observación de headers, sin GET auxiliar |
| 3observadores DOM+3XML independientes | Cada SVG contiene12000IDs únicos layer:bar:r0..r999:s0..s11,36000en total; álgebra/colores/orden/gaps/cero contra fixture, sin importar geometría del producto como esperado |
| 3raster PNG independientes | Qdense_raster conserva cobertura fraccional/source-over y umbrales media≤6,p95≤18,mínimo100píxeles coloreados/1000canales, exclusión existente de centros ejes/rejilla±2px |
| 3capturas reales | Firma/CRC/ancho y posterior inspección directa; no crédito por existencia sin ver |
| 6negativos de presupuesto | Por caso page/document, ambos POST422 ruta exacta diseños, mensajes/claves correctos; page1página/2capas, document9páginas/1capa,≤500capas/página/<2MiB, aislando presupuesto de primitivas |
| 3scope/red/viewports/fonts | rows1000/series12/bars12000,large_52MB_stress=false,quota_lru_scaled_policy_only=true; issues=[],exactamente2POST422esperados/caso,0abortos,fonts=[] |

El caso actual tiene cero comparaciones de preview-píxel, tipografía privada/glifos, axe, PDF de diseño o materialización nativa. No inventar esos recibos ni llamar a mains globales36/33/30.

La fase observe ejecutará sólo una vez las funciones XML/PNG reales después de corePASS, dentro del mismo guard/plazo. El recibo incluye SHA de cuatro inputs por caso y de fuentes del oráculo antes/después. El cierre sólo verifica integridad/recibo y no repite raster costoso. Toda falta, exceso, fallo o deriva invalida el resultado.

Job3GiB COMMIT agregado, preflight físico6GiB antes de UUID/seed/server, piso3GiB/100ms,Node512/PHP256,900s compartidos incluyendo finalize inmediato; no900s por subfase ni ampliaciones. El coste real de36000barras/medición/raster queda pendiente de medir; el plazo puede fallar y no se relajará para obtener verde. Preservar aislamiento antes de Kernel, sólo loopback y fixture CSS externa exacta; no datos reales/.env/proveedores.

Antes del nativo: contrato/rootACK, fuentes congeladas, build actual revisado, colección3/storage0, AST y controles puros significativos, scope/lentes frescas y manifiesto flat completo actual. RED del contador ausente y GREEN43controles son sólo pruebas puras; no prueban todavía los oráculos nativos ni el producto. T12 permanece abierta.
