/** Pure chart data binding; document integration and geometry are separate concerns. */
import { validateDatasetTable } from './dataset-table.js';

const fail = message => { throw new TypeError(message); };
function columnId(value) {
    if (typeof value !== 'string' || value.length < 1 || value.length > 64 || !/^[A-Za-z0-9_-]+$/.test(value)) fail('Gráfico: ID de columna inválido.');
    return value;
}
function keys(value, required, optional, path) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)
        || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail(`${path}: campos inválidos.`);
    const descriptors = Object.getOwnPropertyDescriptors(value);
    if (Reflect.ownKeys(descriptors).some(key => !required.includes(key) && !optional.includes(key))
        || required.some(key => !Object.hasOwn(descriptors, key))) fail(`${path}: campos inválidos.`);
    const captured = {};
    for (const key of [...required, ...optional]) {
        if (!Object.hasOwn(descriptors, key)) continue;
        const descriptor = descriptors[key];
        if (!Object.hasOwn(descriptor, 'value') || !descriptor.enumerable) fail(`${path}.${key}: propiedad de datos propia requerida.`);
        captured[key] = descriptor.value;
    }
    return captured;
}
function denseArray(value, min, max, path) {
    if (!Array.isArray(value)) fail(`${path}: lista requerida.`);
    const descriptors = Object.getOwnPropertyDescriptors(value);
    const length = descriptors.length?.value;
    if (!Number.isInteger(length) || length < min || length > max || Reflect.ownKeys(descriptors).length !== length + 1) fail(`${path}: longitud o propiedades inválidas.`);
    const result = [];
    for (let index = 0; index < length; index++) {
        const descriptor = descriptors[index];
        if (!descriptor || !Object.hasOwn(descriptor, 'value') || !descriptor.enumerable) fail(`${path}: lista densa de datos propios requerida.`);
        result.push(descriptor.value);
    }
    return result;
}
function reference(value) {
    value = keys(value, ['id', 'revision', 'sha256'], [], 'Referencia');
    for (const key of ['id', 'revision']) if (!Number.isSafeInteger(value[key]) || value[key] < 1) fail(`Referencia.${key}: entero positivo seguro requerido.`);
    if (typeof value.sha256 !== 'string' || value.sha256.length !== 64 || !/^[a-f0-9]{64}$/.test(value.sha256)) fail('Referencia.sha256: SHA-256 inválido.');
    return Object.freeze({ id: value.id, revision: value.revision, sha256: value.sha256 });
}
export function normalizeChartBinding(value) {
    value = keys(value, ['dataset', 'category_column', 'series_columns'], ['precision'], 'Gráfico');
    const precision = Object.hasOwn(value, 'precision') ? value.precision : 'safe';
    if (!['safe', 'approximate'].includes(precision)) fail('Gráfico.precision: modo desconocido.');
    const category = columnId(value.category_column);
    const series = denseArray(value.series_columns, 1, 12, 'Series');
    const selected = new Set([category]);
    for (const id of series) { columnId(id); if (selected.has(id)) fail('Gráfico: columnas seleccionadas repetidas.'); selected.add(id); }
    return Object.freeze({ dataset: reference(value.dataset), category_column: category,
        series_columns: Object.freeze(series), precision });
}
function captureTable(value) {
    const fields = keys(value, ['columns', 'rows'], [], 'Tabla');
    const columns = denseArray(fields.columns, 1, 100, 'Columnas').map((column, index) => keys(column, ['id', 'label'], [], `Columna ${index + 1}`));
    const rows = denseArray(fields.rows, 0, 1000, 'Filas').map((row, index) => {
        const fields = keys(row, ['id', 'values'], [], `Fila ${index + 1}`);
        return { id: fields.id, values: denseArray(fields.values, 0, 100, `Fila ${index + 1}`) };
    });
    return validateDatasetTable({ columns, rows });
}
/** Compare decimal magnitude before Number rounding; never expand a consumer exponent. */
function exceedsSafeMagnitude(raw) {
    const unsigned = raw[0] === '-' ? raw.slice(1) : raw;
    const at = unsigned.search(/[eE]/);
    const coefficient = at < 0 ? unsigned : unsigned.slice(0, at);
    const dot = coefficient.indexOf('.');
    const fraction = dot < 0 ? 0 : coefficient.length - dot - 1;
    const digits = (dot < 0 ? coefficient : coefficient.slice(0, dot) + coefficient.slice(dot + 1)).replace(/^0+/, '');
    if (!digits.length) return false;
    let exponent = 0;
    if (at >= 0) {
        const text = unsigned.slice(at + 1);
        const negative = text[0] === '-';
        const start = negative || text[0] === '+' ? 1 : 0;
        // An exponent beyond this bound cannot cancel the coefficient's decimal places.
        const cap = raw.length + 32;
        for (let index = start; index < text.length && exponent < cap; index++) exponent = Math.min(cap, exponent * 10 + text.charCodeAt(index) - 48);
        if (negative) exponent = -exponent;
    }
    const integerDigits = digits.length + exponent - fraction;
    if (integerDigits !== 16) return integerDigits > 16;
    const prefix = digits.slice(0, 16).padEnd(16, '0');
    if (prefix !== '9007199254740991') return prefix > '9007199254740991';
    return /[1-9]/.test(digits.slice(16));
}
// Only newly constructed output reaches this helper, never caller-owned objects.
function immutableOutput(value) {
    if (value !== null && typeof value === 'object') {
        for (const child of Object.values(value)) immutableOutput(child);
        Object.freeze(value);
    }
    return value;
}
export function projectCategoricalChart(binding, snapshot) {
    const config = normalizeChartBinding(binding);
    snapshot = keys(snapshot, ['id', 'revision', 'sha256', 'table'], [], 'Revisión');
    const observed = reference({ id: snapshot.id, revision: snapshot.revision, sha256: snapshot.sha256 });
    if (['id', 'revision', 'sha256'].some(key => observed[key] !== config.dataset[key])) fail('Revisión: la identidad, revisión o SHA no coincide con el gráfico.');
    const table = captureTable(snapshot.table);
    const indexOf = id => {
        const index = table.columns.findIndex(column => column.id === id);
        if (index < 0) fail(`Gráfico: la columna ${id} no existe en esta revisión.`);
        return index;
    };
    const categoryIndex = indexOf(config.category_column);
    const seriesIndexes = config.series_columns.map(indexOf);
    const categories = table.rows.map(row => {
        const value = row.values[categoryIndex];
        return { row_id: row.id, type: value === null ? 'empty' : typeof value === 'string' ? 'text' : 'number',
            label: value === null ? '' : typeof value === 'string' ? value : value.raw };
    });
    const warnings = [];
    const series = config.series_columns.map((id, position) => {
        const index = seriesIndexes[position];
        return { column_id: id, label: table.columns[index].label,
            values: table.rows.map((row, rowIndex) => {
                const value = row.values[index];
                if (value === null) return null;
                if (typeof value === 'string') fail(`Fila ${rowIndex + 1} (${row.id}), columna ${index + 1} (${id}): debes convertir el texto explícitamente a número antes de graficar.`);
                const coordinate = Number(value.raw);
                const underflow = coordinate === 0 && /[1-9]/.test(value.raw.split(/[eE]/, 1)[0]);
                if (underflow || exceedsSafeMagnitude(value.raw)) {
                    if (config.precision === 'safe') fail(`Fila ${rowIndex + 1} (${row.id}), columna ${index + 1} (${id}): ${underflow ? 'un valor no nulo se redondea a cero' : 'la magnitud excede la precisión segura'}; elige aproximación explícitamente.`);
                    warnings.push({ code: underflow ? 'UNDERFLOW_COORDINATE' : 'APPROXIMATE_COORDINATE', row_id: row.id, column_id: id, row: rowIndex + 1, column: index + 1, raw: value.raw });
                }
                return { value: coordinate, raw: value.raw };
            }) };
    });
    return immutableOutput({ dataset: config.dataset, category_column: { ...table.columns[categoryIndex] }, precision: config.precision, categories, series, warnings });
}
