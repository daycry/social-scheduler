import test from 'node:test';
import assert from 'node:assert/strict';

test('audio uploader transfers private binary bytes and completes the brand ticket without leaking credentials to storage', async () => {
    let api;
    try { api = await import('../../resources/js/Studio/audio-api.js'); } catch (error) { if (error.code !== 'ERR_MODULE_NOT_FOUND') throw error; }
    assert.equal(typeof api?.uploadStudioAudio, 'function', 'brand audio uploader is missing');
    const previous = { fetch: globalThis.fetch, document: globalThis.document, route: globalThis.route };
    const calls = [];
    globalThis.document = { cookie: 'XSRF-TOKEN=private-csrf' };
    globalThis.route = (name, id) => `/brands/${id}/studio/audio`;
    globalThis.fetch = async (url, options) => {
        calls.push({ url, ...options });
        if (calls.length === 1) return Response.json({ asset: { id: 12 }, upload: { mode: 'single', url: 'https://storage.invalid/signed', headers: { 'Content-Type': 'audio/wav' } }, complete_url: '/brands/7/studio/audio/12/complete' });
        if (calls.length === 2) return new Response('', { status: 200 });
        return Response.json({ asset: { id: 12, status: 'listo', kind: 'audio' } });
    };
    try {
        const file = new File(['own audio'], 'voice.wav', { type: 'audio/wav' });
        const result = await api.uploadStudioAudio(7, file);
        assert.equal(result.id, 12);
        assert.equal(calls[0].url, '/brands/7/studio/audio');
        assert.equal(calls[1].body, file);
        assert.equal(calls[1].credentials, 'omit');
        assert.equal(calls[1].headers['X-XSRF-TOKEN'], undefined);
        assert.equal(calls[1].headers.Authorization, undefined);
        assert.equal(calls[2].url, '/brands/7/studio/audio/12/complete');
        assert.equal(calls[2].credentials, 'same-origin');
    } finally { Object.assign(globalThis, previous); }
});

test('a temporary validation failure retries completion without uploading the bytes again', async () => {
    const { uploadStudioAudio, completeStudioAudio } = await import('../../resources/js/Studio/audio-api.js');
    const previous = { fetch: globalThis.fetch, document: globalThis.document, route: globalThis.route };
    const calls = [];
    globalThis.document = { cookie: '' }; globalThis.route = () => '/brands/7/studio/audio';
    globalThis.fetch = async (url, options) => {
        calls.push({ url, ...options });
        if (calls.length === 1) return Response.json({ upload: { mode: 'single', url: 'https://storage.invalid/signed' }, complete_url: '/brands/7/studio/audio/12/complete' });
        if (calls.length === 2) return new Response('', { status: 200 });
        if (calls.length === 3) return Response.json({ message: 'Sondeador no disponible' }, { status: 503 });
        return Response.json({ asset: { id: 12, status: 'listo' } });
    };
    try {
        let pending;
        await assert.rejects(uploadStudioAudio(7, new File(['tone'], 'voice.wav', { type: 'audio/wav' })), error => {
            pending = error.audioTicket; return error.status === 503 && pending.complete_url === '/brands/7/studio/audio/12/complete';
        });
        assert.equal((await completeStudioAudio(pending)).id, 12);
        assert.equal(calls.filter(call => call.method === 'PUT').length, 1);
        assert.equal(calls.filter(call => call.url === pending.complete_url).length, 2);
    } finally { Object.assign(globalThis, previous); }
});

test('audio completion retains retry tickets only for transient failures and exposes Studio HTTP errors', async t => {
    const { completeStudioAudio } = await import('../../resources/js/Studio/audio-api.js');
    const { StudioApiError } = await import('../../resources/js/Studio/api.js');
    const ticket = { complete_url: '/brands/7/studio/audio/12/complete' };
    const networkError = new TypeError('Connection failed');
    const invalid = { message: 'Invalid', errors: { file: ['Audio no válido.'] } };
    const fetch = t.mock.method(globalThis, 'fetch', async () => { throw networkError; });
    await assert.rejects(completeStudioAudio(ticket), error => error === networkError && error.audioTicket === ticket);

    fetch.mock.mockImplementation(async () => Response.json(invalid, { status: 422 }));
    await assert.rejects(completeStudioAudio(ticket), error => {
        assert.ok(error instanceof StudioApiError);
        assert.equal(error.status, 422);
        assert.equal(error.message, 'Audio no válido.');
        assert.deepEqual(error.data, invalid);
        assert.equal(error.audioTicket, undefined);
        return true;
    });
});

