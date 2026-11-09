# T12 C3 — revisión3: código interno sin gaps pendientes

2026-11-06. Lentes A3/B3 frescas, sólo delta B4 de3archivos/39líneas sobre24fuentes congeladas. Ambas leyeron tabla2 completa y preservaron todos los ✓/n/a y D2. Scope0 sin avisos/info/exclusiones; selector C/D=false para este delta. No se repite D2 porque sus fuentes no cambiaron.

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

A3:20/20 Node PASS/exit0, cuatro SFC compilados,24SHA+4dependencias intactos. B3 independiente:20/20 Node PASS/exit0 y comparación baseline true→false frente a corregido true→true; siete comprobaciones adicionales de vacío/null/cero/textos/campos ausentes.24SHA+8dependencias y dos SFC baseline intactos. B3 contó522 avisos Vue del harness; no son evidencia de navegador. Ambas lentes sin escrituras, Kernel, PHP, DB, red, build, ONNX ni exportaciones nativas.

Aceptación limitada a código C3 interno. T12 sigue en-progreso y total8/23. Responsive, accesibilidad real, fuentes, píxeles, exportaciones SVG/PNG/PDF y12kbarras requieren QA real pendiente; AV de gráficos y demás configuraciones/familias siguen pendientes. No se ejecutó auditoría final ClaudeCLI ni commits/push/PR nuevos. Sin Jira/Confluence habilitados, no operaciones externas.
