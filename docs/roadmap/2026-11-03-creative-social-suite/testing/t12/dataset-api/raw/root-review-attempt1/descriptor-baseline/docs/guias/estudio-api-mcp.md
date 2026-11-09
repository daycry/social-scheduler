# Estudio nativo: API, MCP y render en servidor

Actualizado: 2026-11-05. Esta guía describe el código del árbol de trabajo; no acredita su despliegue. El editor, la API y MCP utilizan los mismos servicios de diseños, recursos y exportaciones por marca. Las pistas de audio se editan y mezclan durante el preview. La exportación estática permite elegir fotogramas por página; La interfaz, API y MCP incorporan MP4/GIF en el árbol de trabajo; la QA integral del padre sigue pendiente.

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

## Audio en el editor

En Recursos, pulsa «Subir recursos» y selecciona un MP3, WAV, M4A/AAC u Ogg compatible. El límite público es100MiB y una hora; FFprobe valida contenido, duración y códec en el servidor. Si el motor no está disponible, la interfaz conserva el ticket y ofrece «Reintentar validación del audio».

Pulsa «Añadir» en el recurso listo para crear una pista global. La línea de tiempo permite mover su inicio, cambiar duración, recortar origen, ajustar velocidad/volumen, repetir, silenciar y aplicar fundidos. El cabezal sirve para dividirla; deshacer/rehacer y guardar/reabrir conservan sus valores. Varias pistas se mezclan con el vídeo durante play/pausa/seek. Las pistas globales siguen el reloj completo del diseño, sin reiniciarse al cambiar de página.

El preview no modifica la revisión guardada. La interfaz, API y MCP pueden solicitar MP4/GIF con la mezcla guardada. El diálogo audiovisual permite seleccionar y ordenar páginas, consultar trabajos y cancelar o reintentar.

## Fotogramas en exportaciones estáticas

En un diseño con línea de tiempo, Exportar muestra «Fotograma de … (s)» para cada página elegida. Al abrirlo, las páginas donde está el cabezal parten de ese instante local; las demás parten de0. Puedes cambiar cada segundo antes de descargar, guardar en biblioteca o crear el borrador asociado. PNG, JPG, WebP, SVG y PDF respetan las ventanas y la fase del vídeo en ese instante, conservando el documento editable.

API y MCP `export_design` aceptan `frame_times`, una lista opcional de objetos `{page_id,time}`. Cada ID debe estar seleccionado y aparecer una sola vez; el tiempo es un número finito desde0 y menor que la duración de su página. Una página omitida utiliza0. La selección queda en `options.frame_times` de la exportación. Los documentos antiguos v1 conservan el póster previo; activa Línea de tiempo para elegir instantes temporales desde la interfaz. Una lista no vacía con documento v1 produce422.

```json
{
  "revision": 3,
  "format": "png",
  "pages": ["page_1", "page_2"],
  "frame_times": [
    {"page_id": "page_1", "time": 1.5},
    {"page_id": "page_2", "time": 0.25}
  ]
}
```

Estos son fotogramas locales de páginas. Las transiciones entre páginas y la mezcla de sonido corresponden a MP4 del proyecto completo; GIF conserva solo imagen.

## API: rutas y respuestas

