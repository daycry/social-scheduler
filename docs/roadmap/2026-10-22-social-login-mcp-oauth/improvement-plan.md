---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-22T19:27:46Z
  fin: 2026-10-22T19:37:43Z
  fuente: medido
  tokens_reales: { entrada: 30, salida: 80220, cache_creacion: 113566, cache_lectura: 2195213 }
  eur: null               # precioTokens no fiable: ejecuta rates-verify
  horas_ia: 0.65
  duracion: 39m
  ratio_usado: 300000
---

# 2026-10-22-social-login-mcp-oauth

> Plan de implementación del login social (Google y Facebook, solo cuentas existentes, con el 2FA intacto) y de la autorización OAuth 2.1 del servidor MCP, para conectar Claude Code, Claude Desktop, Codex y OpenCode desde el navegador sin API key.

| | |
|---|---|
| **Fecha** | 2026-10-22 |
| **Estado** | en-progreso |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Media |
| **Solicitante** | daycry (usuario del proyecto) |
| **Responsable** | daycry |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 ([ADR-004](../../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md), `propuesta` hasta T-02) |
| **Plan de pruebas** | [`test-plan.md`](test-plan.md) |
| **Ledger** | [`tasks.md`](tasks.md) (28 tareas, fuente canónica de progreso) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **116,4h** (97,0h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **15,7h** (13,1h base +20 %) (+ 3,9h supervisión) | 0h | Baja |
| Coste total | **5.820 € + tokens** (tokens: ⚠️ verificar) | 0 € | Media |
| Tokens IA | **3,93M** (in 3,55M / out 0,39M) | 0 | Baja |
| Multiplicador productividad | **×5,9** | — | — |
| Tareas | **28** | 0 hechas | — |

Las cifras **heredan íntegras** las de la evaluación (116,4h, 5.820 €, 3,93M tokens). Cambia el **reparto por fase** (ver abajo); el total no.

---

## Estimación por fase

Las horas incluyen el +20 % de contingencia (entre paréntesis, la base que suman las tareas de `tasks.md`).

| Fase | Estimado (h) | Tokens (in / out) | Coste € |
|------|-------------|-------------------|---------|
| Fase 1 — C-02 · Servidor OAuth del MCP (12 tareas, T-01..T-12) | 60,0 (50,0 base) | 1,83M / 0,20M | 3.000 + tokens |
| Fase 2 — C-01 · Login social (7 tareas, T-13..T-19) | 31,2 (26,0 base) | 0,95M / 0,10M | 1.560 + tokens |
| Fase 3 — C-03 · UI, E2E, docs y cierre (9 tareas, T-20..T-28) | 25,2 (21,0 base) | 0,77M / 0,08M | 1.260 + tokens |
| **Total** | **116,4h** (97,0 base) | **3,55M / 0,39M** | **5.820 € + tokens** |

**Diferencias declaradas con la evaluación (el total no cambia: 97h base).** La evaluación presupuestó C-02 = 39h, C-01 = 28h y C-03 = 30h base. Por las condiciones de orden del usuario se mueve trabajo entre bolsas:

| Movimiento | Horas base | De → a | Motivo |
|---|---|---|---|
| Extraer `LoginFlow` | 2 | C-01 → Fase 1 | Primera tarea (T-01), sin cambiar comportamiento, antes de tocar nada |
| Pantalla de consentimiento `Authorize.vue` | 5 | C-03 → Fase 1 | El smoke CA-29 al final de C-02 necesita una pantalla real |
| Smoke CA-29 con los cuatro clientes | 4 | C-03 → Fase 1 | Condición del usuario: adelantarlo al final de C-02 |
| Revisión de seguridad final de la fase 3 (T-28) | 2 | de E2E (−1) y docs (−1) de C-03 | La evaluación solo presupuestó revisión en C-01 (2h) y C-02 (4h); el usuario pide una al final de cada fase |

Resultado: Fase 1 = 39 + 2 + 5 + 4 = **50h**, Fase 2 = 28 − 2 = **26h**, Fase 3 = 30 − 5 − 4 = **21h**. Las dos preguntas abiertas del diseño (texto de `expired` y `login.via`) se absorben en la partida «errores, auditoría y limitador» de C-01 (T-15, 3h): sin horas nuevas.

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
| Supervisión humana | 25 % de las horas IA | Default. Se queda corta en código de autenticación: la revisión adversarial (T-12, T-19, T-28) y el smoke (T-11) son tiempo humano que la IA no comprime |
| Tokens por tarea | ≈ 40,5k por hora base (3,93M / 97h de la evaluación) | In 90 % / out 10 % |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 97,0h × 50 €/h | 4.850 € |
| Margen de contingencia | +20 % sobre desarrollo base (19,4h) | 970 € |
| Tokens IA (input) | 3,55M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,39M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño/ADR-004 + plan) | ≈ 300k tok facturables (≈ 1h-IA); cada artefacto lo mide en su `generacion:` | ⚠️ verificar |
| **Total estimado (con margen)** | | **5.820 € + tokens (⚠️ verificar)** |

