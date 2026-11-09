import test from 'node:test';
import assert from 'node:assert/strict';

let createPresentationTimer;
try { ({ createPresentationTimer } = await import('../../resources/js/Studio/presentation-timer.js')); }
catch (error) { if (error.code !== 'ERR_MODULE_NOT_FOUND') throw error; }

function harness(options = {}) {
    assert.equal(typeof createPresentationTimer, 'function', 'countdown controller capability is available');
    let time = 0, serial = 0;
    const pending = new Map(), changes = [], errors = [], nodes = [];
    const timer = createPresentationTimer({
        now: () => time,
        requestTick: (callback, delay) => { assert.equal(delay, 100); pending.set(++serial, callback); return serial; },
        cancelTick: id => pending.delete(id),
        onChange: snapshot => changes.push(snapshot), onError: error => errors.push(error),
        createAudio: () => { const node = new FakeAudio(); nodes.push(node); return node; },
        ...options,
    });
    return { timer, pending, changes, errors, nodes,
        advance: ms => { time += ms; },
        fire: () => { const [id, callback] = pending.entries().next().value; pending.delete(id); callback(); },
    };
}

class FakeAudio {
    constructor() { this.listeners = new Map(); this.plays = 0; this.pauses = 0; this.loads = 0; this.src = ''; }
    addEventListener(name, callback) { this.listeners.set(name, callback); }
    removeEventListener(name, callback) { if (this.listeners.get(name) === callback) this.listeners.delete(name); }
    removeAttribute(name) { if (name === 'src') this.src = ''; }
    load() { this.loads++; }
    play() { this.plays++; return this.result ?? Promise.resolve(); }
    pause() { this.pauses++; }
    fail() { this.listeners.get('error')?.(); }
}

const asset = (id, extra = {}) => ({ id, kind: 'audio', status: 'listo', audio_codec: 'aac', duration: 10,
    name: `Track ${id}`, studio_url: `/brands/7/studio/assets/${id}/file`, ...extra });
function gate() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }

test('music captures only authorized ready private same-ID audio DTOs', async () => {
    const input = [asset(1), asset(2, { studio_url: undefined, preview_url: '/brands/7/studio/assets/2/file' }),
        asset(3, { status: 'pending' }), asset(4, { kind: 'video' }), asset(5, { audio_codec: '' }),
        asset(6, { duration: Infinity }), asset(7, { duration: 0 }), asset(8, { studio_url: 'https://evil.test/brands/7/studio/assets/8/file' }),
        asset(9, { studio_url: '//evil.test/brands/7/studio/assets/9/file' }),
        asset(10, { studio_url: '/brands/7/studio/assets/11/file' }), asset(Number.MAX_SAFE_INTEGER + 1),
        asset(12, { studio_url: '/brands/7/studio/assets/12/file?url=https://evil.test' }),
        asset(13, { studio_url: '/brands/7/studio/assets/13/file/../other' }), asset(14, { audio_codec: false }),
        asset(15, { studio_url: '/brands/0/studio/assets/15/file' }), asset(16, { studio_url: '/brands/7/studio/assets/16/file#evil' }),
        asset(17, { studio_url: '/brands/9007199254740992/studio/assets/17/file' })];
    const h = harness({ assets: input });
    assert.deepEqual(h.timer.snapshot().musicOptions, [{ id: 1, name: 'Track 1' }, { id: 2, name: 'Track 2' }]);
    assert.throws(() => { h.timer.snapshot().musicOptions[0].name = 'changed'; }, TypeError);
    input[0].studio_url = 'https://evil.test'; input[0].name = 'changed'; input.push(asset(18));
    for (const id of [3, 4, 5, 6, 7, 8, 9, 10, 12, 13, 14, 15, 16, 17, 18, '1', NaN]) await assert.rejects(h.timer.setMusic(id));
    await h.timer.setMusic(1); assert.equal(h.nodes.length, 0); await h.timer.start();
    assert.equal(h.nodes[0].src, '/brands/7/studio/assets/1/file');
    assert.equal(h.timer.snapshot().musicOptions[0].name, 'Track 1'); h.timer.dispose();
});

