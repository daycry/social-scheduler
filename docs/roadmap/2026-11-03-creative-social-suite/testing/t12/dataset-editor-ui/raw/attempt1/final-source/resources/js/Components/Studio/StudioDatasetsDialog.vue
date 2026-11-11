<script setup>
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { downloadStudioBlob } from '@/Studio/api';
import { createDatasetClient } from '@/Studio/dataset-client';
import * as tableCore from '@/Studio/dataset-table';

const props = defineProps({ modelValue: Boolean, brandId: Number, brandName: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue', 'changed']);
const client = createDatasetClient({ createAssembler: tableCore.createDatasetAssembler, tableChunks: tableCore.datasetTableChunks });
const dialog = ref(null);
const pendingDiscard = shallowRef(null);
const discardButton = ref(null);
let lastActionFocus = null;
function askDiscard(run) {
    if (!dirty.value) return run();
    lastActionFocus = typeof document === 'undefined' ? null : document.activeElement;
    pendingDiscard.value = { run, context };
    nextTick(() => discardButton.value?.focus());
}
function requestClose() { askDiscard(() => { invalidateReads(); emit('update:modelValue', false); }); }
function confirmDiscard() {
    const action = pendingDiscard.value; pendingDiscard.value = null;
    if (!action || action.context !== context) return;
    cellEditor.value = null; columnDrafts.value = Object.create(null);
    if (savedTable) { history.reset(savedTable); table.value = history.table; name.value = savedName.value; historyVersion.value++; }
    return action.run();
}
function cancelDiscard() { pendingDiscard.value = null; nextTick(() => lastActionFocus?.focus()); }
const datasets = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const query = ref('');
const error = ref('');
const status = ref('');
const listBusy = ref(false);
const readBusy = ref(false);
const writeBusy = ref(false);
let context = 0;
let disposed = false;
const requests = new Map();
function invalidateReads() {
    context++; requests.forEach(ticket => ticket.abort.abort()); requests.clear();
    listBusy.value = readBusy.value = historyBusy.value = false;
}
function ticketFor(channel) {
    requests.get(channel)?.abort.abort();
    const ticket = { context, brandId: props.brandId, abort: new AbortController() };
    requests.set(channel, ticket); return ticket;
}
function current(channel, ticket) {
    return !disposed && props.modelValue && ticket.context === context && ticket.brandId === props.brandId && requests.get(channel) === ticket;
}
async function loadList(page = 1) {
    if (!props.modelValue || !props.brandId) return;
    const ticket = ticketFor('list'); listBusy.value = true; error.value = '';
    try {
        const result = await client.list(ticket.brandId, { query: query.value.trim(), page, signal: ticket.abort.signal });
        if (!current('list', ticket)) return;
        datasets.value = result.datasets ?? []; pagination.value = result.pagination ?? { current_page: 1, last_page: 1, total: datasets.value.length };
    } catch (e) { if (current('list', ticket) && e.name !== 'AbortError') error.value = e.message; }
    finally { if (current('list', ticket)) { requests.delete('list'); listBusy.value = false; } }
}
const table = shallowRef(null);
const selected = ref(null);
const revisionView = ref(null);
const sha256 = ref('');
const conflict = ref(false);
const revisions = ref([]);
const historyPagination = ref({ current_page: 1, last_page: 1, total: 0 });
const historyOpen = ref(false);
const downloadFormat = ref('csv');
const downloadMode = ref('spreadsheet');
const downloadUrl = computed(() => selected.value && revisionView.value ? client.exportUrl(props.brandId, selected.value.id, { revision: revisionView.value, format: downloadFormat.value, mode: downloadMode.value }) : '');
const historyBusy = ref(false);
const importFile = shallowRef(null);
const importName = ref('');
const importFormat = ref('csv');
const importHeader = ref(true);
const fileInput = ref(null);
function pickFile(event) {
    importFile.value = event.target.files?.[0] ?? null;
    const filename = importFile.value?.name ?? '';
    if (!importName.value && filename) importName.value = filename.replace(/\.[^.]+$/, '');
    const extension = filename.split('.').at(-1)?.toLowerCase();
    if (['csv', 'tsv', 'json'].includes(extension)) importFormat.value = extension;
}
function importData() {
    if (writeBusy.value || !importFile.value) return;
    return askDiscard(async () => {
        const uploadName = importName.value.trim();
        if (!uploadName || [...uploadName].length > 255) { error.value = 'Escribe un nombre de hasta 255 caracteres para la importación.'; return; }
        const ticket = writeTicket(), payload = { file: importFile.value, name: uploadName, format: importFormat.value, header: importHeader.value };
        writeBusy.value = true; error.value = ''; status.value = 'Importando archivo…';
        try {
            const result = await client.upload(ticket.brandId, payload);
            if (!currentWrite(ticket)) return;
            importFile.value = null; importName.value = ''; if (fileInput.value) fileInput.value.value = '';
            emit('changed', result.dataset); loadList(); await loadDataset(result.dataset, result.dataset.revision);
        } catch (e) { if (currentWrite(ticket)) { error.value = e.message; status.value = ''; } }
        finally { writeBusy.value = false; }
    });
}
function mutateDataset(action) {
    if (writeBusy.value || !selected.value) return;
    return askDiscard(async () => {
        const ticket = writeTicket(), id = selected.value.id;
        writeBusy.value = true; error.value = ''; status.value = action === 'archive' ? 'Archivando tabla…' : 'Restaurando tabla…';
        try {
            const result = await client.control(ticket.brandId, id, action);
            if (!currentWrite(ticket)) return;
            selected.value = result.dataset; emit('changed', result.dataset); loadList(pagination.value.current_page);
            status.value = action === 'archive' ? 'Tabla archivada. Sus revisiones se conservan.' : 'Tabla restaurada.';
        } catch (e) { if (currentWrite(ticket)) { error.value = e.message; status.value = ''; } }
        finally { writeBusy.value = false; }
    });
}
function copyTable() {
    if (!table.value || readBusy.value || writeBusy.value) return;
    try {
        const copied = tableCore.copyDatasetTable(table.value), copiedName = `${name.value} — copia`;
        return askDiscard(() => {
            stopSelectionReads(); selected.value = null; revisionView.value = null; sha256.value = ''; conflict.value = false;
            history = tableCore.createDatasetHistory(copied); table.value = history.table; savedTable = null;
            name.value = copiedName; savedName.value = ''; historyVersion.value++; cellEditor.value = null; syncSelection();
        });
    } catch (e) { error.value = e.message; }
}
function downloadDraft() {
    if (!table.value) return;
    if (cellPending.value || headerPending.value) { error.value = 'Aplica o cancela la edición de la celda o encabezado antes de descargar el borrador.'; return; }
    try {
        downloadStudioBlob(new Blob([...tableCore.datasetTableChunks(table.value)], { type: 'application/json' }), `tabla-${selected.value?.id ?? 'nueva'}-borrador.json`);
    } catch (e) { error.value = e.message; }
}

async function showHistory(page = 1) {
    if (!selected.value || !props.modelValue) return;
    const ticket = ticketFor('history'), id = selected.value.id;
    historyOpen.value = true; historyBusy.value = true; error.value = '';
    try {
        const result = await client.history(ticket.brandId, id, { page, signal: ticket.abort.signal });
        if (!current('history', ticket)) return;
        revisions.value = result.revisions ?? []; historyPagination.value = result.pagination;
    } catch (e) { if (current('history', ticket) && e.name !== 'AbortError') error.value = e.message; }
    finally { if (current('history', ticket)) { requests.delete('history'); historyBusy.value = false; } }
}
function reloadSaved() {
    if (!selected.value || writeBusy.value) return;
    return askDiscard(async () => {
        stopSelectionReads(); const ticket = ticketFor('table'), id = selected.value.id; readBusy.value = true; error.value = '';
        try {
            const result = await client.get(ticket.brandId, id, { signal: ticket.abort.signal });
            if (current('table', ticket)) await loadDataset(result.dataset, result.dataset.revision);
        } catch (e) { if (current('table', ticket) && e.name !== 'AbortError') error.value = e.message; }
        finally { if (current('table', ticket)) { requests.delete('table'); readBusy.value = false; } }
    });
}

let editorEpoch = 0;
function writeTicket() { return { context, brandId: props.brandId, editorEpoch }; }
function currentWrite(ticket) { return !disposed && props.modelValue && ticket.context === context && ticket.brandId === props.brandId && ticket.editorEpoch === editorEpoch; }
async function saveTable() {
    if (!canEdit.value || writeBusy.value || !dirty.value) return;
    if (cellPending.value || headerPending.value) { error.value = 'Aplica o cancela la edición de la celda o encabezado antes de guardar.'; return; }
    const saveName = name.value.trim();
    if (!saveName || [...saveName].length > 255) { error.value = 'Escribe un nombre de hasta 255 caracteres.'; return; }
    const ticket = writeTicket(), snapshot = table.value;
    const id = selected.value?.id ?? null, revision = selected.value?.revision ?? null;
    writeBusy.value = true; error.value = ''; status.value = 'Guardando tabla…';
    try {
        const result = await client.save(ticket.brandId, id, { name: saveName, revision, table: snapshot });
        if (!currentWrite(ticket)) return;
        selected.value = result.dataset; revisionView.value = result.dataset.revision; sha256.value = '';
        savedTable = snapshot; savedName.value = name.value = saveName; conflict.value = false;
        status.value = `Revisión ${result.dataset.revision} guardada.`; emit('changed', result.dataset); loadList(pagination.value.current_page);
    } catch (e) {
        if (currentWrite(ticket)) {
            conflict.value = e.status === 409;
            error.value = conflict.value ? 'La tabla tiene una revisión más reciente. Tu borrador se conserva. Puedes descargarlo o recargar la versión guardada.' : e.message;
            status.value = '';
        }
    } finally { writeBusy.value = false; }
}
const rowPage = ref(0);
const visibleRows = computed(() => table.value?.rows.slice(rowPage.value * 20, rowPage.value * 20 + 20) ?? []);
const visibleColumns = computed(() => table.value?.columns.map((column, index) => ({ ...column, index })).slice(columnPage.value * 8, columnPage.value * 8 + 8) ?? []);
const columnPage = ref(0);
const selectedRowId = ref('');
const selectedColumnId = ref('');
function stopSelectionReads() {
    editorEpoch++; columnDrafts.value = Object.create(null);
    for (const channel of ['table', 'history']) { requests.get(channel)?.abort.abort(); requests.delete(channel); }
    readBusy.value = historyBusy.value = false; historyOpen.value = false; revisions.value = [];
}
function selectDataset(dataset, revision = dataset.revision, snapshotName = dataset.name) {
    return askDiscard(() => loadDataset(dataset, revision, snapshotName));
}
async function loadDataset(dataset, revision, snapshotName = dataset.name) {
    stopSelectionReads(); conflict.value = false; cellEditor.value = null;
    const ticket = ticketFor('table');
    selected.value = dataset; revisionView.value = revision; table.value = null; history = null; savedTable = null;
    name.value = savedName.value = snapshotName; sha256.value = ''; readBusy.value = true; error.value = ''; status.value = 'Cargando tabla…';
    try {
        const result = await client.load(ticket.brandId, dataset.id, revision, { signal: ticket.abort.signal, onProgress: count => {
            if (current('table', ticket)) status.value = `Cargando tabla… ${count} filas leídas`;
        } });
        if (!current('table', ticket)) return;
        history = tableCore.createDatasetHistory(result.table); table.value = savedTable = history.table; historyVersion.value++;
        revisionView.value = result.revision; sha256.value = result.sha256;
        rowPage.value = columnPage.value = 0; selectedRowId.value = table.value.rows[0]?.id ?? ''; selectedColumnId.value = table.value.columns[0]?.id ?? '';
        status.value = 'Tabla cargada.';
    } catch (e) { if (current('table', ticket) && e.name !== 'AbortError') { error.value = e.message; status.value = ''; } }
    finally { if (current('table', ticket)) { requests.delete('table'); readBusy.value = false; } }
}

const name = ref('');
const cellEditor = shallowRef(null);
const cellDraft = ref('');
const cellTypeDraft = ref('text');
const cellInput = ref(null);
const cellTypeInput = ref(null);
const columnDrafts = ref(Object.create(null));
const headerPending = computed(() => Object.keys(columnDrafts.value).length > 0);
function stageColumnLabel(id, label) {
    if (!canEdit.value) return;
    const next = Object.assign(Object.create(null), columnDrafts.value);
    if (table.value.columns.find(column => column.id === id)?.label === label) delete next[id]; else next[id] = label;
    columnDrafts.value = next;
}
const cellOriginalText = ref('');
const cellOriginalType = ref('text');
const cellPending = computed(() => !!cellEditor.value && (cellDraft.value !== cellOriginalText.value || cellTypeDraft.value !== cellOriginalType.value));
const conversionType = ref('number');
const conversionPreview = shallowRef(null);
const searchText = ref('');
const replacementText = ref('');
const onlySelectedColumn = ref(false);
const fillText = ref('');
const fillType = ref('text');
const includeEmptyStrings = ref(false);
const transposePreview = ref(false);
function structure(action) {
    const transforms = {
        'add-row': table => tableCore.addDatasetRow(table),
        'remove-row': table => tableCore.removeDatasetRow(table, selectedRowId.value),
        'add-column': table => tableCore.addDatasetColumn(table),
        'remove-column': table => tableCore.removeDatasetColumn(table, selectedColumnId.value),
    };
    if (!transforms[action] || !applyEdit(transforms[action])) return;
    if (action === 'add-row') { selectedRowId.value = table.value.rows.at(-1).id; rowPage.value = Math.floor((table.value.rows.length - 1) / 20); }
    if (action === 'add-column') { selectedColumnId.value = table.value.columns.at(-1).id; columnPage.value = Math.floor((table.value.columns.length - 1) / 8); }
}
function renameColumn(id, label, event) {
    if (applyEdit(table => tableCore.renameDatasetColumn(table, id, label), { allowHeader: true })) {
        const next = Object.assign(Object.create(null), columnDrafts.value); delete next[id]; columnDrafts.value = next;
    }
}
function previewConversion() {
    if (!canEdit.value) return;
    error.value = '';
    try {
        conversionPreview.value = { ...tableCore.previewDatasetConversion(table.value, selectedColumnId.value, conversionType.value),
            source: table.value, columnId: selectedColumnId.value, type: conversionType.value };
    } catch (e) { error.value = e.message; conversionPreview.value = null; }
}
function applyConversion() {
    const preview = conversionPreview.value;
    if (!preview || preview.source !== table.value || preview.columnId !== selectedColumnId.value || preview.type !== conversionType.value) { error.value = 'Previsualiza la conversión antes de aplicarla.'; return; }
    if (preview.errors.length) { error.value = 'Corrige las filas indicadas antes de convertir la columna.'; return; }
    applyEdit(table => tableCore.applyDatasetConversion(table, selectedColumnId.value, conversionType.value));
}
function replaceText() { applyEdit(table => tableCore.replaceDatasetText(table, searchText.value, replacementText.value, { columnId: onlySelectedColumn.value ? selectedColumnId.value : null })); }
function fillBlanks() { applyEdit(table => tableCore.fillDatasetBlanks(table, tableCore.cellFromInput(fillText.value, fillType.value), { includeEmptyStrings: includeEmptyStrings.value, columnId: onlySelectedColumn.value ? selectedColumnId.value : null })); }
function transposeTable() {
    if (!canEdit.value) return;
    if (table.value.rows.length > 99) { error.value = 'Para conservar los encabezados, se pueden transponer como máximo 99 filas.'; return; }
    transposePreview.value = true; error.value = '';
}
function confirmTranspose() { if (transposePreview.value) applyEdit(table => tableCore.transposeDatasetTable(table)); }

const viewingHistory = computed(() => selected.value && revisionView.value !== selected.value.revision);
const canEdit = computed(() => !!table.value && !readBusy.value && !writeBusy.value && !viewingHistory.value && !selected.value?.archived_at);
const canUndo = computed(() => { historyVersion.value; return !!history?.canUndo && canEdit.value && !cellPending.value && !headerPending.value; });
const canRedo = computed(() => { historyVersion.value; return !!history?.canRedo && canEdit.value && !cellPending.value && !headerPending.value; });
function syncSelection() {
    if (!table.value.rows.some(row => row.id === selectedRowId.value)) selectedRowId.value = table.value.rows[0]?.id ?? '';
    if (!table.value.columns.some(column => column.id === selectedColumnId.value)) selectedColumnId.value = table.value.columns[0]?.id ?? '';
    rowPage.value = Math.max(0, Math.min(rowPage.value, Math.ceil(table.value.rows.length / 20) - 1));
    columnPage.value = Math.max(0, Math.min(columnPage.value, Math.ceil(table.value.columns.length / 8) - 1));
}
function applyEdit(transform, { allowCell = false, allowHeader = false } = {}) {
    if (!canEdit.value) return false;
    if ((cellPending.value && !allowCell) || (headerPending.value && !allowHeader)) { error.value = 'Aplica o cancela la edición de la celda o encabezado antes de continuar.'; return false; }
    error.value = '';
    try {
        history.commit(transform(table.value)); table.value = history.table; historyVersion.value++; syncSelection();
        conversionPreview.value = null; transposePreview.value = false; cellEditor.value = null;
        status.value = 'Cambios sin guardar.'; return true;
    } catch (e) { error.value = e.message; return false; }
}
function openCell(rowId, columnId) {
    if (!canEdit.value) return;
    if (cellPending.value || headerPending.value) { error.value = 'Aplica o cancela la edición actual antes de abrir otra celda.'; return; }
    const row = table.value.rows.find(row => row.id === rowId), index = table.value.columns.findIndex(column => column.id === columnId);
    if (!row || index < 0) return;
    selectedRowId.value = rowId; selectedColumnId.value = columnId; cellEditor.value = { rowId, columnId };
    cellDraft.value = cellOriginalText.value = tableCore.cellText(row.values[index]); cellTypeDraft.value = cellOriginalType.value = tableCore.cellType(row.values[index]);
    nextTick(() => { if (cellTypeDraft.value === 'empty') cellTypeInput.value?.focus(); else cellInput.value?.focus(); });
}
function commitCell() {
    if (!cellEditor.value) return;
    const { rowId, columnId } = cellEditor.value;
    if (applyEdit(table => tableCore.editDatasetCell(table, rowId, columnId, tableCore.cellFromInput(cellDraft.value, cellTypeDraft.value)), { allowCell: true })) cellEditor.value = null;
}
function undoTable(direction) {
    if (!canEdit.value) return;
    if (cellPending.value || headerPending.value) { error.value = 'Aplica o cancela la edición actual antes de deshacer.'; return; }
    table.value = direction === 'undo' ? history.undo() : history.redo(); historyVersion.value++; cellEditor.value = null; conversionPreview.value = null; transposePreview.value = false; syncSelection(); error.value = '';
}

const historyVersion = ref(0);
let history = null;
let savedTable = null;
const savedName = ref('');
const dirty = computed(() => { historyVersion.value; return table.value !== null && (table.value !== savedTable || name.value !== savedName.value || cellPending.value || headerPending.value); });
function newTable() { return askDiscard(createLocalTable); }
function createLocalTable() {
    stopSelectionReads(); conflict.value = false; cellEditor.value = null; revisionView.value = null; sha256.value = ''; error.value = ''; status.value = '';
    rowPage.value = columnPage.value = 0;
    selected.value = null; name.value = 'Tabla sin título'; savedName.value = ''; savedTable = null;
    history = tableCore.createDatasetHistory(tableCore.blankDatasetTable()); table.value = history.table; historyVersion.value++;
    selectedRowId.value = table.value.rows[0]?.id ?? ''; selectedColumnId.value = table.value.columns[0]?.id ?? '';
}
watch(() => [selectedColumnId.value, conversionType.value], () => { conversionPreview.value = null; });
watch(() => [props.modelValue, props.brandId], async ([open]) => {
    invalidateReads(); table.value = null; selected.value = null; history = null; historyVersion.value++;
    pendingDiscard.value = null; cellEditor.value = null; columnDrafts.value = Object.create(null); conversionPreview.value = null; transposePreview.value = false; historyOpen.value = false; revisions.value = []; conflict.value = false; importFile.value = null; importName.value = ''; if (fileInput.value) fileInput.value.value = ''; savedTable = null; name.value = savedName.value = ''; datasets.value = []; query.value = ''; error.value = ''; status.value = '';
    const version = context;
    if (open && props.brandId) loadList();
    await nextTick();
    if (version !== context || disposed) return;
    if (open && !dialog.value?.open) dialog.value?.showModal();
    else if (!open && dialog.value?.open) dialog.value.close();
}, { immediate: true });
function beforeUnload(event) {
    if (!props.modelValue || !dirty.value) return;
    event.preventDefault(); event.returnValue = '';
}
if (typeof window !== 'undefined') window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { disposed = true; invalidateReads(); dialog.value?.close(); if (typeof window !== 'undefined') window.removeEventListener('beforeunload', beforeUnload); });
</script>
<template>
    <Teleport to="body">
        <dialog ref="dialog" aria-label="Tablas de datos de marca" class="datasets-dialog relative m-auto max-h-[94vh] w-[calc(100vw_-_1rem)] max-w-7xl overflow-hidden rounded-2xl border-0 bg-slate-50 p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/50" @cancel.prevent="pendingDiscard ? cancelDiscard() : requestClose()">
            <div class="max-h-[92vh] overflow-y-auto" :inert="!!pendingDiscard || undefined">
                <header class="sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                    <div class="min-w-0"><p class="truncate text-xs font-semibold uppercase tracking-wider text-violet-700">{{ brandName || `Marca ${brandId}` }}</p><h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Tablas de datos</h2></div>
                    <button type="button" class="close-tool shrink-0" aria-label="Cerrar tablas de datos" @click="requestClose">×</button>
                </header>
                <div class="space-y-4 p-3 sm:p-6">
                    <p class="max-w-3xl text-sm leading-relaxed text-slate-600">Organiza los datos de esta marca y conserva sus revisiones. Los textos, números y valores vacíos mantienen su tipo; una importación no convierte los textos en fórmulas.</p>
                    <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-relaxed text-red-800">{{ error }}</p>
                    <div v-if="conflict" class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"><span>Tu borrador permanece en el editor.</span><button type="button" class="data-button" :disabled="writeBusy" @click="reloadSaved">Recargar versión guardada</button></div>
                    <p v-if="status" role="status" class="text-sm text-violet-800">{{ status }}</p>
                    <p v-if="writeBusy" role="status" class="rounded-xl bg-violet-100 p-3 text-sm font-medium text-violet-950">Hay una escritura en curso. Puedes cerrar esta ventana; su resultado se conservará en la marca de origen.</p>
                    <div class="grid min-w-0 gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
                        <aside class="min-w-0 space-y-4" aria-label="Biblioteca de tablas">
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <button type="button" class="data-primary w-full" :disabled="writeBusy" @click="newTable">Nueva tabla</button>
                                <form class="mt-4 flex gap-2" role="search" @submit.prevent="loadList()"><label class="sr-only" for="dataset-search">Buscar tablas</label><input id="dataset-search" v-model="query" type="search" maxlength="120" class="data-input min-w-0 flex-1" placeholder="Buscar tablas…" /><button type="submit" class="data-button px-3" :disabled="listBusy">Buscar</button></form>
                                <p v-if="listBusy" role="status" class="mt-3 text-xs text-slate-500">Buscando tablas…</p>
                                <p v-else-if="!datasets.length" class="mt-4 text-sm text-slate-500">No hay tablas para esta búsqueda. Crea una o importa tus datos.</p>
                                <ul class="mt-3 space-y-2">
                                    <li v-for="dataset in datasets" :key="dataset.id"><button type="button" :data-testid="`dataset-open-${dataset.id}`" :aria-label="dataset.name" :aria-pressed="selected?.id === dataset.id" :disabled="writeBusy" class="w-full rounded-xl border p-3 text-left transition hover:border-violet-400" :class="selected?.id === dataset.id ? 'border-violet-400 bg-violet-50' : 'border-slate-200 bg-white'" @click="selectDataset(dataset)"><span class="block break-words text-sm font-semibold text-slate-900">{{ dataset.name }}</span><span class="mt-1 block text-xs text-slate-500">Revisión {{ dataset.revision }} · {{ dataset.row_count }} filas × {{ dataset.column_count }} columnas</span><span v-if="dataset.archived_at" class="mt-2 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Archivada</span></button></li>
                                </ul>
                                <nav v-if="pagination.last_page > 1" aria-label="Páginas de tablas" class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs"><button type="button" class="data-button" :disabled="listBusy || pagination.current_page <= 1" @click="loadList(pagination.current_page - 1)">Tablas anteriores</button><span>{{ pagination.current_page }} / {{ pagination.last_page }}</span><button type="button" class="data-button" :disabled="listBusy || pagination.current_page >= pagination.last_page" @click="loadList(pagination.current_page + 1)">Tablas siguientes</button></nav>
                                <p class="mt-3 text-[11px] leading-relaxed text-slate-500">La biblioteca incluye las tablas activas y archivadas de esta marca.</p>
                            </div>
                            <details class="rounded-2xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer text-sm font-semibold text-slate-900">Importar un archivo</summary>
                                <form class="mt-4 space-y-3" @submit.prevent="importData"><fieldset class="space-y-3" :disabled="writeBusy">
                                    <label class="block text-xs font-medium text-slate-700">Nombre de la importación<input v-model="importName" class="data-input mt-1 w-full" required placeholder="Datos de la campaña" /></label>
                                    <label class="block text-xs font-medium text-slate-700">Formato de importación<select v-model="importFormat" class="data-input mt-1 w-full"><option value="csv">CSV</option><option value="tsv">TSV</option><option value="json">JSON tipado</option></select></label>
                                    <label v-if="importFormat !== 'json'" class="flex items-start gap-2 text-xs leading-relaxed"><input v-model="importHeader" type="checkbox" class="mt-0.5 rounded border-slate-300 text-violet-700" />La primera fila contiene encabezados</label>
                                    <label class="block text-xs font-medium text-slate-700">Archivo de datos<input ref="fileInput" type="file" accept=".csv,.tsv,.json" class="mt-2 block w-full min-w-0 text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-violet-100 file:px-2 file:py-2 file:text-violet-900" @change="pickFile" /></label>
                                    <p class="text-[11px] leading-relaxed text-slate-500">CSV y TSV: hasta 25 MB. JSON tipado: hasta 52 MB. Máximo 1.000 filas y 100 columnas. CSV y TSV se importan como texto.</p>
                                    <button type="submit" class="data-button w-full" :disabled="!importFile">Importar archivo</button>
                                </fieldset></form>
                            </details>
                        </aside>
                        <section class="min-w-0 space-y-4" aria-label="Editor de tablas">
                            <div v-if="readBusy" role="status" class="rounded-2xl border border-violet-200 bg-violet-50 p-8 text-center text-sm text-violet-900">Cargando tabla…</div>
                            <section v-else-if="table" data-testid="dataset-editor" class="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-3 sm:p-5">
                                <div class="flex flex-wrap items-end justify-between gap-3">
                                    <label class="block min-w-0 flex-1 text-xs font-medium text-slate-600">Nombre de la tabla<input v-model="name" class="data-input mt-1 w-full text-base font-semibold text-slate-950" :disabled="!canEdit" /></label>
                                    <button type="button" class="data-primary" :disabled="!canEdit || !dirty" @click="saveTable">Guardar tabla</button>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-xs"><span v-if="selected" class="rounded-full bg-slate-100 px-2.5 py-1">Revisión {{ revisionView }}</span><span class="text-slate-500">{{ table.rows.length }} filas × {{ table.columns.length }} columnas</span><span role="status" :class="dirty ? 'text-amber-800' : 'text-emerald-700'">{{ dirty ? 'Cambios sin guardar' : 'Sin cambios pendientes' }}</span><span v-if="viewingHistory" class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-900">Vista de revisión histórica</span><span v-if="selected?.archived_at" class="rounded-full bg-slate-200 px-2.5 py-1 text-slate-700">Tabla archivada · sólo lectura</span></div>
                                <div class="flex flex-wrap gap-2"><button type="button" class="data-button" :disabled="!canUndo" @click="undoTable('undo')">Deshacer tabla</button><button type="button" class="data-button" :disabled="!canRedo" @click="undoTable('redo')">Rehacer tabla</button><button type="button" class="data-button" :disabled="writeBusy" @click="copyTable">Duplicar tabla</button><button v-if="selected" type="button" class="data-button" :disabled="writeBusy" @click="showHistory()">Historial de revisiones</button><button v-if="viewingHistory" type="button" class="data-button" :disabled="writeBusy" @click="reloadSaved">Volver a la revisión actual</button></div>
                                <p class="text-[11px] leading-relaxed text-slate-500">Se conservan hasta 30 pasos para deshacer. Pulsa una celda para editar su contenido completo y elegir su tipo.</p>
                                <div class="min-w-0 overflow-x-auto rounded-xl border border-slate-200" tabindex="0" aria-label="Desplazamiento horizontal de la tabla">
                                    <table class="w-full min-w-[640px] table-fixed border-collapse text-left text-xs" aria-label="Datos de la tabla">
                                        <caption class="sr-only">Filas {{ table.rows.length ? rowPage * 20 + 1 : 0 }} a {{ Math.min((rowPage + 1) * 20, table.rows.length) }}. Columnas {{ columnPage * 8 + 1 }} a {{ Math.min((columnPage + 1) * 8, table.columns.length) }}.</caption>
                                        <thead class="bg-slate-50"><tr><th scope="col" class="w-12 border-b border-r border-slate-200 p-2"><span class="sr-only">Fila</span>#</th><th v-for="column in visibleColumns" :key="column.id" scope="col" class="min-w-24 border-b border-r border-slate-200 p-2 last:border-r-0"><label class="sr-only" :for="`dataset-header-${column.id}`">Nombre de columna {{ column.index + 1 }}</label><input :id="`dataset-header-${column.id}`" :value="columnDrafts[column.id] ?? column.label" :disabled="!canEdit" class="w-full min-w-0 rounded-md border border-transparent bg-transparent px-1 py-1 font-semibold text-slate-800 hover:border-slate-300 focus:border-violet-500 focus:bg-white disabled:opacity-100" @input="stageColumnLabel(column.id, $event.target.value)" @change="renameColumn(column.id, $event.target.value, $event)" /></th></tr></thead>
                                        <tbody><tr v-for="(row, rowIndex) in visibleRows" :key="row.id" class="border-b border-slate-100 last:border-b-0"><th scope="row" class="border-r border-slate-200 bg-slate-50 p-2 font-normal text-slate-500">{{ rowPage * 20 + rowIndex + 1 }}</th><td v-for="column in visibleColumns" :key="column.id" class="border-r border-slate-100 p-0 align-top last:border-r-0"><button type="button" :data-testid="`dataset-cell-${row.id}-${column.id}`" :aria-label="`Editar fila ${rowPage * 20 + rowIndex + 1}, columna ${column.index + 1}: ${column.label}`" :disabled="!canEdit" class="block min-h-12 w-full px-3 py-2 text-left hover:bg-violet-50 disabled:opacity-100" :class="selectedRowId === row.id && selectedColumnId === column.id ? 'bg-violet-50 ring-1 ring-inset ring-violet-300' : ''" @click="openCell(row.id, column.id)"><span class="mb-1 block text-[9px] font-semibold uppercase tracking-wide text-slate-400">{{ { text: 'Texto', number: 'Número', empty: 'Vacío' }[tableCore.cellType(row.values[column.index])] }}</span><span class="block max-h-16 overflow-hidden whitespace-pre-wrap break-words text-slate-800">{{ row.values[column.index] === null ? '—' : tableCore.cellText(row.values[column.index]) || 'Texto vacío' }}</span></button></td></tr><tr v-if="!visibleRows.length"><td :colspan="visibleColumns.length + 1" class="p-6 text-center text-slate-500">Esta tabla todavía no tiene filas. Añade la primera fila para empezar.</td></tr></tbody>
                                    </table>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3 text-xs"><div class="flex items-center gap-2"><button type="button" class="data-button" :disabled="rowPage === 0" @click="rowPage--">Filas anteriores</button><span>{{ table.rows.length ? rowPage * 20 + 1 : 0 }}–{{ Math.min((rowPage + 1) * 20, table.rows.length) }} / {{ table.rows.length }}</span><button type="button" class="data-button" :disabled="(rowPage + 1) * 20 >= table.rows.length" @click="rowPage++">Filas siguientes</button></div><div class="flex items-center gap-2"><button type="button" class="data-button" :disabled="columnPage === 0" @click="columnPage--">Columnas anteriores</button><span>{{ columnPage * 8 + 1 }}–{{ Math.min((columnPage + 1) * 8, table.columns.length) }}</span><button type="button" class="data-button" :disabled="(columnPage + 1) * 8 >= table.columns.length" @click="columnPage++">Columnas siguientes</button></div></div>
                                <div v-if="headerPending" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-950">Hay un encabezado pendiente. Sal del campo para aplicarlo o <button type="button" class="font-semibold underline" @click="columnDrafts = Object.create(null)">cancelar cambios de encabezados</button>.</div>
                                <fieldset v-if="cellEditor" class="space-y-3 rounded-xl border border-violet-200 bg-violet-50/60 p-4" :disabled="!canEdit"><legend class="px-1 text-sm font-semibold text-violet-950">Editar celda</legend><label class="block text-xs font-medium">Tipo de la celda<select ref="cellTypeInput" v-model="cellTypeDraft" class="data-input mt-1 w-full sm:max-w-xs"><option value="text">Texto</option><option value="number">Número</option><option value="empty">Vacío</option></select></label><label class="block text-xs font-medium">Valor de la celda<textarea ref="cellInput" v-model="cellDraft" rows="4" class="data-input mt-1 w-full font-mono text-sm" :disabled="cellTypeDraft === 'empty'" spellcheck="false" /></label><p class="text-xs leading-relaxed text-slate-600">Texto conserva ceros iniciales y fórmulas literalmente. Número usa punto decimal, sin separador de miles; Vacío guarda un valor null.</p><div class="flex flex-wrap gap-2"><button type="button" class="data-primary" @click="commitCell">Aplicar celda</button><button type="button" class="data-button" @click="cellEditor = null">Cancelar edición de celda</button></div></fieldset>
                                <details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold text-slate-900">Herramientas de tabla</summary><fieldset class="mt-4 space-y-5" :disabled="!canEdit">
                                    <div class="grid gap-3 sm:grid-cols-2"><label class="block text-xs font-medium">Fila seleccionada<select v-model="selectedRowId" class="data-input mt-1 w-full"><option v-if="!table.rows.length" value="">No hay filas</option><option v-for="(row, index) in table.rows" :key="row.id" :value="row.id">Fila {{ index + 1 }}</option></select></label><label class="block text-xs font-medium">Columna seleccionada<select v-model="selectedColumnId" class="data-input mt-1 w-full"><option v-for="(column, index) in table.columns" :key="column.id" :value="column.id">Columna {{ index + 1 }}: {{ column.label }}</option></select></label></div>
                                    <div class="flex flex-wrap gap-2"><button type="button" class="data-button" @click="structure('add-row')">Añadir fila</button><button type="button" class="data-button" @click="structure('add-column')">Añadir columna</button><button type="button" class="data-button" :disabled="!selectedRowId" @click="structure('remove-row')">Eliminar fila seleccionada</button><button type="button" class="data-button" :disabled="table.columns.length <= 1" @click="structure('remove-column')">Eliminar columna seleccionada</button></div>
                                    <section class="space-y-3 border-t border-slate-100 pt-4" aria-label="Conversión explícita de columna"><h3 class="text-sm font-semibold">Convertir la columna seleccionada</h3><label class="block text-xs font-medium">Tipo de conversión<select v-model="conversionType" class="data-input mt-1 w-full sm:max-w-xs"><option value="number">Número</option><option value="text">Texto</option><option value="empty">Vacío</option></select></label><button type="button" class="data-button" @click="previewConversion">Previsualizar conversión</button><div v-if="conversionPreview" class="space-y-2 rounded-lg bg-slate-50 p-3 text-xs"><p>{{ conversionPreview.changes.length }} filas convertibles. {{ conversionPreview.errors.length }} errores.</p><ul v-if="conversionPreview.errors.length" role="alert" class="space-y-1 text-red-800"><li v-for="failure in conversionPreview.errors.slice(0, 20)" :key="failure.rowId">Fila {{ table.rows.findIndex(row => row.id === failure.rowId) + 1 }}: {{ failure.message }}</li><li v-if="conversionPreview.errors.length > 20">Se muestran los primeros 20 errores.</li></ul><ul v-else class="space-y-1 text-slate-600"><li v-for="change in conversionPreview.changes.slice(0, 5)" :key="change.rowId">Fila {{ table.rows.findIndex(row => row.id === change.rowId) + 1 }} → {{ tableCore.cellText(change.value) || 'Vacío' }}</li></ul><button type="button" class="data-primary" :disabled="conversionPreview.errors.length > 0" @click="applyConversion">Aplicar conversión</button></div></section>
                                    <section class="space-y-3 border-t border-slate-100 pt-4" aria-label="Buscar y rellenar"><label class="flex items-start gap-2 text-xs"><input v-model="onlySelectedColumn" type="checkbox" class="rounded border-slate-300 text-violet-700" />Sólo columna seleccionada</label><div class="grid gap-3 sm:grid-cols-2"><label class="block text-xs font-medium">Texto a buscar<input v-model="searchText" class="data-input mt-1 w-full" /></label><label class="block text-xs font-medium">Texto de reemplazo<input v-model="replacementText" class="data-input mt-1 w-full" /></label></div><p class="text-xs text-slate-500">Reemplazo literal dentro de celdas de texto, sin expresiones ni cambios a números.</p><button type="button" class="data-button" :disabled="!searchText" @click="replaceText">Reemplazar texto</button><div class="grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2"><label class="block text-xs font-medium">Tipo del relleno<select v-model="fillType" class="data-input mt-1 w-full"><option value="text">Texto</option><option value="number">Número</option><option value="empty">Vacío</option></select></label><label class="block text-xs font-medium">Valor para vacíos<input v-model="fillText" class="data-input mt-1 w-full" :disabled="fillType === 'empty'" /></label></div><label class="flex items-start gap-2 text-xs"><input v-model="includeEmptyStrings" type="checkbox" class="rounded border-slate-300 text-violet-700" />Incluir textos vacíos</label><button type="button" class="data-button" @click="fillBlanks">Rellenar vacíos</button></section>
                                    <section class="space-y-3 border-t border-slate-100 pt-4" aria-label="Transponer"><button type="button" class="data-button" @click="transposeTable">Transponer tabla</button><div v-if="transposePreview" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-relaxed text-amber-950"><p>Las columnas pasarán a ser filas. Se conservarán sus encabezados como valores de la primera columna «Columna»; las demás columnas se llamarán «Fila 1», «Fila 2»… Se crearán identificadores nuevos. Esta operación admite hasta 99 filas y se puede deshacer.</p><button type="button" class="data-primary" @click="confirmTranspose">Confirmar transposición</button><button type="button" class="data-button ml-2" @click="transposePreview = false">Cancelar transposición</button></div></section>
                                </fieldset></details>
                                <section v-if="historyOpen" class="space-y-3 rounded-xl border border-slate-200 p-4" aria-label="Historial de revisiones"><div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">Historial de revisiones</h3><button type="button" class="data-button" @click="historyOpen = false">Ocultar historial</button></div><p v-if="historyBusy" role="status" class="text-xs text-slate-500">Cargando revisiones…</p><ul class="space-y-2"><li v-for="revision in revisions" :key="revision.revision"><button type="button" class="flex w-full flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 p-3 text-left hover:border-violet-400" :aria-label="`Ver revisión ${revision.revision}`" :disabled="writeBusy" @click="selectDataset(selected, revision.revision, revision.name)"><span class="min-w-0"><span class="block text-xs font-semibold">Revisión {{ revision.revision }} · {{ revision.name }}</span><span class="mt-1 block text-[11px] text-slate-500">{{ revision.row_count }} filas × {{ revision.column_count }} columnas · {{ revision.json_bytes }} bytes</span></span><span class="text-xs text-violet-700">Ver revisión {{ revision.revision }}</span></button></li></ul><nav v-if="historyPagination.last_page > 1" class="flex flex-wrap items-center justify-between gap-2 text-xs" aria-label="Páginas de revisiones"><button type="button" class="data-button" :disabled="historyBusy || historyPagination.current_page <= 1" @click="showHistory(historyPagination.current_page - 1)">Revisiones anteriores</button><span>{{ historyPagination.current_page }} / {{ historyPagination.last_page }}</span><button type="button" class="data-button" :disabled="historyBusy || historyPagination.current_page >= historyPagination.last_page" @click="showHistory(historyPagination.current_page + 1)">Revisiones siguientes</button></nav></section>
                                <section class="space-y-3 border-t border-slate-200 pt-4" aria-label="Descargas de datos"><div v-if="selected" class="grid gap-3 sm:grid-cols-2"><label class="block text-xs font-medium">Formato de descarga<select v-model="downloadFormat" class="data-input mt-1 w-full"><option value="csv">CSV</option><option value="tsv">TSV</option><option value="json">JSON tipado</option></select></label><label v-if="downloadFormat !== 'json'" class="block text-xs font-medium">Modo de descarga<select v-model="downloadMode" class="data-input mt-1 w-full"><option value="spreadsheet">Segura para hojas de cálculo</option><option value="text">Fiel al original</option></select></label></div><p v-if="selected" class="text-xs leading-relaxed text-slate-500">La descarga corresponde a la revisión {{ revisionView }} guardada. El modo seguro antepone un apóstrofo a todos los textos para evitar que una hoja de cálculo ejecute fórmulas. El modo fiel conserva los textos originales.</p><div class="flex flex-wrap gap-2"><a v-if="selected" :href="downloadUrl" download class="data-button">Descargar tabla</a><button type="button" class="data-button" @click="downloadDraft">Descargar borrador JSON</button><button v-if="selected" type="button" class="data-button ml-auto text-slate-600" :disabled="writeBusy" @click="mutateDataset(selected.archived_at ? 'restore' : 'archive')">{{ selected.archived_at ? 'Restaurar tabla' : 'Archivar tabla' }}</button></div><p v-if="sha256" class="break-all font-mono text-[10px] text-slate-400">SHA-256: {{ sha256 }}</p></section>
                            </section>
                            <div v-else class="rounded-2xl border border-dashed border-violet-200 bg-white p-8 text-center"><div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-100 text-2xl text-violet-700" aria-hidden="true">▦</div><h3 class="text-base font-semibold text-slate-900">Un lugar para los datos de tu marca</h3><p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-500">Elige una tabla de la biblioteca, crea una nueva o importa un archivo para empezar a trabajar.</p><button v-if="selected" type="button" class="data-button mt-4" :disabled="writeBusy" @click="reloadSaved">Reintentar carga de tabla</button></div>
                        </section>
                    </div>
                </div>
            </div>
            <div v-if="pendingDiscard" class="absolute inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4"><section role="alertdialog" aria-modal="true" aria-labelledby="dataset-discard-title" aria-describedby="dataset-discard-description" class="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-2xl"><h3 id="dataset-discard-title" class="text-lg font-semibold text-slate-950">Hay cambios sin guardar</h3><p id="dataset-discard-description" class="text-sm leading-relaxed text-slate-600">Si continúas, se descartarán los cambios de esta tabla que aún no has guardado, incluidos los que estás escribiendo.</p><div class="flex flex-wrap gap-3"><button ref="discardButton" type="button" class="data-primary" @click="cancelDiscard">Seguir editando</button><button type="button" class="data-button" @click="confirmDiscard">Descartar cambios</button></div></section></div>
        </dialog>
    </Teleport>
</template>

<style scoped>
.data-input { @apply rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm disabled:bg-slate-50 disabled:text-slate-500; }
.data-button { @apply inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-violet-400 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50; }
.data-primary { @apply inline-flex items-center justify-center rounded-lg bg-violet-700 px-4 py-2.5 text-xs font-semibold text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50; }
.close-tool { @apply flex h-9 w-9 items-center justify-center rounded-lg text-2xl text-slate-500 hover:bg-slate-100; }
.datasets-dialog :is(button, a, input, select, textarea, summary, [tabindex]):focus-visible { outline: 3px solid #7c3aed; outline-offset: 2px; }
</style>
