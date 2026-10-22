---
generacion:
  inicio: 2026-10-22T09:22:14Z
  fin: 2026-10-22T09:27:59Z
  fuente: medido          # ventana compartida con improvement-plan.md
  tokens_reales: { entrada: 14, salida: 57065, cache_creacion: 57151, cache_lectura: 725911 }
  eur: null               # precioTokens no fiable: ejecuta rates-verify
  horas_ia: 0.38
  duracion: 23m
  ratio_usado: 300000
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Seguridad de cuenta: doble factor (TOTP) y passkeys

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-22 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (Breeze + google2fa propio + `laravel/passkeys`) |
| **Plan de pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Las horas de este fichero son **base** (sin el +20 % de contingencia); el plan muestra las cifras con margen. Tokens de ejecución repartidos según la evaluación (C-01 1,43M · C-02 1,87M · C-03 1,32M).

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — C-01 · TOTP + códigos de recuperación (backend y UI) | 7 | 7 | 100% | 0 / 37,0h | 0 / 4,8h | 0 / 1,2h | 0 / 1,43M |
| Fase 2 — C-02 · Passkeys (gestión y login sin contraseña, backend y UI) | 0 | 7 | 0% | 0 / 45,0h | 0 / 6,2h | 0 / 1,6h | 0 / 1,87M |
| Fase 3 — C-03 · E2E, accesibilidad, documentación y cierre | 0 | 6 | 0% | 0 / 32,0h | 0 / 4,4h | 0 / 1,1h | 0 / 1,32M |
| **TOTAL** | **7** | **20** | **35%** | **0 / 114,0h** | **0 / 15,4h** | **0 / 3,8h** | **0 / 4,62M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real), topado a la jornada configurada. Ver `skills/jira-sync/SKILL.md`.

---

## Fase 1 — C-01 · TOTP + códigos de recuperación (backend y UI)

**Estado**: completado · **Estimado**: 37.0h · **Real**: — · **Coste est.**: 1.850 € + tokens (⚠️ verificar) · **Tokens est.**: 1.43M

### T-01 — Dependencias, migración de 2FA y modelo User

- **Descripción**: Instala `pragmarx/google2fa ^9.1` y `bacon/bacon-qr-code ^3.1`, añade la migración de las columnas de 2FA en `users` (con la siguiente fecha libre tras `2026_11_03_*`) y prepara el modelo `User` (cast `encrypted`, `#[Hidden]`, borrado en cascada). El login clásico queda intacto: es la red de seguridad de todo C-01.
- **Changelog**: Preparada la base de datos y el modelo de usuario para el doble factor; el login actual no cambia.
- **Estado**: completado
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0.32h · real —
- **Supervisión**: est. 0.08h (≈25 % IA) · real —
- **Previsión IA**: 88k in / 9k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `composer.json`, `composer.lock`, `database/migrations/2026_11_04_090000_add_two_factor_columns_to_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `tests/Unit/UserTwoFactorColumnsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/UserTwoFactorColumnsTest.php tests/Feature/Auth` → todo en verde (los 8 ficheros de Auth sin tocar)
  - `php artisan migrate:fresh --env=testing --no-interaction` → migra sin error y existen `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` y `two_factor_last_used_at`

- **Evidencia**: tests Unit+Feature/Auth: 30 passed (77 assertions); migrate:fresh --env=testing OK y migrate local OK, 4 columnas two_factor_* presentes. Nota: two_factor_last_used_at es unsignedBigInteger (paso TOTP), no timestamp; casts extra recovery_codes=array y confirmed_at=datetime; helper hasTwoFactorEnabled().

**Criterios de aceptación**
- [x] Las 4 columnas existen y la migración es posterior a `2026_11_03_*` (`ls database/migrations | tail -3`)
- [x] `$user->toArray()` no contiene `two_factor_secret` ni `two_factor_recovery_codes` (criterio de proceso, `#[Hidden]`)
- [x] Un `SELECT two_factor_secret FROM users` tras guardar un secreto no devuelve el texto en claro (cast `encrypted`)
- [x] `tests/Feature/Auth/*` pasa sin modificar

**Subtareas**
- [x] `composer require pragmarx/google2fa:^9.1 bacon/bacon-qr-code:^3.1`
- [x] Migración nullable con los nombres de columna de Fortify (reversibilidad del diseño)
- [x] Cast `encrypted`, `#[Hidden]` y tests de ocultación y cifrado

**Notas**: Si `2026_11_04_*` ya existe al implementar, usar la siguiente fecha libre (§7 del diseño).

### T-02 — TotpService: secreto, QR SVG, verificación ±1 con antirreplay y códigos con hash

- **Descripción**: Crea `App\Services\TwoFactor\TotpService` sobre `google2fa`: genera el secreto y el QR SVG (bacon), verifica con ventana ±1 con `verifyKeyNewer` y persiste el último paso en `two_factor_last_used_at`, y genera y consume los 8 códigos de recuperación guardados con hash, uno por código (decisión 2 de §4 del diseño).
- **Changelog**: Ya se pueden generar secretos y códigos QR de doble factor, validar los códigos de la app y usar códigos de recuperación de un solo uso.
- **Estado**: completado
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0.64h · real —
- **Supervisión**: est. 0.16h (≈25 % IA) · real —
- **Previsión IA**: 176k in / 17k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `app/Services/TwoFactor/TotpService.php`, `tests/Unit/TotpServiceTest.php`, `app/Models/User.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/TotpServiceTest.php` → todos en verde (ventana ±1, rechazo de reutilización, 8 códigos, consumo único)

- **Evidencia**: tests/Unit/TotpServiceTest.php: 7 passed (38 assertions): ventana ±1, antirreplay, 8 códigos con hash, consumo único, sin logs. Nota: verifyKeyNewer devuelve true (no el paso) con oldTimestamp null, se pasa 0; reclamo del paso con UPDATE condicional atómico; two_factor_recovery_codes = array JSON de hashes bcrypt.

**Criterios de aceptación**
- [x] Un código válido de la ventana actual y de ±1 paso se acepta; fuera de la ventana se rechaza (CA-05)
- [x] El mismo código aceptado una vez se rechaza la segunda vez dentro de su ventana (CA-07; antirreplay en columna, no en cache)
- [x] Se generan 8 códigos; en BD solo hay hashes y el texto en claro se devuelve una única vez (CA-01, CA-10)
- [x] Consumir un código de recuperación lo invalida y devuelve cuántos quedan (CA-09)
- [x] Ninguna cadena de secreto, TOTP o código se escribe en el log

