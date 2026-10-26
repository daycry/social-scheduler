import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, cleanSavedTexts, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedSavedTexts, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Textos guardados (C-01a): página de ajustes (E2E-01) y menú del compositor (E2E-02). Usuarios `e2e-sec-*`, marcas `e2e-composer-*`
 * y textos `e2e-*`: no toca datos reales ni publica en redes.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanSavedTexts(user.email);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `st-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    cleanSavedTexts(user.email);
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

const rows = (page: Page) => page.getByTestId('saved-text-row');

async function guardar(page: Page, o: { kind?: string; brand?: string; title: string; body: string }) {
    const form = page.getByTestId('saved-text-form');
    if (o.kind) await form.getByLabel('Tipo').selectOption(o.kind);
    await form.getByLabel('Marca').selectOption(o.brand ?? '');
    await form.getByLabel('Título').fill(o.title);
    await form.getByLabel('Texto', { exact: true }).fill(o.body);
    await form.getByRole('button', { name: /^Guardar (texto|cambios)$/ }).click();
    await settle(page);
    await expect(form.getByLabel('Título')).toHaveValue(''); // el formulario se vacía al guardar
}

test('E2E-01 · página «Textos guardados»: alta, edición, filtro por marca y borrado', async ({ page }) => {
    const w = watch(page);
    page.on('dialog', (d) => d.accept().catch(() => {}));
    await page.goto('/settings/saved-texts');
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Textos guardados' })).toBeVisible();
    await expect(page.getByText('Aún no tienes textos guardados')).toBeVisible();

    // Un fragmento de «Todas las marcas» y un grupo de hashtags de la marca.
    await guardar(page, { title: 'e2e-firma-global', body: 'Gracias por leernos' });
    await guardar(page, { kind: 'hashtags', brand: String(brand.id), title: 'e2e-hashtags-marca', body: '#cafe #barista' });
    await expect(rows(page)).toHaveCount(2);

    // Filtro por marca: «Solo Todas las marcas» oculta el de la marca; la marca muestra ambos (el global sirve a todas).
    await page.getByTestId('saved-text-filter').selectOption('all');
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText('e2e-firma-global');
    await page.getByTestId('saved-text-filter').selectOption(String(brand.id));
    await expect(rows(page)).toHaveCount(2);
    await page.getByTestId('saved-text-filter').selectOption('');

    // Edición (sin recargar la app): el formulario pasa a «Editar texto».
    await rows(page).filter({ hasText: 'e2e-firma-global' }).getByRole('button', { name: /^Editar/ }).click();
    await expect(page.getByRole('heading', { name: 'Editar texto' })).toBeVisible();
    await page.getByTestId('saved-text-form').getByLabel('Título').fill('e2e-firma-editada');
    await page.getByRole('button', { name: 'Guardar cambios' }).click();
    await settle(page);
    await expect(rows(page).filter({ hasText: 'e2e-firma-editada' })).toHaveCount(1);
    await expect(page.getByRole('heading', { name: 'Nuevo texto' })).toBeVisible();

    // Borrado
    await rows(page).filter({ hasText: 'e2e-hashtags-marca' }).getByRole('button', { name: /^Eliminar/ }).click();
    await settle(page);
    await expect(rows(page)).toHaveCount(1);

    await expectNoOverflow(page);
    await axeNow(page, 'Textos guardados');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-02 · menú del compositor: solo los de la marca y los globales; inserta en el cursor', async ({ page }, info) => {
    const w = watch(page);
    seedSavedTexts(e2eUser(info.project.name).email, brand.id, `${stamp()}-${info.project.name}`);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    const text = page.locator('#text');
    await text.fill('Hola  Mundo');
    // Cursor en mitad (entre los dos espacios): el navegador lo conserva aunque el foco pase al menú.
    await text.evaluate((el: HTMLTextAreaElement) => {
        el.focus();
        el.setSelectionRange(5, 5);
    });

    // Operable con teclado: Enter abre y el foco entra en el menú.
    const open = page.getByTestId('saved-texts-open');
    await open.focus();
    await page.keyboard.press('Enter');
    const menu = page.getByTestId('saved-texts-menu');
    await expect(menu).toBeVisible();
    const options = page.getByTestId('saved-text-option');
    await expect(options).toHaveCount(2);
    await expect(menu).toContainText('e2e-hashtags-globales');
    await expect(menu).toContainText('e2e-firma-marca');
    await expect(menu).not.toContainText('e2e-texto-otra-marca');
    await expectNoOverflow(page);
    await axeNow(page, 'menú de textos guardados');

    // Escape cierra y devuelve el foco al botón.
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(open).toBeFocused();

    // Insertar el grupo de hashtags en el cursor y recalcular el contador.
    await open.click();
    await options.filter({ hasText: 'e2e-hashtags-globales' }).click();
    await expect(text).toHaveValue('Hola #uno #dos Mundo');
    await expect(page.getByTestId('hashtag-count')).toContainText('2 hashtags');
    await expectNoOverflow(page);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
