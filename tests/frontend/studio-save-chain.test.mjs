import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { nextSave } from '../../resources/js/Studio/save-chain.js';

// Decides what to send once a save has finished: pending edits keep the origin of the save that asked for them,
// and an explicit save requested while an autosave was in flight must still produce its own checkpoint (A-07).
test('pending edits are re-sent with the origin of the requested save', () => {
    assert.deepEqual(nextSave({ requested: true, inflight: true, dirty: true }), { send: true, autosave: true, force: false });
    assert.deepEqual(nextSave({ requested: false, inflight: false, dirty: true }), { send: true, autosave: false, force: false });
    assert.deepEqual(nextSave({ requested: false, inflight: true, dirty: true }), { send: true, autosave: false, force: false });
});

test('an explicit save that waited for an autosave sends its own checkpoint even without changes', () => {
    assert.deepEqual(nextSave({ requested: false, inflight: true, dirty: false }), { send: true, autosave: false, force: true });
});

test('nothing is sent when there is nothing new to persist', () => {
    assert.equal(nextSave({ requested: true, inflight: true, dirty: false }).send, false);
    assert.equal(nextSave({ requested: false, inflight: false, dirty: false }).send, false);
});

test('the editor uses the helper in both chained saves and tracks the in-flight origin', () => {
    const source = readFileSync(new URL('../../resources/js/Pages/Studio/Editor.vue', import.meta.url), 'utf8');
    assert.match(source, /from '@\/Studio\/save-chain'/);
    assert.equal((source.match(/nextSave\(/g) ?? []).length, 2);
    assert.match(source, /pendingAutosave = autosave === true/);
});
