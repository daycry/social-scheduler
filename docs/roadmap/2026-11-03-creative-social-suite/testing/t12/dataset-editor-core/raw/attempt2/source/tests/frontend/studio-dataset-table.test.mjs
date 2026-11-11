import test from 'node:test';
import assert from 'node:assert/strict';
import * as data from '../../resources/js/Studio/dataset-table.js';
const tableJSON = '{"columns":[{"id":"c1","label":"Valor"}],"rows":[{"id":"r1","values":[0.0]},{"id":"r2","values":[-0.0]},{"id":"r3","values":[9007199254740993]},{"id":"r4","values":["001"]},{"id":"r5","values":[null]}]}';
test('parse preserves decimal lexemes and typed cells', () => {
    const table = data.parseDatasetTable(tableJSON);
    assert.deepEqual(table.columns, [{ id: 'c1', label: 'Valor' }]);
    assert.deepEqual(table.rows.map(row => row.values[0]), [
        { type: 'number', raw: '0.0' }, { type: 'number', raw: '-0.0' }, { type: 'number', raw: '9007199254740993' }, '001', null,
    ]);
});

const sample = () => ({ columns: [{ id: 'c1', label: 'Valor' }], rows: [{ id: 'r1', values: ['001'] }] });
test('schema rejects unknown fields duplicate identities invalid cells and limits', () => {
    const invalid = [
        { ...sample(), extra: 1 }, { columns: {}, rows: [] }, { columns: [], rows: [] },
        { columns: [{ id: 'a', label: '' }, { id: 'a', label: '' }], rows: [] },
        { columns: [{ id: 'bad id', label: '' }], rows: [] },
        { columns: [{ id: 'a', label: '😀'.repeat(501) }], rows: [] },
        { columns: [{ id: 'a', label: '\ud800' }], rows: [] },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: [] }] },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: [true] }] },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: [{ type: 'number', raw: '1', extra: 1 }] }] },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: ['bad\rcontrol'] }] },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: ['😀'.repeat(10001)] }] },
        { columns: Array.from({ length: 101 }, (_, i) => ({ id: `c${i}`, label: '' })), rows: [] },
        { columns: [{ id: 'a', label: '' }], rows: Array.from({ length: 1001 }, (_, i) => ({ id: `r${i}`, values: [''] })) },
        { columns: [{ id: 'a', label: '' }], rows: [{ id: 'r', values: [''] }, { id: 'r', values: [''] }] },
    ];
    for (const table of invalid) assert.throws(() => data.parseDatasetTable(JSON.stringify(table)), TypeError);
});
test('JSON parser rejects duplicate keys rather than overwriting', () => {
    assert.throws(() => data.parseDatasetTable('{"columns":[],"columns":[{"id":"c","label":""}],"rows":[]}'), /duplicada/);
});
test('canonical byte budget rejects oversized shared-string tables without allocation of whole JSON', () => {
    const columns = Array.from({ length: 100 }, (_, i) => ({ id: `c${i}`, label: '' }));
    const row = Array(100).fill('x'.repeat(10000));
    const table = { columns, rows: Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, values: row })) };
    assert.throws(() => data.validateDatasetTable(table), /52.000.000/);
});

