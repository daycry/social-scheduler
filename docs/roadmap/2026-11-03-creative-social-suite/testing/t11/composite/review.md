# Aceptación pública compuesta de T-11

2026-11-05. T-11 abierta; roadmap7/23. Este bloque amplía la verificación, sin cambios de producto. No acredita VPS, R2 real, ONNX ni paridad de toda la suite.

## Recorrido

UI sube vídeo propio de movimiento/tono440 y WAVestéreo de música880. HTTP público crea dos páginas con overlays yfade; diálogo solicita MP4 desde revisión guardada. Se modifica el documento actual antes de ejecutar el worker para demostrar revisión fijada. Descarga H264/AAC yGIFescalado, medición de frames/duración/píxeles/movimiento/audio yborrador de la marca con igualdad exacta delIDmedio ready.

Primera pasada desktop falló por fixturePATCHsinname (`266581`), corregida sin tocar app. Segunda pasó1/1 (`f2abe5`), pero su oráculo tenía los huecos que detectó revisión1; no se promociona esa pasada a aceptación final. Fuentes/medidas en run-general-b161adb3-d91f-471d-9462-9c35a510fa39. No repetir las161frontend/48E2Eanteriores sin cambios que lo justifiquen; conservan su snapshot.

## Revisión independiente — intento 1

A/B frescas sobre2fuentes/153líneas, SHAcoincidentes; selectorC=false,D=false, scope--baseHEADexit0 sinfuera/avisos/exclusionesusuario. Sin API tocada, constitución, interop, Jira o cobertura mínima. Sin promoción de conocimiento.

| Criterio | A | B | Evidencia |
|---|---|---|---|
| Alcance tests/plan sin producto | ✓ | ✓ | Manifest2/2;test-plan añadido |
| SubidasUI reales vídeo440/WAVestéreo880 | ✓ | ✓ | E2E6/41/51/53;sin interceptar subidas |
| DocumentoHTTP yjobUI | ✓ | ✓ | E2E56/62/81/85;202 |
| Revisión inmutable pese aPATCHposterior | ✓ | ✓ | E2E87/90/95/100;rev1frente2yazulfinal |
| MP4H264/AAC100×80/42frames/3,5s | ✓ | ✓ | E2E101/105/106;medidasreales |
| Overlays/fade/movimiento | ✓ | ✓ | Píxeles253/0/0,0/255/0,127/0/126,253/253/0,0/0/254;RMS28,457 |
| Mezcla/volúmenes ysilenciofinal | ✓ | ✓ | Amplitudes≈0,05,440posterior≈1,22e-7,cola0 |
| Trim/envolvente discriminados | ✗ | ✗envolvente | A1/B1 |
| Contenido deamboscanalesestéreo | n/a | ✗ | B2 |
| GIF50×40/42frames/3,5ssilencioso | ✓ | ✓ | Sondeo una pistaGIF |
| Borradordemarca conmedioReadyexacto | ✓ | ✓ | Estado,brandIDymediaIDsiguales |
| Evidenciafocal ylímites | ✓ | ✓ | Desktop1passreal,QAfinalpendiente |
| Constitución/API/interop/cobertura | n/a | n/a | Sin cambios aplicables |

| # | Grado | Hallazgo | Estado |
|---|---|---|---|
| A1/B1 | Important | Ventanas de audio están en mesetas ycola posterior alfin; omitir gainPoints pasa. Tono880constante con recorte en ciclos enteros no distingue trim. E2E114. | Corregido para revisión2 con rampas ymarcador1760 fuera deltrim. |
| B2 | Important | spectrum solo lee canalizquierdo; dos canales declarados con derecho vacío pasan. E2E30. | Corregido para revisión2 midiendo L/R ysu diferenciaRMS. |

Ambos revisores ejecutaron sondas Node sobre el spectrum real: señal sin envolvente/trim oderechosilencioso acepta todaslasaserciones previas. No ejecutaron FFmpeg/Chromium niQA. Esto demuestra huecos del test, no defectos de producto.

## Corrección del oráculo y trazabilidad

