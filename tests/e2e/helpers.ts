import { expect, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const DEMO = {
    email: process.env.DEMO_USER_EMAIL || 'demo@social-scheduler.test',
    password: process.env.DEMO_USER_PASSWORD || '',
};

export const AUTH_FILE = 'tests/e2e/.auth/demo.json';

/** Páginas públicas (sin sesión). */
export const PUBLIC_PAGES: Array<[string, string]> = [
    ['portada', '/'],
    ['login', '/login'],
    ['registro', '/register'],
    ['recuperar contraseña', '/forgot-password'],
    ['privacidad', '/privacy'],
    ['términos', '/terms'],
];

/** Páginas autenticadas del producto. */
export const AUTH_PAGES: Array<[string, string]> = [
    ['dashboard', '/dashboard'],
    ['dashboard instagram', '/dashboard/instagram'],
    ['dashboard facebook', '/dashboard/facebook'],
    ['dashboard youtube', '/dashboard/youtube'],
    ['dashboard tiktok', '/dashboard/tiktok'],
    ['calendario', '/calendar'],
    ['calendario lista', '/calendar?view=list'],
    ['calendario mes', '/calendar?view=month'],
    ['marcas', '/brands'],
    ['nueva marca', '/brands/create'],
    ['informes', '/reports'],
    ['api-keys', '/api-keys'],
    ['avisos', '/notifications'],
    ['perfil', '/profile'],
    ['confirmar contraseña', '/confirm-password'],
    ['mejores horas', '/best-times'],
    ['biblioteca', '/library'],
    ['papelera', '/trash'],
    ['smartlinks', '/smartlinks'],
    ['competidores', '/competitors'],
    ['autolistas', '/autolists'],
    ['inbox', '/inbox'],
    ['meta ads', '/ads'],
];

export type Watch = { errors: string[] };

/** Escucha errores de consola y excepciones de la página; devuelve la lista viva. */
export function watch(page: Page): Watch {
    const w: Watch = { errors: [] };
    page.on('console', (m) => {
        if (m.type() === 'error') w.errors.push(`console: ${m.text()}`);
    });
    page.on('pageerror', (e) => w.errors.push(`pageerror: ${e.message}`));
    return w;
}

/** Desbordamiento horizontal: el documento no debe ser más ancho que la ventana. */
export async function overflowX(page: Page): Promise<number> {
    return page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth);
}

export async function expectNoOverflow(page: Page) {
    expect(await overflowX(page), 'desbordamiento horizontal (px)').toBeLessThanOrEqual(0);
}

export async function login(page: Page, email = DEMO.email, password = DEMO.password) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/\/dashboard/);
}

/** Espera a que la red quede en reposo (tras un router.post de Inertia). */
export async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
}

export const stamp = () => Date.now().toString(36);