**Subtareas**
- [x] `generateSecret()`, `qrSvg()`, `verify()`, `generateRecoveryCodes()`, `consumeRecoveryCode()`
- [x] Test que calcula el TOTP con el propio `Google2FA` y el secreto conocido
- [x] Test de log con `Log::spy()` sin secretos

**Notas**: Código de seguridad propio: pasa por la revisión de T-07.

### T-03 — Login partido y reto 2FA (`/two-factor-challenge`)

- **Descripción**: Parte `LoginRequest::authenticate()` en `ensureIsNotRateLimited()` + `validateCredentials(): User` (sin `Auth::attempt`, mismo `throttleKey` y mismos mensajes). `AuthenticatedSessionController::store` abre la sesión como hoy si no hay 2FA, o guarda `login.id`, `login.remember` y `login.at` y redirige al reto. Nuevo `TwoFactorChallengeController` (`create`/`store`) con TOTP o recuperación, rate limit `2fa:{login_id}|{ip}` de 5/min, TTL de 5 min (`auth.two_factor.challenge_ttl`), `Auth::login($user, $remember)` + `regenerate` y auditoría.
- **Changelog**: Si tienes el doble factor activado, tras escribir tu contraseña se te pide el código de la app (o uno de recuperación) antes de entrar; 5 fallos bloquean un minuto.
- **Estado**: completado
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0.90h · real —
- **Supervisión**: est. 0.23h (≈25 % IA) · real —
- **Previsión IA**: 246k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `routes/auth.php`, `config/auth.php`, `tests/Feature/Auth/TwoFactorChallengeTest.php`, `tests/Feature/Auth/AuthenticationTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Auth` → todo en verde, incluido el caso «usuario sin 2FA entra igual»
  - `php artisan test --compact --filter=TwoFactorChallenge` → CA-04..CA-09 en verde

- **Evidencia**: tests/Feature/Auth: 39 passed (145 assertions) incl. caso sin 2FA y throttle clásico; --filter=TwoFactorChallenge: 12 passed (CA-04..CA-09). Desviaciones: authenticate() sustituido por validateCredentials() (no se conserva el wrapper); ruta names two-factor.challenge[.store]; sesión regenerada también al abrir el reto; Auth::login directo omite eventos Attempting/Failed de Auth::attempt.

**Criterios de aceptación**
- [x] CA-04: con 2FA, email y contraseña correctos NO autentican (`assertGuest`) y redirigen a `two-factor-challenge`
- [x] CA-05: TOTP válido → `dashboard`/`intended`, sesión regenerada, `remember` respetado y `auth.2fa.challenge_passed`
- [x] CA-06: 5 fallos → el 6.º se bloquea con segundos de espera aunque el código sea correcto; `auth.2fa.challenge_failed` ×5 y `auth.2fa.lockout`
- [x] CA-07: un TOTP reutilizado se rechaza
- [x] CA-08: reto con más de 5 min o sin `login.id` → `/login` con aviso, sin sesión
- [x] CA-09: un código de recuperación válido entra, no sirve dos veces y `auth.2fa.recovery_used` lleva el número restante y nunca el código
- [x] Usuario sin 2FA: login idéntico a hoy (mismo throttle y mismo mensaje)

**Subtareas**
- [x] TDD: primero el test de no-regresión de `AuthenticationTest` y el caso sin 2FA
- [x] Partir `authenticate()` conservando `Lockout` y `auth.throttle`
- [x] Reto: TTL, rate limiter, alternar TOTP/recuperación y auditoría sin secretos

**Notas**: Es el punto de mayor riesgo de regresión (riesgo Alto del diseño). `AuthenticationTest` se adapta solo en lo que documenta el nuevo flujo.

### T-04 — Gestión del 2FA desde el perfil: activar, confirmar, desactivar y regenerar códigos

