# Gráficos de barras — integración interna C3

2026-11-06. El bloque de código de escena/canvas, controles y exportación estática está revisado sin defectos pendientes. **No es aceptación de interfaz ni exportaciones reales. T12 permanece abierta;8/23 tareas cerradas.**

Diez variantes usan la misma escena tipada, revisiones privadas fijadas por SHA y fuentes de marca. Los documentos sólo conservan configuración y referencias; el servidor materializa JSON canónico por streaming. Revisión1 encontró seis defectos importantes; revisión2 confirmó sus correcciones y encontró B4 de histórico vacío. Revisión3 A/B confirmó B4 corregido, conservando toda la tabla anterior y D2.

| Criterio C3 | Veredicto fusionado | Evidencia |
|---|---|---|
| Alcance/T12abierta/snapshot | ✓ | scope0;tasks311;24SHAantes/después |
| Escena normalizada/dimensiones/preparaciónG5única | ✓ | scene41–63;9tests |
| Tablaoriginalinmutable/identidad/tipos/duplicados/raw/null/cero | ✓ | scene117–157;fixtures10variantes |
|10tipos/rejilla/ejes/cero/títulos/categorías/valores/leyenda | ✓ | scene85–154 |
| Textoajustadouniformemente/densidadsineliminardatos | ✓ | renderer14–17;160²/72px/12kbarras |
| Capa/serializeratómicosinderivados | ✓ corregido B1 | canvas132,209,595;groups/ungroup |
| Contextocanvas/grupos/preview/frames/export | ✓ | canvas250,294,321;frame7;exports95 |
| Fuenteprivada/pesoestiloreales | ✓ | canvas269;renderer6–14;declaraciónitalic70016px |
| Publicación concurrente dentro de presupuestos | ✓ corregido B1 | canvas374–388;commitcallbacksync;dosinsert500/50200rechazansegundo |
| Índice/identidad/transformación/selección/falloprevio | ✓ preservado | canvas440–452,493–532;tests existentes |
| Epoch/página/objetovivoimpidenretardíos | ✓ | canvas493–530;borrado/página/fueradeorden |
| Selecciónsólorevisiónguardada/SHA/sintablaenpayload | ✓ | Datasets184–191;dirty/recarga/metadatos |
| ControlesC1/precisión/Unicode/avisoslegibles | ✓ | Charts88–105;4SFCcompilados |
| Remapeo de otra tabla/revisión sin campos invisibles | ✓ corregido B2 | Charts100–103;staleIDsvisibles/retirables/categoríamarcadaretirable |
| Previewcomún/debounce/abort/ticket/disposal | ✓ | Charts41–80;testsretardíos/cancel/contextoprestado |
| Registryporcontexto/histórico/acciónúnica | ✓ corregido B2 | Editor48–81,565,585 |
| Vídeoexistente en selecciónmixta conserva pool/identidad/liberación | ✓ corregido B3 | canvas519–539;nonchartsinplace/play/destroy/rollback |
| Materializaciónautorizada/histórica/dedup/canónicastream | ✓ lectura/evidenciaPHP | Files16–95;7tests29assert (lentesnoKernel) |
| Descriptorcerrado/raíz/tamañoSHA/mapasintético/offline | ✓ | render45–84;entry6–21;wiringtests |
|50k/página200k/trabajoseleccionado | ✓ corregido B1 | scene30–37;exports159–163;hiddenselectedfixtures |
|32refs/64MúnicosdelDOC/TRABAJOindependientesLRU | ✓ corregido A1 | scene27,52;describeactualbytes;33refs0IO/escalado2refsquota241 |
| G5reutilizableporstyletrasexpulsartabla | ✓ corregido A2 | resources86,170,191;G5independiente/metadatalight500 |
| D1anteriorcancelación/inflightpreservada | ✓ |19resourcesPASS |
| Preflightfrío sinlatenciaproporcionalevitable | ✓ corregido D1 | scene41–59;32refs/pico4/8rondas/124mssimulado |
| TDD/baselines/evidenciaestática vsaceptación | ✓ | RED/caracterizaciones/EPERM/harnesserroresdeclarados |
| Edición de histórico válido vacío despuésVerDatos | ✓ corregido B4 | Charts15/Datasets186; baseline true→false, source3 true→true; estilo/revisión intactos |
| UIresponsive/fuentereal/píxelesSVG-PNG-PDF | Pendiente/noverificable | Aún sinbuild/browser/QAintegrada |
| Constitución/coberturaconfigurada/OpenAPI/interop | No aplica | Ausentes/no declarada/sincambiosOpenAPIoagents-commands-hooks |

| # | Grado previo | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | LRU omitía presupuesto por documento/trabajo | T12/C3 | Corregido, revisión2 preservada |32refs antes I/O;64M bytes originales describe |
| A2 | Important | Evicción raw borraba preparación | T12/C3 | Corregido, revisión2 preservada |G5 y metadata ligera independientes de rawLRU |
| B1 | Important | Carrera entre validación y publicación | T12/C3 | Corregido, revisión2 preservada |commit síncrono con versión; segundo insert rechaza 50200/501 |
| B2 | Important | Remapeo dejaba IDs invisibles | T12/C3 | Corregido, revisión2 preservada |Retirada y reasignación explícitas, estilo intacto |
| B3 | Important | Selección mixta perdía vídeo registrado | T12/C3 | Corregido, revisión2 preservada |Objetos nonchart/pool/play/destroy conservados |
| D1 | Important | Preflight frío serial | T12/C3 | Corregido, D2 preservada |32refs/pico4/8rondas/124ms con demora simulada10ms |
| B4 | Important | Ver datos bloqueaba histórico vacío válido | T12/C3 | Corregido, A3/B3 |Dos RED genuinos→45/45 GREEN;20/20 por cada lente sobre snapshot; columnas ausentes/textos siguen bloqueados |

Las dos lentes finales ejecutaron20/20 pruebas cada una sobre el snapshot; A compiló cuatro SFC. El productor obtuvo dos RED funcionales y45/45 verdes. No sumar estas ejecuciones como casos únicos. Root verificó42/42 PHP,279aserciones, cero errores/fallos/skips, diez fuentes estables y límite256M; los tests Renderer usan Process::fake y no acreditan archivos nativos.

La nueva compilación aislada terminó exit0, sin deriva de fuentes, con 77 assets y 62 fuentes registradas. JobWindows3GiB COMMIT agregado, pico1175388160B; mínimo físico observado10815242240B. No es RSS. Entorno OS permitido y envDir vacío para Vite y Laravel; sin.env real. BUILD6 histórico no se usa.

Siguiente puerta:12casos reales en375/768/1440, controles/undo/histórico, cancelación, privacidad, SVG/PNG/PDF, fuentes,12kbarras y axe. Preparación no equivale a pruebas ejecutadas. AV de gráficos, otras configuraciones y demás partes de T12 continúan pendientes. Sin auditoría final ClaudeCLI ni nueva entrega git en este bloque.
