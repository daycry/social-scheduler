import { expect, test, type APIRequestContext, type Page, type TestInfo } from '@playwright/test';
import {
    OAUTH_REDIRECT,
    clearFakeSocial,
    clearOAuthThrottle,
    clearSocialThrottle,
    clearTotpReplay,
    createManualKey,
    deleteE2eOAuthClients,
    e2eUser,
    enableTwoFactorDirect,
    expectNoOverflow,
    linkSocialDirect,
    loginAs,
    mcpToolsList,
    pkce,
    resetSecurity,
    setFakeSocial,
    stamp,
    totp,
    watch,
    type E2eUser,
} from './helpers';

/**
 * Servidor OAuth del MCP en el navegador (ADR-004): un cliente simulado registra (DCR), autoriza con PKCE, consiente,
 * recibe el redirect a loopback (interceptado: nadie escucha en 127.0.0.1:53111), canjea el código con `request` y llama
 * a `/mcp`. Solo usa los usuarios `e2e-sec-*` y clientes `e2e-*`, que se borran en cada test y en el teardown global.
 */

const shot = async (page: Page, info: TestInfo, name: string) =>
    info.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

/** Cierra la sesión sin pasar por el menú: las cookies son la sesión. */
const logout = (page: Page) => page.context().clearCookies();

let user: E2eUser;

test.beforeEach(async ({ page }, info) => {
    user = e2eUser(info.project.name);
    resetSecurity(user.email); // también borra identidades y tokens del usuario
    clearFakeSocial();
    clearSocialThrottle();
    clearOAuthThrottle();
    deleteE2eOAuthClients();
    // Nadie escucha en el puerto de loopback: el redirect del servidor se intercepta y se lee su URL.
    await page.route('http://127.0.0.1:53111/**', (route) => route.fulfill({ status: 200, contentType: 'text/plain', body: 'loopback' }));
});
test.afterEach(() => {
    resetSecurity(user.email);
    deleteE2eOAuthClients();
    clearFakeSocial();
});

type Client = { client_id: string; client_name: string };

async function register(request: APIRequestContext, label: string): Promise<Client> {
    const name = `e2e-${label}-${stamp()}`;
    const response = await request.post('/oauth/register', { data: { client_name: name, redirect_uris: [OAUTH_REDIRECT] } });
    expect(response.status()).toBe(201);
    return response.json();
}

function authorizeUrl(baseURL: string, client: Client, state: string, challenge: string): string {
    const query = new URLSearchParams({
        response_type: 'code',
        client_id: client.client_id,
        redirect_uri: OAUTH_REDIRECT,
        code_challenge: challenge,
        code_challenge_method: 'S256',
        state,
        resource: new URL('/mcp', baseURL).href,
    });
    return `/oauth/authorize?${query}`;
}

/** Espera a la redirección a loopback y devuelve sus parámetros. */
async function loopbackParams(page: Page): Promise<URLSearchParams> {
    await page.waitForURL(/127\.0\.0\.1:53111/);
    return new URL(page.url()).searchParams;
}

