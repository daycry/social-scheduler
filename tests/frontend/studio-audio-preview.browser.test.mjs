import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { build } from 'vite';
import { execFileSync } from 'node:child_process';
import { newElement } from '../../resources/js/Studio/document.js';

let browser, page;
const requests = [];
function tone(frequency) {
    const rate = 16000, samples = rate * 4, wav = Buffer.alloc(44 + samples * 2);
    wav.write('RIFF'); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8); wav.writeUInt32LE(16, 16);
    wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22); wav.writeUInt32LE(rate, 24); wav.writeUInt32LE(rate * 2, 28);
    wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34); wav.write('data', 36); wav.writeUInt32LE(samples * 2, 40);
    for (let i = 0; i < samples; i++) wav.writeInt16LE(Math.round(12000 * Math.sin(2 * Math.PI * frequency * i / rate)), 44 + i * 2);
    return wav;
}
before(async () => {
    const video = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=30:duration=4',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1'], { timeout: 20000 });
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false,
        lib: { entry: 'resources/js/Studio/timeline-preview.js', name: 'AudioPreview', formats: ['iife'] } } });
    browser = await chromium.launch({ headless: true });
    page = await browser.newPage({ serviceWorkers: 'block' });
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body><button id="play">Play</button></body>' });
        if (url.pathname === '/video.mp4') {
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? '');
            const start = range ? Number(range[1]) : 0, end = range?.[2] ? Math.min(Number(range[2]), video.length - 1) : video.length - 1;
            return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4', body: video.subarray(start, end + 1),
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${video.length}` } : {}) } });
        }
        if (url.pathname.endsWith('.wav')) {
            requests.push(url.pathname);
            const wav = tone(url.pathname.includes('880') ? 880 : 440);
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range ?? '');
            const start = range ? Number(range[1]) : 0, end = range?.[2] ? Math.min(Number(range[2]), wav.length - 1) : wav.length - 1;
            return route.fulfill({ status: range ? 206 : 200, contentType: 'audio/wav', body: wav.subarray(start, end + 1),
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${wav.length}` } : {}) } });
        }
        return route.abort();
    });
    await page.goto('http://audio-preview.invalid/');
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
});

test('loop phase, muted tracks, source exhaustion and unavailable assets have explicit preview states', async () => {
    const result = await page.evaluate(async ({ doc, assets }) => {
        const output = document.createElement('canvas'); document.body.append(output);
        const preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
        try { await preview.seek(1.25); return preview.mediaState(); }
        finally { await preview.dispose(); output.remove(); }
    }, { doc: doc([track('loop', 1, { start: 0, duration: 3, trimStart: 0.5, trimEnd: 1.5, sourceOffset: 0.25, playbackRate: 2, loop: true }),
        track('muted', 1, { start: 0, duration: 3, muted: true }), track('eof', 2, { start: 0, duration: 3, trimEnd: 1, sourceOffset: 0 })]), assets });
    assert.ok(Math.abs(result.find(track => track.id === 'loop').sourceTime - 1.25) < 0.025, JSON.stringify(result));
    for (const id of ['muted', 'eof']) { const state = result.find(track => track.id === id); assert.equal(state.gain, 0); assert.equal(state.paused, true); }
    const error = await page.evaluate(async ({ doc }) => {
        const output = document.createElement('canvas'), preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets: [] });
        try { await preview.seek(1.5); return null; } catch (error) { return { message: error.message, remaining: preview.mediaState() }; }
        finally { await preview.dispose(); }
    }, { doc: doc([track('missing', 123)]) });
    assert.match(error.message, /recurso de audio válido/); assert.deepEqual(error.remaining, []);
});
after(async () => { await browser?.close(); });

