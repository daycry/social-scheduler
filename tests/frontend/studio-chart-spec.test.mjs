import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { normalizeChartSpec } from '../../resources/js/Studio/chart-spec.js';
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/studio/chart-spec.json', import.meta.url), 'utf8'));
const minimal = () => structuredClone(fixture.valid[0].input);

test('C1 normalizes omitted options, style and precision into owned frozen defaults', () => {
    const input = minimal(); const output = normalizeChartSpec(input);
    assert.deepEqual(output.options, { kind: 'column', band_gap: 0.2, series_gap: 0.1,
        palette: ['#7c3aed', '#0284c7', '#059669', '#d97706', '#dc2626', '#db2777', '#4f46e5', '#0891b2', '#65a30d', '#ea580c', '#9333ea', '#475569'],
        domain_min: null, domain_max: null, tick_count: 5 });
    assert.deepEqual(output.style, { title: '', subtitle: '', source: '', x_title: '', y_title: '', fontFamily: 'Arial', fontSize: 16,
        fontWeight: 'normal', fontStyle: 'normal', text_color: '#111827', axis_color: '#64748b', grid_color: '#e2e8f0', background: '#ffffff',
        legend: true, show_grid: true, show_axis: true, category_labels: true, legend_position: 'bottom', value_labels: 'none', label_position: 'outside', category_label_limit: 24 });
    assert.equal(output.binding.precision, 'safe');
    for (const value of [output, output.binding, output.binding.dataset, output.binding.series_columns, output.options, output.options.palette, output.style]) assert.ok(Object.isFrozen(value));
    assert.notEqual(output.binding, input.binding);
    input.binding.dataset.id = 9; assert.equal(output.binding.dataset.id, 1);
});

for (const entry of fixture.valid) test(`shared valid fixture: ${entry.name}`, () => {
    const result = normalizeChartSpec(entry.input);
    assert.equal(result.options.kind, entry.expected_subset.kind);
    for (const [key, value] of Object.entries(entry.expected_subset)) if (key !== 'kind') assert.deepEqual(result.style[key], value);
    assert.deepEqual(normalizeChartSpec(result), result);
});
for (const entry of fixture.invalid) test(`shared invalid fixture: ${entry.name}`, () => {
    const input = minimal(); let target = input;
    for (const key of entry.path.slice(0, -1)) target = target[key] ??= {};
    target[entry.path.at(-1)] = entry.value;
    assert.throws(() => normalizeChartSpec(input), TypeError);
});

test('C1 captures only own enumerable data without invoking hostile getters', () => {
    let calls = 0;
    const getter = (object, key) => Object.defineProperty(object, key, { enumerable: true, get() { calls++; throw new Error('executed getter'); } });
    for (const make of [
        () => getter({}, 'binding'),
        () => ({ ...minimal(), options: getter({}, 'kind') }),
        () => ({ ...minimal(), style: getter({}, 'title') }),
        () => { const input = minimal(); getter(input.binding.dataset, 'id'); return input; },
        () => { const input = minimal(); getter(input.binding.series_columns, '0'); return input; },
        () => ({ ...minimal(), options: { kind: 'column', palette: getter(['#123'], '0') } }),
        () => Object.create({ binding: minimal().binding }),
        () => ({ ...minimal(), style: Object.create({ title: 'inherited' }) }),
        () => ({ ...minimal(), [Symbol('secret')]: 1 }),
        () => Object.defineProperty(minimal(), 'style', { value: {}, enumerable: false }),
        () => { const input = minimal(); input.binding.series_columns = Array(1); return input; },
        () => ({ ...minimal(), options: { kind: 'column', palette: Array(1) } }),
        () => ({ ...minimal(), options: { kind: 'column', palette: Object.assign(['#123'], { extra: '#fff' }) } }),
    ]) assert.throws(() => normalizeChartSpec(make()), TypeError);
    assert.equal(calls, 0);
    const input = minimal(); Object.setPrototypeOf(input, null);
    assert.equal(normalizeChartSpec(input).binding.dataset.id, 1);
});

test('C1 option semantics restrict series, zero-inclusive domains and percentages', () => {
    for (const kind of ['bar', 'column', 'bar-multicolor', 'column-multicolor']) {
        const input = minimal(); input.options = { kind }; input.binding.series_columns = ['v1', 'v2'];
        assert.throws(() => normalizeChartSpec(input), TypeError);
    }
    for (const options of [
        { kind: 'column', domain_min: 1 }, { kind: 'column', domain_max: -1 },
        { kind: 'column', domain_min: 0, domain_max: 0 }, { kind: 'bar-proportional', domain_min: -1 },
        { kind: 'column-proportional', domain_max: 99 }, { kind: 'column', opacity: 1 }, { kind: 'column', height: 500 },
        { kind: 'column', palette: ['#123\n'] },
    ]) assert.throws(() => normalizeChartSpec({ ...minimal(), options }), TypeError);
    for (const kind of ['bar-proportional', 'column-proportional']) {
        const input = { ...minimal(), options: { kind, domain_min: 0, domain_max: 100 }, style: { value_labels: 'percentage' } };
        input.binding.series_columns = ['v1', 'v2'];
        assert.equal(normalizeChartSpec(input).style.value_labels, 'percentage');
    }
});

test('C1 style uses codepoints and safe local families, explicit font identity and typed limits', () => {
    assert.equal(normalizeChartSpec({ ...minimal(), style: { title: '😀'.repeat(256), subtitle: '\tline\nnext', fontSize: 72, category_label_limit: 80 } }).style.title.length, 512);
    for (const style of [
        { font_id: 0 }, { font_id: Number.MAX_SAFE_INTEGER + 1 }, { font_id: 1.2 }, { fontSize: 7 }, { fontSize: Infinity },
        { fontFamily: 'url(x)' }, { fontFamily: 'Arial\n' }, { fontFamily: 'StudioFont_123' }, { fontFamily: 'Arial, studiofont_123' },
        { fontWeight: 700 }, { fontStyle: 'oblique' }, { legend: 1 }, { category_labels: null },
        { category_label_limit: 1.5 }, { title: 'bad\r' }, { title: 'bad\u001f' }, { title: 12 }, { html: '' },
        { background: '#fffff' }, { value_labels: 'percent' }, { legend_position: 'top' }, { label_position: 'center' },
    ]) assert.throws(() => normalizeChartSpec({ ...minimal(), style }), TypeError);
});

for (const field of ['title', 'subtitle', 'source', 'x_title', 'y_title']) {
    for (const [name, text] of [['high', '\ud800'], ['low', '\udfff']]) {
        test(`C1 Unicode rejects isolated ${name} surrogate in ${field}`, () => {
            assert.throws(() => normalizeChartSpec({ ...minimal(), style: { [field]: `before${text}after` } }), TypeError);
        });
    }
    test(`characterization: C1 Unicode preserves valid surrogate pairs in ${field}`, () => {
        const text = 'before\ud83d\ude00after';
        assert.equal(normalizeChartSpec({ ...minimal(), style: { [field]: text } }).style[field], text);
    });
}
