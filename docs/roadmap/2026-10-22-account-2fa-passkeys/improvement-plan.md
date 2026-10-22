---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-22T09:22:14Z
  fin: 2026-10-22T09:27:59Z
  fuente: medido          # ventana compartida con improvement-plan.md
  tokens_reales: { entrada: 14, salida: 57065, cache_creacion: 57151, cache_lectura: 725911 }
  eur: null               # precioTokens no fiable: ejecuta rates-verify
  horas_ia: 0.38
  duracion: 23m
  ratio_usado: 300000
---

# 2026-10-22-account-2fa-passkeys

> Plan de implementación de la seguridad de cuenta: doble factor opcional (TOTP + códigos de recuperación) y passkeys como login sin contraseña, sobre Breeze, sin tocar el MCP.

| | |
|---|---|
| **Fecha** | 2026-10-22 |
| **Estado** | en-progreso |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Alta |
| **Solicitante** | daycry (usuario del proyecto) |
| **Responsable** | daycry |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 ([ADR-003](../../knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md)) |
| **Plan de pruebas** | [`test-plan.md`](test-plan.md) |
| **Ledger** | [`tasks.md`](tasks.md) (20 tareas, fuente canónica de progreso) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **136,8h** (114h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **18,5h** (15,4h base +20 %) (+ 4,6h supervisión) | 0h | Baja |
| Coste total | **6.840 € + tokens** (tokens: ⚠️ verificar) | 0 € | Media |
| Tokens IA | **4,62M** (in 4,2M / out 0,42M) | 0 | Baja |
| Multiplicador productividad | **×5,9** | — | — |
| Tareas | **20** | 0 hechas | — |

Las cifras **heredan íntegras** las de la evaluación (`evaluation.md`): 44,4h / 54h / 38,4h por característica, 4,62M tokens, 136,8h totales. No hay diferencias que declarar; solo se reparte lo evaluado en tareas.

---

## Estimación por fase

Las horas incluyen el +20 % de contingencia (entre paréntesis, la base que suman las tareas de `tasks.md`).

| Fase | Estimado (h) | Tokens (in / out) | Coste € |
|------|-------------|-------------------|---------|
| Fase 1 — C-01 · TOTP + códigos de recuperación (7 tareas, T-01..T-07) | 44,4 (37 base) | 1,30M / 0,13M | 2.220 + tokens |
| Fase 2 — C-02 · Passkeys (7 tareas, T-08..T-14) | 54 (45 base) | 1,70M / 0,17M | 2.700 + tokens |
| Fase 3 — C-03 · E2E, accesibilidad, documentación y cierre (6 tareas, T-15..T-20) | 38,4 (32 base) | 1,20M / 0,12M | 1.920 + tokens |
| **Total** | **136,8h** (114 base) | **4,2M / 0,42M** | **6.840 € + tokens** |

**Por qué la UI se reparte dentro de cada fase.** La evaluación agrupa «UI, E2E, a11y y documentación» en C-03, pero aquí la UI de cada característica va en su fase (T-06 en la fase 1 y T-13 en la fase 2). Así el 2FA se puede verificar de extremo a extremo (backend + pantalla) y pasar su revisión de seguridad (T-07) antes de abrir las passkeys, y el riesgo de regresión del login se aísla en la fase donde ocurre. C-03 conserva lo transversal: infraestructura E2E sobre `localhost`, los specs, axe, documentación y cierre. **Diferencia declarada con la evaluación:** los totales por fase (44,4h / 54h / 38,4h) y el global (136,8h) coinciden con la evaluación; lo que cambia es el contenido de cada bolsa, porque la UI de T-06 (9h) y T-13 (11h) se carga a C-01 y C-02 en lugar de a C-03. Para no inflar el total, el resto de cada fase se ajustó a ese reparto (p. ej. C-03 queda en 32h base de infraestructura E2E, specs, axe, documentación y cierre).

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Todos los importes en **EUR**.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h ⚠️ verificar | Default compartido. No existe `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 ⚠️ verificar | Default de `estimation-defaults.md` |
| Precio input | ⚠️ verificar € / 1M tokens | Sin precio fiable: ejecutar la skill `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € ⚠️ verificar | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre horas base humanas e IA |
| Ratio tokens→hora-IA | 300.000 tok/h ⚠️ no calibrado | No existe `docs/roadmap/CALIBRATION.md`. Tokens facturables = entrada + creación de caché + salida |
| Supervisión humana | 25 % de las horas IA | Default compartido |
| Reparto de horas por tarea | Las 114h base de la evaluación repartidas en 20 tareas | De ellas, 12h son revisión de seguridad o UX según la evaluación (aquí T-07 4h y T-14 5,5h, más la parte de UX de T-06, T-13 y T-18) |
| Tokens por tarea | Proporcional a las horas de la tarea dentro de su fase | In 91 % / out 9 %, como la evaluación |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 114h × 50 €/h | 5.700 € |
| Margen de contingencia | +20 % sobre desarrollo base (22,8h) | 1.140 € |
| Tokens IA (input) | 4,2M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,42M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño + plan) | ~250k tok facturables (≈ 0,8h-IA), ya medido en `generacion:` de cada artefacto | ⚠️ verificar |
| **Total estimado (con margen)** | | **6.840 € + tokens (⚠️ verificar)** |

