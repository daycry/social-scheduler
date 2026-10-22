import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import {
    AUTH_FILE,
    AUTH_PAGES,
    PUBLIC_PAGES,
    confirmPassword,
    e2eUser,
    enableTwoFactorDirect,
    expectNoOverflow,
    loginAs,
    loginUntilChallenge,
    resetSecurity,
    settle,
    totp,
    virtualAuthenticator,
    type E2eUser,
} from './helpers';

/** (4) Accesibilidad: ninguna violación `critical` ni `serious` (reglas WCAG 2 A/AA). */
async function axeNow(page: Page, label: string) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

async function axe(page: Page, path: string) {
    await page.goto(path);
    await settle(page);
    await axeNow(page, path);
}

/** axe y 0 px de desbordamiento horizontal (375 y 360 px en los proyectos móviles) en el estado actual de la página. */
async function axeYSinDesbordamiento(page: Page, label: string) {
    await settle(page);
    // La barra de progreso de Inertia (NProgress, `role="bar"`) es transitoria y no es de la app: se espera a que desaparezca.
    await expect(page.locator('#nprogress')).toHaveCount(0);
    // El ratón queda donde hizo el último clic y activa el :hover del botón bajo él; el contraste se mide en reposo.
    await page.mouse.move(0, 0);
    await axeNow(page, label);
    await expectNoOverflow(page);
}

test.describe('axe · públicas', () => {
    for (const [name, path] of PUBLIC_PAGES) test(name, async ({ page }) => axe(page, path));
});

test.describe('axe · autenticadas', () => {
    test.use({ storageState: AUTH_FILE });
    for (const [name, path] of AUTH_PAGES) test(name, async ({ page }) => axe(page, path));
});

/**
 * A11Y-01 — Login, reto 2FA y Perfil → Seguridad en sus estados (CA-23, CA-24). Usuario E2E propio por proyecto
 * (nunca el demo): cada test parte de «sin 2FA ni passkeys» y lo deja igual.
 */
test.describe('axe · seguridad de cuenta', () => {
    let user: E2eUser;

    test.beforeEach(async ({}, info) => {
        user = e2eUser(info.project.name);
        resetSecurity(user.email);
    });
    test.afterEach(() => resetSecurity(user.email));

    test('login con el botón de passkey', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('button', { name: 'Entrar con passkey' })).toBeVisible();
        await axeYSinDesbordamiento(page, '/login (con passkey)');
    });

    test('reto 2FA: código y código de recuperación', async ({ page }) => {
        enableTwoFactorDirect(user.email);
        await loginUntilChallenge(page, user);
        await axeYSinDesbordamiento(page, '/two-factor-challenge (código)');
        await page.getByRole('button', { name: 'Usar un código de recuperación' }).click();
        await expect(page.getByLabel('Código de recuperación')).toBeVisible();
        await axeYSinDesbordamiento(page, '/two-factor-challenge (recuperación)');
        // Con un error del servidor en pantalla (aria-live, aria-invalid).
        await page.getByLabel('Código de recuperación').fill('no-es-un-codigo');
        await page.getByRole('button', { name: 'Verificar' }).click();
        await expect(page.getByText('El código de recuperación no es válido.')).toBeVisible();
        await axeYSinDesbordamiento(page, '/two-factor-challenge (con error)');
    });

    test('Perfil → Seguridad: sin 2FA, QR, códigos, 2FA activo, diálogo y passkeys', async ({ page, context }) => {
        const autenticador = await virtualAuthenticator(context, page);
        await loginAs(page, user);
        await confirmPassword(page, user.password);

        // Sin 2FA y sin passkeys.
        await page.goto('/profile');
        await expect(page.getByText('El doble factor está desactivado')).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (sin 2FA)');

        // QR y clave visibles.
        await page.getByRole('button', { name: 'Activar doble factor' }).click();
        await expect(page.getByRole('img', { name: /Código QR para configurar/ })).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (QR visible)');
        const secret = (await page.locator('code').filter({ hasText: /^[A-Z2-7]{16,}$/ }).first().innerText()).trim();

        // Códigos de recuperación visibles.
        await page.locator('#tf_code').fill(totp(secret));
        await page.getByRole('button', { name: 'Confirmar y activar' }).click();
        await expect(page.getByRole('list', { name: 'Códigos de recuperación' }).getByRole('listitem')).toHaveCount(8);
        await axeYSinDesbordamiento(page, '/profile (códigos visibles)');

        // 2FA activo (sin códigos) y el diálogo de desactivación.
        await page.reload();
        await expect(page.getByText(/El doble factor está\s+activado/)).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (2FA activo)');
        await page.getByRole('button', { name: 'Desactivar doble factor' }).click();
        await expect(page.getByLabel('Contraseña', { exact: true })).toBeVisible();
        await page.waitForTimeout(400); // transición del diálogo
        await axeYSinDesbordamiento(page, '/profile (diálogo de desactivar)');
        await page.getByRole('button', { name: 'Cancelar' }).click();

        // Lista de passkeys y sus diálogos.
        await page.getByRole('button', { name: 'Añadir passkey' }).click();
        await page.waitForTimeout(400);
        await axeYSinDesbordamiento(page, '/profile (diálogo de alta de passkey)');
        await page.getByLabel('Nombre de la passkey').fill('Portátil con un nombre bastante largo para comprobar que no desborda en móvil');
        await page.getByRole('button', { name: 'Continuar' }).click();
        const lista = page.getByRole('list', { name: 'Tus passkeys' });
        await expect(lista.getByRole('listitem')).toHaveCount(1);
        expect(await autenticador.credentials()).toBe(1);
        await axeYSinDesbordamiento(page, '/profile (lista de passkeys)');
        await page.getByRole('button', { name: /^Eliminar la passkey/ }).click();
        await page.waitForTimeout(400);
        await axeYSinDesbordamiento(page, '/profile (diálogo de eliminar passkey)');
    });
});
