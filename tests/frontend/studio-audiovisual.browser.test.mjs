import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { build } from 'vite';
import { newElement } from '../../resources/js/Studio/document.js';

let browser, page;
before(async () => {
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=red:size=64x64:rate=10:duration=1',
        '-f', 'lavfi', '-i', 'color=blue:size=64x64:rate=10:duration=3', '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20000 });
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'resources/js/Studio/timeline-preview.js', name: 'OfflinePreview', formats: ['iife'] } } });
    browser = await chromium.launch({ headless: true }); page = await browser.newPage({ serviceWorkers: 'block' });
    await page.route('**/*', route => {
        if (route.request().url() === 'http://av-preview.invalid/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body></body>' });
        if (route.request().url() === 'http://av-preview.invalid/video') {
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? '');
            const start = range ? Number(range[1]) : 0, end = range?.[2] ? Math.min(Number(range[2]), video.length - 1) : video.length - 1;
            return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4', body: video.subarray(start, end + 1),
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${video.length}` } : {}) } });
        }
        return route.abort();
    });
    await page.goto('http://av-preview.invalid/');
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
});
after(async () => { await browser?.close(); });

test('offline frames never open audio decoders or play media and hold strictly inside trimEnd', async () => {
    const input = { schema_version: 2, width: 100, height: 100, timeline: { fps: 30,
        audio_tracks: [{ id: 'music', asset_id: 99, start: 0, duration: 4 }] }, pages: [
        { id: 'p', duration: 4, background: '#ffffff', elements: [newElement('video', { id: 'v', asset_id: 1,
            x: 0, y: 0, width: 64, height: 64, trimStart: 0, trimEnd: 1, muted: false })] },
    ] };
    const result = await page.evaluate(async doc => {
        const canvas = document.createElement('canvas'), nodes = [], nativeCreate = document.createElement.bind(document);
        let audioNodes = 0, plays = 0, preview;
        const nativePlay = HTMLMediaElement.prototype.play;
        document.createElement = (...args) => { const node = nativeCreate(...args); if (args[0] === 'audio') audioNodes++;
            if (args[0] === 'video') nodes.push(node); return node; };
        HTMLMediaElement.prototype.play = function () { plays++; return nativePlay.call(this); };
        const before = JSON.stringify(doc), pixels = [], times = [];
        try {
            preview = await OfflinePreview.createTimelinePreview(canvas, { document: doc, offline: true,
                assets: [{ id: 1, kind: 'video', status: 'listo', duration: 4, studio_url: 'http://av-preview.invalid/video' }] });
            for (const time of [0.5, 0.9, 1, 2, 3.9]) {
                await preview.seek(time, { play: true });
                pixels.push(Array.from(canvas.getContext('2d').getImageData(10, 10, 1, 1).data));
                times.push(nodes[0].currentTime);
            }
            return { audioNodes, plays, pixels, times, preservesPitch: nodes[0].preservesPitch, unchanged: before === JSON.stringify(doc) };
        } finally { await preview?.dispose(); document.createElement = nativeCreate; HTMLMediaElement.prototype.play = nativePlay; }
    }, input);
    assert.equal(result.audioNodes, 0); assert.equal(result.plays, 0); assert.equal(result.unchanged, true);
    assert.equal(result.preservesPitch, false, 'video and standalone audio must use the same source-rate/pitch policy');
    assert.ok(result.pixels.every(pixel => pixel[0] > 230 && pixel[2] < 20), JSON.stringify(result));
    assert.ok(result.times.slice(2).every(time => time >= 0.9 && time < 1), JSON.stringify(result));
});
