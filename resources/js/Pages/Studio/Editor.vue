<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Inspector from '@/Components/Studio/Inspector.vue';
import LayerPanel from '@/Components/Studio/LayerPanel.vue';
import ExportDialog from '@/Components/Studio/ExportDialog.vue';
import PresentationDialog from '@/Components/Studio/PresentationDialog.vue';
import StudioDialog from '@/Components/Studio/StudioDialog.vue';
import StudioCollabPanel from '@/Components/Studio/StudioCollabPanel.vue';
import VariantsDialog from '@/Components/Studio/VariantsDialog.vue';
import StudioFontsDialog from '@/Components/Studio/StudioFontsDialog.vue';
import StudioChartsDialog from '@/Components/Studio/StudioChartsDialog.vue';
import { createChartResources } from '@/Studio/chart-resources';
import StudioDatasetsDialog from '@/Components/Studio/StudioDatasetsDialog.vue';
import StudioImageJob from '@/Components/Studio/StudioImageJob.vue';
import StudioAiPanel from '@/Components/Studio/StudioAiPanel.vue';
import TimelinePanel from '@/Components/Studio/TimelinePanel.vue';
import { blankDocument, cloneDocument, duplicatePage, findPage, validateDocument } from '@/Studio/document';
import { createStudioCanvas } from '@/Studio/canvas';
import { createHistory } from '@/Studio/history';
import { studioRequest, uploadStudioFile, downloadStudioBlob } from '@/Studio/api';
import { STUDIO_RESIZE_MODES } from '@/Studio/format-presets';
import { clipMediaState, compileTimeline, splitTimelineElement, splitTimelineAudio } from '@/Studio/timeline';
import { createTimelinePreview } from '@/Studio/timeline-preview';
import { completeStudioAudio, isStudioAudio, uploadStudioAudio } from '@/Studio/audio-api';
import { captionOperation } from '@/Studio/captions';

const props = defineProps({ design: Object, brand: Object, kit: Object, assets: Array, folders: Array, ai_configured: { type: Boolean, default: false }, exports: Array, fonts: { type: Array, default: () => [] }, access: { type: Object, default: () => ({ role: 'owner', role_label: 'Propietario', is_owner: true, can_edit: true, can_comment: true, owner_name: '' }) } });
const documentData = ref(validateDocument(props.design.document));
const name = ref(props.design.name);
const template = ref(props.design.is_template);
const revision = ref(props.design.revision);
const kitRevision = ref(props.design.revision);
const pageId = ref(documentData.value.pages[0].id);
const page = computed(() => findPage(documentData.value, pageId.value) ?? documentData.value.pages[0]);
const selection = ref([]);
const layers = ref(page.value.elements);
const panel = ref('layers');
const leftPanel = ref('elements');
const localAssets = ref(props.assets ?? []);
const localFonts = ref([...props.fonts]);
const fontsOpen = ref(false);
const datasetsOpen = ref(false);
const chartsOpen = ref(false);
const chartResources = shallowRef(null);
const chartDraft = ref(null);
const chartSource = ref(null);
let chartTarget = null;
function editChart() {
    if (selection.value.length !== 1 || selection.value[0].type !== 'chart' || busy.value) return;
    const item = selection.value[0];
    chartTarget = { id: item.id, pageId: pageId.value, brandId: props.brand.id, designId: props.design.id };
    chartDraft.value = JSON.parse(JSON.stringify(item.chart)); chartSource.value = null; chartsOpen.value = true;
}
function chooseChartData(draft = null) {
    if (draft) chartDraft.value = JSON.parse(JSON.stringify(draft));
    chartsOpen.value = false; datasetsOpen.value = true;
}
function startChart() { chartTarget = null; chartDraft.value = null; chartSource.value = null; chooseChartData(); }
function selectChartData(source) {
    chartSource.value = source;
    if (chartDraft.value) chartDraft.value = { ...chartDraft.value, binding: { ...chartDraft.value.binding, dataset: { ...source.dataset } } };
    datasetsOpen.value = false; chartsOpen.value = true;
}
async function applyChart(chart) {
    const target = chartTarget, resources = chartResources.value;
    return run(async () => {
        if (disposed || busy.value || !ready.value || !resources) return;
        if (target && (target.brandId !== props.brand.id || target.designId !== props.design.id || target.pageId !== pageId.value || selection.value.length !== 1 || selection.value[0].id !== target.id)) throw new Error('La selección cambió. Abre de nuevo el gráfico para editarlo.');
        busy.value = true;
        try {
            if (target) await adapter.updateSelection({ chart });
            else await adapter.addChart(chart);
            refreshLayers();
        } finally { busy.value = false; }
    });
}
watch(() => [props.brand.id, props.design.id], () => {
    closePresentation();
    chartsOpen.value = datasetsOpen.value = false; chartTarget = null; chartSource.value = null; chartDraft.value = null;
    chartResources.value?.dispose(); chartResources.value = null;
    invalidatePreview(); ready.value = false; adapter?.destroy();
    error.value = 'El contexto del diseño cambió. Abre el diseño de esta marca para continuar.';
});
const imageJobSource = ref(null);
const aiPresetId = ref(null);
const aiPresetToken = ref(0);
const imageJobToken = ref(0);
const imageBusy = ref(false);
const assetQuery = ref('');
const visibleAssets = computed(() => localAssets.value.filter(asset => asset.name?.toLocaleLowerCase().includes(assetQuery.value.toLocaleLowerCase())));
const canvasElement = ref(null);
const stage = ref(null);
const exporter = ref(null);
const fileInput = ref(null);
const ready = ref(false);
const busy = ref(false);
const fontBusy = ref(false);
const exportBusy = ref(false);
const error = ref('');
const uploadStatus = ref('');
const pendingAudio = ref(null);
const selectedAudioId = ref('');
const status = ref('Guardado');
const conflict = ref(false);
const dirty = ref(false);
const zoom = ref(50);
const fitMode = ref(true);
const drawing = ref(false);
const drawColor = ref(props.kit?.palette?.[0] ?? '#7c3aed');
const drawWidth = ref(8);
const exportOpen = ref(false);
const collabOpen = ref(false);
const collabElement = computed(() => selection.value.length === 1 ? (selection.value[0].id ?? null) : null);
async function openCollab() {
    if (dirty.value && !conflict.value) await saveNow();
    collabOpen.value = true;
}
async function restoredVersion(design) {
    await run(async () => { await acceptDesignMutation(design); collabOpen.value = false; automationMessage.value = 'Versión restaurada como una versión nueva.'; });
}
async function saveAsCopy() {
    await run(async () => {
        const result = await studioRequest('POST', route('studio.copies.store', props.design.id), { name: `${name.value.trim() || 'Diseño sin título'} (mi copia)`, document: validateDocument(snapshot().document) });
        dirty.value = false;
        router.visit(result.edit_url);
    });
}
const presentationOpen = ref(false), presentationOpening = ref(false), presentationDocument = shallowRef(null), presentationDialog = ref(null);
const notesValue = ref(page.value.notes ?? ''), notesError = ref('');
const syncNotes = () => { notesValue.value = page.value.notes ?? ''; notesError.value = ''; };
watch(() => page.value.id, syncNotes, { flush: 'sync' });
watch(() => page.value.notes, syncNotes, { flush: 'sync' });
let presentationGeneration = 0, canvasPlaybackPromise = null;
function closePresentation() {
    ++presentationGeneration;
    presentationDialog.value?.close();
    presentationOpen.value = false; presentationOpening.value = false;
}
async function openPresentation() {
    if (presentationOpening.value || presentationOpen.value) return;
    const token = ++presentationGeneration;
    presentationOpening.value = true; error.value = '';
    invalidatePreview(); adapter?.pause(); playing.value = false;
    try {
        await canvasPlaybackPromise?.catch(() => {});
        if (disposed || token !== presentationGeneration) return;
        adapter?.pause();
        await previewPromise?.catch(() => {});
        if (disposed || token !== presentationGeneration) return;
        await previewCleanup;
        if (disposed || token !== presentationGeneration) return;
        if (!await saveNow()) throw new Error(notesError.value || 'Guarda o resuelve el conflicto antes de presentar.');
        if (disposed || token !== presentationGeneration) return;
        presentationDocument.value = cloneDocument(documentData.value);
        presentationOpen.value = true;
    } catch (cause) { if (!disposed && token === presentationGeneration) error.value = cause.message; }
    finally { if (token === presentationGeneration) presentationOpening.value = false; }
}
function patchNotes(event, ownerId) {
    const value = event.target.value; notesValue.value = value;
    try {
        if (Array.from(value).length > 20000) throw new Error('Las notas admiten hasta 20.000 caracteres Unicode. Reduce el texto antes de guardar o cambiar de página.');
        const next = cloneDocument(documentData.value);
        Object.assign(findPage(next, ownerId), { notes: value });
        validateDocument(next);
        notesError.value = ''; patchPage({ notes: value }, ownerId);
    } catch (cause) { notesError.value = cause.message; error.value = cause.message; }
}
const playing = ref(false);
const timeline = ref(0);
const timelineOpen = ref(documentData.value.schema_version === 2);
const previewVisible = ref(false);
const previewBusy = ref(false);
const previewElement = ref(null);
const timelineDuration = computed(() => documentData.value.schema_version === 2 ? compileTimeline(documentData.value).duration : 0);
const audioProjectDuration = computed(() => documentData.value.pages.reduce((total, page) => total + page.duration - (page.transition?.duration ?? 0), 0));
const timelinePageStart = computed(() => documentData.value.schema_version === 2 ? compileTimeline(documentData.value).pages.find(item => item.id === pageId.value)?.start ?? 0 : 0);
const selectedClipId = computed(() => selection.value.length === 1 ? selection.value[0].id : null);
const designWidth = ref(documentData.value.width);
const designHeight = ref(documentData.value.height);
const resizeMode = ref('contain');
const automationBusy = ref(false);
const templateSaveOpen = ref(false);
const templateName = ref('');
const templateCreated = ref(null);
const variantsOpen = ref(false);
const variantsCreated = ref([]);
const templateFields = ref(props.design.template_fields ?? []);
const automationMessage = ref('');
const historyVersion = ref(0);
const snapshot = () => ({ document: cloneDocument(documentData.value), name: name.value, is_template: template.value, kit_revision: kitRevision.value });
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
let preview = null;
let previewPromise = null;
let previewCleanup = Promise.resolve();
let previewGeneration = 0;
let clockGeneration = 0;
let clockFrame = null;

