---
design: account-2fa-passkeys
titulo: "Seguridad de cuenta: doble factor (TOTP) y passkeys"
estado: aprobado              # borrador | aprobado | obsoleto
creado: "2026-10-22"
actualizado: "2026-10-22"
spec: spec.md                 # enlace hacia atrás (misma carpeta)
evaluacion: evaluation.md
plan: improvement-plan.md
adr: docs/knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md
opcion_elegida: O1            # validada por el usuario el 2026-10-22 (vía orquestador, «elegida: O1»)
generacion:
  inicio: 2026-10-22T09:03:59Z
  fin: 2026-10-22T09:19:20Z
  fuente: medido              # usage-meter.py (pasada 1 + pasada 2 sumadas)
  tokens_reales: { entrada: 76, salida: 35158, cache_creacion: 130531, cache_lectura: 2850799 }
  eur: null                   # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.56
  duracion: 9m                # reloj: 8m (pasada 1) + 1m (pasada 2)
  ratio_usado: 300000         # default no calibrado
---

# Diseño — Seguridad de cuenta: doble factor (TOTP) y passkeys

> **Spec:** [`spec.md`](spec.md) (aprobada) · **Evaluación:** [`evaluation.md`](evaluation.md) · **Plan:** [`improvement-plan.md`](improvement-plan.md) · **ADR:** [`ADR-003`](../../knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md) (aceptada)

| | |
|---|---|
| **Estado** | aprobado |
| **Opción elegida** | O1 — Breeze + `google2fa` propio + `laravel/passkeys` (Actions del paquete, rutas propias) |
| **Validada por el usuario** | 2026-10-22 (vía orquestador: «elegida: O1» + los 4 puntos de §7 aceptados) |

## 1. Contexto y restricciones

La spec pide dos cosas. Primero, 2FA opcional por usuario con TOTP y 8 códigos de recuperación, con reto en el login (rate limit 5/min, caducidad 5 min, antirreplay). Segundo, passkeys *discoverable* con `userVerification: required` que sustituyen a contraseña + 2FA en un solo paso, con varias por usuario y alta, renombrado y borrado desde el perfil. Todo sin tocar el MCP (CA-22) y con E2E en Chromium usando el autenticador virtual.

Qué hay hoy en el repo (verificado el 2026-10-22):

- **Login Breeze.** `app/Http/Controllers/Auth/AuthenticatedSessionController.php::store` llama a `app/Http/Requests/Auth/LoginRequest.php::authenticate()`. Este método hace `Auth::attempt()` y **abre la sesión dentro del FormRequest**. El rate limit es de 5 intentos por `email|ip`.
- **Rutas.** `routes/auth.php` tiene los grupos `guest` y `auth`, `password.confirm` y el registro condicionado. El perfil está en `routes/web.php:90-94`.
- **Modelo.** `app/Models/User.php` usa `#[Fillable]`, `#[Hidden(['password','remember_token'])]` y `HasApiTokens`. Los `casts()` no tienen ningún campo de 2FA.
- **UI.** `resources/js/Pages/Profile/Edit.vue` tiene 3 tarjetas. `resources/js/Pages/Auth/Login.vue` tiene `autocomplete="username"` en el email.
- **Auditoría.** `app/Services/AuditLog.php::record(..., channel)`, con `action` de 80 caracteres y `detail` de 500.
- **Infraestructura.** Cache y sesiones en BD (`CACHE_STORE=database`, `SESSION_DRIVER=database`). `bootstrap/app.php` aplica `trustHosts()`, que Laravel no aplica en `local` ni en tests.
- **Dependencias.** No hay ningún paquete de 2FA, QR ni WebAuthn. El PHP local es 8.3.33 con `openssl`, `sodium` y `bcmath`.
- **E2E.** `playwright.config.ts`: 3 proyectos Chromium, `baseURL` `http://127.0.0.1:8080` (no vale como RP ID).

**Resultado de los `composer require --dry-run`** sobre el `composer.lock` real (`laravel/framework v13.37.0`). Se comprobó con `md5sum` que `composer.json` y `composer.lock` quedaron idénticos antes y después, y que no se creó nada en `vendor/`.

