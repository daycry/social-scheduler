<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Inspector from '@/Components/Studio/Inspector.vue';
import LayerPanel from '@/Components/Studio/LayerPanel.vue';
import ExportDialog from '@/Components/Studio/ExportDialog.vue';
import { blankDocument, cloneDocument, duplicatePage, findPage, validateDocument } from '@/Studio/document';
import { createStudioCanvas } from '@/Studio/canvas';
import { createHistory } from '@/Studio/history';
import { studioRequest, uploadStudioFile, downloadStudioBlob } from '@/Studio/api';

const props = defineProps({ design: Object, brand: Object, kit: Object, assets: Array, folders: Array, exports: Array });
const documentData = ref(validateDocument(props.design.document));
const name = ref(props.design.name);
const template = ref(props.design.is_template);
const revision = ref(props.design.revision);
const pageId = ref(documentData.value.pages[0].id);
const page = computed(() => findPage(documentData.value, pageId.value) ?? documentData.value.pages[0]);
const selection = ref([]);
const layers = ref(page.value.elements);
const panel = ref('layers');
const leftPanel = ref('elements');
const localAssets = ref(props.assets ?? []);
const assetQuery = ref('');
const visibleAssets = computed(() => localAssets.value.filter(asset => asset.name?.toLocaleLowerCase().includes(assetQuery.value.toLocaleLowerCase())));
const canvasElement = ref(null);
const stage = ref(null);
const exporter = ref(null);
const fileInput = ref(null);
const ready = ref(false);
const busy = ref(false);
const exportBusy = ref(false);
const error = ref('');
const uploadStatus = ref('');
const status = ref('Guardado');
const conflict = ref(false);
const dirty = ref(false);
const zoom = ref(50);
const fitMode = ref(true);
const drawing = ref(false);
const drawColor = ref(props.kit?.palette?.[0] ?? '#7c3aed');
const drawWidth = ref(8);
const exportOpen = ref(false);
const playing = ref(false);
const timeline = ref(0);
const designWidth = ref(documentData.value.width);
const designHeight = ref(documentData.value.height);
const historyVersion = ref(0);
const snapshot = () => ({ document: cloneDocument(documentData.value), name: name.value, is_template: template.value });
const history = createHistory(snapshot());
const canUndo = computed(() => { historyVersion.value; return history.canUndo; });
const canRedo = computed(() => { historyVersion.value; return history.canRedo; });
const hasVideo = computed(() => JSON.stringify(page.value.elements).includes('"type":"video"'));
let adapter;
let timer;
let changeVersion = 0;
let pendingSave = null;
let resizeObserver;
let removeNavigate;
let disposed = false;
let navigating = false;

