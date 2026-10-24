import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { chooseContentType, cleanComposerBrands, e2eUser, expectNoOverflow, firstCommentOf, loginAs, seedComposerBrand, seedExtraAccount, seedPublishedWithComment, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Primer comentario (C-01b): campo por destino con contador (E2E-03) y estado, edición y Reintentar de un comentario fallido (E2E-04).
 * Usuarios `e2e-sec-*` y marcas `e2e-composer-*`: el destino publicado se siembra y nada llega a una red real.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `fc-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
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

const pill = (page: Page, network: string, name: string) => page.getByRole('button', { name: new RegExp(`^${network}: ${name}`) });
const fields = (page: Page) => page.getByTestId('first-comment');

test('E2E-03 · el campo aparece en Instagram, no en TikTok ni en una Historia, y el contador avisa del máximo', async ({ page }) => {
    const w = watch(page);
    seedExtraAccount(brand.id, 'tiktok', 'e2e TikTok');
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await pill(page, 'Instagram', 'e2e Instagram').click();
    await pill(page, 'TikTok', 'e2e TikTok').click();

    // Solo Instagram tiene campo; TikTok no.
    await expect(fields(page)).toHaveCount(1);
    const field = page.getByLabel('Primer comentario (opcional)');
    await expect(field).toBeVisible();
    await expect(page.getByRole('region', { name: /Opciones de TikTok/ }).getByTestId('first-comment')).toHaveCount(0);

    // Contador y máximo (2.200 en Instagram).
    await field.fill('a'.repeat(2201));
    await expect(page.getByTestId('first-comment-count')).toContainText('2201 / 2200');
    await expect(page.getByTestId('first-comment-count')).toContainText('supera el máximo');
    await expect(field).toHaveAttribute('aria-invalid', 'true');
    // La validación en vivo lo rechaza con el mismo mensaje del servidor.
    await expect(page.getByText(/El primer comentario admite como maximo 2200/).first()).toBeVisible({ timeout: 8000 });

    await field.fill('Más info en el enlace');
    await expect(page.getByTestId('first-comment-count')).toContainText('21 / 2200');
    await expect(field).not.toHaveAttribute('aria-invalid', 'true');
    await expectNoOverflow(page);
    await axeNow(page, 'compositor con primer comentario');

    // Una Historia no admite primer comentario: el campo desaparece y su texto no viaja.
    await chooseContentType(page, /Tipo de contenido en Instagram/, 'Historia');
    await expect(fields(page)).toHaveCount(0);
    await expectNoOverflow(page);
    await axeNow(page, 'compositor con Historia');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-03 · YouTube sin el permiso de comentarios avisa de reconectar y deshabilita el campo', async ({ page }) => {
    seedExtraAccount(brand.id, 'youtube', 'e2e YouTube');
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await pill(page, 'YouTube', 'e2e YouTube').click();

    await expect(page.getByTestId('first-comment-reconnect')).toContainText('Reconecta el canal para comentar');
    await expect(page.getByLabel('Primer comentario (opcional)')).toBeDisabled();
    await expectNoOverflow(page);
    await axeNow(page, 'YouTube sin permiso de comentarios');
});

test('E2E-04 · un comentario fallido se edita y se reintenta sin tocar el estado del destino', async ({ page }, info) => {
    const w = watch(page);
    const seeded = seedPublishedWithComment(e2eUser(info.project.name).email, brand.id);
    await page.goto(`/posts/${seeded.postId}/edit`);
    await settle(page);

    const status = page.getByTestId('first-comment-status');
    await expect(status.getByTestId('first-comment-badge')).toHaveText('Fallido');
    await expect(status.getByTestId('first-comment-error')).toContainText('no admite comentarios');
    // El destino sigue publicado.
    await expect(page.getByRole('heading', { name: 'Estado por red' }).locator('xpath=..')).toContainText('Publicada');
    await expectNoOverflow(page);
    await axeNow(page, 'comentario fallido');

    // Editar el texto.
    await status.getByRole('button', { name: 'Editar' }).click();
    const edit = page.getByLabel('Texto del primer comentario');
    await expect(edit).toBeFocused();
    await edit.fill('e2e Texto corregido');
    await expectNoOverflow(page);
    await axeNow(page, 'editando el comentario');
    await page.getByRole('button', { name: 'Guardar', exact: true }).click();
    await expect(status).toContainText('e2e Texto corregido');
    expect(firstCommentOf(seeded.targetId)).toMatchObject({ status: 'fallido', text: 'e2e Texto corregido', postStatus: 'publicada' });

    // Reintentar: pasa a pendiente y el destino sigue publicado.
    await status.getByRole('button', { name: 'Reintentar' }).click();
    await expect(status.getByTestId('first-comment-badge')).toHaveText('Pendiente');
    await expect(status.getByRole('button', { name: 'Reintentar' })).toHaveCount(0);
    await expect(status.getByRole('button', { name: 'Editar' })).toHaveCount(0);
    expect(firstCommentOf(seeded.targetId)).toMatchObject({ status: 'pendiente', postStatus: 'publicada' });
    await axeNow(page, 'comentario pendiente');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-04 · un comentario publicado no se edita ni se reintenta', async ({ page }, info) => {
    const seeded = seedPublishedWithComment(e2eUser(info.project.name).email, brand.id, 'publicado', '');
    await page.goto(`/posts/${seeded.postId}/edit`);
    await settle(page);

    const status = page.getByTestId('first-comment-status');
    await expect(status.getByTestId('first-comment-badge')).toHaveText('Comentado');
    await expect(status.getByRole('button', { name: 'Editar' })).toHaveCount(0);
    await expect(status.getByRole('button', { name: 'Reintentar' })).toHaveCount(0);
    await expectNoOverflow(page);
});

test('E2E-04 · con envío previo posible: «Ya está publicado» no llama a la red y «Publicar igualmente» pide confirmación', async ({ page }, info) => {
    const w = watch(page);
    const email = e2eUser(info.project.name).email;
    const seeded = seedPublishedWithComment(email, brand.id, 'fallido', 'No se pudo comprobar si el comentario ya existe.', true);
    await page.goto(`/posts/${seeded.postId}/edit`);
    await settle(page);

    const status = page.getByTestId('first-comment-status');
    await expect(status.getByRole('button', { name: 'Ya está publicado' })).toBeVisible();

    // Publicar igualmente: segundo clic con aviso de duplicado; Cancelar no cambia nada.
    await status.getByRole('button', { name: 'Publicar igualmente' }).click();
    const force = status.getByTestId('first-comment-force');
    await expect(force).toContainText('quedará duplicado');
    await expect(force.getByRole('button', { name: 'Sí, publicar igualmente' })).toBeFocused();
    await expectNoOverflow(page);
    await axeNow(page, 'confirmación de Publicar igualmente');
    await force.getByRole('button', { name: 'Cancelar' }).click();
    await expect(force).toHaveCount(0);
    expect(firstCommentOf(seeded.targetId)).toMatchObject({ status: 'fallido' });

    // Ya está publicado: queda Comentado sin más botones.
    await status.getByRole('button', { name: 'Ya está publicado' }).click();
    await expect(status.getByTestId('first-comment-badge')).toHaveText('Comentado');
    await expect(status.getByRole('button', { name: 'Reintentar' })).toHaveCount(0);
    expect(firstCommentOf(seeded.targetId)).toMatchObject({ status: 'publicado', postStatus: 'publicada' });
    await expectNoOverflow(page);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-04 · sin envío previo no se ofrecen las salidas de duplicado', async ({ page }, info) => {
    const seeded = seedPublishedWithComment(e2eUser(info.project.name).email, brand.id);
    await page.goto(`/posts/${seeded.postId}/edit`);
    await settle(page);
    const status = page.getByTestId('first-comment-status');
    await expect(status.getByRole('button', { name: 'Reintentar' })).toBeVisible();
    await expect(status.getByRole('button', { name: 'Ya está publicado' })).toHaveCount(0);
    await expect(status.getByRole('button', { name: 'Publicar igualmente' })).toHaveCount(0);
});
