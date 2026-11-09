# T-11 — retiro y recuperación de reservas de biblioteca

Fecha: 2026-11-05. Subbloque verificado, sin hallazgos bloqueantes; un Minor de planificación SQLite queda registrado. **T-11 sigue en progreso y el roadmap conserva 7/23 tareas completadas**. Register todavía no se entrega desde la cola.

## Qué cambia

El workspace conserva un recibo privado del medio: ID, disco, ruta, hash, tamaño, ubicación física, UploadId y estado. Se guarda antes del I/O y se actualiza en la misma transacción que el checkpoint del medio. Sobrevive a cascadas del propietario y permite recuperar incluso un multipart cuya respuesta de creación se perdió. Una ubicación fijada no cambia al modificar el prefijo configurado; el retiro utiliza el destino original y comprueba bucket/endpoint.

El limpiador adquiere el mutex de workspace y espera una exportación terminal o huérfana, su vencimiento y la ausencia de leases futuros antes de iniciar retiro. El token retiring bloquea asignación, checkpoint y aceptación de esa reserva. Los medios listos se conservan y las referencias a medios pendientes impiden borrarlos. Una generación nueva puede continuar mientras se completa el retiro anterior, sin que se cambien su estado o lease.

En local solo se retiran final/part bajo la raíz original comprobada, sin seguir enlaces. En S3/R2 se aborta un UploadId conocido y se consulta ListParts; mientras el ID siga consultable se conserva la reserva. Los IDs desconocidos se descubren por Key exacta, con páginas de16 y marcadores persistidos. El objeto se borra y se exige HEAD404. Solo entonces se elimina el medio pendiente, se libera la reserva y se marca released. Puede crearse después otra reserva activa; no se mantienen dos medios activos para el mismo workspace.

Los huérfanos retiran primero la copia antes de perder su journal. El recuperador atiende también terminales sin leases pendientes. Los retiros iniciados tienen un reloj/index propios, independientes del active_until de una generación nueva. Solo la pasada de vencimientos procesa retiring: las otras ramas los omiten. Todo intento programado incompleto o fallido se difiere180s; los huérfanos no resueltos también atrasan su selección para permitir trabajo de otros registros. El limpiador interno puede continuar explícitamente sin esperar el reloj del scheduler.

Cada pasada física tiene presupuesto25s, comprobado antes de reclamar token, HTTP≤5s/connect≤2s y retries0. Los15s del recuperador se comprueban entre operaciones y no interrumpen una pasada física ya iniciada. No se mantienen locks de BD durante el I/O.

## Revisión independiente

| Intento | A | B | D | Resultado |
|---|---|---|---|---|
| 1,10 fuentes | Important A1: contrato de aplazamiento incumplido | Sin defectos identificados | Important D1: mismos retiros consumen repetidamente el lote | A1/D1 son el mismo defecto en terminal/orphan |
| 2,10 fuentes; delta92 líneas | A1 corregido, demás criterios conservados | Sin defectos nuevos; equidad comprobada | D1 corregido; nuevo D2-1 Minor SQLite | Sin Critical/Important pendientes |

Cada lente del intento1 ejecutó54/54 PHP,296 aserciones. B añadió14/14 de Cleanup,79 aserciones. A2 ejecutó56/56,306 aserciones; B2 ejecutó80/80,432 aserciones entre focales y Queue filtrada. D2 ejecutó89 correctas,469 aserciones y4 skips nativos (93 casos totales), sin presentar esos skips como ejecución. Las diez SHA actuales y congeladas coinciden antes/después.

El defecto confirmado repetía DeleteObject en una sola recuperación después de fijar cleanup_after futuro. La sonda inicial de25 huérfanos hizo50 llamadas y otras25 en una recuperación inmediata. Los tests permanentes terminal/orphan reprodujeron2 intentos frente a1 (`b503ac`). El fix reserva retiring exclusivamente para la pasada por vencimiento, aplaza desde todas las ramas y retrasa huérfanos no resueltos. La sonda D2 real con SDK simulado confirma **25 intentos y0 adicionales inmediatamente**, con25 fechas futuras. No se atribuye una latencia de R2 real a estas sondas.

Scope --baseHEAD exit0, sin fuera de alcance, avisos ni exclusiones de usuario. Selector C=false/D=true por migración. Modelo heredado por instalación parcial de model-tier. Sin constitución, cobertura configurada, Jira, interop ni cambio OpenAPI aplicables. La secuencia histórica RED se conserva con IDs y extractos de herramienta; no se inventa stdout retrospectivo. La implementación remota que se adelantó al test fue borrada y reescrita después del RED, según queda declarado en el ledger.

## Aviso menor abierto

**D2-1 — Minor, no bloqueante:** en SQLite recién migrado sin estadísticas, el nuevo filtro media_retiring=false puede elegir el índice del flag y una ordenación temporal. Con50000 exportaciones completadas y25 huérfanos más antiguos,20 consultas reales miden244,26ms frente a0,39ms sin ese filtro: aproximadamente12,2ms frente a0,020ms por consulta. ANALYZE cambia el plan y da0,37ms/20. No acredita monopolización del recuperador ni comportamiento MySQL. Se registra para revisar el acceso por flag/vencimiento y las estadísticas dentro de la siguiente modificación del recuperador o T-22; no se declara deuda aceptada por el usuario ni un resultado de producción.

## Verificación final root

**349/349 PHP,1841 aserciones,0 fallos/errores/skips**, exit0 (`133f91`). Los39 hashes de fuentes coinciden antes/después. JUnit/stdout/stderr y comando/proceso están conservados en este directorio.

Incluye cinco casos audiovisuales reales/99 aserciones: cuatro de worker hastaassemble (MP4/GIF y música WAV440Hz/GIF sin copiarla) y uno de puente MP4 con copia local y aceptaciónBD manual. STUDIO_AV_NATIVE_TEST=1 y límite de grupo nativo≤3GiB. El retiro nuevo usa SQLite, disco desechable y simulador SDK con respuestasHTTP404/412, efectos remotos y acknowledgements perdidos. **No acredita un bucket R2 real, MySQL, VPS ni ONNX.**

No cambian JS/UI/rutas públicas. Las153 pruebas frontend anteriores y42E2E conservan su alcance; no se repiten ni se atribuyen al flujo todavía pendiente. QAroot con revisión independiente A/B/D; no es la QA final independiente del padre.

## Trabajo siguiente

Conectar register al runner y reencolar por partes, comprobar recuperación real de entregas/descarga/borrador, señal de cancelación nativa y controles web/API/MCP, después QA integral. Atender D2-1 con evidencia al tocar nuevamente selección/migración. Queue.enqueue(register) continúa false. T-10 mantiene abierta su aceptación con el presupuesto de memoria actual. El roadmap completo y la auditoría final de aplicación/documentación con Claude CLI permanecen pendientes.

Sin nuevos commits/push T-10/T-11 ni migración150000 aplicada a MySQL/VPS. Referencias del protocolo: [AbortMultipartUpload](https://docs.aws.amazon.com/AmazonS3/latest/API/API_AbortMultipartUpload.html), [ListMultipartUploads](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListMultipartUploads.html).
