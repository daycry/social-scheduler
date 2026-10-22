# Plan de pruebas — Login social (Google y Facebook) y OAuth 2.1 del servidor MCP

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (`npm run test:e2e`: Playwright levanta `php artisan serve --host=localhost --port=8080` con `APP_ENV=e2e`). Manuales: el dominio ngrok reservado (HTTPS) |

> Los E2E corren en los tres proyectos Chromium (`desktop`, `mobile` 375, `mobile-360`) con los usuarios dedicados `e2e-sec-<proyecto>@example.test` que ya crean `global-setup.ts` y los helpers de `tests/e2e/helpers.ts` (`e2eUser`, `loginAs`, `totp`, `enableTwoFactorDirect`, `resetSecurity`). **Nunca se usa ni se modifica el usuario id 28 ni el demo.** Google y Facebook reales no son automatizables: en E2E los simula un **proveedor falso** (driver de Socialite `fake`, registrado solo con `APP_ENV=e2e`, T-23). Las pruebas reales con Google, Facebook y los cuatro clientes MCP son manuales (M-01..M-06).

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Login con Google sin 2FA (CA-01, CA-02, CA-10)
- **Objetivo**: un usuario con la identidad (o con email verificado) entra con el proveedor falso, sin contraseña, y llega al dashboard.
- **Precondiciones**: app en `http://localhost:8080` con `APP_ENV=e2e` y el proveedor `google` configurado; usuario E2E sin 2FA, con email verificado y sin sesión.
- **Pasos**:
  1. Abrir `/login` y comprobar que hay «Continuar con Google».
  2. Preparar la identidad falsa (email del usuario E2E, `email_verified=true`) y pulsar el botón.
  3. Volver del proveedor falso.
- **Aserciones**:
  - [ ] Se llega a `/dashboard` autenticado (CA-01).
  - [ ] En el primer login con ese email se crea la vinculación y existe la identidad en *Cuentas vinculadas* (CA-02).
  - [ ] Con el proveedor sin configurar, el botón no se muestra (CA-10).
- **Capturas esperadas**: Login con los botones, dashboard.
- **Cubre tareas**: T-14, T-20, T-23, T-24

### E2E-02 — Login con Google con 2FA (CA-06)
- **Objetivo**: el login social sustituye solo a la contraseña; con 2FA activo se pide el reto.
- **Precondiciones**: usuario E2E con 2FA activo (`enableTwoFactorDirect`) y la identidad vinculada.
- **Pasos**:
  1. Entrar con el proveedor falso.
  2. Comprobar la redirección a `/two-factor-challenge` y que `/dashboard` redirige a login.
  3. Escribir el TOTP calculado en Node.
- **Aserciones**:
  - [ ] Tras el proveedor no hay sesión autenticada y se muestra el reto (CA-06).
  - [ ] El TOTP válido lleva al destino `intended`.
  - [ ] El reto caducado muestra el texto neutro (sin «correo y contraseña»).
- **Capturas esperadas**: reto 2FA tras el login social.
- **Cubre tareas**: T-14, T-15, T-20, T-23, T-24

### E2E-03 — Rechazos del login social (CA-03, CA-04, CA-05, CA-09)
- **Objetivo**: ningún caso de rechazo abre sesión ni revela si el email existe.
- **Precondiciones**: proveedores falsos `google` y `facebook`.
- **Pasos**:
  1. Google con `email_verified=false`.
  2. Google con un email sin cuenta local.
  3. Facebook con el email de un usuario sin identidad vinculada.
  4. Cancelar en el proveedor (`error=access_denied`).
- **Aserciones**:
  - [ ] Los cuatro casos vuelven a `/login` con un mensaje legible y sin sesión (CA-03, CA-05, CA-09).
  - [ ] Los mensajes de rechazo por email son idénticos (no enumeran cuentas) (CA-04).
  - [ ] No aparece ninguna identidad nueva en el perfil.
- **Capturas esperadas**: mensajes de error en `/login`.
- **Cubre tareas**: T-14, T-15, T-16, T-20, T-23, T-24

