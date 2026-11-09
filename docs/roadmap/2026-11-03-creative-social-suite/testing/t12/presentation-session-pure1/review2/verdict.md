# Revisión P1, intento 1

Lentes A+B de contexto fresco, sólo lectura, sobre dos ficheros nuevos completos/236líneas de diff. Scope0 sin avisos/info/exclusiones; C=false,D=false. Ningún cierre T12,8/23estados conservados. La tabla A y sus aprobaciones se conservan debajo; B contradice la conformidad temporal con evidencia nueva reproducida por root. No se convierte el GREEN inicial en aceptación de ese caso límite.

## Lente A, tabla completa recibida

Rutas referidas al snapshot `source/`. P=`resources/js/Studio/presentation.js`; T=`tests/frontend/studio-presentation.test.mjs`; C=`docs/roadmap/2026-11-03-creative-social-suite/t12-presentation-session-contract.md`.

| Criterio T12/P1 | Veredicto A1 | Evidencia |
|---|---|---|
| Alcance y archivos declarados | ✓ | tasks.md:314;improvement-plan.md:9;scope0sinexclusiones |
| Exportación y validación real | ✓ | P:1,35-37;T:10-12,100-107 |
| Selección propia/orden/IDs únicos existentes/máximo100 | ✓ | P:19-46;T:17-25,110-119;document.js:6 |
| Opciones cerradas/loop/startPageId/getters/prototipos | ✓ | P:6-30,45-47;T:110-119/getters0 |
| Estado inicial completo e inmutable | ✓ | P:39,51-60;T:19-22 |
| Copia independiente de documento/opciones | ✓ | P:38-44;T:23-25,122-127 |
| Duración ms/v1v2/conservación temporal | ✓ | P:37,43;T:14-15,100-107/documentosinmutar |
| Comandos/snapshot/navegación/reinicio/playing | ✓ | P:63-77;T:64-72 |
| Extremos/loopmanual/final/reinicio ended | ✓ | P:64,68-73;T:43-49,64-72 |
| Pausa/reanudación/fracción/ignorar pausado | ✓ | P:68-69,81-83;T:36-40 |
| Advance válido/fronteras/múltiples páginas | ✓ en A; contradicho por B1 | P:79-94;T:28-33,43-49 no cubrían duración123,4ms |
| Entradas temporales y selección inválida atómicas | ✓ | P:74-82;T:75-80 |
| Loop enorme y trabajo acotado | ✓ | P:86-91;T:52-61 |
| Blackout sólo visibilidad | ✓ | P:96-98;T:83-88 |
| Dispose idempotente/bloqueo posterior | ✓ | P:62,68-81,97,100;T:91-97 |
| TDD y ejecución | ✓ | RED1/0pass previo al módulo;GREEN12/12;vecinos25/25incluyen12;0fail/skip/cancel;testsenterosleídos |
| Sin integración ni aceptación adelantada | ✓ | C:3,19-21;tasks:3347-3348;sinEditor/buildnuevo/timers/DOM/red/UI/presenter/notas/fullscreen/Office/grabación |
| Aceptación y Verificación global T12 | no verificable, pendiente explícito | tasks:311,313,319;T12enprogreso/checkboxsinmarcar;noexigirglobalparaaceptarcomponenteaislado |
| Constitución/dev.json/cobertura | n/a | ausentes;sin%inventado |
| Interop E2/E3/OpenAPI | n/a | dosficheros no tocan commands/agents/hooks/specs |
| ADR | ✓ | knowledge-find frontend exit0,sinaciertos |
| Integridad | ✓ | 8snapshots+8deps+4evidencias+testvivo sin deriva antes/después |

A1: Critical0/Important0/Minor0, sin hallazgos. Ejecutó SHA y `node --max-old-space-size=512 --check` de ambos archivos, sin repetir suite verde. No reabrió aprobaciones previas. Fuera de lente: ninguno.

## Lente B, salida completa de hallazgos

| Criterio | Veredicto B1 | Evidencia |
|---|---|---|
| Navegación y fronteras temporales deterministas | ✗ | presentation.js:90;reproducción independiente |
| Aislamiento/validación/ciclo de vida | ✓ | lectura completa de implementación/tests |

| # | Grado | Gap | Tarea | fichero:línea | Escenario |
|---|---|---|---|---|---|
| B1 | Important | Reconstrucción de posición absoluta introduce regresión por redondeo | T12/P1 | resources/js/Studio/presentation.js:90 | Documento válido duraciones[0.1,0.1234,0.1],startp2,play/advance0 retrocede p1/elapsed123.39999999999998. Desdep1,advance(snapshot.durationMs) tampoco alcanza p2. |

B ejecutó probes nuevos por stdin con `node --max-old-space-size=512 --experimental-test-isolation=none --input-type=module -`. Frontera exacta:4/4fallos v1/v2×loopfalse/true,exit1. Delta0:96/512ternas retroceden. Suites originales leídas,sin repetir. SHA inicial/final8sourcecontext+8deps+4evidencias intactos. ADR --no-index,ceroaciertos;persona genérica sinTipo. Fuera de lente: ninguno.

