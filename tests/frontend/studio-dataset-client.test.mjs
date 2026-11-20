import test from 'node:test';
import assert from 'node:assert/strict';
import { createDatasetAssembler, datasetTableChunks, cellText } from '../../resources/js/Studio/dataset-table.js';
import { route as ziggyRoute } from '../../vendor/tightenco/ziggy/dist/index.js';
import { createDatasetClient as createClient } from '../../resources/js/Studio/dataset-client.js';
import { StudioApiError } from '../../resources/js/Studio/api.js';

const path = 'brands/{brand}/studio/datasets';
const routes = {
    list: [path, 'GET'],
    get: [path + '/{dataset}', 'GET'],
    archive: [path + '/{dataset}', 'DELETE'],
    restore: [path + '/{dataset}/restore', 'POST'],
    history: [path + '/{dataset}/revisions', 'GET'],
    rows: [path + '/{dataset}/table', 'GET'],
    'import-create': [path + '/import', 'POST'],
    'import-replace': [path + '/{dataset}/import', 'POST'],
    export: [path + '/{dataset}/export', 'GET'],
};
const namedRoutes = {
    url: 'https://local.invalid',
    defaults: {},
    routes: Object.fromEntries(Object.entries(routes).map(([key, [uri, method]]) => [
        `studio.datasets.${key}`, { uri, methods: [method] },
    ])),
};
const routeFor = (name, params, absolute) => ziggyRoute(name, params, absolute, namedRoutes);
const createDatasetClient = options => createClient({ routeFor, ...options });

const metadata = { id: 7, brand_id: 3, name: 'Datos', revision: 2, row_count: 1, column_count: 1, archived_at: null };
const jsonResponse = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
function harness(extra = {}) {
    const calls = [];
    const client = createDatasetClient({ csrf: () => 'own-token', fetchImpl: async (url, options) => { calls.push({ url, options }); return jsonResponse({ datasets: [metadata], dataset: metadata, pagination: { current_page: 2, last_page: 3, total: 41 } }); }, ...extra });
    return { client, calls };
}
test('client rejects missing route configuration without making a request', async () => {
    const { client, calls } = harness({ routeFor: null });
    await assert.rejects(client.list(3), { name: 'StudioApiError', message: /No están disponibles las rutas de tablas/ });
    assert.deepEqual(calls, []);
});

test('client lists the exact brand with literal query pagination and same origin credentials', async () => {
    const { client, calls } = harness(); const abort = new AbortController();
    const result = await client.list(3, { query: 'A&B + =', page: 2, signal: abort.signal });
    assert.equal(result.datasets[0].id, 7);
    const url = new URL(calls[0].url, namedRoutes.url);
    assert.equal(url.pathname, '/brands/3/studio/datasets');
    assert.deepEqual(Object.fromEntries(url.searchParams), { query: 'A&B + =', page: '2', per_page: '20' });
    assert.equal(calls[0].options.credentials, 'same-origin'); assert.equal(calls[0].options.signal, abort.signal);
    assert.equal(new Headers(calls[0].options.headers).get('X-XSRF-TOKEN'), 'own-token');
});

test('client loads raw pages sequentially pinned to revision and passes AbortSignal', async () => {
    const raw = ['{"revision":2,"rows":[{"values":[0.0,9007199254740993]}]}', '{"revision":2,"rows":[{"values":[-0.0]}]}'];
    const calls = [], seen = [], table = { columns: [], rows: ['exact'] }; let nextOffset = 0;
    const client = createDatasetClient({ csrf: () => '', createAssembler: options => {
        assert.deepEqual(options, { datasetId: 7, revision: 2 });
        return { get complete() { return nextOffset === null; }, get nextOffset() { return nextOffset; }, get table() { return table; }, revision: 2, sha256: 'a'.repeat(64),
            append(text) { seen.push(text); nextOffset = seen.length === 1 ? 1 : null; } };
    }, fetchImpl: async (url, options) => { calls.push({ url, options }); return new Response(raw[calls.length - 1]); } });
    const abort = new AbortController(); const result = await client.load(3, 7, 2, { signal: abort.signal });
    assert.deepEqual(seen, raw); assert.equal(result.table, table); assert.equal(result.sha256, 'a'.repeat(64));
    assert.deepEqual(calls.map(call => call.url), ['/brands/3/studio/datasets/7/table?revision=2&offset=0&limit=100', '/brands/3/studio/datasets/7/table?revision=2&offset=1&limit=100']);
    assert.ok(calls.every(call => call.options.signal === abort.signal));
});

