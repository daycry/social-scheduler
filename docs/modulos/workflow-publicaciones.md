# Flujo de trabajo de publicaciones: aprobaciones, series, lotes, colaboradores, publicación manual y reintento

Una única capa (`app/Services/PostWorkflowActions.php`) sirve a la web (`/workflow/*`, con sesión), a la API (`/api/v1/*`, con Sanctum) y al MCP. La autorización vive en los servicios: una publicación ajena responde 404 igual que una inexistente. Probado con simulaciones; no se han usado redes reales.

## Aprobaciones

La aprobación es opcional y por publicación (`requires_approval`; `approval_status` nulo = no requiere). Decide el propietario o un **aprobador invitado** de la marca.

| Acción | API | MCP | Ability |
|---|---|---|---|
| Pedir aprobación | `POST /posts/{post}/approval/request` | `request_post_approval` | `posts:write` |
| Bandeja de pendientes | `GET /approvals` | `list_pending_approvals` | `posts:read` |
| Aprobar | `POST /posts/{post}/approval/approve` | `approve_post` | `posts:approve` |
| Rechazar (comentario obligatorio) | `POST /posts/{post}/approval/reject` | `reject_post` | `posts:approve` |

Reglas: pedir aprobación devuelve a borrador una publicación programada; una pendiente o rechazada no se programa ni publica por ninguna vía; aprobar y rechazar son idempotentes; aprobar exige la `signature` (la `content_signature` revisada) y responde 409 si el contenido cambió; editar el contenido de una aprobada la devuelve a pendiente (cambiar solo la fecha no). Las ocurrencias de una serie nacen sin aprobación.

## Colaboradores de marca

Solo el propietario invita aprobadores por email (`POST /brands/{brand}/collaborators`, `manage_brand_collaborators`). Invitar y retirar exigen la ability **`posts:approve`** (quien decide los pendientes de la marca; `posts:write` no basta); listar sigue pidiendo `posts:read`. La respuesta es siempre la misma (202), exista o no la cuenta; hay un máximo de 20 por marca y 10 invitaciones por minuto. El rol único es `aprobador`. Se revoca con `DELETE /brands/{brand}/collaborators/{collaborator}`.

## Publicación manual

Un destino con `manual: true` nunca va a la red: la publicación sigue `programada` («Manual: toca publicar»), el aviso en la aplicación sale una sola vez al vencer y se rearma si cambia la hora, el contenido o el modo. `GET /posts/{post}/manual` devuelve el texto y la media listos para copiar y `POST /posts/{post}/targets/{target}/manual-published` (`mark_post_published`) lo marca como publicado, con `url` opcional que debe empezar por `https://`. No hay correo ni recordatorio repetido.

## Series

Series diaria, semanal o mensual (`/series`, `manage_post_series`): días de la semana ISO, hora `HH:MM` en la zona de la marca, `ends_on` o `max_occurrences`, o abierta. Las ocurrencias son publicaciones normales generadas a 45 días vista (una por serie y fecha local, sin duplicados); editar la serie cambia las futuras no editadas y cancelarla borra las futuras pendientes. Si la generación de una serie falla, la API (`GET /series`) y `manage_post_series` devuelven `last_error` y `last_error_at`, el usuario recibe la notificación en la aplicación `SeriesGenerationFailed` y el error se limpia cuando una generación posterior tiene éxito. La media usada por una serie activa cuenta como en uso y no se puede borrar.

## Lotes con `Idempotency-Key`

`POST /posts/batch` (`batch_posts`) actúa sobre hasta 100 publicaciones: `schedule`, `reschedule` (`shift_days` y `shift_hours`, admiten negativos), `cancel`, `delete`, `approve` y `reject`. Cada elemento se autoriza y se ejecuta aparte y devuelve `ok` o `error` sin detener el lote; un id ajeno e inexistente dan la misma respuesta. Con la cabecera `Idempotency-Key` (o `idempotency_key` en el cuerpo), repetir la petición devuelve la misma respuesta sin volver a ejecutar y la respuesta lleva `Idempotency-Replayed: true`. `approve` y `reject` exigen `posts:approve` y `signatures` (id => `content_signature`).

```bash
curl -X POST "$BASE/api/v1/posts/batch" \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: lote-2026-11-10-a" \
  -H "Content-Type: application/json" \
  -d '{"action":"reschedule","post_ids":[41,42],"shift_days":1}'
```

## Reintento

`POST /posts/{post}/retry` (`retry_post`) reintenta los destinos con error de una publicación propia. Es idempotente: lo que está en curso, programado o publicado no se toca, así que nunca publica dos veces.

## Pantallas

`/approvals` (bandeja y aprobadores por marca), `/posts/{id}/manual`, `/series` y, en la lista del calendario, selección múltiple con barra de lotes, insignias de aprobación, manual y serie, y el botón «Reintentar».

Contrato HTTP en el [OpenAPI](../api/openapi.yaml); abilities en [MCP](mcp.md#abilities); estados de publicación en [publicación](publicacion.md).
