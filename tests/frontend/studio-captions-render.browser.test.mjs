import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, randomUUID } from 'node:crypto';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { build } from 'vite';

let browser, page, videoBytes, fontGate = null, fontRequested = null;
const fontBytes = readFileSync(new URL('../Fixtures/fonts/SpaceGrotesk-Variable.woff2', import.meta.url));
const font = { id: 11, name: 'Space Grotesk', family: 'Space Grotesk', sha256: createHash('sha256').update(fontBytes).digest('hex'), status: 'ready', mime: 'font/woff2', format: 'woff2', size: fontBytes.length,
    style: 'normal', weight: 400, file_url: '/fonts/11' };
const source = () => ({ schema_version: 2, width: 160, height: 120, timeline: { fps: 12, audio_tracks: [], captions: {
    style: { font_id: 11, fontFamily: 'Space Grotesk', fontSize: 24, strokeWidth: 0, fill: '#ffffff', margin: 0.05, position: 'bottom' },
    cues: [{ id: 'cue_1', start: 0.5, end: 2, text: 'Hola' }],
} }, pages: [{ id: 'a', duration: 1.5, background: '#000000', elements: [], transition: { type: 'fade', duration: 0.5 } },
    { id: 'b', duration: 1.5, background: '#000000', elements: [] }] });

before(async () => {
    videoBytes = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=black:size=24x24:rate=12:duration=3', '-an', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 15000 });
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'tests/frontend/studio-captions-render-entry.mjs', name: 'CaptionRender', formats: ['iife'] } } });
    browser = await chromium.launch({ headless: true }); page = await browser.newPage({ serviceWorkers: 'block' });
    await page.route('**/*', async route => {
        const url = route.request().url();
        if (url === 'http://captions.invalid/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body></body>' });
        if (url === 'http://captions.invalid/fonts/11') { fontRequested?.(); if (fontGate) await fontGate; return route.fulfill({ contentType: 'font/woff2', body: fontBytes }); }
        if (url === 'http://captions.invalid/video.mp4') {
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? ''), start = range ? Number(range[1]) : 0;
            const end = range?.[2] ? Math.min(Number(range[2]), videoBytes.length - 1) : videoBytes.length - 1;
            return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4', body: videoBytes.subarray(start, end + 1), headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${videoBytes.length}` } : {}) } });
        }
        return route.abort();
    });
    await page.goto('http://captions.invalid/');
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
});
after(async () => { await browser?.close(); });

test('Arial22 default caption outline preserves white glyph interiors in preview PNG and SVG without mutating style', async () => {
    const result = await page.evaluate(async () => {
        const source = () => ({ schema_version: 2, width: 320, height: 180, timeline: { fps: 12, audio_tracks: [], captions: {
            style: { fontFamily: 'Arial', fontSize: 22, fontWeight: 'normal', fontStyle: 'normal', fill: '#ffffff', stroke: '#000000' },
            cues: [{ id: 'contrast', start: 0.25, end: 1.25, text: 'Modificado\nsegunda línea' }],
        } }, pages: [{ id: 'a', duration: 1.5, background: '#000000', elements: [] }] });
        const measure = canvas => {
            const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
            let white = 0, energy = 0;
            for (let i = 0; i < data.length; i += 4) {
                const light = Math.min(data[i], data[i + 1], data[i + 2]) * data[i + 3] / 255;
                if (light >= 200) white++;
                energy += light / 255;
            }
            return { white, energy };
        };
        const samples = [];
        for (const outlined of [false, true]) {
            const doc = source(); if (!outlined) doc.timeline.captions.style.strokeWidth = 0;
            const before = JSON.stringify(doc), output = document.createElement('canvas');
            const preview = await CaptionRender.createTimelinePreview(output, { document: doc, offline: true });
            let previewPixels;
            try { await preview.seek(0.5); previewPixels = measure(output); } finally { await preview.dispose(); }
            const blob = await CaptionRender.exportPage(doc, 'a', [], { frame_times: [{ page_id: 'a', time: 0.5 }] });
            const bitmap = await createImageBitmap(blob), raster = document.createElement('canvas');
            raster.width = bitmap.width; raster.height = bitmap.height;
            raster.getContext('2d').drawImage(bitmap, 0, 0); bitmap.close();
            const svg = await (await CaptionRender.exportPage(doc, 'a', [], { format: 'svg', frame_times: [{ page_id: 'a', time: 0.5 }] })).text();
            samples.push({ preview: previewPixels, png: measure(raster), svg, unchanged: before === JSON.stringify(doc) });
        }
        return samples;
    });
    for (const sample of result) assert.equal(sample.unchanged, true);
    for (const kind of ['preview', 'png']) {
        assert.ok(result[0][kind].white > 100, `${kind}: control must contain white text, without emoji fallback`);
        assert.ok(result[1][kind].energy >= result[0][kind].energy * 0.5,
            `${kind}: default outline erased white glyph interiors: ${JSON.stringify(result.map(sample => sample[kind]))}`);
    }
    assert.match(result[1].svg, /paint-order="stroke"/, 'SVG must paint the outline before the white fill');
});