test('closing during an unresponsive audio request aborts its decoder and queue immediately', async () => {
    let pending;
    await page.route('**/hang.wav', route => { pending = route; });
    try {
        const result = await page.evaluate(async ({ doc, assets }) => {
            const output = document.createElement('canvas'), nodes = [], original = document.createElement.bind(document);
            document.createElement = (...args) => { const node = original(...args); if (args[0] === 'audio') nodes.push(node); return node; };
            const preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
            const work = preview.seek(1.5).catch(() => {});
            await new Promise(resolve => setTimeout(resolve, 40));
            const start = performance.now();
            window.pendingAudioCleanup = preview.dispose();
            const finished = await Promise.race([window.pendingAudioCleanup.then(() => true), new Promise(resolve => setTimeout(() => resolve(false), 250))]);
            document.createElement = original;
            window.pendingAudioWork = work;
            return { finished, elapsed: performance.now() - start, count: nodes.length, released: nodes.every(node => !node.getAttribute('src') && node.paused) };
        }, { doc: doc([track('loading', 1)]), assets: [{ ...assets[0], preview_url: 'http://audio-preview.invalid/hang.wav' }] });
        assert.equal(result.count, 1);
        assert.equal(result.finished, true, JSON.stringify(result));
        assert.equal(result.released, true, JSON.stringify(result));
    } finally {
        await pending?.abort().catch(() => {});
        await page.evaluate(async () => { await window.pendingAudioWork; await window.pendingAudioCleanup; delete window.pendingAudioWork; delete window.pendingAudioCleanup; });
        await page.unroute('**/hang.wav');
    }
});

test('64 muted tracks do not allocate decoders or introduce buffering during visual playback', async () => {
    const result = await page.evaluate(async ({ doc, assets }) => {
        const output = document.createElement('canvas'), nodes = [], original = document.createElement.bind(document);
        document.createElement = (...args) => { const node = original(...args); if (args[0] === 'audio') nodes.push(node); return node; };
        const preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
        try {
            await preview.seek(0); // Canvas preparation is visual buffering, independent of muted audio.
            const samples = [];
            for (let frame = 0; frame < 10; frame++) samples.push(await preview.seek(frame / 30, { play: true }));
            return { decoders: nodes.length, state: preview.mediaState(), buffering: samples.reduce((sum, sample) => sum + sample.bufferingMs, 0) };
        } finally { await preview.dispose(); document.createElement = original; }
    }, { doc: doc(Array.from({ length: 64 }, (_, i) => track(`silent${i}`, 1, { start: 0, duration: 3, muted: true }))), assets });
    assert.equal(result.decoders, 0);
    assert.equal(result.buffering, 0);
    assert.equal(result.state.length, 64);
    assert.ok(result.state.every(state => state.paused && state.gain === 0));
});

test('paused seeks honor a sub-40ms source trim at slow playback rates', async () => {
    const result = await page.evaluate(async ({ doc, assets }) => {
        const preview = await AudioPreview.createTimelinePreview(document.createElement('canvas'), { document: doc, assets });
        try { await preview.seek(0); return preview.mediaState()[0]; }
        finally { await preview.dispose(); }
    }, { doc: doc([track('precise', 1, { start: 0, duration: 3, trimStart: 0.039, sourceOffset: 0, playbackRate: 0.25 })]), assets });
    assert.ok(Math.abs(result.sourceTime - 0.039) / 0.25 < 1 / 30, JSON.stringify(result));
});

const track = (id, asset_id, overrides = {}) => ({ id, asset_id, start: 1, duration: 2, trimStart: 0.2, trimEnd: 3,
    playbackRate: 1.5, sourceOffset: 0.3, loop: false, volume: 0.5, muted: false, ...overrides });
const doc = tracks => ({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: tracks },
    pages: [{ id: 'one', duration: 4, background: '#ffffff', elements: [] }] });
const assets = [440, 880].map((frequency, i) => ({ id: i + 1, kind: 'audio', name: `tone${frequency}`, duration: 4,
    audio_codec: 'pcm_s16le', status: 'listo', preview_url: `http://audio-preview.invalid/${frequency}.wav` }));

