---
generacion:
  inicio: 2026-10-22T17:48:56Z
  fin: 2026-10-22T17:58:28Z
  fuente: medido          # incluye también la redacción de spec.md (misma ventana, un solo marcador)
  tokens_reales: { entrada: 60, salida: 52962, cache_creacion: 152437, cache_lectura: 1736128 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.68
  duracion: 41m           # reloj: 10m
  ratio_usado: 300000     # default no calibrado (no existe docs/roadmap/CALIBRATION.md)
---

# 2026-10-22-social-login-mcp-oauth

> Evaluación y presupuesto del login social (Google y Facebook) para cuentas existentes y de la autorización OAuth 2.1 del servidor MCP, que permitirá conectar los clientes desde el navegador sin API key. Sirve para decidir go/no-go, el orden y las dos decisiones de diseño abiertas.

| | |
|---|---|
| **Fecha** | 2026-10-22 |
| **Estado** | completado |
| **Prioridad global** | Media |
| **Solicitante** | daycry (usuario del proyecto) |
| **Spec** | [`spec.md`](spec.md) |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **Características evaluadas** | 3 |

---

## Cuadro de mando

| Métrica | Total estimado | Confianza |
|--------|----------------|-----------|
| Esfuerzo humano | **116,4h** (97h base +20 %) | Media |
| Tiempo IA (ejecución) | **15,7h** (+ 3,9h supervisión) | Baja |
| Coste | **5.820 € + tokens** (tokens: ⚠️ verificar) | Media |
| Tokens IA | **3,93M** (in 3,55M / out 0,38M) | Baja |
| Multiplicador productividad | **×5,9** | — |
| Características | **3** | — |

> Las horas humanas equivalentes (lo que costaría a una persona, base del valor) y las horas IA (lo que tardará el ciclo, base del plazo) son magnitudes distintas y no se suman (LES-007). Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).

---

## Resumen ejecutivo

El usuario pide dos cosas:

- **Login social** con Google y Facebook, solo para cuentas existentes y con el reto 2FA intacto.
- **OAuth 2.1** en el MCP (PRM, ASM, DCR, PKCE y 401 con `WWW-Authenticate`), para que Claude Code, Claude Desktop, Codex y OpenCode se conecten desde el navegador, sin romper las API keys `msk_`.

Lo presupuesto se divide en 3 características: C-01 login social (33,6h), C-02 servidor de autorización MCP (46,8h) y C-03 UI, E2E, documentación y smoke con los cuatro clientes (36h). En total son **116,4h con margen ≈ 5.820 € + tokens**.

El recon da tres hallazgos:

1. `laravel/mcp` 1.0.2 sí trae `Mcp::oauthRoutes()`, pero **depende de Passport**, que no está instalado, y solo concede el scope `mcp:use`.
2. `laravel/socialite` tampoco está instalado.
3. La auditoría guarda `token_id` como `bigint`, lo que encaja con Sanctum y no con Passport.

Por eso se recomienda un servidor de autorización mínimo propio que emite tokens Sanctum. Para la toma de cuenta se recomienda vincular automáticamente solo con Google cuando el email está verificado, y Facebook solo vinculándolo a mano. Veredicto: **go condicionado** a dos decisiones del usuario (P1 y P2) y a una ADR-004.

---

## Requerimientos recibidos

Mapa de la spec a las características evaluadas.

| ID | Característica | Requisito origen (ref.) | ¿Claro? |
|----|---------------|-------------------------|---------|
| C-01 | Login social Google y Facebook (backend): identidades, vinculación automática y manual, integración con el 2FA, auditoría y limitadores | spec § Flujo A–C, CA-01..CA-11 | Claro en lo funcional. Ambiguo en la política de email no verificado (P1) |
| C-02 | Servidor de autorización OAuth 2.1 del MCP: PRM, ASM, DCR, authorize con consentimiento, token con PKCE y coexistencia con `msk_` | spec § Flujo D, CA-12..CA-28, CA-31, CA-32 | Claro en el protocolo. Ambiguo en la arquitectura, Passport o propia (P2), y en el comportamiento real de cada cliente |
| C-03 | UI (botones de login, Cuentas vinculadas, consentimiento, origen en API keys), E2E, axe, documentación y smoke con los cuatro clientes | spec § Arquitectura (Vue), § Pruebas, CA-29, CA-30, § Pasos manuales | Claro |