test('caption preview uses the private font once after scene blend with exact end-exclusive and reverse seek pixels', async () => {
    const result = await page.evaluate(async ({ document: doc, font }) => {
        const before = JSON.stringify(doc);
        const output = document.createElement('canvas');
        const preview = await CaptionRender.createTimelinePreview(output, { document: doc, fonts: [font], offline: true });
        const samples = [];
        try {
            for (const time of [0.25, 0.75, 1.25, 2, 0.75]) {
                await preview.seek(time);
                const bytes = output.getContext('2d').getImageData(0, 0, output.width, output.height).data;
                samples.push([...bytes]);
            }
            return { samples, unchanged: before === JSON.stringify(doc), loaded: [...document.fonts].some(face => face.family.startsWith('StudioFont_11_')) };
        } finally { await preview.dispose(); }
    }, { document: source(), font });
    const whites = bytes => bytes.filter((value, i) => i % 4 === 0 && value > 200).length;
    assert.equal(whites(result.samples[0]), 0);
    assert.ok(whites(result.samples[1]) > 40, 'caption pixels must be rendered');
    assert.deepEqual(result.samples[1], result.samples[2], 'fade must neither attenuate nor duplicate the global cue');
    assert.equal(whites(result.samples[3]), 0);
    assert.deepEqual(result.samples[1], result.samples[4]);
    assert.equal(result.loaded, true);
    assert.equal(result.unchanged, true);
});

test('caption static frames survive scale rerender and include private vector text and embedded font in SVG', async () => {
    const result = await page.evaluate(async ({ document: doc, font }) => {
        const samples = [];
        for (const [pageId, time, scale] of [['a', 0.75, 1], ['b', 0.25, 1], ['a', 0.75, 2], ['a', 0.25, 1]]) {
            const blob = await CaptionRender.exportPage(doc, pageId, [], { fonts: [font], scale, frame_times: [{ page_id: pageId, time }] });
            const bitmap = await createImageBitmap(blob), canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
            const context = canvas.getContext('2d'); context.drawImage(bitmap, 0, 0); bitmap.close();
            const bytes = context.getImageData(0, 0, canvas.width, canvas.height).data;
            samples.push({ width: canvas.width, height: canvas.height, whites: [...bytes].filter((value, i) => i % 4 === 0 && value > 200).length });
        }
        const svg = await (await CaptionRender.exportPage(doc, 'b', [], { format: 'svg', fonts: [font], frame_times: [{ page_id: 'b', time: 0.25 }] })).text();
        const pdf = await CaptionRender.exportPage(doc, 'b', [], { format: 'pdf', fonts: [font], frame_times: [{ page_id: 'b', time: 0.25 }] });
        return { samples, svg, pdf: [...new Uint8Array(await pdf.slice(0, 5).arrayBuffer())] };
    }, { document: source(), font });
    assert.ok(result.samples[0].whites > 40);
    assert.equal(result.samples[0].whites, result.samples[1].whites, 'static page maps local time to global cue clock');
    assert.equal(result.samples[2].width, 320); assert.equal(result.samples[2].height, 240);
    assert.ok(result.samples[2].whites > result.samples[0].whites * 3);
    assert.equal(result.samples[3].whites, 0);
    assert.match(result.svg, /Hola/); assert.match(result.svg, /@font-face/); assert.match(result.svg, /data:font\/woff2;base64,/);
    assert.deepEqual(result.pdf, [37, 80, 68, 70, 45]);
});

