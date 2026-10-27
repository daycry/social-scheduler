import AxeBuilder from '@axe-core/playwright';
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
export function seedComposerBrand(email: string, label: string, timezone = 'Europe/Madrid'): ComposerBrand {
    const name = `e2e-composer-${label}`;
    const out = artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `$b = App\\Models\\Brand::factory()->for($u)->create(['name' => ${q(name)}, 'timezone' => ${q(timezone)}]); ` +
            `App\\Models\\SocialAccount::factory()->for($u)->create(['brand_id' => $b->id, 'network' => App\\Connectors\\Network::Instagram, 'name' => 'e2e Instagram']); ` +
            `App\\Models\\SocialAccount::factory()->for($u)->create(['brand_id' => $b->id, 'network' => App\\Connectors\\Network::Facebook, 'name' => 'e2e Facebook']); ` +
            `echo 'BRAND:' . $b->id;`,
    );
    return { id: Number(out.match(/BRAND:(\d+)/)![1]), name };
}

/** Segunda cuenta de Facebook en la marca E2E (para mezclar una red vinculada y otra con texto propio y poder guardar el borrador). */
export function seedExtraFacebook(brandId: number, name: string) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`App\Models\SocialAccount::factory()->create(['user_id' => $b->user_id, 'brand_id' => $b->id, 'network' => App\Connectors\Network::Facebook, 'name' => ${q(name)}]);`,
    );
}

/** Cuenta extra en la marca E2E (YouTube o TikTok); `directPost` activa la publicación directa de TikTok. */
export function seedExtraAccount(brandId: number, network: 'youtube' | 'tiktok' | 'bluesky' | 'threads', name: string, directPost = false) {
    // Bluesky (C-01): cuenta sembrada con credenciales falsas y un PDS inexistente; la API real nunca se llama.
    const meta = network === 'bluesky'
        ? "['did' => 'did:plc:e2ebluesky00000000000000', 'pds' => 'https://pds.e2e.invalid', 'handle' => 'e2e.bsky.invalid', 'email_confirmed' => true]"
        : directPost ? "['direct_post' => true]" : 'null';
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`App\Models\SocialAccount::factory()->create(['user_id' => $b->user_id, 'brand_id' => $b->id, 'network' => App\Connectors\Network::from(${q(network)}), 'name' => ${q(name)}, 'meta' => ${meta}]);`,
    );
}

/**
 * Escenario de Threads y Bluesky (C-02, E2E-08) en la marca E2E: una cuenta de cada red, una publicación programada por red (a 2 días
 * vista, en el calendario), una de Threads ya publicada con sus métricas y snapshots de cuenta. Todo sembrado con factorías/servicios:
 * nada llega a Threads ni a Bluesky. Requiere `THREADS_APP_ID` en el entorno del servidor (la red solo se ofrece con ella).
 * Devuelve la fecha local de las programadas.
 */
