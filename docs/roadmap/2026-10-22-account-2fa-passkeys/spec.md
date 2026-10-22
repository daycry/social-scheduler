---
spec: account-2fa-passkeys
descripcion: Doble factor opcional (TOTP + códigos de recuperación) y passkeys como login sin contraseña, gestionados desde el perfil, sin tocar las API keys del MCP.
estado: implementada         # borrador | aprobada | implementada | obsoleta
creado: 2026-10-22
actualizado: 2026-10-22
evaluacion: evaluation.md
design: design.md         # aprobado 2026-10-22 — O1 (Breeze + google2fa + laravel/passkeys), ADR-003
plan: improvement-plan.md
generacion:
  inicio: 2026-10-22T08:06:23Z
  fin: 2026-10-22T08:15:25Z
  fuente: estimado        # spec y evaluación se redactaron en la MISMA ventana medida; la medida completa está en evaluation.md
  tokens_reales: { entrada: 0, salida: 0, cache_creacion: 0, cache_lectura: 0 }   # 0 = no medido por separado (ver evaluation.md)
  eur: null               # sin precioTokens fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.23      # estimado: ~45 % de la ventana compartida
  duracion: 31m         # duración de la ventana compartida
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# Seguridad de cuenta: doble factor (TOTP) y passkeys

> **Evaluación:** [`evaluation.md`](evaluation.md)
> **Diseño:** [`design.md`](design.md) (aprobado, O1; prevalece sobre esta spec en librerías, `WEBAUTHN_RP_NAME` eliminado y los 4 supuestos cerrados)
> **Plan de implementación:** [`improvement-plan.md`](improvement-plan.md) · [`tasks.md`](tasks.md) · [`test-plan.md`](test-plan.md)

> **Terminología:**
>
> - **2FA / TOTP**: segundo factor con código de 6 dígitos que cambia cada 30 s (RFC 6238), generado por una app de autenticación (Google Authenticator, 1Password, Aegis…).
> - **Códigos de recuperación**: códigos de un solo uso que sustituyen al TOTP si se pierde el móvil.
> - **Passkey**: credencial WebAuthn/FIDO2 guardada en el dispositivo o en el gestor de contraseñas, que se desbloquea con huella, Face ID, Windows Hello o PIN.
> - **RP ID** (Relying Party ID): el dominio al que queda ligada una passkey (p. ej. `midominio.com`). Una passkey creada para un RP ID **no sirve** en otro.
> - **Reto 2FA**: paso intermedio del login que pide el código tras validar la contraseña.
> - **Ceremonia**: cada intercambio WebAuthn (registro = *attestation*, login = *assertion*).

## Contexto y objetivo

Hoy la cuenta web solo se protege con email + contraseña (Breeze). Quien consiga la contraseña entra en el panel, conecta o desconecta redes sociales, publica y crea API keys del MCP. El usuario pide «permitir configurar doble factor para la seguridad de las cuentas, tanto 2FA como passkeys» (petición del 2026-10-22).

El objetivo es doble:

1. **2FA opcional (TOTP + códigos de recuperación):** cada usuario lo activa desde su perfil. Si lo tiene activo, el login con contraseña pide además el código.
2. **Passkeys como login sin contraseña:** una passkey sustituye a contraseña + 2FA en un solo paso. El usuario puede registrar varias y borrarlas desde su perfil.

Todo ello **sin alterar** el acceso de los agentes al servidor MCP por API key (Bearer de Sanctum), que no pasa nunca por el login web.

Estado verificado en el repo (2026-10-22, rama `feature/social-scheduler`):

