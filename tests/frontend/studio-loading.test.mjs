import test from 'node:test';
import assert from 'node:assert/strict';
import { seekVideoFrame, waitForStudioFonts, videoPlaybackRange } from '../../resources/js/Studio/canvas.js';

class Video extends EventTarget {
    currentTime = 0;
    duration = 12;
}

test('video seek resolves a decoded frame, clamps duration and supports cancellation', async () => {
    const video = new Video();
    const pending = seekVideoFrame(video, 99, { timeout: 100 });
    assert.equal(video.currentTime, 11.99);
    video.dispatchEvent(new Event('seeked'));
    await pending;
    const controller = new AbortController();
    const cancelled = seekVideoFrame(video, 3, { signal: controller.signal, timeout: 100 });
    controller.abort();
    await assert.rejects(cancelled, { name: 'AbortError' });
});

test('video seek reports a stalled decoder and releases its listeners', async () => {
    const video = new Video();
    await assert.rejects(seekVideoFrame(video, 4, { timeout: 10 }), /fotograma/);
    video.dispatchEvent(new Event('seeked'));
    await seekVideoFrame(video, 4, { timeout: 10 });
});

test('font loading fails explicitly if the font service never settles', async () => {
    const original = globalThis.document;
    globalThis.document = { fonts: { load: () => new Promise(() => {}), ready: Promise.resolve() } };
    try {
        await assert.rejects(waitForStudioFonts([{ type: 'text', fontFamily: 'Arial', text: 'Hola' }], { timeout: 10 }), /fuentes/);
    } finally {
        if (original === undefined) delete globalThis.document;
        else globalThis.document = original;
    }
});

test('video playback honors trimmed endpoints and the actual media duration', () => {
    const video = new Video();
    assert.deepEqual(videoPlaybackRange(video, { trimStart: 2, trimEnd: 8 }), { start: 2, end: 8 });
    assert.deepEqual(videoPlaybackRange(video, { trimStart: 2, trimEnd: 20 }), { start: 2, end: 12 });
    assert.deepEqual(videoPlaybackRange(video, { trimStart: 20 }), { start: 11.99, end: 12 });
});
