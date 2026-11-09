import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { stripTypeScriptTypes } from 'node:module';

// Execute the actual helper with an in-memory filesystem and deterministic poll.
// These unit tests make no claim about HTTP or browser delivery.
function harness() {
    const files = new Map(), writes = [], runRoot = '/unit-chart-hold';
    const token = 'unit-token', log = path.join(runRoot, 'chart-http-observer.jsonl');
    let polls = 0, onPoll = () => {};
    const fs = {
        existsSync: file => files.has(file),
        statSync: file => ({ size: files.get(file).length }),
        readFileSync: file => files.get(file),
        writeFileSync(file, body) { files.set(file, Buffer.from(body)); writes.push(file); },
    };
    function expect(value) {
        return {
            toBe: expected => assert.equal(value, expected),
            toBeGreaterThan: expected => assert.ok(value > expected),
            toMatch: expected => assert.match(value, expected),
        };
    }
    expect.poll = read => ({ async toBe(expected) {
        polls++; onPoll(); assert.equal(read(), expected, 'real journal event must be observed');
    } });
    const source = readFileSync(new URL('../e2e/support/studio-chart-delay.ts', import.meta.url), 'utf8')
        .replace(/^import .*;\r?$/gm, '')
        .replace('export function holdRealResponse(method:string,route:string)', 'function holdRealResponse(method,route)');
    const create = new Function('fs', 'path', 'crypto', 'expect', 'runRoot', `${source}\nreturn holdRealResponse;`);
    const hold = create(fs, path, { randomUUID: () => token }, expect, runRoot)('GET', '/real/export');
    const append = row => files.set(log, Buffer.concat([files.get(log) ?? Buffer.alloc(0), Buffer.from(`${JSON.stringify({ token, ...row })}\n`)]));
    return { hold, append, files, writes, releaseFile: path.join(runRoot, `hold-${token}.release`), get polls() { return polls; }, set onPoll(fn) { onPoll = fn; } };
}

test('cleanup releases an uncaptured hold without waiting or fabricating journal events', async () => {
    const h = harness();
    assert.equal(typeof h.hold.cleanup, 'function', 'cleanup API must exist');
    assert.deepEqual(await h.hold.cleanup(), { captured: false, released: false, events: [] });
    assert.equal(h.files.get(h.releaseFile).toString(), 'release');
    assert.equal(h.polls, 0);
    assert.deepEqual(await h.hold.cleanup(), { captured: false, released: false, events: [] });
});

test('captured cleanup waits for the journal release and remains idempotent', async () => {
    const h = harness(), captured = { kind: 'hold-captured-real-response', status: 200, upstream_status: 200, bytes: 289, sha256: 'a'.repeat(64) };
    h.append(captured);
    h.onPoll = () => { if (h.polls === 1) { assert.ok(h.files.has(h.releaseFile)); h.append({ kind: 'hold-released', deadline_release: false }); } };
    const result = await h.hold.cleanup();
    assert.equal(h.polls, 1);
    assert.deepEqual(result, { captured: true, released: true, events: [{ token: 'unit-token', ...captured }, { token: 'unit-token', kind: 'hold-released', deadline_release: false }] });
    assert.deepEqual(await h.hold.cleanup(), result);
});

test('cleanup cannot claim delivery when a captured journal has no release', async () => {
    const h = harness(); h.append({ kind: 'hold-captured-real-response' });
    await assert.rejects(h.hold.cleanup(), /real journal event must be observed/);
    assert.ok(h.files.has(h.releaseFile));
});

test('explicit release and captured cleanup reject a deadline release', async () => {
    for (const method of ['release', 'cleanup']) {
        const h = harness(); h.append({ kind: 'hold-captured-real-response' });
        h.append({ kind: 'hold-released', deadline_release: true });
        await assert.rejects(h.hold[method](), { code: 'ERR_ASSERTION' });
    }
});

