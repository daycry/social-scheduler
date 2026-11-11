# Pruebas de la suite social y creativa

Ámbito: T-03, T-04–10, interfaz/preview, fotogramas, motor y flujo público audiovisual de T-11, y coherencia de marca de T-21. Las demás capacidades de la matriz permanecen abiertas y necesitarán pruebas propias.

## Servicio y contrato

- PHPUnit/Pest: suite completa al integrar contratos compartidos y catálogo MCP. JUnit real, cero errores/fallos/omitidos para acreditar esta ejecución.
- Node: documento, geometría Fabric, cargas cancelables, vídeo recortado, historial, plantillas, límites, raster fraccionario y comparación de píxeles de líneas/trazos.
- Render aislado Chromium: SVG/PDF/PNG reales, imagen privada registrada sin cookies, origen sintético con mapa cerrado y sin continuación de tráfico externo. Probar redondeos y recursos sin simular el proceso en esta capa.
- OpenAPI: lint estructural; respuestas reales pending/kit/documento contra tipos/nulabilidad; paginación de diseños/exportaciones y catálogo MCP.
- Propiedad: otro usuario/marca no accede a diseño, carpeta, kit, recurso o descarga. API bearer sin permiso no escribe; herramientas mixtas seleccionan ability por acción.
- Consultas: preparar 100 recursos <=2 SELECT de recursos; presentar 100 exportaciones <=2 SELECT de exportaciones y primer lote limitado a 50, con página siguiente sin IDs repetidos.

## E2E automáticos

Los tests usan el runner local aislado con SQLite, almacenamiento y cuentas desechables. Las llamadas a redes se impiden; generar una publicación significa crear un **borrador**, no enviarla al proveedor. Ejecutar cada recorrido en `desktop`, `mobile` (375 px) y `mobile-360`.