- **Descripción**: `App\Http\Controllers\Profile\TwoFactorController` con activar (secreto pendiente + QR), confirmar (valida el primer TOTP, guarda `two_factor_confirmed_at`, genera los 8 códigos y **rota `remember_token`**, decisión 3 de §4), desactivar (contraseña + TOTP o recuperación) y regenerar códigos. Rutas bajo `auth` + `password.confirm`.
- **Changelog**: Desde tu perfil puedes activar el doble factor con tu app de autenticación, desactivarlo y generar códigos de recuperación nuevos.
- **Estado**: completado
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0.77h · real —
- **Supervisión**: est. 0.19h (≈25 % IA) · real —
- **Previsión IA**: 211k in / 21k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Profile/TwoFactorController.php`, `app/Http/Requests/Profile/TwoFactorConfirmRequest.php`, `routes/auth.php`, `tests/Feature/Auth/TwoFactorSettingsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=TwoFactorSettings` → CA-01, CA-02, CA-03 y CA-10 en verde
  - `php artisan route:list --path=two-factor --json` → todas las rutas de gestión llevan `auth` y `password.confirm`

- **Evidencia**: --filter=TwoFactorSettings: 11 passed (46 assertions) CA-01/02/03/10 + rotación remember_token; route:list --path=two-factor: las 4 rutas de gestión (user/two-factor, /confirm, DELETE, /recovery-codes) llevan Authenticate + RequirePassword. Desviaciones: rutas bajo prefijo user/two-factor con nombres two-factor.enable|confirm|disable|recovery-codes; secreto pendiente y códigos viajan como flash de sesión (twoFactorSetup / recoveryCodes) que T-06 expondrá por Inertia; throttle 10/min extra en confirm y disable.

**Criterios de aceptación**
- [x] CA-01: con TOTP válido se confirma, se devuelven 8 códigos una sola vez y se audita `auth.2fa.enabled`
- [x] CA-02: TOTP incorrecto → error en el campo, el 2FA no se activa y el secreto pendiente se conserva
- [x] CA-03: desactivar sin contraseña o sin código válido se rechaza; con ambos se borran secreto y códigos y se audita `auth.2fa.disabled`
- [x] CA-10: regenerar da 8 códigos nuevos, los anteriores dejan de valer y se audita `auth.2fa.recovery_regenerated`
- [x] Al confirmar, `remember_token` cambia (test que compara antes y después)
- [x] Los códigos no se pueden volver a mostrar tras la respuesta de generación

**Subtareas**
- [x] Rutas y Form Requests
- [x] Rotación de `remember_token` al confirmar
- [x] Auditoría `auth.2fa.*` con `channel: 'web'`

**Notas**: Respeta las decisiones 2 y 3 de §4 del diseño.

### T-05 — Comando `user:two-factor-reset`, reset de contraseña con 2FA y MCP intacto

- **Descripción**: Comando de operador que desactiva el 2FA de un usuario por email y lo audita (`auth.2fa.reset_by_operator`). Tests de regresión transversales: el reset de contraseña no abre sesión ni desactiva el 2FA (CA-11) y un usuario con 2FA sigue usando el MCP por API key sin reto (CA-22). No se tocan `routes/ai.php`, `McpGuard`, `config/sanctum.php` ni `ApiKeyController`.
- **Changelog**: Soporte puede desactivar el doble factor de un usuario que perdió el móvil con un comando; el reset de contraseña y las claves del MCP no se ven afectados.
- **Estado**: completado
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0.45h · real —
- **Supervisión**: est. 0.11h (≈25 % IA) · real —
- **Previsión IA**: 123k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-03, T-04
- **Tipo**: backend
- **Archivos**: `app/Console/Commands/UserTwoFactorReset.php`, `tests/Feature/Auth/TwoFactorResetCommandTest.php`, `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Auth/McpWithTwoFactorTest.php`
- **Cubre (tests)**: API-01, M-06
- **Verificación**:
  - `php artisan test --compact --filter='TwoFactorResetCommand|McpWithTwoFactor|PasswordReset'` → en verde
  - `git diff --stat -- routes/ai.php app/Http/Middleware/McpGuard.php config/sanctum.php app/Http/Controllers/ApiKeyController.php` → sin salida (no cambian)
  - `php artisan test --compact tests/Feature/Mcp` → en verde sin tocar esos tests

- **Evidencia**: --filter='TwoFactorResetCommand|McpWithTwoFactor|PasswordReset': 11 passed (42 assertions); tests/Feature/Mcp: 43 passed sin tocar; git diff 7b44972 --stat de routes/ai.php, McpGuard, sanctum.php, ApiKeyController: sin salida.

**Criterios de aceptación**
- [x] CA-11: tras el reset de contraseña con 2FA activo no hay sesión, el 2FA sigue activo y el siguiente login pasa por el reto
- [x] CA-12: `php artisan user:two-factor-reset {email}` deja el 2FA desactivado y audita `auth.2fa.reset_by_operator`; el usuario entra solo con contraseña
- [x] CA-22: `POST /mcp` con `Bearer` de un usuario con 2FA responde igual que sin 2FA, y una sesión a medio reto no autentica `/mcp`
- [x] El comando falla con mensaje claro si el email no existe

**Subtareas**
- [x] Comando con confirmación y `--force`
- [x] Tests CA-11, CA-12 y CA-22
- [x] Comprobar que los ficheros del MCP no aparecen en el diff

**Notas**: —

### T-06 — UI del 2FA: reto, Login y tarjeta «Seguridad» con TwoFactorForm

- **Descripción**: `Auth/TwoFactorChallenge.vue` (campo `one-time-code`, `inputmode=numeric`, alternar a código de recuperación, `aria-live`), ajuste mínimo de `Auth/Login.vue`, y `Profile/Partials/TwoFactorForm.vue` (activar con QR con texto alternativo y secreto copiable, confirmar, mostrar los 8 códigos una vez con copiar y descargar, desactivar, regenerar) montado en una tarjeta «Seguridad» de `Profile/Edit.vue`. La UI de C-01 va en la fase 1 (no se aplaza a C-03) para poder verificar el 2FA de extremo a extremo antes de abrir passkeys.
- **Changelog**: Nueva pantalla de verificación en dos pasos al entrar, y en tu perfil una sección de doble factor con QR, clave copiable y códigos de recuperación que puedes copiar o descargar.
- **Estado**: completado
- **Tiempo humano**: est. 9,0h · real —
- **Tiempo IA (ejec.)**: est. 1.16h · real —
- **Supervisión**: est. 0.29h (≈25 % IA) · real —
- **Previsión IA**: 317k in / 31k out tok · ⚠️ verificar €
- **Dependencias**: T-03, T-04
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Auth/TwoFactorChallenge.vue`, `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Profile/Partials/TwoFactorForm.vue`, `app/Http/Controllers/ProfileController.php`, `tests/Feature/ProfileTest.php`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, A11Y-01, M-03, M-05
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact tests/Feature/ProfileTest.php --filter=Security` → la página `Profile/Edit` expone `twoFactor` (estado, sin secretos) por Inertia
  - `vendor/bin/pint --test` → sin cambios pendientes

- **Evidencia**: npm run build OK (built in 807ms); ProfileTest --filter=Security: 3 passed (twoFactor sin secretos, códigos una sola vez); vendor/bin/pint --test: passed; php artisan test completo: 841 passed (796 + 45 nuevos). Desviaciones: Login.vue solo recibe role=status en el aviso (el reto no necesita más cambios); props twoFactorSetup/recoveryCodes llegan por flash de sesión en Inertia; QR como data-URI en <img alt>. A11y/375px se valida en T-18.

**Criterios de aceptación**
- [x] CA-23: el campo de código tiene etiqueta visible, `autocomplete="one-time-code"` e `inputmode="numeric"`; los errores se anuncian con `aria-live`; el enlace a «código de recuperación» es accesible por teclado
- [x] CA-24: el QR tiene `alt`, el secreto aparece como texto copiable y los códigos se pueden copiar o descargar sin ratón
- [x] Las props de Inertia no incluyen `two_factor_secret` ni códigos, salvo la respuesta única de generación
- Nota: «Sin desbordamiento horizontal a 375 y 360 px» se traslada a T-18 (su criterio «0 px de desbordamiento horizontal a 375 y 360 px»), donde se verifica con Playwright.

**Subtareas**
- [x] Página del reto con alternancia TOTP/recuperación
- [x] TwoFactorForm: estados desactivado, pendiente de confirmar y activo
- [x] Props de `ProfileController::edit` (solo estado y nº de códigos restantes)

**Notas**: Reutiliza `TextInput`, `InputLabel`, `InputError`, `PrimaryButton` y `Modal`.

### T-07 — Revisión de seguridad de C-01

- **Descripción**: Revisión dirigida del código de seguridad propio: reto 2FA, partición del login, hashes de recuperación, antirreplay, rate limit y ausencia de secretos en log y auditoría. Resultado: checklist firmada en las notas y huecos corregidos antes de abrir C-02.
- **Changelog**: El doble factor pasó una revisión de seguridad independiente; se reforzó el límite de intentos del código y la protección del secreto durante la activación.
- **Estado**: completado
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0.52h · real —
- **Supervisión**: est. 0.13h (≈25 % IA) · real —
- **Previsión IA**: 141k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-05, T-06
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `tests/Feature/Auth/TwoFactorAuditLeakTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde (796 + los nuevos de C-01)
  - `php artisan test --compact --filter=TwoFactorAuditLeak` → ninguna fila de `audit_logs` ni línea de log contiene secreto, TOTP ni código de recuperación
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [x] La suite completa pasa sin desactivar ni modificar tests existentes salvo el flujo de login documentado
- [x] Ninguna fila de `audit_logs` ni línea de log contiene secreto, TOTP ni código de recuperación
- [x] `vendor/bin/pint --test` sin cambios pendientes

