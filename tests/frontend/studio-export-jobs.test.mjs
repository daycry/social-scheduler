import test from 'node:test';
import assert from 'node:assert/strict';

async function moduleUnderTest() {
    let result;
    try { result = await import('../../resources/js/Studio/export-jobs.js'); }
    catch (error) { if (error.code !== 'ERR_MODULE_NOT_FOUND') throw error; }
    return result;
}

test('native export client preserves page order and sends server rendering without static frame or transparency options', async () => {
    const module = await moduleUnderTest();
    assert.equal(typeof module?.createStudioExportClient, 'function', 'native export transport is unavailable');
    const calls = [];
    const client = module.createStudioExportClient({
        request: async (...args) => { calls.push(args); return { export: { id: 19, status: 'pending' } }; },
        url: (name, id) => `${name}/${id}`,
    });
    const result = await client.start(7, { revision: 3, format: 'mp4', pages: ['second', 'first'], scale: 0.5, quality: 0.8, frame_times: [{ page_id: 'first', time: 1 }], transparent: true });
    assert.deepEqual(calls, [['POST', 'studio.exports.render/7', { revision: 3, format: 'mp4', pages: ['second', 'first'], scale: 0.5, quality: 0.8, transparent: false }]]);
    assert.equal(result.id, 19);
    await client.cancel(19); await client.retry(19); await client.post(19);
    assert.deepEqual(calls.slice(1), [['POST', 'studio.exports.cancel/19', {}], ['POST', 'studio.exports.retry/19', {}], ['POST', 'studio.exports.post/19', {}]]);
    await client.list(7, 2);
    assert.deepEqual(calls[4], ['GET', 'studio.exports.index/7?page=2']);
});

test('export monitoring uses one history request per page and never overlaps or recreates work', async () => {
    const module = await moduleUnderTest();
    assert.equal(typeof module?.createExportMonitor, 'function', 'resumable export monitoring is unavailable');
    const timers = [], updates = [], states = [];
    let calls = 0, release;
    const monitor = module.createExportMonitor({
        load: () => { calls++; return new Promise(resolve => { release = resolve; }); },
        onUpdate: data => updates.push(data), onState: state => states.push(state),
        schedule: callback => { timers.push(callback); return callback; }, cancel: () => {},
    });
    const first = monitor.start();
    await monitor.refresh();
    assert.equal(calls, 1);
    release({ exports: [{ id: 19, status: 'processing' }], pagination: { current_page: 1 } });
    await first;
    assert.equal(updates.length, 1); assert.equal(timers.length, 1);
    const second = timers.shift()();
    release({ exports: [{ id: 19, status: 'ready' }] }); await second;
    assert.equal(calls, 2); assert.equal(timers.length, 0);
    assert.equal(states.at(-1).loading, false);
    monitor.stop();
});

test('closing or switching the history ignores late responses and pauses after the polling budget', async () => {
    const module = await moduleUnderTest();
    assert.equal(typeof module?.createExportMonitor, 'function', 'resumable export monitoring is unavailable');
    const releases = [], updates = [], timers = [], states = [];
    let time = 0;
    const monitor = module.createExportMonitor({
        load: () => new Promise(resolve => releases.push(resolve)),
        onUpdate: data => updates.push(data), onState: state => states.push(state),
        schedule: callback => { timers.push(callback); return callback; }, cancel: () => {}, now: () => time,
    });
    const first = monitor.start(); monitor.stop(); const second = monitor.start();
    assert.equal(releases.length, 1);
    releases[0]({ exports: [{ id: 19, status: 'ready' }] }); await first;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(updates.length, 0);
    releases[1]({ exports: [{ id: 20, status: 'pending' }] }); await second;
    assert.deepEqual(updates.map(update => update.exports[0].id), [20]);
    time = 300001; await timers.shift()();
    assert.equal(releases.length, 2); assert.equal(states.at(-1).paused, true);
    const resumed = monitor.refresh();
    releases[2]({ exports: [{ id: 20, status: 'cancelled' }] }); await resumed;
    assert.equal(updates.at(-1).exports[0].status, 'cancelled');
    assert.equal(states.at(-1).paused, false);
    monitor.stop();
});

test('a history failure pauses polling and manual refresh recovers the same jobs without any mutation', async () => {
    const { createExportMonitor } = await moduleUnderTest();
    const updates = [], states = [], timers = [];
    let calls = 0;
    const monitor = createExportMonitor({
        load: async () => { if (++calls === 1) throw new Error('Sesión caducada'); return { exports: [{ id: 19, status: 'ready' }] }; },
        onUpdate: data => updates.push(data), onState: state => states.push(state),
        schedule: callback => { timers.push(callback); }, cancel: () => {},
    });
    await monitor.start();
    assert.equal(states.at(-1).paused, true); assert.equal(states.at(-1).error, 'Sesión caducada');
    assert.equal(timers.length, 0); assert.equal(updates.length, 0);
    await monitor.refresh();
    assert.equal(calls, 2); assert.equal(updates[0].exports[0].id, 19);
    assert.equal(states.at(-1).paused, false); assert.equal(states.at(-1).error, '');
    monitor.stop();
});

test('rapid view changes abort old reads and serialize even a transport that has not settled cancellation', async () => {
    const { createExportMonitor } = await moduleUnderTest();
    const releases = [], signals = [], updates = [];
    let active = 0, maximum = 0;
    const monitor = createExportMonitor({
        load: ({ signal } = {}) => new Promise(resolve => {
            signals.push(signal); active++; maximum = Math.max(maximum, active);
            releases.push(() => { active--; resolve({ exports: [{ id: signals.length, status: 'ready' }] }); });
        }),
        onUpdate: result => updates.push(result), onState: () => {},
    });
    const pending = [monitor.start(), monitor.start(), monitor.start()];
    try {
        assert.equal(maximum, 1, 'view changes must not multiply pending history requests');
        assert.equal(signals[0].aborted, true, 'previous transport must be aborted');
        releases.shift()(); await new Promise(resolve => setImmediate(resolve));
        assert.equal(signals.length, 2); assert.equal(maximum, 1); assert.equal(updates.length, 0);
        releases.shift()(); await Promise.all(pending);
        assert.equal(updates.length, 1); assert.equal(active, 0);
    } finally { monitor.stop(); for (const release of releases) release(); await Promise.all(pending); }
});

test('history cancellation reaches the actual same-origin fetch transport', async () => {
    const { createStudioExportClient } = await moduleUnderTest();
    const previous = { fetch: globalThis.fetch, document: globalThis.document, route: globalThis.route };
    const calls = [], controller = new AbortController();
    globalThis.document = { cookie: 'XSRF-TOKEN=own-csrf' };
    globalThis.route = () => '/studio/designs/7/exports';
    globalThis.fetch = async (url, options) => { calls.push({ url, ...options }); return Response.json({ exports: [], pagination: { current_page: 2, last_page: 2 } }); };
    try {
        await createStudioExportClient().list(7, 2, controller.signal);
        assert.equal(calls[0].signal, controller.signal);
        assert.equal(calls[0].credentials, 'same-origin');
        assert.equal(calls[0].url, '/studio/designs/7/exports?page=2');
        assert.equal(calls[0].headers.Authorization, undefined);
    } finally { Object.assign(globalThis, previous); }
});