---

## Previsión de tokens (por fase)

Estimación del consumo de tokens del modelo por fase. Base: claude-opus-4-8 ⚠️ verificar · precios pendientes de `rates-verify`.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — C-02 | 1.830.000 | 198.000 | 2.028.000 | ⚠️ verificar |
| Fase 2 — C-01 | 951.000 | 104.000 | 1.055.000 | ⚠️ verificar |
| Fase 3 — C-03 | 767.000 | 84.000 | 851.000 | ⚠️ verificar |
| **Total** | **3.548.000** | **386.000** | **3.934.000** | **⚠️ verificar** |

**Método de estimación:** el de la evaluación (3,93M tokens facturables en 97h base ≈ 40,5k por hora humana base, out ≈ 10 %), repartido por tarea en proporción a sus horas. Hay más lectura de contexto en T-01 (login existente), T-08 (protocolo) y T-15 (2FA y passkey). El smoke T-11 consume pocos tokens reales pero conserva su parte por coherencia con la evaluación.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 116,4 h (97,0 h base +20 %) |
| Horas IA (ejecución) | 15,7 h (13,1 h base +20 %; 3,93M tok ÷ 300.000 tok/h, supuesto) |
| Supervisión humana | 3,9 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **19,7 h** |
| Horas ahorradas | 96,7 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,60 |

> Las horas IA son una estimación aproximada (supuesto sin calibrar). La supervisión real será mayor: la revisión adversarial de las tres fases (10h base) y el smoke con cuatro clientes (4h) los hace una persona.

---

## Resumen ejecutivo

Se añaden dos capacidades a la app. La primera es entrar con Google o Facebook en cuentas que ya existen, con el reto 2FA intacto y sin crear nunca usuarios. La segunda es que el servidor MCP (`/mcp`) se autorice por OAuth 2.1, para que Claude Code, Claude Desktop, Codex y OpenCode se conecten desde el navegador sin copiar una API key; las API keys `msk_` siguen funcionando igual. Se construye la opción O1 del diseño ([ADR-004](../../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)): servidor de autorización propio sobre Sanctum, sin Passport, y Socialite para el login social.

El orden es C-02 → C-01 → C-03. Empieza extrayendo `LoginFlow` sin cambiar el comportamiento, con la suite de 2FA y passkeys de ADR-003 en verde; el smoke con los cuatro clientes se adelanta al final de C-02 para descubrir cuanto antes si algún cliente exige refresh token o CIMD; y cada fase cierra con una revisión de seguridad.

### Objetivos

- Servidor de autorización mínimo y verificable: PRM, ASM, DCR, authorize con consentimiento, token con PKCE S256 y `WWW-Authenticate` (CA-12..CA-28).
- Login con Google y Facebook sin alta de cuentas, con la política P1 (Google solo con `email_verified`; Facebook solo vinculando a mano) y el 2FA intacto (CA-01..CA-11).
- 0 regresiones en login con contraseña, passkey, 2FA y API keys `msk_` (suite ADR-003 y `tests/Feature/Mcp` sin modificar).
- Cuatro clientes MCP conectados sin API key (CA-29) y documentación completa (CA-30).

### Decisiones del usuario que el plan respeta

