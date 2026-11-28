import assert from 'node:assert/strict';
import test from 'node:test';
import { HttpError } from '../../resources/js/lib/http.js';
import { createAiStatus } from '../../resources/js/Composables/useAiStatus.js';

test('a composer shares one status request and the updated quota', async () => {
    const calls = [];
    const status = createAiStatus({
        resolveRoute: name => name,
        transport: async (method, url, options) => {
            calls.push([method, url, options.json]);
            return method === 'GET' ? { enabled: true, remaining: 4 } : { text: 'Propuesta', remaining: 3 };
        },
    });

    await Promise.all([status.load(), status.load()]);
    assert.equal(status.enabled.value, true);
    assert.equal(status.remaining.value, 4);
    assert.equal((await status.call('ai.suggest', { brief: 'Taller' })).text, 'Propuesta');
    assert.equal(status.remaining.value, 3);
    assert.deepEqual(calls, [['GET', 'ai.status', undefined], ['POST', 'ai.suggest', { brief: 'Taller' }]]);
});

test('quota errors update both consumers while preserving the typed HTTP error', async () => {
    const failure = new HttpError('Cuota agotada.', 429, { remaining: 0 });
    const status = createAiStatus({
        resolveRoute: name => name,
        transport: async method => {
            if (method === 'POST') throw failure;
            return { enabled: true, remaining: 1 };
        },
    });

    await status.load();
    await assert.rejects(status.call('ai.schedule', { phrase: 'Mañana' }), error => error === failure);
    assert.equal(status.remaining.value, 0);
});

test('the status exposes whether the model accepts images', async () => {
    const status = createAiStatus({ resolveRoute: name => name, transport: async () => ({ enabled: true, remaining: 3, vision: false }) });
    await status.load();
    assert.equal(status.vision.value, false);
    const withVision = createAiStatus({ resolveRoute: name => name, transport: async () => ({ enabled: true, remaining: 3, vision: true }) });
    await withVision.load();
    assert.equal(withVision.vision.value, true);
});

test('the status says when it is known, even if the request fails', async () => {
    const ok = createAiStatus({ resolveRoute: name => name, transport: async () => ({ enabled: false, remaining: 0 }) });
    assert.equal(ok.loaded.value, false);
    await ok.load();
    assert.equal(ok.loaded.value, true);
    assert.equal(ok.enabled.value, false);

    const broken = createAiStatus({ resolveRoute: name => name, transport: async () => { throw new Error('offline'); } });
    await broken.load();
    assert.equal(broken.loaded.value, true);
    assert.equal(broken.enabled.value, false);
});
