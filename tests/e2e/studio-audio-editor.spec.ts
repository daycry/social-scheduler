import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, quietFor, seedComposerBrand, stamp } from './helpers';

function wave() {
    const buffer = Buffer.alloc(44 + 4 * 32000);
    buffer.write('RIFF'); buffer.writeUInt32LE(buffer.length - 8, 4); buffer.write('WAVEfmt ', 8); buffer.writeUInt32LE(16, 16);
    buffer.writeUInt16LE(1, 20); buffer.writeUInt16LE(1, 22); buffer.writeUInt32LE(16000, 24); buffer.writeUInt32LE(32000, 28);
    buffer.writeUInt16LE(2, 32); buffer.writeUInt16LE(16, 34); buffer.write('data', 36); buffer.writeUInt32LE(buffer.length - 44, 40);
    for (let i = 0; i < 64000; i++) buffer.writeInt16LE(Math.round(10000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
    return buffer;
}

test('Studio audio editor: upload, add, trim, split, undo, play, persist and reopen a private brand track', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-audio-editor-${stamp()}-${info.project.name}`);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        const id = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'voz-propia.wav', mimeType: 'audio/wav', buffer: wave() });
        await expect(page.getByRole('button', { name: 'Añadir voz-propia.wav', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Añadir voz-propia.wav', exact: true }).click();
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        await expect(timeline).toBeVisible();
        await expect(timeline.getByTestId('timeline-audio-track')).toHaveCount(1);
        await timeline.getByLabel('Recorte inicial del audio', { exact: true }).fill('0.5');
        await timeline.getByLabel('Recorte inicial del audio', { exact: true }).press('Tab');
        await timeline.getByLabel('Volumen del audio', { exact: true }).fill('0.4');
        await timeline.getByLabel('Volumen del audio', { exact: true }).press('Tab');
        await timeline.getByLabel('Velocidad del audio', { exact: true }).fill('1.25');
        await timeline.getByLabel('Velocidad del audio', { exact: true }).press('Tab');
        await timeline.getByLabel('Fundido de entrada', { exact: true }).fill('0.25');
        await timeline.getByLabel('Fundido de entrada', { exact: true }).press('Tab');
        await timeline.getByLabel('Fundido de salida', { exact: true }).fill('0.5');
        await timeline.getByLabel('Fundido de salida', { exact: true }).press('Tab');
        await timeline.getByLabel('Repetir audio', { exact: true }).check();
        await timeline.getByLabel('Silenciar audio', { exact: true }).check();
        await timeline.getByLabel('Silenciar audio', { exact: true }).uncheck();
        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        await clock.fill('1'); await clock.press('Tab');
        await timeline.getByRole('button', { name: 'Dividir audio', exact: true }).click();
        await expect(timeline.getByTestId('timeline-audio-track')).toHaveCount(2);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(timeline.getByTestId('timeline-audio-track')).toHaveCount(1);
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
        await expect(timeline.getByTestId('timeline-audio-track')).toHaveCount(2);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const read = () => page.evaluate(async id => (await (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).json()).design, id);
        const before = await read();
        expect(before.document.timeline.audio_tracks).toHaveLength(2);
        expect(before.document.timeline.audio_tracks[0]).toMatchObject({ trimStart: 0.5, volume: 0.4, duration: 1, playbackRate: 1.25, loop: true, muted: false });
        expect(before.document.timeline.audio_tracks[0].gainPoints).toEqual([{ time: 0, value: 0 }, { time: 0.25, value: 1 }, { time: 1, value: 1 }]);
        await timeline.getByRole('button', { name: 'Reproducir', exact: true }).click();
        await expect.poll(async () => Number(await clock.inputValue())).toBeGreaterThan(1.1);
        await timeline.getByRole('button', { name: 'Pausar', exact: true }).click();
        const paused = await clock.inputValue(); await quietFor(page, 100); /* negativa: en pausa el reloj no avanza */ expect(await clock.inputValue()).toBe(paused);
        expect((await read()).document).toEqual(before.document);
        await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
        await page.screenshot({ path: info.outputPath('audio-timeline.png'), fullPage: true });
        await page.reload(); await expect(page.getByTestId('timeline-audio-track')).toHaveCount(2);
        expect((await read()).document).toEqual(before.document);
        await page.getByTestId('timeline-audio-track').first().click();
        await page.getByRole('button', { name: 'Eliminar audio', exact: true }).click();
        await expect(page.getByTestId('timeline-audio-track')).toHaveCount(1);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(page.getByTestId('timeline-audio-track')).toHaveCount(2);
        await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio audio editor: decimal page duration uses document bounds rather than rounded frame bounds', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name); cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-audio-decimal-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const design = await page.evaluate(async brandId => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/designs`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Decimal audio', document: { schema_version: 2, width: 320, height: 180, timeline: { fps: 24, audio_tracks: [] },
                    pages: [{ id: 'page', duration: 1.2, background: '#ffffff', elements: [] }] } }) });
            if (!response.ok) throw new Error(await response.text());
            return (await response.json()).design;
        }, brand.id);
        await page.goto(`/studio/designs/${design.id}/edit`);
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'decimal.wav', mimeType: 'audio/wav', buffer: wave() });
        await page.getByRole('button', { name: 'Añadir decimal.wav', exact: true }).click();
        await expect(page.getByTestId('timeline-audio-track')).toHaveCount(1);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const stored = await page.evaluate(async id => (await (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).json()).design.document, design.id);
        expect(stored.timeline.audio_tracks[0].duration).toBeCloseTo(1.2, 9);
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});