/** Borra las marcas cuyo nombre contiene `prefix` (por defecto todas las `e2e-`); con ellas se van sus datos. */
export async function borrarMarcasE2e(page: Page, prefix = 'e2e-') {
    page.on('dialog', (d) => d.accept().catch(() => {}));
    for (let i = 0; i < 20; i++) {
        await page.goto('/brands');
        const row = page.getByRole('main').locator('li', { hasText: prefix }).first();
        if (!(await row.count())) return;
        await row.getByRole('button', { name: 'Eliminar' }).click();
        await settle(page);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Usuarios E2E dedicados (seguridad de cuenta): uno por proyecto, para no tocar al demo ni compartir estado entre
// proyectos. Se crean en global-setup y se borran en global-teardown; todos llevan el prefijo `e2e-`.
// ---------------------------------------------------------------------------------------------------------------

export const E2E_USERS_FILE = 'tests/e2e/.auth/e2e-users.json';
export const E2E_START_FILE = 'tests/e2e/.auth/e2e-start.txt';
export const E2E_PROJECTS = ['desktop', 'mobile', 'mobile-360'] as const;

export type E2eUser = { email: string; password: string; name: string };

export const e2eEmail = (project: string) => `e2e-sec-${project}@example.test`;

/** Ejecuta PHP en el contexto de la app (tinker) y devuelve su salida. Solo para preparar/limpiar datos `e2e-`. */
export function artisan(code: string): string {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    // tinker devuelve exit 0 aunque el código falle: se detecta por el nombre de la excepción en la salida.
    if (/^([A-Za-z]+[\\])*[A-Za-z]*(Error|Exception)\b/m.test(out)) throw new Error(`artisan tinker falló: ${out.slice(0, 300)}`);
    return out;
}

/** Literal PHP entre comillas simples. */
const q = (value: string) => `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

/** Vuelve al estado «sin 2FA, sin passkeys, sin bloqueos» (PHP). `$u` queda definido. */
const cleanUserPhp = (email: string) =>
    `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
    `$u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_at' => null])->save(); ` +
    `$u->passkeys()->delete(); Illuminate\\Support\\Facades\\RateLimiter::clear('2fa:' . $u->id); ` +
    `$u->socialIdentities()->delete(); $u->tokens()->delete(); ` +
    `foreach (['127.0.0.1', '::1'] as $ip) { Illuminate\\Support\\Facades\\RateLimiter::clear(Illuminate\\Support\\Str::lower(${q(email)}) . '|' . $ip); }`;

/** Crea (o deja limpios) los usuarios E2E y guarda sus credenciales en `.auth` (ignorado por git). */
export function createE2eUsers(): Record<string, E2eUser> {
    const users: Record<string, E2eUser> = {};
    for (const project of E2E_PROJECTS) {
        const user = { email: e2eEmail(project), name: `e2e Seguridad ${project}`, password: `E2e-${crypto.randomBytes(9).toString('hex')}-Aa1!` };
        artisan(
            `$u = App\\Models\\User::firstOrNew(['email' => ${q(user.email)}]); ` +
                `$u->forceFill(['name' => ${q(user.name)}, 'password' => Illuminate\\Support\\Facades\\Hash::make(${q(user.password)}), 'email_verified_at' => now()])->save();`,
        );
        resetSecurity(user.email);
        users[project] = user;
    }
    fs.mkdirSync(path.dirname(E2E_USERS_FILE), { recursive: true });
    fs.writeFileSync(E2E_USERS_FILE, JSON.stringify(users));
    fs.writeFileSync(E2E_START_FILE, new Date().toISOString().slice(0, 19).replace('T', ' '));
    clearFakeSocial();
    return users;
}

/** Borra los usuarios E2E (con ellos se van sus passkeys, marcas y sesiones asociadas por cascada). */
export function deleteE2eUsers() {
    // La auditoria pone user_id a NULL al borrar el usuario: sus filas se borran antes para no dejar huerfanas.
    artisan(
        `$ids = App\\Models\\User::where('email', 'like', 'e2e-sec-%@example.test')->pluck('id'); ` +
            `Illuminate\\Support\\Facades\\DB::table('audit_logs')->whereIn('user_id', $ids)->delete(); ` +
            // Los tokens de Sanctum no tienen FK al usuario: se borran a mano (keys manuales y tokens OAuth de los tests).
            `Illuminate\\Support\\Facades\\DB::table('personal_access_tokens')->where('tokenable_type', App\\Models\\User::class)->whereIn('tokenable_id', $ids)->delete(); ` +
            `App\\Models\\User::whereIn('id', $ids)->get()->each->delete();`,
    );
    // Auditoría sin usuario (user_id NULL): SOLO se borra la identificable como e2e, a partir de los ids de los clientes `e2e-%`
    // (registros DCR: `detail` = `client_id=<id>`), y se hace ANTES de borrar los clientes. Los rechazos del login social
    // (`auth.social.login` con user_id NULL) solo llevan proveedor y motivo: no hay forma fiable de atribuirlos a la batería y
    // se dejan (filas de E2E sobrantes son aceptables; borrar auditoría real, no).
    artisan(
        `$cid = App\\Models\\OAuthClient::where('name', 'like', 'e2e-%')->pluck('id'); ` +
            `Illuminate\\Support\\Facades\\DB::table('audit_logs')->whereNull('user_id')->where('action', 'like', 'oauth.%')->where(fn ($w) => $cid->each(fn ($id) => $w->orWhere('detail', 'like', 'client_id=' . $id . '%')))->whereIn('ip', ['127.0.0.1', '::1'])->when($cid->isEmpty(), fn ($q) => $q->whereRaw('1 = 0'))->delete();`,
    );
    deleteE2eOAuthClients();
    fs.rmSync(E2E_START_FILE, { force: true });
    clearFakeSocial();
    fs.rmSync(E2E_USERS_FILE, { force: true });
}

export function e2eUser(project: string): E2eUser {
    return JSON.parse(fs.readFileSync(E2E_USERS_FILE, 'utf8'))[project];
}

/** Deja al usuario sin 2FA ni passkeys y sin bloqueos del reto (estado inicial de cada test). */
export function resetSecurity(email: string) {
    artisan(cleanUserPhp(email));
}

/** Libera el antirreplay del TOTP (`two_factor_last_used_at`) y el bloqueo del reto: para flujos que no prueban eso. */
export function clearTotpReplay(email: string) {
    artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `$u->forceFill(['two_factor_last_used_at' => null])->save(); Illuminate\\Support\\Facades\\RateLimiter::clear('2fa:' . $u->id);`,
    );
}

/** Deja activo el 2FA del usuario sin pasar por la UI (para los tests que no prueban la activación). Devuelve secreto y códigos. */
export function enableTwoFactorDirect(email: string): { secret: string; recoveryCodes: string[] } {
    const out = artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); $svc = app(App\\Services\\TwoFactor\\TotpService::class); ` +
            `$secret = $svc->generateSecret(); $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_used_at' => null])->save(); ` +
            `echo json_encode(['secret' => $secret, 'recoveryCodes' => $svc->generateRecoveryCodes($u)]);`,
    );
    return JSON.parse(out.slice(out.indexOf('{')));
}

