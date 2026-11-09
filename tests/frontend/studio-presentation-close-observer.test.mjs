import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const spec = fs.readFileSync(new URL('../e2e/studio-presentation-media.spec.ts', import.meta.url), 'utf8');
const oldStart = '        const closed = await mark(page, true);';
const newStart = "        await button(page, 'Cerrar presentación').evaluate(armPresentationCloseObserver);";
const start = Math.min(...[spec.indexOf(oldStart), spec.indexOf(newStart)].filter(index => index >= 0));
assert.ok(start > 0, 'extract the actual first AV close block');
const end = spec.indexOf('\n    } finally', start);
assert.ok(end > start);
const closeBlock = spec.slice(start, end).replaceAll('(e: any)', '(e)');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const executeCloseBlock = new AsyncFunction('page', 'button', 'mark', 'paused', 'expect', 'evidence', 'modal', 'info',
    'armPresentationCloseObserver', 'readPresentationCloseObservation', `let data; ${closeBlock}; return closed;`);
const helperURL = new URL('../e2e/support/studio-presentation-observer.mjs', import.meta.url);
const helperSource = fs.existsSync(helperURL) ? fs.readFileSync(helperURL, 'utf8') : '';

function fixture(options = {}) {
    const probe = { closed: false, events: [] };
    const clock = { now: 0 };
    const snapshots = [], listeners = [], waits = [];
    const environment = { __presentationProbe: probe };
    const helpers = new Function('window', 'performance', `${helperSource.replaceAll('export function ', 'function ')};
        return { arm: typeof armPresentationCloseObserver === 'function' ? armPresentationCloseObserver : undefined,
            read: typeof readPresentationCloseObservation === 'function' ? readPresentationCloseObservation : undefined };`)(environment, { now: () => clock.now });
    const element = {
        addEventListener(type, listener, capture) { listeners.push({ type, listener, capture }); },
        removeEventListener(type, listener, capture) {
            const index = listeners.findIndex(entry => entry.type === type && entry.listener === listener && entry.capture === capture);
            if (index >= 0) listeners.splice(index, 1);
        },
    };
    const dispatch = trusted => {
        // DOM target capture listeners precede the existing Vue bubble handler.
        for (const entry of [...listeners].filter(entry => entry.type === 'click' && entry.capture === true)) entry.listener({ isTrusted: trusted });
        snapshots.push({ at: clock.now, closed: probe.closed, observation: structuredClone(probe.closeObservation), eventCount: probe.events.length });
    };
    const target = {
        async evaluate(fn) { return fn(element); },
        async click() {
            clock.now = 10;
            probe.events.push({ event: 'play', at: clock.now });
            snapshots.push({ waiting: true, closed: probe.closed });
            clock.now = 50;
            if (!options.noClick) dispatch(options.trusted !== false);
            if (options.postPlay) probe.events.push({ event: 'call:play', at: options.postAt ?? 51 });
            if (options.repeatClick) { clock.now = 75; dispatch(true); }
        },
    };
    const page = { async evaluate(fn) { return fn(); }, async waitForTimeout(ms) { waits.push(ms); clock.now += ms; } };
    const expect = actual => ({
        toBe(expected) { assert.equal(actual, expected); },
        toEqual(expected) { assert.deepEqual(actual, expected); },
        async toHaveCount(expected) { assert.equal(actual.count, expected); },
    });
    return { probe, snapshots, listeners, waits, async run() {
        return executeCloseBlock(page, () => target, async (_page, closed) => { probe.closed = closed; return clock.now; },
            async () => {}, expect, async () => ({ events: probe.events }), () => ({ count: 0 }), {}, helpers.arm, helpers.read);
    } };
}

test('actual AV close block excludes playback during Playwright actionability wait', async () => {
    const f = fixture();
    const closed = await f.run();
    assert.equal(closed, 50, 'cutoff must be the close gesture, not the earlier automation call');
    assert.equal(f.snapshots.find(snapshot => snapshot.waiting).closed, false);
    assert.deepEqual(f.probe.events, [{ event: 'play', at: 10 }], 'observation never deletes pre-gesture events');
    assert.deepEqual(f.waits, [350]);
});

test('actual AV close assertion still rejects playback after the close gesture', async () => {
    const f = fixture({ postPlay: true });
    await assert.rejects(f.run(), assert.AssertionError);
    assert.deepEqual(f.probe.events, [{ event: 'play', at: 10 }, { event: 'call:play', at: 51 }]);
});

test('equal-resolution playback after capture is still rejected by the actual AV close block', async () => {
    const f = fixture({ postPlay: true, postAt: 50 });
    await assert.rejects(f.run(), assert.AssertionError);
    assert.deepEqual(f.probe.events, [{ event: 'play', at: 10 }, { event: 'call:play', at: 50 }]);
});

test('capture records trusted close before the Vue handler and only once', async () => {
    const f = fixture({ repeatClick: true });
    assert.equal(await f.run(), 50);
    assert.deepEqual(f.snapshots.filter(snapshot => !snapshot.waiting).map(snapshot => snapshot.observation),
        [{ at: 50, trusted: true, eventIndex: 1 }, { at: 50, trusted: true, eventIndex: 1 }]);
    assert.ok(f.snapshots.filter(snapshot => !snapshot.waiting).every(snapshot => snapshot.closed));
    assert.equal(f.listeners.length, 0);
});

test('missing close gesture fails rather than silently accepting an early mark', async () => {
    const f = fixture({ noClick: true });
    await assert.rejects(f.run(), assert.AssertionError);
});

test('untrusted synthetic click cannot establish a successful close boundary', async () => {
    const f = fixture({ trusted: false });
    await assert.rejects(f.run(), assert.AssertionError);
    assert.equal(f.probe.closed, false);
});