export function seedThreadsScenario(email: string, brandId: number): string {
    seedExtraAccount(brandId, 'threads', 'e2e Threads');
    seedExtraAccount(brandId, 'bluesky', 'e2e Bluesky');
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$th = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'threads')->firstOrFail(); ` +
            String.raw`$bs = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'bluesky')->firstOrFail(); ` +
            String.raw`$svc = app(App\Services\PostService::class); ` +
            String.raw`$when = now()->addDays(2)->setTimezone('Europe/Madrid'); ` +
            String.raw`$mk = fn ($text, $acc, $type, $h) => $svc->create($u, $b, ['text' => $text, 'schedule' => true, 'scheduled_at' => $when->copy()->setTime($h, 0)->format('Y-m-d H:i'), 'media' => [], 'targets' => [['account_id' => $acc->id, 'content_type' => $type]]]); ` +
            String.raw`$mk('e2e programada en Threads', $th, 'threads_text', 10); ` +
            String.raw`$mk('e2e programada en Bluesky', $bs, 'bluesky_post', 11); ` +
            String.raw`$pub = $svc->create($u, $b, ['text' => 'e2e publicada en Threads', 'schedule' => false, 'media' => [], 'targets' => [['account_id' => $th->id, 'content_type' => 'threads_text']]]); ` +
            String.raw`$t = $pub->targets->first(); $t->forceFill(['status' => 'publicada', 'published_at' => now()->subDays(3), 'external_id' => 'e2e-threads-1'])->save(); $pub->syncStatus(); ` +
            String.raw`App\Models\PostMetricSnapshot::withoutUserScope()->create(['user_id' => $u->id, 'post_target_id' => $t->id, 'social_account_id' => $th->id, 'network' => 'threads', 'date' => now('UTC')->subDays(2)->format('Y-m-d'), 'metrics' => array_merge(App\Analytics\Metric::blankPost(), ['views' => 100, 'likes' => 10, 'comments' => 4, 'shares' => 6, 'interactions' => 20])]); ` +
            String.raw`for ($i = 2; $i < 5; $i++) { $d = now('UTC')->subDays($i)->format('Y-m-d'); ` +
            String.raw`App\Models\AccountSnapshot::withoutUserScope()->create(['user_id' => $u->id, 'brand_id' => $b->id, 'social_account_id' => $th->id, 'network' => 'threads', 'date' => $d, 'metrics' => array_merge(App\Analytics\Metric::blankAccount(), ['profile_views' => 40, 'likes' => 12, 'comments' => 3, 'shares' => 3, 'clicks' => 11, 'interactions' => 18, 'followers' => 321])]); ` +
            String.raw`App\Models\AccountSnapshot::withoutUserScope()->create(['user_id' => $u->id, 'brand_id' => $b->id, 'social_account_id' => $bs->id, 'network' => 'bluesky', 'date' => $d, 'metrics' => array_merge(App\Analytics\Metric::blankAccount(), ['followers' => 55, 'post_count' => 9])]); } ` +
            String.raw`echo 'DATE:' . $when->format('Y-m-d');`,
    );
    return out.match(/DATE:([\d-]+)/)![1];
}

/** Borrador de YouTube con un vídeo de prueba (factoría) en la marca E2E: evita subir un vídeo real por la interfaz. */
export function seedYoutubeDraft(brandId: number, accountName: string): number {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$yt = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('name', ${q(accountName)})->firstOrFail(); ` +
            String.raw`$m = App\Models\MediaAsset::factory()->video(duration: 45)->for($u)->create(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'e2e video de youtube', 'schedule' => false, 'media' => [$m->id], 'targets' => [['account_id' => $yt->id, 'content_type' => 'youtube_video']]]); ` +
            String.raw`echo 'POST:' . $p->id;`,
    );
    return Number(out.match(/POST:(\d+)/)![1]);
}

/**
 * Borrador de TikTok cuyo tipo guardado ya no se ofrece: se crea como vídeo y se pasa a `tiktok_photo`, que sin
 * `TIKTOK_VERIFIED_DOMAIN` no está entre los tipos ofrecidos (como un post guardado antes de vaciar el dominio).
 */
