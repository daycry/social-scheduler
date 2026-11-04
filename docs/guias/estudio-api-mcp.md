# Estudio nativo: API, MCP y render en servidor

Actualizado: 2026-11-04. Esta guía describe el código disponible en esta entrega. El editor, la API y MCP utilizan los mismos servicios de diseños, recursos y exportaciones por marca.

Referencias: [OpenAPI](../api/creative-suite.openapi.yaml), [contrato completo del documento v1](../roadmap/2026-11-03-creative-social-suite/document-contract.md), [módulo MCP](../modulos/mcp.md), [despliegue general](../modulos/despliegue.md) y [desarrollo local](desarrollo-local.md).

## Acceso y permisos

La API está en `/api/v1`; MCP en `/mcp`. Ambos aceptan una API key personal como `Authorization: Bearer <token>`. La API de Studio no usa la sesión web como alternativa. Crea una key desde la pantalla de API keys y selecciona las abilities necesarias; una key existente conserva sus permisos originales.

| Ability | Uso |
|---|---|
| `designs:read` | Consultar diseños, kits, recursos privados, exportaciones y sus estados. |
| `designs:write` | Crear/editar/archivar/restaurar/duplicar diseños, editar kits, registrar recursos y solicitar render. |
| `stats:read` | Consultar métricas mediante la API o `get_stats`. |
| `brands:read` | Usar las herramientas previas `list_brands` y `list_media`. |
| `posts:write` | Importar un archivo con la herramienta previa `import_media_from_url`; crear una publicación con `create_post`. |

`designs:write` no concede automáticamente `designs:read`. Para un flujo completo de diseño y seguimiento selecciona ambas. El preset «Publicar» incluye las abilities de lectura, pero debes añadir `designs:write` si quieres crear diseños. Todos los recursos se comprueban contra el usuario de la key y la marca correspondiente.

Los ejemplos siguientes usan Bash, `curl` y, cuando se indica, `jq`. En PowerShell utiliza `curl.exe` y adapta las variables. Los IDs y el dominio son ilustrativos; emplea los que devuelva tu instalación.

```bash
BASE='https://tu-instalacion.example'
TOKEN='TU_API_KEY'
BRAND_ID=12
```

## API: rutas y respuestas

Las respuestas de crear/leer/actualizar un diseño son el objeto de diseño en el nivel superior: `.id`, `.document`, `.revision`. El listado devuelve el paginador en el nivel superior: `.data`, `.current_page`, `.last_page`, `.total`. Los listados de recursos y exportaciones usan `.assets` y `.exports` respectivamente.

