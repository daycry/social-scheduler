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
    // La barra de progreso de Inertia (NProgress, `role="bar"`) es transitoria y no es de la app: se espera a que desaparezca.
    await expect(page.locator('#nprogress')).toHaveCount(0);
    // El ratón queda donde hizo el último clic y activa el :hover del botón bajo él; el contraste se mide en reposo.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400); // fin de las transiciones de color
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

// Familia con ZWJ: 3 emojis + 2 uniones = 5 puntos de código (PHP `mb_strlen` cuenta 5; `.length` de JS contaría 8).
const FAMILIA = '\u{1F468}‍\u{1F469}‍\u{1F467}';

test('E2E-02 · contadores por red y tope de hashtags', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();

    const contadores = page.getByTestId('network-counters');
    const ig = contadores.getByRole('listitem').filter({ hasText: 'Instagram' });
    const fb = contadores.getByRole('listitem').filter({ hasText: 'Facebook' });

    // «Hola » (5) + familia (5) + « #uno #dos #tres» (16) = 26 puntos de código, 3 hashtags.
    await page.getByLabel('Texto', { exact: true }).fill(`Hola ${FAMILIA} #uno #dos #tres`);
    await expect(ig.getByTestId('counter-chars')).toHaveText('26 / 2.200');
    await expect(ig.getByTestId('counter-hashtags')).toHaveText('3 / 30 #');
    await expect(fb.getByTestId('counter-chars')).toHaveText('26 / 63.206');
    await expect(fb.getByTestId('counter-hashtags')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/composer-contadores-emoji.png' });
    await expectNoOverflow(page);

    // 31 hashtags: el contador cambia de estado con texto y la validación en vivo lo explica.
    await page.getByLabel('Texto', { exact: true }).fill(Array.from({ length: 31 }, (_, i) => `#t${i + 1}`).join(' '));
    await expect(ig.getByTestId('counter-hashtags')).toHaveText('31 / 30 # · supera el máximo');
    await expect(page.getByRole('alert').filter({ hasText: /31 hashtags.*admite como maximo 30/ })).toBeVisible();
    await page.screenshot({ path: 'test-results/composer-error-hashtags.png' });
    await expectNoOverflow(page);

    await axeNow(page, 'compositor con contadores');
    expect(w.errors).toEqual([]);
});

test('E2E-03 · barra del editor: emoji en el cursor, IA y media con teclado', async ({ page }) => {
    const w = watch(page);
    // El asistente IA es opcional en el servidor: se simula su estado para poder probar el botón (sin llamar a ningún proveedor).
    await page.route('**/ai/status', (route) => route.fulfill({ json: { enabled: true, remaining: 5 } }));
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();

    const texto = page.getByLabel('Texto', { exact: true });
    const toolbar = page.getByRole('toolbar', { name: 'Herramientas del editor' });
    const botonEmoji = toolbar.getByRole('button', { name: /Insertar emoji/ });

    // Emoji en la posición del cursor (entre «Hola» y «Mundo»), con buscador y teclado.
    await texto.fill('HolaMundo');
    await texto.evaluate((el: HTMLTextAreaElement) => el.setSelectionRange(4, 4));
    await botonEmoji.focus();
    await page.keyboard.press('Enter');
    const selector = page.getByRole('dialog', { name: 'Selector de emoji' });
    await expect(selector).toBeVisible();
    await expect(page.getByLabel('Buscar emoji')).toBeFocused();
    await page.screenshot({ path: 'test-results/composer-emoji-picker.png' });
    await page.getByLabel('Buscar emoji').fill('fuego');
    await selector.getByRole('button', { name: 'Fuego' }).click();
    await expect(texto).toHaveValue('Hola🔥Mundo');
    await expect(selector).toHaveCount(0);
    await expect(botonEmoji).toBeFocused();

    // Escape cierra el selector y devuelve el foco al botón.
    await page.keyboard.press('Enter');
    await expect(selector).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(selector).toHaveCount(0);
    await expect(botonEmoji).toBeFocused();
    await expect(texto).toHaveValue('Hola🔥Mundo');

    // IA: se abre con el teclado y Escape la cierra devolviendo el foco.
    const ia = toolbar.getByRole('button', { name: 'Sugerir texto' });
    await ia.focus();
    await page.keyboard.press('Enter');
    await expect(ia).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByLabel(/¿De qué trata la publicación/)).toBeVisible();
    await page.getByLabel(/¿De qué trata la publicación/).focus();
    await page.keyboard.press('Escape');
    await expect(ia).toHaveAttribute('aria-expanded', 'false');
    await expect(ia).toBeFocused();

    // Media: el control de subida es alcanzable con el teclado y tiene nombre accesible.
    const media = toolbar.getByLabel('Añadir imagen o vídeo');
    await media.focus();
    await expect(media).toBeFocused();

    await axeNow(page, 'compositor con barra del editor');
    await expectNoOverflow(page);
    expect(w.errors).toEqual([]);
});

