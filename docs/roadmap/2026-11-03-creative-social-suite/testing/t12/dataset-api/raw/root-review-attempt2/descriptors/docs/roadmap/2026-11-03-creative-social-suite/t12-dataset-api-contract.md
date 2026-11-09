# T-12: adaptadores públicos de datasets

2026-11-06. Contrato fijado antes de endpoints web/API/MCP. Estado posterior: implementados en el árbol, con revisión independiente y transporte TCP pendientes. [Recurso versionado](t12-datasets-contract.md) y [codec](t12-chart-data-contract.md) siguen siendo servicios compartidos. El recurso interno fue aprobado en la segunda revisión; los adaptadores tienen revisión propia y mantienen pendiente la aceptación por TCP.

## Superficies y permisos

API bajo `/api/v1/brands/{brand}/datasets`; web bajo `/brands/{brand}/studio/datasets`. Todas las rutas identifican marca y comprueban su propiedad. Detalle y operaciones usan `{dataset}` además de marca. Usuario y marca explícitos llegan al mismo servicio; modelo o parámetros no permiten acceder a otra marca. API `auth:sanctum`, lectura `designs:read`, creación/cambios `designs:write`; web auth+verified y CSRF.401sincredenciales/403sinability/404ajeno.

CRUD: listarGET y crearPOST colección, detalleGET/reemplazarPUT/archivarDELETE recurso, restaurarPOST `/restore`, historialGET `/revisions`, filasGET `/table`, importaciónPOST `/import` sobre colección o recurso, descargaGET `/export`. Reemplazar/importar en recurso exige `revision` positivo optimista; cualquier conflicto409 deja revisión y archivos anteriores intactos. Campos desconocidos se rechazan por acción. Modelos sólo transportan IDs; no se acepta disco/ruta/user_id/brand_id alternativo/perfil/límite.

La respuesta de metadatos contiene `dataset` seguro; listado e historial contienen colección y `pagination` explícita con current_page/per_page/total/last_page/next_page. Parámetros page positivos y per_page1–100,por defecto25. Búsqueda literal máximo120caracteres. Historial puede leerse tras archivado; editar archivado falla404.

## Tabla e importación

Filas usan `revision` opcional,`offset`0–1000 y`limit`1–100(default50), con columnas completas y celdas/IDs/tipos originales. Se captura una revisión concreta antes de leer; respuesta fija `revision` y `sha256` reales de esa revisión. Metadatos de revisión se obtienen por método compartido autorizado antes del I/O, no consulta ajena en controller. No deben combinarse filas de revisiones distintas al seguir un cursor.

Presupuesto de filas devueltas6MiB de JSON UTF-8 sin escapar Unicode/barras, más metadatos/columnas acotados. Puede devolver menos de limit para respetarlo; `next_offset` indica el siguiente índice exacto, nunca se saltan filas ni se truncan celdas. Un solo registro válido cabe en ese presupuesto por límites del codec. Esta paginación no cambia la tabla almacenada.

Crear/reemplazar acepta JSON tipado `name`/`table` (yrevision alactualizar). Importar admite archivo multipartCSV/TSV hasta25.000.000B y JSON canónico tipado hasta52.000.000B para conservar tipos/IDs al editar datos grandes; JSON usa el mismo esquema cerrado, sin colapsar objetos en listas. CSV/TSV tiene opción explícita header=true/false; JSON no acepta header. XLSX queda pendiente hasta su parser seguro, no se anuncia soportado en este subbloque.

El cuerpo JSON total se acota antes de su decodificación; los archivos se procesan desde streams/ruta temporal interna del upload, sin URL remota ni camino indicado por usuario. No se selecciona un presupuesto numérico desde HTTP. El despliegue debe permitir multipart de almenos64MiB para el JSON52MB; las pruebas del servidor del usuario siguen pendientes. Importar devuelve sólo metadatos, no una segunda tabla completa que duplique memoria. Se verificarán archivo25MB real y tabla paginada en el flujo público antes de aceptación.

## Descarga segura y MCP

Export admite `format:csv|tsv|json`, revisión opcional y `mode:spreadsheet|text` paraCSV/TSV (defaultspreadsheet). JSON conserva identidades/tipos, sin mode. El modo spreadsheet neutraliza TODOS los campos de texto, incluidas etiquetas, con apóstrofo inicial antes del escape CSV. No se confía sólo en entrecomillar, ni en detectar prefijos de fórmulas. Valores ya numéricos ynull mantienen su representación lexical. Se documenta que los campos de texto del archivo seguro llevan apóstrofo; el modo text conserva intercambio literal y permite que una hoja externa interprete fórmulas. No se modifica la tabla original ni se amplía su JSONbudget al producir el archivo.

Respuesta privada streamed,Content-Type porformato,UTF-8,Cache-Control private/no-store,nosniff y filename generado únicamente desdeID/revisión. No path/disk/credenciales en headers o JSON. 503 para snapshot ausente/corrupto, sin fallback.