| ID | Archivo/caso | Criterio observable |
|---|---|---|
| E2E-01 | `metrics-quality.spec.ts` | Cero real conserva cobertura parcial y fecha; comparación desigual se omite; denominador diario visible; sin errores JS ni overflow. |
| E2E-02 | `studio.spec.ts`, persistencia | Crear, añadir texto, guardar/reabrir; PNG 1080² real; subir/registrar y abrir borrador con adjunto en su marca. |
| E2E-03 | `studio.spec.ts`, historial/exportación | Undo/redo, páginas, JPEG/WebP/SVG/PDF con firmas/dimensiones/páginas reales; imagen portable sin URL privada. |
| E2E-04 | `studio.spec.ts`, conflicto | Adelantar revisión desde segundo escritor; 409 conserva trabajo local y permite copia JSON o recuperar versión guardada. |
| E2E-05 | `studio.spec.ts`, raster fraccionario | Diseño 41² a escala 0,5: PNG/JPEG/WebP 21²; subida y registro 201 en servidor. |
| E2E-06 | `brand-reporting.spec.ts` | Cambiar marca en Ads, abrir informes sin query y conservar marca; generar informe y mantener contexto/propiedad. |
| E2E-07 | `studio-automation.spec.ts`, plantilla propia | Guardar una copia como plantilla sin convertir el original; biblioteca por marca, campos literales con `$&` y llaves, instancia independiente, edición/reapertura y PNG real. |
| E2E-08 | `studio-automation.spec.ts`, variantes/kit | Aplicar identidad real, adaptar tamaño conservando geometría, generar dos variantes sin cambiar el original, reabrir y exportar un PNG de dimensiones exactas. |
| E2E-09 | `studio-imaging.spec.ts`, fuentes | Subir TTF con licencia, normalizar a WOFF2, cargar FontFace real, buscar/archivar/restaurar, vincular al kit, crear/reabrir/exportar texto con ese archivo y rechazar DELETE en uso con explicación visible. |
| E2E-10 | `studio-imaging.spec.ts`, imágenes | Subir fotografía pública, buscarla, encolar y ejecutar el procesador ONNX real en el entorno desechable; medir alpha y sujeto, comprobar SHA del original, añadir otra imagen, aplicar máscara/filtro/recorte, reabrir y exportar. |
| E2E-11 | `studio-timeline.spec.ts`, ventanas y reloj | Activar v2, editar inicio/duración, dividir texto, guardar/reabrir y comprobar avance/pausa sin mutar documento. |
| E2E-12 | `studio-timeline.spec.ts`, vídeo real | Generar MP464²/3s, subir/sondear, añadir sin perderlo al abrir timeline, dividir con trimEnd=null conservando sourceOffset, reproducir y guardar. |
| E2E-13 | `studio-timeline.spec.ts`, página2 | Cabezal global7s divide clip local en2s; undo/redo conserva ventanas, guardado y ausencia de overflow. |
| E2E-14 | `studio-timeline.spec.ts`, buffering | Con clip0,5s y latencia local900ms, preparar recursos no consume la duración; avance inicial<0,4s y final0,5s, sin errores. |
| E2E-15 | `studio-timeline.spec.ts`, cancelación | Cerrar el timeline mientras carga el vídeo cancela el play pendiente; no reaparece la preview ni avanza el reloj al reabrir. |
| E2E-16 | `studio-audio-resources.spec.ts`, API y navegador | Subir tono WAV propio como binario mediante endpoints reales, sondear y registrar por marca, descargar rango exacto y decodificar/reproducir/pausar en HTMLAudio nativo sin relajar autoplay. Comprobar catálogo social sin audio. El elemento nativo del test no se presenta como control del editor ni acredita mezcla/UI de pistas. |
| E2E-17 | `studio-audio-editor.spec.ts`, pistas en editor | Subir WAV propio desde Recursos, añadir pista global, recorte/volumen/rate/fundidos/loop/mute, dividir, undo/redo, play/pause, guardar/reabrir, eliminar/deshacer sin mutar documento por preview. Captura por viewport, consola limpia y sin overflow. |
| E2E-18 | `studio-audio-editor.spec.ts`, duración decimal | Página1,2s/FPS24 y audio4s: añadir pista con duración1,2s válida, guardar y comprobar persistencia. El redondeo de frames no amplía el límite del documento. |
| E2E-19 | `studio-frame-export.spec.ts`, fotogramas estáticos | El cabezal1,5s inicializa el campo de página; exportar0/1,5 produce PNG blanco/rojo medido en el archivo privado. Registro conserva options.frame_times y el documento original. Consola limpia, sin overflow y captura del diálogo por viewport. |
| E2E-23 | `studio-captions.spec.ts`, edición y persistencia | Activar temporal explícitamente en v1; importar SRT/WebVTT Unicode, editar cue y estilo, añadir/eliminar/desplazar, undo/redo, guardar y reabrir. Listado/búsqueda y cue activo responden al reloj. Descargar SRT/VTT preserva texto y tiempos; archivo inválido informa error sin mutar la pista. Arial22normal blanco con contorno negro2 sobre negro conserva letras legibles; no basta ver el emoji. Captura, consola limpia y sin overflow en tres viewports. |
| E2E-24 | `studio-captions.spec.ts`, composición y selección | Preview y exportación real estática/MP4/GIF respetan ventana del cue y estilo/fuente privada. Medir antes/dentro/después y en transición: una sola composición opaca tras blend. A sola conserva cue1.8–1.95 aunque originalmente tenga fade; selección no adyacente/reordenación usa ventanas fuente correctas sin alterar el documento. Fuentes siguen disponibles al descargar SVG/PDF. Añadir fixtureArial22normal/fillblanco/strokenegro2 sinemoji: preview y PNG/MP4/GIF conservan cobertura blanca y energía de letras respecto al controlstroke0 positivo sobre fondo negro; comprobar ventana y documento/revisión. |
| E2E-25 | `studio-captions.spec.ts`, revisión y aislamiento | Documento modificado después de encolar no altera subtítulos/fuente de la revisión exportada; otra marca no puede leer/escribir/exportar ni usar su fuente privada. Validación/revisión/abilities comunes a UI/API/MCP; conflicto preserva edición local y muestra recuperación. |
| E2E-26 | QA de transporte de datasets (`studio-datasets-api.spec.cjs`, preparación privada previa) | Servidorlocalhost aislado porTCP: bearer/abilities/owner+brand,CRUD/revisiones/409,filasoffsetrevisiónfija,importmultipart25MB,JSONtyped,descargasseguras/fiel/JSONyheaders,503,guardiasantesdecode,catálogoMCPpaginadoynuevaherramienta conacciones reales. SinUIaún,nicierreT12. |
| E2E-27 | `studio-datasets.spec.ts`, biblioteca/editor | Crear/importarCSVTSVJSON, editar tipos/IDs sin pérdida0.0/-0.0/entero53bits, undo/redo, estructura/conversión explícita, guardar/reabrir,409 conserva borrador y recarga explícita, historial fijo/archivar-restaurar/descarga real segura, aislamiento por marca y respuestas tardías; tabla semántica/teclado/foco, consola limpia y sinoverflow375/768/1440. |
| E2E-28 | `studio-charts.spec.ts`, integración de gráficos | Cuatro escenarios en375/768/1440:10tipos con controles y preview/exportSVG-PNG, históricos/undo/errores/accesibilidad, export estático servidorSVG-PNG-PDF con referencias fijadas y páginas ocultas,12.000barras reales con presupuesto medido. Intentos1/2/3 NO-PASS; nueva matriz pendiente tras corrección focal. |
| E2E-29 | `studio-presentations.spec.ts`, `studio-presentation-media.spec.ts`, `studio-presentation-tools.spec.ts` | Notas/historial, presentación AV real y P2 completo; P3: láser/trazos/TTL/borrado/páginas/blackout, audiencia de píxeles y fullscreen con gesto propio, temporizador/música propia/pausa/volumen/silencio/loop/fin, cierre durante preparación pendiente. Fuentes preparadas; revisión final, build y aceptación nativa integradas pendientes. Conservar375/768/1440 y los24casos P2; recuento total sólo por colección actual. |
| E2E-20 | `studio-native-export.spec.ts`, MP4/GIF | Ordenar páginas, cancelar/reintentar mismoID tras recargar, workerreal, descarga de medios medidos yborradordemarca. |
| E2E-21 | `studio-av-composite.spec.ts` | Vídeomóvil/tone440 yWAVconmarcadores1760 fuera deltrim880; overlays/fade,42frames/3,5s, niveles/rampas/recorte/loop medidos enamboscanales, GIFescala0,5 yborradorconmedioexacto. |
| E2E-22 | `studio-native-export.spec.ts`, historial | 55exportaciones reales,50porpágina,una consulta porpágina, actualización yreapertura sincreartrabajos. |


