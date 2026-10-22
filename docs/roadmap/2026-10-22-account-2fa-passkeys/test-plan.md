# Plan de pruebas — Seguridad de cuenta: doble factor (TOTP) y passkeys

| | |
|---|---|
| **Estado** | borrador |
| **Plan** | [`improvement-plan.md`](improvement-plan.md) |
| **URL local** | http://localhost:8080 (E2E, `php artisan serve --host=localhost --port=8080` con `APP_URL=http://localhost:8080`, `WEBAUTHN_RP_ID=localhost`, `WEBAUTHN_ORIGINS=http://localhost:8080`). Manuales de passkey real: el dominio ngrok vigente (HTTPS) |

> Los E2E corren en los tres proyectos Chromium (`desktop`, `mobile` 375, `mobile-360`) con un **usuario E2E dedicado** (no el demo), creado en `global-setup.ts` y borrado en `global-teardown.ts`. El TOTP se calcula en Node (`crypto`, RFC 6238). Las passkeys usan el autenticador virtual de Chromium (CDP `WebAuthn.addVirtualAuthenticator` con `hasResidentKey` y `hasUserVerification`). WebAuthn no admite una IP como RP ID, por eso `localhost` y no `127.0.0.1`.

## Automáticos (E2E — los ejecuta `qa` con Playwright)

### E2E-01 — Activar el doble factor (CA-01, CA-02, CA-24)
- **Objetivo**: un usuario sin 2FA lo activa desde Perfil → Seguridad con un TOTP real y recibe 8 códigos de recuperación.
- **Precondiciones**: app en `http://localhost:8080`; usuario E2E sin 2FA y con sesión iniciada.
- **Pasos**:
  1. Abrir `/profile`, pulsar «Activar doble factor» y confirmar la contraseña.
  2. Leer el secreto en texto, calcular el TOTP en Node y probar primero un código incorrecto.
  3. Escribir el código correcto.
- **Aserciones**:
  - [ ] El código incorrecto muestra error en el campo y el 2FA no se activa (CA-02).
  - [ ] El QR tiene `alt` y el secreto es texto copiable (CA-24).
  - [ ] Tras el código correcto se muestran 8 códigos una sola vez, con copiar y descargar.
  - [ ] Al recargar, la tarjeta indica 2FA activo y no vuelve a mostrar los códigos.
- **Capturas esperadas**: QR con secreto, error de código, lista de 8 códigos.
- **Cubre tareas**: T-04, T-06, T-15, T-16

### E2E-02 — Login con reto 2FA y «recordarme» (CA-04, CA-05, CA-23)
- **Objetivo**: con 2FA activo, la contraseña sola no basta y el TOTP completa el login.
- **Precondiciones**: usuario E2E con 2FA activo y sin sesión.
- **Pasos**:
  1. En `/login`, enviar email y contraseña correctos.
  2. Comprobar la redirección a `/two-factor-challenge` y que `/dashboard` redirige a login.
  3. Escribir el TOTP calculado.
- **Aserciones**:
  - [ ] Tras la contraseña, `/dashboard` no es accesible (CA-04).
  - [ ] El campo de código tiene etiqueta visible, `autocomplete="one-time-code"` e `inputmode="numeric"` (CA-23).
  - [ ] Con el TOTP válido se llega a `/dashboard` (CA-05).
  - [ ] El mismo TOTP, reenviado en otro login dentro de su ventana, se rechaza (CA-07).
- **Capturas esperadas**: pantalla del reto, error de reutilización, dashboard.
- **Cubre tareas**: T-03, T-06, T-15, T-16

### E2E-03 — Código de recuperación de un solo uso (CA-09, CA-10)
- **Objetivo**: un código de recuperación sustituye al TOTP una vez y la regeneración invalida los anteriores.
- **Precondiciones**: usuario E2E con 2FA activo y los 8 códigos guardados por el test.
- **Pasos**:
  1. En el reto, alternar a «usar un código de recuperación» y escribir el primero.
  2. Cerrar sesión y repetir con el mismo código.
  3. Entrar con otro, regenerar códigos desde el perfil y probar uno antiguo.
- **Aserciones**:
  - [ ] El primer uso entra; el segundo muestra «código no válido».
  - [ ] Tras regenerar hay 8 códigos nuevos y los antiguos fallan.
  - [ ] El enlace de alternancia es accesible con teclado.
- **Capturas esperadas**: reto en modo recuperación, lista regenerada.
- **Cubre tareas**: T-03, T-04, T-06, T-16

### E2E-04 — Bloqueo del reto y desactivación (CA-03, CA-06; CA-08, reto caducado a los 5 min, lo cubre Pest en `TwoFactorChallengeTest`)
- **Objetivo**: 5 fallos bloquean el sexto intento y el 2FA se desactiva con contraseña + código.
- **Precondiciones**: usuario E2E con 2FA activo y sin sesión.
- **Pasos**:
  1. En el reto, enviar 5 códigos incorrectos y después el correcto.
  2. Esperar el desbloqueo, entrar y, en el perfil, desactivar con contraseña y un TOTP.
