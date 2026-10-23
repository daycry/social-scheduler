import { expect, test, type Page, type TestInfo } from '@playwright/test';
import {
    clearFakeSocial,
    clearSocialThrottle,
    confirmPassword,
    createExtraE2eUser,
    e2eUser,
    enableTwoFactorDirect,
    expectNoOverflow,
    linkSocialDirect,
    loginAs,
    resetSecurity,
    setFakeSocial,
    socialRows,
    stamp,
    totp,
    watch,
    type E2eUser,
} from './helpers';

/**
 * Login social (Google y Facebook) y Cuentas vinculadas, de punta a punta con el proveedor FALSO (T-23): el servidor
 * E2E corre con APP_ENV=e2e y lee la identidad que "devuelve el proveedor" de un fichero que prepara cada test.
 * Solo se usan los usuarios `e2e-sec-*` (nunca el demo ni el id 28); cada test parte sin identidades ni 2FA y lo deja igual.
 * CA-10 (sin credenciales no hay botón) no se puede ver aquí —el servidor E2E siempre las tiene—: lo cubre
 * `tests/Feature/Auth/LoginPageProvidersTest.php`. Google y Facebook reales: M-01 y M-02 (manuales).
 */

const REJECTED = 'No hay ninguna cuenta vinculada a esta identidad. Vincúlala desde tu perfil.';

const shot = async (page: Page, info: TestInfo, name: string) =>
    info.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

/** Cierra la sesión sin pasar por el menú: las cookies son la sesión. */
const logout = (page: Page) => page.context().clearCookies();

let user: E2eUser;

test.beforeEach(async ({}, info) => {
    user = e2eUser(info.project.name);
    resetSecurity(user.email); // también borra sus identidades sociales
    clearFakeSocial();
    clearSocialThrottle();
});
test.afterEach(() => {
    resetSecurity(user.email);
    clearFakeSocial();
});

/** Sin sesión: /dashboard manda a /login. */
async function expectGuest(page: Page) {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
}

