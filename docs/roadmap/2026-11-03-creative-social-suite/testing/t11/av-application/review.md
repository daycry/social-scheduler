# T-11: estado persistente y archivos privados

2026-11-05. Componente interno revisado y comprobado; T-11 continúa en progreso y el roadmap conserva7/23 tareas completas. MP4/GIF todavía no están disponibles mediante el flujo público. No se han creado nuevos commits/push de T-10/T-11 ni se ha ejecutado la auditoría final de Claude CLI.

La exportación fija propietario, marca, diseño, revisión, páginas y opciones mediante un fingerprint. La generación de ejecución cambia al reintentar; los leases vencidos o cancelados no pueden aceptar una respuesta tardía. El cursor guarda posición y referencias pequeñas, mientras el plan y los pasos indexados se conservan aparte. Los reintentos mantienen los candidatos a checkpoint, incluso si la generación anterior todavía estaba verificándolos.

Las copias privadas usan recursos autorizados de la marca, rangos de hasta1MiB, timeout por petición de hasta5s sin reintentos y presupuesto compartido de85s por operación. Se comprueban tamaño/reserva/hash y vigencia del intento. Las fuentes WOFF2 conservan el hash de su normalización. La promoción de inputs exige checkpoint aceptado y creación exclusiva; un archivo conflictivo nunca se reemplaza. La inspección física rechaza rutas ajenas, enlaces de directorio y contenido alterado del mismo tamaño.

## Revisión

| Bloque | Lentes | Resultado |
|---|---|---|
| EstadoBD, intento1 | A+B+D; C=false | A/B sin gaps; D1Minor cuantifica al menos3,791GiB deJSON reescrito a4096tramos con una pista |
| EstadoBD, intento2 | A+B+D; C=false | D1 corregido con pasos indexados/plan único/cursor<2000B; sin gaps nuevos |
| Archivos privados, intento1 | A+B; C/D=false | Sin gaps;15/15 tests y68aserciones en ambas lentes |

Diffs, selectores, manifiestos y resultados deterministas están en [raw](raw/). Las lentes comprobaron hashes antes y después. El contrato compartido solo cambió después del freeze de estado para describir los archivos; el manifiesto integrado conserva las diez fuentes restantes y toma la versión revisada más reciente del descriptor.

## Regresión posterior

| Comprobación | Resultado | Evidencia |
|---|---|---|
| PHP: estado/archivos/renderer/API-MCP/backend/automatización/frames/timeline |150/150,744aserciones;0errores/fallos/skips |[JUnit](raw/phpunit.xml), [salida](raw/php.stdout) |
| Playwright: cinco specs de estudio,14casos por viewport |42/42;0failed/flaky/skipped/errores/interrupted; un intento por caso |[JSON](raw/playwright.json), [salida](raw/e2e.stdout) |
| qa-gate | VERDE,exit0 |[Resultado](raw/qa-gate.json) |
| Fuentes durante ambas pasadas |13SHA coincidentes antes/después |[Manifiesto](manifest.json) |
| Pantallas inspeccionadas |1440px,375px,360px; controles accesibles y sin desbordamiento visible |[Desktop](desktop.png), [móvil](mobile.png), [360px](mobile-360.png) |

Ejecución PHP terminal: PID9296, resultado `cda3fc`,6801ms. Playwright terminal: sesión11545/PID29592, resultado `8723c0`,303017,579ms. Raíz desechable `run-general-4bac5cda-4672-485e-8cbe-ffd3e05e2b99`. No repetir estos procesos sin nuevos cambios o fallos. Los avisos NO_COLOR/FORCE_COLOR de Node no fueron errores del runner. Pint y git diff --check correctos; ledger-lint0incoherencias y16avisos previos de Changelog en tareas abiertas.

## Límites y siguiente integración

QA ejecutada por root; no es QA independiente del flujo final. Las pruebas de estado usan SQLite, sin concurrencia MySQL multiproceso. La migración nueva se ejecutó en las bases desechables de pruebas; no se aplicó a MySQL local ni al VPS. La prueba S3 es simulada. El fixture con extensión MP4 comprueba I/O y hashes, no codecs; las mediciones audiovisuales anteriores mantienen sus propios manifiestos históricos. No se repitió frontend ni multimedia sin cambios en sus fuentes.

Quedan limpieza física antes de liberar reservas, invalidación/recuperación de checkpoints corruptos, jobs y recuperación de la cola, ejecución nativa desde Laravel, progreso/cancel/retry público, registro y borrador web/API/MCP, y revisión/QA final del flujo completo. Las pruebas reales del servidor/redes siguen a cargo del usuario. No se ejecutó ONNX ni imaging. La auditoría completa con Claude CLI sigue prevista después de implementar todo el roadmap.
