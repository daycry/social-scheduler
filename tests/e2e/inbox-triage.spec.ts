import { expect, test } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedInboxComment, seedInboxProviders, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * T-20 · Inbox ampliado: cobertura por red (TikTok fuera con su motivo), filtros por red/estado/etiqueta/asignado, cierre,
 * etiquetas, acciones en lote y respuestas guardadas. Los comentarios se siembran por factoría: ninguna red se toca.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `inbox2-${stamp()}-${info.project.name}`);
    seedInboxComment(brand.id, 'e2e comentario instagram');
    seedInboxProviders(brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const row = (page: import('@playwright/test').Page, text: string) => page.locator('li').filter({ hasText: text }).first();
const rows = (page: import('@playwright/test').Page) => page.getByTestId('select-comment');

test('E2E-20a · cobertura por red, filtro por red y por estado', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    await page.goto(`/inbox?brand_id=${brand.id}`);
    await settle(page);
    await expect(rows(page)).toHaveCount(4);
    await expect(page.getByTestId('coverage-tiktok')).toContainText('No disponible');
    await expect(page.getByTestId('coverage-tiktok')).toContainText('API pública');
    await expect(page.getByTestId('coverage-threads')).toContainText('Leer y responder');
    await expect(page.getByTestId('coverage-instagram')).toContainText('mensajes directos');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Inbox ampliado (inicio)');

    await page.getByTestId('filter-network').selectOption('bluesky');
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await expect(page.getByText('e2e respuesta bluesky')).toBeVisible();
    // Ocultar solo existe en Meta: un comentario de Bluesky no lo ofrece.
    await expect(row(page, 'e2e respuesta bluesky').getByRole('button', { name: 'Ocultar' })).toHaveCount(0);

    await page.getByTestId('filter-network').selectOption('');
    await settle(page);
    await row(page, 'e2e hilo threads').getByTestId('toggle-close').click();
    await settle(page);
    await expect(row(page, 'e2e hilo threads').getByTestId('closed-badge')).toBeVisible();
    await page.getByTestId('filter-status').selectOption('closed');
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await page.getByTestId('filter-status').selectOption('open');
    await settle(page);
    await expect(rows(page)).toHaveCount(3);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Inbox ampliado (estado)');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-20b · etiquetas, asignación y filtros', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    await page.goto(`/inbox?brand_id=${brand.id}`);
    await settle(page);

    const yt = row(page, 'e2e comentario youtube');
    await yt.getByTestId('label-input').fill('Ventas Q4');
    await yt.getByTestId('label-add').click();
    await settle(page);
    await expect(row(page, 'e2e comentario youtube').getByTestId('label-chip')).toContainText('ventas q4');

    await page.getByTestId('filter-label').selectOption('ventas q4');
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await expectNoOverflow(page);
    await page.getByTestId('filter-label').selectOption('');
    await settle(page);
    await expect(rows(page)).toHaveCount(4);

    await row(page, 'e2e comentario youtube').getByTestId('assign-select').selectOption({ index: 1 }); // «Nadie» y el propietario
    await settle(page);
    await page.getByTestId('filter-assigned').selectOption('me');
    await settle(page);
    await expect(rows(page)).toHaveCount(1);
    await page.getByTestId('filter-assigned').selectOption('none');
    await settle(page);
    await expect(rows(page)).toHaveCount(3);

    // Quitar la etiqueta.
    await page.getByTestId('filter-assigned').selectOption('');
    await settle(page);
    await row(page, 'e2e comentario youtube').getByRole('button', { name: 'Quitar etiqueta ventas q4' }).click();
    await settle(page);
    await expect(page.getByTestId('label-chip')).toHaveCount(0);
    await axeSeriousCritical(page, 'Inbox ampliado (etiquetas)');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-20c · lote (leer y cerrar) y respuestas guardadas', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    await page.goto(`/inbox?brand_id=${brand.id}`);
    await settle(page);

    await expect(page.getByTestId('batch-read')).toBeDisabled();
    await page.getByTestId('select-all').check();
    await expect(page.getByText('4 seleccionados')).toBeVisible();
    await expectNoOverflow(page);
    await page.getByTestId('batch-read').click();
    await settle(page);
    await expect(page.getByText('nuevo', { exact: true })).toHaveCount(0);

    await page.getByTestId('select-all').check();
    await page.getByTestId('batch-close').click();
    await settle(page);
    await expect(page.getByTestId('closed-badge')).toHaveCount(4);
    await page.getByTestId('filter-status').selectOption('open');
    await settle(page);
    await expect(rows(page)).toHaveCount(0);

    // Respuesta guardada: alta, uso en el cuadro de respuesta y borrado.
    await page.getByTestId('saved-title').fill('Gracias');
    await page.getByTestId('saved-body').fill('Gracias por escribirnos, te contestamos hoy.');
    await page.getByTestId('saved-add').click();
    await settle(page);
    await expect(page.getByTestId('saved-item')).toHaveCount(1);

    await page.getByTestId('filter-status').selectOption('');
    await settle(page);
    await row(page, 'e2e comentario instagram').getByRole('button', { name: 'Responder' }).click();
    await page.getByTestId('saved-select').selectOption({ label: 'Gracias' });
    await expect(page.getByPlaceholder('Escribe tu respuesta…')).toHaveValue('Gracias por escribirnos, te contestamos hoy.');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Inbox ampliado (respuesta guardada)');

    await page.getByRole('button', { name: /Eliminar la respuesta guardada Gracias/ }).click();
    await settle(page);
    await expect(page.getByTestId('saved-item')).toHaveCount(0);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
