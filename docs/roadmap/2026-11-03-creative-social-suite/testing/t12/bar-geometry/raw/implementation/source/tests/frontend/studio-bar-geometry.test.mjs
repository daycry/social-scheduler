import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeBarChartOptions, createBarChartGeometry } from '../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';

const sha = 'a'.repeat(64);
const data = (rows, count = rows[0]?.length ?? 1, precision = 'safe') => ({
    binding: { dataset: { id: 8, revision: 3, sha256: sha }, category_column: 'category', series_columns: Array.from({ length: count }, (_, i) => `s${i}`), precision },
    snapshot: { id: 8, revision: 3, sha256: sha, table: {
        columns: [{ id: 'category', label: 'Categoría' }, ...Array.from({ length: count }, (_, i) => ({ id: `s${i}`, label: `Serie ${i}` }))],
        rows: rows.map((values, i) => ({ id: `r${i}`, values: [`Categoría ${i}`, ...values.map(value => value === null ? null : datasetNumber(String(value)))] })),
    } },
});
const geometry = (source, options) => createBarChartGeometry(source.binding, source.snapshot, options);
const near = (actual, expected) => assert.ok(Math.abs(actual - expected) <= Math.max(1, Math.abs(expected)) * 1e-12, `${actual} != ${expected}`);

test('options supplies closed immutable defaults detached from caller palette', () => {
    const defaults = normalizeBarChartOptions();
    assert.equal(defaults.kind, 'column'); assert.equal(defaults.width, 640); assert.equal(defaults.height, 360);
    assert.equal(defaults.band_gap, 0.2); assert.equal(defaults.series_gap, 0.1); assert.equal(defaults.opacity, 1);
    assert.equal(defaults.domain_min, null); assert.equal(defaults.domain_max, null); assert.equal(defaults.tick_count, 5);
    assert.ok(defaults.palette.length > 0 && Object.isFrozen(defaults) && Object.isFrozen(defaults.palette));
    const palette = ['#123', '#aabbcc']; const result = normalizeBarChartOptions({ palette });
    palette[0] = '#fff'; assert.equal(result.palette[0], '#123');
});

