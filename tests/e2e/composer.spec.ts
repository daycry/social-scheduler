import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedComposerPosts, seedExtraAccount, seedExtraFacebook, seedYoutubeDraft, setBrandTimezone, settle, stamp, watch, type ComposerBrand } from './helpers';

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
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ${n.html.slice(0, 200)} ${n.any.map((a) => a.message).join('; ')}`).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

const open = async (page: Page) => {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
};

/** Botón dividido de la barra de acciones: el principal programa; el menú trae borrador, biblioteca y Publicar ahora. */
const menuToggle = (page: Page) => page.getByRole('button', { name: 'Más acciones de publicación' });
const menuItem = (page: Page, name: string) => page.getByRole('menuitem', { name });
async function abrirMenu(page: Page) {
    if ((await menuToggle(page).getAttribute('aria-expanded')) !== 'true') await menuToggle(page).click();
    await expect(page.getByRole('menu')).toBeVisible();
}
async function guardarBorrador(page: Page) {
    await abrirMenu(page);
    await menuItem(page, 'Guardar borrador').click();
}

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
    await expect(page.getByTestId('errors-panel').getByText(/31 hashtags.*admite como maximo 30/)).toBeVisible();
    // Aserción aplazada de la Fase 1: con 31 hashtags Programar queda deshabilitado y Guardar borrador sigue habilitado.
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    await abrirMenu(page);
    await expect(menuItem(page, 'Guardar borrador')).toBeEnabled();
    await expect(menuItem(page, 'Publicar ahora')).toBeDisabled();
    await page.keyboard.press('Escape');
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
    const chooser = page.waitForEvent('filechooser');
    await page.keyboard.press('Space'); // el teclado activa el control: se abre el selector de archivos
    expect(await chooser).toBeTruthy();

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
    await guardarBorrador(page);
    await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();
    await page.getByRole('link', { name: 'Cerrar' }).click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));

    expect(w.errors).toEqual([]);
});

test('E2E-02 · el contador cuenta sobre el texto recortado y avisa al superar el máximo de caracteres', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    const ig = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'Instagram' });
    const texto = page.getByLabel('Texto', { exact: true });

    // El servidor recorta los espacios de los extremos: «  hola  » son 4 caracteres.
    await texto.fill('   hola   ');
    await expect(ig.getByTestId('counter-chars')).toHaveText('4 / 2.200');
    // `a#b` y el # de una URL no son hashtags; los demás sí.
    await texto.fill('a#b https://x.com/p#ancla #uno');
    await expect(ig.getByTestId('counter-hashtags')).toHaveText('1 / 30 #');

    // Superar el máximo de caracteres: el contador cambia con texto y la validación en vivo lo explica.
    await texto.fill('a'.repeat(2201));
    await expect(ig.getByTestId('counter-chars')).toHaveText('2.201 / 2.200 · supera el máximo');
    await expect(page.getByTestId('errors-panel').getByText(/2201 caracteres.*admite como maximo 2200/)).toBeVisible();
    await expect(ig.getByTestId('counter-chars')).toHaveAttribute('aria-live', 'polite');
    await expectNoOverflow(page);
    expect(w.errors).toEqual([]);
});

test('E2E-03 · el emoji se inserta en el cursor de un texto con varias líneas', async ({ page }) => {
    const w = watch(page);
    await open(page);
    const texto = page.getByLabel('Texto', { exact: true });
    await texto.fill('l1\nl2\nl3');
    await texto.evaluate((el: HTMLTextAreaElement) => el.setSelectionRange(5, 5)); // fin de «l2»
    await page.getByRole('toolbar', { name: 'Herramientas del editor' }).getByRole('button', { name: /Insertar emoji/ }).click();
    await page.getByLabel('Buscar emoji').fill('fuego');
    await page.getByRole('dialog', { name: 'Selector de emoji' }).getByRole('button', { name: 'Fuego' }).click();
    await expect(texto).toHaveValue('l1\nl2🔥\nl3');
    expect(w.errors).toEqual([]);
});

