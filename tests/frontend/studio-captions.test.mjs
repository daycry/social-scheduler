import test from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';
import { blankDocument, validateDocument } from '../../resources/js/Studio/document.js';
import { parseCaptionFile, encodeCaptionFile, compileCaptions, captionOperation } from '../../resources/js/Studio/captions.js';

export function captionScene() {
    const document = blankDocument(320, 180);
    document.schema_version = 2;
    document.timeline = { fps: 30, audio_tracks: [], captions: { style: { fontSize: 24, position: 'bottom' }, cues: [
        { id: 'cue_1', start: 0.5, end: 1.5, text: 'Hola 🎵\nsegunda línea' },
    ] } };
    return document;
}
test('caption document preserves Unicode cues and style while legacy absence stays absent', () => {
    const doc = captionScene();
    assert.deepEqual(validateDocument(doc).timeline.captions, doc.timeline.captions);
    delete doc.timeline.captions;
    assert.equal(Object.hasOwn(validateDocument(doc).timeline, 'captions'), false);
    assert.equal(Object.hasOwn(validateDocument(blankDocument()).pages[0], 'captions'), false);
});

const badCaptions = {
    numericString: c => { c.cues[0].start = '0.5'; },
    emptyInterval: c => { c.cues[0].end = c.cues[0].start; },
    negative: c => { c.cues[0].start = -1; },
    afterEnd: c => { c.cues[0].end = 100; },
    duplicate: c => { c.cues.push({ ...c.cues[0] }); },
    tooManyActive: c => { c.cues = Array.from({ length: 9 }, (_, i) => ({ ...c.cues[0], id: `c_${i}` })); },
    tooManyCues: c => { c.cues = Array.from({ length: 5001 }, (_, i) => ({ ...c.cues[0], id: `c_${i}` })); },
    tooMuchText: c => { c.cues[0].text = '🎵'.repeat(501); },
    emptyText: c => { c.cues[0].text = ''; },
    control: c => { c.cues[0].text = 'a\u0000b'; },
    unknownStyle: c => { c.style.url = 'https://foreign/font'; },
    unknownCue: c => { c.cues[0].html = '<b>x</b>'; },
    internalFont: c => { c.style.fontFamily = 'StudioFont_1_fake'; },
    invalidGeometry: c => { c.style.margin = 0.3; },
};
for (const [name, mutate] of Object.entries(badCaptions)) test(`caption validator rejects ${name} without changing input`, () => {
    const doc = captionScene(); mutate(doc.timeline.captions);
    const before = structuredClone(doc);
    assert.throws(() => validateDocument(doc));
    assert.deepEqual(doc, before);
});
test('caption validator accepts eight end-exclusive active cues and Unicode code points', () => {
    const doc = captionScene();
    doc.timeline.captions.cues = Array.from({ length: 16 }, (_, i) => ({ id: `c_${i}`, start: i < 8 ? 0 : 1, end: i < 8 ? 1 : 2, text: '🎵'.repeat(500) }));
    assert.equal(validateDocument(doc).timeline.captions.cues.length, 16);
});

test('caption codec preserves BOM CRLF Unicode whitespace and millisecond times in SRT and VTT', () => {
    const body = '  Hola 🎵\n línea segunda  ';
    const parsed = parseCaptionFile(`\uFEFF1\r\n00:00:00,500 --> 00:00:01,500\r\n${body.replaceAll('\n', '\r\n')}\r\n`, 'srt');
    assert.deepEqual(parsed.cues, [{ id: 'cue_1', start: 0.5, end: 1.5, text: body }]);
    for (const format of ['srt', 'vtt']) assert.deepEqual(parseCaptionFile(encodeCaptionFile(parsed.cues, format), format).cues, parsed.cues);
});
test('caption index handles forward reverse seeks and exact boundaries with bounded comparisons', () => {
    const cues = Array.from({ length: 5000 }, (_, i) => ({ id: `cue_${i}`, start: i * 0.1, end: i * 0.1 + 0.05, text: `Texto ${i}` }));
    const index = compileCaptions(cues);
    for (const time of [99.02, 0, 499.91, 40.05, 99.1, 50, 0.049, 400.049]) {
        assert.deepEqual(index.at(time), cues.filter(cue => cue.start <= time && time < cue.end));
        assert.ok(index.comparisons <= 15, 'must binary search, not scan5000cues/frame');
    }
});
test('caption local operations preserve document fields and atomically reject invalid offsets', () => {
    const doc = captionScene(); const before = structuredClone(doc);
    const next = captionOperation(doc, { action: 'offset', seconds: 1 });
    assert.equal(next.timeline.captions.cues[0].start, 1.5);
    assert.deepEqual(next.pages, doc.pages); assert.deepEqual(doc, before);
    assert.throws(() => captionOperation(doc, { action: 'offset', seconds: -1 }));
    assert.deepEqual(doc, before);
    assert.equal(Object.hasOwn(captionOperation(doc, { action: 'clear' }).timeline, 'captions'), false);
});
test('caption operations accept the real Vue reactive editor document without mutating it', () => {
    const doc = reactive(captionScene());
    const result = captionOperation(doc, { action: 'offset', seconds: 1 });
    assert.equal(result.timeline.captions.cues[0].start, 1.5);
    assert.equal(doc.timeline.captions.cues[0].start, 0.5);
});

