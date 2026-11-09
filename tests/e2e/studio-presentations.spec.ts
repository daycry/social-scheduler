import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';
// helpers imports isolated/runtime: managed loopback server + disposable fake-env DB only.
async function web(page: Page, method: string, url: string, body?: unknown) {
    return page.evaluate(async ({ method, url, body }) => {
        const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        return { status: response.status, data: await response.json() };
    }, { method, url, body });
}
async function setup(page: Page, info: TestInfo) {
    const user = e2eUser(info.project.name); cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `e2e-composer-presentation-${stamp()}-${info.project.name}`);
    await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
    await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
    const creation = page.getByRole('dialog', { name: 'Nuevo diseño', exact: true });
    await creation.getByLabel('Ancho', { exact: true }).fill('320'); await creation.getByLabel('Alto', { exact: true }).fill('180');
    await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
    await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
    const id = Number(page.url().match(/designs\/(\d+)/)![1]);
    await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
    return { user, id };
}
const presentation = (page: Page) => page.getByRole('dialog', { name: 'Presentación', exact: true });
const save = async (page: Page) => { await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click(); await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado'); };
async function installDeck(page: Page, id: number) {
    const before = await web(page, 'GET', `/studio/designs/${id}`); expect(before.status).toBe(200);
    const document = { schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [], captions: { style: { fontSize: 20, position: 'bottom' }, cues: [{ id: 'caption_a', start: 0.1, end: 0.8, text: 'Caption visible' }] } }, pages: [
        { id: 'first', name: 'Roja', duration: 2, background: '#ff0000', notes: 'Primera 🎵\ntexto <b>plano</b>', elements: [], transition: { type: 'fade', duration: 0.5 } },
        { id: 'second', name: 'Azul', duration: 2, background: '#0000ff', notes: 'Segunda Ω', elements: [] },
    ] };
    const response = await web(page, 'PATCH', `/studio/designs/${id}`, { name: before.data.design.name, document, revision: before.data.design.revision, is_template: false, folder_id: null, kit_revision: before.data.design.kit_revision ?? before.data.design.revision });
    expect(response.status).toBe(200); await page.reload();
    await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
    return (await web(page, 'GET', `/studio/designs/${id}`)).data.design.document;
}
async function pixel(page: Page) {
    return presentation(page).getByRole('img', { name: 'Diapositiva de audiencia', exact: true }).evaluate((node: HTMLCanvasElement) => [...node.getContext('2d')!.getImageData(5, 5, 1, 1).data]);
}

