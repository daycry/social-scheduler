---
id: ADR-003
titulo: 2FA con TOTP propio sobre Breeze y passkeys con laravel/passkeys aislado tras un gateway
estado: propuesta
fecha: 2026-10-22
iniciativa: 2026-10-22-account-2fa-passkeys
---

# ADR-003: 2FA con TOTP propio sobre Breeze y passkeys con laravel/passkeys aislado tras un gateway

## Contexto

La cuenta web solo se protegía con email y contraseña (Breeze, cuyo `LoginRequest::authenticate()` abre la sesión). La spec pide dos cosas: 2FA opcional con TOTP y códigos de recuperación, y passkeys que sustituyan a contraseña + 2FA. Todo sin tocar el MCP (Bearer de Sanctum). Había que elegir dónde vive el 2FA (Breeze o Fortify) y qué librería de passkeys usar.

El `composer require --dry-run` sobre el lock real (`laravel/framework` v13.37.0) resolvió todas las candidatas, sin *advisories*, y marcó `laragear/webauthn` como abandonado. Fuente: [`docs/roadmap/2026-10-22-account-2fa-passkeys/design.md`](../../roadmap/2026-10-22-account-2fa-passkeys/design.md), opción O1, validada por el usuario el 2026-10-22.

## Decisión

**Breeze sigue siendo el dueño del login.** `LoginRequest::authenticate()` se parte en dos pasos, validar credenciales sin abrir sesión y abrir sesión, y un reto 2FA propio cierra el login.

**El TOTP se hace en casa:**

- `pragmarx/google2fa` ^9.1 con ventana ±1.
- Antirreplay persistente en `users.two_factor_last_used_at`.
- QR SVG con `bacon/bacon-qr-code` ^3.1.
- 8 códigos de recuperación guardados con hash.
- Se rota `remember_token` al activar el 2FA.

**Las passkeys usan `laravel/passkeys` ~0.2.1 sin sus rutas:**

- Se llama a `Passkeys::ignoreRoutes()`. Las rutas son propias, en `routes/auth.php`, con `throttle:10,1` y 404 ante una passkey ajena.
- Las rutas propias llaman a las Actions del paquete a través de `App\Services\Passkeys\PasskeyGateway`, que es el único punto de contacto con el paquete.
- El cliente es `@laravel/passkeys/vue`.

**El RP ID y los orígenes salen de `WEBAUTHN_RP_ID` / `WEBAUTHN_ORIGINS`.** Si no se definen, se usa `APP_URL`. El nombre del RP es el propio RP ID: no existe `WEBAUTHN_RP_NAME`. El `user_handle_secret` se fija con `PASSKEYS_USER_HANDLE_SECRET`.

## Alternativas descartadas

- **Breeze + TOTP propio + `spatie/laravel-passkeys` 1.8.1 + cliente Vue a mano (`@simplewebauthn/browser`).** Es estable (1.x), pero obliga a escribir código de seguridad en el cliente, su UI está pensada para Livewire y se sale del ecosistema oficial. Coste relativo M frente a S.
- **Migrar la autenticación a Fortify 1.41 sin vistas (2FA y passkeys de Fortify).** Sustituye el login de Breeze, que es el mayor riesgo de regresión, y es poco reversible. Además hereda el mismo `laravel/passkeys` ^0.2. Coste relativo L.
- **`laragear/webauthn` 5.0.2.** El paquete está abandonado y Composer recomienda `laravel/passkeys`.
- **`web-auth/webauthn-lib` directo.** `laravel/passkeys` ya es una capa fina sobre esa misma versión (5.3.9). Usarlo directo duplicaría esa capa sin ganar control.

## Consecuencias

**Lo que se gana:**

- El resto de Breeze queda intacto: registro, reset de contraseña y confirmación.
- La criptografía WebAuthn y el cliente Vue vienen de una fuente oficial.
- Las columnas de 2FA en `users` tienen los mismos nombres que en Fortify, así que una migración futura a Fortify es barata.

**Lo que se asume:**

- El reto 2FA y el servicio TOTP son código de seguridad propio, con revisión obligatoria.
- `laravel/passkeys` es pre-1.0. Subir de versión obliga a revisar este ADR y el `PasskeyGateway`.
- Cambiar de librería de passkeys obliga a migrar el JSON `credential` o a pedir a los usuarios que registren sus passkeys de nuevo.

**Condicionantes:**

- El dominio de producción debe ser estable: cambiar el RP ID invalida todas las passkeys.
- Rotar `APP_KEY` exige `APP_PREVIOUS_KEYS`, o se pierden los secretos TOTP.

## Estado

`propuesta` — validar por la revisión de dos lentes o el usuario en la puerta. Pasa a `aceptada` cuando se valida; a `obsoleta` si una decisión posterior la reemplaza (enlaza aquí la que la sustituye, nunca se borra el rastro).