- **Aserciones**:
  - [ ] El sexto intento se bloquea con mensaje de espera en segundos aunque el código sea correcto.
  - [ ] Desactivar sin contraseña o sin código válido se rechaza.
  - [ ] Con ambos, la tarjeta vuelve a «sin 2FA» y el siguiente login no pide código.
- **Capturas esperadas**: mensaje de espera, tarjeta desactivada.
- **Cubre tareas**: T-03, T-04, T-06, T-16

### E2E-05 — Alta, segunda passkey y renombrado (CA-13, CA-14, CA-15)
- **Objetivo**: registrar passkeys con el autenticador virtual y renombrarlas.
- **Precondiciones**: `localhost:8080`; autenticador virtual con residente y verificación de usuario; usuario E2E con sesión.
- **Pasos**:
  1. En Perfil → Seguridad, «Añadir passkey» (confirma contraseña), nombre «Portátil».
  2. Añadir una segunda en otro autenticador virtual, «Móvil».
  3. Intentar registrar otra vez el primer autenticador.
  4. Renombrar «Móvil» a «Móvil 2», probar nombre vacío y de 101 caracteres.
- **Aserciones**:
  - [ ] La lista muestra las dos con nombre y fecha de alta.
  - [ ] Registrar el mismo autenticador de nuevo se rechaza.
  - [ ] El nombre nuevo aparece; vacío y >100 caracteres se rechazan.
- **Capturas esperadas**: lista con 2 passkeys, error de nombre.
- **Cubre tareas**: T-10, T-13, T-15, T-17

### E2E-06 — Login con passkey sin contraseña ni reto (CA-17, CA-18)
- **Objetivo**: la passkey sustituye a contraseña + 2FA, y una passkey borrada no sirve.
- **Precondiciones**: usuario E2E con 2FA activo y una passkey; sin sesión; mismo autenticador virtual.
- **Pasos**:
  1. En `/login`, pulsar «Entrar con passkey».
  2. Cerrar sesión, borrar la passkey desde el perfil (con confirmación) y repetir el intento.
- **Aserciones**:
  - [ ] La primera vez entra a `/dashboard` sin contraseña y sin ver el reto 2FA.
  - [ ] Tras borrarla, el intento falla con un mensaje que no revela si existe la cuenta (CA-18).
  - [ ] La passkey borrada desaparece de la lista (CA-16).
- **Capturas esperadas**: dashboard tras passkey, mensaje de fallo.
- **Cubre tareas**: T-11, T-13, T-15, T-17

### E2E-07 — Contexto no seguro o sin WebAuthn (CA-20)
- **Objetivo**: sin WebAuthn, el login con contraseña funciona y se avisa del HTTPS.
- **Precondiciones**: contexto con `navigator.credentials` deshabilitado (script de inicio) o sirviendo por `http://127.0.0.1:8080`.
- **Pasos**:
  1. Abrir `/login` y luego Perfil → Seguridad.
  2. Iniciar sesión con contraseña.
- **Aserciones**:
  - [ ] No aparecen el botón ni la tarjeta de passkeys.
  - [ ] Se ve «Las passkeys requieren HTTPS».
  - [ ] El login con contraseña funciona igual.
- **Capturas esperadas**: login con aviso, perfil con aviso.
- **Cubre tareas**: T-13, T-17

## API (opcional, opt-in — smoke de endpoints, los ejecuta `qa` con curl)

### API-01 — MCP intacto con 2FA activo (CA-22)
- **Método y ruta**: `POST /mcp` con `Authorization: Bearer <api key del usuario E2E con 2FA>` (relativa a la URL local)
- **Status esperado**: el mismo que sin 2FA (200 con el `initialize`/`tools/list` JSON-RPC), sin redirección ni reto
- **Aserción sobre el body**: `jsonrpc` presente y sin `Location`; la misma petición con cookie de sesión a medio reto 2FA y sin Bearer devuelve 401
- **Cubre tareas**: T-05

## Accesibilidad (opcional, opt-in — axe-core vía Playwright)

### A11Y-01 — Login, reto 2FA y Perfil → Seguridad (CA-23, CA-24)
- **Página(s)**: `/login`, `/two-factor-challenge` (con reto pendiente), `/profile` (estados: sin 2FA, QR visible, códigos visibles, lista de passkeys). Proyectos `desktop`, `mobile` (375) y `mobile-360`.
- **Umbral**: 0 violaciones `serious`/`critical`; 0 px de desbordamiento horizontal a 375 y 360 px
- **Cubre tareas**: T-06, T-13, T-18

## Manuales (M — los realiza una persona)