| Método y ruta, después de `/api/v1` | Permiso | Resultado |
|---|---|---|
| `GET /brands/{brand}/designs` | `designs:read` | Resúmenes, sin cargar documentos completos; 24 por página. |
| `POST /brands/{brand}/designs` | `designs:write` | `201`, diseño con revisión 1. |
| `GET /designs/{design}` | `designs:read` | Documento, kit, recursos y exportaciones. |
| `PATCH /designs/{design}` | `designs:write` | Nueva revisión; `409` si la esperada quedó obsoleta. |
| `DELETE /designs/{design}` | `designs:write` | Archivado lógico; conserva revisiones y exportaciones. |
| `POST /designs/{design}/duplicate` | `designs:write` | `201`, copia con historial independiente. |
| `POST /designs/{design}/template` | `designs:write` | `201`, `{design}` de una copia como plantilla, con revisión de origen obligatoria. |
| `POST /designs/{design}/instantiate` | `designs:write` | `201`, `{design}` independiente, sustituyendo campos de una plantilla. |
| `POST /designs/{design}/resize` | `designs:write` | `200`, `{design}` con nueva revisión y tamaño adaptado. |
| `POST /designs/{design}/variants` | `designs:write` | `201`, `{designs}` independientes, hasta doce en una transacción. |
| `POST /designs/{design}/apply-kit` | `designs:write` | `200`, `{design}` con los estilos del kit actual. |
| `POST /designs/{design}/restore` | `designs:write` | Restaura un diseño archivado. |
| `GET /brands/{brand}/brand-kit` | `designs:read` | Kit de esa marca. |
| `PUT /brands/{brand}/brand-kit` | `designs:write` | Actualización parcial de los campos del kit. |
| `GET /brands/{brand}/design-folders` | `designs:read` | Carpetas de esa marca. |
| `POST /brands/{brand}/design-folders` | `designs:write` | `201`, crea carpeta con `name`. |
| `DELETE /design-folders/{folder}` | `designs:write` | Elimina la carpeta y conserva sus diseños. |
| `GET /brands/{brand}/design-assets` | `designs:read` | Recursos registrados en esa marca. |
| `POST /brands/{brand}/design-assets` | `designs:write` | Registra `media_id` propio, listo, de imagen o vídeo. |
| `GET /brands/{brand}/design-assets/{media}/file` | `designs:read` | Bytes privados; admite un rango HTTP simple. |
| `GET /designs/{design}/exports` | `designs:read` | Historial de exportaciones, `page` y `per_page` (50 por defecto, máximo 100). |
| `POST /designs/{design}/exports` | `designs:write` | `202`, trabajo de render con su estado real. |
| `GET /design-exports/{export}` | `designs:read` | Estado, resultados y descarga temporal si está lista. |
| `GET /brands/{brand}/analytics` | `stats:read` | Métricas del mismo servicio que el dashboard. |

El listado de diseños acepta `query` —búsqueda literal, también para `%` y `_`—, `folder_id`, `templates`, `archived` y `page`. `archived=true` lista únicamente archivados. Los booleanos de query admiten `true`/`false` y `1`/`0`.

```bash
curl --fail --get "$BASE/api/v1/brands/$BRAND_ID/designs" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'query=Campaña' --data-urlencode 'templates=false' \
  --data-urlencode 'archived=false' --data-urlencode 'page=1'
```

## Documento tipado y creación

El documento es un formato propio con `schema_version: 1`; los objetos internos de Fabric no son el contrato de persistencia. Los elementos tienen `id`, `type`, `x`, `y`, `width` y `height`. Los tipos disponibles son `text`, `rect`, `ellipse`, `triangle`, `line`, `path`, `image`, `video` y `group`. Un grupo contiene `elements`; las imágenes y vídeos referencian `asset_id` registrado en la misma marca.

```bash
cat > design-request.json <<'JSON'
{
  "name": "Campaña de primavera",
  "is_template": false,
  "folder_id": null,
  "document": {
    "schema_version": 1,
    "width": 1080,
    "height": 1080,
    "pages": [{
      "id": "page_1",
      "name": "Portada",
      "background": "#ffffff",
      "duration": 5,
      "elements": [{
        "id": "title",
        "type": "text",
        "x": 80,
        "y": 100,
        "width": 920,
        "height": 140,
        "text": "Hola, marca",
        "fontFamily": "Arial",
        "fontSize": 64,
        "fontWeight": "bold",
        "fill": "#111827",
        "stroke": ""
      }]
    }]
  }
}
JSON

curl --fail "$BASE/api/v1/brands/$BRAND_ID/designs" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @design-request.json \
  --output design.json
DESIGN_ID=$(jq -r '.id' design.json)
```

Reglas principales del documento:

- JSON hasta 2 MiB, entre 1 y 100 páginas; dimensiones enteras de diseño entre 40 y 8000 px.
- Hasta 500 elementos por página contando descendientes; profundidad máxima 8. IDs únicos en todo el documento, 1–64 caracteres ASCII alfanuméricos, `-` o `_`.
- Coordenadas finitas entre −32000 y 32000; dimensiones de elemento entre 0 y 32000; escalas positivas entre 0.01 y 100; rotación entre −360 y 360; `skewX`/`skewY` entre −89 y 89; opacidad entre 0 y 1.
- Texto plano hasta 10000 caracteres por elemento. La cadena vacía y los espacios iniciales/finales del texto se conservan. `stroke: ""` expresa ausencia de trazo; no lo sustituyas por `null`.
- Relleno/fondo hexadecimal o gradiente lineal con 2–12 paradas ordenadas. Las rutas vectoriales contienen comandos numéricos `M`, `L`, `C`, `Q`, `Z`, sin XML/SVG libre.
- No se admiten `src`, URLs arbitrarias, callbacks o propiedades de clases del motor. Las opciones y límites completos están en el [contrato v1](../roadmap/2026-11-03-creative-social-suite/document-contract.md).

