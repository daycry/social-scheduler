import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { cleanComposerBrands, quietFor } from './helpers';
import { setup, read, save, web, dataset, uploadFont, choose, configure, apply } from './support/studio-chart-ui';
import { armPresentationCloseObserver, readPresentationCloseObservation } from './support/studio-presentation-observer.mjs';

// Runtime-only fixture generation. The root's single Job must own Playwright and ffmpeg.
function fixtures(info: TestInfo) {
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=3', '-an', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-map_metadata', '-1', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20000, windowsHide: true, maxBuffer: 1024 * 1024 });
    const audio = Buffer.alloc(44 + 3 * 16000 * 2);
    audio.write('RIFF'); audio.writeUInt32LE(audio.length - 8, 4); audio.write('WAVEfmt ', 8); audio.writeUInt32LE(16, 16); audio.writeUInt16LE(1, 20); audio.writeUInt16LE(1, 22); audio.writeUInt32LE(16000, 24); audio.writeUInt32LE(32000, 28); audio.writeUInt16LE(2, 32); audio.writeUInt16LE(16, 34); audio.write('data', 36); audio.writeUInt32LE(audio.length - 44, 40);
    for (let i = 0; i < 48000; i++) audio.writeInt16LE(Math.round(10000 * Math.sin(2 * Math.PI * 440 * i / 16000)), 44 + i * 2);
    for (const [name, bytes] of [['own-video.mp4', video], ['own-audio.wav', audio]] as const) fs.writeFileSync(info.outputPath(name), bytes);
    fs.writeFileSync(info.outputPath('fixture-metadata.json'), JSON.stringify({ duration: 3, video_size: [64, 64], sample_rate: 16000, frequency: 440, hashes: [video, audio].map(bytes => crypto.createHash('sha256').update(bytes).digest('hex')) }, null, 2));
    return { video, audio };
}
const modal = (page: Page) => page.getByRole('dialog', { name: 'Presentación', exact: true });
const button = (page: Page, name: string) => modal(page).getByRole('button', { name, exact: true });
async function observe(page: Page) {
    await page.evaluate(() => {
        const w = window as any;
        const nodes: HTMLMediaElement[] = [], events: any[] = [], draws: any[] = [], ids = new WeakMap<object, number>(); let serial = 0;
        const id = (node: object) => { if (!ids.has(node)) ids.set(node, ++serial); return ids.get(node); };
        const record = (node: HTMLMediaElement, event: string, extra = {}) => events.push({ event, id: id(node), kind: node.tagName, src: node.currentSrc || node.src, time: node.currentTime, paused: node.paused, at: performance.now(), ...extra });
        const watch = (node: HTMLMediaElement) => {
            if (nodes.includes(node)) return; nodes.push(node);
            for (const name of ['loadedmetadata', 'loadeddata', 'play', 'playing', 'pause', 'ended', 'error', 'timeupdate', 'emptied']) node.addEventListener(name, () => record(node, name));
            if (node instanceof HTMLVideoElement && node.requestVideoFrameCallback) {
                const frame = (_now: number, meta: VideoFrameCallbackMetadata) => { record(node, 'frame', { mediaTime: meta.mediaTime, presentedFrames: meta.presentedFrames }); if (!w.__presentationProbe.closed) node.requestVideoFrameCallback(frame); };
                node.requestVideoFrameCallback(frame);
            }
        };
        const play = HTMLMediaElement.prototype.play, pause = HTMLMediaElement.prototype.pause, load = HTMLMediaElement.prototype.load;
        HTMLMediaElement.prototype.play = function () { watch(this); record(this, 'call:play'); return play.call(this); };
        HTMLMediaElement.prototype.pause = function () { watch(this); record(this, 'call:pause'); return pause.call(this); };
        HTMLMediaElement.prototype.load = function () { watch(this); record(this, 'call:load'); return load.call(this); };
        const drawImage = CanvasRenderingContext2D.prototype.drawImage, fillText = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.drawImage = function (...args: any[]) { draws.push({ event: 'drawImage', target: id(this.canvas), source: id(args[0]), at: performance.now() }); return (drawImage as any).apply(this, args); };
        CanvasRenderingContext2D.prototype.fillText = function (...args: any[]) { draws.push({ event: 'fillText', target: id(this.canvas), text: String(args[0]), font: this.font, at: performance.now() }); return (fillText as any).apply(this, args); };
        w.__presentationProbe = { nodes, events, draws, id, closed: false };
    });
}
async function evidence(page: Page, info: TestInfo, name: string) {
    const data = await page.evaluate(() => { const p = (window as any).__presentationProbe; return { events: p.events, draws: p.draws, media: p.nodes.map((n: HTMLMediaElement) => ({ id: p.id(n), kind: n.tagName, src: n.currentSrc || n.src, paused: n.paused, time: n.currentTime, readyState: n.readyState, duration: Number.isFinite(n.duration) ? n.duration : null, width: n instanceof HTMLVideoElement ? n.videoWidth : null, height: n instanceof HTMLVideoElement ? n.videoHeight : null })), fonts: Array.from(document.fonts).map(f => ({ family: f.family, status: f.status })) }; });
    fs.writeFileSync(info.outputPath(`${name}.json`), JSON.stringify(data, null, 2)); return data;
}
async function mark(page: Page, closed = false) { return page.evaluate(closed => { const p = (window as any).__presentationProbe; p.closed = closed; return performance.now(); }, closed); }
async function paused(page: Page) { await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.every((n: HTMLMediaElement) => n.paused))).toBe(true); }
async function installAV(page: Page, info: TestInfo, id: number) {
    const bytes = fixtures(info); await page.getByRole('button', { name: 'Recursos', exact: true }).click();
    for (const [name, mimeType, buffer] of [['own-video.mp4', 'video/mp4', bytes.video], ['own-audio.wav', 'audio/wav', bytes.audio]] as const) {
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name, mimeType, buffer });
        await page.getByRole('button', { name: `Añadir ${name}`, exact: true }).click();
    }
    await save(page); const design = await read(page, id); expect(design.document.timeline.audio_tracks).toHaveLength(1);
    expect(design.document.pages[0].elements.some((e: any) => e.type === 'video')).toBe(true);
    const document = structuredClone(design.document); document.width = 320; document.height = 180;
    const video = document.pages[0].elements.find((e: any) => e.type === 'video');
    Object.assign(video, { width: 64, height: 64, scaleX: 1, scaleY: 1, x: (document.width - 64) / 2, y: (document.height - 64) / 2 });
    document.pages[0].duration = 3; document.pages[0].notes = 'PRIVATE AV NOTES Ω';
    document.pages.push({ id: 'silent-destination', name: 'Destino sin medios', duration: 2, background: '#0000ff', notes: 'Destino Ω', elements: [] });
    const response = await web(page, 'PATCH', `/studio/designs/${id}`, { name: design.name, revision: design.revision, document }); expect(response.status).toBe(200);
    await page.reload(); await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeEnabled();
    fs.writeFileSync(info.outputPath('saved-av-document.json'), JSON.stringify(await read(page, id), null, 2));
}
async function open(page: Page) { await page.getByRole('button', { name: 'Presentar', exact: true }).click(); await expect(button(page, 'Reproducir presentación')).toBeEnabled(); }