MCP `manage_studio_dataset`:list/get/rows/history/export requieren designs:read;create/replace/import/archive/restore designs:write, con brand_id obligatorio y dataset_id poracción. Mismos límites, errores, revision y servicios. ImporttextCSV/TSV debe preservar hasta25MB de entrada cuando el transporte lo permita; se documentará un camino de transferencia por chunks/API para entradas que el cliente no pueda enviar en una llamada. No se fingirá paridad de subida grande por aceptar sólo un ejemplo pequeño. Export devuelve metadatos y enlace privado de descarga (no52MB en la respuesta de herramienta); filas paginadas para lectura. Se actualizarán catálogos/paginación y auditoría para dataset_id; no cambia publicación social.

Pruebas requeridas: REDrutas/herramienta antescódigo; abilities,usuarios/marcas/keys/revision; errores ytipoobj/list; pagination sin pérdida; CSVformula-leading whitespace/Unicode/control seguro ymodo fiel; roundtripJSONtyped, file25MB, corrupción503 ydescarga stream; descubrimiento MCP y catálogo contado realmente, sin inventar el total. No se cierra T12 con estos adaptadores.


## Codec de descarga previo a handlers

`StudioTableExport::chunks(array $table, string $format='csv', ?string $mode=null, bool $header=true): iterable` valida tabla y opciones **al llamar**, antes de devolver el iterador; evita fallar por una opción inválida después de enviar headers. No abre FS/red/DB. Usa perfil dataset fijo. CSV/TSV mode ausente significa spreadsheet; text debe ser explícito. JSON sólo acepta mode=null y header=true, porque no tiene encabezado opcional. Formatos u opciones incompatibles fallan sin truncar.

CSV/TSV fiel debe coincidir byte a byte con el codec aprobado, CRLF y entrecomillado correctos incluso U+FEFF inicial. Modo spreadsheet antepone exactamente un apóstrofo a CADA string de etiqueta/celda, aunque sea texto simple o ya empiece por apóstrofo; no toca números/null ni muta la tabla. Este texto transformado se codifica por campo/registro sin volver a normalizar una copia más grande de la tabla: un dataset válido en el límite52MB no debe fallar sólo porque el archivo seguro añade prefijos. JSON se emite como columnas+filas por registros con flags canónicos, preservando tipos/IDs/Unicode/0.0 y sin generar otra cadena JSON completa.

Pruebas RED individuales para defaultsafe, type/format/mode inválidos y validación inmediata, modo fiel y JSONstream typed; pruebas con datos hostiles y margen de presupuesto. No mutación y presupuesto original antes de prefijos son propiedades comprobadas mediante caracterización: ya pasan en su primera ejecución y no se cuentan como TDD. No se introduce una escritura artificial o un cambio de firma para fabricar su RED. Esta distinción sigue la regla de la skill TDD para un criterio ya cubierto; no cambia el comportamiento exigido ni la historia de pruebas. No se atribuye a este codec seguridad de una hoja externa enmodo text ni implementación de endpointdescarga.


## Presupuesto de transporte — 2026-11-06, previo al cambio MCP

CuerposJSON de rutasHTTPdatasets: máximo**53MiB (55.574.528B)** antes de decodificar; archivosCSV/TSV25.000.000B yJSON52.000.000B conservan sus límites independientes. El envoltorioJSON-RPC MCP puede crecer al escaparUnicode o incluir otroJSONcomo texto. Se fija máximo**160MiB (167.772.160B)** de transporte MCP para los encoders habituales; no cambia el límite semántico del dataset ni permite un presupuesto indicado por el cliente. No se promete aceptar serializaciones arbitrariamente infladas con escapes o whitespace ilimitado.

La guardia debe contar el stream incluso sin Content-Length o con una cabecera incoherente, antes de parsear o llegar a TrimStrings. Al decodificar se conserva identidad objeto/lista para rechazar rows:{} o columnas como objeto con clavesnuméricas; no se permite convertirlo silenciosamente enlista vacía. La estructuraJSON-RPC debe mantenerse compatible con eltransportereal delSDK y los otros tools.

No se sube ini_set/memory_limit en producción automáticamente. El ensayo de transporte grande usará un proceso aislado con techoPHP512MiB si elperfil256M no basta, medirá su picoasignador y expondrá la necesidad de configuración. Payloadsgrandes y copiasraw se liberan cuando elSDK lo permita, sin romper su consumo legítimo. Estos512MiB del adaptador no son inferenciaT10 ni autorizan sus modelos. Antes de aceptación se requiere prueba de servidor y transporteMCP real; testsHTTPKernel/UploadedFile no equivalen a multipart porTCP.

Para mensajes MCP grandes, la configuración del servidor y del proxy debe admitir al menos192MiB de cuerpo POST, además de upload_max_filesize64MiB para archivos y PHPmemory_limit512MiB. El mínimo64MiB de multipart no basta por sí solo para un envoltorioMCPUnicode de153MiB. No se cambia ninguna configuración del servidor automáticamente. La guardiaMCP aplica a todos losPOST que consume elSDK, conindependencia de Content-Type; GET/SSE no lee cuerpo. La conservación objeto/lista y0.0 también se verifica en ese camino.