`fontFamily` y las fuentes del kit son nombres de familias, sin CSS ni URLs. El render usa las fuentes disponibles para Chromium en el servidor. Escribir «Arial» u otra familia no instala ni descarga esa fuente; si no existe, el sistema puede usar una sustitución. Esta entrega no incorpora un cargador de tipografías personalizadas. Comprueba las fuentes instaladas cuando necesites igualdad tipográfica entre el navegador y el servidor.

## Actualización y conflictos de revisión

Un `PATCH` requiere `name`, el documento completo y la `revision` que leyó el cliente. Cada guardado correcto incrementa esa revisión y guarda un snapshot. Conserva las propiedades que no estés editando.

```bash
curl --fail "$BASE/api/v1/designs/$DESIGN_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --output design.json

jq '{name,document,revision,is_template,folder_id}
    | .document.pages[0].elements[0].text = "Nueva campaña"' \
  design.json > design-update.json

curl --fail -X PATCH "$BASE/api/v1/designs/$DESIGN_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @design-update.json \
  --output design.json
```

Si otra sesión ya guardó, devuelve HTTP `409` y conserva la versión del servidor. Vuelve a leer el diseño, compara y resuelve los cambios antes de reenviar; cambiar solamente el número de revisión podría sobrescribir trabajo ajeno. En MCP el resultado tiene `isError: true` y un mensaje que identifica el conflicto `409`.

Duplicar crea una revisión 1 independiente. `folder_id: null` quita la carpeta. La copia y el archivado se pueden invocar así:

```bash
curl --fail -X POST "$BASE/api/v1/designs/$DESIGN_ID/duplicate" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  --data '{"name":"Variante de campaña"}'
curl --fail -X DELETE "$BASE/api/v1/designs/$DESIGN_ID" \
  -H "Authorization: Bearer $TOKEN"
curl --fail -X POST "$BASE/api/v1/designs/$DESIGN_ID/restore" \
  -H "Authorization: Bearer $TOKEN"
```

Las carpetas están aisladas por marca y usuario. Crear una devuelve `{"folder":{...}}`; el listado devuelve `{"folders":[...]}`. Asigna su ID mediante `folder_id` al crear o actualizar un diseño. Eliminar una carpeta quita esa asociación tanto en diseños activos como archivados, sin borrar documentos, revisiones o exportaciones; los números de revisión se conservan.

## Plantillas y automatización por marca

El detalle añade `template_fields`, una lista de los campos `{{nombre}}` presentes en textos, incluidos grupos. Guardar una plantilla requiere `revision` y admite `name`; la fuente se conserva. La instancia requiere la revisión de la plantilla y todos y solo sus campos, con valores de texto literales. `apply_brand_kit: true` solicita aplicar los estilos actuales de la marca; no instala fuentes. El nuevo diseño tiene IDs y revisiones independientes.

```json
{"revision":3,"name":"Saludo de noviembre","fields":{"nombre":"Aipocalipsis"},"apply_brand_kit":true}
```

Envía ese cuerpo a `POST /api/v1/designs/{template}/instantiate`. Los envoltorios `{design}`/`{designs}` de estas nuevas operaciones son distintos de las respuestas sin envolver de los endpoints CRUD existentes. Una plantilla se edita o archiva con los endpoints CRUD habituales. Filtra `templates=true` para obtener la biblioteca paginada.