### QA final de fuentes e imágenes (T-10)

La QA final local de E2E-09/10 conserva dos escenarios en 375/768/1440 píxeles (seis casos), un worker, cero reintentos y fallo temprano con reporter JSON real. La fuente propia debe cargar WOFF2 real del origen local; el PNG exportado se contrasta con glifos de referencia dibujados con la TTF original y debe discriminar Arial como fallback. El resultado de imagen se contrasta mediante un decoder PNG independiente con alpha, elipse, recorte y sepia; se conservan hashes del original y metadatos de revisión. Las seis auditorías axe de fuentes/Propiedades deben tener cero violaciones e incomplete, sin exclusiones. Errores de aplicación y HTTP inesperados fallan; sólo el DELETE409 concreto de la fuente en uso se predeclara y afirma.

El modelo mantiene 6 GiB por worker. Sólo la QA integrada de imagen tiene Job agregado de 7 GiB para controlador/PHP/browser, preflight físico de 11 GiB antes de crear UUID o servidor, piso observado de 4 GiB cada 100 ms y deadline de 660 s; killOnClose y noBreakaway consultados en kernel. Si falta capacidad se registra REFUSED sin ejecución; no se reducen protecciones ni se repite automáticamente. El supervisor audiovisual de 3 GiB permanece intacto. Las pruebas de presupuesto focales aceptadas en Windows no sustituyen esta QA ni acreditan Linux/VPS. El efecto de la segmentación en accesorios se observa sin prometer conservación perfecta; el original se conserva y el resultado se previsualiza antes de añadirlo.

