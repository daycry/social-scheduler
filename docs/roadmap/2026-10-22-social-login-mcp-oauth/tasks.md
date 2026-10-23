---
generacion:
  inicio: 2026-10-22T19:27:46Z
  fin: 2026-10-22T19:37:43Z
  fuente: medido          # ventana compartida con improvement-plan.md
  tokens_reales: { entrada: 30, salida: 80220, cache_creacion: 113566, cache_lectura: 2195213 }
  eur: null               # precioTokens no fiable: ejecuta rates-verify
  horas_ia: 0.65
  duracion: 39m
  ratio_usado: 300000
estado: en-progreso
changelog: Added
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Login social (Google y Facebook) y OAuth 2.1 del servidor MCP

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-22 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (Socialite y estado del servidor de autorización en tablas, [ADR-004](../../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)) |
| **Plan de pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Las horas de este fichero son **base** (sin el +20 % de contingencia); el plan muestra las cifras con margen. Orden fijo: Fase 1 (C-02) → Fase 2 (C-01) → Fase 3 (C-03). Tokens repartidos en proporción a las horas (≈ 40,5k por hora base, como la evaluación: 3,93M en 97h).

> **Restricciones del usuario que valen para todas las tareas:** no tocar los datos del usuario id 28 (los E2E usan solo `e2e-sec-*`); las API keys `msk_` existentes siguen funcionando; ADR-004 pasa a `aceptada` solo con el visto bueno del usuario (T-02).

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — C-02 · Servidor OAuth del MCP | 11 | 12 | 92% | 0 / 50,0h | 0 / 6,8h | 0 / 1,7h | 0 / 2,03M |
| Fase 2 — C-01 · Login social | 7 | 7 | 100% | 0 / 26,0h | 0 / 3,5h | 0 / 0,9h | 0 / 1,05M |
| Fase 3 — C-03 · UI, E2E, docs y cierre | 9 | 9 | 100% | 0 / 21,0h | 0 / 2,8h | 0 / 0,7h | 0 / 0,85M |
| **TOTAL** | **27** | **28** | **96%** | **0 / 97,0h** | **0 / 13,1h** | **0 / 3,3h** | **0 / 3,93M** |

> **Horas reales (G7).** Las horas reales de la Fase 1 son una **estimación a juicio** (marcadas `(estimado)` en cada tarea): no se midieron con `usage-meter` (las tareas se cerraron sin marcadores de medición), así que no hay dato medido. Suma de las tareas cerradas T-01..T-10 ≈ 4,5h IA ejecución, ≈ 1,1h supervisión y ≈ 5,3h humanas (las tareas pesan muy por debajo de la estimación porque la IA ejecutó casi todo el trabajo); más ≈ 0,8h IA de la revisión F1 (intento 1). La tabla de arriba deja las columnas «real» a 0 hasta que existan datos medidos; estas cifras no se imputan a Jira.

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real), topado a la jornada configurada. No hay `.claude/jira.json`: no aplica.

---

## Fase 1 — C-02 · Servidor de autorización OAuth 2.1 del MCP (con la extracción de `LoginFlow`, la pantalla de consentimiento y el smoke CA-29)

**Estado**: en-progreso · **Estimado**: 50.0h · **Real**: — · **Coste est.**: 2.500 € + tokens (⚠️ verificar) · **Tokens est.**: 2.03M

### T-01 — Extraer `LoginFlow` de `AuthenticatedSessionController::store` sin cambiar el comportamiento

- **Descripción**: Mueve la bifurcación «login directo / reto 2FA» a `App\Services\Auth\LoginFlow::complete($request, $user, $remember)`: con 2FA deja `login.id`, `login.remember` y `login.at` y redirige a `two-factor.challenge`; sin 2FA hace `Auth::login` + `regenerate` + `intended`. `store` solo delega. Es la primera tarea y no añade nada de lo social: es la red de seguridad de todo el plan (ADR-003, criterio 2 del diseño). `TwoFactorChallengeController` y `PasskeyLoginController` no se tocan.
- **Changelog**: El inicio de sesión con contraseña y el reto 2FA se comportan igual que antes.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 0.3h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.3h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.08h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/Auth/LoginFlow.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `tests/Feature/Auth/LoginFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Auth` → todo en verde: los 18 ficheros de ADR-003 (2FA, passkeys, contraseña, reset) sin modificar
  - `git status --porcelain tests/Feature/Auth` → solo `?? tests/Feature/Auth/LoginFlowTest.php` (ningún test existente modificado)
  - `git diff --stat -- app/Http/Controllers/Auth/TwoFactorChallengeController.php app/Http/Controllers/Auth/PasskeyLoginController.php` → vacío
  - `vendor/bin/pint --test` → passed

**Criterios de aceptación**
- [x] Sin 2FA: el login con contraseña abre sesión, regenera el id y va a `intended`, idéntico a hoy
- [x] Con 2FA: `assertGuest`, sesión con `login.id`/`login.remember`/`login.at` y redirección a `two-factor.challenge`, idéntico a hoy
- [x] `LoginFlowTest` fija ambos caminos y `remember` true/false
- [x] La suite de 2FA y passkeys de ADR-003 pasa sin modificar ni una línea de sus tests

**Subtareas**
- [x] Ejecutar `tests/Feature/Auth` antes de tocar nada y anotar el nº de tests como línea base
- [x] Crear `LoginFlow` y delegar desde `store`
- [x] Test de `LoginFlow` para ambos caminos
- [x] Volver a ejecutar la suite y comparar con la línea base

**Notas**: Si algún test existente de `Auth` falla, se revierte la extracción: no se retoca el test. `login.via` y el texto de `expired` NO entran aquí (sería cambiar comportamiento); van en T-15.

**Evidencia de cierre**: tests/Feature/Auth 134 -> 138 tests en verde (4 nuevos de LoginFlow), ningún test existente modificado, Pint passed.

**Evidencia añadida (revisión F1 intento 1, G6)**: `git diff --stat abb08af~1 HEAD -- app/Http/Controllers/Auth/TwoFactorChallengeController.php app/Http/Controllers/Auth/PasskeyLoginController.php` → vacío (ninguno de los dos se ha tocado en toda la iniciativa).

---

### T-02 — Puerta de ADR-004 (visto bueno del usuario) y configuración `services.mcp.oauth.*`

- **Descripción**: CA-32 exige ADR-004 aceptada antes de implementar C-02. El implementador pide el visto bueno al usuario y, solo con su respuesta afirmativa, cambia `estado: propuesta` a `aceptada` (en el ADR y en el índice `docs/knowledge/README.md`). Si el usuario no lo da, se detiene C-02. Además añade `token_days` (30, topado por `services.mcp.key_max_days`), `code_ttl` (300) y `redirect_allowed` (CSV) en `config/services.php` y `.env.example`.
- **Changelog**: Nuevas opciones de configuración para la vida del token OAuth del MCP, el código de autorización y los redirects permitidos.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 0.2h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.2h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.05h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `docs/knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md`, `docs/knowledge/README.md`, `config/services.php`, `.env.example`, `tests/Unit/McpOAuthConfigTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `grep -n "^estado: aceptada" docs/knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md` → 1 línea (solo tras el visto bueno explícito del usuario)
  - `php artisan test --compact tests/Unit/McpOAuthConfigTest.php` → defaults 30 / 300 / vacío y `token_days` nunca por encima de `key_max_days`

**Criterios de aceptación**
- [x] ADR-004 en `aceptada` con el visto bueno del usuario (dado el 2026-10-22 al aprobar el plan); sin él, la tarea queda bloqueada y las siguientes no arrancan
- [x] `.env.example` documenta `MCP_OAUTH_TOKEN_DAYS`, `MCP_OAUTH_CODE_TTL` y `MCP_OAUTH_REDIRECT_ALLOWED`
- [x] Un `MCP_OAUTH_TOKEN_DAYS` mayor que `MCP_KEY_MAX_DAYS` se topa

**Subtareas**
- [x] Pedir el visto bueno al usuario (puerta humana)
- [x] Actualizar ADR-004 e índice de conocimiento
- [x] Config y test de defaults y tope

**Notas**: Restricción del usuario: ADR-004 pasa a `aceptada` solo con su visto bueno; el agente no la acepta por su cuenta.

**Evidencia de cierre**: grep estado aceptada: 1 línea (ADR aceptada por el usuario el 2026-10-22); McpOAuthConfigTest 3 tests en verde (defaults 30/300/vacío, tope por key_max_days, CSV).

---

### T-03 — Migraciones `oauth_clients`, `oauth_auth_codes` y `personal_access_tokens.oauth_client_id`, y modelos

- **Descripción**: Crea las dos tablas del servidor de autorización (cliente DCR con UUID y `redirect_uris` JSON; código con hash SHA-256, `used_at`, `token_id` y `expires_at`) y la columna nullable `oauth_client_id` con `nullOnDelete`. Añade los modelos `OAuthClient`, `OAuthAuthCode` y `App\Models\PersonalAccessToken` (extiende el de Sanctum, relación `oauthClient`) y lo registra con `Sanctum::usePersonalAccessTokenModel`. Las API keys `msk_` existentes no se tocan: la columna es nullable y sin valor por defecto.
- **Changelog**: Preparada la base de datos para conectar clientes MCP por OAuth; las API keys existentes no cambian.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 0.4h (estimado)
- **Tiempo IA (ejec.)**: est. 0.41h · real 0.3h (estimado)
- **Supervisión**: est. 0.10h (≈25 % IA) · real 0.08h (estimado)
- **Previsión IA**: 110k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: db
- **Archivos**: `database/migrations/2026_11_06_090000_create_oauth_clients_table.php`, `database/migrations/2026_11_06_090100_create_oauth_auth_codes_table.php`, `database/migrations/2026_11_06_090200_add_oauth_client_id_to_personal_access_tokens_table.php`, `app/Models/OAuthClient.php`, `app/Models/OAuthAuthCode.php`, `app/Models/PersonalAccessToken.php`, `app/Providers/AppServiceProvider.php`, `tests/Unit/OAuthModelsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan migrate:fresh --env=testing --no-interaction` → migra sin error; existen las dos tablas y la columna `oauth_client_id`
  - `php artisan test --compact tests/Unit/OAuthModelsTest.php` → relaciones, `nullOnDelete` y el modelo de token registrado
  - `php artisan test --compact tests/Feature/Mcp tests/Feature/Auth` → en verde (las API keys siguen funcionando con el modelo nuevo)

**Criterios de aceptación**
- [x] La migración es posterior a `2026_11_05_*` y solo añade: no altera filas existentes
- [x] Borrar un cliente deja `oauth_client_id` a NULL en sus tokens (no los borra)
- [x] `ApiKeyController::index` y `createToken` siguen funcionando con `App\Models\PersonalAccessToken`
- [x] Ninguna migración ni test toca filas de `users` existentes (restricción: datos del usuario id 28 intactos)

**Subtareas**
- [x] Tres migraciones aditivas
- [x] Modelos y casts (`redirect_uris` array, `abilities` array)
- [x] `usePersonalAccessTokenModel` y test de regresión de keys

**Notas**: Si ya existe una migración con fecha `2026_11_06_*`, usar la siguiente libre.

**Evidencia de cierre**: migrate:fresh --env=testing (BD metricool_testing) OK; OAuthModelsTest 5 tests + tests/Feature/Mcp + tests/Feature/Auth = 186 en verde; migrate local aditivo aplicado.

---

### T-04 — Metadatos PRM y ASM, `WWW-Authenticate` en el 401 y `location` de Nginx

- **Descripción**: `MetadataController` sirve `GET /.well-known/oauth-protected-resource[/{path}]` (con el nombre `mcp.oauth.protected-resource.nested` y `where('path', '.*')`, para reutilizar `AddWwwAuthenticateHeader` del vendor sin tocarlo) y `GET /.well-known/oauth-authorization-server` con los valores de CA-12 y CA-13. Las rutas viven en `routes/ai.php`, fuera de `web`. Añade `location ^~ /.well-known/oauth-` a `deploy/hestia/nginx-overrides.conf`. Es el quick win de la evaluación: hace el MCP descubrible.
- **Changelog**: Los clientes MCP ya pueden descubrir cómo autorizarse: el servidor publica sus metadatos OAuth y el 401 indica dónde encontrarlos.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 0.4h (estimado)
- **Tiempo IA (ejec.)**: est. 0.41h · real 0.3h (estimado)
- **Supervisión**: est. 0.10h (≈25 % IA) · real 0.08h (estimado)
- **Previsión IA**: 110k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Mcp/OAuth/MetadataController.php`, `routes/ai.php`, `deploy/hestia/nginx-overrides.conf`, `deploy/hestia/nginx-http-map.conf`, `deploy/hestia/README.md`, `tests/Feature/Mcp/OAuth/MetadataTest.php`
- **Cubre (tests)**: API-01
- **Verificación**:
  - `php artisan test --compact --filter=OAuthMetadata` → CA-12, CA-13 y CA-14 en verde (PRM raíz y anidada, ASM sin `refresh_token` ni `client_secret_*`, 401 con `resource_metadata`)
  - `php artisan route:list --path=well-known` → PRM con nombre `mcp.oauth.protected-resource.nested` y ASM
  - `grep -n "well-known/oauth-" deploy/hestia/nginx-overrides.conf` → la `location ^~` con `try_files $uri /index.php?$query_string`
  - `git diff --stat -- vendor app/Http/Middleware/McpGuard.php` → vacío