Root reproduce el falso positivo antes del fix: assertderechazo falla (`5b5747`), rawJSON ystdout en t11-composite-oracle-red.json/prove-oracle.txt. Nuevo oráculo inexistenteRED7/7 (`8feb07`); implementación de pruebas→GREEN7/7 (`e04db6`): fixtureanalítica válida yseis mutaciones sinenvolvente/trimStart/trimEnd/loop/derecho/derechoinvertido. La fixtureanalítica no usa el mezclador ni el generador WAV del E2E.

MúsicaWAV contiene1760Hz fuera de[0,25;1,25]s y880Hz dentro. Tras trim+loop, el resultado no debe contener el marcador. Mesetas esperan0,05; rampasglobales0,3–0,4 y3,0–3,1s esperan0,02±0,005, cola3,35–3,45s≤0,002RMS. Se mide lo mismo enamboscanales ysu diferenciaRMS≤0,001 para la fixtureduplicada. Un archivo corto o noestéreo no se acepta como silencio. Código compartido por E2E yunitarias, condeclaracionesTS.

Desktop reforzado1/1correcto (`dbbaba`), sin cambiar el producto. Revisión2 yQAindependiente aúnpendientes. Las notas degananciasanteriores no se danporaceptadas sin este nuevo oráculo.

QA detectó además trazabilidad incompleta: T11sinCubre eIDs13/14duplicados. Se añaden CubreT11E2E11–22/M03 yCubreT10E2E09/10 (T10siguebloqueado, no ejecutado); casos públicos recibenIDs20/21/22 únicos. coverage-check0refsrotas/0testshuérfanos; otras11tareas sinmapping siguenabiertas yfuera de QA T11, sin exenciones inventadas.

QAindependiente del flujo custom-agents está READY sin suites; su documentación/script de agente procede del fallbacklocalcompleto1.22.0 al faltar agents/commands en cacheCodex1.21.1. Se reutilizan herramientas/dependencias instaladas, sin instalaciones ni permisos repetidos. Solo ejecutará después de revisión aprobada yfreeze de criterios.


## Revisión independiente — intento 2

A2/B2 frescas, cincofuentes/delta236líneas; manifiesto5SHAcoincidente,scope0fuera/avisos/exclusionesusuario,C/Dfalse. Tabla ytodoslosgaps/✓anteriores transmitidos; no se reabren sin evidencia nueva.

| Criterio corregido | A2 | B2 | Evidencia |
|---|---|---|---|
| Envolvente ausente rechazada | ✓ | ✓ | Rampasglobales0,3/3,0s;mutaciónsinenvrechazada;medidasreales≈0,019968/0,019963 |
| TrimStart/trimEnd/loop discriminados | ✓ | ✓ | Marcador1760fuera/880dentro,fixtureanalítica independiente;3mutaciones rechazadas |
| CanalesL/R ypolaridad preservados | ✓ | ✓ | Derecho0/invertido rechazados;medidasrealesdiferenciaRMS1,64e-6 |
| CalibraciónWAV yentradas inválidas | ✓ | ✓ | B2sonda1760/880/1760≈0,25;PCMcorto/NaN rechazados |
| Resto criterios delintento1 | ✓preservados | ✓preservados | Sin cambiosdeproducto ni evidenciaadversa nueva |
| EvidenciaRED/GREEN ytrazabilidad | ✓ | ✓ | Rawrootrechazofalla,7RED→7GREEN;T11Cubre11–22/M03,IDsúnicos;coverage0rotas/0huérfanos |
| QAindependiente final | Pendiente | Pendiente | No ejecutada porrevisores |

0Critical/Important/Minor pendientes. A2/B2ejecutaron las7unitarias correctas y contrastaron medidasJSON conelhelperactual. SinChromium/FFmpeg/ONNX/VPS, sinconfundir la revisión conQA.

La evidencia actual de desktopfocal1/1 está en run-general-bfe6a820-36b3-4d40-bc1a-b9f0cefd5a70. MovimientoRMS28,457;440≈0,049847;880≈0,049994;fundidos≈0,019968/0,019963;marker1760≈2,48e-5;diferenciaL/R≈1,64e-6;cola0. Amboscanales medidos. QAindependiente iniciará la pasada63prevista con fuentes ycriterios congelados; resultado real/gate determinará suveredicto, sin cierre automático de lainiciativa23tareas.