Para redimensionar, envía `revision`, `width`, `height` y `mode` (`canvas`, `contain` o `cover`). Para variantes, envía `revision` y `variants: [{name,width,height,mode}]`; añade `fields` si la fuente contiene campos y `apply_brand_kit` si corresponde. Se permiten hasta doce variantes, con un máximo acumulado de 8 MiB de documentos y 20000 elementos. Si falla una variante no se crea ninguna.

MCP reutiliza `manage_design` con acciones `template`, `instantiate`, `resize`, `variants` y `apply-kit`, además de las anteriores. El resto de argumentos son los del servicio compartido, junto a `design_id`; se requiere `designs:write`. El catálogo mantiene 36 herramientas. El [contrato de automatización](../roadmap/2026-11-03-creative-social-suite/t09-automation.md) detalla estilos, copias, modos y conflictos.

Al restaurar desde el historial un documento anterior a un cambio de kit, `PATCH /designs/{design}` y MCP `update_design` admiten `kit_revision`: número de una revisión guardada del mismo diseño. El servidor recupera de ella la referencia del kit; no acepta referencias de otro diseño o usuario. El editor conserva ese dato en undo/redo, guardado y recuperación para que las aplicaciones posteriores del kit reconozcan los estilos restaurados. Sin ese argumento, una actualización conserva la referencia actual.

La migración adicional `2026_11_04_080000_add_kit_reference_to_studio_designs.php` conserva la referencia del kit en diseños y revisiones. Debe aplicarse junto con el despliegue antes de servir este código. En esta sesión no se pudo aplicar al MySQL local porque no estaba escuchando en loopback; las pruebas utilizan bases desechables con la migración real. El VPS sigue sin modificarse.

```bash
curl --fail "$BASE/api/v1/brands/$BRAND_ID/design-folders" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  --data '{"name":"Lanzamientos"}' --output folder.json
FOLDER_ID=$(jq -r '.folder.id' folder.json)
curl --fail "$BASE/api/v1/brands/$BRAND_ID/design-folders" \
  -H "Authorization: Bearer $TOKEN"
curl --fail -X DELETE "$BASE/api/v1/design-folders/$FOLDER_ID" \
  -H "Authorization: Bearer $TOKEN"
```

## Registrar recursos y consultar el kit

Primero sube el archivo mediante la biblioteca existente o impórtalo mediante MCP. Espera a que su estado sea `listo`. Registrar un recurso en una marca no transfiere su propiedad; un ID ajeno o pendiente no es utilizable. Para este ejemplo, `MEDIA_ID` debe ser el ID real de una imagen propia lista.

```bash
MEDIA_ID=701
curl --fail "$BASE/api/v1/brands/$BRAND_ID/design-assets" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  --data "{\"media_id\":$MEDIA_ID}" --output attached-asset.json

FILE_URL=$(jq -r '.asset.file_url' attached-asset.json)
curl --fail "$BASE$FILE_URL" -H "Authorization: Bearer $TOKEN" \
  --output recurso-privado.bin
```

`file_url` es una ruta relativa de API que necesita Bearer con `designs:read`. `preview_url` corresponde a la sesión web del editor. El proxy transmite bytes desde el almacenamiento, incluidos recursos R2, sin depender de CORS; admite `Range: bytes=inicio-fin`, rangos abiertos y sufijos. No pegues estas rutas en el documento: usa un elemento como `{"id":"photo","type":"image","asset_id":701,"x":0,"y":0,"width":1080,"height":1080}` con el ID devuelto.

El kit admite `palette`, `fonts`, `logos`, `guidelines` y `voice`; los campos omitidos se conservan. `guidelines` y `voice` pueden ser `null` para vaciarlos. Los logos son IDs de imágenes propias ya registradas en esa marca.

```bash
curl --fail -X PUT "$BASE/api/v1/brands/$BRAND_ID/brand-kit" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  --data '{"palette":["#7c3aed","#ffffff"],"fonts":["Arial"],"guidelines":"Mantener margen de seguridad","voice":"Clara y cercana"}'
```

