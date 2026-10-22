---
generacion:
  inicio: 2026-10-22T08:06:23Z
  fin: 2026-10-22T08:15:25Z
  fuente: medido          # incluye también la redacción de spec.md (misma ventana, sin solapar marcadores)
  tokens_reales: { entrada: 10000, salida: 44058, cache_creacion: 99351, cache_lectura: 1442682 }
  eur: null             # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.51
  duracion: 31m
  ratio_usado: 300000          # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# 2026-10-22-account-2fa-passkeys

> Evaluación y presupuesto de la seguridad de cuenta: 2FA opcional (TOTP + códigos de recuperación) y passkeys como login sin contraseña, para decidir go/no-go y el orden de construcción antes del paso de diseño.

| | |
|---|---|
| **Fecha** | 2026-10-22 |
| **Estado** | completado |
| **Prioridad global** | Alta |
| **Solicitante** | daycry (usuario del proyecto) |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **Características evaluadas** | 3 |

---

## Cuadro de mando

| Métrica | Total estimado | Confianza |
|--------|----------------|-----------|
| Esfuerzo humano | **136,8h** (114h base +20 %) | Media |
| Tiempo IA (ejecución) | **18,5h** (+ 4,6h supervisión) | Baja |
| Coste | **6.840 € + tokens** (tokens: ⚠️ verificar) | Media |
| Tokens IA | **4,62M** (in 4,2M / out 0,42M) | Baja |
| Multiplicador productividad | **×5,9** | — |
| Características | **3** | — |

> Las horas humanas equivalentes (lo que costaría a una persona, base del valor) y las horas IA (lo que tardará el ciclo, base del plazo) son magnitudes distintas y no se suman (LES-007). Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).

---

## Resumen ejecutivo

El usuario pide doble factor opcional (TOTP + códigos de recuperación) y passkeys como login sin contraseña que sustituye a contraseña + 2FA, con varias passkeys por usuario gestionables desde el perfil. Se presupuesta en 3 características: C-01 TOTP + recuperación (44,4h), C-02 Passkeys (54h) y C-03 UI, E2E, accesibilidad y documentación (38,4h). En total, **136,8h con margen ≈ 6.840 € + tokens**. El recon confirma que no hay ninguna dependencia previa, que el login de Breeze abre sesión dentro de `LoginRequest::authenticate()` (hay que partirlo) y que el MCP (Bearer de Sanctum, `guard => []`) está desacoplado del login web. El riesgo dominante es WebAuthn: RP ID ligado al dominio, contexto seguro, ngrok rotatorio, una IP no válida como RP ID en E2E y la madurez de la librería. Recomendación: **go**, empezando por C-01.

---

## Requerimientos recibidos

Mapa de la spec a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | 2FA TOTP + códigos de recuperación (backend, reto en el login, auditoría, comando de operador) | spec § Flujo «Activar 2FA» / «Login con 2FA», CA-01..CA-12, CA-22 | Claro (nº de códigos y «recordarme» como supuestos) |
| C-02 | Passkeys: registro, renombrado y borrado múltiples, y login sin contraseña | spec § Flujo «Login con passkey» / «Gestionar passkeys», CA-13..CA-21, § Supuestos (RP ID) | Claro en lo funcional, ambiguo en la librería (decide el diseño) |
| C-03 | UI (Perfil → Seguridad, Login, reto), E2E Playwright, axe y documentación | spec § Arquitectura (páginas Vue), § Pruebas, CA-20, CA-23, CA-24, criterios de proceso | Claro |

**Ambigüedades / información que falta:**

- **Librería de passkeys** sin elegir: `laravel/passkeys` (oficial, pre-1.0), `laragear/webauthn` 5.x o `spatie/laravel-passkeys` (UI Livewire). Afecta a ±8h en C-02.
- **Librería TOTP:** Fortify choca con las rutas de Breeze. Se presupuesta con `pragmarx/google2fa` + `bacon/bacon-qr-code` directos. Si el diseño elige Fortify completo, hay que sumar el trabajo de migrar el login (supuesto).
- **Testabilidad de WebAuthn en Pest:** depende de las utilidades de la librería. Sin ellas, hay que fabricar vectores de prueba (+4-6h).
- Supuestos de la spec por confirmar: 8 códigos de recuperación, que «recordarme» no vuelva a pedir el 2FA, no cerrar otras sesiones y tolerar el contador de firmas a 0 (passkeys sincronizadas).
- Extensiones `sodium` y `openssl` en el PHP de Hestia: ⚠️ verificar.

