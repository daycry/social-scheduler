import { normalizeTimelineDocument } from './timeline.js';
import { normalizeChartSpec } from './chart-spec.js';

/** The persisted document is application data, never executable Fabric JSON. */
export const ELEMENT_TYPES = Object.freeze(['text', 'rect', 'ellipse', 'triangle', 'line', 'path', 'image', 'video', 'group', 'chart', 'table', 'connector']);
export const DOCUMENT_LIMITS = Object.freeze({ bytes: 2 * 1024 * 1024, pages: 100, elements: 500, depth: 8, minSize: 40, maxSize: 8000 });
const copy = value => JSON.parse(JSON.stringify(value));
const plain = value => value !== null && typeof value === 'object' && !Array.isArray(value) && [Object.prototype, null].includes(Object.getPrototypeOf(value));
const colorPattern = /^#(?:[a-f\d]{3}|[a-f\d]{4}|[a-f\d]{6}|[a-f\d]{8})$/i;
const idPattern = /^[A-Za-z0-9_-]{1,64}$/;
let sequence = 0;

export class StudioDocumentError extends Error {
    constructor(errors) {
        const list = Array.isArray(errors) ? errors : [errors];
        super(list.join(' '));
        this.name = 'StudioDocumentError';
        this.errors = list;
    }
}

export function newId(prefix = 'element') {
    const uuid = globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}_${(++sequence).toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
    return `${prefix.slice(0, 20)}_${uuid}`;
}

export function newElement(type = 'rect', patch = {}) {
    const element = {
        id: newId(type), type, name: type === 'text' ? 'Texto' : type === 'image' ? 'Imagen' : type === 'video' ? 'Vídeo' : 'Elemento',
        x: 80, y: 80, width: type === 'text' ? 600 : 260, height: type === 'text' ? 100 : 180,
        scaleX: 1, scaleY: 1, rotation: 0, skewX: 0, skewY: 0, opacity: 1, locked: false, visible: true,
        fill: '#7c3aed', stroke: '', strokeWidth: 0, flipX: false, flipY: false,
    };
    if (type === 'text') Object.assign(element, { text: 'Escribe tu texto', fontFamily: 'Arial', fontSize: 64, fontWeight: 'normal', fontStyle: 'normal', textAlign: 'left', underline: false, lineHeight: 1.2, charSpacing: 0, fill: '#111827' });
    if (type === 'rect') element.radius = 0;
    if (type === 'line' || type === 'path') Object.assign(element, { fill: '#00000000', stroke: '#7c3aed', strokeWidth: 4 });
    if (type === 'group') element.elements = [];
    if (type === 'path') element.path = [['M', 0, 0], ['L', 260, 180]];
    if (type === 'image' || type === 'video') Object.assign(element, { asset_id: null, cropX: 0, cropY: 0, filters: {} });
    if (type === 'video') Object.assign(element, { trimStart: 0, trimEnd: null, volume: 1, muted: false });
    if (type === 'chart') Object.assign(element, { name: 'Gráfico', width: 800, height: 500 });
    if (type === 'table') Object.assign(element, { name: 'Tabla', width: 900, height: 240, table: defaultTable() });
    if (type === 'connector') Object.assign(element, { name: 'Conector', width: 300, height: 0, from: null, to: null, route: 'straight', arrow: 'end', points: [[0, 0], [300, 0]], fill: '#00000000', stroke: '#7c3aed', strokeWidth: 4 });
    return Object.assign(element, patch);
}

export function defaultTable() {
    return { rows: [['Concepto', 'Enero', 'Febrero'], ['Ventas', '120', '140'], ['Visitas', '900', '1050']], header: true, fontSize: 28, textColor: '#111827', headerFill: '#7c3aed', headerColor: '#ffffff', cellFill: '#ffffff', borderColor: '#cbd5e1', align: 'left' };
}

export function blankDocument(width = 1080, height = 1080) {
    return validateDocument({ schema_version: 1, width, height, pages: [{ id: newId('page'), name: 'Página 1', background: '#ffffff', duration: 5, elements: [] }] });
}