export function seedTiktokPhotoDraft(brandId: number, accountName: string): number {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$tt = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('name', ${q(accountName)})->firstOrFail(); ` +
            String.raw`$m = App\Models\MediaAsset::factory()->video(duration: 30)->for($u)->create(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'e2e tiktok no disponible', 'schedule' => false, 'media' => [$m->id], 'targets' => [['account_id' => $tt->id, 'content_type' => 'tiktok_video']]]); ` +
            String.raw`$p->targets()->update(['content_type' => 'tiktok_photo']); ` +
            String.raw`echo 'POST:' . $p->id;`,
    );
    return Number(out.match(/POST:(\d+)/)![1]);
}

/** Cambia la zona horaria de la marca E2E (para comprobar que la UI usa la de la marca y no la del navegador). */
export function setBrandTimezone(brandId: number, timezone: string) {
    artisan(String.raw`App\Models\Brand::withoutUserScope()->findOrFail(${brandId})->forceFill(['timezone' => ${q(timezone)}])->save();`);
}

/**
 * Borra las marcas `e2e-composer-*` del usuario E2E (con ellas se van sus cuentas y publicaciones). Antes retira de la cola
 * `jobs` los `PublishPostTarget` de ESOS destinos y solo esos (coincidencia exacta de `targetId`): «Publicar ahora» encola de
 * verdad y nada de lo E2E debe llegar a publicarse si hubiera un worker en marcha.
 */
export function cleanComposerBrands(email: string) {
    artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->first(); ` +
            String.raw`if ($u) { ` +
            String.raw`$brands = App\Models\Brand::withoutUserScope()->where('user_id', $u->id)->where('name', 'like', 'e2e-composer-%')->get(); ` +
            String.raw`$ids = App\Models\PostTarget::withoutUserScope()->whereIn('post_id', App\Models\Post::withoutUserScope()->withTrashed()->whereIn('brand_id', $brands->pluck('id'))->select('id'))->pluck('id'); ` +
            String.raw`foreach ($ids as $id) { Illuminate\Support\Facades\DB::table('jobs')->where('payload', 'like', '%PublishPostTarget%')->whereRaw("payload like ? escape '|'", ['%targetId\";i:' . $id . ';%'])->delete(); } ` +
            String.raw`foreach ($ids as $id) { Illuminate\Support\Facades\DB::table('jobs')->where('payload', 'like', '%PostFirstComment%')->whereRaw("payload like ? escape '|'", ['%targetId\";i:' . $id . ';%'])->delete(); } ` +
            String.raw`$brands->each->delete(); ` +
            String.raw`App\Models\MediaAsset::withoutUserScope()->where('user_id', $u->id)->get()->each->delete(); }`,
    );
}

export type ComposerPosts = { draft: number; scheduled: number; published: number; scheduledDate: string };

/** Tres publicaciones de Facebook en la marca E2E: un borrador, una programada (a 2 dias) y una ya publicada. */
export function seedComposerPosts(email: string, brandId: number): ComposerPosts {
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$fb = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); ` +
            String.raw`$svc = app(App\Services\PostService::class); ` +
            String.raw`$mk = fn ($text, $schedule, $at) => $svc->create($u, $b, ['text' => $text, 'schedule' => $schedule, 'scheduled_at' => $at, 'media' => [], 'targets' => [['account_id' => $fb->id, 'content_type' => 'facebook_text']]]); ` +
            String.raw`$draft = $mk('e2e borrador de calendario', false, null); ` +
            String.raw`$when = now()->addDays(2)->setTimezone('Europe/Madrid'); ` +
            String.raw`$sched = $mk('e2e programada de calendario', true, $when->copy()->setTime(10, 0)->format('Y-m-d H:i')); ` +
            String.raw`$pub = $mk('e2e publicada de calendario', true, $when->copy()->setTime(11, 0)->format('Y-m-d H:i')); ` +
            String.raw`$pub->targets->each(fn ($t) => $t->forceFill(['status' => 'publicada', 'published_at' => now()])->save()); $pub->syncStatus(); ` +
            String.raw`echo 'IDS:' . $draft->id . ',' . $sched->id . ',' . $pub->id . ',' . $when->format('Y-m-d');`,
    );
    const [draft, scheduled, published, scheduledDate] = out.match(/IDS:([\d,-]+)/)![1].split(',');
    return { draft: Number(draft), scheduled: Number(scheduled), published: Number(published), scheduledDate };
}

/** axe (WCAG 2.x A y AA): falla con cualquier violación `serious` o `critical` de la página actual. */
export async function axeSeriousCritical(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0); // barra transitoria de Inertia
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400); // fin de las transiciones de color
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}: ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ${n.html.slice(0, 200)}`).join(' | ')}`).join(' // ');
    expect(bad, `violaciones de accesibilidad en ${label}: ${detail}`).toEqual([]);
}


/** Elige el tipo de publicación de una cuenta con el desplegable junto a su pastilla (`account` = id de la cuenta o texto de la etiqueta). */
export async function chooseContentType(page: Page, account: number | RegExp, label: string) {
    const button = typeof account === 'number' ? page.locator(`#type-${account}`) : page.getByRole('button', { name: account });
    await button.click();
    await page.getByRole('option', { name: new RegExp(`^${label}`) }).click();
    await expect(button).toContainText(label);
}

