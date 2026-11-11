# T-11: entrega pública de exportaciones audiovisuales

2026-11-05. Subbloque del árbol de trabajo. T-11 continúa en progreso y el roadmap conserva 7/23 tareas cerradas. La interfaz MP4/GIF y la QA integral del padre siguen pendientes. No acredita despliegue, multimedia Linux, MySQL, R2 real ni publicación social.

## Cambio y alcance

El runner entrega register por partes, difiere y reencola la misma posición hasta completar copia/verificación; solo entonces acepta el medio y ready. Recuperación repara el corte entre checkpoint y reencolado sin duplicar el medio. API, web y MCP comparten envío MP4/GIF, consulta, cancelación y reintento. La señal nativa se escribe sin esperar al mutex del worker, después del fencing de BD; un fallo de señal devuelve503 seguro y permite repetirla. El borrador API/MCP exige ambas capabilities y conserva la marca original. El catálogo MCP pasa a40 herramientas.

## Revisión independiente — intento 1

Freeze: `storage/framework/testing/t11-public-av-review/attempt1/manifest.json`, 20 fuentes y diff de1019líneas contra baseline preservada. Revisión `custom-agents:adversarial-review`, agentes frescos A/B/D; selector C=false, D=true (SchedulerServer/ExportDesign/migración). Scope-check --baseHEAD:0fuera,0avisos,0exclusiones de usuario, exit0. Model-tier no localiza agents/ en la instalación parcial; modelo heredado. Sin Jira activo, cobertura mínima ni interop aplicable. No se promueve conocimiento.

| Criterio | A | B | D | Evidencia |
|---|---|---|---|---|
| register por partes hasta ready | ✓ | ✓ | ✓ | Queue:30, Runner:30, DeliveryTest:50 |
| Duplicados y recuperación de entrega | ✓ | ✓ | ✓ | DeliveryTest:50,67 |
| Índice sin ordenación temporal | ✓ | ✓ | ✓ | Migración150000:31, DeliveryTest:83, probeD |
| Envío nativo tras commit, estáticos conservados | ✓ | ✓ | n/a | Renderer:29,62; PublicTest:55,199 |
| frame_times y transparencia rechazados | ✓ | ✓ | n/a | Renderer:36; PublicTest:95 |
| Consulta y DTO sin estado privado | ✓ | ✓ | n/a | StudioExports:135; ManageDesignExport:59; PublicTest:160 |
| Cancelación idempotente, señal sin mutex y error seguro | ✓ | ✓ | ✓ | Control:16; Storage:16; PublicTest:101,187 |
| Retry nuevo, revisión fijada, ready preservado | ✓ | ✓ | n/a | Control:28; State:458; PublicTest:64,173 |
| Propiedad, marca y permisos por acción | ✓ | ✓ | n/a | APIroutes:11,24,45; ManageDesignExport:24; State:616 |
| Borrador con medios ready sin programación | ✓ | ✓ | n/a | APIcontroller:169; Exports:148; PublicTest:114,206 |
| Público nativo hasta descarga/borrador | ✓ | nativo no ejecutado porB | n/a | Root focal3/3,82aserciones |
| Pruebas y evidencia declarada | ✓ | ✓ | ✓ | Root focal32correctos/307aserciones y3skips; nativos separados |
| RED histórico | No verificable desde raw aportado | n/a | n/a | Ledger registra IDs/cause de3/3,5/5,4/4 RED; padre abierto |
| Alcance y compatibilidad OpenAPI | ✓ | n/a | n/a | scope0; lint0errores/3avisos previos; diff0rompedores/1path nuevo |
| Descriptores actualizados | ✗ | n/a | n/a | A1 Minor, corregido para revisión documental2 |
| Constitución, ADR e interop | n/a | n/a | n/a | Sin constitución ni ADR aplicable; sin commands/agents/hooks |
| Padre abierto y UI pendiente | ✓ | ✓ | ✓ | LedgerT11 y cabecera del contrato |

| # | Grado | Hallazgo | Estado |
|---|---|---|---|
| A1 | Minor | Contrato:53,101,127 decía register no entregado y scheduler/flujo desconectados; deployREADME:38,42 mantenía cola/jobs futuros; README:41 decía39/doceStudio. | Corregido: describe handlers actuales, catálogo40/treceStudio y límites UI/despliegue. Cerrado en revisión documental2. |
| D2-1 anterior | Minor | Índiceflag con TEMPsort en SQLite sin estadísticas. | D confirma corregido mediante índice compuesto y EXPLAIN real; no afirma rendimiento constante del histórico. |