test('E2E-03 · el botón Atrás del navegador pide confirmación con cambios sin guardar', async ({ page }) => {
    const w = watch(page);
    // Navegación de Inertia (sin recarga) para que Atrás sea un `popstate`.
    await page.goto(`/brands/${brand.id}`);
    await settle(page);
    await page.getByRole('link', { name: 'Nueva publicación' }).first().click();
    await expect(page).toHaveURL(/posts\/create$/);
    await settle(page);
    const texto = page.getByLabel('Texto', { exact: true });
    const dialogo = page.getByRole('alertdialog', { name: '¿Salir sin guardar?' });

    // Sin cambios, Atrás sale sin preguntar.
    await page.goBack();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));
    await settle(page);
    await page.getByRole('link', { name: 'Nueva publicación' }).first().click();
    await expect(page).toHaveURL(/posts\/create$/);
    await settle(page);

    // Con cambios: Atrás pregunta, cancelar conserva URL y texto.
    await texto.fill('e2e texto que no debe perderse');
    await page.goBack();
    await expect(dialogo).toBeVisible();
    await expect(page).toHaveURL(/posts\/create$/);
    await page.getByRole('button', { name: 'Seguir editando' }).click();
    await expect(dialogo).toHaveCount(0);
    await expect(page).toHaveURL(/posts\/create$/);
    await expect(texto).toHaveValue('e2e texto que no debe perderse');

    // Un segundo Atrás vuelve a preguntar; confirmar sale.
    await page.goBack();
    await expect(dialogo).toBeVisible();
    await page.getByRole('button', { name: 'Salir sin guardar' }).click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}$`));
    expect(w.errors).toEqual([]);
});

const pad = (n: number) => String(n).padStart(2, '0');

test('E2E-04 · panel único de errores, botón dividido con teclado y selector de fecha con la zona de la marca', async ({ page }) => {
    const w = watch(page);
    // La marca está en Pacific/Auckland y el navegador en Europe/Madrid. Con el reloj fijado a las 20:00 UTC, en Madrid es el día D
    // y en Auckland (UTC+12/13) ya es D+1: si el selector usara la hora local del navegador, «hoy» sería otro día y el test fallaría.
    const ZONA = 'Pacific/Auckland';
    setBrandTimezone(brand.id, ZONA);
    const base = new Date();
    base.setUTCHours(20, 0, 0, 0);
    await page.clock.install({ time: base });
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();

    // Texto por encima del máximo de Facebook (63.206): hay errores en los dos destinos (Instagram exige además media).
    await page.getByLabel('Texto', { exact: true }).fill('x'.repeat(63207));
    const panel = page.getByTestId('errors-panel');
    const items = panel.getByTestId('error-item');
    await expect(panel.getByText(/Facebook · e2e Facebook/).first()).toBeVisible();
    await expect(panel.getByText(/Instagram · e2e Instagram/).first()).toBeVisible();
    expect(await items.count()).toBeGreaterThanOrEqual(2);
    await expect(panel).toHaveAttribute('aria-live', 'polite');
    await page.screenshot({ path: 'test-results/composer-panel-errores.png' });
    await expectNoOverflow(page);

    // El enlace de cada error lleva al control afectado (la pastilla de la cuenta).
    await items.filter({ hasText: 'Instagram' }).first().getByRole('button', { name: /Ir al control/ }).click();
    await expect(page.getByRole('button', { name: /^Instagram: e2e Instagram/ })).toBeFocused();

    // Con errores: Programar y Publicar ahora deshabilitados; Guardar borrador y Guardar en biblioteca, habilitados.
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    await menuToggle(page).focus();
    await expect(menuToggle(page)).toHaveAttribute('aria-haspopup', 'menu');
    await page.keyboard.press('ArrowDown');
    await expect(menuItem(page, 'Guardar borrador')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menuItem(page, 'Guardar en biblioteca')).toBeFocused();
    await page.keyboard.press('ArrowDown'); // Publicar ahora está deshabilitado: las flechas dan la vuelta
    await expect(menuItem(page, 'Guardar borrador')).toBeFocused();
    await expect(menuItem(page, 'Publicar ahora')).toBeDisabled();
    await expect(menuItem(page, 'Guardar en biblioteca')).toBeEnabled();
    await axeNow(page, 'menú del botón dividido abierto');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(menuToggle(page)).toBeFocused();

    // Se corrige: solo Facebook y un texto válido. El panel desaparece y Programar se habilita.
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e selector de fecha');
    await expect(items).toHaveCount(0);

    // Selector de fecha: zona de la marca, sin días pasados y valor `Y-m-d\TH:i`.
    const picker = page.locator('#scheduled_at');
    await expect(page.getByTestId('datetime-zone')).toContainText(ZONA);
    await picker.click();
    const dialogo = page.getByRole('dialog', { name: 'Elegir fecha y hora' });
    await expect(dialogo).toBeVisible();
    const hoy = base.toLocaleDateString('en-CA', { timeZone: ZONA });
    const hoyLocal = base.toLocaleDateString('en-CA', { timeZone: 'Europe/Madrid' });
    expect(hoy).not.toBe(hoyLocal); // la prueba solo vale si la zona de la marca y la del navegador caen en días distintos
    const [y, m, d] = hoy.split('-').map(Number);
    await expect(dialogo.locator(`[data-date="${hoyLocal}"]`)).toBeDisabled(); // «hoy» de Madrid ya es pasado en Auckland
    await expect(dialogo.locator(`[data-date="${hoy}"]`)).toBeEnabled();
    await expect(dialogo.locator(`[data-date="${hoy}"]`)).toBeFocused();
    if (d > 1) await expect(dialogo.locator(`[data-date="${y}-${pad(m)}-${pad(d - 1)}"]`)).toBeDisabled();
    await page.keyboard.press('ArrowLeft'); // con el teclado tampoco se llega a un día pasado
    await expect(dialogo.locator(`[data-date="${hoy}"]`)).toBeFocused();
    await page.screenshot({ path: 'test-results/composer-selector-fecha.png' });
    await axeNow(page, 'selector de fecha abierto');
    await expectNoOverflow(page);

    // Elegir una fecha pasada: se escoge la próxima franja de hoy y se deja pasar el tiempo (el reloj avanza 10 minutos). El panel
    // muestra el error y Programar se deshabilita; Guardar borrador y Publicar ahora siguen disponibles (el servidor revalida).
    await dialogo.locator(`[data-date="${hoy}"]`).click();
    await expect(picker).toHaveAttribute('data-value', /T09:05$/);
    await expect(items).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Programar' })).toBeEnabled();
    await page.clock.fastForward('10:00');
    await expect(items).toHaveCount(1);
    await expect(panel.getByText(/debe ser futura/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    await page.keyboard.press('Escape'); // cierra el selector (tapa el botón dividido)
    await expect(dialogo).toHaveCount(0);
    await abrirMenu(page);
    await expect(menuItem(page, 'Guardar borrador')).toBeEnabled();
    await expect(menuItem(page, 'Publicar ahora')).toBeEnabled();
    await page.keyboard.press('Escape');
    await page.screenshot({ path: 'test-results/composer-fecha-pasada.png' });
    await axeNow(page, 'compositor con el error de fecha pasada');
    await picker.click();
    await expect(dialogo).toBeVisible();

    const sigMes = m === 12 ? `${y + 1}-01` : `${y}-${pad(m + 1)}`;
    const dia = `${sigMes}-15`;
    await dialogo.getByRole('button', { name: 'Mes siguiente' }).click();
    await dialogo.locator(`[data-date="${dia}"]`).click();
    await expect(picker).toHaveAttribute('data-value', `${dia}T09:05`); // conserva la hora ya elegida
    await dialogo.getByLabel('Hora').selectOption('14');
    await dialogo.getByLabel('Minutos').selectOption('30');
    await expect(picker).toHaveAttribute('data-value', `${dia}T14:30`);
    await expect(picker).toContainText('14:30');
    await dialogo.getByLabel('Minutos').focus();
    await page.keyboard.press('Escape'); // Escape cierra y devuelve el foco al botón
    await expect(dialogo).toHaveCount(0);
    await expect(picker).toBeFocused();
    await expect(items).toHaveCount(0); // con una fecha futura el error desaparece y Programar vuelve a estar habilitado

    // El formato que se envía no cambia: `Y-m-d\TH:i` en la hora de la marca.
    const peticion = page.waitForRequest((r) => r.method() === 'POST' && /\/brands\/\d+\/posts$/.test(new URL(r.url()).pathname));
    await page.getByRole('button', { name: 'Programar' }).click();
    const body = (await peticion).postDataJSON();
    expect(body.scheduled_at).toBe(`${dia}T14:30`);
    expect(body.schedule).toBe(true);
    await expect(page).toHaveURL(/\/calendar/);
    expect(w.errors).toEqual([]);
});

test('E2E-05 · Publicar ahora desde el compositor: «Enviando…», una sola petición y sin duplicar', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e publicar ahora');

    // Se cuentan las peticiones de guardado (la validación en vivo es otra ruta) y se retrasa la respuesta para ver el estado.
    let guardados = 0;
    await page.route(/\/brands\/\d+\/posts$/, async (route) => {
        if (route.request().method() === 'POST') {
            guardados++;
            await new Promise((r) => setTimeout(r, 1500));
        }
        await route.continue();
    });
    await abrirMenu(page);
    await expect(menuItem(page, 'Publicar ahora')).toBeEnabled();
    await menuItem(page, 'Publicar ahora').click();

    await expect(page.getByTestId('sending-now')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enviando…' })).toBeDisabled();
    await expect(menuToggle(page)).toBeDisabled();
    await page.screenshot({ path: 'test-results/composer-enviando.png' });
    // Un segundo envío inmediato (Enter en el formulario, doble clic...) no genera otra petición.
    await page.evaluate(() => document.querySelector('form')?.requestSubmit());
    await expect(page).toHaveURL(/\/calendar/);
    expect(guardados).toBe(1);

    // En el calendario el destino queda programada o publicando, una sola vez (nunca duplicado).
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    const filas = page.getByRole('row').filter({ hasText: 'e2e publicar ahora' });
    await expect(filas).toHaveCount(1);
    await expect(filas.first()).toContainText(/Programada|Publicando|Procesando|Publicada|Con error/);
    expect(w.errors).toEqual([]);
});

test('E2E-06 · Publicar ahora desde el calendario y la lista: confirmación accesible y solo en lo que se puede publicar', async ({ page }, info) => {
    const w = watch(page);
    const posts = seedComposerPosts(e2eUser(info.project.name).email, brand.id);
    const boton = (id: number) => page.getByRole('button', { name: `Publicar ahora la publicación ${id}` });
    const dialogo = page.getByRole('alertdialog', { name: '¿Publicar ahora?' });

    // Cancelar con el botón y con Escape (foco de vuelta al botón) y axe con la confirmación abierta, en la vista indicada.
    const cancelarConfirmacion = async (vista: string) => {
        await boton(posts.scheduled).click();
        await expect(dialogo).toBeVisible();
        await axeNow(page, `calendario (${vista}) con la confirmación de Publicar ahora abierta`);
        await dialogo.getByRole('button', { name: 'Cancelar' }).click();
        await expect(dialogo).toHaveCount(0);
        await expect(boton(posts.scheduled)).toBeFocused();
        await boton(posts.scheduled).click();
        await expect(dialogo).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialogo).toHaveCount(0);
        await expect(boton(posts.scheduled)).toBeFocused();
        await expect(page.getByText('Enviando…')).toHaveCount(0);
    };

    // LISTA: borrador y programada ofrecen la acción; la publicada no.
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    await settle(page);
    await expect(boton(posts.draft)).toBeVisible();
    await expect(boton(posts.scheduled)).toBeVisible();
    await expect(boton(posts.published)).toHaveCount(0);
    const fila = page.getByRole('row').filter({ hasText: 'e2e borrador de calendario' });

    // Cancelar (botón y Escape) no cambia nada y devuelve el foco.
    await boton(posts.draft).click();
    await expect(dialogo).toBeVisible();
    await expect(dialogo.getByRole('button', { name: 'Cancelar' })).toBeFocused();
    await page.screenshot({ path: 'test-results/calendario-confirmar-publicar-ahora.png' });
    await expectNoOverflow(page);
    await axeNow(page, 'calendario con la confirmación de Publicar ahora abierta');
    await page.keyboard.press('Escape');
    await expect(dialogo).toHaveCount(0);
    await expect(boton(posts.draft)).toBeFocused();
    await boton(posts.draft).click();
    await dialogo.getByRole('button', { name: 'Cancelar' }).click();
    await expect(dialogo).toHaveCount(0);
    await expect(fila).toContainText('Borrador');
    await expect(fila.getByText('Enviando…')).toHaveCount(0);

    // Confirmar envía la acción y la fila muestra «Enviando…».
    await boton(posts.draft).click();
    await dialogo.getByRole('button', { name: 'Publicar ahora' }).click();
    await expect(fila.getByText('Enviando…')).toBeVisible();
    await expect(boton(posts.draft)).toHaveCount(0);
    await expectNoOverflow(page);

    // SEMANA y MES: la programada ofrece la acción, la publicada no; confirmar muestra «Enviando…» en la tarjeta.
    await page.goto(`/calendar?view=month&brand_id=${brand.id}&date=${posts.scheduledDate}`);
    await settle(page);
    await expect(boton(posts.scheduled)).toBeVisible();
    await expect(boton(posts.published)).toHaveCount(0);
    await expectNoOverflow(page);
    await cancelarConfirmacion('mes');

    await page.goto(`/calendar?view=week&brand_id=${brand.id}&date=${posts.scheduledDate}`);
    await settle(page);
    await expect(boton(posts.published)).toHaveCount(0);
    await cancelarConfirmacion('semana');
    await boton(posts.scheduled).click();
    await expect(dialogo).toBeVisible();
    await expectNoOverflow(page);
    await dialogo.getByRole('button', { name: 'Publicar ahora' }).click();
    await expect(page.locator('article').filter({ hasText: 'e2e programada de calendario' }).getByText('Enviando…')).toBeVisible();
    expect(w.errors).toEqual([]);
});

test('E2E-07 · Guardar en biblioteca sin guardar el post: crea la plantilla y no crea ninguna publicación', async ({ page }) => {
    const w = watch(page);
    page.on('dialog', (d) => d.accept().catch(() => {})); // beforeunload al salir con el formulario sin guardar
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e texto de plantilla sin guardar');

    await abrirMenu(page);
    await menuItem(page, 'Guardar en biblioteca').click();
    const dialogo = page.getByRole('dialog', { name: 'Guardar en la biblioteca' });
    await expect(dialogo).toBeVisible();
    await expect(dialogo.getByLabel('Nombre de la plantilla')).toBeFocused();
    await page.screenshot({ path: 'test-results/composer-guardar-biblioteca.png' });
    await expectNoOverflow(page);
    await dialogo.getByLabel('Nombre de la plantilla').fill('e2e plantilla del compositor');
    await dialogo.getByRole('button', { name: 'Guardar', exact: true }).click();

    // Sigue en el compositor (sin guardar), con el aviso, y no hay ningún post.
    await expect(dialogo).toHaveCount(0);
    await expect(page.getByText('Guardado en la biblioteca.')).toBeVisible();
    await expect(page).toHaveURL(/posts\/create$/);
    await expect(page.getByLabel('Texto', { exact: true })).toHaveValue('e2e texto de plantilla sin guardar');

    await page.goto(`/library?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByText('e2e texto de plantilla sin guardar').first()).toBeVisible();
    await expect(page.getByText('e2e plantilla del compositor').first()).toBeVisible();
    // La plantilla conserva los destinos: las dos redes elegidas.
    const plantilla = page.locator('li').filter({ hasText: 'e2e plantilla del compositor' });
    await expect(plantilla.getByText('Instagram', { exact: true })).toBeVisible();
    await expect(plantilla.getByText('Facebook', { exact: true })).toBeVisible();
    await page.screenshot({ path: 'test-results/biblioteca-plantilla.png' });
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByText('No hay publicaciones en este periodo')).toBeVisible();
    expect(w.errors).toEqual([]);
});