---

## Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades (las 3 decisiones del usuario están cerradas; quedan los supuestos menores listados arriba)
- [x] **Alcance** de cada característica acotado (qué entra y qué NO): spec § Alcance
- [x] **Criterios de aceptación / éxito** por característica: CA-01..CA-24 + criterios de proceso
- [x] **Restricciones** (técnicas): RP ID y origen por entorno, contexto seguro, secretos cifrados, MCP intacto
- [ ] **Dependencias externas** identificadas: librería de passkeys y TOTP pendiente del diseño, extensiones PHP en Hestia sin verificar
- [x] **Contexto técnico** del proyecto disponible: recon del repo (rutas en la spec § Referencias)
- [ ] **Tarifa/hora y supuestos de coste** confirmados: no existe `.claude/rates.json`, se usan los defaults (⚠️ verificar)

---

## Supuestos económicos (ajustables)

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**.

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h ⚠️ verificar | Default compartido. No existe `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 ⚠️ verificar | Default de `estimation-defaults.md`, base de la previsión de tokens |
| Precio input | ⚠️ verificar € / 1M tokens | No hay precio fiable configurado: ejecuta la skill `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem. Coste tokens = (4,2M × P_in + 0,42M × P_out) |
| Tipo de cambio | 1 USD = 0.92 € ⚠️ verificar | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre horas base (humanas e IA) |
| Ratio tokens→hora-IA | 300.000 tok/h ⚠️ no calibrado | No existe `docs/roadmap/CALIBRATION.md`. Tokens facturables = entrada + creación de caché + salida |
| Supervisión humana | 25 % de las horas IA | Default compartido |
| Escala de tokens | ≈ 40k tok facturables por hora humana base | Misma proporción que la evaluación de `social-scheduler` (20,9M in / 2,09M out para 606h). Out ≈ 10 % del in |

**Método de las horas:** desglose por tarea en cada característica (ver los bloques). Cada una incluye una **línea propia de revisión de seguridad** (LES-001/LES-009: la revisión es la partida grande en código de producto) y los tests al ritmo TDD (LES-005: encarece la ejecución y abarata la revisión).

---

## Evaluación por característica

### C-01 — 2FA TOTP + códigos de recuperación

- **Requisito origen**: spec § Flujo «Activar el 2FA» y «Login con 2FA», CA-01..CA-12, CA-22
- **Descripción**: secreto TOTP cifrado por usuario, activación con QR y código de confirmación, y desactivación con contraseña + código. Reto 2FA en el login con rate limit, caducidad y antirreplay. 8 códigos de recuperación de un solo uso y regenerables. Auditoría `auth.2fa.*` y comando `user:two-factor-reset`.
- **Complejidad**: Media-Alta
- **Esfuerzo**: 37h base → **44,4h** con margen · confianza Media
  - Integración `pragmarx/google2fa` + `bacon/bacon-qr-code` y servicio TOTP (ventana ±1, antirreplay): 4h
  - Migración en `users` + casts `encrypted` + `#[Hidden]`: 1h
  - Activar, confirmar y desactivar (controladores, `password.confirm`, validación): 6h
  - Reto 2FA: partir `LoginRequest::authenticate()` (validar sin `Auth::attempt`), estado pendiente en sesión, «recordarme», caducidad 5 min, rate limit 5/min, `intended`: 8h
  - Códigos de recuperación (un solo uso, regeneración, guardado con hash o cifrado): 3h
  - Reset de contraseña con 2FA (verificar que no abre sesión) + comando de operador: 2h
  - Auditoría `auth.2fa.*` sin secretos: 1h
  - Tests Pest (~25 casos: CA-01..12, 22, no-fuga en `audit_logs`): 8h
  - Revisión de seguridad (línea propia): 4h
