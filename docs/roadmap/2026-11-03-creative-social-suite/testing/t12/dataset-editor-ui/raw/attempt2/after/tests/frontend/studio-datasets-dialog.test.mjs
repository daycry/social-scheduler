import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import * as realCore from '../../resources/js/Studio/dataset-table.js';
import { computed, ref, shallowRef, watch, reactive, nextTick, effectScope } from 'vue';

const blank = () => ({ columns: [{ id: 'c1', label: 'Columna 1' }], rows: [{ id: 'r1', values: [''] }] });
const fakeCore = {
    blankDatasetTable: blank,
    createDatasetHistory(initial) {
        let table = initial; const undo = [], redo = [];
        return { get table() { return table; }, get canUndo() { return undo.length > 0; }, get canRedo() { return redo.length > 0; }, limit: 30,
            commit(next) { undo.push(table); table = next; redo.length = 0; }, reset(next) { table = next; undo.length = redo.length = 0; },
            undo() { if (undo.length) { redo.push(table); table = undo.pop(); } return table; }, redo() { if (redo.length) { undo.push(table); table = redo.pop(); } return table; },
        };
    },
};
function mount(client = {}, initial = {}, core = fakeCore) {
    const source = readFileSync(new URL('../../resources/js/Components/Studio/StudioDatasetsDialog.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'datasets-dialog-regression' }).content.replace(/^import .*?;\r?$/gm, '').replace('export default', 'return');
    const downloads = [], windowListeners = new Map();
    const fakeWindow = { addEventListener: (name, fn) => windowListeners.set(name, fn), removeEventListener: name => windowListeners.delete(name) };
    const props = reactive({ modelValue: true, brandId: 3, brandName: 'Marca A', ...initial }), hooks = [], emitted = [];
    const defaults = { list: async () => ({ datasets: [], pagination: { current_page: 1, last_page: 1, total: 0 } }), ...client };
    const factory = new Function('computed', 'nextTick', 'onBeforeUnmount', 'ref', 'shallowRef', 'watch', 'createDatasetClient', 'tableCore', 'downloadStudioBlob', 'window', compiled);
    const component = factory(computed, nextTick, fn => hooks.push(fn), ref, shallowRef, watch, () => defaults, core, (...args) => downloads.push(args), fakeWindow);
    const scope = effectScope(); const state = scope.run(() => component.setup(props, { expose() {}, emit: (...args) => emitted.push(args) }));
    return { state, props, emitted, downloads, windowListeners, close() { hooks.forEach(hook => hook()); scope.stop(); } };
}
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); };

test('dialog creates a local typed table with unsaved state without posting a blank resource', async () => {
    const panel = mount();
    try { panel.state.newTable(); assert.equal(panel.state.table.value.columns[0].id, 'c1'); assert.equal(panel.state.selected.value, null); assert.equal(panel.state.name.value, 'Tabla sin título'); assert.equal(panel.state.dirty.value, true); }
    finally { panel.close(); }
});

test('dialog lists on open and fences an old brand response even when transport ignores abort', async () => {
    const requests = []; const panel = mount({ list: (brand, options) => new Promise(resolve => requests.push({ brand, options, resolve })) });
    try {
        await flush(); assert.equal(requests.length, 1); assert.equal(requests[0].brand, 3);
        panel.props.brandId = 9; await flush(); assert.equal(requests[0].options.signal.aborted, true); assert.equal(requests.length, 2);
        requests[1].resolve({ datasets: [{ id: 90, name: 'Marca B' }], pagination: { current_page: 1, last_page: 1, total: 1 } }); await flush();
        requests[0].resolve({ datasets: [{ id: 30, name: 'Marca A' }], pagination: { current_page: 1, last_page: 1, total: 1 } }); await flush();
        assert.deepEqual(panel.state.datasets.value.map(row => row.id), [90]); assert.equal(panel.state.error.value, '');
    } finally { panel.close(); for (const request of requests) request.resolve({ datasets: [] }); }
});

