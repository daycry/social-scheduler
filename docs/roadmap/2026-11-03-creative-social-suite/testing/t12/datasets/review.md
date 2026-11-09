# T-12 datasets: revisión adversarial

2026-11-06. Intento1 del recurso, cinco fuentes nuevas baselineABSENT,712líneas. A+B+D, C=false. Scope0 sinavisos/info/exclusionesusuario. T-12 sigue abierta; sin handlers/UI/MCP/XLSX/editor/Office. [Contrato previo](../../../t12-datasets-contract.md). Cinco SHA y codec aprobado coinciden. Modelo heredado por instalación parcial; sin constitución/dev.json/cobertura mínima.

## Intento1: tablas completas

S=`app/Services/Studio/StudioDatasets.php`;T=`tests/Feature/Studio/StudioDatasetsTest.php`;M=`database/migrations/2026_11_06_010000_create_studio_datasets.php`.

| Criterio A | Veredicto | Evidencia |
|---|---|---|
| Archivos necesarios y declarados | ✓ | Ledger313;SHA5/5. |
| Propiedad, relaciones, índices y revisión única | ✓ | M11,23;modelos14/14. |
| Nombre, creación normalizada y revisión1 | ✓ | S186,220;T57,286. |
| Aislamiento owner/brand, incluso modelo ajeno | ✓ | S148,215;T106. |
| JSON privado fuera de DB, ruta generada/perfil fijo | ✓ | S191,230;M23;T57,258. |
| SHA/tamaño/contadores exactos | ✓ | S239,259;T65,69. |
| Reemplazo atómico e histórico retenido | ✓ | S32;T83. |
| Sólo nombre también crea revisión | ✓ | S36;T302. |
| Expected positivo/conflicto409 sinhuérfanos | ✓ | S25,35;T92. |
| Lock antes de leer/writerSQLite reservado | ✓ | S33,155,164;T242. |
| Transacción externa rechazada antes de storage | ✓ | S208;T172. |
| Escritura/inserción fallida revierte metadata y archivo nuevo | ✓ funcional | S41,200;T191,218 preservan archivo ajeno e histórico. |
| Lectura fija/tipos/identidades/sin fallback | ✓ | S49;T98. |
| Ausente/SHA/esquema/forma inválidos503 | ✓ | S56,103;T154,272,322. |
| Archivado reversible/conserva historia/bloquea edición | ✓ | S171,153;T119. |
| Metadatos paginados/búsqueda literal/sin storage | ✓ | S118,130,138;T133,149. B1extremo se añade en su lente. |
| Revisiones inmutables por modelo | ✓ | ModelRevision25;T307. |
| Tests antes de código por criterio | ✗ A1Important | Contrato25 yhandoff22: escritura/rollback añadidos ya verdes. |
| Verificación real del candidato | ✓ |15/15,108aserciones;JUnit0fallos/errores/skips,PintpropioPASS. |
| Base/storage desechables, sin.envreal | ✓ | T21,33;env seleccionado inexistente comprobado. |
| T12 integral abierta | ✓ | Ledger311–318;contrato27. |
| Constitución/cobertura/interop | n/a | Sinconstitución/mínimo yningúncommands/agents/hooks. |

A leyó tresRED y caracterizaciones/JSON/JUnit, ejecutóPint ySHA;sinrepetirPHPUnit. REDrecurso agrupado se declara como un comando antesdecódigo, no siete; los errores de setup no cuentan.

| Criterio B | Veredicto | Evidencia |
|---|---|---|
| Creación/aislamiento/revisiones/archivo/transacciones/cleanup | ✓ | PHPUnit15/15,108assert,exit0. |
| Fidelidad y lectura corrupta | ✓ |15valores extremos;8JSONmalformados conhashválido503. |
| Búsqueda literal | ✓ |7búsquedas !/%/_/barra sincoincidencia incorrecta. |
| Paginación extrema | ✗ B1Minor | S127,135;PHP_INT_MAX/perPage25 devuelve primera fila en vezde página vacía. |

| Criterio D | Veredicto | Evidencia |
|---|---|---|
| SinN+1 ni storage alpaginar | ✓ |200registros/perPage1y100:listing3/history4/present2queries;serializaciónincluida,Storage never. |
| Índices | ✓ | M21,36;EXPLAIN usa índicecompuesto yunique dataset/revision,sinordenacióntemporal. |
| Escritura incremental/cargamáxima | ✓ | S233,248;1000×100JSON52.000.000B,create331,66ms,phpTemp1MiBspill. |
| Lectura/memoria256M | ✓ | S79,80;read301,38ms,===true,pico218.103.808B(208MiB),caller conservaoriginal. |

D usóPHPstdin256M,SQLite-memory ystoragesimulado phpTemp;no filesystem52MB/RSS/HTTP/editor acreditados. Primerprobe descartado por mocksize incorrecto, no defecto fuente. C=falseporselector; Dtrigger migration.

