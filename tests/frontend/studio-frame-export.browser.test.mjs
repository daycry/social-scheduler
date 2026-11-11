import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { build } from 'vite';
import { newElement, validateDocument } from '../../resources/js/Studio/document.js';

let browser, page;
before(async () => {
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=red:size=64x64:rate=10:duration=1',
        '-f', 'lavfi', '-i', 'color=blue:size=64x64:rate=10:duration=3', '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20000 });
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'resources/js/Studio/exports.js', name: 'FrameExports', formats: ['iife'] } } });
    browser = await chromium.launch({ headless: true }); page = await browser.newPage({ serviceWorkers: 'block' });
    await page.route('**/*', route => {
        if (route.request().url() === 'http://frame-export.invalid/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body></body>' });
        if (route.request().url() === 'http://frame-export.invalid/video.mp4') {
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? '');
            const start = range ? Number(range[1]) : 0, end = range?.[2] ? Math.min(Number(range[2]), video.length - 1) : video.length - 1;
            return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4', body: video.subarray(start, end + 1),
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${video.length}` } : {}) } });
        }
        return route.abort();
    });
    await page.goto('http://frame-export.invalid/');
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
    await page.evaluate(() => {
        window.framePixel = async blob => {
            const image = new Image(), url = URL.createObjectURL(blob);
            try { image.src = url; await image.decode(); const canvas = document.createElement('canvas'); canvas.width = canvas.height = 100;
                canvas.getContext('2d').drawImage(image, 0, 0); return Array.from(canvas.getContext('2d').getImageData(10, 10, 1, 1).data); }
            finally { URL.revokeObjectURL(url); }
        };
    });
});
after(async () => { await browser?.close(); });

const document = elements => validateDocument({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: [] },
    pages: [{ id: 'one', duration: 4, background: '#ffffff', elements }] });
const element = (type, properties) => newElement(type, { x: 0, y: 0, ...properties });

test('static export respects the selected local frame and semi-open windows without changing the design', async () => {
    const result = await page.evaluate(async doc => {
        const before = JSON.stringify(doc), samples = [];
        for (const time of [0, 1.5, 2]) samples.push(await window.framePixel(await FrameExports.exportPage(doc, 'one', [], { frame_times: [{ page_id: 'one', time }] })));
        return { samples, unchanged: before === JSON.stringify(doc) };
    }, document([element('rect', { id: 'red', width: 60, height: 60, fill: '#ff0000', timing: { start: 1, duration: 1 } })]));
    assert.deepEqual(result.samples, [[255, 255, 255, 255], [255, 0, 0, 255], [255, 255, 255, 255]]);
    assert.equal(result.unchanged, true);
});

test('static video frames use trim, rate, source offset and loop instead of the initial poster', async () => {
    const result = await page.evaluate(async doc => {
        const assets = [{ id: 1, kind: 'video', status: 'listo', duration: 4, preview_url: 'http://frame-export.invalid/video.mp4' }];
        const samples = [];
        for (const time of [1.3, 2.2]) samples.push(await window.framePixel(await FrameExports.exportPage(doc, 'one', assets, { frame_times: [{ page_id: 'one', time }] })));
        return samples;
    }, document([element('video', { id: 'video', asset_id: 1, width: 64, height: 64, timing: { start: 0.5, duration: 2 },
        trimStart: 0.1, trimEnd: 3.1, playbackRate: 2, sourceOffset: 0.2, loop: true })]));
    assert.ok(result[0][2] > 230 && result[0][0] < 20, JSON.stringify(result));
    assert.ok(result[1][0] > 230 && result[1][2] < 20, JSON.stringify(result));
});

test('an exhausted non-looping video holds the last frame inside its trim interval', async () => {
    const result = await page.evaluate(async doc => {
        const assets = [{ id: 1, kind: 'video', status: 'listo', duration: 4, preview_url: 'http://frame-export.invalid/video.mp4' }];
        const samples = [];
        for (const time of [0.9, 1, 2, 3.9]) samples.push(await window.framePixel(await FrameExports.exportPage(doc, 'one', assets, { frame_times: [{ page_id: 'one', time }] })));
        return samples;
    }, document([element('video', { id: 'short', asset_id: 1, width: 64, height: 64, timing: { start: 0, duration: 4 },
        trimStart: 0, trimEnd: 1, playbackRate: 1, sourceOffset: 0, loop: false })]));
    assert.ok(result.every(pixel => pixel[0] > 230 && pixel[2] < 20), JSON.stringify(result));
});

test('inactive grouped media are not loaded and selected frames apply to every static format', async () => {
    const result = await page.evaluate(async doc => {
        const samples = [];
        for (const format of ['png', 'jpeg', 'webp', 'svg']) {
            const files = await FrameExports.exportDesign(doc, { pageIds: ['one'], format, frame_times: [{ page_id: 'one', time: 1.25 }] });
            samples.push(await window.framePixel(files[0].blob));
        }
        const pdf = await FrameExports.exportDesign(doc, { pageIds: ['one'], format: 'pdf', frame_times: [{ page_id: 'one', time: 1.25 }] });
        return { samples, pdf: await pdf[0].blob.slice(0, 5).text() };
    }, document([element('group', { id: 'group', width: 100, height: 100, timing: { start: 1, duration: 2 }, elements: [
        element('rect', { id: 'red', x: -50, y: -50, width: 60, height: 60, fill: '#ff0000', timing: { start: 1, duration: 1 } }),
        element('video', { id: 'future', x: 0, y: 0, asset_id: 999, width: 60, height: 60, timing: { start: 2, duration: 1 } }),
    ] })]));
    assert.ok(result.samples.every(pixel => pixel[0] > 230 && pixel[1] < 25 && pixel[2] < 25), JSON.stringify(result));
    assert.equal(result.pdf, '%PDF-');
});

test('frame-time inputs reject invalid types, unknown pages, duplicates and end boundaries', async () => {
    const result = await page.evaluate(async doc => {
        const invalid = [null, {}, [Object.create({ page_id: 'one', time: 0 })], [{ page_id: 'one', time: '1' }], [{ page_id: 'one', time: null }], [{ page_id: 'one', time: 4 }],
            [{ page_id: 'one', time: -0.1 }], [{ page_id: 'one', time: NaN }], [{ page_id: 'other', time: 0 }],
            [{ page_id: 'one', time: 0, url: 'https://invalid.test' }], [{ page_id: 'one', time: 0 }, { page_id: 'one', time: 1 }]];
        const outcomes = [];
        for (const frame_times of invalid) {
            try { await FrameExports.exportPage(doc, 'one', [], { frame_times }); outcomes.push(false); }
            catch { outcomes.push(true); }
        }
        const legacy = { ...doc, schema_version: 1 }; delete legacy.timeline;
        try { await FrameExports.exportPage(legacy, 'one', [], { frame_times: [{ page_id: 'one', time: 0 }] }); outcomes.push(false); }
        catch { outcomes.push(true); }
        return outcomes;
    }, document([]));
    assert.ok(result.every(Boolean), JSON.stringify(result));
});

test('different page times retain the selected order and inherited group visibility', async () => {
    const input = document([element('rect', { id: 'red', width: 60, height: 60, fill: '#ff0000', timing: { start: 1, duration: 1 } })]);
    input.pages.push({ ...input.pages[0], id: 'two', name: 'Dos', background: '#0000ff', elements: [
        element('group', { id: 'hidden_group', width: 100, height: 100, timing: { start: 2, duration: 1 }, elements: [
            element('video', { id: 'hidden_video', asset_id: 999, x: -50, y: -50, width: 60, height: 60 }),
        ] }),
    ] });
    const result = await page.evaluate(async doc => {
        const files = await FrameExports.exportDesign(doc, { pageIds: ['two', 'one'], frame_times: [{ page_id: 'one', time: 1.5 }, { page_id: 'two', time: 1 }] });
        const pixels = []; for (const file of files) pixels.push({ page: file.pageId, color: await window.framePixel(file.blob) });
        return pixels;
    }, input);
    assert.deepEqual(result, [{ page: 'two', color: [0, 0, 255, 255] }, { page: 'one', color: [255, 0, 0, 255] }]);
});

test('a missing selected page reports a usable frame error', async () => {
    const error = await page.evaluate(async doc => {
        try { await FrameExports.exportPage(doc, 'missing'); return null; } catch (error) { return error.message; }
    }, document([]));
    assert.match(error, /página/);
});
