---
spec: social-login-mcp-oauth
descripcion: Login social con Google y Facebook (solo para cuentas existentes, con el 2FA intacto) y autorización OAuth 2.1 del servidor MCP para que los clientes se conecten desde el navegador sin copiar una API key.
estado: aprobada          # borrador | aprobada | implementada | obsoleta
creado: 2026-10-22
actualizado: 2026-10-22
evaluacion: evaluation.md
design: n/a               # pasará a design.md si el architect hace el paso de diseño (ADR-004, ver Decisiones)
plan: pendiente
generacion:
  inicio: 2026-10-22T17:48:56Z
  fin: 2026-10-22T17:58:28Z
  fuente: estimado        # la redacción de esta spec está dentro de la ventana medida de evaluation.md (un solo marcador, sin solapes); ver su bloque generacion
  tokens_reales: { entrada: 0, salida: 0, cache_creacion: 0, cache_lectura: 0 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.00          # contabilizadas en evaluation.md
  duracion: 0m            # contabilizada en evaluation.md
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Login social (Google y Facebook) y OAuth 2.1 del servidor MCP

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Plan de implementación:** pendiente

> **Terminología:**
>
> - **Identidad social**: el par (proveedor, id estable del usuario en el proveedor), es decir, el `sub` de Google o el `id` de Facebook. Se vincula a un `User` existente. El email **no** es la identidad.
> - **Vinculación automática**: en el primer login social, enlazar la identidad a la cuenta cuyo email coincide. Solo se permite con email verificado por el proveedor (ver Decisiones).
> - **AS (Authorization Server)**: la parte de la app que registra clientes, muestra el consentimiento y emite tokens. **RS (Resource Server)**: el endpoint `/mcp`.
> - **DCR**: Dynamic Client Registration (RFC 7591). **PKCE**: Proof Key for Code Exchange (RFC 7636), siempre con `S256`. **PRM**: metadatos del recurso protegido (RFC 9728). **ASM**: metadatos del servidor de autorización (RFC 8414).
> - **Ability**: permiso de Sanctum definido en `app/Mcp/Abilities.php`. Los presets son `read` ⊂ `publish` ⊂ `all`.

## Contexto y objetivo

Hoy se entra con email y contraseña o con passkey; si el usuario tiene 2FA, se le pide además el código. El servidor MCP solo acepta Bearer con una API key Sanctum `msk_`, que el usuario crea en *API keys* y pega en la configuración del cliente (`docs/modulos/mcp.md` §instalación). Se persiguen dos objetivos:

1. **Login social con Google y Facebook** en `/login`, solo para cuentas que ya existen (`REGISTRATION_ENABLED=false`; las cuentas se crean por consola). Sustituye a la contraseña, no al segundo factor.
2. **OAuth 2.1 en el MCP**, tal como lo pide la [especificación MCP de autorización](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization). Claude Code CLI, Claude Desktop, Codex y OpenCode abren el navegador; el usuario entra con Google, Facebook, contraseña o passkey (más el 2FA si lo tiene), elige los permisos y el cliente queda conectado sin copiar una API key. Las API keys `msk_` siguen funcionando igual.

Fuentes: petición del usuario en la fase 1 de `/dev-cycle` (2026-10-22). Recon del repo: `routes/ai.php`, `app/Http/Middleware/McpGuard.php`, `app/Mcp/Abilities.php`, `vendor/laravel/mcp/src/Server/Registrar.php`, `routes/auth.php`, `app/Http/Controllers/Auth/*`, `config/services.php` y ADR-003.

## Decisiones de diseño

| Decisión | Elección | Motivo |
|---|---|---|
| Alta de cuentas | **Nunca.** El login social no crea usuarios, tampoco con `REGISTRATION_ENABLED=true`. | Decisión del usuario. Las cuentas se crean por consola. |
| Qué identifica a la persona | **La identidad social (proveedor + id estable)**, guardada en una tabla nueva `social_identities`. El email solo sirve para la vinculación automática del primer login. | El email puede cambiar o reasignarse; el `sub` y el `id` no. |
| Email ausente o no verificado (**propuesta, pendiente de confirmar**) | **Google:** vinculación automática solo si `email_verified=true` **y** la cuenta local tiene `email_verified_at`. **Facebook:** nunca se vincula sola; hay que hacerlo desde *Perfil → Cuentas vinculadas*. Si falta el email o no está verificado, se rechaza con el mensaje «Vincula esta cuenta desde tu perfil». | Hay riesgo de toma de cuenta. Google expone `email_verified` y es autoritativo para Gmail y Workspace. La Graph API de Facebook no da una señal de verificación fiable y hay casos públicos de takeover por emails no confirmados. Detalle en evaluation.md, C-01. |
| 2FA | **El login social sustituye solo a la contraseña.** Con 2FA activo, el callback deja `login.id`, `login.remember` y `login.at` en sesión y redirige a `two-factor.challenge`, el mismo reto que ya existe. | Decisión del usuario. Reutiliza `TwoFactorChallengeController`. |
| Desvincular | Requiere `password.confirm`. La identidad queda **bloqueada** para la vinculación automática (no se borra el rastro) hasta que el usuario la vuelva a vincular a mano. | Si no, el siguiente login con Google la volvería a vincular sola por email. |
| Librería de login social | **`laravel/socialite` ^5.31**, que soporta Laravel 13 (verificado con `composer show`). | Es oficial y trae los drivers de Google y Facebook. Nada parecido está instalado hoy. |
| Credenciales de login | **Apps o clientes separados de los de conexión de redes** (`GOOGLE_LOGIN_*` y `META_LOGIN_*`). | La app de Meta actual es de *Facebook Login for Business* y tiene permisos de Páginas (GOT-001). El login de consumidor (`email`, `public_profile`) es otro caso de uso. Separarlas evita mezclar el app review, el consentimiento y la rotación de secretos. |
| Servidor de autorización MCP (**propuesta, se decide en ADR-004**) | **Opción B: AS mínimo propio que emite tokens Sanctum `msk_`.** Pasos: DCR, `/oauth/authorize` con consentimiento, `/oauth/token` con PKCE S256, sin refresh tokens. La alternativa A es Passport más `Mcp::oauthRoutes()`. | `laravel/mcp` 1.0.2 trae `Mcp::oauthRoutes()`, pero depende de Passport (`route('passport.token')`, `Laravel\Passport\ClientRepository`) y solo concede el scope `mcp:use`. Con B, las abilities, `tokenCan()`, la caducidad, la auditoría (`audit_logs.token_id` es `bigint`) y la revocación desde *API keys* funcionan sin tocar nada. Comparativa en evaluation.md, C-02. |
| Permisos en el consentimiento | **Los presets `read`, `publish` y `all` con casillas editables, como al crear una API key. Viene marcado `read` por defecto.** Las abilities del token son las que marca el usuario. Se anuncia solo el scope `mcp:use`. | Mínimo privilegio. Si se anunciaran todas las abilities en `scopes_supported`, los clientes las pedirían todas. |
| Vida del token OAuth | `MCP_OAUTH_TOKEN_DAYS`, **30 días por defecto**, con tope `MCP_KEY_MAX_DAYS`. Sin refresh token: al caducar, el cliente recibe 401 y vuelve a abrir el navegador. | El usuario dejó fuera los refresh tokens de larga duración salvo que hagan falta. La spec MCP no los exige. |
| Reautorizar el mismo cliente | **Sustituye al token anterior** del mismo (usuario, cliente OAuth), que queda revocado. | Evita que se acumulen tokens vivos por cada `/mcp login`. |
| Redirect URIs admitidas en DCR | **Solo** loopback (`http://localhost`, `http://127.0.0.1` y `http://[::1]`, cualquier puerto, RFC 8252) y los callbacks de Claude (`https://claude.ai/api/mcp/auth_callback`, `https://claude.com/api/mcp/auth_callback`) ⚠️ verificar. Se amplía con `MCP_OAUTH_REDIRECT_ALLOWED`. | El default del vendor (`redirect_domains => ['*']`) es un redirector abierto de códigos. |
| `Origin` en `/mcp` | **Sin cambios.** `McpGuard` sigue validando solo `/mcp`. Los endpoints OAuth no exigen `Origin`. | Los clientes CLI y el backend de Claude no mandan `Origin`. |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| Cliente Google de login | `GOOGLE_LOGIN_CLIENT_ID` / `GOOGLE_LOGIN_CLIENT_SECRET` | vacío (botón oculto, ruta 404) | **cliente web de un proyecto de Google Cloud dedicado** |
| App Meta de login | `META_LOGIN_APP_ID` / `META_LOGIN_APP_SECRET` | vacío (botón oculto, ruta 404) | **app de consumidor con el caso de uso «Facebook Login»** |
| Versión Graph para el login | `META_GRAPH_VERSION` (se reutiliza) | `v23.0` | igual que las conexiones |
| Vida del token OAuth MCP | `MCP_OAUTH_TOKEN_DAYS` | 30 | **30** (tope `MCP_KEY_MAX_DAYS` = 730) |
| Vida del código de autorización | `MCP_OAUTH_CODE_TTL` (segundos) | 300 | **300**, de un solo uso |
| Redirect URIs extra para DCR | `MCP_OAUTH_REDIRECT_ALLOWED` (lista CSV de prefijos `https://`) | vacío | vacío (loopback y Claude van fijos) |
| Límite de DCR | limitador `mcp-oauth-register` por IP | 10/h | **10/h** |
| Límite del token endpoint | limitador `mcp-oauth-token` por IP | 30/min | **30/min** |
| Límite del callback social | limitador `social-login` por IP | 20/min | **20/min** |
| Purga de clientes OAuth sin uso | comando programado `mcp:oauth-prune` | 30 días sin token vivo | **30 días** |

## Arquitectura y componentes

**Se reutiliza:**

- `AuthenticatedSessionController::store`: la bifurcación entre «login directo» y «reto 2FA» se extrae a un servicio (`App\Services\Auth\LoginFlow`), que comparten la contraseña y el login social.
- `TwoFactorChallengeController`, sin cambios.
- `redirect()->intended()`: lo usan ya la contraseña, la passkey y el 2FA, y es lo que devuelve al usuario a `/oauth/authorize`.
- Sanctum (`createToken`, abilities, `expires_at`), `App\Mcp\Abilities::presets()`, `App\Services\AuditLog` (canal `web`), `McpGuard`, `AddWwwAuthenticateHeader` del vendor (emite `resource_metadata` si existe la ruta con nombre `mcp.oauth.protected-resource.nested`) y la página `ApiKeys/Index`.
- Las páginas `/privacy` y `/terms` (`routes/web.php`), para el registro de la app de Meta.

**Nuevo, parte 1 (login social):**

- `laravel/socialite` y la sección de configuración `services.google_login` / `services.facebook_login`.
- Migración `social_identities` con estas columnas: `user_id`, `provider`, `provider_user_id` (único por proveedor), `email` en el momento de vincular, `linked_at`, `last_login_at` y `blocked_at`.
- `Auth\SocialLoginController` (`redirect` y `callback`) y `Profile\SocialIdentityController` (`link`, `unlink`).
- Rutas `GET auth/{provider}/redirect`, `GET auth/{provider}/callback` (`guest`, más el modo vincular si hay sesión) y `DELETE user/social/{provider}` (`password.confirm`).
- Vue: botones en `Auth/Login.vue` y el parcial `Profile/Partials/LinkedAccountsForm.vue` en `Profile/Edit.vue`.

**Nuevo, parte 2 (OAuth MCP, opción B):**

- Migraciones:
  - `oauth_clients`: `client_id` UUID, `name`, `redirect_uris` JSON, `software_id`/`software_version` si vienen, `created_ip`, `last_used_at`.
  - `oauth_auth_codes`: hash del código, `client_id`, `user_id`, `redirect_uri`, `code_challenge`, `abilities`, `resource`, `expires_at`, `used_at`, `token_id`.
  - `personal_access_tokens.oauth_client_id`, nullable.
- Rutas fuera del grupo `web` (sin CSRF ni sesión): `GET /.well-known/oauth-protected-resource[/mcp]`, `GET /.well-known/oauth-authorization-server[/…]`, `POST /oauth/register` y `POST /oauth/token`.
- Rutas en el grupo `web` + `auth`: `GET /oauth/authorize` (consentimiento) y `POST /oauth/authorize` (aprobar o denegar, con CSRF).
- Controladores `Mcp\OAuth\{MetadataController, RegisterController, AuthorizeController, TokenController}` y un servicio `Mcp\OAuth\AuthorizationServer` (validación, PKCE, emisión del token).
- Vue: `OAuth/Authorize.vue` (consentimiento) y la columna «Origen» en `ApiKeys/Index`.
- El comando `mcp:oauth-prune` y su programación.

## Flujo (paso a paso)

**A. Login social (sin 2FA)**

1. En `/login` el usuario pulsa «Continuar con Google». Se llama a `GET auth/google/redirect`, Socialite guarda `state` en sesión y redirige a Google con `openid email profile`.
2. Google vuelve a `GET auth/google/callback?code&state`. Se valida `state` y se canjea el código para obtener `sub`, `email` y `email_verified`.
3. Se resuelve el usuario. Si existe una identidad `(google, sub)` no bloqueada, se usa su usuario. Si no existe, se busca un email verificado en ambos lados con una cuenta local verificada, se vincula la identidad y se audita `social.linked` (auto). En cualquier otro caso se rechaza.
4. `LoginFlow`: sin 2FA, `Auth::login` + `regenerate` + `redirect()->intended('dashboard')`. Se audita `auth.social.login`.

**B. Login social con 2FA**

1. Igual que A hasta el paso 3.
2. `LoginFlow` guarda `login.id`, `login.remember=false` y `login.at` y redirige a `two-factor.challenge`.
3. Con el código correcto, el usuario sigue a `intended`.

**C. Vincular o desvincular desde el perfil**

1. *Perfil → Cuentas vinculadas → Vincular Google o Facebook*. Pide `password.confirm`, deja en sesión `social.intent=link` y redirige al proveedor.
2. En el callback, con sesión iniciada e `intent=link`: si la identidad ya pertenece a otro usuario, se muestra un error y no cambia nada. Si no, se vincula (o se desbloquea) y se audita `social.linked`.
3. *Desvincular* (con `password.confirm`) marca `blocked_at` y audita `social.unlinked`.

**D. Conexión de un cliente MCP por OAuth**

1. El cliente llama a `POST /mcp` sin token y recibe `401` con `WWW-Authenticate: Bearer realm="mcp", resource_metadata="https://APP_URL/.well-known/oauth-protected-resource/mcp"`.
2. Con `GET` de la PRM obtiene `authorization_servers: [APP_URL]` y, con `GET /.well-known/oauth-authorization-server`, los endpoints.
3. `POST /oauth/register` con `redirect_uris` devuelve `201` con `client_id` (cliente público, `token_endpoint_auth_method: none`).
4. El cliente abre el navegador en `/oauth/authorize?response_type=code&client_id&redirect_uri&code_challenge&code_challenge_method=S256&state&scope=mcp:use&resource=APP_URL/mcp`.
5. Sin sesión, `auth` redirige a `/login` y guarda `url.intended`. El usuario entra con contraseña, Google, Facebook o passkey, y supera el 2FA si lo tiene. Vuelve a `/oauth/authorize`.
6. Consentimiento: nombre del cliente (marcado como «declarado por el cliente»), host de redirección, presets y casillas (por defecto `read`). Si aprueba, se crea el código (300 s, un solo uso) y se redirige a `redirect_uri?code&state`. Si deniega, a `redirect_uri?error=access_denied&state`.
7. `POST /oauth/token` (`grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `code_verifier`, `resource`) valida y emite un token Sanctum `msk_…` con las abilities elegidas, `expires_at = now + MCP_OAUTH_TOKEN_DAYS` y `oauth_client_id`, y revoca el token anterior de ese (usuario, cliente). La respuesta es `{access_token, token_type: "Bearer", expires_in, scope}` con `Cache-Control: no-store`.
8. El cliente usa el Bearer en `/mcp` por el mismo camino `auth:sanctum` que las API keys.

## Alcance

- **Dentro (esta iteración):**
  - Login social con Google y Facebook para cuentas existentes, con vinculación automática (solo Google con email verificado) y vinculación manual desde el perfil.
  - Integración con el reto 2FA existente y con `intended`.
  - Vincular y desvincular desde el perfil, auditoría y limitadores.
  - AS OAuth 2.1 para el MCP: PRM, ASM, DCR, authorize con consentimiento y presets, token con PKCE S256, `WWW-Authenticate` con `resource_metadata` y validación de `resource` (RFC 8707).
  - Los tokens OAuth aparecen y se revocan en *API keys*, con reautorización que sustituye al token anterior y purga de clientes sin uso.
  - Coexistencia con las API keys `msk_`: sin cambios para quien ya las usa.
  - Documentación de instalación sin API key para Claude Code CLI, Claude Desktop, Codex y OpenCode, más la guía de pasos manuales (Google Cloud, Meta, Nginx/Hestia).
  - Tests Pest y E2E Playwright con un proveedor social falso en el entorno E2E, y axe en las pantallas nuevas.
- **Fuera (siguientes specs):**
  - Apple, GitHub y otros proveedores.
  - Alta de cuentas por login social.
  - Refresh tokens y rotación (se valorarán si algún cliente lo exige ⚠️ verificar).
  - Client ID Metadata Documents (CIMD), que la spec MCP 2025-11-25 recomienda frente a DCR. Se añadirán cuando los cuatro clientes lo usen.
  - Confirmar la contraseña con el proveedor social en lugar de con la contraseña (`password.confirm` sigue pidiéndola).
  - CORS para clientes MCP que corren en el navegador (MCP Inspector web).
  - Clientes confidenciales y `client_credentials`.
  - Introspección o revocación por endpoint (RFC 7662/7009): se revoca desde la UI.

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| El usuario cancela en Google o Facebook (`error=access_denied`) | Vuelve a `/login` con «Has cancelado el inicio de sesión con Google». Sin sesión y sin 500. |
| `state` no válido o caducado | Vuelve a `/login` con «La sesión de inicio ha caducado, inténtalo de nuevo». Auditoría `auth.social.login` DENIED. |
| Proveedor sin email, email no verificado o Facebook sin vincular | Mensaje genérico: «No hay ninguna cuenta vinculada a esta identidad. Vincúlala desde tu perfil». Nunca revela si el email existe. |
| Email sin cuenta local | El mismo mensaje genérico. No se crea ninguna cuenta. |
| Identidad ya vinculada a otro usuario (al vincular) | Error en el perfil y ningún cambio. Auditoría DENIED. |
| Proveedor sin configurar | El botón no se muestra y la ruta responde 404. |
| Proveedor caído o error al canjear el código | Vuelve a `/login` con «No se ha podido contactar con Google». Se registra en log sin el código. |
| DCR con redirect no permitida | `400 {"error":"invalid_redirect_uri"}`. |
| DCR con metadatos inválidos | `400 {"error":"invalid_client_metadata"}`. |
| DCR por encima del límite | `429` con `Retry-After`. |
| Authorize con `client_id` o `redirect_uri` no válidos | Página de error propia. **Nunca** redirige. |
| Authorize sin PKCE, con `plain` o con `response_type` distinto de `code` | Redirige a `redirect_uri?error=invalid_request&state`. |
| `resource` distinto de `APP_URL/mcp` | `invalid_target`. |
| Token con código caducado, reutilizado, verifier erróneo, otro `redirect_uri` o `client_id` | `400 {"error":"invalid_grant"}`. Si el código se reutiliza, se revoca el token emitido con él. |
| Token con `grant_type` no soportado | `400 {"error":"unsupported_grant_type"}`. |
| `/mcp` con token caducado o revocado | `401` con `WWW-Authenticate … resource_metadata`, y el cliente vuelve a autorizar. |
| Token OAuth sin la ability necesaria | La misma denegación que una API key sin permiso (comportamiento actual de `SchedulerTool`). |

## Criterios de aceptación

**Login social**

- [ ] [GWT] CA-01: **Dado** un usuario sin 2FA con la identidad Google vinculada, **cuando** completa el login con Google, **entonces** llega al dashboard autenticado y queda en `audit_logs` `auth.social.login` OK con `channel=web`.
- [ ] [GWT] CA-02: **Dado** un usuario con email verificado y sin identidad Google, **cuando** entra con Google y el proveedor devuelve el mismo email con `email_verified=true`, **entonces** se crea la fila en `social_identities`, se audita `social.linked` (auto) y queda autenticado.
- [ ] [GWT] CA-03: **Dado** que Google devuelve `email_verified=false` o no devuelve email, **cuando** termina el callback, **entonces** no se abre sesión, se muestra el mensaje genérico de vincular desde el perfil, no se crea ninguna identidad y se audita DENIED.
- [ ] [GWT] CA-04: **Dado** un email del proveedor sin cuenta local, **cuando** termina el callback (con `REGISTRATION_ENABLED` a `true` o a `false`), **entonces** el número de `users` no cambia y se muestra el mensaje genérico.
- [ ] [GWT] CA-05: **Dado** un usuario cuyo email coincide con el de Facebook pero sin identidad Facebook vinculada, **cuando** entra con Facebook, **entonces** se rechaza con el mensaje de vincular desde el perfil.
- [ ] [GWT] CA-06: **Dado** un usuario con 2FA activo, **cuando** vuelve de Google o Facebook con una identidad válida, **entonces** se le redirige a `/two-factor-challenge` sin sesión autenticada y, tras un código válido, llega al destino `intended`.
- [ ] [GWT] CA-07: **Dado** un usuario autenticado que ha confirmado la contraseña, **cuando** vincula Facebook desde *Perfil → Cuentas vinculadas*, **entonces** la identidad aparece en la lista. Si ya pertenece a otro usuario, se muestra el error y no cambia nada.
- [ ] [GWT] CA-08: **Dado** un usuario con Google vinculado, **cuando** lo desvincula, **entonces** el login con esa identidad ya no entra y la vinculación automática por email no la vuelve a enlazar hasta que la vincule a mano.
- [ ] [GWT] CA-09: **Dado** el login con Google, **cuando** el usuario cancela en el proveedor o el `state` no coincide, **entonces** vuelve a `/login` con un mensaje legible y sin error 500.
- [ ] [GWT] CA-10: **Dado** que faltan `GOOGLE_LOGIN_CLIENT_ID` o `META_LOGIN_APP_ID`, **cuando** se carga `/login`, **entonces** no se muestra el botón de ese proveedor y `auth/{provider}/redirect` responde 404.
- [ ] [GWT] CA-11: **Dado** más de 20 callbacks sociales por minuto desde una IP, **cuando** llega el siguiente, **entonces** se responde 429.

**OAuth del MCP**

- [ ] [GWT] CA-12: **Dado** el servidor en marcha, **cuando** se pide `GET /.well-known/oauth-protected-resource/mcp` (y la variante raíz), **entonces** responde 200 JSON con `resource = APP_URL/mcp`, `authorization_servers = [APP_URL]` y `scopes_supported = ["mcp:use"]`.
- [ ] [GWT] CA-13: **Dado** el servidor en marcha, **cuando** se pide `GET /.well-known/oauth-authorization-server`, **entonces** responde con `issuer`, `authorization_endpoint`, `token_endpoint`, `registration_endpoint`, `response_types_supported=["code"]`, `grant_types_supported=["authorization_code"]`, `code_challenge_methods_supported=["S256"]` y `token_endpoint_auth_methods_supported=["none"]`.
- [ ] [GWT] CA-14: **Dado** un `POST /mcp` sin Bearer o con un Bearer no válido, **cuando** se procesa, **entonces** responde 401 con `WWW-Authenticate: Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/mcp"`.
- [ ] [GWT] CA-15: **Dado** `POST /oauth/register` con `redirect_uris=["http://127.0.0.1:53111/callback"]`, **cuando** se procesa, **entonces** responde 201 con `client_id` y `token_endpoint_auth_method: "none"`. Con `https://evil.example/cb` responde 400 `invalid_redirect_uri`, y a partir de 10 registros por hora desde una IP, 429.
- [ ] [GWT] CA-16: **Dado** un navegador sin sesión, **cuando** abre una URL `/oauth/authorize` válida, **entonces** se le redirige a `/login`. Tras entrar con contraseña, Google, Facebook o passkey (más el 2FA si está activo), vuelve a la pantalla de consentimiento con los mismos parámetros.
- [ ] [GWT] CA-17: **Dado** un authorize sin `code_challenge`, con `code_challenge_method=plain` o con `response_type=token`, **cuando** se procesa, **entonces** se redirige a `redirect_uri` con `error=invalid_request` y el `state`. Con `client_id` o `redirect_uri` no registrados se muestra una página de error **sin redirigir**.
- [ ] [GWT] CA-18: **Dado** el consentimiento, **cuando** se muestra, **entonces** enseña el nombre del cliente marcado como declarado, el host de redirección y los presets `read`, `publish` y `all` con casillas, con `read` marcado por defecto. Además lleva `X-Frame-Options: DENY` o `frame-ancestors 'none'`.
- [ ] [GWT] CA-19: **Dado** el consentimiento, **cuando** el usuario aprueba con el preset `publish`, **entonces** se le redirige a `redirect_uri?code=…&state=…`. Si deniega, a `redirect_uri?error=access_denied&state=…`.
- [ ] [GWT] CA-20: **Dado** un código válido, **cuando** el cliente llama a `POST /oauth/token` con el `code_verifier` correcto, **entonces** recibe 200 con `access_token` (prefijo `msk_`), `token_type=Bearer`, `expires_in` (30 días) y `scope`, más `Cache-Control: no-store`. El token tiene exactamente las abilities elegidas y `oauth_client_id`.
- [ ] [GWT] CA-21: **Dado** un código, **cuando** se canjea con un verifier erróneo, ya usado, caducado (más de 300 s) o con otro `redirect_uri` o `client_id`, **entonces** responde 400 `invalid_grant`. Si el código ya se había canjeado, el token emitido con él queda revocado.
- [ ] [GWT] CA-22: **Dado** un `resource` distinto de `APP_URL/mcp` en authorize o token, **cuando** se procesa, **entonces** responde `invalid_target`.
- [ ] [GWT] CA-23: **Dado** un token OAuth con solo `read`, **cuando** el cliente llama a la tool `create_post`, **entonces** se deniega igual que con una API key sin `posts:write`.
- [ ] [GWT] CA-24: **Dado** una API key `msk_` creada desde *API keys*, **cuando** se usa en `/mcp` después del cambio, **entonces** funciona igual que antes (la suite MCP actual sigue en verde).
- [ ] [GWT] CA-25: **Dado** un token emitido por OAuth, **cuando** el usuario abre *API keys*, **entonces** lo ve con el origen «OAuth · <cliente>» y puede revocarlo. Después de revocarlo, `/mcp` responde 401.
- [ ] [GWT] CA-26: **Dado** un usuario que ya autorizó un cliente, **cuando** lo vuelve a autorizar, **entonces** el token anterior de ese (usuario, cliente) queda revocado y solo vive el nuevo.
- [ ] [GWT] CA-27: **Dado** cualquier evento OAuth o social (`oauth.client.registered`, `oauth.authorized`, `oauth.denied`, `oauth.token.issued`, `oauth.code.replayed`, `auth.social.login`, `social.linked`, `social.unlinked`), **cuando** se registra en `audit_logs`, **entonces** no contiene códigos, tokens, verifiers ni secretos.
- [ ] [GWT] CA-28: **Dado** `McpGuard`, **cuando** llega a `/mcp` un `Origin` no permitido, **entonces** sigue respondiendo 403. Los endpoints `/oauth/*` y `/.well-known/*` responden sin exigir `Origin`.

**Proceso**

- [ ] CA-29: Smoke manual documentado: Claude Code CLI, Claude Desktop, Codex y OpenCode se conectan contra el entorno ngrok solo con el navegador, sin pegar ninguna API key. Se registran en `testing/` el comando o la configuración usados y la versión de cada cliente.
- [ ] CA-30: Actualizados `docs/modulos/mcp.md` (instalación OAuth y API key), `docs/modulos/despliegue.md` (redirect URIs, `/.well-known` y `Authorization` en Nginx/Hestia), `.env.example` y la guía de pasos manuales de Google y Meta.
- [ ] CA-31: Comando `mcp:oauth-prune` programado y probado: borra los clientes sin token vivo durante 30 días y sus códigos caducados.
- [ ] CA-32: ADR-004 (servidor de autorización MCP: propio sobre Sanctum o Passport) redactada y aceptada antes de implementar C-02.

## Pruebas

- **Pest (Feature):**
  - Login social con `Socialite::fake()` o un mock del driver, para cada CA-01..CA-11.
  - Endpoints OAuth: metadatos, DCR, authorize, token y negativos (CA-12..CA-28).
  - Regresión completa del MCP con `msk_` (CA-24).
  - Test de flujo completo: register → authorize (actingAs) → token → `tools/call` en `/mcp`.
- **Pest (Unit):** verificación PKCE S256 con los vectores del RFC 7636 §B, normalización de redirect loopback (puerto variable) y política de vinculación.
- **Playwright (E2E):**
  - Proveedor social falso solo en `APP_ENV=e2e` (driver Socialite `fake` registrado por config). Nunca se registra en producción y lo cubre un test que lo comprueba.
  - Login Google sin 2FA y con 2FA.
  - Flujo OAuth MCP en el navegador: el test genera el verifier, abre authorize, inicia sesión, consiente, captura el redirect a loopback y canjea con `request`.
  - axe en Login, Cuentas vinculadas y Consentimiento.
- **Manual:** CA-29 con los cuatro clientes, y el login real con Google y Facebook (las apps de verdad) en ngrok.

## Referencias

- `routes/ai.php:8`: `Mcp::web('/mcp', SchedulerServer::class)->middleware(['auth:sanctum', 'throttle:mcp'])`.
- `vendor/laravel/mcp/src/Server/Registrar.php`:
  - `oauthRoutes()` registra PRM, ASM y `POST oauth/register`.
  - `authorizationServerMetadata()` exige `route('passport.authorizations.authorize')` y `route('passport.token')`.
  - El único scope es `OAUTH_SCOPE = 'mcp:use'`.
- `vendor/laravel/mcp/src/Server/Http/Controllers/OAuthRegisterController.php`: crea clientes con `Laravel\Passport\ClientRepository` y devuelve 500 si Passport no está instalado.
- `vendor/laravel/mcp/src/Server/Middleware/AddWwwAuthenticateHeader.php`: `resource_metadata` solo si existe la ruta `mcp.oauth.protected-resource.nested`; si no, `error="invalid_token"`.
- `vendor/laravel/mcp/config/mcp.php`: `redirect_domains => ['*']` (default inseguro), `custom_schemes` y `authorization_server`.
- `composer.json`: no están ni `laravel/passport` ni `laravel/socialite`. `laravel/mcp ~1.0.2`, `laravel/sanctum ^4.0`, `laravel/passkeys ~0.2.1`.
- `app/Http/Middleware/McpGuard.php`: `Origin`, límite por IP y log de los 401, solo en `mcp`.
- `app/Mcp/Abilities.php` (presets) y `app/Http/Controllers/ApiKeyController.php` (emisión, caducidad y auditoría).
- `app/Services/AuditLog.php:25` y `database/migrations/2026_10_24_090000_create_audit_logs_table.php:15`: `token_id` es `unsignedBigInteger`, que cuadra con Sanctum y no con los ids de texto de Passport.
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: bifurcación 2FA, con `login.id`, `login.remember` y `login.at`.
- `config/services.php`, claves `meta` y `google`: apps de conexión de redes. GOT-001: permisos por caso de uso en Meta.
- `docs/knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md` y `docs/modulos/despliegue.md` §ngrok.
- Spec MCP de autorización 2025-11-25, RFC 6749, 7591, 7636, 8252, 8414, 8707 y 9728, y el borrador OAuth 2.1.

## Pasos manuales del usuario

**Google (login)**

1. En Google Cloud Console, crea un **proyecto dedicado** («Metricool Login»). Así el consentimiento y la verificación no se mezclan con el proyecto de YouTube, que tiene scopes sensibles.
2. En *Google Auth Platform → Branding*, rellena el nombre, el email de soporte, el dominio autorizado (el de producción y `ngrok-free.app` ⚠️ verificar que lo admite), y los enlaces `/privacy` y `/terms`.
3. Scopes: `openid`, `email` y `profile`. No son sensibles y no necesitan verificación.
4. Audiencia: «External». En modo *Testing* solo entran los test users (hasta 100), lo que basta para un uso personal. Para abrirlo a cualquiera, publícalo (sin revisión con scopes no sensibles ⚠️ verificar).
5. En *Clients → Create client → Web application*, añade como redirect URIs `https://workable-humorous-rat.ngrok-free.app/auth/google/callback` y `https://<dominio-prod>/auth/google/callback`.
6. Copia el ID y el secreto en `.env` como `GOOGLE_LOGIN_CLIENT_ID` y `GOOGLE_LOGIN_CLIENT_SECRET`.

**Meta (login)**

1. En developers.facebook.com, crea una **app nueva** con el caso de uso «Autenticar y solicitar datos a los usuarios con Inicio de sesión con Facebook» (consumidor). No uses la app de conexiones (*Facebook Login for Business*) ⚠️ verificar que no admite los dos casos de uso.
2. Dentro del caso de uso, añade los permisos `email` y `public_profile` (GOT-001: si no están dentro del caso de uso, aparece «Invalid Scopes»).
3. En *Configuración → Básica*: dominios de la app (ngrok y producción), URL de la política de privacidad (`/privacy`), condiciones (`/terms`), URL de instrucciones de eliminación de datos, icono, categoría y plataforma «Sitio web».
4. En *Inicio de sesión con Facebook → Configuración*: URIs de redirección OAuth válidas `https://<ngrok>/auth/facebook/callback` y `https://<dominio-prod>/auth/facebook/callback`, con «Usar modo estricto» y «Forzar HTTPS» activados.
5. **Modo de la app:** en *Desarrollo* solo entran los usuarios con rol en la app (administrador, desarrollador o tester), sin app review. Recomendado si los usuarios son pocos y conocidos. Para *Live* hace falta acceso avanzado a `email` y probablemente la verificación de la empresa ⚠️ verificar.
6. Copia el ID y el secreto en `.env` como `META_LOGIN_APP_ID` y `META_LOGIN_APP_SECRET`.

**Servidor y clientes MCP**

1. Nginx/Hestia:
   - `/.well-known/oauth-*` tiene que llegar a PHP. Las plantillas de Hestia sirven `/.well-known/acme-challenge` desde disco; comprueba que no se trague el resto.
   - La cabecera `Authorization` tiene que llegar a PHP-FPM (pendiente M-F6-2).
2. Registra en `.env` `MCP_OAUTH_TOKEN_DAYS` y, si hace falta, `MCP_OAUTH_REDIRECT_ALLOWED`.
3. Para Claude Desktop (conectores personalizados) el servidor debe ser accesible desde Internet (ngrok o producción) y el plan de Claude debe admitir conectores remotos ⚠️ verificar.
4. Configura cada cliente con la URL `https://<APP_URL>/mcp` sin cabecera `Authorization`. Los comandos exactos irán a `docs/modulos/mcp.md`:
   - Claude Code: `claude mcp add --transport http …` y después `/mcp` para autenticarse.
   - Codex: `codex mcp add … --url …` y después `codex mcp login …`.
   - OpenCode: entrada `remote` en `opencode.json` y después `opencode mcp auth …`.

   ⚠️ Verificar los tres comandos en la versión actual de cada cliente.

## Decisiones confirmadas (revisión del usuario · 2026-10-22)

1. Solo cuentas existentes, sin alta (`REGISTRATION_ENABLED=false`). **Confirmado.**
2. Con 2FA activo, tras el login social se pide el reto 2FA. El login social sustituye solo a la contraseña. **Confirmado.**
3. MCP con OAuth 2.1 según la spec MCP: PRM, ASM, PKCE, DCR y 401 con `WWW-Authenticate`. Las API keys `msk_` y las abilities y presets se mantienen. **Confirmado.**

## Supuestos

- **Decidido por el usuario (2026-10-22), P1:** Google vincula automáticamente solo con `email_verified=true` y cuenta local verificada; Facebook solo vinculando a mano desde el perfil; sin email o sin verificar, rechazo.
- **Decidido por el usuario (2026-10-22), P2:** servidor de autorización propio mínimo que emite tokens Sanctum `msk_` (opción B), no Passport; se formaliza en ADR-004.
- **Decidido por el usuario (2026-10-22), P3:** consentimiento con presets read/publish/all editables (read por defecto); token de 30 días sin refresh, revocable desde API keys.
- **P4 (por defecto, recomendación del evaluator):** proyecto de Google y app de Meta propios para el login, separados de los que conectan redes.
- Claude Code, Claude Desktop, Codex y OpenCode admiten DCR, PKCE S256 y el parámetro `resource`, y aceptan tokens sin `refresh_token` (vuelven a autorizar al caducar). ⚠️ verificar en el smoke CA-29. Si alguno exige refresh token, se presupuesta aparte (≈ +8h, ver evaluación).
- Los callbacks de Claude Desktop y Claude web son `https://claude.ai/api/mcp/auth_callback` y `https://claude.com/api/mcp/auth_callback`; Claude Code, Codex y OpenCode usan loopback `http://localhost` o `127.0.0.1` con puerto variable. ⚠️ verificar. Lo confirma CA-29 y lo ajusta `MCP_OAUTH_REDIRECT_ALLOWED`.
- La interstitial de ngrok free («You are about to visit…») solo afecta a navegadores, una vez por dominio. Las peticiones de máquina (token, register, `/mcp`) pasan sin ella. ⚠️ verificar. Si no, dominio reservado o cabecera `ngrok-skip-browser-warning`.
- El dominio de producción todavía no está fijado. Las redirect URIs de producción se añaden en el despliegue.
- Los usuarios actuales tienen contraseña, así que `password.confirm` sigue funcionando para vincular y desvincular.