- `composer.json`: `laravel/framework ^13.17` (instalado `v13.37.0` según `composer.lock`), `laravel/sanctum ^4.0`, `laravel/breeze ^2.4` (dev). No hay ningún paquete de 2FA, QR ni WebAuthn (ni en `composer.lock` ni en `vendor/`). PHP local 8.3.33 con `openssl`, `sodium` y `bcmath`.
- Login: `AuthenticatedSessionController::store` llama a `LoginRequest::authenticate()`, que hace `Auth::attempt()` y **abre ya la sesión**. El reto 2FA obliga a cambiarlo: validar credenciales sin abrir sesión y aplazar el login.
- Rate limit actual: `LoginRequest::ensureIsNotRateLimited()` admite 5 intentos por `email|ip` y dispara `Lockout`.
- Reset de contraseña (`NewPasswordController::store`): cambia la contraseña y redirige a `/login` **sin abrir sesión**. Por tanto, el próximo login seguirá pasando por el reto 2FA.
- Confirmación de contraseña: ya existe `password.confirm` (`ConfirmablePasswordController`, rutas en `routes/auth.php`).
- Perfil: `resources/js/Pages/Profile/Edit.vue` con tres tarjetas (`UpdateProfileInformationForm`, `UpdatePasswordForm`, `DeleteUserForm`). No hay ninguna sección de seguridad.
- Auditoría: `App\Services\AuditLog::record($user, $action, $outcome, $resource, $resourceId, $detail, $channel)`. La tabla `audit_logs` tiene `action` (80), `outcome` (`ok|denied|error`), `resource` (40), `detail` (500) e `ip`. Ya se usa con `channel: 'web'` en `ApiKeyController` (`api_key.created` / `api_key.revoked`).
- MCP: `routes/ai.php` monta `Mcp::web('/mcp')` con `auth:sanctum` + `throttle:mcp`. `config/sanctum.php` tiene `'guard' => []` (solo Bearer: una sesión web no autentica el MCP). `McpGuard` solo actúa en `/mcp`. **Conclusión: el login web y el MCP están desacoplados**, y el 2FA no tiene por qué tocar esa ruta.
- Entorno: `APP_URL=https://workable-humorous-rat.ngrok-free.app` en `.env` (con `TRUSTED_PROXIES=*`), y `APP_URL=https://metricool.test` en `.env.example`. `bootstrap/app.php` aplica `trustHosts()` sobre el host de `APP_URL`. Sesiones en BD (`SESSION_DRIVER=database`). Registro público según `REGISTRATION_ENABLED` (`config('app.registration_enabled')`).
- Tests: Pest en `tests/Feature/Auth/*` (8 ficheros) y `tests/Feature/ProfileTest.php`. Playwright en `tests/e2e` con proyectos `desktop`, `mobile` (375) y `mobile-360`, todos sobre **Chromium**, `baseURL` por defecto `http://127.0.0.1:8080`, y axe en `a11y.spec.ts`.

## Decisiones de diseño

| Decisión | Elección | Motivo |
|---|---|---|
| Flujo de la iniciativa | **Completo: spec → evaluación → diseño → plan → implementación** | Decisión del usuario (2026-10-22). Toca autenticación, así que conviene un paso de diseño que fije librerías y esquema. |
| Papel de la passkey | **Login sin contraseña: la passkey sustituye a contraseña + 2FA (un solo paso)** | Decisión del usuario. Una passkey con verificación de usuario (biometría o PIN) ya es multifactor: algo que tienes (el dispositivo) y algo que eres o sabes. |
| Gestión de passkeys | **Varias por usuario, con alta, renombrado y borrado desde el perfil** | Decisión del usuario (el renombrado lo pide el encargo de la spec). |
| Obligatoriedad del 2FA | **Opcional por usuario. El login sin 2FA sigue funcionando.** | Decisión del usuario. No se fuerza a nadie ni hay política global. |
| Tipo de 2FA | **TOTP (app de autenticación) + códigos de recuperación** | Decisión del usuario. Ni SMS ni email como segundo factor. |
| Verificación de usuario en la passkey | **`userVerification: required` y credencial *discoverable* (residente)** | Restricción derivada de la decisión anterior: si la passkey sustituye a contraseña + 2FA, debe exigir biometría o PIN, y debe poder usarse sin escribir antes el email. |
| Contraseña tras crear passkeys | **Se mantiene.** La passkey es un método más, no elimina la contraseña. | Así la recuperación por email y el login clásico siguen siendo la vía de respaldo si se pierden todas las passkeys. Las cuentas solo-passkey quedan fuera. |
| Librerías | **Las decide el paso de diseño** (candidatas verificadas en *Supuestos e incógnitas*) | Hay varias opciones compatibles con Laravel 13 y con compromisos distintos (choque con Breeze, madurez, soporte para Vue). |

## Configuración / parámetros

