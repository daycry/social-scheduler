import { expect, test, type Page } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Bluesky (C-01, E2E-03): conexión con handle + app password y superficies de la red nueva (calendario, dashboard, compositor).
 * Usa los usuarios `e2e-sec-*` y marcas `e2e-composer-*` con una cuenta de Bluesky SEMBRADA (factoría, PDS inexistente): la API
 * real nunca se llama y el formulario no se envía (el servidor consultaría a Bluesky; eso lo cubre Pest con `Http::fake`).
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `bsky-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const card = (page: Page) => page.locator('section[data-network="bluesky"]');

test('E2E-03 · conectar Bluesky: formulario de credenciales accesible y sin desborde', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/connections`);
    await settle(page);

    await expect(card(page)).toBeVisible();
    await expect(card(page).getByRole('heading', { name: 'Bluesky' })).toBeVisible();
    await expect(card(page)).toContainText('Sin cuentas conectadas');
    await expectNoOverflow(page);

    await card(page).getByRole('button', { name: '+ Conectar' }).click();
    const handle = card(page).getByLabel('Handle de Bluesky');
    const password = card(page).getByLabel('App password');
    await expect(handle).toBeVisible();
    await expect(password).toHaveAttribute('type', 'password');
    await expect(password).toHaveAttribute('autocomplete', 'new-password');
    await expect(card(page)).toContainText('app password');
    await expect(card(page).getByText('No uses tu contraseña principal')).toBeVisible();

    // Los campos son obligatorios: con ellos vacíos el navegador no deja enviar (y nada llega a Bluesky).
    await expect(handle).toHaveJSProperty('required', true);
    await expect(password).toHaveJSProperty('required', true);

    await handle.fill('e2e.bsky.invalid');
    await password.fill('aaaa-bbbb-cccc-dddd');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Conexiones con el formulario de Bluesky');

    // Cancelar cierra el formulario y borra lo escrito.
    await card(page).getByRole('button', { name: 'Cancelar' }).click();
    await expect(handle).toHaveCount(0);
    await card(page).getByRole('button', { name: '+ Conectar' }).click();
    await expect(card(page).getByLabel('Handle de Bluesky')).toHaveValue('');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-03 · una cuenta de Bluesky conectada aparece con su estado y sin credenciales en la página', async ({ page }) => {
    const w = watch(page);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
    await page.goto(`/brands/${brand.id}/connections`);
    await settle(page);

    await expect(card(page)).toContainText('e2e Bluesky');
    await expect(card(page)).toContainText('Conectada');
    await expect(card(page).getByRole('button', { name: 'Desconectar' })).toBeVisible();
    // Reconectar una cuenta con app password se hace desde «+ Conectar», no por redirección OAuth.
    await expect(card(page).getByRole('button', { name: /Reconectar|Conectar de nuevo/ })).toHaveCount(0);
    const html = await page.content();
    expect(html).not.toContain('credential_secret');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Conexiones con una cuenta de Bluesky');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-03 · Bluesky en el calendario y en el dashboard (icono, filtro y página de red)', async ({ page }) => {
    const w = watch(page);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');

    // Calendario: el filtro de red ofrece Bluesky y lo aplica.
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    await settle(page);
    const red = page.getByLabel('Red', { exact: true });
    await expect(red.locator('option', { hasText: 'Bluesky' })).toHaveCount(1);
    await red.selectOption('bluesky');
    await page.waitForURL(/network=bluesky/);
    await settle(page);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Calendario filtrado por Bluesky');

    // Dashboard: página de la red con el icono y las métricas «No disponible» (sin datos aún, nunca 0).
    await page.goto(`/dashboard/bluesky?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Bluesky');
    await expect(page.getByText('No disponible').first()).toBeVisible();
    await expect(page.getByText('Alcance', { exact: true })).toHaveCount(0); // Bluesky no da alcance (ni visualizaciones de cuenta)
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Dashboard de Bluesky');

    // Resumen: Bluesky entra en las pestañas de red (móvil) o en la barra lateral (escritorio).
    await page.goto(`/dashboard?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('link', { name: /Bluesky/ }).first()).toBeVisible();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Resumen con Bluesky');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-03 · compositor: Bluesky cuenta grafemas (un emoji compuesto es 1) con tope de 300', async ({ page }) => {
    const w = watch(page);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const bsky = page.getByRole('button', { name: /^Bluesky: e2e Bluesky/ });
    await expect(bsky).toHaveAttribute('aria-pressed', 'false');
    await bsky.click();
    await expect(bsky).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: /Tipo de contenido en Bluesky/ })).toContainText('Publicación');

    // «Hola » (5) + familia con ZWJ (1 grafema, 5 puntos de código) = 6 grafemas.
    const familia = '\u{1F468}‍\u{1F469}‍\u{1F467}';
    await page.getByLabel('Texto', { exact: true }).fill(`Hola ${familia}`);
    const contador = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'Bluesky' });
    await expect(contador.getByTestId('counter-chars')).toHaveText('6 / 300');

    await page.getByLabel('Texto', { exact: true }).fill('a'.repeat(301));
    await expect(contador.getByTestId('counter-chars')).toContainText('301 / 300');
    await expect(contador.getByTestId('counter-chars')).toContainText('supera el máximo');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Compositor con Bluesky');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