/** Give copies fresh ids and keep connectors attached to the copies that live beside them. */
export function renameElements(elements) {
    const renamed = new Map();
    const visit = element => { const id = newId(element.type); renamed.set(element.id, id); element.id = id; if (element.type === 'group') element.elements.forEach(visit); };
    const relink = element => {
        if (element.type === 'connector') for (const key of ['from', 'to']) element[key] = renamed.get(element[key]) ?? null;
        if (element.type === 'group') element.elements.forEach(relink);
    };
    elements.forEach(visit);
    elements.forEach(relink);
}

export function duplicatePage(page) {
    const result = copy(page);
    result.id = newId('page');
    renameElements(result.elements);
    return result;
}

export function cloneDocument(document, { newIds = false } = {}) {
    const result = copy(document);
    if (newIds) result.pages = result.pages.map(duplicatePage);
    return result;
}

export function findPage(document, pageId) {
    return document.pages.find(page => page.id === pageId) ?? null;
}

export function selectPages(document, pageIds = document.pages.map(page => page.id)) {
    if (!Array.isArray(pageIds) || pageIds.length === 0 || new Set(pageIds).size !== pageIds.length) throw new StudioDocumentError('Selecciona páginas distintas para exportar.');
    return pageIds.map(id => {
        const page = findPage(document, id);
        if (!page) throw new StudioDocumentError(`La página ${id} ya no está disponible.`);
        return page;
    });
}

function fail(path, message) { throw new StudioDocumentError(`${path}: ${message}`); }
function keys(value, allowed, path) {
    if (!plain(value)) fail(path, 'debe ser un objeto.');
    for (const key of Object.keys(value)) if (!allowed.includes(key)) fail(`${path}.${key}`, 'propiedad no admitida.');
}
function number(value, min, max, path, fallback) {
    const result = value === undefined ? fallback : value;
    if (!Number.isFinite(result) || result < min || result > max) fail(path, `debe estar entre ${min} y ${max}.`);
    return result;
}
function integer(value, min, max, path) {
    const result = number(value, min, max, path);
    if (!Number.isInteger(result)) fail(path, 'debe ser un número entero.');
    return result;
}
function text(value, max, path, fallback = '') {
    const result = value === undefined ? fallback : value;
    if (typeof result !== 'string' || [...result].length > max) fail(path, `debe ser texto de hasta ${max} caracteres.`);
    return result;
}
function bool(value, path, fallback = false) {
    const result = value === undefined ? fallback : value;
    if (typeof result !== 'boolean') fail(path, 'debe ser booleano.');
    return result;
}
function oneOf(value, values, path, fallback) {
    const result = value === undefined ? fallback : value;
    if (!values.includes(result)) fail(path, 'valor no admitido.');
    return result;
}
function identity(value, path, ids) {
    if (typeof value !== 'string' || !idPattern.test(value) || ids.has(value)) fail(path, 'identificador inválido o repetido.');
    ids.add(value);
    return value;
}
function color(value, path, empty = false) {
    if (empty && value === '') return '';
    if (typeof value !== 'string' || !colorPattern.test(value)) fail(path, 'usa un color hexadecimal.');
    return value;
}

export function normalizeFill(value, path = 'color') {
    if (typeof value === 'string') return color(value, path);
    keys(value, ['type', 'angle', 'stops'], path);
    if (value.type !== 'linear' || !Array.isArray(value.stops) || value.stops.length < 2 || value.stops.length > 12) fail(path, 'gradiente lineal inválido.');
    let previous = -1;
    return { type: 'linear', angle: number(value.angle, -360, 360, `${path}.angle`, 0), stops: value.stops.map((stop, i) => {
        keys(stop, ['offset', 'color'], `${path}.stops.${i}`);
        const offset = number(stop.offset, 0, 1, `${path}.stops.${i}.offset`);
        if (offset < previous) fail(path, 'las paradas deben estar ordenadas.');
        previous = offset;
        return { offset, color: color(stop.color, `${path}.stops.${i}.color`) };
    }) };
}

