# T-07/T-11: subtítulos y ZIP multipágina

2026-11-05. **Intento2: A2/B2/C2 sin gaps; D1 preservada, selectorDdelta=false.** Los cuatro Important de intento1 están corregidos. T-07 y T-11 siguen abiertas, total6/23, hasta QA independiente. Las pruebas implementadas no borran el NO-VERDE62/63 de QA1.

Actualización posterior: [QA2](../final-qa2/report.md) terminó72/72 automáticos y42/42 observaciones de medios, pero abrió ImportantQA2-V01 de legibilidad conArial22normal/contorno2. La [corrección de contraste](../caption-contrast/review.md) tiene RED causal y GREEN nativo, con revisión3/QA posterior pendientes. Esta evidencia nueva no reabre los cuatro gaps de codec/import ya corregidos, pero impide cerrar T7/T11.

## Snapshot y evidencia

Diff acotado2596 líneas,39 fuentes:17 existentes,19 nuevas y tres helpers;76 archivos de build. Las17 bases se verificaron por SHA previo: ocho copias originales y nueve reconstruidas exclusivamente en copias, sin usar HEAD como falsa base del WIP. No faltan bases. El snapshot final anterior a los fixes se conserva en `storage/framework/testing/t11-captions/review-attempt1-final/`, con fuente/baseline/build/evidencia. Root mantiene siete descriptores actuales aparte, leídos completos, sin atribuirles una base anterior inexistente.

PHP102/102,549 aserciones y cero skips, con un puente AV nativo real; Node55/55; Chromium4/4; ZIP Python real5/5; TypeScript y build correctos. La pasada propia de captions terminó **9/9**, cero flaky/skips/errores de runner/reintentos,206939ms, sesión41544/helper6304/Playwright24692 terminales. Raíz `run-general-57b7e341-6e5a-4bf0-9867-dde69ab4737a`; JobObject3GiBCOMMIT, pico1209057280B. Los39 SHA de fuentes y76 generados no variaron durante esa pasada. Son tres casos en desktop/mobile375/mobile360; no QA2.

Se descargan MP4/GIF con ventana, fuente y revisión fijada; SVG/PNG multipágina en ZIP y PDF real; se miden A sola/cue1.8–1.95 y selecciones C→A/A→C desde UI→worker. El fallback ZIP elimina la dependencia obligatoria de ZipArchive local con Python estándar y ZIP64, conservando la rama existente cuando la extensión está disponible.

## Revisión1 fusionada A+B+C+D

Scope HEAD: cero fuera/avisos/info/exclusiones de usuario; ledger sin incoherencias. Modelo heredado por instalación parcial del plugin. C se activó por `exec` en captions.js, que C confirmó como RegExp.exec. D se activó por Scheduler, heurísticas N+1/await/JSON; analizó el delta real. Sin constitución/dev.json/cobertura mínima obligatoria ni interop aplicable. Las cuatro lentes leyeron el diff completo por bloques.

