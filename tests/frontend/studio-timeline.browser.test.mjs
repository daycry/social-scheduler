import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { build } from 'vite';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { newElement, validateDocument } from '../../resources/js/Studio/document.js';

let browser, page;
const videoRequests = [];
before(async () => {
    const fixture = path.resolve(`storage/framework/testing/t11-video-${crypto.randomUUID()}.mp4`);
    execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-n', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=3',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', fixture], { timeout: 20_000 });
    const video = await fs.readFile(fixture);
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'resources/js/Studio/canvas.js', name: 'StudioTemporalCanvas', formats: ['iife'] } } });
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => {
        if (route.request().url() === 'http://timeline-testing.invalid/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' });
        if (route.request().url().startsWith('http://timeline-testing.invalid/clip.mp4')) {
            videoRequests.push(route.request().url());
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? '');
            if (range) {
                const start = Number(range[1]), end = range[2] ? Math.min(Number(range[2]), video.length - 1) : video.length - 1;
                return route.fulfill({ status: 206, contentType: 'video/mp4', body: video.subarray(start, end + 1),
                    headers: { 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${start}-${end}/${video.length}` } });
            }
            return route.fulfill({ contentType: 'video/mp4', body: video, headers: { 'Accept-Ranges': 'bytes' } });
        }
        return route.abort();
    });
    page = await context.newPage(); await page.goto('http://timeline-testing.invalid/');
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
    const previewBundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'resources/js/Studio/timeline-preview.js', name: 'StudioTimelinePreview', formats: ['iife'] } } });
    await page.addScriptTag({ content: (Array.isArray(previewBundle) ? previewBundle[0] : previewBundle).output[0].code });
});

test('real video retains source phase, playback rate, loop and gain metadata across Canvas edits', async () => {
    const document = validateDocument({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] },
        pages: [{ id: 'one', background: '#ffffff', duration: 4, elements: [newElement('video', { id: 'clip', asset_id: 1,
            width: 64, height: 64, trimStart: 0.2, trimEnd: 2.5, timing: { start: 0.5, duration: 3 },
            sourceOffset: 0.25, playbackRate: 1.5, loop: true, gainPoints: [{ time: 0, value: 0.5 }, { time: 3, value: 1 }] })] }] });
    const result = await page.evaluate(async doc => {
        const element = document.createElement('canvas'); document.body.append(element);
        const editor = await StudioTemporalCanvas.createStudioCanvas(element, { document: doc,
            assets: [{ id: 1, kind: 'video', width: 64, height: 64, status: 'listo', studio_url: 'http://timeline-testing.invalid/clip.mp4' }] });
        try {
            const video = editor.canvas.getObjects()[0].studioVideo;
            const loaded = { time: video.currentTime, rate: video.playbackRate, duration: video.duration,
                seekable: Array.from({ length: video.seekable.length }, (_, i) => [video.seekable.start(i), video.seekable.end(i)]) };
            editor.select(['clip']); await editor.updateSelection({ x: 12 });
            return { loaded, layer: editor.serializePage()[0] };
        } finally { await editor.destroy(); element.remove(); }
    }, document);
    const expected = document.pages[0].elements[0];
    for (const key of ['timing', 'sourceOffset', 'playbackRate', 'loop', 'gainPoints']) assert.deepEqual(result.layer[key], expected[key], key);
    assert.ok(Math.abs(result.loaded.time - 0.45) < 0.02, JSON.stringify(result.loaded));
    assert.equal(result.loaded.rate, 1.5);
});
after(async () => { await browser?.close(); });

test('preview loads only resources in the effective window and releases inactive decoders', async () => {
    videoRequests.length = 0;
    const result = await page.evaluate(async () => {
        const output = document.createElement('canvas'); document.body.append(output);
        const nodes = [], original = document.createElement.bind(document);
        document.createElement = (...args) => { const node = original(...args); if (args[0] === 'video') nodes.push(node); return node; };
        const base = { x: 0, y: 0, width: 64, height: 64, scaleX: 1, scaleY: 1, rotation: 0, opacity: 1, visible: true, locked: false };
        const doc = { schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [{ id: 'one', duration: 5, background: '#ffffff', elements: [
            { ...base, id: 'title', type: 'rect', fill: '#ff0000' },
            ...Array.from({ length: 8 }, (_, i) => ({ ...base, id: `future_${i}`, type: 'video', asset_id: i + 1, muted: true, trimEnd: 3, timing: { start: 4, duration: 1 } })),
        ] }] };
        let preview;
        try {
            preview = await StudioTimelinePreview.createTimelinePreview(output, { document: doc,
                assets: Array.from({ length: 8 }, (_, i) => ({ id: i + 1, status: 'listo', kind: 'video', studio_url: `http://timeline-testing.invalid/clip.mp4?future=${i}` })) });
            await preview.seek(1); const before = nodes.length;
            await preview.seek(4.2); const during = nodes.length;
            await preview.seek(1); const released = nodes.every(node => node.paused && !node.getAttribute('src'));
            return { before, during, released };
        } finally { await preview?.dispose(); document.createElement = original; output.remove(); }
    });
    assert.equal(result.before, 0, 'future clips must not allocate or load decoders');
    assert.equal(result.during, 8);
    assert.equal(result.released, true);
    assert.equal(new Set(videoRequests.filter(url => url.includes('?future='))).size, 8);
});

