/** Pure, lossless dataset editing. Numbers keep their JSON lexeme instead of entering IEEE-754. */
const NUMBER = /^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/;
const numberTokens = new WeakSet();
const fail = message => { throw new TypeError(message); };
export function datasetNumber(raw) {
    if (typeof raw !== 'string' || !NUMBER.test(raw) || !Number.isFinite(Number(raw))) fail('Número JSON no finito o inválido.');
    const token = Object.freeze({ type: 'number', raw }); numberTokens.add(token); return token;
}
function parseLossless(text) {
    if (typeof text !== 'string') fail('Se esperaba JSON como texto.');
    let pos = 0;
    const ws = () => { while (/[ \t\r\n]/.test(text[pos] ?? '') && pos < text.length) pos++; };
    function value(depth = 0) {
        ws(); if (depth > 16) fail('JSON demasiado anidado.');
        const start = pos;
        if (text[pos] === '"') {
            pos++;
            while (pos < text.length) {
                if (text[pos] === '\\') { pos += 2; continue; }
                if (text[pos++] === '"') return JSON.parse(text.slice(start, pos));
            }
            fail('Cadena JSON incompleta.');
        }
        if (text[pos] === '[' || text[pos] === '{') {
            const object = text[pos++] === '{'; const out = object ? {} : []; const end = object ? '}' : ']';
            ws(); if (text[pos] === end) { pos++; return out; }
            while (pos < text.length) {
                let key;
                if (object) { if (text[pos] !== '"') fail('Clave JSON inválida.'); key = value(depth + 1); if (Object.hasOwn(out, key)) fail('Clave JSON duplicada.'); ws(); if (text[pos++] !== ':') fail('Falta dos puntos.'); }
                const child = value(depth + 1);
                if (object) Object.defineProperty(out, key, { value: child, enumerable: true, writable: true, configurable: true }); else out.push(child);
                ws(); const separator = text[pos++]; if (separator === end) return out;
                if (separator !== ',') fail('Separador JSON inválido.'); ws();
            }
            fail('JSON incompleto.');
        }
        for (const [literal, result] of [['null', null], ['true', true], ['false', false]]) {
            if (text.startsWith(literal, pos)) { pos += literal.length; return result; }
        }
        const match = /^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/.exec(text.slice(pos));
        if (!match) fail('Valor JSON inválido.');
        pos += match[0].length; return datasetNumber(match[0]);
    }
    const parsed = value(); ws(); if (pos !== text.length) fail('Contenido después del JSON.'); return parsed;
}
export function parseDatasetTable(text) { inputBudget(text, 52000000, '52.000.000 bytes de entrada'); return prepared(parseLossless(text)); }
const UTF8 = new TextEncoder();
function inputBudget(text, max, label) {
    if (typeof text !== 'string') fail('Se esperaba JSON como texto.');
    if (text.length > max) fail(`El JSON supera ${label}.`);
    let bytes = 0;
    for (let start = 0; start < text.length;) {
        let end = Math.min(start + 65536, text.length);
        const high = text.charCodeAt(end - 1); const low = text.charCodeAt(end);
        if (end < text.length && high >= 0xd800 && high <= 0xdbff && low >= 0xdc00 && low <= 0xdfff) end--;
        bytes += UTF8.encode(text.slice(start, end)).byteLength;
        if (bytes > max) fail(`El JSON supera ${label}.`);
        start = end;
    }
}
const ID = /^[A-Za-z0-9_-]{1,64}$/;
function dataFields(object, expected, path) {
    if (!object || typeof object !== 'object' || Array.isArray(object)
        || ![Object.prototype, null].includes(Object.getPrototypeOf(object))) fail(`${path}: campos inválidos.`);
    const descriptors = Object.getOwnPropertyDescriptors(object);
    if (Reflect.ownKeys(descriptors).length !== expected.length) fail(`${path}: campos inválidos.`);
    const captured = {};
    for (const key of expected) {
        const descriptor = descriptors[key];
        if (!descriptor || !Object.hasOwn(descriptor, 'value') || !descriptor.enumerable) fail(`${path}: se requieren propiedades de datos propias.`);
        captured[key] = descriptor.value;
    }
    return captured;
}
const keys = (object, expected, path) => { dataFields(object, expected, path); };
function denseDataArray(array, min, max, path) {
    if (!Array.isArray(array)) fail(`${path}: se esperaba una lista.`);
    const descriptors = Object.getOwnPropertyDescriptors(array); const length = descriptors.length?.value;
    if (!Number.isInteger(length) || length < min || length > max || Reflect.ownKeys(descriptors).length !== length + 1) fail(`${path}: longitud o propiedades de lista inválidas.`);
    const captured = [];
    for (let index = 0; index < length; index++) {
        const descriptor = descriptors[index];
        if (!descriptor || !Object.hasOwn(descriptor, 'value') || !descriptor.enumerable) fail(`${path}: la lista debe ser densa y contener datos propios.`);
        captured.push(descriptor.value);
    }
    return captured;
}
function captureTableData(table) {
    if (frozenTables.has(table)) return table;
    const fields = dataFields(table, ['columns', 'rows'], 'Tabla');
    const columns = frozenColumnArrays.has(fields.columns) ? fields.columns : denseDataArray(fields.columns, 1, 100, 'Columnas').map((column, index) => dataFields(column, ['id', 'label'], `Columna ${index + 1}`));
    const rows = denseDataArray(fields.rows, 0, 1000, 'Filas').map((row, index) => {
        if (frozenRows.has(row)) return row;
        const fields = dataFields(row, ['id', 'values'], `Fila ${index + 1}`);
        return { id: fields.id, values: denseDataArray(fields.values, 0, 100, `Fila ${index + 1}`) };
    });
    return { columns, rows };
}
function validText(value, limit, path) {
    if (typeof value !== 'string') fail(`${path}: se esperaba texto.`);
    let length = 0;
    for (const point of value) {
        const code = point.codePointAt(0);
        if (++length > limit || (code >= 0xd800 && code <= 0xdfff) || (code < 32 && code !== 9 && code !== 10) || (code >= 127 && code <= 159)) fail(`${path}: longitud, Unicode o controles inválidos.`);
    }
}
function validId(id, seen, path) {
    if (typeof id !== 'string' || !ID.test(id) || seen.has(id)) fail(`${path}: identidad inválida o duplicada.`);
    seen.add(id);
}
function validCell(value, path) {
    if (value === null) return;
    if (typeof value === 'string') return validText(value, 10000, path);
    if (!numberTokens.has(value)) fail(`${path}: se esperaba texto, número tipado o vacío.`);
    keys(value, ['type', 'raw'], path);
    if (value.type !== 'number') fail(`${path}: tipo de celda inválido.`);
    datasetNumber(value.raw);
}
const quote = value => JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
const encodeCell = value => value === null ? 'null' : typeof value === 'string' ? quote(value) : value.raw;
function* rawChunks(table) {
    yield '{"columns":[';
    for (let i = 0; i < table.columns.length; i++) {
        const column = table.columns[i];
        yield `${i ? ',' : ''}{"id":${quote(column.id)},"label":${quote(column.label)}}`;
    }
    yield '],"rows":[';
    for (let i = 0; i < table.rows.length; i++) {
        const row = table.rows[i];
        yield `${i ? ',' : ''}{"id":${quote(row.id)},"values":[${row.values.map(encodeCell).join(',')}]}`;
    }
    yield ']}';
}
function validateCapturedTable(table) {
    keys(table, ['columns', 'rows'], 'Tabla');
    if (!Array.isArray(table.columns) || !Array.isArray(table.rows) || table.columns.length < 1 || table.columns.length > 100 || table.rows.length > 1000) fail('Tabla: se requieren 1–100 columnas y hasta 1000 filas.');
    const columnIds = new Set(); const rowIds = new Set(); const checkedStrings = new Set();
    table.columns.forEach((column, index) => {
        keys(column, ['id', 'label'], `Columna ${index + 1}`);
        validId(column.id, columnIds, `Columna ${index + 1}`); validText(column.label, 500, `Columna ${index + 1}`);
    });
    table.rows.forEach((row, index) => {
        keys(row, ['id', 'values'], `Fila ${index + 1}`); validId(row.id, rowIds, `Fila ${index + 1}`);
        if (!Array.isArray(row.values) || row.values.length !== table.columns.length) fail(`Fila ${index + 1}: anchura inválida.`);
        row.values.forEach((value, col) => {
            if (typeof value === 'string' && checkedStrings.has(value)) return;
            validCell(value, `Fila ${index + 1}, columna ${col + 1}`);
            if (typeof value === 'string') checkedStrings.add(value);
        });
    });
    let bytes = 0;
    for (const chunk of rawChunks(table)) { bytes += UTF8.encode(chunk).byteLength; if (bytes > 52000000) fail('La tabla supera 52.000.000 bytes JSON UTF-8.'); }
    return table;
}
export function validateDatasetTable(table) { validateCapturedTable(captureTableData(table)); return table; }
function snapshot(table) {
    if (frozenTables.has(table)) return table;
    const columns = frozenColumnArrays.has(table.columns) ? table.columns : Object.freeze(table.columns.map(column => Object.freeze({ id: column.id, label: column.label })));
    frozenColumnArrays.add(columns);
    const rows = Object.freeze(table.rows.map(row => {
        if (frozenRows.has(row)) return row;
        const result = Object.freeze({ id: row.id, values: Object.freeze([...row.values]) });
        frozenRows.add(result); return result;
    }));
    const result = Object.freeze({ columns, rows }); frozenTables.add(result); return result;
}
/** Validation happens now, not after the caller has sent download/upload headers. */
export function datasetTableChunks(table) { return rawChunks(prepared(table)); }
export function cellType(value) { validCell(value, 'Celda'); return value === null ? 'empty' : typeof value === 'string' ? 'text' : 'number'; }
export function cellText(value) { return cellType(value) === 'empty' ? '' : typeof value === 'string' ? value : value.raw; }
export function cellFromInput(text, type) {
    if (type === 'empty') return null;
    if (typeof text !== 'string') fail('El valor editable debe ser texto.');
    if (type === 'text') { validText(text, 10000, 'Celda'); return text; }
    if (type === 'number') return datasetNumber(text);
    fail('Tipo de celda desconocido.');
}
const frozenRows = new WeakSet(); const frozenTables = new WeakSet(); const frozenColumnArrays = new WeakSet();
function prepared(table) { return frozenTables.has(table) ? table : snapshot(validateCapturedTable(captureTableData(table))); }
function finished(columns, rows) { return prepared({ columns, rows }); }
function columnIndex(table, id) { const index = table.columns.findIndex(column => column.id === id); if (index < 0) fail('Columna no encontrada.'); return index; }
function rowIndex(table, id) { const index = table.rows.findIndex(row => row.id === id); if (index < 0) fail('Fila no encontrada.'); return index; }
function mappedRows(table, transform) {
    let changed = false;
    const rows = table.rows.map((row, rowIndex) => {
        const values = row.values.map((value, colIndex) => transform(value, colIndex, rowIndex));
        if (values.every((value, i) => value === row.values[i])) return row;
        changed = true; return { id: row.id, values };
    });
    return changed ? finished(table.columns, rows) : table;
}
export function editDatasetCell(source, rowId, columnId, value) {
    const table = prepared(source); const ri = rowIndex(table, rowId); const ci = columnIndex(table, columnId); validCell(value, 'Celda');
    return mappedRows(table, (previous, c, r) => r === ri && c === ci ? value : previous);
}
export function renameDatasetColumn(source, columnId, label) {
    const table = prepared(source); const index = columnIndex(table, columnId); validText(label, 500, 'Columna');
    return finished(table.columns.map((column, i) => i === index ? { ...column, label } : column), table.rows);
}
function newId(kind, existing, idFactory) {
    for (let index = 1; index <= 10000; index++) {
        const id = idFactory ? idFactory(kind, index, new Set(existing)) : `${kind}${index}`;
        if (typeof id !== 'string' || !ID.test(id)) fail('La factoría devolvió una identidad inválida.');
        if (!existing.has(id)) { existing.add(id); return id; }
    }
    fail('No se pudo generar una identidad nueva.');
}
export function blankDatasetTable({ rows = 3, columns = 3, idFactory } = {}) {
    if (!Number.isInteger(rows) || !Number.isInteger(columns) || rows < 0 || rows > 1000 || columns < 1 || columns > 100) fail('Dimensiones de tabla inválidas.');
    const cids = new Set(); const rids = new Set();
    return finished(Array.from({ length: columns }, (_, i) => ({ id: newId('c', cids, idFactory), label: `Columna ${i + 1}` })),
        Array.from({ length: rows }, () => ({ id: newId('r', rids, idFactory), values: Array(columns).fill(null) })));
}
export function addDatasetRow(source, { afterId = null, idFactory } = {}) {
    const table = prepared(source); if (table.rows.length >= 1000) fail('Máximo 1000 filas.');
    const index = afterId === null ? table.rows.length : rowIndex(table, afterId) + 1;
    const id = newId('r', new Set(table.rows.map(row => row.id)), idFactory);
    const rows = [...table.rows]; rows.splice(index, 0, { id, values: Array(table.columns.length).fill(null) });
    return finished(table.columns, rows);
}
export function removeDatasetRow(source, rowId) {
    const table = prepared(source); const index = rowIndex(table, rowId);
    return finished(table.columns, table.rows.filter((_, i) => i !== index));
}
export function addDatasetColumn(source, { afterId = null, label = 'Columna', idFactory } = {}) {
    const table = prepared(source); if (table.columns.length >= 100) fail('Máximo 100 columnas.'); validText(label, 500, 'Columna');
    const index = afterId === null ? table.columns.length : columnIndex(table, afterId) + 1;
    const id = newId('c', new Set(table.columns.map(column => column.id)), idFactory);
    const columns = [...table.columns]; columns.splice(index, 0, { id, label });
    const rows = table.rows.map(row => { const values = [...row.values]; values.splice(index, 0, null); return { id: row.id, values }; });
    return finished(columns, rows);
}
export function removeDatasetColumn(source, columnId) {
    const table = prepared(source); const index = columnIndex(table, columnId); if (table.columns.length === 1) fail('La tabla necesita al menos una columna.');
    return finished(table.columns.filter((_, i) => i !== index), table.rows.map(row => ({ id: row.id, values: row.values.filter((_, i) => i !== index) })));
}
export function copyDatasetTable(source, { idFactory } = {}) {
    const table = prepared(source); const cids = new Set(table.columns.map(column => column.id)); const rids = new Set(table.rows.map(row => row.id));
    return finished(table.columns.map(column => ({ id: newId('c', cids, idFactory), label: column.label })),
        table.rows.map(row => ({ id: newId('r', rids, idFactory), values: row.values })));
}
export function replaceDatasetText(source, search, replacement, { columnId = null } = {}) {
    const table = prepared(source); validText(search, 10000, 'Buscar'); validText(replacement, 10000, 'Reemplazo'); if (search === '') fail('La búsqueda no puede estar vacía.');
    const column = columnId === null ? null : columnIndex(table, columnId);
    return mappedRows(table, (value, c) => {
        if (typeof value !== 'string' || (column !== null && c !== column)) return value;
        let occurrences = 0; let pos = 0;
        while ((pos = value.indexOf(search, pos)) !== -1) { occurrences++; pos += search.length; }
        if ([...value].length + occurrences * ([...replacement].length - [...search].length) > 10000) fail('El reemplazo supera 10000 caracteres por celda.');
        return value.split(search).join(replacement);
    });
}
export function fillDatasetBlanks(source, value, { includeEmptyStrings = false, columnId = null } = {}) {
    const table = prepared(source); validCell(value, 'Relleno'); if (typeof includeEmptyStrings !== 'boolean') fail('Opción de vacíos inválida.');
    const column = columnId === null ? null : columnIndex(table, columnId);
    return mappedRows(table, (previous, c) => (column === null || c === column) && (previous === null || (includeEmptyStrings && previous === '')) ? value : previous);
}
export function previewDatasetConversion(source, columnId, type) {
    const table = prepared(source); const index = columnIndex(table, columnId); if (!['text', 'number', 'empty'].includes(type)) fail('Tipo de celda desconocido.');
    const changes = []; const errors = [];
    for (const row of table.rows) {
        try { changes.push({ rowId: row.id, value: cellFromInput(cellText(row.values[index]), type) }); }
        catch (error) { errors.push({ rowId: row.id, message: error.message }); }
    }
    return { changes, errors };
}
export function applyDatasetConversion(source, columnId, type) {
    const table = prepared(source); const index = columnIndex(table, columnId); const preview = previewDatasetConversion(table, columnId, type);
    if (preview.errors.length) { const error = new TypeError('La conversión contiene errores; no se ha cambiado ninguna celda.'); error.errors = preview.errors; throw error; }
    const values = new Map(preview.changes.map(change => [change.rowId, change.value]));
    return mappedRows(table, (value, c, r) => c === index ? values.get(table.rows[r].id) : value);
}
export function createDatasetHistory(initial, { limit = 30 } = {}) {
    if (!Number.isInteger(limit) || limit < 1 || limit > 30) fail('El historial admite de 1 a 30 operaciones.');
    let states = [prepared(initial)]; let index = 0;
    return {
        get table() { return states[index]; }, get canUndo() { return index > 0; }, get canRedo() { return index + 1 < states.length; },
        get undoDepth() { return index; }, get redoDepth() { return states.length - index - 1; }, get limit() { return limit; },
        commit(next) {
            next = prepared(next);
            if (next !== states[index]) {
                states.splice(index + 1); states.push(next);
                if (states.length > limit + 1) states.shift();
                index = states.length - 1;
            }
            return states[index];
        },
        undo() { index = Math.max(0, index - 1); return states[index]; },
        redo() { index = Math.min(states.length - 1, index + 1); return states[index]; },
        reset(next) { states = [prepared(next)]; index = 0; return states[0]; },
    };
}
export function transposeDatasetTable(source, { idFactory } = {}) {
    const table = prepared(source); if (table.rows.length > 99) fail('Transponer conservando encabezados admite hasta 99 filas (100 columnas de salida).');
    const cids = new Set(table.columns.map(column => column.id)); const rids = new Set(table.rows.map(row => row.id));
    const columns = [{ id: newId('c', cids, idFactory), label: 'Columna' }, ...table.rows.map((_, i) => ({ id: newId('c', cids, idFactory), label: `Fila ${i + 1}` }))];
    const rows = table.columns.map((column, c) => ({ id: newId('r', rids, idFactory), values: [column.label, ...table.rows.map(row => row.values[c])] }));
    return finished(columns, rows);
}
function integer(value, min, max, path) {
    const raw = typeof value === 'number' ? String(value) : numberTokens.has(value) ? value.raw : '';
    const result = Number(raw);
    if (!/^(?:0|[1-9]\d*)$/.test(raw) || !Number.isSafeInteger(result) || result < min || result > max) fail(`${path}: entero seguro inválido.`);
    return result;
}
function metadata(value, path = 'Metadatos') {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') return value;
    if (numberTokens.has(value)) return integer(value, 0, Number.MAX_SAFE_INTEGER, path);
    if (Array.isArray(value)) return value.map(child => metadata(child, path));
    if (!value || typeof value !== 'object') fail(`${path}: objeto inválido.`);
    const result = {};
    for (const key of Object.keys(value)) {
        if (['__proto__', 'constructor', 'prototype'].includes(key)) fail(`${path}: clave inválida.`);
        Object.defineProperty(result, key, { value: metadata(value[key], path), enumerable: true });
    }
    return result;
}
const validSha = sha => typeof sha === 'string' && /^[a-f0-9]{64}$/.test(sha);
export function parseDatasetPage(text) {
    inputBudget(text, 8 * 1024 * 1024, '8 MiB de entrada');
    const input = parseLossless(text); keys(input, ['dataset', 'revision', 'sha256', 'columns', 'rows', 'pagination'], 'Página');
    const dataset = metadata(input.dataset);
    if (!dataset || Array.isArray(dataset) || typeof dataset !== 'object') fail('Dataset inválido.');
    integer(dataset.id, 1, Number.MAX_SAFE_INTEGER, 'Dataset'); integer(dataset.brand_id, 1, Number.MAX_SAFE_INTEGER, 'Marca');
    const revision = integer(input.revision, 1, Number.MAX_SAFE_INTEGER, 'Revisión');
    if (integer(dataset.revision, 1, Number.MAX_SAFE_INTEGER, 'Revisión actual') < revision || !validSha(input.sha256)) fail('Snapshot inválido.');
    const table = prepared({ columns: input.columns, rows: input.rows });
    keys(input.pagination, ['offset', 'limit', 'total', 'next_offset'], 'Paginación');
    const pagination = {
        offset: integer(input.pagination.offset, 0, 1000, 'Offset'), limit: integer(input.pagination.limit, 1, 100, 'Límite'),
        total: integer(input.pagination.total, 0, 1000, 'Total'),
        next_offset: input.pagination.next_offset === null ? null : integer(input.pagination.next_offset, 0, 1000, 'Siguiente offset'),
    };
    const next = pagination.offset + table.rows.length;
    if (table.rows.length > pagination.limit || next > pagination.total || pagination.offset > pagination.total
        || (next < pagination.total ? table.rows.length === 0 || pagination.next_offset !== next : pagination.next_offset !== null)) fail('Página incompleta o cursor no contiguo.');
    let rowBytes = 2;
    for (let i = 0; i < table.rows.length; i++) rowBytes += UTF8.encode(JSON.stringify({ id: table.rows[i].id }).slice(0, -1) + ',"values":[' + table.rows[i].values.map(encodeCell).join(',') + ']}').byteLength + (i ? 1 : 0);
    if (rowBytes > 6 * 1024 * 1024) fail('La página supera 6 MiB de filas.');
    return { dataset, revision, sha256: input.sha256, columns: table.columns, rows: table.rows, pagination };
}
export function createDatasetAssembler({ datasetId, revision, sha256 = null }) {
    integer(datasetId, 1, Number.MAX_SAFE_INTEGER, 'Dataset'); integer(revision, 1, Number.MAX_SAFE_INTEGER, 'Revisión');
    if (sha256 !== null && !validSha(sha256)) fail('SHA de snapshot inválido.');
    let nextOffset = 0; let columns = null; let rows = []; let brandId = null; let total = null; let table = null; let complete = false;
    return {
        get nextOffset() { return nextOffset; }, get complete() { return complete; }, get table() { return table; },
        get revision() { return revision; }, get sha256() { return sha256; },
        append(text) {
            if (complete) fail('La tabla ya está completa.');
            const page = parseDatasetPage(text);
            if (page.dataset.id !== datasetId || page.revision !== revision || (sha256 !== null && page.sha256 !== sha256)
                || (brandId !== null && page.dataset.brand_id !== brandId) || (total !== null && page.pagination.total !== total)
                || page.pagination.offset !== nextOffset || (columns !== null && (columns.length !== page.columns.length || columns.some((column, i) => column.id !== page.columns[i].id || column.label !== page.columns[i].label)))) fail('Las páginas no pertenecen al mismo snapshot o no son consecutivas.');
            const candidate = finished(columns ?? page.columns, [...rows, ...page.rows]);
            const finishedPage = page.pagination.next_offset === null;
            // Commit only after all consistency, identity and cumulative byte checks have succeeded.
            columns = candidate.columns; rows = candidate.rows; sha256 = page.sha256; brandId = page.dataset.brand_id; total = page.pagination.total;
            nextOffset = page.pagination.next_offset; complete = finishedPage; if (complete) table = candidate;
            return table;
        },
    };
}