test('explicit captured and release methods still require real journal events', async () => {
    const h = harness();
    await assert.rejects(h.hold.captured(), /real journal event must be observed/);
    await assert.rejects(h.hold.release(), /real journal event must be observed/);
});

test('helper journal ignores foreign tokens', async () => {
    const h = harness();
    h.append({ token: 'foreign', kind: 'hold-captured-real-response' });
    assert.deepEqual(await h.hold.cleanup(), { captured: false, released: false, events: [] });
});

// Characterize evidence retention in the real hook with failures, without a browser.
function afterEachHarness({ holds = [], fonts = [], network = { issues: [], expected_http: [], intentional_aborts: [] }, overflowError } = {}) {
    const source = readFileSync(new URL('../e2e/studio-charts.spec.ts', import.meta.url), 'utf8');
    const hookSource = source.slice(source.indexOf('test.afterEach('), source.indexOf("test.fixme('E2E28.01"));
    let hook, cleaned = 0;
    const files = new Map(), page = { __chartHolds: holds, viewportSize: () => ({ width: 375, height: 812 }) };
    const policies = new WeakMap([[page, {}]]), fontReads = new WeakMap([[page, fonts]]);
    const fs = { writeFileSync: (file, bytes) => files.set(file, bytes), existsSync: () => false };
    const expect = value => ({ toEqual: other => assert.deepEqual(value, other), toHaveLength: length => assert.equal(value.length, length) });
    new Function('test', 'fs', 'isolation', 'policies', 'fontReads', 'expect', 'expectNoOverflow', 'cleanComposerBrands', 'e2eUser', stripTypeScriptTypes(hookSource))(
        { afterEach: fn => { hook = fn; } }, fs, { assess: () => network }, policies, fontReads, expect,
        async () => { if (overflowError) throw overflowError; }, () => { cleaned++; }, () => ({ email: 'unit@example.invalid' }),
    );
    return { files, get cleaned() { return cleaned; }, run: () => hook({ page }, { title: 'E2E28.03 unit', project: { name: 'mobile' }, outputPath: name => name }) };
}

test('afterEach retains viewport network fonts and cleanup results before rethrowing a cleanup failure', async () => {
    const error = new Error('release journal missing'), later = { captured: false, released: false, events: [] };
    const h = afterEachHarness({ holds: [{ cleanup: async () => { throw error; } }, { cleanup: async () => later }] });
    await assert.rejects(h.run(), err => err instanceof AggregateError && err.errors.includes(error));
    assert.deepEqual(JSON.parse(h.files.get('hold-cleanup.json')), [later]);
    assert.deepEqual(JSON.parse(h.files.get('viewport.json')).viewport, { width: 375, height: 812 });
    assert.deepEqual(JSON.parse(h.files.get('network-observer.json')).issues, []);
    assert.deepEqual(JSON.parse(h.files.get('font-responses.json')), []);
    assert.equal(h.cleaned, 1);
});

test('afterEach preserves fonts when network or overflow assertions fail and rejects intentional aborts', async () => {
    const h = afterEachHarness({ network: { issues: [], expected_http: [], intentional_aborts: [{}] }, overflowError: new Error('overflow') });
    await assert.rejects(h.run(), err => err instanceof AggregateError && err.errors.length === 2);
    assert.ok(h.files.has('viewport.json')); assert.ok(h.files.has('network-observer.json'));
    assert.deepEqual(JSON.parse(h.files.get('font-responses.json')), []);
    assert.equal(h.cleaned, 1);
});

test('afterEach records successful cleanup without altering its observed events', async () => {
    const result = { captured: true, released: true, events: [{ kind: 'hold-captured-real-response' }, { kind: 'hold-released', deadline_release: false }] };
    const h = afterEachHarness({ holds: [{ cleanup: async () => result }] });
    await h.run(); assert.deepEqual(JSON.parse(h.files.get('hold-cleanup.json')), [result]);
    assert.equal(h.cleaned, 1);
});