---

## Previsión de tokens (por fase)

Estimación del consumo de tokens del modelo por fase. Base: claude-opus-4-8 ⚠️ verificar · precios pendientes de `rates-verify`.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — C-01 | 1.302.000 | 128.000 | 1.430.000 | ⚠️ verificar |
| Fase 2 — C-02 | 1.702.000 | 168.000 | 1.870.000 | ⚠️ verificar |
| Fase 3 — C-03 | 1.201.000 | 119.000 | 1.320.000 | ⚠️ verificar |
| **Total** | **4.205.000** | **415.000** | **4.620.000** | **⚠️ verificar** |

**Método de estimación:** el de la evaluación (≈ 40k tokens facturables por hora humana base, out ≈ 10 % del in), repartido por tarea en proporción a sus horas dentro de la fase. Hay más lectura de contexto en T-03 (partir el login), T-12 (vector WebAuthn) y T-13 (UI).

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 136,8 h (114 h base +20 %) |
| Horas IA (ejecución) | 18,5 h (15,4 h base +20 %; 4,62M tok ÷ 300.000 tok/h, supuesto) |
| Supervisión humana | 4,6 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **23,1 h** |
| Horas ahorradas | 113,7 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,71 |

> Las horas IA son una estimación aproximada (supuesto sin calibrar). Las 12 h de revisión de seguridad o UX de la evaluación no se comprimen: la supervisión humana real de T-07 y T-14 es cercana a la estimación humana.

---

## Resumen ejecutivo

Se añade seguridad de cuenta a la app: cada usuario podrá activar un doble factor opcional (código de 6 dígitos de una app de autenticación, con 8 códigos de recuperación de un solo uso) y registrar varias passkeys para entrar sin contraseña con huella, Face ID o Windows Hello. Todo se gestiona desde el perfil. Se construye sobre Breeze (opción O1 del diseño: `pragmarx/google2fa` propio + `laravel/passkeys` aislado tras un gateway) sin cambiar el acceso de los agentes al MCP por API key.

### Objetivos

- Reto 2FA operativo en el login con rate limit de 5/min, caducidad de 5 min y antirreplay del TOTP (CA-04..CA-09).
- Passkeys *discoverable* con verificación de usuario obligatoria: alta, renombrado, borrado y login sin contraseña ni 2FA (CA-13..CA-21).
- 0 cambios en `routes/ai.php`, `McpGuard`, `config/sanctum.php` y `ApiKeyController`, y los tests de `tests/Feature/Mcp/*` sin tocar (CA-22).
- Las 24 condiciones de la spec (CA-01..CA-24) cubiertas por Pest, E2E o un M-xx del `test-plan.md`.

### Decisiones del diseño que el plan respeta