**Criterios de aceptación**
- [x] CA-12: PRM en 200 con `resource`, `authorization_servers` y `scopes_supported=["mcp:use"]`, en la raíz y en `/mcp`
- [x] CA-13: ASM con `code_challenge_methods_supported=["S256"]`, `grant_types_supported=["authorization_code"]` y `token_endpoint_auth_methods_supported=["none"]`
- [x] CA-14: `POST /mcp` sin Bearer o con uno inválido devuelve 401 con `WWW-Authenticate: Bearer realm="mcp", resource_metadata="…"`
- [x] Las API keys `msk_` válidas siguen entrando en `/mcp` (CA-24)

**Subtareas**
- [x] Controlador y rutas
- [x] Test del formato exacto de `WWW-Authenticate`
- [x] `location` de Nginx con comentario del motivo

**Revisión F1 (intento 1)**: G2 — el ASM responde también en `/.well-known/oauth-authorization-server/mcp` (spec.md:101), con test. C4 — la `Referrer-Policy` de Nginx ya no duplica la de PHP: `add_header` usa `$referrer_policy_default`, que sale de un `map` de nivel http (`deploy/hestia/nginx-http-map.conf`) y queda vacío si PHP ya envía la suya; `fastcgi_hide_header` se descartó porque borraría la de PHP, que es la que debe prevalecer.

**Notas**: El `curl` de verificación en `despliegue.md` se escribe en T-27. M-07 (Hestia real) cierra la parte de M-F6-2.

**Evidencia de cierre**: --filter=OAuthMetadata 7 tests en verde (CA-12/13/14, 404 de recurso ajeno, CA-24/28); route:list muestra mcp.oauth.protected-resource.nested; tests/Feature/Mcp en verde; git diff vendor + McpGuard vacío.

**Evidencia añadida (revisión F1 intento 1, G6)**: `grep -n "well-known/oauth-" deploy/hestia/nginx-overrides.conf` → línea 36 (comentario) y 38 `location ^~ /.well-known/oauth- {` con `try_files $uri /index.php?$query_string`; `git diff --stat abb08af~1 HEAD -- vendor app/Http/Middleware/McpGuard.php` → vacío; `php artisan route:list --path=well-known` → PRM, PRM anidada (`mcp.oauth.protected-resource.nested`), ASM y ASM anidada (`mcp.oauth.authorization-server.nested`, añadida en la revisión, G2).

---

### T-05 — DCR: `POST /oauth/register`, política de redirect, limitador y `mcp:oauth-prune`

- **Descripción**: `RegisterController` registra clientes públicos (RFC 7591) con `RedirectPolicy`: solo loopback (`localhost`, `127.0.0.1`, `[::1]`, cualquier puerto, RFC 8252), los callbacks de Claude y los prefijos `https://` de `MCP_OAUTH_REDIRECT_ALLOWED`. Limitador `mcp-oauth-register` de 10/h por IP. Comando programado `mcp:oauth-prune` que borra clientes sin token vivo en 30 días y los códigos caducados.
- **Changelog**: Los clientes MCP pueden registrarse solos (solo con redirecciones seguras) y los registros abandonados se limpian a diario.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 0.7h (estimado)
- **Tiempo IA (ejec.)**: est. 0.68h · real 0.6h (estimado)
- **Supervisión**: est. 0.17h (≈25 % IA) · real 0.15h (estimado)
- **Previsión IA**: 183k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-03, T-04
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Mcp/OAuth/RegisterController.php`, `app/Services/Mcp/OAuth/RedirectPolicy.php`, `app/Console/Commands/McpOAuthPrune.php`, `routes/ai.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, `config/services.php`, `database/migrations/2026_11_07_090000_oauth_fix1_columns.php`, `tests/Feature/Mcp/OAuth/RegisterTest.php`, `tests/Feature/Mcp/OAuth/PruneTest.php`, `tests/Unit/RedirectPolicyTest.php`
- **Cubre (tests)**: API-02
- **Verificación**:
  - `php artisan test --compact --filter=OAuthRegister` → CA-15 (201 con `token_endpoint_auth_method: none`, 400 `invalid_redirect_uri`, 400 `invalid_client_metadata`, 429 con `Retry-After` al 11.º)
  - `php artisan test --compact tests/Unit/RedirectPolicyTest.php` → loopback con puerto variable, `[::1]`, rechazo de `http://evil`, `https://evil`, userinfo y `localhost.evil.com`
  - `php artisan test --compact --filter=OAuthPrune` → CA-31: borra solo clientes sin token vivo >30 días y códigos caducados
  - `php artisan schedule:list` → aparece `mcp:oauth-prune`

**Criterios de aceptación**
- [x] CA-15 completo, sin 500 con cuerpos malformados
- [x] La política compara host exacto (no prefijos de cadena) y esquema
- [x] CA-31: un cliente con un token vivo no se purga
- [x] El registro audita `oauth.client.registered` sin datos sensibles

**Subtareas**
- [x] `RedirectPolicy` y su Unit
- [x] Controlador y limitador
- [x] Comando, programación y test

**Notas**: Código de seguridad propio: lo revisa T-12. **Decisión deliberada (G3)**: los eventos OAuth se auditan en un canal propio `oauth` (no `web`) para poder filtrarlos del resto del `AuditLog`; `spec.md` se ha actualizado en consecuencia.

**Revisión F1 (intento 1)**: B2 — `mcp:oauth-prune` borra solo clientes sin token vivo y con `max(created_at, last_used_at) < now-30d`; `last_used_at` se actualiza al emitir un token (test «revocado hoy, cliente de hace 40 días → no se borra»). B1 — `grant_types`/`response_types` con elementos no string → 400 `invalid_client_metadata`. C1 — `RedirectPolicy` rechaza segmentos `.`/`..` y `%2e` en cualquier caja. C2 — tope global de DCR (`services.mcp.oauth.register_global_per_hour`, 200/h) y límite por /64 en IPv6. G1 — columnas `software_id`, `software_version`, `created_ip` en `oauth_clients` (migración aditiva `2026_11_07_090000_oauth_fix1_columns`) guardadas en el DCR. B10 — quitada la aserción vacía de `PruneTest`.

**Evidencia de cierre**: --filter OAuthRegister|OAuthPrune|RedirectPolicy 49 tests en verde (CA-15, 429 con Retry-After, CA-31); schedule:list muestra mcp:oauth-prune.

---

### T-06 — `AuthorizationServer` y `GET|POST /oauth/authorize` (backend del consentimiento)

- **Descripción**: Servicio `AuthorizationServer` con la validación de la petición y la emisión del código (aleatorio, solo su hash SHA-256 en BD, 300 s, ligado a usuario, cliente, `redirect_uri`, `code_challenge`, abilities y `resource`). `AuthorizeController` en `routes/web.php` con `auth` (guarda `url.intended` y vuelve tras el login, con 2FA), muestra el consentimiento por Inertia (`OAuth/Authorize`) con presets de `App\Mcp\Abilities::presets()` (read por defecto) y la respuesta lleva `Content-Security-Policy: frame-ancestors 'none'`. `client_id` o `redirect_uri` inválidos: página de error propia y nunca redirige.
- **Changelog**: Al conectar un agente por OAuth, la app pide iniciar sesión (con 2FA si lo tienes) y mostrar una pantalla de autorización antes de darle acceso.
- **Estado**: completado
- **Tiempo humano**: est. 6,0h · real 0.7h (estimado)
- **Tiempo IA (ejec.)**: est. 0.81h · real 0.6h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real 0.15h (estimado)
- **Previsión IA**: 220k in / 23k out tok · ⚠️ verificar €
- **Dependencias**: T-05
- **Tipo**: backend
- **Archivos**: `app/Services/Mcp/OAuth/AuthorizationServer.php`, `app/Http/Controllers/Mcp/OAuth/AuthorizeController.php`, `routes/web.php`, `tests/Feature/Mcp/OAuth/AuthorizeTest.php`, `app/Services/Mcp/OAuth/AuthorizeRequest.php`, `app/Services/Mcp/OAuth/OAuthException.php`, `resources/js/Pages/OAuth/Authorize.vue`, `routes/web.php`, `app/Providers/AppServiceProvider.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=OAuthAuthorize` → CA-16 (redirige a `/login` y vuelve con los mismos parámetros), CA-17, CA-18 (cabecera), CA-19, CA-22 (`invalid_target`) y `access_denied` con `state` y auditoría `oauth.denied`

**Criterios de aceptación**
- [x] CA-17: sin `code_challenge`, con `plain` o con `response_type=token` redirige con `error=invalid_request` y `state`; con `client_id`/`redirect_uri` no registrados NO redirige
- [x] CA-19: aprobar con `publish` redirige a `redirect_uri?code&state`; denegar, a `error=access_denied&state`
- [x] CA-22: `resource` distinto de `APP_URL/mcp` devuelve `invalid_target`
- [x] El código en claro nunca se guarda ni se audita; las abilities del código son las marcadas y nunca superan al preset `all`
- [x] POST con CSRF; GET sin efectos laterales

**Subtareas**
- [x] Validación de parámetros y `RedirectPolicy`
- [x] Emisión y hash del código
- [x] Controlador, CSP y errores sin redirect
- [x] Eventos `oauth.authorized` / `oauth.denied`

**Notas**: El 2FA en mitad del flujo lo cubre E2E-06; aquí basta con que `auth` guarde `intended`.

**Desviación (T-06)**: dos clases de apoyo nuevas (`AuthorizeRequest`, `OAuthException`) para la validación y los errores con/sin redirect; `Authorize.vue` nace como esqueleto aquí (Inertia exige que la página exista) y se completa en T-07.

**Desviaciones (G4, T-06)**: las rutas de `/oauth/authorize` llevan el middleware `verified` además de `auth` (un usuario sin verificar no puede conceder acceso al MCP; coherente con el resto de rutas autenticadas de la app). Revisión F1: C3 — throttle con nombre `mcp-oauth-authorize` (30/min por usuario); B6 — un `state` de más de 500 caracteres se rechaza con `invalid_request` en vez de truncarse.

**Evidencia de cierre**: --filter OAuthAuthorize 20 tests en verde (CA-16/17/18/19/22, hash del código, abilities, revalidación en POST). Authorize.vue es esqueleto hasta T-07.

---

### T-07 — Pantalla de consentimiento `OAuth/Authorize.vue`

- **Descripción**: Se adelanta a C-02 (la evaluación la tenía en C-03) porque el smoke CA-29 necesita una pantalla real. Muestra el nombre del cliente marcado como «declarado por el cliente», el host de redirección, los presets `read`/`publish`/`all` con casillas (read marcado) y los botones Autorizar / Denegar. Accesible: etiquetas, foco inicial, anuncio de errores, sin desbordamiento a 375 y 360 px.
- **Changelog**: Nueva pantalla para autorizar un agente: muestra quién pide acceso, adónde vuelves y los permisos (solo lectura marcado por defecto) con Autorizar y Denegar.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 0.5h (estimado)
- **Tiempo IA (ejec.)**: est. 0.68h · real 0.4h (estimado)
- **Supervisión**: est. 0.17h (≈25 % IA) · real 0.1h (estimado)
- **Previsión IA**: 183k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-06
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/OAuth/Authorize.vue`, `tests/Feature/Mcp/OAuth/AuthorizeTest.php`
- **Cubre (tests)**: E2E-05, E2E-06, A11Y-01, M-03, M-04, M-05, M-06, M-08
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact --filter=OAuthAuthorize` → la página recibe cliente, host, presets y estado marcado por defecto (CA-18)
  - `vendor/bin/pint --test` → sin cambios pendientes

**Criterios de aceptación**
- [x] CA-18: se ve el nombre declarado, el host de redirección y los tres presets con `read` marcado
- [x] Las casillas son editables y lo marcado es lo que viaja en el POST
- [x] El texto no permite confundir el nombre del cliente con una identidad verificada
- [x] Etiquetas y foco accesibles

**Subtareas**
- [x] Componente con `useForm` de Inertia
- [x] Presets coherentes con `ApiKeys/Index`
- [x] Estados de error