## Fusión y reproducción raíz

Root confirma B1 mediante `storage/framework/testing/t12-presentation-review-root1/reproduce-zero-advance.mjs`, Node512,exit1: snapshot antesp2/elapsed0 ydespuésp1/elapsed123.39999999999998. El assert exige estado idéntico al avanzar0. Causa: al reconstruir posición absoluta desde starts[index] y volver a restar duraciones binarias, un redondeo queda justo antes del límite anterior. No es defecto de fixture ni de validación del documento.

Resultado intento1: Critical0/Important1/Minor0. B1 pendiente de corrección; no aceptar P1 ni cerrar T12. Implementer reactivado para RED de ambos escenarios antes del fix, algoritmo con índice/offset local y ciclos completos acotados, GREEN apropiado y nueva congelación. Revisión2 sólo reevalúa B1/cambios: conservar todos los ✓/n/a/pendientes anteriores, sin reabrirlos salvo evidencia nueva. No deuda aceptada ni hallazgo rebatido.

Sin Jira habilitado, sin publicación externa. Sin promoción de conocimiento. Root único ledger/docs,qa cliente escribe sólo client-qa1; ningún build/nativo concurrente.


## Revisión de dos lentes — intento 2: T-12/P1 — B1 corregido, B2 nuevo

2026-11-07,A+Bfresh,delta2files115líneas,8snapshots8deps12evidencias. Scope0/C=false/D=false. Historia completa anterior preservada literalmente arriba. A2nohallazgos;B2confirmaB1corregido eintroduceregresiónnueva reproducible. No se reabren otros✓/n-a/pendientes ni se declara aceptación global.

| Criterio corregido | A2 | B2/fusión y evidencia |
|---|---|---|
| B1delta0nopáginaatrás | ✓ | ✓4/4v1v2×loop;P79-84,T155-162 |
| B1duraciónexactap1→p2elapsed0 | ✓ | ✓4/4;P87-93,T164-171 |
| Offsetlocal/fracción/frontera | ✓ | Aprobe4small/2endedPASS;P87-93,T130-153 |
| Cicloscompletos/deltaenorme/O(páginas) | ✓ | BoráculoBigInt108/108hasta100páginas/invertidas/noiniciales/loop/maxsafe;P48,83-93,T173-192 |
| v1/v2validadorrealdocpreservado | ✓ | P37,T155-171,189-191 |
| TDD3REDantesfix/GREEN16focal29incl16 | ✓ | correction1logssinfallos/skips/cancel;Aleyóreales |
| Alcance2files/declared/sinUIbuildnuevo | ✓ | scope0;tasks314;P1solomódulo |
| 12testsoriginales/logs/baseline/SOURCE_DONEpreservados | ✓ | Aprefijoidénticobytes/SHA |
| Integridad | ✓ | A32SHAantes/después;B8snapshots8deps12evidenciasestables |
| FinaltrasduraciónTOTALdecimal | nocubiertoenA | ✗B2nuevocontraejemplo,antesfuncionaba |

A2preserva íntegra cadafilaA1dealcance/validación/selección/opciones/estado/copia/duración/comandos/extremos/pausa/entradas/loop/blackout/dispose/TDD/sinintegración/T12pendiente/n-a/ADR/integridad, contenidasenprefixanterior. Sujuiciotemporalhistórico✓contradichoporB1siguedocumentado;B1ahoracorregido. Aejecutó32SHA/probepuroNode512stdin:{zero4,boundary4,small4,ended2,failures0};suitesverdesleídas,norepetidas. NoesQAUI.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Important | Redondeoreconstrucciónabsoluta retrocedía delta0/fallabafrontera | T-12/P1 | Corregido | A2/B2zero4+boundary4,rootreproduce-zero-advanceexit0 |
| B2 | Important | RestarsucesivamenteimpidefinishalconsumirTOTALdecimal | T-12/P1 | Pendientecorrection2/REDantesfix | presentation.js87;dur[.1,.1234,.1],startp0,advance323.4→p2elapsed99.99999999999999/endedfalse/playtrue;oldp2elapsed100/endedtrue/playfalse;v1/v2 |

B2ejecutócomparadorold/newv1v2,assertendedtrueexit1;oráculoBigInt108PASS,B1ochoPASS,sinwrites/suitesrepetidas. Rootconfirmaexit1conreproduce-total-duration.mjs(caso1):mismosnapshotnoended. Nuevacausa:restardeltaenvariasduracionesenvezcompararfronterascumulativashaciaadelante. FusiónCritical0/Important1/Minor0;B1resuelto,B2nuevo. Implementerpreparacorrection2conbaselineactual,testREDantesfixyalgoritmofronteraslocalescumulativas,sineps,nilímitesuavizado. Revisión3acotada;siquedagapImportantrootreplanificará,noseaceptacomo deuda.

SinJira/conocimientoaprobado/nativos/Editor/build7nuevo. T12abierta8/23/objetivo23+ClaudeCLIglobal/fixes+Gitconservados.
