import test from 'node:test';
import assert from 'node:assert/strict';
import { HttpError, readXsrfCookie, requestJson, uploadMedia } from '../../resources/js/lib/http.js';

const tokenHeader = 'X-XSRF-TOKEN';
const reply = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });

test('reads only the exact session cookie and decodes it once', () => {
    assert.equal(readXsrfCookie('prefix-XSRF-TOKEN=other;XSRF-TOKEN=real%2521; foo=1'), 'real%21');
    assert.equal(readXsrfCookie('prefix-XSRF-TOKEN=other'), '');
    assert.equal(readXsrfCookie('XSRF-TOKEN=%E0%A4%A'), '');
});

test('sends the session token and AbortSignal for same-origin JSON', async () => {
    const controller = new AbortController();
    const payload = { revision: 2 };
    const result = await requestJson('PATCH', '/studio/designs/5', {
        json: payload,
        signal: controller.signal,
        csrf: () => 'test-token',
        fetchImpl: async (url, init) => {
            assert.equal(url, '/studio/designs/5');
            assert.equal(init.credentials, 'same-origin');
            assert.equal(init.signal, controller.signal);
            assert.equal(new Headers(init.headers).get(tokenHeader), 'test-token');
            assert.deepEqual(JSON.parse(init.body), payload);
            return reply({ saved: 2 });
        },
    });
    assert.deepEqual(result, { saved: 2 });
});

test('HTTP failures preserve the field errors, status and payload', async () => {
    const data = { message: 'Revisa los datos.', errors: { name: ['Escribe un nombre.'] } };
    await assert.rejects(
        requestJson('POST', '/studio/designs', { json: {}, fetchImpl: async () => reply(data, 422) }),
        error => {
            assert.ok(error instanceof HttpError);
            assert.equal(error.status, 422);
            assert.deepEqual(error.data, data);
            assert.deepEqual(error.errors, data.errors);
            return true;
        },
    );
});

test('a transport failure keeps its native error for callers that retry', async () => {
    const failure = new TypeError('simulated connection problem');
    await assert.rejects(requestJson('GET', '/studio/designs', { fetchImpl: async () => { throw failure; } }), error => error === failure);
});

test('signed media PUT sends the storage headers and leaves the session on app requests', async () => {
    const file = new File(['demo'], 'demo.png', { type: 'image/png' });
    const signed = 'https://storage.example.test/asset?signature=dummy';
    const stages = [];
    const calls = [];
    const signal = new AbortController().signal;
    const fetchImpl = async (url, init) => {
        calls.push({ url, init });
        if (url === '/media') return reply({ asset: { id: 7 }, upload: { mode: 'single', url: signed, headers: { 'Content-Type': file.type } } });
        if (url === signed) return new Response(null, { status: 200 });
        if (url === '/media/7/complete') return reply({ asset: { id: 7, status: 'listo' } });
        throw new Error('unexpected request');
    };
    const asset = await uploadMedia(file, {
        storeUrl: '/media',
        completeUrl: id => '/media/' + id + '/complete',
        onStatus: message => stages.push(message),
        csrf: () => 'app-token',
        fetchImpl,
        signal,
    });
    assert.deepEqual(asset, { id: 7, status: 'listo' });
    assert.deepEqual(calls.map(call => call.url), ['/media', signed, '/media/7/complete']);
    assert.equal(calls[1].init.credentials, 'omit');
    assert.equal(new Headers(calls[1].init.headers).get(tokenHeader), null);
    assert.equal(new Headers(calls[1].init.headers).get('Content-Type'), file.type);
    assert.ok(calls.every(call => call.init.signal === signal));
    assert.equal(new Headers(calls[0].init.headers).get(tokenHeader), 'app-token');
    assert.equal(new Headers(calls[2].init.headers).get(tokenHeader), 'app-token');
    assert.ok(stages.length >= 2);
});

function mockStorageUpload(t, requests) {
    class Upload {
        upload = {};
        headers = new Headers();

        open(method, url) {
            this.method = method;
            this.url = url;
        }

        setRequestHeader(key, value) { this.headers.set(key, value); }
        getResponseHeader() { return this.url.split('/').at(-1); }

        send(body) {
            requests.push(this);
            queueMicrotask(() => {
                if (this.aborted) return;
                this.upload.onprogress?.({ lengthComputable: true, loaded: body.size });
                if (this.aborted) return;
                this.status = 200;
                this.onload();
            });
        }

        abort() {
            this.aborted = true;
            this.onabort();
        }
    }

    globalThis.XMLHttpRequest = Upload;
    t.after(() => { delete globalThis.XMLHttpRequest; });
}

test('multipart progress counts all bytes and completes with each returned receipt', async t => {
    const file = new File(['abcd'], 'demo.png', { type: 'image/png' });
    const calls = [];
    const uploads = [];
    const progress = [];
    mockStorageUpload(t, uploads);
    const result = await uploadMedia(file, {
        storeUrl: '/media',
        completeUrl: id => '/media/' + id + '/complete',
        onProgress: (loaded, total) => progress.push([loaded, total]),
        csrf: () => 'own-token',
        fetchImpl: async (url, init) => {
            calls.push({ url, init });
            if (url === '/media') return reply({ asset: { id: 7 }, upload: { mode: 'multipart', part_size: 2, parts: [
                { number: 1, url: 'https://storage.example.test/a' },
                { number: 2, url: 'https://storage.example.test/b' },
            ] } });
            return reply({ asset: { id: 7, status: 'listo' } });
        },
    });
    assert.deepEqual(progress, [[2, 4], [4, 4]]);
    assert.ok(uploads.every(upload => !upload.headers.has(tokenHeader) && !upload.withCredentials));
    assert.deepEqual(JSON.parse(calls[1].init.body), { parts: [{ number: 1, etag: 'a' }, { number: 2, etag: 'b' }] });
    assert.equal(result.status, 'listo');
});

test('cancelling an upload aborts in-flight XHR and skips the completion request', async t => {
    const file = new File(['demo'], 'demo.png', { type: 'image/png' });
    const abort = new AbortController();
    const urls = [];
    const uploads = [];
    mockStorageUpload(t, uploads);
    await assert.rejects(uploadMedia(file, {
        storeUrl: '/media',
        completeUrl: id => '/media/' + id + '/complete',
        signal: abort.signal,
        onProgress: () => abort.abort(),
        fetchImpl: async url => {
            urls.push(url);
            return reply({ asset: { id: 7 }, upload: { mode: 'single', url: 'https://storage.example.test/a' } });
        },
    }), { name: 'AbortError' });
    assert.deepEqual(urls, ['/media']);
    assert.equal(uploads[0].aborted, true);
});

test('a cancelled response body preserves the abort reason', async () => {
    const abort = new DOMException('Cancelled while reading the response.', 'AbortError');
    const body = new ReadableStream({ start: controller => controller.error(abort) });
    await assert.rejects(
        requestJson('GET', '/studio/designs', { fetchImpl: async () => new Response(body) }),
        error => error === abort,
    );
});

test('a non-JSON error body still reports the HTTP status', async () => {
    await assert.rejects(requestJson('GET', '/studio/designs', {
        fetchImpl: async () => new Response('Service unavailable', { status: 503 }),
    }), { name: 'HttpError', status: 503, data: null });
});
