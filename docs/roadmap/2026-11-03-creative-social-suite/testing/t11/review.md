# T-11: núcleo temporal y primera interfaz

2026-11-04. Trabajo sin commit sobre `94e17d7`. T-11 permanece en progreso; siete de veintitrés tareas globales completadas. Este informe no acredita audio completo, exportación MP4/GIF ni QA final.

## Núcleo, revisión 2

A/B/C/D revisaron once archivos congelados, con comparación exacta de las cinco piezas compartidas contra T-10. B1 (desagrupar), B2 (capacidad al dividir) y B3 (huecos en gainPoints) están corregidos. B reproduce además continuidad de origen/ganancia con360muestras; C no encuentra hallazgos nuevos y D confirma operaciones acotadas bajo2MiB. A no pudo leer los tres chunks originales GREEN del intento2: esa limitación se mantuvo explícita, sin atribuirlos a XML/TAP anteriores.

La verificación posterior conserva las salidas: [PHP20/20,33aserciones](raw/phpunit-core-reviewed.xml), [frontend76/76](raw/frontend-ui-development.tap). A de la interfaz leyó esos dos artefactos y reejecutó las13pruebas puras con resultado correcto. El TAP incorpora los cuatro casos Canvas del núcleo y un quinto de preview. Solo se marcan completados los tres subchecks del núcleo; el criterio padre y la tarea siguen abiertos.

## Interfaz y preview, revisiones 1–3

Cinco archivos congelados en `storage/framework/testing/t11-ui-review-manifest.json`. La baseline de Editor se reconstruyó desde HEAD y el diff de T-10; su SHA coincide con el manifiesto T-10. Scope98/98, cero fuera/avisos/exclusiones. Selector C=false/D=true por awaits en bucles; sin Jira habilitado.

La interfaz permite activar v2, ajustar ventanas, dividir, seleccionar clips, deshacer/rehacer, guardar, buscar un instante y reproducir con reloj común. El preview usa un lienzo separado y desechable, conserva ventanas de grupos, fase/rate/loop de vídeo y composición de páginas. No persiste estados transitorios de reproducción.

Evidencia de desarrollo:

- RED de interfaz ausente `701b13`; preview ausente `c757c6` → preview real1/1 `c953c3`.
- Página2 comparaba tiempo global con local, RED `3c8f4e` → seis E2E correctos en escritorio/375/360 (`6ad375`), raíz `storage/framework/testing/t43/run-general-e20d38f2-7493-4598-857f-961d0807f013`.
- Vídeo real64²/3s subido y sondeado: cambio de modo cancelaba inserción (`0cb711`), corregido con busy; división sin duración de origen dejaba un clip (`fc43ab`), corregida usando el dato sondeado de su recurso. GREEN1/1 desktop (`efed91`), raíz `run-general-acdd542a-7fcf-411a-b0f7-719787be99dc`.
- Build, TypeScript y diff-check correctos. Suite PHP completa posterior **2675/2675,15833aserciones**, cero fallos/errores/omitidos, raíz única `storage/framework/testing/t11-full-8aa8ec287c3b4ece8640e2dbbd4dafda`. [JUnit](raw/phpunit-full-core-ui.xml). No hubo cambios PHP en las posteriores correcciones de preview.

A1 no encontró gaps de conformidad. B1 confirmó que900ms de preparación consumían un proyecto de0,5s; D1 confirmó que ocho vídeos futuros con latencia local40ms bloqueaban el primer frame387,7ms. RED permanentes:8decodificadores frente0 (`242666`) y reloj0,5s frente<0,4s (`76e2da`). La escena ahora materializa solo ventanas efectivas; libera los decodificadores fuera de ellas, prepara el primer frame antes del reloj y descuenta buffering de cambios posteriores. Chromium6/6 y [frontend completo77/77](raw/frontend-ui-fixed.tap).

A2 y D2 aprobaron el delta. La sonda D pasó a3,30ms, cero vídeos/peticiones/seeks antes de la ventana; ocho durante y todos liberados después. B2 confirmó inicio, entrada de página y cambio de conjunto sin saltos tras900ms de buffering, pero encontró una carrera nueva: cerrar durante preparación reactivaba la preview al resolver el await. RED E2E `9fcad8`; ahora la generación se captura antes de preparar y se comprueba al terminar. GREEN focal cancelación1/1 (`0ebb7b`).

