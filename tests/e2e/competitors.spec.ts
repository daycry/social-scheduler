import { expect, test } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedCompetitors, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * T-20 · Competidores: comparativa por rango, ranking por métrica, publicaciones destacadas y CSV. Los datos se siembran por
 * factoría (snapshots y publicaciones ya «recogidos»): ninguna llamada a Instagram.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `comp-${stamp()}-${info.project.name}`);
    seedCompetitors(brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const rivals = (page: import('@playwright/test').Page) => page.getByTestId('competitor-row').filter({ hasText: 'rival_' });
const names = (page: import('@playwright/test').Page) => rivals(page).locator('p.font-medium');

test('E2E-20d · competidores: rango, ranking por métrica, publicaciones destacadas y CSV', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/competitors?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Competidores' })).toBeVisible();
    await expect(page.getByTestId('competitor-row')).toHaveCount(3); // la cuenta propia y dos competidores
    await expect(rivals(page)).toHaveCount(2);
    // Orden por seguidores: el grande primero (posición 1) y el rápido después.
    await expect(names(page).first()).toContainText('rival_grande');
    await expect(rivals(page).first().getByTestId('rank')).toHaveText('1');
    await expect(rivals(page).first().getByTestId('range-growth')).toHaveText('+100');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Competidores (rango)');

    // Ranking por crecimiento %: el rápido (+60 %) pasa al primer puesto.
    await page.getByTestId('range-sort').selectOption('growth_pct');
    await page.getByTestId('range-apply').click();
    await settle(page);
    await expect(names(page).first()).toContainText('rival_rapido');
    await expect(rivals(page).first()).toContainText('+60 %');

    // Un rango incoherente se rechaza con un mensaje (no un 500).
    await page.getByTestId('range-from').fill('2030-01-02');
    await page.getByTestId('range-to').fill('2030-01-01');
    await page.getByTestId('range-apply').click();
    await settle(page);
    await expect(page.getByText('La fecha inicial no puede ser posterior a la final.')).toBeVisible();

    // Publicaciones destacadas del competidor.
    await page.goto(`/competitors?brand_id=${brand.id}&sort=avg_interactions`);
    await settle(page);
    await expect(names(page).first()).toContainText('rival_grande');
    await page.getByTestId('show-posts').first().click();
    await settle(page);
    await expect(page.getByTestId('top-posts')).toContainText('e2e post grande');
    await expect(page.getByTestId('top-post')).toHaveCount(1);
    await expect(page.getByTestId('top-posts')).toContainText('130 interacciones');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Competidores (publicaciones)');

    // CSV: descarga con BOM, cabecera y filas, sin fórmulas sin neutralizar.
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-csv').click()]);
    expect(download.suggestedFilename()).toMatch(/^competidores-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/);
    const fs = await import('node:fs');
    const text = fs.readFileSync((await download.path())!, 'utf8');
    expect(text.charCodeAt(0)).toBe(0xfeff);
    expect(text).toContain('"Cuenta";"Posición"');
    expect(text).toContain('rival_grande');
    expect(text).toContain('rival_rapido');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