// ---------------------------------------------------------------------------------------------------------------
// Textos guardados (C-01a): datos `e2e-` del usuario E2E. Los de una marca se van con ella (cascade); los de «Todas las marcas» se borran aparte.
// ---------------------------------------------------------------------------------------------------------------

/** Borra los textos guardados con título `e2e-…` del usuario E2E (los de marca ya se fueron con `cleanComposerBrands`). */
export function cleanSavedTexts(email: string) {
    artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->first(); ` +
            `if ($u) { App\\Models\\SavedText::withoutUserScope()->where('user_id', $u->id)->where('title', 'like', 'e2e-%')->delete(); }`,
    );
}

/**
 * Siembra tres textos: uno de «Todas las marcas» (hashtags), uno de la marca y uno de otra marca `e2e-composer-otra-…`.
 * El de la otra marca NO debe aparecer en el compositor de `brandId`.
 */
export function seedSavedTexts(email: string, brandId: number, label: string) {
    artisan(
        `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
            `$other = App\\Models\\Brand::factory()->for($u)->create(['name' => ${q(`e2e-composer-otra-${label}`)}]); ` +
            `$mk = fn ($brand, $kind, $title, $body) => App\\Models\\SavedText::factory()->create(['user_id' => $u->id, 'brand_id' => $brand, 'kind' => $kind, 'title' => $title, 'body' => $body]); ` +
            `$mk(null, 'hashtags', 'e2e-hashtags-globales', '#uno #dos'); ` +
            `$mk(${brandId}, 'fragmento', 'e2e-firma-marca', 'Un saludo de la marca'); ` +
            `$mk($other->id, 'fragmento', 'e2e-texto-otra-marca', 'No debe verse');`,
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Primer comentario (C-01b): destino publicado sembrado. Nunca se publica ni se comenta en una red real.
// ---------------------------------------------------------------------------------------------------------------

export type PublishedComment = { postId: number; targetId: number };

/**
 * Publicación de Instagram ya `publicada` (sembrada, sin pasar por la red) cuyo primer comentario está en `status`
 * (`fallido` por defecto, con el motivo). Reintentar solo encola un `PostFirstComment` en la cola `database`: sin worker no llega a ninguna red.
 */
export function seedPublishedWithComment(email: string, brandId: number, status = 'fallido', error = 'Instagram no admite comentarios en esta publicacion.', sentAt = false): PublishedComment {
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$m = App\Models\MediaAsset::factory()->for($u)->create(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'e2e publicada con comentario', 'schedule' => false, 'media' => [$m->id], 'targets' => [['account_id' => $ig->id, 'content_type' => 'instagram_image', 'first_comment' => 'e2e Mas info en el enlace']]]); ` +
            String.raw`$p->targets->each(fn ($t) => $t->forceFill(['status' => 'publicada', 'published_at' => now(), 'external_id' => 'e2e-ext-1', 'external_url' => 'https://example.test/e2e', 'first_comment_status' => ${q(status)}, 'first_comment_attempts' => 3, 'first_comment_error' => ${q(error)}, 'first_comment_sent_at' => ${sentAt ? 'now()' : 'null'}])->save()); $p->syncStatus(); ` +
            String.raw`echo 'IDS:' . $p->id . ',' . $p->targets()->first()->id;`,
    );
    const [postId, targetId] = out.match(/IDS:([\d,]+)/)![1].split(',');
    return { postId: Number(postId), targetId: Number(targetId) };
}

/** Estado del primer comentario de un destino (columna), para comprobar el efecto de Editar y Reintentar. */
export function firstCommentOf(targetId: number): { status: string; text: string; postStatus: string } {
    const out = artisan(
        String.raw`$t = App\Models\PostTarget::withoutUserScope()->findOrFail(${targetId}); ` +
            String.raw`echo 'FC:' . json_encode(['status' => $t->first_comment_status?->value, 'text' => $t->first_comment, 'postStatus' => $t->status->value]);`,
    );
    return JSON.parse(out.match(/FC:(\{.*\})/)![1]);
}

// ---------------------------------------------------------------------------------------------------------------
// Cuadrícula de Instagram (C-02): lo publicado se siembra en la caché de 10 min (nunca se llama a Graph) y lo programado en la BD.
// ---------------------------------------------------------------------------------------------------------------

export type GridSeed = { accountId: number; later: number; earlier: number; story: number };

/**
 * Cuenta de Instagram de la marca E2E con tres publicadas «leídas» (caché `ig-grid:{cuenta}`, 10 min), dos posts programados
 * (`later` es el más tardío) y una Story programada que NO debe aparecer. Miniaturas como `data:` URI: sin red.
 */
export function seedInstagramGrid(email: string, brandId: number): GridSeed {
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$svc = app(App\Services\PostService::class); ` +
            String.raw`$mk = fn ($type, $text, $at) => $svc->create($u, $b, ['text' => $text, 'schedule' => true, 'scheduled_at' => $at, 'media' => [App\Models\MediaAsset::factory()->for($u)->create()->id], 'targets' => [['account_id' => $ig->id, 'content_type' => $type]]]); ` +
            String.raw`$when = now()->addDays(3)->setTimezone($b->timezone); ` +
            String.raw`$earlier = $mk('instagram_image', 'e2e grid programada 1', $when->copy()->setTime(10, 0)->format('Y-m-d H:i')); ` +
            String.raw`$later = $mk('instagram_image', 'e2e grid programada 2', $when->copy()->addDay()->setTime(10, 0)->format('Y-m-d H:i')); ` +
            String.raw`$story = $mk('instagram_story', 'e2e grid story', $when->copy()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i')); ` +
            String.raw`$svg = fn ($c) => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="' . $c . '"/></svg>'); ` +
            String.raw`$pub = fn ($id, $type, $c, $day) => ['kind' => 'published', 'id' => $id, 'target_id' => null, 'post_id' => null, 'type' => $type, 'image_url' => $svg($c), 'video_url' => null, 'permalink' => 'https://www.instagram.com/p/' . $id . '/', 'timestamp' => now()->subDays($day)->toIso8601String()]; ` +
            String.raw`Illuminate\Support\Facades\Cache::put('ig-grid:' . $ig->id, [$pub('e2eP1', 'IMAGE', '#4f46e5', 1), $pub('e2eP2', 'VIDEO', '#0d9488', 2), $pub('e2eP3', 'CAROUSEL_ALBUM', '#be123c', 3)], now()->addMinutes(10)); ` +
            String.raw`echo 'GRID:' . $ig->id . ',' . $later->id . ',' . $earlier->id . ',' . $story->id;`,
    );
    const [accountId, later, earlier, story] = out.match(/GRID:([\d,]+)/)![1].split(',');
    return { accountId: Number(accountId), later: Number(later), earlier: Number(earlier), story: Number(story) };
}

