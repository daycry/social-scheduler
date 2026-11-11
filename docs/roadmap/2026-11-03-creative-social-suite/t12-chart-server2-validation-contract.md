# T12: segunda validación focal del servidor

Contrato adoptado por root el2026-11-07 tras cerrar el guard del cliente1. La propuesta original SHAe13f334616b5f4aa4b31cba378ddfefab08e0042a22d775cba8e3e373ea93980 se conserva inmutable enserver-qa2/proposal.md. El cuerpo contractual se copia abajo; su párrafo inicial describe el momento de propuesta, no el estado actual.

**Estado actual:** seis fuentes privadas preparadas/SOURCE_DONE,REDporcontadorausente→GREEN86puros(incl11controles) yAST/wrapper7;0prepare/collection/runtime/nativos/PDFreal. Config yobserve-pdf son copias byteidénticas de los anteriores. Falta revisión fresca del conjunto completo. Objetivo pendiente: revalidar servidor02 íntegro sobre el build real posterior aVL1/VL2, sin alterar nueve descargas ni oráculos.

**Cambio de contexto obligatorio antes de preparar:** client1build7pasó3/3automático yfalló aceptación visual(VL1etiquetasocultas/VL2porcentajesyleyenda). Implementer corrige producto bajo t12-chart-value-labels-contract.md. Las fuentes actualesserver2siguen fijadas a7: no declarar build8 ni cambiar literales/proofs hasta tener revisión y receipt real nuevo. La adaptación futura de runner/probe/contexto deberá preservar SOURCE_DONE86/7histórico,baselines y requerir revisión explícita. No ejecutar los probes anteriores para reescribir sus resultados.

Sin modificación del alcance totalT12/23tareas. Root único docs/ledger/build/nativos; los agentes sólo sus fuentes asignadas. La autorización ROOT_ACK de preparación de fuentes no autoriza prepare/collection/browser/DB/PHP/PDFreal ni ejecución.

---

# Servidor02 sobre build7 — propuesta pendiente de ACK root

Sólo lectura y propuesta en este namespace nuevo. Cero fuentes de operador creadas, cero prepare/colección/runtime/nativos. El operador servidor anterior está en `t12-chart-focused-qa1`, con recount embebido en `run.py` (no existe `recount.py` allí). Su informe `testing/t12/server-focused-qa1/report.md` acredita build3 histórico y queda inmutable. No transferir sus métricas ni14/4probes a una ejecución actual. Root conserva el único slot pesado para cliente1.

## Delta y ejecución futura

Revalidar íntegro el escenario público E2E28.02, sin editar spec/helpers/producto/Q: desktop1440×1000, mobile375×812 y mobile-360768×1024; selector fijo `(?:^|\s)E2E28\.02(?:\s|$)`. Salida nueva única `storage/framework/testing/t12-chart-server-qa2/out/server-execution2`, sin retry, merge ni reescritura histórica. JSON Playwright original + un blob oficial,3casos con una ejecución passed/retry0, cero errores/skips/flaky/interrupted. Un worker/retries0/maxFailures1,240s/test,global850s.

Build7 vigente62inputs/77assets necesita verificación actual en la preparación futura por root, sin recompilar. Readiness/footer/títuloY son cambios revisados según contexto root; esta propuesta no los reconstruye ni les atribuye resultados nuevos. El mismo camino estático necesita SVG/PNG/PDF actuales después de esos cambios. Y-title «Valores» debe conservar ID/texto/fuente privada y mostrar orientación−90°/escala uniforme/matriz y tinta finitas en el SVG entregado; la fixture800×500/font18 permite comprobar legibilidad efectiva específica sin generalizar un mínimo a títulos arbitrarios. El spec02 no genera un recibo dedicado de paint/readiness nativo:0, no inventarlo. No repetir el foco title6 como si perteneciera a esta tanda.

## Cardinalidades reales del cuerpo02