| Paquete | Resuelve | Instala | Aviso |
|---|---|---|---|
| `pragmarx/google2fa:^9.1` + `bacon/bacon-qr-code:^3.1` | Sí: v9.1.0 + v3.1.1 | 4 paquetes (`dasprid/enum`, `paragonie/constant_time_encoding`) | ninguno |
| `laravel/passkeys:~0.2.1` | Sí: v0.2.1 | 11 paquetes (`web-auth/webauthn-lib` 5.3.9, `web-auth/cose-lib`, `spomky-labs/*`, `symfony/serializer` 7.4…) | ninguno |
| `laravel/passkeys` + `google2fa` + `bacon` juntos | Sí | 14 paquetes | ninguno |
| `spatie/laravel-passkeys:^1.8` | Sí: 1.8.1 | 12 paquetes (los mismos de `webauthn-lib` + `spatie/laravel-package-tools`) | ninguno |
| `laravel/fortify:^1.41` | Sí: v1.41.0 | 15 paquetes (arrastra `laravel/passkeys` v0.2.1, `google2fa` y `bacon`) | ninguno |
| `laragear/webauthn:^5.0` | Sí: v5.0.2 | 2 paquetes | **abandonado**: «Package laragear/webauthn is abandoned… Use laravel/passkeys instead» |

En ningún caso hay *advisories* de seguridad (`No security vulnerability advisories found`).

**Hechos de las librerías** (código fuente leído, no solo el README):

- **`laravel/passkeys` v0.2.1:**
  - Registro y login ya fijan `residentKey: required` y `userVerification: required`.
  - Las opciones de cada ceremonia se guardan en sesión y se consumen con `pull`, así que el reto es de un solo uso.
  - `webauthn-lib` 5.3.9 solo valida el contador si alguno de los dos valores es distinto de 0, así que tolera las passkeys sincronizadas.
  - Trae `Passkeys::ignoreRoutes()`, unas *Actions* reemplazables (`GenerateRegistrationOptions`, `StorePasskey`, `GenerateVerificationOptions`, `VerifyPasskey`, `DeletePasskey`) y los eventos `PasskeyRegistered`, `PasskeyVerified` y `PasskeyDeleted`.
  - Lo que **no** trae: renombrado, evento de fallo, `rp_name` propio (usa el RP ID) y 404 ante una passkey ajena (el `destroy` hace `abort_unless(..., 403)`).
  - El `user_handle_secret` vale `APP_KEY` por defecto.
  - El cliente `@laravel/passkeys` 0.4.0 trae un adaptador `./vue` (`usePasskeyVerify` / `usePasskeyRegister`, con `isSupported` y `autofill`), envía `XSRF-TOKEN` y depende de `@simplewebauthn/browser` ^13.
- **`laravel/fortify` v1.41.0:** depende de `laravel/passkeys ^0.2.0` y de `pragmarx/google2fa ^9`. Su antirreplay del TOTP usa la cache (`fortify.2fa_codes.*`).

**Restricciones que fijan el espacio de soluciones**

- **ADR-001** (`propuesta`): monolito Laravel en Hestia con Inertia + Vue, priorizando el ecosistema oficial de Laravel. No hay Livewire en el proyecto. No existe `docs/CONSTITUTION.md`.
- **No se tocan** `routes/ai.php`, `app/Http/Middleware/McpGuard.php`, `config/sanctum.php` ni `ApiKeyController` (criterio de proceso + CA-22).
- **Hay que partir `LoginRequest::authenticate()`:** validar las credenciales sin abrir sesión, y abrir la sesión en el controlador o en el reto.
- **La spec exige cosas que ninguna librería da sin código propio:** 404 ante una passkey ajena (CA-19), renombrado con un máximo de 100 caracteres (CA-15), auditoría también de los fallos (CA-18), throttle de 10/min (CA-21) y aviso de contexto no seguro (CA-20).
- **Testabilidad:** feature tests en Pest sin navegador, y E2E con CDP `WebAuthn.addVirtualAuthenticator` sobre `http://localhost:8080` con `WEBAUTHN_RP_ID=localhost`.
- **Presupuesto:** la evaluación (136,8h) supone google2fa directo y avisa de +8-12h si se elige Fortify completo.

