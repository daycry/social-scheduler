import test from 'node:test';
import assert from 'node:assert/strict';
import { createBarChartScene } from '../../resources/js/Studio/chart-scene.js';
import { prepareBarChart } from '../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';
import { createChartResources } from '../../resources/js/Studio/chart-resources.js';
import { createHash } from 'node:crypto';

const kinds = ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor'];
function fixture(kind, { negative = false, position = 'outside', labels, values } = {}) {
    const single = !/grouped|stacked|proportional/.test(kind);
    const proportional = kind.endsWith('proportional');
    const rows = values ?? (single ? [['10'], [negative ? '-20' : '20'], [null], ['0']]
        : [['10', '20'], [negative ? '-20' : '20', negative ? '-40' : '40'], [null, null], ['0', '-0.0']]);
    const columns = Array.from({ length: rows[0].length }, (_, i) => `s${i}`);
    const binding = { dataset: { id: 1, revision: 1, sha256: 'a'.repeat(64) }, category_column: 'c', series_columns: columns, precision: 'safe' };
    const snapshot = { ...binding.dataset, table: { columns: [{ id: 'c', label: 'Category' }, ...columns.map(id => ({ id, label: `Series ${id}` }))], rows: rows.map((row, i) => ({ id: `r${i}`, values: [`Año ${i} é`, ...row.map(v => v === null ? null : datasetNumber(String(v)))] })) } };
    let calls = 0;
    const resources = { async prepare(input) { calls++; assert.deepEqual(input, binding); return prepareBarChart(binding, snapshot); } };
    return { chart: { binding, options: { kind, domain_min: proportional ? 0 : -100, domain_max: 100, tick_count: 7 }, style: { title: 'QA 2026 é Ω', subtitle: 'Datos originales', source: 'Fixture propia', x_title: 'Categorías', y_title: 'Valores', fontSize: 18, legend_position: 'right', value_labels: labels ?? (proportional ? 'percentage' : 'raw'), label_position: position } }, resources, calls: () => calls };
}
const overlap = (a, b) => Math.max(0, Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x)) * Math.max(0, Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y));
const valuesOf = scene => scene.primitives.filter(p => p.role === 'value');

test('VL1 every value annotation is painted after all data rectangles', async () => {
    for (const kind of ['bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional']) {
        for (const position of ['inside', 'outside']) {
            const f = fixture(kind, { position }); const scene = await createBarChartScene(f.chart, f.resources);
            const lastBar = Math.max(...scene.primitives.map((p, i) => p.role === 'bar' ? i : -1));
            const firstValue = scene.primitives.findIndex(p => p.role === 'value');
            assert.ok(firstValue > lastBar, `${kind}/${position}: a later segment can cover an earlier value annotation`);
            assert.equal(f.calls(), 1, 'One resource preparation/projection, never another data read');
        }
    }
});

test('VL1 outside slots avoid every data segment legend annotation and other value slot', async () => {
    for (const kind of kinds) {
        for (const negative of kind.endsWith('proportional') ? [false] : [false, true]) {
            const f = fixture(kind, { negative }); const scene = await createBarChartScene(f.chart, f.resources);
            const values = valuesOf(scene);
            const obstacles = scene.primitives.filter(p => ['bar', 'legend', 'legend-swatch', 'title', 'subtitle', 'source', 'x-title', 'y-title', 'category'].includes(p.role));
            for (const value of values) {
                assert.ok(value.width > 0 && value.height > 0, `${kind}/${value.id}: visible value slot required`);
                for (const other of obstacles) assert.ok(overlap(value, other) < 1e-7, `${kind}/${negative}/${value.id} overlaps ${other.id}`);
            }
            for (let i = 0; i < values.length; i++) for (let j = i + 1; j < values.length; j++) {
                assert.ok(overlap(values[i], values[j]) < 1e-7, `${kind}/${negative}: value slots ${values[i].id}/${values[j].id} collide`);
            }
        }
    }
});

