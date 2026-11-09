import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, newElement, validateDocument } from '../../resources/js/Studio/document.js';

function temporalScene() {
    const doc = blankDocument(320, 180);
    doc.schema_version = 2;
    doc.timeline = { fps: 30, audio_tracks: [{ id: 'music', asset_id: 2, start: 0, duration: 9,
        trimStart: 1, trimEnd: 12, sourceOffset: 0.25, playbackRate: 1, loop: false, volume: 0.4, muted: false,
        gainPoints: [{ time: 0, value: 0 }, { time: 0.5, value: 1 }, { time: 8, value: 1 }, { time: 9, value: 0 }] }] };
    doc.pages[0].transition = { type: 'fade', duration: 0.5 };
    doc.pages[0].elements = [newElement('text', { id: 'title', text: 'Título temporal', timing: { start: 0.5, duration: 2 } }),
        newElement('video', { id: 'clip', asset_id: 1, width: 320, height: 180, trimStart: 1, trimEnd: 5,
            timing: { start: 1, duration: 3 }, playbackRate: 2, sourceOffset: 0.25, loop: true,
            gainPoints: [{ time: 0, value: 1 }, { time: 3, value: 0 }] })];
    doc.pages.push({ id: 'second', name: 'Segunda', background: '#ffffff', duration: 5, elements: [] });
    return doc;
}

test('v2 retains typed timing, rates, source phase, envelopes, audio and page transitions', () => {
    const input = temporalScene();
    const output = validateDocument(input);
    assert.equal(output.schema_version, 2);
    assert.deepEqual(output.timeline, input.timeline);
    assert.deepEqual(output.pages[0].transition, input.pages[0].transition);
    assert.deepEqual(output.pages[0].elements[0].timing, { start: 0.5, duration: 2 });
    for (const key of ['playbackRate', 'sourceOffset', 'loop', 'gainPoints']) assert.deepEqual(output.pages[0].elements[1][key], input.pages[0].elements[1][key]);
});

test('legacy v1 still rejects temporal fields instead of silently accepting a different contract', () => {
    const input = temporalScene(); input.schema_version = 1;
    assert.throws(() => validateDocument(input), /timeline|propiedad/);
    delete input.timeline;
    assert.throws(() => validateDocument(input), /transition|propiedad/);
});

export { temporalScene };

test('temporal arrays cannot contain holes that would become null after persistence', () => {
    const input = temporalScene(); input.timeline.audio_tracks = Array(2);
    assert.throws(() => validateDocument(input), /audio_tracks|pistas|objeto/);
});

test('gain envelopes reject an interior array hole before persistence or sampling', () => {
    const input = temporalScene();
    input.timeline.audio_tracks[0].gainPoints = [{ time: 0, value: 0 }, , { time: 9, value: 1 }];
    assert.throws(() => validateDocument(input), /gainPoints|objeto|envolvente/);
});
