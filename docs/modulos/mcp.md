# Servidor MCP y API keys

Servidor MCP por HTTP para que un agente (p. ej. Claude) programe y consulte publicaciones. Usa `laravel/mcp` ~1.0.2 y Sanctum.

## Piezas

| Pieza | Ruta |
|---|---|
| Registro de la ruta | `routes/ai.php` |
| Servidor | `app/Mcp/Servers/SchedulerServer.php` |
| Herramientas | `app/Mcp/Tools/*.php` (base: `SchedulerTool.php`) |
| Permisos | `app/Mcp/Abilities.php` |
| API keys (web) | `ApiKeyController`, `resources/js/Pages/ApiKeys/Index.vue` |
| Servidor OAuth | `app/Http/Controllers/Mcp/OAuth/`, `app/Services/Mcp/OAuth/`, `resources/js/Pages/OAuth/Authorize.vue` |
| Auditoría | `app/Services/AuditLog.php`, tabla `audit_logs` |

```php
Mcp::web('/mcp', SchedulerServer::class)->middleware(['auth:sanctum', 'throttle:mcp']);
```

## Autenticación

El Bearer es una API key personal con prefijo `msk_`, creada a mano en **API keys** o emitida por el flujo OAuth de la sección siguiente. Sanctum corre con `guard=[]`: una sesión web **no** autentica `/mcp`. La key se muestra una sola vez al crearla; en la base solo queda el hash. Caduca a los 365 días por defecto (`MCP_KEY_DEFAULT_DAYS`) con tope de 730 (`MCP_KEY_MAX_DAYS`). Crear y revocar quedan en `audit_logs` sin el secreto.

Límites: `throttle:mcp-ip` (120/min, antes de auth, frena la adivinación de keys) y `throttle:mcp` (60/min por key).

## Autorización OAuth 2.1 (sin API key)

Además de las API keys, la app es un servidor de autorización OAuth 2.1 propio ([ADR-004](../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)): un cliente MCP se autoriza con el navegador, sin que el usuario pegue ninguna clave. Sanctum sigue verificando: el token emitido es un `msk_` más, con las mismas abilities.

| Qué | Dónde |
|---|---|
| Metadatos del recurso protegido (RFC 9728) | `GET /.well-known/oauth-protected-resource` y `/.well-known/oauth-protected-resource/mcp` |
| Metadatos del servidor de autorización (RFC 8414) | `GET /.well-known/oauth-authorization-server` (y `/mcp`) |
| Registro dinámico de clientes (RFC 7591) | `POST /oauth/register` (10/h por IP y tope global de 200/h, `MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR`) |
| Consentimiento | `GET/POST /oauth/authorize` (tras iniciar sesión, 2FA incluido) |
| Canje del código | `POST /oauth/token` (30/min por IP) |

- Solo `authorization_code` con PKCE `S256`, clientes públicos (`token_endpoint_auth_method=none`) y sin refresh token. La redirección debe ser loopback (`localhost`, `127.0.0.1`, `[::1]`), un callback de Claude o un prefijo `https://` de `MCP_OAUTH_REDIRECT_ALLOWED`.
- El consentimiento propone solo lectura por defecto y ofrece tres conjuntos (*Solo lectura*, *Publicar*, *Todo*); las casillas se pueden editar. El nombre del cliente lo declara la propia aplicación y no está verificado.
- La duración del acceso la elige el usuario en el consentimiento («Duración del acceso»): 30 días, 90 días (por defecto), 6 meses o 1 año. Solo cuenta lo elegido en esa pantalla (el cliente no puede fijarlo por la URL) y un valor fuera de la lista se rechaza. La lista se recorta a `MCP_KEY_MAX_DAYS` y `MCP_OAUTH_TOKEN_DAYS` (90) marca la opción preseleccionada (la más cercana por debajo si no está en la lista). El token aparece en **API keys** con origen «OAuth · <cliente>»; se revoca desde ahí. Reautorizar el mismo cliente sustituye al token anterior; reutilizar un código ya canjeado revoca el token que emitió.
- **401 con `resource_metadata`:** toda respuesta 401 de `/mcp` (sin token, caducado o revocado) lleva `WWW-Authenticate: Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/mcp"`. Un cliente compatible lo usa para descubrir el servidor de autorización y repetir la autorización por sí solo. Una API key manual caducada recibe el mismo 401: el usuario debe crear otra key o conectar el cliente por OAuth.
- `McpGuard` no cambia: un `Origin` no permitido sigue dando 403 en `/mcp`; `/oauth/*` y `/.well-known/*` responden sin `Origin`.

## Conectar un cliente

Sustituye `<APP_URL>` por la URL pública de la app **con esquema**, p. ej. `https://midominio.com` (así, `<APP_URL>/mcp` es `https://midominio.com/mcp`).