1. Sin `WEBAUTHN_RP_NAME`: el nombre del RP sale del RP ID (T-08).
2. Códigos de recuperación con hash, uno por código; se muestran una sola vez (T-02, T-04).
3. Se rota `remember_token` al activar el 2FA (T-04).
4. `throttle:10,1` por IP en el login con passkey, en lugar del `6,1` del paquete (T-11).

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (spec aprobada, decisiones confirmadas el 2026-10-22)
- [x] **Alcance** cerrado (spec § Alcance: dentro y fuera)
- [x] **Criterios de éxito / métricas** acordados (CA-01..CA-24)
- [x] **Accesos y credenciales** necesarios: ninguno externo; para M-01/M-02 hace falta el dominio ngrok vigente
- [x] **Entornos** disponibles: local (Laragon), E2E en `http://localhost:8080`, ngrok para passkeys reales; producción en Hestia
- [x] **Stakeholders** identificados: el usuario valida el plan y ejecuta los M-xx
- [x] **Dependencias externas** mapeadas: `pragmarx/google2fa`, `bacon/bacon-qr-code`, `laravel/passkeys` y `@laravel/passkeys` (dry-run del diseño: sin conflictos ni advisories)
- [x] **Restricciones** conocidas: WebAuthn exige contexto seguro y liga la passkey al RP ID; `laravel/passkeys` es pre-1.0; no tocar el MCP
- [ ] **Tarifa/hora y supuestos de coste** confirmados (50 €/h por defecto; precio de tokens ⚠️ verificar con `rates-verify`)

---

## Análisis de impacto

- **`app/Http/Requests/Auth/LoginRequest.php`** y **`app/Http/Controllers/Auth/AuthenticatedSessionController.php`**: `authenticate()` se parte en limitar + validar credenciales sin abrir sesión; `store` decide entre abrir sesión y aplazar al reto. Es la zona de mayor riesgo de regresión.
- **`app/Models/User.php`**: cast `encrypted`, `#[Hidden]`, `PasskeyUser`, relación de passkeys y borrado en cascada.
- **`app/Services/TwoFactor/`** (nuevo) y **`app/Services/Passkeys/`** (nuevo): TOTP en casa y gateway único hacia `Laravel\Passkeys\*`.
- **`routes/auth.php`**: reto 2FA, login con passkey (`guest`, `throttle:10,1`), gestión de 2FA y passkeys (`auth` + `password.confirm`). No se toca `routes/web.php`.
- **`app/Providers/AppServiceProvider.php`**: `Passkeys::ignoreRoutes()` y listeners de auditoría.
- **`resources/js/Pages/Auth/*` y `Profile/*`**: reto, botón de passkey y tarjeta «Seguridad».
- **`database/migrations/`**: dos migraciones nuevas con fecha posterior a `2026_11_03_*`.
- **`tests/e2e/*` y `playwright.config.ts`**: la batería pasa a `http://localhost:8080`.
- **Sin cambios:** `routes/ai.php`, `app/Http/Middleware/McpGuard.php`, `config/sanctum.php`, `ApiKeyController` y `app/Services/AuditLog.php` (solo se llama a `record(..., channel: 'web')`).

---

## Cambios arquitectónicos

- **Opción O1 del diseño** ([ADR-003](../../knowledge/adr/ADR-003-2fa-totp-propio-y-laravel-passkeys.md)): Breeze sigue dueño del login; TOTP propio sobre `pragmarx/google2fa` ^9.1 con QR SVG de `bacon/bacon-qr-code` ^3.1; passkeys con `laravel/passkeys ~0.2.1` sin sus rutas (`ignoreRoutes()`) y controladores propios sobre sus Actions. Este plan no rediseña nada: implementa la tabla de impacto de §5 del diseño.
- **Antirreplay en columna** (`users.two_factor_last_used_at`), no en cache, para sobrevivir a una limpieza de cache.
- **`PasskeyGateway`** como único punto de contacto con el paquete 0.x; el ADR obliga a revisar el gateway al subir de versión.
- **Mismas URL que espera el cliente** (`/passkeys/login/options`, `/passkeys/login`, `/user/passkeys/options`, `/user/passkeys`), así `@laravel/passkeys/vue` funciona sin configuración.
- **Columnas con los nombres de Fortify**, para que migrar a Fortify en el futuro sea casi directo.
- **E2E sobre `localhost:8080`** con `WEBAUTHN_RP_ID=localhost`: WebAuthn no admite IP como RP ID.

---

