# T-11: audio en el editor y preview sincronizado

2026-11-05. Implementación local sin commit sobre `94e17d7`, rama `feature/creative-social-suite`. Subbloque de audio en interfaz verificado: **51/51 E2E, gateVERDE** y tres capturas inspeccionadas. T-11 sigue en progreso y el total del roadmap permanece en7/23; la exportación audiovisual y su aceptación completa continúan pendientes.

## Comportamiento implementado

Recursos admite subir audio por el ticket privado de la marca, mediante PUT binario local o single/multipart prefirmado. Las peticiones externas no reciben CSRF, Bearer ni cookies de sesión. Si la transferencia acabó y el sondeador responde503, el botón de revalidación conserva el ticket para repetir complete sin subir otra vez los bytes. El estado listo procede del sondeo nativo del backend compartido, no de la extensión del nombre.

Añadir un audio crea una pista global y activa documento v2 de forma explícita. El panel permite inicio/duración, recorte inicial/final, velocidad, volumen, mute, loop y fundidos. División conserva origen y envolvente; eliminar/deshacer/rehacer y guardar/reabrir usan el historial y persistencia existentes. La biblioteca identifica los recursos de audio sin tratarlos como imágenes ni permitirlos como adjuntos sociales aislados.

El preview usa HTMLAudio streaming y nodos Web Audio de ganancia para las pistas que pueden sonar. El reloj es común al vídeo y a todas las páginas; el seek y la reproducción no alteran el documento. Se liberan los medios al salir de su intervalo y al cerrar; las pistas muted o agotadas conservan estado sin abrir decodificadores. No se usa decodeAudioData para cargar archivos completos de hasta una hora en buffers JS.

## Evidencia

| Comprobación | Resultado y alcance |
|---|---|
| Integración final |51/51,0failed/flaky/skipped/interrupted/erroresrunner y0reintentos en tres viewports; [JSON](raw/playwright-integrated.json), [gateVERDE](raw/qa-gate.json) |
| Integridad y capturas |10fuentes y13artefactos con SHA256; [manifiesto](manifest.json); capturas [desktop](screenshots/audio-desktop.png), [375px](screenshots/audio-mobile.png), [360px](screenshots/audio-mobile-360.png) inspeccionadas |
| Frontend Studio |17archivos,71/71,0fallos/cancelados/skips; [TAP real](raw/frontend.tap), toolc38d65 |
| Preview Chromium |7/7; tonos propios440/880Hz, FFT/RMS por canal y silencio tras pause; trim/rate/loop/envolvente,20muestras con vídeo real dentro de1frame; documento intacto |
| Editor desktop ampliado |2/2, controles/división/undo/guardado/reapertura y duración1,2s/FPS24; tool ecfb64, JSON `run-general-fe7f9191-097c-49fc-aa31-f610d31245ed` |
| Regresión de fixture |metrics-quality/all-brands desktop2/2, tool3b68c1, JSON `run-general-8c696189-3b3d-404b-8f5a-ec7571413e2c` |
| Backend conservado |25SHA idénticas al [manifiesto de recursos](../audio/manifest.json); PHP2714/2714,16100aserciones es evidencia anterior, no una nueva ejecución de este subbloque |
| Tipos/build |TypeScript correcto; Vite/renderer recompilados, tool0efbb2; advertencia previa de tamaño de chunk sigue visible |

Las pruebas de frecuencia y energía miden audio real del navegador. No prueban la mezcla de una exportación MP4, ni sustituyen la aceptación audiovisual humana. El test de recurso del bloque anterior usa un HTMLAudio instrumental; el nuevo E2E sí usa los controles del editor.

## Revisión adversarial

Delta inicial9fuentes, baseline de las tres piezas compartidas reconstruida y comprobada contra sus SHA de revisión anterior. A/B/C/D leen el diff completo; scope sin archivos fuera ni avisos. Intento1: A/C sin gaps y cuatro Important reproducidos por root antes de corregirlos.

| Gap | Evidencia roja | Corrección y re-revisión |
|---|---|---|
| B1 cierre espera la carga que debería cancelar |1fcbf2:tras250ms dispose pendiente y src retenido |Aborta audio antes de esperar tail; B2 confirma liberación inmediata |
| B2 edición usa duración redondeada de frames |3db6f6:página1,2s/FPS24 no admite la pista1,208333s |Límite raw en edición/panel; B2 ejecuta crear y mover conservando final1,2s |
| B3 trim corto omitido a velocidad lenta |c6a15a:trim0,039/rate0,25 conserva fuente0 |Seek pausado preciso; tolerancia durante avance en reloj de salida; B2 confirma regresión |
| D1 muted64pistas producen1280seeks/s |1fcbf2:64decoders frente0 |Estados muted/EOF sin node; D2 mide0decoders/0seeks/0buffering en64×20preparaciones,1,7/0,8ms |

A2/B2/D2 sin gaps; C1 preservada porque las correcciones no cambian transferencia, cabeceras, permisos ni origen de recursos. La primera integración48casos terminó45passed/3failed,0flaky/skipped: fallaban las tres instancias de métricas por un fixture que confundía ayerUTC con día cerrado del Pacífico. No se rebajaron las assertions ni se cambió la cobertura del producto. El helper ahora usa una sola ancla PHP UTC−2 para snapshots y un rango de diez días ya cerrados, conservando el último día sin datos. A3 comprueba72anclas horarias/transiciones DST; el otro consumidor, Todas las marcas, se incorpora a la pasada final51. El suplemento también cambia la etiqueta de subida y desplaza arriba antes de capturar, para que la cabecera sticky no tape controles en el artefacto.

Sin Jira ni promoción de conocimiento. Los revisores son independientes; la ejecución E2E integrada corresponde al orquestador y no se presenta como QA independiente.

La primera pasada final51 quedó sin proceso ni results.json entre turnos; no se le atribuye éxito. Antes de repetir se comprobó en host ausencia de procesos QA, PHP y listener8080. La ejecución sustituta terminó51/51 en5,2min, sesión41924/tool320847. Las capturas nuevas se hicieron desde el principio de la página: cabecera en su posición, etiqueta «Subir recursos», campos y pistas accesibles en los tres tamaños, sin desbordamiento horizontal. Los píxeles del lienzo son blancos porque el fixture es un diseño solo de audio; no representan un fallo de vídeo ni acreditan render audiovisual.

## Pendiente

Continuar con fotograma temporal en exportaciones estáticas y render MP4/GIF por tramos, sus medidas de frames/duración/espectro, cancelación y recuperación. Mantener todos los demás bloques del roadmap. T-10 sigue sin aceptación de memoria ONNX; no se ejecutaron inferencia ni E2E de quitar fondo. No se modificaron conexiones, credenciales, `.env`, cron, workers ni VPS durante este subbloque.
