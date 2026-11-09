import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeChartBinding, projectCategoricalChart } from '../../resources/js/Studio/chart-binding.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';

const sha = 'a'.repeat(64);
const binding = () => ({ dataset: { id: 7, revision: 3, sha256: sha }, category_column: 'category', series_columns: ['value'] });
const table = () => ({ columns: [{ id: 'category', label: 'Categoría' }, { id: 'value', label: 'Valor' }], rows: [{ id: 'r1', values: ['A', datasetNumber('1.0')] }] });
const snapshot = (data = table()) => ({ id: 7, revision: 3, sha256: sha, table: data });

test('binding normalizes a fixed identity and explicit default precision into own immutable data', () => {
    const source = binding();
    const result = normalizeChartBinding(source);
    assert.deepEqual(result, { ...source, precision: 'safe' });
    assert.notEqual(result, source);
    assert.ok(Object.isFrozen(result) && Object.isFrozen(result.dataset) && Object.isFrozen(result.series_columns));
    source.dataset.id = 9; source.series_columns[0] = 'other';
    assert.equal(result.dataset.id, 7); assert.deepEqual(result.series_columns, ['value']);
});

test('binding rejects invalid reference identity and unknown fields', () => {
    for (const reference of [
        { id: 0 }, { id: 1.5 }, { id: Number.MAX_SAFE_INTEGER + 1 }, { id: '7' },
        { revision: 0 }, { revision: NaN }, { revision: Infinity }, { revision: '3' },
        { sha256: 'A'.repeat(64) }, { sha256: 'a'.repeat(63) }, { sha256: 123 },
        { extra: true },
    ]) assert.throws(() => normalizeChartBinding({ ...binding(), dataset: { ...binding().dataset, ...reference } }), TypeError);
    assert.throws(() => normalizeChartBinding({ ...binding(), arbitrary: 'library options' }), TypeError);
    assert.throws(() => normalizeChartBinding({ ...binding(), precision: 'auto' }), TypeError);
    assert.throws(() => normalizeChartBinding({ ...binding(), precision: null }), TypeError);
});

test('binding requires one to twelve distinct series IDs separate from the category', () => {
    for (const change of [
        { category_column: '' }, { category_column: 'bad id' }, { category_column: 'x'.repeat(65) },
        { series_columns: [] }, { series_columns: Array.from({ length: 13 }, (_, i) => `v${i}`) },
        { series_columns: ['value', 'value'] }, { series_columns: ['category'] },
        { series_columns: ['bad id'] }, { series_columns: ['x'.repeat(65)] }, { series_columns: [1] },
        { series_columns: 'value' },
    ]) assert.throws(() => normalizeChartBinding({ ...binding(), ...change }), TypeError);
    const selected = Array.from({ length: 12 }, (_, i) => `v${i}`);
    assert.deepEqual(normalizeChartBinding({ ...binding(), series_columns: selected }).series_columns, selected);
});

test('binding rejects accessor fields without invoking getters and rejects inherited or hidden data', () => {
    let calls = 0;
    const bad = binding();
    Object.defineProperty(bad, 'category_column', { enumerable: true, get() { calls++; return 'category'; } });
    assert.throws(() => normalizeChartBinding(bad), TypeError);
    assert.equal(calls, 0);
    const nested = binding();
    Object.defineProperty(nested.dataset, 'id', { enumerable: true, get() { calls++; return 7; } });
    assert.throws(() => normalizeChartBinding(nested), TypeError);
    assert.equal(calls, 0);
    assert.throws(() => normalizeChartBinding(Object.assign(Object.create({ extra: 1 }), binding())), TypeError);
    const hidden = binding(); Object.defineProperty(hidden, 'hidden', { value: true });
    assert.throws(() => normalizeChartBinding(hidden), TypeError);
    const symbol = binding(); symbol[Symbol('hidden')] = 1;
    assert.throws(() => normalizeChartBinding(symbol), TypeError);
});

