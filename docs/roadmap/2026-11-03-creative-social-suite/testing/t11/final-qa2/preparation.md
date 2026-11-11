# QA independiente T11 — preparación del intento 2

2026-11-05. **READY para coordinación; ejecución bloqueada hasta la señal interna exacta `revisión aprobada; ejecuta intento2`.** No se han ejecutado tests, workers, renderizadores nativos ni build durante esta preparación. QA1 permanece inmutable y conserva NO-VERDE62/63.

## Contexto y entradas

La corrección SQLite tiene revisión A1/B1 sin gaps y un recorrido focal mobile-360 correcto, descritos en [sqlite-concurrency/review.md](../sqlite-concurrency/review.md). Son resultados previos del orquestador, no una QA2 ejecutada ni una modificación del resultado original. La reproducción mostró HY000/5 con escritores reales; no se atribuye retrospectivamente una excepción no capturada a la primera ejecución.

Contrato nuevo: [t11-captions-contract.md](../../../t11-captions-contract.md). E2E23–25 del [test-plan](../../../test-plan.md) siguen en implementación. ASR y traducción corresponden a T14; importar texto no los acredita. No se amplía esta QA a T10/ONNX, MySQL, VPS, R2 real o las23tareas del roadmap.

Helper nuevo: `storage/framework/testing/t11-independent-qa2.py`. El modo `prepare` solo inventaría rutas; el modo `run` exige la señal exacta como argumento, todos los specs obligatorios y puerto8080 libre. Nunca omite silenciosamente captions, reutiliza un servidor, mata Node ajeno ni repite una ejecución que ya tenga registro.

Se mantienen los11archivos de QA1 y se añade `studio-captions.spec.ts`, que apareció durante esta preparación. El conteo final se toma del JSON real; no se presupone72. Tres proyectos Chromium: desktop1440×900, mobile375×812, mobile-360360×740. Runtime aislado existente, rootUUIDt43 autorizado, SQLite/storage/usuarios propios. El slot nativo actual pertenece al implementador de captions.

## Puertas al recibir la señal

1. Releer contrato, spec de captions, tabla E2E y manifiesto final de revisión; comprobar que los requisitos y outputs pendientes se han resuelto. Ejecutar guardrail oficial de localhost y las puertas ledger-lint/coverage-check. No dar aceptación global a otras tareas abiertas ni al T10 no ejecutado.
2. Verificar que el implementador liberó el slot y que8080 está libre. Registrar snapshot SHA256 real anterior a ejecución. No emplear el inventario de preparación como baseline congelada.
3. Ejecutar una sola pasada de los12archivos en los tres proyectos; comunicar sesión/PID/root al arrancar. Mantener el mismo proceso ante timeout de observación. Recoger JSON completo, stdout/stderr, trazas y capturas; un rojo no se corrige dentro de QA.
4. Snapshot posterior, comparación completa y qa-gate sobre JSON original. Reportar aparte skipped/interrupted/retries/errores runner; no justificar un fallo como fluctuación sin causa probada ni alterar el umbral.
5. Recálculo audiovisual sobre archivos descargados, sin otra exportación ni worker implícito. Inspección de capturas de los tres viewports y medios reales. InformeMD/PDF con adaptador to-pdf existente, rasterización PyMuPDF y revisión visual; manifiesto de fuentes/artefactos.

## Cobertura del snapshot

Se incluyen app, UI/documento/servicios Studio, rutas/config/migraciones, scripts de render/fases, frontend unit/browser y sus entradas, Feature/Studio, runner/soporte E2E y sus `.mjs`/`.d.mts`, todos los artefactos de public/build, contrato de captions, matriz, criterios/ledger/test-plan y fuentes privadas de fixtures. Extensiones tipográficas WOFF2/WOFF/TTF/OTF y SOURCE.json/OFL.txt quedan cubiertas. Static/frame/vector/font adapters y renderers JS están dentro de esos árboles, junto con los bundles compilados.

El snapshot amplio también observa `StudioChartData.php` y su test nuevo de T12. Si cambian durante la futura ejecución se registrará deriva, sin excluirlos automáticamente. El orquestador debe congelar esas dos fuentes al arrancar QA2 o comunicar previamente una delimitación explícita y justificada si permanecen desconectadas de T11.

## Medición de captions independiente de los asserts de implementación