test('music starts within gesture, loops and applies independent volume and mute', async () => {
    const wait = gate(), node = new FakeAudio(); node.result = wait.promise;
    const h = harness({ assets: [asset(1)], createAudio: () => node });
    await h.timer.setMusic(1); h.timer.setVolume(.7); h.timer.setMuted(true);
    const started = h.timer.start(); assert.equal(node.plays, 1); assert.equal(node.loop, true);
    assert.equal(node.volume, .7); assert.equal(node.muted, true); assert.equal(node.loads, 1);
    h.timer.setMuted(false); h.timer.setVolume(0); assert.equal(node.muted, false); assert.equal(node.volume, 0);
    wait.resolve(); await started; h.timer.dispose();
    assert.equal(node.src, ''); assert.equal(node.listeners.size, 0); assert.equal(h.pending.size, 0);
});

test('music replacement and deselection release previous node synchronously', async () => {
    const h = harness({ assets: [asset(1), asset(2)] }); await h.timer.setMusic(1); await h.timer.start();
    const first = h.nodes[0]; const replacing = h.timer.setMusic(2);
    assert.ok(first.pauses > 0); assert.equal(first.src, ''); assert.equal(first.listeners.size, 0);
    assert.equal(h.nodes[1].plays, 1); await replacing;
    await h.timer.setMusic(2); assert.equal(h.nodes.length, 2);
    const second = h.nodes[1]; await h.timer.setMusic(null);
    assert.ok(second.pauses > 0); assert.equal(second.src, ''); assert.equal(h.timer.snapshot().status, 'running');
    h.timer.dispose();
});

test('music pause reset and finish stop immediately, paused selections do not load', async () => {
    const h = harness({ assets: [asset(1), asset(2)] }); h.timer.configure(1); await h.timer.setMusic(1); await h.timer.start();
    h.advance(100); h.timer.pause(); assert.equal(h.nodes[0].src, '/brands/7/studio/assets/1/file'); assert.ok(h.nodes[0].pauses > 0);
    await h.timer.setMusic(2); assert.equal(h.nodes.length, 1); await h.timer.start();
    h.timer.reset(); assert.equal(h.nodes[1].src, ''); assert.equal(h.pending.size, 0);
    await h.timer.start(); h.advance(1001); h.fire();
    assert.equal(h.timer.snapshot().status, 'finished'); assert.equal(h.nodes[2].src, ''); assert.equal(h.pending.size, 0);
    h.timer.dispose();
});

test('music play rejection pauses with remaining time and permits fresh gesture retry', async () => {
    const wait = gate();
    const failed = new FakeAudio(); failed.result = wait.promise;
    // A custom factory makes the first node fail while retry creates a fresh node.
    let count = 0; const retry = harness({ assets: [asset(1)], createAudio: () => count++ === 0 ? failed : new FakeAudio() });
    retry.timer.configure(5); await retry.timer.setMusic(1); const started = retry.timer.start();
    retry.advance(1200); wait.reject(new Error('gesture blocked')); await started;
    assert.equal(retry.timer.snapshot().status, 'paused'); assert.equal(retry.timer.snapshot().remainingMs, 3800);
    assert.match(retry.errors[0].message, /gesture blocked/); assert.equal(retry.pending.size, 0); assert.equal(failed.src, '');
    await retry.timer.start(); assert.equal(retry.timer.snapshot().status, 'running'); retry.timer.dispose();
});