test('preview seeks real private audio with trim/rate/envelope and releases it outside its window', async () => {
    requests.length = 0;
    const result = await page.evaluate(async ({ doc, assets }) => {
        const output = document.createElement('canvas'); document.body.append(output);
        const nodes = [], original = document.createElement.bind(document);
        document.createElement = (...args) => { const node = original(...args); if (args[0] === 'audio') nodes.push(node); return node; };
        const preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
        const before = JSON.stringify(doc);
        try {
            await preview.seek(0.5); const unallocated = nodes.length;
            await preview.seek(1.5); const state = preview.mediaState();
            const actual = nodes.map(node => ({ time: node.currentTime, rate: node.playbackRate, paused: node.paused }));
            await preview.seek(3); const released = nodes.every(node => node.paused && !node.getAttribute('src'));
            return { unallocated, state, actual, released, unchanged: JSON.stringify(doc) === before };
        } finally { await preview.dispose(); document.createElement = original; output.remove(); }
    }, { doc: doc([track('voice', 1, { gainPoints: [{ time: 0, value: 0 }, { time: 2, value: 1 }] })]), assets });
    assert.equal(result.unallocated, 0);
    const audio = result.state.find(item => item.id === 'voice');
    assert.ok(audio, 'the preview must include the audio track');
    assert.ok(Math.abs(audio.sourceTime - 1.25) < 0.025, JSON.stringify(result));
    assert.ok(Math.abs(audio.gain - 0.125) < 0.001);
    assert.equal(result.actual[0].rate, 1.5);
    assert.equal(result.actual[0].paused, true);
    assert.equal(result.released, true);
    assert.equal(result.unchanged, true);
    assert.ok(requests.length > 0);
});

test('two real tones mix through independent gain nodes and pause without mutating tracks', async () => {
    const result = await page.evaluate(async ({ doc, assets }) => {
        const meters = [], original = AudioContext.prototype.createGain;
        AudioContext.prototype.createGain = function () {
            const gain = original.call(this), analyser = this.createAnalyser(); analyser.fftSize = 8192;
            gain.connect(analyser); meters.push({ analyser, context: this }); return gain;
        };
        const output = document.createElement('canvas'); document.body.append(output);
        const preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
        const measure = () => meters.map(({ analyser, context }) => {
            const data = new Float32Array(analyser.fftSize); analyser.getFloatTimeDomainData(data);
            const spectrum = new Float32Array(analyser.frequencyBinCount); analyser.getFloatFrequencyData(spectrum);
            let peak = 1; for (let i = 2; i < spectrum.length; i++) if (spectrum[i] > spectrum[peak]) peak = i;
            return { rms: Math.sqrt(data.reduce((sum, value) => sum + value * value, 0) / data.length), hz: peak * context.sampleRate / analyser.fftSize };
        });
        const before = JSON.stringify(doc);
        try {
            await preview.seek(0.25);
            document.querySelector('#play').onclick = async () => {
                try { await preview.seek(0.25, { play: true }); window.audioProbeDone = true; }
                catch (error) { window.audioProbeError = error.message; }
            };
            window.audioProbe = { preview, measure, before, doc, output, restore: () => { AudioContext.prototype.createGain = original; } };
            return { prepared: true };
        } catch (error) { await preview.dispose(); AudioContext.prototype.createGain = original; output.remove(); throw error; }
    }, { doc: doc([track('low', 1, { start: 0, duration: 3, playbackRate: 1, volume: 0.5 }),
        track('high', 2, { start: 0, duration: 3, playbackRate: 1, volume: 0.25 })]), assets });
    assert.equal(result.prepared, true);
    await page.locator('#play').click();
    try {
        await page.waitForFunction(() => window.audioProbeDone || window.audioProbeError);
        assert.equal(await page.evaluate(() => window.audioProbeError), undefined);
        // Hasta que el analizador llena su ventana con las dos pistas (evento observable, no un tiempo fijo).
        await page.waitForFunction(() => { const m = window.audioProbe.measure(); return m.length === 2 && m[0].rms > 0.10 && m[1].rms > 0.05; });
        const measured = await page.evaluate(() => ({ meters: window.audioProbe.measure(), state: window.audioProbe.preview.mediaState() }));
        assert.equal(measured.meters.length, 2);
        assert.ok(Math.abs(measured.meters[0].hz - 440) < 10, JSON.stringify(measured));
        assert.ok(Math.abs(measured.meters[1].hz - 880) < 10, JSON.stringify(measured));
        assert.ok(measured.meters[0].rms > 0.10 && measured.meters[0].rms < 0.15, JSON.stringify(measured));
        assert.ok(measured.meters[1].rms > 0.05 && measured.meters[1].rms < 0.08, JSON.stringify(measured));
        assert.ok(measured.state.every(track => !track.paused));
        await page.evaluate(() => window.audioProbe.preview.pause());
        // Hasta que el analizador vacía su ventana: la pausa ha silenciado las dos pistas.
        await page.waitForFunction(() => window.audioProbe.measure().every(meter => meter.rms < 0.0001));
        const paused = await page.evaluate(() => ({ meters: window.audioProbe.measure(), state: window.audioProbe.preview.mediaState(),
            unchanged: window.audioProbe.before === JSON.stringify(window.audioProbe.doc) }));
        assert.ok(paused.meters.every(meter => meter.rms < 0.0001), JSON.stringify(paused));
        assert.ok(paused.state.every(track => track.paused));
        assert.equal(paused.unchanged, true);
    } finally {
        await page.evaluate(async () => { const probe = window.audioProbe; await probe.preview.dispose(); probe.restore(); probe.output.remove(); delete window.audioProbe; delete window.audioProbeDone; delete window.audioProbeError; });
    }
});