test('options rejects unknown kinds fields and ambiguous numeric or palette values', () => {
    const invalid = [null, [], 1, { extra: true }, { kind: 'line' }, { kind: undefined },
        ...['width', 'height'].flatMap(key => [39, 8001, Infinity, NaN, '100', null].map(value => ({ [key]: value }))),
        ...['band_gap', 'series_gap'].flatMap(key => [-0.1, 0.91, NaN, '0.2', null].map(value => ({ [key]: value }))),
        ...[-1, 1.1, Infinity, '1', null].map(opacity => ({ opacity })),
        ...[1, 22, 2.5, '5', null].map(tick_count => ({ tick_count })),
        ...['domain_min', 'domain_max'].flatMap(key => [Infinity, NaN, '0', undefined].map(value => ({ [key]: value }))),
        ...[[], Array(25).fill('#fff'), ['red'], ['url(x)'], ['#12'], [123], null, 'red'].map(palette => ({ palette })),
    ];
    for (const value of invalid) assert.throws(() => normalizeBarChartOptions(value), TypeError, JSON.stringify(value));
    for (const kind of ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor']) assert.equal(normalizeBarChartOptions({ kind }).kind, kind);
    assert.deepEqual(normalizeBarChartOptions({ palette: ['#123', '#1234', '#123456', '#12345678'], opacity: 0 }).palette, ['#123', '#1234', '#123456', '#12345678']);
});

test('options rejects accessors without invoking them and rejects inherited hidden or symbol fields', () => {
    let calls = 0; const source = {};
    Object.defineProperty(source, 'width', { enumerable: true, get() { calls++; return 640; } });
    let rejected = false; try { normalizeBarChartOptions(source); } catch (error) { rejected = error instanceof TypeError; }
    assert.equal(calls, 0); assert.equal(rejected, true);
    assert.throws(() => normalizeBarChartOptions(Object.create({ width: 640 })), TypeError);
    const hidden = {}; Object.defineProperty(hidden, 'width', { value: 640 });
    assert.throws(() => normalizeBarChartOptions(hidden), TypeError);
    assert.throws(() => normalizeBarChartOptions({ [Symbol('extra')]: true }), TypeError);
    assert.equal(normalizeBarChartOptions(Object.assign(Object.create(null), { width: 500 })).width, 500);
});

test('palette requires dense own data and never invokes array getters', () => {
    let calls = 0; const accessor = ['#fff']; Object.defineProperty(accessor, '0', { enumerable: true, get() { calls++; return '#fff'; } });
    let rejected = false; try { normalizeBarChartOptions({ palette: accessor }); } catch { rejected = true; }
    assert.equal(calls, 0); assert.equal(rejected, true);
    const sparse = new Array(1), prototype = Object.create(Array.prototype); prototype[0] = '#fff'; Object.setPrototypeOf(sparse, prototype);
    const extra = ['#fff']; extra.extra = 'not allowed';
    for (const palette of [new Array(1), sparse, extra]) assert.throws(() => normalizeBarChartOptions({ palette }), TypeError);
});

test('columns preserve row and number identity with negatives zero and null gaps', () => {
    const result = geometry(data([['5.0'], ['-5'], ['0.0'], [null]]), { width: 400, height: 100 });
    assert.equal(result.kind, 'column'); assert.equal(result.orientation, 'vertical');
    assert.deepEqual(result.dataset, { id: 8, revision: 3, sha256: sha });
    assert.deepEqual(result.domain, { min: -5, max: 5 }); assert.equal(result.baseline, 50);
    assert.deepEqual(result.categories.map(c => [c.row_id, c.type, c.label, c.center, c.band_start, c.band_end]), [
        ['r0', 'text', 'Categoría 0', 50, 0, 100], ['r1', 'text', 'Categoría 1', 150, 100, 200],
        ['r2', 'text', 'Categoría 2', 250, 200, 300], ['r3', 'text', 'Categoría 3', 350, 300, 400],
    ]);
    assert.deepEqual(result.series, [{ column_id: 's0', label: 'Serie 0' }]);
    assert.equal(result.bars.length, 3);
    assert.deepEqual(result.bars.map(b => [b.key, b.row_id, b.column_id, b.x, b.y, b.width, b.height, b.value, b.raw, b.start, b.end, b.percentage]), [
        ['r0:s0', 'r0', 's0', 10, 0, 80, 50, 5, '5.0', 0, 5, null],
        ['r1:s0', 'r1', 's0', 110, 50, 80, 50, -5, '-5', 0, -5, null],
        ['r2:s0', 'r2', 's0', 210, 50, 80, 0, 0, '0.0', 0, 0, null],
    ]);
});

test('simple and multicolor variants use both orientations and deterministic category colors', () => {
    for (const kind of ['bar', 'column', 'bar-multicolor', 'column-multicolor']) {
        const result = geometry(data([[2], [-4]]), { kind, width: 120, height: 120, palette: ['#123', '#abc'], opacity: 0.4 });
        const horizontal = kind.startsWith('bar'); assert.equal(result.orientation, horizontal ? 'horizontal' : 'vertical');
        assert.deepEqual(result.bars.map(b => b.fill), kind.endsWith('multicolor') ? ['#123', '#abc'] : ['#123', '#123']);
        assert.ok(result.bars.every(b => b.opacity === 0.4));
        assert.equal(result.baseline, horizontal ? 80 : 40);
        assert.deepEqual(result.bars.map(b => [b.x, b.y, b.width, b.height]), horizontal ? [[80, 6, 40, 48], [0, 66, 80, 48]] : [[6, 0, 48, 40], [66, 40, 48, 80]]);
    }
});

test('simple and multicolor reject multiple series rather than ignoring selected data', () => {
    for (const kind of ['bar', 'column', 'bar-multicolor', 'column-multicolor']) assert.throws(() => geometry(data([[1, 2]]), { kind }), /serie/i);
});

test('grouped variants reserve null slots and use series colors without shifting observations', () => {
    for (const kind of ['column-grouped', 'bar-grouped']) {
        const result = geometry(data([[4, null, -2], [null, 2, 1]]), { kind, width: 200, height: 120, band_gap: 0.2, series_gap: 0.25, palette: ['#123', '#456', '#789'] });
        assert.deepEqual(result.bars.map(b => [b.row_id, b.column_id, b.fill]), [['r0', 's0', '#123'], ['r0', 's2', '#789'], ['r1', 's1', '#456'], ['r1', 's2', '#789']]);
        assert.deepEqual(result.domain, { min: -2, max: 4 });
        const horizontal = kind.startsWith('bar'); const band = (horizontal ? 120 : 200) / 2;
        const slot = band * 0.8 / 3, thickness = slot * 0.75;
        near(horizontal ? result.bars[0].y : result.bars[0].x, band * 0.1 + slot * 0.125);
        near(horizontal ? result.bars[1].y : result.bars[1].x, band * 0.1 + slot * 2.125);
        near(horizontal ? result.bars[0].height : result.bars[0].width, thickness);
        assert.equal(result.bars[0].start, 0); assert.equal(result.bars[0].end, 4);
        assert.equal(result.bars[1].start, 0); assert.equal(result.bars[1].end, -2);
    }
});

test('stacked variants accumulate positive and negative series independently for each category', () => {
    for (const kind of ['bar-stacked', 'column-stacked']) {
        const result = geometry(data([[2, -3, 4, -5], [1, -1, null, 0]]), { kind, width: 140, height: 140 });
        assert.deepEqual(result.domain, { min: -8, max: 6 });
        assert.deepEqual(result.bars.map(b => [b.row_id, b.column_id, b.start, b.end]), [
            ['r0', 's0', 0, 2], ['r0', 's1', 0, -3], ['r0', 's2', 2, 6], ['r0', 's3', -3, -8],
            ['r1', 's0', 0, 1], ['r1', 's1', 0, -1], ['r1', 's3', 1, 1],
        ]);
        assert.equal(result.baseline, kind.startsWith('bar') ? 80 : 60);
        assert.ok(result.bars.every(b => b.width >= 0 && b.height >= 0));
    }
});

test('proportional variants scale huge finite totals and distinguish numeric zeros from missing categories', () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        const result = geometry(data([['1e308', '1e308'], [0, 0], [null, null], [1, 3]], 2, 'approximate'), { kind, width: 400, height: 400 });
        assert.deepEqual(result.domain, { min: 0, max: 100 });
        assert.deepEqual(result.bars.map(b => [b.row_id, b.column_id, b.start, b.end, b.percentage]), [
            ['r0', 's0', 0, 50, 50], ['r0', 's1', 50, 100, 50],
            ['r1', 's0', 0, 0, 0], ['r1', 's1', 0, 0, 0],
            ['r3', 's0', 0, 25, 25], ['r3', 's1', 25, 100, 75],
        ]);
        assert.equal(result.bars[0].value, 1e308); assert.equal(result.bars[0].raw, '1e308');
        assert.deepEqual(result.warnings.filter(w => w.code === 'ZERO_TOTAL'), [{ code: 'ZERO_TOTAL', row_id: 'r1' }]);
        assert.ok(result.bars.every(b => [b.x, b.y, b.width, b.height].every(Number.isFinite)));
    }
});