test('music load error pauses and releases, including synchronous load failure', async () => {
    const h = harness({ assets: [asset(1)] }); await h.timer.setMusic(1); await h.timer.start();
    const node = h.nodes[0]; const oldError = node.listeners.get('error'); h.advance(400); node.fail();
    assert.equal(h.timer.snapshot().status, 'paused'); assert.equal(h.timer.snapshot().remainingMs, 299600);
    assert.equal(h.errors.length, 1); assert.equal(node.listeners.size, 0);
    await h.timer.start(); oldError(); assert.equal(h.timer.snapshot().status, 'running'); assert.equal(h.errors.length, 1); h.timer.dispose();
    const bad = new FakeAudio(); bad.load = function () { this.fail(); };
    const sync = harness({ assets: [asset(1)], createAudio: () => bad }); await sync.timer.setMusic(1); await sync.timer.start();
    assert.equal(sync.timer.snapshot().status, 'paused'); assert.equal(sync.errors.length, 1); assert.equal(bad.plays, 0); sync.timer.dispose();
});

for (const action of ['pause', 'reset', 'replace', 'dispose']) for (const outcome of ['resolve', 'reject']) {
    test(`music stale play ${outcome} after ${action} only pauses its captured node`, async () => {
        const wait = gate(), nodes = [];
        const h = harness({ assets: [asset(1), asset(2)], createAudio: () => {
            const node = new FakeAudio(); if (!nodes.length) node.result = wait.promise; nodes.push(node); return node;
        } });
        await h.timer.setMusic(1); const started = h.timer.start();
        if (action === 'replace') await h.timer.setMusic(2);
        else h.timer[action]();
        assert.ok(nodes[0].pauses > 0); assert.equal(nodes[0].src, action === 'pause' ? '/brands/7/studio/assets/1/file' : '');
        if (action === 'pause') nodes[0].result = Promise.resolve();
        if (action === 'pause' || action === 'reset') await h.timer.start();
        const changes = h.changes.length, errors = h.errors.length, pauses = nodes[0].pauses;
        wait[outcome](outcome === 'reject' ? new Error('stale') : undefined); await started;
        if (action === 'pause') assert.equal(nodes[0].pauses, pauses);
        else assert.ok(nodes[0].pauses > pauses);
        assert.equal(h.errors.length, errors); assert.equal(h.changes.length, changes);
        if (action !== 'dispose') { assert.equal(h.timer.snapshot().status, 'running'); assert.equal(action === 'pause' ? nodes.length : nodes[1].pauses, action === 'pause' ? 1 : 0); }
        else { assert.equal(h.pending.size, 0); assert.equal(nodes.length, 1); }
        h.timer.dispose();
    });
}

test('clock defaults and immutable snapshots do not publish on construction', () => {
    const h = harness();
    assert.equal(h.changes.length, 0);
    assert.equal(h.pending.size, 0);
    assert.deepEqual(h.timer.snapshot(), { durationMs: 300000, remainingMs: 300000, elapsedMs: 0,
        status: 'idle', label: '05:00', musicId: null, musicOptions: [], volume: .4, muted: false, visible: true });
    assert.throws(() => { h.timer.snapshot().status = 'running'; }, TypeError);
    assert.throws(() => { h.timer.snapshot().musicOptions.push({ id: 9 }); }, TypeError);
});

test('clock validates integer duration and display settings', () => {
    const { timer } = harness();
    for (const value of [0, -1, 86401, 1.5, NaN, Infinity, '10', null]) assert.throws(() => timer.configure(value));
    timer.configure(86400); assert.equal(timer.snapshot().label, '24:00:00');
    timer.configure(3661); assert.equal(timer.snapshot().label, '01:01:01');
    timer.configure(1); assert.equal(timer.snapshot().label, '00:01');
    for (const value of [-.1, 1.1, NaN, '0.4']) assert.throws(() => timer.setVolume(value));
    assert.throws(() => timer.setMuted(1)); assert.throws(() => timer.setVisible('yes'));
    timer.setVolume(0); timer.setMuted(true); timer.setVisible(false);
    assert.equal(timer.snapshot().volume, 0); assert.equal(timer.snapshot().muted, true); assert.equal(timer.snapshot().visible, false);
});

