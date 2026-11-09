import test from 'node:test';
import assert from 'node:assert/strict';
import { prepareBarChart, createPreparedBarChartGeometry, createBarChartGeometry } from '../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';

const source = (rows, count = rows[0]?.length ?? 1, precision = 'safe') => ({
    binding: { dataset: { id: 8, revision: 3, sha256: 'a'.repeat(64) }, category_column: 'category', series_columns: Array.from({ length: count }, (_, i) => `s${i}`), precision },
    snapshot: { id: 8, revision: 3, sha256: 'a'.repeat(64), table: {
        columns: [{ id: 'category', label: 'Categoría 😀' }, ...Array.from({ length: count }, (_, i) => ({ id: `s${i}`, label: `Serie ${i}` }))],
        rows: rows.map((values, i) => ({ id: `r${i}`, values: [`Categoría ${i}`, ...values.map(value => value === null ? null : datasetNumber(String(value)))] })),
    } },
});
const direct = (input, options) => createBarChartGeometry(input.binding, input.snapshot, options);
const prepared = input => prepareBarChart(input.binding, input.snapshot);
const sameError = (a, b) => {
    let expected;
    try { a(); } catch (error) { expected = error; }
    assert.ok(expected instanceof TypeError);
    assert.throws(b, error => error.constructor === expected.constructor && error.message === expected.message);
};
const frozen = value => {
    if (value && typeof value === 'object') {
        assert.ok(Object.isFrozen(value));
        for (const child of Object.values(value)) frozen(child);
    }
};

test('G5 equivalence ten variants preserve full geometry ordering colors negatives null and proportions', () => {
    for (const kind of ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor']) {
        const multi = /-(grouped|stacked|proportional)$/.test(kind);
        const input = source(multi ? [[1, 3, null], [0, 0, 0], [null, null, null], [2, kind.endsWith('proportional') ? 4 : -4, 1]] : [[2], [-4], [0], [null]]);
        const handle = prepared(input);
        for (const style of [{}, { width: 40.01, height: 123.45, band_gap: 0, series_gap: 0, opacity: 0.4, palette: ['#123', '#abc'], tick_count: 21 }]) {
            const options = { kind, ...style };
            const result = createPreparedBarChartGeometry(handle, options);
            assert.deepEqual(result, direct(input, options)); frozen(result);
        }
    }
});

test('G5 capture is own opaque frozen data and later external mutation or getters are never read', () => {
    const input = source([[3, 5], [null, 0]]);
    const options = { kind: 'column-grouped' }, expected = direct(input, options);
    const handle = prepared(input);
    assert.ok(handle && Object.isFrozen(handle)); assert.deepEqual(Reflect.ownKeys(handle), []);
    input.binding.dataset.id = 99; input.snapshot.table.rows[0].values[1] = datasetNumber('99');
    input.snapshot.table.columns[1].label = 'changed';
    let calls = 0;
    const poison = object => {
        for (const key of Object.keys(object)) Object.defineProperty(object, key, { enumerable: true, configurable: true, get() { calls++; throw new Error('external read'); } });
    };
    poison(input.binding); poison(input.snapshot.table.rows[0]); poison(input.snapshot.table.columns[0]); poison(input.snapshot.table); poison(input.snapshot);
    const result = createPreparedBarChartGeometry(handle, options);
    assert.deepEqual(result, expected); assert.equal(calls, 0);
    const palette = ['#123'];
    const changed = createPreparedBarChartGeometry(handle, { ...options, palette }); palette[0] = '#fff';
    assert.equal(changed.bars[0].fill, '#123'); assert.deepEqual(result, expected);
});

test('G5 handles reject foreign frozen forged cloned and serialized values without getter calls', () => {
    const handle = prepared(source([[1]]));
    let calls = 0;
    const forged = Object.freeze({ get projection() { calls++; throw new Error('foreign getter'); } });
    for (const value of [undefined, null, 1, 'x', {}, [], Object.freeze({}), forged, { ...handle }, structuredClone(handle), JSON.parse(JSON.stringify(handle)), Object.create(handle)]) {
        assert.throws(() => createPreparedBarChartGeometry(value), TypeError);
    }
    assert.equal(calls, 0);
});

test('G5 preparation validates identity precision and hostile external frozen inputs without trusting frozen', () => {
    const stale = source([[1]]); stale.snapshot.revision++;
    const text = source([[1]]); text.snapshot.table.rows[0].values[1] = '12';
    for (const input of [stale, text, source([['1e308']]), source([['1e-999']])]) sameError(() => direct(input), () => prepared(input));
    let calls = 0;
    const input = source([[1]]);
    Object.defineProperty(input.snapshot.table.rows[0].values, '1', { enumerable: true, get() { calls++; return datasetNumber('1'); } });
    Object.freeze(input.snapshot.table.rows[0].values); Object.freeze(input.snapshot.table);
    assert.throws(() => prepared(input), TypeError); assert.equal(calls, 0);
});

