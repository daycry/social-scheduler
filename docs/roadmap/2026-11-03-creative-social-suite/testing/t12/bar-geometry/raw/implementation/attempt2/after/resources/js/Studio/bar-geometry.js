/** Pure chart geometry; public document and rendering integration are separate. */
import { projectCategoricalChart } from './chart-binding.js';
const DEFAULT_PALETTE = Object.freeze(['#7c3aed', '#0284c7', '#059669', '#d97706', '#dc2626', '#db2777', '#4f46e5', '#0891b2', '#65a30d', '#ea580c', '#9333ea', '#475569']);
const KINDS = ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor'];
const FIELDS = ['kind', 'width', 'height', 'band_gap', 'series_gap', 'palette', 'opacity', 'domain_min', 'domain_max', 'tick_count'];
const fail = message => { throw new TypeError(message); };
const numeric = (value, min, max, field) => {
    if (!Number.isFinite(value) || value < min || value > max) fail(`Gráfico.${field}: número fuera de rango.`);
};
// This receives only the fresh result graph created below, never arbitrary input objects.
function immutable(value) {
    if (value && typeof value === 'object') {
        for (const child of Object.values(value)) immutable(child);
        Object.freeze(value);
    }
    return value;
}
function containedSize(start, requested, limit) {
    const size = Math.max(0, Math.min(requested, limit - start));
    if (start + size <= limit) return size;
    // Rounded subtraction may still add back above limit. Lower one positive float,
    // rather than repeating the same subtraction or changing the data/domain.
    const bits = new DataView(new ArrayBuffer(8));
    bits.setFloat64(0, size);
    const low = bits.getUint32(4);
    if (low === 0) { bits.setUint32(0, bits.getUint32(0) - 1); bits.setUint32(4, 0xffffffff); }
    else bits.setUint32(4, low - 1);
    return bits.getFloat64(0);
}
export function normalizeBarChartOptions(value = {}) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail('Gráfico: opciones inválidas.');
    const descriptors = Object.getOwnPropertyDescriptors(value); const captured = {};
    for (const key of Reflect.ownKeys(descriptors)) {
        const descriptor = descriptors[key];
        if (!FIELDS.includes(key) || !descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) fail('Gráfico: campos de datos propios requeridos.');
        captured[key] = descriptor.value;
    }
    const result = { kind: 'column', width: 640, height: 360, band_gap: 0.2, series_gap: 0.1,
        opacity: 1, domain_min: null, domain_max: null, tick_count: 5, palette: DEFAULT_PALETTE, ...captured };
    if (!KINDS.includes(result.kind)) fail('Gráfico.kind: variante desconocida.');
    for (const key of ['width', 'height']) numeric(result[key], 40, 8000, key);
    for (const key of ['band_gap', 'series_gap']) numeric(result[key], 0, 0.9, key);
    numeric(result.opacity, 0, 1, 'opacity'); numeric(result.tick_count, 2, 21, 'tick_count');
    if (!Number.isInteger(result.tick_count)) fail('Gráfico.tick_count: entero requerido.');
    for (const key of ['domain_min', 'domain_max']) if (result[key] !== null && !Number.isFinite(result[key])) fail(`Gráfico.${key}: número finito o null requerido.`);
    if (!Array.isArray(result.palette)) fail('Gráfico.palette: lista requerida.');
    const length = Object.getOwnPropertyDescriptor(result.palette, 'length')?.value;
    if (!Number.isInteger(length) || length < 1 || length > 24) fail('Gráfico.palette: entre 1 y 24 colores.');
    const colors = Object.getOwnPropertyDescriptors(result.palette); const palette = [];
    if (Reflect.ownKeys(colors).length !== length + 1) fail('Gráfico.palette: propiedades adicionales.');
    for (let i = 0; i < length; i++) {
        const descriptor = colors[i];
        if (!descriptor || !Object.hasOwn(descriptor, 'value') || !descriptor.enumerable) fail('Gráfico.palette: lista densa de datos propios requerida.');
        const color = descriptor.value;
        if (typeof color !== 'string' || !/^#(?:[a-f\d]{3}|[a-f\d]{4}|[a-f\d]{6}|[a-f\d]{8})$/i.test(color)) fail('Gráfico.palette: color inválido.');
        palette.push(color);
    }
    return Object.freeze({ ...result, palette: Object.freeze(palette) });
}
export function createBarChartGeometry(binding, snapshot, options = {}) {
    const config = normalizeBarChartOptions(options);
    const projection = projectCategoricalChart(binding, snapshot);
    if (!/-(?:grouped|stacked|proportional)$/.test(config.kind) && projection.series.length !== 1) fail('Gráfico: esta variante exige exactamente una serie.');
    const segments = [], warnings = [...projection.warnings]; let min = 0, max = 0;
    const proportional = config.kind.endsWith('proportional');
    const stacked = config.kind.endsWith('stacked');
    const grouped = config.kind.endsWith('grouped');
    const multicolor = config.kind.endsWith('multicolor');
    if (proportional && ((config.domain_min !== null && config.domain_min !== 0) || (config.domain_max !== null && config.domain_max !== 100))) fail('Gráfico: el dominio proporcional debe ser 0…100.');
    for (let index = 0; index < projection.categories.length; index++) {
        let positive = 0, negative = 0;
        let largest = 0, total = 0, count = 0, cumulative = 0;
        if (proportional) {
            for (const series of projection.series) {
                const cell = series.values[index]; if (cell === null) continue;
                const nonzero = /[1-9]/.test(cell.raw.split(/[eE]/, 1)[0]);
                if (cell.raw[0] === '-' && nonzero) fail(`Fila ${projection.categories[index].row_id}, columna ${series.column_id}: el gráfico proporcional no admite negativos.`);
                if (cell.value === 0 && nonzero) fail(`Fila ${projection.categories[index].row_id}, columna ${series.column_id}: un valor no nulo se redondea a cero; no se puede calcular su porcentaje.`);
                largest = Math.max(largest, cell.value); count++;
            }
            for (const series of projection.series) { const cell = series.values[index]; if (cell !== null && largest !== 0) total += cell.value / largest; }
            if (count && total === 0) warnings.push({ code: 'ZERO_TOTAL', row_id: projection.categories[index].row_id });
        }
        for (let column = 0; column < projection.series.length; column++) {
            const cell = projection.series[column].values[index]; if (cell === null) continue;
            const start = proportional ? total ? cumulative / total * 100 : 0 : stacked ? cell.value < 0 ? negative : positive : 0;
            if (proportional && largest !== 0) cumulative += cell.value / largest;
            const end = proportional ? total ? cumulative / total * 100 : 0 : start + cell.value;
            if (!Number.isFinite(end)) fail(`Fila ${projection.categories[index].row_id}, columna ${projection.series[column].column_id}: acumulado no finito.`);
            if (stacked) { if (cell.value < 0) negative = end; else positive = end; }
            min = Math.min(min, start, end); max = Math.max(max, start, end);
            segments.push({ row: index, series: column, ...cell, start, end, percentage: proportional ? end - start : null });
        }
    }
    if (proportional) { min = 0; max = 100; }
    else {
        const observedMin = min, observedMax = max;
        min = config.domain_min ?? min; max = config.domain_max ?? max;
        if (min > 0 || max < 0 || min > observedMin || max < observedMax || min > max) fail('Gráfico: el dominio debe incluir cero y todos los extremos de los datos.');
        if (min === max) {
            if (config.domain_min !== null && config.domain_max !== null) fail('Gráfico: el dominio explícito no puede ser degenerado.');
            if (config.domain_max !== null) min = -1; else max = 1;
        }
    }
    if (min === 0) min = 0;
    if (max === 0) max = 0;
    const horizontal = config.kind.startsWith('bar');
    const extent = horizontal ? config.width : config.height;
    const scale = Math.max(Math.abs(min), Math.abs(max));
    const low = min / scale, high = max / scale;
    const position = value => {
        const fraction = (value / scale - low) / (high - low);
        return horizontal ? fraction * extent : extent - fraction * extent;
    };
    const band = (horizontal ? config.height : config.width) / projection.categories.length;
    const categories = projection.categories.map((category, index) => ({ ...category, center: (index + 0.5) * band, band_start: index * band, band_end: (index + 1) * band }));
    const bars = segments.map(segment => {
        const category = categories[segment.row]; const column = projection.series[segment.series];
        const first = position(segment.start), last = position(segment.end);
        const slot = band * (1 - config.band_gap) / (grouped ? projection.series.length : 1);
        const categoryStart = category.band_start + band * config.band_gap / 2 + (grouped ? slot * (segment.series + config.series_gap / 2) : 0);
        const thickness = slot * (grouped ? 1 - config.series_gap : 1);
        // The validated domain contains every endpoint; only remove border roundoff, never clip data.
        const x = Math.max(0, Math.min(config.width, horizontal ? Math.min(first, last) : categoryStart));
        const y = Math.max(0, Math.min(config.height, horizontal ? categoryStart : Math.min(first, last)));
        const width = containedSize(x, horizontal ? Math.abs(first - last) : thickness, config.width);
        const height = containedSize(y, horizontal ? thickness : Math.abs(first - last), config.height);
        if (segment.value !== 0 && (horizontal ? width : height) === 0) warnings.push({ code: 'ZERO_SIZE_BAR', row_id: category.row_id, column_id: column.column_id, raw: segment.raw });
        return { key: `${category.row_id}:${column.column_id}`, row_id: category.row_id, column_id: column.column_id,
            x, y, width, height,
            fill: config.palette[(multicolor ? segment.row : segment.series) % config.palette.length], opacity: config.opacity, value: segment.value, raw: segment.raw,
            start: segment.start, end: segment.end, percentage: segment.percentage };
    });
    const values = new Set([min, max, 0]);
    for (let i = 1; i < config.tick_count - 1; i++) {
        const fraction = i / (config.tick_count - 1);
        const value = min * (1 - fraction) + max * fraction;
        values.add(value === 0 ? 0 : value);
    }
    const ticks = [...values].sort((a, b) => a - b).map(value => ({ value, position: position(value) }));
    return immutable({ kind: config.kind, dataset: projection.dataset, orientation: horizontal ? 'horizontal' : 'vertical', width: config.width, height: config.height,
        domain: { min, max }, baseline: position(0), ticks, categories,
        series: projection.series.map(({ column_id, label }) => ({ column_id, label })), bars, warnings });
}