test('serializer emits lossless JSON chunks and validates before returning iterator', () => {
    const table = data.parseDatasetTable(tableJSON);
    const chunks = [...data.datasetTableChunks(table)];
    assert.equal(chunks.join(''), tableJSON);
    assert.ok(chunks.length > 2);
    assert.throws(() => data.datasetTableChunks({ columns: [], rows: [] }), TypeError);
    const hostile = data.parseDatasetTable('{"columns":[{"id":"c","label":"\\uFEFFé"}],"rows":[{"id":"r","values":["=HYPERLINK(1)\\n\\t😀\\u2028\\u2029"]}]}');
    assert.deepEqual(data.parseDatasetTable([...data.datasetTableChunks(hostile)].join('')), hostile);
});
test('cell display and explicit conversion preserve text empty and numeric distinctions', () => {
    const number = data.datasetNumber('-0.0');
    assert.equal(data.cellType(number), 'number'); assert.equal(data.cellText(number), '-0.0');
    assert.equal(data.cellType(null), 'empty'); assert.equal(data.cellText(null), '');
    assert.equal(data.cellType(''), 'text'); assert.equal(data.cellType('001'), 'text');
    assert.equal(data.cellFromInput('001', 'text'), '001');
    assert.deepEqual(data.cellFromInput('1e3', 'number'), { type: 'number', raw: '1e3' });
    assert.equal(data.cellFromInput('anything', 'empty'), null);
    for (const invalid of ['', ' 1', '01', '+1', 'NaN', 'Infinity', '1e309', '=1+1']) assert.throws(() => data.cellFromInput(invalid, 'number'), TypeError);
});
test('cell and column edits are immutable by checked identity and share untouched rows', () => {
    const source = data.parseDatasetTable(tableJSON);
    const changed = data.editDatasetCell(source, 'r1', 'c1', 'literal');
    assert.equal(changed.rows[0].values[0], 'literal');
    assert.equal(source.rows[0].values[0].raw, '0.0');
    assert.equal(changed.rows[1], source.rows[1]);
    assert.equal(changed.columns, source.columns);
    assert.throws(() => data.editDatasetCell(source, 'unknown', 'c1', 'x'), /Fila/);
    assert.throws(() => data.editDatasetCell(source, 'r1', 'unknown', 'x'), /Columna/);
    const named = data.renameDatasetColumn(source, 'c1', 'Nuevo');
    assert.equal(named.columns[0].label, 'Nuevo'); assert.equal(source.columns[0].label, 'Valor');
});
test('row and column structure operations keep identities and enforce dimension limits', () => {
    const factory = (kind, index) => `${kind}_${index}`;
    const table = data.blankDatasetTable({ rows: 2, columns: 2, idFactory: factory });
    assert.equal(table.columns.length, 2); assert.equal(table.rows.length, 2);
    assert.deepEqual(table.rows[0].values, [null, null]);
    const row = data.addDatasetRow(table, { afterId: table.rows[0].id, idFactory: factory });
    assert.equal(row.rows.length, 3); assert.equal(row.rows[0], table.rows[0]); assert.equal(row.rows[2], table.rows[1]);
    const column = data.addDatasetColumn(row, { afterId: table.columns[0].id, label: 'Extra', idFactory: factory });
    assert.deepEqual(column.columns.map(c => c.label), ['Columna 1', 'Extra', 'Columna 2']);
    assert.deepEqual(column.rows[0].values, [null, null, null]);
    assert.equal(data.removeDatasetRow(column, row.rows[1].id).rows.length, 2);
    assert.deepEqual(data.removeDatasetColumn(column, column.columns[1].id), row);
    assert.throws(() => data.removeDatasetColumn(data.blankDatasetTable({ columns: 1 }), 'c1'), TypeError);
    assert.throws(() => data.blankDatasetTable({ rows: 1001 }), TypeError);
    assert.throws(() => data.addDatasetColumn(data.blankDatasetTable({ columns: 100 })), TypeError);
    assert.throws(() => data.addDatasetRow(data.blankDatasetTable({ rows: 1000 })), TypeError);
});
test('copy uses new deterministic IDs and retains typed values without source mutation', () => {
    const source = data.parseDatasetTable(tableJSON);
    const copy = data.copyDatasetTable(source, { idFactory: (kind, i) => `copy_${kind}_${i}` });
    assert.notEqual(copy.columns[0].id, source.columns[0].id);
    assert.notEqual(copy.rows[0].id, source.rows[0].id);
    assert.deepEqual(copy.rows.map(row => row.values), source.rows.map(row => row.values));
    assert.equal(source.rows[0].id, 'r1');
});
test('literal replacement and explicit blank filling leave numbers and unrelated columns intact', () => {
    const source = data.parseDatasetTable('{"columns":[{"id":"a","label":"A"},{"id":"b","label":"B"}],"rows":[{"id":"r1","values":["x.x$&",0.0]},{"id":"r2","values":[null,""]}]}');
    const replaced = data.replaceDatasetText(source, '.', '$&', { columnId: 'a' });
    assert.equal(replaced.rows[0].values[0], 'x$&x$&');
    assert.equal(replaced.rows[0].values[1], source.rows[0].values[1]);
    const filled = data.fillDatasetBlanks(source, 'fill');
    assert.deepEqual(filled.rows[1].values, ['fill', '']);
    assert.deepEqual(data.fillDatasetBlanks(source, 'fill', { includeEmptyStrings: true }).rows[1].values, ['fill', 'fill']);
    assert.equal(source.rows[1].values[0], null);
    assert.throws(() => data.replaceDatasetText(source, '', 'x'), TypeError);
});
test('column conversion previews row errors and applies no partial mutation', () => {
    const source = data.parseDatasetTable('{"columns":[{"id":"a","label":"A"}],"rows":[{"id":"r1","values":["1.0"]},{"id":"r2","values":["bad"]}]}');
    const preview = data.previewDatasetConversion(source, 'a', 'number');
    assert.equal(preview.changes.length, 1); assert.equal(preview.errors.length, 1); assert.equal(preview.errors[0].rowId, 'r2');
    assert.throws(() => data.applyDatasetConversion(source, 'a', 'number'), error => error.errors?.[0]?.rowId === 'r2');
    assert.equal(source.rows[0].values[0], '1.0');
    const valid = data.editDatasetCell(source, 'r2', 'a', '2');
    assert.deepEqual(data.applyDatasetConversion(valid, 'a', 'number').rows.map(r => r.values[0]), [data.datasetNumber('1.0'), data.datasetNumber('2')]);
});
test('history uses bounded shared states and supports undo redo branch and reset', () => {
    const initial = data.parseDatasetTable(tableJSON); const history = data.createDatasetHistory(initial, { limit: 2 });
    const one = data.editDatasetCell(initial, 'r1', 'c1', 'one'); history.commit(one);
    assert.equal(history.table.rows[0].values[0], 'one'); assert.equal(history.canUndo, true);
    const two = data.editDatasetCell(one, 'r1', 'c1', 'two'); history.commit(two);
    const three = data.editDatasetCell(two, 'r1', 'c1', 'three'); history.commit(three);
    assert.equal(history.undoDepth, 2); assert.equal(history.table.rows[1], initial.rows[1]);
    assert.equal(history.undo(), two); assert.equal(history.undo(), one); assert.equal(history.canUndo, false);
    assert.equal(history.redo(), two); assert.equal(history.canRedo, true);
    history.commit(three); assert.equal(history.canRedo, false);
    assert.throws(() => history.commit({ columns: [], rows: [] }), TypeError);
    history.reset(initial); assert.equal(history.canUndo, false); assert.equal(history.table.rows[0].values[0].raw, '0.0');
});