## Render, seguimiento y descarga

La exportación en servidor admite exactamente `png`, `jpeg`, `webp`, `svg` y `pdf`. Solicita una revisión existente y los IDs de páginas en el orden deseado. Se renderiza ese snapshot aunque se guarden revisiones nuevas después.

```bash
jq '{revision,format:"png",pages:[.document.pages[0].id],scale:1,quality:0.94,transparent:false}' \
  design.json > export-request.json
curl --fail "$BASE/api/v1/designs/$DESIGN_ID/exports" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  --data-binary @export-request.json --output export-status.json
EXPORT_ID=$(jq -r '.id' export-status.json)

# Repetir hasta status=ready o status=failed; no reenvía el POST de render.
curl --fail "$BASE/api/v1/design-exports/$EXPORT_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --output export-status.json
jq '{id,revision,status,media_ids,error,download_url}' export-status.json
```

El `POST` responde `202` con el estado real, normalmente `pending` con la cola asíncrona. El worker pasa a `processing` y finalmente `ready` o `failed`. La aceptación del trabajo no significa que haya terminado. `media_ids` puede ser `null` mientras no haya resultados; `error` y `download_url` también admiten `null`. `error` explica los fallos previstos; no hay IDs de archivos inventados mientras se procesa.

El historial está paginado para limitar cada consulta. `GET /designs/{design}/exports?page=2&per_page=50` devuelve `exports` y `pagination:{current_page,last_page,per_page,total,has_more}`. La consulta del diseño completo devuelve 50 exportaciones por página y el mismo metadato bajo `exports_pagination`; usa `?exports_page=2` para otra página. El orden es por ID descendente, por lo que los trabajos nuevos aparecen al principio. Para seguir un trabajo concreto sin recorrer el historial, usa `GET /design-exports/{export}`. MCP permite `get_design` con `exports_page`.

Cuando `status` sea `ready`, `download_url` contiene una URL firmada válida durante 10 minutos desde la consulta que la generó. Si caduca, vuelve a consultar el estado con Bearer para obtener otra. Esa URL concede acceso temporal al archivo por sí misma: la descarga no necesita reenviar la API key.

```bash
# Ejecutar solo después de comprobar status=ready.
DOWNLOAD_URL=$(jq -r '.download_url' export-status.json)
curl --fail "$DOWNLOAD_URL" --output exportacion.bin
```

El archivo descargado depende del formato y número de páginas:

| Selección | Archivo y uso |
|---|---|
| Una página PNG/JPEG/WebP | Imagen; `media_ids` contiene media validada y registrada en la marca. |
| Varias páginas PNG/JPEG/WebP o SVG | ZIP con `page-001.*`, `page-002.*`, etc., en el orden pedido. |
| Una página SVG | SVG con los píxeles de las imágenes incorporados, sin URLs temporales de esos recursos. |
| Una o varias páginas PDF | Un PDF multipágina. Cada página se rasteriza a PNG y se incorpora al PDF; el texto no se conserva como texto seleccionable ni como vector editable. |

SVG/PDF no generan media publicable para el compositor; sus resultados se descargan como artefactos. Un resultado PNG/JPEG/WebP puede usarse después en `create_post` mediante `media_ids`, con los permisos y reglas de publicación habituales. Crear o exportar un diseño no publica nada.

Límites actuales del render en servidor:

- `scale`: 0.1–4; `quality`: 0.1–1; `transparent`: booleano. JPEG compone el fondo transparente sobre blanco.
- Hasta 16000 px por lado de salida y 64 megapíxeles acumulados entre todas las páginas del trabajo: `ancho_salida × alto_salida × páginas ≤ 64000000`.
- Recursos de entrada seleccionados: hasta 64000000 bytes en conjunto. Cada archivo de salida también se limita a 64000000 bytes; el proceso Node limita la entrada JSON a 90 MB.
- El proceso tiene límite PHP de 100 s; el render del navegador, 80 s; el job, 120 s y un intento. Reduce páginas, escala o recursos si falla por capacidad o tiempo.