Las capturas se revisan visualmente. `qa-gate.py` consume el JSON real de Playwright y debe dar VERDE; no se fabrica un resumen con menos casos que la ejecución. Las pruebas focales durante desarrollo no sustituyen el resultado final tras las correcciones de revisión.

La QA independiente final local de T-11 quedó aceptada en [QA3](testing/t11/final-qa3/report.md), con 72/72 casos y observadores audiovisuales separados. Incluyó los recorridos de estudio, automatización, timeline, audio, fotogramas, exportación, composición, subtítulos y regresión relacionada de métricas/marcas en tres viewports. Excluyó studio-imaging.spec.ts: esa pasada no acredita T-10. La condición de memoria de T-10 ya se resolvió en su focal Windows, pero su aceptación integrada requiere la QA separada de imagen descrita arriba. Las capacidades no implementadas y los manuales externos siguen abiertos; no se declara equivalencia global por cerrar la composición audiovisual.

`studio-audio-preview.browser.test.mjs` añade medición nativa en Chromium con tonos propios440/880Hz: espectro y RMS de cada canal, pausa silenciosa, trim/rate/loop/envolvente y documento intacto; vídeo sintético30fps junto al audio sigue el mismo reloj en20muestras con desviación<=1frame de salida. Carga sin respuesta debe abortar/liberar al cerrar;64pistas muted mantienen estado sin crear decodificadores ni seeks/buffering de audio. El recorte0,039s a rate0,25 conserva precisión. Estas medidas automatizadas no sustituyen la aceptación del render MP4/GIF ni una revisión audiovisual humana.

T-10 añade `tests/studio_runtime` para checksum, escritura exclusiva, instalación atómica, preflight tipográfico e inferencia real sobre `astronaut.png` de NASA. Ejecutar con el Python privado instalado por `scripts/studio/setup-runtime.py --download-model`; la ausencia del modelo no se omite como un éxito. El runner E2E usa los pesos compartidos solo en lectura y conserva todas las entradas, salidas y filas en su raíz desechable. CI instala los paquetes privados y prueba parser/contratos; las pruebas nativas de inferencia y E2E local aportan evidencia aparte, sin presentarlas como ejecución del VPS.

## Motor audiovisual interno

Estos tests llaman a las bibliotecas nativas con snapshots y fixtures propios. Por sí solos no acreditan jobs/progreso/reintento/registro web/API/MCP; las pruebas públicas E2E-20, E2E-21 y E2E-22 añaden ese alcance.

| Archivo | Criterios |
|---|---|
| `studio-audiovisual-plan.test.mjs` | Reloj entero sin deriva en siete FPS, muestras48000, ventanas de grupos sin reiniciar fase/envolvente, fades de páginas/pista global, trim/rate/offset/loop/EOF y metadata inválida. |
| `studio-audiovisual.browser.test.mjs` | Render offline sin abrir audio ni play; vídeo conserva último frame estrictamente dentro del trim y no muta el documento; política de pitch coherente. |
| `studio-audiovisual-audio.test.mjs` | Mezcla de tonos/frecuencias/ganancias y continuidad de muestras; normalización M4A44,1/48k yMP344,1k aPCM48k, tramos7frames/default2s con RMS<0,0001 respecto a decode continuo;64pistas comparten1perfil/1lectura; perfiles/rutas/abort y comprimido truncado rechazado. |
| `studio-audiovisual-render.test.mjs` | Vídeo con movimiento/overlay/transición reales; MP4H264/AAC con faststart y tono medido; GIF único con30frames, paletas ydelays acumulados; soundtrack original/fade y fuente96k corta seguida de silencio. |
| `studio-audiovisual-safety.test.mjs` | Mapa cerrado/Range/HEAD yrechazo de peticiones ajenas, rutas/MIME/IDs/tamaños inválidos, procesos acotados/abort, parciales y manifiestos alterados. |

