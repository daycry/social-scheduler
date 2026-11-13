import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { e2eUser, loginAs, settle } from './helpers';

/**
 * Barra de progreso de navegación: la de Inertia usa `role="bar"` (rol ARIA inválido, axe `aria-roles` critical) y su plantilla no es
 * configurable, así que se sustituye (`resources/js/Composables/navigationProgress.js`) por un `progressbar` con nombre. Se fuerza una
 * navegación lenta (la petición de Inertia queda retenida) y se audita la página con la barra a la vista.
 */
test('Barra de progreso · en una navegación lenta es visible, tiene rol y nombre válidos y axe no da violaciones', { tag: '@responsive' }, async ({ page }, info) => {
    await loginAs(page, e2eUser(info.project.name));
    await page.goto('/dashboard');
    await settle(page);

    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route('**/*', async (route) => {
        if (route.request().headers()['x-inertia'] && route.request().resourceType() !== 'document') await gate;
        await route.continue();
    });

    const menu = page.getByRole('button', { name: 'Abrir menú' });
    if (await menu.isVisible()) await menu.click();
    await page.getByRole('link', { name: 'SmartLinks', exact: true }).click();

    try {
        const bar = page.locator('#nprogress [role="progressbar"]');
        await expect(bar).toHaveCount(1, { timeout: 10_000 });
        await expect(bar).toBeAttached();
        await expect(bar).toHaveAttribute('aria-label', 'Cargando página');
        await expect(page.locator('#nprogress [role="bar"], #nprogress [role="spinner"]')).toHaveCount(0);
        await expect(page.locator('#nprogress .bar')).toBeVisible();

        const results = await new AxeBuilder({ page }).include('#nprogress').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        expect(results.violations, results.violations.map((v) => `${v.id} [${v.impact}] ${v.help}`).join('\n')).toEqual([]);

        const full = await new AxeBuilder({ page }).withRules(['aria-roles', 'aria-allowed-role', 'aria-progressbar-name']).analyze();
        expect(full.violations, full.violations.map((v) => `${v.id} [${v.impact}] ${v.help}`).join('\n')).toEqual([]);
    } finally {
        release();
    }

    await page.waitForURL(/smartlinks/);
    await expect(page.locator('#nprogress')).toHaveCount(0);
});
