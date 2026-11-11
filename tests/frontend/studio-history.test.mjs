import test from 'node:test';
import assert from 'node:assert/strict';
import { createHistory } from '../../resources/js/Studio/history.js';

test('history restores full states, does not share snapshots and clears redo after branching', () => {
    const initial = { pages: [{ text: 'Original' }] };
    const history = createHistory(initial);
    initial.pages[0].text = 'Mutation outside history';
    assert.equal(history.current.pages[0].text, 'Original');
    assert.equal(history.canUndo, false);
    assert.equal(history.commit({ pages: [{ text: 'Second' }] }), true);
    assert.equal(history.commit({ pages: [{ text: 'Second' }] }), false);
    assert.equal(history.undo().pages[0].text, 'Original');
    assert.equal(history.canRedo, true);
    const state = history.redo();
    state.pages[0].text = 'Mutated return value';
    assert.equal(history.current.pages[0].text, 'Second');
    history.undo();
    history.commit({ pages: [{ text: 'Branch' }] });
    assert.equal(history.canRedo, false);
    assert.equal(history.redo(), null);
});

test('history bounds retained states and reset starts a new clean timeline', () => {
    const history = createHistory({ n: 0 }, { limit: 3 });
    for (let n = 1; n <= 5; n++) history.commit({ n });
    assert.equal(history.length, 3);
    assert.deepEqual(history.undo(), { n: 4 });
    assert.deepEqual(history.undo(), { n: 3 });
    assert.equal(history.undo(), null);
    history.reset({ n: 99 });
    assert.equal(history.canUndo, false);
    assert.deepEqual(history.current, { n: 99 });
});
