# Motor audiovisual interno de T-11

2026-11-05. El motor nativo genera tramos y archivos MP4/GIF reales. **T-11 sigue abierta**: preparación gestionada, checkpoints/jobs, progreso/cancelación/reintento, registro y borrador web/API/MCP, presupuesto total de fase y límites OS/RSS todavía requieren integración y aceptación. La API y el diálogo siguen ofreciendo sus formatos estáticos.

## Resultado verificado

| Comprobación | Resultado | Evidencia |
|---|---|---|
| Frontend completo después de revisión | 122/122, 25 archivos; cero fallos/cancelados/omitidos | [TAP](raw/frontend.tap), [resumen](raw/frontend-result.json) |
| Regresión del editor | 42/42; 14 escenarios en desktop/375/360; cero fallos/flaky/omitidos/interrumpidos/errores del runner y cero reintentos | [JSON real](raw/playwright-editor-regression.json), [gate](raw/qa-gate.json) |
| Integridad | 16 fuentes y dos bundles iguales antes/después | [Fuentes revisadas](raw/reviewed-sources.json), [manifiesto de entrega](manifest.json) |
| Muestra MP4 | H264/AAC, 400×240, 30 frames, 12fps, 2,5s, faststart | [MP4 sintético](samples/sample.mp4), [ffprobe](raw/sample-metrics.json) |
| Muestra GIF | Un GIF89a, 400×240, 30 frames, 2,5s; paletas y delays de centésimas acumulados | [GIF sintético](samples/sample.gif), [ffprobe](raw/sample-metrics.json) |
| Mezcla completa | En0,75–1s: amplitud440Hz0,07459 y880Hz0,03390; después del clip:440Hz<0,000001 y880Hz0,03388 | [Espectro del MP4 final](raw/sample-spectrum.json) |
| Inspección visual | Cuatro frames del resultado y nueve capturas del editor inspeccionados | [Frame0](samples/frame-000.png), [frame9](samples/frame-009.png), [frame15](samples/frame-015.png), [frame24](samples/frame-024.png), `screenshots/` |

Build y TypeScript pasan (`2e24ec`, `0ca969`). Frontend:23954ms, sesión35936 terminal (`280896`). E2E:310472ms/5,2min, sesión74249 terminal (`a2c60c`), raíz `run-general-bbc3e925-c691-43c2-adaa-51b06723df66`. Los42 son la regresión directamente afectada; no se suman a la pasada anterior54como si fueran escenarios nuevos. No se repitió PHP porque este subbloque no cambia producción PHP; la última suite2740/16149aserciones conserva su fecha/alcance en el informe de fotogramas.

Los27 casos nuevos internos cubren plan, modo offline, normalización/mezcla, render/unión y defensas. El sample usa vídeo/tonos propios y la fuente privada de prueba Space Grotesk con su licencia del repositorio. Los cuatro PNG muestran movimiento, overlay, transición y texto tipográfico; no se han editado para corregir el resultado. Se verificaron nueve capturas de audio/timeline/exportación en los tres tamaños; el runner produjo33capturas, sin afirmar que se inspeccionaron las otras24. El lienzo blanco del diseño solo de audio y el vídeo pequeño de64px en1080px son los fixtures originales, no defectos demostrados.

La ejecución integrada corresponde a root después de revisión independiente. **No es QA independiente ni aceptación audiovisual humana**, tampoco prueba del VPS/R2 o de publicaciones reales. GIF cuantiza el reloj a centésimas: el sample tiene30frames/2,5s aunque ffprobe muestre `avg_frame_rate=25/2`; ese campo no se presenta como12/1 exacto.

## Qué hace el motor

Compila un documento v2 validado con ventanas semiabiertas y reloj entero de fotogramas/PCM48000. Los grupos conservan la fase y envolvente original al recortar su ventana efectiva. Las páginas comparten transiciones y fades; las pistas globales continúan. El modo offline no reproduce ni abre audio y retiene vídeo estrictamente dentro del trim. Los originales no se modifican.