A3/B3 aprueban exclusivamente la corrección de cancelación y conservan los criterios anteriores. B3 repite cuatro sondas de vídeo reales con900ms: cancelación deja panel cerrado/preview oculta/play detenido en0s; inicio recorre32frames/0,5s, página47frames/0,7s y cambio de conjunto53frames/0,8s. Máximo avance18,5ms. **Cero gaps pendientes de corrección/rendimiento del subbloque**. D2 se conserva porque el tercer delta solo añade una guarda de generación y pruebas.

Las seis pruebas iniciales y los focales no se presentan como QA final. El GREEN de cancelación fue anterior a añadir hooks generales de errores de consola/pageerror; esa vigilancia requiere la pasada integrada actual. No se atribuyen los hooks a una ejecución anterior.

## Puertas abiertas

Pasada integrada terminada: **39/39**, cero fallos/flaky/skipped/interrupted y cero errores del runner,251316ms, sin reintentos. `studio.spec.ts`, `studio-automation.spec.ts`, `studio-timeline.spec.ts`, `metrics-quality.spec.ts` y `brand-reporting.spec.ts`, tres viewports. [JSON real](raw/playwright-ui-integrated.json), [gateVERDE](raw/qa-gate.json) y [manifiesto](manifest.json). Las cincoSHA coinciden antes/después. Ejecutada por el orquestador después de la revisión independiente; no se denomina QA independiente ni cierre del flujo audiovisual completo.

Capturas de vídeo en [escritorio](screenshots/timeline-video-desktop.png), [375px](screenshots/timeline-video-mobile.png) y [360px](screenshots/timeline-video-mobile-360.png) inspeccionadas: panel/controles/capas accesibles, sin desbordamiento horizontal. El MP4 de64² aparece pequeño porque conserva su tamaño nativo dentro del diseño1080²; no se presenta como prueba de escalado/HD. Los39casos comprueban overflow; los cinco de timeline comprueban errores de consola/pageerror con los hooks actuales.

Después, audio validado por marca, pistas/mezcla/envolventes, controles avanzados, integración del fotograma temporal en exportaciones estáticas, render audiovisual por tramos y medidas reales de frames/audio. La operación externa del VPS sigue a cargo del usuario.

T-10 conserva su autorización de memoria pendiente. No se ejecutó ONNX ni el E2E de quitar fondo durante este bloque.

## Punto de entrada del siguiente subbloque

**Actualización 2026-11-05:** la inspección siguiente es histórica. Los recursos de audio ya admiten transferencia binaria, registro privado por marca, validación real, API/MCP y recuperación single/multipart; véase el [informe actual de recursos](audio/review.md), PHP2714/2714 y tres E2E del recurso. El siguiente subbloque implementa biblioteca/pistas y reproducción mezclada en la interfaz; MP4/GIF y aceptación audiovisual completa permanecen abiertos. Las cinco fuentes de la UI visual anterior conservan sus hashes como evidencia histórica, no como un manifiesto de las modificaciones de audio posteriores.

La inspección del código confirma que el audio de las pruebas PHP sigue siendo una fila ready creada directamente: no acredita subida ni sondeo. `StudioAssets::attach` solo admite imagen/vídeo; `MediaUploadService::kindFor` tampoco admite audio y `MediaProbe::inspect` trataría un audio como imagen. `MediaController::index` lista toda la media ready; al ampliar tipos habrá que conservar un selector social compatible. `MediaValidator` ya rechaza kinds fuera de los permitidos por cada tipo de publicación; esa defensa necesita regresión con audio real.

El siguiente trabajo es ampliar el contrato OpenAPI antes de los endpoints; reutilizar subidas por metadatos y transferencia binaria/prefirmada local/R2, con entrada específica autorizada por marca, sin JSON base64 masivo. Mantener las abilities designs:read/write y el mismo servicio para UI/API/MCP; sondear contenido propio local con demuxers/protocolos cerrados y límites; registrar audio ready únicamente tras la validación real. Después conectar pistas, trim/rate/loop/envolventes y mezcla al reloj del preview, y completar render por tramos. Estas notas identifican trabajo pendiente; no describen una implementación existente.
