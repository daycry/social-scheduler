import { expect, test } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test('Studio frame export: choose a local page time, export real pixels and retain it with the private brand output', async ({ page }, info) => {
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-frame-${stamp()}-${info.project.name}`);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const design = await page.evaluate(async brandId => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/designs`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Fotograma', document: { schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: [] }, pages: [
                    { id: 'one', name: 'Una', duration: 4, background: '#ffffff', elements: [{ id: 'red', type: 'rect', x: 0, y: 0, width: 60, height: 60, fill: '#ff0000', timing: { start: 1, duration: 1 } }] },
                ] } }) });
            if (!response.ok) throw new Error(await response.text());
            return (await response.json()).design;
        }, brand.id);
        await page.goto(`/studio/designs/${design.id}/edit`);
        const clock = page.getByLabel('Tiempo de reproducción', { exact: true });
        await clock.fill('1.5'); await clock.press('Tab');
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        const time = dialog.getByLabel('Fotograma de Una (s)', { exact: true });
        await expect(time).toHaveValue('1.5');
        for (const [second, expected] of [[0, [255, 255, 255, 255]], [1.5, [255, 0, 0, 255]]] as const) {
            await time.fill(String(second));
            const registration = page.waitForResponse(response => response.url().endsWith(`/studio/designs/${design.id}/exports`) && response.request().method() === 'POST');
            await dialog.getByRole('button', { name: 'Guardar en biblioteca', exact: true }).click();
            const response = await registration; expect(response.status()).toBe(201);
            const result = (await response.json()).export;
            expect(result.options.frame_times).toEqual([{ page_id: 'one', time: second }]);
            const pixel = await page.evaluate(async ({ brandId, mediaId }) => {
                const data = await (await fetch(`/brands/${brandId}/studio/assets`, { headers: { Accept: 'application/json' } })).json();
                const asset = data.assets.find((asset: {id: number}) => asset.id === mediaId);
                const bitmap = await createImageBitmap(await (await fetch(asset.preview_url)).blob());
                try { const canvas = document.createElement('canvas'); canvas.width = canvas.height = 100; const context = canvas.getContext('2d')!;
                    context.drawImage(bitmap, 0, 0); return [...context.getImageData(10, 10, 1, 1).data]; }
                finally { bitmap.close(); }
            }, { brandId: brand.id, mediaId: result.media_ids[0] });
            expect(pixel).toEqual(expected);
            await expect(dialog.getByRole('status')).toContainText('biblioteca');
        }
        const heading = dialog.getByRole('heading', { name: 'Exportar diseño', exact: true });
        await heading.scrollIntoViewIfNeeded();
        await expect(heading).toBeInViewport({ ratio: 0.99 });
        await page.screenshot({ path: info.outputPath('frame-export.png'), fullPage: true });
        const download = dialog.getByRole('button', { name: 'Descargar', exact: true });
        await download.scrollIntoViewIfNeeded();
        await expect(download).toBeInViewport({ ratio: 0.99 });
        await page.screenshot({ path: info.outputPath('frame-export-actions.png'), fullPage: true });
        await dialog.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        const stored = await page.evaluate(async id => (await (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).json()).design, design.id);
        expect(stored.document).toEqual(design.document);
        await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});