test('E2E-03 · confirmación al salir con cambios sin guardar', async ({ page }) => {
    const w = watch(page);
    await open(page);
    const cerrar = page.getByRole('link', { name: 'Cerrar' });
    const dialogo = page.getByRole('alertdialog', { name: '¿Salir sin guardar?' });

    // Sin cambios no se pide nada.
    await cerrar.click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));

    // Con cambios: cancelar mantiene el texto y el foco vuelve a Cerrar.
    await open(page);
    await page.getByLabel('Texto', { exact: true }).fill('e2e borrador sin guardar');
    await cerrar.click();
    await expect(dialogo).toBeVisible();
    await page.screenshot({ path: 'test-results/composer-salir-sin-guardar.png' });
    await expect(page.getByRole('button', { name: 'Seguir editando' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialogo).toHaveCount(0);
    await expect(page).toHaveURL(/posts\/create$/);
    await expect(page.getByLabel('Texto', { exact: true })).toHaveValue('e2e borrador sin guardar');
    await expect(cerrar).toBeFocused();

    // Volver a Cerrar y confirmar sale.
    await cerrar.click();
    await page.getByRole('button', { name: 'Seguir editando' }).click();
    await expect(dialogo).toHaveCount(0);
    await cerrar.click();
    await page.getByRole('button', { name: 'Salir sin guardar' }).click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));

    // Tras guardar el borrador ya no hay cambios pendientes: Cerrar sale sin preguntar.
    await open(page);
    await page.getByLabel('Texto', { exact: true }).fill('e2e borrador guardado');
    await page.getByRole('button', { name: 'Guardar borrador' }).click();
    await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();
    await page.getByRole('link', { name: 'Cerrar' }).click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));

    expect(w.errors).toEqual([]);
});

test('E2E-12 · barrido de accesibilidad y desbordamiento del compositor (crear y editar)', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoOverflow(page);
    await axeNow(page, 'compositor vacío');

    // Con las dos redes activas y un texto largo (sin cuentas sin espacios que fuercen el ancho).
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill(`e2e ${'texto largo '.repeat(40)} #uno #dos`);
    await expectNoOverflow(page);
    await axeNow(page, 'compositor con redes y texto');

    // Editar: se guarda un borrador (solo Facebook: Instagram exige media) y se revisa la página de edición.
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: 'Guardar borrador' }).click();
    await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();
    await settle(page);
    await expectNoOverflow(page);
    await axeNow(page, 'editar publicación');
    expect(w.errors).toEqual([]);
});

test('A11Y-01 · compositor con el selector de emoji, la IA y el diálogo de salida abiertos', async ({ page }) => {
    const w = watch(page);
    await page.route('**/ai/status', (route) => route.fulfill({ json: { enabled: true, remaining: 5 } }));
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e a11y');
    const toolbar = page.getByRole('toolbar', { name: 'Herramientas del editor' });

    await toolbar.getByRole('button', { name: /Insertar emoji/ }).click();
    await expect(page.getByRole('dialog', { name: 'Selector de emoji' })).toBeVisible();
    await axeNow(page, 'selector de emoji abierto');
    await expectNoOverflow(page);
    await page.keyboard.press('Escape');

    await toolbar.getByRole('button', { name: 'Sugerir texto' }).click();
    await expect(page.getByLabel(/¿De qué trata la publicación/)).toBeVisible();
    await axeNow(page, 'asistente de texto abierto');
    await expectNoOverflow(page);
    await page.getByLabel(/¿De qué trata la publicación/).press('Escape');

    await page.getByRole('link', { name: 'Cerrar' }).click();
    await expect(page.getByRole('alertdialog', { name: '¿Salir sin guardar?' })).toBeVisible();
    await axeNow(page, 'diálogo de salida abierto');
    await expectNoOverflow(page);
    expect(w.errors).toEqual([]);
});