function normalizeTable(value, path) {
    keys(value, ['rows', 'header', 'fontSize', 'textColor', 'headerFill', 'headerColor', 'cellFill', 'borderColor', 'align'], path);
    if (!Array.isArray(value.rows) || value.rows.length < 1 || value.rows.length > 30) fail(`${path}.rows`, 'la tabla debe tener entre 1 y 30 filas.');
    const columns = Array.isArray(value.rows[0]) ? value.rows[0].length : 0;
    if (columns < 1 || columns > 12) fail(`${path}.rows`, 'la tabla debe tener entre 1 y 12 columnas.');
    const defaults = defaultTable();
    const result = {
        rows: value.rows.map((row, i) => {
            if (!Array.isArray(row) || row.length !== columns) fail(`${path}.rows.${i}`, 'todas las filas deben tener el mismo número de columnas.');
            return row.map((cell, j) => text(cell, 500, `${path}.rows.${i}.${j}`));
        }),
        header: bool(value.header, `${path}.header`, true), fontSize: number(value.fontSize, 8, 200, `${path}.fontSize`, defaults.fontSize),
        align: oneOf(value.align, ['left', 'center', 'right'], `${path}.align`, 'left'),
    };
    for (const key of ['textColor', 'headerFill', 'headerColor', 'cellFill', 'borderColor']) result[key] = color(value[key] ?? defaults[key], `${path}.${key}`);
    return result;
}

const commonKeys = ['id', 'type', 'name', 'x', 'y', 'width', 'height', 'scaleX', 'scaleY', 'rotation', 'skewX', 'skewY', 'opacity', 'locked', 'visible', 'fill', 'stroke', 'strokeWidth', 'flipX', 'flipY', 'shadow'];
const typeKeys = {
    text: ['text', 'fontFamily', 'font_id', 'fontSize', 'fontWeight', 'fontStyle', 'textAlign', 'underline', 'lineHeight', 'charSpacing'],
    rect: ['radius'], ellipse: [], triangle: [], line: ['x1', 'y1', 'x2', 'y2'], path: ['path'], chart: ['chart'], table: ['table'], connector: ['from', 'to', 'route', 'arrow', 'points'],
    image: ['asset_id', 'cropX', 'cropY', 'filters', 'mask'], video: ['asset_id', 'cropX', 'cropY', 'filters', 'trimStart', 'trimEnd', 'volume', 'muted'], group: ['elements'],
};