La pasada histórica de regresión de interfaz seleccionó `studio`, `studio-automation`, `studio-timeline`, `studio-audio-editor` y `studio-frame-export`:14escenarios en tres viewports,42recorridos. No sustituye la pasada previa54ni la aceptación pública compuesta actual. Se conserva como evidencia de su snapshot histórico. El JSON real determina su resultado; no se cuenta un test interno como una exportación realizada por el usuario. La muestra sintética del motor conserva30frames/2,5s con fuente privada ydos tonos; sus píxeles se inspeccionan aparte. T-10 y ONNX mantienen su puerta independiente.

## Manuales externos preservados

| ID | Responsable | Evidencia necesaria |
|---|---|---|
| M-01 | Usuario | Scheduler/worker del VPS ejecutándose, heartbeat y capturas recientes del periodo cerrado. No se pide SSH de nuevo. |
| M-02 | Usuario | Publicación real por red y smokes Bluesky/Threads previamente pendientes. |
| M-03 | Desarrollo + usuario al configurar | Renderer del servidor: Node/Chromium/fuentes/cola media, artefacto real con imagen/vídeo local y R2. Las pruebas locales no certifican Linux/R2 en producción. |
| M-04 | Usuario, cuando lo retome | Claude Desktop: sigue aplazado; no volver a abrir autorización. |

El fallo de un requisito externo permanece explícito. La suite no declara paridad completa ni operación en producción por tener estas pruebas locales correctas.


### Flujo público audiovisual

El caso público de `studio-native-export.spec.ts` recorre MP4/GIF desde el diálogo, orden explícito y omisión de opciones estáticas, cancel/retry y persistencia de historial al recargar, worker database real y descarga con codec/dimensiones/frames/píxel inicial, borrador de la marca sin publicación. Se ejecuta en desktop/mobile/mobile-360. Su pasada root48/48 está en testing/t11/export-ui; la QA final independiente del padre se registra aparte. `studio-export-jobs.test.mjs` cubre contrato del cliente, consultas sin solapamiento, respuestas tardías, presupuesto5min y reanudación sin duplicar trabajos.

Regresiones de revisiónUI: `studio-export-jobs.test.mjs` exige cancelación real de fetch y serialización hasta settle aun con transporte no cancelable; `studio-export-job-panel.test.mjs` compila el SFC real con Vue para comprobar respuesta de borrador tras cerrar y lectura tras cambiar MP4/GIF. Son casos aislados sin Chromium, red ni ONNX.


La aceptación compuesta de `studio-av-composite.spec.ts` usa recursos propios subidos por UI, vídeo con movimiento y tono440, música WAV estéreo880 con marcadores1760 fuera del trim, loop y envolvente, overlays y fade de páginas. Exportación MP4 H264/AAC y GIF silencioso/escala0,5 con42frames/3,5s; píxeles/RMS de movimiento y espectro/amplitudes/rampas/silencio en ambos canales, con igualdad L/R para la fixture estéreo duplicada. Se modifica el documento actual después de encolar para comprobar revisión fijada. Descarga y borrador de marca con IDs de medio comprobados. A2/B2 aprobaron el oráculo; la QA independiente terminó62/63, con fallo compuesto mobile-360: [evidencia original](testing/t11/final-qa/report.md). La corrección SQLite en revisión necesita nueva aceptación, sin reemplazar este resultado por repeticiones focales.

