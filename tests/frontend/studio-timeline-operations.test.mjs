import test from 'node:test';
import assert from 'node:assert/strict';
import { newElement, validateDocument } from '../../resources/js/Studio/document.js';
import { compileTimeline, sampleTimeline, clipMediaState, splitTimelineElement, splitTimelineAudio, restrictElementWindow } from '../../resources/js/Studio/timeline.js';

const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-8, `${actual} != ${expected}`);
const page = (id, duration = 5, elements = []) => ({ id, name: id, duration, background: '#ffffff', elements });
const scene = (pages, audio_tracks = [], fps = 30) => validateDocument({ schema_version: 2, width: 320, height: 180, timeline: { fps, audio_tracks }, pages });
const video = () => newElement('video', { id: 'clip', asset_id: 1, width: 320, height: 180, trimStart: 2, trimEnd: 6, sourceOffset: 0.5,
    playbackRate: 2, loop: true, volume: 0.8, timing: { start: 1, duration: 3 }, gainPoints: [{ time: 0, value: 0 }, { time: 1, value: 1 }, { time: 3, value: 0 }] });

test('page clock overlaps transitions and samples visual and audio gains at exact project time', () => {
    const first = page('one'); first.transition = { type: 'fade', duration: 1 };
    const second = page('two', 3); second.transition = { type: 'slide_left', duration: 0.5 };
    const doc = scene([first, second, page('three', 2)]);
    const plan = compileTimeline(doc);
    assert.deepEqual(plan.pages.map(item => item.start), [0, 4, 6.5]);
    near(plan.duration, 8.5); assert.equal(plan.frameCount, 255);
    const fading = sampleTimeline(plan, 4.5);
    assert.deepEqual(fading.pages.map(item => item.id), ['one', 'two']);
    for (const item of fading.pages) { near(item.opacity, 0.5); near(item.audioGain, 0.5); }
    const sliding = sampleTimeline(plan, 6.75);
    assert.deepEqual(sliding.pages.map(item => item.translateX), [-160, 160]);
    for (const item of sliding.pages) { near(item.opacity, 1); near(item.audioGain, 0.5); }
    assert.equal(sampleTimeline(plan, 8.5).pages.length, 0);
    assert.equal(sampleTimeline(plan, 8.5).ended, true);
});

test('rounding cumulative boundaries preserves 100 fractional pages without project drift or frame gaps', () => {
    const plan = compileTimeline(scene(Array.from({ length: 100 }, (_, i) => page(`page_${i}`, 0.1)), [], 24));
    assert.equal(plan.frameCount, 240); near(plan.duration, 10);
    for (let frame = 0; frame < plan.frameCount; frame++) assert.equal(sampleTimeline(plan, frame / 24).pages.length, 1, `frame ${frame}`);
});

test('media sampling uses trim, rate and loop phase while the half-open end remains silent', () => {
    const clip = video();
    const sample = clipMediaState(clip, 2.75, 10, 5);
    assert.equal(sample.active, true); near(sample.sourceTime, 2); near(sample.gain, 0.5);
    assert.equal(clipMediaState(clip, 4, 10, 5).active, false);
    assert.equal(clipMediaState(clip, 4, 10, 5).gain, 0);
    const held = clipMediaState({ ...clip, loop: false }, 3, 10, 5);
    assert.equal(held.active, true); near(held.sourceTime, 6); near(held.gain, 0);
});

test('splitting a visual clip preserves geometry, loop phase and the original gain curve without mutating input', () => {
    const doc = scene([page('one', 5, [video()])]);
    const before = JSON.stringify(doc);
    const result = splitTimelineElement(doc, 'one', 'clip', 2.25, { sourceDuration: 10, createId: () => 'clip_right' });
    assert.equal(JSON.stringify(doc), before);
    const [left, right] = result.pages[0].elements;
    assert.equal(left.id, 'clip'); assert.equal(right.id, 'clip_right');
    assert.deepEqual(left.timing, { start: 1, duration: 1.25 });
    assert.deepEqual(right.timing, { start: 2.25, duration: 1.75 });
    near(right.sourceOffset, 3); near(right.gainPoints[0].value, 0.875);
    assert.equal(right.width, doc.pages[0].elements[0].width);
    const original = clipMediaState(doc.pages[0].elements[0], 3.75, 10, 5);
    const copied = clipMediaState(right, 3.75, 10, 5);
    near(copied.sourceTime, original.sourceTime); near(copied.gain, original.gain);
    assert.doesNotThrow(() => validateDocument(result));
});