MP4 y GIF no están implementados como formatos de exportación. Los elementos de vídeo disponibles en el documento/editor no convierten esta entrega en un exportador de animación ni garantizan una pista de audio en los cinco formatos estáticos. Las extensiones futuras se siguen en el [roadmap de la suite](../roadmap/2026-11-03-creative-social-suite/tasks.md).

## MCP: nueve herramientas y flujo completo

El servidor anuncia 36 herramientas en total. `tools/list` devuelve 30 en la primera página y un `nextCursor`; envía ese valor como `params.cursor` para obtener la siguiente. Acumula todas las páginas hasta que `nextCursor` no exista. Las nueve herramientas de diseño quedan repartidas entre páginas; `get_stats` sigue siendo la herramienta de métricas existente.

```bash
curl --fail "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json, text/event-stream' -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

La siguiente llamada tiene la forma `{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{"cursor":"VALOR_EXACTO_DE_nextCursor"}}`. El cursor es opaco: no lo construyas a mano. Los clientes MCP compatibles realizan también la negociación `initialize` descrita en la [guía del módulo MCP](../modulos/mcp.md).

| Herramienta | Argumentos y resultado |
|---|---|
| `list_designs` | `brand` o `brand_id`; opcionales `query`, `folder_id`, `templates`, `archived`, `page`. Resúmenes paginados de 24. `designs:read`. |
| `get_design` | `design_id`, `exports_page` opcional. Documento completo, revisión, kit, recursos con `file_url` e historial `exports` de 50 por página con `exports_pagination`. `designs:read`. |
| `create_design` | Marca, `name`, `document`, opcionales `folder_id`/`is_template`. Diseño con revisión 1. `designs:write`. |
| `update_design` | `design_id`, `name`, `document`, `revision`, opcionales `folder_id`/`is_template`. Nueva revisión o conflicto 409. `designs:write`. |
| `export_design` | `design_id`, `revision`, `format`, `pages`, opcionales `scale`/`quality`/`transparent`. Trabajo real; consultar `get_design` para seguimiento. `designs:write`. |
| `manage_brand_kit` | Marca; `kit` omitido consulta y, si está presente, actualiza sus campos. Requiere `designs:write` en ambos casos; `get_design` permite leer el kit con `designs:read`. |
| `attach_design_asset` | Marca y `media_id` propio listo. Registra el recurso y devuelve sus metadatos. `designs:write`. |
| `manage_design` | `design_id`, `action: duplicate\|archive\|restore`; `name` opcional para duplicar. Copia independiente o archivado/restauración conservando snapshots y exportaciones. `designs:write`. |
| `manage_design_folder` | Marca y `action: list\|create\|delete`; `name` obligatorio al crear, `folder_id` al eliminar. `list` requiere `designs:read`; crear/eliminar, `designs:write`. Eliminar conserva los diseños sin asociación. |

Una llamada a una herramienta usa el mismo transporte JSON-RPC:

```bash
curl --fail "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json, text/event-stream' -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_designs","arguments":{"brand_id":12,"page":1}}}'
```

La respuesta de la herramienta está en `result.content[].text` como JSON serializado; revisa también `result.isError`. Un HTTP `200` de MCP puede contener un error de herramienta. No trates ese caso como un guardado correcto.

Flujo reproducible de las nueve herramientas, adaptando los IDs a cada respuesta:

1. `list_designs({"brand_id":12,"page":1})` para consultar los diseños existentes; continúa con `page=2` si corresponde.
2. `manage_brand_kit({"brand_id":12,"kit":{"palette":["#7c3aed","#ffffff"],"fonts":["Arial"],"voice":"Clara"}})` para preparar la marca. Repetir sin `kit` consulta el resultado.
3. Si necesitas un recurso nuevo, usa la herramienta previa `import_media_from_url({"url":"https://tu-dominio.example/foto.png"})` con una URL pública válida. Consulta `list_media({"status":"pendiente"})` hasta que el archivo pase a `listo`; `list_media({"status":"invalido"})` da el motivo de fallo. La importación requiere `posts:write`; `list_media`, `brands:read`.
4. `attach_design_asset({"brand_id":12,"media_id":701})`. Utiliza el ID real devuelto en un elemento `image`/`video` del documento, no la URL de importación.
5. `create_design({"brand_id":12,"name":"Campaña","document":DOCUMENTO_V1})` con el objeto completo del ejemplo anterior. Para un logo, registra primero la imagen y actualiza después `kit.logos` con su ID.
6. `get_design({"design_id":42})`. Conserva el documento y la revisión devueltos; modifica los elementos deseados.
7. `update_design({"design_id":42,"name":"Campaña revisada","revision":1,"document":DOCUMENTO_EDITADO})`. Si devuelve 409, vuelve al paso 6 y resuelve el conflicto. Usa la nueva revisión devuelta si el guardado pasa.
8. `export_design({"design_id":42,"revision":2,"format":"png","pages":["page_1"],"scale":1})`. Consulta periódicamente `get_design({"design_id":42})` y localiza el trabajo por su ID en `exports` hasta `ready`/`failed`. Descarga `download_url` únicamente cuando exista; no repitas `export_design` para hacer polling.
9. `manage_design_folder({"brand_id":12,"action":"create","name":"Lanzamientos"})` devuelve el objeto de carpeta con su `id`. Consulta con `{"brand_id":12,"action":"list"}` y utiliza ese ID en `folder_id` de `create_design`/`update_design`; `list_designs` también puede filtrar por carpeta. `manage_design_folder({"brand_id":12,"action":"delete","folder_id":10})` solo acepta una carpeta de esa misma marca y conserva sus diseños sin asociación.
10. `manage_design({"design_id":42,"action":"duplicate","name":"Variante"})` devuelve una copia con ID nuevo y revisión 1. `manage_design({"design_id":42,"action":"archive"})` archiva el original; `manage_design({"design_id":42,"action":"restore"})` lo restaura conservando sus revisiones y exportaciones. La restauración solo admite recursos del usuario, aunque estén archivados. Para volver a verlos antes de restaurar, usa `list_designs({"brand_id":12,"archived":true})`.

Para continuar hacia publicación, PNG/JPEG/WebP aportan `exports[].media_ids`. La herramienta existente `create_post` consume esos IDs y valida los destinos; `schedule:false` crea un borrador. Los servicios de diseño/exportación no programan ni publican por sí solos.

## Métricas por API

`from` y `to` son fechas inclusivas `YYYY-MM-DD` obligatorias. El periodo admite hasta 366 días. `network` filtra una red; `compare` admite `none`, `previous` o `year`. Sin `network` se devuelve el resumen de marca. Se mantienen los valores `null` para métricas no disponibles y la información de cobertura del mismo servicio que utiliza el dashboard; no conviertas `null` a cero.

```bash
curl --fail --get "$BASE/api/v1/brands/$BRAND_ID/analytics" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'from=2026-10-01' --data-urlencode 'to=2026-10-31' \
  --data-urlencode 'network=instagram' --data-urlencode 'compare=previous'