## 2. Opciones (2-3)

Hay **dos ejes** de decisión: dónde vive el 2FA y qué librería de passkeys se usa. Las tres opciones son combinaciones coherentes y distintas entre sí. Las variantes que no aportan un trade-off nuevo se descartan en §4.

### O1 — Breeze + `google2fa` propio + `laravel/passkeys` (Actions del paquete, rutas propias)

- **TOTP en casa.** Un servicio `App\Services\TwoFactor\TotpService` sobre `pragmarx/google2fa` 9.1 (`verifyKeyNewer` con ventana ±1 y el último *timestamp* guardado en `users.two_factor_last_used_at`, como antirreplay persistente) más `bacon/bacon-qr-code` 3.1, que genera el QR en SVG en el servidor.
- **Login partido.** `LoginRequest::authenticate()` se divide en `validateCredentials(): User`, que usa `Auth::validate` / `getProvider()->retrieveByCredentials` sin abrir sesión. `AuthenticatedSessionController::store` decide entre `Auth::login` y redirigir al reto, y un `TwoFactorChallengeController` nuevo cierra el login.
- **Passkeys con `laravel/passkeys` ~0.2.1:**
  - Se llama a `Passkeys::ignoreRoutes()` y las rutas propias en `routes/auth.php` **delegan en sus Actions**. Así se controlan el 404 ajeno, el renombrado, el throttle de 10/min, la auditoría de los fallos y la respuesta JSON con `redirect` para Inertia.
  - Se mantienen las mismas URL que espera el cliente (`/passkeys/login/options`, `/passkeys/login`, `/user/passkeys/options`, `/user/passkeys`), así que `@laravel/passkeys/vue` funciona sin configurar nada.
  - Tabla `passkeys` del paquete (migración publicada) + una columna propia de `name` de 100 caracteres validada en nuestra request.
- **Testabilidad.**
  - Pest: el 2FA se prueba calculando el TOTP con el mismo `Google2FA` y el secreto conocido.
  - Pest, passkeys: los controladores propios se prueban con *bind* de una `VerifyPasskey` falsa. La ceremonia real se prueba con un vector grabado una vez con el autenticador virtual, inyectando en sesión `passkey.verification_options` con su reto.
  - Playwright: TOTP en Node (RFC 6238) y CDP en Chromium.

| Criterio | Valoración |
|---|---|
| Complejidad | **Media.** Un servicio TOTP pequeño, el reto propio y unos controladores finos sobre las Actions. La parte criptográfica de WebAuthn no se escribe. |
| Riesgo | **Medio.** `laravel/passkeys` es pre-1.0: un 0.3 puede romper la firma de las Actions o el esquema de `credential`. Se acota fijando `~0.2.1`, encapsulando las llamadas en `App\Services\Passkeys\PasskeyGateway` y cubriéndolas con tests. El reto 2FA es código de seguridad propio y exige su revisión. |
| Coste relativo | **S.** Es el escenario que ya presupuestó la evaluación. Ahorra algo en C-02 porque el cliente Vue y la ceremonia vienen hechos. |
| Reversibilidad | **Alta en el TOTP**: las columnas de `users` tienen los mismos nombres que Fortify, así que migrar a Fortify más adelante es casi directo. **Media en las passkeys**: cambiar de librería obliga a migrar el JSON `credential` o a pedir que se vuelvan a registrar. |
| Choque con Breeze | **Ninguno.** Breeze sigue siendo el dueño del login, el registro y el reset. |
| Madurez | google2fa 9.x es muy maduro. `laravel/passkeys` 0.2.1 es oficial, pero 0.x y con 3 versiones publicadas. Por debajo usa `webauthn-lib` 5.3.9, que es maduro. |
| Vue / Inertia | Adaptador oficial `@laravel/passkeys/vue` (composables y autocompletado). |
| Testabilidad (Pest / Playwright) | Buena: las Actions se pueden reemplazar con `bind` y el reto vive en una sesión inyectable. El autenticador virtual vale con `localhost`. |

