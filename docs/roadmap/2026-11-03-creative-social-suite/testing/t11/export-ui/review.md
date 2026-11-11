# T-11: diálogo audiovisual e historial

2026-11-05. Subbloque de interfaz del árbol de trabajo revisado y verificado con QA root. T-11 y la QA integral del padre siguen pendientes; roadmap7/23. El backend conserva su [snapshot verificado](../av-public/review.md), sin atribuirlo a estos cambios de interfaz.

## Alcance y evidencia inicial

El diálogo añade MP4/GIF a los cinco formatos estáticos, selección/orden de páginas, escala/calidad y tamaño padded. Los trabajos usan revisión guardada y cola del servidor, sin frame_times ni transparencia. El panel consulta el historial por página, permite cancel/retry y ofrece descarga/borrador explícitos desde ready. Cerrar no cancela el worker.

RED del cliente/monitor3/3 (`9ba9ee`) registrado en ledger; raw no conservado independientemente. RED de navegador opciónmp4 ausente (`9642d7`) con stdout/captura/contexto/trace conservados. GREEN inicial4puro y desktop1/1 (`d6d15e`), run-general-79747df0-78db-4842-8b62-3e98cad5dac2: MP4H264100×80/24frames, primerpíxel[0,0,254] y posterior[253,0,0], GIF100×80 y borrador de la marca. Primera pasada desktop falló por fixture que esperaba pending en lugar de processing al reservar el lease; se corrigió el test sin cambiar el producto. El segundo caso E2E de paginación y móvil se ejecutaron después en la pasada integrada descrita abajo.

## Revisión independiente — intento 1

Freeze8fuentes/493líneas en storage/framework/testing/t11-export-ui-review/attempt1; baseline preservada. A/B/D frescas; C=false,D=true por selector. Scope--baseHEAD exit0 sinfuera/avisos/exclusionesusuario. Sin model-tier disponible por instalación parcial; modelo heredado. Sin Jira, constitución, cobertura mínima ni interop aplicables; sin promoción de conocimiento.

| Criterio | A | B | D | Evidencia |
|---|---|---|---|---|
| 7formatos y estáticos conservados | ✓ | ✓ | ✓ | ExportDialog23/61/67/106 |
| Guardar revisión antes de solicitar | ✓ | ✓ | n/a | ExportDialog39; Editor302 |
| Selección/orden explícitos | ✓ | ✓ | ✓ | ExportDialog24–43/107; píxelesE2E |
| Escala/calidad y paddingpar | ✓ | ✓ | n/a | ExportDialog26–28/43/104 |
| Payloadnativo sinframes/transparencia | ✓ | ✓ | n/a | export-jobs6–9 |
| GIFsilencioso | ✓ | ✓ | n/a | ExportDialog23/101 |
| Bloqueo solo guardado/envío | ✓ | ✓ | n/a | NativeExportJobs27–35 |
| Historial paginado/2s agregado | ✓ | ✓ | ✓ | export-jobs11/19/32; panel18 |
| No solapar consultas | ✗ | n/a | ✗ | A1/D1 duplicados |
| Pausa/error/5min y actualización | ✓ | ✓ | ✓ | export-jobs26/35/47–50;4tests |
| Cierre cerca polling | ✓ | ✓ | ✓ | panel59–60; export-jobs30/41 |
| Cierre cerca navegación tardía | n/a | ✗ | n/a | B1 |
| CambioMP4/GIF cerca seguimiento | ✗ | remitidoA | n/a | A2 |
| Historial tras recarga | ✓ | ✓ | n/a | panel19/59; E2Edesktop |
| Filas estado/revisión/progreso/error | ✓ | ✓ | n/a | panel73–75 |
| Cancel/retry segúnDTO | ✓ | ✓ | n/a | panel40/77–78; cliente12–13 |
| CSRF/rutas propias | ✓ | ✓ | n/a | cliente1/8–14; api.js |
| Descarga/borrador ready explícitos | ✓ | ✓ exceptoB1 | n/a | panel42–44/79–80 |
| Alcance y evidenciafocal | ✓ | ✓ | ✓ | Scope0;4tests; hashes8 |
| QA móvil/paginado/integrada | No verificable | No verificable | n/a | Pendiente declarado, sin cierre indebido |
| Documentación y padreabierto | ✓ | ✓ | n/a | Contrato/guía/ledger |

