# T12 C3 — revisión2: seis gaps corregidos, nuevo B4

24fuentes;delta10archivos/845líneas/full2566,scopeactual0 sinavisos/info/exclusiones,CfalseDtrue. A2/B2/D2frescas leyeron tabla1completa y preservan✓/n/a. No código nativo ejecutado. Nuevo B4 nace en el delta de remapeo; no reabre la corrección B2 de IDsausentes ni los otros criterios sin evidencia.

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
| Edición de histórico válido vacío despuésVerDatos | ✗ nuevo B4 | Charts19 rows.length>0 marca seriepresente sinfilas comoausente |
| UIresponsive/fuentereal/píxelesSVG-PNG-PDF | Pendiente/noverificable | Aún sinbuild/browser/QAintegrada |
| Constitución/coberturaconfigurada/OpenAPI/interop | No aplica | Ausentes/no declarada/sincambiosOpenAPIoagents-commands-hooks |

| # | Grado previo | Gap | Estado | Evidencia |
|---|---|---|---|---|
| A1 | Important | Presupuesto doc confundido con cache | Corregido |32refs antesI/O;64Mbytes originales vía describe; no reserializarU2028 |
| A2 | Important | Paleta pierdepreps trasLRUraw | Corregido |G5independiente/metadatafrozen6campos/500ligeros ypreps500 |
| B1 | Important | Carrera entrevalidación/publicación | Corregido |checkversión+commit callbackmisma continuaciónsíncrona enTODOScallers |
| B2 | Important | IDsantiguos invisibles alremap | Corregido |Aviso+remover/reasignar explícito, estilo intacto |
| B3 | Important | Mixedvideo nuevo no registrado | Corregido |Mantiene noncharts ypool/identidad/play/destroy |
| D1 | Important |32RTTseriales antes pintar | Corregido |4trabajadores/describes ystopalerror sincancelsharedajeno |
| B4 | Important nuevo | VerDatos bloquea histórico válido sinfilas | Pendiente al cerrar revisión2 |Charts19/22:rows.length>0 fuerza numericfalse;G5/sceneaceptanempty;baselinecanApplytrue/true,source2true/false |

A2 ejecutó96/96JS en cincosuites,0fallos/skips/cancelaciones/exit0,4SFCPASS y24fuentes/dependenciasSHAintactos. B2también96/96/exit0y11depsSHA;primero95/96pordata:importabsdelharness,corregidosóloenmemoria(no defectoproducto),después96. Reportó493avisosVNodewithDirectivesdelharness,ningunaaceptaciónbrowser. ProbeB4comparabaseline/actual concolumns cat/val,rows[] ysource:null:antesVerDatosseaplica;despuésvalseconsideranodisponibleybloquea;escenarealvalidacero filas yC1binding196lacaracteriza.

D2 ejecutó40/40(scene15/resources25),24fuentes+4depsSHAintactos. Probe32refs/3552B/demora10mssimulada:pico4/8rondas/124ms/0G5extra. Fallo/abortinicia4llamadas;excesobytesinicia5antesdeobservarlo,ningunanuevaPOSTobservación. Consumidorajeno de ref2continúa al fallarref1,sinquinta descarga. Quotaescaladagenuina121B/ref/límite241rechaza2refs,33refs0IO. Paleta/LRU500/metadata/dispose/inflightcorregidos. SinHTTPreal/52MB/RSSniNative.

Corrección3 B4 encargada aownerUIconREDprimero: misma elegibilidad every(number/null) incluso0filas enambosdiálogos, sincoerciónniresetestilo;serieausente/textoexistentesiguenbloqueados. No cambiarlímites niotroApp. Revisión3frescaA/B únicamente deltadeB4, preservar todos✓inclD2porque no cambian susfuentes;C/Dcondicionales segúndelta. T12 abierta8/23;build/QAreal12casos yAVpendientes. SinJira/Confluence/git.