```

En MCP utiliza `get_stats`, sin una segunda herramienta de métricas de Studio.

## Despliegue del renderer

Además del despliegue Laravel habitual y sus migraciones, el servidor de workers necesita Node, las dependencias JavaScript de ejecución, Chromium y las bibliotecas del sistema que este requiere. `playwright`, `fabric` y `pdf-lib` son dependencias de ejecución. No elimines `playwright` ni sus navegadores al limpiar dependencias de desarrollo.

Desde la raíz del proyecto, durante el despliegue:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci --include=dev
npm run build
# Linux: ejecutar con los permisos de administración que requiera la instalación de paquetes del sistema.
npx playwright install --with-deps chromium
php artisan migrate --force
php artisan queue:restart
```

`npm run build` ejecuta Vite y `scripts/studio/build.mjs`; debe generar `public/build/studio-renderer.js`, además de los assets de la aplicación. Conserva los paquetes de ejecución de `node_modules` en el host del worker: el proceso Node los importa en tiempo de render. Si haces `npm prune --omit=dev` después del build, comprueba que se mantienen las dependencias de producción y que el comando de Chromium usa el Playwright de ejecución del proyecto. Instala Chromium para el mismo usuario/cache que ejecutará el worker, o configura una ruta de navegador disponible para ese usuario.

