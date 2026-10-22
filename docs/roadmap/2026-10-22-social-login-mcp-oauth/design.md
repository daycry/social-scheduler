---
design: "social-login-mcp-oauth"
titulo: "Login social (Google y Facebook) y OAuth 2.1 del servidor MCP"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-22"
actualizado: "2026-10-22"
spec: spec.md                 # enlace hacia atrás (misma carpeta)
evaluacion: "evaluation.md"
plan: pendiente               # improvement-plan.md cuando el planner lo cree
adr: "docs/knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md"
opcion_elegida: "O1"          # validada por el usuario el 2026-10-22 (vía orquestador, sin variante)
generacion:
  inicio: 2026-10-22T19:05:26Z   # pasada 1 (la pasada 2 abre el marcador design.md#pasada2)
  fin: 2026-10-22T19:12:19Z
  fuente: medido
  tokens_reales: { entrada: 1543, salida: 27692, cache_creacion: 115503, cache_lectura: 2022462 }
  eur: null                   # precioTokens no fiable (sin .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.48
  duracion: 29m               # reloj: 7m
  ratio_usado: 300000         # default no calibrado
  pasada2: { inicio: 2026-10-22T19:23:59Z, fin: 2026-10-22T19:25:15Z, fuente: medido, tokens_reales: { entrada: 16, salida: 6300, cache_creacion: 13879, cache_lectura: 1033571 }, eur: null, horas_ia: 0.07, duracion: 4m }
---

# Diseño: login social (Google y Facebook) y OAuth 2.1 del servidor MCP

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) · **Plan:** pendiente · **ADR:** [ADR-004](../../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md) (propuesta)

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | O1, Socialite y estado del servidor de autorización en tablas |
| **Validada por el usuario** | sí, el 2026-10-22 (vía `/dev-cycle`, `elegida: O1`, sin variante) |

## 1. Contexto y restricciones

La spec pide dos cosas. La primera es el login con Google y Facebook para cuentas que ya existen, con el reto 2FA intacto (CA-01..CA-11). La segunda es que `/mcp` se pueda autorizar por OAuth 2.1 (PRM, ASM, DCR, PKCE S256 y 401 con `WWW-Authenticate`), emitiendo tokens Sanctum `msk_` sin romper las API keys (CA-12..CA-28).

Qué hay hoy en el repo:

- `routes/ai.php:8`: `Mcp::web('/mcp', …)->middleware(['auth:sanctum', 'throttle:mcp'])`. El vendor carga este fichero con `Route::group([], …)` (`vendor/laravel/mcp/src/Server/McpServiceProvider.php:96`), es decir, **fuera** del grupo `web`: sin sesión ni CSRF.
- `vendor/laravel/mcp/src/Server/Middleware/AddWwwAuthenticateHeader.php`: ya es middleware global (`McpServiceProvider.php:54`) y además va en la ruta de `Mcp::web` (`Registrar.php:52-56`). Ante un 401 en `/mcp`, emite `Bearer realm="mcp", resource_metadata="<route('mcp.oauth.protected-resource.nested', ['path' => 'mcp'])>"` **solo si existe una ruta con ese nombre**. Si no existe, emite `error="invalid_token"`. No depende de Passport.
- `Registrar::oauthRoutes()` (`Registrar.php:124-158`) registra PRM, ASM y DCR. Su ASM llama a `route('passport.authorizations.authorize')` y `route('passport.token')`, anuncia `refresh_token` y `client_secret_*` y **siempre** registra la variante anidada `/.well-known/oauth-authorization-server/{path}`. `OAuthRegisterController` devuelve 500 sin Passport. Del vendor solo sirven, sin Passport, el middleware del 401 (por contrato de nombre de ruta) y la constante `Registrar::OAUTH_SCOPE = 'mcp:use'`.
- `app/Http/Middleware/McpGuard.php`: middleware global que solo actúa si `$request->is('mcp')`. Valida `Origin`, limita por IP y registra los 401.
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php:35-52`: la bifurcación del 2FA (`login.id`, `login.remember`, `login.at` y redirección a `two-factor.challenge`) está dentro de `store`. `TwoFactorChallengeController` lee esas claves y cierra el login con `redirect()->intended()`. `PasskeyLoginController` las borra, porque la passkey sustituye a la contraseña y al 2FA (ADR-003).
- `config/sanctum.php`: `guard => []` (solo Bearer), `token_prefix => 'msk_'`, `expiration => null`. La tabla `personal_access_tokens` es una migración propia del repo (`database/migrations/2026_10_19_223906_create_personal_access_tokens_table.php`). `ApiKeyController::index` lista `$user->tokens()`.
- `app/Connectors/Google/GoogleConnector.php` y `app/Connectors/Meta/MetaConnector.php` implementan el contrato `Connector` (conectar cuentas de publicación: `ConnectionCandidate`, `refresh(SocialAccount)`) con las credenciales `services.google`/`services.meta`. Sus helpers HTTP (`GoogleConnector::token/parse`, `MetaConnector::exchange`, secretos en el cuerpo, CWE-598) son privados. `app/Connectors/Meta/GraphClient.php` es público (`get($path, $query, $token)`) y está pensado para publicar.
- `deploy/hestia/nginx-overrides.conf`: `location ~ /\.(?!well-known)` ya deja pasar `/.well-known`, y `add_header X-Frame-Options "SAMEORIGIN" always`. `public/.htaccess:8-10` reinyecta `Authorization` si hay Apache delante.
- `config/cache.php`: `CACHE_STORE=database`. `deploy/hestia/deploy.sh:126` recomienda `optimize:clear` en la recuperación, que vacía la caché de aplicación.

**Restricciones que fijan el espacio de soluciones**

- Decisiones del usuario, que no se reabren: P1 (Google vincula automáticamente solo con `email_verified=true` y cuenta local verificada; Facebook solo a mano; sin email o sin verificar, rechazo), P2 (servidor de autorización propio y mínimo que emite tokens Sanctum `msk_`, **sin Passport**), P3 (presets read/publish/all editables con read por defecto; token de 30 días sin refresh, revocable desde *API keys*), P4 (apps de Google y Meta propias para el login), solo cuentas existentes y 2FA tras el login social.
- ADR-003 (`aceptada`): Breeze es el dueño del login, `LoginRequest::validateCredentials()` valida sin abrir sesión y el reto 2FA cierra el login. La passkey no pasa por el 2FA; el login social sí.
- ADR-001 (`propuesta`): la autenticación del MCP sale del ecosistema oficial y se despliega en HestiaCP (Nginx + PHP-FPM).
- Presupuesto de la evaluación: 116,4h con la opción B de C-02. No hay `docs/CONSTITUTION.md`.

**Decisiones comunes a las tres opciones** (solo hay una salida razonable en cada una, así que no se presentan como opciones):

| Eje | Decisión | Alternativa descartada y motivo |
|---|---|---|
| 2FA tras el login social | Extraer de `AuthenticatedSessionController::store` un servicio `App\Services\Auth\LoginFlow::complete($request, $user, $remember)`: con 2FA deja `login.*` y redirige a `two-factor.challenge`; sin 2FA, `Auth::login` + `regenerate` + `intended`. Lo usan la contraseña y el callback social (este con `remember=false`). `TwoFactorChallengeController` y `PasskeyLoginController` no cambian. | Copiar la bifurcación en el controlador social: dos copias de lógica de seguridad que acaban divergiendo. Un `PendingLogin` con el método de entrada: obliga a tocar `TwoFactorChallengeController` sin que lo pida ningún criterio. |
| Callback social | Una sola ruta `GET auth/{provider}/callback` **sin** `guest` que se bifurca por `social.intent` en sesión (login o vincular). El proveedor admite una única redirect URI por app. | Dos callbacks (uno `guest` y otro `auth`): duplica las redirect URIs que el usuario registra en Google y Meta. |
| Marca del token OAuth | Columna `personal_access_tokens.oauth_client_id` (UUID, nullable, `nullOnDelete`). *API keys* muestra «OAuth · <cliente>» con un modelo `App\Models\PersonalAccessToken` que extiende el de Sanctum (relación `oauthClient`), registrado con `Sanctum::usePersonalAccessTokenModel`. La reautorización revoca en una transacción los tokens vivos de ese (usuario, cliente). | Tabla puente `oauth_client_tokens`: una join y una sincronización más al revocar, sin ventaja (la migración de Sanctum ya es nuestra). Codificarlo en `name` o en una ability ficticia: frágil, y la ability ensucia `tokenCan`. |
| 401 con `WWW-Authenticate` | Se reutiliza `AddWwwAuthenticateHeader` del vendor sin tocarlo: basta con que nuestra ruta de la PRM se llame `mcp.oauth.protected-resource.nested` y tenga `{path}` con `where('path', '.*')`. Sale exactamente el formato de CA-14. | Middleware propio: duplica lo que el vendor ya hace y ya está registrado como global. |
| Metadatos PRM y ASM | `App\Http\Controllers\Mcp\OAuth\MetadataController` propio con los valores de CA-12 y CA-13 y `scopes_supported = [Registrar::OAUTH_SCOPE]`. | `Mcp::oauthRoutes(registration: false)` con nuestras rutas llamadas `passport.*`: el ASM del vendor anuncia `refresh_token` y `client_secret_*` (contradice CA-13), la variante anidada no se puede sustituir y los nombres `passport.*` engañarían a quien instale Passport después. |
| Dónde viven las rutas | `/.well-known/oauth-protected-resource[/{path}]`, `/.well-known/oauth-authorization-server`, `POST /oauth/register` y `POST /oauth/token` en `routes/ai.php`, junto a `Mcp::web` (sin sesión ni CSRF). `GET|POST /oauth/authorize` en `routes/web.php` con `auth` (sesión, CSRF, `url.intended`). | `bootstrap/app.php` `then:` (como `/health`): dispersa el MCP en dos sitios. Todo en `web.php`: el token endpoint heredaría CSRF y sesión. |
| `McpGuard` y `Origin` | Sin cambios. Solo actúa en `mcp`; las rutas OAuth y `/.well-known` quedan fuera por construcción. Un test fija CA-28. | Validar `Origin` en `/oauth/token`: los CLI y el backend de Claude no lo mandan, y no hay CORS en alcance. |
| Nginx y Hestia | En `deploy/hestia/nginx-overrides.conf`, añadir `location ^~ /.well-known/oauth- { try_files $uri /index.php?$query_string; }` para que ninguna `location` de Hestia (acme-challenge) lo capture. `Authorization` llega a PHP-FPM de forma nativa con Nginx y, si hay Apache, `public/.htaccess` ya la reinyecta. Hay que añadir `curl` de verificación en `docs/modulos/despliegue.md` (cierra la parte de M-F6-2). La pantalla de consentimiento envía `Content-Security-Policy: frame-ancestors 'none'` desde la app, no un segundo `X-Frame-Options`. | Un `X-Frame-Options: DENY` desde la app: choca con el `SAMEORIGIN` global de Nginx (dos valores en conflicto). `frame-ancestors` prevalece sobre XFO. |

## 2. Opciones (2-3)

Las opciones difieren en los dos ejes que de verdad están abiertos: **cómo se habla con Google y Facebook** y **dónde vive el estado efímero del servidor de autorización** (los códigos de autorización). Todo lo demás es la tabla común de arriba.

### O1: Socialite y estado del servidor de autorización en tablas (la spec al pie de la letra)

- **Login social:** `laravel/socialite` ^5.31 con los drivers de Google y Facebook, configurados con `services.google_login` y `services.facebook_login` (P4), y Facebook con `usingGraphVersion(config('social.meta.graph_version'))`. Los conectores de `app/Connectors` no se tocan.
- **Resolución del usuario:** `App\Services\Auth\SocialIdentityResolver` aplica P1 sobre la tabla `social_identities`. Usa `user['email_verified']` de Google y falla cerrado si falta.
- **Servidor de autorización:** tablas `oauth_clients` (DCR) y `oauth_auth_codes` (hash SHA-256 del código, `used_at` y `token_id`), más la columna común. El canje hace `UPDATE … SET used_at WHERE used_at IS NULL` (un solo uso atómico) y, si detecta replay, revoca `token_id` (CA-21). `mcp:oauth-prune` purga los clientes sin token vivo y los códigos caducados.
- **Tests:** mock del driver de Socialite en Pest. En E2E, un driver `fake` registrado solo con `APP_ENV=e2e`.

| Criterio | Valoración |
|---|---|
| Complejidad | Media. Sigue la forma de la spec y de la evaluación; las piezas nuevas son aditivas. |
| Riesgo | Medio. El riesgo de protocolo del servidor propio es el mismo en las tres. El propio de O1 es que Socialite cambie de forma o no exponga `email_verified` (se verifica al instalar; falla cerrado). |
| Coste relativo | **M** (referencia: es lo que presupuestó la evaluación). |
| Reversibilidad | Alta. Dependencia nueva aislada en dos controladores. Tablas nuevas y una columna nullable. Quitar el login social o el OAuth es desactivar las rutas por configuración. |

### O2: Cliente social propio sobre la capa HTTP de `app/Connectors` y estado en tablas

- **Login social sin dependencias nuevas:**
  - Google por OIDC a mano: `authorize` con `openid email profile` y `prompt=select_account`, canje en `oauth2.googleapis.com/token` y `sub`/`email`/`email_verified` desde el endpoint userinfo. No hace falta validar la firma del `id_token`, porque llega directamente del token endpoint por TLS.
  - Facebook: `dialog/oauth`, canje por POST con el secreto en el cuerpo (patrón de `MetaConnector::exchange`) y `/me?fields=id,name,email` con `appsecret_proof`, reutilizando `GraphClient::get`.
  - Reutilizarlos de verdad exige **extraer** los helpers privados de `GoogleConnector` y `MetaConnector` a un helper parametrizado por credenciales (`App\Connectors\Support\OAuthHttp`). Eso toca los conectores que publican. La alternativa es copiarlos y duplicarlos.
- **Servidor de autorización:** igual que O1 (tablas).
- **Tests:** `Http::fake`, como los 6 tests actuales de `tests/Feature/Connectors`. El driver falso de E2E pasa a ser un `IdentityProvider` falso propio.

| Criterio | Valoración |
|---|---|
| Complejidad | Media-Alta. Hay que mantener `state`, el mapeo de errores, el `#_=_` de Facebook y el cambio de versión de Graph, que Socialite ya resuelve. |
| Riesgo | Medio-Alto. Más código propio en un camino de autenticación y regresión posible en la publicación de YouTube y Meta si se extraen los helpers. A cambio, control total de `email_verified` y ninguna dependencia nueva. |
| Coste relativo | **L** (frente a M de O1: el cliente social propio y su batería de tests sustituyen a unas pocas líneas de configuración de Socialite). Desborda la partida C-01 de la evaluación. |
| Reversibilidad | Media. Si se extraen los helpers, deshacerlo vuelve a tocar los conectores. Pasar después a Socialite es sustituir dos clases. |