function scene(collisions = false) {
    const doc = blankDocument(320, 180); doc.schema_version = 2;
    doc.timeline = { fps: 30, audio_tracks: [], captions: { style: { fontFamily: 'Arial' }, cues: [{ id: 'keep', start: 0, end: 0.25, text: 'Conservar' }] } };
    if (collisions) {
        doc.pages[0].id = 'cue_1';
        doc.pages[0].elements = [{ id: 'import_1', type: 'rect', x: 0, y: 0, width: 10, height: 10 }, { id: 'group', type: 'group', x: 0, y: 0, width: 20, height: 20, elements: [{ id: 'cue_2', type: 'rect', x: 0, y: 0, width: 10, height: 10 }] }];
        doc.timeline.audio_tracks = [{ id: 'cue_3', asset_id: 123, start: 0, duration: 1 }];
    }
    return validateDocument(doc);
}
const imported = '1\n00:00:00,500 --> 00:00:01,000\nUno\n\n2\n00:00:01,500 --> 00:00:02,000\nDos\n\n3\n00:00:02,500 --> 00:00:03,000\nTres\n';
test('A1 default import appends without deleting existing cues', () => {
    const original = scene(), before = JSON.stringify(original);
    const next = captionOperation(original, { action: 'import', format: 'srt', text: imported });
    assert.equal(next.timeline.captions.cues.length, 4);
    assert.ok(next.timeline.captions.cues.some(cue => cue.id === 'keep'));
    assert.equal(JSON.stringify(original), before);
});
for (const format of ['srt', 'vtt']) for (const [kind, text] of Object.entries({ leading: '\nHola', trailing: 'Hola\n', leading_spaces: ' \t\nHola', trailing_spaces: 'Hola\n \t', interior: 'Hola\n \t\nMundo' })) {
    test(`B1 ${format} rejects ${kind} blank lines without mutating canonical cue`, () => {
        const cue = { id: 'cue', start: 0.5, end: 1.5, text }, before = structuredClone(cue);
        const doc = scene(); doc.timeline.captions.cues = [cue]; validateDocument(doc);
        assert.throws(() => encodeCaptionFile([cue], format)); assert.deepEqual(cue, before);
    });
}
for (const format of ['srt', 'vtt']) for (const count of [2, 3, 4, 5]) for (const newline of ['\n', '\r\n']) {
    test(`B2 ${format} accepts ${count} separator newlines ${JSON.stringify(newline)}`, () => {
        const comma = format === 'srt' ? ',' : '.', header = format === 'vtt' ? 'WEBVTT\n\n' : '';
        let text = `${header}1\n00:00:00${comma}500 --> 00:00:01${comma}000\nUno${'\n'.repeat(count)}2\n00:00:01${comma}500 --> 00:00:02${comma}000\nDos\n`;
        text = text.replaceAll('\n', newline);
        assert.deepEqual(parseCaptionFile(text, format).cues.map(cue => cue.text), ['Uno', 'Dos']);
    });
}
for (const mode of ['append', 'replace']) test(`B3 import ${mode} reserves pages nested layers and audio IDs`, () => {
    const original = scene(true), before = JSON.stringify(original);
    const next = captionOperation(original, { action: 'import', format: 'srt', text: imported, mode });
    const reserved = new Set(['cue_1', 'cue_2', 'cue_3', 'import_1', 'group']);
    if (mode === 'append') reserved.add('keep');
    const cues = next.timeline.captions.cues.filter(cue => cue.text !== 'Conservar');
    assert.equal(cues.length, 3); assert.equal(new Set(cues.map(cue => cue.id)).size, 3);
    assert.ok(cues.every(cue => !reserved.has(cue.id))); assert.equal(JSON.stringify(original), before);
});
for (const action of ['upsert', 'replace']) test(`B3 explicit ${action} IDs remain strict instead of being renamed`, () => {
    const doc = scene(true), cue = { id: 'cue_1', start: 0.5, end: 1, text: 'Explícito' };
    assert.throws(() => captionOperation(doc, action === 'upsert' ? { action, cue } : { action, captions: { style: {}, cues: [cue] } }));
});
test('nonempty Unicode space tab and LF text survives both supported text formats', () => {
    const cue = { id: 'cue_1', start: 0.5, end: 1, text: ' Hola 🎵 \n\tMundo  ' };
    for (const format of ['srt', 'vtt']) assert.equal(parseCaptionFile(encodeCaptionFile([cue], format), format).cues[0].text, cue.text);
});