El job `App\Jobs\Studio\RenderStudioDesign` utiliza la cola `media`; un worker que solo escucha `publish,poll,metrics,reports` no lo procesará. Ejemplo con la conexión `database` del proyecto:

```bash
php artisan queue:work database --queue=media --timeout=120 --tries=1
# O añadir media al worker que atiende el resto de colas:
php artisan queue:work database --queue=publish,poll,metrics,reports,media --timeout=120
```

Configura ese proceso en el gestor de servicios del servidor y mantenlo activo. El `retry_after` o tiempo de visibilidad del backend de cola debe superar el timeout de 120 s; la configuración `database` usa 180 s por defecto. No ejecutes ambos ejemplos sobre el mismo worker como si fueran pasos consecutivos: son alternativas de distribución de colas.

| Variable/requisito | Uso |
|---|---|
| `STUDIO_NODE_BINARY` | Ejecutable Node, por defecto `node`; se puede indicar una ruta absoluta. |
| `STUDIO_CHROMIUM_PATH` | Ruta opcional del ejecutable Chromium; si falta, Playwright usa su navegador instalado. |
| `PLAYWRIGHT_BROWSERS_PATH` | Variable de Playwright si compartes una ubicación de navegadores entre instalación y worker. Debe ser coherente en ambos procesos. |
| Disco Laravel `local` | Guarda los artefactos privados de descarga en `studio-exports/...`; debe ser escribible y accesible por worker y proceso web. |
| `MEDIA_DISK` | Almacenamiento de la media generada, `local` o R2 según la configuración existente. |
| PHP `ZipArchive` | Necesario para agrupar varias páginas de PNG/JPEG/WebP/SVG en ZIP. |
| Fuentes del sistema | Determinan la tipografía disponible en el Chromium del worker. |

El renderer abre un navegador aislado con tráfico de red bloqueado. PHP lee los recursos propios desde Storage y entrega sus bytes al proceso; el navegador no visita URLs de usuarios. No necesita un servidor E2E ni un puerto de aplicación auxiliar para renderizar.

## Diagnóstico de errores

| Estado/respuesta | Comprobación |
|---|---|
| HTTP `401` | Bearer ausente, inválido, revocado o caducado; una sesión web no sustituye la API key. |
| HTTP `403` | Ability ausente; en descarga firmada, firma incorrecta o vencida. |
| HTTP `404` | Recurso inexistente o ajeno; comprobar también marca y registro del asset. |
| HTTP `409` | Recargar y resolver el conflicto de revisión. |
| HTTP `422` | Revisar `errors`: documento tipado, recurso no listo, formato, páginas, dimensiones o periodo de métricas. |
| HTTP `429` | Respetar `Retry-After`; las rutas API usan el throttle configurado de 120 solicitudes/minuto. MCP tiene sus límites propios. |
| Exportación `pending` persistente | Comprobar el worker de `media` y su conexión de cola. |
| Exportación `failed` | Leer `error`; comprobar `studio-renderer.js`, Node, Playwright/Chromium, permisos de Storage, fuentes y límites. Corregir la causa y solicitar un trabajo nuevo. |
| Tipografía distinta | Comparar las fuentes realmente instaladas en cliente y worker. |
| Descarga caducada | Obtener otra `download_url` consultando el estado autenticado. |

La guía no ejecuta instalaciones, migraciones ni pruebas contra el servidor. Para los requisitos generales de PHP, colas, almacenamiento y proxy, sigue el [despliegue del proyecto](../modulos/despliegue.md).
