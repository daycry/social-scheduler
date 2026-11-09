import test from 'node:test';
import assert from 'node:assert/strict';
import { createDatasetAssembler, datasetTableChunks, cellText } from '../../resources/js/Studio/dataset-table.js';
import { createDatasetClient } from '../../resources/js/Studio/dataset-client.js';

const metadata = { id: 7, brand_id: 3, name: 'Datos', revision: 2, row_count: 1, column_count: 1, archived_at: null };
const jsonResponse = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
function harness(extra = {}) {
    const calls = [];
    const client = createDatasetClient({ csrf: () => 'own-token', fetchImpl: async (url, options) => { calls.push({ url, options }); return jsonResponse({ datasets: [metadata], dataset: metadata, pagination: { current_page: 2, last_page: 3, total: 41 } }); }, ...extra });
    return { client, calls };
}
test('client lists the exact brand with literal query pagination and same origin credentials', async () => {
    const { client, calls } = harness(); const abort = new AbortController();
    const result = await client.list(3, { query: 'A&B + =', page: 2, signal: abort.signal });
    assert.equal(result.datasets[0].id, 7);
    assert.equal(calls[0].url, '/brands/3/studio/datasets?query=A%26B+%2B+%3D&page=2&per_page=20');
    assert.equal(calls[0].options.credentials, 'same-origin'); assert.equal(calls[0].options.signal, abort.signal);
    assert.equal(calls[0].options.headers['X-XSRF-TOKEN'], 'own-token');
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
    assert.equal(headers['Content-Type'], undefined); assert.equal(headers['X-XSRF-TOKEN'], 'own-token');
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