## Archivos a crear/modificar

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `composer.json`, `composer.lock`, `package.json`, `package-lock.json` | Modificar | `google2fa`, `bacon-qr-code`, `laravel/passkeys ~0.2.1`, `@laravel/passkeys ~0.4.0` |
| `database/migrations/2026_11_04_090000_add_two_factor_columns_to_users_table.php` | Crear | Columnas de 2FA en `users` (fecha: siguiente libre tras `2026_11_03_*`) |
| `database/migrations/2026_11_04_090100_create_passkeys_table.php` | Crear | Tabla `passkeys` con `name` de 100 caracteres |
| `app/Models/User.php` | Modificar | Casts, `#[Hidden]`, `PasskeyUser`, cascada |
| `app/Services/TwoFactor/TotpService.php` | Crear | Secreto, QR, verificación ±1, antirreplay, códigos con hash |
| `app/Services/Passkeys/PasskeyGateway.php` | Crear | Aislamiento del paquete 0.x |
| `app/Http/Requests/Auth/LoginRequest.php` | Modificar | Validar credenciales sin abrir sesión |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Modificar | Aplazar el login al reto |
| `app/Http/Controllers/Auth/TwoFactorChallengeController.php` | Crear | Reto 2FA |
| `app/Http/Controllers/Auth/PasskeyLoginController.php` | Crear | Login con passkey |
| `app/Http/Controllers/Profile/TwoFactorController.php`, `PasskeyController.php` | Crear | Gestión desde el perfil |
| `app/Http/Controllers/ProfileController.php` | Modificar | Props de seguridad para Inertia |
| `app/Console/Commands/UserTwoFactorReset.php` | Crear | `user:two-factor-reset {email}` |
| `app/Providers/AppServiceProvider.php`, `app/Listeners/AuditPasskeyEvents.php` | Modificar / Crear | `ignoreRoutes()` y auditoría |
| `config/passkeys.php`, `config/auth.php` | Crear / Modificar | RP ID, orígenes, `challenge_ttl` |
| `routes/auth.php` | Modificar | Rutas nuevas |
| `resources/js/Pages/Auth/TwoFactorChallenge.vue`, `Login.vue` | Crear / Modificar | Reto y botón de passkey |
| `resources/js/Pages/Profile/Edit.vue`, `Partials/TwoFactorForm.vue`, `Partials/PasskeysForm.vue` | Modificar / Crear | Tarjeta «Seguridad» |
| `resources/js/Composables/useSecureContext.js` | Crear | Aviso de contexto no seguro |
| `.env.example`, `.env.production.example` | Modificar | `WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS`, `PASSKEYS_USER_HANDLE_SECRET` |
| `tests/Unit/*`, `tests/Feature/Auth/*`, `tests/Fixtures/passkeys/` | Crear / Modificar | Pest (ver `tasks.md`) |
| `playwright.config.ts`, `tests/e2e/helpers.ts`, `global-setup.ts`, `global-teardown.ts`, `security.spec.ts`, `a11y.spec.ts` | Modificar / Crear | E2E sobre `localhost:8080`, TOTP, CDP y axe |
| `docs/usuario/guia-de-usuario.md`, `docs/modulos/despliegue.md`, `docs/guias/desarrollo-local.md` | Modificar | Guía de usuario y de operación |
| `docs/knowledge/adr/ADR-003-*.md` | Modificar | `propuesta` → `aceptada` |

---

## Dependencias y prerequisitos

- Spec aprobada, evaluación en `go` y diseño aprobado (O1), todos de esta carpeta.
- Rama de trabajo propia (la actual es `feature/social-scheduler`; no mezclar con la paridad Metricool).
- PHP local 8.3 con `openssl`, `sodium` y `bcmath` (verificado). **En Hestia hay que comprobar `php -m | grep sodium`** (T-19 y M-04).
- Para M-01/M-02 hace falta el dominio ngrok vigente (HTTPS) y un equipo con Windows Hello o un móvil.
- Orden fijo: C-01 → C-02 → C-03. T-08 depende de la revisión T-07; T-15 depende de T-14.

---

## Criterios de aceptación (global)