### O3: Socialite y códigos de autorización sin tabla (cifrados y con marca de uso en caché)

- **Login social:** igual que O1.
- **Servidor de autorización:** el código no se guarda.
  - Es `Crypt::encryptString` de `{client_id, user_id, redirect_uri, code_challenge, abilities, resource, exp, jti}`.
  - El un solo uso se garantiza con `Cache::add("oauth:code:{jti}", token_id, ttl)`, que es atómico en el store `database`.
  - La marca de caché guarda el `token_id` para revocarlo si hay replay.
  - Solo quedan `oauth_clients` y la columna común, y la purga solo afecta a los clientes.

| Criterio | Valoración |
|---|---|
| Complejidad | Media. Una tabla y una purga menos, pero la garantía de un solo uso pasa a depender de la caché. |
| Riesgo | Medio-Alto. `optimize:clear`/`cache:clear` (recomendado en `deploy/hestia/deploy.sh:126`) borra las marcas, y un código interceptado se puede canjear dos veces dentro de sus 300 s. Si alguien cambia `CACHE_STORE` a `file` o `array`, la garantía desaparece en silencio. Los códigos en curso no se pueden inspeccionar ni auditar. |
| Coste relativo | **S-M** (algo menos que O1: sin migración ni purga de códigos). |
| Reversibilidad | Alta. Pasar a tabla después es cambiar `AuthorizationServer::issueCode/redeemCode` sin tocar el contrato HTTP. |

