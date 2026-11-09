import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

async function call(page: Page, method: string, url: string, body?: unknown) {
    return page.evaluate(async ({ method, url, body }) => {
        const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
        const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        if (!response.ok) throw new Error(`${response.status}: ${await response.text()}`);
        return response.json();
    }, { method, url, body });
}
const blank = () => ({ schema_version: 1, width: 320, height: 180, pages: [{ id: 'one', name: 'Una', duration: 5, background: '#000000', elements: [] }] });
async function stored(page: Page, id: number) { return (await call(page, 'GET', `/studio/designs/${id}`)).design; }
async function captionPreviewCoverage(page: Page) {
    return page.getByLabel('Previsualización de la línea de tiempo').evaluate((canvas: HTMLCanvasElement) => {
        const data = canvas.getContext('2d')!.getImageData(0, 0, canvas.width, canvas.height).data;
        let white = 0, energy = 0;
        for (let i = 0; i < data.length; i += 4) {
            const light = Math.min(data[i], data[i + 1], data[i + 2]);
            if (light >= 200) white++;
            energy += light / 255;
        }
        return { white, energy, background: [...data.slice(0, 3)], width: canvas.width, height: canvas.height };
    });
}

test('Studio captions UI: import edit style offset delete undo reopen and download SRT VTT with clear file errors', async ({ page }, info) => {
    const user = e2eUser(info.project.name); cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `captions-ui-${stamp()}-${info.project.name}`), errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const design = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: 'Subtítulos UI', document: blank() })).design;
        await page.goto(`/studio/designs/${design.id}/edit`);
        await page.getByRole('button', { name: 'Línea de tiempo', exact: true }).click();
        const panel = page.getByRole('region', { name: 'Subtítulos', exact: true });
        await expect(panel).toBeVisible();
        await expect.poll(async () => (await stored(page, design.id)).document.schema_version).toBe(2);
        const text = '1\r\n00:00:00,250 --> 00:00:00,750\r\nHola 🎵\r\nsegunda línea\r\n\r\n2\r\n00:00:00,750 --> 00:00:01,250\r\nAdiós\r\n';
        await panel.getByLabel('Importar subtítulos').setInputFiles({ name: 'own.srt', mimeType: 'application/x-subrip', buffer: Buffer.from(text) });
        await expect(panel.getByTestId('caption-row')).toHaveCount(2);
        await panel.getByTestId('caption-row').first().click();
        const input = panel.getByLabel('Texto del subtítulo', { exact: true });
        await input.fill('Modificado 🎵\nsegunda línea'); await input.press('Tab');
        await expect.poll(async () => (await stored(page, design.id)).document.timeline.captions?.cues[0]?.text).toBe('Modificado 🎵\nsegunda línea');
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(input).toHaveValue('Hola 🎵\nsegunda línea');
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
        await expect(input).toHaveValue('Modificado 🎵\nsegunda línea');
        await panel.getByText('Estilo de subtítulos', { exact: true }).click();
        await panel.getByLabel('Tamaño', { exact: true }).fill('22'); await panel.getByLabel('Tamaño', { exact: true }).press('Tab');
        await panel.getByLabel('Desplazar toda la pista (segundos)', { exact: true }).fill('0.1');
        await panel.getByRole('button', { name: 'Aplicar desplazamiento', exact: true }).click();
        await panel.getByTestId('caption-row').last().click();
        await panel.getByRole('button', { name: 'Eliminar subtítulo', exact: true }).click();
        await expect(panel.getByTestId('caption-row')).toHaveCount(1);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(panel.getByTestId('caption-row')).toHaveCount(2);
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect.poll(async () => (await stored(page, design.id)).document.timeline.captions.style.fontSize).toBe(22);
        await expect.poll(async () => (await stored(page, design.id)).document.timeline.captions.cues.length).toBe(2);
        await page.reload(); await expect(panel.getByTestId('caption-row')).toHaveCount(2);
        await panel.getByTestId('caption-row').first().click(); await expect(input).toHaveValue('Modificado 🎵\nsegunda línea');
        await panel.getByRole('button', { name: 'Ir al subtítulo', exact: true }).click();
        await expect(page.getByLabel('Previsualización de la línea de tiempo')).toBeVisible();
        // The colored emoji alone must never satisfy legibility at the actual UI defaults.
        await expect.poll(async () => (await captionPreviewCoverage(page)).white).toBeGreaterThan(500);
        fs.writeFileSync(info.outputPath('captions-ui-contrast.json'), JSON.stringify(await captionPreviewCoverage(page), null, 2));
        for (const format of ['SRT', 'VTT']) {
            const [download] = await Promise.all([page.waitForEvent('download'), panel.getByRole('button', { name: `Descargar ${format}`, exact: true }).click()]);
            await download.saveAs(info.outputPath(`captions.${format.toLowerCase()}`));
            const output = fs.readFileSync((await download.path())!, 'utf8');
            expect(output).toContain('Modificado 🎵\nsegunda línea'); expect(output).toContain(format === 'SRT' ? '00:00:00,350' : '00:00:00.350');
        }
        const before = await stored(page, design.id);
        await panel.getByLabel('Importar subtítulos').setInputFiles({ name: 'bad.srt', mimeType: 'application/x-subrip', buffer: Buffer.from([255]) });
        await expect(panel.getByRole('alert')).toBeVisible();
        expect((await stored(page, design.id)).document).toEqual(before.document);
        await expectNoOverflow(page); expect(errors).toEqual([]);
        await page.screenshot({ path: info.outputPath('captions-editor.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});

test('Studio captions render: private font pinned MP4 GIF and SVG retain opaque cues across page fade', async ({ page }, info) => {
    test.setTimeout(180_000);
    const user = e2eUser(info.project.name); cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `captions-render-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const font = await page.evaluate(async ({ brandId, bytes }) => {
            const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const form = new FormData(); form.append('file', new Blob([new Uint8Array(bytes)], { type: 'font/woff2' }), 'Own.woff2'); form.append('name', 'Captions propia');
            const response = await fetch(`/brands/${brandId}/studio/fonts`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, body: form });
            if (!response.ok) throw new Error(await response.text()); return (await response.json()).font;
        }, { brandId: brand.id, bytes: [...fs.readFileSync('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2')] });
        const sceneDocument = { schema_version: 2, width: 160, height: 120, timeline: { fps: 12, audio_tracks: [], captions: {
            style: { font_id: font.id, fontFamily: font.family, fontSize: 24, strokeWidth: 0, fill: '#ffffff' }, cues: [{ id: 'caption', start: 0.25, end: 1.25, text: 'Hola' }],
        } }, pages: [{ id: 'a', name: 'A', duration: 1, background: '#000000', elements: [], transition: { type: 'fade', duration: 0.5 } }, { id: 'b', name: 'B', duration: 1, background: '#000000', elements: [] }] };
        const design = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: 'Subtítulos render', document: sceneDocument })).design;
        const outputs: any[] = [];
        for (const format of ['mp4', 'gif', 'svg', 'png', 'pdf']) {
            const result = (await call(page, 'POST', `/studio/designs/${design.id}/render`, { revision: 1, format, pages: ['a', 'b'], ...(!['mp4', 'gif'].includes(format) ? { frame_times: [{ page_id: 'a', time: 0.5 }, { page_id: 'b', time: 0.25 }] } : {}) })).export;
            outputs.push(result);
        }
        await call(page, 'PATCH', `/studio/designs/${design.id}/captions`, { action: 'clear', revision: 1 });
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio,media','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        const measurements: any[] = [];
        for (const result of outputs) {
            const current = (await call(page, 'GET', `/studio/exports/${result.id}`)).export;
            expect(current.status).toBe('ready'); expect(current.revision).toBe(1);
            const file = await page.request.get(current.download_url); expect(file.ok()).toBe(true);
            const filename = info.outputPath(`captions-${result.format}.${['svg', 'png'].includes(result.format) ? 'zip' : result.format}`); fs.writeFileSync(filename, await file.body());
            if (result.format === 'svg') {
                const files = JSON.parse(execFileSync('python', ['-I', '-c', 'import json,sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print(json.dumps([z.read(n).decode("utf-8") for n in z.namelist()]))', filename], { encoding: 'utf8', timeout: 15000 }));
                expect(files).toHaveLength(2);
                for (const svg of files) { expect(svg).toContain('Hola'); expect(svg).toContain('@font-face'); expect(svg).toContain('data:font/woff2;base64,'); }
                continue;
            }
            if (result.format === 'png') {
                const files = JSON.parse(execFileSync('python', ['-I', '-c', 'import base64,json,sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print(json.dumps([base64.b64encode(z.read(n)).decode("ascii") for n in z.namelist()]))', filename], { encoding: 'utf8', timeout: 15000 }));
                expect(files).toHaveLength(2);
                for (const [index, encoded] of files.entries()) {
                    const bytes = Buffer.from(encoded, 'base64'); fs.writeFileSync(info.outputPath(`caption-frame-${index}.png`), bytes);
                    const rgb = execFileSync('ffmpeg', ['-v', 'error', '-i', 'pipe:0', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { input: bytes, timeout: 15000 });
                    expect(rgb.length).toBe(160 * 120 * 3);
                    let whites = 0; for (let i = 0; i < rgb.length; i += 3) if (rgb[i] > 200 && rgb[i + 1] > 200 && rgb[i + 2] > 200) whites++;
                    expect(whites).toBeGreaterThan(40); measurements.push({ format: 'png', page: index, whites });
                }
                continue;
            }
            if (result.format === 'pdf') {
                const code = "import sys,json; sys.path.insert(0,'C:/laragon/www/metricool/storage/framework/testing/t43-pdf-tools'); import fitz; d=fitz.open(sys.argv[1]); a=[]\nfor p in d:\n x=p.get_pixmap(matrix=fitz.Matrix(96/72,96/72),alpha=False); b=x.samples; a.append({'width':x.width,'height':x.height,'whites':sum(1 for i in range(0,len(b),3) if b[i]>200 and b[i+1]>200 and b[i+2]>200)})\nprint(json.dumps(a))";
                const pixels = JSON.parse(execFileSync('python', ['-c', code, filename], { encoding: 'utf8', timeout: 15000 }));
                expect(pixels).toHaveLength(2);
                for (const pixelsPage of pixels) { expect(pixelsPage.width).toBe(160); expect(pixelsPage.height).toBe(120); expect(pixelsPage.whites).toBeGreaterThan(40); }
                measurements.push({ format: 'pdf', pages: pixels }); continue;
            }
            const probe = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-count_frames', '-show_streams', '-of', 'json', filename], { encoding: 'utf8', timeout: 15000 }));
            expect(probe.streams[0]).toMatchObject({ codec_name: result.format === 'mp4' ? 'h264' : 'gif', width: 160, height: 120, nb_read_frames: '18' });
            const raw = execFileSync('ffmpeg', ['-v', 'error', '-i', filename, '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 20000, maxBuffer: 4 * 1024 * 1024 });
            const frameBytes = 160 * 120 * 3;
            const whites = (frame: number) => { let count = 0; for (let i = frame * frameBytes; i < (frame + 1) * frameBytes; i += 3) if (raw[i] > 200 && raw[i + 1] > 200 && raw[i + 2] > 200) count++; return count; };
            expect(whites(0)).toBe(0); expect(whites(4)).toBeGreaterThan(40); expect(whites(15)).toBe(0);
            expect(Math.abs(whites(4) - whites(9))).toBeLessThanOrEqual(8);
            const journal = JSON.parse(artisan(`$w=App\\Models\\Studio\\StudioAvWorkspace::withoutUserScope()->where('export_id',${result.id})->firstOrFail(); echo json_encode(['fontIds'=>$w->plan['fontIds'],'fonts'=>$w->source_metadata['fonts']]);`));
            expect(journal.fontIds).toContain(font.id); expect(journal.fonts.find((entry: any) => entry.id === font.id).sha256).toBe(font.sha256);
            measurements.push({ format: result.format, probe, white_counts: [0, 4, 9, 15].map(whites), revision: current.revision, journal });
        }
        fs.writeFileSync(info.outputPath('captions-native-measurements.json'), JSON.stringify(measurements, null, 2));
        expect((await stored(page, design.id)).revision).toBe(2);
        // Independent default-style scene: preserve the private-font/stroke0 checks above.
        // Compare actual public preview/downloads against an explicitly unoutlined control.
        const contrastResults: any[] = [];
        for (const outlined of [false, true]) {
            const doc = { schema_version: 2, width: 320, height: 180, timeline: { fps: 12, audio_tracks: [], captions: {
                style: { fontFamily: 'Arial', fontSize: 22, fontWeight: 'normal', fontStyle: 'normal', fill: '#ffffff', stroke: '#000000', ...(!outlined ? { strokeWidth: 0 } : {}) },
                cues: [{ id: 'contrast', start: 0.25, end: 1.25, text: 'Modificado\nsegunda línea' }],
            } }, pages: [{ id: 'contrast_page', name: 'Contraste', duration: 1.5, background: '#000000', elements: [] }] };
            const contrast = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: `Contraste ${outlined ? 'contorno' : 'control'}`, document: doc })).design;
            const original = await stored(page, contrast.id);
            await page.goto(`/studio/designs/${contrast.id}/edit`);
            const timelineButton = page.getByRole('button', { name: 'Línea de tiempo', exact: true });
            await expect(timelineButton).toBeEnabled();
            if (await timelineButton.getAttribute('aria-pressed') !== 'true') await timelineButton.click();
            await page.getByTestId('caption-row').first().click();
            await page.getByRole('button', { name: 'Ir al subtítulo', exact: true }).click();
            await expect.poll(async () => (await captionPreviewCoverage(page)).white).toBeGreaterThan(500);
            const preview = await captionPreviewCoverage(page);
            expect(preview.width).toBe(320); expect(preview.height).toBe(180); expect(preview.background).toEqual([0, 0, 0]);
            await page.screenshot({ path: info.outputPath(`captions-contrast-${outlined ? 'outline' : 'control'}.png`), fullPage: true });
            expect((await stored(page, contrast.id)).document).toEqual(original.document);
            const exports: any[] = [];
            for (const format of ['png', 'mp4', 'gif']) exports.push((await call(page, 'POST', `/studio/designs/${contrast.id}/render`, {
                revision: original.revision, format, pages: ['contrast_page'], ...(format === 'png' ? { frame_times: [{ page_id: 'contrast_page', time: 0.5 }] } : {}),
            })).export);
            expect((await stored(page, contrast.id)).document).toEqual(original.document);
            // Change live source after enqueue: every downloaded artifact must retain the pinned cue.
            await call(page, 'PATCH', `/studio/designs/${contrast.id}/captions`, { action: 'clear', revision: original.revision });
            const cleared = await stored(page, contrast.id);
            artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio,media','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
            const sample: any = { outlined, preview, outputs: [] };
            for (const exported of exports) {
                const current = (await call(page, 'GET', `/studio/exports/${exported.id}`)).export;
                expect(current.status).toBe('ready'); expect(current.revision).toBe(original.revision);
                const response = await page.request.get(current.download_url); expect(response.ok()).toBe(true);
                const filename = info.outputPath(`contrast-${outlined ? 'outline' : 'control'}.${exported.format}`);
                fs.writeFileSync(filename, await response.body());
                const raw = execFileSync('ffmpeg', ['-v', 'error', '-i', filename, '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 20000, maxBuffer: 4 * 1024 * 1024 });
                const frameBytes = 320 * 180 * 3, frame = exported.format === 'png' ? 0 : 6;
                expect(raw.length).toBe(frameBytes * (exported.format === 'png' ? 1 : 18));
                let white = 0, energy = 0;
                for (let i = frame * frameBytes; i < (frame + 1) * frameBytes; i += 3) {
                    const light = Math.min(raw[i], raw[i + 1], raw[i + 2]); if (light >= 200) white++; energy += light / 255;
                }
                expect([...raw.subarray(frame * frameBytes, frame * frameBytes + 3)]).toEqual([0, 0, 0]);
                if (exported.format !== 'png') {
                    let inactiveWhite = 0;
                    for (let i = 0; i < frameBytes; i += 3) if (Math.min(raw[i], raw[i + 1], raw[i + 2]) >= 200) inactiveWhite++;
                    expect(inactiveWhite).toBe(0);
                }
                sample.outputs.push({ format: exported.format, frame, white, energy, revision: current.revision });
            }
            expect((await stored(page, contrast.id)).document).toEqual(cleared.document);
            contrastResults.push(sample);
        }
        fs.writeFileSync(info.outputPath('captions-default-contrast-measurements.json'), JSON.stringify(contrastResults, null, 2));
        const [control, outline] = contrastResults;
        expect(control.preview.white).toBeGreaterThan(1000);
        expect(outline.preview.energy).toBeGreaterThanOrEqual(control.preview.energy * 0.5);
        for (const baseline of control.outputs) {
            const actual = outline.outputs.find((output: any) => output.format === baseline.format);
            expect(baseline.white).toBeGreaterThan(500);
            expect(actual.white).toBeGreaterThanOrEqual(baseline.white * 0.5);
            expect(actual.energy).toBeGreaterThanOrEqual(baseline.energy * 0.5);
        }
        // A new source has the required late cue after the canonical fade midpoint.
        // Selections below go through the actual dialog controls and public worker queue.
        const selectionDocument = { ...sceneDocument, timeline: { ...sceneDocument.timeline, fps: 30, captions: { style: sceneDocument.timeline.captions.style,
            cues: [{ id: 'late_a', start: 1.8, end: 1.95, text: 'AA' }, { id: 'on_c', start: 3.8, end: 4, text: 'CCCCCCCC' }] } },
            pages: [{ id: 'a', name: 'A', duration: 2, background: '#880000', elements: [], transition: { type: 'fade', duration: 0.5 } },
                { id: 'b', name: 'B', duration: 2, background: '#008800', elements: [] }, { id: 'c', name: 'C', duration: 2, background: '#000088', elements: [] }] };
        const selectionDesign = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: 'Selección subtítulos', document: selectionDocument })).design;
        const selectionResults: any[] = [];
        for (const ids of [['a'], ['c', 'a'], ['a', 'c']]) {
            await page.goto(`/studio/designs/${selectionDesign.id}/edit`);
            await page.getByRole('button', { name: 'Exportar', exact: true }).click();
            const dialog = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
            await dialog.getByLabel('Formato', { exact: true }).selectOption('mp4');
            for (const id of ['a', 'b', 'c']) await dialog.locator(`input[type="checkbox"][value="${id}"]`).setChecked(ids.includes(id));
            if (ids[0] === 'c') { await dialog.getByRole('button', { name: 'Subir página C', exact: true }).click(); await dialog.getByRole('button', { name: 'Subir página C', exact: true }).click(); }
            const response = page.waitForResponse(response => response.url().endsWith(`/studio/designs/${selectionDesign.id}/render`) && response.request().method() === 'POST');
            await dialog.getByRole('button', { name: 'Preparar MP4', exact: true }).click();
            const exported = (await (await response).json()).export; expect(exported.pages).toEqual(ids);
            artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
            const current = (await call(page, 'GET', `/studio/exports/${exported.id}`)).export; expect(current.status).toBe('ready');
            const path = info.outputPath(`captions-selection-${ids.join('-')}.mp4`); fs.writeFileSync(path, await (await page.request.get(current.download_url)).body());
            const raw = execFileSync('ffmpeg', ['-v', 'error', '-i', path, '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 20000, maxBuffer: 8 * 1024 * 1024 });
            const frameBytes = 160 * 120 * 3, counts = [];
            for (let frame = 0; frame < raw.length / frameBytes; frame++) { let white = 0; for (let i = frame * frameBytes; i < (frame + 1) * frameBytes; i += 3) if (raw[i] > 200 && raw[i + 1] > 200 && raw[i + 2] > 200) white++; counts.push(white); }
            if (ids.length === 1) { expect(counts).toHaveLength(60); expect(counts[55]).toBeGreaterThan(20); expect(counts[58]).toBeGreaterThan(20); expect(counts[59]).toBe(0); }
            else if (ids[0] === 'c') { expect(counts).toHaveLength(120); expect(counts[10]).toBeGreaterThan(40); expect(counts[115]).toBeGreaterThan(20); expect(counts[119]).toBe(0); expect(raw[2]).toBeGreaterThan(100); expect(raw[raw.length - 3]).toBeGreaterThan(100); }
            else { expect(counts).toHaveLength(105); expect(counts[56]).toBeGreaterThan(40); }
            selectionResults.push({ ids, counts });
        }
        expect(selectionResults[2].counts[56]).toBeGreaterThan(selectionResults[0].counts[56] * 2);
        fs.writeFileSync(info.outputPath('captions-selection-measurements.json'), JSON.stringify(selectionResults, null, 2));
    } finally { cleanComposerBrands(user.email); }
});

test('Studio captions access: revision pinned API MCP abilities ownership and optimistic conflicts', async ({ page }, info) => {
    const user = e2eUser(info.project.name); cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `captions-access-${stamp()}-${info.project.name}`);
    const tokenName = `captions-e2e-${stamp()}`;
    let foreignUserId: number | undefined;
    try {
        await loginAs(page, user); await page.goto(`/studio?brand_id=${brand.id}`);
        const doc = { ...blank(), schema_version: 2, timeline: { fps: 30, audio_tracks: [], captions: { style: { fontSize: 22 }, cues: [{ id: 'cue', start: 0.5, end: 1.5, text: 'Revision original 🎵' }] } } };
        const design = (await call(page, 'POST', `/brands/${brand.id}/studio/designs`, { name: 'Acceso captions', document: doc })).design;
        const keys = JSON.parse(artisan(`$u=App\\Models\\User::where('email',${JSON.stringify(user.email)})->firstOrFail(); $f=App\\Models\\User::factory()->create(); echo json_encode(['read'=>$u->createToken(${JSON.stringify(tokenName)},['designs:read'])->plainTextToken,'write'=>$u->createToken(${JSON.stringify(tokenName)},['designs:write'])->plainTextToken,'foreign'=>$f->createToken(${JSON.stringify(tokenName)},['designs:read'])->plainTextToken,'foreign_user_id'=>$f->id]);`));
        foreignUserId = keys.foreign_user_id;
        const url = `/api/v1/designs/${design.id}/captions`;
        expect((await page.request.get(url, { headers: { Authorization: `Bearer ${keys.write}` } })).status()).toBe(403);
        expect((await page.request.patch(url, { headers: { Authorization: `Bearer ${keys.read}` }, data: { action: 'clear', revision: 1 } })).status()).toBe(403);
        expect((await page.request.get(url, { headers: { Authorization: `Bearer ${keys.foreign}` } })).status()).toBe(404);
        const changed = await page.request.patch(url, { headers: { Authorization: `Bearer ${keys.write}` }, data: { action: 'offset', revision: 1, seconds: 1 } });
        expect(changed.status()).toBe(200);
        expect((await page.request.patch(url, { headers: { Authorization: `Bearer ${keys.write}` }, data: { action: 'clear', revision: 1 } })).status()).toBe(409);
        const original = await page.request.get(`${url}/export?revision=1&format=vtt`, { headers: { Authorization: `Bearer ${keys.read}` } });
        expect(await original.text()).toContain('00:00:00.500 --> 00:00:01.500');
        const rpc = await page.request.post('/mcp', { headers: { Authorization: `Bearer ${keys.read}`, Accept: 'application/json, text/event-stream' }, data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'manage_design_captions', arguments: { design_id: design.id, action: 'get', revision: 1, per_page: 1 } } } });
        expect(rpc.ok()).toBe(true); const result = (await rpc.json()).result; expect(result.isError ?? false).toBe(false);
        expect(JSON.parse(result.content[0].text).cues[0].start).toBe(0.5);
        await expectNoOverflow(page);
    } finally {
        artisan(`$u=App\\Models\\User::where('email',${JSON.stringify(user.email)})->first(); if($u) $u->tokens()->where('name',${JSON.stringify(tokenName)})->delete();`);
        if (foreignUserId) artisan(`App\\Models\\User::whereKey(${foreignUserId})->delete();`);
        cleanComposerBrands(user.email);
    }
});