/** Vacía la caché de la cuadrícula de las cuentas de Instagram del usuario E2E. */
export function cleanInstagramGridCache(email: string) {
    artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->first(); ` +
            String.raw`if ($u) { App\Models\SocialAccount::withoutUserScope()->where('user_id', $u->id)->where('network', 'instagram')->pluck('id')->each(fn ($id) => Illuminate\Support\Facades\Cache::forget('ig-grid:' . $id)); }`,
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Campañas (C-03): datos `e2e-` en marcas `e2e-composer-*` (se van con la marca por cascade). Nada se publica en una red.
// ---------------------------------------------------------------------------------------------------------------

/** Otra marca `e2e-composer-otra-…` con una campaña: NO debe ofrecerse en el compositor de la marca principal. */
export function seedOtherBrandCampaign(email: string, label: string) {
    artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$o = App\Models\Brand::factory()->for($u)->create(['name' => ${q(`e2e-composer-otra-${label}`)}]); ` +
            String.raw`App\Models\Campaign::factory()->create(['user_id' => $u->id, 'brand_id' => $o->id, 'name' => 'e2e-camp-otra-marca']);`,
    );
}

export type CampaignSeed = { campaignId: number };

/**
 * Campaña `e2e-camp-panel` con un post de dos destinos `publicada` (Instagram y Facebook) y snapshots sembrados: Instagram con
 * 100 visualizaciones, 80 de alcance y 20 interacciones; Facebook con 50 y 40 y las interacciones en `null` (sin datos).
 */
