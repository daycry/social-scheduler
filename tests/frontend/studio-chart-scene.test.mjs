import test from 'node:test';
import assert from 'node:assert/strict';
import { createBarChartScene, assertChartDocumentBudget } from '../../resources/js/Studio/chart-scene.js';
import { prepareBarChart, createPreparedBarChartGeometry } from '../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';
import { createChartResources } from '../../resources/js/Studio/chart-resources.js';
import { createHash } from 'node:crypto';
import { registerHooks } from 'node:module';

const sha = 'a'.repeat(64);
function fixture(kind = 'column', values = [['5.00'], ['-2'], ['0'], [null]], style = {}) {
    const count = values[0].length;
    const binding = { dataset: { id: 1, revision: 2, sha256: sha }, category_column: 'c', series_columns: Array.from({ length: count }, (_, i) => `s${i}`) };
    const snapshot = { ...binding.dataset, table: {
        columns: [{ id: 'c', label: 'Category' }, ...binding.series_columns.map(id => ({ id, label: `Series ${id}` }))],
        rows: values.map((row, i) => ({ id: `r${i}`, values: [`Categoría larga ${i}`, ...row.map(v => v === null ? null : datasetNumber(String(v)))] })),
    } };
    let calls = 0;
    const resources = { async prepare(input) { calls++; assert.deepEqual(input.dataset, binding.dataset); return prepareBarChart(binding, snapshot); } };
    return { chart: { binding, options: { kind }, style }, resources, calls: () => calls };
}
test('scene retains raw zero null and identity in its complete accessible table', async () => {
    const f = fixture(); const scene = await createBarChartScene(f.chart, f.resources);
    assert.equal(f.calls(), 1);
    assert.deepEqual(scene.table.rows.map(r => r.values[0].raw), ['5.00', '-2', '0', null]);
    assert.deepEqual(scene.table.rows.map(r => r.row_id), ['r0', 'r1', 'r2', 'r3']);
    assert.equal(scene.primitives.filter(p => p.role === 'bar').length, 3);
    assert.ok(Object.isFrozen(scene) && Object.isFrozen(scene.table.rows[0].values));
});
test('all ten variants preserve bars and multicolor legend represents categories', async () => {
    for (const kind of ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor']) {
        const single = !/grouped|stacked|proportional/.test(kind);
        const f = fixture(kind, single ? [[1], [0], [null]] : [[1, 3], [0, 0], [null, 2]], { value_labels: kind.endsWith('proportional') ? 'percentage' : 'raw' });
        const scene = await createBarChartScene(f.chart, f.resources);
        assert.equal(scene.primitives.filter(p => p.role === 'bar').length, single ? 2 : 5, kind);
        assert.equal(scene.legend.length, kind.endsWith('multicolor') ? 3 : single ? 1 : 2);
        assert.equal(scene.table.rows[0].values[0].percentage, kind.endsWith('proportional') ? 25 : null);
        assert.equal(scene.primitives.filter(p => p.role === 'value').length, single ? 2 : 5);
    }
});