test('dialog close asks to discard dirty data and cancellation retains the draft', async () => {
    const panel = mount();
    try {
        panel.state.newTable(); const original = panel.state.table.value;
        panel.state.requestClose(); assert.ok(panel.state.pendingDiscard.value); assert.equal(panel.emitted.length, 0);
        panel.state.cancelDiscard(); assert.equal(panel.state.pendingDiscard.value, null); assert.equal(panel.state.table.value, original);
        panel.state.requestClose(); panel.state.confirmDiscard();
        assert.deepEqual(panel.emitted, [['update:modelValue', false]]);
    } finally { panel.close(); }
});

test('dialog selection aborts the old table and refuses a late result after creating a new draft', async () => {
    const loads = []; const panel = mount({ load: (brand, id, revision, options) => new Promise(resolve => loads.push({ brand, id, revision, options, resolve })) });
    try {
        const open = panel.state.selectDataset({ id: 7, name: 'Anterior', revision: 2 }); await flush();
        assert.equal(loads.length, 1); panel.state.newTable(); assert.equal(loads[0].options.signal.aborted, true);
        loads[0].resolve({ table: { columns: [{ id: 'foreign', label: 'Old' }], rows: [] }, revision: 2, sha256: 'a'.repeat(64) }); await open; await flush();
        assert.equal(panel.state.selected.value, null); assert.equal(panel.state.table.value.columns[0].id, 'c1'); assert.equal(panel.state.name.value, 'Tabla sin título');
    } finally { panel.close(); loads.forEach(load => load.resolve({ table: blank(), revision: 2, sha256: 'a'.repeat(64) })); }
});

test('dialog commits explicitly typed cells and undo redo through the shared immutable history', async () => {
    const core = { ...realCore, blankDatasetTable: blank }; const panel = mount({}, {}, core);
    try {
        panel.state.newTable(); panel.state.openCell('r1', 'c1');
        panel.state.cellDraft.value = '-0.0'; panel.state.cellTypeDraft.value = 'number'; panel.state.commitCell();
        assert.equal(realCore.cellText(panel.state.table.value.rows[0].values[0]), '-0.0');
        assert.equal(realCore.cellType(panel.state.table.value.rows[0].values[0]), 'number');
        panel.state.undoTable('undo'); assert.equal(panel.state.table.value.rows[0].values[0], '');
        panel.state.undoTable('redo'); assert.equal(realCore.cellText(panel.state.table.value.rows[0].values[0]), '-0.0');
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = '01'; panel.state.commitCell();
        assert.ok(panel.state.error.value); assert.equal(realCore.cellText(panel.state.table.value.rows[0].values[0]), '-0.0');
    } finally { panel.close(); }
});

test('dialog preserves its edited draft after a revision conflict without silently reloading', async () => {
    let saves = 0; const panel = mount({ load: async () => ({ table: blank(), revision: 2, sha256: 'a'.repeat(64) }),
        save: async () => { saves++; throw Object.assign(new Error('Dataset revision conflict.'), { status: 409 }); } });
    try {
        await panel.state.selectDataset({ id: 7, name: 'Guardada', revision: 2 }); panel.state.name.value = 'Mi borrador'; const draft = panel.state.table.value;
        await panel.state.saveTable(); assert.equal(saves, 1); assert.equal(panel.state.conflict.value, true);
        assert.equal(panel.state.name.value, 'Mi borrador'); assert.equal(panel.state.table.value, draft); assert.equal(panel.state.dirty.value, true); assert.equal(panel.state.selected.value.revision, 2);
    } finally { panel.close(); }
});
test('dialog serializes writes and fences a late save across close and brand change without cancelling it', async () => {
    let finish; const saves = []; const panel = mount({ save: (brand, id, payload, options) => { saves.push({ brand, id, payload, options }); return new Promise(resolve => { finish = resolve; }); } });
    try {
        panel.state.newTable(); const pending = panel.state.saveTable(); await flush();
        assert.equal(saves.length, 1); await panel.state.saveTable(); assert.equal(saves.length, 1);
        panel.props.modelValue = false; panel.props.brandId = 9; await flush(); panel.props.modelValue = true; await flush(); panel.state.newTable();
        finish({ dataset: { id: 7, name: 'Old saved', revision: 1 } }); await pending; await flush();
        assert.equal(panel.state.selected.value, null); assert.equal(panel.state.name.value, 'Tabla sin título');
        assert.equal(panel.state.writeBusy.value, false); assert.equal(saves[0].options, undefined); assert.equal(panel.emitted.some(event => event[0] === 'changed'), false);
    } finally { panel.close(); if (finish) finish({ dataset: { id: 7, revision: 1 } }); }
});