1. P1: Google vincula solo con `email_verified=true` y cuenta local verificada; Facebook solo a mano.
2. P2: servidor de autorización propio sobre Sanctum, sin Passport.
3. P3: presets read/publish/all editables (read por defecto); token de 30 días sin refresh.
4. P4: apps de Google y Meta propias para el login.
5. Preguntas abiertas del diseño, ambas «sí»: texto neutro en `TwoFactorChallengeController::expired` con test, y `login.via` en la auditoría del 2FA limpiado en `PasskeyLoginController` (ambas en T-15).
6. No tocar los datos del usuario id 28; ADR-004 pasa a `aceptada` solo con su visto bueno (T-02).

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (spec aprobada; decisiones P1-P4 y preguntas abiertas resueltas)
- [x] **Alcance** cerrado (spec § Alcance: Apple, GitHub, alta de cuentas, refresh tokens y CIMD fuera)
- [x] **Criterios de éxito / métricas** acordados (CA-01..CA-32)
- [ ] **Accesos y credenciales** necesarios: las apps de login de Google y Meta las crea el usuario (desarrollo y tests no las necesitan; M-01 y M-02 sí)
- [x] **Entornos** disponibles: local (Laragon), E2E en `http://localhost:8080` con `npm run test:e2e`, ngrok reservado para el smoke; producción en Hestia
- [x] **Stakeholders** identificados: el usuario valida el plan, da el visto bueno a ADR-004 y ejecuta los M-xx
- [ ] **Dependencias externas** mapeadas: `laravel/socialite` ^5.31 (se verifica `email_verified` al instalar), cuatro clientes MCP y su versión (⚠️ sin verificar hasta T-11)
- [ ] **Restricciones** conocidas: no hay deadline; el app review de Meta puede condicionar el modo live; no tocar el usuario id 28; las API keys `msk_` no se rompen
- [ ] **Tarifa/hora y supuestos de coste** confirmados (50 €/h por defecto; precio de tokens ⚠️ verificar con `rates-verify`)

---

## Análisis de impacto

- **`app/Http/Controllers/Auth/AuthenticatedSessionController.php`** y **`app/Services/Auth/LoginFlow.php`** (nuevo): la bifurcación 2FA se extrae sin cambiar el comportamiento (T-01). Es la zona de mayor riesgo de regresión del login.
- **`app/Http/Controllers/Auth/TwoFactorChallengeController.php`** y **`PasskeyLoginController.php`**: cambios mínimos de T-15 (texto de `expired`, `login.via`), aprobados como preguntas abiertas del diseño.
- **`app/Services/Mcp/OAuth/*`**, **`app/Http/Controllers/Mcp/OAuth/*`** (nuevos): servidor de autorización (metadatos, DCR, authorize, token).
- **`app/Services/Auth/SocialIdentityResolver.php`**, **`Auth/SocialLoginController.php`**, **`Profile/SocialIdentityController.php`** (nuevos): login social y vinculación.
- **`routes/ai.php`**, **`routes/web.php`**, **`routes/auth.php`**, **`routes/console.php`**: rutas OAuth fuera de `web` (sin CSRF ni sesión), `authorize` en `web` + `auth`, rutas sociales y purga programada.
- **`app/Providers/AppServiceProvider.php`**: `Sanctum::usePersonalAccessTokenModel`, limitadores (`social-login`, `mcp-oauth-register`, `mcp-oauth-token`) y el driver falso solo en `APP_ENV=e2e`.
- **`database/migrations/`**: cuatro migraciones nuevas, todas aditivas (`oauth_clients`, `oauth_auth_codes`, `personal_access_tokens.oauth_client_id`, `social_identities`).
- **`resources/js/Pages/`**: `Auth/Login.vue`, `Profile/Edit.vue`, `Profile/Partials/LinkedAccountsForm.vue`, `OAuth/Authorize.vue`, `ApiKeys/Index.vue`.
- **`deploy/hestia/nginx-overrides.conf`**, **`docs/modulos/{mcp,despliegue}.md`**: `location ^~ /.well-known/oauth-` y guías.
- **Sin cambios:** `app/Http/Middleware/McpGuard.php`, `vendor/laravel/mcp/*`, `app/Mcp/Tools/SchedulerTool.php`, `app/Services/AuditLog.php` y `app/Connectors/*`.

---

## Cambios arquitectónicos

- **Opción O1 del diseño** ([ADR-004](../../knowledge/adr/ADR-004-oauth-mcp-propio-sobre-sanctum-y-login-social-socialite.md)). Este plan no rediseña nada: implementa la tabla de impacto de §5 del diseño.
- **`LoginFlow` compartido** por contraseña y login social; la passkey sigue sin pasar por el 2FA (ADR-003).
- **Un solo callback social** (`GET auth/{provider}/callback`, sin `guest`) que se bifurca por `social.intent` (login o vincular).
- **Código de autorización en tabla** (`oauth_auth_codes`: hash SHA-256, `used_at` atómico, `token_id` para revocar en un replay), que sobrevive a `optimize:clear`.
- **Marca del token** `personal_access_tokens.oauth_client_id` con un modelo `PersonalAccessToken` propio; la reautorización revoca en transacción los tokens vivos de ese (usuario, cliente).
- **401 con `WWW-Authenticate`** reutilizando `AddWwwAuthenticateHeader` del vendor mediante la ruta con nombre `mcp.oauth.protected-resource.nested`.
- **Consentimiento con `Content-Security-Policy: frame-ancestors 'none'`** (no un segundo `X-Frame-Options`, que chocaría con el `SAMEORIGIN` de Nginx).

