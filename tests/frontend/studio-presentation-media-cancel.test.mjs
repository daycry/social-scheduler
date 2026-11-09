import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse } from '@babel/parser';
import { createPresentationPlayer } from '../../resources/js/Studio/presentation-player.js';
import { validateDocument, newElement } from '../../resources/js/Studio/document.js';
import { compileTimeline, sampleTimeline, clipMediaState } from '../../resources/js/Studio/timeline.js';

// Execute the exact production factory, replacing only its browser-facing imports.
// The player, document validation, compiled clock, sampling and clip timing stay real.
const source = await readFile(new URL('../../resources/js/Studio/timeline-preview.js', import.meta.url), 'utf8');
const ast = parse(source, { sourceType: 'module' });
const factory = ast.program.body.find(node => node.type === 'ExportNamedDeclaration' && node.declaration?.id?.name === 'createTimelinePreview').declaration;
const instantiate = Function('validateDocument', 'renderStudioPage', 'seekVideoFrame', 'clipMediaState', 'compileTimeline', 'sampleTimeline',
    'createTimelineAudio', 'createCaptionLayer', 'assertChartDocumentBudget', `return (${source.slice(factory.start, factory.end)});`);
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const document = () => ({ schema_version: 2, width: 320, height: 180, timeline: { fps: 30, audio_tracks: [] }, pages: [
    { id: 'a', duration: 1, background: '#ff0000', elements: [newElement('video', { id: 'clip', asset_id: 1 })] },
    { id: 'b', duration: 1, background: '#0000ff', elements: [] },
] });
function fixture() {
    const calls = [], published = []; let now = 0, callback, blocker, seekId = 0, resolvedSeek = 0;
    const context = { clearRect() {}, save() {}, restore() {}, drawImage() {}, fillRect() {} };
    const canvas = { getContext: () => context };
    const video = { duration: 4, currentTime: 0, paused: true, volume: 1,
        pause() { this.paused = true; calls.push({ type: 'pause' }); },
        async play() { this.paused = false; calls.push({ type: 'play', seek: resolvedSeek }); } };
    const object = { studioVideo: video, set() {} };
    const createRenderer = instantiate(validateDocument,
        async (_document, pageId) => ({ canvas: { getObjects: () => pageId === 'a' ? [object] : [], renderAll() {}, lowerCanvasEl: {} }, async dispose() { video.pause(); } }),
        async (target, time) => {
            const id = ++seekId, wait = blocker; blocker = null;
            if (wait) { wait.entered.resolve(id); await wait.promise; }
            target.currentTime = time; resolvedSeek = id;
        }, clipMediaState, compileTimeline, sampleTimeline,
        () => ({ prepare: async () => ({ bufferingMs: 0 }), pause() {}, pauseMedia() {}, play: async () => {}, state: () => [], dispose: async () => {} }),
        async () => null, async () => {});
    const player = createPresentationPlayer({ document: document(), output: canvas, createRenderer: (_canvas, options) => createRenderer(canvas, options),
        now: () => now, requestFrame: cb => { callback = cb; return 1; }, cancelFrame: () => { callback = null; },
        publish: (_canvas, state) => published.push(state) });
    return { player, calls, published, createRenderer, canvas, video,
        block() { const wait = deferred(); wait.entered = deferred(); blocker = wait; return wait; },
        tick(time) { now = time; const cb = callback; callback = null; assert.ok(cb, 'autoplay frame must be scheduled'); return cb(); } };
}

for (const action of ['pause', 'close', 'navigation']) test(`real preview never starts old video after ${action} during frame seek`, async () => {
    const f = fixture(); await f.player.ready(); await f.player.play();
    const wait = f.block(), frame = f.tick(100), oldSeek = await wait.entered.promise;
    const publishedBefore = f.published.length, callsBefore = f.calls.length;
    const command = action === 'pause' ? f.player.pause() : action === 'close' ? f.player.dispose() : f.player.next();
    wait.resolve(); await Promise.all([frame, command]);
    assert.equal(f.calls.slice(callsBefore).filter(call => call.type === 'play' && call.seek === oldSeek).length, 0,
        'cancelled frame must never call video.play, even transiently before re-pausing');
    assert.equal(f.player.snapshot().pageId, action === 'navigation' ? 'b' : 'a');
    if (action !== 'navigation') assert.equal(f.published.length, publishedBefore);
    if (action === 'pause') assert.equal(f.player.snapshot().playing, false);
    await f.player.dispose();
});

test('uninterrupted real preview still starts video after the deferred frame seek', async () => {
    const f = fixture(); await f.player.ready(); await f.player.play();
    f.video.pause();
    const wait = f.block(), frame = f.tick(100), seek = await wait.entered.promise;
    wait.resolve(); await frame;
    assert.ok(f.calls.some(call => call.type === 'play' && call.seek === seek));
    assert.equal(f.player.snapshot().playing, true); assert.equal(f.player.snapshot().mediaTime, .1);
    await f.player.dispose();
});

test('an older failed video seek cannot pause or cancel a newer preview request', async () => {
    const f = fixture(), preview = await f.createRenderer(f.canvas, { document: document() });
    const wait = f.block(), old = preview.seek(.1, { play: true }), oldResult = assert.rejects(old, /old decoder failure/);
    await wait.entered.promise;
    const latest = preview.seek(.2, { play: true });
    wait.reject(new Error('old decoder failure'));
    await oldResult; await latest;
    assert.ok(f.calls.some(call => call.type === 'play'), 'newer request must retain permission to start video');
    assert.equal(preview.mediaState().find(item => item.id === 'clip')?.paused, false);
    await preview.dispose(); await f.player.dispose();
});