test('transpose retains original headers as values and regenerates all identities', () => {
    const source = data.parseDatasetTable('{"columns":[{"id":"a","label":"A"},{"id":"b","label":"B"}],"rows":[{"id":"r1","values":[0.0,"x"]},{"id":"r2","values":[null,"y"]}]}');
    const transposed = data.transposeDatasetTable(source, { idFactory: (kind, i) => `new_${kind}_${i}` });
    assert.deepEqual(transposed.columns.map(column => column.label), ['Columna', 'Fila 1', 'Fila 2']);
    assert.deepEqual(transposed.rows.map(row => row.values), [['A', data.datasetNumber('0.0'), null], ['B', 'x', 'y']]);
    assert.ok(transposed.columns.every(column => !source.columns.some(old => old.id === column.id)));
    assert.ok(transposed.rows.every(row => !source.rows.some(old => old.id === row.id)));
    assert.equal(data.transposeDatasetTable(data.blankDatasetTable({ rows: 0, columns: 2 })).rows.length, 2);
    assert.throws(() => data.transposeDatasetTable(data.blankDatasetTable({ rows: 100 })), /99/);
    assert.deepEqual(source.rows[0].values, [data.datasetNumber('0.0'), 'x']);
});
const pageRaw = ({ offset = 0, next = 1, total = 2, revision = 2, sha256 = 'a'.repeat(64), dataset = 5, brand = 7, currentRevision = 2, rows = '[{"id":"r1","values":[0.0]}]', columns = '[{"id":"c1","label":"Valor"}]' } = {}) =>
    `{"dataset":{"id":${dataset},"brand_id":${brand},"revision":${currentRevision}},"revision":${revision},"sha256":"${sha256}","columns":${columns},"rows":${rows},"pagination":{"offset":${offset},"limit":50,"total":${total},"next_offset":${next === null ? 'null' : next}}}`;