test('dialog reads paginated revision metadata and historic tables stay read only until explicit current reload', async () => {
    const loads = [], historyCalls = []; const panel = mount({
        load: async (brand, id, revision) => { loads.push(revision); return { table: blank(), revision, sha256: 'a'.repeat(64) }; },
        history: async (brand, id, options) => { historyCalls.push({ brand, id, options }); return { revisions: [{ revision: 1, name: 'Original' }], pagination: { current_page: 2, last_page: 3, total: 41 } }; },
        get: async () => ({ dataset: { id: 7, name: 'Actual', revision: 3 } }),
    });
    try {
        const dataset = { id: 7, name: 'Actual', revision: 2 }; await panel.state.selectDataset(dataset);
        await panel.state.showHistory(2); assert.equal(historyCalls[0].options.page, 2); assert.equal(panel.state.revisions.value[0].revision, 1);
        await panel.state.selectDataset(dataset, 1, 'Original'); assert.equal(panel.state.viewingHistory.value, true); assert.equal(panel.state.canEdit.value, false);
        await panel.state.reloadSaved(); assert.deepEqual(loads, [2, 1, 3]); assert.equal(panel.state.viewingHistory.value, false); assert.equal(panel.state.name.value, 'Actual');
    } finally { panel.close(); }
});

test('dialog structural tools add rename and remove the selected entities', async () => {
    const panel = mount({}, {}, { ...realCore, blankDatasetTable: blank });
    try {
        panel.state.newTable(); panel.state.structure('add-row'); panel.state.structure('add-column');
        assert.equal(panel.state.table.value.rows.length, 2); assert.equal(panel.state.table.value.columns.length, 2);
        panel.state.renameColumn('c1', 'Cantidad'); assert.equal(panel.state.table.value.columns[0].label, 'Cantidad');
        panel.state.selectedRowId.value = panel.state.table.value.rows[1].id; panel.state.structure('remove-row'); assert.equal(panel.state.table.value.rows.length, 1);
        panel.state.selectedColumnId.value = panel.state.table.value.columns[1].id; panel.state.structure('remove-column'); assert.equal(panel.state.table.value.columns.length, 1);
    } finally { panel.close(); }
});
const loadedPanel = async (values, labels = ['Cantidad', 'Otro']) => {
    const table = { columns: values[0].map((_, index) => ({ id: `c${index + 1}`, label: labels[index] })), rows: values.map((values, index) => ({ id: `r${index + 1}`, values })) };
    const panel = mount({ load: async () => ({ table, revision: 2, sha256: 'a'.repeat(64) }) }, {}, realCore);
    await panel.state.selectDataset({ id: 7, name: 'Datos', revision: 2 }); return panel;
};
test('dialog numeric conversion previews row errors and refuses partial application', async () => {
    const panel = await loadedPanel([['10'], ['bad']]);
    try {
        panel.state.previewConversion(); assert.equal(panel.state.conversionPreview.value.errors.length, 1);
        const original = panel.state.table.value; panel.state.applyConversion(); assert.equal(panel.state.table.value, original);
        panel.state.openCell('r2', 'c1'); panel.state.cellDraft.value = '30'; panel.state.commitCell();
        panel.state.previewConversion(); assert.equal(panel.state.conversionPreview.value.errors.length, 0); panel.state.applyConversion();
        assert.equal(realCore.cellType(panel.state.table.value.rows[1].values[0]), 'number');
    } finally { panel.close(); }
});
test('dialog literal replacement and blank filling preserve types and distinguish null from empty text', async () => {
    const panel = await loadedPanel([['bad', null], ['bad', '']]);
    try {
        panel.state.searchText.value = 'bad'; panel.state.replacementText.value = '30'; panel.state.replaceText();
        assert.equal(panel.state.table.value.rows[0].values[0], '30'); assert.equal(realCore.cellType(panel.state.table.value.rows[0].values[0]), 'text');
        panel.state.fillText.value = 'Vacío'; panel.state.fillBlanks();
        assert.equal(panel.state.table.value.rows[0].values[1], 'Vacío'); assert.equal(panel.state.table.value.rows[1].values[1], '');
        panel.state.includeEmptyStrings.value = true; panel.state.fillBlanks(); assert.equal(panel.state.table.value.rows[1].values[1], 'Vacío');
    } finally { panel.close(); }
});
test('dialog transpose requires preview confirmation preserves headers and is undoable', async () => {
    const panel = await loadedPanel([['10', '20'], ['30', '40']]);
    try {
        const original = panel.state.table.value; panel.state.transposeTable(); assert.equal(panel.state.table.value, original); assert.equal(panel.state.transposePreview.value, true);
        panel.state.confirmTranspose(); assert.equal(panel.state.table.value.columns[0].label, 'Columna'); assert.equal(panel.state.table.value.rows[0].values[0], 'Cantidad');
        panel.state.undoTable('undo'); assert.equal(panel.state.table.value, original);
    } finally { panel.close(); }
});