function scheduleSave() {
    clearTimeout(timer);
    if (!conflict.value) timer = setTimeout(() => { saveNow(); }, 1200);
}
function commit() {
    history.commit(snapshot()); historyVersion.value++;
    changeVersion++; dirty.value = true; status.value = conflict.value ? 'Conflicto de revisión' : 'Cambios pendientes';
    scheduleSave();
}
async function saveNow() {
    clearTimeout(timer);
    if (conflict.value) return false;
    if (pendingSave) { const success = await pendingSave; if (!success || conflict.value) return false; return dirty.value ? saveNow() : true; }
    if (!dirty.value) return true;
    const version = changeVersion;
    const saved = snapshot();
    status.value = 'Guardando…';
    pendingSave = (async () => {
        try {
            const result = await studioRequest('PATCH', route('studio.designs.update', props.design.id), { name: saved.name.trim() || 'Diseño sin título', document: validateDocument(saved.document), revision: revision.value, is_template: saved.is_template, folder_id: props.design.folder_id });
            revision.value = result.design.revision;
            if (version === changeVersion) { dirty.value = false; status.value = 'Guardado'; }
            else { status.value = 'Cambios pendientes'; scheduleSave(); }
            return true;
        } catch (e) {
            if (e.status === 409) { conflict.value = true; status.value = 'Conflicto de revisión'; }
            else status.value = 'No se ha podido guardar';
            error.value = e.message;
            return false;
        }
    })().finally(() => { pendingSave = null; });
    const success = await pendingSave;
    if (success && dirty.value) return saveNow();
    return success;
}
async function prepareExport() {
    adapter?.pause(); playing.value = false;
    if (!await saveNow()) throw new Error('Guarda o resuelve el conflicto del diseño antes de exportar.');
    return { ...snapshot(), revision: revision.value };
}
async function run(action) {
    error.value = '';
    try { return await action(); } catch (e) { error.value = e.message; }
}
function refreshLayers() { layers.value = adapter?.layers() ?? page.value.elements; }
function fit() {
    if (!adapter || !stage.value) return;
    fitMode.value = true;
    const width = Math.max(80, stage.value.clientWidth - 48);
    const height = Math.max(180, stage.value.clientHeight - 72);
    zoom.value = Math.max(5, Math.min(100, Math.floor(Math.min(width / documentData.value.width, height / documentData.value.height) * 100)));
    adapter.zoom(zoom.value / 100);
}
function setZoom(value) { fitMode.value = false; zoom.value = Math.max(5, Math.min(400, Number(value))); adapter?.zoom(zoom.value / 100); }
async function reloadCanvas() {
    if (!adapter) return;
    busy.value = true; playing.value = false;
    try { await adapter.loadDocument(documentData.value, pageId.value); refreshLayers(); if (fitMode.value) fit(); }
    finally { busy.value = false; }
}
async function restoreHistory(mode) {
    await run(async () => {
        const state = history[mode]();
        if (!state) return;
        documentData.value = state.document; name.value = state.name; template.value = state.is_template;
        if (!findPage(documentData.value, pageId.value)) pageId.value = documentData.value.pages[0].id;
        designWidth.value = documentData.value.width; designHeight.value = documentData.value.height;
        changeVersion++; historyVersion.value++; dirty.value = true; status.value = 'Cambios pendientes'; scheduleSave();
        await reloadCanvas();
    });
}
const action = (method, value) => run(async () => { await adapter?.[method](value); refreshLayers(); });
const patchSelection = patch => run(() => { adapter.updateSelection(patch); refreshLayers(); });
const addText = () => run(() => { adapter.addText('Escribe tu texto', { fontFamily: props.kit?.fonts?.[0] ?? 'Arial', fill: props.kit?.palette?.[0] ?? '#111827' }); refreshLayers(); });
const addShape = type => action('addShape', type);
function selectLayer(id, multiple = false) {
    run(() => { const ids = new Set(multiple ? selection.value.map(item => item.id) : []); if (ids.has(id)) ids.delete(id); else ids.add(id); adapter.select([...ids]); });
}
function patchLayer(id, patch) {
    run(() => {
        const layer = layers.value.find(item => item.id === id);
        adapter.select(id);
        if (layer.locked && patch.locked === undefined) adapter.updateSelection({ ...patch, locked: false });
        else adapter.updateSelection(patch);
        if (layer.locked && patch.locked === undefined) adapter.lock(true);
        refreshLayers();
    });
}
async function changePage(id) { pageId.value = id; await run(reloadCanvas); }
async function pageAction(mode, offset = 0) {
    await run(async () => {
        const next = cloneDocument(documentData.value);
        const index = next.pages.findIndex(item => item.id === pageId.value);
        if (mode === 'add') { const added = blankDocument(next.width, next.height).pages[0]; added.name = `Página ${next.pages.length + 1}`; next.pages.splice(index + 1, 0, added); pageId.value = added.id; }
        if (mode === 'duplicate') { const added = duplicatePage(next.pages[index]); added.name = `${added.name} · copia`; next.pages.splice(index + 1, 0, added); pageId.value = added.id; }
        if (mode === 'delete') { if (next.pages.length === 1) return; next.pages.splice(index, 1); pageId.value = next.pages[Math.min(index, next.pages.length - 1)].id; }
        if (mode === 'move') { const target = index + offset; if (target < 0 || target >= next.pages.length) return; [next.pages[index], next.pages[target]] = [next.pages[target], next.pages[index]]; }
        documentData.value = validateDocument(next); commit(); await reloadCanvas();
    });
}
function patchPage(patch) {
    run(() => { const next = cloneDocument(documentData.value); Object.assign(findPage(next, pageId.value), patch); documentData.value = validateDocument(next); commit(); });
}
function resizeDesign() {
    run(async () => { const next = validateDocument({ ...cloneDocument(documentData.value), width: Number(designWidth.value), height: Number(designHeight.value) }); documentData.value = next; commit(); await reloadCanvas(); fit(); });
}
async function upload(event) {
    const files = [...(event.target.files ?? [])];
    if (!files.length) return;
    busy.value = true;
    await run(async () => {
        for (const file of files) {
            const uploaded = await uploadStudioFile(file, value => { uploadStatus.value = value; });
            const result = await studioRequest('POST', route('studio.assets.store', props.brand.id), { media_id: uploaded.id });
            localAssets.value = [result.asset, ...localAssets.value.filter(asset => asset.id !== result.asset.id)];
            adapter.setAssets(localAssets.value);
        }
        uploadStatus.value = 'Recursos añadidos a esta marca.';
    });
    busy.value = false; event.target.value = '';
}
function toggleDrawing() { drawing.value = !drawing.value; adapter?.setDrawing(drawing.value, drawColor.value, drawWidth.value); }
async function togglePlayback() {
    await run(async () => { if (playing.value) { adapter.pause(); playing.value = false; } else { await adapter.play(); playing.value = true; } });
}
function downloadLocal() { downloadStudioBlob(new Blob([JSON.stringify(snapshot(), null, 2)], { type: 'application/json' }), `${name.value}-copia-local.json`); }
async function recover() {
    await run(async () => {
        const latest = await studioRequest('GET', route('studio.designs.show', props.design.id));
        documentData.value = validateDocument(latest.design.document); name.value = latest.design.name; revision.value = latest.design.revision; template.value = latest.design.is_template;
        pageId.value = documentData.value.pages[0].id; conflict.value = false; dirty.value = false; status.value = 'Guardado'; error.value = ''; history.reset(snapshot()); historyVersion.value++;
        await reloadCanvas();
    });
}
function beforeUnload(event) { if (dirty.value || pendingSave) { event.preventDefault(); event.returnValue = ''; } }
function onKey(event) {
    if (/INPUT|TEXTAREA|SELECT/.test(event.target?.tagName) || event.target?.isContentEditable || adapter?.canvas.getActiveObject()?.isEditing) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); restoreHistory(event.shiftKey ? 'redo' : 'undo'); }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); saveNow(); }
}
onMounted(async () => {
    await nextTick();
    await run(async () => {
        adapter = await createStudioCanvas(canvasElement.value, { document: documentData.value, pageId: pageId.value, assets: localAssets.value, onChange(elements, meta) {
            const changedPage = findPage(documentData.value, meta.pageId);
            if (!changedPage) return;
            changedPage.elements = elements; changedPage.background = meta.background; layers.value = elements; commit();
        }, onSelection(items) { selection.value = items; } });
        if (disposed) { await adapter.destroy(); return; }
        adapter.canvas.on('studio:error', ({ error: e }) => { error.value = e.message; });
        ready.value = true; refreshLayers(); fit();
        resizeObserver = new ResizeObserver(() => { if (fitMode.value) fit(); }); resizeObserver.observe(stage.value);
    });
    window.addEventListener('beforeunload', beforeUnload);
    window.addEventListener('keydown', onKey);
    removeNavigate = router.on('before', event => {
        if (!dirty.value || navigating || event.detail.visit.method !== 'get') return;
        event.preventDefault();
        saveNow().then(success => { if (success && !disposed) { navigating = true; router.visit(event.detail.visit.url); } });
    });
});
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); resizeObserver?.disconnect(); removeNavigate?.(); window.removeEventListener('beforeunload', beforeUnload); window.removeEventListener('keydown', onKey); adapter?.destroy(); });
</script>