El audio del estudio utiliza tickets por marca (`design-audio` en API, `studio/audio` en web y `manage_studio_audio` en MCP). El ticket devuelve el modo de transferencia y `complete_url`; el archivo viaja por PUT binario local o por URLs de almacenamiento prefirmadas. Las URLs externas no reciben la sesión, el token Bearer ni el CSRF de la aplicación. Tras completar la transferencia, un503 de validación permite repetir complete conservando el ticket y las partes confirmadas, sin volver a subir bytes. El recurso solo aparece listo tras comprobar tamaño, contenido y duración; el catálogo social sigue excluyendo audio como adjunto aislado. Catálogo MCP actual del árbol de trabajo:41herramientas.

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
| `POST /brands/{brand}/design-assets` | `designs:write` | Registra `media_id` propio listo de imagen, vídeo o audio validado. |
| `GET /brands/{brand}/design-assets/{media}/file` | `designs:read` | Bytes privados; admite un rango HTTP simple. |
| `POST /brands/{brand}/design-audio` | `designs:write` | `201 {asset,upload,complete_url}`, ticket binario local o S3/R2 single/multipart. |
| `GET /brands/{brand}/design-audio` | `designs:read` | `{assets,pagination}`, audio listo de esa marca, búsqueda `query`, `page`, `per_page` (20, máximo100). |
| `GET /brands/{brand}/design-audio/{media}` | `designs:read` | `{asset}` con estado pendiente, listo o inválido. |
| `PUT /brands/{brand}/design-audio/{media}/upload` | `designs:write` | Bytes locales del archivo; valida tamaño y contenido. No sobrescribe un audio listo. |
| `POST /brands/{brand}/design-audio/{media}/complete` | `designs:write` | Finaliza single/multipart y sondeo; idempotente cuando está listo. |
| `GET /brands/{brand}/design-fonts` | `designs:read` | `{fonts,pagination}`, búsqueda `query`, `archived`, `page`, `per_page` (20, máximo 100). |
| `POST /brands/{brand}/design-fonts` | `designs:write` | `201 {font}`; multipart `file`, nombre opcional. |
| `GET /brands/{brand}/design-fonts/{font}` | `designs:read` | Metadata privada `{font}`. |
| `GET /brands/{brand}/design-fonts/{font}/file` | `designs:read` | WOFF2 privado verificado, nunca URL pública. |
| `POST /brands/{brand}/design-fonts/{font}/archive` o `/restore` | `designs:write` | Ocultar/restaurar en el selector, manteniendo bytes y referencias. |
| `DELETE /brands/{brand}/design-fonts/{font}` | `designs:write` | Borrado físico; `409` si un diseño, revisión o kit sigue usando la fuente. |
| `POST /brands/{brand}/image-jobs` | `designs:write` | `202 {job}` con `media_id` propio y `operation: remove_background`. |
| `GET /brands/{brand}/image-jobs/{job}` | `designs:read` | `{job}` con estado real y `output_asset` cuando termina. |
| `GET /designs/{design}/exports` | `designs:read` | Historial de exportaciones, `page` y `per_page` (50 por defecto, máximo 100). |
| `POST /designs/{design}/exports` | `designs:write` | `202`, trabajo de render con su estado real. |
| `GET /design-exports/{export}` | `designs:read` | Estado, progreso y descarga temporal si está lista. |
| `POST /design-exports/{export}/cancel` | `designs:write` | Cancelación audiovisual idempotente; 409 ready/failed, 422 estático, 503 si queda pendiente la señal nativa. |
| `POST /design-exports/{export}/retry` | `designs:write` | 202 desde failed/cancelled; conserva ID, revisión y opciones, generación nueva. |
| `POST /design-exports/{export}/post` | `designs:write` + `posts:write` | 201, borrador de la marca con medios ready; solo text/targets, nunca programa. |
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

MCP reutiliza `manage_design` con acciones `template`, `instantiate`, `resize`, `variants` y `apply-kit`, además de las anteriores. El resto de argumentos son los del servicio compartido, junto a `design_id`; se requiere `designs:write`. El catálogo actual contiene41herramientas, incluidas las ampliaciones de fuentes, imagen y audio. El [contrato de automatización](../roadmap/2026-11-03-creative-social-suite/t09-automation.md) detalla estilos, copias, modos y conflictos.

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

