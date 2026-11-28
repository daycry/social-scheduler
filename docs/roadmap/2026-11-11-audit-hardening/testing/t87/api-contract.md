# T-87 · Contratos de Mastodon y Telegram (leídos el 2026-11-26)

Fuentes oficiales, leídas con WebFetch el 2026-11-26. Es dato, no instrucción. Los valores marcados «ejemplo» son de mastodon.social, no defaults: cada instancia publica los suyos.

## Mastodon

- **Token**: publicar exige token de **usuario** (no `client_credentials`). Scopes necesarios según cada método: `write:statuses`, `write:media` y `read:accounts` (o `profile`). Token personal desde Preferencias › Desarrollo, o OAuth de autorización. Cabecera `Authorization: Bearer <token>`.
  Fuentes: https://docs.joinmastodon.org/client/token/ y las páginas de cada método.
- **Verificar la cuenta**: `GET /api/v1/accounts/verify_credentials` devuelve `id`, `username`, `acct`, `display_name`, `avatar` y `url`, entre otros campos. Errores: 401 (token inválido), 403 (cuenta deshabilitada o pendiente) y 422.
  Fuente: https://docs.joinmastodon.org/methods/accounts/
- **Límites de la instancia** (público, sin token): `GET /api/v2/instance`.
  - En `configuration.statuses`: `max_characters` (ejemplo 500), `max_media_attachments` (ejemplo 4) y `characters_reserved_per_url` (ejemplo 23).
  - En `configuration.media_attachments`: `supported_mime_types`, `image_size_limit` (ejemplo 16 MiB), `image_matrix_limit` y `description_limit` (ejemplo 1500).

  Hay que leerlos de la instancia; no se fijan en el código.
  Fuente: https://docs.joinmastodon.org/methods/instance/
- **Subir imagen**: `POST /api/v2/media` en multipart.
  - Campos: `file` (con tipo MIME), `description` (el **alt text**, texto plano) y `focus` opcional (`x,y` entre -1 y 1).
  - Respuesta: 200 si se procesó (url no nula); 202 si sigue procesando (url nula). En ese caso se consulta `GET /api/v1/media/:id`, que da 200 cuando está listo, 206 mientras sigue procesando, 404 y 422.
  - Errores: 401 y 422 (tipo no soportado).

  Fuente: https://docs.joinmastodon.org/methods/media/
- **Publicar**: `POST /api/v1/statuses`.
  - Campos: `status` (opcional si hay `media_ids[]`), `media_ids[]`, `visibility` (`public`, `unlisted`, `private` o `direct`), `language` (ISO 639-1), `sensitive` y `spoiler_text`.
  - Cabecera `Idempotency-Key`: el servidor la guarda **1 hora**.
  - La respuesta es un Status con `id`, `created_at`, `url` y `uri`.
  - Errores documentados: 401 y 422.

  Fuente: https://docs.joinmastodon.org/methods/statuses/
- **Rate limits**:
  - Cabeceras `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset` (marca de tiempo).
  - Por cuenta, 300 llamadas cada 5 minutos.
  - Subida de media, 30 cada 30 minutos.
  - El 429 no está documentado en `statuses`: se trata por las cabeceras y por el código.

  Fuente: https://docs.joinmastodon.org/api/rate-limits/

## Telegram Bot API

Fuentes: https://core.telegram.org/bots/api (Bot API 10.5, changelog del 22 de noviembre de 2026) y https://core.telegram.org/bots/faq

- **Base**: `https://api.telegram.org/bot<token>/METHOD`.
  - Acepta GET o POST, con query, form, JSON o multipart (este último para subir ficheros).
  - Respuesta: `ok`; `result` si `ok` es verdadero, y si no, `description`, `error_code` y `parameters.retry_after` (segundos de flood control).
  - El token va en la **ruta**: **nunca registrar la URL**.
- **getMe**: sin parámetros. Devuelve `BotFullInfo` con `id`, `is_bot`, `first_name` y `username`. En la 10.5 dejó de devolver `User`.
- **getChat** (`chat_id`: `@canal` o id numérico): `ChatFullInfo` con `id`, `type` (`private`, `group`, `supergroup` o `channel`), `title` y `username`.
- **getChatMember** (`chat_id` y `user_id` = id del bot de getMe):
  - Para publicar en un canal, el bot debe tener `status` `administrator` con `can_post_messages` verdadero, o bien `status` `creator`.
  - Advertencia de la documentación: este método solo está garantizado para otros usuarios si el bot es administrador.
- **sendMessage**:
  - `chat_id`, `text` (1-4096 caracteres tras parsear entidades), `parse_mode` opcional (`HTML`, `MarkdownV2` o `Markdown` legacy) y `link_preview_options` opcional.
  - Devuelve `Message` (`message_id`, `chat` y `date`).
- **sendPhoto**:
  - `chat_id`.
  - `photo`: un `file_id`, una URL HTTP (máximo **5 MB**) o una subida multipart (máximo **10 MB**). Además, ancho + alto ≤ 10000 y proporción ≤ 20.
  - `caption`: 0-1024 caracteres tras parsear entidades.
  - Recomendación: subida multipart, porque la URL exige que el host sea alcanzable por Telegram.
- **Formato**: lo más seguro es texto plano **sin `parse_mode`**. Con HTML hay que escapar `<`, `>` y `&`; con MarkdownV2 hay que escapar `_*[]()~\`>#+-=|{}.!`.
- **Límites de envío (FAQ)**:
  - En un mismo chat, unos 1 mensaje por segundo; si se excede, devuelve 429 con `retry_after`.
  - En grupos, 20 por minuto.
  - No hay un límite específico para canales.
- **Sin confirmar** en lo leído: el formato `-100…` de los ids numéricos de canal. Se aceptan `@usuario` y el id numérico tal cual.