test('VL2 percentage display is concise while complete original values percentages and IDs stay intact', async () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        const f = fixture(kind); const scene = await createBarChartScene(f.chart, f.resources);
        const noLabels = await createBarChartScene({ ...f.chart, style: { ...f.chart.style, value_labels: 'none' } }, f.resources);
        assert.deepEqual(scene.table, noLabels.table, 'Display formatting never changes table data');
        assert.deepEqual(valuesOf(scene).map(p => p.id), ['value:r0:s0', 'value:r0:s1', 'value:r1:s0', 'value:r1:s1', 'value:r3:s0', 'value:r3:s1']);
        assert.deepEqual(valuesOf(scene).map(p => p.text), ['33.3%', '66.7%', '33.3%', '66.7%', '0%', '0%']);
        for (const value of valuesOf(scene)) {
            const [row, column] = [value.row_id, value.column_id];
            const original = scene.table.rows.find(r => r.row_id === row).values.find(v => v.column_id === column);
            assert.equal(value.raw, original.raw); assert.equal(value.percentage, original.percentage);
        }
    }
});

test('VL2 ordinary percentage slots have nominal readable capacity separate from the legend', async () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        const f = fixture(kind); const scene = await createBarChartScene(f.chart, f.resources);
        for (const value of valuesOf(scene)) {
            // Pure necessary layout capacity; native font/ink acceptance remains a separate root gate.
            assert.ok(value.height >= 21.6, `${kind}: value slot height cannot fit an18px face`);
            assert.ok(value.width >= value.text.length * 18 * 0.6, `${kind}: percentage text unnecessarily compressed`);
        }
        const legend = scene.primitives.filter(p => p.role === 'legend');
        for (const value of valuesOf(scene)) for (const entry of legend) assert.equal(overlap(value, entry), 0);
    }
});

test('raw lexemes zero null signs and inside value identity are never formatted away', async () => {
    for (const kind of kinds.filter(k => !k.endsWith('proportional'))) {
        const single = !/grouped|stacked/.test(kind);
        const f = fixture(kind, { position: 'inside', values: single ? [['5.00'], ['-2'], ['-0.0'], [null]] : [['5.00', '3'], ['-2', '-4'], ['-0.0', '0'], [null, null]] });
        const scene = await createBarChartScene(f.chart, f.resources);
        assert.deepEqual(valuesOf(scene).map(p => p.text), single ? ['5.00', '-2', '-0.0'] : ['5.00', '3', '-2', '-4', '-0.0', '0']);
        assert.equal(scene.primitives.filter(p => p.role === 'bar').length, valuesOf(scene).length);
        for (const p of valuesOf(scene)) assert.equal(p.text, p.raw);
    }
});

test('percentage display never rounds a finite nonzero percentage to zero', async () => {
    for (const kind of ['bar-proportional', 'column-proportional']) {
        const f = fixture(kind, { values: [['1e-100', '1'], ['1', '1'], ['0', '0'], [null, null]] });
        const scene = await createBarChartScene(f.chart, f.resources);
        const value = valuesOf(scene).find(p => p.id === 'value:r0:s0');
        assert.notEqual(Number(value.text.slice(0, -1)), 0);
        assert.ok(Number.isFinite(Number(value.text.slice(0, -1))));
        assert.equal(value.raw, '1e-100');
    }
});

test('inside zero labels retain visible nominal slots anchored at zero with an honest warning', async () => {
    for (const kind of ['bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional']) {
        const f = fixture(kind, { position: 'inside' }); const scene = await createBarChartScene(f.chart, f.resources);
        const zeros = valuesOf(scene).filter(p => p.row_id === 'r3');
        assert.equal(zeros.length, 2);
        assert.ok(zeros.every(p => p.width > 0 && p.height > 0), `${kind}: zero text is scaled to zero`);
        assert.equal(overlap(zeros[0], zeros[1]), 0, `${kind}: zero labels need distinct anchors`);
        assert.ok(scene.warnings.some(w => w.code === 'ZERO_VALUE_LABEL_ANCHORED'));
        assert.equal(f.calls(), 1);
    }
});