test('dialog imports the selected original file then opens the created fixed revision', async () => {
    const uploads = [], loads = [], file = new Blob(['A,B\n001,=SUM(A1)\n']);
    const panel = mount({ upload: async (brand, payload) => { uploads.push({ brand, payload }); return { dataset: { id: 8, name: 'Original', revision: 1 } }; },
        load: async (brand, id, revision) => { loads.push({ brand, id, revision }); return { table: blank(), revision, sha256: 'a'.repeat(64) }; } });
    try {
        panel.state.pickFile({ target: { files: [file] } }); panel.state.importName.value = 'Original'; panel.state.importHeader.value = false;
        await panel.state.importData(); assert.equal(uploads.length, 1); assert.equal(uploads[0].payload.file, file); assert.equal(uploads[0].payload.header, false);
        assert.deepEqual(loads, [{ brand: 3, id: 8, revision: 1 }]); assert.equal(panel.state.importFile.value, null); assert.equal(panel.state.selected.value.id, 8);
    } finally { panel.close(); }
});
test('dialog archive and restore update metadata and enforce archive read only state', async () => {
    const actions = []; const panel = mount({ load: async () => ({ table: blank(), revision: 2, sha256: 'a'.repeat(64) }),
        control: async (brand, id, action) => { actions.push(action); return { dataset: { id, name: 'Datos', revision: 2, archived_at: action === 'archive' ? '2026-11-06' : null } }; } });
    try {
        await panel.state.selectDataset({ id: 7, name: 'Datos', revision: 2, archived_at: null }); await panel.state.mutateDataset('archive');
        assert.equal(panel.state.canEdit.value, false); assert.ok(panel.state.selected.value.archived_at);
        await panel.state.mutateDataset('restore'); assert.equal(panel.state.canEdit.value, true); assert.deepEqual(actions, ['archive', 'restore']);
    } finally { panel.close(); }
});
test('dialog copies a table into a new unsaved draft and downloads exact typed JSON locally', async () => {
    const panel = await loadedPanel([['10', null]]);
    try {
        const original = panel.state.table.value; panel.state.copyTable(); assert.equal(panel.state.selected.value, null); assert.notEqual(panel.state.table.value.rows[0].id, original.rows[0].id);
        panel.state.openCell(panel.state.table.value.rows[0].id, panel.state.table.value.columns[0].id); panel.state.cellDraft.value = '-0.0'; panel.state.cellTypeDraft.value = 'number'; panel.state.commitCell();
        panel.state.downloadDraft(); assert.equal(panel.downloads.length, 1); const raw = await panel.downloads[0][0].text(); assert.ok(raw.includes('[-0.0,null]'));
    } finally { panel.close(); }
});