El oráculo compartido de aceptación (`tests/e2e/support/studio-composite-audio.mjs`) tiene regresiones puras que rechazan salida sin envolvente, trimStart, trimEnd, loop, canalderecho ycanalderechoinvertido; una fixtureanalítica válida calibra0,05enmeseta y0,02enrampas. No se confunden los siete casos ligeros con ejecuciones del worker.

### Subtítulos: criterios previos a interfaz y render

La primera versión de E2E-23–25 se ejecutó en QA2, dentro de sus72casos correctos y42 comprobaciones independientes de medios. QA2 mantuvo aceptación visual NO porQA2-V01. Los criterios reforzados de legibilidadArial22/controlstroke0 de E2E23/24 se añadieron después; su recorrido actual completo queda pendiente de QA3. No se atribuyen subtítulos a la QA63 anterior ni se presume el número final de pruebas antes de leer el JSON real. El [contrato](t11-captions-contract.md) fija límites, estilo, codec y remapeo. ASR/traducción corresponde además a T-14 con pruebas del proveedor/modelo real; importar texto manual no cierra ese requisito.

Los tests puros deben comprobar parser/serializador SRT/VTT sin perder espacios significativos, Unicode, BOM/CRLF, intervalos y rechazos con error concreto; validación estricta equivalente PHP/JS, IDs únicos y ocho activos; índice reutilizable de5000 cues con seek y consultas acotadas. Las operaciones de selección prueban A sola, ventanas contiguas, cortes/reordenación, repeticiones y bordes `[start,end)` sin mutación del original. Un resultado esperado vacío no basta para acreditar remapeo.

Las pruebas nativas deben observar el archivo o canvas real y la fuente privada, sin sustituir render por un DTO. SVG debe contener el subtítulo y recurso tipográfico portable; PNG/PDF deben mostrarlo; MP4/GIF deben conservar ventana, posición, opacidad y selección en frames medidos. La medición durante el fade debe distinguir dibujar una vez del doble blend de texto. Preview, render estático y offline comparten reglas. Snapshots y uso histórico de fuentes se verifican también en backend/API/MCP.


### Datasets: transporte aceptado; editor pendiente

E2E-26 se ejecutó por transporte real sin navegador: [QA2 aceptada13/13](testing/t12/dataset-api-qa2/report.md); QA1 conserva6PASS/7fallosdeharness inmutables. Los párrafos siguientes conservan el protocolo utilizado: los tres viewports anteriores aplican a recorridosUI; repetirAPIporanchodepantalla no cambia suvalidación. Editor/tablas/gráficos sí tendrán pruebasUIresponsive independientes cuando estén implementados.

El runner crea servidor propio localhost8080, SQLite/storage/cuentas/token de prueba UUIDsin.envreal, sin ngrok/producción/VPS/R2/redsocial. Descargas importadas y requests son reales porTCP, no sólo HTTPKernel/UploadedFile. Guardrailprivatehost, procesospropios y JobObjectWindows3GiB COMMIT,killOnClose/noBreakaway condeadlineycierreárbol; métricaPHPallocator separada deCOMMIT yRSS. PHPservidor512MiB declarado paraenvoltorioMCPgrande, sin ini_set producción.

Fijar endpoints yfixtures después de revisiónAPI aprobada y hash de susfuentes. No editarapp/contrato/criterios/build duranteQA. Una sola pasada íntegra;timeout de observación no autoriza reinicio. Sólo señal explícita delroot «revisión aprobada; ejecuta intento1».

Casos:401/403y404usuarios/marcas,crear/replace/historial/archivo-restaurar,409sinmutación,filasrevisiónfijada/offsetsinpérdidas y6MiB,archivoCSV25.000.000Bmultipart+extremos/hash/metadata,JSONtypedobjeto/lista,CSVseguroTODOSstringsytextfiel,JSONstream/hashes/headersprivados/filename,guardia53MiBantesparse yContentLengthausente,missing/corruptsnapshot503controlado,descubrimientoMCPpaginas/actionread/write/auditoríadatasetID ysmoketoolanteriores. TransporteMCP160MiBdebepermitirelJSONUnicodeescapadolegítimo del dataset52M conperfildememoria declarado; si se mide menorcaso sólo se acredita ese tamaño, no el límite entero.

