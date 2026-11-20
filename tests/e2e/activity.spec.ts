import { expect, test } from '@playwright/test';
import {
    artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow,
    loginAs, READONLY, seedComposerBrand, settle, stamp, watch,
} from './helpers';

test('E2E-15 · Actividad: acciones reales, filtros, paginación y aislamiento', { tag: '@responsive' }, async ({ page, request }, info) => {
    const user = e2eUser(info.project.name);
    const errors = watch(page);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `e2e-composer-activity-${stamp()}`);
    const foreignAction = `e2e_foreign_activity_${stamp()}`;
    artisan(String.raw`$u = App\Models\User::where('email', '${READONLY.email}')->firstOrFail();
        app(App\Services\AuditLog::class)->record($u, '${foreignAction}', 'ok', null, null, null, 'web');`);

    try {
        await loginAs(page, user);
        await page.goto('/api-keys');
        const keyName = `e2e-activity-${stamp()}`;
        await page.getByLabel(/Nombre \(p\. ej\./).fill(keyName);
        await page.getByRole('button', { name: 'Solo lectura', exact: true }).click();
        await page.getByRole('button', { name: 'Crear API key', exact: true }).click();
        await expect(page.getByTestId('new-key')).toBeVisible();
        const key = (await page.getByTestId('new-key').textContent())!.trim();
        expect((await request.get(`/api/v1/brands/${brand.id}/posts`, { headers: { Authorization: `Bearer ${key}` } })).status()).toBe(200);
        await page.getByText(keyName, { exact: true }).locator('xpath=ancestor::li').getByRole('button', { name: 'Revocar' }).click();
        await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Revocar API key', exact: true }).click();
        await expect(page.getByText(keyName, { exact: true })).toHaveCount(0);

        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await page.getByRole('banner').getByRole('link', { name: 'Actividad', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Actividad', exact: true })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Acción', exact: true })).toBeVisible();
        const entries = page.getByTestId('activity-entry');
        for (const action of ['api_key.created', 'list_posts', 'api_key.revoked']) {
            await expect(entries.filter({ hasText: action })).toHaveCount(1);
        }
        await expect(page.locator('body')).not.toContainText(foreignAction);
        await expect(page.getByLabel('Acción', { exact: true }).locator('option', { hasText: foreignAction })).toHaveCount(0);
        await expect(page.getByTestId('activity-retention')).toContainText('365 días');
        const history = page.getByRole('region', { name: 'Historial de actividad', exact: true });
        await history.focus();
        await expect(history).toBeFocused();
        if (await history.evaluate((element) => element.scrollWidth > element.clientWidth)) {
            const before = await history.evaluate((element) => element.scrollLeft);
            await page.keyboard.press('ArrowRight');
            await expect.poll(() => history.evaluate((element) => element.scrollLeft)).toBeGreaterThan(before);
            await history.evaluate((element) => { element.scrollLeft = 0; });
        }
        await page.screenshot({ path: info.outputPath('activity-initial.png'), fullPage: true });
        await axeSeriousCritical(page, 'Actividad');
        await expectNoOverflow(page);

        await page.getByLabel('Marca', { exact: true }).selectOption(String(brand.id));
        await page.getByLabel('Usuario', { exact: true }).selectOption({ label: user.name });
        await page.getByLabel('Acción', { exact: true }).selectOption('list_posts');
        await page.getByRole('button', { name: 'Filtrar actividad', exact: true }).click();
        await expect(entries).toHaveCount(1);
        await expect(entries).toContainText(['list_posts']);
        await expect(entries).toContainText(brand.name);
        await expect(page.getByTestId('activity-retention')).toContainText('Europe/Madrid');
        await page.screenshot({ path: info.outputPath('activity-filtered.png'), fullPage: true });
        await axeSeriousCritical(page, 'Actividad filtrada');
        await expectNoOverflow(page);

        // A real forward cursor navigates to the older audited action.
        await page.goto('/activity?limit=1');
        await expect(entries).toHaveCount(1);
        await expect(entries).toContainText('api_key.revoked');
        await page.getByRole('link', { name: 'Siguiente', exact: true }).click();
        await expect(entries).toContainText('list_posts');
        await page.getByRole('link', { name: 'Primera página', exact: true }).click();
        await expect(entries).toContainText('api_key.revoked');
        await settle(page);
        expect(errors.errors).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});