test('E2E-29 notes preserve immediate save, page ownership, Unicode, history and reopen', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    try {
        await page.getByRole('button', { name: 'Página', exact: true }).click();
        const notes = page.getByLabel('Notas del presentador', { exact: true });
        await notes.fill('Primera 🎵\n <b>texto plano</b>  ');
        // Click immediately: no blur/debounce is needed to commit notes.
        await page.getByRole('button', { name: 'Añadir página', exact: true }).click();
        await notes.fill('Segunda Ω'); await save(page);
        let stored = (await web(page, 'GET', `/studio/designs/${id}`)).data.design.document;
        expect(stored.pages.map((item: { notes: string }) => item.notes)).toEqual(['Primera 🎵\n <b>texto plano</b>  ', 'Segunda Ω']);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click(); await expect(notes).not.toHaveValue('Segunda Ω');
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click(); await expect(notes).toHaveValue('Segunda Ω');
        await notes.fill('🎵'.repeat(20000)); await expect(notes).toHaveAttribute('aria-invalid', 'false');
        await notes.fill('🎵'.repeat(20001)); await expect(notes).toHaveAttribute('aria-invalid', 'true');
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click(); await expect(page.getByText(/Las notas admiten hasta/).first()).toBeVisible();
        await notes.fill('Última edición antes de presentar');
        await page.getByRole('button', { name: 'Presentar', exact: true }).click();
        await expect(presentation(page).getByRole('button', { name: 'Reproducir presentación', exact: true })).toBeEnabled();
        await presentation(page).getByRole('button', { name: 'Última diapositiva', exact: true }).click();
        await expect(presentation(page).getByTestId('presentation-notes')).toHaveText('Última edición antes de presentar');
        await presentation(page).getByRole('button', { name: 'Cerrar presentación', exact: true }).click();
        await page.reload(); await page.getByRole('button', { name: 'Página', exact: true }).click();
        stored = (await web(page, 'GET', `/studio/designs/${id}`)).data.design.document;
        expect(stored.schema_version).toBe(1); expect(stored.pages[1].notes).toBe('Última edición antes de presentar');
        await page.screenshot({ path: info.outputPath('presentation-notes-reopened.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 presenter uses real pixels, manual fade destination, keys, autoplay, loop, blackout and popup cleanup', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    try {
        const before = await installDeck(page, id);
        await page.getByRole('button', { name: 'Presentar', exact: true }).click();
        const modal = presentation(page); await expect(modal.getByRole('button', { name: 'Reproducir presentación', exact: true })).toBeEnabled();
        await expect.poll(() => pixel(page)).toEqual([255, 0, 0, 255]);
        await expect(modal.getByTestId('presentation-notes')).toHaveText('Primera 🎵\ntexto <b>plano</b>');
        await expect(modal.getByTestId('presentation-notes').locator('b')).toHaveCount(0);
        await modal.press('ArrowRight'); await expect.poll(() => pixel(page)).toEqual([0, 0, 255, 255]);
        await expect(modal.getByTestId('presentation-notes')).toHaveText('Segunda Ω');
        await modal.press('Home'); await expect.poll(() => pixel(page)).toEqual([255, 0, 0, 255]);
        await modal.press('b'); await expect.poll(() => pixel(page)).toEqual([0, 0, 0, 255]);
        await modal.press('b'); await expect.poll(() => pixel(page)).toEqual([255, 0, 0, 255]);
        await modal.getByLabel('Bucle', { exact: true }).check();
        await modal.getByRole('button', { name: 'Reproducir presentación', exact: true }).click();
        await expect(modal.getByTestId('presentation-notes')).toHaveText('Segunda Ω', { timeout: 5000 });
        await expect(modal.getByTestId('presentation-notes')).toHaveText('Primera 🎵\ntexto <b>plano</b>', { timeout: 5000 });
        await modal.getByRole('button', { name: 'Pausar presentación', exact: true }).click();
        const popupPromise = page.waitForEvent('popup'); await modal.getByRole('button', { name: 'Abrir audiencia', exact: true }).click(); const popup = await popupPromise;
        await expect(popup.locator('body > canvas')).toHaveCount(1); await expect(popup.locator('body')).not.toContainText('Primera');
        await modal.getByRole('button', { name: 'Pantalla negra', exact: true }).click();
        await expect.poll(() => popup.locator('canvas').evaluate((node: HTMLCanvasElement) => [...node.getContext('2d')!.getImageData(5, 5, 1, 1).data])).toEqual([0, 0, 0, 255]);
        await modal.getByRole('button', { name: 'Mostrar diapositiva', exact: true }).click();
        await popup.locator('body').press('End'); await expect(modal.getByTestId('presentation-notes')).toHaveText('Segunda Ω');
        await page.screenshot({ path: info.outputPath('presenter-current-next-notes.png'), fullPage: true });
        const closed = popup.waitForEvent('close'); await modal.press('Escape'); await closed;
        await expect(modal).not.toBeVisible(); await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeFocused();
        expect((await web(page, 'GET', `/studio/designs/${id}`)).data.design.document).toEqual(before);
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 popup blocking and fullscreen failures are visible; audience mode and terminal frame remain usable', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    try {
        await installDeck(page, id);
        await page.evaluate(() => { window.open = () => null; Element.prototype.requestFullscreen = () => Promise.reject(new Error('Fullscreen denied for QA')); });
        await page.getByRole('button', { name: 'Presentar', exact: true }).click(); const modal = presentation(page);
        await expect(modal.getByRole('button', { name: 'Reproducir presentación', exact: true })).toBeEnabled();
        await modal.getByRole('button', { name: 'Abrir audiencia', exact: true }).click(); await expect(modal.getByRole('alert')).toContainText('bloqueada');
        await modal.getByRole('button', { name: 'Pantalla completa', exact: true }).click(); await expect(modal.getByRole('alert')).toContainText('Fullscreen denied');
        await modal.getByLabel('Vista de presentación', { exact: true }).selectOption('audience'); await expect(modal.getByTestId('presentation-notes')).not.toBeVisible();
        await modal.getByRole('button', { name: 'Última diapositiva', exact: true }).click();
        await modal.getByRole('button', { name: 'Diapositiva siguiente', exact: true }).click(); await expect.poll(() => pixel(page)).toEqual([0, 0, 255, 255]);
        await modal.press('Escape'); await page.reload(); await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
        await page.screenshot({ path: info.outputPath('presentation-clean-close.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});