function normalizeElement(value, path, ids, count, depth) {
    if (++count.value > DOCUMENT_LIMITS.elements || depth > DOCUMENT_LIMITS.depth) fail(path, 'se supera el límite de capas o grupos.');
    const type = oneOf(value?.type, ELEMENT_TYPES, `${path}.type`);
    keys(value, [...commonKeys, ...typeKeys[type]], path);
    const defaults = newElement(type);
    const result = {
        id: identity(value.id, `${path}.id`, ids), type, name: text(value.name, 255, `${path}.name`, defaults.name),
        x: number(value.x, -32000, 32000, `${path}.x`, defaults.x), y: number(value.y, -32000, 32000, `${path}.y`, defaults.y),
        width: number(value.width, type === 'chart' ? 160 : 0, type === 'chart' ? 8000 : 32000, `${path}.width`, defaults.width), height: number(value.height, type === 'chart' ? 160 : 0, type === 'chart' ? 8000 : 32000, `${path}.height`, defaults.height),
        scaleX: number(value.scaleX, 0.01, 100, `${path}.scaleX`, 1), scaleY: number(value.scaleY, 0.01, 100, `${path}.scaleY`, 1),
        rotation: number(value.rotation, -360, 360, `${path}.rotation`, 0), skewX: number(value.skewX, -89, 89, `${path}.skewX`, 0), skewY: number(value.skewY, -89, 89, `${path}.skewY`, 0),
        opacity: number(value.opacity, 0, 1, `${path}.opacity`, 1), locked: bool(value.locked, `${path}.locked`), visible: bool(value.visible, `${path}.visible`, true),
        fill: normalizeFill(value.fill ?? defaults.fill, `${path}.fill`), stroke: color(value.stroke ?? defaults.stroke, `${path}.stroke`, true), strokeWidth: number(value.strokeWidth, 0, 1000, `${path}.strokeWidth`, defaults.strokeWidth),
        flipX: bool(value.flipX, `${path}.flipX`), flipY: bool(value.flipY, `${path}.flipY`),
    };
    if (value.shadow != null) {
        keys(value.shadow, ['color', 'blur', 'offsetX', 'offsetY'], `${path}.shadow`);
        result.shadow = { color: color(value.shadow.color, `${path}.shadow.color`), blur: number(value.shadow.blur, 0, 500, `${path}.shadow.blur`, 0), offsetX: number(value.shadow.offsetX, -32000, 32000, `${path}.shadow.offsetX`, 0), offsetY: number(value.shadow.offsetY, -32000, 32000, `${path}.shadow.offsetY`, 0) };
    }
    if (type === 'text') {
        const family = text(value.fontFamily, 100, `${path}.fontFamily`, 'Arial');
        if (!/^[\p{L}\p{N}][\p{L}\p{N} .,_-]{0,99}$/u.test(family) || /[\r\n]/.test(family)) fail(`${path}.fontFamily`, 'familia tipográfica inválida.');
        if (/(?:^|,)\s*StudioFont_/i.test(family)) fail(`${path}.fontFamily`, 'la familia privada interna está reservada.');
        Object.assign(result, { text: text(value.text, 10000, `${path}.text`, ''), fontFamily: family, fontSize: number(value.fontSize, 1, 1000, `${path}.fontSize`, 64), fontWeight: oneOf(value.fontWeight, ['normal', 'bold'], `${path}.fontWeight`, 'normal'), fontStyle: oneOf(value.fontStyle, ['normal', 'italic'], `${path}.fontStyle`, 'normal'), textAlign: oneOf(value.textAlign, ['left', 'center', 'right', 'justify'], `${path}.textAlign`, 'left'), underline: bool(value.underline, `${path}.underline`), lineHeight: number(value.lineHeight, 0.5, 5, `${path}.lineHeight`, 1.2), charSpacing: number(value.charSpacing, -200, 2000, `${path}.charSpacing`, 0) });
        if (value.font_id != null) result.font_id = integer(value.font_id, 1, Number.MAX_SAFE_INTEGER, `${path}.font_id`);
    }
    if (type === 'chart') {
        const descriptor = Object.getOwnPropertyDescriptor(value, 'chart');
        if (!descriptor?.enumerable || !Object.hasOwn(descriptor, 'value')) fail(`${path}.chart`, 'configuración propia requerida.');
        try { result.chart = normalizeChartSpec(descriptor.value); }
        catch (error) { fail(`${path}.chart`, error.message); }
    }
    if (type === 'table') result.table = normalizeTable(value.table, `${path}.table`);
    if (type === 'connector') {
        for (const key of ['from', 'to']) {
            if (value[key] != null && (typeof value[key] !== 'string' || !idPattern.test(value[key]))) fail(`${path}.${key}`, 'identificador de extremo inválido.');
            result[key] = value[key] ?? null;
        }
        result.route = oneOf(value.route, ['straight', 'elbow'], `${path}.route`, 'straight');
        result.arrow = oneOf(value.arrow, ['none', 'end', 'both'], `${path}.arrow`, 'end');
        if (!Array.isArray(value.points) || value.points.length < 2 || value.points.length > 4) fail(`${path}.points`, 'el conector necesita entre 2 y 4 puntos.');
        result.points = value.points.map((point, i) => {
            if (!Array.isArray(point) || point.length !== 2) fail(`${path}.points.${i}`, 'cada punto debe ser [x, y].');
            return point.map(coordinate => number(coordinate, -32000, 32000, `${path}.points.${i}`));
        });
    }
    if (type === 'rect') result.radius = number(value.radius, 0, 16000, `${path}.radius`, 0);
    if (type === 'line') for (const [key, fallback] of Object.entries({ x1: 0, y1: 0, x2: result.width, y2: result.height })) result[key] = number(value[key], -32000, 32000, `${path}.${key}`, fallback);
    if (type === 'path') {
        if (!Array.isArray(value.path) || value.path.length === 0 || value.path.length > 10000) fail(`${path}.path`, 'trazado vacío o excesivo.');
        result.path = value.path.map((command, i) => {
            const sizes = { M: 3, L: 3, C: 7, Q: 5, Z: 1 };
            if (!Array.isArray(command) || command.length !== sizes[command[0]]) fail(`${path}.path.${i}`, 'comando vectorial no admitido.');
            return [command[0], ...command.slice(1).map(v => number(v, -32000, 32000, `${path}.path.${i}`))];
        });
    }
    if (type === 'image' || type === 'video') {
        result.asset_id = integer(value.asset_id, 1, Number.MAX_SAFE_INTEGER, `${path}.asset_id`);
        result.cropX = number(value.cropX, 0, 32000, `${path}.cropX`, 0);
        result.cropY = number(value.cropY, 0, 32000, `${path}.cropY`, 0);
        // PHP's associative JSON roundtrip represents an empty filter object as an empty list.
        const filters = Array.isArray(value.filters) && value.filters.length === 0 && Object.keys(value.filters).length === 0 ? {} : value.filters ?? {};
        keys(filters, ['brightness', 'contrast', 'saturation', 'blur', 'grayscale', 'sepia', 'invert'], `${path}.filters`);
        result.filters = {};
        for (const [key, filter] of Object.entries(filters)) result.filters[key] = ['grayscale', 'sepia', 'invert'].includes(key) ? bool(filter, `${path}.filters.${key}`) : number(filter, key === 'blur' ? 0 : -1, 1, `${path}.filters.${key}`);
    }
    if (type === 'video') {
        result.trimStart = number(value.trimStart, 0, 86400, `${path}.trimStart`, 0);
        result.trimEnd = value.trimEnd == null ? null : number(value.trimEnd, 0.001, 86400, `${path}.trimEnd`);
        if (result.trimEnd !== null && result.trimEnd <= result.trimStart) fail(`${path}.trimEnd`, 'el final debe ser posterior al inicio.');
        result.volume = number(value.volume, 0, 1, `${path}.volume`, 1);
        result.muted = bool(value.muted, `${path}.muted`);
    }
    if (type === 'image' && value.mask != null) {
        keys(value.mask, ['type', 'radius'], `${path}.mask`);
        const shape = oneOf(value.mask.type, ['ellipse', 'rounded_rect'], `${path}.mask.type`);
        if (shape === 'ellipse' && Object.hasOwn(value.mask, 'radius')) fail(`${path}.mask.radius`, 'solo se admite en la máscara rectangular.');
        result.mask = shape === 'ellipse' ? { type: shape } : { type: shape, radius: number(value.mask.radius, 0, 16000, `${path}.mask.radius`, 0) };
    }
    if (type === 'group') {
        if (!Array.isArray(value.elements) || value.elements.length === 0) fail(`${path}.elements`, 'el grupo debe contener elementos.');
        result.elements = value.elements.map((child, i) => normalizeElement(child, `${path}.elements.${i}`, ids, count, depth + 1));
    }
    return result;
}