---

## Archivos a crear/modificar

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `composer.json`, `composer.lock` | Modificar | `laravel/socialite` ^5.31 |
| `database/migrations/2026_11_06_09*` | Crear | `oauth_clients`, `oauth_auth_codes`, `oauth_client_id`, `social_identities` |
| `app/Services/Auth/{LoginFlow,SocialIdentityResolver}.php` | Crear | Bifurcación 2FA compartida y política P1 |
| `app/Services/Mcp/OAuth/{AuthorizationServer,RedirectPolicy}.php` | Crear | Código, PKCE, emisión del token y política de redirect |
| `app/Http/Controllers/Mcp/OAuth/*` | Crear | Metadatos, DCR, authorize y token |
| `app/Http/Controllers/Auth/{SocialLoginController}.php`, `Profile/SocialIdentityController.php` | Crear | Login social, vincular y desvincular |
| `app/Http/Controllers/Auth/{AuthenticatedSessionController,TwoFactorChallengeController,PasskeyLoginController}.php`, `ApiKeyController.php`, `ProfileController.php` | Modificar | Delegar en `LoginFlow`, `login.via`, origen del token y props |
| `app/Models/{OAuthClient,OAuthAuthCode,PersonalAccessToken,SocialIdentity}.php`, `User.php` | Crear / Modificar | Modelos y relación |
| `app/Console/Commands/McpOAuthPrune.php`, `routes/*.php`, `app/Providers/AppServiceProvider.php`, `config/services.php`, `.env.example` | Crear / Modificar | Purga, rutas, limitadores y configuración |
| `resources/js/Pages/{Auth/Login,Profile/Edit,ApiKeys/Index}.vue`, `Profile/Partials/LinkedAccountsForm.vue`, `OAuth/Authorize.vue` | Modificar / Crear | UI |
| `deploy/hestia/nginx-overrides.conf` | Modificar | `location ^~ /.well-known/oauth-` |
| `tests/Feature/Mcp/OAuth/*`, `tests/Feature/Auth/Social/*`, `tests/Unit/*` | Crear | Pest |
| `playwright.config.ts`, `tests/e2e/{helpers,social.spec,oauth.spec,a11y.spec}.ts` | Modificar / Crear | E2E con proveedor falso, OAuth y axe |
| `docs/modulos/{mcp,despliegue}.md`, `docs/usuario/guia-de-usuario.md`, `docs/guias/login-social-google-meta.md` | Modificar / Crear | Documentación |
| `docs/knowledge/adr/ADR-004-*.md`, `docs/knowledge/README.md` | Modificar | `propuesta` → `aceptada` con el visto bueno del usuario |

---

## Dependencias y prerequisitos

- Spec aprobada, evaluación en `go` y diseño aprobado (O1), todos de esta carpeta. La rama de trabajo es `feature/social-login-mcp-oauth`.
- **Puerta humana en T-02:** el visto bueno del usuario a ADR-004 antes de implementar C-02 (CA-32).
- Orden fijo: Fase 1 (C-02) → Fase 2 (C-01) → Fase 3 (C-03). T-13 depende de la revisión T-12 y T-20 de T-19. T-01 (`LoginFlow`) es la primera tarea.
- Para T-11 y los M-xx: dominio ngrok reservado, los cuatro clientes MCP instalados y, para M-01/M-02, las apps de login de Google y Meta creadas con los pasos de la spec.
- Un PHP de Hestia con acceso para el `curl` de verificación de M-07.

---

## Criterios de aceptación (global)