| Parámetro | Clave / mecanismo | Default | Valor objetivo |
|---|---|---|---|
| RP ID de WebAuthn | `WEBAUTHN_RP_ID` (nueva) | host de `APP_URL` | **dominio de producción** en Hestia. En dev, el host de ngrok vigente (cambia si se rota) |
| Orígenes permitidos de WebAuthn | `WEBAUTHN_ORIGINS` (nueva, lista) | `APP_URL` | **solo `https://` + RP ID**, salvo `http://localhost` en local y E2E |
| Nombre visible del RP | — (decidido en diseño: sin variable; el paquete usa el RP ID) | — | dominio |
| Validez del reto 2FA pendiente | `auth.two_factor.challenge_ttl` (nueva) | — | **5 min** (después hay que volver a escribir la contraseña) |
| Intentos del reto 2FA | rate limiter con clave `2fa:{login_id}|{ip}` | — | **5 / min**, igual que el login actual (`LoginRequest`) |
| Intentos del login con passkey | `throttle` en los endpoints de opciones y verificación | — | **10 / min por IP** (⚠️ ajustar en diseño) |
| Ventana de tolerancia TOTP | parámetro de la librería TOTP | 1 paso (±30 s) | **±1 paso**, sin reutilizar un código ya usado |
| Nº de códigos de recuperación | generación | — | **8 códigos** (⚠️ confirmar en diseño) |
| Timeout de confirmación de contraseña | `auth.password_timeout` (Breeze) | 10800 s | sin cambios |

## Arquitectura y componentes

**Se reutiliza:**

- `AuthenticatedSessionController` / `LoginRequest`. Se modifican: separar «validar credenciales» de «abrir sesión» y redirigir al reto si el usuario tiene el 2FA confirmado.
- `password.confirm` de Breeze, como guarda de las acciones sensibles del perfil.
- `AuditLog::record(..., channel: 'web')` para todos los eventos (familia `auth.*`).
- `RateLimiter`, con el mismo patrón que `LoginRequest`.
- Cast `encrypted` de Eloquent para los secretos en reposo (usa `APP_KEY` y admite `previous_keys`, ya presente en `config/app.php`).
- `Profile/Edit.vue`, que gana una tarjeta **Seguridad**.

**Es nuevo:**

- Migración en `users`: `two_factor_secret` (cifrado), `two_factor_recovery_codes` (cifrado, o con hash por código), `two_factor_confirmed_at`. Según la librería, también el último paso TOTP usado (antirreplay).
- Tabla `passkeys` (o la de la librería): `user_id`, `name`, `credential_id` (único), clave pública, contador de firmas, `transports`, `aaguid`, `last_used_at` y timestamps.
- Controladores: activación, confirmación, desactivación y regeneración de códigos del 2FA. Reto 2FA (`two-factor-challenge`). Gestión de passkeys (opciones de registro, registro, renombrado, borrado). Login con passkey (opciones y verificación, como `guest`).
- Páginas Vue: `Auth/TwoFactorChallenge.vue`, y en `Profile/Partials/` `TwoFactorForm.vue` y `PasskeysForm.vue`. En `Auth/Login.vue`, el botón «Entrar con passkey» (y autocompletado condicional si el navegador lo admite).
- Cliente JS de WebAuthn: `@laravel/passkeys` (`/vue`), `@laragear/webpass` o `@simplewebauthn/browser`, según la librería elegida.
- Comando de operador `php artisan user:two-factor-reset {email}`, para soporte cuando un usuario pierde el móvil y los códigos.

**No se toca:** `routes/ai.php`, `McpGuard`, `config/sanctum.php`, `ApiKeyController`, ni el modelo de abilities del MCP.

## Flujo (paso a paso)

**Activar el 2FA**

1. En Perfil → Seguridad, el usuario pulsa «Activar doble factor». Si la contraseña no se ha confirmado hace poco, se le pide (`password.confirm`).
2. El servidor genera el secreto TOTP (cifrado, sin confirmar) y muestra el QR junto con el secreto en texto para meterlo a mano.
3. El usuario escribe un código de su app. Si es válido, se guarda `two_factor_confirmed_at`, se generan los códigos de recuperación y se muestran **una sola vez** (copiar o descargar). Se audita `auth.2fa.enabled`.

**Login con 2FA**

1. El usuario envía email y contraseña. Se valida con el rate limit actual, **sin abrir sesión**.
2. Si el usuario no tiene el 2FA confirmado, se hace el login normal (sin cambios).
3. Si lo tiene, se guarda en sesión `login.id`, `login.remember` y la hora, y se redirige a `/two-factor-challenge`.
4. El usuario escribe el TOTP, o alterna a «usar un código de recuperación». Si es válido: `Auth::login`, se regenera la sesión, se consume el código de recuperación si era uno y se audita. Si no es válido: se cuenta en el rate limit y se audita el fallo.

