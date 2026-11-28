import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedAnalyticsSnapshots, seedComposerBrand, stamp, watch } from './helpers';

test('Metrics: zero activity retains partial coverage, stale dates and suppressed unequal comparison', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `metric-quality-${stamp()}-${info.project.name}`);
    const period = seedAnalyticsSnapshots(brand.id, 0, 50, 3);
    const errors = watch(page);
    try {
        await loginAs(page, user);
        const query = new URLSearchParams({ brand_id: String(brand.id), from: period.from, to: period.to, compare: 'previous' });
        await page.goto(`/dashboard?${query}`);
        const views = page.locator('[data-card="views"]');
        await expect(views.getByText('Datos parciales', { exact: true }).first()).toBeVisible();
        await expect(views.getByText(/3 de 20 observaciones/)).toBeVisible();
        await expect(views.getByText(/Último día con datos:/).first()).toBeVisible();
        await expect(views.getByText(/Comparación omitida:/).first()).toBeVisible();
        await expectNoOverflow(page);
        await page.goto(`/dashboard/instagram?${query}`);
        await expect(page.locator('[data-metric="views"]').getByText(/3 de 10 observaciones/)).toBeVisible();
        await expect(page.getByText('Visualizaciones por día con datos', { exact: true })).toBeVisible();
        await expect(page.locator('[data-section="kpis"]').getByText('3 de 10 días').first()).toBeVisible();
        await expectNoOverflow(page);
        await page.screenshot({ path: info.outputPath('metrics-quality.png'), fullPage: true });
        expect(errors.errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});