function pauseTimeline(hide = false) {
    playing.value = false; ++clockGeneration;
    if (clockFrame !== null) cancelAnimationFrame(clockFrame);
    clockFrame = null; preview?.pause();
    if (hide) previewVisible.value = false;
}
function invalidatePreview() {
    pauseTimeline(true); ++previewGeneration;
    const old = preview; preview = null;
    if (old) previewCleanup = previewCleanup.then(() => old.dispose());
}
async function ensurePreview() {
    if (preview) return preview;
    if (previewPromise) return previewPromise;
    const generation = previewGeneration;
    previewPromise = (async () => {
        await previewCleanup; await nextTick();
        const created = await createTimelinePreview(previewElement.value, { document: documentData.value, assets: localAssets.value, fonts: localFonts.value, chartResources: chartResources.value });
        if (disposed || generation !== previewGeneration) { await created.dispose(); throw new Error('El diseño cambió durante la previsualización.'); }
        preview = created; return created;
    })().finally(() => { previewPromise = null; });
    return previewPromise;
}
async function seekTimeline(seconds) {
    pauseTimeline();
    return run(async () => {
        timeline.value = Math.max(0, Math.min(timelineDuration.value, seconds));
        previewBusy.value = true; previewVisible.value = true;
        try { await (await ensurePreview()).seek(timeline.value); }
        finally { previewBusy.value = false; }
    });
}
async function playTimeline() {
    if (presentationOpen.value || presentationOpening.value) return;
    if (playing.value) { pauseTimeline(); return; }
    const generation = ++clockGeneration;
    await run(async () => {
        if (timeline.value >= timelineDuration.value) timeline.value = 0;
        previewBusy.value = true;
        let instance;
        try {
            instance = await ensurePreview();
            await instance.seek(timeline.value);
        }
        finally { previewBusy.value = false; }
        if (disposed || generation !== clockGeneration || !timelineOpen.value) return;
        previewVisible.value = true; playing.value = true;
        let origin = performance.now() - timeline.value * 1000;
        const frame = async () => {
            if (disposed || generation !== clockGeneration || !playing.value) return;
            const time = Math.min(timelineDuration.value, (performance.now() - origin) / 1000);
            try {
                const result = await instance.seek(time, { play: time < timelineDuration.value });
                if (generation !== clockGeneration || disposed) return;
                origin += result?.bufferingMs ?? 0;
                timeline.value = time;
                if (time >= timelineDuration.value) { pauseTimeline(); return; }
                clockFrame = requestAnimationFrame(frame);
            } catch (e) { pauseTimeline(); error.value = e.message; }
        };
        await frame();
    });
}
async function openTimeline() {
    if (timelineOpen.value) { timelineOpen.value = false; pauseTimeline(true); return; }
    await run(async () => {
        const selected = selectedClipId.value;
        if (documentData.value.schema_version === 1) {
            documentData.value = validateDocument({ ...cloneDocument(documentData.value), schema_version: 2, timeline: { fps: 30, audio_tracks: [] } });
            commit(); await reloadCanvas();
            if (selected) adapter.select(selected);
        }
        timelineOpen.value = true;
    });
}
async function applyTimelineChange(next, selected = selectedClipId.value) {
    documentData.value = validateDocument(next); commit();
    timeline.value = Math.min(timeline.value, timelineDuration.value);
    await reloadCanvas(); if (selected) adapter.select(selected);
}
async function applyCaptions(operation) {
    if (busy.value || playing.value || conflict.value || exportBusy.value || disposed) throw new Error('Espera a que termine la operación actual antes de editar subtítulos.');
    await applyTimelineChange(captionOperation(documentData.value, operation));
}
function patchClipWindow(patch) {
    return run(async () => {
        const next = cloneDocument(documentData.value);
        const clip = findPage(next, pageId.value).elements.find(item => item.id === selectedClipId.value);
        if (!clip) return;
        const old = clip.timing ?? { start: 0, duration: page.value.duration };
        const timing = { ...old, ...patch };
        if (patch.start !== undefined) timing.duration = Math.min(timing.duration, page.value.duration - timing.start);
        if (clip.gainPoints && old.duration !== timing.duration) clip.gainPoints = clip.gainPoints.map(point => ({ ...point, time: point.time / old.duration * timing.duration }));
        clip.timing = timing;
        await applyTimelineChange(next);
    });
}
function splitClip() {
    return run(async () => {
        const plan = compileTimeline(documentData.value);
        const pageStart = plan.pages.find(item => item.id === pageId.value).start;
        const clip = page.value.elements.find(item => item.id === selectedClipId.value);
        const sourceDuration = clip?.type === 'video' ? Number(localAssets.value.find(asset => asset.id === clip.asset_id)?.duration) : undefined;
        const next = splitTimelineElement(documentData.value, pageId.value, selectedClipId.value, timeline.value - pageStart, { sourceDuration });
        await applyTimelineChange(next);
    });
}
function patchTimelineFps(fps) { return run(() => applyTimelineChange({ ...cloneDocument(documentData.value), timeline: { ...documentData.value.timeline, fps } })); }