B no encontró defectos. Ejecutó con almacenamiento propio Delivery+Public:17correctos/110aserciones/3skips y Queue+MCP+Renderer:43correctos/322aserciones/4skips. D ejecutó Delivery+Public:17correctos/110aserciones/3skips. Son comprobaciones de revisión, no QA final ni ejecución nativa. A ejecutó scope/lint/diff/hashes; no suplió pruebas faltantes.

## Probe de recuperación

El revisor D ejecutó inline un SQLite3.53.2 recién migrado, sin ANALYZE ni sqlite_stat, con50000exportsready y25workspaceshuérfanos. Capturó la consulta real de Recovery.run y verificó `SEARCH studio_av_workspaces USING INDEX studio_av_recovery_due (media_retiring=? AND active_until<?)`, subconsulta de export por PK y ausencia de TEMPB-TREE. Cien consultas devuelven25huérfanos: mediana8,6663ms, p959,2644ms. Persisten comprobaciones por PK de históricos vencidos; no es regresión introducida. No es benchmark MySQL ni prueba de ausencia de starvation. Resultado transcrito de la revisión; el probe y stdout no se guardaron como archivos raw.

## Verificación root

Antes de la revisión: API MP4/GIF y MCP MP4 atraviesan worker database real hasta ready, descarga con SHA igual al assemble y borrador de la marca con el mismo medio:3/3,82aserciones (`19966c`/`e70c9e`). Entrega+public+MCP sinoptin:32ejecutadoscorrectos/307aserciones/3nativosomitidos (`c1eeb7`). No se cuentan los3skips como ejecutados en esa pasada. Pint aplicado, OpenAPI0errores/3avisos previos y diff0rompedores/1path nuevo.

Regresión final root: **369/369,2046aserciones,0fallos/errores/skips**,48562ms (`33c118`). Ocho casos nativos reales/193aserciones: dos worker MP4/GIF hasta registro durable ready; dos música real (MP4 mezcla/tono medido y GIF omite audio) hasta assemble; un puenteLaravel MP4 con copia/aceptación manual; tres entradas públicas API MP4/GIF y MCP MP4 hasta descarga/borrador. Los otros casos incluyen fakes y no se etiquetan como nativos. `php-result.json` se derivó del JUnit; se corrigió solo su clasificación de nombres para excluir bridges simulados e incluir los ocho tests con optin explícito, sin repetir la suite (`317071`).

55SHA iguales antes/después. Sesión74561/PID18492 terminal, helper storage/framework/testing/t11-public-av-verify.py. Ninguna nueva repetición sin cambios/fallos que la justifiquen. JS/UI no cambiaron en este subbloque: frontend153 yE2E42 previos conservan su alcance histórico y no se han repetido ni se atribuyen a los handlers nuevos. QA ejecutada por root más revisión independiente, no QA final independiente del padre. Sin ONNX, MySQL, VPS, R2 real ni publicación social.

Artefactos raw y hashes en [manifest.json](manifest.json). Los hashes de fuentes cubren el subbloque y sus dependencias comprobadas, no toda la aplicación. No se cierra T-11 ni la auditoría final ClaudeCLI por superar esta regresión.


## Revisión documental — intento 2

A2 fresca leyó el delta completo de134líneas en cinco documentos;23SHA coincidentes, scope--baseHEAD exit0,504WIP/0fuera/avisos/exclusionesusuario. Veredictos A/B/D1 preservados para código/tests/routes/OpenAPI sin cambios; no se pidió ejecutar pruebas para suplir evidencia.

| Criterio corregido | Veredicto | Evidencia |
|---|---|---|
| Register entregado por cola hasta ready | ✓ | Contrato53/109; Runner30–66; State430 |
| Limpieza/recuperación conectadas | ✓ | Contrato127; Recovery30/66/104/124; consola32 |
| Retiro físico antes de liberar | ✓ | Contrato53; RegistrationCleanup38–67 |
| Cola actual y timeout100s | ✓ | DeployREADME38/42; Job17/21; unidad14 |
| Catálogo40/27social+13Studio | ✓ | README41; MCP122; servidor64–103 |
| Acciones compartidas y permisos | ✓ | MCP122; ManageDesignExport55–62; controladoresAPI153–170/web182–198 |
| UI/QA integral y VPS pendientes explícitos | ✓ | Contrato3/101; guía3/309; deployREADME42 |
| Veredictos previos preservados | ✓ | PHP/tests/rutas/OpenAPI SHA sin cambios |

A1 cerrado;0Critical/Important/Minor pendientes. REDhistórico sigue no verificable desde raw aportado, sin presentarlo como evidencia nueva. La regresión root anterior concluyó después del snapshot leído porA2; sus salidas se registran arriba, sin atribuir esa ejecución al revisor.