test('clock uses monotonic elapsed time, preserves pause and finishes without tick drift', async () => {
    const h = harness(); h.timer.configure(5);
    await h.timer.start(); await h.timer.start(); assert.equal(h.pending.size, 1);
    h.advance(2301); h.fire(); assert.equal(h.timer.snapshot().remainingMs, 2699); assert.equal(h.timer.snapshot().label, '00:03');
    h.advance(200); h.timer.pause(); assert.equal(h.pending.size, 0); assert.equal(h.timer.snapshot().remainingMs, 2499);
    h.advance(10000); assert.equal(h.timer.snapshot().remainingMs, 2499);
    await h.timer.start(); h.timer.setVisible(false); h.advance(3000); h.fire();
    assert.equal(h.timer.snapshot().status, 'finished'); assert.equal(h.timer.snapshot().remainingMs, 0);
    assert.equal(h.timer.snapshot().label, '00:00'); assert.equal(h.timer.snapshot().elapsedMs, 5000); assert.equal(h.pending.size, 0);
    h.timer.reset(); assert.equal(h.timer.snapshot().status, 'idle'); assert.equal(h.timer.snapshot().remainingMs, 5000);
});

test('clock reset, reconfigure and disposal cancel captured callbacks', async () => {
    const h = harness(); await h.timer.start();
    const callback = h.pending.values().next().value;
    h.advance(123); h.timer.configure(3);
    assert.equal(h.timer.snapshot().status, 'idle'); assert.equal(h.pending.size, 0);
    callback(); assert.equal(h.pending.size, 0);
    await h.timer.start(); h.timer.reset(); assert.equal(h.pending.size, 0);
    await h.timer.start(); const stale = h.pending.values().next().value;
    h.timer.dispose(); h.timer.dispose(); const count = h.changes.length;
    stale(); await h.timer.start(); await h.timer.setMusic(null); h.timer.configure(4); h.timer.reset();
    h.timer.setVisible(false); h.timer.setMuted(true); h.timer.setVolume(.2); h.timer.pause();
    assert.equal(h.timer.snapshot().status, 'disposed'); assert.equal(h.changes.length, count); assert.equal(h.pending.size, 0);
});


test('music normal resume preserves same node position without reloading', async () => {
    const h = harness({ assets: [asset(1)] }); await h.timer.setMusic(1); await h.timer.start();
    const node = h.nodes[0]; node.currentTime = 7.25; h.advance(1200); h.timer.pause();
    assert.equal(node.src, '/brands/7/studio/assets/1/file'); assert.equal(node.loads, 1);
    h.advance(5000); await h.timer.start();
    assert.equal(h.nodes.length, 1); assert.equal(node.currentTime, 7.25);
    assert.equal(node.loads, 1); assert.equal(node.plays, 2); assert.equal(h.timer.snapshot().remainingMs, 298800);
    h.timer.dispose(); assert.equal(node.src, ''); assert.equal(node.listeners.size, 0);
});

