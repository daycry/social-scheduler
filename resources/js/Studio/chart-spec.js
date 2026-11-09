import { normalizeChartBinding } from './chart-binding.js';
import { normalizeBarChartOptions } from './bar-geometry.js';

const OPTION_FIELDS = ['kind', 'band_gap', 'series_gap', 'palette', 'domain_min', 'domain_max', 'tick_count'];
const STYLE_DEFAULTS = Object.freeze({ title: '', subtitle: '', source: '', x_title: '', y_title: '',
    fontFamily: 'Arial', fontSize: 16, fontWeight: 'normal', fontStyle: 'normal', text_color: '#111827',
    axis_color: '#64748b', grid_color: '#e2e8f0', background: '#ffffff', legend: true, show_grid: true,
    show_axis: true, category_labels: true, legend_position: 'bottom', value_labels: 'none', label_position: 'outside', category_label_limit: 24 });
const fail = (path, message) => { throw new TypeError(`${path}: ${message}`); };

// Capture descriptors before reading values. Validation never executes a caller's accessor.
function fields(value, allowed, required, path) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail(path, 'objeto de datos propio requerido.');
    const descriptors = Object.getOwnPropertyDescriptors(value);
    const result = {};
    for (const key of Reflect.ownKeys(descriptors)) {
        const descriptor = descriptors[key];
        if (!allowed.includes(key) || !descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) fail(path, 'campos de datos propios requeridos.');
        result[key] = descriptor.value;
    }
    if (required.some(key => !Object.hasOwn(result, key))) fail(path, 'faltan campos obligatorios.');
    return result;
}
function dense(value, path) {
    if (!Array.isArray(value) || ![Array.prototype, null].includes(Object.getPrototypeOf(value))) fail(path, 'lista propia requerida.');
    const descriptors = Object.getOwnPropertyDescriptors(value);
    const length = descriptors.length.value;
    if (length > 24 || Reflect.ownKeys(descriptors).length !== length + 1) fail(path, 'lista densa requerida.');
    const result = [];
    for (let i = 0; i < length; i++) {
        const descriptor = descriptors[i];
        if (!descriptor?.enumerable || !Object.hasOwn(descriptor, 'value')) fail(path, 'lista densa de datos propios requerida.');
        result.push(descriptor.value);
    }
    return result;
}
function hex(value, path) {
    if (typeof value !== 'string' || value[0] !== '#' || ![4, 5, 7, 9].includes(value.length) || /[^a-fA-F0-9]/.test(value.slice(1))) fail(path, 'color hexadecimal completo requerido.');
}
function choice(value, allowed, path) { if (!allowed.includes(value)) fail(path, 'valor no admitido.'); }
function numeric(value, min, max, path, integer = false) {
    if (!Number.isFinite(value) || value < min || value > max || (integer && !Number.isSafeInteger(value))) fail(path, 'número fuera de rango.');
}

/** Persist references and presentation only, never a dataset, geometry or executable markup. */
export function normalizeChartSpec(value) {
    const input = fields(value, ['binding', 'options', 'style'], ['binding'], 'chart');
    const capturedBinding = fields(input.binding, ['dataset', 'category_column', 'series_columns', 'precision'], ['dataset', 'category_column', 'series_columns'], 'chart.binding');
    capturedBinding.series_columns = dense(capturedBinding.series_columns, 'chart.binding.series_columns');
    const binding = normalizeChartBinding(capturedBinding);
    for (const id of [binding.category_column, ...binding.series_columns]) if (/[^A-Za-z0-9_-]/.test(id)) fail('chart.binding', 'ID de columna completo requerido.');
    const capturedOptions = Object.hasOwn(input, 'options') ? fields(input.options, OPTION_FIELDS, ['kind'], 'chart.options') : { kind: 'column' };
    if (Object.hasOwn(capturedOptions, 'palette')) {
        capturedOptions.palette = dense(capturedOptions.palette, 'chart.options.palette');
        capturedOptions.palette.forEach(color => hex(color, 'chart.options.palette'));
    }
    const normalized = normalizeBarChartOptions(capturedOptions);
    const options = Object.fromEntries(OPTION_FIELDS.map(key => [key, key === 'palette' ? Object.freeze([...normalized.palette]) : normalized[key]]));
    const proportional = options.kind.endsWith('-proportional');
    if (['bar', 'column', 'bar-multicolor', 'column-multicolor'].includes(options.kind) && binding.series_columns.length !== 1) fail('chart.binding.series_columns', 'esta variante requiere una sola serie.');
    if (proportional) {
        if ((options.domain_min !== null && options.domain_min !== 0) || (options.domain_max !== null && options.domain_max !== 100)) fail('chart.options', 'el dominio proporcional debe ser 0…100.');
    } else if ((options.domain_min !== null && options.domain_min > 0) || (options.domain_max !== null && options.domain_max < 0)
        || (options.domain_min !== null && options.domain_max !== null && options.domain_min >= options.domain_max)) fail('chart.options', 'el dominio debe incluir cero y no ser degenerado.');

    const capturedStyle = Object.hasOwn(input, 'style') ? fields(input.style, [...Object.keys(STYLE_DEFAULTS), 'font_id'], [], 'chart.style') : {};
    const style = { ...STYLE_DEFAULTS, ...capturedStyle };
    for (const key of ['title', 'subtitle', 'source', 'x_title', 'y_title']) {
        if (typeof style[key] !== 'string' || [...style[key]].length > 256 || /[\u0000-\u0008\u000b-\u001f\ud800-\udfff]/u.test(style[key])) fail(`chart.style.${key}`, 'texto Unicode válido de 256 caracteres sin controles requerido.');
    }
    if (typeof style.fontFamily !== 'string' || !/^[\p{L}\p{N}][\p{L}\p{N} .,_-]{0,99}$/u.test(style.fontFamily) || /[\r\n]/.test(style.fontFamily)
        || /(?:^|,)\s*StudioFont_/i.test(style.fontFamily)) fail('chart.style.fontFamily', 'familia tipográfica inválida o reservada.');
    if (Object.hasOwn(style, 'font_id')) numeric(style.font_id, 1, Number.MAX_SAFE_INTEGER, 'chart.style.font_id', true);
    numeric(style.fontSize, 8, 72, 'chart.style.fontSize');
    numeric(style.category_label_limit, 1, 80, 'chart.style.category_label_limit', true);
    for (const key of ['text_color', 'axis_color', 'grid_color', 'background']) hex(style[key], `chart.style.${key}`);
    for (const key of ['legend', 'show_grid', 'show_axis', 'category_labels']) if (typeof style[key] !== 'boolean') fail(`chart.style.${key}`, 'booleano requerido.');
    for (const [key, allowed] of Object.entries({ fontWeight: ['normal', 'bold'], fontStyle: ['normal', 'italic'], legend_position: ['bottom', 'right'], value_labels: ['none', 'raw', 'percentage'], label_position: ['inside', 'outside'] })) choice(style[key], allowed, `chart.style.${key}`);
    if (style.value_labels === 'percentage' && !proportional) fail('chart.style.value_labels', 'porcentajes sólo para variantes proporcionales.');
    return Object.freeze({ binding, options: Object.freeze(options), style: Object.freeze(style) });
}