**Login con passkey**

1. En `/login`, el usuario pulsa «Entrar con passkey» (o la elige en el autocompletado del campo email).
2. El servidor emite el reto (opciones de *assertion* con `userVerification: required`) y lo guarda en sesión.
3. El navegador pide la biometría o el PIN y devuelve la *assertion*. El servidor verifica firma, origen, RP ID, contador y reto de un solo uso.
4. Si todo cuadra: `Auth::login`, se regenera la sesión, se actualiza `last_used_at`, se audita y se redirige a `intended` (o a `dashboard`). **No se pide el 2FA.**

**Gestionar passkeys**

1. En Perfil → Seguridad, el usuario pulsa «Añadir passkey» (con `password.confirm`), le pone un nombre («MacBook», «iPhone»…) y completa la ceremonia de registro.
2. La lista muestra nombre, fecha de alta y último uso. Cada passkey se puede renombrar o borrar, y el borrado pide confirmación.

## Alcance

- **Dentro (esta iteración):**
  - TOTP opcional por usuario: activar (QR, secreto en texto y código de confirmación) y desactivar (contraseña + código TOTP o de recuperación).
  - Reto 2FA en el login con contraseña, con rate limit, caducidad y antirreplay del TOTP.
  - Códigos de recuperación de un solo uso, regenerables (los anteriores quedan invalidados) y descargables o copiables.
  - Passkeys: registrar varias, listarlas, renombrarlas y borrarlas. Login sin contraseña con passkey.
  - RP ID y orígenes configurables por entorno, con aviso claro en la UI si el contexto no es seguro.
  - Auditoría de cada evento en `audit_logs` (`channel = web`).
  - Comando de operador para resetear el 2FA de un usuario.
  - Tests Pest (unidad y feature) y E2E Playwright (TOTP real calculado en el test, autenticador virtual de Chromium), axe en desktop, 375 y 360.
  - Documentación de usuario (`docs/usuario/`) y de operación (variables `WEBAUTHN_*` en `.env.example`, RP ID en Hestia).
- **Fuera (siguientes specs):**
  - 2FA obligatorio por política o por rol.
  - Segundo factor por SMS o email.
  - Cuentas solo-passkey (sin contraseña) y alta con passkey en el registro.
  - Usar la passkey como **segundo factor** tras la contraseña (aquí siempre sustituye al login completo).
  - Cerrar las otras sesiones al activar el 2FA o borrar una passkey (posible mejora, ver Supuestos).
  - Dispositivos de confianza («no pedir 2FA en este equipo durante 30 días»).
  - Cualquier cambio en las API keys o en el MCP (auth Bearer, abilities, OAuth del MCP).
  - Atestación empresarial o restricción por AAGUID.

## Manejo de errores

| Caso | Comportamiento |
|---|---|
| Código TOTP incorrecto en la activación | No se activa. Error en el campo y el secreto pendiente se conserva para reintentar. |
| Código TOTP o de recuperación incorrecto en el reto | Error genérico («código no válido»), suma 1 al rate limit y se audita `auth.2fa.challenge_failed`. |
| Más de 5 intentos en el reto | `Lockout`, mensaje con los segundos de espera (como `auth.throttle`) y se audita `auth.2fa.lockout`. |
| Reto 2FA caducado (> 5 min) o sesión sin `login.id` | Vuelta a `/login` con aviso «vuelve a introducir tus credenciales». |
| Reutilizar un código TOTP ya usado en su ventana | Se rechaza (antirreplay). |
| Reutilizar un código de recuperación ya usado | Se rechaza como código no válido. |
| Navegador sin WebAuthn o contexto no seguro (`http://` que no sea `localhost`) | No aparecen el botón ni la tarjeta de passkeys. En su lugar, aviso «Las passkeys requieren HTTPS» y el login con contraseña sigue igual. |
| El usuario cancela la biometría (`NotAllowedError`) | Mensaje neutro («No se completó la passkey») sin auditar como ataque. Se puede reintentar. |
| Passkey de otro RP ID (p. ej. tras rotar ngrok) | El navegador no la ofrece o la verificación falla. Mensaje «Esta passkey no es válida en este dominio». |
| Passkey desconocida o borrada | La verificación falla: se audita `auth.passkey.login_failed` y no se revela si existe el usuario. |
| Contador de firmas que retrocede (posible clon) | Se rechaza el login y se audita con detalle `sign_count_regression` (⚠️ las passkeys sincronizadas suelen usar contador 0: el diseño debe tolerarlo). |
| Registrar una passkey con un `credential_id` ya existente | Se rechaza (`excludeCredentials` + unicidad en BD). |
| Usuario pierde el móvil y los códigos | Puede entrar con una passkey, si tiene. Si no, el operador ejecuta `user:two-factor-reset`, que queda auditado. El reset de contraseña por email **no** desactiva el 2FA. |
| Email sin verificar tras el login con passkey | Igual que hoy: el middleware `verified` lleva a `verification.notice`. |