test('reserved layout reuses one genuine registry fetch parse and projection through style changes', async () => {
    const table = '{"columns":[{"id":"c","label":"Category"},{"id":"s0","label":"First"},{"id":"s1","label":"Second"}],"rows":[{"id":"r0","values":["A",10,20]},{"id":"r1","values":["B",0,0]}]}';
    const bytes = new TextEncoder().encode(table), sha256 = createHash('sha256').update(bytes).digest('hex');
    const f = fixture('column-stacked'); f.chart.binding.dataset.sha256 = sha256;
    const oldLocation = globalThis.location; globalThis.location = new URL('https://value-labels.test/editor');
    let reads = 0, bodyReads = 0;
    const registry = createChartResources({ brandId: 7, fetchImpl: async () => {
        reads++;
        return { status: 200, url: 'https://value-labels.test/dataset', headers: new Headers({ 'content-type': 'application/json', 'content-length': String(bytes.length) }),
            body: new ReadableStream({ start(controller) { bodyReads++; controller.enqueue(bytes); controller.close(); } }) };
    } });
    try {
        await createBarChartScene(f.chart, registry);
        const first = registry.stats();
        assert.equal(reads, 1); assert.equal(bodyReads, 1); assert.equal(first.resources, 1); assert.equal(first.projections, 1);
        await createBarChartScene({ ...f.chart, style: { ...f.chart.style, label_position: 'inside' } }, registry);
        await createBarChartScene({ ...f.chart, style: { ...f.chart.style, value_labels: 'none' } }, registry);
        assert.equal(reads, 1); assert.equal(bodyReads, 1); assert.deepEqual(registry.stats(), first);
    } finally { registry.dispose(); if (oldLocation === undefined) delete globalThis.location; else globalThis.location = oldLocation; }
});

test('dense and small reserved scenes preserve finite contained objects and minimum plot dimensions', async () => {
    for (const kind of kinds) for (const fontSize of [8, 18, 72]) for (const size of [160, 161, 200]) {
        const f = fixture(kind, { negative: !kind.endsWith('proportional') }); f.chart.style.fontSize = fontSize;
        const scene = await createBarChartScene(f.chart, f.resources, { width: size, height: size });
        assert.ok(scene.plot.width >= 40 && scene.plot.height >= 40, `${kind}/${fontSize}/${size}`);
        for (const p of scene.primitives) {
            assert.ok([p.x, p.y, p.width, p.height].every(n => Number.isFinite(n) && n >= 0));
            assert.ok(p.x + p.width <= size + 1e-9 && p.y + p.height <= size + 1e-9, `${kind}/${p.id}`);
        }
    }
});

for (const c of [
    { name: 'horizontal signed zero', kind: 'bar-stacked', fontSize: 18, width: 244, height: 244, zero: true },
    { name: 'horizontal signed font8', kind: 'bar-stacked', fontSize: 8, width: 238, height: 500 },
    { name: 'horizontal signed font18', kind: 'bar-stacked', fontSize: 18, width: 244, height: 500 },
    { name: 'vertical signed font18', kind: 'column-stacked', fontSize: 18, width: 500, height: 210 },
]) test(`outside reservation preserves legal minimum plot: ${c.name}`, async () => {
    const f = fixture(c.kind, { negative: true, ...(c.zero ? { values: [['0', '-0.0']] } : {}) });
    if (c.zero) {
        f.chart.options = { kind: c.kind };
        f.chart.style = { fontSize: c.fontSize, value_labels: 'raw', y_title: 'Y', legend_position: 'right' };
    } else f.chart.style.fontSize = c.fontSize;
    const dimensions = { width: c.width, height: c.height };
    const original = await createBarChartScene({ ...f.chart, style: { ...f.chart.style, value_labels: 'none' } }, f.resources, dimensions);
    const callsBefore = f.calls();
    const scene = await createBarChartScene(f.chart, f.resources, dimensions);
    assert.equal(f.calls() - callsBefore, 1, 'One prepared resource for the reserved scene');
    assert.ok(scene.plot.width >= 40 && scene.plot.height >= 40, 'Legal layers retain the exact geometry minimum');
    assert.deepEqual(scene.table, original.table, 'Reservations cannot alter source lexemes, zeros, nulls or percentages');
    const semanticBars = s => s.primitives.filter(p => p.role === 'bar').map(({ id, row_id, column_id, value, raw, percentage, fill }) => ({ id, row_id, column_id, value, raw, percentage, fill }));
    assert.deepEqual(semanticBars(scene), semanticBars(original));
    const bars = semanticBars(scene), values = valuesOf(scene);
    assert.equal(bars.length, c.zero ? 2 : 6, 'No source bar is omitted');
    assert.deepEqual(values.map(p => [p.row_id, p.column_id, p.text, p.raw, p.percentage]), bars.map(p => [p.row_id, p.column_id, p.raw, p.raw, p.percentage]));
    assert.deepEqual(values.filter(p => p.row_id === (c.zero ? 'r0' : 'r3')).map(p => p.raw), ['0', '-0.0']);
    for (const p of scene.primitives) {
        assert.ok([p.x, p.y, p.width, p.height].every(n => Number.isFinite(n) && n >= 0));
        assert.ok(p.x + p.width <= c.width + 1e-9 && p.y + p.height <= c.height + 1e-9, p.id);
    }
});
