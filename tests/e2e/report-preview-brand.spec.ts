import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, loginAs, seedComposerBrand, stamp } from './helpers';

test('Informe: cambiar de marca cancela una vista previa pendiente', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const suffix = stamp();
    const first = seedComposerBrand(user.email, 'e2e-preview-source-' + suffix);
    const second = seedComposerBrand(user.email, 'e2e-preview-target-' + suffix);
    const previewPath = '**/reports/preview';

    try {
        await loginAs(page, user);
        await page.goto('/reports?brand_id=' + first.id);
        const brand = page.getByLabel('Marca', { exact: true });
        const previewButton = page.getByRole('button', { name: 'Vista previa', exact: true });
        await expect(brand).toHaveValue(String(first.id));

        let markArrival!: () => void;
        const firstArrived = new Promise<void>(resolve => { markArrival = resolve; });
        await page.route(previewPath, async route => {
            if (Number(route.request().postDataJSON()?.brand_id) === first.id) {
                markArrival();
                return;
            }
            await route.continue();
        });
        const aborted = page.waitForEvent('requestfailed', request =>
            new URL(request.url()).pathname === '/reports/preview' && Number(request.postDataJSON()?.brand_id) === first.id,
        );
        await previewButton.click();
        await firstArrived;
        await brand.selectOption(String(second.id));
        expect((await aborted).failure()?.errorText).toBe('net::ERR_ABORTED');
        await expect(page.locator('[data-preview]')).toHaveCount(0);

        await previewButton.click();
        const preview = page.locator('[data-preview]');
        await expect(preview).toContainText(second.name);
        await expect(preview).not.toContainText(first.name);
    } finally {
        await page.unroute(previewPath);
        cleanComposerBrands(user.email);
    }
});
