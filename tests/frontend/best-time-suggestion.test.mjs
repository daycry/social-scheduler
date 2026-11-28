import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope, nextTick, reactive } from 'vue';
import { useBestTimeSuggestion } from '../../resources/js/Composables/useBestTimeSuggestion.js';

const proposal = (overrides = {}) => ({
    available: true,
    reason: null,
    brand_id: 7,
    network: 'instagram',
    timezone: 'UTC',
    posts: 12,
    min_posts: 10,
    scheduled_at: '2099-01-01T10:00',
    scheduled_at_utc: '2099-01-01T10:00:00+00:00',
    ...overrides,
});
const response = (body, status = 200) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

function harness(t) {
    const oldFetch = globalThis.fetch;
    const oldRoute = globalThis.route;
    const hadRoute = Object.hasOwn(globalThis, 'route');
    const calls = [];
    globalThis.route = (name, params) => {
        assert.equal(name, 'best-times.suggest');
        return `http://localhost/brands/${params.brand}/best-time-suggestion?network=${encodeURIComponent(params.network)}`;
    };
    // Deliberately ignore AbortSignal: identity checks must also reject an already-delivered response.
    globalThis.fetch = (url, options) =>
        new Promise((resolve, reject) => calls.push({ url, options, resolve, reject }));
    const context = reactive({ brandId: 7, network: 'instagram', timezone: 'UTC', value: '', disabled: false });
    const applied = [];
    const scope = effectScope();
    const api = scope.run(() =>
        useBestTimeSuggestion(
            () => ({ ...context }),
            (value) => applied.push(value),
        ),
    );
    t.after(() => {
        scope.stop();
        globalThis.fetch = oldFetch;
        if (hadRoute) globalThis.route = oldRoute;
        else delete globalThis.route;
    });
    return { context, applied, calls, scope, api };
}

test('best time requests the selected brand/network and applies one future proposal with a readable status', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    assert.equal(h.api.busy.value, true);
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].options.method, 'GET');
    assert.match(h.calls[0].url, /brands\/7\/best-time-suggestion\?network=instagram$/);
    assert.equal(h.calls[0].options.signal.aborted, false);
    h.calls[0].resolve(response(proposal()));
    await pending;
    assert.deepEqual(h.applied, [proposal()]);
    assert.equal(h.api.busy.value, false);
    assert.equal(h.api.error.value, '');
    assert.match(h.api.notice.value, /2099/);
    assert.match(h.api.notice.value, /UTC/);
});

test('insufficient data preserves the current value and exposes the actual reason', async (t) => {
    const h = harness(t);
    h.context.value = '2099-02-03T11:30';
    const pending = h.api.suggest();
    const reason = 'Faltan métricas: mínimo 10 publicaciones.';
    h.calls[0].resolve(response(proposal({ available: false, reason, scheduled_at: null, scheduled_at_utc: null })));
    await pending;
    assert.deepEqual(h.applied, []);
    assert.equal(h.context.value, '2099-02-03T11:30');
    assert.equal(h.api.notice.value, reason);
    assert.equal(h.api.error.value, '');
    assert.equal(h.api.busy.value, false);
});

test('a brand switch aborts and invalidates the old response without clearing a newer request', async (t) => {
    const h = harness(t);
    const first = h.api.suggest();
    h.context.brandId = 8;
    await nextTick();
    assert.equal(h.calls[0].options.signal.aborted, true);
    const second = h.api.suggest();
    h.calls[0].resolve(response(proposal()));
    await first;
    assert.deepEqual(h.applied, []);
    assert.equal(h.api.busy.value, true);
    h.calls[1].resolve(response(proposal({ brand_id: 8 })));
    await second;
    assert.equal(h.applied.length, 1);
    assert.equal(h.applied[0].brand_id, 8);
    assert.equal(h.api.busy.value, false);
});

test('manual date edits during a request are never overwritten', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    h.context.value = '2099-03-04T12:00';
    h.calls[0].resolve(response(proposal()));
    await pending;
    assert.deepEqual(h.applied, []);
    assert.equal(h.context.value, '2099-03-04T12:00');
    assert.match(h.api.notice.value, /cambiaste.*fecha/i);
    assert.equal(h.api.busy.value, false);
});

test('server failures remain retryable and never apply a value', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    h.calls[0].resolve(response({ message: 'Servicio no disponible.' }, 503));
    await pending;
    assert.deepEqual(h.applied, []);
    assert.equal(h.api.error.value, 'Servicio no disponible.');
    assert.equal(h.api.busy.value, false);
    const retry = h.api.suggest();
    assert.equal(h.calls.length, 2);
    h.calls[1].resolve(response(proposal()));
    await retry;
    assert.equal(h.applied.length, 1);
});

test('a proposal from another scope or with a past instant cannot change the form', async (t) => {
    const h = harness(t);
    for (const value of [
        proposal({ brand_id: 8 }),
        proposal({ network: 'facebook' }),
        proposal({ scheduled_at_utc: '2000-01-01T10:00:00+00:00' }),
    ]) {
        const pending = h.api.suggest();
        h.calls.at(-1).resolve(response(value));
        await pending;
        assert.deepEqual(h.applied, []);
        assert.notEqual(h.api.error.value, '');
        assert.equal(h.api.busy.value, false);
    }
});

test('scope disposal aborts work and ignores a response that arrives after unmount', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    h.scope.stop();
    assert.equal(h.calls[0].options.signal.aborted, true);
    h.calls[0].resolve(response(proposal()));
    await pending;
    assert.deepEqual(h.applied, []);
    assert.equal(h.api.busy.value, false);
    assert.equal(h.api.notice.value, '');
    assert.equal(h.api.error.value, '');
});

test('editing and then clearing the date while pending still preserves the users final choice', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    h.context.value = '2099-03-04T12:00';
    await nextTick();
    h.context.value = '';
    await nextTick();
    h.calls[0].resolve(response(proposal()));
    await pending;
    assert.deepEqual(h.applied, []);
    assert.equal(h.context.value, '');
    assert.match(h.api.notice.value, /cambiaste.*fecha/i);
});

test('success status survives deferred parent feedback but clears after a later manual edit', async (t) => {
    const h = harness(t);
    const pending = h.api.suggest();
    h.calls[0].resolve(response(proposal()));
    await pending;
    h.context.value = proposal().scheduled_at;
    await nextTick();
    assert.match(h.api.notice.value, /Fecha propuesta/);
    h.context.value = '2099-01-02T11:30';
    await nextTick();
    assert.equal(h.api.notice.value, '');
});
