# Conexiones por red

Cada marca (`Brand`) conecta cuentas de Meta (Facebook + Instagram), Google (YouTube), TikTok, Bluesky, Threads, LinkedIn, Pinterest, Mastodon y Telegram. Meta, Google, TikTok, Threads, LinkedIn y Pinterest se conectan por OAuth; Bluesky con handle + app password, Mastodon con la instancia + un token personal y Telegram con el token de un bot + un canal (sin redirección). Los conectores usan el cliente `Http` de Laravel, sin SDK.

## Contrato y catálogo de redes

El contrato está segregado (`app/Connectors/Contracts/`):

- `Connector`: lo común (`provider()`, `networks()`, `refresh()`, `refreshMarginHours()`). `RefreshSocialAccount` solo conoce esto.
- `OAuthConnector extends Connector`: `authorizationUrl()` y `exchangeCode()`. Lo implementan Meta, Google, TikTok, Threads, LinkedIn (`LinkedInConnector`) y Pinterest (`PinterestConnector`). `OAuthController` responde 404 si el proveedor no es un `OAuthConnector`.
- `CredentialConnector extends Connector`: `credentialIntro()` (el texto que abre el formulario), `credentialFields()` y `connectWithCredentials()`. Lo implementan Bluesky, Mastodon y Telegram. Lo usa `CredentialConnectionController`, que valida los campos que declara el conector, y un único formulario (`CredentialsForm.vue`) que pinta esos campos.

`App\Connectors\Network` es el **catálogo**: `label()`, `shortLabel()`, `color()`, `provider()`, `authKind()` (`oauth` o `credentials`), `providers()` y `offered()` (las redes con conector registrado). El shell de Inertia (`shell.networks`), las rutas, la IA y las descripciones del MCP leen de ahí, y el frontend usa `useNetworks()`. Los mapas de datos por red (`Metric::EXPECTED_FLOWS`, `AnalyticsService::SUPPORTED`, reglas de media, zona horaria) se quedan en su sitio y `tests/Unit/Networks/NetworkCoverageTest.php` falla si una red ofrecida no tiene todas sus superficies. Para añadir una red: caso en `Network`, tipos en `ContentType` y `config/media_specs.php`, entradas en `Metric` y `AnalyticsService`, zona en `config/social.php`, glifo en `NetworkIcon.vue` y registro en `AppServiceProvider`.

`social_accounts.credential_secret` (cifrada y oculta) guarda la credencial larga de las redes sin OAuth; se borra al desconectar y nunca sale en `ConnectionCandidate::toPublicArray()`.

## Piezas

| Pieza | Ruta |
|---|---|
| Registro de conectores | `app/Connectors/ConnectorRegistry.php` |
| Contratos | `app/Connectors/Contracts/{Connector,OAuthConnector,CredentialConnector,Publisher,StatusPoller,InsightsReader,RangeInsightsReader}.php` |
| Meta | `app/Connectors/Meta/MetaConnector.php`, `GraphClient.php` |
| Google | `app/Connectors/Google/GoogleConnector.php` |
| TikTok | `app/Connectors/TikTok/TikTokConnector.php` |
| Bluesky | `app/Connectors/Bluesky/{BlueskyConnector,BlueskySession,BlueskyClient,BlueskyPublisher,BlueskyInsights,Facets,Tid,BlueskyErrors}.php` |
| LinkedIn | `app/Connectors/LinkedIn/{LinkedInConnector,LinkedInClient,LinkedInPublisher,LinkedInPostOptions}.php` ([guía](../guias/linkedin-pinterest.md)) |
| Pinterest | `app/Connectors/Pinterest/{PinterestConnector,PinterestClient,PinterestPublisher,PinterestPostOptions,PinterestInsightsReader}.php` ([guía](../guias/linkedin-pinterest.md)) |
| Mastodon | `app/Connectors/Mastodon/{MastodonConnector,MastodonClient,MastodonPublisher,MastodonLimits,MastodonPostOptions,MastodonErrors,MastodonException}.php` ([guía](../guias/mastodon-telegram.md)) |
| Telegram | `app/Connectors/Telegram/{TelegramConnector,TelegramClient,TelegramPublisher,TelegramErrors,TelegramException}.php` ([guía](../guias/mastodon-telegram.md)) |
| Threads | `app/Connectors/Threads/{ThreadsConnector,ThreadsClient,ThreadsPublisher,ThreadsInsights}.php`, `app/Connectors/Meta/GraphErrors.php` (tabla de códigos compartida con `GraphClient`) |
| Controladores | `app/Http/Controllers/OAuth/OAuthController.php`, `CredentialConnectionController.php` |
| Cuentas | `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php` |
| Refresco | `app/Jobs/RefreshTokens.php`, `RefreshSocialAccount.php` |
| UI | `resources/js/Pages/Brands/Connections.vue`, `Components/Connections/CredentialsForm.vue` |