for (const outcome of ['resolve', 'reject']) {
    test(`music same-node resume survives older play ${outcome}`, async () => {
        const first = gate(), second = gate();
        // Install the first deferred play before the synchronous gesture calls play().
        const node = new FakeAudio(); node.result = first.promise;
        const nodes = [];
        const r = harness({ assets: [asset(1)], createAudio: () => { nodes.push(node); return node; } });
        await r.timer.setMusic(1); const started = r.timer.start(); r.timer.pause();
        node.result = second.promise; const resumed = r.timer.start();
        assert.equal(nodes.length, 1); const pauses = node.pauses, changes = r.changes.length;
        first[outcome](outcome === 'reject' ? new Error('old play') : undefined); await started;
        assert.equal(node.pauses, pauses); assert.equal(r.timer.snapshot().status, 'running');
        assert.equal(r.changes.length, changes); assert.equal(r.errors.length, 0);
        second.resolve(); await resumed; r.timer.dispose();
    });
    test(`music older play ${outcome} while still paused prevents late playback`, async () => {
        const wait = gate(), node = new FakeAudio(); node.result = wait.promise;
        const h = harness({ assets: [asset(1)], createAudio: () => node });
        await h.timer.setMusic(1); const started = h.timer.start(); h.timer.pause();
        const pauses = node.pauses, changes = h.changes.length;
        wait[outcome](outcome === 'reject' ? new Error('old play') : undefined); await started;
        assert.ok(node.pauses > pauses); assert.equal(node.src, '/brands/7/studio/assets/1/file');
        assert.equal(h.timer.snapshot().status, 'paused'); assert.equal(h.changes.length, changes); assert.equal(h.errors.length, 0);
        h.timer.dispose();
    });
}

test('music resumed node still reports resource errors and permits fresh retry', async () => {
    const h = harness({ assets: [asset(1)] }); await h.timer.setMusic(1); await h.timer.start();
    const node = h.nodes[0]; h.timer.pause(); await h.timer.start(); node.fail();
    assert.equal(h.timer.snapshot().status, 'paused'); assert.equal(h.errors.length, 1);
    assert.equal(node.src, ''); assert.equal(node.listeners.size, 0);
    await h.timer.start(); assert.equal(h.nodes.length, 2); assert.equal(h.timer.snapshot().status, 'running'); h.timer.dispose();
});

test('music current owner resource error while paused releases and recovers on first gesture', async () => {
    const h = harness({ assets: [asset(1)] }); await h.timer.setMusic(1); await h.timer.start();
    const node = h.nodes[0]; h.advance(1000); h.timer.pause();
    node.error = { code: 4 };
    node.play = function () {
        this.plays++;
        return Promise.reject(new Error('NotSupportedError'));
    };
    node.fail();
    assert.equal(h.errors.length, 1, 'current retained owner error is reported during pause');
    assert.match(h.errors[0].message, /cargar/);
    assert.equal(node.src, '', 'broken resource is released synchronously');
    assert.equal(node.listeners.size, 0); assert.equal(node.loads, 2);
    assert.equal(h.timer.snapshot().status, 'paused'); assert.equal(h.timer.snapshot().remainingMs, 299000);
    assert.equal(h.pending.size, 0); h.advance(5000);
    const resumed = h.timer.start();
    assert.equal(h.nodes.length, 2); assert.equal(h.nodes[1].plays, 1, 'fresh node plays within first resume gesture');
    await resumed;
    assert.equal(h.timer.snapshot().status, 'running'); assert.equal(h.timer.snapshot().remainingMs, 299000);
    assert.equal(h.errors.length, 1); assert.equal(h.pending.size, 1); h.timer.dispose();
});

for (const action of ['replace', 'reset', 'dispose']) {
    test(`music released owner resource error after ${action} cannot affect current session`, async () => {
        const h = harness({ assets: [asset(1), asset(2)] }); await h.timer.setMusic(1); await h.timer.start();
        const old = h.nodes[0], oldError = old.listeners.get('error'); h.timer.pause();
        if (action === 'replace') await h.timer.setMusic(2);
        else h.timer[action]();
        if (action !== 'dispose') await h.timer.start();
        const before = h.timer.snapshot(), changes = h.changes.length;
        const current = h.nodes.at(-1), pauses = current.pauses;
        old.error = { code: 4 }; oldError();
        assert.deepEqual(h.timer.snapshot(), before); assert.equal(h.changes.length, changes);
        assert.equal(h.errors.length, 0); assert.equal(current.pauses, pauses);
        assert.equal(old.src, ''); assert.equal(old.listeners.size, 0); h.timer.dispose();
    });
}