test('dialog treats unapplied cell input as dirty and refuses to save a draft that would lose that input', async () => {
    let saves = 0; const panel = mount({ load: async () => ({ table: blank(), revision: 2, sha256: 'a'.repeat(64) }), save: async () => { saves++; return { dataset: { id: 7, revision: 3 } }; } }, {}, realCore);
    try {
        await panel.state.selectDataset({ id: 7, name: 'Datos', revision: 2 }); panel.state.openCell('r1', 'c1');
        panel.state.cellDraft.value = 'Texto pendiente'; assert.equal(panel.state.dirty.value, true);
        panel.state.requestClose(); assert.ok(panel.state.pendingDiscard.value); panel.state.cancelDiscard();
        await panel.state.saveTable(); assert.equal(saves, 0); assert.match(panel.state.error.value, /celda/i); assert.equal(panel.state.cellDraft.value, 'Texto pendiente');
    } finally { panel.close(); }
});

test('dialog stages a header without losing it on close or saving the previous header silently', async () => {
    const panel = await loadedPanel([['10']]);
    try {
        panel.state.stageColumnLabel('c1', 'Nuevo encabezado'); assert.equal(panel.state.dirty.value, true);
        panel.state.requestClose(); assert.ok(panel.state.pendingDiscard.value); panel.state.cancelDiscard();
        assert.equal(panel.state.columnDrafts.value.c1, 'Nuevo encabezado');
        await panel.state.saveTable(); assert.match(panel.state.error.value, /encabezado/i);
        panel.state.renameColumn('c1', 'Nuevo encabezado'); assert.equal(panel.state.columnDrafts.value.c1, undefined); assert.equal(panel.state.table.value.columns[0].label, 'Nuevo encabezado');
    } finally { panel.close(); }
});
test('dialog browser reload warns for a dirty visible draft and removes its handler on unmount', () => {
    const panel = mount(); let prevented = 0;
    try {
        panel.state.newTable(); const handler = panel.windowListeners.get('beforeunload'); assert.equal(typeof handler, 'function');
        handler({ preventDefault() { prevented++; }, returnValue: null }); assert.equal(prevented, 1);
        panel.props.modelValue = false; handler({ preventDefault() { prevented++; } }); assert.equal(prevented, 1);
    } finally { panel.close(); }
    assert.equal(panel.windowListeners.has('beforeunload'), false);
});

test('dialog renders bounded row and column windows without dropping data from its editor state', async () => {
    const values = Array.from({ length: 45 }, (_, row) => Array.from({ length: 10 }, (_, column) => `${row}:${column}`));
    const panel = await loadedPanel(values, Array.from({ length: 10 }, (_, index) => `C${index}`));
    try {
        assert.equal(panel.state.visibleRows.value.length, 20); assert.equal(panel.state.visibleColumns.value.length, 8);
        panel.state.rowPage.value = 2; panel.state.columnPage.value = 1;
        assert.equal(panel.state.visibleRows.value.length, 5); assert.equal(panel.state.visibleRows.value[0].id, 'r41');
        assert.equal(panel.state.visibleColumns.value.length, 2); assert.equal(panel.state.table.value.rows.length, 45);
    } finally { panel.close(); }
});
test('dialog refuses cell switching or undo while uncommitted input would be lost', async () => {
    const panel = await loadedPanel([['A', 'B']]);
    try {
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = 'Guardado en borrador'; panel.state.commitCell();
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = 'Pendiente';
        panel.state.openCell('r1', 'c2'); assert.equal(panel.state.cellDraft.value, 'Pendiente'); assert.equal(panel.state.cellEditor.value.columnId, 'c1');
        panel.state.undoTable('undo'); assert.equal(panel.state.cellDraft.value, 'Pendiente'); assert.equal(panel.state.table.value.rows[0].values[0], 'Guardado en borrador');
    } finally { panel.close(); }
});

test('dialog undo preserves pending cell input until it is applied or cancelled', async () => {
    const panel = await loadedPanel([['A']]);
    try {
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = 'B'; panel.state.commitCell();
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = 'Pendiente'; panel.state.undoTable('undo');
        assert.equal(panel.state.table.value.rows[0].values[0], 'B'); assert.equal(panel.state.cellDraft.value, 'Pendiente'); assert.ok(panel.state.cellEditor.value);
    } finally { panel.close(); }
});