test.describe('Login social · entrar', () => {
    test('E2E-01 · Google sin 2FA: entra sin contraseña y deja la vinculación (CA-01, CA-02)', async ({ page }, info) => {
        const w = watch(page);
        setFakeSocial('google', { id: `g-${stamp()}`, email: user.email, email_verified: true, name: 'Persona E2E' });

        await page.goto('/login');
        await expect(page.getByRole('link', { name: 'Continuar con Google' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Continuar con Facebook' })).toBeVisible();
        await expectNoOverflow(page);
        await shot(page, info, 'login-con-botones');

        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await page.waitForURL(/\/dashboard/);
        await shot(page, info, 'dashboard-tras-google');

        // CA-02: la primera vez se crea la vinculación y se ve en Cuentas vinculadas.
        expect(socialRows(user.email)).toEqual([expect.objectContaining({ provider: 'google', blocked: false })]);
        await page.goto('/profile');
        const google = page.getByTestId('linked-google');
        await expect(google).toContainText('Vinculada');
        await expect(google).toContainText(user.email);
        expect(w.errors).toEqual([]);
    });

    test('E2E-01 · una segunda entrada con la identidad ya vinculada vuelve a entrar', async ({ page }) => {
        const id = `g-${stamp()}`;
        linkSocialDirect(user.email, 'google', id, user.email);
        setFakeSocial('google', { id, email: 'otro-correo@example.test', email_verified: false });

        await page.goto('/login');
        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await page.waitForURL(/\/dashboard/);
        expect(socialRows(user.email)).toHaveLength(1);
    });

    test('E2E-02 · Google con 2FA: pide el reto, no hay sesión antes y el TOTP lleva al destino (CA-06)', async ({ page }, info) => {
        const { secret } = enableTwoFactorDirect(user.email);
        const id = `g-${stamp()}`;
        linkSocialDirect(user.email, 'google', id, user.email);
        setFakeSocial('google', { id, email: user.email, email_verified: true });

        await page.goto('/login');
        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await page.waitForURL(/two-factor-challenge/);
        await shot(page, info, 'reto-2fa-tras-google');

        // Tras el proveedor todavía no hay sesión autenticada: las rutas protegidas no se abren.
        await page.goto('/dashboard');
        await expect(page).not.toHaveURL(/\/dashboard/);

        await page.goto('/two-factor-challenge');
        await expect(page.getByLabel('Código de verificación')).toBeVisible();
        await page.getByLabel('Código de verificación').fill(totp(secret));
        await page.getByRole('button', { name: 'Verificar' }).click();
        await page.waitForURL(/\/dashboard/);
    });

    test('E2E-03 · rechazos: email no verificado, sin cuenta, Facebook sin vincular y cancelar (CA-03, CA-04, CA-05, CA-09)', async ({ page }, info) => {
        const start = async (provider: 'Google' | 'Facebook') => {
            await page.goto('/login');
            await page.getByRole('link', { name: `Continuar con ${provider}` }).click();
            // El rechazo vuelve a /login (la misma URL de partida): la señal es el aviso, no el cambio de URL.
            await expect(page.getByTestId('login-error')).toBeVisible();
            return (await page.getByTestId('login-error').innerText()).trim();
        };

        // 1) Google con el email de un usuario real pero sin verificar.
        setFakeSocial('google', { id: `g-${stamp()}-a`, email: user.email, email_verified: false });
        const sinVerificar = await start('Google');
        await shot(page, info, 'rechazo-email-no-verificado');
        await expectGuest(page);

        // 2) Google verificado, pero sin cuenta local con ese correo.
        setFakeSocial('google', { id: `g-${stamp()}-b`, email: `nadie-${stamp()}@example.test`, email_verified: true });
        const sinCuenta = await start('Google');
        await expectGuest(page);

        // 3) Facebook con el email de un usuario sin identidad vinculada: nunca se vincula solo.
        setFakeSocial('facebook', { id: `fb-${stamp()}`, email: user.email });
        const facebookSinVincular = await start('Facebook');
        await expectGuest(page);

        // Los tres rechazos por email muestran exactamente el mismo texto: no se puede enumerar qué correos existen (CA-04).
        expect([sinVerificar, sinCuenta, facebookSinVincular]).toEqual([REJECTED, REJECTED, REJECTED]);

        // 4) El usuario cancela en el proveedor.
        setFakeSocial('google', { id: 'g-cancel', error: 'access_denied' });
        expect(await start('Google')).toBe('Has cancelado el inicio de sesión con Google.');
        await expectGuest(page);

        // Ninguna de las cuatro creó identidades.
        expect(socialRows(user.email)).toEqual([]);
        await expectNoOverflow(page);
    });
});

test.describe('Cuentas vinculadas · perfil', () => {
    test('E2E-04 · vincular Facebook pide la contraseña, vincula y lo muestra (CA-07)', async ({ page }, info) => {
        const w = watch(page);
        const id = `fb-${stamp()}`;
        setFakeSocial('facebook', { id, email: user.email, name: 'Persona FB' });
        await loginAs(page, user);
        await page.goto('/profile');
        const facebook = page.getByTestId('linked-facebook');
        await expect(facebook).toContainText('Sin vincular');
        await shot(page, info, 'cuentas-vinculadas-antes');

        // Sin contraseña confirmada, la acción manda a confirmarla (no abre el proveedor ni da un 423 visible).
        await page.getByRole('button', { name: 'Vincular Facebook' }).click();
        await page.waitForURL(/confirm-password/);
        expect(socialRows(user.email)).toEqual([]);
        await page.getByLabel('Contraseña').fill(user.password);
        await page.getByRole('button', { name: 'Confirmar' }).click();
        await page.waitForURL(/\/dashboard|\/profile/);

        await page.goto('/profile');
        await page.getByRole('button', { name: 'Vincular Facebook' }).click();
        await page.waitForURL(/\/profile/);
        await expect(page.getByText('Facebook vinculado a tu cuenta.')).toBeVisible();
        await expect(page.getByTestId('linked-facebook')).toContainText('Vinculada');
        await expect(page.getByTestId('linked-facebook')).toContainText(user.email);
        expect(socialRows(user.email)).toEqual([expect.objectContaining({ provider: 'facebook', provider_user_id: id, blocked: false })]);
        await shot(page, info, 'cuentas-vinculadas-despues');
        await expectNoOverflow(page);
        // El 409 es la navegación completa al proveedor (Inertia.location): esperado.
        expect(w.errors.filter((e) => !/status of 409/.test(e))).toEqual([]);
    });

    test('E2E-04 · una identidad ya vinculada a otro usuario da error y no cambia nada (CA-07)', async ({ page }, info) => {
        const owner = createExtraE2eUser(`ajeno-${info.project.name}`);
        const id = `fb-ajeno-${stamp()}`;
        linkSocialDirect(owner, 'facebook', id, owner);
        setFakeSocial('facebook', { id, email: user.email });

        await loginAs(page, user);
        await confirmPassword(page, user.password);
        await page.goto('/profile');
        await page.getByRole('button', { name: 'Vincular Facebook' }).click();
        await page.waitForURL(/\/profile/);

        await expect(page.getByText('Esa cuenta de Facebook no se puede vincular: ya está en uso.')).toBeVisible();
        await expect(page.getByTestId('linked-facebook')).toContainText('Sin vincular');
        expect(socialRows(user.email)).toEqual([]);
        expect(socialRows(owner)).toHaveLength(1);
        await shot(page, info, 'cuentas-vinculadas-error');
    });

    test('E2E-04 · desvincular bloquea la identidad: ni el login con ella ni Google por email la recuperan (CA-08)', async ({ page }, info) => {
        const id = `g-${stamp()}`;
        linkSocialDirect(user.email, 'google', id, user.email);
        setFakeSocial('google', { id, email: user.email, email_verified: true });

        await loginAs(page, user);
        await confirmPassword(page, user.password);
        await page.goto('/profile');
        await expect(page.getByTestId('linked-google')).toContainText('Vinculada');

        await page.getByRole('button', { name: 'Desvincular Google' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await shot(page, info, 'desvincular-confirmacion');
        await dialog.getByRole('button', { name: 'Desvincular', exact: true }).click();

        await expect(page.getByText('Google desvinculado.')).toBeVisible();
        await expect(page.getByTestId('linked-google')).toContainText('Sin vincular');
        expect(socialRows(user.email)).toEqual([expect.objectContaining({ provider: 'google', blocked: true })]);

        // Con la misma identidad (y el correo verificado) el login ya no entra ni la vuelve a vincular sola.
        await logout(page);
        await page.goto('/login');
        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await expect(page.getByTestId('login-error')).toHaveText(REJECTED);
        await expectGuest(page);
        expect(socialRows(user.email)).toEqual([expect.objectContaining({ provider: 'google', blocked: true })]);
    });
});