| Criterio | Veredicto / lentes | Evidencia |
|---|---|---|
| Alcance T7/T11 y T12 excluida | ✓ A | Archivos del ledger cubren servicios/render/UI/API/MCP/fallback. |
| Datos opcionales y compatibilidad v1/v2 | ✓ A | StudioTimeline:39,81; timeline.js:118 y caracterizaciones. |
| Unicode, espacios significativos, BOM/CRLF ordinarios | ✓ A; bordes LF abiertos B1 | Codec y tests PHP/JS; los probes B añaden el caso no cubierto. |
| LF extremos al exportar | ✗ B1 | `Hola\n` pierde LF en roundtrip; `\nHola` genera descarga no reimportable. |
| Varias líneas vacías entre cues | ✗ B2 | Separador3LF falla;2/4LF funcionan en PHP/JS; afecta SRT/VTT. |
| IDs globales al importar | ✗ B3 | Página o clip/audio `cue_1` colisiona con ID generado; append y replace fallan. |
| Importar sin reemplazo silencioso | ✗ A1 | Modo opcional aplica replace; sonda real PHP conserva solo el nuevo cue sin warning. |
| Errores de formato, UTF-8 y sintaxis no soportada | ✓ A | Rechazos tipados y UI; un error nativo inglés identifica codificación, no se elevó a defecto funcional. |
| Límites5000/8/500/512KiB/2MiB | ✓ A | Validaciones y pruebas de límites. |
| Servicios compartidos, abilities y aislamiento | ✓ A/B/C | Controller/tool/rutas; propietario/marca/fuente y revisión comprobados. |
| Paginación, revisión fijada y atomicidad | ✓ A/B/C | Servicio y snapshots, E2E acceso y pruebas PHP. |
| Fuentes privadas y uso histórico | ✓ A/C | Timeline/Fonts/AvProcess y autorización, hashes y snapshots. |
| UI: edición, offset, estilo, undo/autosave/reapertura/exportación/error | ✓ A | Tres viewports en final9; no sustituye los bordes de codec descubiertos después. |
| Índice reutilizable y seek | ✓ A/D |5000 cues;100000 consultas15,03ms, máximo14 comparaciones; compilación4,27ms. |
| Selección A sola/C→A/A→C y reloj fuente | ✓ A/B | Tests puros y exportación pública medida. |
| Composición global opaca después de blend | ✓ A/B/D | Preview/renderer y pruebas de píxeles. |
| Preview, estático, SVG/PNG/PDF y MP4/GIF con fuente privada | ✓ A/B/C | Cuatro caminos y archivos descargados. |
| Pausa del reloj nativo durante primera carga tipográfica | ✓ A | Font-buffer-green4/4; fuente futura no precargada. |
| ZIP: orden/bytes/CRC/ZIP64/límites/exclusividad/limpieza | ✓ A/B/C/D | Cinco pruebas reales; C valida argv/rutas/enlaces; D comprueba una lectura por página. |
| ZIP streaming y recursos acotados | ✓ D | Prueba pura64.000.000B:62 bloques, máximo1.048.576B/petición; no es benchmark de disco. |
| ZipArchive preferido y fallback sin paquetes/modelo/config global | ✓ A | Renderer y guía/deploy actualizados. |
| Texto/estilo sin ejecución, HTML o URL activa | ✓ C | exec es regex; Fabric escapa texto SVG; nueve payloads rechazados. |
| Descarga privada sin inyección de cabeceras | ✓ C | Filenames/formatos cerrados y controller. |
| Helpers CLI sin nueva exposición remota | ✓ C | Tres helpers leídos completos, sin endpoint. |
| Acciones masivas y heurísticas de bucles | ✓ D | Offset5000IDs40,11ms; append2500+2500 con colisiones127,79ms. Son acciones únicas, no por frame; eager loading conservado. |
| OpenAPI compatible | ✓ A | Lint0errores/3avisos previos, diff0rompedores/2paths nuevos. |
| Generados y descriptores al día | ✓ A |39fuentes/76generadoschanged[],build2; siete descriptores actuales leídos íntegros. |
| Constitución/interoperabilidad/cobertura mínima | No aplican | Ausencia y alcance comprobados. |
| Verificación declarada y tareas abiertas | ✓ A | Evidencia real sin etiquetar9/9 como QA2; T7/T11 abiertas. |

| Gap | Grado | Fuente | Escenario / decisión |
|---|---|---|---|
| A1 | Important | StudioCaptions.php:57; captions.js:141 | Importar sin mode elimina cues previos. Default append; replace explícito. |
| B1 | Important | StudioCaptionCodec.php:73; captions.js:92 | Encoder acepta LF que no puede conservar. No recortar: error explícito para texto no representable en SRT/VTT; documento y render siguen admitiéndolo. |
| B2 | Important | StudioCaptionCodec.php:22; captions.js:64 | Separadores impares dejan LF residual. Admitir dos o más LF tras normalizar CRLF. |
| B3 | Important | StudioCaptions.php:58; captions.js:138 | Generación no reserva IDs globales. Reservar páginas/elementos recursivos/audio y cues existentes en append; IDs explícitos siguen estrictos. |

Sin Critical. C y D no encuentran gaps. El contorno de texto observado en capturas no se promueve como defecto por preferencia estética sin escenario funcional probado.

