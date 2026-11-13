# API v1: núcleo social y estudio

Una sola API REST bajo `/api/v1`, un solo contrato ([OpenAPI](../api/openapi.yaml)) y los mismos servicios que usan la web y el [MCP](../modulos/mcp.md). La autenticación es un `Bearer` con una API key (pantalla **API keys**); cada endpoint exige una *ability* y solo ve los datos del usuario de la key.

## Convenciones comunes

- **La marca va siempre en la ruta**: `/brands/{brand}/…`. Nunca en la query ni en el cuerpo (`POST /brands/{brand}/posts`, `POST /brands/{brand}/series`). Una marca ajena responde 404, igual que una inexistente.
- **Paginación por cursor única** en los listados de posts, media, comentarios y diseños: `limit` (1-100) y `cursor` de entrada; `has_more` y `next_cursor` de salida. Pasa el `next_cursor` recibido como `cursor` hasta que `has_more` sea `false`. No existen `page`, `before_id` ni `total` en estos listados.
- **Errores JSON**: `{"message": "…"}` y, en validación (422), `errors` por campo. 401 sin key, 403 sin la ability (`Falta el permiso …`), 404 recurso ajeno o inexistente, 409 estado que no admite la operación, 429 con el mensaje en castellano y `Retry-After`.
- **Throttle**: 120 peticiones por minuto y key; las acciones sensibles (borrar, cancelar, publicar, responder, informes, importar) tienen un límite propio más bajo.

## Endpoints

| Endpoint | Ability | Notas |
|---|---|---|
| `GET /brands`, `GET /brands/{brand}`, `GET /brands/{brand}/accounts` | `brands:read` | Marcas y cuentas conectadas con sus tipos de contenido; nunca tokens. |
| `GET /brands/{brand}/posts` | `posts:read` | Cursor; filtro `status`. |
| `POST /brands/{brand}/posts` | `posts:write` | Mismo contrato que el compositor y `create_post`. |
| `GET/PUT/PATCH/DELETE /posts/{post}` | `posts:read` / `posts:write` | Borrar solo si no está publicada ni en vuelo. |
| `POST /posts/{post}/cancel`, `POST /posts/{post}/publish` | `posts:write` | Cancelar la programación (vuelve a borrador) y publicar ya (202). |
| `GET /media`, `GET /media/{asset}` | `brands:read` | Cursor; `status` (`listo` por defecto) y `kind` (`image`, `video`, `pdf`; sin él, solo lo publicable). |
| `POST /media`, `PUT /media/{asset}/upload`, `POST /media/{asset}/complete`, `POST /media/import-url`, `DELETE /media/{asset}` | `posts:write` | Subida en dos pasos (con disco local el binario va a `upload.url`) o importación por URL pública. |
| `GET /brands/{brand}/comments` | `inbox:read` | Cursor; filtros `network`, `status`, `label`, `assigned`, `unread`. El texto y el autor son de terceros (`untrusted`). |
| `POST /comments/{comment}/reply` | `inbox:write` | Publica en la red; irreversible. |
| `POST /brands/{brand}/reports`, `GET /reports/{report}` | `reports:write` | Se encola (202) y se consulta; la descarga es una URL firmada que caduca. |
| `GET /brands/{brand}/best-times` | `stats:read` | `network` obligatorio, `days` 7-365. |
| `GET /brands/{brand}/competitors` | `stats:read` | `from`, `to`, `sort`, `network`, `top_posts`. |
| `GET/PUT /brands/{brand}/smart-link`, `GET …/smart-link/stats` | `smartlinks:read` / `smartlinks:write` | Un SmartLink por marca; el `PUT` crea (201) o reemplaza (200). |
| `GET /brands/{brand}/sites`, `GET /sites/{site}`, `POST /sites/{site}/publish`, `POST /sites/{site}/unpublish` | `sites:read` / `sites:write` | Nunca devuelve URLs con token de acceso. |
| `GET /brands/{brand}/designs` y el resto del estudio | `designs:read` / `designs:write` | Ver la [guía del estudio](estudio-api-mcp.md). Cursor con 24 por defecto. |

## Ejemplo: recorrer los posts de una marca

```bash
cursor=""
while :; do
  page=$(curl --fail --get "$BASE/api/v1/brands/$BRAND_ID/posts" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
    --data-urlencode 'limit=50' --data-urlencode "cursor=$cursor")
  echo "$page" | jq -r '.posts[].id'
  [ "$(echo "$page" | jq -r '.has_more')" = true ] || break
  cursor=$(echo "$page" | jq -r '.next_cursor')
done
```

Las pruebas por endpoint (éxito, otro usuario, otra marca, ability ausente y validación) están en `tests/Feature/Api/`.