## Criterios de aceptación

**Proceso y no regresión**

- [ ] `php artisan test` pasa completo (~796 tests de base + los nuevos), sin desactivar ni modificar tests existentes de `tests/Feature/Auth/*` salvo para adaptar el flujo de login documentado.
- [ ] `routes/ai.php`, `app/Http/Middleware/McpGuard.php`, `config/sanctum.php` y `ApiKeyController` no cambian, o si cambian, la diferencia está justificada en el plan. Los tests de `tests/Feature/Mcp/*` pasan sin tocarlos.
- [ ] Los secretos TOTP y los códigos de recuperación se guardan cifrados (cast `encrypted`) u ocultos con hash. Un `SELECT` sobre `users` no muestra el secreto en claro, y `$user->toArray()` no expone ningún campo de 2FA (`#[Hidden]`).
- [ ] Ninguna fila de `audit_logs` ni ninguna línea del log contiene un secreto, un código TOTP, un código de recuperación ni material de una credencial WebAuthn.
- [ ] `.env.example` documenta `WEBAUTHN_RP_ID` y `WEBAUTHN_ORIGINS`, y la guía de operación explica el RP ID en Hestia y el efecto de rotar ngrok.
- [ ] Las páginas nuevas o modificadas (Login, reto 2FA, Perfil → Seguridad) pasan axe sin violaciones `serious` ni `critical`, y no desbordan en horizontal en 375 y 360 px.

**2FA (TOTP y recuperación)**

- [ ] [GWT] CA-01 — Dado un usuario sin 2FA y con la contraseña confirmada, Cuando pulsa «Activar doble factor» y escribe un código TOTP válido de su app, Entonces el 2FA queda confirmado, se le muestran 8 códigos de recuperación una sola vez y se registra `auth.2fa.enabled`.
- [ ] [GWT] CA-02 — Dado un usuario en mitad de la activación, Cuando escribe un código TOTP incorrecto, Entonces el 2FA no se activa, ve un error en el campo y su login sigue sin pedir código.
- [ ] [GWT] CA-03 — Dado un usuario con 2FA activo, Cuando intenta desactivarlo sin contraseña o sin un código válido, Entonces se rechaza. Y Cuando aporta la contraseña y un código TOTP o de recuperación válido, Entonces el 2FA se desactiva, se borran el secreto y los códigos, y se registra `auth.2fa.disabled`.
- [ ] [GWT] CA-04 — Dado un usuario con 2FA activo, Cuando envía email y contraseña correctos, Entonces **no** queda autenticado (ninguna ruta `auth` le responde) y es redirigido a `/two-factor-challenge`.
- [ ] [GWT] CA-05 — Dado un usuario en el reto 2FA, Cuando escribe un TOTP válido, Entonces entra al `dashboard` (o a la URL `intended`) con la sesión regenerada, y se registra `auth.2fa.challenge_passed`. Si había marcado «Recordarme», se respeta.
- [ ] [GWT] CA-06 — Dado un usuario en el reto 2FA, Cuando falla el código 5 veces, Entonces el sexto intento se bloquea con un mensaje de espera en segundos, aunque el código sea correcto, y se registran `auth.2fa.challenge_failed` ×5 y `auth.2fa.lockout`.
- [ ] [GWT] CA-07 — Dado un usuario en el reto 2FA, Cuando reenvía el mismo TOTP válido que acaba de usar en otro login dentro de su ventana, Entonces se rechaza (antirreplay).
- [ ] [GWT] CA-08 — Dado un reto 2FA iniciado hace más de 5 minutos, Cuando el usuario envía un código, Entonces vuelve a `/login` con un aviso y no se autentica.
- [ ] [GWT] CA-09 — Dado un usuario con 2FA activo, Cuando entra con un código de recuperación válido, Entonces accede, ese código deja de valer (un segundo uso falla), le quedan N−1 y se registra `auth.2fa.recovery_used` con el número restante (nunca el código).
- [ ] [GWT] CA-10 — Dado un usuario con 2FA activo y la contraseña confirmada, Cuando regenera los códigos de recuperación, Entonces ve 8 códigos nuevos, ninguno de los anteriores vale y se registra `auth.2fa.recovery_regenerated`.
- [ ] [GWT] CA-11 — Dado un usuario con 2FA activo que ha olvidado la contraseña, Cuando la restablece por el enlace del email, Entonces **no** queda autenticado, el 2FA sigue activo y su siguiente login con la contraseña nueva pasa por el reto 2FA.
- [ ] [GWT] CA-12 — Dado un usuario que ha perdido el móvil y los códigos, Cuando el operador ejecuta `php artisan user:two-factor-reset {email}`, Entonces el 2FA queda desactivado, se registra `auth.2fa.reset_by_operator` y el usuario entra solo con su contraseña.