### E2E-04 — Vincular y desvincular desde el perfil (CA-07, CA-08)
- **Objetivo**: *Perfil → Cuentas vinculadas* vincula Facebook con confirmación de contraseña y desvincular bloquea la vinculación automática.
- **Precondiciones**: usuario E2E con sesión iniciada.
- **Pasos**:
  1. En `/profile`, pulsar «Vincular Facebook» y confirmar la contraseña.
  2. Volver del proveedor falso y comprobar la lista.
  3. Desvincular y probar el login con esa identidad (y con Google por email).
- **Aserciones**:
  - [ ] La identidad aparece tras vincular (CA-07).
  - [ ] Una identidad ya vinculada a otro usuario muestra el error y no cambia nada (CA-07).
  - [ ] Tras desvincular, el login con esa identidad no entra y Google no la re-vincula solo (CA-08).
- **Capturas esperadas**: parcial «Cuentas vinculadas» antes y después.
- **Cubre tareas**: T-17, T-21, T-23, T-24

### E2E-05 — Flujo OAuth del MCP en el navegador (CA-16, CA-18, CA-19, CA-20)
- **Objetivo**: un cliente simulado completa register → authorize → token y usa el token en `/mcp`.
- **Precondiciones**: usuario E2E con sesión iniciada; el test genera `code_verifier` y `code_challenge` S256.
- **Pasos**:
  1. `POST /oauth/register` con `redirect_uris=["http://127.0.0.1:53111/callback"]`.
  2. Abrir `/oauth/authorize` con los parámetros PKCE y `resource`.
  3. En el consentimiento, marcar el preset `publish` y aprobar; capturar el redirect a loopback.
  4. Canjear el código en `POST /oauth/token` con `request` y llamar a `/mcp`.
- **Aserciones**:
  - [ ] El consentimiento muestra el nombre declarado, el host de redirección y los tres presets con `read` marcado (CA-18).
  - [ ] El redirect lleva `code` y `state` (CA-19) y el token empieza por `msk_` con `expires_in` de 30 días (CA-20).
  - [ ] El token funciona en `/mcp` y un `Origin` ajeno sigue dando 403 (CA-28).
  - [ ] Sin sesión, `/oauth/authorize` lleva a `/login` y vuelve a la misma URL tras entrar (CA-16).
- **Capturas esperadas**: pantalla de consentimiento y respuesta del token (sin el valor).
- **Cubre tareas**: T-06, T-07, T-08, T-25

### E2E-06 — Authorize con 2FA en mitad del flujo y denegar (CA-16, CA-19)
- **Objetivo**: el 2FA y el login social se encadenan con `intended` y denegar redirige con `access_denied`.
- **Precondiciones**: usuario E2E con 2FA activo y sin sesión.
- **Pasos**:
  1. Abrir `/oauth/authorize` válido sin sesión.
  2. Entrar con contraseña y superar el reto con el TOTP.
  3. En el consentimiento pulsar «Denegar».
  4. Repetir entrando con el proveedor falso en lugar de la contraseña.
- **Aserciones**:
  - [ ] Tras el reto se vuelve al consentimiento con los mismos parámetros (CA-16).
  - [ ] «Denegar» redirige a `redirect_uri?error=access_denied&state=…` (CA-19).
  - [ ] Con el login social ocurre lo mismo.
- **Capturas esperadas**: consentimiento tras el reto 2FA.
- **Cubre tareas**: T-06, T-07, T-15, T-25

### E2E-07 — Origen, revocación y coexistencia con las API keys (CA-24, CA-25, CA-26)
- **Objetivo**: el token OAuth se ve y se revoca en *API keys*, la reautorización lo sustituye y una API key manual no cambia.
- **Precondiciones**: usuario E2E con una API key manual y un token emitido por E2E-05.
- **Pasos**:
  1. Abrir `/api-keys` y localizar «OAuth · <cliente>» y la key manual.
  2. Reautorizar el mismo cliente y comprobar el token anterior.
  3. Revocar el token OAuth y llamar a `/mcp` con él y con la key manual.
- **Aserciones**:
  - [ ] El token OAuth aparece con su origen y la key manual como «Manual» (CA-25).
  - [ ] Tras reautorizar solo vive el token nuevo del par (usuario, cliente) (CA-26).
  - [ ] Tras revocar, `/mcp` responde 401 con `WWW-Authenticate … resource_metadata`; la key manual sigue funcionando (CA-24, CA-25).
