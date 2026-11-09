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
| E2E-20 | `studio-native-export.spec.ts`, MP4/GIF | Ordenar páginas, cancelar/reintentar mismoID tras recargar, workerreal, descarga de medios medidos yborradordemarca. |
| E2E-21 | `studio-av-composite.spec.ts` | Vídeomóvil/tone440 yWAVconmarcadores1760 fuera deltrim880; overlays/fade,42frames/3,5s, niveles/rampas/recorte/loop medidos enamboscanales, GIFescala0,5 yborradorconmedioexacto. |
| E2E-22 | `studio-native-export.spec.ts`, historial | 55exportaciones reales,50porpágina,una consulta porpágina, actualización yreapertura sincreartrabajos. |

Las capturas se revisan visualmente. `qa-gate.py` consume el JSON real de Playwright y debe dar VERDE; no se fabrica un resumen con menos casos que la ejecución. Las pruebas focales durante desarrollo no sustituyen el resultado final tras las correcciones de revisión.

La QA independiente final local de T-11 selecciona `studio.spec.ts`, `studio-automation.spec.ts`, `studio-timeline.spec.ts`, `studio-audio-editor.spec.ts`, `studio-audio-resources.spec.ts`, `studio-frame-export.spec.ts`, `studio-native-export.spec.ts`, `studio-av-composite.spec.ts`, `metrics-quality.spec.ts`, `brand-reporting.spec.ts` y `all-brands.spec.ts` en los tres viewports (previsión63casos; el JSON real determina el conteo). Excluye explícitamente `studio-imaging.spec.ts`: T-10 mantiene pendiente la autorización de memoria y esa exclusión impide dar por aceptado T-10. La pasada cubre los E2E de T-11 y regresión relacionada; no cierra las capacidades no implementadas de las otras tareas ni los manuales externos. La nueva aceptación compuesta requiere revisar sus oráculos y medir rampas/recorte yamboscanales antes de afirmar equivalencia audiovisual.

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
