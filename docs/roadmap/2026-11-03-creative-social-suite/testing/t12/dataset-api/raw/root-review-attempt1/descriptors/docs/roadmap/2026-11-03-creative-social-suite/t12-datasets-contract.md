# T-12: datasets privados por marca y revisiones

2026-11-06. Contrato previo de la capa de recursos; no acredita editor/gráficos ni cierre de T-12. Complementa [el codec de datos](t12-chart-data-contract.md). Los adaptadores web/API/MCP y el documento chart se contratarán antes de implementarlos.

## Identidad, propiedad y persistencia

Un dataset pertenece de forma explícita a `user_id` y `brand_id`. Modelo `StudioDataset` con nombre de1–255 caracteres significativos, revisión positiva, número de filas/columnas, timestamps y archivado reversible. Modelo `StudioDatasetRevision` inmutable con dataset, propietario/marca, revisión, nombre, filas/columnas, tamaño JSON y SHA-256. Índices por usuario/marca y unicidad `(dataset_id, revision)`. Relaciones con usuario/marca/dataset mediante claves foráneas. Las tablas completas no se guardan en una columna JSON de MySQL: se almacenan como bytes canónicos privados, evitando que el máximo paquete de MySQL rebaje silenciosamente la entrada25MB.

El servicio compartido `StudioDatasets` comprueba usuario y marca en todas las operaciones, incluso si recibe un modelo obtenido sin scopes. Una ID ajena, marca ajena o referencia cruzada devuelve404. No se confía sólo en route binding ni en el scope de sesión; jobs/adaptadores pasan usuario explícito. No se expone `user_id`, disco ni ruta en presentaciones públicas. Listados por marca paginados y búsqueda literal por nombre; no consultas por fila ni carga de tablas completas al listar.

Los archivos canónicos se guardan en disco privado `local`, bajo raíz propia `studio-datasets/<user>/<brand>/`, con identificador generado por servidor para cada revisión. Clientes no proporcionan disco, ruta o hash. No se sobrescriben archivos históricos. El codec fijo `dataset` valida el JSON antes de guardarlo; se usan los flags canónicos documentados. Un fallo al escribir no crea registros. Un fallo de transacción, conflicto o inserción elimina sólo el nuevo archivo de esa operación, sin tocar revisiones anteriores ni archivos ajenos. Si un caller mantiene una transacción externa abierta, la operación debe rechazarla antes de escribir: no anunciar una revisión persistida ni dejar un archivo huérfano por rollback posterior no controlado.

## Operaciones internas y concurrencia

Crear valida nombre/tabla y registra revisión1. Reemplazar exige `expectedRevision` positivo y actualiza nombre/tabla de forma atómica, creando una nueva revisión aunque cambie sólo el nombre. El chequeo se hace después de adquirir bloqueo de escritura en la transacción;409 si la revisión cambió. En SQLite se reserva escritor antes de la primera lectura dentro de la transacción; `lockForUpdate` solo no basta para impedir upgrade DEFERRED. En otros motores se utiliza bloqueo de fila. Un conflicto no altera metadatos, revisiones ni archivos previos. Nombre, filas, columnas, SHA y tamaño deben corresponder exactamente al archivo de esa revisión. No se evalúan fórmulas ni se infieren números.

Leer una tabla requiere dataset y revisión explícita (o captura de la revisión actual en una sola consulta). La revisión solicitada debe pertenecer al mismo dataset, usuario y marca. Se comprueban tamaño acotado, SHA, JSON válido y esquema; archivo ausente/corrupto no se sustituye por una tabla vacía, reciente o por otra revisión. Se devuelve un error503 controlado, sin ruta privada. Las revisiones históricas siguen legibles cuando el dataset se archive para conservar diseños fijados; listados normales y edición no incluyen archivados. Archivo/restore no crean revisión de tabla ni eliminan archivos.

La capa de recurso ofrece listado paginado de metadatos, historial paginado, presentación segura, creación, reemplazo, lectura de tabla por revisión y archivo/restauración. Las tablas se intercambian con las mismas identidades/tipos del codec. Importar25MB se validará al conectarlo al upload; este servicio no descarga URLs. La selección de perfil es exclusivamente código servidor.

## Referencias y puertas pendientes

El futuro elemento chart guardará referencia inmutable de dataset+revisión+SHA, selección de columnas y representación tipada; nunca referencia live que cambie exports históricos. Resolver tabla, permisos y archivos para preview/export/duplicación exige contrato específico; no basta guardar la ID en Canvas. El documento2MiB no se amplía ni incluye tablas grandes. No se modifica aún `StudioDocument`, canvas ni renderer.

Pruebas antes de código por criterio: creación/normalización, aislamiento usuario/marca, snapshot anterior tras reemplazo, conflicto optimista sin huérfanos, archivo/restauración, listados sin materializar tablas, error de escritura/rollback y lectura corrupta. Base/storage exclusivamente desechables, sin `.env`, MySQL/VPS/R2 reales. Las revisiones archivadas retenidas no equivalen a un sistema de cuotas o GC: barrido de huérfanos tras eliminación definitiva de propietario/marca queda abierto en la validación integral T-22/T-23 antes de cierre operativo.

Persisten editor accesible/import preview, XLSX/conexiones, más de30 tipos de gráficos y estilos, documentos refluibles, presentaciones, pizarras y Office. Este subbloque no reduce su alcance ni cierra T-12.