test('vertical y-title retains rotated placement against the reserved value-label plot in ten variants', async () => {
    // VL1/VL2 intentionally reserve annotation gutters and reflow values. The
    // pre-layout goldens and exact deltas remain in t12-chart-value-labels1.
    // Full none scenes and original tables/bar semantics are checked separately.
    const baseline = {
        bar: '0078ce98e72eb6d03ce27b72f4d4dfe41f405a9686841cffdeb197cf8754491a',
        column: '30c536cbbdf9886495ce518604abda187fa90f4118e61aa2e36a42c689b70cbb',
        'bar-grouped': '79f795bc64708f156be8661972320a3d754120b9c9089eaacfee09a40fe09ec5',
        'column-grouped': '3a5c13c459f26a92997f989f9ad7d2bce70493e9e3c09fbfa3637d537ded1734',
        'bar-stacked': '61bddcb03486703d523f73198522b6638efe4ed39fca0278f1734a6c2470efb2',
        'column-stacked': '0c34139b3e3a12e25b57af05380f726f64af441f25bb8af4abb9e213ee9ae7bb',
        'bar-proportional': '3efe7f98bd4ebc3772f69afde0030c44574a6f270b6dfa361b6e33b41c8bf34c',
        'column-proportional': 'a8233498e2cc03d6242c578547480248c6b63441f3485053dd9e6ebda21ecad2',
        'bar-multicolor': '98ba951fe2758b1dfd0c335ca5c44dd811ad1d5b9adba1978e0482913eb86101',
        'column-multicolor': '3698d943a443bfc5882f1b492c4641e8960e4c136be150382f047f0f0b53d871',
    };
    for (const kind of Object.keys(baseline)) {
        const single = !/grouped|stacked|proportional/.test(kind), proportional = kind.endsWith('proportional');
        const values = single ? [['5.00'], ['-2'], ['-0.0'], [null]] : proportional
            ? [['5.00', '3'], ['2', '4'], ['-0.0', '0'], [null, '2']]
            : [['5.00', '3'], ['-2', '-4'], ['-0.0', '0'], [null, '2']];
        const f = fixture(kind, values, { fontSize: 18, title: 'QA 2026 é Ω', subtitle: 'Datos originales', source: 'Fixture propia', x_title: 'Categorías', y_title: 'Valores', legend_position: 'right', value_labels: proportional ? 'percentage' : 'raw' });
        f.chart.options = { kind, domain_min: proportional ? 0 : -100, domain_max: 100, tick_count: 7 };
        const scene = await createBarChartScene(f.chart, f.resources);
        const preserved = { width: scene.width, height: scene.height, plot: scene.plot, primitives: scene.primitives.filter(p => p.role !== 'y-title'), table: scene.table, legend: scene.legend };
        assert.equal(createHash('sha256').update(JSON.stringify(preserved)).digest('hex'), baseline[kind], kind);
        const title = scene.primitives.find(p => p.role === 'y-title');
        assert.equal(title.rotation, -90, `${kind}: vertical y-title orientation`);
        assert.deepEqual([title.x, title.y, title.width, title.height], [12.5, scene.plot.y, 29.759999999999998, scene.plot.height]);
        assert.ok(title.x >= 0 && title.x + title.width < scene.plot.x);
        assert.ok(title.y >= 66.5 && title.y + title.height <= 375.3 + 1e-9);
        assert.ok(title.height >= 100, 'Ordinary value annotations must not erase the vertical title capacity');
        assert.equal(title.text, 'Valores');
        assert.equal(scene.primitives.find(p => p.role === 'x-title').text, 'Categorías');
    }
});

test('vertical y-title fitting warning uses rotated capacity and retains genuine long-title warning', async () => {
    const f = fixture('column', [['1']], { fontSize: 18, y_title: 'Valores', show_axis: false, category_labels: false, legend: false, value_labels: 'none' });
    const scene = await createBarChartScene(f.chart, f.resources);
    assert.ok(!scene.warnings.some(w => w.code === 'TEXT_FITTED'), 'Readable vertical title must not warn because of horizontal width');
    f.chart.style.y_title = 'Valores '.repeat(30);
    const long = await createBarChartScene(f.chart, f.resources);
    assert.ok(long.warnings.some(w => w.code === 'TEXT_FITTED'), 'Long title still needs uniform fitting');
});