/** Entra con el formulario clásico desde la redirección de `auth` (vuelve al destino `intended`). */
async function passwordLogin(page: Page, who: E2eUser) {
    await page.getByLabel('Correo electrónico').fill(who.email);
    await page.getByLabel('Contraseña').fill(who.password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
}

async function exchange(request: APIRequestContext, baseURL: string, client: Client, code: string, verifier: string) {
    return request.post('/oauth/token', {
        form: { grant_type: 'authorization_code', code, redirect_uri: OAUTH_REDIRECT, client_id: client.client_id, code_verifier: verifier, resource: new URL('/mcp', baseURL).href },
    });
}

/** Con la sesión iniciada: autoriza al cliente con el preset indicado y devuelve el token canjeado. */
async function obtainToken(page: Page, request: APIRequestContext, baseURL: string, client: Client, preset: string) {
    const { verifier, challenge } = pkce();
    const state = `st-${stamp()}`;
    await page.goto(authorizeUrl(baseURL, client, state, challenge));
    await page.getByRole('button', { name: preset, exact: true }).click();
    await page.getByRole('button', { name: 'Autorizar' }).click();
    const params = await loopbackParams(page);
    expect(params.get('state')).toBe(state);
    const response = await exchange(request, baseURL, client, params.get('code')!, verifier);
    expect(response.status()).toBe(200);
    return (await response.json()) as { access_token: string; expires_in: number };
}

test.describe('MCP OAuth · API (sin interfaz)', () => {
    test('API-01 · metadatos y 401 con WWW-Authenticate (CA-12, CA-13, CA-14)', async ({ request, baseURL }) => {
        const unauth = await request.post('/mcp', { headers: { Accept: 'application/json, text/event-stream' }, data: { jsonrpc: '2.0', id: 1, method: 'initialize' } });
        expect(unauth.status()).toBe(401);
        const challenge = unauth.headers()['www-authenticate'];
        expect(challenge).toContain('Bearer realm="mcp"');
        expect(challenge).toContain(`resource_metadata="${new URL('/.well-known/oauth-protected-resource/mcp', baseURL).href}"`);

        const prm = await request.get('/.well-known/oauth-protected-resource/mcp');
        expect(prm.status()).toBe(200);
        expect((await prm.json()).scopes_supported).toEqual(['mcp:use']);

        const asm = await request.get('/.well-known/oauth-authorization-server');
        expect(asm.status()).toBe(200);
        expect((await asm.json()).code_challenge_methods_supported).toEqual(['S256']);
    });

    test('API-02 · DCR: 201 con loopback y 400 con una redirect_uri pública (CA-15)', async ({ request }) => {
        const ok = await request.post('/oauth/register', { data: { client_name: `e2e-dcr-${stamp()}`, redirect_uris: [OAUTH_REDIRECT] } });
        expect(ok.status()).toBe(201);
        const body = await ok.json();
        expect(body.token_endpoint_auth_method).toBe('none');
        expect(body.client_id).toBeTruthy();

        const bad = await request.post('/oauth/register', { data: { client_name: `e2e-dcr-${stamp()}`, redirect_uris: ['https://evil.example/cb'] } });
        expect(bad.status()).toBe(400);
        expect((await bad.json()).error).toBe('invalid_redirect_uri');
    });
});

test.describe('MCP OAuth · navegador', () => {
    test('E2E-05 · registro, consentimiento con preset publish, token en /mcp y Origin ajeno (CA-16, CA-18, CA-19, CA-20, CA-28)', async ({ page, request, baseURL }, info) => {
        const w = watch(page);
        const client = await register(request, 'flujo');
        const { verifier, challenge } = pkce();
        const state = `st-${stamp()}`;
        const url = authorizeUrl(baseURL!, client, state, challenge);

        // CA-16: sin sesión lleva a /login y, tras entrar, vuelve a la misma URL de autorización.
        await page.goto(url);
        await expect(page).toHaveURL(/\/login/);
        await passwordLogin(page, user);
        await page.waitForURL(/\/oauth\/authorize/);
        const back = new URL(page.url());
        expect(back.searchParams.get('client_id')).toBe(client.client_id);
        expect(back.searchParams.get('state')).toBe(state);
        expect(back.searchParams.get('code_challenge')).toBe(challenge);

        // CA-18: nombre declarado, host de redirección y los tres presets con «solo lectura» marcado.
        await expect(page.getByRole('heading', { name: 'Autorizar acceso al agente' })).toBeVisible();
        await expect(page.getByText(`«${client.client_name}»`)).toBeVisible();
        await expect(page.getByText('127.0.0.1:53111')).toBeVisible();
        for (const label of ['Solo lectura', 'Publicar', 'Todo']) await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Solo lectura', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('button', { name: 'Publicar', exact: true })).toHaveAttribute('aria-pressed', 'false');
        // Duración del acceso: selector con label asociado, 90 días por defecto y las cuatro opciones.
        const duration = page.getByLabel('Duración del acceso');
        await expect(duration).toHaveValue('90');
        await expect(duration.locator('option')).toHaveText(['30 días', '90 días', '6 meses', '1 año']);
        await expectNoOverflow(page);
        await shot(page, info, 'consentimiento');

        await page.getByRole('button', { name: 'Publicar', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Publicar', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await page.getByRole('button', { name: 'Autorizar' }).click();

        // CA-19: el redirect lleva `code` y `state`.
        const params = await loopbackParams(page);
        expect(params.get('state')).toBe(state);
        expect(params.get('code')).toBeTruthy();
        expect(params.get('error')).toBeNull();

        // CA-20: el token es msk_ y dura 90 días (default del selector «Duración del acceso»).
        const response = await exchange(request, baseURL!, client, params.get('code')!, verifier);
        expect(response.status()).toBe(200);
        const token = await response.json();
        expect(token.access_token).toContain('|msk_');
        expect(token.token_type).toBe('Bearer');
        expect(token.expires_in).toBe(90 * 86400);
        // El token funciona en /mcp; un Origin ajeno sigue dando 403 (CA-28).
        expect((await mcpToolsList(request, token.access_token)).status()).toBe(200);
        expect((await mcpToolsList(request, token.access_token, 'https://evil.example')).status()).toBe(403);

        // CA-21: el código es de un solo uso y reutilizarlo revoca el token que emitió.
        expect((await exchange(request, baseURL!, client, params.get('code')!, verifier)).status()).toBe(400);
        expect((await mcpToolsList(request, token.access_token)).status()).toBe(401);
        expect(w.errors.filter((e) => !/status of (409|404)/.test(e) && !/127\.0\.0\.1:53111/.test(e))).toEqual([]);
    });

    test('E2E-06 · 2FA en mitad del flujo y «Denegar» (CA-16, CA-19)', async ({ page, request, baseURL }, info) => {
        const { secret } = enableTwoFactorDirect(user.email);
        const client = await register(request, 'denegar');
        const { challenge } = pkce();
        const state = `st-${stamp()}`;
        const url = authorizeUrl(baseURL!, client, state, challenge);

        // Contraseña + TOTP: tras el reto se vuelve al consentimiento con los mismos parámetros.
        await page.goto(url);
        await expect(page).toHaveURL(/\/login/);
        await passwordLogin(page, user);
        await page.waitForURL(/two-factor-challenge/);
        await page.getByLabel('Código de verificación').fill(totp(secret));
        await page.getByRole('button', { name: 'Verificar' }).click();
        await page.waitForURL(/\/oauth\/authorize/);
        expect(new URL(page.url()).searchParams.get('state')).toBe(state);
        await expect(page.getByRole('heading', { name: 'Autorizar acceso al agente' })).toBeVisible();
        await shot(page, info, 'consentimiento-tras-2fa');

        await page.getByRole('button', { name: 'Denegar' }).click();
        let params = await loopbackParams(page);
        expect(params.get('error')).toBe('access_denied');
        expect(params.get('state')).toBe(state);
        expect(params.get('code')).toBeNull();

        // Lo mismo entrando con el proveedor social en lugar de la contraseña.
        await logout(page);
        clearTotpReplay(user.email);
        const id = `g-${stamp()}`;
        linkSocialDirect(user.email, 'google', id, user.email);
        setFakeSocial('google', { id, email: user.email, email_verified: true });
        await page.goto(url);
        await expect(page).toHaveURL(/\/login/);
        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await page.waitForURL(/two-factor-challenge/);
        await page.getByLabel('Código de verificación').fill(totp(secret, 1)); // otro paso: el anterior ya se usó
        await page.getByRole('button', { name: 'Verificar' }).click();
        await page.waitForURL(/\/oauth\/authorize/);
        expect(new URL(page.url()).searchParams.get('state')).toBe(state);
        await page.getByRole('button', { name: 'Denegar' }).click();
        params = await loopbackParams(page);
        expect(params.get('error')).toBe('access_denied');
        expect(params.get('state')).toBe(state);
    });

    test('E2E-07 · origen, reautorización, revocación y coexistencia con las API keys (CA-24, CA-25, CA-26)', async ({ page, request, baseURL }, info) => {
        const manual = createManualKey(user.email, 'e2e key manual');
        await loginAs(page, user);
        const client = await register(request, 'origen');

        const first = await obtainToken(page, request, baseURL!, client, 'Solo lectura');
        expect((await mcpToolsList(request, first.access_token)).status()).toBe(200);

        // CA-25: el token OAuth aparece con su origen y la key manual como «Manual».
        await page.goto('/api-keys');
        const oauthRow = page.locator('li', { hasText: `OAuth · ${client.client_name}` });
        const manualRow = page.locator('li', { hasText: 'e2e key manual' });
        await expect(oauthRow).toHaveCount(1);
        await expect(oauthRow.getByTestId('key-origin')).toHaveText(`Origen: OAuth · ${client.client_name}`);
        await expect(manualRow.getByTestId('key-origin')).toHaveText('Origen: Manual');
        await expectNoOverflow(page);
        await shot(page, info, 'api-keys-origen');

        // CA-26: reautorizar el mismo cliente sustituye al token anterior.
        const second = await obtainToken(page, request, baseURL!, client, 'Publicar');
        expect((await mcpToolsList(request, first.access_token)).status()).toBe(401);
        expect((await mcpToolsList(request, second.access_token)).status()).toBe(200);
        await page.goto('/api-keys');
        await expect(page.locator('li', { hasText: `OAuth · ${client.client_name}` })).toHaveCount(1);

        // Revocar el token OAuth: /mcp responde 401 con resource_metadata y la key manual sigue funcionando (CA-24, CA-25).
        page.once('dialog', (d) => d.accept());
        await page.getByRole('button', { name: `Revocar OAuth · ${client.client_name}` }).click();
        await expect(page.locator('li', { hasText: `OAuth · ${client.client_name}` })).toHaveCount(0);
        const revoked = await mcpToolsList(request, second.access_token);
        expect(revoked.status()).toBe(401);
        expect(revoked.headers()['www-authenticate']).toContain('resource_metadata=');
        expect((await mcpToolsList(request, manual)).status()).toBe(200);
        await expect(manualRow.getByTestId('key-origin')).toHaveText('Origen: Manual');
    });
});