function addAudioTrack(asset) {
    return run(async () => {
        pauseTimeline(true);
        const next = cloneDocument(documentData.value);
        if (next.schema_version === 1) { next.schema_version = 2; next.timeline = { fps: 30, audio_tracks: [] }; }
        const total = audioProjectDuration.value;
        const start = timeline.value < total - 1 / next.timeline.fps ? timeline.value : 0;
        const id = crypto.randomUUID();
        next.timeline.audio_tracks.push({ id, asset_id: asset.id, start, duration: Math.min(Number(asset.duration), total - start),
            trimStart: 0, trimEnd: null, playbackRate: 1, sourceOffset: 0, loop: false, volume: 1, muted: false });
        await applyTimelineChange(next); selectedAudioId.value = id; timelineOpen.value = true;
    });
}
function addLibraryAsset(asset) { return asset.kind === 'audio' ? addAudioTrack(asset) : action('addAsset', asset); }
function patchAudio(patch) {
    return run(async () => {
        const next = cloneDocument(documentData.value);
        const track = next.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
        if (!track) return;
        const duration = track.duration;
        Object.assign(track, patch);
        if (patch.start !== undefined) track.duration = Math.min(track.duration, audioProjectDuration.value - track.start);
        if (track.gainPoints && duration !== track.duration) track.gainPoints = track.gainPoints.map(point => ({ ...point, time: point.time / duration * track.duration }));
        const asset = localAssets.value.find(asset => asset.id === track.asset_id);
        clipMediaState(track, track.start, Number(asset?.duration));
        await applyTimelineChange(next);
    });
}
function fadeAudio(fades) {
    const track = documentData.value.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
    if (!track) return;
    if (![fades.in, fades.out].every(value => Number.isFinite(value) && value >= 0) || fades.in + fades.out > track.duration) {
        error.value = 'Los fundidos deben quedar dentro de la duración del audio.'; return;
    }
    const points = new Map([[0, fades.in ? 0 : 1], [track.duration, fades.out ? 0 : 1]]);
    if (fades.in) points.set(fades.in, 1);
    if (fades.out) points.set(track.duration - fades.out, 1);
    return patchAudio({ gainPoints: [...points].sort(([a], [b]) => a - b).map(([time, value]) => ({ time, value })) });
}
function splitAudio() {
    return run(async () => {
        const track = documentData.value.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
        if (!track) return;
        const asset = localAssets.value.find(asset => asset.id === track.asset_id);
        await applyTimelineChange(splitTimelineAudio(documentData.value, track.id, timeline.value, { sourceDuration: Number(asset?.duration) }));
    });
}
function removeAudio() {
    return run(async () => {
        const next = cloneDocument(documentData.value);
        next.timeline.audio_tracks = next.timeline.audio_tracks.filter(item => item.id !== selectedAudioId.value);
        await applyTimelineChange(next); selectedAudioId.value = '';
    });
}