test('timeline preview samples real video phase, inherited visibility and page transitions without mutating the document', async () => {
    const doc = validateDocument({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [
        { id: 'first', background: '#ffffff', duration: 4, transition: { type: 'fade', duration: 1 }, elements: [
            newElement('rect', { id: 'red', x: 0, y: 0, width: 100, height: 100, fill: '#ff0000', timing: { start: 1, duration: 2 } }),
            newElement('group', { id: 'window', x: 120, y: 0, width: 64, height: 64, timing: { start: 1, duration: 2 }, elements: [
                newElement('video', { id: 'video', x: 0, y: 0, width: 64, height: 64, asset_id: 1, trimStart: 0.2, trimEnd: 2.5,
                    sourceOffset: 0.25, playbackRate: 1.5, loop: true, muted: true }),
            ] }),
        ] },
        { id: 'second', background: '#0000ff', duration: 2, elements: [] },
    ] });
    const result = await page.evaluate(async doc => {
        const output = document.createElement('canvas'); document.body.append(output);
        const original = JSON.stringify(doc);
        const preview = await StudioTimelinePreview.createTimelinePreview(output, { document: doc,
            assets: [{ id: 1, kind: 'video', width: 64, height: 64, status: 'listo', studio_url: 'http://timeline-testing.invalid/clip.mp4' }] });
        const pixel = () => Array.from(output.getContext('2d').getImageData(20, 20, 1, 1).data);
        try {
            await preview.seek(0.5); const before = pixel();
            await preview.seek(1.5); const during = pixel();
            const media = preview.mediaState().find(item => item.id === 'video');
            await preview.seek(3.5); const transition = pixel();
            await preview.seek(4.5); const second = pixel();
            await preview.seek(1.5); const repeated = pixel();
            return { before, during, transition, second, repeated, media, unchanged: original === JSON.stringify(doc) };
        } finally { await preview.dispose(); output.remove(); }
    }, doc);
    assert.deepEqual(result.before, [255, 255, 255, 255]);
    assert.deepEqual(result.during, [255, 0, 0, 255]);
    assert.ok(Math.abs(result.media.sourceTime - 0.4) < 0.02, JSON.stringify(result.media));
    assert.equal(result.media.active, true);
    assert.equal(result.media.paused, true);
    assert.deepEqual(result.second, [0, 0, 255, 255]);
    assert.ok(result.transition[0] > 110 && result.transition[0] < 145, JSON.stringify(result.transition));
    assert.ok(result.transition[2] > 240);
    assert.deepEqual(result.repeated, result.during);
    assert.equal(result.unchanged, true);
});

