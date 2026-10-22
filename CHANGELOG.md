# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added — `mcp-install` initiative (2026-10-22)

- **T-01 — Permisos predefinidos al crear la API key** Al crear una API key, atajos «Solo lectura», «Publicar» y «Todo» para marcar los permisos de un clic. (`app/Mcp/Abilities.php`, `app/Http/Controllers/ApiKeyController.php`, `resources/js/Pages/ApiKeys/Index.vue`)
- **T-02 — Configuración lista para copiar por cliente** La página de API keys explica cómo conectar Claude Code, Claude Desktop, Codex y OpenCode, con la configuración ya rellena y un botón para copiarla. (`resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/ApiKeys/mcpClients.js`, `tests/e2e/*`)
- **T-03 — Botón «Probar conexión»** Botón «Probar conexión» que comprueba la API key recién creada contra el servidor MCP y explica el error si falla. (`resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/ApiKeys/mcpClients.js`, `tests/e2e/*`)

### Added — `account-2fa-passkeys` initiative (2026-10-22)

- **T-01 — Dependencias, migración de 2FA y modelo User** Preparada la base de datos y el modelo de usuario para el doble factor; el login actual no cambia. (`composer.json`, `composer.lock`, `database/migrations/2026_11_04_090000_add_two_factor_columns_to_users_table.php`)
- **T-02 — TotpService: secreto, QR SVG, verificación ±1 con antirreplay y códigos con hash** Ya se pueden generar secretos y códigos QR de doble factor, validar los códigos de la app y usar códigos de recuperación de un solo uso. (`app/Services/TwoFactor/TotpService.php`, `tests/Unit/TotpServiceTest.php`, `app/Models/User.php`)
- **T-03 — Login partido y reto 2FA (`/two-factor-challenge`)** Si tienes el doble factor activado, tras escribir tu contraseña se te pide el código de la app (o uno de recuperación) antes de entrar; 5 fallos bloquean un minuto.
- **T-04 — Gestión del 2FA desde el perfil: activar, confirmar, desactivar y regenerar códigos** Desde tu perfil puedes activar el doble factor con tu app de autenticación, desactivarlo y generar códigos de recuperación nuevos. (`app/Http/Controllers/Profile/TwoFactorController.php`, `app/Http/Requests/Profile/TwoFactorConfirmRequest.php`, `routes/auth.php`)
- **T-05 — Comando `user:two-factor-reset`, reset de contraseña con 2FA y MCP intacto** Soporte puede desactivar el doble factor de un usuario que perdió el móvil con un comando; el reset de contraseña y las claves del MCP no se ven afectados. (`app/Console/Commands/UserTwoFactorReset.php`, `tests/Feature/Auth/TwoFactorResetCommandTest.php`, `tests/Feature/Auth/PasswordResetTest.php`)
- **T-06 — UI del 2FA: reto, Login y tarjeta «Seguridad» con TwoFactorForm** Nueva pantalla de verificación en dos pasos al entrar, y en tu perfil una sección de doble factor con QR, clave copiable y códigos de recuperación que puedes copiar o descargar.
- **T-07 — Revisión de seguridad de C-01** El doble factor pasó una revisión de seguridad independiente; se reforzó el límite de intentos del código y la protección del secreto durante la activación. (`docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `tests/Feature/Auth/TwoFactorAuditLeakTest.php`)
- **T-08 — Dependencias de passkeys, esquema y configuración WebAuthn** Preparada la base técnica de las passkeys: dependencias, tabla en la base de datos y configuración del dominio por entorno; el login actual no cambia.
- **T-09 — PasskeyGateway y auditoría por eventos** Las altas, entradas y bajas de passkeys quedan en el registro de auditoría de la cuenta, sin guardar nada de la credencial. (`app/Services/Passkeys/PasskeyGateway.php`, `app/Providers/AppServiceProvider.php`, `app/Listeners/AuditPasskeyEvents.php`)
- **T-10 — Gestión de passkeys del perfil: opciones, alta, renombrado y borrado** Ya se pueden añadir, renombrar y borrar passkeys desde el perfil (con la contraseña confirmada); no se pueden tocar las de otra cuenta. (`app/Http/Controllers/Profile/PasskeyController.php`, `app/Http/Requests/Profile/PasskeyNameRequest.php`, `routes/auth.php`)
- **T-11 — Login con passkey: opciones y verificación como `guest`** Ya se puede entrar con una passkey desde el inicio de sesión, sin contraseña ni código de doble factor; los intentos están limitados a 10 por minuto. (`app/Http/Controllers/Auth/PasskeyLoginController.php`, `routes/auth.php`, `app/Providers/AppServiceProvider.php`)
- **T-12 — Tests de la ceremonia WebAuthn con vector grabado** Las passkeys se prueban de extremo a extremo con una grabación real de un autenticador, no solo con simulaciones. (`tests/Feature/Auth/PasskeyCeremonyTest.php`, `tests/Fixtures/passkeys/`, `tests/e2e/record-passkey-vector.ts`)
- **T-13 — UI de passkeys: PasskeysForm, botón en Login y avisos de contexto** Desde el perfil se gestionan las passkeys (añadir, renombrar, eliminar) y en la pantalla de acceso aparece «Entrar con passkey»; si el navegador o la conexión no son seguros se avisa y la contraseña sigue funcionando.
- **T-14 — Revisión de seguridad de C-02** Las passkeys pasaron una revisión de seguridad independiente: avisos por correo de passkeys nuevas, errores honestos y sin delatar qué cuentas existen. (`docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `tests/Feature/Auth/PasskeyAuditLeakTest.php`)
- **T-15 — Infraestructura E2E sobre localhost:8080** Las pruebas automáticas de extremo a extremo corren ahora sobre localhost con usuarios de prueba propios, listas para comprobar el doble factor y las passkeys.
- **T-16 — E2E del 2FA: activar, reto, recuperación, bloqueo y desactivar** Las pruebas automáticas recorren ya activar el doble factor, entrar con el reto, usar un código de recuperación, el bloqueo tras cinco fallos y desactivarlo. (`tests/e2e/security.spec.ts`, `tests/e2e/helpers.ts`)
- **T-17 — E2E de passkeys con el autenticador virtual de Chromium** Las pruebas automáticas comprueban ya el alta, el renombrado y el borrado de passkeys, la entrada sin contraseña ni código y el aviso cuando el navegador no admite passkeys. (`tests/e2e/security.spec.ts`, `tests/e2e/helpers.ts`)
- **T-18 — axe y desbordamiento en Login, reto y Perfil → Seguridad** Las pruebas de accesibilidad cubren ahora el acceso con passkey, el reto del doble factor y todos los estados de Perfil → Seguridad, también en móvil de 375 y 360 px. (`tests/e2e/a11y.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/pages.spec.ts`)
- **T-19 — Documentación de usuario y operación, comprobación de sodium y cierre del ADR** Nueva guía para activar el doble factor, guardar los códigos de recuperación y usar passkeys, con qué hacer si pierdes el móvil, y guía de operación para el servidor.
- **T-20 — Revisión final, regresión completa y cierre del plan** Las cuentas pueden activar el doble factor con app de autenticación (y códigos de recuperación) y entrar sin contraseña con passkeys; todo cubierto por la batería E2E en escritorio y móvil. (`docs/roadmap/2026-10-22-account-2fa-passkeys/tasks.md`, `docs/roadmap/2026-10-22-account-2fa-passkeys/improvement-plan.md`, `docs/roadmap/README.md`)

