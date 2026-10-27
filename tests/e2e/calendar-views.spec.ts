import { expect, test, type Page } from '@playwright/test';
import { axeSeriousCritical, cleanCalendarViews, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedComposerPosts, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Vistas guardadas del calendario (C-03a, E2E-02): guardar, aplicar desde otro filtro, renombrar y borrar. Usuarios `e2e-sec-*` y
 * marcas `e2e-composer-*`: no toca datos reales ni publica en redes.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanCalendarViews(user.email);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `views-${stamp()}-${info.project.name}`);
    seedComposerPosts(user.email, brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    const user = e2eUser(info.project.name);
    cleanCalendarViews(user.email);
    cleanComposerBrands(user.email);
});

const select = (page: Page, label: string) => page.getByLabel(label, { exact: true });
const menu = (page: Page) => page.getByTestId('views-menu');
const rows = (page: Page) => page.getByTestId('view-row');

async function openMenu(page: Page) {
    await page.getByTestId('views-open').click();
    await expect(menu(page)).toBeVisible();
}

/** Clic en un enlace del menú principal: en móvil vive en el drawer (se abre solo si hace falta), en escritorio está en la barra. */
async function clickNav(page: Page, name: string, exact = false) {
    const toggle = page.getByRole('button', { name: 'Abrir menú' });
    if (await toggle.isVisible()) {
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    }
    await page.getByRole('link', { name, exact }).filter({ visible: true }).first().click();
}

test('E2E-02 · vistas del calendario: guardar, aplicar desde otro filtro, renombrar y borrar', async ({ page }) => {
    const w = watch(page);
    await page.goto('/calendar?view=list');
    await settle(page);

    // Tres filtros: marca, red y estado (la lista es la vista que tiene el filtro de estado).
    // Cada filtro recarga la página: se espera a que el anterior conste en la URL antes de elegir el siguiente.
    await select(page, 'Marca').selectOption(String(brand.id));
    await page.waitForURL(/brand_id=/);
    await settle(page);
    await select(page, 'Red').selectOption('facebook');
    await page.waitForURL(/network=facebook/);
    await settle(page);
    await select(page, 'Estado').selectOption('programada');
    await page.waitForURL(/status=programada/);
    await settle(page);

    // Estado vacío del menú y guardado.
    await openMenu(page);
    await expect(page.getByTestId('views-empty')).toBeVisible();
    await page.getByTestId('view-name').fill('e2e Pendientes FB');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Calendario con el menú de vistas');
    await page.getByTestId('view-save').click();
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText('e2e Pendientes FB');

    // Cambia el filtro (todo en blanco) y elige la vista: se reaplican marca, red y estado.
    await page.keyboard.press('Escape');
    await page.goto('/calendar?view=week');
    await settle(page);
    await expect(select(page, 'Red')).toHaveValue('');
    await openMenu(page);
    await page.getByTestId('view-apply').click();
    await settle(page);
    await expect(select(page, 'Marca')).toHaveValue(String(brand.id));
    await expect(select(page, 'Red')).toHaveValue('facebook');
    await expect(select(page, 'Estado')).toHaveValue('programada');
    await expect(page.getByRole('tab', { name: 'Lista' })).toHaveAttribute('aria-selected', 'true');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Calendario con la vista aplicada');

    // Renombrar.
    await openMenu(page);
    await page.getByTestId('view-rename').click();
    await page.getByTestId('view-rename-input').fill('e2e Renombrada');
    await page.getByTestId('view-rename-save').click();
    await settle(page);
    await expect(rows(page).first()).toContainText('e2e Renombrada');

    // Borrar: el selector vuelve al estado vacío.
    await page.getByTestId('view-delete').click();
    await settle(page);
    await expect(rows(page)).toHaveCount(0);
    await expect(page.getByTestId('views-empty')).toBeVisible();
    await axeSeriousCritical(page, 'Calendario sin vistas');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-02 · la vista por defecto se aplica al abrir el calendario', async ({ page }) => {
    await page.goto('/calendar?view=list');
    await settle(page);
    await select(page, 'Red').selectOption('facebook');
    await page.waitForURL(/network=facebook/);
    await settle(page);
    await openMenu(page);
    await page.getByTestId('view-name').fill('e2e Por defecto');
    await page.getByTestId('view-save').click();
    await settle(page);
    await page.getByTestId('view-default').click();
    await settle(page);
    // Causa raíz del intermitente: `toContainText('por defecto')` casaba con el NOMBRE de la vista («e2e Por defecto»), no con la
    // marca de «por defecto», así que el test navegaba antes de que el PUT se hubiera confirmado (el servidor lo procesaba en
    // paralelo al GET de /calendar y este leía aún sin vista por defecto). Se espera al estado real del botón (aria-pressed).
    await expect(page.getByTestId('view-default')).toHaveAttribute('aria-pressed', 'true');

    await page.goto('/calendar');
    await settle(page);
    await expect(select(page, 'Red')).toHaveValue('facebook');
    await expect(page.getByRole('tab', { name: 'Lista' })).toHaveAttribute('aria-selected', 'true');
    await expectNoOverflow(page);
});

test('E2E-02 · la vista por defecto también se aplica al entrar por el menú (brand_id y view en el enlace)', async ({ page }) => {
    await page.goto('/calendar?view=list');
    await settle(page);
    await select(page, 'Red').selectOption('facebook');
    await page.waitForURL(/network=facebook/);
    await settle(page);
    await openMenu(page);
    await page.getByTestId('view-name').fill('e2e Menú');
    await page.getByTestId('view-save').click();
    await settle(page);
    await page.getByTestId('view-default').click();
    await expect(page.getByTestId('view-default')).toHaveAttribute('aria-pressed', 'true');

    // Desde otra página, por el menú: Planificación (enlace a /calendar con brand_id) y luego «Calendario» (brand_id + view=week).
    await page.goto('/dashboard');
    await settle(page);
    await clickNav(page, 'Planificación');
    await page.waitForURL(/\/calendar/);
    await settle(page);
    await expect(select(page, 'Red')).toHaveValue('facebook');
    await expect(page.getByRole('tab', { name: 'Lista' })).toHaveAttribute('aria-selected', 'true');

    await clickNav(page, 'Calendario', true);
    await page.waitForURL(/view=week/);
    await settle(page);
    await expect(select(page, 'Red')).toHaveValue('facebook');
    await expect(page.getByRole('tab', { name: 'Semana' })).toHaveAttribute('aria-selected', 'true');
});