- **Capturas esperadas**: tabla de API keys con la columna «Origen».
- **Cubre tareas**: T-09, T-22, T-25

## API (opcional, opt-in — smoke de endpoints, los ejecuta `qa` con curl)

### API-01 — Metadatos y 401 con `WWW-Authenticate` (CA-12, CA-13, CA-14)
- **Método y ruta**: `GET /.well-known/oauth-protected-resource/mcp`, `GET /.well-known/oauth-authorization-server` y `POST /mcp` sin Bearer (relativas a la URL local)
- **Status esperado**: 200, 200 y 401
- **Aserción sobre el body**: PRM con `"scopes_supported":["mcp:use"]`; ASM con `"code_challenge_methods_supported":["S256"]`; el 401 lleva `WWW-Authenticate: Bearer realm="mcp", resource_metadata="…/.well-known/oauth-protected-resource/mcp"`
- **Cubre tareas**: T-04

### API-02 — DCR (CA-15)
- **Método y ruta**: `POST /oauth/register`
- **Status esperado**: 201 con loopback, 400 `invalid_redirect_uri` con `https://evil.example/cb`
- **Aserción sobre el body**: `"token_endpoint_auth_method":"none"` y un `client_id`
- **Cubre tareas**: T-05

## Accesibilidad (opcional, opt-in — axe-core vía Playwright)

### A11Y-01 — Login, Cuentas vinculadas y Consentimiento
- **Página(s)**: `/login`, `/profile` (Cuentas vinculadas) y `/oauth/authorize` (con un cliente registrado y los parámetros PKCE)
- **Umbral**: 0 violaciones `serious`/`critical`, 0 px de desbordamiento horizontal a 375 y 360 px
- **Cubre tareas**: T-07, T-20, T-21, T-26

## Manuales (M — los realiza una persona)

### M-01 — Login real con Google en el dominio ngrok
- **Qué revisar**: con la app de Google real (proyecto «Metricool Login») y `GOOGLE_LOGIN_*` en `.env`, entrar desde `https://<ngrok>/login` con una cuenta de Google real, con y sin 2FA.
- **Por qué no se automatiza**: Google exige una cuenta real y bloquea la automatización; el E2E usa el proveedor falso.
- **Criterio de aceptación**:
  - [ ] El login con Google entra (o pide el reto 2FA) y la auditoría registra `auth.social.login`.
  - [ ] Una cuenta de Google con email no verificado o sin cuenta local es rechazada con el mensaje genérico.
- **Cubre tareas**: T-20, T-28

### M-02 — Login real y vinculación con Facebook
- **Qué revisar**: con la app de Meta de login en modo desarrollo y un usuario con rol, vincular Facebook desde el perfil y entrar con él; comprobar que Facebook sin vincular no entra aunque el email coincida.
- **Por qué no se automatiza**: requiere una cuenta real de Facebook y la app de Meta.
- **Criterio de aceptación**:
  - [ ] Vincular y entrar con Facebook funciona; sin vincular, se rechaza (CA-05).
  - [ ] La versión de Graph de la petición es la configurada.
- **Cubre tareas**: T-20, T-21, T-28

### M-03 — Claude Code CLI sin API key (CA-29)
- **Qué revisar**: `claude mcp add --transport http …` contra `https://<ngrok>/mcp` sin cabecera `Authorization`, `/mcp` para autenticarse, consentimiento y una llamada a una tool. Anotar versión y comando en `testing/smoke-ca29.md`.
- **Por qué no se automatiza**: depende del cliente real, de su versión y del navegador del usuario.
- **Criterio de aceptación**:
  - [ ] Se conecta solo con el navegador y llama a una tool.
  - [ ] El callback real (loopback y puerto) queda anotado.
- **Cubre tareas**: T-07, T-11