- **Previsión IA**: 1,30M in / 0,13M out tok · ⚠️ verificar € (= 1,30M × P_in + 0,13M × P_out) · ≈ 4,8h-IA base (5,7h con margen)
- **Coste**: (44,4h × 50 €/h) + tokens = **2.220 € + tokens**
- **Impacto / áreas afectadas**: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Models/User.php`, `routes/auth.php`, `routes/web.php` (hotspot: 21 cambios/90 d según `code-health`, así que hay riesgo de conflicto de merge, no de complejidad), `database/migrations/*`, `app/Services/AuditLog.php` (solo uso), `app/Console/Commands/*`, `tests/Feature/Auth/*`
- **Dependencias y prerequisitos**: elegir la librería TOTP en el diseño. Ninguna dependencia de C-02.
- **Riesgos**: romper el login actual al partir `authenticate()` (lo mitigan los 4 tests de `AuthenticationTest` + los nuevos). Rotar `APP_KEY` sin `APP_PREVIOUS_KEYS` pierde los secretos. Si el diseño elige Fortify completo, choque de rutas con Breeze (+8-12h).
- **Incógnitas / preguntas abiertas**: ¿8 códigos? ¿Guardarlos con hash (no se pueden volver a mostrar) o cifrados? ¿Rotar `remember_token` al activar?

### C-02 — Passkeys (registro múltiple, renombrado, borrado y login sin contraseña)

- **Requisito origen**: spec § Flujo «Login con passkey» y «Gestionar passkeys», CA-13..CA-21, § Supuestos (RP ID y contexto seguro)
- **Descripción**: varias credenciales WebAuthn *discoverable* por usuario, con `userVerification: required`. Alta, renombrado y borrado con `password.confirm`. Login como `guest` sin contraseña ni 2FA. RP ID y orígenes configurables por entorno, throttle y auditoría `auth.passkey.*`.
- **Complejidad**: Alta
- **Esfuerzo**: 45h base → **54h** con margen · confianza Media-Baja
  - Spike de librería sobre el `composer.lock` real (`laravel/framework v13.37.0`, PHP 8.3, Inertia + Vue): `laravel/passkeys` 0.2.1 vs `laragear/webauthn` 5.0.2: 4h
  - Migración y modelo `passkeys` (o la de la librería), relación con `User` y borrado en cascada: 1h
  - Config de RP ID y orígenes (`WEBAUTHN_*`), derivación desde `APP_URL`, encaje con `trustHosts()` y ngrok: 3h
  - Ceremonia de registro (opciones, verificación, `excludeCredentials`, nombre): 5h
  - Listado, renombrado y borrado con aislamiento entre usuarios (404 ajeno): 3h
  - Login con passkey (opciones `guest`, reto de un solo uso en sesión, verificación, contador, `Auth::login`, regenerar sesión, saltar el 2FA): 8h
  - Cliente JS de la ceremonia (base64url, errores `NotAllowedError`, detección de soporte y de contexto seguro): 4h
  - Throttle 10/min + auditoría `auth.passkey.*`: 2h
  - Tests Pest (vectores de *attestation* y *assertion* o utilidades de la librería, CA-13..19, 21): 10h
  - Revisión de seguridad (línea propia; es lo más delicado de la iniciativa): 5h
- **Previsión IA**: 1,70M in / 0,17M out tok · ⚠️ verificar € (= 1,70M × P_in + 0,17M × P_out) · ≈ 6,2h-IA base (7,5h con margen)
- **Coste**: (54h × 50 €/h) + tokens = **2.700 € + tokens**
- **Impacto / áreas afectadas**: `composer.json`, `package.json`, `app/Models/User.php`, `routes/auth.php`, `routes/web.php`, `config/*` (nuevo `webauthn` o el de la librería), `.env.example`, `bootstrap/app.php` (solo lectura: `trustHosts`), `database/migrations/*`, `resources/js/*` (cliente WebAuthn), `tests/Feature/Auth/*`
- **Dependencias y prerequisitos**: decisión de librería en el diseño. Para el reto 2FA y su bypass, conviene que C-01 haya refactorizado ya `AuthenticatedSessionController` (orden C-01 → C-02).
- **Riesgos**:
  - Madurez: `laravel/passkeys` es pre-1.0 (0.2.x) y puede romper la API.
  - RP ID: las passkeys mueren al rotar ngrok, `http://metricool.test` no sirve, y la IP `127.0.0.1` del E2E no es un RP ID válido.
  - Contador de firmas a 0 en passkeys sincronizadas (iCloud o Google): si se valida mal, bloquea a usuarios legítimos.
  - Tests de WebAuthn caros sin utilidades de la librería (+4-6h).
- **Incógnitas / preguntas abiertas**: librería final; si `sodium` y `openssl` están en el PHP de Hestia; si se quiere autocompletado condicional en el campo email (incluido como mejora progresiva, no bloqueante).

### C-03 — UI, E2E, accesibilidad y documentación

- **Requisito origen**: spec § Arquitectura (páginas Vue), § Pruebas, CA-20, CA-23, CA-24, criterios de proceso (axe, 375/360, `.env.example`, guía)
- **Descripción**: tarjeta **Seguridad** en `Profile/Edit.vue` (`TwoFactorForm.vue` con estados apagado, configurando y activo, más los códigos; `PasskeysForm.vue` con lista, alta, renombrado y borrado). Página `Auth/TwoFactorChallenge.vue` y botón «Entrar con passkey» en `Login.vue`. Batería Playwright con TOTP real y autenticador virtual de Chromium, axe en 3 viewports, y documentación de usuario y de operación.
- **Complejidad**: Media
- **Esfuerzo**: 32h base → **38,4h** con margen · confianza Media
  - `TwoFactorForm.vue` (QR con alt, secreto copiable, confirmación, códigos con copiar y descargar, desactivar y regenerar): 5h
  - `PasskeysForm.vue` (lista, alta con nombre, renombrar en línea, borrar con modal, aviso de contexto no seguro): 3h
  - `TwoFactorChallenge.vue` + cambios en `Login.vue` (botón de passkey, `aria-live`, `one-time-code`): 5h
  - Playwright: helper TOTP RFC 6238 en Node, usuario E2E dedicado + teardown, recorrido del 2FA, CDP `WebAuthn.addVirtualAuthenticator` para las passkeys, servidor E2E en `http://localhost:8080` con `WEBAUTHN_RP_ID=localhost`: 10h
  - axe + desbordamiento en desktop, 375 y 360 sobre las 3 pantallas: 3h
  - Documentación (`docs/usuario/`, guía de operación del RP ID en Hestia y ngrok, `.env.example`): 3h
  - Revisión (UX, a11y y E2E, línea propia): 3h
- **Previsión IA**: 1,20M in / 0,12M out tok · ⚠️ verificar € (= 1,20M × P_in + 0,12M × P_out) · ≈ 4,4h-IA base (5,3h con margen)
- **Coste**: (38,4h × 50 €/h) + tokens = **1.920 € + tokens**
- **Impacto / áreas afectadas**: `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Profile/Partials/*`, `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Auth/TwoFactorChallenge.vue` (nuevo), `tests/e2e/*` (`helpers.ts`, `global-setup.ts`, `global-teardown.ts`, `a11y.spec.ts`, nuevo `security.spec.ts`), `playwright.config.ts`, `docs/usuario/*`, `.env.example`
- **Dependencias y prerequisitos**: endpoints de C-01 y C-02. La parte de 2FA de la UI y el E2E puede ir en paralelo con C-02.
- **Riesgos**: el autenticador virtual solo existe en Chromium (los 3 proyectos Playwright ya son Chromium, así que no bloquea). Cambiar el `baseURL` a `localhost` puede afectar a las cookies o al `trustHosts` del resto de la batería: hay que verificarlo. El usuario demo no debe quedarse con el 2FA activo.
- **Incógnitas / preguntas abiertas**: si el E2E de passkeys corre en todos los viewports o solo en desktop (presupuestado en todos); formato de descarga de los códigos (`.txt`).

---

## Comparativa

Ordenada por orden de construcción recomendado.

| # | Característica | Complejidad | Horas | Coste € | Tokens | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-01 | 2FA TOTP + códigos de recuperación | Media-Alta | 44,4h | 2.220 € + tokens | 1,43M | Alta | Media |
| C-02 | Passkeys (CRUD + login sin contraseña) | Alta | 54h | 2.700 € + tokens | 1,87M | Alta | Media-Baja |
| C-03 | UI, E2E, a11y y documentación | Media | 38,4h | 1.920 € + tokens | 1,32M | Alta | Media |
| | **Total** | | **136,8h** | **6.840 € + tokens** | **4,62M** | | |

---

## Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 114h × 50 €/h (de las cuales 12h son revisión de seguridad o UX: 4 + 5 + 3) | 5.700 € |
| Margen de contingencia | +20 % sobre desarrollo base (22,8h) | 1.140 € |
| Tokens IA (input) | 4,2M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,42M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño + plan) | ~250k tok facturables estimados (≈ 0,8h-IA). Esta evaluación queda medida en `generacion:` (LES-008) | ⚠️ verificar |
| **Total estimado (con margen)** | | **6.840 € + tokens (⚠️ verificar)** |

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 136,8 h (114h base) |
| Horas IA (ejecución) | 18,5 h (15,4h base = 4,62M tok ÷ 300.000 tok/h) |
| Supervisión humana | 4,6 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **23,1 h** |
| Horas ahorradas | 113,7 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,71 |

> Horas mostradas **con el margen de contingencia (+20 %)** ya aplicado sobre las horas base (humanas e IA). Las horas IA son una **estimación no calibrada**: en el histórico de los kits, las horas IA previstas se desviaron entre −77 % y −96 % y las humanas nunca se han validado (LES-007). Úsalas para el plazo, no para el valor. La supervisión del 25 % es probablemente baja para código de autenticación: si se presupuesta la revisión adversarial aparte, sube a ~8h.

---

## Recomendación

- **Veredicto**: **go**, con la condición de resolver en el paso de diseño (ya previsto en el flujo) la librería de passkeys y la estrategia de TOTP (google2fa directo vs. Fortify) antes de planificar C-02.
- **Quick wins** (bajo coste, alto valor): C-01. Es la mitad del valor de seguridad, funciona en todos los entornos (incluido `http://metricool.test`) y no depende de WebAuthn.
- **Costosas / a valorar**: C-02, por la incertidumbre de la librería pre-1.0, el RP ID por entorno y los tests de WebAuthn.
- **Orden sugerido**: C-01 → C-02 → C-03. C-01 refactoriza el login, del que dependen el bypass del 2FA y el reto. La parte de UI y E2E del 2FA en C-03 puede arrancar en paralelo a C-02.
- **Fuera de alcance recomendado**: 2FA obligatorio por política, SMS o email, cuentas solo-passkey, dispositivos de confianza y cierre de otras sesiones (spec § Alcance).

---

## Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Regresión del login clásico al partir `LoginRequest::authenticate()` | Media | Alto | TDD: los tests de `tests/Feature/Auth/*` en verde antes y después, y casos nuevos para usuario sin 2FA (CA-04 negativo) |
| RP ID y contexto seguro: passkeys inválidas al rotar ngrok, `http://metricool.test` sin soporte, `127.0.0.1` no válido como RP ID en E2E | Alta | Medio | `WEBAUTHN_RP_ID` explícito por entorno, aviso en la UI (CA-20), E2E sobre `http://localhost:8080`, guía de operación. En producción, dominio estable |
| Librería de passkeys pre-1.0 (`laravel/passkeys` 0.2.x) con cambios de API | Media | Medio | Spike en el diseño. Fijar la versión (`~0.2.1`) o elegir `laragear/webauthn` 5.x estable. Encapsular detrás de un servicio propio |
| Pérdida de secretos TOTP al rotar `APP_KEY` | Baja | Alto | Documentar `APP_PREVIOUS_KEYS` (ya soportado en `config/app.php`) y que el comando de operador sirva de salida |
| Bloqueo de usuarios legítimos (contador de firmas, lockout agresivo) | Media | Medio | Tolerar contador 0, rate limit por `login_id|ip` y no por usuario global, y comando de operador |
| Fuga de secretos o códigos en logs o auditoría | Baja | Alto | Criterio explícito + test que inspecciona `audit_logs`. `detail` solo con metadatos (nº de códigos restantes) |
| Efecto colateral en el MCP | Baja | Alto | Sin cambios en `routes/ai.php`, `McpGuard` ni `sanctum.guard`. CA-22 + `tests/Feature/Mcp/*` sin tocar |
| Extensiones PHP ausentes en Hestia (`sodium`, `openssl`) | Baja | Medio | `php -m` en el servidor antes del despliegue. `paragonie/sodium_compat` como alternativa |
| Conflictos de merge en `routes/web.php` (hotspot de `code-health`: 21 cambios en 90 días) | Media | Bajo | Rutas nuevas en `routes/auth.php` o en un fichero aparte |

---

## Siguiente paso

El flujo acordado incluye **diseño** antes del plan. El agente **`architect`** debe fijar la librería TOTP (recomendado: `pragmarx/google2fa` + `bacon/bacon-qr-code`, sin Fortify), la de passkeys (spike `laravel/passkeys` 0.2.x vs `laragear/webauthn` 5.x), el esquema (`users` + `passkeys`) y la estrategia de RP ID por entorno, y escribirlo en `design.md`. Después, el agente **`planner`** genera `improvement-plan.md` + `tasks.md` con **las tres características aprobadas (C-01, C-02, C-03)**, en el orden C-01 → C-02 → C-03 (UI y E2E del 2FA en paralelo a C-02). El `planner` hereda las horas y costes de esta evaluación por característica, sin re-estimar desde cero, y actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec.