| Aspecto | Observación propia sobre el resultado real | Evidencia requerida |
|---|---|---|
| Ventana semiabierta | Decodificar MP4/GIF; medir ROI y máscara de glifos antes, en inicio, dentro, en fin exacto y después. Elegir bordes representables en FPS; almacenar índices y timestamps reales. | Archivo descargado, ffprobe, frames PNG, conteos/luminancia/bbox/diferencias. |
| Opacidad durante transición | Comparar máscara y luminancia de la misma cue antes/durante/después del fade sobre fondo conocido. Distinguir texto atenuado y duplicado; no usar únicamente cantidad de píxeles>200 como prueba de composición única. | Frames y diferencias de ROI; fixture de fondo/cue discriminante y regla numérica fijada antes de ejecutar. |
| Fase de origen/selección | Exportar A sola conserva cue1.8–1.95 pese al fade original; selección no adyacente/reordenada remapea ventanas en puntos anteriores/posteriores al midpoint, sin adelantar/reiniciar la pista ni alterar documento. | Outputs de esas selecciones, documentos/revisiones originales, tiempos fuente/salida y muestras con identidades de cue distinguibles. |
| Fuente privada | Extraer SVG del ZIP y parsear texto/CSS/datafont; calcular SHA de WOFF2 embebido y contrastar con el recurso privado validado/snapshot, no solo family o DTO. Rastrear uso en las salidas raster. | Fuente normalizada esperada, SVG completo, metadatos del recurso, raster con glifos discriminantes; comparar referencia tipográfica independiente cuando haga falta. |
| Salida estática | Inspeccionar SVG vectorial/portable y PNG/PDF reales; medir presencia/ausencia según tiempo global equivalente, escala y estilo. Una firma PDF o tamañoZIP no prueba texto visible/fuente. | SVG extraído, PNG/PDF descargados y rasterización real, tiempos/revisión de cada página. |
| Revisión y aislamiento | Los outputs conservan cue/fuente de la revisión encolada tras editar/limpiar el actual. Separar lector/escritor, otra marca/usuario, API/MCP y conflicto; conservar códigos/respuestas sin secretos reales. | Resultados reales de endpoints/DTO, revisiones antes/después y archivos de la revisión fijada. |

El recálculo de captions no importará `captions.js`, `captions-renderer.js` ni sus asserts como único oráculo. FFmpeg/ffprobe, análisis de píxeles, XML/ZIP y SHA actuarán sobre los bytes descargados. Los tests unit/browser previos y sus DTO no sustituyen esa evidencia. La aceptación visual humana y los límites OS permanecen expresos.

Se ha preparado `inspect-caption-media.py` sin ejecutarlo. Acepta un perfil definitivo con archivos/índices y SHA esperados; observa múltiples umbrales, luminancia, bbox, máscaras/diferencias entre frames y fuentes/textos de SVG dentro del ZIP. Guarda PNG decodificados y JSON de observaciones. No declara aceptación por nombre de archivo. La opacidad observable se puede contrastar en píxeles; no se promete deducir el número interno de llamadas de dibujo cuando dos composiciones son matemáticamente indistinguibles. PNG/PDF y sus muestras de selección se incorporarán al perfil cuando los outputs finales estén definidos.

## Huecos de entrada observados ahora — no son verdictos finales

- El spec de captions ya existe con tres casos declarados, pero está siendo implementado y no se ha ejecutado aquí.
- Su caso render actual descarga MP4/GIF y un ZIP de SVG; la ramaSVG solo exige tamaño>1000. Falta revisar su contenido real, fuente embebida y rasterización portable.
- En el E2E observado todavía no aparecen descargas PNG/PDF ni recorridos de selecciónA-sola/noadyacente/reordenada requeridos por E2E24. Existen tests frontend de selección y browser estático, pero no suplen una prueba pública final pendiente.
- Faltan el catálogo/artifact-schema definitivos, documento/ventanas discriminantes y recibo de fuente normalizada esperado para preparar umbrales independientes precisos. Se fijarán tras el freeze final; no se inventan fixtures o resultados para declarar verde.
- Se necesita la señal de revisión aprobada y liberación del slot nativo. El orquestador ha confirmado que congelará los dos archivosT12 y ledger/docs durante QA2; no se excluirán del snapshot por cuenta de QA.

## Restricciones y salida

No tocar app/tests/ledger/spec/plan ni cerrar estados. Windows aplica COMMIT agregado3GiB, noRSS; Linuxcgroup con Python no demuestra multimediaLinux/VPS. R2 simulado no es bucketreal. VPS y smokes Bluesky/Threads siguen a cargo del usuario; ClaudeDesktop aplazado y ClaudeCLI final después del roadmap. Sin ONNX/test_background/imaging, delegación, knowledge ni mensajes externos.

Salida futura: `final-qa2/report.md`, `report.pdf`, `manifest.json`, `raw/`, `screenshots/`, `samples/`. El veredicto QA2 será independiente y se entregará al orquestador; no sobrescribirá QA1 ni cerrará la iniciativa completa.