test('binding accepts only dense own series data without invoking array accessors', () => {
    let calls = 0;
    const accessor = ['value']; Object.defineProperty(accessor, '0', { enumerable: true, get() { calls++; return 'value'; } });
    assert.throws(() => normalizeChartBinding({ ...binding(), series_columns: accessor }), TypeError);
    assert.equal(calls, 0);
    const extra = ['value']; extra.extra = 1;
    assert.throws(() => normalizeChartBinding({ ...binding(), series_columns: extra }), TypeError);
    const sparse = new Array(1);
    const inherited = Object.create(Array.prototype); inherited[0] = 'value'; Object.setPrototypeOf(sparse, inherited);
    assert.throws(() => normalizeChartBinding({ ...binding(), series_columns: sparse }), TypeError);
});

test('projection preserves row identity Unicode labels raw numbers negatives zero and null gaps', () => {
    const source = table();
    source.columns.push({ id: 'second', label: '第二系列' });
    source.rows = [
        { id: 'r1', values: ['😀 A', datasetNumber('-0.0'), datasetNumber('2.50')] },
        { id: 'r2', values: ['😀 A', null, datasetNumber('-3')] },
        { id: 'r3', values: [null, datasetNumber('0.0'), null] },
        { id: 'r4', values: ['', datasetNumber('1e-2'), datasetNumber('1.0')] },
        { id: 'r5', values: [datasetNumber('9007199254740993'), datasetNumber('5'), null] },
    ];
    const result = projectCategoricalChart({ ...binding(), series_columns: ['second', 'value'] }, snapshot(source));
    assert.deepEqual(result.categories, [
        { row_id: 'r1', type: 'text', label: '😀 A' }, { row_id: 'r2', type: 'text', label: '😀 A' },
        { row_id: 'r3', type: 'empty', label: '' }, { row_id: 'r4', type: 'text', label: '' },
        { row_id: 'r5', type: 'number', label: '9007199254740993' },
    ]);
    assert.deepEqual(result.category_column, { id: 'category', label: 'Categoría' });
    assert.deepEqual(result.series, [
        { column_id: 'second', label: '第二系列', values: [{ value: 2.5, raw: '2.50' }, { value: -3, raw: '-3' }, null, { value: 1, raw: '1.0' }, null] },
        { column_id: 'value', label: 'Valor', values: [{ value: -0, raw: '-0.0' }, null, { value: 0, raw: '0.0' }, { value: 0.01, raw: '1e-2' }, { value: 5, raw: '5' }] },
    ]);
    assert.deepEqual(result.dataset, binding().dataset); assert.equal(result.precision, 'safe'); assert.deepEqual(result.warnings, []);
});

test('projection rejects snapshot identity mismatches and incomplete or ambiguous envelopes before reading cells', () => {
    for (const change of [{ id: 8 }, { revision: 4 }, { sha256: 'b'.repeat(64) }, { extra: true }, { rows: [] }]) {
        assert.throws(() => projectCategoricalChart(binding(), { ...snapshot(), ...change }), TypeError);
    }
    const missing = snapshot(); delete missing.revision;
    assert.throws(() => projectCategoricalChart(binding(), missing), TypeError);
    let calls = 0; const bad = { id: 8, revision: 3, sha256: sha };
    Object.defineProperty(bad, 'table', { enumerable: true, get() { calls++; return table(); } });
    assert.throws(() => projectCategoricalChart(binding(), bad), TypeError); assert.equal(calls, 0);
});

test('projection rejects unavailable selected columns with their stable ID before rows', () => {
    assert.throws(() => projectCategoricalChart({ ...binding(), category_column: 'missing_category' }, snapshot()), /missing_category/);
    assert.throws(() => projectCategoricalChart({ ...binding(), series_columns: ['missing_series'] }, snapshot()), /missing_series/);
    const empty = table(); empty.rows = [];
    assert.throws(() => projectCategoricalChart({ ...binding(), series_columns: ['missing_series'] }, snapshot(empty)), /missing_series/);
});

