import { expect, test } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedAnalyticsSnapshots, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * «Todas las marcas» (C-03b, E2E-04): suma de dos marcas sembradas, «No disponible» donde ninguna tiene dato, una fila por marca y el
 * cambio de periodo. Usuarios `e2e-sec-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

let alfa: ComposerBrand;
let beta: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    alfa = seedComposerBrand(user.email, `allb-a-${stamp()}-${info.project.name}`);
    beta = seedComposerBrand(user.email, `allb-b-${stamp()}-${info.project.name}`);
    seedAnalyticsSnapshots(alfa.id, 10, 100); // 3 días: 30 visualizaciones, 100 seguidores
    seedAnalyticsSnapshots(beta.id, 5, 40); // 3 días: 15 visualizaciones, 40 seguidores
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

test('E2E-04 · Todas las marcas: totales, tabla por marca, gráfica y cambio de periodo', async ({ page }) => {
    const w = watch(page);
    await page.goto('/dashboard');
    await settle(page);
    await page.getByTestId('all-brands-link').click();
    await page.waitForURL(/todas-las-marcas/);
    await settle(page);

    await expect(page.locator('h1')).toHaveText('Todas las marcas');
    await expect(page.getByTestId('all-brands-note')).toContainText('días UTC');
    await expect(page.getByTestId('all-brands-note')).toContainText('no son personas únicas');

    // Suma de las dos marcas; «No disponible» donde ninguna tiene dato (nunca 0).
    await page.getByRole('button', { name: '30 días' }).click();
    await page.waitForURL(/from=/);
    await settle(page);
    await expect(page.locator('[data-card="views"]')).toContainText('45');
    await expect(page.locator('[data-card="followers"]')).toContainText('140');
    await expect(page.locator('[data-card="reach"]')).toContainText('No disponible');
    await expect(page.locator('[data-card="views"] svg').first()).toBeVisible();

    // Una fila por marca.
    await expect(page.locator(`[data-brand-row="${alfa.id}"] [data-metric="views"]`)).toHaveText('30');
    await expect(page.locator(`[data-brand-row="${beta.id}"] [data-metric="views"]`)).toHaveText('15');
    await expect(page.locator(`[data-brand-row="${beta.id}"] [data-metric="reach"]`)).toHaveText('No disponible');

    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Todas las marcas');

    // Otro periodo: un solo día (el último cerrado) queda fuera de los snapshots (de hace 2 a 4 días) y la suma pasa a «No disponible».
    await page.getByLabel('Desde').fill(await page.getByLabel('Hasta').inputValue());
    await page.waitForURL((u) => u.searchParams.get('from') === u.searchParams.get('to'));
    await settle(page);
    await expect(page.locator('[data-card="views"]')).toContainText('No disponible');
    await expectNoOverflow(page);
    expect(w.errors, 'errores de consola').toEqual([]);
});
