import test from 'node:test';
import assert from 'node:assert/strict';
import { newElement } from '../../resources/js/Studio/document.js';
import { compileAudiovisual, audioStateAt } from '../../resources/js/Studio/audiovisual-plan.js';

const doc = (pages, tracks = [], fps = 30) => ({ schema_version: 2, width: 100, height: 100,
    timeline: { fps, audio_tracks: tracks }, pages });
const page = (id, duration, elements = [], transition) => ({ id, duration, background: '#ffffff', elements, ...(transition ? { transition } : {}) });
const asset = (id, duration = 10, kind = 'audio') => ({ id, duration, kind, audio_codec: 'pcm_s16le', status: 'listo' });
const track = (id, asset_id, start, duration, rest = {}) => ({ id, asset_id, start, duration, ...rest });

test('segments cover the accumulated project exactly once in frames and PCM samples', () => {
    for (const fps of [12, 15, 24, 25, 30, 50, 60]) {
        const input = doc(Array.from({ length: 100 }, (_, i) => page(`p${i}`, 0.137)), [], fps);
        const plan = compileAudiovisual(input, [], { segmentFrames: 7 });
        assert.equal(plan.frameCount, Math.round(13.7 * fps));
        assert.equal(plan.sampleRate, 48000);
        assert.equal(plan.segments.at(-1).frameStart + plan.segments.at(-1).frameCount, plan.frameCount);
        let frames = 0, samples = 0;
        for (const segment of plan.segments) {
            assert.equal(segment.frameStart, frames); assert.equal(segment.sampleStart, samples);
            assert.ok(segment.frameCount > 0 && segment.frameCount <= 7);
            frames += segment.frameCount; samples += segment.sampleCount;
        }
        assert.equal(samples, plan.frameCount * 48000 / fps);
    }
});

test('inherited video windows preserve source phase and envelope instead of restarting at the group boundary', () => {
    const video = newElement('video', { id: 'v', asset_id: 1, timing: { start: 0.5, duration: 3 },
        trimStart: 1, trimEnd: 7, sourceOffset: 0.3, playbackRate: 2, volume: 0.5,
        gainPoints: [{ time: 0, value: 0 }, { time: 3, value: 1 }] });
    const group = newElement('group', { id: 'g', timing: { start: 1, duration: 1 }, elements: [video] });
    const input = doc([page('p', 4, [group])]); const before = JSON.stringify(input);
    const plan = compileAudiovisual(input, [asset(1, 10, 'video')]);
    assert.equal(plan.audio.length, 1); assert.equal(plan.audio[0].start, 1); assert.equal(plan.audio[0].end, 2);
    assert.deepEqual(audioStateAt(plan, 0.99), []);
    const [sample] = audioStateAt(plan, 1.5);
    assert.ok(Math.abs(sample.sourceTime - 3.3) < 1e-9);
    assert.ok(Math.abs(sample.gain - 1 / 6) < 1e-9);
    assert.deepEqual(audioStateAt(plan, 2), []); assert.equal(JSON.stringify(input), before);
});

test('page audio crossfades on the same accumulated clock while global music stays continuous', () => {
    for (const type of ['fade', 'slide_left', 'slide_right']) {
        const layer = (id, asset_id) => newElement('video', { id, asset_id });
        const plan = compileAudiovisual(doc([page('a', 2, [layer('v1', 1)], { type, duration: 1 }),
            page('b', 2, [layer('v2', 2)])], [track('music', 3, 0, 3, { volume: 0.25 })]),
        [asset(1, 10, 'video'), asset(2, 10, 'video'), asset(3)]);
        assert.equal(plan.frameCount, 90);
        const states = audioStateAt(plan, 1.5); assert.equal(states.length, 3);
        const byId = Object.fromEntries(states.map(sample => [sample.id, sample]));
        assert.equal(byId.v1.gain, 0.5); assert.equal(byId.v2.gain, 0.5); assert.equal(byId.music.gain, 0.25);
        assert.equal(byId.v1.sourceTime, 1.5); assert.equal(byId.v2.sourceTime, 0.5); assert.equal(byId.music.sourceTime, 1.5);
    }
});

test('audio source trim, offset, slow rate, loop and EOF use a semi-open interval', () => {
    const plan = compileAudiovisual(doc([page('p', 5)], [
        track('once', 1, 0.5, 4, { trimStart: 1, trimEnd: 2, sourceOffset: 0.25, playbackRate: 0.5 }),
        track('loop', 1, 0.5, 4, { trimStart: 1, trimEnd: 2, sourceOffset: 0.25, playbackRate: 2, loop: true }),
    ]), [asset(1)]);
    const at = (id, time) => audioStateAt(plan, time).find(sample => sample.id === id);
    assert.equal(at('once', 1.5).sourceTime, 1.75);
    assert.equal(at('once', 2), undefined); assert.equal(at('loop', 2).sourceTime, 1.25);
    assert.equal(at('loop', 4.5), undefined);
});

test('muted, silent, invisible and non-intersecting video layers never become audio inputs', () => {
    const layers = [newElement('video', { id: 'mute', asset_id: 1, muted: true }),
        newElement('video', { id: 'audible', asset_id: 1, volume: 0.5 }),
        newElement('video', { id: 'zero', asset_id: 1, volume: 0 }), newElement('video', { id: 'silent', asset_id: 2 }),
        newElement('group', { id: 'hidden', visible: false, elements: [newElement('video', { id: 'hidden_child', asset_id: 99 })] }),
        newElement('group', { id: 'disjoint', timing: { start: 0, duration: 1 }, elements: [
            newElement('video', { id: 'late', asset_id: 99, timing: { start: 2, duration: 1 } }),
        ] })];
    const plan = compileAudiovisual(doc([page('p', 4, layers)]), [asset(1, 10, 'video'), { ...asset(2, 10, 'video'), audio_codec: null }]);
    assert.deepEqual(plan.audio.map(input => input.id), ['audible']);
});

test('audio compilation rejects unready, duplicate, missing and out-of-source metadata and invalid segment budgets', () => {
    const input = doc([page('p', 2)], [track('music', 1, 0, 2, { trimStart: 0.5, trimEnd: 1.5 })]);
    for (const assets of [[], [asset(1, 1)], [{ ...asset(1), status: 'pendiente' }], [asset(1), asset(1)], [{ ...asset(1), duration: NaN }]]) {
        assert.throws(() => compileAudiovisual(input, assets), /recurso|origen|audio|duplicad/i);
    }
    for (const segmentFrames of [0, -1, 1.5, NaN, 61, '10']) {
        assert.throws(() => compileAudiovisual(input, [asset(1)], { segmentFrames }), /tramo/i);
    }
});