- [ ] `php artisan test` pasa completo (796 + los nuevos) sin desactivar ni modificar tests existentes, salvo el flujo de login documentado en `AuthenticationTest`.
- [ ] CA-01..CA-24 de la spec cubiertos: Pest en las fases 1 y 2, E2E en la fase 3 y M-xx entregados como checklist.
- [ ] `git diff --stat -- routes/ai.php app/Http/Middleware/McpGuard.php config/sanctum.php app/Http/Controllers/ApiKeyController.php` vacío, y `tests/Feature/Mcp` en verde.
- [ ] Un `SELECT` sobre `users` no muestra secretos en claro, `$user->toArray()` no expone campos de 2FA y ninguna fila de `audit_logs` ni línea de log contiene secretos ni material WebAuthn.
- [ ] `npm run build`, `vendor/bin/pint --test` y `npx playwright test` (desktop, mobile y mobile-360) en verde.
- [ ] axe sin violaciones `serious`/`critical` en Login, reto 2FA y Perfil → Seguridad, y 0 px de desbordamiento a 375 y 360 px.
- [ ] `.env.example` documenta `WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS` y `PASSKEYS_USER_HANDLE_SECRET` (y no `WEBAUTHN_RP_NAME`), y la guía de operación explica el RP ID en Hestia y la rotación de ngrok.

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Regresión del login clásico al partir `authenticate()` | Media | Alto | TDD: `tests/Feature/Auth/*` en verde antes y después, caso «sin 2FA entra igual» (T-03) y revisión en T-07 |
| `laravel/passkeys` 0.x rompe API o esquema | Media | Medio | `~0.2.1` fijado, `PasskeyGateway` único punto de contacto, test de ceremonia (T-12), ADR obliga a revisar al subir de versión |
| Vector WebAuthn grabado inestable en Pest (T-12) | Media | Medio | Plan B: `bind` de la Action y ceremonia real solo en E2E (+3-4 h, dentro del margen) |
| Rotar `APP_KEY` sin `APP_PREVIOUS_KEYS` pierde los secretos TOTP, o cambia el `user_handle_secret` | Baja | Alto | `PASSKEYS_USER_HANDLE_SECRET` explícito desde T-08; documentar en T-19; `user:two-factor-reset` como salida |
| `password.confirm` responde 423 a un `fetch` de registro | Media | Bajo | La UI confirma la contraseña antes de abrir el alta (T-13) |
| Pasar el E2E a `localhost` rompe cookies o URLs de la batería actual | Media | Bajo | T-15 verifica la batería existente antes de escribir los specs nuevos |
| Falta `sodium` en el PHP de Hestia | Baja | Medio | `php -m` en el servidor (M-04); `paragonie/sodium_compat` como alternativa |
| Contador de firmas a 0 en passkeys sincronizadas | Baja | Medio | `webauthn-lib` lo tolera; test con contador 0 (T-11, T-12) |
| Passkeys de ngrok mueren al rotar el dominio | Media | Bajo | Aceptado en desarrollo; se documenta (T-19) |

---

## Métricas de éxito

- 24/24 criterios de aceptación de la spec verificados (automáticos o M-xx firmados).
- 0 regresiones: los ~796 tests existentes siguen en verde y los 8 ficheros de `tests/Feature/Auth` solo cambian en lo que documenta el nuevo flujo.
- Desviación de horas reales frente a la estimación ≤ 20 % (el margen), medida en `tasks.md`.
- Un usuario con 2FA completa el login con reto en ≤ 2 pantallas; un usuario con passkey entra en 1 gesto.

---

## Decisiones y preguntas que el plan deja abiertas

- **Reparto de la UI** entre fases (T-06 y T-13): decisión del planner, justificada arriba; el total evaluado no cambia.
- **Rama de trabajo**: se propone una rama nueva desde `main` o desde la de paridad, a decidir antes de T-01.
- **ADR-003** pasa a `aceptada` en T-19 con el visto bueno del usuario.
- **Spec desalineada**: la spec aún menciona `WEBAUTHN_RP_NAME`; manda el diseño. Si se quiere alinear el texto lo hace `analyst` (el planner no edita el cuerpo de la spec).
- **Precio de tokens** y tarifa: `⚠️ verificar` con `rates-verify`.

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-22 | Creación del plan: 3 fases, 20 tareas, 136,8h (114h base), `test-plan.md` con E2E-01..07, API-01, A11Y-01 y M-01..06 | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre una rama, marcando `tasks.md` como **ledger canónico** (checkbox + estado por tarea). Al terminar, handoff a `qa` (que ejecuta los E2E-xx del `test-plan.md` y guía los M-xx) y cierre con `documenter`.