### O2 — Breeze + `google2fa` propio + `spatie/laravel-passkeys` 1.8 + cliente Vue a mano

- **TOTP y login partido:** igual que en O1.
- **Passkeys con `spatie/laravel-passkeys` 1.8.1:** versión estable 1.x, también sobre `webauthn-lib ^5.3`. Se usan sus Actions (generar opciones, guardar la passkey, buscarla para autenticar) desde controladores propios.
- **Front a mano:** su UI es un componente Livewire que aquí no sirve. Se escribe un composable `resources/js/Composables/useWebAuthn.js` sobre `@simplewebauthn/browser` 14 (`startRegistration` / `startAuthentication` / `browserSupportsWebAuthn`, base64url, CSRF y `NotAllowedError`).

| Criterio | Valoración |
|---|---|
| Complejidad | **Media-Alta.** Lo mismo que O1 más el cliente WebAuthn propio y adaptar una librería que está pensada para Livewire. |
| Riesgo | **Bajo-Medio.** La API es 1.x estable. Lo que se arriesga es el composable propio, que es código de seguridad en el cliente (codificaciones o `excludeCredentials` mal pasados). |
| Coste relativo | **M.** Es el escenario de +4h de «cliente JS» que la evaluación ya contemplaba, más la fricción de una documentación orientada a Livewire. |
| Reversibilidad | Igual que O1 en el TOTP. **Media en las passkeys**: misma situación de esquema propio de la librería. El composable es reutilizable con cualquier backend. |
| Choque con Breeze | **Ninguno.** |
| Madurez | La más estable en el lado de las passkeys (1.x, de Spatie). Pero se sale del «ecosistema oficial» que prioriza ADR-001. |
| Vue / Inertia | Sin adaptador: todo a mano. |
| Testabilidad (Pest / Playwright) | Similar a O1. El composable propio exige además tests de unidad en JS o descansar solo en el E2E. |

### O3 — Migrar la autenticación a Fortify 1.41 sin vistas (2FA y passkeys de Fortify)

- **Qué es.** Es el camino del *starter kit* oficial de Vue: Fortify en modo sin vistas (`'views' => false`) se queda con `/login`, el reto `two-factor-challenge`, la confirmación de contraseña y, con `Features::passkeys()`, las rutas de `laravel/passkeys`.
- **Qué cambia en Breeze.**
  - Se retiran `AuthenticatedSessionController@store`, `LoginRequest::authenticate()` y, según cómo se configure, el registro y el reset de Breeze.
  - Las páginas Vue se registran con `Fortify::loginView` / `twoFactorChallengeView`, y la lógica se ajusta con *pipelines* y *Actions* de Fortify.
  - El 2FA (secreto cifrado, códigos de recuperación cifrados en JSON, antirreplay en cache) y su reto vienen hechos.

| Criterio | Valoración |
|---|---|
| Complejidad | **Alta al principio y Baja después.** Hay que convivir con o sustituir el andamiaje de Breeze: rutas duplicadas `/login`, `/register`, `/forgot-password`, `password.confirm`, middleware `guest`. Requisitos de la spec como el throttle propio del reto, la auditoría de cada fallo y el aviso de reto caducado se hacen con *pipelines* y *listeners* de Fortify. |
| Riesgo | **Medio-Alto.** Hay que reescribir el flujo de login que hoy cubren 8 ficheros de `tests/Feature/Auth/*`, y la regresión del login clásico es el riesgo nº 1 de la evaluación. Además hereda la misma dependencia pre-1.0 (`laravel/passkeys ^0.2.0`). |
| Coste relativo | **L.** Es el +8-12h de la evaluación por migrar el login, menos el ahorro del TOTP y del reto. En neto sale por encima de O1. |
| Reversibilidad | **Baja.** Volver a Breeze supone reescribir otra vez la autenticación. A cambio, después queda alineado con lo que Laravel mantiene. |
| Choque con Breeze | **Total, por diseño:** se sustituye. |
| Madurez | Fortify 1.x es muy maduro en 2FA. Sus passkeys son las mismas 0.2 de O1. |
| Vue / Inertia | Bueno: es el patrón del starter kit Vue, con `@laravel/passkeys/vue`. |
| Testabilidad (Pest / Playwright) | Buena: los tests de Fortify cubren lo genérico. Los propios cambian de forma porque hay que adaptar `tests/Feature/Auth/*`. |

