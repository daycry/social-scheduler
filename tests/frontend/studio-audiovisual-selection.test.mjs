import test from 'node:test';
import assert from 'node:assert/strict';
import { newElement } from '../../resources/js/Studio/document.js';
import { compileAudiovisual, audioStateAt } from '../../resources/js/Studio/audiovisual-plan.js';

const page = (id, duration = 2, transition) => ({ id, duration, background: '#ffffff', elements: [], ...(transition ? { transition } : {}) });
const doc = (pages, tracks = [], fps = 30) => ({ schema_version: 2, width: 100, height: 100, pages, timeline: { fps, audio_tracks: tracks } });
const asset = (id, duration = 20, kind = 'audio') => ({ id, duration, kind, status: 'listo', audio_codec: 'pcm_f32le' });
const music = duration => ({ id: 'music', asset_id: 1, start: 0, duration });

test('audiovisual selection preserves explicit order, removes only the final transition and leaves the source intact', () => {
    const input = doc([page('a', 2, { type: 'fade', duration: 0.5 }), page('b'), page('c')]);
    const before = JSON.stringify(input), plan = compileAudiovisual(input, [], { pageIds: ['c', 'a'] });
    assert.deepEqual(plan.document.pages.map(page => page.id), ['c', 'a']);
    assert.equal(plan.frameCount, 120); assert.equal(plan.document.pages[1].transition, undefined);
    assert.equal(JSON.stringify(input), before);
});

test('audiovisual page selection rejects unknown, duplicate, empty, sparse and mistyped IDs and impossible new adjacency', () => {
    const input = doc([page('a', 4, { type: 'fade', duration: 1 }), page('b', 4), page('short', 0.5)]);
    for (const pageIds of [[], null, {}, ['missing'], ['a', 'a'], ['a', null], [1], new Array(1), ['a', 'short']]) {
        assert.throws(() => compileAudiovisual(input, [], { pageIds }), /página|pages|transici|selecci/i, JSON.stringify(pageIds));
    }
});

test('global music follows a selected source interval while preserving envelope, trim and phase', () => {
    const input = doc([page('a'), page('b'), page('c')], [{ ...music(6), trimStart: 1, trimEnd: 11, sourceOffset: 0.2,
        playbackRate: 1.5, volume: 0.5, gainPoints: [{ time: 0, value: 0 }, { time: 6, value: 1 }] }]);
    const before = JSON.stringify(input), plan = compileAudiovisual(input, [asset(1)], { pageIds: ['b'] });
    assert.equal(plan.duration, 2); assert.equal(plan.audio.length, 1);
    const [sample] = audioStateAt(plan, 0.5);
    assert.ok(Math.abs(sample.sourceTime - (1 + 0.2 + 2.5 * 1.5)) < 1e-9);
    assert.ok(Math.abs(sample.gain - 0.5 * 2.5 / 6) < 1e-9);
    assert.equal(JSON.stringify(input), before);
});

test('contiguous fractional pages use a single source clock without duplicating music in their internal transition', () => {
    const input = doc([page('omitted', 0.137), page('a', 0.537, { type: 'fade', duration: 0.137 }), page('b', 0.537)], [music(1.074)]);
    const full = compileAudiovisual(input, [asset(1)]), selected = compileAudiovisual(input, [asset(1)], { pageIds: ['a', 'b'] });
    assert.equal(selected.audio.length, 1);
    const sourceStart = full.timeline.pages[1].start;
    for (let frame = 0; frame < selected.frameCount; frame++) {
        const time = frame / selected.fps, states = audioStateAt(selected, time);
        if (time + sourceStart >= 1.074) continue;
        assert.equal(states.length, 1); assert.ok(Math.abs(states[0].sourceTime - time - sourceStart) < 1e-9);
        assert.equal(states[0].gain, 1, `internal transition changed global gain at ${time}`);
    }
});

test('reordered source intervals crossfade only their boundary and retain native video audio on selected pages', () => {
    const a = page('a', 2, { type: 'fade', duration: 1 }); a.elements = [newElement('video', { id: 'video_a', asset_id: 2 })];
    const input = doc([a, page('b'), page('c')], [music(5)]);
    const plan = compileAudiovisual(input, [asset(1), asset(2, 10, 'video')], { pageIds: ['a', 'c'] });
    assert.equal(plan.duration, 3);
    const states = audioStateAt(plan, 1.5), global = states.filter(sample => sample.assetId === 1), native = states.find(sample => sample.id === 'video_a');
    assert.equal(global.length, 2);
    assert.deepEqual(global.map(sample => sample.gain), [0.5, 0.5]);
    assert.deepEqual(global.map(sample => sample.sourceTime), [1.5, 3.5]);
    assert.equal(native.gain, 0.5); assert.equal(native.sourceTime, 1.5);
});

test('legacy audiovisual conversion stays in memory and preserves an exhausted poster without audio', () => {
    const input = { schema_version: 1, width: 100, height: 100, pages: [page('old'), page('other')] };
    input.pages[0].elements = [newElement('video', { id: 'v', asset_id: 1, trimStart: 100 })];
    input.pages[1].elements = [newElement('video', { id: 'unused', asset_id: 99, trimStart: 100 })];
    const before = JSON.stringify(input), plan = compileAudiovisual(input, [asset(1, 4, 'video')], { pageIds: ['old'] });
    assert.equal(plan.document.schema_version, 2); assert.equal(plan.fps, 30);
    const video = plan.document.pages[0].elements[0];
    assert.ok(video.trimStart < 4 && video.trimStart > 3.9); assert.equal(video.muted, true);
    assert.deepEqual(plan.audio, []); assert.equal(JSON.stringify(input), before);
});

test('render settings preserve default quality and scale geometry without changing timeline duration', () => {
    const input = doc([page('p')]), normal = compileAudiovisual(input), half = compileAudiovisual(input, [], { scale: 0.5, quality: 0.1 });
    assert.equal(normal.render.crf, 18); assert.equal(normal.render.paletteColors, 256);
    assert.equal(half.render.width, 50); assert.equal(half.render.height, 50); assert.equal(half.render.crf, 40); assert.equal(half.render.paletteColors, 16);
    assert.equal(half.frameCount, normal.frameCount); assert.equal(half.document.width, 100);
    const odd = compileAudiovisual({ ...input, width: 101, height: 103 }, [], { scale: 0.5, quality: 1 });
    assert.equal(odd.render.width, 51); assert.equal(odd.render.height, 52); assert.equal(odd.render.paddedWidth, 52); assert.equal(odd.render.crf, 16);
});

test('render settings reject non-numeric options and an oversized result including even padding', () => {
    for (const bad of [null, '1', NaN, Infinity, 0, -1, 5]) {
        assert.throws(() => compileAudiovisual(doc([page('p')]), [], { scale: bad }), /escala|scale/i);
    }
    for (const bad of [null, '1', NaN, Infinity, 0, 1.1]) {
        assert.throws(() => compileAudiovisual(doc([page('p')]), [], { quality: bad }), /calidad|quality/i);
    }
    assert.throws(() => compileAudiovisual({ ...doc([page('p')]), width: 4001, height: 3999 }), /resoluci|megap|límite/i);
});
