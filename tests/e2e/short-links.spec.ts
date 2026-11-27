import { expect, test } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

/**
 * T-89 · Acortador propio (E2E-28). Se crea un enlace corto desde SmartLinks, se visita tres veces sin seguir la redirección (el destino es
 * un dominio ficticio: no hay red) y la pantalla muestra tres clics agregados. Un enlace desactivado deja de redirigir (410 legible) y no
 * cuenta; al reactivarlo vuelve a redirigir. Los datos llevan la etiqueta `e2e-short-*` y se borran al terminar.
 */
test.describe.configure({ mode: 'serial' });

const DESTINO = 'https://destino.example.test/oferta?utm_source=e2e';

const cleanShortLinks = () =>
    artisan(String.raw`App\Models\ShortLink::withoutUserScope()->where('label', 'like', 'e2e-short-%')->delete();`);

test.describe('Acortador propio', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;
    let label: string;

    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        cleanShortLinks();
        brand = seedComposerBrand(user.email, `short-${stamp()}-${info.project.name}`);
        label = `e2e-short-${stamp()}-${info.project.name}`;
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        try {
            cleanShortLinks();
        } finally {
            cleanComposerBrands(e2eUser(info.project.name).email);
        }
    });

    test('E2E-28 · crea un enlace corto, lo visita 3 veces y ve 3 clics; desactivado no redirige ni cuenta', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto(`/smartlinks?brand_id=${brand.id}`);
        await settle(page);
        const panel = page.getByRole('region', { name: 'Enlaces cortos' });
        await expect(panel.getByText('Todavía no has acortado ningún enlace.')).toBeVisible();
        await page.screenshot({ path: info.outputPath('short-links-inicial.png'), fullPage: true });

        await panel.getByLabel('Dirección de destino').fill('javascript:alert(1)');
        await panel.getByLabel('Nombre (opcional)').fill(label);
        await panel.getByRole('button', { name: 'Acortar' }).click();
        await settle(page);
        await expect(panel.getByText('Escribe una dirección que empiece por http:// o https://.')).toBeVisible();
        await expect(panel.getByRole('listitem')).toHaveCount(0);

        await panel.getByLabel('Dirección de destino').fill(DESTINO);
        await panel.getByRole('button', { name: 'Acortar' }).click();
        await settle(page);

        const item = panel.getByRole('listitem').filter({ hasText: label });
        await expect(item).toBeVisible();
        const shortUrl = (await item.getByRole('link', { name: /\/s\/[A-Z][A-Z0-9]{7}$/ }).textContent()) ?? '';
        expect(shortUrl).toMatch(/\/s\/[A-Z][A-Z0-9]{7}$/);
        await expect(item.getByTestId('short-link-total')).toHaveText('0');

        for (let i = 0; i < 3; i++) {
            const res = await page.request.get(shortUrl, { maxRedirects: 0 });
            expect(res.status()).toBe(302);
            expect(res.headers()['location']).toBe(DESTINO);
        }

        await page.reload();
        await settle(page);
        await expect(item.getByTestId('short-link-total')).toHaveText('3');
        await expect(item.getByTestId('short-link-today')).toHaveText('3');
        await page.screenshot({ path: info.outputPath('short-links-final.png'), fullPage: true });

        // Desactivado: 410 legible, sin redirección y sin contar.
        await item.getByRole('button', { name: /^Desactivar el enlace / }).click();
        await settle(page);
        await expect(item.getByText('Desactivado', { exact: true })).toBeVisible();
        const off = await page.request.get(shortUrl, { maxRedirects: 0 });
        expect(off.status()).toBe(410);
        expect(await off.text()).toContain('Este enlace está desactivado');
        await page.reload();
        await settle(page);
        await expect(item.getByTestId('short-link-total')).toHaveText('3');

        // Reactivado: vuelve a redirigir.
        await item.getByRole('button', { name: /^Reactivar el enlace / }).click();
        await settle(page);
        const on = await page.request.get(shortUrl, { maxRedirects: 0 });
        expect(on.status()).toBe(302);

        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Enlaces cortos');
        expect(w.errors).toEqual([]);
    });
});