test('G5 warnings precision domains ticks and semantic failures match the existing geometry', () => {
    for (const input of [source([['1e-300'], ['1e308']], 1, 'approximate'), source([['1e-999']], 1, 'approximate'), source([['5e-324']]), source([['-0.0'], [null]]), source([], 1)]) {
        const handle = prepared(input);
        for (const options of [{}, { kind: 'bar', tick_count: 21 }, { domain_min: -1e308, domain_max: 1e308 }]) assert.deepEqual(createPreparedBarChartGeometry(handle, options), direct(input, options));
    }
    for (const [input, options] of [
        [source([[1, 2]]), {}], [source([[-1, 2]]), { kind: 'bar-proportional' }],
        [source([['1e-999', 1]], 2, 'approximate'), { kind: 'column-proportional' }],
        [source([['-1e-999', 1]], 2, 'approximate'), { kind: 'bar-proportional' }],
        [source([['1e308', '1e308']], 2, 'approximate'), { kind: 'column-stacked' }],
        [source([[2]]), { domain_max: 1 }], [source([[0]]), { domain_min: 0, domain_max: 0 }],
        [source([[1]]), { kind: 'bar-proportional', domain_max: 99 }],
    ]) {
        const handle = prepared(input); sameError(() => direct(input, options), () => createPreparedBarChartGeometry(handle, options));
    }
    const huge = source([['1e308', '1e308'], [0, 0], [null, null]], 2, 'approximate');
    assert.deepEqual(createPreparedBarChartGeometry(prepared(huge), { kind: 'column-proportional' }), direct(huge, { kind: 'column-proportional' }));
});

test('G5 prepared options remain closed and direct validation order precedes projection', () => {
    const handle = prepared(source([[1]]));
    let calls = 0; const options = { get width() { calls++; return 640; } };
    assert.throws(() => createPreparedBarChartGeometry(handle, options), /propios/);
    assert.equal(calls, 0);
    for (const value of [null, { extra: true }, { width: '640' }, { palette: new Array(1) }]) sameError(() => direct(source([[1]]), value), () => createPreparedBarChartGeometry(handle, value));
    sameError(() => direct(source([[1]]), { kind: 'line' }), () => createBarChartGeometry(null, null, { kind: 'line' }));
});

test('G5 twelve thousand bars allow repeated style changes without table access', t => {
    const input = source(Array.from({ length: 1000 }, (_, row) => Array.from({ length: 12 }, (_, col) => (row + col) % 7 - 3)));
    const expected = direct(input, { kind: 'column-grouped' });
    const started = performance.now(); const handle = prepared(input); const prepareMs = performance.now() - started;
    let calls = 0;
    Object.defineProperty(input.snapshot, 'table', { get() { calls++; throw new Error('table reread'); } });
    const layoutStart = performance.now();
    for (const kind of ['column-grouped', 'bar-grouped', 'column-stacked', 'bar-stacked']) {
        const result = createPreparedBarChartGeometry(handle, { kind });
        assert.ok(Array.isArray(result.bars), 'prepared layout must produce bars');
        assert.equal(result.bars.length, 12000); assert.equal(new Set(result.bars.map(bar => bar.key)).size, 12000);
        if (kind === 'column-grouped') assert.deepEqual(result, expected);
    }
    assert.equal(calls, 0);
    t.diagnostic(JSON.stringify({ rows: 1000, series: 12, bars: 12000, styleLayouts: 4, prepareMs, layoutsMs: performance.now() - layoutStart, externalTableReads: calls, heapUsedBytes: process.memoryUsage().heapUsed }));
});

test('G5 lifecycle retains neither external table nor dropped handles in a strong cache', async () => {
    assert.equal(typeof global.gc, 'function', 'run this suite with --expose-gc');
    let input = source([[1]]), handle = prepared(input);
    const tableRef = new WeakRef(input.snapshot.table), handleRef = new WeakRef(handle);
    input = null;
    const collect = async ref => {
        for (let i = 0; i < 20; i++) {
            await new Promise(resolve => setImmediate(resolve)); global.gc();
            if (ref.deref() === undefined) return true;
        }
        return false;
    };
    assert.equal(await collect(tableRef), true, 'handle must not retain external table');
    assert.deepEqual(createPreparedBarChartGeometry(handle), direct(source([[1]])));
    handle = null;
    assert.equal(await collect(handleRef), true, 'WeakMap must not retain dropped handle');
});