## 3. Criterios de decisión

1. **Que no haya regresión en el login clásico ni en el MCP.** Es el riesgo Alto de la evaluación. Penaliza tocar más superficie de autenticación que la imprescindible.
2. **No escribir criptografía WebAuthn ni código de ceremonia propio.** La verificación de firma, el origen y el contador deben venir de una librería auditada, y el cliente, mejor si viene hecho.
3. **Cumplir la spec sin forzar la librería.** El 404 ajeno, el renombrado, la auditoría de fallos, el throttle de 10/min y el reto de 5 min tienen que poder hacerse sin parchear `vendor/`.
4. **Encaje con el stack (ADR-001)**: Inertia + Vue, sin Livewire y con el ecosistema oficial de Laravel por delante.
5. **Mantener el presupuesto evaluado (136,8h).** Con la misma funcionalidad, gana la opción de menor coste relativo.
6. **Reversibilidad frente a la madurez pre-1.0.** Si se elige un 0.x, tiene que estar encapsulado y fijado.

## 4. Recomendación · opción elegida y por qué

**Recomendación del arquitecto: O1.** Es la única opción que cumple los criterios 1 a 5 a la vez:

- No toca el resto de Breeze. Solo se parte `authenticate()`.
- La criptografía y el cliente Vue vienen hechos y son oficiales.
- Las exigencias de la spec se resuelven con rutas propias sobre las Actions, que el paquete está pensado para permitir (`ignoreRoutes`, *bind* de Actions).
- Es el escenario presupuestado.

El riesgo pre-1.0 (criterio 6) se acota con `~0.2.1`, un *gateway* propio y tests de la ceremonia. Hay además una salida natural: Fortify usa el mismo paquete y las mismas columnas de `users`, así que migrar más adelante es barato.

**O1 — Breeze + `google2fa` propio + `laravel/passkeys` (Actions del paquete, rutas propias).** El usuario la validó el 2026-10-22.

Se ancla así en los criterios:

- Solo se modifica `authenticate()` y el resto de Breeze queda intacto (criterio 1).
- La criptografía WebAuthn y el cliente Vue son oficiales (criterios 2 y 4).
- Con `ignoreRoutes()` y rutas propias se cumplen el 404 ante una passkey ajena, el renombrado, la auditoría de fallos y los 10/min (criterio 3).
- Es el coste S que ya estaba presupuestado (criterio 5).
- `~0.2.1` fijado y el `PasskeyGateway` acotan el riesgo pre-1.0 (criterio 6).

La decisión queda registrada en [ADR-003](../../knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md).

**Decisiones complementarias aceptadas por el usuario** (antes, preguntas abiertas de §7):

1. **Sin `WEBAUTHN_RP_NAME`.** El nombre del RP sale del RP ID, que es el comportamiento del paquete. Las menciones a esa variable en la spec quedan sustituidas por este diseño.
2. **Códigos de recuperación guardados con hash** (uno por código). Se muestran una sola vez al generarlos y no se pueden volver a mostrar.
3. **Se rota `remember_token` al activar el 2FA.** Las sesiones «recordadas» vuelven a pasar por el login.
4. **Throttle de 10/min por IP** en los endpoints de login con passkey: `throttle:10,1` en las rutas propias, en lugar del `6,1` del paquete.

**Descartadas:**

- **O2**: cuesta más (M) por el cliente WebAuthn propio, y la estabilidad 1.x no compensa el código de seguridad en el cliente ni el salirse del ecosistema oficial.
- **O3**: cuesta más (L), tiene la reversibilidad más baja y el mayor riesgo de regresión en el login, y además hereda el mismo pre-1.0.
- **`laragear/webauthn`**: está **abandonado** según el dry-run y recomienda `laravel/passkeys`.
- **`web-auth/webauthn-lib` directo**: `laravel/passkeys` ya es una capa fina sobre esa misma versión (5.3.9). Usarlo directo duplicaría esa capa sin ganar control que las Actions no den.

