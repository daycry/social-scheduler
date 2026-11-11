# T12 C3 — revisión1 A/B/D, seis Important

24fuentes/2218líneas leídas completas por bloques; scope0 sinavisos/info/exclusiones,Cfalse/Dtrue. Baseline WIP correcto, noHEAD. Algunos productores conservaron sólo hashes originales: root recuperó cinco fuentes históricas y reconstruyó exports/render en copia privada revirtiendo únicamente adicionesC3; hashes exactos coinciden con autorización1378 anteriorC1/C3. Recovery documentado, no se atribuyen copias de baseline que no existían. UIinitial5+polish3 se unieron antes despacho; paqueteinterno22 incompleto no fue revisado, snapshotpublicado24 incluyeEditor/Datasets.

| Criterio C3 | Veredicto fusionado | Evidencia |
|---|---|---|
| Alcance/T12abierta/snapshot | ✓ | scope0;tasks311;24SHAantes/después |
| Escena normalizada/dimensiones/preparaciónG5única | ✓ | scene41–63;9tests |
| Tablaoriginalinmutable/identidad/tipos/duplicados/raw/null/cero | ✓ | scene117–157;fixtures10variantes |
|10tipos/rejilla/ejes/cero/títulos/categorías/valores/leyenda | ✓ | scene85–154 |
| Textoajustadouniformemente/densidadsineliminardatos | ✓ | renderer14–17;160²/72px/12kbarras |
| Capa/serializeratómicosinderivados | ✓ salvo publicaciónB1 | canvas132,209,595;groups/ungroup |
| Contextocanvas/grupos/preview/frames/export | ✓ | canvas250,294,321;frame7;exports95 |
| Fuenteprivada/pesoestiloreales | ✓ | canvas269;renderer6–14;declaraciónitalic70016px |
| Publicación concurrente dentro de presupuestos | ✗ B1 | canvas374–384/447;dosvalidacionesv0publican50200/501capas |
| Índice/identidad/transformación/selección/falloprevio | ✓ preservado | canvas440–452,493–532;tests existentes |
| Epoch/página/objetovivoimpidenretardíos | ✓ | canvas493–530;borrado/página/fueradeorden |
| Selecciónsólorevisiónguardada/SHA/sintablaenpayload | ✓ | Datasets184–191;dirty/recarga/metadatos |
| ControlesC1/precisión/Unicode/avisoslegibles | ✓ | Charts88–105;4SFCcompilados |
| Remapeo de otra tabla/revisión sin campos invisibles | ✗ B2 | Editor61/Charts45,94;old_valinvisible no retirabledesdenuevascolumnas |
| Previewcomún/debounce/abort/ticket/disposal | ✓ | Charts41–80;testsretardíos/cancel/contextoprestado |
| Registryporcontexto/histórico/acciónúnica | ✓ salvoB2 | Editor48–81,565,585 |
| Vídeoexistente en selecciónmixta conserva pool/identidad/liberación | ✗ B3 | canvas502reconstruyetodos sinvideos |
| Materializaciónautorizada/histórica/dedup/canónicastream | ✓ lectura/evidenciaPHP | Files16–95;7tests29assert (lentesnoKernel) |
| Descriptorcerrado/raíz/tamañoSHA/mapasintético/offline | ✓ | render45–84;entry6–21;wiringtests |
|50k/página200k/trabajoseleccionado | ✓ salvo carreraB1 | scene30–37;exports159–163;hiddenselectedfixtures |
|32refs/64MúnicosdelDOC/TRABAJOindependientesLRU | ✗ A1 | scene18;rawLRUpermite33docs/bytes222>136escalados |
| G5reutilizableporstyletrasexpulsartabla | ✗ A2 | resources84borrapreps;palettefetch33→34 |
| D1anteriorcancelación/inflightpreservada | ✓ |19resourcesPASS |
| Preflightfrío sinlatenciaproporcionalevitable | ✗ D1nuevo | scene27;32descargassecuencialesantescanvas400 |
| TDD/baselines/evidenciaestática vsaceptación | ✓ | RED/caracterizaciones/EPERM/harnesserroresdeclarados |
| UIresponsive/fuentereal/píxelesSVG-PNG-PDF | Pendiente/noverificable | Aún sinbuild/browser/QAintegrada |
| Constitución/coberturaconfigurada/OpenAPI/interop | No aplica | Ausentes/no declarada/sincambiosOpenAPIoagents-commands-hooks |

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | LRUconvierte presupuestodocumento/trabajo en simplecaché | T12/C3 | Pendiente al cerrar revisión1 |1página33charts/14641B/33refs:accept3399primitives/33fetch/cache32;baselineC2rechaza33. Loaderescalado222Búnicos>136aceptados concache111 |
| A2 | Important | Evictrawborrapreparación ypaletteexigeHTTPnuevo | T12/C3 | Pendiente |Prepararref1→consultar2..33→palette1fetch33→34;failsiconexiónindisponibleaunconescenapreparada |
| B1 | Important | Validación async separada de publicación permite carrera | T12/C3 | Pendiente |DosaddChart1000×12:ambosfulfilled/layers2/bound50200;499rects+2add→501capas |
| B2 | Important | Nuevadatasourceconservaserieantiguainvisible | T12/C3 | Pendiente |old_val→new_val,sólo newencontroles;selectedold+new,simpleinválidoysinretirarold |
| B3 | Important | Reconstrucciónmixtapierdevídeoregistrado yliberación | T12/C3 | Pendiente |Chart+videoopacity crea2videos,playretirado1/visible0,destroydejasrcnuevo |
| D1 | Important | Preflight32fuentesindependientesserializalatencia | T12/C3 | Pendiente |32referencias3552B/fetchlocal15ms:704mspico1;control4consumidores185mspico4;32paralelo34ms |

A2 también señalado porD: deduplicado, no séptimo gap. A1 verificó70/70JS y4SFC,24SHA;B1 ejecutó59/59 ycuatroprobes con8dependenciasSHA;D128/28yconcurrenciacontrolada con4depsSHA. Todos Node512/sinescrituras/Ker nel/DB/HTTP/browser/nativos. Tiempos locales simulados no predicen producción/RSS;probe64M escalado no52MBreal. PHPsóloevidenciaproductor leída porlentes.

RootPHP independiente42/42/279assert,JUnit42/0fallos/0errores/0skips,10PHPfuentesintactas,256M;rendererFeatureusaProcessfake, NOproduceexportsreales. Evidencia t12-chart-c3-root-verification/;no soluciona seis defectosJS.

Correcciones2 disjuntas encargadas conREDprevio y tablas1 preservadas: registrymetadatadescribe/independenciaG5; escena presupuestodoc32/64/concurrencia4 ycanvascommit/vídeomixto; U Iremapeo, retiradeseleccióncategoría yscrollregionteclado. Clarificaciones de mecanismo no relajan contratosanteriores. Revisión2concontextofresco sólo deltasygaps, sin reabrir✓salvoevidencianueva. T12abierta8/23,AVyotrasfamiliaspendientes,QA12casosencursoPREPARACIÓNsin ejecutar. SinJira/Confluence/gitmutaciones.
