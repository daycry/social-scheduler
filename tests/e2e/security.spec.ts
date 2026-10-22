import { expect, test, type Page, type TestInfo } from '@playwright/test';
import {
    clearTotpReplay,
    confirmPassword,
    confirmPasswordIfAsked,
    e2eUser,
    enableTwoFactorDirect,
    expectNoOverflow,
    loginAs,
    resetSecurity,
    totp,
    watch,
    wrongTotp,
    type E2eUser,
} from './helpers';

/**
 * Seguridad de cuenta: doble factor (TOTP) y passkeys, de punta a punta en la UI real.
 * Cada proyecto usa su propio usuario `e2e-sec-<proyecto>` (creado en global-setup, borrado en global-teardown):
 * nunca el usuario demo. Cada test parte de «sin 2FA ni passkeys» y lo deja igual (idempotente).
 * El TOTP se calcula en Node (RFC 6238) con el secreto que muestra la pantalla.
 */

const shot = async (page: Page, info: TestInfo, name: string) =>
    info.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

/** Cierra la sesión sin pasar por el menú: las cookies son la sesión. */
const logout = (page: Page) => page.context().clearCookies();

/** Envía el formulario del reto y espera a la respuesta del servidor (el error persiste entre intentos: no sirve como señal). */
async function submitChallenge(page: Page, field: 'code' | 'recovery', value: string) {
    await page.locator(field === 'code' ? '#code' : '#recovery_code').fill(value);
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/two-factor-challenge') && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Verificar' }).click(),
    ]);
    await page.waitForLoadState('networkidle');
    // Si sigue en el reto, la app vacía el campo al terminar (form.reset): hay que esperarlo antes de escribir el siguiente.
    const input = page.locator(field === 'code' ? '#code' : '#recovery_code');
    await expect.poll(async () => (await input.count()) === 0 || (await input.inputValue()) === '').toBe(true);
    return response;
}

/** Email y contraseña correctos hasta llegar al reto (sin completarlo). */
async function loginUntilChallenge(page: Page, user: E2eUser, remember = false) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(user.email);
    await page.getByLabel('Contraseña').fill(user.password);
    if (remember) await page.getByLabel('Recordarme').check();
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(/two-factor-challenge/);
}