test('axis ticks use readable decimals in both orientations without changing data or positions', async () => {
    for (const kind of ['bar', 'column']) {
        const f = fixture(kind, [['5.00'], ['-2'], ['-0.0'], [null]], { fontSize: 18, value_labels: 'raw' });
        f.chart.options = { kind, domain_min: -100, domain_max: 100, tick_count: 7 };
        const scene = await createBarChartScene(f.chart, f.resources);
        const ticks = scene.primitives.filter(p => p.role === 'tick');
        assert.deepEqual(ticks.map(p => p.text), ['-100', '-66.7', '-33.3', '0', '33.3', '66.7', '100']);
        assert.deepEqual(scene.table.rows.map(r => r.values[0].raw), ['5.00', '-2', '-0.0', null]);
        assert.deepEqual(scene.primitives.filter(p => p.role === 'value').map(p => p.text), ['5.00', '-2', '-0.0']);
        const grid = scene.primitives.filter(p => p.role === 'grid');
        const geometry = createPreparedBarChartGeometry(await f.resources.prepare(f.chart.binding), { ...f.chart.options, width: scene.plot.width, height: scene.plot.height });
        assert.equal(grid.length, 7);
        assert.equal(ticks.length, grid.length);
        assert.deepEqual(grid.map(p => kind === 'bar' ? p.x : p.y), geometry.ticks.map(t => (kind === 'bar' ? scene.plot.x : scene.plot.y) + t.position));
        assert.deepEqual(scene.table.series.map(s => s.label), ['Series s0']);
        assert.deepEqual(scene.table.categories.map(c => c.label), ['Categoría larga 0', 'Categoría larga 1', 'Categoría larga 2', 'Categoría larga 3']);
    }
});