test('proportional variants reject negative data and incompatible explicit domains', () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        assert.throws(() => geometry(data([[1, -2]]), { kind }), error => /r0/.test(error.message) && /s1/.test(error.message));
        for (const domain of [{ domain_min: -1 }, { domain_max: 50 }, { domain_min: 1 }, { domain_max: 101 }]) assert.throws(() => geometry(data([[1, 2]]), { kind, ...domain }), /dominio/i);
        assert.deepEqual(geometry(data([[1, 2]]), { kind, domain_min: 0, domain_max: 100 }).domain, { min: 0, max: 100 });
    }
});

test('explicit domains contain zero and represented extrema while empty zero domains stay nondegenerate', () => {
    assert.deepEqual(geometry(data([[-2], [4]]), { domain_min: -10, domain_max: 20 }).domain, { min: -10, max: 20 });
    for (const limits of [{ domain_min: -1 }, { domain_max: 3 }, { domain_min: 1, domain_max: 10 }, { domain_min: -10, domain_max: -1 }, { domain_min: 0, domain_max: 0 }]) assert.throws(() => geometry(data([[-2], [4]]), limits), /dominio/i);
    for (const source of [data([], 1), data([[0], [null]])]) {
        assert.deepEqual(geometry(source).domain, { min: 0, max: 1 });
        assert.deepEqual(geometry(source, { domain_max: 0 }).domain, { min: -1, max: 0 });
        assert.deepEqual(geometry(source, { domain_min: 0 }).domain, { min: 0, max: 1 });
        assert.ok(Number.isFinite(geometry(source).baseline));
    }
    assert.throws(() => geometry(data([[3, 3]]), { kind: 'column-stacked', domain_max: 5 }), /dominio/i);
});

