import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp, watch } from './helpers';

test('Ads and reports retain the selected brand across navigation and report creation', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const a = seedComposerBrand(user.email, `A-brand-context-${stamp()}`);
    const z = seedComposerBrand(user.email, `Z-brand-context-${stamp()}`, 'America/Los_Angeles');
    const errors = watch(page);
    try {
        await loginAs(page, user);
        await page.goto(`/ads?brand_id=${a.id}&days=7`);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(a.name);
        await page.getByRole('button', { name: 'Cambiar de marca', exact: true }).click();
        await page.getByRole('button', { name: z.name, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`brand_id=${z.id}(?:&|$)`));
        await expect(page.getByRole('heading', { level: 1 })).toContainText(z.name);
        await expectNoOverflow(page);

        // No explicit query: the current shell/session brand must survive into Reporting.
        await page.goto('/reports');
        await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
        await page.getByLabel('PDF', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/reports\\?brand_id=${z.id}$`));
        await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
        await expect(page.locator('[data-report]').first()).toContainText(z.name);
        await expectNoOverflow(page);
        expect(errors.errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});