test('page decoder keeps numeric cells lossless and validates safe metadata and contiguous offsets', () => {
    const page = data.parseDatasetPage(pageRaw());
    assert.deepEqual(page.dataset, { id: 5, brand_id: 7, revision: 2 }); assert.equal(page.revision, 2); assert.equal(page.pagination.offset, 0);
    assert.deepEqual(page.rows[0].values[0], data.datasetNumber('0.0'));
    for (const raw of [pageRaw({ next: 0 }), pageRaw({ next: 2 }), pageRaw({ total: 0 }), pageRaw({ dataset: '9007199254740993' }), pageRaw({ sha256: 'bad' }), pageRaw({ revision: 0 })]) assert.throws(() => data.parseDatasetPage(raw), TypeError);
});
test('assembler pins historical revision and hash across complete ordered pages', () => {
    const assembler = data.createDatasetAssembler({ datasetId: 5, revision: 2 });
    assert.equal(assembler.nextOffset, 0); assert.equal(assembler.append(pageRaw()), null);
    assert.equal(assembler.nextOffset, 1); assert.equal(assembler.complete, false);
    const table = assembler.append(pageRaw({ offset: 1, next: null, currentRevision: 3, rows: '[{"id":"r2","values":[9007199254740993]}]' }));
    assert.equal(assembler.complete, true); assert.equal(assembler.nextOffset, null);
    assert.equal(assembler.revision, 2); assert.equal(assembler.sha256, 'a'.repeat(64));
    assert.equal(table.rows[1].values[0].raw, '9007199254740993'); assert.equal(assembler.table, table);
    assert.throws(() => assembler.append(pageRaw()), TypeError);
});
test('assembler rejects mixed brand revision hash columns total gaps cycles and duplicate row IDs atomically', () => {
    const variants = [
        { brand: 8 }, { dataset: 6 }, { revision: 3 }, { sha256: 'b'.repeat(64) }, { total: 3, next: 2 },
        { columns: '[{"id":"c1","label":"Changed"}]' }, { offset: 0, next: 1 }, { rows: '[{"id":"r1","values":[1.0]}]' },
    ];
    for (const change of variants) {
        const assembler = data.createDatasetAssembler({ datasetId: 5, revision: 2, sha256: 'a'.repeat(64) }); assembler.append(pageRaw());
        assert.throws(() => assembler.append(pageRaw({ offset: 1, next: null, rows: '[{"id":"r2","values":[1.0]}]', ...change })), TypeError);
        assert.equal(assembler.nextOffset, 1); assert.equal(assembler.complete, false); assert.equal(assembler.table, null);
        assembler.append(pageRaw({ offset: 1, next: null, rows: '[{"id":"r2","values":[1.0]}]' }));
        assert.equal(assembler.complete, true);
    }
});