test('client saves exact typed chunks as multipart JSON with optimistic revision and no MIME override', async () => {
    const text = '{"columns":[{"id":"c","label":"N"}],"rows":[{"id":"r","values":[-0.0]}]}';
    const { client, calls } = harness({ tableChunks: table => { assert.equal(table, 'own-table'); return [text.slice(0, 30), text.slice(30)]; } });
    const result = await client.save(3, 7, { name: 'Datos 🎵', revision: 2, table: 'own-table' });
    assert.equal(result.dataset.id, 7); assert.equal(calls[0].url, '/brands/3/studio/datasets/7/import');
    const { body, headers, method } = calls[0].options;
    assert.equal(method, 'POST'); assert.equal(body.get('name'), 'Datos 🎵'); assert.equal(body.get('revision'), '2');
    assert.equal(body.get('format'), 'json'); assert.equal(body.has('header'), false); assert.equal(await body.get('file').text(), text);
    assert.equal(new Headers(headers).get('Content-Type'), null); assert.equal(new Headers(headers).get('X-XSRF-TOKEN'), 'own-token');
});

test('client imports original file without reading it and enforces independent format byte limits', async () => {
    const { client, calls } = harness(); const file = new Blob(['A,B\r\n=SUM(A1),001\r\n'], { type: 'text/csv' });
    const result = await client.upload(3, { file, name: 'Importación', format: 'csv', header: false });
    assert.equal(result.dataset.id, 7); assert.equal(calls[0].url, '/brands/3/studio/datasets/import');
    assert.equal(await calls[0].options.body.get('file').text(), await file.text());
    assert.equal(calls[0].options.body.get('header'), '0');
    await assert.rejects(client.upload(3, { file: new Blob([' '.repeat(25000001)]), name: 'Grande', format: 'csv' }), /25 MB/);
    await assert.rejects(client.upload(3, { file, name: 'XLSX', format: 'xlsx' }), /formato/i);
    assert.equal(calls.length, 1);
});

test('client metadata history archive restore and download URLs remain in the selected brand revision', async () => {
    const { client, calls } = harness();
    await client.get(3, 7); await client.history(3, 7, { page: 2 }); await client.control(3, 7, 'archive'); await client.control(3, 7, 'restore');
    assert.deepEqual(calls.map(call => [call.options.method, call.url]), [['GET', '/brands/3/studio/datasets/7'], ['GET', '/brands/3/studio/datasets/7/revisions?page=2&per_page=20'], ['DELETE', '/brands/3/studio/datasets/7'], ['POST', '/brands/3/studio/datasets/7/restore']]);
    assert.equal(client.exportUrl(3, 7, { revision: 2, format: 'csv' }), '/brands/3/studio/datasets/7/export?revision=2&format=csv&mode=spreadsheet');
    assert.equal(client.exportUrl(3, 7, { revision: 1, format: 'json', mode: 'text' }), '/brands/3/studio/datasets/7/export?revision=1&format=json');
    assert.equal(client.exportUrl(3, 7, { revision: 1, format: 'tsv', mode: 'text' }), '/brands/3/studio/datasets/7/export?revision=1&format=tsv&mode=text');
    assert.throws(() => client.exportUrl(3, 7, { revision: 1, format: 'xlsx' }), /formato/i);
    assert.throws(() => client.exportUrl(3, '../other', { revision: 1 }), /tabla/i);
});

test('client real codec integration preserves zero fractions and a large integer across pages and save Blob', async () => {
    const columns = '[{"id":"a","label":"A"},{"id":"b","label":"B"}]';
    const page = (offset, values, next) => `{"dataset":{"id":7,"brand_id":3,"revision":2},"revision":1,"sha256":"${'a'.repeat(64)}","columns":${columns},"rows":[{"id":"r${offset}","values":${values}}],"pagination":{"offset":${offset},"limit":100,"total":2,"next_offset":${next}}}`;
    const reads = [page(0, '[0.0,9007199254740993]', 1), page(1, '[-0.0,1.0]', null)], writes = [];
    const client = createDatasetClient({ csrf: () => '', createAssembler: createDatasetAssembler, tableChunks: datasetTableChunks,
        fetchImpl: async (url, options) => options.method === 'GET' ? new Response(reads.shift()) : (writes.push(options.body), jsonResponse({ dataset: metadata })) });
    const loaded = await client.load(3, 7, 1); assert.equal(cellText(loaded.table.rows[0].values[1]), '9007199254740993');
    await client.save(3, 7, { revision: 2, name: 'Datos', table: loaded.table });
    const raw = await writes[0].get('file').text(); assert.ok(raw.includes('[0.0,9007199254740993]')); assert.ok(raw.includes('[-0.0,1.0]')); assert.equal(reads.length, 0);
});
test('client exposes server validation details and aborts an ignored in flight page before reading another', async () => {
    const denied = createDatasetClient({ csrf: () => '', fetchImpl: async () => jsonResponse({ message: 'Invalid', errors: { name: ['Nombre no válido.'] } }, 422) });
    await assert.rejects(denied.list(3), error => error.status === 422 && error.message === 'Nombre no válido.');
    const abort = new AbortController(); let calls = 0;
    const client = createDatasetClient({ csrf: () => '', createAssembler: () => ({ complete: false, nextOffset: 0, append() { throw new Error('must not append aborted page'); } }),
        fetchImpl: async () => { calls++; abort.abort(); return new Response('{}'); } });
    await assert.rejects(client.load(3, 7, 2, { signal: abort.signal }), error => error.name === 'AbortError'); assert.equal(calls, 1);
});

