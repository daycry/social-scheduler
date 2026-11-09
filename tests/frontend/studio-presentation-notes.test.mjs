import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, validateDocument, cloneDocument, duplicatePage } from '../../resources/js/Studio/document.js';
import { createHistory } from '../../resources/js/Studio/history.js';

function scene(version = 1, notes) {
    const doc = blankDocument(320, 180);
    if (version === 2) Object.assign(doc, { schema_version: 2, timeline: { fps: 30, audio_tracks: [] } });
    if (notes !== undefined) doc.pages[0].notes = notes;
    return doc;
}
for (const version of [1, 2]) {
    test(`v${version} preserves optional plain notes exactly, including empty, CRLF and emoji`, () => {
        for (const notes of ['', '  \r\n🎤 e\u0301\n\t  ', '<script>literal text</script>']) {
            assert.equal(validateDocument(scene(version, notes)).pages[0].notes, notes);
        }
        assert.equal(Object.hasOwn(validateDocument(scene(version)).pages[0], 'notes'), false);
    });
    test(`v${version} accepts exactly 20000 Unicode codepoints and rejects 20001`, () => {
        const notes = '🎤'.repeat(20000);
        assert.equal(validateDocument(scene(version, notes)).pages[0].notes, notes);
        assert.throws(() => validateDocument(scene(version, notes + 'x')), /notes.*20000/);
    });
    test(`v${version} rejects every nonstring notes value`, () => {
        const explicitUndefined = scene(version); explicitUndefined.pages[0].notes = undefined;
        assert.throws(() => validateDocument(explicitUndefined), /notes/);
        for (const notes of [null, true, false, 0, 3.5, [], {}, ['text']]) {
            assert.throws(() => validateDocument(scene(version, notes)), /notes/);
        }
    });
    test(`v${version} retains notes through clone, duplicate, undo and redo without shared state`, () => {
        const doc = validateDocument(scene(version, ' \r\n🎤 original  '));
        const cloned = cloneDocument(doc, { newIds: true });
        assert.equal(cloned.pages[0].notes, doc.pages[0].notes);
        assert.notEqual(cloned.pages[0].id, doc.pages[0].id);
        const page = duplicatePage(doc.pages[0]);
        assert.equal(page.notes, doc.pages[0].notes);
        page.notes = 'changed';
        assert.equal(doc.pages[0].notes, ' \r\n🎤 original  ');
        const history = createHistory(doc);
        const changed = cloneDocument(doc); changed.pages[0].notes = '';
        history.commit(validateDocument(changed));
        assert.equal(history.undo().pages[0].notes, doc.pages[0].notes);
        assert.equal(history.redo().pages[0].notes, '');
    });
    test(`v${version} keeps the total 2 MiB cap even when every page notes field is valid`, () => {
        const doc = scene(version, '🎤'.repeat(20000));
        doc.pages = Array.from({ length: 30 }, (_, i) => ({ ...doc.pages[0], id: `page_${i}` }));
        assert.throws(() => validateDocument(doc), /2 MiB/);
    });
}