Elobservador independiente descarga yparseaformatos/tipos/IDs ySHA sinimportar elcodec o sus asserts. Conservarcadafallo real; errores de fixture/harness no se convierten enREDdeproducto. GateJSONrecuento real, informeMD/PDFinspeccionado, before/afterSHA yslotliberado al terminar. Mantener fuera ONNX/T10,programasajenos y smokesexternosdelusuario.


### Datasets: E2E-27 — criterios UI previos

E2E-27 requiere nueva pasada independiente tras revisión del núcleo/editor, a375/768/1440. Preparación permitida antes del freeze; no ejecutar, instalar ni levantar servidor hasta señalroot y manifiestoSHA. Tests nuevos no son REDdeproducto por no haberse ejecutado. Reutilizar sólo infraestructura aislada con configcacheficticia antesKernel/SQLite+storageUUID/cuentaspropias y JobWindows3GiBCOMMIT; nunca `.env`/MySQL del usuario. Una sola pasada nueva, sin retries/reinicio por timeout de observación. Build y Chromium comparten slotnativo; no ejecutar a la vez.

- E2E27.01: abrir desdeIndex yEditor, crear tabla, editar tiposTexto/Número/Vacío, nombre/columna/fila, undo/redo, guardar/reabrir; descargarJSON y comparar lexemas numéricos originales0.0/1.0/-0.0/9007199254740993 y valoresstringmultilínea/null/IDs.
- E2E27.02: importarCSV/TSV/JSON por input real, opción encabezado y error archivo inválido sin mutación; descargaCSV/TSVdefaultsegura ytext explícito observadas mediante parser independiente. RecorridoUI no repite52MB: transporte ya aceptado, medir un archivo representativo y celdas extremos. Página/ventanas de tabla deben dar acceso a todasfilas/columnas sin100.000inputs.
- E2E27.03: edición estructural/renombre/buscar-reemplazar literal/fillvacíos/transponer, conversión columna preview con error y atomicidad, límites visibles; guardado remoto coincidente con lo observado enUI, no sóloestadoVue.
- E2E27.04: segundoescritor adelanta revisión; guardar409 mantiene borrador, permite descarga y recarga explícita; historialpaginado revisionfija, archivar/restaurar sin perder datos; permisos y marca al cambiar no heredan tabla ajena.
- E2E27.05: lectura/escritura demorada (rutaslocales retrasadas, no mocksdeéxitoparaaceptación) al cerrar/abrir otra selección/marca no cambia selección/ni navegación; confirmar descarte y conservar si cancela. Semántica tabla/labels, foco/teclado visible y contraste del texto mediante axe ya instalado, capturas inspeccionadas a3anchos, consolaerrores0 y sinoverflow.

Recuento final lo determina JSONreal; no presumir15casos si se desdoblan. Gatecanónico+observadores+hashesbefore/after y PDFinspeccionado antesdeaceptar subbloque. Gráficos/capas, XLSX/conexiones,docs/presentaciones/pizarras siguen pendientes deT12.


### Resultado diagnóstico E27.1 y protocolo de corrección

[QA1interrumpida](testing/t12/dataset-editor-qa1/report.md) no tiene JSON final: un fallo desktop visible y ninguna cadena aceptada. No se reescribe como15fallos ni se suma comoPASS. La siguiente pasada usará nueva carpeta/runtime y maxFailures1 para fail-fast con reporterJSON; para aceptar siguen siendo necesarios los15resultados nuevos de cinco escenarios a tresanchos, sin skips/retries/interrupted y conaxe completo.

