import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

const near = (pixel: number[], expected: number[], tolerance = 12) => pixel.every((value, i) => Math.abs(value - expected[i]) <= tolerance);

test('Studio object animation: animate an object, see it in two instants of the preview and export the frame that matches', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-animation-${stamp()}-${info.project.name}`);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const design = await page.evaluate(async brandId => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/designs`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Animado', document: { schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: [] }, pages: [
                    { id: 'one', name: 'Una', duration: 4, background: '#ffffff', elements: [{ id: 'red', type: 'rect', name: 'Cuadro rojo', x: 0, y: 0, width: 100, height: 100, fill: '#ff0000' }] },
                ] } }) });
            if (!response.ok) throw new Error(await response.text());
            return (await response.json()).design;
        }, brand.id);
        await page.goto(`/studio/designs/${design.id}/edit`);
        const timeline = page.getByRole('region', { name: 'Línea de tiempo', exact: true });
        await expect(timeline).toBeVisible();
        await page.screenshot({ path: info.outputPath('object-animation-initial.png'), fullPage: true });
        await timeline.getByRole('button', { name: 'Seleccionar clip Cuadro rojo', exact: true }).click();
        // Sin animación declarada, el objeto sigue como antes.
        await expect(timeline.getByTestId('timeline-animation-in')).toHaveCount(0);
        await timeline.getByLabel('Entrada', { exact: true }).selectOption('fade');
        const duration = timeline.getByLabel('Duración de la entrada (s)', { exact: true });
        await duration.fill('1'); await duration.press('Tab');
        await timeline.getByLabel('Curva de la entrada', { exact: true }).selectOption({ label: 'Lineal' });
        await expect(timeline.getByTestId('timeline-animation-in')).toHaveCount(1);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const stored = await page.evaluate(async id => (await (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).json()).design.document, design.id);
        expect(stored.pages[0].elements[0].animation).toEqual({ in: { type: 'fade', duration: 1, easing: 'linear' } });

        const clock = timeline.getByLabel('Tiempo de reproducción', { exact: true });
        const preview = page.getByLabel('Previsualización de la línea de tiempo', { exact: true });
        const pixel = () => preview.evaluate(canvas => [...(canvas as HTMLCanvasElement).getContext('2d')!.getImageData(50, 50, 1, 1).data]);
        // El reloj parte de 0: el primer instante debe cambiar para que se genere la previsualización.
        const instants = [[2, [255, 0, 0, 255]], [0.5, [255, 128, 128, 255]], [0, [255, 255, 255, 255]]] as const;
        for (const [second, expected] of instants) {
            await clock.fill(String(second)); await clock.press('Tab');
            await expect.poll(async () => near(await pixel(), [...expected]), { message: `previsualización en ${second} s` }).toBe(true);
        }
        await page.screenshot({ path: info.outputPath('object-animation-preview.png'), fullPage: true });

        // El fotograma exportado en el instante elegido coincide con la previsualización.
        await clock.fill('0.5'); await clock.press('Tab');
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        const time = dialog.getByLabel('Fotograma de Una (s)', { exact: true });
        await expect(time).toHaveValue('0.5');
        for (const [second, expected] of [[0.5, [255, 128, 128, 255]], [2, [255, 0, 0, 255]]] as const) {
            await time.fill(String(second));
            const registration = page.waitForResponse(response => response.url().endsWith(`/studio/designs/${design.id}/exports`) && response.request().method() === 'POST');
            await dialog.getByRole('button', { name: 'Guardar en biblioteca', exact: true }).click();
            const response = await registration; expect(response.status()).toBe(201);
            const result = (await response.json()).export;
            const exported = await page.evaluate(async ({ brandId, mediaId }) => {
                const data = await (await fetch(`/brands/${brandId}/studio/assets`, { headers: { Accept: 'application/json' } })).json();
                const asset = data.assets.find((asset: { id: number }) => asset.id === mediaId);
                const bitmap = await createImageBitmap(await (await fetch(asset.preview_url)).blob());
                try { const canvas = document.createElement('canvas'); canvas.width = canvas.height = 100; const context = canvas.getContext('2d')!;
                    context.drawImage(bitmap, 0, 0); return [...context.getImageData(50, 50, 1, 1).data]; }
                finally { bitmap.close(); }
            }, { brandId: brand.id, mediaId: result.media_ids[0] });
            expect(near(exported, [...expected]), `fotograma exportado en ${second} s: ${exported}`).toBe(true);
            await expect(dialog.getByRole('status')).toContainText('biblioteca');
            await page.screenshot({ path: info.outputPath(`object-animation-exported-${second}s.png`), fullPage: true });
        }
        await dialog.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});