export function seedCampaignPanel(email: string, brandId: number): CampaignSeed {
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$c = App\Models\Campaign::factory()->create(['user_id' => $u->id, 'brand_id' => $b->id, 'name' => 'e2e-camp-panel', 'color' => '#0f766e']); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$fb = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); ` +
            String.raw`$m = App\Models\MediaAsset::factory()->for($u)->create(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'e2e post de campaña', 'schedule' => false, 'campaign_id' => $c->id, 'media' => [$m->id], 'targets' => [['account_id' => $ig->id, 'content_type' => 'instagram_image'], ['account_id' => $fb->id, 'content_type' => 'facebook_photo']]]); ` +
            String.raw`$metrics = ['instagram' => ['views' => 100, 'reach' => 80, 'interactions' => 20], 'facebook' => ['views' => 50, 'reach' => 40, 'interactions' => null]]; ` +
            String.raw`foreach ($p->targets as $t) { $t->forceFill(['status' => 'publicada', 'published_at' => now()->subDays(2), 'external_id' => 'e2e-camp-' . $t->id, 'external_url' => 'https://example.test/e2e-' . $t->id])->save(); ` +
            String.raw`App\Models\PostMetricSnapshot::withoutUserScope()->create(['user_id' => $u->id, 'post_target_id' => $t->id, 'social_account_id' => $t->social_account_id, 'network' => $t->network->value, 'date' => now()->subDay()->toDateString(), 'metrics' => $metrics[$t->network->value]]); } ` +
            String.raw`$p->syncStatus(); echo 'CAMP:' . $c->id;`,
    );
    return { campaignId: Number(out.match(/CAMP:(\d+)/)![1]) };
}

// ---------------------------------------------------------------------------------------------------------------
// Notas internas del inbox (C-04d): comentarios sembrados en la marca `e2e-composer-*` (se van con ella, cascade). Nada llega a una red.
// ---------------------------------------------------------------------------------------------------------------

/** Siembra comentarios de terceros en la cuenta de Instagram de la marca E2E (con permiso de lectura, sin banner de «no disponible»). */
export function seedInboxComment(brandId: number, ...texts: string[]) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$ig->forceFill(['scopes' => ['instagram_manage_comments']])->save(); ` +
            String.raw`foreach ([${texts.map(q).join(', ')}] as $i => $t) { App\Models\InboxComment::factory()->create(['user_id' => $b->user_id, 'brand_id' => $b->id, 'social_account_id' => $ig->id, 'text' => $t, 'author_name' => 'e2e-autor-' . $i, 'commented_at' => now()->subMinutes($i + 1)]); }`,
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Vistas guardadas del calendario (C-03a): se borran las del usuario E2E (no cuelgan de la marca).
// ---------------------------------------------------------------------------------------------------------------