> **Estado de verificación:** los comandos de OAuth de los cuatro clientes están **pendientes del smoke manual con los clientes reales** (T-11; M-03 a M-06 del plan de pruebas). Se indican tal como se preparó esa prueba y hay que contrastarlos con la versión instalada de cada cliente antes de darlos por buenos. Lo que sí está probado de punta a punta (Pest y Playwright) es el flujo del servidor: registro, autorización con PKCE, token, uso en `/mcp`, revocación y coexistencia con las API keys.

### Con OAuth (recomendado: sin API key)

El cliente se configura **sin** cabecera `Authorization`. La primera vez abre el navegador: inicia sesión (y el reto 2FA si lo tienes), revisa los permisos y pulsa *Autorizar*.

**Claude Code** (CLI), pendiente de verificar (M-03):

```bash
claude mcp add --transport http social-scheduler <APP_URL>/mcp
# dentro de `claude`: /mcp -> social-scheduler -> Authenticate
```

**Claude Desktop**, pendiente de verificar (M-04): *Ajustes → Connectors → Add custom connector*, nombre `social-scheduler`, URL `<APP_URL>/mcp`, *Connect*. El callback es de Claude (`https://claude.ai/api/mcp/auth_callback`, o `claude.com`). El servidor debe ser accesible desde Internet y el plan de Claude debe admitir conectores remotos; `claude_desktop_config.json` solo sirve para servidores locales (stdio).

**Codex**, pendiente de verificar (M-05):

```bash
codex mcp add social-scheduler --url <APP_URL>/mcp
codex mcp login social-scheduler
```

Si la versión pide activar el cliente MCP remoto, habilítalo en su configuración (`rmcp_client`).

**OpenCode**, pendiente de verificar (M-06):

```bash
opencode mcp add social-scheduler --url <APP_URL>/mcp --global
opencode mcp auth social-scheduler
```

Si no inicia el flujo OAuth, añade `"oauth": {}` a la entrada remota del servidor en `opencode.json`.

Si un cliente es rechazado en el registro (`invalid_redirect_uri`), añade el prefijo `https://` de su callback a `MCP_OAUTH_REDIRECT_ALLOWED`.

### Con API key (alternativa)

Las API keys `msk_` siguen funcionando igual (se crean en **API keys**):

```bash
claude mcp add --transport http scheduler <APP_URL>/mcp --header "Authorization: Bearer msk_..."
```

