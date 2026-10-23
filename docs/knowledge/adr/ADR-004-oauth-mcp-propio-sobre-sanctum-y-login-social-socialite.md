---
id: ADR-004
titulo: Servidor de autorización OAuth 2.1 del MCP propio sobre Sanctum y login social con Socialite
estado: aceptada (visto bueno del usuario 2026-10-22 al aprobar el plan)
fecha: 2026-10-22
iniciativa: 2026-10-22-social-login-mcp-oauth
---

# ADR-004: Servidor de autorización OAuth 2.1 del MCP propio sobre Sanctum y login social con Socialite

## Contexto

La iniciativa pide dos cosas:

- Login con Google y Facebook para las cuentas que ya existen, con el 2FA intacto.
- Que los clientes MCP se autoricen por OAuth 2.1 (PRM, ASM, DCR, PKCE S256 y 401 con `WWW-Authenticate`) sin romper las API keys `msk_`.

El `Mcp::oauthRoutes()` de `laravel/mcp` 1.0.2 depende de Passport, que no está instalado, y solo concede `mcp:use`. Por eso el usuario decidió (P2) un servidor de autorización propio y mínimo que emite tokens Sanctum. Quedaba por decidir cómo se habla con Google y Facebook y dónde vive el estado del servidor de autorización. Fuente: [`docs/roadmap/2026-10-22-social-login-mcp-oauth/design.md`](../../roadmap/2026-10-22-social-login-mcp-oauth/design.md), opción O1, validada por el usuario el 2026-10-22.

## Decisión

**Servidor de autorización propio sobre Sanctum, sin Passport:**

- Es un cliente público, con `authorization_code` + PKCE S256 y sin refresh tokens.
- El token es un token Sanctum `msk_` con las abilities elegidas en el consentimiento (presets read/publish/all, con read por defecto) y una vida que elige el usuario en el consentimiento (30, 90 por defecto, 180 o 365 días; `MCP_OAUTH_TOKEN_DAYS` marca el default).
- El estado vive en tablas: `oauth_clients` (DCR) y `oauth_auth_codes` (hash del código, `used_at` y `token_id`).
  - El código es de un solo uso con un `UPDATE … WHERE used_at IS NULL` atómico.
  - Un replay revoca el token que se emitió con ese código.
- El token se marca con `personal_access_tokens.oauth_client_id` y se lista y revoca en *API keys*.

**Reutilización de `laravel/mcp`:**

- Solo se reutiliza `AddWwwAuthenticateHeader`: la ruta de la PRM se llama `mcp.oauth.protected-resource.nested`.
- Los metadatos (PRM y ASM), el DCR, el token y el authorize son controladores propios.
- `/.well-known/*`, `register` y `token` viven en `routes/ai.php`, sin sesión ni CSRF.
- `authorize` vive en `routes/web.php`, con `auth`.
- `McpGuard` no cambia.

**Login social con `laravel/socialite` ^5.31:**

- Credenciales propias de login (`services.google_login`, `services.facebook_login`), separadas de las de `app/Connectors`, que no se tocan.
- La identidad (proveedor + id) se guarda en `social_identities`.
- Política P1: Google vincula automáticamente solo con `email_verified=true` y la cuenta local verificada; si falta el campo, se rechaza (falla cerrado). Facebook solo se vincula a mano.
- La bifurcación del 2FA se extrae a `App\Services\Auth\LoginFlow`, que comparten la contraseña y el login social. La passkey sigue sin pasar por el 2FA (ADR-003).

## Alternativas descartadas

- **Passport 13 + `Mcp::oauthRoutes()`.** Descartada por el usuario (P2):
  - Choca con las abilities, porque el DCR del vendor solo da `mcp:use`.
  - Obliga a convivir con dos sistemas de tokens en `User`.
  - Rompe `audit_logs.token_id` (`bigint`).
  - Añade claves RSA en Hestia.
- **`Mcp::oauthRoutes(registration: false)` con nuestras rutas llamadas `passport.*`.** El ASM del vendor anuncia `refresh_token` y `client_secret_*`, lo que contradice CA-13, y su variante anidada no se puede sustituir.
- **Cliente social propio (OIDC y Graph a mano) sobre los helpers HTTP de `app/Connectors`** (O2). Hay que extraer los helpers privados de `GoogleConnector` y `MetaConnector`, con riesgo de regresión en la publicación, y aumenta el código propio en un camino de autenticación. Coste relativo L frente a M.
- **Códigos de autorización cifrados, sin tabla, con la marca de un solo uso en caché** (O3). `optimize:clear` o cambiar `CACHE_STORE` borra las marcas y permite canjear dos veces un código interceptado. Además, los códigos en curso no se pueden auditar.

## Consecuencias

**Lo que se gana:**

- Las abilities, `tokenCan()`, la caducidad, la auditoría y la revocación desde *API keys* funcionan sin cambios para los tokens OAuth.
- Las API keys manuales no cambian.
- Los conectores de publicación no se tocan.

**Lo que se asume:**

- El protocolo OAuth es código de seguridad propio: exige revisión adversarial, vectores del RFC 7636 y un test negativo por cada `invalid_*`.

**Lo que queda condicionado:**

- Si algún cliente MCP exige refresh tokens o solo usa CIMD, hay que ampliar este servidor (≈ +8h base para refresh). Lo comprueba el smoke CA-29.
- `laravel/socialite` es una dependencia nueva: al instalarla hay que verificar que expone `email_verified`.
- Nginx/Hestia tiene que dejar pasar `/.well-known/oauth-*` (`location ^~` en `deploy/hestia/nginx-overrides.conf`).

## Estado

`aceptada`: visto bueno explícito del usuario el 2026-10-22, al aprobar el plan. Pasa a `aceptada` cuando se valida y a `obsoleta` si una decisión posterior la reemplaza (enlaza aquí la que la sustituye, nunca se borra el rastro).