## Corrección y revisión2

Antes de tocar las39 fuentes se guardó el snapshot anterior completo y se ejecutaron tests privados: Node32 casos,13 correctos/19 fallos; PHP29,10 correctos/nueve fallos/diez errores de dominio que reproducen los casos. No son errores de infraestructura. Los separadores pares, IDs explícitos estrictos y Unicode/espacios/TAB/LF no vacíos ya correctos se declaran caracterizaciones. Raws `review1-red-js.txt` y `review1-red-php.txt` bajo storage del bloque.

El [contrato](../../../t11-captions-contract.md) aclaró comportamiento antes del fix. GREEN final: PHP63/63,168 aserciones; JS52/52 y vecinos87/87; Chromium5/5. El quinto caso nuevo es caracterización, no RED: textos con LF inicial/final/interior continúan renderables y documento byteintacto. Nuevo build, Pint y OpenAPI correctos. No se repitieron los nueve E2E por cada fix de codec; sus archivos y snapshot siguen conservados como evidencia anterior.

Freeze3, `storage/framework/testing/t11-captions/review-attempt2-final/`: delta362 líneas de diez fuentes,39 fuentes/76 generados verificados; los dos bundles audiovisuales mantienen SHA idéntico. Los tres helpers no cambiaron. A2/B2/C2 recibieron la tabla completa anterior y preservaron todos los ✓; sin rebatidos ni gaps nuevos. Selector sobre delta10: C=true por exec de regex, D=false; las medidas y conclusiones D1 se conservan sin inventar un benchmark nuevo.

| Gap anterior | Resultado revisión2 | Evidencia |
|---|---|---|
| A1: default reemplazaba pista | ✓ Corregido A2/B2/C2 | Servicio:57/JS:138, tests HTTP→MCP append→replace y schema/guía alineados. |
| B1: pérdida de LF | ✓ Corregido A2/B2/C2 | Codec:73/JS:92; rechazo accionable sin mutación, documento sigue válido y renderable. |
| B2: separadores impares | ✓ Corregido A2/B2 | Codec:22/JS:64;2/3/4/5LF,CRLF y separadoresSPACE/TAB probados. |
| B3: IDs globales | ✓ Corregido A2/B2/C2 | Data:11/Service:58/JS:139; páginas, grupos recursivos/audio y cues según modo; IDs explícitos estrictos. |

La tabla completa de criterios de revisión1 anterior se transmite a revisión2 con estos cuatro ✗ convertidos a ✓. Los demás criterios conservan evidencia/lentes originales; aceptación final T7/T11 todavía pendiente de QA2. **Cero Critical/Important/Minor abiertos** del bloque.

A2 ejecutó Node52/52, OpenAPIlint0errores/tres avisos previos y diff0rompedores;39 fuentes/76 generados y siete descriptores sin deriva. B2 ejecutó40/40 probes JS y36/36 PHP de codec/IDs, ambos snapshots y20 hashes before/after del delta correctos. C2 ejecutó tres modos JS, quince rechazos y dos modos PHP, incluyendo claves de prototipo; sin hallazgos,39 SHA correctos y29 fuentes sin cambios. No se atribuyen estos probes a QA nativa ni se repiten pruebas no afectadas.

La próxima QA independiente usa `testing/t11/final-qa2/` y recibe señal del orquestador tras esta aprobación. Incluirá los doce specs y recuento real del JSON, con observación propia de píxeles/fuentes/ZIP/selección, informeMD+PDF inspeccionado y SHA antes/después. No hay cierre, commits ni push antes del resultado; QA1 permanece intacta.


## Resultado posterior — 2026-11-06

[QA3 final](../final-qa3/report.md) ejecutada con las fuentes corregidas:72/72,42/42medios y24/24contraste, tres viewports legibles, PDF9páginas inspeccionado y842SHA sin deriva. Root acepta localmenteT-07/T-11. Las tablas y focales anteriores conservan sus resultados históricos; esta nota no convierte sus fallos enPASS. VPS/R2/social/T-10/ASR permanecen fuera de esa aceptación y el roadmap sigue abierto.