| # | Grado | Hallazgo | Estado |
|---|---|---|---|
| A1/D1 | Important | start→stop borraba loading sin terminar GET anterior. Paginador permitía navegar durante carga;10clics alternados generan11consultas simultáneas, aunque solo se aplica la última respuesta. Panel56 ycliente41. | Corregido para intento2: AbortSignal hasta fetch y serialización hasta settle incluso si transporte ignora abort. |
| B1 | Important | control(post) respondía tras cerrar y router.visit abandonaba el editor; solo comprobaba disposed. Panel44. | Corregido: token de vista para acciones, invalidado al cerrar/cambiar formato/diseño; el borrador servidor se conserva sin navegar después. |
| A2 | Minor | MP4↔GIF no invalidaba lecturas, al observar solo active. Panel59. | Corregido: observa contexto activo/formato/diseño, detiene/cerca y vuelve a consultar. |

A ejecutó hashes8,4tests y sondas:2GETsimultáneos; SFCreal con rendererVue aceptaba respuestaMP4 traspasaraGIF. B ejecutó4tests y SFCreal/Vue con cliente diferido: active=false y visits=[/posts/88/edit]. D ejecutó4tests y cuerpo real de turn:11consultas pendientes/soloúltima aplicada. Los probes fueron inline, sin raw de stdout archivado. No atribuirlos a QA final.

## Corrección con regresión

Root reproduce los3hallazgos antes de corregir (`a66dda`): tres lecturas simultáneas en vezdeuna; navegación después de cerrar y lectura obsoleta al cambiar formato. Raw t11-export-ui-review-red-{monitor,panel}.txt. Harness permanente compila el SFC real y usa ref/watch/reactive de Vue, no una copia de sus métodos.

El monitor aborta el transporte anterior pero conserva el inFlight hasta settle, para que ni loaders que ignoran abort acumulen solicitudes. Cada reinicio espera y solo la última generación inicia otra lectura. El panel añade token de vista a acciones y observa active/formato/diseño. Mutaciones no se abortan al cerrar: sus efectos solicitados pueden persistir, pero sus respuestas ya no cambian la vista ni navegan. api.js añade signal opcional, manteniendo CSRF y credenciales propias. Baselineapi.js preservada antes del cambio. Unmount libera busy.

GREEN inicial de fixes:5monitor+2SFCcorrectos (`f3decc`); test adicional de AbortSignal hasta fetch incorporado después. Build/tipos reconstruidos correctos. Revisión2 y regresión integrada completadas con el alcance descrito abajo.


## Revisión independiente — intento 2

A2/B2/D2 frescas leen el delta correctivo completo de261líneas/10fuentes. Tabla ytodoslosveredictos delintento1 transmitidos,✓previos conservados salvo correcciones. Scope--baseHEAD0fuera/avisos/info/exclusionesusuario,10SHAiguales. C=false,D=true; sinJira/interop/constitución/cobertura mínima ni promoción de conocimiento.

| Criterio corregido | A2 | B2 | D2 | Evidencia |
|---|---|---|---|---|
| A1/D1 serialización real hasta settle | ✓ | ✓ | ✓ | Monitor27–49; prueba6; sonda SFCreal11navegaciones,max1pendiente/2lecturas totales |
| AbortSignal hasta fetch propio | ✓ | ✓ | ✓ | api.js8–10; client11–13; testtransporte con credenciales propias |
| B1 navegación tardía tras cerrar | ✓suplementario | ✓ | n/a | Panel18/48/64; SFCcierre correcto |
| A2 cambioMP4/GIF cerca la lectura | ✓ | ✓ | ✓delta | Panel64–68; SFCformato correcto |
| Acciones ante reabrir/formato/diseño/unmount | ✓contexto | ✓ | Sin nueva degradación | B2sonda12escenariospost/cancel/retry sin actualización/navegación obsoleta |
| Preparación ante cambiodiseño | ✓token | ✓ | n/a | B2sonda start0trasinvalidarcontexto |
| Compatibilidad transporte y✓previos | ✓ | ✓ | ✓ | Lectura de consumidores, código anterior no reabierto sin evidencia |
| REDroot y regresiones permanentes | ✓ | ✓ | ✓ | Raw3fallos,6monitor+2SFCcorrectos ejecutados independientemente |
| QAintegrada/móvil/paginado | No verificable aún | No verificable aún | Fuera de su lente | Pendiente declarado; no suplantado por sondas |