### M-04 — Claude Desktop (conector personalizado) (CA-29)
- **Qué revisar**: añadir el conector remoto con la URL del MCP, autorizar en el navegador y probar una tool. Verificar el callback `https://claude.ai/api/mcp/auth_callback` y si el plan admite conectores remotos.
- **Por qué no se automatiza**: cliente de escritorio de terceros y plan de pago.
- **Criterio de aceptación**:
  - [ ] Conecta sin API key; el callback queda anotado y, si es otro, se añade a `MCP_OAUTH_REDIRECT_ALLOWED`.
- **Cubre tareas**: T-07, T-11

### M-05 — Codex (CA-29)
- **Qué revisar**: `codex mcp add … --url …` y `codex mcp login …`; comprobar DCR, PKCE y `resource`.
- **Por qué no se automatiza**: cliente de terceros.
- **Criterio de aceptación**:
  - [ ] Conecta sin API key y llama a una tool; versión y comando verificados.
- **Cubre tareas**: T-07, T-11

### M-06 — OpenCode (CA-29)
- **Qué revisar**: entrada `remote` en `opencode.json` y `opencode mcp auth …`; comprobar si exige refresh token o CIMD.
- **Por qué no se automatiza**: cliente de terceros.
- **Criterio de aceptación**:
  - [ ] Conecta sin API key y llama a una tool; conclusión explícita sobre refresh y CIMD.
- **Cubre tareas**: T-07, T-11

### M-07 — Nginx/Hestia en el entorno real (cierra la parte de M-F6-2)
- **Qué revisar**: `curl -i https://<dominio>/.well-known/oauth-protected-resource/mcp` y `.../oauth-authorization-server` devuelven JSON (no 404 de Nginx), y que `Authorization: Bearer` llega a PHP (un token válido entra en `/mcp`).
- **Por qué no se automatiza**: depende de la configuración del servidor de producción.
- **Criterio de aceptación**:
  - [ ] Los dos `curl` devuelven 200 con JSON y `POST /mcp` sin Bearer devuelve 401 con `resource_metadata`.
  - [ ] Un Bearer válido llega a PHP-FPM.
- **Cubre tareas**: T-04, T-27, T-28

### M-08 — Teclado y lector de pantalla en las pantallas nuevas
- **Qué revisar**: Login con botones sociales, Cuentas vinculadas, Consentimiento y la columna «Origen» recorridos solo con teclado y con un lector de pantalla; en el consentimiento, que el nombre declarado no parezca verificado.
- **Por qué no se automatiza**: el juicio de usabilidad y de claridad del aviso de seguridad no lo da axe.
- **Criterio de aceptación**:
  - [ ] Todas las acciones se alcanzan con teclado y los controles tienen nombre accesible.
  - [ ] El consentimiento deja claro qué se autoriza y a qué host se redirige.
- **Cubre tareas**: T-07, T-21, T-22, T-28

## Criterios cubiertos solo por Pest

Estos criterios [GWT] no necesitan navegador y los cubren los tests de Feature/Unit de las tareas indicadas (la batería E2E no los repite):

- CA-11 (429 del callback social): T-15 y T-18.
- CA-17 (authorize sin PKCE, `plain` o `response_type=token`): T-06 y T-10.
- CA-21 (`invalid_grant` y revocación por replay): T-08 y T-10.
- CA-22 (`invalid_target` por `resource`): T-06, T-08 y T-10.
- CA-23 (token OAuth `read` no puede `create_post`): T-09.
- CA-27 (auditoría sin códigos, tokens ni secretos): T-10 y T-18.

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-04 | API-01 |
| T-05 | API-02 |
| T-07 | E2E-05, E2E-06, A11Y-01, M-03, M-04, M-05, M-06, M-08 |
| T-11 | M-03, M-04, M-05, M-06 |
| T-20 | E2E-01, E2E-02, E2E-03, A11Y-01, M-01, M-02 |
| T-21 | E2E-04, A11Y-01, M-02, M-08 |
| T-22 | E2E-07, M-08 |
| T-23 | E2E-01, E2E-02, E2E-03 |
| T-24 | E2E-01, E2E-02, E2E-03, E2E-04 |
| T-25 | E2E-05, E2E-06, E2E-07 |
| T-26 | A11Y-01 |
| T-27 | M-07 |
| T-28 | M-01, M-02, M-03, M-04, M-05, M-06, M-07, M-08 |