/** Borra todas las vistas guardadas del calendario del usuario `e2e-sec-*`. */
export function cleanCalendarViews(email: string) {
    artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->first(); ` +
            String.raw`if ($u) { App\Models\CalendarView::withoutUserScope()->where('user_id', $u->id)->delete(); }`,
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Analítica entre marcas (C-03b): snapshots de cuenta sembrados en marcas `e2e-composer-*` (se van con la marca).
// ---------------------------------------------------------------------------------------------------------------

export type AnalyticsSeed = { from: string; to: string; days: number };

/**
 * Siembra `days` snapshots diarios (los días 2..days+1 anteriores a hoy, UTC) de la cuenta de Instagram de la marca con
 * `views` visualizaciones y `followers` seguidores por día. Facebook no recibe ninguno: sus métricas son «no disponible».
 * Devuelve el periodo (10 días cerrados) que lo contiene.
 */
export function seedAnalyticsSnapshots(brandId: number, views: number, followers: number, days = 3): AnalyticsSeed {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`for ($i = 2; $i < ${days} + 2; $i++) { App\Models\AccountSnapshot::withoutUserScope()->create(['user_id' => $b->user_id, 'brand_id' => $b->id, 'social_account_id' => $ig->id, 'network' => 'instagram', 'date' => now('UTC')->subDays($i)->format('Y-m-d'), 'metrics' => array_merge(App\Analytics\Metric::blankAccount(), ['views' => ${views}, 'followers' => ${followers}])]); }`,
    );
    const day = (back: number) => new Date(Date.now() - back * 86_400_000).toISOString().slice(0, 10);
    return { from: day(10), to: day(1), days };
}

// ---------------------------------------------------------------------------------------------------------------
// Exportar CSV (C-03c): publicaciones de Facebook con texto de fórmula en la marca E2E.
// ---------------------------------------------------------------------------------------------------------------

export const CSV_FORMULA = '=HYPERLINK("http://e2e.invalid";"x")';

/** Dos publicaciones programadas de Facebook (texto de fórmula y texto normal) a 2 días vista en la marca E2E; devuelve su fecha local. */
export function seedCsvPosts(email: string, brandId: number): string {
    const out = artisan(
        String.raw`$u = App\Models\User::where('email', ${q(email)})->firstOrFail(); ` +
            String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$fb = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); ` +
            String.raw`$svc = app(App\Services\PostService::class); ` +
            String.raw`$when = now()->addDays(2)->setTimezone('Europe/Madrid'); ` +
            String.raw`foreach ([[${q(CSV_FORMULA)}, 10], ['e2e csv texto normal', 11]] as [$text, $hour]) { ` +
            String.raw`$svc->create($u, $b, ['text' => $text, 'schedule' => true, 'scheduled_at' => $when->copy()->setTime($hour, 0)->format('Y-m-d H:i'), 'media' => [], 'targets' => [['account_id' => $fb->id, 'content_type' => 'facebook_text']]]); } ` +
            String.raw`echo 'DATE:' . $when->format('Y-m-d');`,
    );
    return out.match(/DATE:([\d-]+)/)![1];
}

// ---------------------------------------------------------------------------------------------------------------
// Hashtags sugeridos (C-04a, E2E-06): histórico publicado con métricas en la marca E2E.
// ---------------------------------------------------------------------------------------------------------------

/**
 * Cinco publicaciones de Instagram YA publicadas con su lectura de métricas: #verano (3 usos) y #oferta (2 usos) salen; #nuevo (1 uso)
 * no. Es solo histórico sembrado: no se publica nada ni se llama a ninguna red.
 */
export function seedHashtagHistory(brandId: number) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`foreach ([['Sol #verano #oferta', 120], ['Playa #verano', 90], ['Rebajas #verano #oferta', 150], ['Sin etiquetas', 10], ['Otro #nuevo', 400]] as $i => [$text, $n]) { ` +
            String.raw`$p = App\Models\Post::factory()->create(['brand_id' => $b->id, 'status' => App\Enums\PostStatus::Published, 'text' => 'e2e ' . $text]); ` +
            String.raw`$t = App\Models\PostTarget::factory()->create(['post_id' => $p->id, 'social_account_id' => $ig->id, 'network' => App\Connectors\Network::Instagram, 'status' => App\Enums\PostStatus::Published, 'published_at' => now()->subDays(5 + $i)]); ` +
            String.raw`App\Models\PostMetricSnapshot::withoutUserScope()->create(['user_id' => $b->user_id, 'post_target_id' => $t->id, 'social_account_id' => $ig->id, 'network' => 'instagram', 'date' => now()->subDay()->toDateString(), 'metrics' => array_merge(App\Analytics\Metric::blankPost(), [App\Analytics\Metric::INTERACTIONS => $n])]); } ` +
            String.raw`Illuminate\Support\Facades\Cache::flush();`,
    );
}