// ---------------------------------------------------------------------------------------------------------------
// TOTP (RFC 6238, SHA-1, 6 dígitos, paso de 30 s) calculado en Node a partir del secreto mostrado en la UI.
// ---------------------------------------------------------------------------------------------------------------

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of input.toUpperCase().replace(/[^A-Z2-7]/g, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** Código TOTP del secreto base32. `stepOffset` desplaza el paso (-1, 0, +1 entran en la ventana del servidor). */
export function totp(secret: string, stepOffset = 0, now = Date.now()): string {
    const counter = Math.floor(now / 1000 / 30) + stepOffset;
    const buffer = Buffer.alloc(8);
    buffer.writeBigUInt64BE(BigInt(counter));
    const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(buffer).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const value = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];
    return (value % 1_000_000).toString().padStart(6, '0');
}

/** Un código de 6 dígitos que seguro NO es válido ahora (ni en la ventana ±1). */
export function wrongTotp(secret: string): string {
    const valid = new Set([-2, -1, 0, 1, 2].map((o) => totp(secret, o)));
    for (let n = 123456; ; n++) {
        const code = n.toString().padStart(6, '0');
        if (!valid.has(code)) return code;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Autenticador virtual de WebAuthn (Chromium, CDP). Con residente + verificación de usuario, igual que una passkey real.
// ---------------------------------------------------------------------------------------------------------------

export type VirtualAuthenticator = {
    id: string;
    credentials: () => Promise<number>;
    /** Con `false` el autenticador deja de contestar solo (para que otro autenticador de la misma página sea el que responda). */
    respond: (enabled: boolean) => Promise<void>;
    remove: () => Promise<void>;
};

export async function virtualAuthenticator(context: BrowserContext, page: Page): Promise<VirtualAuthenticator> {
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable', { enableUI: false });
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });
    return {
        id: authenticatorId,
        credentials: async () => (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.length,
        respond: async (enabled: boolean) => {
            await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled });
        },
        remove: async () => {
            await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
        },
    };
}

/** Inicia sesión con el formulario clásico (y el reto 2FA si se pasa un secreto). */
export async function loginAs(page: Page, user: E2eUser, secret?: string) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(user.email);
    await page.getByLabel('Contraseña').fill(user.password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    if (secret) {
        await page.waitForURL(/two-factor-challenge/);
        await page.getByLabel('Código de verificación').fill(totp(secret));
        await page.getByRole('button', { name: 'Verificar' }).click();
    }
    await page.waitForURL(/\/dashboard/);
}

/** Si la página cae en «Confirma tu contraseña», la rellena (una confirmación por sesión y ventana de `auth.password_timeout`). */
export async function confirmPasswordIfAsked(page: Page, password: string) {
    await page.waitForLoadState('networkidle');
    if (/confirm-password/.test(page.url())) {
        await page.getByLabel('Contraseña').fill(password);
        await page.getByRole('button', { name: 'Confirmar' }).click();
        await page.waitForURL((u) => !/confirm-password/.test(u.pathname));
        await page.waitForLoadState('networkidle');
    }
}