test('client omits an optional blank search query while preserving literal search and GET transport', async () => {
    const { client, calls } = harness(); const abort = new AbortController();
    const cases = [{ page: 1 }, { query: '', page: 2 }, { query: ' \t\r\n ', page: 3 }];
    const observed = [];
    for (const options of cases) {
        const result = await client.list(3, { ...options, signal: abort.signal });
        assert.equal(result.datasets[0].id, 7);
        const call = calls.at(-1), url = new URL(call.url, 'https://local.invalid');
        observed.push({ hasQuery: url.searchParams.has('query'), page: url.searchParams.get('page'), perPage: url.searchParams.get('per_page') });
        assert.equal(url.pathname, '/brands/3/studio/datasets'); assert.equal(call.options.credentials, 'same-origin');
        assert.equal(call.options.method, 'GET'); assert.equal(new Headers(call.options.headers).get('Content-Type'), null);
        assert.equal(new Headers(call.options.headers).get('X-XSRF-TOKEN'), 'own-token'); assert.equal(call.options.signal, abort.signal);
    }
    const literal = ' Libre + = & '; await client.list(3, { query: literal, page: 4 });
    assert.equal(new URL(calls.at(-1).url, 'https://local.invalid').searchParams.get('query'), literal);
    assert.deepEqual(observed, [{ hasQuery: false, page: '1', perPage: '20' }, { hasQuery: false, page: '2', perPage: '20' }, { hasQuery: false, page: '3', perPage: '20' }]);
});

test('dataset error responses retain their HTTP status for a null body', async () => {
    const client = createDatasetClient({ csrf: () => '', fetchImpl: async () => jsonResponse(null, 422) });
    await assert.rejects(client.list(3), error => error instanceof StudioApiError && error.status === 422 && error.data === null);
});

test('dataset errors retain cancellation while reading the response body', async () => {
    const abort = new DOMException('Cancelled while reading the response.', 'AbortError');
    const body = new ReadableStream({ start: controller => controller.error(abort) });
    const client = createDatasetClient({ csrf: () => '', fetchImpl: async () => new Response(body, { status: 422 }) });
    await assert.rejects(client.list(3), error => error === abort);
});


test('dataset actions use named Ziggy web routes with relative URLs and literal query parameters', async () => {
    const generated = [];
    const { client, calls } = harness({ routeFor(name, params, absolute) {
        generated.push({ name, params, absolute });
        return 'https://local.invalid/fixture/subpath/' + name;
    } });
    await client.list(3, { query: 'A&B + =', page: 2 });
    await client.control(3, 7, 'restore');
    assert.equal(client.exportUrl(3, 7, { revision: 2, format: 'json' }), '/fixture/subpath/studio.datasets.export');
    assert.deepEqual(generated, [
        { name: 'studio.datasets.list', params: { brand: 3, _query: { query: 'A&B + =', page: 2, per_page: 20 } }, absolute: true },
        { name: 'studio.datasets.restore', params: { brand: 3, dataset: 7 }, absolute: true },
        { name: 'studio.datasets.export', params: { brand: 3, dataset: 7, _query: { revision: 2, format: 'json' } }, absolute: true },
    ]);
    assert.deepEqual(calls.map(call => call.url), [
        '/fixture/subpath/studio.datasets.list',
        '/fixture/subpath/studio.datasets.restore',
    ]);
});

test('actual Ziggy routes preserve the deployment subdirectory for list and export', async () => {
    const config = { ...namedRoutes, url: 'https://local.invalid/subdir' };
    const { client, calls } = harness({
        routeFor: (name, params, absolute) => ziggyRoute(name, params, absolute, config),
    });

    await client.list(3);
    assert.equal(calls[0].url, '/subdir/brands/3/studio/datasets?page=1&per_page=20');
    assert.equal(client.exportUrl(3, 7, { revision: 2, format: 'json' }), '/subdir/brands/3/studio/datasets/7/export?revision=2&format=json');
});