test('identity number and hash tokens reject trailing line breaks exactly', () => {
    for (const raw of ['1\n', '1\r', '1\r\n']) assert.throws(() => data.datasetNumber(raw), TypeError);
    assert.throws(() => data.parseDatasetTable('{"columns":[{"id":"c1\\n","label":""}],"rows":[]}'), TypeError);
    assert.throws(() => data.parseDatasetPage(pageRaw({ sha256: 'a'.repeat(64) + '\\n' })), TypeError);
});
test('serializer snapshots caller-owned mutable objects without freezing caller state', () => {
    const source = sample(); const chunks = data.datasetTableChunks(source);
    source.rows[0].values[0] = 'changed'; source.columns[0].label = 'Changed';
    const parsed = data.parseDatasetTable([...chunks].join(''));
    assert.equal(parsed.rows[0].values[0], '001'); assert.equal(parsed.columns[0].label, 'Valor');
    assert.equal(Object.isFrozen(source.rows[0]), false);
});

test('JSON cell objects cannot impersonate internal number tokens', () => {
    const raw = '{"columns":[{"id":"c","label":""}],"rows":[{"id":"r","values":[{"type":"number","raw":"1.0"}]}]}';
    assert.throws(() => data.parseDatasetTable(raw), TypeError);
    assert.throws(() => data.validateDatasetTable({ columns: [{ id: 'c', label: '' }], rows: [{ id: 'r', values: [{ type: 'number', raw: '1.0' }] }] }), TypeError);
});

test('characterization exact Unicode codepoint limits and immutable caller snapshots', () => {
    const source = { columns: [{ id: 'c', label: '😀'.repeat(500) }], rows: [{ id: 'r', values: ['😀'.repeat(10000)] }] };
    assert.equal(data.validateDatasetTable(source), source);
    const history = data.createDatasetHistory(source);
    source.rows[0].values[0] = 'changed'; source.columns[0].label = 'changed';
    assert.equal(history.table.rows[0].values[0], '😀'.repeat(10000));
    assert.equal(history.table.columns[0].label, '😀'.repeat(500));
    assert.equal(Object.isFrozen(source), false);
    const numeric = data.datasetNumber('1e-400'); assert.equal(numeric.raw, '1e-400');
    assert.ok(Object.isFrozen(numeric));
});
test('characterization JSON grammar prototype isolation escaped strings and depth', () => {
    for (const raw of ['null', '[]', '{}', '{"columns":[],"rows":[],}', '{"columns":[{"id":"c","label":""}],"rows":[{"id":"r","values":[01]}]}', '{"columns":[{"id":"c","label":""}],"rows":[{"id":"r","values":[1e309]}]}', '{"__proto__":{"polluted":true},"columns":[{"id":"c","label":""}],"rows":[]}', '['.repeat(18) + '0' + ']'.repeat(18)]) assert.throws(() => data.parseDatasetTable(raw));
    assert.equal({}.polluted, undefined);
    const table = data.parseDatasetTable('{"columns":[{"id":"c","label":"Quote \\""}],"rows":[{"id":"r","values":["backslash \\\\ tab\\tLF\\n"]}]}');
    assert.equal(table.rows[0].values[0], 'backslash \\ tab\tLF\n');
});
test('characterization invalid bulk result and row cursors never mutate original', () => {
    const source = data.parseDatasetTable('{"columns":[{"id":"c","label":""}],"rows":[{"id":"r","values":["abc"]}]}');
    assert.throws(() => data.replaceDatasetText(source, 'a', 'x'.repeat(10000)), TypeError);
    assert.equal(source.rows[0].values[0], 'abc');
    assert.throws(() => data.parseDatasetPage(pageRaw({ dataset: '{"type":"number","raw":"5"}' })), TypeError);
    const empty = data.createDatasetAssembler({ datasetId: 5, revision: 2 });
    assert.deepEqual(empty.append(pageRaw({ total: 0, next: null, rows: '[]' })).rows, []);
});

test('raw table input byte cap is enforced before parsing or whitespace scanning', () => {
    assert.throws(() => data.parseDatasetTable(' '.repeat(52000001)), /52.000.000 bytes de entrada/);
});
test('raw page byte cap counts UTF8 incrementally before decoding schema', () => {
    const raw = '{"padding":"' + 'é'.repeat(4 * 1024 * 1024) + '"}';
    assert.throws(() => data.parseDatasetPage(raw), /8 MiB de entrada/);
});