test('a real video and a streaming audio track follow the same clock within one output frame', async () => {
    const source = doc([track('sound', 1, { start: 0, duration: 3, playbackRate: 1.25 })]);
    source.pages[0].elements.push(newElement('video', { id: 'visual', asset_id: 3, width: 64, height: 64, muted: true,
        trimStart: 0.2, trimEnd: 3, sourceOffset: 0.3, playbackRate: 1.25, timing: { start: 0, duration: 3 } }));
    await page.evaluate(async ({ doc, assets }) => {
        const output = document.createElement('canvas'), preview = await AudioPreview.createTimelinePreview(output, { document: doc, assets });
        await preview.seek(0.5);
        window.avProbe = { preview, before: JSON.stringify(doc), doc, frames: [] };
        document.querySelector('#play').onclick = async () => {
            try {
                await preview.seek(0.5, { play: true });
                let origin = performance.now() - 500;
                for (let i = 0; i < 20; i++) {
                    await new Promise(resolve => requestAnimationFrame(resolve));
                    const time = (performance.now() - origin) / 1000;
                    const sample = await preview.seek(time, { play: true }); origin += sample.bufferingMs;
                    window.avProbe.frames.push({ time, media: preview.mediaState() });
                }
                preview.pause(); window.avProbe.done = true;
            } catch (error) { window.avProbe.error = error.message; }
        };
    }, { doc: source, assets: [...assets, { id: 3, kind: 'video', status: 'listo', duration: 4, preview_url: 'http://audio-preview.invalid/video.mp4' }] });
    try {
        await page.locator('#play').click(); await page.waitForFunction(() => window.avProbe.done || window.avProbe.error);
        const result = await page.evaluate(() => ({ ...window.avProbe, preview: undefined }));
        assert.equal(result.error, undefined);
        assert.equal(result.frames.length, 20);
        const errors = result.frames.map(frame => {
            const audio = frame.media.find(item => item.id === 'sound'), video = frame.media.find(item => item.id === 'visual');
            return Math.abs(audio.sourceTime - video.sourceTime) / 1.25;
        });
        assert.ok(Math.max(...errors) <= 1 / 30, JSON.stringify({ maxOutputDrift: Math.max(...errors), errors }));
        assert.equal(result.before, JSON.stringify(result.doc));
    } finally { await page.evaluate(async () => { await window.avProbe.preview.dispose(); delete window.avProbe; }); }
});