test('local audio preserves the cancellation reason during validation response reading', async t => {
    const { uploadStudioAudio } = await import('../../resources/js/Studio/audio-api.js');
    const cancelled = new DOMException('Cancelled while reading the response.', 'AbortError');
    const previousRoute = globalThis.route;
    globalThis.route = () => '/brands/7/studio/audio';
    t.after(() => {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
    });
    t.mock.method(globalThis, 'fetch', async (url, { method }) => {
        if (method === 'POST') {
            return Response.json({ upload: { mode: 'local', url: '/brands/7/studio/audio/12/content' } });
        }
        return new Response(new ReadableStream({ start: controller => controller.error(cancelled) }), { status: 503 });
    });

    await assert.rejects(uploadStudioAudio(7, new File(['tone'], 'voice.wav', { type: 'audio/wav' })), error => error === cancelled);
});

test('Studio media preparation preserves typed field details in the displayed message', async t => {
    const { StudioApiError, uploadStudioFile } = await import('../../resources/js/Studio/api.js');
    const previousRoute = globalThis.route;
    globalThis.route = () => '/media';
    t.after(() => {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
    });
    const errors = { name: ['Nombre no válido.'], mime: ['Formato no admitido.'] };
    t.mock.method(globalThis, 'fetch', async () => Response.json({ message: 'Nombre no válido. (and 1 more)', errors }, { status: 422 }));

    await assert.rejects(uploadStudioFile(new File(['data'], 'test.png', { type: 'image/png' })), error => {
        assert.ok(error instanceof StudioApiError);
        assert.equal(error.status, 422);
        assert.deepEqual(error.errors, errors);
        assert.equal(error.message, 'Nombre no válido. Formato no admitido.');
        return true;
    });
});

test('Studio media completion displays all validation details', async t => {
    const { StudioApiError, uploadStudioFile } = await import('../../resources/js/Studio/api.js');
    const previousRoute = globalThis.route;
    globalThis.route = (name, id) => name === 'media.store' ? '/media' : '/media/' + id + '/complete';
    t.after(() => {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
    });
    const errors = { parts: ['Falta una parte.'], file: ['Formato no válido.'] };
    const replies = [
        Response.json({ asset: { id: 7 }, upload: { mode: 'single', url: 'https://storage.invalid/asset' } }),
        new Response(null, { status: 200 }),
        Response.json({ message: 'Falta una parte. (and 1 more)', errors }, { status: 422 }),
    ];
    t.mock.method(globalThis, 'fetch', async () => replies.shift());

    await assert.rejects(uploadStudioFile(new File(['data'], 'test.png', { type: 'image/png' })), error => {
        assert.ok(error instanceof StudioApiError);
        assert.equal(error.status, 422);
        assert.equal(error.message, 'Falta una parte. Formato no válido.');
        return true;
    });
    assert.equal(replies.length, 0);
});

test('local audio preserves all reported validation details', async t => {
    const { uploadStudioAudio } = await import('../../resources/js/Studio/audio-api.js');
    const { StudioApiError } = await import('../../resources/js/Studio/api.js');
    const previousRoute = globalThis.route;
    globalThis.route = () => '/brands/7/studio/audio';
    t.after(() => {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
    });
    const data = { message: 'Nombre no válido. (and 1 more)', errors: { name: ['Nombre no válido.'], mime: ['Formato no admitido.'] } };
    t.mock.method(globalThis, 'fetch', async (url, { method }) => method === 'POST'
        ? Response.json({ upload: { mode: 'local', url: '/brands/7/studio/audio/12/content' } })
        : Response.json(data, { status: 422 }));

    await assert.rejects(uploadStudioAudio(7, new File(['tone'], 'voice.wav', { type: 'audio/wav' })), error => {
        assert.ok(error instanceof StudioApiError);
        assert.equal(error.message, 'Nombre no válido. Formato no admitido.');
        assert.deepEqual(error.data, data);
        assert.equal(error.audioTicket, undefined);
        return true;
    });
});