export function validateDocument(document) {
    if (document?.schema_version === 2) {
        try { return normalizeTimelineDocument(document, validateDocument); }
        catch (error) { if (error instanceof StudioDocumentError) throw error; throw new StudioDocumentError(error.message); }
    }
    keys(document, ['schema_version', 'width', 'height', 'pages'], 'document');
    if (document.schema_version !== 1) fail('schema_version', 'versión no admitida.');
    const width = integer(document.width, DOCUMENT_LIMITS.minSize, DOCUMENT_LIMITS.maxSize, 'width');
    const height = integer(document.height, DOCUMENT_LIMITS.minSize, DOCUMENT_LIMITS.maxSize, 'height');
    if (!Array.isArray(document.pages) || document.pages.length === 0 || document.pages.length > DOCUMENT_LIMITS.pages) fail('pages', 'debe contener entre 1 y 100 páginas.');
    const ids = new Set();
    const pages = document.pages.map((page, i) => {
        const path = `pages.${i}`;
        keys(page, ['id', 'name', 'background', 'duration', 'elements', 'notes'], path);
        const id = identity(page.id, `${path}.id`, ids);
        if (!Array.isArray(page.elements)) fail(`${path}.elements`, 'debe ser una lista.');
        const count = { value: 0 };
        const result = { id, name: text(page.name, 255, `${path}.name`, `Página ${i + 1}`), background: normalizeFill(page.background ?? '#ffffff', `${path}.background`), duration: number(page.duration, 0.1, 3600, `${path}.duration`, 5), elements: page.elements.map((element, j) => normalizeElement(element, `${path}.elements.${j}`, ids, count, 1)) };
        if (Object.hasOwn(page, 'notes')) {
            if (typeof page.notes !== 'string') fail(`${path}.notes`, 'debe ser texto de hasta 20000 caracteres.');
            result.notes = text(page.notes, 20000, `${path}.notes`);
        }
        return result;
    });
    const result = { schema_version: 1, width, height, pages };
    if (new TextEncoder().encode(JSON.stringify(result)).byteLength > DOCUMENT_LIMITS.bytes) fail('document', 'supera el tamaño máximo de 2 MiB.');
    return result;
}