test('E2E-04 · respaldo nativo del selector de fecha (datetime-local con min en la zona de la marca) y fecha pasada', async ({ page }) => {
    const w = watch(page);
    setBrandTimezone(brand.id, 'Pacific/Auckland');
    const base = new Date();
    base.setUTCHours(20, 0, 0, 0);
    await page.clock.install({ time: base });
    await page.goto(`/brands/${brand.id}/posts/create?selector=nativo`);
    await settle(page);
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e selector nativo');

    const campo = page.locator('input#scheduled_at[type="datetime-local"]');
    await expect(campo).toBeVisible();
    await expect(page.locator('[data-testid="datetime-picker"]')).toHaveCount(0);
    // El mínimo es «hoy» en la zona de la marca, no el del navegador.
    const hoy = base.toLocaleDateString('en-CA', { timeZone: 'Pacific/Auckland' });
    await expect(campo).toHaveAttribute('min', new RegExp(`^${hoy}T`));

    await campo.fill(`${hoy}T00:00`); // ya pasado en Auckland (son las 09:00)
    await expect(page.getByTestId('errors-panel').getByText(/debe ser futura/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();

    await campo.fill('2030-01-15T10:30');
    await expect(page.getByTestId('error-item')).toHaveCount(0);
    const peticion = page.waitForRequest((r) => r.method() === 'POST' && /\/brands\/\d+\/posts$/.test(new URL(r.url()).pathname));
    await page.getByRole('button', { name: 'Programar' }).click();
    expect((await peticion).postDataJSON().scheduled_at).toBe('2030-01-15T10:30');
    await expect(page).toHaveURL(/\/calendar/);
    expect(w.errors).toEqual([]);
});

test('E2E-04 · si en la zona de la marca no queda ninguna franja hoy, el día de hoy no se puede elegir', async ({ page }) => {
    const w = watch(page);
    setBrandTimezone(brand.id, 'Pacific/Auckland');
    const base = new Date();
    base.setUTCHours(10, 58, 0, 0); // 23:58 en Auckland (UTC+13 en octubre)
    await page.clock.install({ time: base });
    await open(page);
    await page.locator('#scheduled_at').click();
    const dialogo = page.getByRole('dialog', { name: 'Elegir fecha y hora' });
    const hoy = base.toLocaleDateString('en-CA', { timeZone: 'Pacific/Auckland' });
    const manana = new Date(base.getTime() + 24 * 3600 * 1000).toLocaleDateString('en-CA', { timeZone: 'Pacific/Auckland' });
    await expect(page.getByTestId('datetime-zone')).toContainText('Pacific/Auckland');
    if (hoy.slice(0, 7) === manana.slice(0, 7)) {
        await expect(dialogo.locator(`[data-date="${hoy}"]`)).toBeDisabled();
        await expect(dialogo.locator(`[data-date="${manana}"]`)).toBeEnabled();
        await expect(dialogo.locator(`[data-date="${manana}"]`)).toBeFocused();
    }
    expect(w.errors).toEqual([]);
});

test('E2E-05 · con una subida de media en curso se deshabilitan Programar, Guardar borrador, Publicar ahora y Guardar en biblioteca', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e subida en curso');
    // La subida tarda: el registro del asset responde con retraso y con un error (no hay almacenamiento en la prueba).
    await page.route('**/media', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        await new Promise((r) => setTimeout(r, 2500));
        await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'e2e: subida simulada' }) });
    });
    await page.locator('input[type="file"]').setInputFiles({ name: 'e2e.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgo=', 'base64') });
    await expect(page.getByText(/Subiendo…/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    await abrirMenu(page);
    await expect(menuItem(page, 'Publicar ahora')).toBeDisabled();
    await expect(menuItem(page, 'Guardar en biblioteca')).toBeDisabled();
    // Guardar borrador tampoco guardaria nada con la subida a medias (revision F2 i2, N3): se deshabilita y se avisa.
    await expect(menuItem(page, 'Guardar borrador')).toBeDisabled();
    await expect(page.getByTestId('uploading-note')).toHaveText('Espera a que termine la subida.');
    await page.keyboard.press('Escape');
    // Al terminar (aquí con error) todo vuelve a estar disponible.
    await expect(page.getByText('e2e: subida simulada')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programar' })).toBeEnabled();
    await abrirMenu(page);
    await expect(menuItem(page, 'Publicar ahora')).toBeEnabled();
    await expect(menuItem(page, 'Guardar en biblioteca')).toBeEnabled();
    expect(w.errors.filter((e) => !/422/.test(e))).toEqual([]);
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
    await guardarBorrador(page);
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

// ------------------------------------------------------------------------------------------------------------------
// C-03 · Texto por red («Editar por red»)
// ------------------------------------------------------------------------------------------------------------------

const tabOf = (page: Page, name: string | RegExp) => page.getByRole('tab', { name });
const chars = (s: string) => [...s].length;
const miles = (n: number) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

test('E2E-08 · texto común por defecto y mezcla de redes: una personalizada y otra vinculada, contadores y teclado', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    const comun = 'Texto común e2e';
    await page.getByLabel('Texto', { exact: true }).fill(comun);

    // Por defecto no se toca nada: sin pestañas y todas las redes publican el texto común.
    await expect(page.getByRole('tablist')).toHaveCount(0);
    await expect(page.getByText('Todas las redes publican el mismo texto.')).toBeVisible();
    const lista = page.getByTestId('network-counters');
    const ig = lista.getByRole('listitem').filter({ hasText: 'Instagram' });
    const fb = lista.getByRole('listitem').filter({ hasText: 'Facebook' });
    await expect(ig.getByTestId('counter-chars')).toHaveText(`${chars(comun)} / 2.200`);
    await expect(fb.getByTestId('counter-chars')).toHaveText(`${chars(comun)} / 63.206`);

    // «Editar por red»: una pestaña para el texto común y una por red.
    const editar = page.getByRole('button', { name: 'Editar por red' });
    await editar.click();
    await expect(editar).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('tab')).toHaveCount(3);
    await expect(tabOf(page, 'Texto común')).toHaveAttribute('aria-selected', 'true');

    // Facebook empieza vinculada y muestra el texto común.
    await tabOf(page, /Facebook · e2e Facebook/).click();
    await expect(page.getByTestId('linked-badge')).toHaveText('Vinculada al texto común');
    await expect(page.getByTestId('linked-text')).toHaveText(comun);

    // CA-08: se personaliza Facebook y su contador usa su texto; el de Instagram sigue con el común.
    await page.getByRole('button', { name: 'Personalizar el texto de esta red' }).click();
    const propio = page.getByLabel('Texto de Facebook · e2e Facebook');
    await expect(propio).toHaveValue(comun); // el texto común es el punto de partida
    await expect(page.getByTestId('own-indicator')).toBeVisible();
    const textoFb = 'Propio de Facebook con ✨ y #etiqueta';
    await propio.fill(textoFb);
    // A1 (revisión de la fase 3): la previsualización muestra el texto efectivo de la pestaña activa, no siempre el común.
    const previa = page.locator('div.card', { has: page.getByRole('heading', { name: 'Previsualización' }) });
    await expect(previa).toContainText(textoFb);
    await expect(fb.getByTestId('counter-chars')).toHaveText(`${chars(textoFb)} / 63.206`);
    await expect(ig.getByTestId('counter-chars')).toHaveText(`${chars(comun)} / 2.200`);
    await expect(fb).toContainText('texto propio');
    await expect(ig).not.toContainText('texto propio');
    await expect(tabOf(page, /Facebook · e2e Facebook/)).toContainText('propio');
    await page.screenshot({ path: 'test-results/composer-texto-por-red.png' });
    await expectNoOverflow(page);

    // Si se edita el texto común, solo cambia el de las redes vinculadas.
    await tabOf(page, 'Texto común').click();
    const editado = 'Texto común EDITADO';
    await page.getByLabel('Texto', { exact: true }).fill(editado);
    await expect(previa).toContainText(editado);
    await expect(previa).not.toContainText(textoFb);
    await expect(ig.getByTestId('counter-chars')).toHaveText(`${chars(editado)} / 2.200`);
    await expect(fb.getByTestId('counter-chars')).toHaveText(`${chars(textoFb)} / 63.206`);
    await tabOf(page, /Instagram · e2e Instagram/).click();
    await expect(page.getByTestId('linked-text')).toHaveText(editado);

    // Teclado: flechas, Inicio y Fin mueven la selección entre pestañas (roving tabindex).
    await tabOf(page, 'Texto común').focus();
    await page.keyboard.press('ArrowRight');
    await expect(tabOf(page, /Instagram · e2e Instagram/)).toHaveAttribute('aria-selected', 'true');
    await expect(tabOf(page, /Instagram · e2e Instagram/)).toBeFocused();
    await page.keyboard.press('End');
    await expect(tabOf(page, /Facebook · e2e Facebook/)).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('ArrowRight'); // da la vuelta
    await expect(tabOf(page, 'Texto común')).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('ArrowLeft');
    await expect(tabOf(page, /Facebook · e2e Facebook/)).toBeFocused();
    await page.keyboard.press('Home');
    await expect(tabOf(page, 'Texto común')).toBeFocused();

    // Un texto propio que supera el máximo de su red lo cuenta esa red y su error lleva a su pestaña.
    await tabOf(page, /Instagram · e2e Instagram/).click();
    await page.getByRole('button', { name: 'Personalizar el texto de esta red' }).click();
    await page.getByLabel('Texto de Instagram · e2e Instagram').fill('x'.repeat(2201));
    await expect(ig.getByTestId('counter-chars')).toHaveText(`${miles(2201)} / 2.200 · supera el máximo`);
    await expect(fb.getByTestId('counter-chars')).toHaveText(`${chars(textoFb)} / 63.206`);
    await tabOf(page, 'Texto común').click();
    await page.getByTestId('errors-panel').getByRole('button', { name: /Ir al control con el error de e2e Instagram/ }).first().click();
    await expect(page.getByLabel('Texto de Instagram · e2e Instagram')).toBeFocused();

    await axeNow(page, 'texto por red con pestañas');
    await expectNoOverflow(page);
    expect(w.errors.filter((e) => !/422/.test(e))).toEqual([]);
});

test('E2E-08 · guardar, reabrir conserva el texto propio y volver a vincular (con confirmación) lo borra', async ({ page }) => {
    const w = watch(page);
    seedExtraFacebook(brand.id, 'e2e Pagina B');
    await open(page);
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Pagina B/ }).click();
    await page.getByLabel('Texto', { exact: true }).fill('e2e texto común');
    await page.getByRole('button', { name: 'Editar por red' }).click();
    await tabOf(page, /Facebook · e2e Pagina B/).click();
    await page.getByRole('button', { name: 'Personalizar el texto de esta red' }).click();
    await page.getByLabel('Texto de Facebook · e2e Pagina B').fill('e2e texto propio de B');
    await guardarBorrador(page);

    // Reabierto: las pestañas se abren solas (hay texto propio) y cada red conserva lo suyo: A vinculada y B personalizada.
    await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();
    await settle(page);
    await expect(page.getByRole('tablist')).toBeVisible();
    await expect(tabOf(page, /Facebook · e2e Pagina B/)).toContainText('propio');
    await expect(tabOf(page, /Facebook · e2e Facebook/)).not.toContainText('propio');
    await tabOf(page, /Facebook · e2e Pagina B/).click();
    await expect(page.getByLabel('Texto de Facebook · e2e Pagina B')).toHaveValue('e2e texto propio de B');
    await tabOf(page, /Facebook · e2e Facebook/).click();
    await expect(page.getByTestId('linked-text')).toHaveText('e2e texto común');
    await expectNoOverflow(page);

    // CA-09: volver a vincular pide confirmación («Se perderá el texto propio de esta red»); Cancelar lo conserva.
    await tabOf(page, /Facebook · e2e Pagina B/).click();
    await page.getByRole('button', { name: 'Volver al texto común' }).click();
    await expect(page.getByTestId('relink-confirm')).toContainText('Se perderá el texto propio de esta red');
    await axeNow(page, 'confirmación de volver al texto común');
    await expectNoOverflow(page);
    await page.getByRole('button', { name: 'Cancelar' }).click();
    await expect(page.getByLabel('Texto de Facebook · e2e Pagina B')).toHaveValue('e2e texto propio de B');
    await page.getByRole('button', { name: 'Volver al texto común' }).click();
    await page.getByRole('button', { name: 'Sí, volver al texto común' }).click();
    await expect(page.getByTestId('linked-badge')).toBeVisible();
    await expect(page.getByTestId('linked-text')).toHaveText('e2e texto común');
    await expect(tabOf(page, /Facebook · e2e Pagina B/)).not.toContainText('propio');

    // Guardado y recargado: el texto propio ya no existe (sin pestañas abiertas, todas con el texto común).
    await guardarBorrador(page);
    await page.reload();
    await settle(page);
    await expect(page.getByRole('tablist')).toHaveCount(0);
    await expect(page.getByText('Todas las redes publican el mismo texto.')).toBeVisible();
    await page.getByRole('button', { name: 'Editar por red' }).click();
    await tabOf(page, /Facebook · e2e Pagina B/).click();
    await expect(page.getByTestId('linked-text')).toHaveText('e2e texto común');
    await axeNow(page, 'texto por red reabierto');
    await expectNoOverflow(page);
    expect(w.errors.filter((e) => !/422/.test(e))).toEqual([]);
});