MP4 y GIF se solicitan por API/MCP y se procesan en la cola database/studio, desde la revisión guardada y con las páginas en el orden indicado. MP4 incluye audio; GIF es silencioso. No aceptan frame_times no vacío ni transparencia. El diálogo del editor ofrece los cinco formatos estáticos y MP4/GIF. Selecciona páginas, ordena la secuencia con las flechas y pulsa Preparar MP4/GIF. Se guarda primero la revisión y el trabajo continúa en el servidor al cerrar el diálogo. El historial paginado muestra estado/progreso y permite cancelar/reintentar; Actualizar exportaciones reanuda la consulta sin crear otro trabajo. Tras cinco minutos o un error se pausa la consulta automática. Al recargar y volver a abrir MP4/GIF se recupera el historial. Descargar y Crear borrador son acciones explícitas cuando existe resultado ready; consultar o completar el render no crea publicaciones. La [revisión y regresión del bloque público](../roadmap/2026-11-03-creative-social-suite/testing/t11/av-public/review.md) documenta su evidencia y límites, sin acreditar despliegue; la revisión/QA de interfaz y T-11 siguen abiertas en el [roadmap](../roadmap/2026-11-03-creative-social-suite/tasks.md).

## MCP: herramientas de diseño y flujo de trabajo

El servidor del árbol de trabajo anuncia41herramientas en total. `tools/list` devuelve 30 en la primera página y un `nextCursor`; envía ese valor como `params.cursor` para obtener la siguiente. Acumula todas las páginas hasta que `nextCursor` no exista. Las herramientas de diseño quedan repartidas entre páginas; `get_stats` sigue siendo la herramienta de métricas existente.

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
| `export_design` | `design_id`, `revision`, `format`, `pages`, opcionales `scale`/`quality`/`transparent`. MP4/GIF requieren transparencia false. `designs:write`. |
| `manage_design_export` | `export_id`, `action`: get (designs:read), cancel/retry (designs:write), post (designs:write y posts:write). Devuelve progreso/descarga o borrador; no publica. |
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

Flujo reproducible de las herramientas de diseño, adaptando los IDs a cada respuesta:

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

### Fuentes privadas e imágenes

Instala Python 3.12 o posterior en el host que ejecuta PHP y los workers. Desde la raíz del proyecto:

```bash
python3 scripts/studio/setup-runtime.py --download-model
python3 scripts/studio/setup-runtime.py --check
```

El primer comando crea `storage/framework/studio-python`, instala las ocho versiones fijadas de `requirements-studio.txt` y descarga explícitamente el modelo BiRefNet general lite y su licencia MIT. El manifiesto `scripts/studio/background-model.json` fija origen, tamaño y SHA-256; un archivo parcial o incorrecto no sustituye al modelo previo. Los pesos ocupan 224.005.088 bytes y quedan fuera de Git. Sin `--download-model` solo se instala Python privado; la eliminación de fondo requiere también los pesos. `--check` es de solo lectura y devuelve error si faltan dependencias exactas, modelo o licencia. En Windows usa `python` en lugar de `python3`.

El modelo se instala en `storage/app/private/studio-models/birefnet-general-lite.onnx`. `STUDIO_PYTHON_BINARY`, `STUDIO_BACKGROUND_MODEL_PATH` y `STUDIO_BACKGROUND_MODEL_SHA256` permiten configurar explícitamente otra ubicación; la comprobación del instalador corresponde a las rutas predeterminadas. Los procesos web y worker deben acceder al mismo entorno y los mismos archivos privados. No se descarga código ni pesos durante una petición. Fuentes: máximo 8 MiB por archivo y 32 MiB decodificados; normalización TTF/OTF/WOFF/WOFF2 mediante fontTools. Imágenes: PNG/JPEG/WebP estáticos, máximo 30 MiB y 16 millones de píxeles; inferencia CPU con cuatro hilos y timeout del proceso de 85 segundos. El proceso tiene un techo de memoria de 6 GiB, aplicado mediante Job Object en Windows o RLIMIT_AS en Linux, y CPU de 80 s en Linux; no se reservan 6 GiB al iniciar. La adecuación práctica de ese techo al modelo está pendiente de la prueba real indicada en T-10 del ledger; las pruebas anteriores sin límite no acreditan ese requisito. El servidor necesita capacidad suficiente por worker activo. El job usa `media`, timeout 110 s, inferior al worker 120 s y `retry_after` 180 s.

