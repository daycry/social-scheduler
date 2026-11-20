import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../e2e/studio-presentation-media.spec.ts', import.meta.url), 'utf8');
const start = source.indexOf('            // Inertia navigation unmounts the editor');
const end = source.lastIndexOf('        }', source.indexOf('        await page.screenshot', start));
assert.ok(start >= 0 && end > start, 'Extract the pending-media unmount assertion');
const body = ts.transpileModule(source.slice(start, end), {
    compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext },
}).outputText;
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const run = new AsyncFunction('page', 'mark', 'expect', 'release', 'quietFor', 'paused', 'evidence', 'info', 'modal', 'outputs', 'deliverySettled', body);

function fixture({ lateDraw = false, latePlay = false } = {}) {
    let time = 0;
    let unmounted = false;
    let released = false;
    const draws = [];
    const events = [];
    let marked = false;
    const home = {
        async evaluate(click) {
            time = 10;
            draws.push({ at: time, target: 7 });
            click({ click() {} });
        },
    };
    const page = { getByRole: () => home };
    const expect = value => ({
        async toBeAttached() { assert.equal(value, home); },
        async toHaveURL() { time = 20; unmounted = true; },
        async toHaveCount(count) { assert.equal(unmounted ? 0 : 1, count); },
        async toBeVisible() { assert.equal(unmounted, true); },
        toEqual: expected => assert.deepEqual(value, expected),
    });
    return async () => {
        await run(page, async () => {
            assert.equal(released, false, 'Every observation precedes delivery');
            if (unmounted) marked = true;
            return time;
        }, expect, () => {
            assert.equal(unmounted, true);
            assert.equal(marked, true, 'Record the unmount before delivering late media');
            released = true;
            if (lateDraw) draws.push({ at: 20, target: 7 });
            if (latePlay) events.push({ at: 10, event: 'play' });
        }, async () => {}, async () => {}, async () => ({ events, draws }), {}, () => ({}), [7], Promise.resolve());
        assert.equal(released, true);
    };
}

test('unmount observation tolerates a frame before navigation commits', async () => {
    await fixture()();
});

test('unmount assertion still rejects a pending frame released after cancellation', async () => {
    await assert.rejects(fixture({ lateDraw: true })(), assert.AssertionError);
});


test('unmount assertion still rejects playback during navigation or after cancellation', async () => {
    await assert.rejects(fixture({ latePlay: true })(), assert.AssertionError);
});