test('projection requires explicit conversion of text series and diagnoses row and column IDs', () => {
    for (const value of ['12', '=SUM(1,2)', '']) {
        const source = table(); source.rows = [{ id: 'row_problem', values: ['Category', value] }];
        assert.throws(() => projectCategoricalChart(binding(), snapshot(source)), error => error instanceof TypeError
            && /fila 1.*row_problem/i.test(error.message) && /columna 2.*value/i.test(error.message) && /convert/i.test(error.message));
    }
});

test('projection validates complete table limits and branded cells without invoking external table getters', () => {
    let calls = 0; const bad = table();
    Object.defineProperty(bad.rows[0], 'values', { enumerable: true, get() { calls++; return ['A', datasetNumber('1')]; } });
    assert.throws(() => projectCategoricalChart(binding(), snapshot(bad)), TypeError); assert.equal(calls, 0);
    for (const data of [
        { ...table(), rows: new Array(1) },
        { ...table(), rows: [{ id: 'r1', values: ['A', { type: 'number', raw: '2' }] }] },
        { ...table(), rows: [{ id: 'r1', values: ['A', datasetNumber('1')] }, { id: 'r1', values: ['B', datasetNumber('2')] }] },
        { ...table(), rows: Array.from({ length: 1001 }, (_, i) => ({ id: `r${i}`, values: ['A', null] })) },
        { columns: Array.from({ length: 101 }, (_, i) => ({ id: `c${i}`, label: '' })), rows: [] },
        { ...table(), extra: true },
        { ...table(), rows: [{ id: 'r1', values: ['A'] }] },
        { ...table(), rows: [{ id: 'r1', values: ['\ud800', null] }] },
    ]) assert.throws(() => projectCategoricalChart(binding(), snapshot(data)), TypeError);
});

test('safe precision rejects exact decimal magnitudes above the safe integer boundary including rounded exponents', () => {
    for (const raw of ['9007199254740992', '9007199254740991.1', '-9007199254740991.0000000001', '9.0071992547409911e15', '900719925474099110e-2']) {
        const source = table(); source.rows[0].values[1] = datasetNumber(raw);
        assert.throws(() => projectCategoricalChart(binding(), snapshot(source)), error => error instanceof TypeError
            && /fila 1.*r1/i.test(error.message) && /columna 2.*value/i.test(error.message) && /precisi/i.test(error.message));
    }
});

test('approximate precision emits exact-magnitude warnings per cell and preserves original lexemes', () => {
    const source = table(); source.rows = [
        { id: 'r1', values: ['A', datasetNumber('9007199254740991.1')] },
        { id: 'r2', values: ['B', datasetNumber('-9.007199254740993e15')] },
        { id: 'r3', values: ['C', datasetNumber('9007199254740991')] },
    ];
    const result = projectCategoricalChart({ ...binding(), precision: 'approximate' }, snapshot(source));
    assert.deepEqual(result.warnings, [
        { code: 'APPROXIMATE_COORDINATE', row_id: 'r1', column_id: 'value', row: 1, column: 2, raw: '9007199254740991.1' },
        { code: 'APPROXIMATE_COORDINATE', row_id: 'r2', column_id: 'value', row: 2, column: 2, raw: '-9.007199254740993e15' },
    ]);
    assert.equal(result.series[0].values[0].raw, '9007199254740991.1');
    assert.equal(result.series[0].values[0].value, Number('9007199254740991.1'));
});

test('projection returns deeply immutable own data detached from externally mutable input', () => {
    const source = table(); source.rows[0].values[1] = datasetNumber('9007199254740993');
    const input = { ...binding(), precision: 'approximate' };
    const result = projectCategoricalChart(input, snapshot(source));
    const assertFrozen = value => { if (value && typeof value === 'object') { assert.ok(Object.isFrozen(value)); for (const child of Object.values(value)) assertFrozen(child); } };
    assertFrozen(result);
    source.columns[0].label = 'changed'; source.rows[0].id = 'changed'; source.rows[0].values[0] = 'changed'; input.dataset.id = 99;
    assert.equal(result.category_column.label, 'Categoría'); assert.equal(result.categories[0].row_id, 'r1');
    assert.equal(result.categories[0].label, 'A'); assert.equal(result.dataset.id, 7);
    assert.throws(() => { result.series[0].values[0].raw = 'mutated'; }, TypeError);
});