const seccion = (page: Page, red: string, cuenta: string) => page.getByRole('region', { name: `Opciones de ${red} (${cuenta})` });

test('E2E-09 · interruptores por red: se recuerdan por marca, no se recuerdan los textos y la etiqueta de IA solo sale donde la API la admite', async ({ page }, info) => {
    const w = watch(page);
    const user = e2eUser(info.project.name);
    const otra = seedComposerBrand(user.email, `${stamp()}-b-${info.project.name}`);
    seedExtraAccount(brand.id, 'youtube', 'e2e YouTube');
    seedExtraAccount(brand.id, 'tiktok', 'e2e TikTok borrador');
    seedExtraAccount(brand.id, 'tiktok', 'e2e TikTok directo', true);
    seedExtraAccount(otra.id, 'youtube', 'e2e YouTube B');
    const draft = seedYoutubeDraft(brand.id, 'e2e YouTube');

    // Edición de un borrador de YouTube en la marca A: interruptores con su valor por defecto y la etiqueta de IA de YouTube.
    await page.goto(`/posts/${draft}/edit`);
    await settle(page);
    const yt = seccion(page, 'YouTube', 'e2e YouTube');
    await expect(yt.getByRole('switch', { name: 'Notificar a los suscriptores' })).toBeChecked();
    await expect(yt.getByRole('switch', { name: /Contenido alterado o generado con IA/ })).not.toBeChecked();
    await expect(yt.getByLabel('Categoría')).toHaveValue('22');

    // «Hecho para niños» avisa de que se desactivan los comentarios (se anuncia como estado).
    await yt.getByRole('switch', { name: 'Contenido hecho para niños' }).check();
    await expect(yt.getByText(/desactiva los comentarios/)).toBeVisible();
    await yt.getByRole('switch', { name: 'Contenido hecho para niños' }).uncheck();
    await expectNoOverflow(page);
    await axeNow(page, 'opciones de YouTube con interruptores');

    // CA-15: se apaga «Notificar», se escriben etiquetas y se guarda; el interruptor se recuerda en esta marca.
    await yt.getByRole('switch', { name: 'Notificar a los suscriptores' }).uncheck();
    await yt.getByLabel('Etiquetas (separadas por comas)').fill('e2e, prueba');
    await guardarBorrador(page);
    await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();

    // Un post nuevo de A: el interruptor aparece apagado y los textos (etiquetas) vacíos.
    await open(page);
    await page.getByRole('button', { name: /^YouTube: e2e YouTube/ }).click();
    await expect(seccion(page, 'YouTube', 'e2e YouTube').getByRole('switch', { name: 'Notificar a los suscriptores' })).not.toBeChecked();
    await expect(seccion(page, 'YouTube', 'e2e YouTube').getByLabel('Etiquetas (separadas por comas)')).toHaveValue('');

    // CA-16 y CA-14: la etiqueta de IA no existe en Instagram ni Facebook; TikTok en modo borrador no ofrece nada; el directo sí.
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
    await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
    await page.getByRole('button', { name: /^TikTok: e2e TikTok borrador/ }).click();
    await page.getByRole('button', { name: /^TikTok: e2e TikTok directo/ }).click();
    await expect(page.getByRole('switch', { name: /IA/ })).toHaveCount(2); // YouTube y TikTok directo, ninguna más
    await expect(page.getByRole('region', { name: /Opciones de Instagram/ }).getByRole('switch')).toHaveCount(0);
    await expect(page.getByRole('region', { name: /Opciones de Facebook/ }).getByRole('switch')).toHaveCount(0);
    const borrador = seccion(page, 'TikTok', 'e2e TikTok borrador');
    await expect(borrador.getByRole('switch')).toHaveCount(0);
    await expect(borrador.getByText(/borrador a tu bandeja/)).toBeVisible();
    const directo = seccion(page, 'TikTok', 'e2e TikTok directo');
    await expect(directo.getByRole('switch', { name: 'Desactivar Duet' })).toBeVisible();
    await expect(directo.getByRole('switch', { name: 'Desactivar Stitch' })).toBeVisible();
    await directo.getByRole('switch', { name: /Colaboración de pago/ }).check();
    await expect(directo.getByText(/No puede ser privado/)).toBeVisible();
    await page.screenshot({ path: 'test-results/composer-interruptores-por-red.png' });
    await expectNoOverflow(page);
    await axeNow(page, 'interruptores de YouTube y TikTok');

    // En la otra marca no cambia nada: «Notificar» sigue activado.
    await page.goto(`/brands/${otra.id}/posts/create`);
    await settle(page);
    await page.getByRole('button', { name: /^YouTube: e2e YouTube B/ }).click();
    await expect(seccion(page, 'YouTube', 'e2e YouTube B').getByRole('switch', { name: 'Notificar a los suscriptores' })).toBeChecked();
    await expectNoOverflow(page);
    expect(w.errors.filter((e) => !/422/.test(e))).toEqual([]);
});

