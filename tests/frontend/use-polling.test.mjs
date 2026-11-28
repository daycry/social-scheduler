import assert from 'node:assert/strict';
import test from 'node:test';
import { createPolling } from '../../resources/js/Composables/usePolling.js';

function setup(t, run, options = {}) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const visibility = Object.assign(new EventTarget(), { hidden: false });
    const polling = createPolling(run, { initialDelay: 10, maxDelay: 25, maxAttempts: 3, visibility, ...options });
    t.after(polling.dispose);
    const advance = async ms => {
        t.mock.timers.tick(ms);
        await Promise.resolve();
    };
    function setHidden(hidden) {
        visibility.hidden = hidden;
        visibility.dispatchEvent(new Event('visibilitychange'));
    }
    return { polling, advance, setHidden };
}

test('polling backs off, caps the delay and stops after the configured number of checks', async t => {
    let calls = 0;
    let exhausted = 0;
    const { polling, advance } = setup(t, async () => { calls++; }, { onExhausted: () => { exhausted++; } });
    polling.setActive(true);
    await advance(9);
    assert.equal(calls, 0);
    await advance(1);
    assert.equal(calls, 1);
    await advance(19);
    assert.equal(calls, 1);
    await advance(1);
    assert.equal(calls, 2);
    await advance(24);
    assert.equal(calls, 2);
    await advance(1);
    assert.equal(calls, 3);
    await advance(1_000);
    assert.equal(calls, 3);
    assert.equal(exhausted, 1);
});

test('hidden tabs pause polling and a slow request never overlaps another', async t => {
    let calls = 0;
    let resolve;
    let signal;
    const { polling, advance, setHidden } = setup(t, currentSignal => {
        signal = currentSignal;
        calls++;
        return new Promise(done => { resolve = done; });
    });
    polling.setActive(true);
    setHidden(true);
    await advance(100);
    assert.equal(calls, 0);
    setHidden(false);
    await advance(10);
    assert.equal(calls, 1);
    await advance(100);
    assert.equal(calls, 1);
    setHidden(true);
    assert.equal(signal.aborted, true);
    resolve();
    await Promise.resolve();
    await advance(100);
    assert.equal(calls, 1);
    setHidden(false);
    await advance(20);
    assert.equal(calls, 2);
});

test('deactivation cancels an in-flight request and can start a fresh polling run', async t => {
    const signals = [];
    let done;
    const { polling, advance } = setup(t, signal => {
        signals.push(signal);
        return new Promise(resolve => { done = resolve; });
    });
    polling.setActive(true);
    await advance(10);
    polling.setActive(false);
    assert.equal(signals[0].aborted, true);
    polling.setActive(true);
    await advance(1_000);
    assert.equal(signals.length, 1);
    done();
    await Promise.resolve();
    await advance(10);
    assert.equal(signals.length, 2);
});

test('pausing the last in-flight check preserves the attempt and can resume', async t => {
    const requests = [];
    let exhausted = 0;
    const { polling, advance, setHidden } = setup(t, signal => {
        const pending = Promise.withResolvers();
        requests.push({ signal, resolve: pending.resolve });
        return pending.promise;
    }, { maxAttempts: 1, onExhausted: () => { exhausted++; } });

    polling.setActive(true);
    await advance(10);
    setHidden(true);
    assert.equal(requests[0].signal.aborted, true);
    requests[0].resolve();
    await advance(0);
    assert.equal(exhausted, 0);

    setHidden(false);
    await advance(10);
    assert.equal(requests.length, 2);
    requests[1].resolve();
    await advance(0);
    assert.equal(exhausted, 1);
});

test('restarting aborts stale work and gives the next check a fresh budget', async t => {
    const requests = [];
    let exhausted = 0;
    const { polling, advance } = setup(t, signal => {
        const pending = Promise.withResolvers();
        requests.push({ signal, resolve: pending.resolve });
        return pending.promise;
    }, { maxAttempts: 1, onExhausted: () => { exhausted++; } });

    polling.setActive(true);
    await advance(10);
    polling.restart();
    assert.equal(requests[0].signal.aborted, true);
    await advance(1_000);
    assert.equal(requests.length, 1);

    requests[0].resolve();
    await Promise.resolve();
    await advance(10);
    assert.equal(requests.length, 2);
    requests[1].resolve();
    await advance(0);
    assert.equal(exhausted, 1);
});