**Ambigüedades / información que falta:**

1. **Email ausente o no verificado (P1).** El usuario pide una recomendación. La propuesta de la spec es vincular automáticamente solo con Google y `email_verified=true`, y nunca con Facebook. Cambia CA-05 y unas 2h de C-01.
2. **Passport o servidor propio (P2).** Cambia unas 6h de C-02 y el perfil de riesgo. Ver la comparativa en C-02.
3. **Comportamiento real de los cuatro clientes:** callbacks, soporte de `resource`, si exigen refresh token y si usan CIMD en vez de DCR. No se puede verificar desde el repo. Va como supuesto ⚠️ y lo comprueba el smoke de CA-29.
4. **Dominio de producción** sin fijar: afecta a las redirect URIs, no al coste.
5. **Modo de la app de Meta** (desarrollo o live): afecta al app review y al calendario, no a las horas de desarrollo.

---

## Datos necesarios para una evaluación completa

- [x] **Requerimientos** completos y sin ambigüedades. Lo están, salvo P1 y P2, que la spec deja como propuestas.
- [x] **Alcance** de cada característica acotado: spec § Alcance, con Apple, GitHub, alta de cuentas, refresh tokens y CIMD fuera.
- [x] **Criterios de aceptación / éxito** por característica: CA-01..CA-32.
- [ ] **Restricciones** (deadline, presupuesto, compliance): no hay deadline. El app review de Meta puede condicionar el modo live.
- [ ] **Dependencias externas** identificadas: Google Cloud, la app de Meta y las versiones de los cuatro clientes MCP. Identificadas, pero sin verificar (⚠️).
- [x] **Contexto técnico** del proyecto disponible: recon del repo y del vendor `laravel/mcp`.
- [ ] **Tarifa/hora y supuestos de coste** confirmados: no existe `.claude/rates.json`. Se usan los defaults y los tokens quedan ⚠️ verificar.

---

## Supuestos económicos (ajustables)

**Coste = (horas × tarifa) + coste de tokens de IA.** Importes en **EUR**.

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default compartido (`estimation-defaults.md`). No hay `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 | Base de la previsión de tokens |
| Precio input | ⚠️ verificar € / 1M tokens | Sin `rates.json`: ejecuta `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre las horas base (humanas e IA) |
| Ratio tokens→hora-IA | 300.000 tok/h | Default **no calibrado**: no existe `docs/roadmap/CALIBRATION.md` |
| Densidad tokens/hora humana | ≈ 40.500 tok por hora base | Tomada de la iniciativa anterior, `account-2fa-passkeys` (4,62M tok / 114h base), de stack y tipo de trabajo parecidos (auth, Pest, Playwright). Es un indicio, no un histórico calibrado (una sola fila) |
| Supervisión humana | 25 % de las horas IA | Default. Probablemente se queda corta para código de autenticación (ver Productividad) |

---

## Evaluación por característica

### C-01: Login social con Google y Facebook (backend)