test('E2E-10 · notas internas: se guardan en un post ya publicado, el calendario las marca y no se renderizan como HTML', async ({ page }, info) => {
    const w = watch(page);
    const posts = seedComposerPosts(e2eUser(info.project.name).email, brand.id);
    const nota = 'Revisar con el cliente <b>negrita</b> <img src=x onerror=alert(1)>\nSegunda línea';

    await page.goto(`/posts/${posts.published}/edit`);
    await settle(page);
    await expect(page.getByText('Esta publicación ya no se puede editar.')).toBeVisible();

    const campo = page.getByLabel('Notas internas');
    await expect(campo).toBeEditable();
    await campo.fill(nota);
    await expect(page.getByTestId('notes-count')).toContainText(`${nota.length} / 5000`);
    await page.getByRole('button', { name: 'Guardar notas' }).click();
    await expect(page.getByTestId('notes-saved')).toBeVisible();
    await page.screenshot({ path: 'test-results/composer-notas.png' });
    await axeNow(page, 'compositor con notas internas');
    await expectNoOverflow(page);

    // Recargar conserva la nota tal cual (texto plano, con el salto de línea).
    await page.reload();
    await settle(page);
    await expect(page.getByLabel('Notas internas')).toHaveValue(nota);
    await expect(page.locator('main b, form b')).toHaveCount(0);

    // Calendario: indicador con texto accesible solo en la tarjeta con nota, en las tres vistas.
    for (const vista of ['week', 'month', 'list']) {
        await page.goto(`/calendar?brand_id=${brand.id}&view=${vista}&date=${posts.scheduledDate}`);
        await settle(page);
        await expect(page.getByTestId('notes-indicator')).toHaveCount(1);
        await expect(page.getByTestId('notes-indicator')).toContainText('Nota');
        await expect(page.locator('b', { hasText: 'negrita' })).toHaveCount(0);
        await expectNoOverflow(page);
    }
    await axeNow(page, 'calendario con indicador de nota');
    expect(w.errors).toEqual([]);
});