La normalización decodifica cada origen utilizado una sola vez a PCM estéreo privado en disco. La mezcla lee offsets exactos, comparte ventanas en una LRU16MiB y aplica velocidad/volumen/ganancia/fades por muestra. Audio corto de vídeo termina en silencio; archivos corruptos y perfiles incompletos fallan explícitamente. No se transportan vídeos/audio como JSON base64 ni se carga una biblioteca completa en RAM.

Chromium accede a snapshots con nombres generados mediante un origen loopback con token y mapa cerrado, Range y backpressure. FFmpeg recibe arrays, formatos/protocolos permitidos y rutas propias. Cada tramo se sondea antes de publicarse; la unión verifica hashes/orden/cantidad/dimensiones y el archivo final. MP4 copia vídeo ya codificado y codifica AAC; GIF conserva LZW comprimido y paletas locales sin concatenar cabeceras completas ni recodificar toda la animación.

## Revisión adversarial

Se leyeron diffs completos con contexto fresco. [Intento1](raw/review-attempt1.diff):15fuentes; [intento2](raw/review-attempt2.diff):16fuentes; [intento3](raw/review-attempt3.diff):solo dos archivos cambian frentea2. Las tablas canónicas y todos los RED/GREEN están en T-11 de `tasks.md`.

| Hallazgo | Evidencia roja | Corrección |
|---|---|---|
| B1: seek comprimido cambia fase o devuelve menos muestras | M4A44,1k rechaza PCM válida; M4A48k RMS0,106408; MP344,1k RMS0,021818 (`045e1b`) | Normalización continua en disco y lecturas por muestra. Comparaciones con decode continuo RMS<0,0001 en tramos7frames y2s; B2 confirma cierre. |
| D1: misma ventana se decodifica64veces | 64pistas→64procesos, ~2416ms (`045e1b`) | Un perfil y una lectura compartida. D2/D3 confirman; mediana local64pistas190ms, no benchmark de producción. |
| B2: padding oculta M4A truncado | Contenedor6s truncado55%, FFmpeg devuelve0 con errores; normalización lo acepta (`3ea0cd`) | Decode estricto `-xerror`/`-err_detect explode`; rechazo y cero PCM/parciales. B3 confirma y conserva soundtrack corto válido. |

A3/B3/C3/D3: cero Critical/Important/Minor pendientes del motor. A/B ejecutan10tests de audio y1soundtrack; C3 tres defensas focales; D3 una ventana compartida. No se les atribuye la ejecución integrada posterior. La medida D2 empezó tras una detección de deriva: se capturaron hashes vivos antes/después, coincidentes con el manifiesto3; no se atribuye al manifiesto2. La coordinación queda registrada en el ledger.

La sonda inicial de `-ss0` acreditó una fixture AAC48k y no todos los códecs; la corrección general posterior la sustituye por normalización continua. Los errores `spawn EPERM` no cuentan como RED funcional. Los tests añadidos para caracterizar defensas ya presentes se declaran como tales. Model-tier no pudo localizar `agents/` de la instalación parcial; se usó reviewer heredado, sin override. Sin Jira configurado ni promoción automática del candidato AAC.

## Siguiente integración obligatoria

1. Semántica de selección/orden de páginas, pistas globales, v1 y escala/calidad del export sin mutar documentos.
2. Preparación privada por marca y snapshots/checkpoints, fases de normalización por recurso/render/mezcla por tramo/unión, recuperación e idempotencia.
3. Presupuesto total de fase y recursos de todos los descendientes, progreso, cancelación, reintento y limpieza de intentos.
4. Formatos y seguimiento públicos web/API/MCP, registro privado y borrador con validación de red; revisión y QA del flujo completo antes de cerrar T-11.

Los límites y pendientes exactos están en `t11-contract.md`. T-10 sigue sin autorización de su prueba pesada: no se ejecutó ONNX, `test_background.py` ni E2Eimaging. No se modificaron conexiones reales, `.env`, MySQL, cron/workers del VPS ni permisos externos. No se realizaron commits/push deT10/T11 durante este bloque.