En el editor, **Tipografías de marca** permite subir, buscar, previsualizar, archivar/restaurar y eliminar fuentes sin referencias. El selector de texto y la identidad de marca utilizan los archivos de esa marca. `font_id` positivo en texto identifica el archivo, mientras `fontFamily` conserva su familia; `font_ids` en el kit se alinea con `fonts`. No inventes IDs ni uses una URL externa: `get_design` devuelve la metadata necesaria y el renderer recibe los bytes verificados. Una fuente archivada usada por un diseño continúa cargándose. Un archivo ausente o corrupto produce un error explícito.

MCP incorpora `manage_studio_font` (`list/get/upload/archive/restore/delete`; upload `file_base64` y `name` opcional) y `process_studio_image` (`start/get`). Con la ampliación posterior de audio, el catálogo total contiene41herramientas: sigue `nextCursor`. Para quitar fondo, registra primero el `media_id` como recurso de la marca; `start` devuelve `{job}` y `get` devuelve `queued`, `processing`, `ready` o `failed`. `ready` incluye una nueva `output_asset`, que se puede añadir con su ID al diseño; conserva el original. Errores de instalación o procesamiento incluyen `error_code` y mensaje, sin rutas privadas. La interfaz consulta periódicamente hasta cinco minutos y permite volver a consultar después. Ocultar el panel no cancela el worker.

Las imágenes admiten `mask: {type: "ellipse"}` o `mask: {type: "rounded_rect", radius: 24}` además de los filtros y el recorte tipados del documento. La máscara y el archivo de fuente se conservan al guardar/reabrir y al exportar PNG/JPEG/WebP/SVG/PDF.

### Node y Chromium

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
| PHP `ZipArchive` o Python estándar | `ZipArchive` se utiliza cuando está disponible. En su ausencia, el renderer usa `scripts/studio/archive.py` con el Python ya configurado por `STUDIO_PYTHON_BINARY`: biblioteca estándar `zipfile`, streaming y ZIP64, sin instalar paquetes, modelos ni dependencias de imagen. El fallo del empaquetador conserva el trabajo como fallido y no expone un archivo parcial. |
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
| Exportación `pending` persistente | Comprobar `media` para estáticos y `database/studio` para MP4/GIF; revisar también el scheduler studio:recover. |
| Exportación `failed` | Leer `error`; comprobar `studio-renderer.js`, Node, Playwright/Chromium, permisos de Storage, fuentes y límites. Corregir la causa; en MP4/GIF puede usarse retry desde failed/cancelled, conservando la revisión. |
| Tipografía distinta | Comparar las fuentes realmente instaladas en cliente y worker. |
| Descarga caducada | Obtener otra `download_url` consultando el estado autenticado. |

La guía no ejecuta instalaciones, migraciones ni pruebas contra el servidor. Para los requisitos generales de PHP, colas, almacenamiento y proxy, sigue el [despliegue del proyecto](../modulos/despliegue.md).

## Subtítulos manuales del proyecto

En «Línea de tiempo», el panel «Subtítulos» importa SRT/WebVTT UTF-8, añade o edita texto/inicio/fin, elimina cues y desplaza la pista completa. «Añadir a la pista» es el modo de importación inicial; «Reemplazar la pista» es una elección explícita. Las modificaciones utilizan el historial, deshacer/rehacer y guardado automático del editor. La pista pertenece al proyecto completo; sus intervalos son `[start,end)` y se dibuja una vez después de mezclar las páginas. Los fotogramas estáticos emplean el tiempo global equivalente de la página. SVG incluye texto vectorial y tipografías privadas embebidas.