<template>
    <Head :title="`${name} · Estudio`" />
    <AuthenticatedLayout>
        <div class="-m-3 min-w-0 space-y-3 sm:-m-1">
            <header class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3">
                <Link :href="route('studio.index', { brand_id: brand.id })" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Volver al estudio"><Icon name="chevronLeft" class="h-4 w-4" /></Link>
                <div class="min-w-0 flex-1"><label class="sr-only" for="studio-design-name">Nombre del diseño</label><input id="studio-design-name" v-model="name" class="w-full min-w-0 rounded-md border-transparent bg-transparent px-1 py-0.5 text-sm font-semibold focus:border-violet-300 focus:ring-violet-200" maxlength="255" @input="commit" /><div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-[10px] text-slate-500"><span>{{ brand.name }}</span><span data-testid="studio-save-status" role="status" :class="conflict ? 'text-red-700' : dirty ? 'text-amber-700' : 'text-emerald-700'">{{ status }}</span><span>Revisión {{ revision }}</span></div></div>
                <div class="flex flex-wrap items-center gap-2"><button type="button" class="tool" :disabled="!canUndo || busy" aria-label="Deshacer" title="Deshacer (Ctrl+Z)" @click="restoreHistory('undo')">↶</button><button type="button" class="tool" :disabled="!canRedo || busy" aria-label="Rehacer" title="Rehacer (Ctrl+Mayús+Z)" @click="restoreHistory('redo')">↷</button><button type="button" class="btn-secondary text-xs" :disabled="conflict" @click="saveNow">Guardar ahora</button><button type="button" class="btn-secondary text-xs" :disabled="!ready || busy || exportBusy" @click="exportOpen = true">Exportar</button><button type="button" class="rounded-lg bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white hover:bg-violet-800 disabled:opacity-50" :disabled="!ready || busy || exportBusy" @click="exporter.publish()">Usar en publicación</button></div>
            </header>
            <div v-if="error" role="alert" class="flex items-start justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><span>{{ error }}</span><button v-if="!conflict" type="button" aria-label="Cerrar error" @click="error = ''">×</button></div>
            <div v-if="conflict" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>Otra sesión ha cambiado este diseño. El guardado automático está detenido para conservar tus cambios locales. Descarga una copia antes de recuperar la versión guardada.</p><div class="flex flex-wrap gap-2"><button type="button" class="btn-secondary text-xs" @click="downloadLocal">Descargar copia local</button><button type="button" class="btn-secondary text-xs" @click="recover">Recuperar versión guardada</button></div></div>

            <div class="grid min-w-0 items-start gap-3 xl:grid-cols-[190px_minmax(240px,1fr)_260px]">
                <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto" aria-label="Herramientas de diseño">
                    <div class="mb-4 flex gap-1 rounded-lg bg-slate-100 p-1"><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="leftPanel === 'elements' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="leftPanel = 'elements'">Elementos</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="leftPanel === 'assets' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="leftPanel = 'assets'">Recursos</button></div>
                    <div v-if="leftPanel === 'elements'" class="space-y-5"><button type="button" class="flex w-full items-center justify-center gap-2 rounded-xl bg-violet-700 px-3 py-3 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-50" :disabled="!ready || busy" @click="addText"><span class="font-serif text-xl" aria-hidden="true">T</span>Añadir texto</button><div><h2 class="mb-3 text-xs font-semibold">Formas</h2><div class="grid grid-cols-3 gap-2"><button v-for="shape in [{key:'rect',label:'Rectángulo',symbol:'▭'},{key:'ellipse',label:'Elipse',symbol:'◯'},{key:'triangle',label:'Triángulo',symbol:'△'},{key:'line',label:'Línea',symbol:'╱'},{key:'path',label:'Trazado',symbol:'〰'}]" :key="shape.key" type="button" class="rounded-xl border border-slate-200 py-3 text-2xl text-slate-600 transition hover:border-violet-300 hover:bg-violet-50 disabled:opacity-50" :aria-label="`Añadir ${shape.label.toLowerCase()}`" :title="shape.label" :disabled="!ready || busy" @click="addShape(shape.key)">{{ shape.symbol }}</button></div></div><div class="space-y-3 border-t border-slate-100 pt-4"><button type="button" class="w-full rounded-lg border px-3 py-2 text-xs font-medium" :class="drawing ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200'" :aria-pressed="drawing" :disabled="!ready" @click="toggleDrawing">Dibujo libre</button><div v-if="drawing" class="flex items-center gap-2"><label class="sr-only" for="studio-draw-color">Color del pincel</label><input id="studio-draw-color" v-model="drawColor" type="color" class="h-8 w-10" @input="adapter.setDrawing(true,drawColor,drawWidth)" /><label class="sr-only" for="studio-draw-width">Grosor del pincel</label><input id="studio-draw-width" v-model.number="drawWidth" type="number" min="1" max="200" class="field w-20 text-xs" @change="adapter.setDrawing(true,drawColor,drawWidth)" /></div></div><div class="border-t border-slate-100 pt-4"><h3 class="mb-3 text-xs font-semibold">Colores de {{ brand.name }}</h3><div class="flex flex-wrap gap-2"><button v-for="color in kit?.palette ?? []" :key="color" type="button" class="h-7 w-7 rounded-full border border-slate-200" :style="{ background: color }" :aria-label="`Color de marca ${color}`" :disabled="!selection.length" @click="patchSelection({fill:color})" /></div><p class="mt-3 text-[10px] leading-relaxed text-slate-400">Selecciona un elemento para aplicarle un color.</p></div></div>
                    <div v-else class="space-y-3"><button type="button" class="w-full rounded-xl bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white disabled:opacity-50" :disabled="busy" @click="fileInput.click()">Subir imagen o vídeo</button><input ref="fileInput" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm,video/quicktime" multiple aria-label="Subir recursos de marca" @change="upload" /><label class="sr-only" for="studio-asset-query">Buscar recursos</label><input id="studio-asset-query" v-model="assetQuery" type="search" class="field text-xs" placeholder="Buscar recursos…" /><p v-if="uploadStatus" role="status" class="text-xs text-violet-700">{{ uploadStatus }}</p><div class="grid grid-cols-2 gap-2"><button v-for="asset in visibleAssets" :key="asset.id" type="button" class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 text-left hover:border-violet-300" :aria-label="`Añadir ${asset.name}`" :disabled="!ready || busy" @click="action('addAsset',asset)"><img v-if="asset.kind !== 'video'" :src="asset.preview_url" :alt="asset.name" class="aspect-square w-full object-cover" loading="lazy" /><span v-else class="flex aspect-square items-center justify-center text-2xl text-violet-500" aria-hidden="true">▶</span><span class="block truncate p-2 text-[10px]">{{ asset.name }}</span></button></div><p v-if="!visibleAssets.length" class="py-5 text-xs leading-relaxed text-slate-500">{{ assetQuery ? 'No hay recursos con ese nombre.' : 'Sube imágenes o vídeos para usarlos en los diseños de esta marca.' }}</p></div>
                </aside>

                <main class="min-w-0 space-y-3" aria-label="Editor de diseño">
                    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-2"><span class="px-1 text-xs font-medium text-slate-600">{{ selection.length ? `${selection.length} seleccionado(s)` : page.name }}</span><div class="ml-auto flex items-center gap-1"><button type="button" class="tool text-xs" :disabled="!selection.length || busy" aria-label="Duplicar elemento" @click="action('duplicate')">Duplicar</button><button type="button" class="tool text-xs text-red-600" :disabled="!selection.length || busy" aria-label="Eliminar elemento" @click="action('delete')">Eliminar</button><button type="button" class="tool text-xs" :disabled="!selection.length || busy" aria-label="Bloquear elemento" @click="action('lock')">Bloquear</button></div></div>
                    <div ref="stage" class="studio-stage relative flex h-[430px] min-w-0 items-start justify-start overflow-auto rounded-2xl border border-slate-200 p-6 sm:h-[560px] xl:h-[calc(100vh-340px)] xl:min-h-[380px]">
                        <div class="m-auto shrink-0 bg-white shadow-[0_10px_40px_-8px_rgba(15,23,42,0.2)]" :style="{ width: `${documentData.width * zoom / 100}px`, height: `${documentData.height * zoom / 100}px` }"><canvas ref="canvasElement" /></div>
                        <div v-if="!ready || busy" role="status" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-slate-100/80 text-sm text-slate-600">{{ busy ? 'Preparando página…' : 'Cargando el lienzo…' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs"><span class="text-slate-500">{{ documentData.width }} × {{ documentData.height }} px</span><div class="flex items-center gap-2"><button type="button" class="tool" aria-label="Reducir zoom" @click="setZoom(zoom - 10)">−</button><label class="sr-only" for="studio-zoom">Zoom</label><input id="studio-zoom" type="number" min="5" max="400" :value="zoom" class="w-16 rounded-lg border-slate-200 py-1 text-xs" @change="setZoom($event.target.value)" /><span>%</span><button type="button" class="tool" aria-label="Aumentar zoom" @click="setZoom(zoom + 10)">+</button><button type="button" class="tool" @click="fit">Ajustar</button></div></div>
                    <div v-if="hasVideo" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 text-xs"><button type="button" class="tool" @click="togglePlayback">{{ playing ? 'Pausar vídeo' : 'Reproducir vídeo' }}</button><label class="flex items-center gap-2">Fotograma (s)<input v-model.number="timeline" type="number" min="0" step="0.1" class="field w-24 text-xs" @change="run(() => adapter.seek(timeline))" /></label><span class="text-slate-400">La exportación incluye un fotograma estático.</span></div>
                    <section class="rounded-xl border border-slate-200 bg-white p-3" aria-label="Páginas del diseño"><div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h2 class="text-xs font-semibold">Páginas <span class="font-normal text-slate-400">{{ documentData.pages.length }}/100</span></h2><div class="flex flex-wrap gap-1"><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length >= 100" @click="pageAction('add')">Añadir página</button><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length >= 100" @click="pageAction('duplicate')">Duplicar página</button><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length < 2" @click="pageAction('delete')">Eliminar página</button></div></div><div class="flex gap-2 overflow-x-auto pb-1"><button v-for="(item,index) in documentData.pages" :key="item.id" type="button" class="min-w-24 max-w-40 shrink-0 rounded-lg border p-2 text-left" :class="pageId === item.id ? 'border-violet-400 bg-violet-50 text-violet-800' : 'border-slate-200'" :aria-pressed="pageId === item.id" :disabled="busy" @click="changePage(item.id)"><span class="block text-[10px] text-slate-400">{{ String(index + 1).padStart(2,'0') }}</span><span class="mt-1 block truncate text-xs font-medium">{{ item.name }}</span></button></div><div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3"><button type="button" class="tool text-xs" :disabled="busy || documentData.pages[0].id === pageId" @click="pageAction('move',-1)">← Mover página</button><button type="button" class="tool text-xs" :disabled="busy || documentData.pages.at(-1).id === pageId" @click="pageAction('move',1)">Mover página →</button></div></section>
                </main>

                <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto" aria-label="Panel de edición"><div class="mb-5 flex gap-1 rounded-lg bg-slate-100 p-1"><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'layers' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'layers'">Capas</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'properties' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'properties'">Propiedades</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'page' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'page'">Página</button></div><LayerPanel v-show="panel === 'layers'" :layers="layers" :selection="selection" @select="selectLayer" @patch="patchLayer" @action="action" /><Inspector v-show="panel === 'properties'" :selection="selection" :kit="kit" @patch="patchSelection" @action="action" /><section v-show="panel === 'page'" class="space-y-4 text-xs"><h2 class="text-sm font-semibold">Ajustes de página</h2><label class="block space-y-1">Nombre de la página<input class="field text-xs" :value="page.name" maxlength="255" @change="patchPage({name:$event.target.value})" /></label><label class="flex items-center justify-between gap-2">Fondo de página<input type="color" class="h-8 w-10" :value="typeof page.background === 'string' ? page.background.slice(0,7) : page.background.stops[0].color.slice(0,7)" :disabled="!ready" @input="action('setBackground',$event.target.value)" /></label><label class="block space-y-1">Duración de página (s)<input type="number" class="field text-xs" min="0.1" max="3600" step="0.1" :value="page.duration" @change="patchPage({duration:Number($event.target.value)})" /></label><div class="space-y-3 border-t border-slate-100 pt-4"><h3 class="font-semibold">Tamaño de todo el diseño</h3><div class="grid grid-cols-2 gap-2"><label>Ancho del diseño<input v-model.number="designWidth" type="number" min="40" max="8000" class="field mt-1 text-xs" /></label><label>Alto del diseño<input v-model.number="designHeight" type="number" min="40" max="8000" class="field mt-1 text-xs" /></label></div><button type="button" class="tool w-full text-xs" :disabled="busy || !ready" @click="resizeDesign">Aplicar tamaño</button><p class="leading-relaxed text-slate-400">Las capas conservan sus posiciones y tamaño al cambiar el lienzo.</p></div><label class="flex items-center gap-2 border-t border-slate-100 pt-4"><input v-model="template" type="checkbox" @change="commit" />Guardar como plantilla de marca</label></section></aside>
            </div>
        </div>
        <ExportDialog ref="exporter" v-model="exportOpen" :document="documentData" :assets="localAssets" :design-id="design.id" :prepare="prepareExport" @busy="exportBusy = $event" />
    </AuthenticatedLayout>
</template>
<style scoped>
.studio-stage{background-color:#eef0f5;background-image:radial-gradient(#c5c9d3 .7px,transparent .7px);background-size:14px 14px}
.tool{display:inline-flex;align-items:center;justify-content:center;min-height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;background:#fff;color:#475569}
.tool:hover{background:#f8fafc;border-color:#c4b5fd}.tool:disabled{opacity:.4;cursor:not-allowed}
</style>
