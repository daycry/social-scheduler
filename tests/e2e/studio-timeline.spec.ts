import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, quietFor, seedComposerBrand, stamp } from './helpers';

const browserErrors = new WeakMap<Page, string[]>();
test.beforeEach(async ({ page }) => {
    const errors: string[] = []; browserErrors.set(page, errors);
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => { if (message.type() === 'error') errors.push(`console: ${message.text()}`); });
});
test.afterEach(async ({ page }) => {
    await expectNoOverflow(page);
    expect(browserErrors.get(page) ?? []).toEqual([]);
});

test('Studio timeline: edit windows, split, persist and run a common playhead without changing the document', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-timeline-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        const creation = page.getByRole('dialog', { name: 'Nuevo diseño', exact: true });
        await creation.getByLabel('Ancho', { exact: true }).fill('320');
        await creation.getByLabel('Alto', { exact: true }).fill('180');
        await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        const id = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        await expect(timeline).toBeVisible();
        await timeline.getByLabel('Inicio del clip', { exact: true }).fill('1');
        await timeline.getByLabel('Inicio del clip', { exact: true }).press('Tab');
        await timeline.getByLabel('Duración del clip', { exact: true }).fill('3');
        await timeline.getByLabel('Duración del clip', { exact: true }).press('Tab');
        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        await clock.fill('2.5'); await clock.press('Tab');
        await timeline.getByRole('button', { name: 'Dividir clip', exact: true }).click();
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(2);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const read = () => page.evaluate(async designId => (await (await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } })).json()).design, id);
        const before = await read();
        expect(before.document.schema_version).toBe(2);
        expect(before.document.pages[0].elements.map((element: {timing: object}) => element.timing))
            .toEqual([{ start: 1, duration: 1.5 }, { start: 2.5, duration: 1.5 }]);
        await timeline.getByRole('button', { name: 'Reproducir', exact: true }).click();
        await expect.poll(async () => Number(await clock.inputValue())).toBeGreaterThan(2.6);
        await timeline.getByRole('button', { name: 'Pausar', exact: true }).click();
        const paused = Number(await clock.inputValue());
        await quietFor(page, 150); // negativa: en pausa el reloj no avanza
        expect(Number(await clock.inputValue())).toBeCloseTo(paused, 2);
        expect((await read()).document).toEqual(before.document);
        await page.screenshot({ path: info.outputPath('timeline-editor.png'), fullPage: true });
        await expectNoOverflow(page);
        await page.reload();
        expect((await read()).document).toEqual(before.document);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio timeline: an uploaded real video with an open source end can be split and previewed', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-timeline-video-${stamp()}-${info.project.name}`);
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=3',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20_000 });
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        const id = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'clip-timeline.mp4', mimeType: 'video/mp4', buffer: video });
        await page.getByRole('button', { name: 'Añadir clip-timeline.mp4', exact: true }).click();
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        await clock.fill('1'); await clock.press('Tab');
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(1);
        await expect(timeline.getByRole('button', { name: 'Dividir clip', exact: true })).toBeEnabled();
        await timeline.getByRole('button', { name: 'Dividir clip', exact: true }).click();
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(2);
        await timeline.getByRole('button', { name: 'Reproducir', exact: true }).click();
        await expect.poll(async () => Number(await clock.inputValue())).toBeGreaterThan(1.1);
        await timeline.getByRole('button', { name: 'Pausar', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const saved = await page.evaluate(async designId => (await (await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } })).json()).design.document, id);
        expect(saved.pages[0].elements[1].sourceOffset).toBe(1);
        expect(saved.pages[0].elements[1].trimEnd).toBeNull();
        await page.screenshot({ path: info.outputPath('timeline-video.png'), fullPage: true });
        await expect(page.getByRole('alert')).toHaveCount(0);
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio timeline: splitting the second page uses local clip time and survives undo and redo', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-timeline-pages-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        const id = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir página', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        await clock.fill('7'); await clock.press('Tab');
        await expect(timeline.getByRole('button', { name: 'Dividir clip', exact: true })).toBeEnabled();
        await timeline.getByRole('button', { name: 'Dividir clip', exact: true }).click();
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(2);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(1);
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
        await expect(timeline.getByTestId('timeline-clip')).toHaveCount(2);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const saved = await page.evaluate(async designId => (await (await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } })).json()).design.document, id);
        expect(saved.pages[1].elements.map((element: { timing: object }) => element.timing)).toEqual([{ start: 0, duration: 2 }, { start: 2, duration: 3 }]);
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio timeline: buffering never consumes a short clip before playback starts', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-timeline-buffer-${stamp()}-${info.project.name}`);
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=3',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20_000 });
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'buffer.mp4', mimeType: 'video/mp4', buffer: video });
        await page.getByRole('button', { name: 'Añadir buffer.mp4', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(1);
        await page.getByRole('button', { name: 'Página', exact: true }).click();
        await page.getByLabel('Duración de página (s)', { exact: true }).fill('0.5');
        await page.getByLabel('Duración de página (s)', { exact: true }).press('Tab');
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        await expect(timeline.getByRole('button', { name: 'Reproducir', exact: true })).toBeEnabled();
        await page.route('**/brands/*/studio/assets/*/file', async route => { await new Promise(resolve => setTimeout(resolve, 900)); await route.continue(); });
        await timeline.getByRole('button', { name: 'Reproducir', exact: true }).click();
        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        await expect.poll(async () => Number(await clock.inputValue()), { intervals: [10] }).toBeGreaterThan(0.05);
        expect(Number(await clock.inputValue())).toBeLessThan(0.4);
        await expect(timeline.getByRole('button', { name: 'Reproducir', exact: true })).toBeVisible();
        await expect(clock).toHaveValue('0.5');
        await expect(page.getByRole('alert')).toHaveCount(0);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio timeline: closing while media buffers cancels the pending playback', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-timeline-cancel-${stamp()}-${info.project.name}`);
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=3',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20_000 });
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'cancel.mp4', mimeType: 'video/mp4', buffer: video });
        await page.getByRole('button', { name: 'Añadir cancel.mp4', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(1);
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        await expect(timeline.getByRole('button', { name: 'Reproducir', exact: true })).toBeEnabled();
        await page.route('**/brands/*/studio/assets/*/file', async route => { await new Promise(resolve => setTimeout(resolve, 900)); await route.continue(); });
        const request = page.waitForRequest(/\/brands\/\d+\/studio\/assets\/\d+\/file$/);
        await timeline.getByRole('button', { name: 'Reproducir', exact: true }).click();
        await request;
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        // Negative temporal assertion: wait beyond both possible 900ms range responses.
        await quietFor(page, 2200);
        await expect(timeline).toHaveCount(0);
        await expect(page.getByLabel('Previsualización de la línea de tiempo', { exact: true })).toBeHidden();
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        await expect(timeline.getByRole('button', { name: 'Reproducir', exact: true })).toBeVisible();
        await expect(timeline.getByLabel('Tiempo de reproducción', { exact: true })).toHaveValue('0');
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});