## Fusión intento1

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | MétodoTDD incumplido para escritura/rollback | T-12 | Pendiente: borrar manejo escrito antes de sus tests, RED focal verdadero y reimplementar. | Contrato25,handoff22,T191/218;no se rebate ni falsea RED. |
| B1 | Minor | Overflowoffset devuelveprimera página | T-12 | Pendiente conRED focal; mantener resultado vacío correcto ovalidaciónexplícita. | S127/135;pagePHP_INT_MAX,y368934881474191034 conperPage25,castintnegativoseacotaa0. |

A1 es incumplimiento de secuencia, no defecto funcional probado del manejo de fallos. Las caracterizaciones preservan valor de cobertura; no se cambian retrospectivamente aRED. SinCritical,1Important/1Minor. Antesdeaceptarrecursosse corrigenambos. Tablas✓ se transmitirán completas alintento2, reabrir sóloevidencianueva.


## Intento2: correcciones aceptadas A2/B2, D1 preservada

Delta completo258líneas en servicio/test; modelos,migración ycodec sin cambios. [Handoff y comandos](raw/attempt2/handoff.md), [delta](raw/attempt2/delta.diff), [snapshot realmente borrado antes de RED](raw/attempt2/removed-before-red.php), [final19/169](raw/attempt2/green-final.txt). A2/B2 frescas recibieron todas las tablas anteriores y preservaron✓; sólo reevaluaron correcciones, sin nueva evidencia para reabrir lo aprobado. C/Ddelta=false;D1 histórica preservada.

| Criterio A previo | Resultado2 | Evidencia |
|---|---|---|
| Archivos declarados | ✓ |5SHA actuales/copias/baseline;delta limitado2rutas. |
| Propiedad/relaciones/índices/revisión única | ✓ preservado | Modelos/migración idénticos. |
| Nombre/creación normalizada/rev1 | ✓ preservado | S195;T57. |
| Aislamiento owner/brand/modelo ajeno | ✓ preservado | T106. |
| JSONprivado/ruta generada/perfilfijo | ✓ preservado | S247. |
| SHA/tamaño/contadores | ✓ preservado | S254. |
| Reemplazo atómico/histórico | ✓ | S33,210;T83,191. |
| Sólo nombrecrearevisión | ✓ preservado | T327. |
| Expectedpositivo/409sinhuérfanos | ✓ preservado | S27,36;T83. |
| Lock/writerSQLite | ✓ | S34 dentrodepersistRevision;T283. |
| Transacciónexternarechazadaantesstorage | ✓ preservado | S25,197,225;T172. |
| Escritura/inserciónreviertearchivo/metadata | ✓ | S210,270;T191,221,239,255;cuatroGREEN9/6/4/7. |
| Lecturafija/tipos/identidad/sinfallback | ✓ preservado | T98. |
| Ausente/corrupto/shape503 | ✓ preservado | T154,313,363. |
| Archivo/restauración/historia | ✓ preservado | T119. |
| Metadatapaginada/literales/sinstorage | ✓ | S144;T133,375,392;extremosconservantotal/página/límite. |
| Revisionesinmutablesmodelo | ✓ preservado | Modelo sin cambios. |
| Testsantesdecódigoporcriterio | ✓ A1corregido | Snapshotborradosinwrappers/catch/guard,cuatroREDindividuales yhelper reescrito;historiaanteriorintacta. |
| Verificacióndelcandidato | ✓ |19/19,169assert,Pint0;A2seistestsdelta6/6,76assert. |
| DB/storage desechables/sin.envreal | ✓ | T21–28;bootstrapenvficticio. |
| T12abierta | ✓ preservado | Ledger/contrato conservanalcanceintegral. |
| Constitución/cobertura/interop | n/a preservado | Noconstitución/dev.json,cobertura ni piezasgeneradas nuevas. |

| Criterio B previo | Resultado2 | Evidencia |
|---|---|---|
| Creación/aislamiento/revisiones/archivo/transacciones/cleanup | ✓ | Seisfallosprobadosindependientemente,rollback/referenciaalnuevoarchivo yerrororiginalpreservados. |
| Fidelidad/corrupción | ✓ preservado |15valores/8JSON503anteriores;códigolectorintacto;probeleerev1/2. |
| Búsquedaliteral | ✓ preservado |7búsquedasanteriores;probetotalfiltradoenpáginaextrema. |
| Paginaciónextrema | ✓ B1corregido | perPage1…100,bordesrepresentables/overflow/PHPINTMAX,total,página,serializaciónyclampscorrectos. |

D1previa mantiene sus cuatro✓:sinN+1/lecturasstorage,índices,escritura52M ylectura256Mconmock. No se repite ni se atribuye nuevo52MB/RSS/discoreal alintento2.

| Gap | Grado anterior | Resolución | Evidencia |
|---|---|---|---|
| A1 | Important | Corregido | Borradoreal/4REDfocales/reescritura trasRED;A2✓. |
| B1 | Minor | Corregido | Divisiónentera antesoffset,resultado vacío contotalreal;A2/B2✓. |

**0Critical/Important/Minor pendientes.** A2ejecutaPint5fuentesy6/6,76assert;B2ejecuta6/6,76assert yprobeindependiente**1643comprobacionesPASS**,SQLite-memory/storagemem/PHP256M. CincoSHAexactosantes/después. Root acepta el recurso interno; no equivale aAPI/import25MBpúblico/editor ni cierraT12.
