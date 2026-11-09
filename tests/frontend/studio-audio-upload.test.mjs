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