test('Canvas edits, selection and copy preserve temporal metadata from a stored v2 document', async () => {
    const document = validateDocument({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] },
        pages: [{ id: 'one', background: '#ffffff', duration: 5, elements: [newElement('text', { id: 'title', text: 'Tiempo',
            width: 200, height: 50, fontSize: 20, timing: { start: 1, duration: 2 } })] }] });
    const result = await page.evaluate(async doc => {
        const element = document.createElement('canvas'); document.body.append(element);
        const editor = await StudioTemporalCanvas.createStudioCanvas(element, { document: doc });
        try {
            const initial = editor.serializePage();
            editor.select(['title']);
            await editor.updateSelection({ text: 'Editado', x: 30 });
            const selected = editor.selection();
            editor.copy(); await editor.paste();
            return { initial, selected, layers: editor.serializePage() };
        } finally { await editor.destroy(); element.remove(); }
    }, document);
    assert.deepEqual(result.initial[0].timing, { start: 1, duration: 2 });
    assert.equal(result.selected[0].text, 'Editado');
    assert.deepEqual(result.selected[0].timing, { start: 1, duration: 2 });
    assert.equal(result.layers.length, 2);
    assert.notEqual(result.layers[0].id, result.layers[1].id);
    for (const layer of result.layers) assert.deepEqual(layer.timing, { start: 1, duration: 2 });
});

test('legacy v1 keeps its existing clamped video poster when only trimStart was saved beyond the source', async () => {
    const document = validateDocument({ schema_version: 1, width: 320, height: 180,
        pages: [{ id: 'one', background: '#ffffff', duration: 5, elements: [newElement('video', { id: 'legacy_clip', asset_id: 1,
            width: 64, height: 64, trimStart: 9, trimEnd: null })] }] });
    const result = await page.evaluate(async doc => {
        const element = document.createElement('canvas'); document.body.append(element);
        const editor = await StudioTemporalCanvas.createStudioCanvas(element, { document: doc,
            assets: [{ id: 1, kind: 'video', width: 64, height: 64, status: 'listo', studio_url: 'http://timeline-testing.invalid/clip.mp4' }] });
        try { return { time: editor.canvas.getObjects()[0].studioVideo.currentTime, clip: editor.serializePage()[0] }; }
        finally { await editor.destroy(); element.remove(); }
    }, document);
    assert.ok(result.time > 2.9 && result.time < 3);
    assert.equal(result.clip.trimStart, 9);
    assert.equal(Object.hasOwn(result.clip, 'playbackRate'), false);
    assert.equal(Object.hasOwn(result.clip, 'timing'), false);
});

test('ungroup keeps the effective temporal window and clips video phase and gain without losing subframe intersections', async () => {
    const clip = newElement('video', { id: 'nested_video', asset_id: 1, x: 0, y: 0, width: 64, height: 64,
        trimStart: 0.2, trimEnd: 2.5, sourceOffset: 0.25, playbackRate: 1.5, loop: true,
        gainPoints: [{ time: 0, value: 0.5 }, { time: 5, value: 1 }] });
    const group = newElement('group', { id: 'group', x: 0, y: 0, width: 64, height: 64, timing: { start: 1, duration: 2 },
        elements: [clip, newElement('rect', { id: 'nested_shape', x: 0, y: 0, width: 20, height: 20, timing: { start: 0, duration: 1.01 } })] });
    const doc = validateDocument({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] },
        pages: [{ id: 'one', background: '#ffffff', duration: 5, elements: [group] }] });
    const result = await page.evaluate(async doc => {
        const element = document.createElement('canvas'); document.body.append(element);
        const editor = await StudioTemporalCanvas.createStudioCanvas(element, { document: doc,
            assets: [{ id: 1, kind: 'video', width: 64, height: 64, status: 'listo', studio_url: 'http://timeline-testing.invalid/clip.mp4' }] });
        try { editor.select(['group']); editor.ungroup(); return editor.serializePage(); }
        finally { await editor.destroy(); element.remove(); }
    }, doc);
    assert.deepEqual(result[0].timing, { start: 1, duration: 2 });
    assert.ok(Math.abs(result[0].sourceOffset - 1.75) < 1e-9);
    assert.deepEqual(result[0].gainPoints, [{ time: 0, value: 0.6 }, { time: 2, value: 0.8 }]);
    assert.equal(result[1].timing.start, 1);
    assert.ok(Math.abs(result[1].timing.duration - 0.01) < 1e-9);
    doc.pages[0].elements = result;
    assert.doesNotThrow(() => validateDocument(doc));
});