**Passkeys**

- [ ] [GWT] CA-13 — Dado un usuario autenticado en un contexto seguro y con la contraseña confirmada, Cuando pulsa «Añadir passkey», le pone nombre y completa la biometría, Entonces la passkey aparece en la lista con su nombre y fecha, y se registra `auth.passkey.registered`.
- [ ] [GWT] CA-14 — Dado un usuario con una passkey, Cuando registra una segunda en otro autenticador, Entonces la lista muestra las dos. Y Cuando intenta registrar otra vez el mismo autenticador, Entonces se rechaza.
- [ ] [GWT] CA-15 — Dado un usuario con passkeys, Cuando renombra una, Entonces el nombre nuevo aparece en la lista y se registra `auth.passkey.renamed`. Un nombre vacío o de más de 100 caracteres se rechaza.
- [ ] [GWT] CA-16 — Dado un usuario con passkeys, Cuando borra una y lo confirma, Entonces desaparece de la lista, ya no sirve para entrar y se registra `auth.passkey.deleted`.
- [ ] [GWT] CA-17 — Dado un usuario **sin sesión** con una passkey registrada (y con o sin 2FA), Cuando pulsa «Entrar con passkey» en `/login` y completa la biometría, Entonces entra al `dashboard` **sin escribir contraseña ni código 2FA**, y se registra `auth.passkey.login`.
- [ ] [GWT] CA-18 — Dado un usuario sin sesión, Cuando presenta una passkey borrada o de otro RP ID, Entonces no entra, ve un mensaje que no revela si la cuenta existe y se registra `auth.passkey.login_failed`.
- [ ] [GWT] CA-19 — Dado un usuario de otra cuenta, Cuando intenta renombrar o borrar una passkey ajena por su ID, Entonces recibe 404 y la passkey sigue intacta.
- [ ] [GWT] CA-20 — Dado un navegador sin WebAuthn o una página servida por `http://` que no sea `localhost`, Cuando el usuario abre `/login` o Perfil → Seguridad, Entonces no aparecen los controles de passkey, ve el aviso «Las passkeys requieren HTTPS» y el login con contraseña funciona igual.
- [ ] [GWT] CA-21 — Dado un cliente que llama más de 10 veces por minuto desde la misma IP a los endpoints de login con passkey, Cuando hace la siguiente petición, Entonces recibe 429.

**MCP intacto**

- [ ] [GWT] CA-22 — Dado un usuario con 2FA activo y una API key válida, Cuando un agente llama a `POST /mcp` con `Authorization: Bearer <key>`, Entonces la respuesta es la misma que sin 2FA (sin reto ni redirección), y una sesión web a medio reto 2FA no autentica `/mcp`.

**Accesibilidad**

- [ ] [GWT] CA-23 — Dado el reto 2FA, Cuando se navega solo con teclado y lector de pantalla, Entonces el campo de código tiene etiqueta visible, `autocomplete="one-time-code"` e `inputmode="numeric"`, los errores se anuncian (`aria-live`), y el enlace para alternar a «código de recuperación» es accesible.
- [ ] [GWT] CA-24 — Dado Perfil → Seguridad durante la activación del 2FA, Cuando se inspecciona, Entonces el QR tiene texto alternativo, el secreto aparece como texto copiable para meterlo a mano, y los códigos de recuperación se pueden copiar o descargar sin ratón.

## Pruebas