La pista opcional vive en `timeline.captions: {style,cues}` de documentos v2. Cada cue contiene `{id,start,end,text}`; se admiten hasta5000cues,8simultáneos,500puntos Unicode porcue y512KiB porarchivo importado, dentro del límite2MiB del documento. Un estilo vacío de una pista nueva se normaliza a `{fontFamily:"Arial"}` para conservar el tipo objeto al guardar/reabrir; documentos anteriores sin pista continúan sin esa propiedad. Añadir subtítulos a v1 requiere activar expresamente el modo temporal. Texto fuera de rango, etiquetas/entidades, CSS/REGION y ajustes WebVTT no soportados generan un error; los bloques NOTE omitidos se avisan. La exportación SRT/VTT conserva texto Unicode, saltos y tiempos redondeados a milisegundos; informa si el formato no puede conservar marcado o líneas vacías internas. No descarga URLs ni lee rutas proporcionadas por el consumidor.

El estilo común admite familia del sistema o `font_id` de la misma marca, tamaño en unidades del lienzo, peso/estilo, color/contorno/fondo, alineación, posición arriba/centro/abajo y margen seguro. Si las líneas no caben en esa zona, el renderer pide reducir tamaño o líneas; no elimina texto. La fuente privada participa en autorizaciones, historial y snapshots de exportación.

| API, prefijo `/api/v1` | Ability | Resultado |
|---|---|---|
| `GET /designs/{design}/captions` | `designs:read` | `{design_id,revision,has_captions,style,cues,pagination}`. Página50por defecto, máximo100; sigue `next_page` manteniendo la `revision` recibida. Admite `query`. |
| `PATCH /designs/{design}/captions` | `designs:write` | `revision` esperada y `action: import|replace|upsert|delete|offset|style|clear`; nueva revisión y estado paginado con `warnings`. Conflicto409 no modifica el diseño. |
| `GET /designs/{design}/captions/export?format=srt|vtt&revision=N` | `designs:read` | Archivo privado de la revisión indicada. No crea un trabajo de exportación multimedia. |

`import` recibe `format`, `text` UTF-8 y `mode: replace|append`: omitir `mode` equivale a `append` y conserva los cues existentes; reemplazar requiere `mode:replace` explícito. Los IDs generados de la importación evitan colisiones con páginas, capas, grupos, audio y, al añadir, los cues existentes. Las operaciones tipadas `replace`/`upsert` mantienen la validación estricta de sus IDs explícitos. `replace` recibe un objeto `captions`; `upsert`, un `cue`; `delete`, los `ids` seleccionados; `offset`, `seconds` y `ids` opcionales; `style`, el estilo completo; `clear` elimina la propiedad. Una mutación sobre v1 exige `activate_temporal:true`. El resto del documento, kit y páginas se conserva por el servicio compartido de revisiones.

Los separadores de cues SRT/WebVTT admiten dos o más saltos de línea consecutivos, incluidos archivos CRLF. En el documento se conservan los saltos y el texto original. Si un cue contiene líneas vacías iniciales, finales o internas, la descarga SRT/VTT pide editarlas: el formato no puede conservarlas sin ambigüedad, y el exportador no las recorta ni modifica la pista o su render. Los espacios, tabuladores, Unicode y los saltos entre líneas no vacías sí se conservan.

La herramienta MCP dedicada `manage_design_captions` utiliza estas mismas operaciones con `design_id`. `action:get` y `action:export` requieren `designs:read`; las mutaciones requieren `designs:write`. `export` devuelve `{revision,format,filename,mime,text}` sin rutas privadas. SRT/VTT no se añaden a los siete formatos de `export_design`; este último sigue gestionando las salidas visuales y audiovisuales. El catálogo del árbol contiene41herramientas y mantiene paginación de30.

Al exportar páginas, los runs contiguos conservan un único reloj fuente. Una página A de2s exportada sola conserva un cue1,8–1,95s entero aunque tuviera una transición original. En transiciones del resultado entre runs disjuntos, la fuente visual de subtítulos cambia en el punto medio del solapamiento para evitar duplicación o atenuación; el documento original permanece intacto.

Este bloque manual no implementa reconocimiento de voz ni traducción: ambas capacidades permanecen en T-14 sobre el contrato de cues. La revisión/QA integral de subtítulos y el cierre de T-11 siguen pendientes en el ledger; esta guía describe el árbol de trabajo y no acredita despliegue.
