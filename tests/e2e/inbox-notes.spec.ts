import { expect, test } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedInboxComment, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Notas internas del inbox (C-04d, E2E-01): alta, insignia «con nota», filtro y borrado. Usuarios `e2e-sec-*` y marcas `e2e-composer-*`;
 * los comentarios se siembran por factoría: no se toca ninguna red ni el usuario demo.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `inbox-${stamp()}-${info.project.name}`);
    seedInboxComment(brand.id, 'e2e comentario con nota', 'e2e comentario sin nota');
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const row = (page: import('@playwright/test').Page, text: string) => page.locator('li').filter({ hasText: text });

test('E2E-01 · notas del inbox: alta, insignia, filtro y borrado', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/inbox?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Inbox de comentarios' })).toBeVisible();
    await expect(page.getByTestId('note-badge')).toHaveCount(0);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Inbox (inicio)');

    // Alta de una nota en el primer comentario.
    const con = row(page, 'e2e comentario con nota');
    await con.getByTestId('comment-note-toggle').click();
    await con.getByTestId('comment-note-input').fill('Pasar a ventas el lunes');
    await expectNoOverflow(page);
    await con.getByTestId('comment-note-save').click();
    await settle(page);
    await expect(con.getByTestId('comment-note')).toContainText('Pasar a ventas el lunes');
    await expect(page.getByTestId('note-badge')).toHaveCount(1);
    await expect(row(page, 'e2e comentario sin nota').getByTestId('comment-note')).toHaveCount(0);

    // Filtro «con nota».
    await expect(page.locator('li').filter({ hasText: 'e2e comentario' })).toHaveCount(2);
    await page.getByTestId('filter-has-notes').check();
    await settle(page);
    await expect(page.locator('li').filter({ hasText: 'e2e comentario' })).toHaveCount(1);
    await expect(page.getByText('e2e comentario sin nota')).toHaveCount(0);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Inbox (filtrado, con nota)');

    // Borrado: la insignia desaparece y, con el filtro activo, ya no hay nada que mostrar.
    await page.getByTestId('comment-note-delete').click();
    await settle(page);
    await expect(page.getByTestId('comment-note')).toHaveCount(0);
    await expect(page.getByTestId('note-badge')).toHaveCount(0);
    await page.getByTestId('filter-has-notes').uncheck();
    await settle(page);
    await expect(page.locator('li').filter({ hasText: 'e2e comentario' })).toHaveCount(2);
    await axeSeriousCritical(page, 'Inbox (sin notas)');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
