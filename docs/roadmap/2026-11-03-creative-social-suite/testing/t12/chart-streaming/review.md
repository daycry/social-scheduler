# T-12: codec streaming CSV/TSV — revisión 1

2026-11-06. Subbloque aprobado A1/B1, 0 Critical/Important/Minor. T-12 sigue abierta: no acredita recursos por marca, editor/gráficos, XLSX/conectores, documentos/presentaciones/pizarras ni Office. [Contrato previo](../../../t12-chart-data-contract.md), [TDD y comandos](raw/tdd-evidence.md), [diff exacto](raw/promotion/final-two-files.patch). No hay nuevos endpoints/OpenAPI ni generados.

## Verificación real

Pest sobre las dos fuentes promovidas: **67/67,187 aserciones,14,504s**, PHP256M,pico asignador58.720.256B,exit0. Incluye57 casos anteriores intactos y10 nuevos. Pint posterior modifica sólo formato; comparación independiente acredita tokens semánticos iguales. No se afirma nueva ejecución Pest después de Pint. [Salida real](raw/promotion/real-tests.txt), [Pint](raw/promotion/pint-final.txt), [equivalencia](raw/promotion/format-equivalence.txt).

CSV25.000.000B real,1000×100,roundtrip SHA `20f9d6b6754f93fb94fc301a0e137392bfed36673509703943d5f2e698c040a1`, forma/extremos y entrada+1 correctos. JSON52.000.000/+1 medido por codificador nativo por registros independiente. Los picos PHP no son RSS ni prueba de memoria de futuros handlers. Cinco RED individuales por comportamiento ausente o incorrecto antes de implementación, preservados en raw. Caracterizaciones adicionales se identifican sin inventar RED.

## Lente A — tabla completa

S=`app/Services/Studio/StudioChartData.php`; T=`tests/Feature/Studio/StudioChartDataTest.php`. A2 del núcleo anterior se conserva sin reabrir criterios aprobados. Scope0 sin fuera de alcance/avisos/info/exclusiones de usuario. Selector C=false,D=false. Modelo heredado por instalación parcial del plugin; sin constitución/dev.json/cobertura mínima.

| Criterio | Veredicto | Evidencia |
|---|---|---|
| Dos PHP autorizados, diff completo | ✓ | Patch aplicado en memoria reproduce fuentes y hashes de baseline/final. |
| Inline2.097.152B entrada/JSON | ✓ | S17–28,218; T222–226,258–288. |
| Dataset25.000.000B entrada/52.000.000B JSON | ✓ | S21,28,218; T314–365. |
| Perfil cerrado, desconocido rechazado | ✓ | S17–23; T229–230. |
| Wrappers y firmas anteriores preservados | ✓ | S114–116,282–289;57 tests intactos. |
| importChunks/exportChunks | ✓ | S120,270;T212,296. |
| Sólo chunks string, vacíos admitidos | ✓ | S213–221;T252–255. |
| Chunk completo cobrado antes de procesar/reanudar productor | ✓ | S217–220;T258–266. |
| BOM/Unicode/comillas/CRLF entre chunks | ✓ | S130–179,223–241;T212–219,243–250;probe propio3/3. |
| Campos completos, CRLF interiorLF/CR suelto error | ✓ | S133–154,182–194;T243–250. |
| Registro incluye encabezado y multiline no aumenta registro | ✓ | S132,183,189,192,205;T192–209,243–250. |
| Esquema cerrado/listas/IDs/tipos finitos | ✓ | A2 preservada;S40–105,T18–82. |
| Límites1000×100 y Unicode | ✓ | S47,57,74,103,158,248–252;T75–82,117–131. |
| Contador incremental sin JSON de tabla completa | ✓ | S50–79 codifica sólo estructura/columnas/celdas. |
| Flags JSON, claves, escapes y números | ✓ | S15,58,69,78;T269–293 contra json_encode nativo. |
| Rechazo antes de cola virtual que excede presupuesto | ✓ | S59,70,79;T233–240. |
| Frontera52.000.000/+1 independiente | ✓ | T345–365;probe A JSON nativo completo bajo256M. |
| CSV25.000.000B/hash/forma/extremos y+1 | ✓ | T307–342;salida real67/67. |
| Export por registros y wrapper equivalente | ✓ | S270–289;T296–304,CSV/TSV±header. |
| Fidelidad lexical/espacios/fórmulas, sin inferencia | ✓ | S174,199–202,292–303;T85–103,133–178. |
|57 tests anteriores conservados efectivamente | ✓ | Primeras210 líneas iguales; exactamente10 nuevos sin parametrización. |
| RED/GREEN auténticos y caracterizaciones explícitas | ✓ | Lectura de cinco RED individuales y evidencia. |
| Fuentes reales verificadas, formato posterior trazable | ✓ |67/187 exit0;Pint y token comparator propios exit0. |
| Pureza sin DB/red/filesystem/endpoints | ✓ | Lectura completa S1–305. |
| T-12 completa permanece abierta | ✓ | Ledger/contrato conservan criterio integral sin marcar. |
| Constitución/cobertura/OpenAPI | n/a | Ausencia de constitución/dev.json; sin spec API tocada. |
| Generados e interopE2/E3 | n/a | Sin commands/agents/hooks tocados. |
| Conocimiento ADR del área | ✓ | Consulta sin aciertos,exit0,sin escribir índice. |

A ejecutó patch/SHA/preservación de tests, equivalencia de tokens y probes JSON52M/splits; no repitió CSV25MB ni Laravel/DB/red. 0gaps.

## Lente B — tabla completa

| Criterio | Veredicto | Evidencia |
|---|---|---|
| Regresiones CSV/TSV | ✓ | S120–205;234.372 casos diferenciales contra baseline,chunks1/2/3,±header:0 diferencias. |
| BOM/Unicode/CRLF/comillas/diagnóstico | ✓ | S130–242;parte de517 comprobaciones independientes,0fallos. |
| JSON exacto y rechazo temprano | ✓ | S50–79,217–219;18 fronteras2MiB/+1 nativas con escapes/claves/números y productor sin reanudar. |
| Tipos finitos y export lexical | ✓ | S71–79,270–303;equivalencia de generador/wrapper e inputs inválidos. |

Dos probes PHP256M por stdin,exit0; segundo pico asignador16.777.216B. SHA actuales coinciden. No repitió67/25MB/52MB; leyó su evidencia. Sin defectos,0 Critical/Important/Minor.

## Fusión y límites

A+B aprobadas, sin rebatidos ni deuda. No nuevos endpoints, por lo que no corresponde aún descarga CSV segura para hojas: el codec conserva texto de fórmulas, una hoja externa puede evaluarlo. El perfil dataset sólo se elige en código servidor; el documento Canvas2MiB no cambia. Se mantiene pendiente la integración de recursos, gráfico editable y preview/export reales.