Usa `fixture({negative:true})`,4filas/3columnas, column-stacked con s0/s1, raw, dominio[-100,100]. Porcaso6barras semánticas:2positivas+2negativas+2ceros; null omitido. Grupo18barras (6de cada signo/cero),9ejes,27formas XML barra/ejes. Categorías Unicode/duplicadas y numérica2026, leyenda2series, geometría/stack/paleta/orden/gaps, tinta Canvas y controles se conservan.

| Artefacto/comprobación | Porcaso | Grupo |
|---|---:|---:|
| SVG/PNG/PDF entregados + deliveries JSON |1/1/1+1 |3/3/3+3;9descargas |
| SVG DOM+analítico / XML independiente / PNG independiente |1/1/1 |3/3/3 |
| OriginalTTF/Arial referencePNG + glyphJSON/check |2+1/1 |6PNG+3JSON/3checks |
| server-static UI PNG |1 |3 |
| PNG deterministas totales |4 |12:3export+6referencias+3UI |
| font-upload-source / font-metadata / font-responses JSON |1/1/1 |3/3/3 |
| native-materialization JSON |1lista×3formatos |3listas/9materializaciones |
| viewport/network/actor-network/hold-cleanup JSON |1de cada |3de cada |
| PDF observer JSON + RGB extraído / procedencia raster |1+1/1 |3+3/3 |
| Preview píxel/crop, axe, tabla archivada, documento archivado, tick-readability explícito |0 |0 |

Aquí se sube **WOFF2**, no TTF:3recibos de upload que deben igualar bytes/SHA del fixture WOFF2 original. El TTF original se lee sólo como referencia independiente de glifos,3veces, después de las entregas. SVG contiene WOFF2 coincidente con metadata en cada caso. Respuestas privadas dinámicas>=1/caso, archivos1:1; TODAS las respuestas del mismoID/ruta deben coincidir con metadata SHA/size, además200/MIMEfont/woff2/firmawOF2 y SHA/bytes del archivo real. No imponer3o30respuestas ni heredar el antiguo any de coincidencia parcial.

## Pinning, autorización y procedencia

Conservar la secuencia íntegra: dataset negativo revision1 fijado; PUT posterior con tabla positiva y DELETE/archivado; segunda página `not-selected` con otro dataset; PATCH del documento; render explícito sólo de la página original para cada formato. Materialización debe mostrar exactamente3formatos/caso, documentoSHA común, una página seleccionada y una referencia chart porformato: mismo id/revision1/SHA del recurso original, observed_bytes=bytes y observed_sha256=sha256, una fuente con id de metadata. No materializar la página oculta ni su referencia. Contrastar los9registros con JSONL pasivo real; no crear recibos de API que el cuerpo no guarda.

Los GET contra marca equivocada y POST render de otro actor conservan404: porcaso exactamente1expected_http en contexto principal (GET /brands/other/studio/datasets/pinned/table) y1en actor (POST /studio/designs/design/render), declarados y assertados por el spec; issues=[]/intentional_aborts=[] y hold-cleanup=[]. `isolation.assess` publica respuestas emparejadas, no el token `asserted`: no exigir un campo inexistente en el recibo. No tratar esos dos404 previstos como fallo, ni permitir excepciones adicionales.

Cada entrega HTTP requiere bytes/SHA iguales al archivo y al observador pasivo, status/upstream_status200, upstream_ended/response_finishedtrue, cabeceras attachment y MIME específico del formato. Los9eventos download deben corresponder1:1 con las9entregas; sin GET sintético ni reutilizar URLs/recibos históricos.

