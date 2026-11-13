# Media por destino (`targets[].media`)

Cada red de una publicación puede llevar su propia media, en su propio orden y con su texto alternativo y portada. Funciona igual en el compositor, la API REST y el MCP.

## Modelo

| Campo | Significado |
|---|---|
| `media` (de la publicación) | Media común: lista de ids de la biblioteca (hasta 35) |
| `targets[].media` ausente o `null` | El destino **hereda** la media común |
| `targets[].media` = lista de `{id, alt, cover_id}` | Media **propia** del destino, en ese orden (puede ser vacía) |

`App\Support\TargetMedia` es la única función que resuelve la media efectiva; la leen `PostService`, `PublishRequestFactory` y `PostPresenter`. La respuesta de la API incluye por destino `media` (lo guardado) y `effective_media` (lo que se publica).

## Almacenamiento y borrado

La media propia se guarda en la tabla relacional `post_target_media` (`post_target_id`, `media_asset_id`, `cover_id`, `alt`, `position`), con clave foránea `restrict` hacia `media_assets` tanto para el archivo como para la portada; `post_targets.has_own_media` distingue «hereda» (`false`) de «lista propia, también vacía» (`true`). `PostTarget::$media` conserva su forma pública (`null` o lista de `{id, alt, cover_id}`), así que la API, el MCP y las props del compositor no cambian. La escritura sustituye la lista completa al guardar el destino.

Borrar un archivo usado por un destino sin publicar, por una portada, por una serie activa o por una autolista falla con «El archivo se usa en una publicación»; la comprobación es una consulta indexada (`MediaAsset::usedByPosts()`), no un recorrido de filas, y la FK lo garantiza aunque se salte el servicio. Las referencias de destinos ya publicados se sueltan al borrar (el historial no necesita el archivo). Las series (`post_series.payload`) y los elementos de autolista (`autolist_items.media`) siguen guardando su contenido en JSON, con un índice relacional derivado (`post_series_media`, `autolist_item_media`) mantenido por sus modelos. `studio_design_exports.media_ids` queda en JSON, pero el borrado lo respeta: un export `ready` de los últimos `STUDIO_EXPORT_MEDIA_GUARD_DAYS` días (30 por defecto, 0 = no bloquea) cuenta como uso (consulta filtrada por usuario, estado y fecha); los exports más antiguos no impiden borrar. Series y autolistas validan en el guardado que la media exista y sea del usuario (error de validación, no un 500), con el elemento y su índice en una sola transacción. Al borrar una cuenta, `User` vacía antes explícitamente `post_media` y los tres índices para no depender del orden de las cascadas de InnoDB.

```json
{
  "brand_id": 7, "schedule": false, "text": "Novedades",
  "media": [11, 12],
  "targets": [
    { "account_id": 3, "content_type": "instagram_carousel" },
    { "account_id": 9, "content_type": "bluesky_post",
      "media": [{ "id": 13, "alt": "Equipo en la oficina" }, { "id": 11 }] }
  ]
}
```

## Validación

`PostService::plan()` valida la media **efectiva** de cada destino con `MediaValidator` (tipos, número, duración, tamaño) y el error nombra el destino. También rechaza archivos ajenos, inexistentes o repetidos, `alt` en vídeo o en redes que no lo publican (hasta 1000 caracteres) y `cover_id` que no sea una imagen lista de un vídeo. Cambiar la media de un destino reinicia solo ese destino.

## Qué red admite qué

| Dato | Redes |
|---|---|
| Texto alternativo (`alt`) | Instagram (imagen y carrusel), Facebook (foto y varias fotos), Threads (imagen y carrusel), Bluesky, LinkedIn (imagen y varias imágenes) y Pinterest (pin de imagen) |
| Portada (`cover_id`) | Reel de Instagram, YouTube (miniatura de hasta 2 MB), vídeo de Pinterest y vídeo de LinkedIn (T-34) |

El vídeo de Pinterest (hasta 128 MB) se sube en streaming desde el disco de media; se rechaza al programar si pesa más.

Las historias (también las de Facebook), TikTok y el vídeo de Facebook rechazan el `alt` con un error en español. La portada de YouTube es de mejor esfuerzo (no se reintenta). La media propia no se guarda en las plantillas de la biblioteca.

## API y MCP

`POST /api/v1/brands/{brand}/posts`, `GET/PUT/PATCH /api/v1/posts/{post}` (abilities `posts:read|write`) y las herramientas `create_post`, `update_post` y `get_post` usan el mismo `PostRequest`. Contrato en el [OpenAPI](../api/openapi.yaml). El compositor muestra una previsualización por red de lo que se publicará. Ver también [publicación](publicacion.md) y [media](media.md).