test('caption private font SVG passes the actual closed-network server render entry', () => {
    const directory = path.resolve('storage/framework/testing/t11-captions/studio-exports/0/0', randomUUID());
    const input = { document: source(), assets: [], fonts: [{ ...font, file_url: `data:font/woff2;base64,${fontBytes.toString('base64')}` }], format: 'svg', pageIds: ['a', 'b'],
        frame_times: [{ page_id: 'a', time: 0.75 }, { page_id: 'b', time: 0.25 }] };
    const output = execFileSync('node', ['scripts/studio/render.mjs', directory], { input: JSON.stringify(input), encoding: 'utf8', timeout: 90000, maxBuffer: 4 * 1024 * 1024 });
    const manifest = JSON.parse(output); assert.equal(manifest.files.length, 2);
    for (const file of manifest.files) { const svg = readFileSync(path.join(directory, file.name), 'utf8'); assert.match(svg, /Hola/); assert.match(svg, /data:font\/woff2;base64,/); }
});

test('caption first private font load pauses native video before waiting and resumes on the shared playhead', async () => {
    let release;
    const requested = new Promise(resolve => { fontRequested = resolve; });
    fontGate = new Promise(resolve => { release = resolve; });
    const doc = source(); doc.pages = [{ id: 'a', duration: 3, background: '#000000', elements: [{ id: 'clip', type: 'video', asset_id: 1, x: 0, y: 0, width: 24, height: 24, muted: true }] }];
    const assets = [{ id: 1, kind: 'video', mime: 'video/mp4', status: 'listo', duration: 3, width: 24, height: 24, studio_url: '/video.mp4', preview_url: '/video.mp4' }];
    try {
        await page.evaluate(async ({ doc, assets, font }) => {
            window.captionSync = await CaptionRender.createTimelinePreview(document.createElement('canvas'), { document: doc, assets, fonts: [font] });
            await window.captionSync.seek(0, { play: true });
            window.captionPauseCalls = 0; window.originalCaptionPause = HTMLMediaElement.prototype.pause;
            HTMLMediaElement.prototype.pause = function () { window.captionPauseCalls++; return window.originalCaptionPause.call(this); };
            window.captionPendingSeek = window.captionSync.seek(0.75, { play: true });
        }, { doc, assets, font });
        await requested;
        const pauses = await page.evaluate(() => window.captionPauseCalls);
        assert.ok(pauses > 0, 'the native clock must pause while the new caption font buffers');
        release(); await page.evaluate(async () => { await window.captionPendingSeek; });
        const state = await page.evaluate(() => window.captionSync.mediaState());
        assert.equal(state[0].paused, false); assert.ok(Math.abs(state[0].sourceTime - 0.75) < 0.1);
    } finally {
        release?.(); fontGate = null; fontRequested = null;
        await page.evaluate(async () => { await window.captionPendingSeek?.catch(() => {}); await window.captionSync?.dispose(); if (window.originalCaptionPause) HTMLMediaElement.prototype.pause = window.originalCaptionPause; });
    }
});

test('canonical cues with blank lines remain unchanged and renderable even when plain caption export rejects them', async () => {
    const result = await page.evaluate(async ({ document: doc, font }) => {
        const values = [];
        for (const text of ['\nHola', 'Hola\n', 'Hola\n\nMundo']) {
            doc.timeline.captions.style.fontSize = 16;
            doc.timeline.captions.cues[0].text = text;
            const before = JSON.stringify(doc);
            const output = document.createElement('canvas');
            const preview = await CaptionRender.createTimelinePreview(output, { document: doc, fonts: [font], offline: true });
            try {
                await preview.seek(0.75);
                const bytes = output.getContext('2d').getImageData(0, 0, output.width, output.height).data;
                const whites = [...bytes].filter((value, i) => i % 4 === 0 && value > 200).length;
                values.push({ whites, unchanged: before === JSON.stringify(doc), text: doc.timeline.captions.cues[0].text });
            } finally { await preview.dispose(); }
        }
        return values;
    }, { document: source(), font });
    assert.deepEqual(result.map(value => value.text), ['\nHola', 'Hola\n', 'Hola\n\nMundo']);
    for (const value of result) { assert.ok(value.whites > 20); assert.equal(value.unchanged, true); }
});
