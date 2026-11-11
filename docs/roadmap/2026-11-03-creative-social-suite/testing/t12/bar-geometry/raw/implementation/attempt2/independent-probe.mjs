import fs from 'node:fs';
import assert from 'node:assert/strict';
import { pathToFileURL, fileURLToPath } from 'node:url';
import path from 'node:path';
import { performance } from 'node:perf_hooks';
import { createBarChartGeometry } from '../../../../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../../../../resources/js/Studio/dataset-table.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../../../../..');
const oldSource = fs.readFileSync(path.join(here, 'before/resources/js/Studio/bar-geometry.js'), 'utf8')
    .replace("'./chart-binding.js'", JSON.stringify(pathToFileURL(path.join(root, 'resources/js/Studio/chart-binding.js')).href));
const previous = await import('data:text/javascript;base64,' + Buffer.from(oldSource).toString('base64'));
const sha = 'a'.repeat(64);
function fixture(kind, rows) {
    const count = /-(grouped|stacked|proportional)$/.test(kind) ? 3 : 1;
    const binding = { dataset: { id: 8, revision: 3, sha256: sha }, category_column: 'category', series_columns: Array.from({ length: count }, (_, i) => `s${i}`) };
    const snapshot = { id: 8, revision: 3, sha256: sha, table: {
        columns: [{ id: 'category', label: 'Categoría' }, ...binding.series_columns.map(id => ({ id, label: id }))],
        rows: Array.from({ length: rows }, (_, i) => ({ id: `r${i}`, values: [`Row ${i}`, ...Array.from({ length: count }, (_, j) => {
            if (j === 1 && i % 2 === 0) return null;
            let value = [[129, 7, 533], [17, 13, 347], [0, 29, 199]][i % 3][j];
            if (!kind.endsWith('proportional') && i % 2 && j === 0) value = -value;
            return datasetNumber(String(value));
        })] })),
    } };
    return { binding, snapshot };
}
const semantic = result => ({ ...result, bars: result.bars.map(({ x, y, width, height, ...record }) => record) });
let seed = 123456789;
const randomSize = () => { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return 40 + seed / 4294967296 * 7960; };
const sizes = [[3728.5800000000004, 3728.5800000000004], [40.01, 40.01], [123.45, 8000], [8000, 123.45], ...Array.from({ length: 24 }, () => [randomSize(), randomSize()])];
const start = performance.now(); let charts = 0, bars = 0, originalOutside = 0, originalOutsideX = 0, originalOutsideY = 0;
for (const kind of ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor']) {
    for (const rowCount of [1, 3, 7]) for (const [index, [width, height]] of sizes.entries()) {
        const input = fixture(kind, rowCount);
        const options = { kind, width, height, band_gap: [0, 0.2, 0.9][index % 3], series_gap: [0.9, 0, 0.1][index % 3] };
        const old = previous.createBarChartGeometry(input.binding, input.snapshot, options);
        const current = createBarChartGeometry(input.binding, input.snapshot, options);
        assert.deepEqual(semantic(current), semantic(old), 'Data, ranges, percentages, identities and warnings must remain unchanged');
        for (const record of old.bars) {
            if (record.x + record.width > width || record.y + record.height > height) originalOutside++;
            if (record.x + record.width > width) originalOutsideX++;
            if (record.y + record.height > height) originalOutsideY++;
        }
        for (const [barIndex, record] of current.bars.entries()) {
            const priorBar = old.bars[barIndex];
            assert.equal(record.x, priorBar.x); assert.equal(record.y, priorBar.y);
            assert(record.width <= priorBar.width && record.height <= priorBar.height);
            assert(record.x >= 0 && record.y >= 0 && record.width >= 0 && record.height >= 0);
            assert(record.x + record.width <= width && record.y + record.height <= height);
            assert([record.x, record.y, record.width, record.height].every(Number.isFinite)); bars++;
        }
        charts++;
    }
}
const exact = fixture('bar-proportional', 1), size = 3728.5800000000004;
const prior = previous.createBarChartGeometry(exact.binding, exact.snapshot, { kind: 'bar-proportional', width: size }).bars.at(-1);
const fixed = createBarChartGeometry(exact.binding, exact.snapshot, { kind: 'bar-proportional', width: size }).bars.at(-1);
const bits = new DataView(new ArrayBuffer(8)); bits.setFloat64(0, prior.width); bits.setBigUint64(0, bits.getBigUint64(0) - 1n);
assert.equal(fixed.width, bits.getFloat64(0)); assert(fixed.x + fixed.width <= size);
const result = { charts, bars, original_outside_bars: originalOutside, original_outside_x: originalOutsideX, original_outside_y: originalOutsideY, current_outside_bars: 0,
    unchanged_semantics: true, exact_one_representable_step: true, milliseconds: performance.now() - start,
    scope: 'Pure deterministic geometry probe; 28 fractional size pairs, both axes, ten variants; no native/app/HTTP/DB' };
fs.writeFileSync(path.join(here, 'independent-probe.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify(result));