test('dialog and its two existing entry pages compile with Vue script and template compilers', () => {
    for (const name of ['Components/Studio/StudioDatasetsDialog.vue', 'Pages/Studio/Index.vue', 'Pages/Studio/Editor.vue']) {
        const { descriptor, errors } = parse(readFileSync(new URL(`../../resources/js/${name}`, import.meta.url), 'utf8'));
        assert.deepEqual(errors, []); const script = compileScript(descriptor, { id: name });
        const result = compileTemplate({ id: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(result.errors, [], name);
    }
});

test('dialog focuses the type control for an empty cell and its full value editor for text', async () => {
    const panel = await loadedPanel([[null, 'Complete text']]); let types = 0, values = 0;
    try {
        panel.state.cellTypeInput.value = { focus() { types++; } }; panel.state.cellInput.value = { focus() { values++; } };
        panel.state.openCell('r1', 'c1'); await flush(); assert.equal(types, 1); assert.equal(values, 0);
        panel.state.openCell('r1', 'c2'); await flush(); assert.equal(values, 1);
    } finally { panel.close(); }
});

test('dialog header drafts use own ID keys even for prototype named column IDs', async () => {
    const table = { columns: [{ id: '__proto__', label: 'Encabezado' }], rows: [{ id: 'constructor', values: ['Texto'] }] };
    const panel = mount({ load: async () => ({ table, revision: 1, sha256: 'a'.repeat(64) }) }, {}, realCore);
    try {
        await panel.state.selectDataset({ id: 7, name: 'IDs válidos', revision: 1 });
        assert.equal(panel.state.columnDrafts.value.constructor, undefined);
        panel.state.stageColumnLabel('__proto__', 'Editado'); assert.equal(panel.state.columnDrafts.value.__proto__, 'Editado'); assert.equal(panel.state.dirty.value, true);
        panel.state.renameColumn('__proto__', 'Editado'); assert.equal(panel.state.table.value.columns[0].label, 'Editado'); assert.equal(Object.getPrototypeOf(panel.state.columnDrafts.value), null);
    } finally { panel.close(); }
});

test('dialog save invalidates a rendered dirty cache after an applied cell edit without renaming', async () => {
    const saves = []; const panel = mount({
        load: async () => ({ table: blank(), revision: 2, sha256: 'a'.repeat(64) }),
        save: async (brand, id, payload) => { saves.push({ brand, id, payload }); return { dataset: { id: 7, name: 'Datos', revision: 3, row_count: 1, column_count: 1 } }; },
    }, {}, realCore);
    try {
        await panel.state.selectDataset({ id: 7, name: 'Datos', revision: 2 });
        assert.equal(panel.state.dirty.value, false);
        panel.state.openCell('r1', 'c1'); panel.state.cellDraft.value = '-0.0'; panel.state.cellTypeDraft.value = 'number'; panel.state.commitCell();
        assert.equal(panel.state.name.value, 'Datos');
        assert.equal(panel.state.dirty.value, true, 'read the dirty computed before saving, as the rendered template does');
        await panel.state.saveTable();
        assert.equal(saves.length, 1); assert.equal(saves[0].id, 7); assert.equal(saves[0].payload.revision, 2); assert.equal(saves[0].payload.name, 'Datos');
        assert.equal(realCore.cellText(saves[0].payload.table.rows[0].values[0]), '-0.0');
        assert.equal(panel.state.revisionView.value, 3); assert.match(panel.state.status.value, /Revisión 3 guardada/);
        assert.equal(panel.state.dirty.value, false, 'saving the same name must invalidate the cached dirty state');
        panel.state.undoTable('undo'); assert.equal(panel.state.dirty.value, true);
        panel.state.undoTable('redo'); assert.equal(panel.state.dirty.value, false);
        panel.state.requestClose(); assert.equal(panel.state.pendingDiscard.value, null);
        assert.deepEqual(panel.emitted.at(-1), ['update:modelValue', false]);
    } finally { panel.close(); }
});
