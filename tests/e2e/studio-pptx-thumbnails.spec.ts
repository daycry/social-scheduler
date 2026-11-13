import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

/**
 * T-35 · Estudio: miniaturas por diseño en el listado (bajo demanda por la cola `media`, invalidadas al editar), exportación PPTX y traducción
 * de subtítulos desactivada sin asistente de IA. Chromium renderiza de verdad; ningún dato sale a un proveedor de IA. Abrir el PPTX en PowerPoint
 * es el manual M-11.
 */
async function call(page: Page, method: string, url: string, body?: unknown) {
    return page.evaluate(async ({ method, url, body }) => {
        const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
        const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        if (!response.ok) throw new Error(`${response.status}: ${await response.text()}`);
        return response.json();
    }, { method, url, body });
}
const document2 = (color: string) => ({ schema_version: 1, width: 320, height: 180, pages: [
    { id: 'one', name: 'Portada', duration: 5, background: color, elements: [] },
    { id: 'two', name: 'Cierre', duration: 5, background: '#00aa55', elements: [] },
] });
const runQueue = (queue: string) => artisan(`Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'${queue}','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);`);

test('T-35 · listado: miniatura bajo demanda, caducada al editar y exportación PPTX descargable', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(180_000);
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-pptx-${stamp()}-${info.project.name}`);
    page.on('pageerror', (error) => errors.push(error.message));
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        const design = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: 'Con miniatura', document: document2('#2244cc') })).design;

        // Sin miniatura: hueco con el nombre y el trabajo queda en la cola; con el worker, aparece la imagen.
        await page.goto(`/studio?brand_id=${brand.id}`);
        await expect(page.getByTestId('design-thumbnail-pending')).toHaveCount(1);
        runQueue('media');
        await page.reload();
        const thumbnail = page.getByTestId('design-thumbnail');
        await expect(thumbnail).toBeVisible();
        await expect.poll(() => thumbnail.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
        await expectNoOverflow(page);

        // Editar invalida la miniatura (vuelve el hueco) y se regenera sin bloquear el guardado.
        const edited = (await call(page, 'PATCH', `/studio/designs/${design.id}`, { name: 'Con miniatura', document: document2('#cc2244'), revision: design.revision, autosave: true })).design;
        expect(edited.revision).toBe(design.revision + 1);
        await page.reload();
        await expect(page.getByTestId('design-thumbnail-pending')).toHaveCount(1);
        runQueue('media');
        await page.reload();
        await expect(page.getByTestId('design-thumbnail')).toBeVisible();

        // PPTX: se prepara en segundo plano y se descarga como un paquete OOXML valido con una diapositiva por pagina.
        await page.goto(`/studio/designs/${design.id}/edit`);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        await dialog.getByLabel('Formato', { exact: true }).selectOption('pptx');
        await expect(dialog.getByText('el texto no es editable en PowerPoint')).toBeVisible();
        const created = page.waitForResponse((r) => r.url().endsWith(`/studio/designs/${design.id}/render`) && r.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Preparar PPTX', exact: true }).click();
        const started = await created;
        expect(started.status()).toBe(202);
        expect(started.request().postDataJSON()).toMatchObject({ format: 'pptx', pages: ['one', 'two'] });
        const row = dialog.getByRole('region', { name: `Exportación ${(await started.json()).export.id}`, exact: true });
        runQueue('media');
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        await expect(row).toContainText('Lista');
        await expect(row.getByRole('button', { name: 'Crear borrador' })).toHaveCount(0);
        const [download] = await Promise.all([page.waitForEvent('download'), row.getByRole('link', { name: 'Descargar PPTX', exact: true }).click()]);
        const file = (await download.path())!;
        const listing = execFileSync('python', ['-m', 'zipfile', '-l', file], { encoding: 'utf8' });
        expect(listing).toContain('[Content_Types].xml');
        expect(listing).toContain('ppt/slides/slide1.xml');
        expect(listing).toContain('ppt/slides/slide2.xml');
        expect(listing).toContain('ppt/media/image2.png');
        execFileSync('python', ['-m', 'zipfile', '-t', file]);
        expect(errors, errors.join('\n')).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

const captionsDocument = () => ({ schema_version: 2, width: 320, height: 180, timeline: { fps: 12, audio_tracks: [], captions: {
    style: { fontFamily: 'Arial', fontSize: 24, fill: '#ffffff' }, cues: [{ id: 'cue_a', start: 0.25, end: 1.25, text: 'Hola a todos' }, { id: 'cue_b', start: 1.5, end: 2.5, text: 'Hasta pronto' }],
} }, pages: [{ id: 'one', name: 'Una', duration: 5, background: '#000000', elements: [] }] });

async function openCaptions(page: Page, brandId: number, name: string) {
    await page.goto(`/studio?brand_id=${brandId}`);
    const design = (await call(page, 'POST', `/brands/${brandId}/studio/designs`, { name, document: captionsDocument() })).design;
    await page.goto(`/studio/designs/${design.id}/edit`);
    const timeline = page.getByRole('button', { name: 'Línea de tiempo', exact: true });
    await expect(timeline).toBeEnabled();
    if ((await timeline.getAttribute('aria-pressed')) !== 'true') await timeline.click();
    const panel = page.getByRole('region', { name: 'Subtítulos', exact: true });
    await expect(panel).toBeVisible();
    await panel.getByText('Traducir subtítulos con IA').click();

    return { design, panel };
}

test('T-35 · subtítulos: sin asistente de IA la traducción se explica y no envía nada', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-translate-off-${stamp()}-${info.project.name}`);
    const sent: string[] = [];
    page.on('request', (r) => { if (r.url().includes('/ai-text')) sent.push(r.url()); });
    // El asistente de este servidor E2E puede estar activado: se simula la respuesta de «no configurado» del estado común.
    await page.route('**/brands/*/studio/ai', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ providers: [], text: { enabled: false, remaining: 0, vision: false } }) }));
    try {
        await loginAs(page, user);
        const { panel } = await openCaptions(page, brand.id, 'Traducción apagada');
        await expect(panel.getByTestId('captions-translate-unconfigured')).toContainText('Textos con IA: no configurado');
        await expect(panel.getByRole('button', { name: 'Traducir con IA' })).toHaveCount(0);
        await expectNoOverflow(page);
        expect(sent).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('T-35 · subtítulos: la propuesta de traducción se revisa y solo cambia los textos al aplicarla', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-translate-on-${stamp()}-${info.project.name}`);
    const bodies: any[] = [];
    // La IA se simula en el navegador: ninguna petición llega al asistente ni a un proveedor.
    await page.route('**/brands/*/studio/ai', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ providers: [], text: { enabled: true, remaining: 5, vision: false } }) }));
    await page.route('**/brands/*/studio/ai-text', (route) => {
        bodies.push(route.request().postDataJSON());
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ result: { language: 'inglés', cues: [{ id: 'cue_a', text: 'Hello everyone' }, { id: 'cue_b', text: 'See you soon' }] } }) });
    });
    try {
        await loginAs(page, user);
        const { design, panel } = await openCaptions(page, brand.id, 'Traducción activa');
        await panel.getByLabel('Idioma de destino', { exact: true }).fill('inglés');
        await panel.getByRole('button', { name: 'Traducir con IA' }).click();
        const proposal = panel.getByTestId('captions-translation');
        await expect(proposal).toContainText('Hello everyone');
        expect(bodies).toEqual([{ action: 'translate_captions', language: 'inglés', cues: [{ id: 'cue_a', text: 'Hola a todos' }, { id: 'cue_b', text: 'Hasta pronto' }] }]);
        // Es una propuesta: hasta aplicarla, el diseno guardado conserva el texto original.
        const stored = async () => (await call(page, 'GET', `/studio/designs/${design.id}`)).design.document.timeline.captions.cues;
        expect((await stored()).map((c: any) => c.text)).toEqual(['Hola a todos', 'Hasta pronto']);
        await proposal.getByRole('button', { name: 'Aplicar traducción (reemplaza los textos)' }).click();
        await expect.poll(async () => (await stored()).map((c: any) => c.text)).toEqual(['Hello everyone', 'See you soon']);
        expect((await stored()).map((c: any) => [c.id, c.start, c.end])).toEqual([['cue_a', 0.25, 1.25], ['cue_b', 1.5, 2.5]]);
        await expectNoOverflow(page);
    } finally {
        cleanComposerBrands(user.email);
    }
});