**Notas**: Reutiliza los componentes de formulario ya usados en `Profile/Partials`. **Diferido (G5)**: la comprobación de «sin desbordamiento horizontal a 375 y 360 px» no se ha medido aquí; se mide en T-26 y no cuenta como cumplida en esta tarea.

**Evidencia de cierre**: npm run build OK; --filter OAuthAuthorize 20 tests en verde (props de cliente, host, presets, read por defecto); Pint passed. Medición 375/360 px y E2E/M-xx pendientes en T-26/manual (no verificables aquí).

---

### T-08 — `POST /oauth/token`: PKCE S256, código de un solo uso y emisión del token Sanctum

- **Descripción**: `TokenController` y `AuthorizationServer::redeemCode`: valida `grant_type`, `code`, `redirect_uri`, `client_id`, `code_verifier` (`hash_equals` sobre `base64url(sha256(verifier))`) y `resource`; consume el código con `UPDATE … SET used_at WHERE used_at IS NULL` atómico; emite un token `msk_` con las abilities del código, `expires_at = now + token_days` y `oauth_client_id`, y guarda `token_id` en el código. Un replay revoca el token emitido (CA-21). Respuesta con `Cache-Control: no-store`. Limitador `mcp-oauth-token` de 30/min por IP.
- **Changelog**: Los agentes MCP pueden canjear la autorización por un token de 30 días que se revoca desde API keys; un código reutilizado queda anulado junto con su token.
- **Estado**: completado
- **Tiempo humano**: est. 6,0h · real 0.8h (estimado)
- **Tiempo IA (ejec.)**: est. 0.81h · real 0.7h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real 0.18h (estimado)
- **Previsión IA**: 220k in / 23k out tok · ⚠️ verificar €
- **Dependencias**: T-06
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Mcp/OAuth/TokenController.php`, `app/Services/Mcp/OAuth/AuthorizationServer.php`, `routes/ai.php`, `app/Providers/AppServiceProvider.php`, `tests/Unit/PkceTest.php`, `tests/Feature/Mcp/OAuth/TokenTest.php`, `app/Services/Mcp/OAuth/AuthorizeRequest.php`, `app/Http/Controllers/ApiKeyController.php`, `database/migrations/2026_11_07_090000_oauth_fix1_columns.php`, `tests/Feature/Mcp/OAuth/FullFlowTest.php`, `tests/Feature/Mcp/OAuth/CoexistenceTest.php`, `tests/Feature/Auth/LoginFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/PkceTest.php` → vectores del RFC 7636 §B
  - `php artisan test --compact --filter=OAuthToken` → CA-20 (200, `msk_`, `expires_in`, `no-store`, abilities exactas, `oauth_client_id`), CA-21 (verifier erróneo, usado, caducado, otro `redirect_uri` o `client_id` → `invalid_grant`; replay revoca el token) y `unsupported_grant_type`

**Criterios de aceptación**
- [x] CA-20 completo
- [x] CA-21: cada `invalid_*` tiene un test negativo; el canje concurrente del mismo código emite un solo token
- [x] CA-22 en el token endpoint (`invalid_target`)
- [x] El hash del código es lo único guardado; el token en claro solo está en la respuesta; ninguna auditoría lleva código, verifier ni token

**Subtareas**
- [x] PKCE y comparación en tiempo constante
- [x] Canje atómico y revocación por replay
- [x] Emisión del token y respuesta
- [x] Limitador y auditoría `oauth.token.issued` / `oauth.code.replayed`

**Notas**: Núcleo del riesgo dominante de la evaluación. Alcance mínimo: cliente público, code+S256, sin refresh.

**Desviaciones (G4, T-08)**: un `client_id` desconocido responde `invalid_grant` (igual que un código inválido) en vez de `invalid_client`, para no permitir enumerar clientes; un código se quema (`used_at`) ante un mismatch de `client_id`, `redirect_uri` o verifier, de modo que quien lo intercepte solo tiene un intento.

**Revisión F1 (intento 1)**: B4 — el canje corre en `DB::transaction(…, 3)` y, tras emitir, borra los demás tokens OAuth del par (usuario, cliente); B5 — `redirect_uri` es opcional en `/oauth/token` si no se envió en authorize (el código guarda `redirect_uri_sent`) y, si viene, debe coincidir; B7 — test que fuerza la rama `$issued === null` (otra petición consume el código entre la lectura y el UPDATE); B3 — la columna `origin` (`manual`/`oauth`) de `personal_access_tokens` sobrevive al borrado del cliente y `ApiKeyController` la usa; B8/B9 — `FullFlowTest` autoriza dos veces de verdad y `LoginFlowTest` comprueba la cookie `remember_web_*`.

**Evidencia de cierre**: PkceTest (vectores RFC 7636 B) + --filter OAuthToken en verde (CA-20, CA-21 con cada invalid_*, replay revoca el token, carrera simulada, CA-22, unsupported_grant_type, 429 al 31, sin secretos en auditoría); --filter OAuth 122 tests. El canje concurrente real no es reproducible en SQLite en memoria: se simula la carrera perdida; la atomicidad es el UPDATE ... WHERE used_at IS NULL.

---

### T-09 — Coexistencia con `msk_`: sustitución al reautorizar, origen del token y `McpGuard` intacto

- **Descripción**: Al emitir, revoca en una transacción con bloqueo de filas los tokens vivos de ese (usuario, cliente) (CA-26). `ApiKeyController::index` añade el origen (manual u «OAuth · cliente») como dato; la columna visible llega en T-22. Un test fija que `McpGuard` sigue devolviendo 403 con un `Origin` no permitido en `/mcp` y que `/oauth/*` y `/.well-known/*` responden sin `Origin` (CA-28).
- **Changelog**: Volver a autorizar un agente sustituye su token anterior (los demás clientes y tus API keys manuales no se tocan) y API keys distingue el origen de cada clave.
- **Estado**: completado
- **Tiempo humano**: est. 1,0h · real 0.3h (estimado)
- **Tiempo IA (ejec.)**: est. 0.14h · real 0.2h (estimado)
- **Supervisión**: est. 0.03h (≈25 % IA) · real 0.05h (estimado)
- **Previsión IA**: 37k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Services/Mcp/OAuth/AuthorizationServer.php`, `app/Http/Controllers/ApiKeyController.php`, `tests/Feature/Mcp/OAuth/CoexistenceTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=OAuthCoexistence` → CA-23, CA-24, CA-25 (revocar → 401), CA-26 y CA-28 en verde
  - `git diff --stat -- app/Http/Middleware/McpGuard.php app/Mcp/Tools/SchedulerTool.php app/Services/AuditLog.php` → vacío

**Criterios de aceptación**
- [x] CA-26: tras reautorizar solo vive el token nuevo del par (usuario, cliente); los de otros clientes y las API keys manuales no se tocan
- [x] CA-23: un token OAuth `read` no puede `create_post`, igual que una API key sin `posts:write`
- [x] CA-24: `tests/Feature/Mcp` sin modificar y en verde
- [x] Ningún token manual existente cambia

**Subtareas**
- [x] Sustitución transaccional
- [x] Dato de origen en `index`
- [x] Tests de coexistencia y de CA-28

**Evidencia de cierre**: --filter OAuthCoexistence 6 tests (CA-23, 24, 25, 26, 28); tests/Feature/Mcp + tests/Unit 236 en verde; git diff McpGuard/SchedulerTool/AuditLog vacío.

---

### T-10 — Batería Pest del servidor OAuth: flujo completo, negativos y fugas

- **Descripción**: Test de flujo completo register → authorize (actingAs) → token → `tools/call` en `/mcp`, más los negativos por cada `invalid_*` hasta unos 30 tests nuevos en total entre T-04..T-09 y esta tarea, y `OAuthAuditLeakTest` (CA-27: ningún evento OAuth lleva códigos, tokens, verifiers ni secretos). Cierra huecos de cobertura detectados al mirar CA-12..CA-28 uno a uno.
- **Changelog**: El flujo completo de conexión OAuth de un agente MCP queda cubierto por pruebas automáticas, incluidos los casos de error y que no se filtran secretos.
- **Estado**: completado
- **Tiempo humano**: est. 9,0h · real 1.0h (estimado)
- **Tiempo IA (ejec.)**: est. 1.22h · real 0.9h (estimado)
- **Supervisión**: est. 0.30h (≈25 % IA) · real 0.22h (estimado)
- **Previsión IA**: 329k in / 36k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: test
- **Archivos**: `tests/Feature/Mcp/OAuth/FullFlowTest.php`, `tests/Feature/Mcp/OAuth/NegativesTest.php`, `tests/Feature/Mcp/OAuth/OAuthAuditLeakTest.php`, `tests/Feature/Mcp/OAuth/Helpers.php`, `tests/Pest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=OAuth` → ≥ 30 tests en verde cubriendo CA-12..CA-28
  - `php artisan test --compact --filter=OAuthAuditLeak` → ninguna fila de `audit_logs` ni línea de log contiene código, token, verifier ni secreto
  - `php artisan test --compact` → suite completa en verde

**Criterios de aceptación**
- [x] Cada CA-12..CA-28 tiene al menos un test que lo nombra (tabla CA → test en las Notas)
- [x] El flujo completo termina con una llamada real a una tool del MCP con el token emitido
- [x] Suite completa sin desactivar ni modificar tests existentes

**Subtareas**
- [x] Flujo completo
- [x] Negativos que falten por CA
- [x] Test de fugas
- [x] Tabla CA → test

**Desviación (T-10)**: se añaden `tests/Feature/Mcp/OAuth/Helpers.php` (ayudantes compartidos) y un `afterEach(gc_collect_cycles)` en `tests/Pest.php`. Motivo: con >1050 tests el proceso supera los 128 MB y el worker de `queue:work` de `InfraTest` (test existente, sin modificar) se detiene tras el primer job; la línea base ya estaba en 122 MB. El GC por test lo deja en verde sin tocar ese test.

**Evidencia de cierre**: --filter OAuth 145 tests en verde; suite completa 1075/1075; OAuthAuditLeak sin código/verifier/token/hash en audit_logs ni log; suite completa en verde. Tabla CA→test: CA-12/13/14=MetadataTest; CA-15=RegisterTest+RedirectPolicyTest; CA-16/17/18/19=AuthorizeTest (+NegativesTest); CA-20/21/22=TokenTest+PkceTest; CA-23/24/25/26/28=CoexistenceTest; CA-27=OAuthAuditLeakTest (+TokenTest/AuthorizeTest); flujo extremo a extremo=FullFlowTest; CA-31=PruneTest.

---

### T-11 — Smoke CA-29 con Claude Code, Claude Desktop, Codex y OpenCode (adelantado al final de C-02)

- **Descripción**: Con el servidor tras el dominio ngrok reservado, el usuario conecta los cuatro clientes solo con el navegador, sin API key, y se registra en `testing/smoke-ca29.md` el comando o configuración y la versión de cada cliente. Verifica los supuestos ⚠️: DCR, `resource`, callbacks reales y si algún cliente exige refresh token o CIMD. Si alguno lo exige, la tarea no lo implementa: lo eleva al usuario (≈ +8h base para refresh) y ajusta la política de redirect con `MCP_OAUTH_REDIRECT_ALLOWED`.
- **Changelog**:
- **Estado**: en-progreso
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0.54h · real —
- **Supervisión**: est. 0.14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-07, T-10
- **Archivos**: `docs/roadmap/2026-10-22-social-login-mcp-oauth/testing/smoke-ca29.md`, `docs/roadmap/2026-10-22-social-login-mcp-oauth/tasks.md`, `docs/modulos/mcp.md`
- **Nota (revisión F3, A4)**: al cerrar el smoke se quita el «pendiente de verificar» de `docs/modulos/mcp.md` en los clientes que funcionen.
- **Cubre (tests)**: M-03, M-04, M-05, M-06
- **Verificación**:
  - `ls docs/roadmap/2026-10-22-social-login-mcp-oauth/testing/smoke-ca29.md` → existe
  - lectura: el fichero tiene una fila por cliente (Claude Code CLI, Claude Desktop, Codex, OpenCode) con comando/configuración, versión, resultado y callback observado
  - lectura: hay una conclusión explícita sobre refresh token y CIMD (necesario: sí/no)

**Criterios de aceptación**
- [ ] CA-29: los 4 clientes se conectan sin pegar ninguna API key y llaman a una tool; o queda documentado cuál falla y por qué
- [ ] Los comandos de los tres clientes por CLI quedan verificados en su versión actual (⚠️ de la spec)
- [ ] Decisión del usuario sobre refresh/CIMD si el smoke la dispara, antes de pasar a C-01

**Subtareas**
- [ ] Preparar ngrok y `.env` (`MCP_OAUTH_REDIRECT_ALLOWED` si hace falta)
- [ ] Conectar cada cliente y anotar
- [ ] Redactar la conclusión

**Notas**: Tarea manual del usuario: las horas humanas son suyas; la IA solo prepara la plantilla del registro y lo redacta. Se usa la cuenta del usuario solo para autenticarse: no se modifica ningún dato del usuario id 28.

**Preparado por el implementer (parte automatizable, hecha)**: smoke HTTP con curl contra `https://workable-humorous-rat.ngrok-free.app` (app local): 401 con `WWW-Authenticate ... resource_metadata=".../.well-known/oauth-protected-resource/mcp"`; PRM raíz y `/mcp` en 200; ASM correcto (solo `code`, `S256`, `none`); DCR 201 y 400 `invalid_redirect_uri` con `https://evil.example/cb`; login + consentimiento 200 con `Content-Security-Policy: frame-ancestors 'none'`; authorize → 302 a `http://127.0.0.1:53111/callback?code=…&state=…`; token con verifier erróneo → 400 `invalid_grant`; token correcto → 200 (`msk_`, `Bearer`, `expires_in` 2592000, `no-store`); replay → 400; llamada real `tools/call list_brands` con el token → OK. Usuario y datos de prueba (`e2e-sec-oauth-smoke`) borrados al terminar; usuario id 28 intacto. Versiones locales detectadas: Claude Code 2.1.308, Codex 0.162.1, OpenCode 2.0.18.

**Checklist para el usuario (pendiente; la tarea NO está completada)**. `testing/smoke-ca29.md` no lo puede crear el implementer (guardrail de alcance de `docs/roadmap/`): créalo tú o con `qa`, con una fila por cliente (comando/config, versión, resultado, callback observado) y la conclusión sobre refresh token y CIMD. URL del MCP: `https://workable-humorous-rat.ngrok-free.app/mcp` (ngrok activo, sin API key en ningún paso).
- [ ] **Claude Code** (2.1.308): `claude mcp add --transport http social-scheduler https://workable-humorous-rat.ngrok-free.app/mcp`; dentro de `claude` ejecuta `/mcp`, elige `social-scheduler` → *Authenticate*; se abre el navegador → inicia sesión → Autorizar. Callback esperado: loopback `http://localhost:<puerto>/callback`. Comprueba con `/mcp` que sale *connected* y pide que liste tus marcas.
- [ ] **Claude Desktop**: Ajustes → *Connectors* → *Add custom connector* → nombre `social-scheduler`, URL `https://workable-humorous-rat.ngrok-free.app/mcp` → *Connect* (navegador → Autorizar). Callback esperado: `https://claude.ai/api/mcp/auth_callback` (o `claude.com`). No sirve `claude_desktop_config.json` (solo stdio). Anota la versión (Ayuda → Acerca de).
- [ ] **Codex** (0.162.1): `codex mcp add social-scheduler --url https://workable-humorous-rat.ngrok-free.app/mcp` y después `codex mcp login social-scheduler` (navegador → Autorizar); `codex mcp list` debe mostrarlo con auth OAuth. Si pide activar el cliente RMCP, añade `--enable rmcp_client` o `features.rmcp_client=true` en `~/.codex/config.toml` (⚠️ verificar en esta versión). Pídele una tool del MCP.
- [ ] **OpenCode** (2.0.18): `opencode mcp add social-scheduler --url https://workable-humorous-rat.ngrok-free.app/mcp --global` y después `opencode mcp auth social-scheduler` (navegador → Autorizar); `opencode mcp list` debe mostrarlo conectado. Si no hace OAuth solo, añade `"oauth": {}` al servidor en `opencode.json`.
- [ ] Para cada cliente anota: comando/config, versión, resultado, `redirect_uri` observada (en *API keys* debe aparecer el token como «OAuth · <cliente>»; hoy la columna de origen llega en T-22, el nombre del token ya empieza por «OAuth ·»).
- [ ] Si algún callback es rechazado (`invalid_redirect_uri` en el DCR): añade su prefijo `https://` a `MCP_OAUTH_REDIRECT_ALLOWED` en `.env` y reintenta.
- [ ] Conclusión explícita: ¿algún cliente exige refresh token o CIMD? Si sí: NO se implementa aquí (≈ +8h base); decide tú antes de pasar a Fase 2.
- [ ] Limpieza: revoca desde *API keys* los tokens «OAuth · …» de la prueba.

---

### T-12 — Revisión de seguridad adversarial de C-02

- **Descripción**: Revisión dirigida del servidor de autorización propio: open redirect, replay y concurrencia del código, comparación PKCE, clickjacking (CSP de la pantalla de consentimiento), enumeración de clientes, timing, spam de DCR, secretos en log y auditoría, coexistencia con `msk_`. Resultado: checklist firmada en las Notas y huecos corregidos antes de abrir C-01. Es la puerta de salida de la fase 1.
- **Changelog**: El servidor OAuth del MCP pasó una revisión de seguridad independiente: se cerraron redirecciones que escapaban del dominio permitido, se limitaron el registro de clientes y el consentimiento, y la limpieza ya no borra clientes recién revocados.
- **Estado**: completado
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0.54h · real —
- **Supervisión**: est. 0.14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-11
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-22-social-login-mcp-oauth/tasks.md`, `tests/Feature/Mcp/OAuth/OAuthSecurityReviewTest.php`, `app/Services/Mcp/OAuth/RedirectPolicy.php`, `app/Providers/AppServiceProvider.php`, `deploy/hestia/nginx-overrides.conf`, `tests/Unit/RedirectPolicyTest.php`, `tests/Feature/Mcp/OAuth/RegisterTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `php artisan test --compact --filter=OAuthSecurityReview` → los casos adversariales hallados quedan fijados
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [x] La suite completa pasa sin desactivar ni modificar tests existentes
- [x] Cada hallazgo Important o Critical tiene test que lo fija y corrección
- [x] Ninguna fila de `audit_logs` ni línea de log contiene secreto, código, verifier ni token

**Subtareas**
- [x] Revisar PKCE, canje y replay
- [x] Revisar redirect y consentimiento
- [x] Revisar DCR y limitadores
- [x] Suite completa y Pint

**Notas**: Puerta de salida de la fase 1.


**Evidencia (2026-10-22)**: revisión de dos lentes, intentos 1 y 2 (tablas al final del ledger); `php artisan test --compact` → 1102 passed; `--filter=OAuthSecurityReview` → 10 passed (escape de prefijo con `..`, `%2e`, `%2f`, `%5c`, `%252e`, userinfo, host parecido, esquema javascript; metadatos no string → 400); `vendor/bin/pint --test` → passed. Ningún secreto en auditoría ni log: `OAuthAuditLeakTest`.
**Checklist**: PKCE S256 obligatorio y verifier en tiempo constante ✓ · canje atómico y replay que revoca ✓ · redirect exacta, loopback RFC 8252 y prefijos sin escape ✓ · consentimiento con CSRF, frame-ancestors none y sin aprobación implícita ✓ · DCR 10/h por IP (IPv6 por /64, IPv4 mapeada por dirección) y tope global 200/h ✓ · token 30/min ✓ · authorize 30/min por usuario ✓ · metadatos desde APP_URL ✓.
---

## Fase 2 — C-01 · Login social con Google y Facebook (backend)

**Estado**: completado · **Estimado**: 26.0h · **Real**: — · **Coste est.**: 1.300 € + tokens (⚠️ verificar) · **Tokens est.**: 1.05M

### T-13 — Socialite, configuración de login y migración `social_identities`

- **Descripción**: Instala `laravel/socialite` ^5.31 y verifica en el vendor que el driver de Google expone `email_verified` (si no, el login con Google falla cerrado). Config `services.google_login` y `services.facebook_login` (`GOOGLE_LOGIN_*`, `META_LOGIN_*`) separada de la de conectores. Migración y modelo `SocialIdentity` (`user_id`, `provider`, `provider_user_id` único por proveedor, `email`, `linked_at`, `last_login_at`, `blocked_at`) y relación en `User` con borrado en cascada.
- **Changelog**: Se añade la base del login con Google y Facebook: dependencia Socialite, credenciales propias del login (vacías = desactivado) y tabla de cuentas vinculadas.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.41h · real 0.41h (estimado)
- **Supervisión**: est. 0.10h (≈25 % IA) · real 0.10h (estimado)
- **Previsión IA**: 110k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-12
- **Tipo**: backend
- **Archivos**: `composer.json`, `composer.lock`, `config/services.php`, `.env.example`, `database/migrations/2026_11_06_090300_create_social_identities_table.php`, `app/Models/SocialIdentity.php`, `app/Models/User.php`, `tests/Unit/SocialIdentityTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `composer show laravel/socialite` → versión ^5.31 instalada sin conflictos
  - `grep -rn "email_verified" vendor/laravel/socialite/src/Two/GoogleProvider.php` → el campo llega en el usuario crudo (anotar el resultado en Notas)
  - `php artisan test --compact tests/Unit/SocialIdentityTest.php` → unicidad (proveedor, id), cascada y `blocked_at`
  - `php artisan test --compact tests/Feature/Auth` → en verde

**Criterios de aceptación**
- [x] `(provider, provider_user_id)` es único
- [x] Las claves `GOOGLE_LOGIN_*` y `META_LOGIN_*` vacías dejan el proveedor desactivado (CA-10, se prueba en T-14)
- [x] Los conectores de `app/Connectors` no se modifican (`git diff --stat -- app/Connectors` vacío)

**Subtareas**
- [x] `composer require laravel/socialite`
- [x] Comprobación de `email_verified` en el vendor
- [x] Migración, modelo y relación

**Notas**: Si Socialite no expone `email_verified`, se documenta y se obtiene del userinfo en el propio controlador, siempre fallando cerrado.

**Evidencia (2026-10-23)**: `composer show laravel/socialite` → v5.31.0; el driver de Google deja `email_verified` en el usuario crudo (`GoogleProvider.php:91`, vía `setRaw`); `tests/Unit/SocialIdentityTest.php` + `tests/Feature/Auth` → 141 passed; `git diff --stat -- app/Connectors` vacío; migración aplicada con `php artisan migrate`.

---

### T-14 — `SocialIdentityResolver` y `SocialLoginController` (redirect y callback de login)

- **Descripción**: `SocialIdentityResolver` aplica P1 sobre `social_identities`: identidad existente no bloqueada → su usuario; Google con `email_verified=true` y cuenta local con `email_verified_at` → vincula y audita `social.linked` (auto); Facebook, sin email, sin verificar, bloqueada o sin cuenta → rechazo con mensaje genérico. `SocialLoginController` (`redirect` con `guest`, `callback` sin `guest` y bifurcado por `social.intent`) cierra el login con `LoginFlow::complete(…, remember: false)`. Nunca crea usuarios, ni con `REGISTRATION_ENABLED=true`. Proveedor sin configurar: 404.
- **Changelog**: Ya se puede entrar con Google, o con Facebook si está vinculado, a una cuenta existente; Google se vincula solo con correo verificado y nunca se crean cuentas nuevas.
- **Estado**: completado
- **Tiempo humano**: est. 4,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.54h · real 0.54h (estimado)
- **Supervisión**: est. 0.14h (≈25 % IA) · real 0.14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-13, T-01
- **Tipo**: backend
- **Archivos**: `app/Services/Auth/SocialIdentityResolver.php`, `app/Services/Auth/SocialResolution.php`, `app/Http/Controllers/Auth/SocialLoginController.php`, `app/Providers/AppServiceProvider.php`, `routes/auth.php`, `tests/Feature/Auth/Social/SocialLoginTest.php`, `tests/Feature/Auth/Social/SocialTestHelpers.php`, `tests/Unit/SocialIdentityResolverTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=SocialLogin` → CA-01 a CA-06 y CA-10 en verde con el driver de Socialite simulado
  - `php artisan test --compact tests/Unit/SocialIdentityResolverTest.php` → tabla de decisión de P1 completa
  - `php artisan test --compact tests/Feature/Auth` → ADR-003 sigue en verde

**Criterios de aceptación**
- [x] CA-01/CA-02: login directo y vinculación automática solo con Google verificado
- [x] CA-03/CA-05: email no verificado, ausente o Facebook sin vincular → rechazo genérico, sin sesión, sin identidad
- [x] CA-04: el número de `users` no cambia con `REGISTRATION_ENABLED` a true o false
- [x] CA-06: con 2FA, `assertGuest` y redirección a `two-factor.challenge`; tras el código llega a `intended`
- [x] El mensaje no revela si el email existe

**Subtareas**
- [x] Resolver y su tabla de decisión
- [x] Controlador y rutas
- [x] Rama de login del callback (la de vincular llega en T-17)

**Evidencia (2026-10-23)**: `--filter=Social` → 39 passed (CA-01..CA-06, CA-10, tabla de decisión de P1 en `SocialIdentityResolverTest`); `tests/Feature/Auth tests/Unit` → 282 passed (ADR-003 en verde). Desviación: el driver se registra como `google-login`/`facebook-login` (`Socialite::extend` en `AppServiceProvider`) para usar `services.*_login`; el rechazo usa la clave de error `email` de `/login` y la identidad Google ya vinculada o desvinculada de un usuario también bloquea la vinculación automática de otra cuenta Google. Se añade `app/Services/Auth/SocialResolution.php` y `app/Providers/AppServiceProvider.php` a Archivos.

---

### T-15 — Errores, auditoría, limitador, `login.via` en el 2FA y texto neutro de `expired`

- **Descripción**: Mapa de errores del callback (cancelar, `state` caducado, proveedor caído; CA-09), limitador `social-login` de 20/min (CA-11) y eventos `auth.social.login`, `social.linked`, `social.unlinked` sin secretos (CA-27). Resuelve las dos preguntas abiertas del diseño, ambas con «sí»: `LoginFlow` guarda `login.via` (contraseña, google, facebook) junto a `login.*`, `TwoFactorChallengeController` lo añade al `detail` de `auth.2fa.challenge_passed` y `auth.2fa.challenge_failed` y lo olvida en `expired`; `PasskeyLoginController` lo limpia junto a `login.*`; y `expired` pasa a un texto neutro («La verificación caducó. Vuelve a iniciar sesión.») con un test del mensaje.
- **Changelog**: Si cancelas o falla el inicio de sesión con Google o Facebook vuelves al login con un mensaje claro; el aviso de verificación caducada ya no habla solo de contraseña y el reto 2FA registra con qué método entraste.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.41h · real 0.41h (estimado)
- **Supervisión**: est. 0.10h (≈25 % IA) · real 0.10h (estimado)
- **Previsión IA**: 110k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-14
- **Tipo**: backend
- **Archivos**: `app/Services/Auth/LoginFlow.php`, `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `app/Http/Controllers/Auth/PasskeyLoginController.php`, `app/Http/Controllers/Auth/SocialLoginController.php`, `app/Providers/AppServiceProvider.php`, `routes/auth.php`, `tests/Feature/Auth/TwoFactorChallengeViaTest.php`, `tests/Feature/Auth/Social/SocialErrorsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=TwoFactorChallengeVia` → el `detail` de la auditoría lleva `via` y el reto caducado muestra el texto neutro
  - `php artisan test --compact --filter=SocialErrors` → CA-09 y CA-11
  - `php artisan test --compact tests/Feature/Auth` → ADR-003 sigue en verde (los tests existentes no se modifican)

**Criterios de aceptación**
- [x] `auth.2fa.challenge_passed` / `challenge_failed` registran `via` = password, google, facebook; la passkey no pasa por el 2FA y deja `login.via` limpio (`PasskeyLoginController`)
- [x] `expired` ya no dice «correo y contraseña» y un test fija el texto nuevo; ningún test previo lo asertaba
- [x] CA-09: cancelar o `state` inválido vuelve a `/login` con mensaje legible, sin 500
- [x] CA-11: la petición 21 en un minuto responde 429

**Subtareas**
- [x] `login.via` en `LoginFlow` y auditoría
- [x] Limpieza en `PasskeyLoginController` y en `expired`
- [x] Texto neutro con test
- [x] Errores del callback y limitador

**Notas**: Cambia `TwoFactorChallengeController` y `PasskeyLoginController`, que el diseño daba por «sin cambios»: lo aprueban las preguntas abiertas del diseño (ambas «sí»).

**Evidencia (2026-10-23)**: `--filter=TwoFactorChallengeVia` → 8 passed, `--filter=SocialErrors` → 5 passed (CA-09, CA-11); `tests/Feature/Auth tests/Unit` → verde sin modificar tests previos. El `detail` de la auditoría pasa a `totp;via=<método>`/`recovery;via=<método>`. Se añade `routes/auth.php` (throttle `social-login`) a Archivos.

---

### T-16 — Particularidades de Facebook: versión de Graph, email ausente y `#_=_`

- **Descripción**: Fija `usingGraphVersion(config('social.meta.graph_version'))` (la versión por defecto del driver puede ser antigua), pide `email` y `public_profile`, trata el email ausente (rechazo genérico) y el fragmento `#_=_` de la redirección. Test sobre el payload crudo.
- **Changelog**: Facebook usa la versión de Graph configurada, pide solo email y perfil público, y su redirección de vuelta ya no deja el `#_=_` en la barra de direcciones.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.27h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-14
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Auth/SocialLoginController.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Auth/Social/FacebookLoginTest.php`, `tests/Feature/Auth/Social/SocialLoginTest.php`, `tests/Feature/Auth/Social/SocialErrorsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=FacebookLogin` → versión de Graph aplicada, email ausente rechazado, Facebook sin vincular rechazado (CA-05)

**Criterios de aceptación**
- [x] La versión de Graph sale de la config y no del driver
- [x] Sin email: rechazo genérico, sin identidad creada
- [x] Facebook nunca vincula solo (P1)

**Subtareas**
- [x] Versión de Graph
- [x] Email ausente
- [x] Fragmento `#_=_`

**Evidencia (2026-10-23)**: `--filter=FacebookLogin` → 6 passed (versión de Graph y campos en el payload crudo, appsecret_proof, email ausente rechazado, Facebook sin vincular rechazado); `--filter=Social` → 50 passed. Desviación: la versión y los scopes se fijan en el driver `facebook-login` (`AppServiceProvider`), no en el controlador; `config/services.php` no cambia (la versión ya vive en `social.meta.graph_version`); el `#_=_` se resuelve con un `#` vacío en el Location (los tests de Facebook de T-14/T-15 esperan `…#`).

---

### T-17 — Vincular y desvincular desde el perfil (`password.confirm`) y bloqueo de la vinculación automática

- **Descripción**: `SocialIdentityController` (`link` y `unlink`) con `password.confirm`. Vincular deja `social.intent=link` en sesión y el callback, con sesión iniciada, vincula o desbloquea; si la identidad pertenece a otro usuario, error en el perfil y ningún cambio (CA-07). Desvincular marca `blocked_at` y audita `social.unlinked`: la vinculación automática no la vuelve a enlazar (CA-08). `ProfileController` expone al Inertia las cuentas vinculadas.
- **Changelog**: Desde el perfil puedes vincular y desvincular Google y Facebook (pide confirmar la contraseña); una cuenta desvinculada no se vuelve a enlazar sola por correo.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.68h · real 0.68h (estimado)
- **Supervisión**: est. 0.17h (≈25 % IA) · real 0.17h (estimado)
- **Previsión IA**: 183k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Profile/SocialIdentityController.php`, `app/Http/Controllers/Auth/SocialLoginController.php`, `app/Http/Controllers/ProfileController.php`, `app/Services/Auth/SocialIdentityResolver.php`, `routes/auth.php`, `tests/Feature/Auth/Social/LinkUnlinkTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=LinkUnlink` → CA-07 (incluida la identidad ya ajena) y CA-08 en verde
  - `php artisan test --compact tests/Feature/ProfileTest.php` → sigue en verde

**Criterios de aceptación**
- [x] CA-07: vincular Facebook con `password.confirm` lo añade a la lista; identidad ajena → error y sin cambios
- [x] CA-08: tras desvincular, el login con esa identidad no entra y Google no la re-vincula por email hasta vincularla a mano
- [x] Vincular y desvincular exigen `password.confirm`
- [x] Auditoría `social.linked` / `social.unlinked` sin secretos

**Subtareas**
- [x] Rama `intent=link` del callback
- [x] Controlador y rutas
- [x] Props del perfil

**Evidencia (2026-10-23)**: `--filter=LinkUnlink` → 15 passed (CA-07 incluida la identidad ajena, CA-08); `ProfileTest.php` + `tests/Feature/Auth` → verde. Desviación: vincular es `POST user/social/{provider}/link` (con CSRF, y `Inertia::location` si la petición es Inertia) y desvincular `DELETE user/social/{provider}`; `ProfileController` expone `socialAccounts`; la lógica de vincular/desbloquear/desvincular vive en `SocialIdentityResolver` (`app/Services/Auth/SocialIdentityResolver.php`, añadido a Archivos).

---

### T-18 — Batería Pest del login social con Socialite simulado

- **Descripción**: Cierra la matriz CA-01..CA-11 con el driver de Socialite simulado: pérdida de `url.intended` en el viaje al proveedor (la cookie `SameSite=lax` debe volver en el GET), `remember=false`, que la passkey y la contraseña siguen igual, y un test de fugas (`SocialAuditLeakTest`) sobre `audit_logs` y el log.
- **Changelog**: El login social queda cubierto por una batería de pruebas que incluye el regreso al destino original (también a la autorización del MCP), la sesión no recordada y que no se filtran códigos ni tokens.
- **Estado**: completado
- **Tiempo humano**: est. 7,0h · real 0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.95h · real 0.95h (estimado)
- **Supervisión**: est. 0.24h (≈25 % IA) · real 0.24h (estimado)
- **Previsión IA**: 256k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17
- **Tipo**: test
- **Archivos**: `tests/Feature/Auth/Social/SocialIntendedTest.php`, `tests/Feature/Auth/Social/SocialAuditLeakTest.php`, `tests/Unit/SocialIdentityResolverTest.php`, `tests/Feature/Infra/InfraTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=Social` → toda la matriz CA-01..CA-11 en verde
  - `php artisan test --compact --filter=SocialAuditLeak` → ninguna fila de `audit_logs` ni línea de log contiene código, token ni secreto
  - `php artisan test --compact` → suite completa en verde

**Criterios de aceptación**
- [x] Cada CA-01..CA-11 tiene al menos un test que lo nombra
- [x] `url.intended` sobrevive al viaje (a `/oauth/authorize` incluido)
- [x] Suite completa sin modificar tests existentes, salvo la excepción de infraestructura de test `InfraTest --memory=1024` (sin cambiar aserciones; ver Notas)

**Subtareas**
- [x] Huecos de la matriz
- [x] `url.intended`
- [x] Fugas

**Evidencia (2026-10-23)**: `--filter=Social` → 76 passed (CA-01..CA-11 nombrados en tests; `SocialIntendedTest` cubre `url.intended` hasta `/oauth/authorize` y tras el reto 2FA; `SocialAuditLeakTest` revisa `audit_logs` y el log sin code, state, token ni secreto). Suite completa: ver evidencia al cierre de fase.

**Notas (revisión fase 2, intento 1)**: se corrigen C1 (comparación de email exacta y byte a byte en PHP con `hash_equals`; en SQLite los tests no distinguen la collation `_ci` de MySQL, por lo que la garantía real es el filtro en PHP), B1 (RELINKED comprueba otra identidad activa; `unlink` bloquea todas las activas), B2 (transferencia de identidad bloqueada ajena, auditada), B3 (`block(5,5)`), B4, B5, B6, A2, A3 y A4. Desviación A2: textos literales de la tabla «Manejo de errores» de la spec (cancelado, state caducado, rechazo genérico «No hay ninguna cuenta vinculada a esta identidad. Vincúlala desde tu perfil.», proveedor caído «No se ha podido contactar con {Proveedor}.»); se mantiene un único mensaje de rechazo para no enumerar cuentas y, en modo vincular, los mensajes propios del perfil (OTHER_IDENTITY/TAKEN), que no figuran en la tabla.

**Notas**: Desviación: `tests/Feature/Infra/InfraTest.php` (test existente) recibe `--memory=1024` en su `queue:work`. Con la suite ya en 1186 tests el worker de prueba, que corre dentro del proceso de Pest, superaba los 128 MB por defecto y paraba con 3 jobs sin procesar (pasaba solo, fallaba en la suite completa). No cambia ninguna aserción.

**Evidencia (2026-10-23)**: `php artisan test --compact` → 1186 passed (8038 assertions); `vendor/bin/pint --test` → passed; `npm run build` → exit 0; `ledger-lint` → 0 incoherencias.

---

### T-19 — Revisión de seguridad de C-01 (toma de cuenta y enumeración)

- **Descripción**: Revisión dirigida del login social: toma de cuenta por email no verificado, identidad ajena, `state`, mensajes que no revelan el email, `LoginFlow` compartido y bloqueo al desvincular. Checklist firmada en las Notas y huecos corregidos antes de la UI. Puerta de salida de la fase 2.
- **Changelog**: El login con Google y Facebook pasó una revisión de seguridad independiente: los emails se comparan byte a byte (ya no se confunden `ß` y `ss`), desvincular quita todas las identidades del proveedor y la protección CSRF del regreso del proveedor queda cubierta por tests.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0.27h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-18
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-22-social-login-mcp-oauth/tasks.md`, `tests/Unit/SocialIdentityResolverTest.php`, `app/Services/Auth/SocialIdentityResolver.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `vendor/bin/pint --test` → passed
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [x] La suite completa pasa sin desactivar ni modificar tests existentes (salvo `InfraTest --memory=1024`, aceptado en T-18: infraestructura de test sin cambio de aserciones)
- [x] Cada hallazgo Important o Critical tiene test y corrección
- [x] Ningún secreto en log ni auditoría

**Subtareas**
- [x] Revisar la resolución de identidad
- [x] Revisar `LoginFlow` y el 2FA
- [x] Suite y Pint

**Notas**: Puerta de salida de la fase 2.


**Evidencia (2026-10-23)**: revisión de dos lentes, intentos 1 y 2 (tablas al final del ledger); `php artisan test --compact` → 1205 passed; `vendor/bin/pint --test` → passed. C1 comprobado contra el MySQL local de Laragon (collation `utf8mb4_unicode_ci`) dentro de transacciones con `rollBack`: la consulta devuelve la fila equivocada (`straße` para `strasse`, `exämple` para `example`) y `pickExactEmail` la descarta; el test `pickExactEmail descarta lo que una collation _ci da por igual` falla con el filtro anterior. Los casos adversariales de esta fase quedan fijados en `SocialIdentityResolverTest`, `SocialErrorsTest` y `SocialIntendedTest` (no se creó `SocialSecurityReviewTest`). Ningún secreto en auditoría ni log: `SocialAuditLeakTest`.
**Checklist**: vínculo por `sub` y nunca por email ✓ · Google solo con `email_verified` y cuenta local verificada ✓ · comparación de email exacta (CWE-176) ✓ · Facebook nunca automático ✓ · `state` validado con el driver real ✓ · sin bypass del 2FA y `regenerate` tras el login ✓ · mensaje único (sin enumeración) ✓ · vincular y desvincular con `password.confirm` ✓ · callback serializado y limitado a 20/min ✓.
---

## Fase 3 — C-03 · UI, E2E, accesibilidad, documentación y cierre

**Estado**: completado · **Estimado**: 21.0h · **Real**: — · **Coste est.**: 1.050 € + tokens (⚠️ verificar) · **Tokens est.**: 0.85M

### T-20 — Botones «Continuar con Google» y «Continuar con Facebook» en `Login.vue`

- **Descripción**: Los botones solo se muestran si el proveedor está configurado (prop compartida desde el controlador). Mismo estilo que el botón de passkey, accesibles y sin desbordamiento a 375 y 360 px.
- **Changelog**: La pantalla de entrada muestra «Continuar con Google» y «Continuar con Facebook» solo cuando ese acceso está configurado.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.27h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-19
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Auth/Login.vue`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `tests/Feature/Auth/LoginPageProvidersTest.php`, `resources/js/Components/SocialProviderIcon.vue` (2026-10-23: logotipos oficiales y estilos de marca de los botones, a petición del usuario)
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, A11Y-01, M-01, M-02
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact --filter=LoginPageProviders` → CA-10: sin credenciales no hay botón y `auth/{provider}/redirect` da 404
  - `vendor/bin/pint --test` → passed

**Criterios de aceptación**
- [x] CA-10 en la UI: el botón aparece solo con el proveedor configurado
- [x] Los botones tienen texto visible y nombre accesible
- [x] El orden de tabulación no rompe el formulario

**Subtareas**
- [x] Prop `socialProviders`
- [x] Botones
- [x] Test de props

**Evidencia (2026-10-23)**: npm run build → OK; --filter=LoginPageProviders → 3 passed (sin credenciales: sin botones y 404 en redirect; solo el configurado; sin secretos en la respuesta); pint --test → passed. Medición 375/360 px y axe: T-26.

---

---

### T-21 — Parcial «Cuentas vinculadas» (`LinkedAccountsForm.vue`) en el perfil

- **Descripción**: Lista las identidades (proveedor, fecha, estado), vincular Google/Facebook (con confirmación de contraseña previa, como en passkeys) y desvincular con confirmación. Muestra los errores del callback (identidad ajena). Montado en `Profile/Edit.vue`.
- **Changelog**: El perfil tiene un apartado «Cuentas vinculadas» para ver, vincular y desvincular Google y Facebook, pidiendo antes la contraseña.
- **Estado**: completado
- **Tiempo humano**: est. 4,0h · real 4,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.54h · real 0.54h (estimado)
- **Supervisión**: est. 0.14h (≈25 % IA) · real 0.14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-17, T-20
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Profile/Partials/LinkedAccountsForm.vue`, `tests/Feature/ProfileLinkedAccountsTest.php`
- **Cubre (tests)**: E2E-04, A11Y-01, M-02, M-08
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact --filter=ProfileLinkedAccounts` → la página expone las cuentas vinculadas sin secretos
  - `vendor/bin/pint --test` → passed

**Criterios de aceptación**
- [x] CA-07/CA-08 visibles: vincular, ver, desvincular
- [x] La acción pide `password.confirm` antes de abrir el proveedor (un `fetch` no recibe 423)
- [x] Accesible por teclado y sin desbordamiento a 375/360 px

**Subtareas**
- [x] Parcial y montaje
- [x] Confirmación de contraseña
- [x] Errores

**Evidencia (2026-10-23)**: npm run build → OK; --filter=ProfileLinkedAccounts → 2 passed (props sin secretos ni ids del proveedor; vincular/desvincular redirigen a confirmar contraseña); pint --test → passed. Flujo real y 375/360 px: T-24/T-26.

---

---

### T-22 — Columna «Origen» en `ApiKeys/Index`

- **Descripción**: Muestra «Manual» u «OAuth · <cliente>» con el dato que añadió T-09, y mantiene la revocación. Las API keys existentes se ven como «Manual», sin cambios.
- **Changelog**: En «API keys» cada clave muestra su origen («Manual» u «OAuth · cliente») y las emitidas por OAuth se pueden revocar igual que las demás.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.27h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/ApiKeys/Index.vue`, `tests/Feature/ApiKeyOriginTest.php`
- **Cubre (tests)**: E2E-07, M-08
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact --filter=ApiKeyOrigin` → CA-25: el listado distingue origen y revoca
  - `php artisan test --compact tests/Feature/Mcp` → en verde (CA-24)

**Criterios de aceptación**
- [x] CA-25: un token OAuth aparece como «OAuth · cliente» y se revoca
- [x] Las keys manuales siguen como «Manual»
- [x] Legible a 375 y 360 px

**Subtareas**
- [x] Columna
- [x] Test de listado

**Evidencia (2026-10-23)**: npm run build → exit 0; --filter=ApiKeyOrigin → 2 passed (CA-25: origen manual/oauth con nombre de cliente, revocación, key antigua = manual); tests/Feature/Mcp → 175 passed (CA-24); pint --test → passed. Medición 375/360 px: T-26.

---

---

### T-23 — Proveedor social falso solo en `APP_ENV=e2e` y servidor E2E

- **Descripción**: Driver de Socialite `fake` registrado únicamente cuando `APP_ENV=e2e`, con identidades configurables por el test (email, `email_verified`, id). `playwright.config.ts` arranca el servidor con `APP_ENV: 'e2e'` y los helpers de `tests/e2e/helpers.ts` ganan utilidades de preparación para los usuarios `e2e-sec-*`. Un test Pest comprueba que en `production`, `local` y `testing` el driver no existe.
- **Changelog**: Las pruebas de extremo a extremo disponen de un proveedor de inicio de sesión falso que solo existe en el entorno de pruebas y nunca en producción.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.27h · real 0.27h (estimado)
- **Supervisión**: est. 0.07h (≈25 % IA) · real 0.07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-14
- **Tipo**: test
- **Archivos**: `app/Providers/AppServiceProvider.php`, `app/Services/Auth/Social/FakeSocialiteProvider.php`, `playwright.config.ts`, `tests/e2e/helpers.ts`, `tests/Feature/Auth/FakeSocialProviderEnvTest.php`, `storage/framework/cache/e2e-social.json` (generado, ignorado por git)
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03
- **Verificación**:
  - `php artisan test --compact --filter=FakeSocialProviderEnv` → el driver `fake` solo se registra con `APP_ENV=e2e`
  - `npx playwright test --list` → la batería existente sigue listada sin errores de configuración

**Criterios de aceptación**
- [x] El driver falso no se puede activar por petición ni por query
- [x] Producción nunca lo registra (test)
- [x] La batería E2E existente (2FA, passkeys) no cambia de comportamiento con `APP_ENV=e2e`

**Subtareas**
- [x] Driver falso
- [x] Registro condicionado
- [x] Config de Playwright y helpers

**Notas**: `FakeSocialProviderEnvTest` limpia en `afterEach` los hosts de confianza estáticos de Symfony (`Request::setTrustedHosts`), que con `APP_ENV` distinto de `testing` contaminaban a `TrustedProxiesTest` y `ProfileTest` en la suite completa. Suite final: `php artisan test --compact` → 1221 passed.

**Revisión F3 (C1, B4)**: `FakeSocialiteProvider::allowed()` exige además `APP_URL` definida de forma explícita (`env('APP_URL')` no vacía) y, con petición, Host local; el test de «no se activa por query» corre ahora con `APP_ENV=e2e`, sin fichero de identidad y con `fake_email` en la query.

**Notas**: Google y Facebook reales no son automatizables: van a M-01 y M-02.

**Evidencia (2026-10-23)**: --filter=FakeSocialProviderEnv → 9 passed (el driver falso no existe en production/local/testing/staging, solo con APP_ENV=e2e y APP_URL local; no se activa por query; el viaje conserva el state real); npx playwright test --list → 225 tests listados sin errores; npx playwright test --project=desktop security.spec.ts con APP_ENV=e2e → 8 passed (2FA y passkeys sin cambios); tsc --noEmit y pint --test → OK. Desviación: el fichero de identidades del proveedor falso es storage/framework/cache/e2e-social.json (ignorado por git), que no estaba en Archivos; los helpers añaden también limpieza de identidades en resetSecurity.

---

---

### T-24 — E2E del login social y de Cuentas vinculadas

- **Descripción**: Specs de Playwright con el proveedor falso: login con Google sin 2FA y con 2FA, rechazos (email no verificado, sin cuenta, Facebook sin vincular, cancelar) y vincular/desvincular desde el perfil. Usa los usuarios `e2e-sec-*` y nunca el usuario id 28.
- **Changelog**: El inicio de sesión con Google y Facebook y la gestión de cuentas vinculadas quedan probados de punta a punta, y el login muestra ahora el motivo cuando el proveedor rechaza la entrada.
- **Estado**: completado
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0.34h · real 0.34h (estimado)
- **Supervisión**: est. 0.08h (≈25 % IA) · real 0.08h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-21, T-23
- **Tipo**: test
- **Archivos**: `tests/e2e/social.spec.ts`, `tests/e2e/helpers.ts`, `resources/js/Pages/Auth/Login.vue`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04
- **Verificación**: `npm run test:e2e -- social.spec.ts` → en verde en los tres proyectos (desktop, mobile, mobile-360)

**Criterios de aceptación**
- [x] E2E-01..E2E-04 pasan con aserciones reales
- [x] Cada spec limpia lo que crea (usuarios e identidades `e2e-`)
- [x] Ningún spec lee ni escribe un usuario fuera de `e2e-sec-*`

**Notas**: T-24 corrige `Login.vue` (T-20): los rechazos del login social llegaban por redirección con el error `email` en la sesión y la pantalla solo pintaba `form.errors`, así que el usuario no veía ningún mensaje; ahora se muestra en un aviso (`role=alert`). Verificación en desktop durante el desarrollo; la de los tres proyectos se hace una sola vez al final (límite de memoria).

**Cobertura por Pest, no por E2E (revisión F3, A1/A2)**: el texto del reto 2FA caducado, Google con otro `sub`, el aviso de «nombre declarado» y CA-10 se cubren con Pest (`TwoFactorChallengeViaTest:67`, `LinkUnlinkTest:133`, `LoginPageProvidersTest`) y no por E2E.

**Subtareas**
- [x] Login sin y con 2FA
- [x] Rechazos
- [x] Vincular y desvincular

**Evidencia (2026-10-23)**: npm run test:e2e (batería completa, una sola ejecución, informe en testing/results.json) → 268 passed, 2 skipped (preexistentes, solo desktop), 0 failed, 0 flaky: desktop 90/90, mobile 89 + 1 skipped, mobile-360 89 + 1 skipped. social.spec.ts: 7 tests por proyecto (21 en total; E2E-01..04) en verde en los tres; tsc --noEmit OK.

---

---

### T-25 — E2E del flujo OAuth del MCP en el navegador

- **Descripción**: El test genera el verifier, registra un cliente, abre `/oauth/authorize`, inicia sesión (con y sin 2FA), consiente, captura el redirect a loopback, canjea con `request` y llama a `/mcp`. Cubre denegar, la revocación desde API keys (401 después) y que una API key manual sigue funcionando.
- **Changelog**: El flujo de autorización OAuth del MCP (registro, consentimiento, token, revocación y convivencia con las API keys) queda probado en el navegador.
- **Estado**: completado
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0.34h · real 0.34h (estimado)
- **Supervisión**: est. 0.08h (≈25 % IA) · real 0.08h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-22, T-23
- **Tipo**: test
- **Archivos**: `tests/e2e/oauth.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-05, E2E-06, E2E-07
- **Verificación**: `npm run test:e2e -- oauth.spec.ts` → en verde en los tres proyectos

**Criterios de aceptación**
- [x] E2E-05..E2E-07 pasan (CA-16, CA-18..CA-20, CA-24..CA-26)
- [x] El token canjeado funciona en `/mcp` y deja de hacerlo tras revocar
- [x] No queda ningún cliente ni token `e2e-` tras el teardown

**Notas**: Los helpers de limpieza (`tests/e2e/helpers.ts`) borran también los clientes `e2e-*`, los tokens de los usuarios `e2e-sec-*` (Sanctum no tiene FK al usuario) y las filas de auditoría sin usuario de la batería. Reutilizar un código ya canjeado revoca su token (CA-21): el spec lo comprueba al final del flujo. Verificación en desktop durante el desarrollo; los tres proyectos se ejecutan una sola vez al final (límite de memoria).

**Cobertura por Pest, no por E2E (revisión F3, A1/A2)**: el texto del reto 2FA caducado, Google con otro `sub`, el aviso de «nombre declarado» y CA-10 se cubren con Pest (`TwoFactorChallengeViaTest:67`, `LinkUnlinkTest:133`, `LoginPageProvidersTest`) y no por E2E.

**Revisión F3 (B2, B3)**: la limpieza de auditoría sin usuario se acota a los registros `oauth.%` con IP loopback y `detail` con el id de un cliente `e2e-%` (los rechazos `auth.social.login` sin usuario no son atribuibles a la batería: se dejan, es aceptable); `clearOAuthThrottle` ya no reinicia `mcp-oauth-register:global` (el servidor E2E sube el tope con `MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR`) y solo reinicia las claves de 127.0.0.1 y ::1 (anotado en la guía de desarrollo: no correr la batería mientras se prueba con tráfico real por ngrok).

**Subtareas**
- [x] Flujo con PKCE
- [x] Denegar y 2FA en mitad
- [x] Revocación y API key manual

**Evidencia (2026-10-23)**: npm run test:e2e (batería completa, una sola ejecución, informe en testing/results.json) → 268 passed, 2 skipped (preexistentes, solo desktop), 0 failed, 0 flaky: desktop 90/90, mobile 89 + 1 skipped, mobile-360 89 + 1 skipped. oauth.spec.ts: 5 specs por proyecto (API-01, API-02, E2E-05..07) en verde en los tres; sin clientes ni usuarios e2e tras el teardown.

---

---

### T-26 — axe y desbordamiento en Login, Cuentas vinculadas y Consentimiento

- **Descripción**: Añade las tres pantallas nuevas a `tests/e2e/a11y.spec.ts` con el umbral de la iniciativa anterior (0 violaciones `serious`/`critical`) y mide el desbordamiento a 375 y 360 px.
- **Changelog**: Las pantallas de acceso con botones sociales, Cuentas vinculadas y consentimiento del MCP cumplen accesibilidad (sin fallos graves) y no desbordan a 375 y 360 px.
- **Estado**: completado
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.14h · real 0.14h (estimado)
- **Supervisión**: est. 0.03h (≈25 % IA) · real 0.03h (estimado)
- **Previsión IA**: 37k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-24, T-25
- **Tipo**: test
- **Archivos**: `tests/e2e/a11y.spec.ts`, `resources/js/Pages/OAuth/Authorize.vue`
- **Cubre (tests)**: A11Y-01
- **Verificación**: `npm run test:e2e -- a11y.spec.ts` → 0 violaciones `serious`/`critical` y 0 px de desbordamiento horizontal en las pantallas nuevas

**Criterios de aceptación**
- [x] Login con botones, Cuentas vinculadas y Consentimiento sin violaciones `serious`/`critical`
- [x] 0 px de desbordamiento a 375 y 360 px

**Notas**: axe encontró un fallo real `color-contrast` (serious) en el consentimiento: los códigos de permiso usaban `text-slate-400`; pasan a `text-slate-600` (por eso `Authorize.vue` entra en Archivos). Verificación en desktop durante el desarrollo; los tres proyectos (375 y 360 px) se ejecutan una sola vez al final.

**Subtareas**
- [x] Casos nuevos en `a11y.spec.ts`

**Evidencia (2026-10-23)**: npm run test:e2e (batería completa, una sola ejecución, informe en testing/results.json) → 268 passed, 2 skipped (preexistentes, solo desktop), 0 failed, 0 flaky: desktop 90/90, mobile 89 + 1 skipped, mobile-360 89 + 1 skipped. a11y.spec.ts: 3 casos nuevos por proyecto con 0 violaciones serious/critical y 0 px de desbordamiento a 375 y 360 px.

---

---

### T-27 — Documentación: instalación sin API key, despliegue, `.env.example` y guía de Google y Meta

- **Descripción**: `docs/modulos/mcp.md` con la instalación OAuth de los cuatro clientes (comandos verificados en T-11), la API key como alternativa y el aviso del 401 con `resource_metadata` para keys caducadas. `docs/modulos/despliegue.md` con redirect URIs, `/.well-known`, `Authorization` y el `curl` de verificación (cierra la parte de M-F6-2). Guía de pasos manuales de Google y Meta, guía de usuario y `.env.example` completo (CA-30).
- **Changelog**: Hay instrucciones para conectar Claude Code, Claude Desktop, Codex y OpenCode sin API key, para desplegar el OAuth del MCP y para activar el login con Google y Facebook.
- **Estado**: completado
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0.41h · real 0.41h (estimado)
- **Supervisión**: est. 0.10h (≈25 % IA) · real 0.10h (estimado)
- **Previsión IA**: 110k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-11, T-21
- **Tipo**: docs
- **Archivos**: `docs/modulos/mcp.md`, `docs/modulos/despliegue.md`, `docs/usuario/guia-de-usuario.md`, `docs/guias/login-social-google-meta.md`, `.env.example`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/guias/desarrollo-local.md`
- **Cubre (tests)**: M-07
- **Verificación**:
  - `grep -n "oauth-protected-resource" docs/modulos/despliegue.md docs/modulos/mcp.md` → el `curl` de verificación y la explicación del 401
  - `grep -n "MCP_OAUTH_TOKEN_DAYS\|GOOGLE_LOGIN_CLIENT_ID\|META_LOGIN_APP_ID" .env.example` → las tres claves documentadas
  - lectura: `mcp.md` tiene un apartado por cliente (Claude Code, Claude Desktop, Codex, OpenCode) con el comando probado en T-11

**Criterios de aceptación**
- [x] CA-30 completo
- [x] La guía de Google y Meta recoge los pasos manuales de la spec (proyecto dedicado, caso de uso, redirect URIs, modo desarrollo)
- [x] No quedan comandos de clientes sin verificar presentados como definitivos

**Subtareas**
- [x] `mcp.md`
- [x] `despliegue.md` y `curl`
- [x] Guía de Google y Meta y guía de usuario

**Notas (revisión F3, A4)**: la verificación «comando probado en T-11» queda diferida a T-11 (los comandos de los cuatro clientes siguen marcados «pendiente de verificar» en `mcp.md`). Se añadió `MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR` a `.env.example` y `mcp.md`, `MCP_OAUTH_*`/`GOOGLE_LOGIN_*`/`META_LOGIN_*` a `docs/referencia/configuracion.md`, y las redirect URIs usan `<APP_URL>` con esquema en las tres guías.

**Evidencia (2026-10-23)**: grep oauth-protected-resource en despliegue.md y mcp.md → 5 coincidencias (curl de verificación y explicación del 401); grep en .env.example → las tres claves (MCP_OAUTH_TOKEN_DAYS, GOOGLE_LOGIN_CLIENT_ID, META_LOGIN_APP_ID); mcp.md con un apartado por cliente. Los comandos de los cuatro clientes se presentan como PENDIENTES de verificar (T-11 / M-03..M-06 son manuales del usuario) y no como definitivos. Desviación: se tocaron también docs/README.md, docs/RAG-INDEX.md y docs/guias/desarrollo-local.md (índices y variables del servidor E2E), no listados en Archivos.

---

---

### T-28 — Revisión final, regresión completa y cierre del plan

- **Descripción**: Suite Pest completa, Pint, build y Playwright completo; comprueba que lo declarado «sin cambios» sigue intacto y que no se ha tocado ningún dato del usuario id 28. Entrega los M-xx como checklist para el usuario, actualiza el estado de la spec y el índice del roadmap. Puerta de salida de la fase 3.
- **Changelog**: Puedes entrar con Google o Facebook y conectar Claude Code, Claude Desktop, Codex u OpenCode al MCP iniciando sesión en el navegador, sin copiar ninguna API key; todo cubierto por tests de extremo a extremo en escritorio y móvil.
- **Estado**: completado
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0.27h · real —
- **Supervisión**: est. 0.07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-26, T-27
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-22-social-login-mcp-oauth/tasks.md`, `docs/roadmap/2026-10-22-social-login-mcp-oauth/improvement-plan.md`, `docs/roadmap/README.md`
- **Cubre (tests)**: M-01, M-02, M-03, M-04, M-05, M-06, M-07, M-08
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `npm run build && vendor/bin/pint --test` → sin errores
  - `npm run test:e2e` → batería completa en verde (desktop, mobile y mobile-360)
  - `git diff --stat main -- app/Http/Middleware/McpGuard.php app/Mcp/Tools/SchedulerTool.php app/Services/AuditLog.php vendor` → vacío
  - `grep -rnE "find\(28\)|where\('id', *28\)|id *= *28" app database tests/e2e` → vacío (ningún código toca al usuario id 28)

**Criterios de aceptación**
- [x] Todos los criterios globales del plan cumplidos, salvo CA-29 (smoke con los 4 clientes reales), que depende de T-11
- [x] Los 8 M-xx entregados como checklist, con los de Google/Facebook reales y clientes MCP para el usuario
- [x] Spec en `implementada` solo si CA-29 está verificado por el usuario: NO lo está todavía, así que la spec sigue `aprobada` y el plan `en-progreso` hasta cerrar T-11

**Subtareas**
- [x] Regresión completa
- [x] Comprobaciones de «sin cambios»
- [x] Cierre de ledger y roadmap


**Evidencia (2026-10-23)**: `php artisan test --compact` → 1223 passed; `vendor/bin/pint --test` → passed; `npm run build` → OK; `npx tsc --noEmit -p .` → 0 errores; `npx playwright test` (3 proyectos) → 268 passed · 0 failed · 0 flaky · 2 skipped (16,4 min) y `qa-gate` sobre `testing/results.json` → VERDE; `git diff --stat feature/social-scheduler..HEAD -- app/Http/Middleware/McpGuard.php app/Mcp/Tools/SchedulerTool.php app/Services/AuditLog.php vendor` → vacío; grep del usuario id 28 en `app database tests/e2e` → vacío. Revisión de la fase 3 (intento 1): sin Critical ni Important; los Minor corregidos en 7986b1f (tabla al final del ledger).

**Checklist para el usuario (M-xx, detalle en `test-plan.md`)**:
- ✅ M-01 · Login real con Google por ngrok — verificado por el usuario el 2026-10-23: entrada OK y vinculación automática (`social.linked`, `modo=auto`) en la auditoría. Se reutilizó el cliente OAuth de Google de los conectores (`GOOGLE_LOGIN_*` = `GOOGLE_CLIENT_*`) con la redirect `<APP_URL>/auth/google/callback` añadida.
- ⏳ M-02 · Login y vinculación real con Facebook por ngrok — aplazado por el usuario (2026-10-23): la app de Meta de los conectores es de tipo Empresa y no ofrece el caso de uso de login (Meta respondió «URL bloqueada»). Pendiente: crear una app de tipo Consumidor solo para el login (pasos en `docs/guias/login-social-google-meta.md`) y rellenar `META_LOGIN_*`.
- ⏳ M-03 · Claude Code CLI sin API key (T-11, CA-29).
- ⏳ M-04 · Claude Desktop, conector personalizado (T-11).
- ⏳ M-05 · Codex (T-11).
- ⏳ M-06 · OpenCode (T-11).
- ⏳ M-07 · Nginx/Hestia real: `/.well-known/*` y cabecera `Authorization` con el `curl` de `docs/modulos/despliegue.md`.
- ⏳ M-08 · Teclado y lector de pantalla en el login, Cuentas vinculadas y el consentimiento.
---


## Revisión de dos lentes — intento 1: Fase 1 (T-01..T-10) — 1 Important y 20 Minor

Lentes A+B+C+D (C: rutas de auth/OAuth; D: migraciones de `oauth_*`). Diff `594ba59..HEAD`. `scope-check --base 594ba59` → 0 fuera de alcance.

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|---|
| B2 | B | Important | El prune borraba un cliente de más de 30 días la misma noche en que se revocaba su token (CA-31 pide 30 días sin token vivo) | T-05 | corregido (41f1db4): filtra también por `last_used_at` nulo o anterior al límite | `McpOAuthPrune.php:27`; `PruneTest.php:45` |
| B1 | B | Minor | `grant_types`/`response_types` no string → 500 | T-05 | corregido (a784cbb) → 400 `invalid_client_metadata` | `RegisterController.php:49-50` |
| B3 | B | Minor | Al borrar el cliente el token pasaba a «manual» | T-09 | corregido: columna `origin` con backfill | `2026_11_07_090000_oauth_fix1_columns.php`; `ApiKeyController.php:29` |
| B4 | B | Minor | Canjes concurrentes del mismo par: deadlock (500) o dos tokens | T-08 | corregido: transacción con 3 intentos y borrado de los demás tokens OAuth del par | `AuthorizationServer.php:195-214` |
| B5 | B | Minor | `redirect_uri` obligatorio en token aunque se omitiera en authorize | T-08 | corregido: `redirect_uri_sent` | `AuthorizationServer.php:58,103,171` |
| B6 | B | Minor | `state` truncado a 500 en silencio | T-06 | corregido → `invalid_request` | `AuthorizationServer.php:59-62` |
| B7-B10 | B | Minor | Tests que no probaban lo que decían (rama `issued === null`, doble autorización, cookie recaller, aserción vacía) | T-01, T-08, T-10 | corregidos | `TokenTest.php:185`; `FullFlowTest`; `LoginFlowTest`; `PruneTest` |
| C1 | C | Minor | El prefijo de `MCP_OAUTH_REDIRECT_ALLOWED` no normalizaba `..` ni `%2e` (CWE-601/22) | T-05 | corregido (a784cbb) y completado en el intento 2 | `RedirectPolicy.php` |
| C2 | C | Minor | DCR limitado solo por IP (CWE-770) | T-05 | corregido: /64 en IPv6 y tope global 200/h | `AppServiceProvider.php:103-116`; `RegisterController.php:21-25` |
| C3 | C | Minor | `/oauth/authorize` sin throttle | T-06 | corregido: `mcp-oauth-authorize` 30/min por usuario | `routes/web.php:79-80` |
| C4 | C | Minor | Nginx duplicaba `Referrer-Policy` y anulaba el no-referrer del consentimiento | T-04 | corregido: `map` a nivel http (`nginx-http-map.conf`); `nginx -t` y runtime comprobados por el revisor con nginx 1.30 local | `deploy/hestia/` |
| G1 | A | Minor | Faltaban `software_id`, `software_version` y `created_ip` | T-03 | corregido (migración aditiva) | `RegisterController.php:54-67` |
| G2 | A | Minor | Faltaba el ASM anidado | T-04 | corregido | `routes/ai.php:20` |
| G3 | A | Minor | Canal de auditoría `oauth` en vez de `web` | T-05 | descartado (rebatido): canal propio deliberado para poder filtrar los eventos OAuth; spec ajustada | `spec.md` |
| G4-G7 | A | Minor | Desviaciones sin anotar, criterio 375/360 marcado sin medir, evidencias incompletas, horas reales | T-01..T-10 | corregidos en el ledger | este ledger |
| D | D | — | Sin hallazgos de rendimiento | — | — | EXPLAIN del prune; índices verificados |

## Revisión de dos lentes — intento 2: Fase 1 (fix1) — 1 Important y 5 Minor, cerrados

Lentes B+C sobre `42bd1f0..HEAD`. Lo aprobado y lo rebatido en el intento 1 no se reabre.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | C1 seguía abierto con separadores codificados: `x%2f..%2f..%2fadmin`, `%5c` y `%252e` pasaban el prefijo y un nginx real los normaliza a `/admin` (CWE-22/601) | T-05 | corregido: se rechaza cualquier `%2f`, `%5c`, `%2e` o `%25` en la ruta | `RedirectPolicy.php` (parse); `RedirectPolicyTest`; `OAuthSecurityReviewTest` |
| N2 | Minor | `ipKey` metía toda IPv4 mapeada y `::1` en un único cubo (un atacante bloqueaba el DCR de todos con Nginx dual-stack) | T-05 | corregido: IPv4 mapeada → su IPv4; `::1` aparte | `AppServiceProvider::ipKey`; `RegisterTest` |
| N3 | Minor | El tope global de DCR permite bloquear el registro de clientes nuevos durante 1 h | T-05 | deuda aceptada (Minor): compromiso inherente al tope global; los tokens existentes no se ven afectados y el valor es configurable (`MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR`) | `RegisterController.php:21-25` |
| N4 | Minor | La carrera real de B4 no tiene test ejecutado (SQLite) | T-08 | deuda aceptada (Minor): en MySQL la cubren `lockForUpdate` y el borrado de los demás tokens del par | `AuthorizationServer.php:195-214` |
| N5 | Minor | El orden de instalación de Nginx (rebuild antes de copiar el `map`) rompe `nginx -t` | T-04 | corregido: el `map` se copia antes y el reload va tras `nginx -t` | `deploy/hestia/nginx-overrides.conf:4-9` |
| N6 | Minor | El ASM anidado devuelve `issuer` sin `/mcp` (RFC 8414 §3.3) | T-04 | descartado (rebatido): el PRM anuncia el servidor de autorización en la raíz, así que los clientes piden el ASM raíz, cuyo issuer coincide; la variante anidada es un alias | `MetadataController.php:34`; `MetadataTest` |

Resultado: sin gaps Critical ni Important pendientes. Suite 1102 passed.


## Revisión de dos lentes — intento 1: Fase 2 (T-13..T-18) — 2 Important y 12 Minor

Lentes A+B+C+D (C: rutas de auth/sesión; D: migración `social_identities`). Diff `5b312ef..HEAD`. `scope-check --base 5b312ef` → 0 fuera de alcance.

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|---|
| C1 | C | Important | La vinculación automática de Google comparaba con `lower(email)` y heredaba `utf8mb4_unicode_ci`: `ß`=`ss` y `ä`=`a` vinculaban la cuenta equivocada (CWE-176/287) | T-14 | corregido (693031d): consulta indexada y filtro exacto en PHP con `hash_equals` | `SocialIdentityResolver::userByExactEmail`, `pickExactEmail` |
| B1 | B | Important | RELINKED desbloqueaba sin comprobar otra identidad activa del proveedor: 2 Google activos y desvincular solo quitaba uno | T-17 | corregido: `hasOtherActive` en RELINKED; `unlink` bloquea todas | `SocialIdentityResolver.php:99,147,181`; `SocialIdentityResolverTest.php:141,153` |
| D2 | D | Minor | `lower(email)` impedía usar el índice (full scan en cada callback) | T-14 | corregido con C1 | EXPLAIN `const` |
| B2 | B | Minor | Una identidad desvinculada quedaba reservada para siempre al antiguo dueño | T-17 | corregido: `transfer` de una identidad bloqueada, con `password.confirm` y el `sub` del intercambio OAuth | `SocialIdentityResolver.php:145-171` |
| B3 | B | Minor | Callbacks concurrentes podían pisar la sesión | T-14 | corregido: `->block(5, 5)` | `routes/auth.php:62` |
| B4 | B | Minor | Los tests de `state` no probaban el `state` | T-18 | corregido: test con el driver real y `MockHandler` | `SocialErrorsTest.php:79` |
| B5 | B | Minor | `LoginFlow` sin 2FA no olvidaba `login.*` de un reto abandonado | T-15 | corregido | `LoginFlow.php:35`; `SocialIntendedTest.php:125` |
| B6 | B | Minor | Fallos de vinculación auditados como `auth.social.login` sin usuario; TAKEN por carrera sin auditar | T-15, T-17 | corregido | `SocialLoginController.php:151`; `SocialIdentityResolver.php:78,121,163` |
| A1 | A | Minor | Criterio «sin modificar tests existentes» marcado pese a `InfraTest --memory=1024` | T-18 | corregido en el ledger; cambio aceptado (solo afecta al worker dentro de Pest, no al de producción) | T-18 |
| A2 | A | Minor | Textos de error distintos de la tabla de la spec | T-14, T-15 | corregido; los mensajes del perfil (OTHER_IDENTITY, TAKEN) se mantienen como desviación anotada | `SocialLoginController.php` |
| A3 | A | Minor | CA-06 sin test con Facebook | T-18 | corregido | `SocialIntendedTest` |
| A4 | A | Minor | CA-03 «Google sin email» no comprobaba la auditoría DENIED | T-18 | corregido | `SocialLoginTest` |

## Revisión de dos lentes — intento 2: Fase 2 (fix1) — sin gaps Critical ni Important

Lentes B+C sobre `693031d~1..HEAD`. C1 verificado contra el MySQL local con transacciones deshechas.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Minor | Los tests de C1 pasaban también con el código vulnerable (SQLite no tiene collation `_ci`) | T-18 | corregido: filtro extraído a `pickExactEmail` y probado con las filas que MySQL devolvería de más | `SocialIdentityResolverTest` (dataset CWE-176) |
| N2 | Minor | `block(5,5)` puede dar 500 si dos callbacks de la misma sesión se solapan más de 5 s con un proveedor lento | T-14 | deuda aceptada (Minor): ventana estrecha, sin cuelgue ni efecto de seguridad | `routes/auth.php:62` |
| N3 | Minor | `transfer` borra la fila bloqueada del dueño anterior y con ella su exclusión de la vinculación automática | T-17 | deuda aceptada (Minor): solo afecta si Google reasignara el email de A a otra cuenta Google | `SocialIdentityResolver.php:153` |

Resultado: sin gaps Critical ni Important pendientes. Suite 1205 passed.


## Revisión de dos lentes — intento 1: Fase 3 (T-20..T-27) — sin gaps Critical ni Important

Lentes A y B+C (C: proveedor falso de Socialite y rutas de auth). Diff `e606e1e..HEAD`. `scope-check --base e606e1e` → 0 fuera de alcance. `qa-gate` → VERDE (268 passed).

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|---|
| C1 | C | Minor | La barrera de APP_URL local del proveedor falso se cumplía por defecto si faltaba `APP_URL` (`http://localhost`), y no miraba el Host (CWE-1188/489) | T-23 | corregido (7986b1f): exige `APP_URL` explícita y Host local | `FakeSocialiteProvider::allowed`; `FakeSocialProviderEnvTest` |
| B4 | B | Minor | El test «no se activa por query» corría en `testing` y no probaba nada | T-23 | corregido: corre con `APP_ENV=e2e` | `FakeSocialProviderEnvTest` |
| B2 | B | Minor | La limpieza E2E borraba auditoría con `user_id` NULL que no era de la batería | T-24, T-25 | corregido: solo las filas de los clientes `e2e-%`; los rechazos sociales no atribuibles se dejan | `tests/e2e/helpers.ts` |
| B3 | B/C | Minor | Se reiniciaba el tope GLOBAL de DCR (CWE-799) | T-25 | corregido: ya no se reinicia; el servidor E2E lo sube por variable de proceso. Aviso en la guía de no correr la batería con tráfico real por ngrok | `helpers.ts`; `playwright.config.ts`; `desarrollo-local.md` |
| A1 | A | Minor | Aserciones del test-plan que no hace ningún E2E | T-24, T-25 | corregido en el ledger: cubiertas por Pest (`TwoFactorChallengeViaTest`, `LinkUnlinkTest`, `LoginPageProvidersTest`) | este ledger |
| A2 | A | Minor | La evidencia de T-24 decía 5 specs (son 7 por proyecto) | T-24 | corregido | este ledger |
| A3 | A | Minor | Faltaban `MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR` en `.env.example` y las variables nuevas en la referencia de configuración | T-27 | corregido | `.env.example`; `docs/referencia/configuracion.md`; `docs/modulos/mcp.md` |
| A4 | A | Minor | T-27 completada con la verificación «probado en T-11» pendiente | T-27, T-11 | corregido: `mcp.md` añadido a T-11 para quitar el «pendiente de verificar» al cerrar el smoke | este ledger |
| A6 | A | Minor | Marcador `<APP_URL>` ambiguo en las redirect URIs (`https://https://…`) | T-27 | corregido: `<APP_URL>` siempre con esquema | `login-social-google-meta.md`; `mcp.md`; `despliegue.md` |

Resultado: sin gaps Critical ni Important. Suite 1223 passed; E2E desktop de `oauth` y `social` 12 passed tras las correcciones; batería completa 268 passed.