- **Pest (feature):** activación, confirmación y desactivación del 2FA (CA-01..03). Reto 2FA, rate limit, caducidad y antirreplay (CA-04..08), con el TOTP calculado en el test a partir del secreto. Códigos de recuperación (CA-09, 10). Reset de contraseña con 2FA (CA-11). Comando de operador (CA-12). CRUD de passkeys y aislamiento entre usuarios (CA-14..16, 19). Login con passkey con vectores de *attestation* y *assertion* fijos o con el soporte de pruebas de la librería (CA-17, 18). Throttle (CA-21). MCP con Bearer y usuario con 2FA (CA-22). Auditoría y ausencia de secretos en `audit_logs`.
- **Pest (unidad):** generación y verificación de TOTP en la ventana, y antirreplay. Derivación del RP ID y de los orígenes desde la config.
- **Playwright E2E (Chromium, desktop, 375 y 360):** recorrido completo de activación del 2FA y login con reto, con el TOTP calculado en Node (`crypto`, RFC 6238). Alta, renombrado y borrado de passkeys, y login con passkey usando el **autenticador virtual de Chromium** (CDP `WebAuthn.addVirtualAuthenticator`). axe en Login, reto y Perfil → Seguridad (CA-23, 24). Usuario E2E dedicado, para no dejar el 2FA activo en el usuario demo, con limpieza en `global-teardown.ts`.
- **Nota E2E:** el `baseURL` actual es `http://127.0.0.1:8080`, y WebAuthn **no admite una IP como RP ID**. Las pruebas de passkeys deben correr sobre `http://localhost:8080` (contexto seguro y dominio válido) con `WEBAUTHN_RP_ID=localhost`.
- **Manual (checklist del usuario):** una passkey real con Windows Hello o un móvil en el dominio ngrok vigente, y en producción tras el despliegue.

## Referencias

- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (`store`: `authenticate()` → `regenerate()` → `intended`).
- `app/Http/Requests/Auth/LoginRequest.php` (`authenticate`, `ensureIsNotRateLimited`: 5 intentos, `throttleKey` = `email|ip`).
- `app/Http/Controllers/Auth/NewPasswordController.php` (`store`: reset sin login, redirige a `login`).
- `routes/auth.php` (grupos `guest` / `auth`, `password.confirm`, registro condicionado por `config('app.registration_enabled')`).
- `routes/web.php` líneas 90-93 (perfil bajo `auth`) y 78-80 (API keys bajo `auth` + `verified`).
- `routes/ai.php`, `app/Http/Middleware/McpGuard.php`, `config/sanctum.php:40` (`'guard' => []`).
- `app/Services/AuditLog.php` y `database/migrations/2026_10_24_090000_create_audit_logs_table.php`.
- `app/Models/User.php` (`HasApiTokens`, `casts()`, borrado en cascada de tokens en `booted`).
- `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Auth/Login.vue`.
- `bootstrap/app.php` (`trustHosts()`, `TrustProxiesFromConfig`), `.env.example` (`APP_URL`, `TRUSTED_PROXIES`, `REGISTRATION_ENABLED`).
- `playwright.config.ts` (proyectos Chromium, `baseURL` `http://127.0.0.1:8080`), `tests/e2e/helpers.ts` (`login`).
- `docs/knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md` (monolito Laravel nativo en Hestia, ecosistema oficial de Laravel).
- Packagist (consulta del 2026-10-22): `laravel/fortify`, `pragmarx/google2fa`, `bacon/bacon-qr-code`, `laravel/passkeys`, `laragear/webauthn`, `spatie/laravel-passkeys`, `web-auth/webauthn-lib`. npm: `@laravel/passkeys`, `@laragear/webpass`, `@simplewebauthn/browser`.

## Decisiones confirmadas (revisión del usuario · 2026-10-22)

1. Flujo completo: spec → evaluación → diseño → plan → implementación. **Confirmado.**
2. Passkeys = login sin contraseña: una passkey sustituye a contraseña + 2FA en un solo paso (huella, Face ID, Windows Hello). El usuario puede registrar varias passkeys y borrarlas desde su perfil. **Confirmado.**
3. El 2FA (TOTP con app de autenticación + códigos de recuperación) es opcional: cada usuario lo activa desde su perfil y el login sin 2FA sigue funcionando. **Confirmado.**

## Supuestos