- **Requisito origen**: spec § Flujo A, B y C, CA-01..CA-11, § Decisiones (identidad, email, 2FA y desvincular).
- **Descripción**: Socialite con los drivers de Google y Facebook y credenciales propias de login, más la tabla `social_identities` (proveedor + id estable). Incluye la resolución del usuario (identidad vinculada, vinculación automática solo con Google y email verificado en ambos lados, rechazo en el resto) y la extracción de la bifurcación 2FA a `LoginFlow`, que comparten la contraseña y el login social. También vincular y desvincular desde el perfil con `password.confirm` y bloqueo de la vinculación automática, auditoría y limitador.
- **Complejidad**: Media.
- **Esfuerzo**: 33,6h (28h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Socialite y configuración | 1h |
  | Migración y modelo | 2h |
  | Controlador y `LoginFlow` | 6h |
  | Vincular y desvincular | 5h |
  | Errores, auditoría y limitador | 3h |
  | Particularidades de Facebook (versión Graph, email ausente, fragmento `#_=_`) | 2h |
  | Pest con Socialite simulado | 7h |
  | Revisión de seguridad (toma de cuenta, enumeración) | 2h |
  | **Total** | **28h** |

- **Previsión IA**: 1,02M in / 0,11M out tok · ⚠️ verificar €.
- **Coste**: (33,6h × 50 €/h) + tokens = **1.680 € + tokens**.
- **Impacto / áreas afectadas**:
  - `composer.json` (`laravel/socialite`), `config/services.php` y `.env.example`.
  - `routes/auth.php` y `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (extraer `LoginFlow`).
  - Nuevos: `Auth/SocialLoginController.php`, `Profile/SocialIdentityController.php`, migración `social_identities` y `app/Providers/AppServiceProvider.php` (limitador `social-login`).
- **Dependencias y prerequisitos**:
  - Las apps de login de Google y Meta, creadas por el usuario. El desarrollo y los tests no las necesitan (mocks); el smoke real sí.
  - Respuesta del usuario a P1.
- **Riesgos**:
  - **Toma de cuenta por email no verificado.** Es el riesgo dominante y se trata abajo.
  - Regresión del login con contraseña al extraer `LoginFlow`. Está cubierto por la suite de ADR-003, que hay que mantener en verde.
  - Pérdida de `url.intended` en el viaje al proveedor. La cookie de sesión `SameSite=lax` viaja en la navegación GET de vuelta, así que el riesgo es bajo, pero un test lo debe fijar.
- **Análisis del riesgo de toma de cuenta (lo pide el usuario)**:

  | Opción | Riesgo | UX | Recomendación |
  |---|---|---|---|
  | Vincular por email sin mirar la verificación | **Alto**: quien cree una identidad en el proveedor con el email de la víctima, sin verificarlo, entra en su cuenta | Mejor | Descartar |
  | Vincular solo si el proveedor marca el email como verificado (Google `email_verified`; en Facebook no hay señal fiable) | **Bajo** con Google, porque es autoritativo para Gmail y Workspace. **Medio-alto** con Facebook: la Graph API no expone la verificación, hay takeovers públicos con emails no confirmados y un Facebook registrado con teléfono puede añadir emails | Buena | **Sí para Google**; para Facebook ver la fila siguiente |
  | Solo vinculación manual desde el perfil, con sesión iniciada y `password.confirm` | **Mínimo**: la identidad la enlaza quien ya demostró poseer la cuenta | Un paso extra la primera vez | **Sí para Facebook** (y opción conservadora también para Google) |
  | Sin email (el usuario lo deniega en Facebook o la cuenta no lo tiene) | — | — | Se rechaza con «vincúlala desde tu perfil». No se crea nada |

  Salvaguardas añadidas en todos los casos:

  - la cuenta local debe tener `email_verified_at`;
  - un mensaje genérico que no revela si el email existe;
  - el 2FA se mantiene;
  - auditoría de cada vinculación (también la automática), para detectar un enlace no esperado;
  - desvincular bloquea la vinculación automática.

  Elegir solo vinculación manual para los dos proveedores ahorra ≈ 1h y elimina el riesgo residual de Google, a cambio de un paso más.
- **Incógnitas / preguntas abiertas**:
  - P1 (política de email).
  - Si Socialite 5.31 devuelve `email_verified` en `user['email_verified']` con el endpoint userinfo actual de Google ⚠️ verificar en el código del vendor al instalarlo.

### C-02: Servidor de autorización OAuth 2.1 del MCP

- **Requisito origen**: spec § Flujo D, CA-12..CA-28, CA-31, CA-32, § Decisiones (AS, permisos, vida del token, redirect URIs).
- **Descripción**: el endpoint `/mcp` pasa a ser descubrible y autorizable por OAuth. Incluye:
  - PRM (`/.well-known/oauth-protected-resource[/mcp]`) y ASM (RFC 8414).
  - DCR público con una política estricta de redirect (loopback y callbacks de Claude).
  - `/oauth/authorize` con login (cualquier método, con 2FA) y consentimiento con presets de abilities.
  - `/oauth/token` con PKCE S256, código de un solo uso y validación de `resource`, que emite un **token Sanctum `msk_`** con las abilities elegidas y 30 días de vida.
  - `WWW-Authenticate` con `resource_metadata`, mediante la ruta con nombre que espera el middleware del vendor.
  - Sustitución del token al reautorizar, purga de clientes y auditoría.
- **Complejidad**: Alta.
- **Esfuerzo**: 46,8h (39h base, opción B) · confianza Media-Baja. Desglose base:

  | Partida | Horas base |
  |---|---|
  | ADR-004 | 2h |
  | Migraciones | 2h |
  | Metadatos y `WWW-Authenticate` | 3h |
  | DCR, límites y purga | 5h |
  | Authorize y consentimiento (backend) | 6h |
  | Token endpoint y PKCE | 6h |
  | Coexistencia con `msk_` (`oauth_client_id`, sustitución, auditoría) | 2h |
  | Revisión de seguridad adversarial (open redirect, replay, clickjacking, enumeración, timing) | 4h |
  | Pest: flujo completo y negativos, unos 30 tests | 9h |
  | **Total** | **39h** |

- **Previsión IA**: 1,43M in / 0,15M out tok · ⚠️ verificar €.
- **Coste**: (46,8h × 50 €/h) + tokens = **2.340 € + tokens**.
- **Impacto / áreas afectadas**:
  - `routes/ai.php` y las rutas OAuth (fuera de `web`, sin CSRF).
  - Nuevos: `app/Mcp/OAuth/*` y `app/Http/Controllers/Mcp/OAuth/*`, y las migraciones `oauth_clients`, `oauth_auth_codes` y `personal_access_tokens.oauth_client_id`.
  - `app/Providers/AppServiceProvider.php` (limitadores `mcp-oauth-register` y `mcp-oauth-token`), `routes/console.php` (purga) y `config/services.php` (`mcp.oauth.*`).
  - Sin cambios: `McpGuard`, `SchedulerTool::tokenCan` y `AuditLog`.
- **Dependencias y prerequisitos**:
  - ADR-004 aceptada (P2).
  - C-01, solo para cubrir «entrar con Google o Facebook» dentro del authorize. El authorize con contraseña o passkey no depende de C-01.
- **Comparativa de arquitectura (P2)**:

  | Criterio | **A. Passport 13.10 + `Mcp::oauthRoutes()`** | **B. AS mínimo propio sobre Sanctum** (recomendada) |
  |---|---|---|
  | Código OAuth propio | Poco: league/oauth2-server valida el code+PKCE y el vendor da DCR y metadatos | Unas 600-800 líneas: DCR, authorize, token y PKCE (sobre RFC, con vectores de test) |
  | Abilities y presets | **Choca**. El DCR del vendor solo da al cliente `mcp:use`, y `ScopeRepository::finalizeScopes` de Passport filtra los scopes que el cliente no tiene. Hay que sobrescribir el DCR y el `ApproveAuthorizationController` para inyectar las abilities elegidas | Directo: el token nace con las abilities marcadas |
  | Modelo `User` | Hay que resolver el conflicto de traits `HasApiTokens` (Sanctum) con el de Passport (`tokens()`, `createToken()`, `tokenCan()`…) y el guard `auth:sanctum,api` | Sin cambios |
  | Auditoría y limitador | `audit_logs.token_id` es `bigint` y los ids de Passport son texto: migración o columna nueva | Sin cambios (el id de Sanctum es entero) |
  | Revocación en la UI | Hay que listar los tokens Passport aparte en *API keys* | Ya están en `personal_access_tokens` |
  | Despliegue | Claves RSA (`passport:keys`), permisos en Hestia y 5 tablas | 2 tablas y 1 columna |
  | Refresh tokens | Incluidos (el vendor anuncia `refresh_token`) | Fuera de alcance (+8h base si hicieran falta) |
  | Riesgo | Menor riesgo criptográfico y de protocolo; mayor riesgo de integración (dos sistemas de tokens) | Mayor riesgo de implementar mal el protocolo, mitigado con un alcance mínimo (solo cliente público, code+S256, sin refresh), 4h de revisión adversarial y tests con los RFC |
  | Esfuerzo base C-02 | **≈ 45h** (+6h) → 54h con margen, **≈ +360 €** | **39h** → 46,8h con margen |
  | Precedente en el repo | ADR-001: «la auth del MCP sale del ecosistema oficial». `docs/modulos/mcp.md:50` ya preveía «OAuth vía Passport» como ampliación | ADR-003: TOTP propio cuando el paquete añade más fricción que valor |

- **Riesgos**:
  - **Comportamiento de los clientes**: callbacks, `resource`, refresh y CIMD. Un cliente que exija refresh token añade unas 8h base.
  - **Errores de protocolo** con la opción B: redirect abierto, replay de códigos, PKCE mal comparado. Se mitigan con tests negativos y la revisión adversarial.
  - **Nginx/Hestia** puede tragarse `/.well-known/*` o la cabecera `Authorization` (M-F6-2, pendiente).
  - **DCR abierto a Internet**: spam de clientes, mitigado con límite por IP y purga.
- **Incógnitas / preguntas abiertas**:
  - P2 (A o B).
  - Si Claude Desktop sigue pidiendo DCR o ya usa CIMD. ⚠️ verificar.
  - Si algún cliente manda `Origin` al token endpoint. Hoy no se valida y no debería hacer falta.

### C-03: UI, E2E, accesibilidad, documentación y smoke con los clientes

- **Requisito origen**: spec § Arquitectura (Vue), § Pruebas, CA-18, CA-25 (UI), CA-29, CA-30, § Pasos manuales.
- **Descripción**: comprende estas piezas.
  - Botones «Continuar con Google» y «Continuar con Facebook» en `Login.vue`, visibles solo si el proveedor está configurado.
  - El parcial *Cuentas vinculadas* en el perfil.
  - La pantalla de consentimiento `OAuth/Authorize.vue`: presets y casillas, cliente declarado, host de redirección y denegar.
  - La columna «Origen» en *API keys*.
  - Playwright con el driver social falso (solo E2E) y el flujo OAuth completo desde el navegador, más axe en las pantallas nuevas.
  - Documentación: `mcp.md` con los cuatro clientes sin API key, `despliegue.md`, `.env.example` y la guía de Google y Meta.
  - Smoke manual con Claude Code, Claude Desktop, Codex y OpenCode en ngrok.
- **Complejidad**: Media.
- **Esfuerzo**: 36h (30h base) · confianza Media. Desglose base:

  | Partida | Horas base |
  |---|---|
  | Botones de login | 2h |
  | Cuentas vinculadas | 4h |
  | Consentimiento | 5h |
  | Origen en *API keys* | 2h |
  | E2E (driver falso y flujo OAuth con PKCE desde el test) | 8h |
  | axe | 1h |
  | Documentación | 4h |
  | Smoke con los cuatro clientes y registro en `testing/` | 4h |
  | **Total** | **30h** |

- **Previsión IA**: 1,10M in / 0,12M out tok · ⚠️ verificar €.
- **Coste**: (36h × 50 €/h) + tokens = **1.800 € + tokens**.
- **Impacto / áreas afectadas**:
  - `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Profile/Edit.vue` y `resources/js/Pages/Profile/Partials/LinkedAccountsForm.vue` (nuevo).
  - `resources/js/Pages/OAuth/Authorize.vue` (nuevo) y `resources/js/Pages/ApiKeys/Index.vue`.
  - `tests/e2e/*` y la configuración E2E.
  - `docs/modulos/mcp.md`, `docs/modulos/despliegue.md` y `.env.example`.
- **Dependencias y prerequisitos**:
  - C-01 y C-02 terminadas.
  - El smoke necesita las apps reales de Google y Meta y los cuatro clientes instalados en la máquina del usuario.
- **Riesgos**:
  - El smoke depende de terceros y de su versión.
  - La interstitial de ngrok free en el navegador.
  - El driver falso podría filtrarse a producción; se mitiga registrándolo solo en `APP_ENV=e2e`, con un test que lo comprueba.
- **Incógnitas / preguntas abiertas**:
  - Los comandos exactos de cada cliente en su versión actual. ⚠️ verificar.
  - Si Claude Desktop exige plan de pago para conectores remotos. ⚠️ verificar.

---

## Comparativa

| # | Característica | Complejidad | Horas | Coste € | Tokens | Prioridad | Confianza |
|---|---------------|-------------|-------|---------|--------|-----------|-----------|
| C-02 | Servidor de autorización OAuth 2.1 del MCP | Alta | 46,8h | 2.340 € | 1,58M | Alta | Media-Baja |
| C-01 | Login social con Google y Facebook | Media | 33,6h | 1.680 € | 1,13M | Media | Media |
| C-03 | UI, E2E, documentación y smoke con los clientes | Media | 36h | 1.800 € | 1,22M | Media | Media |
| | **Total** | | **116,4h** | **5.820 €** | **3,93M** | | |

> C-02 tiene prioridad Alta porque es la que quita fricción real: hoy hay que copiar y pegar una API key en cada cliente. C-01 es un extra de comodidad del login, que además alimenta al authorize de C-02.

---

## Presupuesto total

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 97h × 50 €/h (incluye 6h de revisión de seguridad, 2 + 4, y 5h de smoke y axe, 4 + 1) | 4.850 € |
| Margen de contingencia | +20 % sobre el desarrollo base (19,4h) | 970 € |
| Tokens IA (input) | 3,55M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,38M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño/ADR-004 + plan) | ≈ 300k tok facturables estimados (≈ 1h-IA). Esta evaluación queda medida en `generacion:` (LES-008) | ⚠️ verificar |
| **Total estimado (con margen)** | | **5.820 € + tokens (⚠️ verificar)** |

**Variantes que mueven la cifra:**

| Variante | Delta base | Delta con margen |
|---|---|---|
| Opción A (Passport) en lugar de B | +6h | +7,2h ≈ +360 € |
| Refresh tokens, si algún cliente los exige | +8h | +9,6h ≈ +480 € |
| Solo vinculación manual también para Google (P1 conservadora) | −1h | −1,2h ≈ −60 € |
| Solo C-02 + C-03 sin login social (los botones y Cuentas vinculadas salen de C-03) | −34h | ≈ −40,8h ≈ −2.040 € |

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 116,4 h (97h base) |
| Horas IA (ejecución) | 15,7 h (13,1h base = 3,93M tok ÷ 300.000 tok/h) |
| Supervisión humana | 3,9 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **19,6 h** |
| Horas ahorradas | 96,8 h |
| **Ahorro** | **83,2 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,61 |

> Las horas se muestran **con el margen de contingencia (+20 %)** ya aplicado sobre las horas base, humanas e IA. Las horas IA son una **estimación no calibrada**: en el histórico de los kits, las horas IA previstas se desviaron entre −77 % y −96 %, y las humanas nunca se han validado (LES-007). Úsalas para el plazo, no para el valor. Para código de autenticación y OAuth, la supervisión del 25 % es baja: con la revisión adversarial aparte sube a ~8h (LES-009: la revisión es la partida grande). Además, el smoke con los cuatro clientes es tiempo del usuario que no reduce la IA.

---

## Recomendación

- **Veredicto**: **go condicionado** a dos decisiones del usuario, P1 (política de email) y P2 (Passport o servidor propio, formalizado en ADR-004), antes de planificar C-02.
- **Quick wins** (bajo coste, alto valor): dentro de C-02, los metadatos más `WWW-Authenticate` con `resource_metadata` (≈ 3h). Es el primer paso que hace al MCP descubrible. No hay quick wins aislados de valor completo: el flujo solo aporta valor terminado.
- **Costosas / a valorar**: C-02, por su complejidad y por depender de clientes de terceros.
- **Orden sugerido**: **C-02 (backend) → C-01 → C-03**. Motivos:
  - C-02 es la de más valor y no depende de C-01, porque el authorize ya funciona con contraseña, passkey y 2FA.
  - C-01 se suma después al login que usa el authorize.
  - C-03 cierra con UI, E2E y el smoke con apps reales.

  Si el usuario prefiere ver antes el login social, C-01 → C-02 → C-03 también funciona; solo cambia cuándo llega el valor.
- **Fuera de alcance recomendado**: refresh tokens (salvo que el smoke demuestre que algún cliente los exige), CIMD, Apple y GitHub, CORS para clientes de navegador, introspección y revocación por endpoint, y `password.confirm` con el proveedor social.

---

## Riesgos transversales

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Toma de cuenta por login social, con un email no verificado o una identidad ajena | Media | Crítico | Política P1 recomendada: Google solo con `email_verified`, Facebook solo a mano. Cuenta local verificada, 2FA intacto, auditoría de cada vinculación, mensaje genérico y desvincular que bloquea |
| Un cliente MCP no sigue la spec como se supone (callbacks, `resource`, refresh, CIMD) | Media | Alto | Política de redirect configurable (`MCP_OAUTH_REDIRECT_ALLOWED`), `resource` opcional pero validado si viene, smoke CA-29 temprano (al terminar C-02, no al final) y +8h reservables para refresh |
| Error de implementación del protocolo en el servidor propio (B) | Media | Alto | Alcance mínimo (cliente público, code+S256, sin refresh), vectores del RFC 7636, tests negativos por cada `invalid_*` y revisión adversarial de 4h |
| Regresión del login con contraseña, passkey o 2FA al extraer `LoginFlow` | Baja | Alto | Suite de ADR-003 obligatoria en verde. `LoginFlow` se extrae sin cambiar el comportamiento antes de añadir lo social |
| Nginx/Hestia no reenvía `/.well-known/oauth-*` o `Authorization` | Media | Alto (en producción) | Paso manual documentado, `curl` de verificación en `despliegue.md` y cierre de M-F6-2 |
| Fricción con las apps de Meta y Google (casos de uso, modo live, verificación) | Media | Medio | Apps de login separadas, Meta en modo desarrollo con roles (sin app review) y Google con scopes no sensibles en un proyecto dedicado |
| Dominio ngrok rotatorio: las redirect URIs registradas caducan | Media | Bajo | Dominio ngrok reservado (ya se recomienda para passkeys en `despliegue.md`) |
| La API keys `msk_` dejan de funcionar | Baja | Alto | La opción B no toca `auth:sanctum` ni el modelo. Test de regresión CA-24 |

---

## Siguiente paso

Para **ejecutar** lo aprobado, genera el plan detallado con el agente **`planner`** (`improvement-plan.md` + `tasks.md` en `docs/roadmap/2026-10-22-social-login-mcp-oauth/`). Antes:

1. Que el usuario responda a P1 y P2.
2. Pasar por el diseño (`architect`) con ADR-004.

Se aprueban para planificar **C-01, C-02 y C-03**, en el orden C-02 → C-01 → C-03, con el smoke CA-29 adelantado al final de C-02. El `planner` heredará las horas y costes de esta evaluación por característica, sin re-estimar desde cero, y actualizará la fila **Plan** de esta evaluación y el campo `plan:` de la spec al crear el plan.