/** Confirma la contraseña de antemano (ventana de `auth.password_timeout`): las acciones de seguridad ya no redirigen. */
export async function confirmPassword(page: Page, password: string) {
    await page.goto('/confirm-password');
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Confirmar' }).click();
    await page.waitForURL((u) => !/confirm-password/.test(u.pathname));
}

/** Email y contraseña correctos hasta llegar al reto (sin completarlo). */
export async function loginUntilChallenge(page: Page, user: E2eUser, remember = false) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(user.email);
    await page.getByLabel('Contraseña').fill(user.password);
    if (remember) await page.getByLabel('Recordarme').check();
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/two-factor-challenge/);
}

// ---------------------------------------------------------------------------------------------------------------
// Proveedor social FALSO (T-23). El servidor E2E (APP_ENV=e2e) lee la identidad que "devuelve el proveedor" de este
// fichero; el driver real de Google/Facebook nunca se invoca. Solo existe con APP_ENV=e2e (FakeSocialiteProvider).
// ---------------------------------------------------------------------------------------------------------------

export const FAKE_SOCIAL_FILE = 'storage/framework/cache/e2e-social.json';

export type FakeIdentity = {
    id: string;
    email?: string | null;
    /** Solo Google: `email_verified` del userinfo; omítelo en Facebook. */
    email_verified?: boolean | string | null;
    name?: string;
    /** El proveedor devuelve `error=<valor>` (p. ej. `access_denied`: el usuario cancela). */
    error?: string;
};

/** Deja la identidad que devolverá el proveedor falso (`null` la quita). */
export function setFakeSocial(provider: 'google' | 'facebook', identity: FakeIdentity | null) {
    const all: Record<string, FakeIdentity> = fs.existsSync(FAKE_SOCIAL_FILE) ? JSON.parse(fs.readFileSync(FAKE_SOCIAL_FILE, 'utf8') || '{}') : {};
    if (identity === null) delete all[provider];
    else all[provider] = identity;
    fs.writeFileSync(FAKE_SOCIAL_FILE, JSON.stringify(all));
}

export function clearFakeSocial() {
    fs.rmSync(FAKE_SOCIAL_FILE, { force: true });
}

/** El callback social va limitado a 20/min por IP: se libera para que la batería no dependa del ritmo (la clave es el md5 del limitador). */
export function clearSocialThrottle() {
    artisan(
        `foreach (['127.0.0.1', '::1'] as $ip) { Illuminate\\Support\\Facades\\RateLimiter::clear(md5('social-login' . 'social-login:' . $ip)); }`,
    );
}

/** Vincula una identidad al usuario E2E (sin pasar por el proveedor). */
export function linkSocialDirect(email: string, provider: 'google' | 'facebook', providerUserId: string, linkedEmail: string | null = null) {
    artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `$u->socialIdentities()->create(['provider' => ${q(provider)}, 'provider_user_id' => ${q(providerUserId)}, 'email' => ${linkedEmail === null ? 'null' : q(linkedEmail)}, 'linked_at' => now()]);`,
    );
}

export type SocialRow = { provider: string; provider_user_id: string; blocked: boolean };

/** Identidades del usuario (activas y bloqueadas), para comprobar el efecto en la base de datos. */
export function socialRows(email: string): SocialRow[] {
    const out = artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `echo json_encode($u->socialIdentities()->orderBy('id')->get()->map(fn ($i) => ['provider' => $i->provider, 'provider_user_id' => $i->provider_user_id, 'blocked' => $i->blocked_at !== null])->all());`,
    );
    return JSON.parse(out.slice(out.indexOf('[')));
}

/** Crea un usuario E2E extra (para identidades "ajenas"); `deleteE2eUsers` lo borra por su patrón `e2e-sec-%@example.test`. */
export function createExtraE2eUser(label: string): string {
    const email = `e2e-sec-${label}@example.test`;
    artisan(
        `$u = App\\Models\\User::firstOrNew(['email' => ${q(email)}]); ` +
            `$u->forceFill(['name' => 'e2e extra ${label}', 'password' => Illuminate\\Support\\Facades\\Hash::make(${q(crypto.randomBytes(12).toString('hex') + '-Aa1!')}), 'email_verified_at' => now()])->save();`,
    );
    return email;
}

