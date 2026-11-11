# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: studio.spec.ts >> Studio: undo, page ordering and portable JPEG/WebP/SVG/PDF exports retain the design
- Location: tests\e2e\studio.spec.ts:103:1

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: expect(received).toEqual(expected) // deep equality

- Expected  - 1
+ Received  + 4

- Array []
+ Array [
+   "console: Failed to load resource: net::ERR_CONNECTION_REFUSED",
+   "pageerror: Network Error",
+ ]
```

```
Error: page.waitForURL: Test timeout of 60000ms exceeded.
=========================== logs ===========================
waiting for navigation until "load"
============================================================
```

# Page snapshot

```yaml
- main [ref=e4]:
  - link "Social Scheduler, inicio" [ref=e5] [cursor=pointer]:
    - /url: /
    - generic [ref=e6]: Social Scheduler
  - generic [ref=e12]:
    - heading "Entra en tu cuenta" [level=1] [ref=e13]
    - paragraph [ref=e14]: Bienvenido de nuevo a Social Scheduler.
    - generic [ref=e15]:
      - generic [ref=e16]:
        - generic [ref=e17]: Correo electrónico
        - textbox "Correo electrónico" [ref=e18]: e2e-sec-mobile-360@example.test
      - generic [ref=e19]:
        - generic [ref=e20]: Contraseña
        - textbox "Contraseña" [ref=e21]
      - generic [ref=e23]:
        - checkbox "Recordarme" [ref=e24]
        - generic [ref=e25]: Recordarme
      - generic [ref=e26]:
        - link "¿Has olvidado tu contraseña?" [ref=e27] [cursor=pointer]:
          - /url: http://localhost:8080/forgot-password
        - button "Entrar" [ref=e28] [cursor=pointer]
    - generic [ref=e29]:
      - link "Continuar con Google" [ref=e30] [cursor=pointer]:
        - /url: http://localhost:8080/auth/google/redirect
      - link "Continuar con Facebook" [ref=e37] [cursor=pointer]:
        - /url: http://localhost:8080/auth/facebook/redirect
    - generic [ref=e42]:
      - button "Entrar con passkey" [ref=e43] [cursor=pointer]
      - paragraph [ref=e44]: "Sin contraseña ni código: con tu huella, tu cara o el PIN del dispositivo."
      - alert [ref=e45]: Las passkeys requieren HTTPS y un navegador compatible. Puedes seguir entrando con tu contraseña.
    - paragraph [ref=e46]:
      - text: ¿Aún no tienes cuenta?
      - link "Crear cuenta" [ref=e47] [cursor=pointer]:
        - /url: http://localhost:8080/register
  - paragraph [ref=e48]:
    - link "Privacidad" [ref=e49] [cursor=pointer]:
      - /url: /privacy
    - text: ·
    - link "Condiciones" [ref=e50] [cursor=pointer]:
      - /url: /terms
