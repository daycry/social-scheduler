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
 * T-88 · Autolistas desde RSS (E2E-27). La autolista tiene un feed RSS como origen: el servidor E2E sirve el feed desde un host público ficticio
 * (`rss-on`: UrlGuard lo valida y la petición la atiende un proveedor simulado, sin red). Se genera dos veces y la cola no duplica entradas;
 * una dirección privada como feed se rechaza con un mensaje legible. Datos `e2e-composer-*` del usuario E2E.
 */
test.describe.configure({ mode: 'serial' });

const FEED = 'https://feeds.example.test/blog.xml';

test.describe('Autolistas RSS', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;

    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `autolist-rss-${stamp()}-${info.project.name}`);
        artisan(String.raw`file_put_contents(storage_path('rss-on'), '1');`);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        try {
            artisan(String.raw`if (is_file(storage_path('rss-on'))) unlink(storage_path('rss-on'));`);
        } finally {
            cleanComposerBrands(e2eUser(info.project.name).email);
        }
    });

    test('E2E-27 · crea una autolista RSS con un feed local y genera dos veces sin duplicados', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto(`/autolists?brand_id=${brand.id}`);
        await settle(page);
        await expect(page.getByRole('heading', { name: 'Autolistas', level: 1 })).toBeVisible();
        await page.screenshot({ path: info.outputPath('autolist-rss-inicial.png'), fullPage: true });

        await page.getByLabel('Nombre', { exact: true }).fill('Blog E2E');
        await page.getByLabel('Feed RSS o Atom (opcional)').fill(FEED);
        await page.getByRole('checkbox', { name: 'e2e Facebook' }).check();
        await page.getByRole('button', { name: 'Crear autolista' }).click();
        await settle(page);

        const list = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Blog E2E', level: 2 }) });
        await expect(list).toBeVisible();
        await expect(list).toContainText(`Feed: ${FEED}`);
        await expect(list.getByRole('listitem')).toHaveCount(0);

        // Primera generación: las tres entradas del feed entran en la cola, de la más antigua a la más reciente.
        await list.getByRole('button', { name: 'Generar ahora' }).click();
        await settle(page);
        await expect(list.getByRole('listitem')).toHaveCount(3);
        await expect(list.getByRole('listitem').nth(0)).toContainText('Primera entrada E2E');
        await expect(list.getByRole('listitem').nth(2)).toContainText('Tercera entrada E2E');
        await expect(list).not.toContainText('Último sondeo con error');
        await expect(list).not.toContainText('último sondeo: nunca');

        // Segunda generación: ninguna entrada se duplica.
        await list.getByRole('button', { name: 'Generar ahora' }).click();
        await settle(page);
        await expect(list.getByRole('listitem')).toHaveCount(3);
        await page.screenshot({ path: info.outputPath('autolist-rss-final.png'), fullPage: true });

        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Autolista RSS');
        expect(w.errors).toEqual([]);
    });

    test('E2E-27 · un feed que apunta a una dirección privada se rechaza con un mensaje legible', async ({ page }) => {
        await page.goto(`/autolists?brand_id=${brand.id}`);
        await settle(page);
        await page.getByLabel('Nombre', { exact: true }).fill('Blog hostil');
        await page.getByLabel('Feed RSS o Atom (opcional)').fill('http://10.0.0.5/feed.xml');
        await page.getByRole('checkbox', { name: 'e2e Facebook' }).check();
        await page.getByRole('button', { name: 'Crear autolista' }).click();
        await settle(page);

        await expect(page.getByText('La URL apunta a una direccion no permitida.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Blog hostil', level: 2 })).toHaveCount(0);
    });
});
