import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import {
    AUTH_FILE,
    AUTH_PAGES,
    animationsDone,
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
import {
    OAUTH_REDIRECT,
    clearFakeSocial,
    clearOAuthThrottle,
    clearSocialThrottle,
    deleteE2eOAuthClients,
    linkSocialDirect,
    pkce,
    setFakeSocial,
    stamp,
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
    await animationsDone(page); // fin de la transición del diálogo y del color del botón
    await axeNow(page, label);
    await expectNoOverflow(page);
}

test.describe('axe · públicas', { tag: '@responsive' }, () => {
    for (const [name, path] of PUBLIC_PAGES) test(name, async ({ page }) => axe(page, path));
});

test.describe('axe · autenticadas', { tag: '@responsive' }, () => {
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

    test('login con el botón de passkey', { tag: '@responsive' }, async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('button', { name: 'Entrar con passkey' })).toBeVisible();
        await axeYSinDesbordamiento(page, '/login (con passkey)');
    });

    test('reto 2FA: código y código de recuperación', { tag: '@responsive' }, async ({ page }) => {
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

    test('Perfil → Seguridad: sin 2FA, QR, códigos, 2FA activo, diálogo y passkeys', { tag: '@responsive' }, async ({ page, context }) => {
        const autenticador = await virtualAuthenticator(context, page);
        await loginAs(page, user);
        await confirmPassword(page, user.password);

        // Sin 2FA y sin passkeys.
        await page.goto('/profile');
        await expect(page.getByText(/^El doble factor está desactivado/)).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (sin 2FA)');

        // QR y clave visibles.
        await page.getByRole('button', { name: 'Activar doble factor' }).click();
        await expect(page.getByRole('img', { name: /Código QR para configurar/ })).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (QR visible)');
        const secret = (await page.locator('code').filter({ hasText: /^[A-Z2-7]{16,}$/ }).innerText()).trim();

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
        await axeYSinDesbordamiento(page, '/profile (diálogo de desactivar)');
        await page.getByRole('button', { name: 'Cancelar' }).click();

        // Lista de passkeys y sus diálogos.
        await page.getByRole('button', { name: 'Añadir passkey' }).click();
        await axeYSinDesbordamiento(page, '/profile (diálogo de alta de passkey)');
        await page.getByLabel('Nombre de la passkey').fill('Portátil con un nombre bastante largo para comprobar que no desborda en móvil');
        await page.getByRole('button', { name: 'Continuar' }).click();
        const lista = page.getByRole('list', { name: 'Tus passkeys' });
        await expect(lista.getByRole('listitem')).toHaveCount(1);
        expect(await autenticador.credentials()).toBe(1);
        await axeYSinDesbordamiento(page, '/profile (lista de passkeys)');
        await page.getByRole('button', { name: /^Eliminar la passkey/ }).click();
        await axeYSinDesbordamiento(page, '/profile (diálogo de eliminar passkey)');
    });
});

/**
 * A11Y-01 — Login con los botones sociales, Perfil → Cuentas vinculadas y Consentimiento OAuth del MCP (T-26): 0 violaciones
 * `serious`/`critical` y 0 px de desbordamiento horizontal (375 y 360 px en los proyectos móviles). Proveedor social FALSO
 * (APP_ENV=e2e) y usuario `e2e-sec-*` propio: nunca el demo ni el id 28.
 */
test.describe('axe · login social, cuentas vinculadas y consentimiento', () => {
    let user: E2eUser;

    test.beforeEach(async ({}, info) => {
        user = e2eUser(info.project.name);
        resetSecurity(user.email); // también borra identidades y tokens
        clearFakeSocial();
        clearSocialThrottle();
        clearOAuthThrottle();
        deleteE2eOAuthClients();
    });
    test.afterEach(() => {
        resetSecurity(user.email);
        clearFakeSocial();
        deleteE2eOAuthClients();
    });

    test('login con «Continuar con Google» y «Continuar con Facebook», y con un rechazo en pantalla', { tag: '@responsive' }, async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('link', { name: 'Continuar con Google' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Continuar con Facebook' })).toBeVisible();
        await axeYSinDesbordamiento(page, '/login (botones sociales)');

        // Con el aviso de un rechazo (role=alert) encima del formulario.
        setFakeSocial('google', { id: 'g-a11y', error: 'access_denied' });
        await page.getByRole('link', { name: 'Continuar con Google' }).click();
        await expect(page.getByTestId('login-error')).toBeVisible();
        await axeYSinDesbordamiento(page, '/login (rechazo del proveedor)');
    });

    test('Perfil → Cuentas vinculadas: sin vincular, vinculada y diálogo de desvincular', { tag: '@responsive' }, async ({ page }) => {
        await loginAs(page, user);
        await confirmPassword(page, user.password);

        await page.goto('/profile');
        await expect(page.getByTestId('linked-google')).toContainText('Sin vincular');
        await axeYSinDesbordamiento(page, '/profile (cuentas sin vincular)');

        linkSocialDirect(user.email, 'google', `g-${stamp()}`, user.email);
        await page.reload();
        await expect(page.getByTestId('linked-google')).toContainText('Vinculada');
        await axeYSinDesbordamiento(page, '/profile (cuenta vinculada)');

        await page.getByRole('button', { name: 'Desvincular Google' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await axeYSinDesbordamiento(page, '/profile (diálogo de desvincular)');
    });

    test('Perfil conserva identificadores únicos al confirmar la eliminación de cuenta', { tag: '@responsive' }, async ({ page }) => {
        await loginAs(page, user);
        await confirmPassword(page, user.password);
        await page.goto('/profile');
        await page.getByRole('button', { name: 'Eliminar cuenta', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: '¿Seguro que quieres eliminar tu cuenta?' });
        const password = dialog.getByLabel('Contraseña', { exact: true });
        await expect(password).toBeFocused();
        const id = await password.getAttribute('id');
        expect(id).toBeTruthy();
        await expect(page.locator(`[id="${id}"]`)).toHaveCount(1);
        await expect(page.locator(`[id="${id}-error"]`)).toHaveCount(1);
    });

    test('consentimiento OAuth: pantalla de autorización y pantalla de error', { tag: '@responsive' }, async ({ page, request, baseURL }) => {
        const registered = await request.post('/oauth/register', { data: { client_name: `e2e-a11y-${stamp()}`, redirect_uris: [OAUTH_REDIRECT] } });
        expect(registered.status()).toBe(201);
        const client = await registered.json();
        await loginAs(page, user);

        const query = new URLSearchParams({
            response_type: 'code',
            client_id: client.client_id,
            redirect_uri: OAUTH_REDIRECT,
            code_challenge: pkce().challenge,
            code_challenge_method: 'S256',
            state: 'a11y',
            resource: new URL('/mcp', baseURL).href,
        });
        await page.goto(`/oauth/authorize?${query}`);
        await expect(page.getByRole('heading', { name: 'Autorizar acceso al agente' })).toBeVisible();
        await axeYSinDesbordamiento(page, '/oauth/authorize (consentimiento)');

        // Con el preset «Todo» y todas las casillas marcadas (la pantalla más larga).
        await page.getByRole('button', { name: 'Todo', exact: true }).click();
        await axeYSinDesbordamiento(page, '/oauth/authorize (preset Todo)');

        await page.route('**/oauth/authorize', async route => {
            const data = route.request().postDataJSON();
            await route.continue({ postData: JSON.stringify({ ...data, abilities: [data.abilities[0], 'e2e.invalid'] }) });
        }, { times: 1 });
        await page.getByRole('button', { name: 'Autorizar', exact: true }).click();
        const group = page.getByRole('group', { name: 'Permisos', exact: true });
        const alert = page.getByRole('alert').filter({ hasText: /\S/ });
        await expect(alert).toBeVisible();
        await expect(group).toHaveAccessibleDescription(await alert.innerText());
        await expect(group).toBeFocused();

        // Pantalla de error propia (cliente desconocido): nunca redirige al cliente.
        await page.goto(`/oauth/authorize?${new URLSearchParams({ response_type: 'code', client_id: 'no-existe' })}`);
        await expect(page.getByRole('heading', { name: 'No se puede autorizar esta conexión' })).toBeVisible();
        await axeYSinDesbordamiento(page, '/oauth/authorize (error)');
    });
});

test('E2E-12 · el error de acceso queda asociado al campo de correo @responsive', async ({ page }, info) => {
    await page.goto('/login');
    const email = page.getByLabel('Correo electrónico');
    await email.fill(`e2e-invalid-${stamp()}-${info.project.name}@example.test`);
    await page.getByLabel('Contraseña', { exact: true }).fill('Fixture-invalid-Aa1!');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();

    const error = page.getByRole('alert').filter({ hasText: 'Estas credenciales no coinciden con nuestros registros.' });
    await expect(error).toBeVisible();
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAccessibleDescription(await error.innerText());
    await axeYSinDesbordamiento(page, 'Inicio de sesión con error asociado');
});

test.describe('E2E-12 · pestañas del área autenticada', () => {
    test.use({ storageState: AUTH_FILE });

    test('Calendario navega con flechas y anuncia el panel activo @responsive', async ({ page }) => {
        await page.goto('/calendar?view=week');
        const tabs = page.getByRole('tablist', { name: 'Vista del calendario' });
        const week = tabs.getByRole('tab', { name: 'Semana', exact: true });
        const month = tabs.getByRole('tab', { name: 'Mes', exact: true });
        const list = tabs.getByRole('tab', { name: 'Lista', exact: true });

        await expect(week).toHaveAttribute('aria-selected', 'true');
        await expect(month).toHaveAttribute('tabindex', '-1');
        await week.focus();
        await page.keyboard.press('ArrowRight');
        await expect(month).toBeFocused();
        await expect(month).toHaveAttribute('aria-selected', 'true');
        const panel = page.getByRole('tabpanel', { name: 'Mes', exact: true });
        await expect(panel).toHaveAttribute('id', (await month.getAttribute('aria-controls'))!);

        await page.keyboard.press('End');
        await expect(list).toBeFocused();
        await expect(list).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByRole('tabpanel', { name: 'Lista', exact: true })).toBeVisible();
        await page.keyboard.press('ArrowRight');
        await expect(week).toBeFocused();
        await expect(week).toHaveAttribute('aria-selected', 'true');
        await axeYSinDesbordamiento(page, 'Calendario con navegación por pestañas');
    });

    test('las pestañas de instalación de MCP conservan foco y panel @responsive', async ({ page }) => {
        await page.goto('/api-keys');
        const tabs = page.getByRole('tablist', { name: 'Cliente' });
        const first = tabs.getByRole('tab', { name: 'Claude Code', exact: true });
        const last = tabs.getByRole('tab', { name: 'OpenCode', exact: true });

        await first.focus();
        await page.keyboard.press('ArrowLeft');
        await expect(last).toBeFocused();
        await expect(last).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByRole('tabpanel')).toHaveAccessibleName(await last.innerText());
        await page.keyboard.press('Home');
        await expect(first).toHaveAttribute('aria-selected', 'true');
        await expect(first).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('tabpanel', { name: 'Claude Code' })).toBeFocused();
    });

    test('API keys enfoca el grupo de permisos rechazado por el servidor @responsive', async ({ page }) => {
        await page.goto('/api-keys');
        await page.getByLabel('Nombre (p. ej. Claude Desktop)').fill('Permisos no válidos');
        await page.route('**/api-keys', async route => {
            const data = route.request().postDataJSON();
            await route.continue({ postData: JSON.stringify({ ...data, abilities: [data.abilities[0], 'e2e.invalid'] }) });
        }, { times: 1 });
        await page.getByRole('button', { name: 'Crear API key', exact: true }).click();
        const permissions = page.getByRole('group', { name: 'Permisos', exact: true });
        await expect(permissions).toBeFocused();
        await expect(permissions).toHaveAccessibleDescription(/permiso|válid/i);
        await expect(permissions.locator('input[type=checkbox][aria-describedby]')).toHaveCount(0);
        await axeYSinDesbordamiento(page, 'API keys con error de permisos');
    });
});