test('visual tick rounding preserves finite unique order signs and tiny nonzero values', async () => {
    for (const [min, max] of [[0, 3e-6], [0, 3e-300], [0, 5e-324], [-5e-324, 0], [-1e308, 1e308], [-Number.MAX_VALUE, Number.MAX_VALUE]]) {
        const f = fixture('column', [[null]]);
        f.chart.options = { kind: 'column', domain_min: min, domain_max: max, tick_count: 7 };
        const scene = await createBarChartScene(f.chart, f.resources);
        const labels = scene.primitives.filter(p => p.role === 'tick').map(p => p.text);
        const parsed = labels.map(Number);
        assert.ok(parsed.every(Number.isFinite), `${min}..${max}`);
        assert.equal(new Set(parsed).size, parsed.length);
        assert.ok(parsed.every((v, i) => i === 0 || parsed[i - 1] < v));
        assert.equal(labels.filter(x => Number(x) === 0).length, 1);
        assert.ok(!labels.includes('-0'));
        if (min < 0) assert.ok(parsed[0] < 0);
        if (max > 0) assert.ok(parsed.at(-1) > 0);
        if (max === 3e-6) assert.ok(labels.every(x => x.length <= 6), labels.join(','));
        assert.equal(scene.table.rows[0].values[0].raw, null);
    }
});
test('all C1 annotations fit tiny charts with large fonts without truncating data or bars', async () => {
    const values = Array.from({ length: 1000 }, () => Array(12).fill(1));
    const f = fixture('column-grouped', values, { fontSize: 72, title: 'Title', subtitle: 'Subtitle', source: 'Source', x_title: 'X', y_title: 'Y', category_label_limit: 1, value_labels: 'raw', legend_position: 'right' });
    const scene = await createBarChartScene(f.chart, f.resources, { width: 160, height: 160 });
    assert.ok(scene.plot.width >= 40 && scene.plot.height >= 40);
    assert.equal(scene.table.rows.length, 1000);
    assert.equal(scene.primitives.filter(p => p.role === 'bar').length, 12000);
    assert.ok(scene.primitives.length <= 100 + 1000 + 24000);
    assert.ok(scene.warnings.length > 0);
    assert.equal(new Set(scene.primitives.map(p => p.id)).size, scene.primitives.length);
    for (const p of scene.primitives) {
        for (const key of ['x', 'y', 'width', 'height']) assert.ok(Number.isFinite(p[key]) && p[key] >= 0, `${p.id}.${key}`);
        assert.ok(p.x + p.width <= 160 + 1e-9 && p.y + p.height <= 160 + 1e-9, p.id);
    }
});
test('style switches control axes grid categories legend and preserve semantic table', async () => {
    const f = fixture('bar', [[1]], { show_axis: false, show_grid: false, category_labels: false, legend: false, background: '#1234' });
    const scene = await createBarChartScene(f.chart, f.resources);
    for (const role of ['axis', 'grid', 'zero', 'category', 'legend']) assert.equal(scene.primitives.filter(p => p.role === role).length, 0, role);
    assert.equal(scene.primitives[0].fill, '#1234');
    assert.equal(scene.table.rows.length, 1);
});
test('legend limits visual entries with a summary while retaining all category records', async () => {
    const f = fixture('bar-multicolor', Array.from({ length: 20 }, () => [1]));
    const scene = await createBarChartScene(f.chart, f.resources);
    assert.equal(scene.legend.length, 20);
    assert.equal(scene.primitives.filter(p => p.role === 'legend').length, 12);
    assert.ok(scene.primitives.some(p => p.role === 'legend-summary' && p.text.includes('8')));
});
test('abort before prepare performs no work; late abort never publishes', async () => {
    const f = fixture(); const c = new AbortController(); c.abort();
    await assert.rejects(createBarChartScene(f.chart, f.resources, { signal: c.signal }), { name: 'AbortError' }); assert.equal(f.calls(), 0);
    const late = new AbortController(); let release;
    const promise = createBarChartScene(f.chart, { prepare: () => new Promise(resolve => { release = resolve; }) }, { signal: late.signal });
    late.abort(); release(await f.resources.prepare(f.chart.binding));
    await assert.rejects(promise, { name: 'AbortError' });
});
test('dimensions and missing resources reject before preparing', async () => {
    const f = fixture();
    await assert.rejects(createBarChartScene(f.chart, f.resources, { width: 159 }), /dimension/i);
    await assert.rejects(createBarChartScene(f.chart, null), /resource/i);
    assert.equal(f.calls(), 0);
});
test('outside labels follow the signed outer end while stable IDs survive style changes', async () => {
    for (const kind of ['bar', 'column']) {
        const f = fixture(kind, [[-2], [3]], { value_labels: 'raw', label_position: 'outside' });
        const scene = await createBarChartScene(f.chart, f.resources);
        const bar = scene.primitives.find(p => p.id === 'bar:r0:s0');
        const value = scene.primitives.find(p => p.id === 'value:r0:s0');
        assert.equal(value.raw, '-2');
        if (kind === 'bar') assert.ok(value.x + value.width <= bar.x + 1e-9);
        else assert.ok(value.y >= bar.y + bar.height - 1e-9);
        const again = await createBarChartScene({ ...f.chart, style: { ...f.chart.style, title: 'Added title', legend: false } }, f.resources);
        assert.ok(again.primitives.some(p => p.id === bar.id));
    }
});
test('document budgets count chart bound plus ordinary nested layers and deduplicate revisions', async () => {
    const f = fixture('column-grouped', [[1, 2]]);
    const chartLayer = { type: 'chart', chart: f.chart };
    const d = { pages: [{ elements: [chartLayer, { type: 'group', elements: [{ type: 'rect' }] }] }] };
    let calls = 0;
    const registry = { describe: async ref => { calls++; return Object.freeze({ ...ref, bytes: 111, row_count: 1000, column_count: 13 }); } };
    await assertChartDocumentBudget(d, registry); assert.equal(calls, 1);
    const big = fixture('column-grouped', [Array(12).fill(1)]);
    const bigLayer = { type: 'chart', chart: big.chart };
    await assert.rejects(assertChartDocumentBudget({ pages: [{ elements: [bigLayer, bigLayer] }] }, registry), /50.?000|page/i);
    await assert.rejects(assertChartDocumentBudget({ pages: Array.from({ length: 8 }, () => ({ elements: [bigLayer] })) }, registry), /200.?000|document/i);
    calls = 0; await assertChartDocumentBudget({ pages: [{ elements: [chartLayer, chartLayer] }] }, registry); assert.equal(calls, 1);
});
const referenceWork = count => ({ pages: [{ elements: Array.from({ length: count }, (_, i) => ({ type: 'chart', chart: { binding: { dataset: { id: i + 1, revision: 1, sha256: sha }, category_column: 'c', series_columns: ['s'] } } })) }] });
test('A1 rejects 33 distinct work references before any registry I/O even with LRU available', async () => {
    let calls = 0;
    const registry = { resolve: async () => { calls++; return { table: { rows: [null] } }; }, describe: async ref => { calls++; return { ...ref, bytes: 111, row_count: 1, column_count: 2 }; } };
    await assert.rejects(assertChartDocumentBudget(referenceWork(33), registry), /32|reference/i);
    assert.equal(calls, 0);
});
test('A1 sums original verified bytes per unique work ref independently of retained tables', async () => {
    let described = 0, resolved = 0;
    const registry = { resolve: async () => { resolved++; return { table: { rows: [null] } }; }, describe: async ref => { described++; return Object.freeze({ ...ref, bytes: 33000001, row_count: 1, column_count: 2 }); } };
    await assert.rejects(assertChartDocumentBudget(referenceWork(2), registry), /64.?000.?000|byte/i);
    assert.equal(described, 2); assert.equal(resolved, 0);
    described = 0; const d = referenceWork(1); d.pages[0].elements.push(d.pages[0].elements[0]);
    await assertChartDocumentBudget(d, registry); assert.equal(described, 1);
});
test('D1 simulated cold 32-reference preflight has exactly four concurrent descriptions and no G5', async () => {
    let active = 0, peak = 0, calls = 0, prepared = 0;
    const started = performance.now();
    const describe = async ref => {
        calls++; active++; peak = Math.max(peak, active);
        await new Promise(resolve => setTimeout(resolve, 10)); active--;
        return Object.freeze({ ...ref, bytes: 111, row_count: 1, column_count: 2 });
    };
    const registry = { describe, resolve: async ref => { await describe(ref); return { table: { rows: [null] } }; }, prepare: () => { prepared++; throw Error('G5 forbidden in preflight'); } };
    await assertChartDocumentBudget(referenceWork(32), registry);
    process.stdout.write(`D1 simulated metadata probe: ${JSON.stringify({ references: calls, canonical_bytes: 32 * 111, delay_ms: 10, peak, rounds: Math.ceil(calls / peak), elapsed_ms: Math.round(performance.now() - started), extra_G5: prepared })}\n`);
    assert.equal(peak, 4); assert.equal(calls, 32); assert.equal(prepared, 0); assert.equal(active, 0);
});
test('D1 stops queue after observed failure abort or byte excess while bounding inflight at four', async () => {
    for (const reason of ['failure', 'abort', 'bytes']) {
        const c = new AbortController(); let calls = 0, active = 0;
        const registry = { async describe(ref) {
            calls++; active++;
            await new Promise(resolve => setTimeout(resolve, ref.id === 1 ? 1 : 10)); active--;
            if (ref.id === 1 && reason === 'failure') throw Error('Missing pinned revision');
            if (ref.id === 1 && reason === 'abort') c.abort();
            return Object.freeze({ ...ref, bytes: reason === 'bytes' ? 33000001 : 111, row_count: 1, column_count: 2 });
        } };
        await assert.rejects(assertChartDocumentBudget(referenceWork(32), registry, { signal: c.signal }), reason === 'failure' ? /Missing pinned/ : reason === 'abort' ? { name: 'AbortError' } : /canonical bytes/);
        assert.equal(calls, reason === 'bytes' ? 5 : 4); // First successful 33M may start one replacement before the second reveals excess.
        assert.equal(active, 0);
    }
});
test('D1 failed preflight cancels only its own waits while a genuine registry consumer completes', async () => {
    const bytes = new TextEncoder().encode('{"columns":[{"id":"c","label":"C"},{"id":"s","label":"S"}],"rows":[{"id":"r","values":["A",1]}]}');
    const digest = createHash('sha256').update(bytes).digest('hex'), ref = id => ({ id, revision: 1, sha256: digest });
    const d = referenceWork(32); d.pages[0].elements.forEach((e, i) => { e.chart.binding.dataset = ref(i + 1); });
    let release; const gate = new Promise(resolve => { release = resolve; });
    const started = [], requests = new Map(), oldLocation = globalThis.location;
    globalThis.location = new URL('https://scene.test/editor');
    const registry = createChartResources({ brandId: 7, fetchImpl: async (url, { signal }) => {
        const id = Number(url.match(/datasets\/(\d+)\/export/)[1]); started.push(id); requests.set(id, signal);
        if (id !== 1) await gate;
        return { status: id === 1 ? 503 : 200, url: 'https://scene.test/data', headers: new Headers({ 'content-type': 'application/json' }), body: new ReadableStream({ start(c) { c.enqueue(bytes); c.close(); } }) };
    } });
    try {
        const external = registry.describe(ref(2));
        await assert.rejects(assertChartDocumentBudget(d, registry), /HTTP 200/);
        assert.deepEqual([...started].sort(), [1, 2, 3, 4]);
        assert.equal(requests.get(2).aborted, false);
        release(); assert.equal((await external).row_count, 1); assert.equal(registry.stats().projections, 0);
    } finally { release(); registry.dispose(); if (oldLocation === undefined) delete globalThis.location; else globalThis.location = oldLocation; }
});
test('A1 genuine registry preflight uses exact verified Unicode bytes without G5 or retaining tables in helper', async () => {
    const original = new TextEncoder().encode('{"columns":[{"id":"c","label":"Café"},{"id":"s","label":"Series"}],"rows":[{"id":"r","values":["\\u2028\\u2029",1.2500]}]}');
    const digest = createHash('sha256').update(original).digest('hex');
    const ref = id => ({ id, revision: 1, sha256: digest });
    const work = count => { const d = referenceWork(count); d.pages[0].elements.forEach((e, i) => { e.chart.binding.dataset = ref(i + 1); }); return d; };
    const oldLocation = globalThis.location; globalThis.location = new URL('https://scene.test/editor');
    let calls = 0;
    const registry = createChartResources({ brandId: 7, fetchImpl: async () => { calls++; return { status: 200, url: 'https://scene.test/data', headers: new Headers({ 'content-type': 'application/json', 'content-length': String(original.length) }), body: new ReadableStream({ start(c) { c.enqueue(original); c.close(); } }) }; } });
    try {
        const quota = original.length * 2 - 1;
        const hook = registerHooks({ load(url, context, next) {
            const result = next(url, context);
            if (!url.endsWith('?t12QuotaScale')) return result;
            const source = typeof result.source === 'string' ? result.source : new TextDecoder().decode(result.source);
            assert.equal(source.split('bytes > 64000000').length, 2, 'scale only the work canonical-byte threshold');
            return { ...result, source: source.replace('bytes > 64000000', `bytes > ${quota}`) };
        } });
        let scaled;
        try { scaled = (await import('../../resources/js/Studio/chart-scene.js?t12QuotaScale')).assertChartDocumentBudget; }
        finally { hook.deregister(); }
        await assert.rejects(scaled(work(2), registry), /canonical bytes/);
        assert.equal(calls, 2);
        const first = await registry.describe(ref(1)); assert.equal(first.bytes, original.length); assert.ok(Object.isFrozen(first));
        const reencoded = new TextEncoder().encode(JSON.stringify(JSON.parse(new TextDecoder().decode(original))));
        assert.notEqual(first.bytes, reencoded.length);
        assert.equal(await assertChartDocumentBudget(work(2), registry), 206);
        assert.equal(calls, 2); assert.equal(registry.stats().projections, 0);
        for (let id = 3; id <= 34; id++) await registry.describe(ref(id));
        const before = calls; await assertChartDocumentBudget(work(1), registry); assert.equal(calls, before);
        await assert.rejects(assertChartDocumentBudget(work(33), registry), /32 unique references/); assert.equal(calls, before);
        process.stdout.write(`A1 scaled genuine-registry quota probe: ${JSON.stringify({ canonical_bytes_per_ref: original.length, work_limit_scaled: quota, actual_production_limit: 64000000, refs: 2, extra_G5: registry.stats().projections })}\n`);
    } finally { registry.dispose(); if (oldLocation === undefined) delete globalThis.location; else globalThis.location = oldLocation; }
});
