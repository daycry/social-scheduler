# Contrato T-10 — fuentes e imágenes avanzadas

2026-11-04. Contrato previo a implementación; T-10 permanece abierta hasta pruebas reales, revisión independiente y QA. Mantiene la compatibilidad de documentos v1 y las operaciones de T-09.

## Fuentes privadas por marca

Recurso propio `StudioFont`, separado de MediaAsset: propietario, marca, nombre visible, familia/estilo/peso extraídos, formato, tamaño, SHA-256, estado y archivo privado. Upload TTF/OTF/WOFF/WOFF2 con tamaño acotado y validación real de estructura mediante proceso tipográfico local. Archivos corruptos se rechazan; no se confía en extensión ni MIME declarado. No se descargan fuentes arbitrarias por URL.

Texto del documento admite `font_id` opcional, entero positivo. Una referencia explícita exige fuente válida de esa marca. Se registra el archivo real antes de medir o dibujar texto en editor, miniaturas y exportación; una fuente explícita ausente o que no carga produce error, no fallback silencioso. La familia CSS interna deriva del ID/hash, evitando colisiones entre marcas. Los nombres locales existentes siguen siendo compatibles.

Kit admite `font_ids` opcional alineado con `fonts`, con IDs de la misma marca o null para familias locales. La referencia del kit conserva esta asociación. Aplicar el kit actualiza familia y referencia de archivo conjuntamente; cambiar a una familia local elimina el `font_id` anterior. Undo/redo restaura la referencia como en T-09. Una fuente usada por diseños/revisiones no se elimina físicamente; se ofrece archivo lógico sin romper resultados históricos.

Rutas web, después de `/brands/{brand}/studio`: `GET /fonts` (query/page/per_page), `POST /fonts` (multipart `file`, nombre opcional), `GET /fonts/{font}/file` (bytes privados), `DELETE /fonts/{font}` (409 si está usada). API v1 usa `/brands/{brand}/design-fonts` y el equivalente de archivo; diseños read/write según acción. MCP `manage_studio_font` permite list/get/upload/delete mediante el mismo servicio, con upload base64 acotado. Listado paginado, respuestas `{fonts,pagination}` o `{font}`.

También `GET /fonts/{font}` y `POST /fonts/{font}/archive|restore`, respuesta200 `{font}`, en web/API/MCP. El listado admite `archived=true|false`. `archived_at` se separa de `status:ready`: archivo lógico oculta del selector habitual sin invalidar bytes, referencias propias, guardado ni exportaciones históricas. No se cambia la propiedad ni se permite el borrado físico de una fuente en uso.

## Máscaras y recorte

Imagen admite `mask` opcional y tipada: `{type: ellipse|rounded_rect, radius?:number}`. La máscara se expresa en el espacio del elemento, conserva las transformaciones y se construye con clases conocidas; no se acepta JSON libre de Fabric ni SVG ejecutable. Contratos PHP/JS y serialización coinciden. Los filtros existentes deben verificarse por píxeles y el recorte debe respetar las dimensiones reales del recurso.

## Eliminación de fondo

Trabajo propio por marca, usuario y media de entrada, con estado queued/processing/ready/failed, motor/modelo/hash explícitos, salida PNG registrada y asociada a la marca. Solo imagen propia lista; el original permanece. El worker utiliza ejecutor local ONNX/Python con presupuestos de tiempo/memoria/píxeles y archivos de salida controlados; ausencia de modelo/dependencia es un error explícito. No se finge resultado ni se ejecuta código de modelo remoto.

Rutas web `POST /brands/{brand}/studio/image-jobs` con `{media_id,operation:remove_background}` →202 `{job}`, `GET /brands/{brand}/studio/image-jobs/{job}` → `{job}`. API v1 `/brands/{brand}/image-jobs` equivalente. MCP `process_studio_image` inicia/consulta usando servicios y abilities por acción. UI permite iniciar desde recurso de imagen, seguir el trabajo, gestionar error y añadir la salida real al diseño.

El modelo debe tener origen y licencia comprobados, descarga verificada y configuración reproducible. Inferencia real sobre fotografías con sujeto/fondo: comprobar PNG, dimensiones, alpha/transparencia, conservación del sujeto y diferencias de bytes. Una fixture sintética uniforme o un proceso simulado no cierra este criterio.

## Render, permisos y verificación

Fuentes viajan como bytes propios en un paquete separado de recursos visuales, autorizado por marca, acotado en el presupuesto conjunto y servido en el mapa cerrado o registrado con ArrayBuffer. No abrir red externa del renderer. El mismo cargador se usa en editor/miniaturas/exportación.

RED por criterio: fuente corrupta/ajena, carga de fuente real sin fallback, máscaras/crop/filtros con RGBA real, jobs/propiedad/errores y alpha con modelo real. Contrato OpenAPI previo a endpoint; catálogo MCP y permisos actualizados. Revisión A/B/C/D según selector, QA en tres viewports y artefactos reales antes de cerrar. Sin VPS ni publicaciones externas.
