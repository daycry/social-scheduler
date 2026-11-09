# T-11: concurrencia SQLite del motor audiovisual

2026-11-05. Estado: **A1/B1 sin gaps y regresión pública mobile-360 correcta; T-11 abierta, 7/23 tareas cerradas**. Este informe no cambia el resultado NO-VERDE de la [QA independiente original](../final-qa/report.md).

## Problema y corrección

La aceptación compuesta falló en mobile-360 durante el registro de un MP4. Una reproducción controlada con escritores reales capturó SQLite HY000/5 al promover una transacción de lectura a escritura después de copiar los bytes. El parcial conservaba tamaño y SHA del archivo ensamblado. Otro recorrido falló tras aceptar plan; la regresión de claim reprodujo la misma familia de carrera. Las excepciones originales de esos dos recorridos no se capturaron y no se inventan retrospectivamente.

El cambio adquiere la reserva de escritura de SQLite antes de leer el estado cercado en los trece métodos mutantes probados. Usa una actualización de PK a sí misma, sin cambiar timestamps ni contenido. Las lecturas puras no escriben. El camino MySQL conserva su política anterior.

Transfer pasa el deadline a checkpoint. El checkpoint limita las esperas del escritor y COMMIT al presupuesto restante, restaura busy_timeout y usa las APIs públicas de transacción de Laravel para poder hacer rollback si COMMIT falla. Un commit externo continúa siendo responsabilidad del caller. No se modifica vendor ni configuración global y no se repite la copia de archivos para reintentar una transacción. Runner coincide exactamente con su baseline, sin instrumentación de diagnóstico.

## Evidencia de implementación

Las regresiones usan dos PDO y escritores o lectores reales en bases SQLite desechables; no inyectan excepciones. Se conserva el orden RED antes de cada cambio. [Historial de diagnóstico](raw/findings.md), [diff limitado](raw/fix.diff), [SHA de fuentes y artefactos](manifest.json).

| Comprobación | Resultado y evidencia |
|---|---|
| Checkpoint con escritor concurrente | [RED HY000/5](raw/red2-output.txt), [GREEN inicial 1/1, nueve aserciones](raw/green2-output.txt). |
| Claim/handoff después de plan | [RED](raw/claim-red-output.txt), [GREEN 1/1, nueve aserciones](raw/claim-green-output.txt). |
| Begin, defer, accept, cancel, retry, fail y failPosition | [RED de siete métodos; claim ya pasaba](raw/mutations-red-output.txt), [GREEN 8/8,66 aserciones](raw/mutations-green-output.txt). |
| Allocate/bind | [RED dos métodos](raw/registration-mutations-red-output.txt), [GREEN 5/5,64 aserciones con checkpoints vecinos](raw/registration-mutations-green-output.txt). |
| Initialize/rejectCheckpoint | [RED initialize](raw/initialize-red-output.txt), [RED reject](raw/reject-red-output.txt), [GREEN conjunto 10/10,83 aserciones](raw/mutators-green-all-output.txt). |
| Deadline de writer y COMMIT bloqueado por lector | [RED espera excedida](raw/budget-red2-output.txt), [RED COMMIT](raw/commit-red-output.txt), [GREEN 3/3,41 aserciones](raw/commit-green-output.txt). Rollback, timeout restaurado, journal y lease intactos, PDO y tracking sin transacción abierta. |
| Transacción externa y afterCommit | [Caracterización correcta 1/1,13 aserciones](raw/nesting-output.txt); el conjunto final añade lectura sin UPDATE. |
| Vecinos finales, cinco archivos PHP | **92 correctos de 96**,567 aserciones, cuatro nativos opt-in omitidos, cero fallos/errores,81.049 s. [Salida](raw/final-neighbors-output.txt), [XML](raw/final-neighbors.xml). |

Los conjuntos focales se solapan; no se suman como una suite nueva. Los cuatro casos omitidos no se presentan como ejecutados. La copia histórica `findings.md` conserva la evolución del parche: su sección final describe el estado congelado.

## Puertas pendientes

Scope-check sobre HEAD: cero archivos fuera de alcance, avisos y exclusiones de usuario. Selector de lentes sobre las cinco fuentes: A+B obligatorias; C=false y D=false. Model-tier no disponible por instalación parcial del plugin; revisores con modelo heredado. Revisiones frescas A1 y B1 terminadas sin gaps: cero Critical/Important/Minor.