## 3. Criterios de decisión

1. **Corrección y seguridad del flujo de autorización.** El código de un solo uso y la detección de replay (CA-21) tienen que ser duraderos y auditables. Pesa más que todo lo demás porque el servidor de autorización es propio (P2) y es el riesgo dominante de la evaluación.
2. **No causar regresiones** en el login con contraseña, passkey o 2FA (suite de ADR-003), en las API keys (CA-24) ni en los conectores que publican.
3. **Facilidad de prueba:** mocks sencillos en Pest y un driver falso solo en E2E.
4. **Reversibilidad:** la spec deja incógnitas en el comportamiento de los clientes (refresh, CIMD), así que conviene poder cambiar piezas sin migraciones dolorosas.
5. **Coste**, dentro del presupuesto de la evaluación. Menos peso: las diferencias entre opciones son pequeñas frente al total.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto:** O1, Socialite y estado del servidor de autorización en tablas.

- Es la única que cumple a la vez el criterio 1 (un solo uso con `UPDATE … WHERE used_at IS NULL` en BD, que sobrevive a `optimize:clear`, frente a O3) y el criterio 2 (no toca `app/Connectors`, frente a O2).
- Socialite es oficial y mantenido, y encaja con ADR-001, que pide que la auth salga del ecosistema oficial.
- Respeta las cifras de la evaluación sin reevaluar.