test('E2E-29 real own video and audio emit native frames and playback; pause navigation and close release every media', async ({ page }, info) => {
    const { user, id } = await setup(page, info);
    try {
        await installAV(page, info, id); await observe(page); await open(page); const started = await mark(page);
        await button(page, 'Reproducir presentación').click();
        for (const kind of ['VIDEO', 'AUDIO']) await expect.poll(() => page.evaluate(({ kind, started }) => (window as any).__presentationProbe.events.some((e: any) => e.kind === kind && e.event === 'playing' && e.at >= started), { kind, started })).toBe(true);
        await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.events.filter((e: any) => e.event === 'frame').length)).toBeGreaterThan(1);
        await page.screenshot({ path: info.outputPath('native-av-playing.png'), fullPage: true });
        await button(page, 'Pausar presentación').click(); await paused(page); const stopped = await mark(page);
        await quietFor(page, 250); /* negativa: en pausa no hay nuevos play */ let data = await evidence(page, info, 'native-av-paused'); expect(data.events.filter((e: any) => e.at > stopped && ['play', 'playing', 'call:play'].includes(e.event))).toEqual([]);
        expect(data.media.some((e: any) => e.kind === 'VIDEO' && e.width === 64 && e.height === 64)).toBe(true);
        const renderedVideo = await modal(page).getByRole('img', { name: 'Diapositiva de audiencia', exact: true }).evaluate((canvas: HTMLCanvasElement) => {
            const p = (window as any).__presentationProbe, ancestors = new Set([p.id(canvas)]); let changed = true;
            while (changed) { changed = false; for (const draw of p.draws) if (ancestors.has(draw.target) && !ancestors.has(draw.source) && draw.event === 'drawImage') { ancestors.add(draw.source); changed = true; } }
            return p.nodes.some((node: HTMLMediaElement) => node instanceof HTMLVideoElement && ancestors.has(p.id(node)));
        }); expect(renderedVideo).toBe(true);
        const frameTimes = data.events.filter((e: any) => e.event === 'frame').map((e: any) => e.mediaTime); expect(Math.max(...frameTimes)).toBeGreaterThan(Math.min(...frameTimes));
        await button(page, 'Última diapositiva').click(); await expect(modal(page).getByTestId('presentation-notes')).toHaveText('Destino Ω'); await paused(page);
        await page.screenshot({ path: info.outputPath('native-av-navigation.png'), fullPage: true });
        await button(page, 'Primera diapositiva').click(); await button(page, 'Reproducir presentación').click();
        await expect.poll(() => page.evaluate(() => (window as any).__presentationProbe.nodes.some((n: HTMLMediaElement) => !n.paused))).toBe(true);
        await button(page, 'Cerrar presentación').evaluate(armPresentationCloseObserver);
        await button(page, 'Cerrar presentación').click();
        const close = await page.evaluate(readPresentationCloseObservation);
        expect(close?.trusted).toBe(true); expect(Number.isFinite(close?.at)).toBe(true);
        expect(Number.isSafeInteger(close?.eventIndex) && close.eventIndex >= 0).toBe(true);
        const closed = close.at; await paused(page); await quietFor(page, 350); /* negativa: cerrada la presentación no hay nuevos play */
        data = await evidence(page, info, 'native-av-closed'); expect(data.events.filter((e: any) => e.at > closed && ['play', 'playing', 'call:play'].includes(e.event))).toEqual([]);
        expect(close.eventIndex <= data.events.length).toBe(true); expect(data.events.slice(close.eventIndex).filter((e: any) => ['play', 'playing', 'call:play'].includes(e.event))).toEqual([]); await expect(modal(page)).toHaveCount(0);
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 private FontFace and live versioned chart render in audience and next preview; popup has pixels only', async ({ page }, info) => {
    const { user, brand, id } = await setup(page, info);
    try {
        const table = { columns: [{ id: 'category', label: 'Categoría' }, { id: 's0', label: 'Serie 1' }], rows: [{ id: 'a', values: ['NATIVE Ω', 25] }, { id: 'b', values: ['Segundo', 60] }] };
        const saved = await dataset(page, brand, table, 'P2 own saved revision'); const font = await uploadFont(page, info);
        await choose(page, saved.id); await configure(page, 'column', ['s0'], { id: font.id }); await apply(page);
        const before = await read(page, id); const layer = before.document.pages[0].elements[0]; expect(layer.type).toBe('chart'); expect(layer.chart.binding.dataset).toMatchObject({ id: saved.id, revision: 1 }); expect(layer.chart.binding.dataset.sha256).toMatch(/^[a-f0-9]{64}$/);
        const document = structuredClone(before.document); document.pages[0].notes = 'PRIVATE CHART NOTES Ω'; document.pages[0].duration = 3;
        document.pages.push({ ...structuredClone(document.pages[0]), id: 'chart-next', name: 'Segundo gráfico', notes: 'PRIVATE NEXT NOTES Ω', elements: [{ ...structuredClone(layer), id: 'next-chart-layer' }] });
        expect((await web(page, 'PATCH', `/studio/designs/${id}`, { name: before.name, revision: before.revision, document })).status).toBe(200);
        const resources: any[] = [], resourceReads: Promise<void>[] = [], resourceFailures: string[] = [];
        let resourceSerial = 0;
        page.on('response', response => { if (/\/studio\/(fonts\/\d+\/file|datasets\/\d+\/(?:export|table))/.test(response.url())) { const file = `resource-${++resourceSerial}.bin`; resourceReads.push((async () => { try { const bytes = await response.body(); fs.writeFileSync(info.outputPath(file), bytes); resources.push({ url: response.url(), status: response.status(), headers: await response.allHeaders(), file, bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex') }); } catch (error) { resourceFailures.push(String(error)); } })()); } });
        await page.reload(); await observe(page);
        await open(page); await expect(modal(page).getByTestId('presentation-notes')).toHaveText('PRIVATE CHART NOTES Ω');
        for (const label of ['Diapositiva de audiencia', 'Vista previa siguiente']) {
            const actual = await modal(page).getByRole('img', { name: label, exact: true }).evaluate((canvas: HTMLCanvasElement) => {
                const p = (window as any).__presentationProbe; const reachable = new Set([p.id(canvas)]); let changed = true;
                while (changed) { changed = false; for (const draw of p.draws) if (draw.event === 'drawImage' && reachable.has(draw.target) && !reachable.has(draw.source)) { reachable.add(draw.source); changed = true; } }
                return { width: canvas.width, height: canvas.height, text: p.draws.filter((d: any) => d.event === 'fillText' && reachable.has(d.target)), pixels: [...canvas.getContext('2d')!.getImageData(5, 5, 1, 1).data] };
            });
            expect(actual.width).toBeGreaterThan(0); expect(actual.text.some((d: any) => d.text.includes('NATIVE Ω'))).toBe(true); expect(actual.text.some((d: any) => d.font.includes(font.css_family))).toBe(true);
            fs.writeFileSync(info.outputPath(`${label === 'Vista previa siguiente' ? 'next' : 'audience'}-chart-lineage.json`), JSON.stringify(actual, null, 2));
        }
        const data = await evidence(page, info, 'native-chart-font'); expect(data.fonts.some(f => f.family === font.css_family && f.status === 'loaded')).toBe(true);
        await Promise.all(resourceReads); expect(resourceFailures).toEqual([]); expect(resources.some(r => r.status === 200 && r.bytes > 0 && r.url.includes(`/datasets/${saved.id}/`))).toBe(true);
        fs.writeFileSync(info.outputPath('native-chart-resources.json'), JSON.stringify({ resources, saved, font, table, document: (await read(page, id)).document }, null, 2));
        const pending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click(); const popup = await pending;
        await expect(popup.locator('body > canvas')).toHaveCount(1); expect(await popup.locator('body').innerText()).toBe('');
        await popup.screenshot({ path: info.outputPath('native-chart-popup.png') }); await page.screenshot({ path: info.outputPath('native-chart-presenter.png'), fullPage: true });
        const closing = popup.waitForEvent('close'); await button(page, 'Cerrar presentación').click(); await closing;
    } finally { cleanComposerBrands(user.email); }
});

test('E2E-29 real fullscreen entry exit focus restoration and owned popup closure', async ({ page }, info) => {
    const { user } = await setup(page, info);
    try {
        await open(page); await button(page, 'Pantalla completa').click();
        await expect.poll(() => page.evaluate(() => document.fullscreenElement?.getAttribute('aria-label'))).toBe('Diapositiva de audiencia');
        fs.writeFileSync(info.outputPath('native-fullscreen.json'), JSON.stringify(await page.evaluate(() => ({ enabled: document.fullscreenEnabled, tag: document.fullscreenElement?.tagName, label: document.fullscreenElement?.getAttribute('aria-label') })), null, 2));
        await page.screenshot({ path: info.outputPath('native-fullscreen.png') }); await page.evaluate(() => document.exitFullscreen()); await expect.poll(() => page.evaluate(() => document.fullscreenElement === null)).toBe(true);
        const pending = page.waitForEvent('popup'); await button(page, 'Abrir audiencia').click(); const popup = await pending;
        await expect(popup.locator('body > canvas')).toHaveCount(1); const closing = popup.waitForEvent('close'); await button(page, 'Cerrar presentación').click(); await closing;
        await expect(page.getByRole('button', { name: 'Presentar', exact: true })).toBeFocused();
    } finally { cleanComposerBrands(user.email); }
});

for (const action of ['close', 'design-unmount'] as const) test(`E2E-29 pending real media preparation cancelled by ${action} never publishes or plays late`, async ({ page }, info) => {
    const { user, id } = await setup(page, info); let release: () => void = () => {}; let delivered = false;
    try {
        await installAV(page, info, id); await observe(page);
        const held = new Promise<void>(resolve => { release = resolve; }); let received!: () => void; const realReceived = new Promise<void>(resolve => { received = resolve; });
        await page.route('**/brands/*/studio/assets/*/file', async route => {
            const response = await route.fetch(); expect([200, 206]).toContain(response.status()); received(); await held;
            try { await route.fulfill({ response }); delivered = true; } catch (error) { if (action !== 'design-unmount') throw error; }
        });
        await page.getByRole('button', { name: 'Presentar', exact: true }).click(); await realReceived;
        await expect(modal(page).getByRole('status')).toHaveText('Preparando medios…');
        const outputs = await modal(page).locator('canvas').evaluateAll(nodes => nodes.map(node => (window as any).__presentationProbe.id(node)));
        if (action === 'close') {
            const closed = await mark(page, true); await button(page, 'Cerrar presentación').click(); release();
            await expect.poll(() => delivered).toBe(true); await quietFor(page, 1200); /* negativa: una preparación cancelada no llega a reproducir */ await paused(page); const data = await evidence(page, info, 'native-preparation-cancelled');
            expect(data.events.filter((e: any) => e.at > closed && ['play', 'playing', 'call:play'].includes(e.event))).toEqual([]);
            expect(data.draws.filter((d: any) => d.at > closed && outputs.includes(d.target))).toEqual([]); await expect(modal(page)).toHaveCount(0);
        } else {
            // Inertia navigation unmounts the editor in the same JS realm, retaining native observers.
            const home = page.getByRole('link', { name: 'Volver al estudio', exact: true }); await expect(home).toBeAttached();
            const closed = await mark(page, true); await home.evaluate((anchor: HTMLAnchorElement) => anchor.click()); await expect(page).toHaveURL(/\/studio(?:\?.*)?$/);
            release(); await quietFor(page, 1200); /* negativa: una preparación cancelada no llega a reproducir */ await paused(page);
            const data = await evidence(page, info, 'native-design-unmounted'); expect(data.events.filter((e: any) => e.at > closed && ['play', 'playing', 'call:play'].includes(e.event))).toEqual([]);
            expect(data.draws.filter((d: any) => d.at > closed && outputs.includes(d.target))).toEqual([]);
            await expect(modal(page)).toHaveCount(0);
        }
        await page.screenshot({ path: info.outputPath(`native-cancel-${action}.png`), fullPage: true });
    } finally { release(); cleanComposerBrands(user.email); }
});