test('audio split preserves global time, phase and fade continuity and rejects subframe cuts', () => {
    const audio = { id: 'music', asset_id: 2, start: 0, duration: 8, trimStart: 0, trimEnd: 2, sourceOffset: 0.2, loop: true, volume: 0.5,
        gainPoints: [{ time: 0, value: 0 }, { time: 2, value: 1 }, { time: 7, value: 1 }, { time: 8, value: 0 }] };
    const doc = scene([page('one', 8)], [audio]);
    const result = splitTimelineAudio(doc, 'music', 3, { sourceDuration: 2, createId: () => 'music_right' });
    const [left, right] = result.timeline.audio_tracks;
    assert.equal(left.duration, 3); assert.equal(right.start, 3); assert.equal(right.duration, 5); near(right.sourceOffset, 1.2);
    near(clipMediaState(right, 7.5, 2).sourceTime, clipMediaState(audio, 7.5, 2).sourceTime);
    near(clipMediaState(right, 7.5, 2).gain, 0.25);
    assert.doesNotThrow(() => validateDocument(result));
    assert.throws(() => splitTimelineAudio(doc, 'music', 0.001, { sourceDuration: 2 }), /fotograma|corte|intervalo/);
});

test('decimal source boundaries and a one-frame split survive floating-point representation', () => {
    const clip = { trimStart: 0.1, trimEnd: 0.3, sourceOffset: 0.2, loop: true, timing: { start: 0, duration: 1 } };
    near(clipMediaState(clip, 0, 1, 1).sourceTime, 0.1);
    const element = newElement('text', { id: 'caption', timing: { start: 0.1, duration: 1 } });
    const doc = scene([page('one', 2, [element])]);
    const result = splitTimelineElement(doc, 'one', 'caption', 4 / 30, { createId: () => 'caption_right' });
    assert.doesNotThrow(() => validateDocument(result));
});

test('split operations reject a result beyond the validated layer and audio limits', () => {
    const layers = scene([page('one', 5, Array.from({ length: 500 }, (_, i) => newElement('rect', { id: `layer_${i}` })))]);
    assert.throws(() => splitTimelineElement(layers, 'one', 'layer_0', 2.5), /500|capas|límite/);
    const tracks = Array.from({ length: 64 }, (_, i) => ({ id: `audio_${i}`, asset_id: 2, start: 0, duration: 4, trimStart: 0, trimEnd: 4 }));
    const audio = scene([page('one')], tracks);
    assert.throws(() => splitTimelineAudio(audio, 'audio_0', 2, { sourceDuration: 4 }), /64|pistas|límite/);
});

test('splitting cannot return a document beyond the UTF-8 byte budget', () => {
    const doc = scene([page('one', 5, Array.from({ length: 51 }, (_, i) => newElement('text', { id: `title_${i}`, text: '😀'.repeat(10000) })))]);
    assert.ok(new TextEncoder().encode(JSON.stringify(doc)).length < 2 * 1024 * 1024);
    assert.throws(() => splitTimelineElement(doc, 'one', 'title_0', 2.5), /2 MiB|tamaño|document/);
});

test('cropping a nonzero clip origin keeps the gain endpoint equal to the resulting duration', () => {
    const clip = newElement('video', { id: 'clip', asset_id: 1, trimStart: 0, trimEnd: 10, timing: { start: 0.01, duration: 3 },
        gainPoints: [{ time: 0, value: 0 }, { time: 3, value: 1 }] });
    const doc = scene([page('one', 5, [clip])]);
    const result = restrictElementWindow(doc.pages[0].elements[0], { start: 0.3, duration: 1.8 }, { pageDuration: 5, sourceDuration: 10 });
    doc.pages[0].elements = [result];
    assert.equal(result.gainPoints.at(-1).time, result.timing.duration);
    assert.doesNotThrow(() => validateDocument(doc));
});