test('characterization UTF8 budget does not split surrogate pairs at 64KiB chunk boundary', () => {
    const prefix = '{"padding":"';
    const before = 'a'.repeat(65535 - prefix.length);
    const first = prefix + before + '😀';
    const suffix = '"}';
    const remaining = 8 * 1024 * 1024 - new TextEncoder().encode(first + suffix).length;
    const raw = first + 'a'.repeat(remaining) + suffix;
    assert.throws(() => data.parseDatasetPage(raw), /Página: campos inválidos/);
});
test('characterization canonical byte limit accepts exact52M and rejects plus1 by streamed row accounting', () => {
    const columns = Array.from({ length: 100 }, (_, i) => ({ id: `c${i}`, label: '' }));
    const rows = Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, values: Array(100).fill('') }));
    const table = { columns, rows }; const encoder = new TextEncoder();
    const base = [...data.datasetTableChunks(table)].reduce((sum, chunk) => sum + encoder.encode(chunk).length, 0);
    let remaining = 52000000 - base; const full = 'x'.repeat(10000);
    for (const row of rows) for (let col = 0; col < row.values.length && remaining; col++) {
        const amount = Math.min(10000, remaining); row.values[col] = amount === 10000 ? full : 'x'.repeat(amount); remaining -= amount;
    }
    assert.equal(remaining, 0); assert.equal(data.validateDatasetTable(table), table);
    rows.at(-1).values[99] += 'x';
    assert.throws(() => data.validateDatasetTable(table), /52.000.000/);
});

test('regression sparse values are rejected before validation serialization and history', () => {
    const source = { columns: [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }], rows: [{ id: 'r', values: ['keep', 'lost'] }] };
    delete source.rows[0].values[1];
    assert.throws(() => data.validateDatasetTable(source), TypeError);
    assert.throws(() => data.datasetTableChunks(source), TypeError);
    assert.throws(() => data.createDatasetHistory(source), TypeError);
});
test('regression inherited array indices do not substitute own dense JSON data', () => {
    const values = ['keep', 'lost']; delete values[1];
    Object.setPrototypeOf(values, Object.assign(Object.create(Array.prototype), { 1: 'inherited' }));
    const source = { columns: [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }], rows: [{ id: 'r', values }] };
    assert.throws(() => data.validateDatasetTable(source), TypeError);
    for (const field of ['columns', 'rows']) {
        const table = sample(); delete table[field][0];
        Object.setPrototypeOf(table[field], Object.assign(Object.create(Array.prototype), { 0: field === 'columns' ? { id: 'c', label: '' } : { id: 'r', values: ['inherited'] } }));
        assert.throws(() => data.validateDatasetTable(table), TypeError);
    }
});
test('regression frozen external getters are rejected without evaluation or snapshot aliasing', () => {
    let label = 'Before'; let calls = 0;
    const column = Object.freeze({ id: 'c', get label() { calls++; return label; } });
    const source = { columns: Object.freeze([column]), rows: [{ id: 'r', values: ['x'] }] };
    assert.throws(() => data.datasetTableChunks(source), TypeError);
    assert.throws(() => data.createDatasetHistory(source), TypeError);
    label = 'After\r';
    assert.equal(calls, 0);
});
test('regression accessors in records and list slots are rejected before invoking user code', () => {
    let calls = 0;
    const source = sample();
    Object.defineProperty(source.rows[0].values, '0', { enumerable: true, get() { calls++; return 'x'; } });
    assert.throws(() => data.validateDatasetTable(source), TypeError);
    assert.equal(calls, 0);
    const rowSource = sample(); Object.defineProperty(rowSource.rows[0], 'id', { enumerable: true, get() { calls++; return 'r'; } });
    assert.throws(() => data.createDatasetHistory(rowSource), TypeError);
    const tableSource = sample(); Object.defineProperty(tableSource, 'rows', { enumerable: true, get() { calls++; return []; } });
    assert.throws(() => data.datasetTableChunks(tableSource), TypeError);
    assert.equal(calls, 0);
});