> **Go del usuario (2026-10-22):** acepta la evaluación y los supuestos por defecto: 8 códigos de recuperación, «recordarme» no vuelve a pedir el 2FA en ese navegador, activar el 2FA no cierra las otras sesiones.

- **Restricción del RP ID y del contexto seguro (importante).** WebAuthn solo funciona en un contexto seguro (`https://`, o `http://localhost`) y liga cada passkey a su RP ID (dominio). En consecuencia:
  - `http://metricool.test` (http) **no admite passkeys**. `https://metricool.test` (el que figura en `.env.example`) sí, si el certificado de Laragon es de confianza para el navegador.
  - Las passkeys creadas en `workable-humorous-rat.ngrok-free.app` **dejan de servir** si se rota el dominio de ngrok. En desarrollo es aceptable: se vuelven a registrar.
  - En producción (Hestia, dominio propio y estable) el RP ID es ese dominio. Cambiarlo más adelante invalida todas las passkeys.
  - 2FA con TOTP **no** tiene esta restricción: funciona en cualquier entorno.
- **Secretos en reposo:** se cifran con `APP_KEY` (cast `encrypted`). Rotar `APP_KEY` exige mover la anterior a `APP_PREVIOUS_KEYS` (ya soportado en `config/app.php`) o se pierden los secretos TOTP. La clave pública de una passkey no es secreta, pero se guarda tal cual la entrega la librería.
- **«Recordarme»:** la cookie *remember* restaura la sesión sin volver a pedir el 2FA (comportamiento estándar, también en Fortify). Se supone aceptable. Activar el 2FA no rota `remember_token` (⚠️ validar en diseño).
- **Otras sesiones:** activar el 2FA o borrar una passkey no cierra las otras sesiones abiertas (con sesiones en BD sería viable). Queda fuera, como posible mejora.
- **Un usuario por cuenta:** no hay roles de administrador en la UI. El reset de 2FA de soporte se hace por comando artisan.
- **Hestia:** se supone que el PHP de producción (8.3+) trae `openssl` y `sodium` (⚠️ verificar con `php -m` en el servidor). `sodium` hace falta para claves EdDSA, y si falta, `paragonie/sodium_compat` lo cubre.
- **Librerías candidatas (Packagist, consulta del 2026-10-22). La elección final corresponde al paso de diseño:**
  - **TOTP:**
    - `laravel/fortify` v1.41.0 (2026-09-28): `illuminate/support ^11|^12|^13`, depende de `pragmarx/google2fa ^9`, `bacon/bacon-qr-code ^3` y, desde v1.37, `laravel/passkeys ^0.2`. ⚠️ Fortify registra sus propias rutas de login, registro y reset, que **chocan con los controladores de Breeze**. Adoptarlo implica desactivar sus vistas y rutas o migrar el login a Fortify.
    - `pragmarx/google2fa` v9.1.0 (2026-08-15, PHP ^7.1|^8.0, independiente del framework) + `bacon/bacon-qr-code` v3.1.1: es la opción mínima y no entra en conflicto con Breeze.
  - **Passkeys:**
    - `laravel/passkeys` v0.2.1 (2026-05-18, oficial, **pre-1.0**): Laravel 11-13, `web-auth/webauthn-lib 5.3.x`. Cliente `@laravel/passkeys` 0.4.0 con helper `./vue`.
    - `laragear/webauthn` v5.0.2 (2026-05-08): Laravel 12-13, PHP ^8.3, implementación propia. Cliente `@laragear/webpass` 2.1.2.
    - `spatie/laravel-passkeys` 1.8.1 (2026-06-05): Laravel 11-13, `web-auth/webauthn-lib ^5.3`. Su UI es un componente Livewire, así que con Inertia y Vue habría que hacer el front a mano (p. ej. con `@simplewebauthn/browser` 14.0.0).
    - `web-auth/webauthn-lib` 5.3.9 (2026-09-10): bajo nivel, máximo control y máximo trabajo.
  - En todas, la compatibilidad declarada con Laravel 13 está verificada en metadatos. Queda **por verificar** con un `composer require --dry-run` sobre el `composer.lock` real (`laravel/framework v13.37.0`) y con un spike de la ceremonia completa con Inertia (⚠️ incógnita para el diseño).
- **Tests de WebAuthn en Pest:** se supone que la librería elegida trae utilidades de prueba o que se pueden fijar vectores de *attestation* y *assertion*. Si no, el coste de C-02 sube (ver evaluación).