test.describe('2FA · doble factor (TOTP)', () => {
    let user: E2eUser;

    test.beforeEach(async ({}, info) => {
        user = e2eUser(info.project.name);
        resetSecurity(user.email);
    });
    test.afterEach(() => resetSecurity(user.email));

    test('2FA · activar con un TOTP real y recibir 8 códigos (E2E-01)', async ({ page }, info) => {
        const w = watch(page);
        await loginAs(page, user);
        await page.goto('/profile');
        await expect(page.getByText('El doble factor está desactivado')).toBeVisible();

        // Activar pide la contraseña (password.confirm) y retoma el perfil.
        await page.getByRole('button', { name: 'Activar doble factor' }).click();
        await confirmPasswordIfAsked(page, user.password);
        if (!(await page.getByLabel('Código de verificación').isVisible())) {
            await page.getByRole('button', { name: 'Activar doble factor' }).click();
        }

        // CA-24: el QR tiene `alt` y el secreto es texto copiable.
        const qr = page.getByRole('img', { name: /Código QR para configurar/ });
        await expect(qr).toBeVisible();
        await expect(qr).toHaveAttribute('alt', /clave de configuración/);
        const secretNode = page.locator('code').filter({ hasText: /^[A-Z2-7]{16,}$/ }).first();
        const secret = (await secretNode.innerText()).trim();
        expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
        await expect(page.getByRole('button', { name: 'Copiar clave' })).toBeVisible();
        await shot(page, info, 'e2e-01-qr-con-secreto');
        await expectNoOverflow(page);

        // CA-02: un código incorrecto no activa nada.
        await page.locator('#tf_code').fill(wrongTotp(secret));
        await page.getByRole('button', { name: 'Confirmar y activar' }).click();
        await expect(page.getByText('El código no es válido. Comprueba la hora de tu móvil')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirmar y activar' })).toBeVisible();
        await shot(page, info, 'e2e-01-error-de-codigo');

        // CA-01: el TOTP calculado en Node lo acepta TotpService; salen 8 códigos, una sola vez.
        await page.locator('#tf_code').fill(totp(secret));
        await page.getByRole('button', { name: 'Confirmar y activar' }).click();
        await expect(page.getByRole('heading', { name: 'Guarda tus códigos de recuperación' })).toBeVisible();
        const codes = page.getByRole('list', { name: 'Códigos de recuperación' }).getByRole('listitem');
        await expect(codes).toHaveCount(8);
        await expect(page.getByRole('button', { name: 'Copiar códigos' })).toBeVisible();
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descargar .txt' }).click()]);
        expect(download.suggestedFilename()).toBe('codigos-de-recuperacion.txt');
        await shot(page, info, 'e2e-01-ocho-codigos');
        await expectNoOverflow(page);

        // Al recargar: 2FA activo y los códigos no vuelven a mostrarse.
        await page.reload();
        await expect(page.getByText(/El doble factor está\s+activado/)).toBeVisible();
        await expect(page.getByText('Te quedan 8 códigos de recuperación')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Guarda tus códigos de recuperación' })).toHaveCount(0);
        expect(w.errors).toEqual([]);
    });

    test('2FA · el login pide el reto, lo supera con el TOTP y rechaza su reutilización (E2E-02)', async ({ page }, info) => {
        const { secret } = enableTwoFactorDirect(user.email);

        // CA-04: tras la contraseña no se entra al panel.
        await loginUntilChallenge(page, user, true);
        await expect(page.getByRole('heading', { level: 1, name: 'Verificación en dos pasos' })).toBeVisible();
        await shot(page, info, 'e2e-02-reto');
        await expectNoOverflow(page);
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login/);

        // CA-23: etiqueta visible, one-time-code y teclado numérico.
        await page.goto('/two-factor-challenge');
        const field = page.getByLabel('Código de verificación');
        await expect(field).toBeVisible();
        await expect(field).toHaveAttribute('autocomplete', 'one-time-code');
        await expect(field).toHaveAttribute('inputmode', 'numeric');

        // CA-05: el TOTP válido completa el login («Recordarme» deja su cookie).
        const code = totp(secret);
        await submitChallenge(page, 'code', code);
        await expect(page).toHaveURL(/\/dashboard/);
        expect((await page.context().cookies()).some((c) => c.name.startsWith('remember_web_'))).toBe(true);

        // CA-07: el mismo código, reenviado en otro login dentro de su ventana, se rechaza.
        await logout(page);
        await loginUntilChallenge(page, user);
        await submitChallenge(page, 'code', code);
        await expect(page.getByText('El código no es válido o ya se ha usado.')).toBeVisible();
        await expect(page).toHaveURL(/two-factor-challenge/);
        await shot(page, info, 'e2e-02-codigo-reutilizado');
    });

    test('2FA · un código de recuperación sirve una vez y regenerar invalida los viejos (E2E-03)', async ({ page }, info) => {
        const { recoveryCodes } = enableTwoFactorDirect(user.email);

        // Alternar con teclado: el enlace es un botón enfocable que se activa con Enter.
        await loginUntilChallenge(page, user);
        const toggle = page.getByRole('button', { name: 'Usar un código de recuperación' });
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByLabel('Código de recuperación')).toBeVisible();
        await shot(page, info, 'e2e-03-reto-recuperacion');

        // CA-09: el primer uso entra; el segundo, con el mismo código, falla.
        await submitChallenge(page, 'recovery', recoveryCodes[0]);
        await expect(page).toHaveURL(/\/dashboard/);
        await logout(page);
        await loginUntilChallenge(page, user);
        await page.getByRole('button', { name: 'Usar un código de recuperación' }).click();
        await submitChallenge(page, 'recovery', recoveryCodes[0]);
        await expect(page.getByText('El código de recuperación no es válido.')).toBeVisible();

        // Entra con otro y regenera desde el perfil (CA-10).
        await submitChallenge(page, 'recovery', recoveryCodes[1]);
        await expect(page).toHaveURL(/\/dashboard/);
        await confirmPassword(page, user.password);
        await page.goto('/profile');
        await expect(page.getByText('Te quedan 6 códigos de recuperación')).toBeVisible();
        await page.getByRole('button', { name: 'Generar códigos nuevos' }).click();
        const list = page.getByRole('list', { name: 'Códigos de recuperación' }).getByRole('listitem');
        await expect(list).toHaveCount(8);
        const fresh = await list.allInnerTexts();
        expect(fresh.filter((c) => recoveryCodes.includes(c.trim()))).toEqual([]);
        await shot(page, info, 'e2e-03-codigos-regenerados');

        // Un código antiguo ya no vale; uno nuevo sí.
        await logout(page);
        await loginUntilChallenge(page, user);
        await page.getByRole('button', { name: 'Usar un código de recuperación' }).click();
        await submitChallenge(page, 'recovery', recoveryCodes[2]);
        await expect(page.getByText('El código de recuperación no es válido.')).toBeVisible();
        await submitChallenge(page, 'recovery', fresh[0].trim());
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('2FA · bloqueo tras 5 fallos y desactivación con contraseña + código (E2E-04)', async ({ page }, info) => {
        const { secret } = enableTwoFactorDirect(user.email);

        // CA-06: 5 fallos y el sexto intento se bloquea aunque el código sea el correcto.
        await loginUntilChallenge(page, user);
        for (let i = 0; i < 5; i++) {
            const response = await submitChallenge(page, 'code', wrongTotp(secret));
            expect(response.status()).toBeLessThan(500);
            await expect(page).toHaveURL(/two-factor-challenge/);
        }
        await submitChallenge(page, 'code', totp(secret));
        await expect(page.getByText(/Demasiados intentos de acceso\. Inténtalo de nuevo en \d+ segundos/)).toBeVisible();
        await expect(page).toHaveURL(/two-factor-challenge/);
        await shot(page, info, 'e2e-04-bloqueo');

        // Desbloqueo: se libera el limitador de la cuenta en vez de esperar el minuto real (desviación documentada).
        clearTotpReplay(user.email);
        await submitChallenge(page, 'code', totp(secret));
        await expect(page).toHaveURL(/\/dashboard/);

        // CA-08: desactivar exige contraseña Y un código válido.
        await confirmPassword(page, user.password);
        await page.goto('/profile');
        await page.getByRole('button', { name: 'Desactivar doble factor' }).click();
        const dialog = page.locator('form', { hasText: 'Desactivar el doble factor' });
        await expect(dialog).toBeVisible();

        // Cada rechazo vacía los campos y devuelve el foco a la contraseña: se espera a ese foco antes de volver a escribir.
        const submitDisable = async (password: string, code: string) => {
            await dialog.getByLabel('Contraseña').fill(password);
            await dialog.getByLabel('Código de verificación').fill(code);
            await dialog.getByRole('button', { name: 'Desactivar', exact: true }).click();
        };
        const rejected = async (message: string | RegExp) => {
            await expect(dialog.getByText(message).first()).toBeVisible();
            await expect(dialog.getByLabel('Contraseña')).toBeFocused();
            await expect(dialog.getByLabel('Contraseña')).toHaveValue('');
        };

        await submitDisable('', ''); // sin nada
        await rejected(/contraseña es obligatorio/);
        await submitDisable('contraseña-incorrecta', totp(secret)); // contraseña mala, código bueno
        await rejected(/contraseña (es|no|proporcionada)|incorrecta/i);
        await submitDisable(user.password, wrongTotp(secret)); // contraseña buena, código malo
        await rejected('El código no es válido.');
        await expect(dialog).toBeVisible();

        clearTotpReplay(user.email);
        await dialog.getByLabel('Contraseña').fill(user.password);
        await dialog.getByLabel('Código de verificación').fill(totp(secret));
        await dialog.getByRole('button', { name: 'Desactivar', exact: true }).click();
        await expect(page.getByText('El doble factor está desactivado')).toBeVisible();
        await shot(page, info, 'e2e-04-tarjeta-desactivada');

        // Y el siguiente login ya no pide código.
        await logout(page);
        await loginAs(page, user);
        await expect(page).toHaveURL(/\/dashboard/);
    });
});
