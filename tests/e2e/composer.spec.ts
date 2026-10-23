import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Compositor de publicaciones (C-01): pantalla completa, pastillas de red, contadores, barra del editor y salida con cambios.
 * Usa los usuarios `e2e-sec-*` y una marca `e2e-composer-*` con cuentas de prueba: no toca datos reales ni publica en redes.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

async function axeNow(page: Page, label: string) {
    await settle(page);
    await page.mouse.move(0, 0);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

const open = async (page: Page) => {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
};

test('E2E-01 · compositor a pantalla completa con pastillas de red', async ({ page }) => {
    const w = watch(page);
    await open(page);

    // Sin la navegación de la aplicación y con la marca en la cabecera.
    await expect(page.getByRole('navigation', { name: 'Módulos' })).toHaveCount(0);
    await expect(page.getByRole('complementary', { name: 'Navegación del módulo' })).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(brand.name);

    const ig = page.getByRole('button', { name: /^Instagram: e2e Instagram/ });
    const fb = page.getByRole('button', { name: /^Facebook: e2e Facebook/ });
    await expect(ig).toHaveAttribute('aria-pressed', 'false');
    await expect(fb).toHaveAttribute('aria-pressed', 'false');

    // Clic y teclado (Espacio)
    await ig.click();
    await expect(ig).toHaveAttribute('aria-pressed', 'true');
    await fb.focus();
    await page.keyboard.press('Space');
    await expect(fb).toHaveAttribute('aria-pressed', 'true');
    await page.screenshot({ path: 'test-results/composer-dos-redes.png' });
    await expectNoOverflow(page);

    // El tipo elegido se conserva al activar y desactivar otras cuentas.
    const tipo = page.getByLabel(/Tipo de contenido en Instagram/);
    const valores = await tipo.locator('option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value));
    expect(valores.length).toBeGreaterThan(1);
    await tipo.selectOption(valores[1]);
    await fb.click();
    await expect(fb).toHaveAttribute('aria-pressed', 'false');
    await fb.click();
    await expect(page.getByLabel(/Tipo de contenido en Instagram/)).toHaveValue(valores[1]);

    await ig.focus();
    await page.keyboard.press('Space');
    await expect(ig).toHaveAttribute('aria-pressed', 'false');

    await axeNow(page, 'compositor');
    await expectNoOverflow(page);
    expect(w.errors).toEqual([]);
});