**Subtareas**
- [x] Revisar reto, rate limit y TTL
- [x] Test de fugas en log y auditoría
- [x] Ejecutar suite completa y Pint

**Notas**: Puerta de salida de la fase 1. Revisión adversarial de dos intentos (lentes A+B+C+D; tablas al final del ledger): intento 1 → 2 Important + 9 Minor corregidos; intento 2 → 1 Important (N1) + 2 Minor corregidos, sin gaps abiertos.
Checklist firmada (orquestador, 2026-10-22):
- [x] Reto: sin bypass (recordarme solo tras el reto, reset de contraseña no inicia sesión, sesión pendiente con TTL y re-chequeo de 2FA, `regenerate` en cada paso).
- [x] Rate limit: por cuenta (`2fa:{id}`), incremento atómico antes de verificar (MySQL `lockForUpdate`), lockout auditado una vez.
- [x] TTL del reto 5 min (`auth.two_factor.challenge_ttl`).
- [x] Antirreplay por paso TOTP en columna con UPDATE condicional; códigos de recuperación con hash bcrypt y uso único.
- [x] Login: `Timebox` y rehash como `SessionGuard::attempt`.
- [x] Secreto pendiente solo con contraseña confirmada reciente; historial cifrado en contexto seguro y borrado al salir.
- [x] Sin secretos en log ni auditoría: `TwoFactorAuditLeakTest`.
**Evidencia**: `php artisan test --compact --filter=TwoFactorAuditLeak` → 1 passed (35 assertions: secreto, 3 TOTP, 7 códigos erróneos y 16 de recuperación ausentes de `audit_logs` y del log en todos los niveles); `php artisan test` → 856 passed (6394 assertions); `vendor/bin/pint --test` → passed.

---

## Fase 2 — C-02 · Passkeys (gestión y login sin contraseña, backend y UI)

**Estado**: borrador · **Estimado**: 45.0h · **Real**: — · **Coste est.**: 2.250 € + tokens (⚠️ verificar) · **Tokens est.**: 1.87M

### T-08 — Dependencias de passkeys, esquema y configuración WebAuthn