Los selects se localizan por su rolecombobox/nombre accesible tras reproducción estática; la labelimplícita contiene también el texto de lasoptions para getByLabelexact. Para aislamiento se admite fixtureCSS vacío únicamente de la URLGoogleInter heredada enapp.blade, registrada expresamente: el recorrido probará fallback local, no la fuenteInter remota. Todo otrohost externo se bloquea y falla. Los erroresHTTP inesperados deben seguir fallando; sólo negativos realmente afirmados por el caso se separan como diagnóstico deChromium, nunca console.error deaplicación.

### QA prevista C3 de gráficos, E2E-28

No sustituye T10 pendiente, ni acredita AV o las otras22configuraciones. Cuatro escenarios×tres viewports=12casos, worker1/retries0/failfast1 y JSONreal. Cada tipo debe guardar/reabrir/editar y exportar con datos originales, no basta su nombre en selector. Observadores independientes de XML/matricesSVG/PNG/PDF verifican geometría/signos/null/ceros/proporciones/colores, IDssemánticos, fuente privada y datos; sin importar módulos del renderer como oráculo. Preview y raster deben usar la misma factory real. Cuota12kbarras se ejecuta realmente bajo guard, no se atribuye stress52MB por pruebas escaladas de LRU.

QA privada se prepara sin runtime/servidor/build/modelo/browser y necesita revisión+manifiestoactual+señalroot. Build6 es histórico; build1 histórico registra62fuentes y77assets; la corrección visual de ticks requiere un build nuevo. Un cambio sólo PHP o de observadores no obliga a recompilar JavaScript si esos inputs siguen intactos. Guard por árbol propio3GiBCOMMIT/killOnClose/noBreakaway con capacidad física previa≥6GiB,piso3GiB/100ms ydeadline900s; si falla preflight REFUSED antesUUID/servidor, sin bajar protecciones. PHPCLI/servidor256M para fixturespequeñas, no límites globales de producción. PDFdelinforme sólo tras terminal yguard separado. Todas las figuras y páginas deben inspeccionarse; SHAantes/después/postPDF y cierreJobs/puerto. No simularéxito, saltarcasos o reintentarautomáticamente si falla. Los snapshots/modelos de T10 no se ejecutan en esta pasada.

El diagnóstico del intento2 distingue el rectángulo tipográfico `getBBox()` de la tinta visible. Se conserva BBox para la estructura SVG/matrices y se observa además tinta con TextMetrics.actualBoundingBox por tspan, fuente efectiva lista y CTM. La banda categórica se contrasta con tinta: no se aumenta la tolerancia0,75px ni se omiten IDs, texto, orden, separación o barras. Un desplazamiento de una fila debe seguir fallando; métricas ausentes/no finitas no se sustituyen silenciosamente por BBox. [Contrato y evidencia del bloque focal](t12-chart-ink-http-contract.md).

La exportación JSON canónica debe declarar Content-Length igual a bytes entregados y json_bytes de la revisión, manteniendo SHA, cuerpo y autorización. CSV/TSV conservan su longitud dinámica. Esta mejora evita el comportamiento reproducido de esta build al observar manualmente cuerpos sin longitud; no cambia redirect:error, límites del lector ni la política estricta de red. Las sondas locales son diagnóstico, no casos E2E aprobados. Intentos1/2/3 NO-PASS permanecen inmutables; ciclo2 tiene tres intentos como máximo, directorios/frase propios y requiere fuentes revisadas, manifiesto completo y build actuales.

Las fuentes efectivas del SVG se cargan explícitamente antes de observar tinta, agrupadas por CSSfont y caracteres únicos en bloques acotados, conservando todas las comprobaciones de identidad/estado. La regresión nativa del callback cubre el caso con FontFace previa de igual familia, más fuentes ausentes/corruptas. Se verifica legibilidad de ticks en los SVG bar/column800×500/font18: siete etiquetas compactas esperadas y al menos12px efectivos según CTM; los valores, posiciones y datos originales permanecen iguales. El cambio de chart-scene invalida build1; se requiere una compilación nueva antes del ciclo2.
