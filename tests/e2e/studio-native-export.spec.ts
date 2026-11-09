import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test('Studio native export: order pages, resume history, cancel retry and download real MP4 GIF before a brand draft', async ({ page }, info) => {
    test.setTimeout(120_000);
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-native-${stamp()}-${info.project.name}`);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const design = await page.evaluate(async brandId => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/designs`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Secuencia nativa', document: { schema_version: 2, width: 100, height: 80, timeline: { fps: 12, audio_tracks: [] }, pages: [
                    { id: 'red', name: 'Roja', duration: 1, background: '#ff0000', elements: [] },
                    { id: 'blue', name: 'Azul', duration: 1, background: '#0000ff', elements: [] },
                ] } }) });
            if (!response.ok) throw new Error(await response.text());
            return (await response.json()).design;
        }, brand.id);
        await page.goto(`/studio/designs/${design.id}/edit`);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        await expect(dialog.getByLabel('Formato', { exact: true }).locator('option[value="mp4"]')).toHaveCount(1);
        await dialog.getByLabel('Formato', { exact: true }).selectOption('mp4');
        await expect(dialog.getByText('Fondo transparente', { exact: true })).toHaveCount(0);
        await expect(dialog.getByLabel('Fotograma de Roja (s)', { exact: true })).toHaveCount(0);
        await dialog.getByRole('button', { name: 'Subir página Azul', exact: true }).click();
        const created = page.waitForResponse(response => response.url().endsWith(`/studio/designs/${design.id}/render`) && response.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Preparar MP4', exact: true }).click();
        const initial = await created; expect(initial.status()).toBe(202);
        expect(initial.request().postDataJSON()).toMatchObject({ format: 'mp4', pages: ['blue', 'red'], transparent: false });
        expect(initial.request().postDataJSON()).not.toHaveProperty('frame_times');
        const first = (await initial.json()).export;
        const row = dialog.getByRole('region', { name: `Exportación ${first.id}`, exact: true });
        expect(first.status).toBe('processing');
        await expect(row).toContainText('Procesando');
        await row.getByRole('button', { name: 'Cancelar', exact: true }).click();
        await expect(row).toContainText('Cancelada');
        await dialog.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.reload();
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        await dialog.getByLabel('Formato', { exact: true }).selectOption('mp4');
        await expect(row).toContainText('Cancelada');
        await row.getByRole('button', { name: 'Reintentar', exact: true }).click();
        await expect(row).toContainText('Procesando');
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        await expect(row).toContainText('Lista');
        const [mp4] = await Promise.all([page.waitForEvent('download'), row.getByRole('link', { name: 'Descargar MP4', exact: true }).click()]);
        const mp4Path = await mp4.path(); expect(mp4Path).toBeTruthy();
        await mp4.saveAs(info.outputPath('sequence.mp4'));
        const probe = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-count_frames', '-show_streams', '-of', 'json', mp4Path!], { encoding: 'utf8' }));
        expect(probe.streams[0]).toMatchObject({ codec_name: 'h264', width: 100, height: 80, nb_read_frames: '24' });
        const frame = execFileSync('ffmpeg', ['-v', 'error', '-i', mp4Path!, '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
        expect(frame[2]).toBeGreaterThan(240); expect(frame[0]).toBeLessThan(15);
        const lastFrame = execFileSync('ffmpeg', ['-v', 'error', '-i', mp4Path!, '-ss', '1.2', '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
        expect(lastFrame[0]).toBeGreaterThan(240); expect(lastFrame[2]).toBeLessThan(15);
        await dialog.getByLabel('Formato', { exact: true }).selectOption('gif');
        const gifResponse = page.waitForResponse(response => response.url().endsWith(`/studio/designs/${design.id}/render`) && response.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Preparar GIF', exact: true }).click();
        const gif = (await (await gifResponse).json()).export;
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        const gifRow = dialog.getByRole('region', { name: `Exportación ${gif.id}`, exact: true });
        await expect(gifRow).toContainText('Lista');
        const [download] = await Promise.all([page.waitForEvent('download'), gifRow.getByRole('link', { name: 'Descargar GIF', exact: true }).click()]);
        await download.saveAs(info.outputPath('sequence.gif'));
        const gifProbe = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_streams', '-of', 'json', (await download.path())!], { encoding: 'utf8' }));
        expect(gifProbe.streams[0]).toMatchObject({ codec_name: 'gif', width: 100, height: 80 });
        fs.writeFileSync(info.outputPath('media-probe.json'), JSON.stringify({ mp4: probe, gif: gifProbe, first_pixel: [...frame.subarray(0, 3)], later_pixel: [...lastFrame.subarray(0, 3)] }, null, 2));
        await gifRow.scrollIntoViewIfNeeded();
        await page.screenshot({ path: info.outputPath('native-export-ready.png'), fullPage: true });
        await row.getByRole('button', { name: 'Crear borrador', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        const brandCheck = artisan(`echo json_encode(App\\Models\\Post::withoutUserScope()->latest('id')->first(['brand_id','status']));`);
        expect(JSON.parse(brandCheck)).toMatchObject({ brand_id: brand.id, status: 'borrador' });
        await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio native history: paginate real exports in one request per page and reopen without creating work', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-export-history-${stamp()}-${info.project.name}`);
    try {
        const design = JSON.parse(artisan(`$b=App\\Models\\Brand::withoutUserScope()->findOrFail(${brand.id}); $u=App\\Models\\User::findOrFail($b->user_id); $d=app(App\\Services\\Studio\\StudioService::class)->create($u,$b,['name'=>'Historial audiovisual','document'=>['schema_version'=>1,'width'=>100,'height'=>80,'pages'=>[['id'=>'one','name'=>'Una','background'=>'#ffffff','elements'=>[]]]]]); for($i=0;$i<55;$i++){App\\Models\\Studio\\DesignExport::create(['user_id'=>$u->id,'brand_id'=>$b->id,'design_id'=>$d->id,'revision'=>1,'format'=>'png','pages'=>['one'],'options'=>[],'media_ids'=>[],'status'=>'ready']);} echo json_encode(['id'=>$d->id]);`));
        await loginAs(page, user); await page.goto(`/studio/designs/${design.id}/edit`);
        const reads: string[] = [], writes: string[] = [];
        page.on('request', request => {
            if (new URL(request.url()).pathname !== `/studio/designs/${design.id}/exports`) return;
            (request.method() === 'GET' ? reads : writes).push(request.url());
        });
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        await dialog.getByLabel('Formato', { exact: true }).selectOption('mp4');
        const rows = dialog.getByRole('region', { name: /^Exportación \d+$/ });
        const pager = dialog.getByRole('navigation', { name: 'Páginas de exportaciones', exact: true });
        await expect(rows).toHaveCount(50); await expect(pager).toContainText('Página 1 de 2');
        await pager.getByRole('button', { name: 'Siguientes', exact: true }).click();
        await expect(rows).toHaveCount(5); await expect(pager).toContainText('Página 2 de 2');
        expect(reads).toHaveLength(2); expect(new URL(reads[1]).searchParams.get('page')).toBe('2');
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        await expect.poll(() => reads.length).toBe(3); await expect(rows).toHaveCount(5);
        await dialog.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.reload(); await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        await dialog.getByLabel('Formato', { exact: true }).selectOption('gif');
        await expect(rows).toHaveCount(50); await expect(pager).toContainText('Página 1 de 2');
        expect(writes).toEqual([]);
        await expectNoOverflow(page);
    } finally { cleanComposerBrands(user.email); }
});