**O1: Socialite y estado del servidor de autorización en tablas.** La validó el usuario el 2026-10-22. Se ancla así a los criterios:

- **Criterio 1.** El código de un solo uso y la detección de replay quedan en BD (`oauth_auth_codes.used_at`, `token_id`): sobreviven a `optimize:clear` y se pueden auditar.
- **Criterio 2.** No toca `app/Connectors` ni el reto 2FA. `LoginFlow` se extrae sin cambiar el comportamiento.
- **Criterio 3.** Socialite se simula en Pest y tiene un driver falso solo en E2E.
- **Criterio 4.** Todo es aditivo (tablas nuevas y una columna nullable).
- **Criterio 5.** Coste M, el que presupuestó la evaluación: no hay que reevaluar.

Descartadas:

- **O2 (cliente social a mano sobre `app/Connectors`).** Cae por el criterio 2: extraer los helpers de `GoogleConnector` y `MetaConnector` pone en riesgo la publicación. Además cuesta L, que desborda C-01.
- **O3 (códigos cifrados con marca en caché).** Cae por el criterio 1: `optimize:clear` o un cambio de `CACHE_STORE` anula la garantía de un solo uso.

## 5. Impacto en módulos y ficheros

Impacto de la opción elegida (O1). Las diferencias de O2 y O3 se indican en la última fila.

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `composer.json` | `laravel/socialite` ^5.31 | modificado |
| `config/services.php`, `.env.example` | `google_login`, `facebook_login`; `mcp.oauth.*` (`token_days`, `code_ttl`, `redirect_allowed`) | modificado |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | `store` delega la bifurcación 2FA en `LoginFlow` (sin cambiar el comportamiento) | modificado |
| `app/Services/Auth/LoginFlow.php` | bifurcación login directo / reto 2FA compartida | nuevo |
| `app/Services/Auth/SocialIdentityResolver.php` | política P1, vinculación automática o rechazo, bloqueo al desvincular | nuevo |
| `app/Http/Controllers/Auth/SocialLoginController.php` | `redirect`, `callback` (login o vincular según `social.intent`) | nuevo |
| `app/Http/Controllers/Profile/SocialIdentityController.php` | vincular y desvincular (`password.confirm`) | nuevo |
| `app/Models/SocialIdentity.php`, migración `social_identities` | identidad (proveedor + id), `blocked_at` | nuevo |
| `routes/auth.php` | `auth/{provider}/redirect` (`guest`), `auth/{provider}/callback` (sin `guest`), `user/social/{provider}` | modificado |
| `routes/ai.php` | `/.well-known/*` (PRM con el nombre `mcp.oauth.protected-resource.nested`), `POST oauth/register`, `POST oauth/token` | modificado |
| `routes/web.php` | `GET|POST oauth/authorize` con `auth` | modificado |
| `app/Http/Controllers/Mcp/OAuth/{MetadataController,RegisterController,AuthorizeController,TokenController}.php` | endpoints del servidor de autorización | nuevo |
| `app/Services/Mcp/OAuth/AuthorizationServer.php` | política de redirect, PKCE S256, emisión y canje del código, emisión del token y sustitución | nuevo |
| `app/Models/{OAuthClient,OAuthAuthCode,PersonalAccessToken}.php` y migraciones `oauth_clients`, `oauth_auth_codes`, `personal_access_tokens.oauth_client_id` | estado del servidor de autorización y marca del token | nuevo |
| `app/Providers/AppServiceProvider.php` | `Sanctum::usePersonalAccessTokenModel`; limitadores `social-login`, `mcp-oauth-register`, `mcp-oauth-token` | modificado |
| `app/Http/Controllers/ApiKeyController.php` | `index` añade el origen (manual u «OAuth · cliente») | modificado |
| `app/Console/Commands/McpOAuthPrune.php`, `routes/console.php` | purga programada | nuevo / modificado |
| `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Profile/Partials/LinkedAccountsForm.vue`, `resources/js/Pages/OAuth/Authorize.vue`, `resources/js/Pages/ApiKeys/Index.vue` | botones, cuentas vinculadas, consentimiento y columna «Origen» | modificado / nuevo |
| `deploy/hestia/nginx-overrides.conf`, `docs/modulos/despliegue.md`, `docs/modulos/mcp.md` | `location ^~ /.well-known/oauth-`, `curl` de verificación, instalación sin API key | modificado |
| Sin cambios | `app/Http/Middleware/McpGuard.php`, `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `app/Http/Controllers/Auth/PasskeyLoginController.php`, `vendor/laravel/mcp/*`, `app/Mcp/Tools/SchedulerTool.php`, `app/Services/AuditLog.php` | — |
| Diferencias de O2 / O3 | O2: sin `composer.json`; nuevos `app/Services/Auth/Social/{GoogleOidc,FacebookGraph}.php` y extracción a `app/Connectors/Support/OAuthHttp.php` (modifica `GoogleConnector.php` y `MetaConnector.php`). O3: sin `oauth_auth_codes` ni `OAuthAuthCode`; la purga solo afecta a los clientes | — |

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Error de protocolo en el servidor de autorización propio (redirect abierto, replay, PKCE mal comparado) | Media | Alto | Alcance mínimo (cliente público, code+S256, sin refresh), `hash_equals` sobre `base64url(sha256(verifier))`, vectores del RFC 7636 §B, un test negativo por cada `invalid_*` y la revisión adversarial presupuestada |
| Socialite no expone `email_verified` de Google como se supone, o la versión de Graph por defecto del driver de Facebook es antigua | Baja | Alto | Verificarlo en el vendor al instalar. Fallar cerrado si falta el campo. Fijar `usingGraphVersion` con `social.meta.graph_version` y un test sobre el payload crudo |
| Regresión del login con contraseña al extraer `LoginFlow` | Baja | Alto | Extracción sin cambio de comportamiento como primera tarea, con la suite de ADR-003 en verde antes de añadir lo social |
| Un cliente con API key `msk_` caducada recibe ahora `resource_metadata` en el 401 e intenta OAuth en lugar de fallar | Media | Bajo | Es el comportamiento previsto por la spec MCP. Documentarlo en `docs/modulos/mcp.md`. CA-24 fija que las keys válidas siguen igual |
| Reautorización concurrente del mismo cliente: quedan dos tokens vivos | Baja | Bajo | Revocar y emitir en una transacción con bloqueo de las filas de ese (usuario, cliente) |
| Conflicto de `X-Frame-Options` entre Nginx (`SAMEORIGIN`) y la pantalla de consentimiento | Media | Medio | `Content-Security-Policy: frame-ancestors 'none'` en la respuesta del consentimiento (prevalece sobre XFO) y test de cabecera (CA-18) |
| Hestia captura `/.well-known/oauth-*` con otra `location` o no llega `Authorization` | Media | Alto (producción) | `location ^~ /.well-known/oauth-` en `nginx-overrides.conf` y `curl` de verificación en `despliegue.md` (M-F6-2) |
| Spam de DCR abierto a Internet | Media | Bajo | Limitador por IP 10/h, política de redirect estricta y purga a 30 días |
| (O2) regresión en la publicación de YouTube o Meta al extraer los helpers HTTP | Media | Alto | Solo aplica a O2: tests de conectores en verde antes y después de la extracción. Coste L, que desborda la evaluación de C-01 |
| (O3) `optimize:clear` borra las marcas de un solo uso y deja canjear dos veces un código interceptado | Baja | Alto | Solo aplica a O3: exigir el store `database` en la configuración y documentar que no se vacíe la caché con flujos en curso; no elimina el riesgo |

## 7. Preguntas abiertas

- **Para el planner:** ¿se cambia el texto de `TwoFactorChallengeController::expired` («Vuelve a escribir tu correo y contraseña») por uno neutro? Tras un login social es inexacto, y la spec dice «sin cambios» en ese controlador. **Recomendación: sí.** Es un cambio de una línea, sin efecto en la lógica, y conviene cubrirlo con un test del mensaje.
- **Para el planner:** ¿registra la auditoría del reto 2FA (`auth.2fa.challenge_passed` y `auth.2fa.challenge_failed`) el método del primer factor (contraseña, Google o Facebook)? **Recomendación: sí.** `LoginFlow` guardaría `login.via` en sesión junto a `login.*`, y el controlador lo añadiría al `detail`, junto a `totp` o `recovery`. Es un cambio mínimo en `TwoFactorChallengeController` y en el `forget` de `login.*` (también en `PasskeyLoginController`).
- Lo heredado de la evaluación, que no cierra el diseño: si algún cliente exige refresh token o usa CIMD en vez de DCR, y los callbacks reales de Claude Desktop. ⚠️ Lo verifica el smoke CA-29, adelantado al final de C-02.

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-22 | Diseño creado (`borrador`). Tres opciones presentadas al usuario; se recomienda O1 |
| 2026-10-22 | El usuario elige O1 sin variante. Pasa a `aprobado`, se escribe ADR-004 (`propuesta`) y se enlaza `design:` en `spec.md` |