Nginx/Hestia debe pasar la cabecera `Authorization` a PHP-FPM y dejar llegar `/.well-known/oauth-*` a PHP: ver [despliegue](despliegue.md#oauth-del-mcp-nginxhestia-y-redirect-uris). `MCP_ALLOWED_ORIGINS` añade orígenes de navegador permitidos; sin cabecera `Origin` siempre se permite.

## Abilities

| Ability | Permite |
|---|---|
| `brands:read` | marcas, cuentas conectadas y media |
| `posts:read` | publicaciones, estado y calendario |
| `posts:write` | crear, programar, editar, cancelar y borrar |
| `stats:read` | estadísticas de marcas, redes y publicaciones |
| `reports:write` | generar y descargar informes |

`*` también vale. Si falta el permiso, la herramienta devuelve error y registra el intento `DENIED` en la auditoría.

## Herramientas (21)

`list_brands`, `list_accounts`, `list_media`, `import_media_from_url`, `list_campaigns`, `get_calendar`, `get_post`, `create_post`, `update_post`, `update_post_notes`, `reschedule_post`, `publish_post_now`, `cancel_post`, `delete_post`, `get_stats`, `get_post_stats`, `get_best_times`, `generate_report`, `get_report`, `list_comments`, `reply_comment`.

`publish_post_now` (`post_id`, ability `posts:write`) publica ya un borrador o una publicación programada: valida como al programar (sin exigir fecha futura), pone la fecha del servidor y encola tras el commit con las mismas defensas contra el doble despacho que la web (ver [publicación](publicacion.md)). Una publicación en vuelo o publicada se rechaza con el mismo error que `update_post`; una de otro usuario responde como inexistente. También `create_post` y `update_post` aceptan `publish_now: true` (sin `scheduled_at`).

`import_media_from_url` (`url`, ability `posts:write`) importa una imagen (JPG, PNG o WebP) o un vídeo (MP4 o MOV) desde una URL pública y devuelve el asset en estado `pendiente` con su progreso (`bytes` y `total`). Usa la misma guardia SSRF que la web (`UrlGuard`): solo `http`/`https` en los puertos 80 y 443, sin credenciales, y se rechazan las direcciones internas, de bucle, de metadatos y las redirecciones hacia ellas. La descarga ocurre en segundo plano (cola `media`); `list_media` solo lista el asset cuando está `listo`. Máximo 3 importaciones en curso por usuario.

Notas internas: `get_post`, `create_post` y `update_post` devuelven `notes` (texto plano, o `null`). `create_post` y `update_post` aceptan `notes` (hasta 5.000 caracteres; omitido = no se toca, vacío = se borra). `update_post_notes` (`post_id`, `notes`, ability `posts:write`) edita solo las notas y funciona aunque la publicación ya esté publicada o en vuelo, porque las notas nunca se publican: no toca los destinos ni reinicia ningún intento. Una publicación de otro usuario responde como inexistente y la auditoría registra la herramienta y la publicación, nunca el contenido de las notas. Con 21 herramientas se mantiene el límite de 25 por página de `tools/list`.

Campañas (C-03): `create_post` y `update_post` aceptan `campaign_id` (omitido = no se toca, `null` = se quita) y `get_post` devuelve `campaign_id` y `campaign` (nombre). La campaña debe ser de la misma marca y del usuario; si no, el error es `campaign_id` como en la web. `list_campaigns` (`brand_id`, ability `brands:read`) lista las campañas de la marca, las activas primero.

Texto por destino: cada elemento de `targets` de `create_post` y `update_post` acepta `text`. Omitido o `null` = el destino está vinculado a la plantilla (el `text` de la publicación); una cadena con contenido es su texto propio, que solo se publica en esa red. Un texto vacío o solo con espacios cuenta como omitido (vinculado), igual que en la web. `get_post`, `create_post` y `update_post` devuelven, por destino, `text` (`null` = vinculado) y `effective_text` (lo que se publica). Se validan con las mismas reglas que la web (`PostRequest` y los límites de caracteres y hashtags de cada red sobre el texto efectivo). `update_post` sustituye los destinos: un destino sin `text` vuelve a estar vinculado.

Opciones por destino: cada elemento de `targets` acepta `options` con las mismas reglas que la web. YouTube: `category_id`, `tags` (se recortan a 500 caracteres y el marcador de idempotencia siempre se conserva), `made_for_kids`, `notify_subscribers`, `contains_synthetic_media`, `title`, `privacy` y `shorts_hashtag`. TikTok: `privacy_level`, `disable_comment` y, solo si la cuenta publica en directo, `disable_duet` y `disable_stitch` (solo vídeo), `brand_organic_toggle`, `brand_content_toggle` (no admite visibilidad privada) e `is_aigc`. Una opción que la red o la cuenta no admite da un error de validación del destino, igual que en la web. `get_post`, `create_post` y `update_post` devuelven las `options` de cada destino. El MCP **no** escribe los interruptores recordados por marca y red (los presets): solo los guarda el compositor web.

Paridad web y MCP (compositor nuevo):

| Función | Web (compositor) | MCP |
|---|---|---|
| Texto común y texto propio por red («Editar por red») | `text` de la publicación y de cada destino | `text` en `create_post`/`update_post` y en cada elemento de `targets`; se leen `text` y `effective_text` |
| Opciones por red (YouTube, TikTok) | panel de opciones de cada red | `options` por destino, con las mismas reglas |
| Opciones recordadas por marca y red (presets) | se guardan al programar o guardar el borrador | no se escriben ni se leen: solo los guarda el compositor web |
| Notas internas | campo de notas del compositor | `notes` en `create_post`/`update_post` y `update_post_notes` |
| Publicar ahora | menú de la barra de acciones, calendario y lista | `publish_post_now` y `publish_now: true` |
| Generador de enlaces con UTM | diálogo del compositor (solo en el navegador, no guarda nada en el servidor) | sin herramienta: el agente escribe la URL ya con sus parámetros en el texto |
| Biblioteca (guardar como plantilla y «Usar») | calendario, lista y `/library` | sin herramienta |

Regresión: `tests/Feature/Mcp/Tools/` cubre cada herramienta de posts frente a las fases 1-5 (`McpToolsTest`, `McpTargetTextTest`, `McpOptionsTest`, `McpNotesTest`, `PublishPostNowToolTest`, `McpImportMediaTest`), incluido un ciclo completo `create_post` → `update_post_notes` → `publish_post_now` → `get_post` y que `tools/list` sigue ofreciendo las 21 herramientas.

Flujo recomendado (de las instrucciones del servidor): `list_brands` → `list_accounts` (cuentas y tipos válidos) → `list_media` → `list_campaigns` (opcional) → `create_post`. `get_report` devuelve una URL firmada de 5 min.

Aislamiento: cada herramienta ejecuta sobre los servicios con el usuario del token (key u OAuth); un recurso ajeno responde "no encontrado" (`NotOwned`). Pruebas en `tests/Feature/Mcp/` (incluido `tests/Feature/Mcp/OAuth/`), `tests/Feature/Isolation/` y la batería E2E `tests/e2e/oauth.spec.ts`.