// ---------------------------------------------------------------------------------------------------------------
// OAuth del MCP (T-25): clientes DCR `e2e-*`, PKCE y llamadas a /mcp.
// ---------------------------------------------------------------------------------------------------------------

export const OAUTH_REDIRECT = 'http://127.0.0.1:53111/callback';

/** Borra los clientes OAuth `e2e-*` (con ellos se van sus códigos; los tokens quedan sin cliente y se borran con el usuario). */
export function deleteE2eOAuthClients() {
    artisan(`App\\Models\\OAuthClient::where('name', 'like', 'e2e-%')->get()->each->delete();`);
}

/**
 * DCR (10/h por IP) y los límites de /oauth/*: se liberan SOLO las claves de 127.0.0.1 y ::1 (claves hash de los limitadores).
 * El tope GLOBAL de DCR no se reinicia: el servidor E2E lo sube con MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR (playwright.config.ts).
 * Reiniciar las claves de loopback también afecta a tráfico real por ngrok que entre desde ahí: no corras la batería mientras
 * pruebas a mano (docs/guias/desarrollo-local.md).
 */
export function clearOAuthThrottle() {
    artisan(
        `foreach (['127.0.0.1', '::1'] as $ip) { foreach (['mcp-oauth-register', 'mcp-oauth-token'] as $name) { Illuminate\\Support\\Facades\\RateLimiter::clear(md5($name . $name . ':' . $ip)); } }`,
    );
}

/** PKCE S256: verifier aleatorio y su challenge. */
export function pkce(): { verifier: string; challenge: string } {
    const verifier = crypto.randomBytes(48).toString('base64url');
    return { verifier, challenge: crypto.createHash('sha256').update(verifier).digest('base64url') };
}

/** Crea una API key MANUAL (origen `manual`) del usuario y devuelve el texto plano. */
export function createManualKey(email: string, name = 'e2e key manual'): string {
    const out = artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `echo 'KEY:' . $u->createToken(${q(name)}, ['brands:read'], now()->addDays(30))->plainTextToken;`,
    );
    return out.slice(out.indexOf('KEY:') + 4).trim();
}

/** Llama a `tools/list` del MCP con un Bearer (y, opcionalmente, un Origin). */
export async function mcpToolsList(request: import('@playwright/test').APIRequestContext, token: string, origin?: string) {
    return request.post('/mcp', {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json, text/event-stream', ...(origin ? { Origin: origin } : {}) },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} },
    });
}

// ---------------------------------------------------------------------------------------------------------------
// Compositor de publicaciones: marca `e2e-composer-*` con cuentas de prueba de Instagram y Facebook (factorías) para un
// usuario `e2e-sec-*`. Nunca se toca al usuario demo ni a datos reales; no se publica en ninguna red.
// ---------------------------------------------------------------------------------------------------------------

export type ComposerBrand = { id: number; name: string };

/** Crea la marca y sus dos cuentas activas (Instagram y Facebook) para el usuario E2E. */
export function seedComposerBrand(email: string, label: string): ComposerBrand {
    const name = `e2e-composer-${label}`;
    const out = artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `$b = App\\Models\\Brand::factory()->for($u)->create(['name' => ${q(name)}, 'timezone' => 'Europe/Madrid']); ` +
            `App\\Models\\SocialAccount::factory()->for($u)->create(['brand_id' => $b->id, 'network' => App\\Connectors\\Network::Instagram, 'name' => 'e2e Instagram']); ` +
            `App\\Models\\SocialAccount::factory()->for($u)->create(['brand_id' => $b->id, 'network' => App\\Connectors\\Network::Facebook, 'name' => 'e2e Facebook']); ` +
            `echo 'BRAND:' . $b->id;`,
    );
    return { id: Number(out.match(/BRAND:(\d+)/)![1]), name };
}

/** Borra las marcas `e2e-composer-*` del usuario E2E (con ellas se van sus cuentas y publicaciones). */
export function cleanComposerBrands(email: string) {
    artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->first(); ` +
            `if ($u) { App\\Models\\Brand::withoutUserScope()->where('user_id', $u->id)->where('name', 'like', 'e2e-composer-%')->get()->each->delete(); }`,
    );
}