## 5. Impacto en módulos y ficheros

Rutas para la opción recomendada (O1). Al final de la tabla se indica qué cambia con O2 y O3.

| Módulo / fichero (ruta real) | Cambio | Nuevo / modificado |
|---|---|---|
| `composer.json` / `composer.lock` | `pragmarx/google2fa ^9.1`, `bacon/bacon-qr-code ^3.1`, `laravel/passkeys ~0.2.1` (14 paquetes según el dry-run) | modificado |
| `package.json` | `@laravel/passkeys` ~0.4.0 (arrastra `@simplewebauthn/browser` ^13) | modificado |
| `app/Http/Requests/Auth/LoginRequest.php` | `authenticate()` se parte en `ensureIsNotRateLimited()` + `validateCredentials(): User` (sin `Auth::attempt`). Mismo `throttleKey` y mismo mensaje | modificado |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | `store`: si el usuario tiene `two_factor_confirmed_at`, guarda `login.id`, `login.remember` y `login.at` en sesión y redirige al reto. Si no, `Auth::login` + `regenerate` como hoy | modificado |
| `app/Http/Controllers/Auth/TwoFactorChallengeController.php` | `create` (página Inertia) y `store`: TOTP o código de recuperación, rate limit `2fa:{login_id}\|{ip}` 5/min, TTL de 5 min, `Auth::login($user, $remember)` y `regenerate` | nuevo |
| `app/Http/Controllers/Profile/TwoFactorController.php` | activar (secreto pendiente + QR SVG), confirmar (rota `remember_token`, decisión de §4), desactivar (contraseña + código) y regenerar los códigos. Todo bajo `password.confirm` | nuevo |
| `app/Services/TwoFactor/TotpService.php` | secreto, QR (bacon SVG), `verify` con ventana ±1 y antirreplay en `two_factor_last_used_at`, generación de los 8 códigos (se muestran una vez y se guardan con hash) y consumo verificando el hash | nuevo |
| `app/Http/Controllers/Auth/PasskeyLoginController.php` | `options` y `store` como `guest`, `throttle:10,1`. Delega en `GenerateVerificationOptions` / `VerifyPasskey`, audita el éxito y el fallo y responde JSON `{redirect}` | nuevo |
| `app/Http/Controllers/Profile/PasskeyController.php` | opciones de registro, `store`, `update` (renombrar, ≤100) y `destroy`. Las búsquedas van con `$request->user()->passkeys()->findOrFail()`, así que una ajena da 404 (CA-19) | nuevo |
| `app/Services/Passkeys/PasskeyGateway.php` | único punto de contacto con `Laravel\Passkeys\*` (aísla el riesgo 0.x) | nuevo |
| `app/Providers/AppServiceProvider.php` | `Passkeys::ignoreRoutes()`; *listeners* de `PasskeyRegistered` / `PasskeyVerified` / `PasskeyDeleted` → `AuditLog` | modificado |
| `config/passkeys.php` | publicado; `relying_party_id` ← `WEBAUTHN_RP_ID`, `allowed_origins` ← `WEBAUTHN_ORIGINS` (lista CSV), `user_handle_secret` ← `PASSKEYS_USER_HANDLE_SECRET` | nuevo |
| `app/Models/User.php` | `implements PasskeyUser`, `use PasskeyAuthenticatable`. Cast `encrypted` para el secreto. Los códigos de recuperación, con hash (`Hash::make` por código, decisión de §4). `#[Hidden]` con `two_factor_secret` y `two_factor_recovery_codes`. Borrado en cascada de las passkeys | modificado |
| `database/migrations/2026_11_04_090000_add_two_factor_columns_to_users_table.php` | `two_factor_secret` (text), `two_factor_recovery_codes` (text), `two_factor_confirmed_at`, `two_factor_last_used_at` (nullable) | nuevo |
| `database/migrations/2026_11_04_090100_create_passkeys_table.php` | la del paquete (`credential_id` único, `credential` json, `last_used_at`) con `name` de 100 caracteres | nuevo |
| `app/Console/Commands/UserTwoFactorReset.php` | `user:two-factor-reset {email}` + auditoría `auth.2fa.reset_by_operator` | nuevo |
| `routes/auth.php` | reto 2FA (`guest`), login con passkey (`guest`, `throttle:10,1`), gestión de 2FA y passkeys (`auth` + `password.confirm`). No toca `routes/web.php`, que es un *hotspot* | modificado |
| `resources/js/Pages/Auth/Login.vue` | botón «Entrar con passkey» (`usePasskeyVerify`), `autocomplete="username webauthn"` para el autocompletado y aviso de HTTPS si `!isSecureContext` | modificado |
| `resources/js/Pages/Auth/TwoFactorChallenge.vue` | código con `one-time-code` / `inputmode="numeric"`, alternar a código de recuperación, `aria-live` | nuevo |
| `resources/js/Pages/Profile/Edit.vue` + `Partials/TwoFactorForm.vue` + `Partials/PasskeysForm.vue` | tarjeta **Seguridad** | modificado + nuevos |
| `.env.example`, `.env.production.example` | `WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS`, `PASSKEYS_USER_HANDLE_SECRET` (sin `WEBAUTHN_RP_NAME`: el nombre es el RP ID, decisión de §4) | modificado |
| `tests/Feature/Auth/*`, `tests/Unit/*` | `AuthenticationTest` se adapta solo en lo que documenta el nuevo flujo. Nuevos: `TwoFactor*Test`, `PasskeyTest`, `McpWithTwoFactorTest` | modificado + nuevos |
| `playwright.config.ts`, `tests/e2e/helpers.ts`, `global-setup.ts`, `global-teardown.ts`, `security.spec.ts` | `E2E_BASE_URL=http://localhost:8080`, helper TOTP y CDP, usuario E2E dedicado | modificado + nuevo |
| `docs/usuario/*`, guía de operación | RP ID por entorno, rotación de ngrok, `APP_PREVIOUS_KEYS` | nuevo |
| `app/Services/AuditLog.php`, `routes/ai.php`, `McpGuard`, `config/sanctum.php` | **sin cambios** (solo se usa `record(..., channel: 'web')`) | — |

