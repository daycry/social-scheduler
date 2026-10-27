import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedHashtagHistory, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Sugerencias de hashtags del compositor (C-04a, E2E-06): salen del histórico propio (sin IA), solo con 2 o más usos y ordenadas por
 * rendimiento; un clic inserta el hashtag en el cursor. Usuarios `e2e-sec-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `ht-${stamp()}-${info.project.name}`);
    seedHashtagHistory(brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

test('E2E-06 · hashtags desde el histórico: ≥ 2 usos, por rendimiento, se insertan en el cursor y el contador los cuenta', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();

    const text = page.locator('#text');
    await text.fill('Hola  Mundo');
    await text.evaluate((el: HTMLTextAreaElement) => {
        el.focus();
        el.setSelectionRange(5, 5); // entre los dos espacios
    });

    // Abre con teclado: Enter y el foco entra en el menú.
    const open = page.getByTestId('hashtags-open');
    await open.focus();
    await page.keyboard.press('Enter');
    const menu = page.getByTestId('hashtags-menu');
    await expect(menu).toBeVisible();
    const chips = page.getByTestId('hashtag-chip');
    await expect(chips).toHaveCount(2);
    // Media = 154: #oferta (135) por encima de #verano (120); #nuevo (1 uso) no sale.
    await expect(chips.nth(0)).toContainText('#oferta');
    await expect(chips.nth(1)).toContainText('#verano');
    await expect(menu).not.toContainText('#nuevo');
    await expect(page.getByTestId('hashtags-max')).toContainText('30');
    await expectNoOverflow(page);

    await settle(page);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(bad, JSON.stringify(bad.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) })))).toEqual([]);

    // El chip se inserta en el cursor (con un solo espacio a cada lado) y el contador lo cuenta.
    await chips.nth(0).click();
    await expect(text).toHaveValue('Hola #oferta Mundo');
    await expect(page.getByTestId('hashtag-count')).toContainText('1 hashtag');
    await chips.nth(1).click();
    await expect(text).toHaveValue(/#oferta.*#verano|#verano.*#oferta/);
    await expect(page.getByTestId('hashtag-count')).toContainText('2 hashtags');

    // Escape cierra y devuelve el foco al botón.
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(open).toBeFocused();
    await expectNoOverflow(page);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-06 · sin histórico se explica y el menú sigue siendo utilizable', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const empty = seedComposerBrand(user.email, `ht-vacia-${stamp()}-${info.project.name}`);
    await page.goto(`/brands/${empty.id}/posts/create`);
    await settle(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();

    await page.getByTestId('hashtags-open').click();
    await expect(page.getByTestId('hashtags-empty')).toContainText('Aún no hay datos suficientes');
    await expect(page.getByTestId('hashtag-chip')).toHaveCount(0);
    await expectNoOverflow(page);
});

test('E2E-06 · se avisa al superar el máximo de hashtags de Instagram', async ({ page }) => {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.locator('#text').fill(Array.from({ length: 31 }, (_, i) => `#t${i}`).join(' '));

    await page.getByTestId('hashtags-open').click();
    await expect(page.getByTestId('hashtags-over')).toContainText('31 de 30');
    await expectNoOverflow(page);
});