0Critical/Important/Minor pendientes. Los tres revisores ejecutaron pruebas ligeras; A/B6+2 yD6, todoscorrectos. B probó además aborto rechazado,12escenarios deacciones ypreparación; D carga real conSFC,peak1/newLoads2/pending0. Sondas inline, no stdout raw archivado. node--test sinaislamientointentado por revisores tropezóspawnEPERM; los archivos directos completaron pruebas, sin atribuir esos errores deinfraestructura alproducto.

## Regresión root en curso

Frontend completo **161/161**,0fallos/cancelados/skips,41032,2921ms (`3c657b`). DiezSHAfuente y76artefactosgenerados iguales antes/después. La pasada E2E integrada terminó48/48correctos,0fallos/flaky/skips/interrupted/erroresrunner/reintentos, gateVERDE (`2e5236`/`1a7055`); duró387527,775ms. Run-general-56c4cbb4-0445-43e2-9de8-434cb3999b68, sesión61050/PID25700 terminal. El helper frontend24208/PID14960 también terminó. Diez fuentes y76generados mantienen SHA antes/después de ambas pasadas; no repetirlas sin cambios/fallos nuevos. No PHP nuevo tras elsnapshot369/2046 delbackend. Ninguna inferenciaONNX,MySQL,VPS,cuentaR2real o publicación social ejecutada.


## Revisión documental complementaria — intento 3

A3 fresca lee48líneas de tres descriptores que conservaban el estado previo del bloque backend: job-contract, OpenAPI ydeployREADME. Ahora describen diálogo de7formatos/controles, conservando QAintegral yVPS pendientes. Los10ficheros originales deintento2 permanecen idénticos;13SHA actuales/congelados coinciden. B2/D2 ytodoslos✓anteriores preservados; ningún cambio de código/test después de revisión2. Scope0fuera/avisos/info/exclusionesusuario, OpenAPI--diff sin cambiossemánticos ylint0errores/3avisosprevios. Sin nueva compilación/pruebas por prosa.

| Criterio documental | A3 | Evidencia |
|---|---|---|
| Preservar✓yfixesprevios | ✓ |10SHAoriginalesiguales,13actuales/congelados |
| Siete formatos ycontroles actuales | ✓ | job-contract3; OpenAPI301; ExportDialog23/102/109; panel79/84/88/89/95 |
| UIreutiliza operaciones públicas | ✓ | job-contract107; client8/11/14–16 |
| Padre abierto ylímites externos explícitos | ✓ | ledgerT11; job-contract3/101; deployREADME42 |
| Alcance,APIcompatible ydescriptores | ✓ | scope0; API--diffsin cambios; contratos/guía/plan contrastados |
| Nuevaspruebas/generación/interop | n/a | Solo prosa,sin cierre de tarea |
| QArootposterior | No ejecutada porA3 | Resultado terminalroot se registra por separado |

A3sin hallazgos;0Critical/Important/Minor pendientes en el subbloque.

## Resultados y límites

Los48recorridos incluyen6nuevos (dos escenarios porviewport) y42de regresión. En escritorio1440 y móviles375/360 se solicita/cancela/reintenta el mismo MP4 tras recargar, se procesa realmente la cola, se descargan MP4/GIF y se crea un borrador de la marca. La paginación real de55exportaciones usa una consulta porpágina y no crea trabajos al consultar/reabrir.

Cada muestra nueva tiene MP4H264100×80/24frames/2,000000s yGIF100×80/2,000000s. Píxel inicial[0,0,254] yposterior[253,0,0] comprueban blue/red tras invertir páginas; la revisión del diseño conserva red/blue. Archivos/probes/capturas porviewport en samples/. Rootinspeccionó las tres capturas: texto/controles legibles, botones se reorganizan yel diálogo mantiene scroll, sin desbordamiento horizontal detectado. Son muestras de colores sin recurso deaudio; no se inventa medición de música en este recorrido UI. El motor yworker conservan sus pruebas separadas de audio; falta la aceptación final compuesta del padre con vídeo/audio/transiciones desde el flujo público.

[Manifiesto](manifest.json):13fuentesactuales,76generadosde la pasada yartefactosraw/samples. Las tres fuentes nuevas enfreeze3 son prosa; las10testeadas son exactamente lasdefreeze2. No PHP nuevo porque este delta tocaVue/JS/transporte; el resultado backend369/2046 se conserva con su snapshot histórico. QA ejecutada por root más revisión independiente, no QAfinalindependientedelpadre. T11sigueabierta7/23; pendientes cierre integrado, resto delroadmap yClaudeCLI final. NoONNX/imaging,VPS/MySQL,R2real ni publicación social.
