# Media por destino (`targets[].media`)

Cada red de una publicación puede llevar su propia media, en su propio orden y con su texto alternativo y portada. Funciona igual en el compositor, la API REST y el MCP.

## Modelo

| Campo | Significado |
|---|---|
| `media` (de la publicación) | Media común: lista de ids de la biblioteca (hasta 35) |
| `targets[].media` ausente o `null` | El destino **hereda** la media común |
| `targets[].media` = lista de `{id, alt, cover_id}` | Media **propia** del destino, en ese orden (puede ser vacía) |

`App\Support\TargetMedia` es la única función que resuelve la media efectiva; la leen `PostService`, `PublishRequestFactory` y `PostPresenter`. La respuesta de la API incluye por destino `media` (lo guardado) y `effective_media` (lo que se publica).

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
| Texto alternativo (`alt`) | Instagram (imagen y carrusel), Facebook (foto), Threads (imagen y carrusel), Bluesky, LinkedIn (imagen y varias imágenes) y Pinterest (pin de imagen) |
| Portada (`cover_id`) | Reel de Instagram, YouTube (miniatura de hasta 2 MB) y vídeo de Pinterest |

Stories, TikTok y el vídeo de Facebook rechazan el `alt` con un error en español. La portada de YouTube es de mejor esfuerzo (no se reintenta). La media propia no se guarda en las plantillas de la biblioteca.

## API y MCP

`POST /api/v1/posts`, `GET/PUT/PATCH /api/v1/posts/{post}` (abilities `posts:read|write`) y las herramientas `create_post`, `update_post` y `get_post` usan el mismo `PostRequest`. Contrato en el [OpenAPI](../api/creative-suite.openapi.yaml). El compositor muestra una previsualización por red de lo que se publicará. Ver también [publicación](publicacion.md) y [media](media.md).