function scheduleSave() {
    clearTimeout(timer);
    if (!conflict.value) timer = setTimeout(() => { saveNow(true); }, 1200);
}
function commit() {
    invalidatePreview();
    history.commit(snapshot()); historyVersion.value++;
    changeVersion++; dirty.value = true; status.value = conflict.value ? 'Conflicto de revisión' : 'Cambios pendientes';
    scheduleSave();
}
// `autosave === true` marca el guardado automatico: el servidor lo funde con la revision reciente del mismo autor (A-07).
async function saveNow(autosave = false) {
    clearTimeout(timer);
    if (notesError.value) { error.value = notesError.value; return false; }
    if (conflict.value) return false;
    if (pendingSave) { const success = await pendingSave; if (!success || conflict.value) return false; return dirty.value ? saveNow(autosave) : true; }
    if (!dirty.value) return true;
    const version = changeVersion;
    const saved = snapshot();
    status.value = 'Guardando…';
    pendingSave = (async () => {
        try {
            const result = await studioRequest('PATCH', route('studio.designs.update', props.design.id), { name: saved.name.trim() || 'Diseño sin título', document: validateDocument(saved.document), revision: revision.value, is_template: saved.is_template, folder_id: props.design.folder_id, kit_revision: saved.kit_revision, autosave: autosave === true });
            revision.value = result.design.revision;
            templateFields.value = result.design.template_fields ?? [];
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
    if (success && dirty.value) return saveNow(autosave);
    return success;
}
async function prepareExport() {
    pauseTimeline(true);
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
    invalidatePreview();
    busy.value = true; playing.value = false;
    try { await adapter.loadDocument(documentData.value, pageId.value); refreshLayers(); if (fitMode.value) fit(); }
    finally { busy.value = false; }
}
async function restoreHistory(mode) {
    await run(async () => {
        const state = history[mode]();
        if (!state) return;
        documentData.value = state.document; name.value = state.name; template.value = state.is_template; kitRevision.value = state.kit_revision;
        if (!findPage(documentData.value, pageId.value)) pageId.value = documentData.value.pages[0].id;
        designWidth.value = documentData.value.width; designHeight.value = documentData.value.height;
        changeVersion++; historyVersion.value++; dirty.value = true; status.value = 'Cambios pendientes'; scheduleSave();
        await reloadCanvas();
    });
}
const action = (method, value) => run(async () => {
    if (busy.value) return;
    busy.value = true;
    try { await adapter?.[method](value); refreshLayers(); }
    finally { busy.value = false; }
});
const patchSelection = patch => run(async () => {
    if (fontBusy.value) return;
    const loadsFont = Object.hasOwn(patch, 'font_id');
    if (loadsFont) fontBusy.value = true;
    try { await adapter.updateSelection(patch); refreshLayers(); }
    finally { if (loadsFont) fontBusy.value = false; }
});
const addText = (content) => run(async () => {
    if (fontBusy.value) return;
    const fontId = props.kit?.font_ids?.[0];
    if (fontId) fontBusy.value = true;
    try { await adapter.addText(typeof content === 'string' ? content : 'Escribe tu texto', { fontFamily: props.kit?.fonts?.[0] ?? 'Arial', ...(fontId ? { font_id: fontId } : {}), fill: props.kit?.palette?.[0] ?? '#111827' }); refreshLayers(); }
    finally { if (fontId) fontBusy.value = false; }
});
const addShape = type => action('addShape', type);
function selectLayer(id, multiple = false) {
    pauseTimeline(true);
    run(() => { const ids = new Set(multiple ? selection.value.map(item => item.id) : []); if (ids.has(id)) ids.delete(id); else ids.add(id); adapter.select([...ids]); });
}
function patchLayer(id, patch) {
    run(async () => {
        const layer = layers.value.find(item => item.id === id);
        adapter.select(id);
        if (layer.locked && patch.locked === undefined) await adapter.updateSelection({ ...patch, locked: false });
        else await adapter.updateSelection(patch);
        if (layer.locked && patch.locked === undefined) await adapter.lock(true);
        refreshLayers();
    });
}
async function changePage(id) { if (notesError.value) { error.value = notesError.value; return; } pageId.value = id; await run(reloadCanvas); }
async function pageAction(mode, offset = 0) {
    if (notesError.value) { error.value = notesError.value; return; }
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
function patchPage(patch, ownerId = pageId.value) {
    return run(() => { const next = cloneDocument(documentData.value); Object.assign(findPage(next, ownerId), patch); documentData.value = validateDocument(next); commit(); });
}
async function savedOperation(operation) {
    if (automationBusy.value || busy.value || exportBusy.value || conflict.value) return;
    automationBusy.value = true; error.value = ''; automationMessage.value = '';
    try {
        await prepareExport();
        return await operation();
    } catch (e) {
        error.value = e.message;
        if (e.status === 409) { conflict.value = true; status.value = 'Conflicto de revisión'; }
    } finally { automationBusy.value = false; }
}
async function acceptDesignMutation(design) {
    if (design.fonts) await updateFonts({ fonts: design.fonts });
    documentData.value = validateDocument(design.document); revision.value = design.revision;
    kitRevision.value = design.revision;
    name.value = design.name; template.value = design.is_template; templateFields.value = design.template_fields ?? [];
    designWidth.value = documentData.value.width; designHeight.value = documentData.value.height;
    if (!findPage(documentData.value, pageId.value)) pageId.value = documentData.value.pages[0].id;
    history.commit(snapshot()); historyVersion.value++; dirty.value = false; status.value = 'Guardado';
    await reloadCanvas(); fit();
}
function resizeDesign() {
    return savedOperation(async () => {
        const result = await studioRequest('POST', route('studio.designs.resize', props.design.id), { revision: revision.value, width: Number(designWidth.value), height: Number(designHeight.value), mode: resizeMode.value });
        await acceptDesignMutation(result.design);
        automationMessage.value = 'Tamaño actualizado en todas las páginas.';
    });
}
function openTemplateSave() {
    templateName.value = `${name.value.slice(0, 240)} — plantilla`; templateCreated.value = null; error.value = ''; templateSaveOpen.value = true;
}
function saveTemplate() {
    return savedOperation(async () => {
        const result = await studioRequest('POST', route('studio.designs.template', props.design.id), { revision: revision.value, name: templateName.value.trim() });
        templateCreated.value = result.design;
    });
}
function openVariants() {
    return savedOperation(async () => { variantsCreated.value = []; variantsOpen.value = true; });
}
function createVariants(payload) {
    return savedOperation(async () => {
        const result = await studioRequest('POST', route('studio.designs.variants', props.design.id), { ...payload, revision: revision.value });
        variantsCreated.value = result.designs;
    });
}
function applyBrandKit() {
    return savedOperation(async () => {
        const result = await studioRequest('POST', route('studio.designs.apply-kit', props.design.id), { revision: revision.value });
        await acceptDesignMutation(result.design);
        automationMessage.value = 'Identidad de marca aplicada. Puedes deshacer el cambio.';
    });
}
async function upload(event) {
    const files = [...(event.target.files ?? [])];
    if (!files.length) return;
    busy.value = true;
    await run(async () => {
        for (const file of files) {
            if (isStudioAudio(file)) {
                try {
                    const asset = await uploadStudioAudio(props.brand.id, file, value => { uploadStatus.value = value; });
                    localAssets.value = [asset, ...localAssets.value.filter(existing => existing.id !== asset.id)];
                    adapter.setAssets(localAssets.value);
                } catch (error) { if (error.audioTicket) pendingAudio.value = error.audioTicket; throw error; }
                continue;
            }
            const uploaded = await uploadStudioFile(file, value => { uploadStatus.value = value; });
            const result = await studioRequest('POST', route('studio.assets.store', props.brand.id), { media_id: uploaded.id });
            localAssets.value = [result.asset, ...localAssets.value.filter(asset => asset.id !== result.asset.id)];
            adapter.setAssets(localAssets.value);
        }
        uploadStatus.value = 'Recursos añadidos a esta marca.';
    });
    busy.value = false; event.target.value = '';
}
async function retryAudioValidation() {
    busy.value = true;
    await run(async () => {
        const asset = await completeStudioAudio(pendingAudio.value);
        localAssets.value = [asset, ...localAssets.value.filter(existing => existing.id !== asset.id)];
        adapter.setAssets(localAssets.value); pendingAudio.value = null; uploadStatus.value = 'Audio validado y listo.';
    });
    busy.value = false;
}
async function updateFonts({ fonts = [], deletedIds = [] }) {
    const next = new Map(localFonts.value.map(font => [font.id, font]));
    fonts.forEach(font => next.set(font.id, font)); deletedIds.forEach(id => next.delete(id));
    localFonts.value = [...next.values()];
    await run(async () => { await adapter?.setFonts(localFonts.value); });
}
function registerImageResult(asset) {
    localAssets.value = [asset, ...localAssets.value.filter(existing => existing.id !== asset.id)];
    adapter?.setAssets(localAssets.value);
}
function removeBackground(assetId) {
    if (imageBusy.value) return;
    const asset = localAssets.value.find(entry => entry.id === assetId && entry.kind === 'image');
    if (!asset) { error.value = 'Selecciona una imagen de los recursos de esta marca.'; return; }
    imageJobSource.value = asset; imageJobToken.value++; leftPanel.value = 'assets';
}
function editWithAi(assetId) { aiPresetId.value = assetId; aiPresetToken.value++; leftPanel.value = 'ai'; }
function toggleDrawing() { drawing.value = !drawing.value; adapter?.setDrawing(drawing.value, drawColor.value, drawWidth.value); }
async function togglePlayback() {
    if (presentationOpen.value || presentationOpening.value) return;
    const token = presentationGeneration;
    canvasPlaybackPromise = run(async () => {
        if (playing.value) { adapter.pause(); playing.value = false; }
        else { await adapter.play(); if (disposed || token !== presentationGeneration) { adapter.pause(); return; } playing.value = true; }
    });
    try { await canvasPlaybackPromise; } finally { canvasPlaybackPromise = null; }
}
function downloadLocal() { downloadStudioBlob(new Blob([JSON.stringify(snapshot(), null, 2)], { type: 'application/json' }), `${name.value}-copia-local.json`); }
async function recover() {
    await run(async () => {
        const latest = await studioRequest('GET', route('studio.designs.show', props.design.id));
        if (latest.design.fonts || latest.fonts) await updateFonts({ fonts: latest.design.fonts ?? latest.fonts });
        documentData.value = validateDocument(latest.design.document); name.value = latest.design.name; revision.value = latest.design.revision; template.value = latest.design.is_template; kitRevision.value = latest.design.revision;
        templateFields.value = latest.design.template_fields ?? [];
        pageId.value = documentData.value.pages[0].id; conflict.value = false; dirty.value = false; status.value = 'Guardado'; error.value = ''; history.reset(snapshot()); historyVersion.value++;
        await reloadCanvas();
    });
}
function beforeUnload(event) { if (dirty.value || pendingSave || automationBusy.value || fontBusy.value) { event.preventDefault(); event.returnValue = ''; } }
function onKey(event) {
    if (presentationOpen.value || presentationOpening.value || automationBusy.value || fontBusy.value) return;
    if (/INPUT|TEXTAREA|SELECT/.test(event.target?.tagName) || event.target?.isContentEditable || adapter?.canvas.getActiveObject()?.isEditing) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); restoreHistory(event.shiftKey ? 'redo' : 'undo'); }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); saveNow(); }
}
onMounted(async () => {
    await nextTick();
    await run(async () => {
        chartResources.value = createChartResources({ brandId: props.brand.id });
        adapter = await createStudioCanvas(canvasElement.value, { chartResources: chartResources.value, document: documentData.value, pageId: pageId.value, assets: localAssets.value, fonts: localFonts.value, onChange(elements, meta) {
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
        if ((automationBusy.value || fontBusy.value) && event.detail.visit.method === 'get') { event.preventDefault(); return; }
        if (!dirty.value || navigating || event.detail.visit.method !== 'get') return;
        event.preventDefault();
        saveNow().then(success => { if (success && !disposed) { navigating = true; router.visit(event.detail.visit.url); } });
    });
});
onBeforeUnmount(() => { closePresentation(); disposed = true; chartResources.value?.dispose(); chartResources.value = null; invalidatePreview(); clearTimeout(timer); resizeObserver?.disconnect(); removeNavigate?.(); window.removeEventListener('beforeunload', beforeUnload); window.removeEventListener('keydown', onKey); adapter?.destroy(); });
</script>

<template>
    <PresentationDialog v-if="presentationOpen" ref="presentationDialog" :document="presentationDocument" :assets="localAssets" :fonts="localFonts" :chart-resources="chartResources" @close="closePresentation" />
    <Head :title="`${name} · Estudio`" />
    <AuthenticatedLayout>
        <div class="-m-3 min-w-0 space-y-3 sm:-m-1">
            <header class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3" :inert="fontBusy || undefined">
                <Link :href="route('studio.index', { brand_id: brand.id })" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Volver al estudio"><Icon name="chevronLeft" class="h-4 w-4" /></Link>
                <div class="min-w-0 flex-1"><label class="sr-only" for="studio-design-name">Nombre del diseño</label><input id="studio-design-name" v-model="name" class="w-full min-w-0 rounded-md border-transparent bg-transparent px-1 py-0.5 text-sm font-semibold focus:border-violet-300 focus:ring-violet-200" maxlength="255" :disabled="automationBusy" @input="commit" /><div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-[10px] text-slate-500"><span>{{ brand.name }}</span><span data-testid="studio-save-status" role="status" :class="conflict ? 'text-red-700' : dirty ? 'text-amber-700' : 'text-emerald-700'">{{ status }}</span><span>Revisión {{ revision }}</span></div></div>
                <div class="flex flex-wrap items-center gap-2"><button type="button" class="tool" :disabled="!canUndo || busy || automationBusy" aria-label="Deshacer" title="Deshacer (Ctrl+Z)" @click="restoreHistory('undo')">↶</button><button type="button" class="tool" :disabled="!canRedo || busy || automationBusy" aria-label="Rehacer" title="Rehacer (Ctrl+Mayús+Z)" @click="restoreHistory('redo')">↷</button><button type="button" class="btn-secondary text-xs" :disabled="conflict || automationBusy" @click="saveNow">Guardar ahora</button><button type="button" class="btn-secondary text-xs" :disabled="!ready || busy || exportBusy || automationBusy || presentationOpening" @click="openPresentation">{{ presentationOpening ? 'Preparando...' : 'Presentar' }}</button><button v-if="access.is_owner" type="button" class="btn-secondary text-xs" :disabled="!ready || busy || exportBusy || automationBusy" @click="exportOpen = true">Exportar</button><button v-if="access.is_owner" type="button" class="rounded-lg bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white hover:bg-violet-800 disabled:opacity-50" :disabled="!ready || busy || exportBusy || automationBusy" @click="exporter.publish()">Usar en publicación</button></div>
            </header>
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2" aria-label="Plantillas y formatos" :inert="fontBusy || undefined">
                <button type="button" class="tool text-xs" :disabled="!ready || busy || automationBusy" @click="openCollab">Compartir y comentarios</button>
                <button v-if="access.is_owner" type="button" class="tool text-xs" :disabled="!ready || busy || automationBusy || exportBusy || conflict" @click="openTemplateSave">Guardar como plantilla</button>
                <button v-if="access.is_owner" type="button" class="tool text-xs" :disabled="!ready || busy || automationBusy || exportBusy || conflict" @click="openVariants">Crear variantes</button>
                <button v-if="access.is_owner" type="button" class="tool text-xs" :disabled="!ready || busy || automationBusy || exportBusy || conflict" @click="applyBrandKit">Aplicar identidad de marca</button>
                <button type="button" class="tool text-xs" :disabled="busy || automationBusy || exportBusy" @click="startChart">Tablas de datos</button>
                <button type="button" class="tool text-xs" :disabled="busy || automationBusy || exportBusy" @click="fontsOpen = true">Tipografías de marca</button>
                <button type="button" class="tool text-xs" :aria-pressed="timelineOpen" :disabled="!ready || busy || automationBusy || exportBusy" @click="openTimeline">Línea de tiempo</button>
                <span v-if="template" class="ml-auto rounded-full bg-violet-50 px-3 py-1 text-[10px] font-semibold text-violet-800">Plantilla de {{ brand.name }}</span>
                <span v-if="automationBusy" role="status" class="text-xs text-violet-700">Guardando y preparando el diseño…</span>
            </div>
            <p v-if="!access.is_owner" role="status" class="rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-900">Editas el diseño de {{ access.owner_name }} como {{ access.role_label.toLowerCase() }}. Sus exportaciones y plantillas siguen siendo del propietario.</p>
            <p v-if="automationMessage" role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ automationMessage }}</p>
            <p v-if="fontBusy" role="status" class="rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-800">Cargando la tipografía antes de actualizar el texto…</p>
            <div v-if="error" role="alert" class="flex items-start justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><span>{{ error }}</span><button v-if="!conflict" type="button" aria-label="Cerrar error" @click="error = ''">×</button></div>
            <div v-if="conflict" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>Otra sesión ha cambiado este diseño. El guardado automático está detenido para conservar tus cambios locales. Guarda tu versión como copia, descarga una copia local o recupera la versión guardada.</p><div class="flex flex-wrap gap-2"><button type="button" class="btn-primary text-xs" :disabled="busy" @click="saveAsCopy">Guardar como copia</button><button type="button" class="btn-secondary text-xs" @click="downloadLocal">Descargar copia local</button><button type="button" class="btn-secondary text-xs" @click="recover">Recuperar versión guardada</button></div></div>

            <div class="grid min-w-0 items-start gap-3 xl:grid-cols-[190px_minmax(240px,1fr)_260px]" :inert="!ready || automationBusy || fontBusy || undefined">
                <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto" aria-label="Herramientas de diseño">
                    <div class="mb-4 flex gap-1 rounded-lg bg-slate-100 p-1"><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="leftPanel === 'elements' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="leftPanel = 'elements'">Elementos</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="leftPanel === 'assets' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="leftPanel = 'assets'">Recursos</button><button v-if="access.is_owner" type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="leftPanel === 'ai' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="leftPanel = 'ai'">IA</button></div>
                    <div v-if="leftPanel === 'elements'" class="space-y-5"><button type="button" class="flex w-full items-center justify-center gap-2 rounded-xl bg-violet-700 px-3 py-3 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-50" :disabled="!ready || busy" @click="addText"><span class="font-serif text-xl" aria-hidden="true">T</span>Añadir texto</button><button type="button" class="w-full rounded-xl border border-violet-200 bg-violet-50 px-3 py-3 text-sm font-semibold text-violet-800 disabled:opacity-50" :disabled="!ready || busy" @click="startChart">Añadir gráfico con datos</button><div class="grid grid-cols-2 gap-2"><button type="button" class="rounded-xl border border-slate-200 px-2 py-2.5 text-xs font-semibold text-slate-700 hover:border-violet-300 hover:bg-violet-50 disabled:opacity-50" :disabled="!ready || busy" @click="action('addTable')">Añadir tabla</button><button type="button" class="rounded-xl border border-slate-200 px-2 py-2.5 text-xs font-semibold text-slate-700 hover:border-violet-300 hover:bg-violet-50 disabled:opacity-50" :disabled="!ready || busy" title="Con dos elementos seleccionados, los une con una flecha que los sigue al moverlos" @click="action('addConnector')">Añadir conector</button></div><div><h2 class="mb-3 text-xs font-semibold">Formas</h2><div class="grid grid-cols-3 gap-2"><button v-for="shape in [{key:'rect',label:'Rectángulo',symbol:'▭'},{key:'ellipse',label:'Elipse',symbol:'◯'},{key:'triangle',label:'Triángulo',symbol:'△'},{key:'line',label:'Línea',symbol:'╱'},{key:'path',label:'Trazado',symbol:'〰'}]" :key="shape.key" type="button" class="rounded-xl border border-slate-200 py-3 text-2xl text-slate-600 transition hover:border-violet-300 hover:bg-violet-50 disabled:opacity-50" :aria-label="`Añadir ${shape.label.toLowerCase()}`" :title="shape.label" :disabled="!ready || busy" @click="addShape(shape.key)">{{ shape.symbol }}</button></div></div><div class="space-y-3 border-t border-slate-100 pt-4"><button type="button" class="w-full rounded-lg border px-3 py-2 text-xs font-medium" :class="drawing ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200'" :aria-pressed="drawing" :disabled="!ready" @click="toggleDrawing">Dibujo libre</button><div v-if="drawing" class="flex items-center gap-2"><label class="sr-only" for="studio-draw-color">Color del pincel</label><input id="studio-draw-color" v-model="drawColor" type="color" class="h-8 w-10" @input="adapter.setDrawing(true,drawColor,drawWidth)" /><label class="sr-only" for="studio-draw-width">Grosor del pincel</label><input id="studio-draw-width" v-model.number="drawWidth" type="number" min="1" max="200" class="field w-20 text-xs" @change="adapter.setDrawing(true,drawColor,drawWidth)" /></div></div><div class="border-t border-slate-100 pt-4"><h3 class="mb-3 text-xs font-semibold">Colores de {{ brand.name }}</h3><div class="flex flex-wrap gap-2"><button v-for="color in kit?.palette ?? []" :key="color" type="button" class="h-7 w-7 rounded-full border border-slate-200" :style="{ background: color }" :aria-label="`Color de marca ${color}`" :disabled="!selection.length || selection.some(item => item.type === 'chart')" @click="patchSelection({fill:color})" /></div><p class="mt-3 text-[10px] leading-relaxed text-slate-400">Selecciona un elemento para aplicarle un color.</p></div></div>
                    <div v-else-if="leftPanel === 'assets'" class="space-y-3">
                        <button type="button" class="w-full rounded-xl bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white disabled:opacity-50" :disabled="busy" @click="fileInput.click()">Subir recursos</button>
                        <input ref="fileInput" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime,audio/mpeg,audio/wav,audio/mp4,audio/aac,audio/ogg,.mp3,.wav,.m4a,.aac,.ogg" multiple aria-label="Subir recursos de marca" @change="upload" />
                        <label class="sr-only" for="studio-asset-query">Buscar recursos</label><input id="studio-asset-query" v-model="assetQuery" type="search" class="field text-xs" placeholder="Buscar recursos…" />
                        <button v-if="pendingAudio" type="button" class="tool text-xs" :disabled="busy" @click="retryAudioValidation">Reintentar validación del audio</button><p v-if="uploadStatus" role="status" class="text-xs text-violet-700">{{ uploadStatus }}</p>
                        <div class="grid grid-cols-2 gap-2"><article v-for="asset in visibleAssets" :key="asset.id" class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-50"><button type="button" class="block w-full min-w-0 text-left hover:bg-violet-50" :aria-label="`Añadir ${asset.name}`" :disabled="!ready || busy" @click="addLibraryAsset(asset)"><img v-if="asset.kind === 'image'" :src="asset.preview_url" :alt="asset.name" class="aspect-square w-full object-cover" loading="lazy" /><span v-else class="flex aspect-square items-center justify-center text-2xl text-violet-500" aria-hidden="true">{{ asset.kind === 'audio' ? '♫' : '▶' }}</span><span class="block truncate p-2 text-[10px]">{{ asset.name }}</span></button><button v-if="asset.kind === 'image'" type="button" class="block w-full border-t border-slate-200 px-2 py-2 text-[10px] font-medium text-violet-700 hover:bg-violet-50 disabled:opacity-50" :aria-label="`Quitar fondo de ${asset.name}`" :disabled="imageBusy" @click="removeBackground(asset.id)">Quitar fondo</button><button v-if="asset.kind === 'image' && access.is_owner && ai_configured" type="button" class="block w-full border-t border-slate-200 px-2 py-2 text-[10px] font-medium text-violet-700 hover:bg-violet-50" :aria-label="`Editar con IA ${asset.name}`" @click="editWithAi(asset.id)">Editar con IA</button></article></div>
                        <p v-if="!visibleAssets.length" class="py-5 text-xs leading-relaxed text-slate-500">{{ assetQuery ? 'No hay recursos con ese nombre.' : 'Sube imágenes, vídeos o audio para usarlos en los diseños de esta marca.' }}</p>
                    </div>
                    <StudioAiPanel v-if="access.is_owner" v-show="leftPanel === 'ai'" :active="leftPanel === 'ai'" :brand-id="brand.id" :design-id="design.id" :revision="revision" :is-template="template" :assets="localAssets" :preset-media-id="aiPresetId" :preset-token="aiPresetToken" @ready="registerImageResult" @add="action('addAsset',$event)" @text="addText" />
                    <StudioImageJob v-if="imageJobSource" class="mt-4" :brand-id="brand.id" :source-id="imageJobSource.id" :source-name="imageJobSource.name" :request-token="imageJobToken" @busy="imageBusy = $event" @ready="registerImageResult" @add="action('addAsset',$event)" @close="imageJobSource = null" />
                </aside>

                <main class="min-w-0 space-y-3" aria-label="Editor de diseño">
                    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-2"><span class="px-1 text-xs font-medium text-slate-600">{{ selection.length ? `${selection.length} seleccionado(s)` : page.name }}</span><div class="ml-auto flex items-center gap-1"><button type="button" class="tool text-xs" :disabled="!selection.length || busy" aria-label="Duplicar elemento" @click="action('duplicate')">Duplicar</button><button type="button" class="tool text-xs text-red-600" :disabled="!selection.length || busy" aria-label="Eliminar elemento" @click="action('delete')">Eliminar</button><button type="button" class="tool text-xs" :disabled="!selection.length || busy" aria-label="Bloquear elemento" @click="action('lock')">Bloquear</button></div></div>
                    <div ref="stage" class="studio-stage relative flex h-[430px] min-w-0 items-start justify-start overflow-auto rounded-2xl border border-slate-200 p-6 sm:h-[560px] xl:h-[calc(100vh-340px)] xl:min-h-[380px]">
                        <div v-show="!previewVisible" class="m-auto shrink-0 bg-white shadow-[0_10px_40px_-8px_rgba(15,23,42,0.2)]" :style="{ width: `${documentData.width * zoom / 100}px`, height: `${documentData.height * zoom / 100}px` }"><canvas ref="canvasElement" /></div>
                        <div v-show="previewVisible" class="m-auto shrink-0 bg-white shadow-xl" :style="{ width: `${documentData.width * zoom / 100}px`, height: `${documentData.height * zoom / 100}px` }"><canvas ref="previewElement" aria-label="Previsualización de la línea de tiempo" class="h-full w-full" /></div>
                        <div v-if="!ready || busy" role="status" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-slate-100/80 text-sm text-slate-600">{{ busy ? 'Preparando página…' : 'Cargando el lienzo…' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs"><span class="text-slate-500">{{ documentData.width }} × {{ documentData.height }} px</span><div class="flex items-center gap-2"><button type="button" class="tool" aria-label="Reducir zoom" @click="setZoom(zoom - 10)">−</button><label class="sr-only" for="studio-zoom">Zoom</label><input id="studio-zoom" type="number" min="5" max="400" :value="zoom" class="w-16 rounded-lg border-slate-200 py-1 text-xs" @change="setZoom($event.target.value)" /><span>%</span><button type="button" class="tool" aria-label="Aumentar zoom" @click="setZoom(zoom + 10)">+</button><button type="button" class="tool" @click="fit">Ajustar</button></div></div>
                    <TimelinePanel v-if="timelineOpen && documentData.schema_version === 2" :document="documentData" :page="page" :page-start="timelinePageStart" :assets="localAssets" :fonts="localFonts" :kit-fonts="kit.fonts" :apply-captions="applyCaptions" :selected-audio-id="selectedAudioId" @audio-select="selectedAudioId = $event" @audio-patch="patchAudio" @audio-split="splitAudio" @audio-remove="removeAudio" @audio-fade="fadeAudio" :selected-id="selectedClipId" :time="timeline" :duration="timelineDuration" :playing="playing" :busy="busy || previewBusy || automationBusy" @select="selectLayer" @window="patchClipWindow" @seek="seekTimeline" @play="playTimeline" @split="splitClip" @fps="patchTimelineFps" @edit="pauseTimeline(true)" />
                    <div v-else-if="hasVideo && documentData.schema_version === 1" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 text-xs"><button type="button" class="tool" @click="togglePlayback">{{ playing ? 'Pausar vídeo' : 'Reproducir vídeo' }}</button><label class="flex items-center gap-2">Fotograma (s)<input v-model.number="timeline" type="number" min="0" step="0.1" class="field w-24 text-xs" @change="run(() => adapter.seek(timeline))" /></label><span class="text-slate-400">La exportación incluye un fotograma estático.</span></div>
                    <section class="rounded-xl border border-slate-200 bg-white p-3" aria-label="Páginas del diseño"><div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h2 class="text-xs font-semibold">Páginas <span class="font-normal text-slate-400">{{ documentData.pages.length }}/100</span></h2><div class="flex flex-wrap gap-1"><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length >= 100" @click="pageAction('add')">Añadir página</button><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length >= 100" @click="pageAction('duplicate')">Duplicar página</button><button type="button" class="tool text-xs" :disabled="busy || !ready || documentData.pages.length < 2" @click="pageAction('delete')">Eliminar página</button></div></div><div class="flex gap-2 overflow-x-auto pb-1"><button v-for="(item,index) in documentData.pages" :key="item.id" type="button" class="min-w-24 max-w-40 shrink-0 rounded-lg border p-2 text-left" :class="pageId === item.id ? 'border-violet-400 bg-violet-50 text-violet-800' : 'border-slate-200'" :aria-pressed="pageId === item.id" :disabled="busy" @click="changePage(item.id)"><span class="block text-[10px] text-slate-400">{{ String(index + 1).padStart(2,'0') }}</span><span class="mt-1 block truncate text-xs font-medium">{{ item.name }}</span></button></div><div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3"><button type="button" class="tool text-xs" :disabled="busy || documentData.pages[0].id === pageId" @click="pageAction('move',-1)">← Mover página</button><button type="button" class="tool text-xs" :disabled="busy || documentData.pages.at(-1).id === pageId" @click="pageAction('move',1)">Mover página →</button></div></section>
                </main>

                <aside class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto" aria-label="Panel de edición">
                    <div class="mb-5 flex gap-1 rounded-lg bg-slate-100 p-1"><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'layers' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'layers'">Capas</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'properties' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'properties'">Propiedades</button><button type="button" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="panel === 'page' ? 'bg-white shadow-sm' : 'text-slate-500'" @click="panel = 'page'">Página</button></div>
                    <LayerPanel v-show="panel === 'layers'" :layers="layers" :selection="selection" @select="selectLayer" @patch="patchLayer" @action="action" />
                    <Inspector v-show="panel === 'properties'" :selection="selection" :kit="kit" :fonts="localFonts" :image-busy="imageBusy" @patch="patchSelection" @action="action" @remove-background="removeBackground" @edit-chart="editChart" />
                    <section v-show="panel === 'page'" class="space-y-4 text-xs">
                        <h2 class="text-sm font-semibold">Ajustes de página</h2>
                        <label class="block space-y-1">Nombre de la página<input class="field text-xs" :value="page.name" maxlength="255" @change="patchPage({name:$event.target.value})" /></label>
                        <label class="flex items-center justify-between gap-2">Fondo de página<input type="color" class="h-8 w-10" :value="typeof page.background === 'string' ? page.background.slice(0,7) : page.background.stops[0].color.slice(0,7)" :disabled="!ready" @input="action('setBackground',$event.target.value)" /></label>
                        <label class="block space-y-1">Duración de página (s)<input type="number" class="field text-xs" min="0.1" max="3600" step="0.1" :value="page.duration" @change="patchPage({duration:Number($event.target.value)})" /></label>
                        <label class="block space-y-1">Notas del presentador<textarea :key="page.id" class="field min-h-32 text-xs" :value="notesValue" :data-page-id="page.id" :aria-invalid="!!notesError" aria-describedby="studio-notes-count studio-notes-error" @input="patchNotes($event, page.id)" /></label>
                        <p id="studio-notes-count" class="text-slate-500">{{ Array.from(notesValue).length.toLocaleString('es') }} / 20.000 caracteres · Solo visibles para el presentador</p>
                        <p v-if="notesError" id="studio-notes-error" role="alert" class="text-red-700">{{ notesError }}</p>
                        <div class="space-y-3 border-t border-slate-100 pt-4">
                            <h3 class="font-semibold">Tamaño de todo el diseño</h3>
                            <div class="grid grid-cols-2 gap-2"><label>Ancho del diseño<input v-model.number="designWidth" type="number" min="40" max="8000" class="field mt-1 text-xs" /></label><label>Alto del diseño<input v-model.number="designHeight" type="number" min="40" max="8000" class="field mt-1 text-xs" /></label></div>
                            <label class="block space-y-1">Adaptación al redimensionar<select v-model="resizeMode" aria-label="Adaptación al redimensionar" class="field mt-1 text-xs"><option v-for="mode in STUDIO_RESIZE_MODES" :key="mode.value" :value="mode.value">{{ mode.label }}</option></select></label>
                            <button type="button" class="tool w-full text-xs" :disabled="busy || !ready || conflict" @click="resizeDesign">Aplicar tamaño</button>
                            <p class="leading-relaxed text-slate-400">{{ resizeMode === 'contain' ? 'Escala y centra el contenido sin deformarlo. Pueden quedar márgenes.' : resizeMode === 'cover' ? 'Escala sin deformar y llena el lienzo. Las partes exteriores quedan recortadas en la exportación.' : 'Conserva las posiciones y el tamaño de las capas.' }}</p>
                        </div>
                        <div class="space-y-2 border-t border-slate-100 pt-4"><p class="font-medium">{{ template ? 'Plantilla de marca' : 'Contenido reutilizable' }}</p><p class="leading-relaxed text-slate-500">Para crear campos sustituibles, escribe un nombre entre llaves dobles en un texto, por ejemplo <code class="rounded bg-slate-100 px-1" v-text="'{{nombre}}'" />. Guarda una copia como plantilla para reutilizarla.</p></div>
                    </section>
                </aside>
            </div>
        </div>
        <ExportDialog ref="exporter" v-model="exportOpen" :document="documentData" :assets="localAssets" :fonts="localFonts" :design-id="design.id" :chart-resources="chartResources" :prepare="prepareExport" :playhead="timeline" @busy="exportBusy = $event" />
        <StudioFontsDialog v-model="fontsOpen" :brand-id="brand.id" @changed="updateFonts" />
        <StudioDatasetsDialog v-model="datasetsOpen" :brand-id="brand.id" :brand-name="brand.name" selectable @select-chart="selectChartData" />
        <StudioChartsDialog v-model="chartsOpen" :brand-id="brand.id" :chart="chartDraft" :source="chartSource" :chart-resources="chartResources" :fonts="localFonts" :kit="kit" @choose-data="chooseChartData" @apply="applyChart" />
        <StudioDialog :model-value="templateSaveOpen" title="Guardar como plantilla" @update:model-value="value => { if (!automationBusy) templateSaveOpen = value; }">
            <div v-if="templateCreated" class="space-y-5"><p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">Plantilla guardada en {{ brand.name }}. Tu diseño original se conserva.</p><div class="flex flex-wrap justify-end gap-2"><button type="button" class="btn-secondary" @click="templateSaveOpen = false">Cerrar plantilla</button><Link :href="route('studio.designs.edit', templateCreated.id)" class="btn-primary bg-violet-700">Ver plantilla</Link></div></div>
            <form v-else class="space-y-5" @submit.prevent="saveTemplate"><p class="text-sm leading-relaxed text-slate-500">Guarda una copia independiente de todas las páginas, recursos y campos para los próximos diseños de esta marca.</p><label class="block text-sm font-medium">Nombre de la plantilla<input v-model="templateName" class="field mt-1" maxlength="255" required autofocus :disabled="automationBusy" /></label><p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p><div class="flex justify-end"><button type="submit" class="btn-primary bg-violet-700" :disabled="automationBusy || !templateName.trim()">{{ automationBusy ? 'Guardando…' : 'Guardar plantilla' }}</button></div></form>
        </StudioDialog>
        <StudioDialog v-model="collabOpen" title="Compartir y comentarios" wide>
            <StudioCollabPanel v-if="collabOpen" :design-id="design.id" :access="access" :pages="documentData.pages.map((item, index) => ({ id: item.id, name: item.name || `Página ${index + 1}` }))" :page-id="pageId" :element-id="collabElement" :revision="revision" id-prefix="editor-collab" @restored="restoredVersion" />
        </StudioDialog>
        <VariantsDialog v-model="variantsOpen" :design-name="name" :template-fields="templateFields" :busy="automationBusy" :error="error" :results="variantsCreated" @create="createVariants" />
    </AuthenticatedLayout>
</template>
<style scoped>
.studio-stage{background-color:#eef0f5;background-image:radial-gradient(#c5c9d3 .7px,transparent .7px);background-size:14px 14px}
.tool{display:inline-flex;align-items:center;justify-content:center;min-height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;background:#fff;color:#475569}
.tool:hover{background:#f8fafc;border-color:#c4b5fd}.tool:disabled{opacity:.4;cursor:not-allowed}
</style>
