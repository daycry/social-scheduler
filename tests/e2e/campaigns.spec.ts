import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedCampaignPanel, seedComposerBrand, seedOtherBrandCampaign, settle, stamp, watch, type CampaignSeed, type ComposerBrand } from './helpers';

/**
 * Campañas (C-03, E2E-06 y E2E-07): usuarios `e2e-sec-*`, marcas `e2e-composer-*` y campañas `e2e-camp-*`. No publica ni lee nada de una red real:
 * el panel se siembra con snapshots en la BD.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email); // las campañas se van con su marca (cascade)
    brand = seedComposerBrand(user.email, `camp-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

async function axeNow(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ${n.html.slice(0, 200)}`).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

const rows = (page: Page) => page.getByTestId('campaign-row');

test('E2E-06 · gestión de campañas: alta con color y fechas, selector del compositor solo con las de la marca, alta en línea y archivo', async ({ page }, info) => {
    const w = watch(page);
    const user = e2eUser(info.project.name);
    seedOtherBrandCampaign(user.email, `${stamp()}-${info.project.name}`);

    await page.goto(`/campaigns?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: /^Campañas/ })).toBeVisible();
    await expect(page.getByText('Aún no hay campañas en esta marca')).toBeVisible();

    // 1) Crear una campaña con color y fechas.
    const form = page.getByTestId('campaign-form');
    await form.getByLabel('Nombre').fill('e2e-camp-lanzamiento');
    await form.getByLabel('Inicio').fill('2026-11-01');
    await form.getByLabel('Fin').fill('2026-11-30');
    await form.getByTestId('campaign-color').fill('#0f766e');
    await form.getByRole('button', { name: 'Crear campaña' }).click();
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText('e2e-camp-lanzamiento');
    await expect(rows(page).first()).toContainText('2026-11-01 → 2026-11-30');
    await expectNoOverflow(page);
    await axeNow(page, `lista de campañas (${info.project.name})`);
    await page.screenshot({ path: `test-results/campaigns-list-${info.project.name}.png`, fullPage: true });

    // Un fin anterior al inicio se rechaza y no crea nada.
    await form.getByLabel('Nombre').fill('e2e-camp-mala');
    await form.getByLabel('Inicio').fill('2026-12-10');
    await form.getByLabel('Fin').fill('2026-12-01');
    await form.getByRole('button', { name: 'Crear campaña' }).click();
    await settle(page);
    await expect(rows(page)).toHaveCount(1);

    // 2) Compositor: la campaña de la marca se ofrece; la de otra marca no. Alta en línea de otra.
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    const select = page.getByTestId('campaign-select');
    await expect(select).toBeVisible();
    await expect(select.locator('option')).toHaveText(['Sin campaña', 'e2e-camp-lanzamiento']);
    await select.selectOption({ label: 'e2e-camp-lanzamiento' });

    await page.getByTestId('campaign-add').click();
    await page.getByTestId('campaign-new-name').fill('e2e-camp-en-linea');
    await page.getByTestId('campaign-create').click();
    await expect(select.locator('option')).toHaveText(['Sin campaña', 'e2e-camp-en-linea', 'e2e-camp-lanzamiento']);
    await expect(select.locator('option:checked')).toHaveText('e2e-camp-en-linea'); // la nueva queda elegida
    await expectNoOverflow(page);
    await axeNow(page, `selector de campaña (${info.project.name})`);
    await page.screenshot({ path: `test-results/campaigns-composer-${info.project.name}.png`, fullPage: true });

    // 3) Archivar la primera: pasa a «Archivada» y deja de ofrecerse en el compositor.
    await page.goto(`/campaigns?brand_id=${brand.id}`);
    await settle(page);
    await page.getByRole('button', { name: 'Archivar e2e-camp-lanzamiento' }).click();
    await settle(page);
    await expect(rows(page).filter({ hasText: 'e2e-camp-lanzamiento' })).toContainText('Archivada');
    await expect(rows(page).last()).toContainText('e2e-camp-lanzamiento'); // archivadas al final
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    await expect(page.getByTestId('campaign-select').locator('option')).toHaveText(['Sin campaña', 'e2e-camp-en-linea']);

    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-07 · panel de campaña: KPIs de los últimos snapshots, «No disponible» distinto de 0, alcance acumulado y aviso de 30 días', async ({ page }, info) => {
    const w = watch(page);
    const seed: CampaignSeed = seedCampaignPanel(e2eUser(info.project.name).email, brand.id);

    await page.goto(`/campaigns/${seed.campaignId}`);
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: 'e2e-camp-panel' })).toBeVisible();

    const kpi = (k: string) => page.getByTestId(`kpi-${k}`);
    await expect(kpi('posts')).toContainText('1');
    await expect(kpi('views')).toContainText('150'); // 100 + 50
    await expect(kpi('reach')).toContainText('120'); // 80 + 40
    await expect(kpi('reach')).toContainText('Alcance acumulado');
    await expect(kpi('interactions')).toContainText('20'); // Facebook no informa: no suma 0
    await expect(kpi('likes')).toContainText('No disponible');
    await expect(kpi('likes')).not.toHaveText(/\b0\b/);

    const byNetwork = page.getByTestId('campaign-by-network');
    await expect(byNetwork.getByRole('row', { name: /Instagram/ })).toContainText('100');
    await expect(byNetwork.getByRole('row', { name: /Facebook/ })).toContainText('No disponible');
    await expect(page.getByTestId('campaign-frozen-note')).toContainText('30 días');
    await expect(page.getByTestId('campaign-post')).toHaveCount(1);

    await expectNoOverflow(page);
    await axeNow(page, `panel de campaña (${info.project.name})`);
    await page.screenshot({ path: `test-results/campaign-panel-${info.project.name}.png`, fullPage: true });
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
