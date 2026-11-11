import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// A chained save (after a pending one, or after edits made while saving) must keep the origin of the first one:
// an autosave stays an autosave so the server can still fold it (A-07); an explicit save stays explicit.
const source = readFileSync(new URL('../../resources/js/Pages/Studio/Editor.vue', import.meta.url), 'utf8');
const body = source.slice(source.indexOf('async function saveNow('), source.indexOf('async function prepareExport'));

test('saveNow forwards the autosave flag in both chained calls', () => {
    const chained = body.match(/saveNow\([^)]*\)/g).filter(call => call !== 'saveNow(autosave = false)');
    assert.equal(chained.length, 2, 'expected the two chained saveNow calls');
    for (const call of chained) assert.equal(call, 'saveNow(autosave)');
});