- **Descripción**: Instala `laravel/passkeys ~0.2.1` y `@laravel/passkeys ~0.4.0`, publica `config/passkeys.php` con `relying_party_id` ← `WEBAUTHN_RP_ID`, `allowed_origins` ← `WEBAUTHN_ORIGINS` (CSV) y `user_handle_secret` ← `PASSKEYS_USER_HANDLE_SECRET`, crea la migración de `passkeys` con `name` de 100 caracteres (siguiente fecha libre), añade `PasskeyUser`/`PasskeyAuthenticatable` a `User` y `Passkeys::ignoreRoutes()`. **Sin `WEBAUTHN_RP_NAME`** (decisión 1 de §4).
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0.41h · real —
- **Supervisión**: est. 0.10h (≈25 % IA) · real —
- **Previsión IA**: 113k in / 11k out tok · ⚠️ verificar €
- **Dependencias**: T-07
- **Tipo**: backend
- **Archivos**: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `config/passkeys.php`, `database/migrations/2026_11_04_090100_create_passkeys_table.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `.env.example`, `.env.production.example`, `tests/Unit/PasskeyConfigTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/PasskeyConfigTest.php` → RP ID y orígenes se derivan de la config (por defecto, de `APP_URL`)
  - `grep -rn WEBAUTHN_RP_NAME .env.example .env.production.example config` → sin coincidencias
  - `php artisan route:list --name=passkeys` → sin rutas del paquete (están ignoradas)

**Criterios de aceptación**
- [ ] `laravel/passkeys` queda fijado en `~0.2.1` en `composer.json`
- [ ] `.env.example` y `.env.production.example` documentan `WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS` y `PASSKEYS_USER_HANDLE_SECRET`, y no `WEBAUTHN_RP_NAME`
- [ ] El RP ID por defecto sale del host de `APP_URL` y los orígenes son una lista CSV
- [ ] La migración queda posterior a la de 2FA y a `2026_11_03_*`
- [ ] `tests/Feature/Auth/*` y la suite de C-01 siguen en verde

**Subtareas**
- [ ] `composer require laravel/passkeys:~0.2.1` y `npm i @laravel/passkeys@~0.4.0`
- [ ] Publicar config y migración; añadir `name`
- [ ] `Passkeys::ignoreRoutes()` y traits del modelo

**Notas**: Antes del `composer require`, repetir el `--dry-run` del diseño sobre el lock vigente.

### T-09 — PasskeyGateway y auditoría por eventos

- **Descripción**: `App\Services\Passkeys\PasskeyGateway` como único punto de contacto con `Laravel\Passkeys\*` (aísla el riesgo 0.x): opciones de registro y de login, alta, verificación y borrado, delegando en las Actions del paquete. Listeners de `PasskeyRegistered`, `PasskeyVerified` y `PasskeyDeleted` hacia `AuditLog`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0.55h · real —
- **Supervisión**: est. 0.14h (≈25 % IA) · real —
- **Previsión IA**: 151k in / 15k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Services/Passkeys/PasskeyGateway.php`, `app/Providers/AppServiceProvider.php`, `app/Listeners/AuditPasskeyEvents.php`, `tests/Feature/Auth/PasskeyGatewayTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PasskeyGateway` → en verde
  - `grep -rln "Laravel.Passkeys" app` → solo `PasskeyGateway.php`, el listener, `AppServiceProvider.php` y `User.php`

**Criterios de aceptación**
- [ ] Fuera del gateway, del modelo, del provider y de los listeners no hay `use Laravel\Passkeys` en `app/`
- [ ] Los eventos del paquete generan filas `auth.passkey.registered`, `auth.passkey.login` y `auth.passkey.deleted` con `channel: 'web'`
- [ ] La auditoría no contiene `credential_id`, clave pública ni datos de la credencial

**Subtareas**
- [ ] Gateway con métodos finos y tipados
- [ ] Listeners y registro en el provider
- [ ] Test con `bind` de una `VerifyPasskey` falsa

**Notas**: Documentar en el docblock la versión del paquete y la regla del ADR-003: revisar al subir de versión.

### T-10 — Gestión de passkeys del perfil: opciones, alta, renombrado y borrado

- **Descripción**: `App\Http\Controllers\Profile\PasskeyController` con las URL que espera el cliente (`/user/passkeys/options`, `/user/passkeys`), `update` (renombrar, ≤100) y `destroy`. Las búsquedas van con `$request->user()->passkeys()->findOrFail()` (404 ante una ajena, CA-19). `excludeCredentials` y la unicidad en BD evitan duplicar el mismo autenticador.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0.97h · real —
- **Supervisión**: est. 0.24h (≈25 % IA) · real —
- **Previsión IA**: 265k in / 26k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Profile/PasskeyController.php`, `app/Http/Requests/Profile/PasskeyNameRequest.php`, `routes/auth.php`, `tests/Feature/Auth/PasskeyManagementTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PasskeyManagement` → CA-13..CA-16 y CA-19 en verde
  - `php artisan route:list --path=user/passkeys` → rutas con `auth` y `password.confirm`

**Criterios de aceptación**
- [ ] CA-13: el alta con nombre aparece en la lista y audita `auth.passkey.registered`
- [ ] CA-14: dos passkeys conviven; registrar de nuevo el mismo autenticador se rechaza
- [ ] CA-15: renombrar actualiza el nombre y audita `auth.passkey.renamed`; nombre vacío o de más de 100 caracteres se rechaza
- [ ] CA-16: borrar tras confirmar quita la passkey, deja de servir para entrar y audita `auth.passkey.deleted`
- [ ] CA-19: renombrar o borrar una passkey de otro usuario devuelve 404 y no la altera

**Subtareas**
- [ ] Rutas y Form Request del nombre
- [ ] Aislamiento por usuario con `findOrFail` sobre la relación
- [ ] Tests con la Action falsa vía gateway

**Notas**: `password.confirm` responde 423 JSON a un `fetch`: la UI confirma antes de abrir el alta (riesgo del diseño, se resuelve en T-13).

### T-11 — Login con passkey: opciones y verificación como `guest`

- **Descripción**: `App\Http\Controllers\Auth\PasskeyLoginController` (`options`, `store`) con las URL del cliente (`/passkeys/login/options`, `/passkeys/login`), `throttle:10,1` por IP (decisión 4 de §4), `Auth::login` + regenerar sesión, `last_used_at`, respuesta JSON `{redirect}` hacia `intended`/`dashboard` y auditoría del fallo sin revelar si existe la cuenta. La passkey sustituye a contraseña + 2FA: no se pide reto.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 6,5h · real —
- **Tiempo IA (ejec.)**: est. 0.90h · real —
- **Supervisión**: est. 0.23h (≈25 % IA) · real —
- **Previsión IA**: 246k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Auth/PasskeyLoginController.php`, `routes/auth.php`, `tests/Feature/Auth/PasskeyLoginTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PasskeyLogin` → CA-17, CA-18 y CA-21 en verde
  - `php artisan route:list --path=passkeys/login` → `guest` y `throttle:10,1`

**Criterios de aceptación**
- [ ] CA-17: usuario sin sesión con passkey válida entra al `dashboard` sin contraseña ni reto 2FA (con y sin 2FA) y se audita `auth.passkey.login`
- [ ] CA-18: passkey borrada o de otro RP ID → no entra, mensaje que no revela si la cuenta existe y `auth.passkey.login_failed`
- [ ] CA-21: la petición 11 en un minuto desde la misma IP recibe 429
- [ ] Un contador de firmas a 0 (passkey sincronizada) no bloquea el login; la regresión real se audita como `sign_count_regression`
- [ ] La sesión se regenera y no hay doble redirección

**Subtareas**
- [ ] Controlador con `{redirect}` para Inertia
- [ ] Auditoría de éxito y fallo
- [ ] Throttle propio en lugar del `6,1` del paquete

**Notas**: El 429 de CA-21 se prueba con 11 llamadas en el test.

### T-12 — Tests de la ceremonia WebAuthn con vector grabado

- **Descripción**: Graba una vez con el autenticador virtual de Chromium un vector real de *attestation* y *assertion* contra `localhost` y lo usa en Pest inyectando en sesión `passkey.verification_options` con su reto, para probar la verificación criptográfica real a través del gateway. Cubre contador 0 y contador que retrocede.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8,0h · real —
- **Tiempo IA (ejec.)**: est. 1.11h · real —
- **Supervisión**: est. 0.28h (≈25 % IA) · real —
- **Previsión IA**: 303k in / 30k out tok · ⚠️ verificar €
- **Dependencias**: T-10, T-11
- **Tipo**: test
- **Archivos**: `tests/Feature/Auth/PasskeyCeremonyTest.php`, `tests/Fixtures/passkeys/`, `tests/e2e/record-passkey-vector.ts`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact --filter=PasskeyCeremony` → en verde con el vector real (origen `http://localhost:8080`, RP ID `localhost`)
  - `php artisan test --compact tests/Feature/Auth` → sin regresiones

**Criterios de aceptación**
- [ ] Una assertion válida del vector autentica al usuario de la fixture
- [ ] Un reto distinto, un origen distinto o un RP ID distinto se rechazan
- [ ] Contador 0 → 0 se tolera; un contador que retrocede con valores distintos de 0 se rechaza y se audita `sign_count_regression`
- [ ] El vector solo contiene una credencial de prueba, sin material reutilizable fuera del test

**Subtareas**
- [ ] Script de grabación con CDP `WebAuthn.addVirtualAuthenticator`
- [ ] Fixtures versionadas con un README del vector
- [ ] Tests de contador y de origen

**Notas**: Incógnita del diseño: si el vector resulta inestable, plan B = `bind` de la Action y cubrir la ceremonia real solo en E2E (+3-4 h).

### T-13 — UI de passkeys: PasskeysForm, botón en Login y avisos de contexto

- **Descripción**: `Profile/Partials/PasskeysForm.vue` (lista con nombre, alta y último uso; añadir con confirmación de contraseña previa; renombrar y borrar con confirmación) dentro de la tarjeta «Seguridad». `Auth/Login.vue` gana «Entrar con passkey» con `usePasskeyVerify` de `@laravel/passkeys/vue`, `autocomplete="username webauthn"` y el aviso «Las passkeys requieren HTTPS» si `!isSecureContext` o no hay WebAuthn (CA-20). Trata `NotAllowedError` con mensaje neutro y el 423.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 11,0h · real —
- **Tiempo IA (ejec.)**: est. 1.52h · real —
- **Supervisión**: est. 0.38h (≈25 % IA) · real —
- **Previsión IA**: 416k in / 41k out tok · ⚠️ verificar €
- **Dependencias**: T-10, T-11
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Profile/Partials/PasskeysForm.vue`, `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Auth/Login.vue`, `resources/js/Composables/useSecureContext.js`, `app/Http/Controllers/ProfileController.php`, `tests/Feature/ProfileTest.php`
- **Cubre (tests)**: E2E-05, E2E-06, E2E-07, A11Y-01, M-01, M-02, M-05
- **Verificación**:
  - `npm run build` → build sin errores
  - `php artisan test --compact tests/Feature/ProfileTest.php` → expone `passkeys` (id, nombre, fechas) sin material de la credencial
  - `vendor/bin/pint --test` → sin cambios pendientes

**Criterios de aceptación**
- [ ] CA-20: sin WebAuthn o en `http://` que no sea `localhost` no aparecen botón ni tarjeta, sale «Las passkeys requieren HTTPS» y el login con contraseña funciona igual
- [ ] CA-13/14/15/16: alta, segunda passkey, renombrado y borrado con confirmación desde la UI
- [ ] Cancelar la biometría muestra «No se completó la passkey» sin auditar como ataque
- [ ] El alta pide `password.confirm` ANTES de abrir el diálogo (no hay 423 visible)
- [ ] Sin desbordamiento horizontal a 375 y 360 px (se valida en T-18)

**Subtareas**
- [ ] Composable de contexto seguro
- [ ] PasskeysForm con estados vacío, lista y error
- [ ] Botón en Login y autofill condicional

**Notas**: Reutiliza `Modal`, `DangerButton` y `EmptyState`.

### T-14 — Revisión de seguridad de C-02

- **Descripción**: Revisión dirigida de passkeys: aislamiento por usuario, throttle, RP ID y orígenes, `user_handle_secret`, mensajes que no revelan cuentas, auditoría sin material de credencial, y comprobación de que Breeze y el MCP no se han tocado.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 5,5h · real —
- **Tiempo IA (ejec.)**: est. 0.76h · real —
- **Supervisión**: est. 0.19h (≈25 % IA) · real —
- **Previsión IA**: 208k in / 21k out tok · ⚠️ verificar €
- **Dependencias**: T-12, T-13
- **Tipo**: docs
- **Archivos**: `docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `tests/Feature/Auth/PasskeyAuditLeakTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde
  - `git diff --stat -- routes/ai.php app/Http/Middleware/McpGuard.php config/sanctum.php app/Http/Controllers/ApiKeyController.php` → sin salida
  - lectura: checklist de revisión en Notas con cada punto marcado

**Criterios de aceptación**
- [ ] La suite completa pasa (796 + los de C-01 y C-02)
- [ ] Ninguna fila de `audit_logs` ni línea de log contiene material de credencial WebAuthn (`PasskeyAuditLeakTest`)
- [ ] `PASSKEYS_USER_HANDLE_SECRET` es explícito en `.env.example` (no depende de `APP_KEY`)

**Subtareas**
- [ ] Revisar controladores y rutas
- [ ] Test de fugas en auditoría y log
- [ ] Suite completa y Pint

**Notas**: Puerta de salida de la fase 2.

---

## Fase 3 — C-03 · E2E, accesibilidad, documentación y cierre

**Estado**: borrador · **Estimado**: 32.0h · **Real**: — · **Coste est.**: 1.600 € + tokens (⚠️ verificar) · **Tokens est.**: 1.32M

### T-15 — Infraestructura E2E sobre localhost:8080

- **Descripción**: Mueve la batería a `http://localhost:8080` (WebAuthn no admite una IP como RP ID): arranque con `APP_URL=http://localhost:8080`, `WEBAUTHN_RP_ID=localhost`, `WEBAUTHN_ORIGINS=http://localhost:8080`. Helpers `totp()` (RFC 6238 con `crypto`) y `virtualAuthenticator()` (CDP), y un usuario E2E dedicado creado en `global-setup.ts` y borrado en `global-teardown.ts`, para no tocar al usuario demo.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0.82h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 225k in / 22k out tok · ⚠️ verificar €
- **Dependencias**: T-14
- **Tipo**: test
- **Archivos**: `playwright.config.ts`, `tests/e2e/helpers.ts`, `tests/e2e/global-setup.ts`, `tests/e2e/global-teardown.ts`, `package.json`, `docs/guias/desarrollo-local.md`
- **Cubre (tests)**: E2E-01, E2E-05
- **Verificación**:
  - `E2E_BASE_URL=http://localhost:8080 npx playwright test tests/e2e/pages.spec.ts tests/e2e/flows.spec.ts` → la batería existente pasa en los tres proyectos sobre `localhost`
  - `npx tsc --noEmit -p .` → sin errores de tipos en `tests/e2e`

**Criterios de aceptación**
- [ ] Toda la batería existente (`pages`, `flows`, `a11y`) pasa sobre `http://localhost:8080` en desktop, mobile y mobile-360
- [ ] El usuario E2E dedicado se crea y se elimina; el usuario demo no se modifica
- [ ] `totp()` genera un código que `TotpService` acepta (se comprueba en E2E-01)

**Subtareas**
- [ ] Variables de arranque del servidor documentadas
- [ ] Helpers `totp` y CDP
- [ ] Setup y teardown del usuario E2E

**Notas**: Riesgo del diseño: cookies o URLs del resto de la batería sobre `localhost`; se confirma aquí antes de escribir los specs.

### T-16 — E2E del 2FA: activar, reto, recuperación, bloqueo y desactivar

- **Descripción**: Bloque 2FA de `tests/e2e/security.spec.ts` con el TOTP calculado en Node: activación completa, login con reto, código de recuperación de un solo uso, bloqueo tras 5 fallos y desactivar y regenerar.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 5,5h · real —
- **Tiempo IA (ejec.)**: est. 0.75h · real —
- **Supervisión**: est. 0.19h (≈25 % IA) · real —
- **Previsión IA**: 206k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: test
- **Archivos**: `tests/e2e/security.spec.ts`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04
- **Verificación**: `E2E_BASE_URL=http://localhost:8080 npx playwright test tests/e2e/security.spec.ts -g "2FA"` → verde en desktop, mobile y mobile-360

**Criterios de aceptación**
- [ ] CA-01, CA-03, CA-05, CA-06, CA-09 y CA-10 se recorren de extremo a extremo en la UI real
- [ ] El spec deja al usuario E2E en «sin 2FA» al terminar (idempotente)
- [ ] Capturas en los momentos clave (QR, códigos, reto, bloqueo)

**Subtareas**
- [ ] Flujo de activación y descarga o copia de códigos
- [ ] Login con reto y con recuperación
- [ ] Bloqueo del sexto intento

**Notas**: —

### T-17 — E2E de passkeys con el autenticador virtual de Chromium

- **Descripción**: Bloque de passkeys de `security.spec.ts` con CDP `WebAuthn.addVirtualAuthenticator` (`hasResidentKey`, `hasUserVerification`, `isUserVerified`): alta, segunda passkey, renombrado, borrado, login sin contraseña ni reto, passkey borrada y aviso de contexto no seguro.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 6,5h · real —
- **Tiempo IA (ejec.)**: est. 0.89h · real —
- **Supervisión**: est. 0.22h (≈25 % IA) · real —
- **Previsión IA**: 244k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: test
- **Archivos**: `tests/e2e/security.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-05, E2E-06, E2E-07
- **Verificación**: `E2E_BASE_URL=http://localhost:8080 npx playwright test tests/e2e/security.spec.ts -g "passkey"` → verde en los tres proyectos

**Criterios de aceptación**
- [ ] CA-13..CA-17 recorridos en la UI real con autenticador virtual
- [ ] CA-18: tras borrar la passkey, el login con ella falla con el mensaje neutro
- [ ] CA-20: con WebAuthn deshabilitado en el contexto no hay controles y sale el aviso
- [ ] El usuario con 2FA entra con passkey sin ver el reto

**Subtareas**
- [ ] Alta, renombrado y borrado
- [ ] Login con passkey (con y sin 2FA)
- [ ] Escenario sin WebAuthn

**Notas**: CA-19 (passkey ajena → 404) queda cubierto por Pest en T-10; no se duplica en E2E.

### T-18 — axe y desbordamiento en Login, reto y Perfil → Seguridad

- **Descripción**: Añade a `a11y.spec.ts` las páginas nuevas (reto 2FA, Login con passkey, Perfil con la tarjeta Seguridad en sus estados de activación) con axe en desktop, 375 y 360 px, y la comprobación de 0 px de desbordamiento horizontal. Actualiza `AUTH_PAGES` y `PUBLIC_PAGES` en `helpers.ts` si procede.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0.55h · real —
- **Supervisión**: est. 0.14h (≈25 % IA) · real —
- **Previsión IA**: 150k in / 15k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17
- **Tipo**: test
- **Archivos**: `tests/e2e/a11y.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/pages.spec.ts`
- **Cubre (tests)**: A11Y-01
- **Verificación**: `E2E_BASE_URL=http://localhost:8080 npx playwright test tests/e2e/a11y.spec.ts` → 0 violaciones `serious`/`critical` y sin desbordamiento horizontal en los tres proyectos

**Criterios de aceptación**
- [ ] Login, reto 2FA y Perfil → Seguridad pasan axe sin violaciones `serious` ni `critical`
- [ ] 0 px de desbordamiento horizontal a 375 y 360 px en esas páginas
- [ ] CA-23 y CA-24 cubiertos en lo automatizable; el resto va a M-05

**Subtareas**
- [ ] Rutas nuevas en `helpers.ts`
- [ ] Estados del perfil (QR visible, códigos visibles)
- [ ] Aserción de desbordamiento

**Notas**: —

### T-19 — Documentación de usuario y operación, comprobación de sodium y cierre del ADR

- **Descripción**: Sección de seguridad de cuenta en la guía de usuario (activar 2FA, guardar códigos, passkeys, qué pasa si se pierde el móvil), guía de operación (RP ID por entorno, rotar ngrok, `APP_PREVIOUS_KEYS`, `PASSKEYS_USER_HANDLE_SECRET`, `user:two-factor-reset`) y comprobación de `sodium` en el PHP de Hestia con `php -m` (con `paragonie/sodium_compat` si falta). ADR-003 pasa de `propuesta` a `aceptada` con el usuario.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0.69h · real —
- **Supervisión**: est. 0.17h (≈25 % IA) · real —
- **Previsión IA**: 188k in / 19k out tok · ⚠️ verificar €
- **Dependencias**: T-14
- **Tipo**: docs
- **Archivos**: `docs/usuario/guia-de-usuario.md`, `docs/modulos/despliegue.md`, `docs/guias/desarrollo-local.md`, `docs/README.md`, `.env.production.example`, `docs/knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md`, `docs/knowledge/README.md`
- **Cubre (tests)**: M-04
- **Verificación**:
  - `grep -n "WEBAUTHN_RP_ID\|PASSKEYS_USER_HANDLE_SECRET\|user:two-factor-reset\|APP_PREVIOUS_KEYS" docs/modulos/despliegue.md docs/guias/desarrollo-local.md` → las cuatro aparecen documentadas
  - `php -m | grep -i sodium` → `sodium` en el PHP local (la comprobación en Hestia es M-04)
  - lectura: la guía de usuario explica activar 2FA, códigos de recuperación y passkeys con lenguaje no técnico

**Criterios de aceptación**
- [ ] La guía de operación explica el RP ID en Hestia y el efecto de rotar ngrok (criterio de proceso)
- [ ] La guía de operación incluye `php -m | grep sodium` previo al despliegue y la alternativa `paragonie/sodium_compat`
- [ ] La guía de usuario cubre la pérdida del móvil: passkey, códigos de recuperación o contacto con el operador
- [ ] ADR-003 actualizado y el índice `docs/knowledge/README.md` coherente

**Subtareas**
- [ ] Guía de usuario
- [ ] Guía de operación y despliegue
- [ ] ADR-003 e índice

**Notas**: La verificación real de `sodium` en el servidor es una acción manual del usuario (M-04).

### T-20 — Revisión final, regresión completa y cierre del plan

- **Descripción**: Pasada final: suite Pest completa, Pint, build, batería E2E completa en los tres proyectos, comprobación de que no cambian los ficheros del MCP, `ledger-lint` y actualización de estados, índice del roadmap y spec y diseño a `implementada`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0.69h · real —
- **Supervisión**: est. 0.17h (≈25 % IA) · real —
- **Previsión IA**: 188k in / 19k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17, T-18, T-19
- **Tipo**: test
- **Archivos**: `docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `docs/roadmap/2026-10-22-account-2fa-passkeys/improvement-plan.md`, `docs/roadmap/README.md`
- **Cubre (tests)**: E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07, API-01, A11Y-01, M-01, M-02, M-03, M-04, M-05, M-06
- **Verificación**:
  - `php artisan test --compact` → suite completa en verde (796 + nuevos)
  - `vendor/bin/pint --test` → sin cambios pendientes
  - `npm run build` → sin errores
  - `E2E_BASE_URL=http://localhost:8080 npx playwright test` → batería completa en verde en desktop, mobile y mobile-360

**Criterios de aceptación**
- [ ] Todos los CA-01..CA-24 de la spec tienen test automático o ítem M-xx marcado
- [ ] Los ficheros del MCP no cambian (`git diff --stat` vacío)
- [ ] Los M-xx quedan listados como pendientes de la persona en el resumen de cierre

**Subtareas**
- [ ] Suite, Pint, build y E2E
- [ ] Actualizar estados y el índice
- [ ] Resumen de cierre con los M-xx pendientes

**Notas**: Los M-xx no bloquean el cierre técnico; se entregan como checklist al usuario.

---

## Revisión de dos lentes — intento 1: Fase 1 (T-01..T-06) — 2 Important y 9 Minor

Lentes A+B+C+D (C: rutas de auth/sesión/secretos; D: hashing en bucle). Diff `7b44972..HEAD`. `scope-check`: fuera de alcance solo ficheros sin seguimiento de herramientas y los artefactos PM de la propia iniciativa (commit 7b44972), sin código. Suite 841 passed.

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|---|
| I1 | C (+D) | Important | Límite del reto 2FA esquivable: clave `usuario+IP` (rotar IP/IPv6 da 5 intentos nuevos por IP) y `tooManyAttempts`/`hit` no atómicos (ráfaga concurrente pasa N intentos) — CWE-307/367 | T-03 | corregido (bf54aed): limitador por cuenta `2fa:{id}` con `RateLimiter::increment` antes de verificar (TOTP y recuperación); `clear` al acertar. Tests: rotación de IP (6.º bloqueado), recuperación, clear | `TwoFactorChallengeController.php:59,78,123-125` |
| I2 | A+B+C | Important | `validateCredentials()` perdió el `Timebox` de `SessionGuard::attempt` (enumeración de usuarios por tiempo, CWE-208) y el rehash de contraseña al entrar (`hashing.rehash_on_login`) | T-03 | corregido (bf54aed): `Timebox` (`auth.timebox_duration`) + `rehashPasswordIfRequired` si `hashing.rehash_on_login`. Tests de rehash con y sin 2FA (fallaban antes) | `LoginRequest.php:47-51`; `SessionGuard.php:444,539-543` |
| M1 | C | Minor | Secreto TOTP y códigos en `history.state` de Inertia (sin `encryptHistory`/`clearHistory` al salir) — CWE-525 | T-04/T-06 | corregido (T-04 fix): `Inertia::encryptHistory()` en el perfil + `Inertia::clearHistory()` en logout. Test M1 | `ProfileController.php:31-32`; `AuthenticatedSessionController.php:59` |
| M2 | C | Minor | Secreto pendiente en claro en `sessions.payload` (flash con `SESSION_ENCRYPT=false`) — CWE-312 | T-04 | corregido (T-04 fix): el secreto ya no va por flash; test M2 sobre la sesión | `Profile/TwoFactorController.php:40,56,67,123` |
| M3 | B | Minor | Un fallo de validación del FormRequest de confirmación pierde el QR pendiente | T-04/T-06 | corregido (T-04 fix): el perfil sirve QR/secreto mientras la activación esté a medias (estado del usuario). Test M3 | `TwoFactorConfirmRequest.php:19`; `Profile/TwoFactorController.php:45-57` |
| M4 | D | Minor | Código de recuperación inválido: 8 `Hash::check` (~1,2 s) dentro de `lockForUpdate`; con ráfaga, workers PHP-FPM ocupados | T-02/T-03 | corregido por I1 (bf54aed); los `Hash::check` siguen dentro de `lockForUpdate` (uso único) | `TotpService.php:116-121` |
| M5 | D | Minor | `regenerate` sin throttle: 8 `Hash::make` por petición | T-04 | corregido (T-04 fix): `throttle:10,1` en recovery-codes. Test M5 | `routes/auth.php:72` |
| M6 | D | Minor | Bloqueado, cada POST escribe auditoría y log | T-03 | corregido (bf54aed): lockout auditado solo al cruzar el umbral. Test M6 (1 lockout en 9 POST) | `TwoFactorChallengeController.php:59-60` |
| M7 | B | Minor | `URL.revokeObjectURL` justo tras `click()` puede abortar la descarga de los códigos | T-06 | corregido (T-06 fix): `revokeObjectURL` en `setTimeout` 1 s | `TwoFactorForm.vue` `downloadCodes` |
| M8 | A | Minor | «Sin desbordamiento a 375/360» marcado `[x]` sin evidencia (se valida en T-18) | T-06 | corregido: criterio trasladado a T-18 (ya lo cubre su criterio de 0 px) | `tasks.md:236` |
| M9 | B | Minor | No hay forma de cancelar una activación a medias | T-04 | corregido (T-04 fix): `DELETE /user/two-factor/pending` (auth+password.confirm, auditado `auth.2fa.setup_cancelled`) + botón «Cancelar activación». Tests M9 | — |

## Revisión de dos lentes — intento 2: Fase 1 (T-01..T-06) — gaps del intento 1 cerrados, 1 Important y 2 Minor nuevos corregidos

Lentes A+B+C+D re-evalúan solo lo corregido (`git diff 5cd81a3..HEAD`): I1, I2, M1–M9 → ✓ cerrados (I1 contrastado con `DatabaseStore::incrementOrDecrement` + `lockForUpdate` en MySQL; I2 con `SessionGuard::attempt`).

| # | Lente | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|---|
| N1 | A (+C) | Important | El arreglo de M3 servía el secreto TOTP pendiente en cada `GET /profile` (solo `auth`), sin `password.confirm`: una sesión robada leía la semilla — CWE-200/522 | T-04/T-06 | corregido: `ProfileController::edit` solo revela `twoFactorSetup` con `auth.password_confirmed_at` dentro de `auth.password_timeout`; si no, `twoFactor.setupLocked` y la UI pide «Generar otro QR» (que pasa por `password.confirm`) | `ProfileTest`: «una sesion sin confirmacion reciente… no puede leer el secreto pendiente (N1)» (sin confirmar y caducada) |
| N2 | C | Minor | `encryptHistory` en http (no localhost) cuelga la navegación: sin `crypto.subtle` Inertia no escribe el historial | T-04/T-06 | corregido: `encryptHistory` solo con `$request->secure()` o host localhost/127.0.0.1/[::1] | `ProfileTest`: «el historial cifrado solo se activa en contexto seguro (N2)» |
| N3 | B | Minor | `throttle:10,1` anónimo comparte contador por usuario con `reports.store` y `ai.suggest` | T-04 | corregido: limitador con nombre `two-factor-manage` (`2fa-manage:{id}`, 10/min) | `RateLimiter::limiter('two-factor-manage')` → `2fa-manage:999999 10`; test M5 actualizado |

**Evidencia (intento 2)**: `php artisan test` → 855 passed (6359 assertions); `vendor/bin/pint --dirty` ok; `npm run build` ok. Sin gaps Critical/Important abiertos: el bucle de la Fase 1 cierra en el intento 2.