**Diferencias con las otras opciones:**

- **O2**: cambia `laravel/passkeys` por `spatie/laravel-passkeys`, añade `resources/js/Composables/useWebAuthn.js` (+ `@simplewebauthn/browser` 14) y usa el esquema de tabla de Spatie.
- **O3**: añade `laravel/fortify`, `config/fortify.php` y `app/Providers/FortifyServiceProvider.php`. **Retira** `AuthenticatedSessionController@store` y `LoginRequest::authenticate()` (y, según la configuración, registro, reset y confirmación), y obliga a reescribir más `tests/Feature/Auth/*`.

**RP ID y origen por entorno** (vale para las tres opciones):

| Entorno | URL | `WEBAUTHN_RP_ID` | `WEBAUTHN_ORIGINS` | Passkeys | TOTP |
|---|---|---|---|---|---|
| Dev ngrok | `https://workable-humorous-rat.ngrok-free.app` | ese host | `https://workable-humorous-rat.ngrok-free.app` | Sí. Mueren si se rota el dominio (hay que registrarlas de nuevo) | Sí |
| Dev Laragon | `https://metricool.test` | `metricool.test` | `https://metricool.test` | Sí, si el certificado de Laragon es de confianza. Con `http://` no hay, y sale el aviso de CA-20 | Sí |
| E2E | `http://localhost:8080` | `localhost` | `http://localhost:8080` (`webauthn-lib` compara origen completo: esquema + host + puerto) | Sí, con el autenticador virtual. `localhost` es contexto seguro | Sí |
| Producción Hestia | `https://<dominio>` | el dominio, **sin subdominio de más**. Es estable: si cambia, todas las passkeys quedan invalidadas | `https://<dominio>` | Sí | Sí |