test('coordinate interpolation stays finite for opposite extreme endpoints and diagnoses stacked overflow', () => {
    for (const kind of ['bar', 'column']) {
        const result = geometry(data([['-1e308'], ['1e308']], 1, 'approximate'), { kind, width: 200, height: 100 });
        assert.deepEqual(result.domain, { min: -1e308, max: 1e308 });
        near(result.baseline, kind === 'bar' ? 100 : 50);
        assert.ok(result.bars.every(b => [b.x, b.y, b.width, b.height].every(Number.isFinite)));
        assert.equal(kind === 'bar' ? result.bars[0].width : result.bars[0].height, kind === 'bar' ? 100 : 50);
    }
    for (const raw of ['1e308', '-1e308']) assert.throws(() => geometry(data([[raw, raw]], 2, 'approximate'), { kind: 'column-stacked' }), error => /r0/.test(error.message) && /s1/.test(error.message) && /finit/i.test(error.message));
});

test('ticks are unique sorted finite and include both endpoints and zero even at subnormal precision', () => {
    for (const source of [data([[-3], [7]]), data([['-1e308'], ['1e308']], 1, 'approximate'), data([['5e-324']])]) {
        for (const kind of ['bar', 'column']) {
            const result = geometry(source, { kind, tick_count: 5 });
            const values = result.ticks.map(t => t.value);
            assert.ok(values.length >= 2 && values.length <= 6);
            assert.equal(values[0], result.domain.min); assert.equal(values.at(-1), result.domain.max);
            assert.ok(values.includes(0)); assert.equal(new Set(values).size, values.length);
            assert.deepEqual(values, [...values].sort((a, b) => a - b));
            for (const tick of result.ticks) assert.ok(Number.isFinite(tick.value) && Number.isFinite(tick.position) && tick.position >= 0 && tick.position <= (kind === 'bar' ? result.width : result.height));
        }
    }
    assert.deepEqual(geometry(data([['5e-324']])).ticks.map(t => t.value), [0, 5e-324]);
});

test('geometry preserves projection warnings and identifies nonzero values disappearing at the chosen scale', () => {
    const result = geometry(data([['1e-300'], ['1e308']], 1, 'approximate'), { height: 100 });
    assert.equal(result.bars[0].value, 1e-300); assert.equal(result.bars[0].raw, '1e-300'); assert.equal(result.bars[0].height, 0);
    assert.ok(result.warnings.some(w => w.code === 'ZERO_SIZE_BAR' && w.row_id === 'r0' && w.column_id === 's0'));
    assert.ok(result.warnings.some(w => w.code === 'APPROXIMATE_COORDINATE' && w.row_id === 'r1' && w.column_id === 's0' && w.raw === '1e308'));
    const zero = geometry(data([['-0.0']]));
    assert.ok(Object.is(zero.bars[0].value, -0)); assert.equal(zero.bars[0].raw, '-0.0');
    assert.ok(!zero.warnings.some(w => w.code === 'ZERO_SIZE_BAR'));
});

test('geometry is deeply immutable and detached from mutable input while preserving Unicode labels', () => {
    const input = data([[3, 5]]); input.snapshot.table.rows[0].values[0] = '😀 東京';
    input.snapshot.table.columns[1].label = 'Été'; const palette = ['#123', '#456'];
    const result = geometry(input, { kind: 'bar-grouped', palette });
    const frozen = value => { if (value && typeof value === 'object') { assert.ok(Object.isFrozen(value)); for (const child of Object.values(value)) frozen(child); } };
    frozen(result);
    input.binding.dataset.id = 100; input.snapshot.table.rows[0].values[0] = 'changed'; input.snapshot.table.columns[1].label = 'changed'; palette[0] = '#fff';
    assert.equal(result.dataset.id, 8); assert.equal(result.categories[0].label, '😀 東京'); assert.equal(result.series[0].label, 'Été'); assert.equal(result.bars[0].fill, '#123');
    assert.throws(() => { result.bars[0].x = 999; }, TypeError);
    assert.throws(() => { result.ticks.push({ value: 0, position: 0 }); }, TypeError);
});

