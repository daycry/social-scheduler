import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, cleanInstagramGridCache, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedInstagramGrid, settle, stamp, watch, type ComposerBrand, type GridSeed } from './helpers';

/**
 * Cuadrícula de Instagram (C-02, E2E-05): usuarios `e2e-sec-*` y marcas `e2e-composer-*`. Lo publicado se siembra en la caché
 * (Graph no se llama nunca) y lo programado en la BD; no publica ni lee nada de una red real.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
let seed: GridSeed;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanInstagramGridCache(user.email);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `ig-${stamp()}-${info.project.name}`);
    seed = seedInstagramGrid(user.email, brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    const user = e2eUser(info.project.name);
    cleanInstagramGridCache(user.email);
    cleanComposerBrands(user.email);
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

test('E2E-05 · programadas arriba (la más tardía primero), publicadas debajo, sin la Story, sin desbordar y con axe limpio', async ({ page }, info) => {
    const w = watch(page);
    // Las miniaturas de lo programado salen de medios sembrados que no existen en disco: se sirven con un PNG de 1x1.
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==', 'base64');
    await page.route(/media\/test\//, (route) => route.fulfill({ status: 200, contentType: 'image/png', body: png }));
    await page.goto(`/instagram/grid?brand_id=${brand.id}`);
    await settle(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Cuadrícula de Instagram' })).toBeVisible();
    const tiles = page.getByTestId('grid-tile');
    // 2 programadas + 3 publicadas; la Story programada no entra (CA-09).
    await expect(tiles).toHaveCount(5);
    await expect(tiles.nth(0)).toHaveAttribute('data-kind', 'scheduled');
    await expect(tiles.nth(1)).toHaveAttribute('data-kind', 'scheduled');
    for (const i of [2, 3, 4]) await expect(tiles.nth(i)).toHaveAttribute('data-kind', 'published');

    // Orden: la más tardía primero, con enlace al compositor; las publicadas llevan enlace a Instagram.
    await expect(tiles.nth(0).locator('a')).toHaveAttribute('href', new RegExp(`/posts/${seed.later}/edit$`));
    await expect(tiles.nth(1).locator('a')).toHaveAttribute('href', new RegExp(`/posts/${seed.earlier}/edit$`));
    await expect(page.locator(`a[href$="/posts/${seed.story}/edit"]`)).toHaveCount(0);
    await expect(tiles.nth(2).locator('a')).toHaveAttribute('href', 'https://www.instagram.com/p/e2eP1/');
    await expect(tiles.nth(2).locator('a')).toHaveAttribute('rel', /noopener/);
    await expect(page.getByTestId('grid-pinned-note')).toContainText('fijados');

    // Tres columnas: las tres primeras teselas comparten fila y la cuarta baja a la siguiente.
    const ys = await Promise.all([0, 1, 2, 3].map((i) => tiles.nth(i).evaluate((el) => Math.round(el.getBoundingClientRect().top))));
    expect(ys[0]).toBe(ys[1]);
    expect(ys[1]).toBe(ys[2]);
    expect(ys[3]).toBeGreaterThan(ys[0]);

    await expectNoOverflow(page);
    await axeNow(page, `cuadrícula (${info.project.name})`);
    await page.screenshot({ path: `test-results/instagram-grid-${info.project.name}.png`, fullPage: true });
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