// Characterizations of previously implemented criteria, not additional RED claims.
test('characterization safe boundary equality decimal suffixes exponents and enormous zero exponents', () => {
    for (const raw of ['9007199254740991', '-9007199254740991', '9007199254740991.00000', '9.007199254740991e15',
        '90071992547409910e-1', '9007199254740990.999999999999', '0e' + '9'.repeat(12000), '-0e-' + '9'.repeat(12000), '5e-324']) {
        const source = table(); source.rows[0].values[1] = datasetNumber(raw);
        const result = projectCategoricalChart(binding(), snapshot(source));
        assert.equal(result.series[0].values[0].raw, raw); assert.deepEqual(result.warnings, []);
    }
});

test('characterization maximum table and twelve series project 12000 values without modifying source', () => {
    const columns = Array.from({ length: 100 }, (_, i) => ({ id: `c${i}`, label: `Columna ${i}` }));
    const number = datasetNumber('1.0');
    const source = { columns, rows: Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, values: [String(i), ...Array(99).fill(number)] })) };
    const result = projectCategoricalChart({ ...binding(), category_column: 'c0', series_columns: Array.from({ length: 12 }, (_, i) => `c${i + 1}`) }, snapshot(source));
    assert.equal(result.categories.length, 1000); assert.equal(result.series.length, 12);
    assert.equal(result.series.reduce((sum, series) => sum + series.values.length, 0), 12000);
    assert.deepEqual(result.series[11].values[999], { value: 1, raw: '1.0' });
    assert.equal(source.rows[999].values[0], '999'); assert.equal(source.rows[999].values[99], number);
});

test('characterization empty tables duplicate labels and null-prototype records remain distinct', () => {
    const input = Object.assign(Object.create(null), binding());
    input.dataset = Object.assign(Object.create(null), input.dataset);
    const source = table(); source.columns[0].label = source.columns[1].label = 'Same'; source.rows = [];
    const result = projectCategoricalChart(input, snapshot(source));
    assert.deepEqual(result.categories, []); assert.deepEqual(result.series, [{ column_id: 'value', label: 'Same', values: [] }]);
    assert.equal(result.category_column.id, 'category'); assert.equal(result.category_column.label, 'Same');
});

test('underflow requires explicit approximation and distinct warnings while genuine zero and subnormal remain valid', () => {
    for (const raw of ['1e-99999999999999999', '-1e-99999999999999999']) {
        const source = table(); source.rows[0].values[1] = datasetNumber(raw);
        assert.throws(() => projectCategoricalChart(binding(), snapshot(source)), error => error instanceof TypeError
            && /fila 1.*r1/i.test(error.message) && /columna 2.*value/i.test(error.message) && /cero/i.test(error.message));
        const result = projectCategoricalChart({ ...binding(), precision: 'approximate' }, snapshot(source));
        assert.deepEqual(result.warnings, [{ code: 'UNDERFLOW_COORDINATE', row_id: 'r1', column_id: 'value', row: 1, column: 2, raw }]);
        assert.equal(result.series[0].values[0].raw, raw);
        assert.ok(Object.is(result.series[0].values[0].value, raw[0] === '-' ? -0 : 0));
    }
    for (const raw of ['0e' + '9'.repeat(12000), '-0e-' + '9'.repeat(12000), '5e-324', '-5e-324']) {
        const source = table(); source.rows[0].values[1] = datasetNumber(raw);
        const result = projectCategoricalChart(binding(), snapshot(source));
        assert.deepEqual(result.warnings, []); assert.equal(result.series[0].values[0].raw, raw);
    }
});