| Criterio de A1 | Veredicto y evidencia |
|---|---|
| Identidad y alcance del parche | ✓ Cinco fuentes y baselines, todos los artefactos SHA coinciden; cuatro archivos con cambios, diff489 líneas. Scope exit0. |
| Trece mutadores reservan antes del primer acceso | ✓ `StudioAvState.php:27,47,78,125,148,280,306,334,372,427,503,526,555`. |
| Lecturas puras sin UPDATE | ✓ `workingExport`:62, task/checkpoints:589,595; test Transfer:359–362 ejecutado según XML final. |
| Fencing, propiedad, idempotencia y timestamps | ✓ Comprobaciones State:705 preservadas; helper:701 solo id=id; TransferTest:315 compara fila completa. |
| MySQL conserva política anterior | ✓ Condicionales State:185,194,698; solo verificación estática, ejecución no acreditada. |
| Deadline de escritor y COMMIT | ✓ State:185–208; datasets TransferTest:295–308 y RED específicos. |
| Rollback, tracking, PRAGMA, nesting y callbacks | ✓ State:197–227, TransferTest:307–357; contrastadas APIs Laravel instaladas. |
| Transfer transmite plazo sin repetir I/O | ✓ Único cambio Transfer:291, tests de bytes TransferTest:304,310. |
| Runner sin instrumentación | ✓ SHA idéntico al baseline, sin delta. |
| Dos PDO y RED/GREEN reales | ✓ QueueTest:119–184, TransferTest:216–326 y salidas conservadas. |
| Documentación y afirmaciones limitadas a lo ejecutado | ✓ 92/96,567 aserciones, cuatro nativos omitidos; T11 y QA pendientes explícitos. |
| Constitución, interop, OpenAPI, cobertura mínima | No aplican a este diff: no constitución/dev.json, ni piezas interop/API tocadas ni mínimo configurado. |

A1 ejecutó SHA, scope y PHP lint de los cuatro archivos cambiados sin errores; leyó los RED/GREEN y XML, sin repetir suites. B1 verificó los mismos invariantes y ejecutó independientemente `php vendor/bin/pest tests/Feature/Studio/StudioAvTransferTest.php tests/Feature/Studio/StudioAvQueueTest.php --filter 'serialize|preserves caller transaction' --compact --no-coverage`: **16/16 correctos,162 aserciones,13.333 s,exit0**, con storage UUID y nativos desactivados; sesión57606 terminal. Cinco SHA y diff seguían iguales después. Sin defectos concretos de corrección ni hallazgos para otras lentes; MySQL no verificable en ejecución. No se han emitido aprobaciones de toda la iniciativa.

Después de la revisión se ejecutó la regresión pública focal siguiente. Sigue pendiente la QA independiente sobre las fuentes finales, junto con la incorporación de subtítulos. No hay verificación MySQL, VPS, bucket R2 real ni multimedia Linux; el límite de espera probado es el checkpoint independiente, no todas las transacciones de la aplicación. El objetivo completo y la auditoría final con Claude CLI siguen pendientes.

La regresión focal mobile-360 terminó **1/1 correcta**, sin reintentos/flaky/skips/errores de runner: sesión12532 terminal, helperPID21328, Playwright17700, raíz `run-general-61afa3ff-4fd2-49bf-87b6-538f68a5ace2`. [Registro](public-focal/execution.json), [JSON original](public-focal/results.json), [mediciones](public-focal/measurements.json). Los608 SHA PHP/renderer/build coinciden antes/después; los SFC de subtítulos aún sin compilar quedan fuera de este snapshot y no reciben aceptación.

El recorrido completa la subida pública de vídeo y música, worker real, descarga y borrador de su marca. MP4 H264/AAC48k estéreo100×80,42 frames/3,5s; GIF50×40,42 frames/3,5s y sin audio. RMS de movimiento28,457; amplitudes440/880≈0,04985/0,04999, rampas≈0,01997, marcador recortado≈0,0000248, diferencia L/R≈0,00000164 y cola silenciosa0. Se conserva revisión1 frente a revisión actual2. [MP4](public-focal/composite.mp4) y [GIF](public-focal/composite.gif). Esta aceptación focal tras el fix no borra QA1 ni equivale a QA2 de T11. Slot nativo devuelto al implementador de subtítulos al terminar.