- [ ] `php artisan test` pasa completo sin desactivar ni modificar tests existentes (los 18 ficheros de `tests/Feature/Auth` de ADR-003 y `tests/Feature/Mcp` sin tocar).
- [ ] CA-01..CA-32 de la spec cubiertos: Pest en las fases 1 y 2, E2E en la fase 3 y M-xx entregados como checklist (CA-29 y el login real con Google y Facebook los ejecuta el usuario).
- [ ] `git diff --stat main -- app/Http/Middleware/McpGuard.php app/Mcp/Tools/SchedulerTool.php app/Services/AuditLog.php vendor` vacío.
- [ ] Una API key `msk_` creada antes del cambio sigue entrando en `/mcp` y ninguna fila de `users` (en particular el id 28) se ha modificado.
- [ ] Ninguna fila de `audit_logs` ni línea de log contiene códigos, tokens, verifiers ni secretos.
- [ ] `npm run build`, `vendor/bin/pint --test` y `npm run test:e2e` (desktop, mobile y mobile-360) en verde.
- [ ] axe sin violaciones `serious`/`critical` en Login, Cuentas vinculadas y Consentimiento, y 0 px de desbordamiento a 375 y 360 px.
- [ ] ADR-004 en `aceptada` con el visto bueno del usuario; `.env.example`, `mcp.md` y `despliegue.md` actualizados (CA-30).

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Error de protocolo en el servidor de autorización propio (open redirect, replay, PKCE mal comparado) | Media | Alto | Alcance mínimo (cliente público, code+S256, sin refresh), `hash_equals`, vectores del RFC 7636, un test negativo por cada `invalid_*` y revisión adversarial T-12 |
| Un cliente MCP exige refresh token o solo usa CIMD | Media | Alto | Smoke CA-29 adelantado al final de C-02 (T-11), antes de construir el resto; +8h base reservables para refresh, a decidir con el usuario |
| Regresión del login con contraseña, passkey o 2FA al extraer `LoginFlow` | Baja | Alto | T-01 primera tarea, sin cambio de comportamiento y con la suite de ADR-003 en verde; si falla, se revierte la extracción |
| Toma de cuenta por login social | Media | Crítico | P1 (Google verificado, Facebook a mano), cuenta local verificada, mensaje genérico, auditoría y revisión T-19 |
| Socialite no expone `email_verified` o el driver de Facebook usa una versión de Graph antigua | Baja | Alto | Comprobación en el vendor al instalar (T-13), falla cerrado y `usingGraphVersion` con test (T-16) |
| Las API keys `msk_` existentes dejan de funcionar | Baja | Alto | La columna `oauth_client_id` es nullable y `auth:sanctum` no cambia; `tests/Feature/Mcp` sin modificar en T-03, T-09 y T-28 |
| Tocar datos del usuario id 28 con migraciones, tests o E2E | Baja | Alto | Migraciones aditivas, E2E solo con `e2e-sec-*` y grep de control en T-28 |
| El driver social falso llega a producción | Baja | Alto | Solo con `APP_ENV=e2e` y test que lo comprueba en otros entornos (T-23) |
| Nginx/Hestia no reenvía `/.well-known/oauth-*` o `Authorization` | Media | Alto (producción) | `location ^~` en T-04, `curl` de verificación en `despliegue.md` y M-07 |
| `APP_ENV=e2e` altera el comportamiento de la batería E2E existente | Media | Medio | T-23 verifica la batería de 2FA y passkeys antes de escribir los specs nuevos |
| Fricción con las apps de Google y Meta (modo live, verificación) | Media | Medio | Apps de login separadas, Meta en modo desarrollo con roles; los tests no las necesitan |
| Un cliente con API key caducada recibe ahora `resource_metadata` en el 401 e intenta OAuth | Media | Bajo | Comportamiento previsto por la spec MCP; se documenta en `mcp.md` (T-27) |
| Dominio ngrok rotatorio invalida las redirect URIs | Media | Bajo | Dominio ngrok reservado |

---

## Métricas de éxito

- 32/32 criterios de aceptación de la spec verificados (automáticos, o M-xx firmados por el usuario).
- 0 regresiones: los tests existentes siguen en verde y no se modifica ninguno.
- Los 4 clientes MCP conectados sin API key, con comando y versión registrados en `testing/smoke-ca29.md`.
- Desviación de horas reales frente a la estimación ≤ 20 % (el margen), medida en `tasks.md`.

---

## Decisiones y preguntas que el plan deja abiertas

- **ADR-004** pasa a `aceptada` solo con el visto bueno del usuario (T-02, puerta humana).
- **Refresh token / CIMD:** lo decide el usuario tras el smoke T-11 si algún cliente lo exige (≈ +8h base).
- **Reparto entre fases:** decisión del planner justificada arriba; el total evaluado no cambia.
- **Precio de tokens** y tarifa: `⚠️ verificar` con `rates-verify`.

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-22 | Creación del plan: 3 fases, 28 tareas, 116,4h (97,0h base), `test-plan.md` con E2E-01..07, API-01..02, A11Y-01 y M-01..08 | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre la rama `feature/social-login-mcp-oauth`, marcando `tasks.md` como **ledger canónico** (checkbox + estado por tarea). Al terminar, handoff a `qa` (que ejecuta los E2E-xx del `test-plan.md` y guía los M-xx) y cierre con `documenter`.