Por defecto se deriva de `APP_URL`, como hace el paquete. El E2E arranca `php artisan serve --host=localhost --port=8080` con `APP_URL=http://localhost:8080`. `trustHosts()` no se aplica en `local`, y en producción el host coincide.

## 6. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Regresión del login clásico al partir `authenticate()` (más alta en O3) | Media | Alto | TDD: `tests/Feature/Auth/*` en verde antes y después, y caso explícito «usuario sin 2FA entra igual». Mismo `throttleKey` y mismos mensajes |
| `laravel/passkeys` 0.x rompe la API o el esquema `credential` (O1 y O3) | Media | Medio | `~0.2.1` fijado, `PasskeyGateway` como único punto de contacto, test de ceremonia con vector grabado y ADR que obliga a revisar al subir de versión |
| La rotación de `APP_KEY` cambia el `user_handle_secret` (por defecto `APP_KEY`) | Baja | Medio | `PASSKEYS_USER_HANDLE_SECRET` explícito desde el día 1 (en `.env.example` y en la guía de Hestia) |
| La rotación de `APP_KEY` sin `APP_PREVIOUS_KEYS` hace perder los secretos TOTP | Baja | Alto | Documentarlo. El comando `user:two-factor-reset` sirve de salida |
| Contador de firmas a 0 en passkeys sincronizadas | Baja | Medio | `webauthn-lib` 5.3.9 ya lo tolera (`CheckCounter`). Test con un vector de contador 0 |
| `password.confirm` responde 423 JSON a `fetch` de las opciones de registro | Media | Bajo | La UI confirma la contraseña **antes** de abrir el diálogo de alta (visita a `password.confirm` con `intended`), y el composable trata el 423 |
| Respuesta del login con passkey: JSON vs. Inertia | Media | Bajo | Controlador propio que devuelve `{redirect}` y `router.visit()` en el cliente. Test de que no hay doble redirección |
| Antirreplay del TOTP en cache (patrón Fortify) se pierde al limpiar la cache | Baja | Medio | En O1 y O2 va en columna (`two_factor_last_used_at`), no en cache |
| E2E sobre `localhost` rompe cookies o URLs del resto de la batería | Media | Bajo | Todo el E2E corre en `localhost:8080` (no solo `security.spec.ts`) y se verifica en la primera tarea de C-03 |
| O2: bug en el composable WebAuthn propio | Media | Medio | Si se elige O2: tests de unidad del composable y revisión de seguridad aparte |
| Falta `sodium` en el PHP de Hestia (claves EdDSA) | Baja | Medio | `php -m` en el servidor antes de desplegar. `paragonie/sodium_compat` como alternativa |

## 7. Preguntas abiertas

Las cinco preguntas de la pasada 1 quedaron resueltas el 2026-10-22: la opción (O1) y los cuatro puntos complementarios de §4. Para el planner y el implementer quedan:

- **Spec desalineada en `WEBAUTHN_RP_NAME`.** La spec todavía la menciona en Configuración y en los criterios de proceso (`.env.example`). Manda este diseño: no se crea la variable. El architect no puede editar el cuerpo de la spec. Si se quiere alinear el texto, lo hace `analyst`.
- **Fecha de las migraciones.** Las existentes llegan hasta `2026_11_03_*`, así que las nuevas deben ir después (en §5 se proponen `2026_11_04_*`). El implementer usa la siguiente fecha libre.
- **Verificar `sodium` en el PHP de Hestia** (`php -m`) antes de desplegar. Si falta, se añade `paragonie/sodium_compat`.

---

## Changelog

| Fecha | Cambio |
|---|---|
| 2026-10-22 | Diseño creado (`borrador`): 3 opciones, dry-run de Composer sobre el lock real y recomendación O1. Se presenta al usuario |
| 2026-10-22 | El usuario elige O1 y acepta los 4 puntos de §7 (sin `WEBAUTHN_RP_NAME`, códigos con hash, rotar `remember_token`, 10/min). Se escribe ADR-003 (`propuesta`), se enlaza `design:` en `spec.md` y el diseño pasa a `aprobado` |
