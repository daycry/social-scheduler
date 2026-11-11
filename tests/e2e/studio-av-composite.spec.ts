import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';
import { stereoWave, measureCompositeAudio, assertCompositeAudio } from './support/studio-composite-audio.mjs';

function native(command: string, args: string[]) {
    return execFileSync(command, ['-v', 'error', ...args], { timeout: 20_000, maxBuffer: 8 * 1024 ** 2 });
}
function frame(file: string, time: number) {
    return native('ffmpeg', ['-i', file, '-ss', String(time), '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
}
function pixel(bytes: Buffer, x: number, y: number, width = 100) { return [...bytes.subarray((y * width + x) * 3, (y * width + x) * 3 + 3)]; }
function near(actual: number[], expected: number[], tolerance = 18) { actual.forEach((value, index) => expect(Math.abs(value - expected[index])).toBeLessThanOrEqual(tolerance)); }

test('Studio composite export: public uploads and revision preserve moving video overlays transition and measured two-tone audio through download and draft', async ({ page }, info) => {
    test.setTimeout(180_000);
    const user = e2eUser(info.project.name), errors: string[] = [];
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-composite-${stamp()}-${info.project.name}`);
    const video = native('ffmpeg', ['-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=12:duration=3', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=3',
        '-map', '0:v', '-map', '1:a', '-af', 'pan=stereo|c0=c0|c1=c0', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '128k', '-shortest', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1']);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'movimiento-440.mp4', mimeType: 'video/mp4', buffer: video });
        await expect(page.getByRole('button', { name: 'Añadir movimiento-440.mp4', exact: true })).toBeVisible();
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'musica-880.wav', mimeType: 'audio/wav', buffer: stereoWave() });
        await expect(page.getByRole('button', { name: 'Añadir musica-880.wav', exact: true })).toBeVisible();
        const design = await page.evaluate(async brandId => {
            const assets = (await (await fetch(`/brands/${brandId}/studio/assets`, { headers: { Accept: 'application/json' } })).json()).assets;
            const video = assets.find((asset: { name: string }) => asset.name === 'movimiento-440.mp4');
            const music = assets.find((asset: { name: string }) => asset.name === 'musica-880.wav');
            if (!video || !music) throw new Error('Publicly uploaded resources are missing');
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/designs`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Mezcla pública medida', document: { schema_version: 2, width: 100, height: 80,
                    timeline: { fps: 12, audio_tracks: [{ id: 'music', asset_id: music.id, start: 0.25, duration: 3, trimStart: 0.25, trimEnd: 1.25, loop: true, volume: 0.2,
                        gainPoints: [{ time: 0, value: 0 }, { time: 0.25, value: 1 }, { time: 2.5, value: 1 }, { time: 3, value: 0 }] }] },
                    pages: [
                        { id: 'red', name: 'Vídeo y verde', duration: 2, background: '#ff0000', transition: { type: 'fade', duration: 0.5 }, elements: [
                            { id: 'video', type: 'video', asset_id: video.id, x: 0, y: 0, width: 40, height: 40, trimStart: 0, trimEnd: 2, volume: 0.4, muted: false, timing: { start: 0, duration: 2 } },
                            { id: 'green', type: 'rect', x: 60, y: 10, width: 20, height: 20, fill: '#00ff00', timing: { start: 0.5, duration: 0.5 } },
                        ] },
                        { id: 'blue', name: 'Azul y amarillo', duration: 2, background: '#0000ff', elements: [
                            { id: 'yellow', type: 'rect', x: 60, y: 10, width: 20, height: 20, fill: '#ffff00', timing: { start: 0.5, duration: 0.5 } },
                        ] },
                    ] } }) });
            if (!response.ok) throw new Error(`Composite design ${response.status}: ${await response.text()}`);
            return (await response.json()).design;
        }, brand.id);
        await page.goto(`/studio/designs/${design.id}/edit`);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        await dialog.getByLabel('Formato', { exact: true }).selectOption('mp4');
        const submitted = page.waitForResponse(response => new URL(response.url()).pathname === `/studio/designs/${design.id}/render` && response.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Preparar MP4', exact: true }).click();
        const accepted = await submitted; expect(accepted.status()).toBe(202);
        const exported = (await accepted.json()).export; expect(exported.revision).toBe(design.revision);
        // Editing the current document after submission must not alter the queued immutable revision.
        const changed = await page.evaluate(async ({ design }) => {
            const documentData = structuredClone(design.document); documentData.pages[1].background = '#00ff00';
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/studio/designs/${design.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, body: JSON.stringify({ name: design.name, revision: design.revision, document: documentData }) });
            if (!response.ok) throw new Error(await response.text()); return (await response.json()).design;
        }, { design });
        expect(changed.revision).toBe(design.revision + 1);
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        const row = dialog.getByRole('region', { name: `Exportación ${exported.id}`, exact: true });
        await expect(row).toContainText('Lista');
        const ready = await page.evaluate(async id => (await (await fetch(`/studio/exports/${id}`, { headers: { Accept: 'application/json' } })).json()).export, exported.id);
        expect(ready).toMatchObject({ status: 'ready', revision: design.revision, brand_id: brand.id }); expect(ready.media_ids).toHaveLength(1);
        const [download] = await Promise.all([page.waitForEvent('download'), row.getByRole('link', { name: 'Descargar MP4', exact: true }).click()]);
        const mp4 = info.outputPath('composite.mp4'); await download.saveAs(mp4);
        const probe = JSON.parse(native('ffprobe', ['-count_frames', '-show_streams', '-of', 'json', mp4]).toString());
        const visual = probe.streams.find((stream: { codec_type: string }) => stream.codec_type === 'video');
        const audio = probe.streams.find((stream: { codec_type: string }) => stream.codec_type === 'audio');
        expect(visual).toMatchObject({ codec_name: 'h264', width: 100, height: 80, nb_read_frames: '42' });
        expect(Number(visual.duration)).toBeCloseTo(3.5, 3); expect(audio).toMatchObject({ codec_name: 'aac', sample_rate: '48000', channels: 2 });
        const early = frame(mp4, 0.25), green = frame(mp4, 0.75), fade = frame(mp4, 1.75), yellow = frame(mp4, 2.25), late = frame(mp4, 3.25);
        near(pixel(early, 70, 20), [255, 0, 0]); near(pixel(green, 70, 20), [0, 255, 0]);
        near(pixel(fade, 80, 60), [128, 0, 128]); near(pixel(yellow, 70, 20), [255, 255, 0]); near(pixel(late, 80, 60), [0, 0, 255]);
        let motion = 0;
        for (let y = 0; y < 40; y++) for (let x = 0; x < 40; x++) for (let c = 0; c < 3; c++) motion += (early[(y * 100 + x) * 3 + c] - green[(y * 100 + x) * 3 + c]) ** 2;
        const motionRms = Math.sqrt(motion / (40 * 40 * 3)); expect(motionRms).toBeGreaterThan(5);
        const pcm = native('ffmpeg', ['-i', mp4, '-vn', '-ac', '2', '-ar', '48000', '-f', 'f32le', 'pipe:1']);
        const tones = measureCompositeAudio(pcm);
        assertCompositeAudio(tones);
        await dialog.getByLabel('Formato', { exact: true }).selectOption('gif');
        await dialog.getByLabel('Escala', { exact: true }).selectOption('0.5');
        const gifResponse = page.waitForResponse(response => new URL(response.url()).pathname === `/studio/designs/${design.id}/render` && response.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Preparar GIF', exact: true }).click();
        const gifJob = (await (await gifResponse).json()).export; expect(gifJob.revision).toBe(design.revision);
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        await dialog.getByRole('button', { name: 'Actualizar exportaciones', exact: true }).click();
        const gifRow = dialog.getByRole('region', { name: `Exportación ${gifJob.id}`, exact: true }); await expect(gifRow).toContainText('Lista');
        const [gifDownload] = await Promise.all([page.waitForEvent('download'), gifRow.getByRole('link', { name: 'Descargar GIF', exact: true }).click()]);
        const gif = info.outputPath('composite.gif'); await gifDownload.saveAs(gif);
        const gifProbe = JSON.parse(native('ffprobe', ['-count_frames', '-show_streams', '-of', 'json', gif]).toString());
        expect(gifProbe.streams).toHaveLength(1); expect(gifProbe.streams[0]).toMatchObject({ codec_name: 'gif', width: 50, height: 40, nb_read_frames: '42' });
        expect(Number(gifProbe.streams[0].duration)).toBeCloseTo(3.5, 2);
        fs.writeFileSync(info.outputPath('measurements.json'), JSON.stringify({ revision: exported.revision, current_revision: changed.revision, probe, gifProbe, motionRms, tones,
            pixels: { before: pixel(early, 70, 20), overlay: pixel(green, 70, 20), fade: pixel(fade, 80, 60), yellow: pixel(yellow, 70, 20), final: pixel(late, 80, 60) } }, null, 2));
        await row.scrollIntoViewIfNeeded(); await page.screenshot({ path: info.outputPath('composite-ready.png'), fullPage: true });
        await row.getByRole('button', { name: 'Crear borrador', exact: true }).click(); await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        const post = JSON.parse(artisan("$p=App\\Models\\Post::withoutUserScope()->latest('id')->firstOrFail();echo json_encode(['brand_id'=>$p->brand_id,'status'=>$p->status->value,'media_ids'=>$p->media->pluck('id')->all()]);"));
        expect(post).toMatchObject({ brand_id: brand.id, status: 'borrador', media_ids: ready.media_ids });
        expect(post.media_ids).toHaveLength(1); await expectNoOverflow(page); expect(errors).toEqual([]);
    } finally { cleanComposerBrands(user.email); }
});