test('E2E-11 · generador de UTM: inserta en el cursor, codifica, rechaza URLs peligrosas y Limpiar vacía', async ({ page }) => {
    const w = watch(page);
    await open(page);
    await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();

    const texto = page.getByLabel('Texto', { exact: true });
    await texto.fill('Mira  ahora');
    await texto.evaluate((el: HTMLTextAreaElement) => el.setSelectionRange(5, 5));

    const abrir = page.getByRole('button', { name: /Enlace con UTM/ });
    await abrir.click();
    const modal = page.getByRole('dialog', { name: 'Generar enlace con UTM' });
    await expect(modal).toBeVisible();
    await expect(modal.getByLabel(/Dirección \(URL\)/)).toBeFocused();
    await axeNow(page, 'generador de UTM abierto');
    await expectNoOverflow(page);

    const anadir = modal.getByRole('button', { name: 'Añadir' });
    await expect(anadir).toBeDisabled();

    // URL peligrosa: se rechaza y no se puede añadir aunque los obligatorios estén completos.
    await modal.getByLabel(/Dirección \(URL\)/).fill('javascript:alert(1)');
    await modal.getByLabel(/\(utm_source\)/).fill('instagram');
    await modal.getByLabel(/\(utm_medium\)/).fill('social');
    await modal.getByLabel(/\(utm_campaign\)/).fill('rebajas');
    await modal.getByLabel(/Dirección \(URL\)/).blur();
    await expect(modal.getByTestId('utm-url-error')).toContainText('http');
    await expect(anadir).toBeDisabled();

    // Limpiar vacía todos los campos.
    await modal.getByRole('button', { name: 'Limpiar' }).click();
    await expect(modal.getByLabel(/Dirección \(URL\)/)).toHaveValue('');
    await expect(modal.getByLabel(/\(utm_source\)/)).toHaveValue('');
    await expect(modal.getByLabel(/\(utm_campaign\)/)).toHaveValue('');
    await expect(anadir).toBeDisabled();

    // URL con parámetros propios y un utm_source previo: se conservan los suyos y no se duplica utm_*.
    await modal.getByLabel(/Dirección \(URL\)/).fill('https://ejemplo.com/oferta?ref=7&utm_source=viejo#precio');
    await modal.getByLabel(/\(utm_source\)/).fill('insta gram');
    await modal.getByLabel(/\(utm_medium\)/).fill('social&paid');
    await modal.getByLabel(/\(utm_campaign\)/).fill('rebajas otoño');
    await expect(anadir).toBeEnabled();
    const esperada = 'https://ejemplo.com/oferta?ref=7&utm_source=insta+gram&utm_medium=social%26paid&utm_campaign=rebajas+oto%C3%B1o#precio';
    await expect(modal.getByTestId('utm-result')).toHaveText(esperada);

    await anadir.click();
    await expect(modal).toHaveCount(0);
    await expect(abrir).toBeFocused();
    await expect(texto).toHaveValue(`Mira ${esperada} ahora`);

    // Escape cierra sin insertar nada.
    await abrir.click();
    await expect(modal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal).toHaveCount(0);
    await expect(texto).toHaveValue(`Mira ${esperada} ahora`);
    expect(w.errors).toEqual([]);
});
