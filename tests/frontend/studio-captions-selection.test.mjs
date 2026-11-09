import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, validateDocument } from '../../resources/js/Studio/document.js';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { compileCaptions } from '../../resources/js/Studio/captions.js';

function source() {
    const doc = blankDocument(320, 180); doc.schema_version = 2;
    doc.pages = ['a', 'b', 'c'].map(id => ({ id, name: id, background: '#000000', duration: 2, elements: [] }));
    doc.pages[0].transition = { type: 'fade', duration: 0.5 };
    doc.timeline = { fps: 30, audio_tracks: [], captions: { style: { fontSize: 24 }, cues: [
        { id: 'late_a', start: 1.8, end: 1.95, text: 'Final A' },
        { id: 'cross_ab', start: 0.5, end: 2.5, text: 'Continuo' },
        { id: 'on_c', start: 3.8, end: 4, text: 'Solo C' },
    ] } };
    return validateDocument(doc);
}
test('caption selection keeps the whole last quarter second of A alone despite original fade dominance', () => {
    const doc = source(), before = structuredClone(doc);
    const plan = compileAudiovisual(doc, [], { pageIds: ['a'] });
    const cue = plan.document.timeline.captions?.cues.find(cue => cue.text === 'Final A');
    assert.ok(cue, 'selected page must retain global captions');
    assert.equal(cue.start, 1.8); assert.equal(cue.end, 1.95);
    assert.deepEqual(doc, before);
});
test('contiguous selected runs never split duplicate or attenuate captions in their internal fade', () => {
    const plan = compileAudiovisual(source(), [], { pageIds: ['a', 'b'] });
    const cues = plan.document.timeline.captions?.cues;
    assert.equal(cues?.filter(cue => cue.text === 'Continuo').length, 1);
    const index = compileCaptions(cues);
    assert.deepEqual(index.at(1.8).map(cue => cue.text), ['Continuo', 'Final A']);
});
test('nonadjacent output fade switches caption source ownership at output midpoint without duplication', () => {
    const plan = compileAudiovisual(source(), [], { pageIds: ['a', 'c'] });
    const index = compileCaptions(plan.document.timeline.captions?.cues);
    assert.deepEqual(index.at(1.7).map(cue => cue.text), ['Continuo']);
    assert.deepEqual(index.at(1.8).map(cue => cue.text), ['Solo C']);
    assert.deepEqual(index.at(2.01), []);
});
test('reorder maps original cue windows per run and repeated compilation has no phase drift', () => {
    const doc = source(), first = compileAudiovisual(doc, [], { pageIds: ['c', 'a'] });
    const index = compileCaptions(first.document.timeline.captions?.cues);
    assert.deepEqual(index.at(0.31).map(cue => cue.text), ['Solo C']);
    assert.ok(index.at(3.85).some(cue => cue.text === 'Final A'));
    const second = compileAudiovisual(doc, [], { pageIds: ['c', 'a'] });
    assert.deepEqual(first.document, second.document);
});