## Flujo

`OAuthStateStore` guarda y consume cada intento bajo un candado de caché. La conexión social identifica el conjunto de intentos por usuario, sesión y proveedor, y conserva hasta diez intentos válidos simultáneos durante 30 minutos.

1. El usuario pulsa **Conectar** en la marca: `POST brands/{brand}/connections/{provider}/connect`. Es POST con CSRF porque iniciar OAuth cambia el estado de la sesión.
2. La red redirige a `GET connections/{provider}/callback` (`provider` ∈ `meta`, `google`, `tiktok`, `threads`, `linkedin`, `pinterest`).
3. El conector canjea el código, lista las cuentas candidatas y, si hay varias, el usuario elige (`brands.connections.select`).
4. Los tokens se guardan cifrados en `social_accounts`.
5. Si `SOCIAL_BACKFILL_ON_CONNECT` está activo, se encola el histórico inicial (ver [analítica](analitica.md)).

Redirect URIs a registrar en cada app (de `.env.example`): `${APP_URL}/connections/meta/callback`, `.../google/callback`, `.../tiktok/callback`, `.../threads/callback`, `.../linkedin/callback` y `.../pinterest/callback`. `APP_URL` debe ser HTTPS (lista completa, con Canva, en [despliegue](despliegue.md#redirect-uris-de-los-conectores-de-redes-y-de-canva)).

## Bluesky (handle + app password)

1. En **Conexiones**, «+ Conectar» en Bluesky abre un formulario con el handle y una **app password** (no la contraseña de la cuenta): `POST brands/{brand}/connections/bluesky/credentials`, con CSRF y `throttle:10,1`. Guía paso a paso: [Bluesky: app password](../guias/bluesky-app-password.md).
2. `BlueskySession::open()` resuelve el handle a su DID (`com.atproto.identity.resolveHandle` en `social.bluesky.entryway`), lee el PDS del documento DID (`plc_directory`, solo https y host público) y llama a `createSession` en ese PDS.
3. Se guarda `access_token` = `accessJwt`, `refresh_token` = `refreshJwt`, `credential_secret` = app password (cifrada), `external_id` = DID y en `meta` `did`, `pds`, `handle` y `email_confirmed`. `token_expires_at` sale del `exp` del JWT (sin verificar la firma: solo planifica la renovación). **`refresh_expires_at` queda en `null`** a propósito: si no, `RefreshSocialAccount::permanentReason()` marcaría la reconexión cuando aún se puede recrear la sesión.
4. **Renovación** (`RefreshTokens` cada 30 min, margen 1 h, `SOCIAL_REFRESH_MARGIN_BLUESKY_HOURS`): `refreshSession`; si responde `ExpiredToken`/`InvalidToken`, `createSession` con la app password; si esa tampoco sirve, `ReconnectRequiredException` y la cuenta pasa a `requiere_reconexion`. Un 5xx o un corte de red es transitorio (no marca la cuenta). El publicador también renueva bajo demanda si una llamada responde sesión caducada (`BlueskySession::call`, una sola repetición).
5. Un bloqueo por cuenta (`Cache::lock("bsky-session:{id}")`) evita que el job de refresco y el publicador roten el `refreshJwt` a la vez; si otro proceso ya renovó, se reutilizan sus credenciales sin llamar a la red.
6. Reconectar una cuenta de Bluesky se hace desde «+ Conectar» con una app password nueva (no hay redirección OAuth). Revocar la app password en Bluesky deja la cuenta en `requiere_reconexion` en la siguiente renovación.

`BLUESKY_ENABLED=false` retira la red de Conexiones, el compositor y los filtros sin tocar código.

## Mastodon (instancia + token personal, T-87)

Contratos leídos de la documentación oficial el 2026-11-26 (`docs/roadmap/2026-11-11-audit-hardening/testing/t87/api-contract.md`); sin red durante el desarrollo, así que el smoke contra una instancia real queda pendiente (M-10). Guía de usuario: [Mastodon y Telegram](../guias/mastodon-telegram.md).

1. **Conectar** (`POST brands/{brand}/connections/mastodon/credentials`, con CSRF y `throttle:10,1`): campos `instance_url` y `access_token`. `MastodonClient::normalizeInstance()` deja la instancia en `https://host`: sin esquema se asume https, `http://` se rechaza y no se admiten ruta, parámetros, credenciales ni puerto distinto de 443; el host tiene que resolver a una IP pública (`UrlGuard`). **Toda petición** (también al publicar) vuelve a validar el DNS y fija la conexión a la IP validada, sin redirecciones.
2. **Verificación**: `GET /api/v1/accounts/verify_credentials` (401/403 = token rechazado). Se guarda `external_id = <host>:<id>` (el `id` de Mastodon solo es único dentro de su instancia, así que dos cuentas de instancias distintas no se pisan), `name` y `username` = el `acct` completo (`ana@mastodon.social`), y en `meta` la instancia, el id, el perfil, el avatar y los límites. El token se guarda cifrado en `access_token` (lo recifra `app:reencrypt`), no caduca (`token_expires_at = null`) y no hay refresco. Los permisos concedidos (`write:statuses`, `write:media`, `read:accounts`) no se pueden comprobar al conectar: si faltan, el primer 401/403 al publicar deja la cuenta en `requiere_reconexion`.
3. **Límites de ESA instancia** (`MastodonLimits`): al conectar se leen de `GET /api/v2/instance` (`max_characters`, `characters_reserved_per_url`, `max_media_attachments`, `supported_mime_types`, `image_size_limit`, `description_limit`) y se guardan en `meta.limits`. El publicador los vuelve a leer **antes de publicar si tienen más de `MASTODON_LIMITS_TTL_HOURS`** (24 h); si la lectura falla, se publica con lo guardado. `MediaValidator` (con la cuenta de cada destino), el compositor (`accounts[].limits`: contadores y aviso) y el publicador usan esos límites. Sin dato, valores prudentes de `social.mastodon.default_*`: **500 caracteres, 4 imágenes, 8 MB y JPEG/PNG/WebP/GIF**. El texto se cuenta como lo cuenta Mastodon: modo `mastodon` de `TextLength` (y su espejo `textLength.js`): grafemas, cada URL `https?://` como `characters_reserved_per_url` (23 si la instancia no dice otra) y una mención remota `@u@dominio` como `@u`. `MastodonLimits::specFor` fija ese modo y la reserva en la regla de la cuenta, el compositor recibe la reserva en `accounts[].limits.url_length` y el publicador comprueba con la misma función antes de enviar. El asistente de IA («Adaptar a cada red») también valida con los límites de la cuenta.
4. **Publicar** (`MastodonPublisher`, tipo `mastodon_post`, de 0 a N imágenes): `POST /api/v2/media` por imagen (multipart; el texto alternativo del destino va como `description`, recortado a `description_limit`); si responde 202 se sondea `GET /api/v1/media/:id` (`MASTODON_MEDIA_POLL_ATTEMPTS` × `MASTODON_MEDIA_POLL_DELAY_MS`, siempre dentro del `StepBudget` del job) hasta el 200; si sigue procesando, el destino se reintenta con los ids ya subidos guardados en su progreso (no se vuelven a subir). Después `POST /api/v1/statuses` con `media_ids[]`, `visibility` (opción del destino `mastodon_visibility`: `public` por defecto, `unlisted`, `private`, `direct`) y la cabecera **`Idempotency-Key`** = SHA-256 de `mastodon-status|<destino>|<inicio de la ventana del intento>`: igual en todos los reintentos de un destino (la instancia la recuerda una hora y devuelve el estado ya creado) y distinta entre destinos. La URL publicada es la `url` del Status (o su `uri`), **solo si es https y del host de la instancia** (`SafeUrl`); si no, queda sin enlace. La clave de idempotencia incluye un hash del contenido (texto, visibilidad, imágenes y su alt): si se edita el post tras un timeout ambiguo, la edición se publica con otra clave en lugar de recibir el estado viejo (el coste es que, si el envío anterior sí salió, queda también ese estado en la instancia). Si una imagen guardada en el progreso ya no existe (404, caducó), se vuelve a subir.
5. **Errores** (`MastodonErrors`): 401/403 → `ReconnectRequiredException`; 429 → `QuotaExceededException` con espera hasta `Retry-After` o `X-RateLimit-Reset` (5 min si no hay cabecera); 422 → permanente (`contenido_rechazado`) con el motivo de la instancia; red y 5xx → transitorio; una instancia que ya no resuelve a una IP pública → permanente. **Tope del cuerpo de la respuesta** (`MASTODON_MAX_RESPONSE_BYTES`, 1 MB): se corta por `Content-Length` y, sin él, en cuanto lo descargado lo pasa (opciones `on_headers` y `progress` de Guzzle: el manejador cURL y la IP fijada no cambian); una instancia hostil no agota la memoria del worker. El avatar y el perfil de `meta` solo se guardan si son https (el perfil, además, del host de la instancia). La espera entre sondeos de una imagen nunca se come el presupuesto del paso. El token nunca aparece en logs, excepciones ni trazas (`#[SensitiveParameter]`).
6. Sin comentarios/inbox ni analítica (`commentNetworks()` no la incluye; `Metric::EXPECTED_FLOWS` y `AnalyticsService::SUPPORTED` van vacíos). `MASTODON_ENABLED=false` retira la red de Conexiones, el compositor y los filtros.

## Telegram (bot + canal, T-87)

Contratos: Bot API 10.5 (`api-contract.md`). Guía de usuario: [Mastodon y Telegram](../guias/mastodon-telegram.md).

1. **Conectar** (`POST brands/{brand}/connections/telegram/credentials`): campos `bot_token` y `channel` (`@usuario` o id numérico). El token solo se acepta con su forma (`<dígitos>:<letras, números, _ y ->`) porque va en la **ruta** de cada petición; el canal, como `@usuario` o id.
2. **Verificación**: `getMe` (id del bot) → `getChat` (el `type` tiene que ser `channel`) → `getChatMember(chat_id, id del bot)` (`administrator` con `can_post_messages`, o `creator`). Si no, error en castellano que explica cómo hacer administrador al bot. **Solo canales, no supergrupos**: la comprobación de «puede publicar» (`can_post_messages`) y el límite de envío que cubre la documentación leída son los de los canales; un supergrupo tiene otros permisos y 20 mensajes por minuto, sin verificar. Se guarda `external_id` = id del chat, `name` = título (o @usuario), el token cifrado en `access_token` (lo recifra `app:reencrypt`) y en `meta` el bot y el @usuario del canal.
3. **Tipos y límites** (`config/media_specs.php`, validados al guardar, así que no fallan al publicar): `telegram_text` (solo texto, 1–4096 caracteres) y `telegram_photo` (**una sola foto**, JPEG/PNG de hasta 10 MB, proporción ≤ 20, y el texto como pie de **hasta 1024**). Varias fotos (`sendMediaGroup`) no están en el contrato leído, así que no se admiten. Si el texto no cabe en el pie, el compositor lo avisa y se usa «Mensaje de texto» (o se acorta); no se envía foto y mensaje por separado. Ancho + alto ≤ 10000 px lo comprueba el publicador antes de enviar.
4. **Publicar** (`TelegramPublisher`): `sendMessage` o `sendPhoto` (multipart), siempre como texto plano **sin `parse_mode`** (ningún carácter se interpreta). URL publicada: `https://t.me/<usuario>/<message_id>` si el canal tiene @usuario público; en un canal privado es `null`. Telegram no ofrece idempotencia ni consulta de lo publicado (`findPublished` devuelve `null`), así que **un timeout o un corte después de enviar no se reintenta**: el destino queda fallido con el motivo `resultado_incierto` («revisa el canal antes de volver a publicar») para no duplicar el mensaje. Solo se reintenta lo que no llegó a salir (DNS, conexión rechazada, TLS: errores de cURL 5, 6, 7, 35, 51, 58, 59 y 60) y los 5xx.
5. **Errores** (`TelegramErrors`): `parameters.retry_after` (o 429) → cuota con esa espera; 401/403, «bot was kicked» o «not enough rights» → reconexión; red y 5xx → transitorio; el resto → permanente con el motivo de Telegram.
6. **El token va en la ruta, nunca se registra**: la URL no se escribe en ningún log, ninguna excepción la lleva (ni como mensaje ni encadenada: las caídas de red se re-lanzan sin el original) y el token es `#[SensitiveParameter]` para que tampoco salga en las trazas ni en `failed_jobs`. `TELEGRAM_ENABLED=false` retira la red. La Bot API es siempre `https://api.telegram.org` (no se configura; la guardia SSRF valida igualmente su DNS y fija la IP).

## Threads (OAuth propio, T1)

Threads es un conector **propio** (`provider = threads`), no una red del proveedor `meta`: usa otro ID y otro secreto de app, otro host de autorización y otro canje, y pedir un permiso `threads_*` no activado no puede romper el login de Facebook e Instagram. Pasos y valores verificados en la documentación oficial de Meta el 2026-10-27 (ver [decisiones por red](../referencia/decisiones-por-red.md#threads)); guía de alta: [Threads: app de Meta](../guias/threads-app-meta.md).

1. `POST brands/{brand}/connections/threads/connect` redirige a `{social.threads.auth_host}/oauth/authorize` (`https://threads.com`) con `client_id = THREADS_APP_ID`, `redirect_uri = <APP_URL>/connections/threads/callback`, `response_type=code`, `state` y los permisos `threads_basic`, `threads_content_publish` y `threads_manage_insights` (`social.threads.scopes`).
2. En el callback, `ThreadsConnector::exchangeCode()` quita la «#_» que Meta añade al código, lo canjea con `POST {graph_host}/oauth/access_token` (secreto **en el cuerpo**) por un token corto de 1 h, lo cambia por el de **larga duración** (`GET /access_token?grant_type=th_exchange_token`, 60 días) y lee el perfil (`GET /v1.0/me?fields=id,username,name,threads_profile_picture_url`). `external_id` es el ID de usuario de Threads.
3. Se guarda el token largo cifrado en `access_token` con `token_expires_at` = +`expires_in`; **`refresh_token` queda `null`** (no existe: el propio token largo se renueva) y `refresh_expires_at` también.
4. **Renovación** (`RefreshTokens` cada 30 min, margen 168 h, `SOCIAL_REFRESH_MARGIN_THREADS_HOURS`): `GET /refresh_access_token?grant_type=th_refresh_token` con el token vigente (la API exige que tenga al menos 24 h y no haya caducado; el margen de 7 días lo cumple de sobra). Un 401, un código `190`/`102`... o un token ya caducado es permanente (`requiere_reconexion`); un 5xx, un 429 o un corte de red es transitorio. Las publicaciones programadas se conservan.
5. **Hosts configurables** (`THREADS_AUTH_HOST` = `https://threads.com`, `THREADS_GRAPH_HOST` = `https://graph.threads.com`, `THREADS_API_VERSION` = `v1.0`) por si Meta vuelve a cambiarlos; las tablas de códigos de error de credencial y de límite de uso son las de Graph (`Meta/GraphErrors`, extraídas de `GraphClient` sin cambiar su comportamiento).
6. **Interruptor** (`THREADS_ENABLED`, por defecto `true`): con `false` **o sin `THREADS_APP_ID`**, `Network::Threads->isAvailable()` es falso, el conector no se registra y la red desaparece de Conexiones, del compositor, de los filtros y de los informes, del MCP (`list_accounts` la marca `available: false` y `create_post` la rechaza) y de la reprogramación. Las cuentas ya conectadas se conservan.
7. Los permisos concedidos no se pueden listar (Threads no tiene `debug_token` propio): se guardan los pedidos. ⚠️ Se confirma en el smoke (T-64).

## Particularidades por red

| Red | Scopes (`config/social.php`) | Notas |
|---|---|---|
| Meta | `business_management`, `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `read_insights`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights`, `instagram_manage_contents` | Páginas de portfolios vía `me/businesses` → `owned_pages`/`client_pages`. `debug_token` valida permisos y `data_access_expires_at`. Instagram solo si la Página tiene `instagram_business_account`. |
| Google | `youtube`, `youtube.readonly`, `yt-analytics.readonly` | `access_type=offline` + `prompt=consent`; exige `refresh_token`. |
| TikTok | `user.info.basic`, `video.upload`, `video.publish` (+ `TIKTOK_EXTRA_SCOPES`, p. ej. `user.info.stats,video.list` para la analítica: solo con la aprobación de TikTok y reconectando la cuenta) | `video.upload` es obligatorio (modo borrador por defecto). `creator_info/query` se llama con cuerpo `{}` y es best-effort. |
| Bluesky | — (app password; `scopes = ['atproto']`) | Sin OAuth: `CredentialConnector`. Sesión con refresco y recreación (ver arriba). |
| LinkedIn | `openid`, `profile`, `w_member_social`; páginas (con `LINKEDIN_COMMUNITY_MANAGEMENT`): `w_organization_social`, `r_organization_social`, `rw_organization_admin`; `r_member_social` con `LINKEDIN_R_MEMBER_SOCIAL` | Perfil siempre; páginas solo con Community Management aprobado. Ver [guía](../guias/linkedin-pinterest.md). |
| Pinterest | `boards:read`, `pins:read`, `pins:write`, `user_accounts:read` | API v5; tablero obligatorio en cada pin (los tableros se leen paginados con `bookmark` —tope de 500 y 10 s por petición, con aviso de lista incompleta— y se refrescan con «Actualizar tableros», `POST brands/{brand}/connections/{account}/pinterest-boards`); acceso de prueba hasta la aprobación; la analítica no existe en sandbox. Ver [guía](../guias/linkedin-pinterest.md). |
| Threads | `threads_basic`, `threads_content_publish`, `threads_manage_insights` (`social.threads.scopes`) | OAuth en `threads.com` con el ID y el secreto **de Threads**; token largo de 60 días sin `refresh_token`; sin App Review solo publican los *testers* de la app. |
| Mastodon | — (token personal; `scopes = ['write:statuses', 'write:media', 'read:accounts']`) | Sin OAuth: `CredentialConnector`. El token no caduca; límites por instancia. Ver arriba. |
| Telegram | — (token del bot; `scopes = ['channel:post']`) | Sin OAuth: `CredentialConnector`. Solo canales donde el bot es administrador. Ver arriba. |

## Estados de cuenta y refresco

`RefreshTokens` corre cada 30 min y renueva con margen: Meta 168 h, Google 1 h, TikTok 6 h, Bluesky 1 h, Threads 168 h, LinkedIn 168 h y Pinterest 72 h (`SOCIAL_REFRESH_MARGIN_*_HOURS`). Mastodon y Telegram no caducan ni se renuevan. LinkedIn sin `refresh_token` no se renueva: la UI avisa «reconecta antes de».

**Caducidad conocida que no se renueva solo (T-23)**: `ConnectionExpiry` calcula la fecha de la conexión a reconectar: el **acceso a datos** (`data_access_expires_at`; Meta lo limita a 90 días desde el último inicio de sesión) en cualquier red que lo informe, la **caducidad del refresh token** (`refresh_expires_at`; LinkedIn a 1 año, Pinterest, TikTok) y el **token de LinkedIn sin `refresh_token`**. Los tokens que sí se refrescan solos (Threads, Pinterest, TikTok, Google…) no cuentan: si su renovación falla, `ReconnectRequired` avisa. Conexiones muestra «Reconecta antes del …» con el motivo (`reconnect_before`, `reconnect_reason`, `reconnect_message`) dentro de la ventana del umbral mayor, y el comando diario `connections:notify-expiry` (`ConnectionExpiryNotifier`) envía `ConnectionExpiring` (aviso en la app y, con la preferencia `notify_by_mail` del usuario, correo) a `SOCIAL_EXPIRY_NOTICE_DAYS` días (por defecto `7,1`) de la caducidad, una sola vez por umbral y fecha: al reconectar, la fecha cambia y el ciclo se reinicia. Ver [avisos](publicacion.md#avisos).

- Fallo **permanente** (`invalid_grant`, 401, `data_access_expires_at` o `refresh_expires_at` vencidos): la cuenta pasa a `requiere_reconexion` al instante y se envía `ReconnectRequired`.
- Fallo **transitorio** (5xx, red): se cuenta en `refresh_failures`; tras `SOCIAL_REFRESH_MAX_TRANSIENT_FAILURES` (12, ≈ 6 h) seguidos escala a `requiere_reconexion`.
- Al reconectar se conservan las publicaciones pendientes de esa cuenta.

Desconectar (`DELETE brands/{brand}/connections/{account}`) deja la cuenta `desconectada`: sus publicaciones pendientes fallan con `cuenta_no_disponible`.

Direct Post de TikTok se activa por cuenta con `PATCH brands/{brand}/connections/{account}/direct-post` (ver [decisiones por red](../referencia/decisiones-por-red.md)).

## Gotchas conocidos

- Meta en apps por casos de uso: cada permiso debe añadirse dentro del caso de uso ("Invalid Scopes" si no). El redirect URI se registra en *Facebook Login for Business → Configurar*; el primer guardado puede no persistir: recargar y comprobar.
- Google en "Testing": el refresh token caduca a 7 días. Decisión G1: consentimiento "En producción" sin verificar.
- Detalle y evidencia: [decisiones por red](../referencia/decisiones-por-red.md).