PDF: `pdf-observer.observe` conserva firma%PDF,1página,1XObjectRGB8bits dibujado realmente, tamaño de página=.75×raster y ausencia de capa textual BT/Tj/TJ. `pdf-provenance.observe` conserva igualdad de TODOS los píxeles RGB extraídos frente al PNG entregado con composición alfa sobre blanco. El resultado histórico810×810pt/1080×1080 y sus SHA no son constantes de aceptación nueva: verificar dimensiones actuales coherentes con SVG/PNG/documento, no asumir bytes idénticos a build3. Cada PNG debe seguir verificando las barras positivas/negativas visibles; ceros no dan muestra central. Glifos conservan IoU>=.8/margenArial>=.03 con thresholds originales obligatorios y box del título original. PDF de diseño raster: no afirmar texto extraíble/vectorial. PDF del informe es trabajo posterior exclusivo de root, fuera de la colección de diseño.

## Reutilización y adaptación propuesta

Reutilizables byteidénticos, sin copiar ni editar: spec02, helpers, fixture/oráculo analítico, infraestructura Q aislamiento/serve/setup/guard/proceso/php y funciones Q XML/PNG/glyph/PDF/provenance. El wrapper `playwright.config.ts` anterior puede copiarse byteidéntico (mantiene JSON y añade blob). `observe-pdf.cjs` anterior también puede copiarse byteidéntico: exige exactamente3PDF y llama sólo función Q.observe. No ejecutar mains globales36/33/30. Toda reutilización se vuelve a hashear en el freeze futuro; sus resultados antiguos no son reutilizables.

Propuesta de **seis fuentes** nuevas tras ACK: `run.py`, `playwright.config.ts`, `recount.py`, `observe-pdf.cjs`, `probe-pure.py`, `probe-ast.cjs`. El sexto wrapper mantiene visible y revisable la llamada Node/PDF sin código nativo opaco en un string. `recount.py` absorbe la lógica focal de `observers.py` anterior, endurece cobertura/procedencia exactas y separa observación de cierre. `run.py` adapta build3→build7, namespace/output2/frase específica, manifiesto actual y protocolo de observación única; no usarlo como simple cambio de etiqueta del operador antiguo. Probes son actuales y requieren RED real antes del contador nuevo, VFS completo y negativos; el anterior probe de build3 no se ejecutará.

Después de corePASS real: una observación por cada XML/PNG/glifo,3PDF y3procedencias. Recibo inmutable con SHA de módulos y TODOS los inputs/outputs, tiempos reales y cobertura exacta. La extracción PDF produce3RGB+3JSON sólo una vez. Finalize verifica el recibo/SHA, metadatos y cierre, sin volver a decodificar PNG/PDF ni rasterizar. Ausencias/extras, fuente divergente, byte/ruta/actor/página equivocados, métricas fuera de umbral, reintento/aborto o deriva ⇒NO-PASS.

## Recursos y siguientes puertas

Mismo Job agregado3GiB COMMIT/killOnClose/sinbreakaway; preflight físico>=6GiB antes UUID/seed/server, piso>=3GiB/100ms, Node512/PHP256.900s TOTAL incluyendo startup/oráculos/gates/cierre/finalize, global850/test240 sin ampliación. Runtime ficticio propio, loopback, sin.env/proveedores reales. Finalize sólo tras core muerto/puertos8080v4/v6libres y dentro del tiempo restante. Una salida, ningún retry automático. El servidor nuevo no se ejecuta mientras cliente ocupa el slot.

Referencia temporal exclusivamente histórica: servidor1 core92.049s/plazo174.923s, informe build3. Orientación no medida para server2:150–400s;9renders estáticos y3PDF+raster pueden variar. Preservar límites si no cabe, conservar evidencia y diagnosticar; no reducir contenido. Alcance sólo02, sin acreditar10variantes×3, otras familias, AV/52MB ni cerrar T12.

Tras ACK root: implementar sólo este namespace, RED/GREEN puros y AST, fresh review/freeze; después root podrá preparar colección3/storage0/sentinel ausente y decidir una ejecución. En esta entrega no se ejecuta ninguna de esas fases. `provenance-read.json` registra fuentes leídas, no un manifiesto READY/build actual ni permiso nativo.