// Characterizations are labelled after observing their first execution, never retroactively called RED.
test('characterization maximum table projects twelve thousand unique bars without mutating the source', () => {
    const input = data(Array.from({ length: 1000 }, (_, row) => Array.from({ length: 12 }, (_, column) => (row + column) % 7 - 3)));
    const result = geometry(input, { kind: 'column-grouped' });
    assert.equal(result.bars.length, 12000); assert.equal(new Set(result.bars.map(b => b.key)).size, 12000);
    assert.equal(result.categories.length, 1000); assert.equal(result.series.length, 12);
    assert.equal(input.snapshot.table.rows[0].values[1].raw, '-3');
    for (const bar of result.bars) assert.ok(bar.x >= 0 && bar.y >= 0 && bar.width >= 0 && bar.height >= 0 && bar.x + bar.width <= result.width && bar.y + bar.height <= result.height);
});

test('characterization binding validation still rejects stale snapshots and text series before geometry', () => {
    const input = data([[1]]);
    assert.throws(() => createBarChartGeometry(input.binding, { ...input.snapshot, revision: 4 }), /revisi/i);
    input.snapshot.table.rows[0].values[1] = '12';
    assert.throws(() => geometry(input), /convert/i);
});

test('characterization bars stay strictly inside fractional plot bounds with zero gaps in every variant', () => {
    for (const kind of ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor']) {
        const count = /-(grouped|stacked|proportional)$/.test(kind) ? 3 : 1;
        for (const rows of [7, 11, 37]) for (const size of [40.1, 123.45, 8000]) {
            const result = geometry(data(Array.from({ length: rows }, () => Array(count).fill(1))), { kind, width: size, height: size, band_gap: 0, series_gap: 0 });
            for (const b of result.bars) assert.ok(b.x >= 0 && b.y >= 0 && b.width >= 0 && b.height >= 0 && b.x + b.width <= size && b.y + b.height <= size, JSON.stringify({ kind, rows, size, b }));
        }
    }
});

test('proportional underflow never invents a zero total or percentage from a lost nonzero value', () => {
    for (const kind of ['bar-proportional', 'column-proportional']) assert.throws(() => geometry(data([['1e-999', 1]], 2, 'approximate'), { kind }), error => /r0/.test(error.message) && /s0/.test(error.message) && /redonde|underflow|representable/i.test(error.message));
    const ordinary = geometry(data([['1e-999']], 1, 'approximate'));
    assert.equal(ordinary.bars[0].value, 0); assert.equal(ordinary.bars[0].raw, '1e-999');
    assert.ok(ordinary.warnings.some(w => w.code === 'UNDERFLOW_COORDINATE'));
});

test('proportional negative eligibility uses the original sign while genuine negative zero remains zero', () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        assert.throws(() => geometry(data([['-1e-999', 1]], 2, 'approximate'), { kind }), error => /r0/.test(error.message) && /s0/.test(error.message) && /negativ/i.test(error.message));
        const result = geometry(data([['-0.0', 1], ['-0e-999', 0]], 2, 'approximate'), { kind });
        assert.equal(result.bars[0].raw, '-0.0'); assert.equal(result.bars[0].percentage, 0);
        assert.ok(Object.is(result.bars[0].value, -0));
        assert.deepEqual(result.warnings.filter(w => w.code === 'ZERO_TOTAL'), [{ code: 'ZERO_TOTAL', row_id: 'r1' }]);
    }
});

test('fractional grouped edges do not escape the plot by floating point roundoff', () => {
    for (const kind of ['bar-grouped', 'column-grouped']) {
        const result = geometry(data([Array(6).fill(1)]), { kind, width: 40.01, height: 40.01, band_gap: 0, series_gap: 0 });
        assert.ok(result.bars.every(b => b.x + b.width <= result.width && b.y + b.height <= result.height));
        const last = result.bars.at(-1); assert.equal(kind === 'bar-grouped' ? last.y + last.height : last.x + last.width, 40.01);
    }
});
