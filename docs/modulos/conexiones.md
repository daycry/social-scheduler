# Conexiones OAuth por red

Cada marca (`Brand`) conecta cuentas de Meta (Facebook + Instagram), Google (YouTube) y TikTok. Los conectores usan el cliente `Http` de Laravel, sin SDK.

## Piezas

| Pieza | Ruta |
|---|---|
| Registro de conectores | `app/Connectors/ConnectorRegistry.php` |
| Contratos | `app/Connectors/Contracts/{Connector,Publisher,StatusPoller,InsightsReader,RangeInsightsReader}.php` |
| Meta | `app/Connectors/Meta/MetaConnector.php`, `GraphClient.php` |
| Google | `app/Connectors/Google/GoogleConnector.php` |
| TikTok | `app/Connectors/TikTok/TikTokConnector.php` |
| Controlador OAuth | `app/Http/Controllers/OAuth/OAuthController.php` |
| Cuentas | `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php` |
| Refresco | `app/Jobs/RefreshTokens.php`, `RefreshSocialAccount.php` |
| UI | `resources/js/Pages/Brands/Connections.vue` |

## Flujo

1. El usuario pulsa **Conectar** en la marca: `POST brands/{brand}/connections/{provider}/connect`. Es POST con CSRF porque iniciar OAuth cambia el estado de la sesión.
2. La red redirige a `GET connections/{provider}/callback` (`provider` ∈ `meta`, `google`, `tiktok`).
3. El conector canjea el código, lista las cuentas candidatas y, si hay varias, el usuario elige (`brands.connections.select`).
4. Los tokens se guardan cifrados en `social_accounts`.
5. Si `SOCIAL_BACKFILL_ON_CONNECT` está activo, se encola el histórico inicial (ver [analítica](analitica.md)).

Redirect URIs a registrar en cada app (de `.env.example`): `${APP_URL}/connections/meta/callback`, `.../google/callback`, `.../tiktok/callback`. `APP_URL` debe ser HTTPS.

## Particularidades por red

| Red | Scopes (`config/social.php`) | Notas |
|---|---|---|
| Meta | `business_management`, `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `read_insights`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights`, `instagram_manage_contents` | Páginas de portfolios vía `me/businesses` → `owned_pages`/`client_pages`. `debug_token` valida permisos y `data_access_expires_at`. Instagram solo si la Página tiene `instagram_business_account`. |
| Google | `youtube`, `youtube.readonly`, `yt-analytics.readonly` | `access_type=offline` + `prompt=consent`; exige `refresh_token`. |
| TikTok | `user.info.basic`, `video.upload`, `video.publish` | `video.upload` es obligatorio (modo borrador por defecto). `creator_info/query` se llama con cuerpo `{}` y es best-effort. |

## Estados de cuenta y refresco

`RefreshTokens` corre cada 30 min y renueva con margen: Meta 168 h, Google 1 h, TikTok 6 h (`SOCIAL_REFRESH_MARGIN_*_HOURS`).

- Fallo **permanente** (`invalid_grant`, 401, `data_access_expires_at` o `refresh_expires_at` vencidos): la cuenta pasa a `requiere_reconexion` al instante y se envía `ReconnectRequired`.
- Fallo **transitorio** (5xx, red): se cuenta en `refresh_failures`; tras `SOCIAL_REFRESH_MAX_TRANSIENT_FAILURES` (12, ≈ 6 h) seguidos escala a `requiere_reconexion`.
- Al reconectar se conservan las publicaciones pendientes de esa cuenta.

Desconectar (`DELETE brands/{brand}/connections/{account}`) deja la cuenta `desconectada`: sus publicaciones pendientes fallan con `cuenta_no_disponible`.

Direct Post de TikTok se activa por cuenta con `PATCH brands/{brand}/connections/{account}/direct-post` (ver [decisiones por red](../referencia/decisiones-por-red.md)).

## Gotchas conocidos

- Meta en apps por casos de uso: cada permiso debe añadirse dentro del caso de uso ("Invalid Scopes" si no). El redirect URI se registra en *Facebook Login for Business → Configurar*; el primer guardado puede no persistir: recargar y comprobar.
- Google en "Testing": el refresh token caduca a 7 días. Decisión G1: consentimiento "En producción" sin verificar.
- Detalle y evidencia: [decisiones por red](../referencia/decisiones-por-red.md).
