# Conexiones por red

Cada marca (`Brand`) conecta cuentas de Meta (Facebook + Instagram), Google (YouTube), TikTok y Bluesky. Meta, Google y TikTok se conectan por OAuth; Bluesky con handle + app password (sin redirección). Los conectores usan el cliente `Http` de Laravel, sin SDK.

## Contrato y catálogo de redes

El contrato está segregado (`app/Connectors/Contracts/`):

- `Connector`: lo común (`provider()`, `networks()`, `refresh()`, `refreshMarginHours()`). `RefreshSocialAccount` solo conoce esto.
- `OAuthConnector extends Connector`: `authorizationUrl()` y `exchangeCode()`. Lo implementan Meta, Google y TikTok. `OAuthController` responde 404 si el proveedor no es un `OAuthConnector`.
- `CredentialConnector extends Connector`: `credentialFields()` y `connectWithCredentials()`. Lo implementa Bluesky. Lo usa `CredentialConnectionController`.

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
| Controladores | `app/Http/Controllers/OAuth/OAuthController.php`, `CredentialConnectionController.php` |
| Cuentas | `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php` |
| Refresco | `app/Jobs/RefreshTokens.php`, `RefreshSocialAccount.php` |
| UI | `resources/js/Pages/Brands/Connections.vue`, `Components/Connections/CredentialsForm.vue` |

## Flujo

1. El usuario pulsa **Conectar** en la marca: `POST brands/{brand}/connections/{provider}/connect`. Es POST con CSRF porque iniciar OAuth cambia el estado de la sesión.
2. La red redirige a `GET connections/{provider}/callback` (`provider` ∈ `meta`, `google`, `tiktok`).
3. El conector canjea el código, lista las cuentas candidatas y, si hay varias, el usuario elige (`brands.connections.select`).
4. Los tokens se guardan cifrados en `social_accounts`.
5. Si `SOCIAL_BACKFILL_ON_CONNECT` está activo, se encola el histórico inicial (ver [analítica](analitica.md)).

Redirect URIs a registrar en cada app (de `.env.example`): `${APP_URL}/connections/meta/callback`, `.../google/callback`, `.../tiktok/callback`. `APP_URL` debe ser HTTPS.

## Bluesky (handle + app password)

1. En **Conexiones**, «+ Conectar» en Bluesky abre un formulario con el handle y una **app password** (no la contraseña de la cuenta): `POST brands/{brand}/connections/bluesky/credentials`, con CSRF y `throttle:10,1`. Guía paso a paso: [Bluesky: app password](../guias/bluesky-app-password.md).
2. `BlueskySession::open()` resuelve el handle a su DID (`com.atproto.identity.resolveHandle` en `social.bluesky.entryway`), lee el PDS del documento DID (`plc_directory`, solo https y host público) y llama a `createSession` en ese PDS.
3. Se guarda `access_token` = `accessJwt`, `refresh_token` = `refreshJwt`, `credential_secret` = app password (cifrada), `external_id` = DID y en `meta` `did`, `pds`, `handle` y `email_confirmed`. `token_expires_at` sale del `exp` del JWT (sin verificar la firma: solo planifica la renovación). **`refresh_expires_at` queda en `null`** a propósito: si no, `RefreshSocialAccount::permanentReason()` marcaría la reconexión cuando aún se puede recrear la sesión.
4. **Renovación** (`RefreshTokens` cada 30 min, margen 1 h, `SOCIAL_REFRESH_MARGIN_BLUESKY_HOURS`): `refreshSession`; si responde `ExpiredToken`/`InvalidToken`, `createSession` con la app password; si esa tampoco sirve, `ReconnectRequiredException` y la cuenta pasa a `requiere_reconexion`. Un 5xx o un corte de red es transitorio (no marca la cuenta). El publicador también renueva bajo demanda si una llamada responde sesión caducada (`BlueskySession::call`, una sola repetición).
5. Un bloqueo por cuenta (`Cache::lock("bsky-session:{id}")`) evita que el job de refresco y el publicador roten el `refreshJwt` a la vez; si otro proceso ya renovó, se reutilizan sus credenciales sin llamar a la red.
6. Reconectar una cuenta de Bluesky se hace desde «+ Conectar» con una app password nueva (no hay redirección OAuth). Revocar la app password en Bluesky deja la cuenta en `requiere_reconexion` en la siguiente renovación.

`BLUESKY_ENABLED=false` retira la red de Conexiones, el compositor y los filtros sin tocar código.

## Particularidades por red

| Red | Scopes (`config/social.php`) | Notas |
|---|---|---|
| Meta | `business_management`, `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `read_insights`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights`, `instagram_manage_contents` | Páginas de portfolios vía `me/businesses` → `owned_pages`/`client_pages`. `debug_token` valida permisos y `data_access_expires_at`. Instagram solo si la Página tiene `instagram_business_account`. |
| Google | `youtube`, `youtube.readonly`, `yt-analytics.readonly` | `access_type=offline` + `prompt=consent`; exige `refresh_token`. |
| TikTok | `user.info.basic`, `video.upload`, `video.publish` | `video.upload` es obligatorio (modo borrador por defecto). `creator_info/query` se llama con cuerpo `{}` y es best-effort. |
| Bluesky | — (app password; `scopes = ['atproto']`) | Sin OAuth: `CredentialConnector`. Sesión con refresco y recreación (ver arriba). |

## Estados de cuenta y refresco

`RefreshTokens` corre cada 30 min y renueva con margen: Meta 168 h, Google 1 h, TikTok 6 h, Bluesky 1 h (`SOCIAL_REFRESH_MARGIN_*_HOURS`).

- Fallo **permanente** (`invalid_grant`, 401, `data_access_expires_at` o `refresh_expires_at` vencidos): la cuenta pasa a `requiere_reconexion` al instante y se envía `ReconnectRequired`.
- Fallo **transitorio** (5xx, red): se cuenta en `refresh_failures`; tras `SOCIAL_REFRESH_MAX_TRANSIENT_FAILURES` (12, ≈ 6 h) seguidos escala a `requiere_reconexion`.
- Al reconectar se conservan las publicaciones pendientes de esa cuenta.

Desconectar (`DELETE brands/{brand}/connections/{account}`) deja la cuenta `desconectada`: sus publicaciones pendientes fallan con `cuenta_no_disponible`.

Direct Post de TikTok se activa por cuenta con `PATCH brands/{brand}/connections/{account}/direct-post` (ver [decisiones por red](../referencia/decisiones-por-red.md)).

## Gotchas conocidos

- Meta en apps por casos de uso: cada permiso debe añadirse dentro del caso de uso ("Invalid Scopes" si no). El redirect URI se registra en *Facebook Login for Business → Configurar*; el primer guardado puede no persistir: recargar y comprobar.
- Google en "Testing": el refresh token caduca a 7 días. Decisión G1: consentimiento "En producción" sin verificar.
- Detalle y evidencia: [decisiones por red](../referencia/decisiones-por-red.md).