### M-01 — Passkey real con Windows Hello en el dominio ngrok
- **Qué revisar**: alta y login con una passkey real de Windows Hello (PIN o huella) en `https://workable-humorous-rat.ngrok-free.app` (o el dominio vigente), con `WEBAUTHN_RP_ID` y `WEBAUTHN_ORIGINS` apuntando a ese host.
- **Por qué no se automatiza**: requiere el autenticador de la plataforma y un gesto biométrico real.
- **Criterio de aceptación**:
  - [ ] El alta con nombre funciona y la passkey aparece en la lista.
  - [ ] Cerrar sesión y «Entrar con passkey» entra sin contraseña ni código.
  - [ ] Cancelar el diálogo muestra «No se completó la passkey» sin error técnico.
- **Cubre tareas**: T-13

### M-02 — Passkey real en el móvil (iPhone o Android) por ngrok
- **Qué revisar**: alta y login desde el navegador del móvil por ngrok, y uso de la passkey desde el gestor (iCloud, Google Password Manager, 1Password) si está sincronizada.
- **Por qué no se automatiza**: dependen del dispositivo, del gestor de claves y de la red real.
- **Criterio de aceptación**:
  - [ ] Alta y login con huella o Face ID en el móvil.
  - [ ] El autocompletado del campo email ofrece la passkey (autofill condicional).
  - [ ] Sin desbordamiento horizontal en la pantalla real.
- **Cubre tareas**: T-13

### M-03 — TOTP con una app de autenticación real
- **Qué revisar**: escanear el QR con Google Authenticator, 1Password o Aegis y completar activación y login.
- **Por qué no se automatiza**: el E2E calcula el TOTP en Node; no valida que el QR sea compatible con apps reales.
- **Criterio de aceptación**:
  - [ ] La app acepta el QR y muestra la cuenta con el nombre esperado.
  - [ ] El código de la app activa el 2FA y supera el reto.
  - [ ] El reloj del móvil desfasado ±30 s sigue funcionando (ventana ±1).
- **Cubre tareas**: T-06

### M-04 — `sodium` y RP ID en el PHP de Hestia (producción)
- **Qué revisar**: `php -m | grep -i sodium` en el servidor (si falta, `paragonie/sodium_compat`), y `WEBAUTHN_RP_ID`, `WEBAUTHN_ORIGINS` y `PASSKEYS_USER_HANDLE_SECRET` en el `.env` de producción con el dominio definitivo.
- **Por qué no se automatiza**: es el entorno de producción y no hay acceso desde los tests.
- **Criterio de aceptación**:
  - [ ] `sodium` aparece en `php -m` del PHP de Hestia (o está `sodium_compat`).
  - [ ] Una passkey real se registra y entra en el dominio de producción.
  - [ ] El dominio de producción es estable (cambiarlo invalida todas las passkeys).
- **Cubre tareas**: T-19

### M-05 — Teclado y lector de pantalla (CA-23, CA-24)
- **Qué revisar**: recorrer reto 2FA y Perfil → Seguridad solo con teclado y con un lector de pantalla (NVDA o VoiceOver).
- **Por qué no se automatiza**: axe detecta reglas, no valida el orden de lectura ni la comprensión.
- **Criterio de aceptación**:
  - [ ] Los errores del código se anuncian al producirse.
  - [ ] Copiar y descargar los códigos de recuperación funciona sin ratón.
  - [ ] El enlace «usar un código de recuperación» se alcanza y activa con teclado.
- **Cubre tareas**: T-06, T-13

### M-06 — Pérdida del móvil: reset por operador en el entorno real (CA-12)
- **Qué revisar**: con un usuario real de pruebas con 2FA, ejecutar `php artisan user:two-factor-reset {email}` y comprobar que entra solo con contraseña y que queda `auth.2fa.reset_by_operator` en `audit_logs`.
- **Por qué no se automatiza**: Pest cubre el comando; esto valida el procedimiento de soporte en el entorno real.
- **Criterio de aceptación**:
  - [ ] El comando desactiva el 2FA y pide confirmación.
  - [ ] El usuario entra con su contraseña.
  - [ ] La auditoría registra el reset sin datos sensibles.
- **Cubre tareas**: T-05

## Cobertura (tarea → escenarios)

| Tarea | Cubierta por |
|-------|--------------|
| T-03 | E2E-02, E2E-03, E2E-04 |
| T-04 | E2E-01, E2E-03, E2E-04 |
| T-05 | API-01, M-06 |
| T-06 | E2E-01, E2E-02, E2E-03, E2E-04, A11Y-01, M-03, M-05 |
| T-10 | E2E-05 |
| T-11 | E2E-06 |
| T-13 | E2E-05, E2E-06, E2E-07, A11Y-01, M-01, M-02, M-05 |
| T-15 | E2E-01, E2E-02, E2E-05, E2E-06 |
| T-16 | E2E-01, E2E-02, E2E-03, E2E-04 |
| T-17 | E2E-05, E2E-06, E2E-07 |
| T-18 | A11Y-01 |
| T-19 | M-04 |
| T-20 | E2E-01..E2E-07, API-01, A11Y-01, M-01..M-06 |
