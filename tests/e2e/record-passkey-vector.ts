/**
 * Graba el vector de la ceremonia WebAuthn que usa tests/Feature/Auth/PasskeyCeremonyTest.php (T-12).
 *
 *   node tests/e2e/record-passkey-vector.ts          (Node >= 22.18, sin transpilar)
 *
 * Sirve una pagina minima en http://localhost:8080 (localhost es contexto seguro) interceptando la peticion con
 * `page.route`, de modo que NO abre ningun puerto y no choca con `php artisan serve`. Crea un autenticador virtual
 * de Chromium por CDP (`WebAuthn.addVirtualAuthenticator`) y hace UN alta (attestation) y TRES logins (assertion)
 * con retos aleatorios. Escribe tests/Fixtures/passkeys/vector.json con las opciones y las respuestas del navegador.
 *
 * Solo se guardan datos publicos (clave publica, firmas, retos): la clave privada vive y muere en el autenticador
 * virtual del navegador. No hace falta que la app este en marcha. Solo hay que regrabar si cambia el formato.
 */
import { chromium } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

// El origen es parte de lo que valida el servidor (esquema + host + puerto): el test usa este mismo valor.
const ORIGIN = 'http://localhost:8080';
const RP_ID = 'localhost';
const OUT = path.resolve(import.meta.dirname, '../Fixtures/passkeys/vector.json');

const b64url = (buf: Buffer) => buf.toString('base64url');

const browser = await chromium.launch();
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.route(`${ORIGIN}/**`, (route) =>
        route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>vector</title><p>Grabando vector de passkeys</p>' }),
    );
    await page.goto(ORIGIN);
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    const registrationOptions = {
        rp: { name: RP_ID, id: RP_ID },
        user: { id: b64url(randomBytes(32)), name: 'vector@example.test', displayName: 'Vector de prueba' },
        challenge: b64url(randomBytes(32)),
        pubKeyCredParams: [
            { type: 'public-key', alg: -7 },
            { type: 'public-key', alg: -257 },
        ],
        timeout: 60000,
        excludeCredentials: [],
        authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
        attestation: 'none',
    };

    const registrationCredential = await page.evaluate(async (options) => {
        const fromB64 = (s: string) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
        const credential = (await navigator.credentials.create({
            publicKey: {
                ...options,
                challenge: fromB64(options.challenge),
                user: { ...options.user, id: fromB64(options.user.id) },
            },
        })) as PublicKeyCredential;
        return credential.toJSON();
    }, registrationOptions);

    const assertions: { options: object; credential: object }[] = [];
    for (let i = 0; i < 3; i++) {
        const options = {
            challenge: b64url(randomBytes(32)),
            rpId: RP_ID,
            timeout: 60000,
            userVerification: 'required',
            allowCredentials: [],
        };
        const credential = await page.evaluate(async (opts) => {
            const fromB64 = (s: string) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
            const result = (await navigator.credentials.get({
                publicKey: { ...opts, challenge: fromB64(opts.challenge), allowCredentials: [] },
            })) as PublicKeyCredential;
            return result.toJSON();
        }, options);
        assertions.push({ options, credential });
    }

    // Contador de firmas de cada assertion (bytes 33..36 de authenticatorData, big endian), solo informativo.
    const counters = assertions.map((a) => {
        const data = Buffer.from((a.credential as any).response.authenticatorData, 'base64url');
        return data.readUInt32BE(33);
    });

    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    fs.writeFileSync(
        OUT,
        JSON.stringify(
            {
                _about: 'Vector grabado con el autenticador virtual de Chromium (ver README.md). Solo datos publicos.',
                origin: ORIGIN,
                rpId: RP_ID,
                chromium: browser.version(),
                counters,
                registration: { options: registrationOptions, credential: registrationCredential },
                assertions,
            },
            null,
            2,
        ) + '\n',
    );
    console.log(`Vector escrito en ${OUT} (contadores de las assertions: ${counters.join(', ')})`);
} finally {
    await browser.close();
}
