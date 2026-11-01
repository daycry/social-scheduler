import test from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, watchEffect } from 'vue';
import { loadTiktokCreator, tiktokCreator } from '../../resources/js/Composables/tiktokCreator.js';

test('failed creator-info is not retried by reactive effects; explicit retry can recover', async () => {
    const originalFetch = globalThis.fetch;
    const originalRoute = globalThis.route;
    const scope = effectScope();
    let calls = 0;
    let success = false;
    globalThis.route = () => 'http://localhost/creator-fixture';
    globalThis.fetch = async () => {
        calls++;
        await new Promise(resolve => setTimeout(resolve, 5));
        return new Response(JSON.stringify(success ? { nickname: 'Recovered', privacy_level_options: ['SELF_ONLY'] } : { message: 'Unavailable' }), { status: success ? 200 : 503, headers: { 'Content-Type': 'application/json' } });
    };
    try {
        scope.run(() => watchEffect(() => { loadTiktokCreator(1, 987654321); }));
        await new Promise(resolve => setTimeout(resolve, 100));
        assert.equal(calls, 1, 'one failed provider request must not create a reactive retry loop');
        assert.equal(tiktokCreator(987654321).error, 'Unavailable');
        success = true;
        await loadTiktokCreator(1, 987654321, true);
        assert.equal(calls, 2);
        assert.equal(tiktokCreator(987654321).data.nickname, 'Recovered');
    } finally {
        scope.stop();
        globalThis.fetch = originalFetch;
        globalThis.route = originalRoute;
    }
});

for (const [index, message] of ['', false, 0, null, {}].entries()) {
    test(`failed creator-info with message ${JSON.stringify(message)} remains a stable error`, async () => {
        const originalFetch = globalThis.fetch;
        const originalRoute = globalThis.route;
        const scope = effectScope();
        const accountId = 987654400 + index;
        let calls = 0;
        globalThis.route = () => 'http://localhost/creator-fixture';
        globalThis.fetch = async () => {
            calls++;
            await new Promise(resolve => setTimeout(resolve, 5));
            return new Response(JSON.stringify({ message }), { status: 503, headers: { 'Content-Type': 'application/json' } });
        };
        try {
            scope.run(() => watchEffect(() => { loadTiktokCreator(1, accountId); }));
            await new Promise(resolve => setTimeout(resolve, 100));
            assert.equal(calls, 1, 'failed status, not truthiness of a provider message, controls retries');
            assert.equal(typeof tiktokCreator(accountId).error, 'string');
            assert.ok(tiktokCreator(accountId).error.length > 0);
        } finally {
            scope.stop();
            globalThis.fetch = originalFetch;
            globalThis.route = originalRoute;
        }
    });
}

for (const [index, body] of [null, false, 0, '', [], {}].entries()) {
    test(`malformed successful creator-info ${JSON.stringify(body)} becomes a stable error`, async () => {
        const originalFetch = globalThis.fetch;
        const originalRoute = globalThis.route;
        const scope = effectScope();
        const accountId = 987654500 + index;
        let calls = 0;
        globalThis.route = () => 'http://localhost/creator-fixture';
        globalThis.fetch = async () => {
            calls++;
            await new Promise(resolve => setTimeout(resolve, 5));
            return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
        };
        try {
            scope.run(() => watchEffect(() => { loadTiktokCreator(1, accountId); }));
            await new Promise(resolve => setTimeout(resolve, 100));
            assert.equal(calls, 1);
            assert.equal(typeof tiktokCreator(accountId).error, 'string');
            assert.ok(tiktokCreator(accountId).error.length > 0);
        } finally {
            scope.stop();
            globalThis.fetch = originalFetch;
            globalThis.route = originalRoute;
        }
    });
}