```

# Test source

```ts
  187 | 
  188 | /** Libera el antirreplay del TOTP (`two_factor_last_used_at`) y el bloqueo del reto: para flujos que no prueban eso. */
  189 | export function clearTotpReplay(email: string) {
  190 |     artisan(
  191 |         `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
  192 |             `$u->forceFill(['two_factor_last_used_at' => null])->save(); Illuminate\\Support\\Facades\\RateLimiter::clear('2fa:' . $u->id);`,
  193 |     );
  194 | }
  195 | 
  196 | /** Deja activo el 2FA del usuario sin pasar por la UI (para los tests que no prueban la activación). Devuelve secreto y códigos. */
  197 | export function enableTwoFactorDirect(email: string): { secret: string; recoveryCodes: string[] } {
  198 |     const out = artisan(
  199 |         `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); $svc = app(App\\Services\\TwoFactor\\TotpService::class); ` +
  200 |             `$secret = $svc->generateSecret(); $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_used_at' => null])->save(); ` +
  201 |             `echo json_encode(['secret' => $secret, 'recoveryCodes' => $svc->generateRecoveryCodes($u)]);`,
  202 |     );
  203 |     return JSON.parse(out.slice(out.indexOf('{')));
  204 | }
  205 | 
  206 | // ---------------------------------------------------------------------------------------------------------------
  207 | // TOTP (RFC 6238, SHA-1, 6 dígitos, paso de 30 s) calculado en Node a partir del secreto mostrado en la UI.
  208 | // ---------------------------------------------------------------------------------------------------------------
  209 | 
  210 | function base32Decode(input: string): Buffer {
  211 |     const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  212 |     let bits = '';
  213 |     for (const char of input.toUpperCase().replace(/[^A-Z2-7]/g, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  214 |     const bytes: number[] = [];
  215 |     for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  216 |     return Buffer.from(bytes);
  217 | }
  218 | 
  219 | /** Código TOTP del secreto base32. `stepOffset` desplaza el paso (-1, 0, +1 entran en la ventana del servidor). */
  220 | export function totp(secret: string, stepOffset = 0, now = Date.now()): string {
  221 |     const counter = Math.floor(now / 1000 / 30) + stepOffset;
  222 |     const buffer = Buffer.alloc(8);
  223 |     buffer.writeBigUInt64BE(BigInt(counter));
  224 |     const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(buffer).digest();
  225 |     const offset = hmac[hmac.length - 1] & 0xf;
  226 |     const value = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];
  227 |     return (value % 1_000_000).toString().padStart(6, '0');
  228 | }
  229 | 
  230 | /** Un código de 6 dígitos que seguro NO es válido ahora (ni en la ventana ±1). */
  231 | export function wrongTotp(secret: string): string {
  232 |     const valid = new Set([-2, -1, 0, 1, 2].map((o) => totp(secret, o)));
  233 |     for (let n = 123456; ; n++) {
  234 |         const code = n.toString().padStart(6, '0');
  235 |         if (!valid.has(code)) return code;
  236 |     }
  237 | }
  238 | 
  239 | // ---------------------------------------------------------------------------------------------------------------
  240 | // Autenticador virtual de WebAuthn (Chromium, CDP). Con residente + verificación de usuario, igual que una passkey real.
  241 | // ---------------------------------------------------------------------------------------------------------------
  242 | 
  243 | export type VirtualAuthenticator = {
  244 |     id: string;
  245 |     credentials: () => Promise<number>;
  246 |     /** Con `false` el autenticador deja de contestar solo (para que otro autenticador de la misma página sea el que responda). */
  247 |     respond: (enabled: boolean) => Promise<void>;
  248 |     remove: () => Promise<void>;
  249 | };
  250 | 
  251 | export async function virtualAuthenticator(context: BrowserContext, page: Page): Promise<VirtualAuthenticator> {
  252 |     const cdp = await context.newCDPSession(page);
  253 |     await cdp.send('WebAuthn.enable', { enableUI: false });
  254 |     const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
  255 |         options: {
  256 |             protocol: 'ctap2',
  257 |             transport: 'internal',
  258 |             hasResidentKey: true,
  259 |             hasUserVerification: true,
  260 |             isUserVerified: true,
  261 |             automaticPresenceSimulation: true,
  262 |         },
  263 |     });
  264 |     return {
  265 |         id: authenticatorId,
  266 |         credentials: async () => (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.length,
  267 |         respond: async (enabled: boolean) => {
  268 |             await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled });
  269 |         },
  270 |         remove: async () => {
  271 |             await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
  272 |         },
  273 |     };
  274 | }
  275 | 
  276 | /** Inicia sesión con el formulario clásico (y el reto 2FA si se pasa un secreto). */
  277 | export async function loginAs(page: Page, user: E2eUser, secret?: string) {
  278 |     await page.goto('/login');
  279 |     await page.getByLabel('Correo electrónico').fill(user.email);
  280 |     await page.getByLabel('Contraseña').fill(user.password);
  281 |     await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  282 |     if (secret) {
  283 |         await page.waitForURL(/two-factor-challenge/);
  284 |         await page.getByLabel('Código de verificación').fill(totp(secret));
  285 |         await page.getByRole('button', { name: 'Verificar' }).click();
  286 |     }
> 287 |     await page.waitForURL(/\/dashboard/);
      |                ^ Error: page.waitForURL: Test timeout of 60000ms exceeded.
  288 | }
  289 | 
  290 | /** Si la página cae en «Confirma tu contraseña», la rellena (una confirmación por sesión y ventana de `auth.password_timeout`). */
  291 | export async function confirmPasswordIfAsked(page: Page, password: string) {
  292 |     await page.waitForLoadState('networkidle');
  293 |     if (/confirm-password/.test(page.url())) {
  294 |         await page.getByLabel('Contraseña').fill(password);
  295 |         await page.getByRole('button', { name: 'Confirmar' }).click();
  296 |         await page.waitForURL((u) => !/confirm-password/.test(u.pathname));
  297 |         await page.waitForLoadState('networkidle');
  298 |     }
  299 | }
  300 | 
  301 | /** Confirma la contraseña de antemano (ventana de `auth.password_timeout`): las acciones de seguridad ya no redirigen. */
  302 | export async function confirmPassword(page: Page, password: string) {
  303 |     await page.goto('/confirm-password');
  304 |     await page.getByLabel('Contraseña').fill(password);
  305 |     await page.getByRole('button', { name: 'Confirmar' }).click();
  306 |     await page.waitForURL((u) => !/confirm-password/.test(u.pathname));
  307 | }
  308 | 
  309 | /** Email y contraseña correctos hasta llegar al reto (sin completarlo). */
  310 | export async function loginUntilChallenge(page: Page, user: E2eUser, remember = false) {
  311 |     await page.goto('/login');
  312 |     await page.getByLabel('Correo electrónico').fill(user.email);
  313 |     await page.getByLabel('Contraseña').fill(user.password);
  314 |     if (remember) await page.getByLabel('Recordarme').check();
  315 |     await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  316 |     await page.waitForURL(/two-factor-challenge/);
  317 | }
  318 | 
  319 | // ---------------------------------------------------------------------------------------------------------------
  320 | // Proveedor social FALSO (T-23). El servidor E2E (APP_ENV=e2e) lee la identidad que "devuelve el proveedor" de este
  321 | // fichero; el driver real de Google/Facebook nunca se invoca. Solo existe con APP_ENV=e2e (FakeSocialiteProvider).
  322 | // ---------------------------------------------------------------------------------------------------------------
  323 | 
  324 | export const FAKE_SOCIAL_FILE = fakeSocialFile;
  325 | 
  326 | export type FakeIdentity = {
  327 |     id: string;
  328 |     email?: string | null;
  329 |     /** Solo Google: `email_verified` del userinfo; omítelo en Facebook. */
  330 |     email_verified?: boolean | string | null;
  331 |     name?: string;
  332 |     /** El proveedor devuelve `error=<valor>` (p. ej. `access_denied`: el usuario cancela). */
  333 |     error?: string;
  334 | };
  335 | 
  336 | /** Deja la identidad que devolverá el proveedor falso (`null` la quita). */
  337 | export function setFakeSocial(provider: 'google' | 'facebook', identity: FakeIdentity | null) {
  338 |     const all: Record<string, FakeIdentity> = fs.existsSync(FAKE_SOCIAL_FILE) ? JSON.parse(fs.readFileSync(FAKE_SOCIAL_FILE, 'utf8') || '{}') : {};
  339 |     if (identity === null) delete all[provider];
  340 |     else all[provider] = identity;
  341 |     fs.writeFileSync(FAKE_SOCIAL_FILE, JSON.stringify(all));
  342 | }
  343 | 
  344 | export function clearFakeSocial() {
  345 |     fs.rmSync(FAKE_SOCIAL_FILE, { force: true });
  346 | }
  347 | 
  348 | /** El callback social va limitado a 20/min por IP: se libera para que la batería no dependa del ritmo (la clave es el md5 del limitador). */
  349 | export function clearSocialThrottle() {
  350 |     artisan(
  351 |         `foreach (['127.0.0.1', '::1'] as $ip) { Illuminate\\Support\\Facades\\RateLimiter::clear(md5('social-login' . 'social-login:' . $ip)); }`,
  352 |     );
  353 | }
  354 | 
  355 | /** Vincula una identidad al usuario E2E (sin pasar por el proveedor). */
  356 | export function linkSocialDirect(email: string, provider: 'google' | 'facebook', providerUserId: string, linkedEmail: string | null = null) {
  357 |     artisan(
  358 |         `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
  359 |             `$u->socialIdentities()->create(['provider' => ${q(provider)}, 'provider_user_id' => ${q(providerUserId)}, 'email' => ${linkedEmail === null ? 'null' : q(linkedEmail)}, 'linked_at' => now()]);`,
  360 |     );
  361 | }
  362 | 
  363 | export type SocialRow = { provider: string; provider_user_id: string; blocked: boolean };
  364 | 
  365 | /** Identidades del usuario (activas y bloqueadas), para comprobar el efecto en la base de datos. */
  366 | export function socialRows(email: string): SocialRow[] {
  367 |     const out = artisan(
  368 |         `$u = App\\Models\\User::where('email', ${q(email)})->firstOrFail(); ` +
  369 |             `echo json_encode($u->socialIdentities()->orderBy('id')->get()->map(fn ($i) => ['provider' => $i->provider, 'provider_user_id' => $i->provider_user_id, 'blocked' => $i->blocked_at !== null])->all());`,
  370 |     );
  371 |     return JSON.parse(out.slice(out.indexOf('[')));
  372 | }
  373 | 
  374 | /** Usuario extra para identidades ajenas; queda sólo en la SQLite desechable para inspección al cerrar el run. */
  375 | export function createExtraE2eUser(label: string): string {
  376 |     const email = `e2e-sec-${label}@example.test`;
  377 |     artisan(
  378 |         `$u = App\\Models\\User::firstOrNew(['email' => ${q(email)}]); ` +
  379 |             `$u->forceFill(['name' => 'e2e extra ${label}', 'password' => Illuminate\\Support\\Facades\\Hash::make(${q(crypto.randomBytes(12).toString('hex') + '-Aa1!')}), 'email_verified_at' => now()])->save();`,
  380 |     );
  381 |     return email;
  382 | }
  383 | 
  384 | // ---------------------------------------------------------------------------------------------------------------
  385 | // OAuth del MCP (T-25): clientes DCR `e2e-*`, PKCE y llamadas a /mcp.
  386 | // ---------------------------------------------------------------------------------------------------------------
  387 | 
```