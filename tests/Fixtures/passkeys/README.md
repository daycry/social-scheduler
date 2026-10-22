# Vector de la ceremonia WebAuthn (T-12)

`vector.json` es una grabación real de una ceremonia WebAuthn hecha con el autenticador virtual de Chromium
(CDP `WebAuthn.addVirtualAuthenticator`, `ctap2` / `internal` / credencial residente / verificación de usuario).
La usa `tests/Feature/Auth/PasskeyCeremonyTest.php` para probar la verificación criptográfica real (firma, reto,
origen, RP ID y contador) a través de `PasskeyGateway`, sin sustituir ninguna Action de `laravel/passkeys`.

| Campo | Valor |
|---|---|
| Origen | `http://localhost:8080` |
| RP ID | `localhost` |
| Contenido | 1 alta (`registration`: opciones + attestation) y 3 logins (`assertions`: opciones + assertion) |
| Contadores | alta = 1; assertions = 2, 3, 4 (el autenticador virtual no emite contador 0) |

## Qué contiene (y qué no)

Solo datos públicos: clave pública dentro de la attestation, firmas, retos y metadatos del cliente. La clave
privada se quedó en el autenticador virtual y desapareció con el navegador, así que el vector no sirve para
suplantar nada fuera de este test (la credencial es de `localhost` y el reto de cada ceremonia es de un solo uso).

## Regrabarlo

Solo hace falta si cambia el formato de las opciones o de `webauthn-lib`:

```bash
node tests/e2e/record-passkey-vector.ts   # Node >= 22.18; no abre ningún puerto ni necesita la app en marcha
```

El script intercepta `http://localhost:8080` con `page.route`, así que funciona aunque `php artisan serve` ya
ocupe ese puerto. Al regrabar, los retos cambian pero los tests no dependen de sus valores.

## Contador 0 → 0

El autenticador virtual nunca devuelve contador 0, así que `PasskeyCeremonyTest` cubre esa regla (passkeys
sincronizadas) ejecutando el paso `CheckCounter` de `webauthn-lib` sobre una assertion real con el contador de
`authenticatorData` forzado: 0 → 0 se tolera; igual, menor o 0 tras un contador > 0 se rechazan. El login completo
con contador 0 queda fuera de este vector (la firma ya no cuadraría) y se cubre por esa regla de la librería.
