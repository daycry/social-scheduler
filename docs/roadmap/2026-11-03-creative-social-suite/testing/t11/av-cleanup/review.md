# T-11: limpieza privada y recuperación de checkpoints

2026-11-05. Bloque interno revisado y comprobado. T-11 sigue abierta y el roadmap conserva7/23 tareas completas. No están conectados aún los jobs, el scheduler de recuperación, los controles públicos o el registro final de MP4/GIF. No se ejecutó la auditoría final con Claude CLI.

La limpieza por lease conserva la reserva hasta retirar sus archivos. Solo admite asignaciones vencidas, fallidas, abandonadas o invalidadas; no borra checkpoints aceptados como basura. La retirada completa del workspace exige exportación inexistente y TTL agotado. Un fallo de I/O o un enlace deja el journal y la contabilidad disponibles para repetir la limpieza con seguridad.

Un archivo de bloqueo por workspace vive fuera del árbol eliminable. El bloqueo no espera ni caduca mientras el proceso existe; lo comparten copias, promociones, lecturas y limpieza. Dos procesos PHP comprobaron su exclusión real. Los recorridos de borrado no mantienen transacciones de servicio en BD y están acotados a30s,65536entradas yprofundidad32.

Cuando la verificación detecta corrupción, se invalida el origen y se repone el paso sin avanzar el cursor. Ninguna generación puede reclamar su sustituto antes de terminar la retirada física. El hash del origen permanece fijado después de liberar sus bytes: si cambió también la biblioteca, la copia falla y exige una exportación nueva. Así no se combinan segmentos anteriores con un nuevo contenido del mismo ID.

Los controles concat llevan lease+UUID en la raíz privada para poder atribuir residuos después de una interrupción. Conservan rutas relativas y `-safe 1`. La prueba nativa observa controles realmente escritos durante una unión pequeña; la prueba PHP usa residuos de interrupción como fixture. Estas dos pruebas no ejecutan un kill real.

## Revisión y regresión

| Comprobación | Resultado | Evidencia |
|---|---|---|
| Lentes A1/B1/D1 | Sin gaps;11SHA iguales antes/después |[Diff](raw/review-reviewed.diff), [selector](raw/review-lenses.json), [alcance](raw/review-scope.json) |
| Focal de las lentes A/B |43/43PHP,242aserciones y1/1nativo; raíces aisladas |Ledger de la iniciativa; D ejecutó4focales/18aserciones |
| PHP posterior: nueve archivos Studio |164/164,823aserciones;0errores/fallos/skips |[JUnit](raw/phpunit.xml), [salida](raw/php.stdout) |
| Frontend primera pasada |149/150; fallo de navegación Chromium antes de encoder |[Salida completa conservada](raw/frontend.stdout), [resultado](raw/frontend-result.json) |
| Caso mínimo del fallo |1/1 sin cambios de fuentes |[Salida](raw/legacy-probe.stdout), [extracción corregida](raw/legacy-probe-summary.json) |
| Frontend segunda pasada completa |150/150;0fallos/cancelados/skips; sinretries porcaso |[Salida](raw/frontend-attempt2.stdout), [resultado](raw/frontend-attempt2-result.json) |
| Integridad de la entrega |20fuentes y22artefactos con hashes |[Manifiesto](manifest.json), [resumen](summary.json) |

Revisiones sobre11fuentes/diff800líneas, baseline6fuentes anteriores. C=false; D=true por migración yheurística await-en-bucle. D confirmó que ese await ya existía antes. La consulta real del pin se explica en SQLite y usa clavesworkspace/fase/índice; no se atribuyen latencias aMySQL. Pint ygit diff --check correctos; ledger-lint0incoherencias y16avisos previos deChangelog en tareas abiertas.

PHP terminó en PID24448 (`f24f3b`),9413ms. Frontend primera pasada: sesión10384/PID26952 terminal,46865,6707ms (`0da3f5`). Segunda: sesión10813/PID31268 terminal,38087,0049ms (`c17339`). No repetir estas suites sin cambios o fallos nuevos. No se repitieron los42E2E UI anteriores: no se cambiaron componentes/rutas públicas y este bloque sigue interno. Sus informes históricos conservan su alcance; no equivalen a E2E de exportación nativa desde la aplicación.

## Incidencia intermitente conservada

El primer fallo fue `page.goto: net::ERR_NO_BUFFER_SPACE` en `native legacy export holds an exhausted poster...`, sobre loopback y antes de codificar. Av-video, av-server yese test no cambiaron. Se aplicó debug-root-cause a demanda: el caso mínimo pasó sin editar fuentes y la suite completa posterior también pasó. No se demostró una causa concreta ni se aplicó un parche especulativo de aplicación.

Después del fallo se midieron25Listen/48Bound/457TimeWait/45Established/6CloseWait, rangoTCP dinámico49152–65535 y12139856KiB de RAM libre. Las cifras son posteriores y no prueban el estado del host durante el fallo. La [documentación de Winsock](https://learn.microsoft.com/en-us/windows/win32/debug/system-error-codes--9000-11999-) describe falta de buffers/cola llena para WSAENOBUFS; no se ha demostrado aquí su correspondencia causal exacta con esta incidencia de Chromium. No se modificó el registro o la red ni se cerraron procesos ajenos.

El helper inicial de la sonda leyó por error el resumen de la primera suite en vez de su propio stdout. Se conservan [ese JSON original](raw/legacy-probe-result.json) yambos raw; `legacy-probe-summary.json` corrige solo la extracción y acredita1/1. No se considera completada una Fase4 con causa/fix probados, ni se promueve una lección de causa raíz.

## Pendiente

QA ejecutada por root, no QA final independiente. SQLite no acredita concurrencia MySQL multiproceso. La migración con el índice solo se aplicó a bases desechables; no se tocó MySQL local, VPS, OAuth ni publicaciones reales. No se ejecutó ONNX ni imaging.

Sigue conectar la cola y su recuperación, envolver cada fase nativa con el bloqueo de workspace, retirar manifiestos de trabajo tras completar cada entrega, gestionar progreso/cancel/retry web/API/MCP, registrar un único medio ycrear borrador. La aceptación pública exige flujo completo con medidas audiovisuales yQA independiente; este informe no la declara.
