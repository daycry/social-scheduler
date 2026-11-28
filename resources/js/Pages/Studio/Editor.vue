<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import FlashBanner from '@/Components/FlashBanner.vue';
import EditorToolbar from '@/Components/Studio/EditorToolbar.vue';
import Inspector from '@/Components/Studio/Inspector.vue';
import LayerPanel from '@/Components/Studio/LayerPanel.vue';
import StudioImageJob from '@/Components/Studio/StudioImageJob.vue';
import StudioAiPanel from '@/Components/Studio/StudioAiPanel.vue';
import TimelinePanel from '@/Components/Studio/TimelinePanel.vue';
import { blankDocument, cloneDocument, duplicatePage, findPage, validateDocument } from '@/Studio/document';
import { useStudioHistory } from '@/Studio/composables/useStudioHistory';
import { studioRequest } from '@/Studio/api';
import { STUDIO_RESIZE_MODES } from '@/Studio/format-presets';
import { useTimelineEditing } from '@/Studio/composables/useTimelineEditing';
import { useTimelinePlayback } from '@/Studio/composables/useTimelinePlayback';
import { useStudioSave } from '@/Studio/composables/useStudioSave';
import { useChartFlow } from '@/Studio/composables/useChartFlow';
import { usePresentation } from '@/Studio/composables/usePresentation';
import { useEditorDialogs } from '@/Studio/composables/useEditorDialogs';
import { useEditorLibrary } from '@/Studio/composables/useEditorLibrary';
import { useEditorCanvas } from '@/Studio/composables/useEditorCanvas';
import { useEditorDesignOperations } from '@/Studio/composables/useEditorDesignOperations';
import { hasOpenModal, shortcutAction } from '@/lib/shortcuts';

const props = defineProps({
    design: Object,
    brand: Object,
    kit: Object,
    assets: Array,
    folders: Array,
    ai_configured: { type: Boolean, default: false },
    exports: Array,
    source_post_id: Number,
    source_post_unavailable: Boolean,
    fonts: { type: Array, default: () => [] },
    access: {
        type: Object,
        default: () => ({
            role: 'owner',
            role_label: 'Propietario',
            is_owner: true,
            can_edit: true,
            can_comment: true,
            owner_name: '',
        }),
    },
});
const disposed = ref(false);
const initialContext = { brandId: props.brand.id, designId: props.design.id };
const contextActive = computed(
    () => !disposed.value && props.brand.id === initialContext.brandId && props.design.id === initialContext.designId,
);
const documentData = ref(validateDocument(props.design.document));
const name = ref(props.design.name);
const template = ref(props.design.is_template);
const kitRevision = ref(props.design.revision);
const pageId = ref(documentData.value.pages[0].id);
const page = computed(() => findPage(documentData.value, pageId.value) ?? documentData.value.pages[0]);
const panel = ref('layers');
const leftPanel = ref('elements');
watch(contextActive, (active) => {
    if (active || disposed.value) return;
    disposed.value = true;
    closePresentation();
    closeEditorDialogs();
    resetChartFlow();
    ready.value = false;
    void releaseStage();
    error.value = 'El contexto del diseño cambió. Abre el diseño de esta marca para continuar.';
});
const error = ref('');
const {
    selection,
    layers,
    canvasElement,
    stage,
    ready,
    busy,
    fontBusy,
    canvasWarning,
    zoom,
    drawing,
    drawColor,
    drawWidth,
    hasVideo,
    getAdapter,
    refreshLayers,
    fit,
    setZoom,
    reloadCanvas,
    action,
    patchSelection,
    addText,
    addShape,
    selectLayer,
    patchLayer,
    toggleDrawing,
    mountCanvas,
    disposeCanvas,
    togglePlayback,
    suspendPlayback,
} = useEditorCanvas({
    documentData,
    page,
    pageId,
    getKit: () => props.kit,
    getChartResources: () => chartResources.value,
    getAssets: () => localAssets.value,
    getFonts: () => localFonts.value,
    error,
    run,
    commit,
    isDisposed: () => !contextActive.value,
    invalidatePreview: () => invalidatePreview(),
    pauseTimeline: (hide) => pauseTimeline(hide),
    isPresenting: () => presentationOpen.value || presentationOpening.value,
    isPlaying: () => playing.value,
    setPlaying: (value) => {
        playing.value = value;
    },
});
const {
    fileInput,
    localAssets,
    localFonts,
    assetQuery,
    visibleAssets,
    uploadStatus,
    pendingAudio,
    imageJobSource,
    aiPresetId,
    aiPresetToken,
    imageJobToken,
    imageBusy,
    upload,
    retryAudioValidation,
    updateFonts,
    registerImageResult,
    removeBackground,
    editWithAi,
} = useEditorLibrary({
    initialAssets: props.assets,
    initialFonts: props.fonts,
    brandId: () => initialContext.brandId,
    busy,
    error,
    leftPanel,
    getAdapter,
    run,
    isActive: () => contextActive.value,
});
const collabElement = computed(() => (selection.value.length === 1 ? (selection.value[0].id ?? null) : null));
async function openCollab() {
    if (!contextActive.value) return;
    if (dirty.value && !conflict.value) await saveNow();
    if (contextActive.value) collabOpen.value = true;
}
async function restoredVersion(design) {
    await run(async () => {
        await acceptDesignMutation(design);
        collabOpen.value = false;
        automationMessage.value = 'Versión restaurada como una versión nueva.';
    });
}
async function saveAsCopy() {
    await run(async () => {
        const result = await studioRequest('POST', route('studio.copies.store', initialContext.designId), {
            name: `${name.value.trim() || 'Diseño sin título'} (mi copia)`,
            document: validateDocument(snapshot().document),
        });
        if (!contextActive.value) return;
        dirty.value = false;
        router.visit(result.edit_url);
    });
}
const {
    datasetsOpen,
    chartsOpen,
    chartResources,
    chartDraft,
    chartSource,
    editChart,
    chooseChartData,
    startChart,
    selectChartData,
    applyChart,
    initChartResources,
    disposeChartResources,
    resetChartFlow,
} = useChartFlow({
    brandId: () => initialContext.brandId,
    designId: () => initialContext.designId,
    pageId,
    selection,
    busy,
    ready,
    run,
    isDisposed: () => !contextActive.value,
    getAdapter,
    refreshLayers,
});
const {
    playing,
    timeline,
    timelineOpen,
    previewVisible,
    previewBusy,
    previewElement,
    timelineDuration,
    timelinePageStart,
    pauseTimeline,
    invalidatePreview,
    seekTimeline,
    playTimeline,
    waitForPreviewCreation,
    waitForPreviewCleanup,
} = useTimelinePlayback({
    documentData,
    pageId,
    localAssets,
    localFonts,
    chartResources,
    error,
    run,
    isDisposed: () => !contextActive.value,
    isPresenting: () => presentationOpen.value || presentationOpening.value,
});
const {
    presentationOpen,
    presentationOpening,
    presentationDocument,
    presentationDialog,
    notesValue,
    notesError,
    openPresentation,
    closePresentation,
    patchNotes,
} = usePresentation({
    page,
    documentData,
    error,
    isDisposed: () => !contextActive.value,
    invalidatePreview,
    suspendPlayback,
    waitForPreviewCreation,
    waitForPreviewCleanup,
    saveNow: () => saveNow(),
    loadPresentationDialog: () => loadPresentationDialog(),
    patchPage,
});
const snapshot = () => ({
    document: cloneDocument(documentData.value),
    name: name.value,
    is_template: template.value,
    kit_revision: kitRevision.value,
});
const {
    revision,
    templateFields,
    dirty,
    status,
    conflict,
    automationBusy,
    automationMessage,
    markDirty,
    saveNow,
    isSaving,
    onExportConflict,
    prepareExport,
    savedOperation,
} = useStudioSave({
    initialDesign: props.design,
    snapshot,
    notesError,
    error,
    isActive: () => contextActive.value,
    persist: (payload) => studioRequest('PATCH', route('studio.designs.update', initialContext.designId), payload),
    beforeExport: () => {
        pauseTimeline(true);
        getAdapter()?.pause();
    },
    closeExport: () => {
        exportOpen.value = false;
    },
    operationBlocked: () => busy.value || exportBusy.value,
});
const { canUndo, canRedo, commitHistory, resetHistory, restore } = useStudioHistory({
    snapshot,
    applySnapshot: async (state) => {
        documentData.value = state.document;
        name.value = state.name;
        template.value = state.is_template;
        kitRevision.value = state.kit_revision;
        if (!findPage(documentData.value, pageId.value)) pageId.value = documentData.value.pages[0].id;
        designWidth.value = documentData.value.width;
        designHeight.value = documentData.value.height;
        markDirty('Cambios pendientes');
        await reloadCanvas();
    },
});
const {
    designWidth,
    designHeight,
    resizeMode,
    templateSaveError,
    templateSaveOpen,
    templateName,
    templateCreated,
    variantsOpen,
    variantsCreated,
    acceptDesignMutation,
    resizeDesign,
    openTemplateSave,
    saveTemplate,
    openVariants,
    createVariants,
    applyBrandKit,
    downloadLocal,
    recover,
} = useEditorDesignOperations({
    request: (method, endpoint, payload) => studioRequest(method, route(endpoint, initialContext.designId), payload),
    documentData,
    name,
    template,
    kitRevision,
    pageId,
    snapshot,
    error,
    run,
    fit,
    reloadCanvas,
    updateFonts,
    revision,
    templateFields,
    conflict,
    dirty,
    status,
    automationMessage,
    savedOperation,
    commitHistory,
    resetHistory,
});
let removeNavigate;
const {
    selectedAudioId,
    selectedClipId,
    openTimeline,
    applyCaptions,
    patchClipWindow,
    patchClipAnimation,
    splitClip,
    patchTimelineFps,
    addAudioTrack,
    patchAudio,
    fadeAudio,
    splitAudio,
    removeAudio,
} = useTimelineEditing({
    documentData,
    page,
    pageId,
    selection,
    localAssets,
    timeline,
    timelineDuration,
    timelinePageStart,
    timelineOpen,
    pauseTimeline,
    error,
    run,
    commit,
    reloadCanvas,
    selectOnCanvas: (id) => getAdapter().select(id),
    isCaptionBlocked: () => busy.value || playing.value || conflict.value || exportBusy.value || !contextActive.value,
});

function addLibraryAsset(asset) {
    return asset.kind === 'audio' ? addAudioTrack(asset) : action('addAsset', asset);
}

function commit() {
    invalidatePreview();
    commitHistory();
    markDirty();
}

async function run(action) {
    if (!contextActive.value) return;
    error.value = '';
    try {
        return await action();
    } catch (e) {
        if (contextActive.value) error.value = e instanceof Error ? e.message : String(e);
    }
}
const restoreHistory = (mode) => run(() => restore(mode));
async function changePage(id) {
    if (notesError.value) {
        error.value = notesError.value;
        return;
    }
    pageId.value = id;
    await run(reloadCanvas);
}
async function pageAction(mode, offset = 0) {
    if (notesError.value) {
        error.value = notesError.value;
        return;
    }
    await run(async () => {
        const next = cloneDocument(documentData.value);
        const index = next.pages.findIndex((item) => item.id === pageId.value);
        if (mode === 'add') {
            const added = blankDocument(next.width, next.height).pages[0];
            added.name = `Página ${next.pages.length + 1}`;
            next.pages.splice(index + 1, 0, added);
            pageId.value = added.id;
        }
        if (mode === 'duplicate') {
            const added = duplicatePage(next.pages[index]);
            added.name = `${added.name} · copia`;
            next.pages.splice(index + 1, 0, added);
            pageId.value = added.id;
        }
        if (mode === 'delete') {
            if (next.pages.length === 1) return;
            next.pages.splice(index, 1);
            pageId.value = next.pages[Math.min(index, next.pages.length - 1)].id;
        }
        if (mode === 'move') {
            const target = index + offset;
            if (target < 0 || target >= next.pages.length) return;
            [next.pages[index], next.pages[target]] = [next.pages[target], next.pages[index]];
        }
        documentData.value = validateDocument(next);
        commit();
        await reloadCanvas();
    });
}
function patchPage(patch, ownerId = pageId.value) {
    return run(() => {
        const next = cloneDocument(documentData.value);
        Object.assign(findPage(next, ownerId), patch);
        documentData.value = validateDocument(next);
        commit();
    });
}
function beforeUnload(event) {
    if (!contextActive.value) return;
    if (dirty.value || isSaving() || automationBusy.value || fontBusy.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}
function onKey(event) {
    if (!contextActive.value) return;
    if (presentationOpen.value || presentationOpening.value || automationBusy.value || fontBusy.value) return;
    if (getAdapter()?.canvas.getActiveObject()?.isEditing) return;
    const action = shortcutAction(event, { scope: 'studio-editor', modalOpen: hasOpenModal() });
    if (!action) return;
    event.preventDefault();
    if (action === 'save') saveNow();
    else restoreHistory(action);
}
async function releaseStage() {
    invalidatePreview();
    await Promise.allSettled([disposeCanvas(), waitForPreviewCreation(), waitForPreviewCleanup()]);
    disposeChartResources();
}

onMounted(async () => {
    let navigating = false;
    window.addEventListener('beforeunload', beforeUnload);
    window.addEventListener('keydown', onKey);
    removeNavigate = router.on('before', (event) => {
        if (!contextActive.value || event.detail.visit.method !== 'get') return;
        if (automationBusy.value || fontBusy.value) {
            event.preventDefault();
            return;
        }
        if (!dirty.value || navigating) return;
        event.preventDefault();
        void run(async () => {
            if ((await saveNow()) && contextActive.value) {
                navigating = true;
                router.visit(event.detail.visit.url);
            }
        });
    });
    await nextTick();
    if (!contextActive.value) return;
    await run(async () => {
        initChartResources();
        await mountCanvas();
    });
});
onBeforeUnmount(() => {
    disposed.value = true;
    closePresentation();
    removeNavigate?.();
    window.removeEventListener('beforeunload', beforeUnload);
    window.removeEventListener('keydown', onKey);
    void releaseStage();
});

const {
    ExportDialog,
    BatchExportDialog,
    PresentationDialog,
    StudioDialog,
    StudioCollabPanel,
    VariantsDialog,
    StudioFontsDialog,
    StudioChartsDialog,
    StudioDatasetsDialog,
    loadPresentationDialog,
    exportMounted,
    batchMounted,
    templateSaveMounted,
    fontsMounted,
    datasetsMounted,
    chartsMounted,
    variantsMounted,
    collabMounted,
    exporter,
    exportOpen,
    batchOpen,
    exportBusy,
    fontsOpen,
    collabOpen,
    exportLoading,
    publishPending,
    publishFromEditor,
    closeEditorDialogs,
} = useEditorDialogs({
    error,
    isDisposed: () => !contextActive.value,
    templateSaveOpen,
    variantsOpen,
    datasetsOpen,
    chartsOpen,
});
</script>

<template>
    <PresentationDialog
        v-if="presentationOpen"
        ref="presentationDialog"
        :document="presentationDocument"
        :assets="localAssets"
        :fonts="localFonts"
        :chart-resources="chartResources"
        @close="closePresentation"
    />
    <Head :title="`${name} · Estudio`" />
    <AuthenticatedLayout full-bleed>
        <div class="min-w-0 space-y-3">
            <EditorToolbar
                v-model:name="name"
                :brand="brand"
                :active="contextActive"
                :status="status"
                :revision="revision"
                :font-busy="fontBusy"
                :automation-busy="automationBusy"
                :busy="busy"
                :export-busy="exportBusy"
                :export-loading="exportLoading"
                :publish-pending="publishPending"
                :conflict="conflict"
                :dirty="dirty"
                :ready="ready"
                :can-undo="canUndo"
                :can-redo="canRedo"
                :presentation-opening="presentationOpening"
                :is-owner="access.is_owner"
                :updates-post="Boolean(source_post_id)"
                @rename="commit"
                @undo="restoreHistory('undo')"
                @redo="restoreHistory('redo')"
                @save="saveNow"
                @present="openPresentation"
                @export="exportOpen = true"
                @publish="publishFromEditor"
            />
            <div
                class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface px-3 py-2"
                aria-label="Plantillas y formatos"
                :inert="fontBusy || undefined"
            >
                <button
                    type="button"
                    class="tool text-xs"
                    :disabled="!ready || busy || automationBusy"
                    @click="openCollab"
                    >Compartir y comentarios</button
                >
                <button
                    v-if="access.is_owner"
                    type="button"
                    class="tool text-xs"
                    :disabled="!ready || busy || automationBusy || exportBusy || conflict"
                    @click="openTemplateSave"
                    >Guardar como plantilla</button
                >
                <button
                    v-if="access.is_owner"
                    type="button"
                    class="tool text-xs"
                    :disabled="!ready || busy || automationBusy || exportBusy || conflict"
                    @click="openVariants"
                    >Crear variantes</button
                >
                <button
                    v-if="access.is_owner"
                    type="button"
                    class="tool text-xs"
                    :disabled="!ready || busy || automationBusy || exportBusy || conflict"
                    @click="batchOpen = true"
                    >Exportar en lote</button
                >
                <button
                    v-if="access.is_owner"
                    type="button"
                    class="tool text-xs"
                    :disabled="!ready || busy || automationBusy || exportBusy || conflict"
                    @click="applyBrandKit"
                    >Aplicar identidad de marca</button
                >
                <button
                    type="button"
                    class="tool text-xs"
                    :disabled="busy || automationBusy || exportBusy"
                    @click="startChart"
                    >Tablas de datos</button
                >
                <button
                    type="button"
                    class="tool text-xs"
                    :disabled="busy || automationBusy || exportBusy"
                    @click="fontsOpen = true"
                    >Tipografías de marca</button
                >
                <button
                    type="button"
                    class="tool text-xs"
                    :aria-pressed="timelineOpen"
                    :disabled="!ready || busy || automationBusy || exportBusy"
                    @click="openTimeline"
                    >Línea de tiempo</button
                >
                <span
                    v-if="template"
                    class="ml-auto rounded-full bg-accent-secondary-soft px-3 py-1 text-[10px] font-semibold text-accent-secondary-ink"
                    >Plantilla de {{ brand.name }}</span
                >
                <span v-if="automationBusy" role="status" class="text-xs text-accent-secondary-ink"
                    >Guardando y preparando el diseño…</span
                >
            </div>
            <FlashBanner
                :info="!access.is_owner
                    ? `Editas el diseño de ${access.owner_name} como ${access.role_label.toLowerCase()}. Sus exportaciones y plantillas siguen siendo del propietario.`
                    : ''"
                :warning="source_post_unavailable ? 'La publicación de origen ya no está vinculada a este diseño.' : ''"
            />
            <FlashBanner
                :success="automationMessage"
                :error="error"
                :dismissible-error="!conflict"
                :warning="conflict ? 'Otra sesión ha cambiado este diseño. El guardado automático está detenido para conservar tus cambios locales. Guarda tu versión como copia, descarga una copia local o recupera la versión guardada.' : ''"
                @dismiss="error = ''"
            >
                <template #warning>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn-primary text-xs" :disabled="busy" @click="saveAsCopy"
                            >Guardar como copia</button
                        >
                        <button type="button" class="btn-secondary text-xs" @click="downloadLocal"
                            >Descargar copia local</button
                        >
                        <button type="button" class="btn-secondary text-xs" @click="recover"
                            >Recuperar versión guardada</button
                        >
                    </div>
                </template>
            </FlashBanner>
            <FlashBanner :warning="canvasWarning" />
            <FlashBanner :info="fontBusy ? 'Cargando la tipografía antes de actualizar el texto…' : ''" />

            <div
                class="grid min-w-0 items-start gap-3 xl:grid-cols-[190px_minmax(240px,1fr)_260px]"
                :inert="!ready || automationBusy || fontBusy || undefined"
            >
                <aside
                    class="min-w-0 rounded-2xl border border-border bg-surface p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto"
                    aria-label="Herramientas de diseño"
                >
                    <div class="mb-4 flex gap-1 rounded-lg bg-surface-hover p-1"
                        ><button
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="leftPanel === 'elements' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="leftPanel = 'elements'"
                            >Elementos</button
                        ><button
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="leftPanel === 'assets' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="leftPanel = 'assets'"
                            >Recursos</button
                        ><button
                            v-if="access.is_owner"
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="leftPanel === 'ai' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="leftPanel = 'ai'"
                            >IA</button
                        ></div
                    >
                    <div v-if="leftPanel === 'elements'" class="space-y-5"
                        ><button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-accent-secondary-fill px-3 py-3 text-sm font-semibold text-on-accent-secondary hover:bg-accent-secondary-hover disabled:opacity-50"
                            :disabled="!ready || busy"
                            @click="addText"
                            ><span class="font-serif text-xl" aria-hidden="true">T</span>Añadir texto</button
                        ><button
                            type="button"
                            class="w-full rounded-xl border border-accent-secondary-border bg-accent-secondary-soft px-3 py-3 text-sm font-semibold text-accent-secondary-ink disabled:opacity-50"
                            :disabled="!ready || busy"
                            @click="startChart"
                            >Añadir gráfico con datos</button
                        ><div class="grid grid-cols-2 gap-2"
                            ><button
                                type="button"
                                class="rounded-xl border border-border px-2 py-2.5 text-xs font-semibold text-foreground-secondary hover:border-accent-secondary-border hover:bg-accent-secondary-soft disabled:opacity-50"
                                :disabled="!ready || busy"
                                @click="action('addTable')"
                                >Añadir tabla</button
                            ><button
                                type="button"
                                class="rounded-xl border border-border px-2 py-2.5 text-xs font-semibold text-foreground-secondary hover:border-accent-secondary-border hover:bg-accent-secondary-soft disabled:opacity-50"
                                :disabled="!ready || busy"
                                title="Con dos elementos seleccionados, los une con una flecha que los sigue al moverlos"
                                @click="action('addConnector')"
                                >Añadir conector</button
                            ></div
                        ><div
                            ><h2 class="mb-3 text-xs font-semibold">Formas</h2
                            ><div class="grid grid-cols-3 gap-2"
                                ><button
                                    v-for="shape in [{key:'rect',label:'Rectángulo',symbol:'▭'},{key:'ellipse',label:'Elipse',symbol:'◯'},{key:'triangle',label:'Triángulo',symbol:'△'},{key:'line',label:'Línea',symbol:'╱'},{key:'path',label:'Trazado',symbol:'〰'}]"
                                    :key="shape.key"
                                    type="button"
                                    class="rounded-xl border border-border py-3 text-2xl text-foreground-muted transition hover:border-accent-secondary-border hover:bg-accent-secondary-soft disabled:opacity-50"
                                    :aria-label="`Añadir ${shape.label.toLowerCase()}`"
                                    :title="shape.label"
                                    :disabled="!ready || busy"
                                    @click="addShape(shape.key)"
                                    >{{ shape.symbol }}</button
                                ></div
                            ></div
                        ><div class="space-y-3 border-t border-border-subtle pt-4"
                            ><button
                                type="button"
                                class="w-full rounded-lg border px-3 py-2 text-xs font-medium"
                                :class="drawing ? 'border-accent-secondary-border bg-accent-secondary-soft text-accent-secondary-ink' : 'border-border'"
                                :aria-pressed="drawing"
                                :disabled="!ready"
                                @click="toggleDrawing"
                                >Dibujo libre</button
                            ><div v-if="drawing" class="flex items-center gap-2"
                                ><label class="sr-only" for="studio-draw-color">Color del pincel</label
                                ><input
                                    id="studio-draw-color"
                                    v-model="drawColor"
                                    type="color"
                                    class="h-8 w-10"
                                    @input="getAdapter()?.setDrawing(true, drawColor, drawWidth)" /><label
                                    class="sr-only"
                                    for="studio-draw-width"
                                    >Grosor del pincel</label
                                ><input
                                    id="studio-draw-width"
                                    v-model.number="drawWidth"
                                    type="number"
                                    min="1"
                                    max="200"
                                    class="field w-20 text-xs"
                                    @change="getAdapter()?.setDrawing(true, drawColor, drawWidth)" /></div></div
                        ><div class="border-t border-border-subtle pt-4"
                            ><h3 class="mb-3 text-xs font-semibold">Colores de {{ brand.name }}</h3
                            ><div class="flex flex-wrap gap-2"
                                ><button
                                    v-for="color in kit?.palette ?? []"
                                    :key="color"
                                    type="button"
                                    class="h-7 w-7 rounded-full border border-border"
                                    :style="{ background: color }"
                                    :aria-label="`Color de marca ${color}`"
                                    :disabled="!selection.length || selection.some(item => item.type === 'chart')"
                                    @click="patchSelection({fill:color})" /></div
                            ><p class="mt-3 text-[10px] leading-relaxed text-foreground-subtle"
                                >Selecciona un elemento para aplicarle un color.</p
                            ></div
                        ></div
                    >
                    <div v-else-if="leftPanel === 'assets'" class="space-y-3">
                        <button
                            type="button"
                            class="w-full rounded-xl bg-accent-secondary-fill px-3 py-2.5 text-xs font-semibold text-on-accent-secondary disabled:opacity-50"
                            :disabled="busy"
                            @click="fileInput.click()"
                            >Subir recursos</button
                        >
                        <input
                            ref="fileInput"
                            class="sr-only"
                            type="file"
                            accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime,audio/mpeg,audio/wav,audio/mp4,audio/aac,audio/ogg,.mp3,.wav,.m4a,.aac,.ogg"
                            multiple
                            aria-label="Subir recursos de marca"
                            @change="upload"
                        />
                        <label class="sr-only" for="studio-asset-query">Buscar recursos</label
                        ><input
                            id="studio-asset-query"
                            v-model="assetQuery"
                            type="search"
                            class="field text-xs"
                            placeholder="Buscar recursos…"
                        />
                        <button
                            v-if="pendingAudio"
                            type="button"
                            class="tool text-xs"
                            :disabled="busy"
                            @click="retryAudioValidation"
                            >Reintentar validación del audio</button
                        ><p
                            v-if="uploadStatus"
                            role="status"
                            class="text-xs text-accent-secondary-ink"
                            >{{ uploadStatus }}</p
                        >
                        <div class="grid grid-cols-2 gap-2"
                            ><article
                                v-for="asset in visibleAssets"
                                :key="asset.id"
                                class="min-w-0 overflow-hidden rounded-xl border border-border bg-canvas"
                                ><button
                                    type="button"
                                    class="block w-full min-w-0 text-left hover:bg-accent-secondary-soft"
                                    :aria-label="`Añadir ${asset.name}`"
                                    :disabled="!ready || busy"
                                    @click="addLibraryAsset(asset)"
                                    ><img
                                        v-if="asset.kind === 'image'"
                                        :src="asset.preview_url"
                                        :alt="asset.name"
                                        class="aspect-square w-full object-cover"
                                        loading="lazy"
                                    /><span
                                        v-else
                                        class="flex aspect-square items-center justify-center text-2xl text-accent-secondary-ink"
                                        aria-hidden="true"
                                        >{{ asset.kind === 'audio' ? '♫' : '▶' }}</span
                                    ><span class="block truncate p-2 text-[10px]">{{ asset.name }}</span></button
                                ><button
                                    v-if="asset.kind === 'image'"
                                    type="button"
                                    class="block w-full border-t border-border px-2 py-2 text-[10px] font-medium text-accent-secondary-ink hover:bg-accent-secondary-soft disabled:opacity-50"
                                    :aria-label="`Quitar fondo de ${asset.name}`"
                                    :disabled="imageBusy"
                                    @click="removeBackground(asset.id)"
                                    >Quitar fondo</button
                                ><button
                                    v-if="asset.kind === 'image' && access.is_owner && ai_configured"
                                    type="button"
                                    class="block w-full border-t border-border px-2 py-2 text-[10px] font-medium text-accent-secondary-ink hover:bg-accent-secondary-soft"
                                    :aria-label="`Editar con IA ${asset.name}`"
                                    @click="editWithAi(asset.id)"
                                    >Editar con IA</button
                                ></article
                            ></div
                        >
                        <p
                            v-if="!visibleAssets.length"
                            class="py-5 text-xs leading-relaxed text-foreground-subtle"
                            >{{ assetQuery ? 'No hay recursos con ese nombre.' : 'Sube imágenes, vídeos o audio para usarlos en los diseños de esta marca.' }}</p
                        >
                    </div>
                    <StudioAiPanel
                        v-if="access.is_owner"
                        v-show="leftPanel === 'ai'"
                        :active="leftPanel === 'ai'"
                        :brand-id="initialContext.brandId"
                        :design-id="initialContext.designId"
                        :revision="revision"
                        :is-template="template"
                        :assets="localAssets"
                        :preset-media-id="aiPresetId"
                        :preset-token="aiPresetToken"
                        @ready="registerImageResult"
                        @add="action('addAsset',$event)"
                        @text="addText"
                    />
                    <StudioImageJob
                        v-if="imageJobSource"
                        class="mt-4"
                        :brand-id="initialContext.brandId"
                        :source-id="imageJobSource.id"
                        :source-name="imageJobSource.name"
                        :request-token="imageJobToken"
                        @busy="imageBusy = $event"
                        @ready="registerImageResult"
                        @add="action('addAsset',$event)"
                        @close="imageJobSource = null"
                    />
                </aside>

                <section class="min-w-0 space-y-3" aria-label="Editor de diseño">
                    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface p-2"
                        ><span
                            class="px-1 text-xs font-medium text-foreground-muted"
                            >{{ selection.length ? `${selection.length} seleccionado(s)` : page.name }}</span
                        ><div class="ml-auto flex items-center gap-1"
                            ><button
                                type="button"
                                class="tool text-xs"
                                :disabled="!selection.length || busy"
                                aria-label="Duplicar elemento"
                                @click="action('duplicate')"
                                >Duplicar</button
                            ><button
                                type="button"
                                class="tool text-xs text-danger"
                                :disabled="!selection.length || busy"
                                aria-label="Eliminar elemento"
                                @click="action('delete')"
                                >Eliminar</button
                            ><button
                                type="button"
                                class="tool text-xs"
                                :disabled="!selection.length || busy"
                                aria-label="Bloquear elemento"
                                @click="action('lock')"
                                >Bloquear</button
                            ></div
                        ></div
                    >
                    <div
                        ref="stage"
                        class="studio-stage relative flex h-[430px] min-w-0 items-start justify-start overflow-auto rounded-2xl border border-border p-6 sm:h-[560px] xl:h-[calc(100vh-340px)] xl:min-h-[380px]"
                    >
                        <div
                            v-show="!previewVisible"
                            class="m-auto shrink-0 bg-white shadow-[0_10px_40px_-8px_rgb(var(--color-overlay-strong)/0.2)]"
                            :style="{ width: `${documentData.width * zoom / 100}px`, height: `${documentData.height * zoom / 100}px` }"
                            ><canvas ref="canvasElement"
                        /></div>
                        <div
                            v-show="previewVisible"
                            class="m-auto shrink-0 bg-white shadow-xl"
                            :style="{ width: `${documentData.width * zoom / 100}px`, height: `${documentData.height * zoom / 100}px` }"
                            ><canvas
                                ref="previewElement"
                                aria-label="Previsualización de la línea de tiempo"
                                class="h-full w-full"
                        /></div>
                        <div
                            v-if="!ready || busy"
                            role="status"
                            class="absolute inset-0 flex items-center justify-center rounded-2xl bg-surface-hover/80 text-sm text-foreground-muted"
                            >{{ busy ? 'Preparando página…' : 'Cargando el lienzo…' }}</div
                        >
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs"
                        ><span class="text-foreground-subtle"
                            >{{ documentData.width }} × {{ documentData.height }} px</span
                        ><div class="flex items-center gap-2"
                            ><button type="button" class="tool" aria-label="Reducir zoom" @click="setZoom(zoom - 10)"
                                >−</button
                            ><label class="sr-only" for="studio-zoom">Zoom</label
                            ><input
                                id="studio-zoom"
                                type="number"
                                min="5"
                                max="400"
                                :value="zoom"
                                class="w-16 rounded-lg border-border py-1 text-xs"
                                @change="setZoom($event.target.value)"
                            /><span>%</span
                            ><button type="button" class="tool" aria-label="Aumentar zoom" @click="setZoom(zoom + 10)"
                                >+</button
                            ><button type="button" class="tool" @click="fit">Ajustar</button></div
                        ></div
                    >
                    <TimelinePanel
                        v-if="timelineOpen && documentData.schema_version === 2"
                        :document="documentData"
                        :page="page"
                        :page-start="timelinePageStart ?? 0"
                        :assets="localAssets"
                        :fonts="localFonts"
                        :kit-fonts="kit.fonts"
                        :brand-id="initialContext.brandId"
                        :apply-captions="applyCaptions"
                        :selected-audio-id="selectedAudioId"
                        @audio-select="selectedAudioId = $event"
                        @audio-patch="patchAudio"
                        @audio-split="splitAudio"
                        @audio-remove="removeAudio"
                        @audio-fade="fadeAudio"
                        :selected-id="selectedClipId"
                        :time="timeline"
                        :duration="timelineDuration"
                        :playing="playing"
                        :busy="busy || previewBusy || automationBusy"
                        @select="selectLayer"
                        @window="patchClipWindow"
                        @animation="patchClipAnimation"
                        @seek="seekTimeline"
                        @play="playTimeline"
                        @split="splitClip"
                        @fps="patchTimelineFps"
                        @edit="pauseTimeline(true)"
                    />
                    <div
                        v-else-if="hasVideo && documentData.schema_version === 1"
                        class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-surface p-3 text-xs"
                        ><button
                            type="button"
                            class="tool"
                            @click="togglePlayback"
                            >{{ playing ? 'Pausar vídeo' : 'Reproducir vídeo' }}</button
                        ><label class="flex items-center gap-2"
                            >Fotograma (s)<input
                                v-model.number="timeline"
                                type="number"
                                min="0"
                                step="0.1"
                                class="field w-24 text-xs"
                                @change="run(() => getAdapter().seek(timeline))" /></label
                        ><span class="text-foreground-subtle">La exportación incluye un fotograma estático.</span></div
                    >
                    <section class="rounded-xl border border-border bg-surface p-3" aria-label="Páginas del diseño"
                        ><div class="mb-3 flex flex-wrap items-center justify-between gap-2"
                            ><h2 class="text-xs font-semibold"
                                >Páginas
                                <span class="font-normal text-foreground-subtle"
                                    >{{ documentData.pages.length }}/100</span
                                ></h2
                            ><div class="flex flex-wrap gap-1"
                                ><button
                                    type="button"
                                    class="tool text-xs"
                                    :disabled="busy || !ready || documentData.pages.length >= 100"
                                    @click="pageAction('add')"
                                    >Añadir página</button
                                ><button
                                    type="button"
                                    class="tool text-xs"
                                    :disabled="busy || !ready || documentData.pages.length >= 100"
                                    @click="pageAction('duplicate')"
                                    >Duplicar página</button
                                ><button
                                    type="button"
                                    class="tool text-xs"
                                    :disabled="busy || !ready || documentData.pages.length < 2"
                                    @click="pageAction('delete')"
                                    >Eliminar página</button
                                ></div
                            ></div
                        ><div class="flex gap-2 overflow-x-auto pb-1"
                            ><button
                                v-for="(item,index) in documentData.pages"
                                :key="item.id"
                                type="button"
                                class="min-w-24 max-w-40 shrink-0 rounded-lg border p-2 text-left"
                                :class="pageId === item.id ? 'border-accent-secondary-fill bg-accent-secondary-soft text-accent-secondary-ink' : 'border-border'"
                                :aria-pressed="pageId === item.id"
                                :disabled="busy"
                                @click="changePage(item.id)"
                                ><span
                                    class="block text-[10px] text-foreground-subtle"
                                    >{{ String(index + 1).padStart(2,'0') }}</span
                                ><span class="mt-1 block truncate text-xs font-medium">{{ item.name }}</span></button
                            ></div
                        ><div class="mt-3 flex flex-wrap gap-2 border-t border-border-subtle pt-3"
                            ><button
                                type="button"
                                class="tool text-xs"
                                :disabled="busy || documentData.pages[0].id === pageId"
                                @click="pageAction('move',-1)"
                                >← Mover página</button
                            ><button
                                type="button"
                                class="tool text-xs"
                                :disabled="busy || documentData.pages.at(-1).id === pageId"
                                @click="pageAction('move',1)"
                                >Mover página →</button
                            ></div
                        ></section
                    >
                </section>

                <aside
                    class="min-w-0 rounded-2xl border border-border bg-surface p-4 xl:max-h-[calc(100vh-210px)] xl:overflow-y-auto"
                    aria-label="Panel de edición"
                >
                    <div class="mb-5 flex gap-1 rounded-lg bg-surface-hover p-1"
                        ><button
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="panel === 'layers' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="panel = 'layers'"
                            >Capas</button
                        ><button
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="panel === 'properties' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="panel = 'properties'"
                            >Propiedades</button
                        ><button
                            type="button"
                            class="flex-1 rounded-md px-2 py-2 text-xs font-medium"
                            :class="panel === 'page' ? 'bg-surface shadow-sm' : 'text-foreground-subtle'"
                            @click="panel = 'page'"
                            >Página</button
                        ></div
                    >
                    <LayerPanel
                        v-show="panel === 'layers'"
                        :layers="layers"
                        :selection="selection"
                        @select="selectLayer"
                        @patch="patchLayer"
                        @action="action"
                    />
                    <Inspector
                        v-show="panel === 'properties'"
                        :selection="selection"
                        :kit="kit"
                        :fonts="localFonts"
                        :image-busy="imageBusy"
                        @patch="patchSelection"
                        @action="action"
                        @remove-background="removeBackground"
                        @edit-chart="editChart"
                    />
                    <section v-show="panel === 'page'" class="space-y-4 text-xs">
                        <h2 class="text-sm font-semibold">Ajustes de página</h2>
                        <label class="block space-y-1"
                            >Nombre de la página<input
                                class="field text-xs"
                                :value="page.name"
                                maxlength="255"
                                @change="patchPage({name:$event.target.value})"
                        /></label>
                        <label class="flex items-center justify-between gap-2"
                            >Fondo de página<input
                                type="color"
                                class="h-8 w-10"
                                :value="typeof page.background === 'string' ? page.background.slice(0,7) : page.background.stops[0].color.slice(0,7)"
                                :disabled="!ready"
                                @input="action('setBackground',$event.target.value)"
                        /></label>
                        <label class="block space-y-1"
                            >Duración de página (s)<input
                                type="number"
                                class="field text-xs"
                                min="0.1"
                                max="3600"
                                step="0.1"
                                :value="page.duration"
                                @change="patchPage({duration:Number($event.target.value)})"
                        /></label>
                        <label class="block space-y-1"
                            >Notas del presentador<textarea
                                :key="page.id"
                                class="field min-h-32 text-xs"
                                :value="notesValue"
                                :data-page-id="page.id"
                                :aria-invalid="!!notesError"
                                aria-describedby="studio-notes-count studio-notes-error"
                                @input="patchNotes($event, page.id)"
                            />
                        </label>
                        <p id="studio-notes-count" class="text-foreground-subtle"
                            >{{ Array.from(notesValue).length.toLocaleString('es') }} / 20.000 caracteres · Solo
                            visibles para el presentador</p
                        >
                        <p
                            v-if="notesError"
                            id="studio-notes-error"
                            role="alert"
                            class="text-danger"
                            >{{ notesError }}</p
                        >
                        <div class="space-y-3 border-t border-border-subtle pt-4">
                            <h3 class="font-semibold">Tamaño de todo el diseño</h3>
                            <div class="grid grid-cols-2 gap-2"
                                ><label
                                    >Ancho del diseño<input
                                        v-model.number="designWidth"
                                        type="number"
                                        min="40"
                                        max="8000"
                                        class="field mt-1 text-xs" /></label
                                ><label
                                    >Alto del diseño<input
                                        v-model.number="designHeight"
                                        type="number"
                                        min="40"
                                        max="8000"
                                        class="field mt-1 text-xs" /></label
                            ></div>
                            <label class="block space-y-1"
                                >Adaptación al redimensionar<select
                                    v-model="resizeMode"
                                    aria-label="Adaptación al redimensionar"
                                    class="field mt-1 text-xs"
                                    ><option
                                        v-for="mode in STUDIO_RESIZE_MODES"
                                        :key="mode.value"
                                        :value="mode.value"
                                        >{{ mode.label }}</option
                                    ></select
                                ></label
                            >
                            <button
                                type="button"
                                class="tool w-full text-xs"
                                :disabled="busy || !ready || conflict"
                                @click="resizeDesign"
                                >Aplicar tamaño</button
                            >
                            <p
                                class="leading-relaxed text-foreground-subtle"
                                >{{ resizeMode === 'contain' ? 'Escala y centra el contenido sin deformarlo. Pueden quedar márgenes.' : resizeMode === 'cover' ? 'Escala sin deformar y llena el lienzo. Las partes exteriores quedan recortadas en la exportación.' : 'Conserva las posiciones y el tamaño de las capas.' }}</p
                            >
                        </div>
                        <div class="space-y-2 border-t border-border-subtle pt-4"
                            ><p class="font-medium">{{ template ? 'Plantilla de marca' : 'Contenido reutilizable' }}</p
                            ><p class="leading-relaxed text-foreground-subtle"
                                >Para crear campos sustituibles, escribe un nombre entre llaves dobles en un texto, por
                                ejemplo <code class="rounded bg-surface-hover px-1" v-text="'{{nombre}}'" />. Guarda una
                                copia como plantilla para reutilizarla.</p
                            ></div
                        >
                    </section>
                </aside>
            </div>
        </div>
        <ExportDialog
            v-if="contextActive && exportMounted"
            ref="exporter"
            v-model="exportOpen"
            :document="documentData"
            :assets="localAssets"
            :fonts="localFonts"
            :design-id="initialContext.designId"
            :source-post-id="source_post_id"
            :chart-resources="chartResources"
            :watermark="kit?.watermark"
            :prepare="prepareExport"
            :playhead="timeline"
            @busy="exportBusy = $event"
            @conflict="onExportConflict"
        />
        <BatchExportDialog
            v-if="contextActive && batchMounted && access.is_owner"
            v-model="batchOpen"
            :document="documentData"
            :design-id="initialContext.designId"
            :watermark="kit?.watermark"
            :prepare="prepareExport"
            @busy="exportBusy = $event"
            @conflict="onExportConflict"
        />
        <StudioFontsDialog
            v-if="contextActive && fontsMounted"
            v-model="fontsOpen"
            :brand-id="initialContext.brandId"
            @changed="updateFonts"
        />
        <StudioDatasetsDialog
            v-if="contextActive && datasetsMounted"
            v-model="datasetsOpen"
            :brand-id="initialContext.brandId"
            :brand-name="brand.name"
            selectable
            @select-chart="selectChartData"
        />
        <StudioChartsDialog
            v-if="contextActive && chartsMounted"
            v-model="chartsOpen"
            :brand-id="initialContext.brandId"
            :chart="chartDraft"
            :source="chartSource"
            :chart-resources="chartResources"
            :fonts="localFonts"
            :kit="kit"
            @choose-data="chooseChartData"
            @apply="applyChart"
        />
        <StudioDialog
            v-if="contextActive && templateSaveMounted"
            :model-value="templateSaveOpen"
            title="Guardar como plantilla"
            @update:model-value="value => { if (!automationBusy) templateSaveOpen = value; }"
        >
            <FlashBanner
                :success="templateCreated ? `Plantilla guardada en ${brand.name}. Tu diseño original se conserva.` : ''"
            />
            <div v-if="templateCreated" class="space-y-5"
                ><div class="flex flex-wrap justify-end gap-2"
                    ><button type="button" class="btn-secondary" @click="templateSaveOpen = false"
                        >Cerrar plantilla</button
                    ><Link :href="route('studio.designs.edit', templateCreated.id)" class="btn-secondary-accent"
                        >Ver plantilla</Link
                    ></div
                ></div
            >
            <form v-else class="space-y-5" @submit.prevent="saveTemplate"
                ><p class="text-sm leading-relaxed text-foreground-subtle"
                    >Guarda una copia independiente de todas las páginas, recursos y campos para los próximos diseños de
                    esta marca.</p
                ><label class="block text-sm font-medium"
                    >Nombre de la plantilla<input
                        v-model="templateName"
                        class="field mt-1"
                        maxlength="255"
                        required
                        autofocus
                        :disabled="automationBusy" /></label
                ><FlashBanner :error="templateSaveError" /><div class="flex justify-end"
                    ><button
                        type="submit"
                        class="btn-secondary-accent"
                        :disabled="automationBusy || !templateName.trim()"
                        >{{ automationBusy ? 'Guardando…' : 'Guardar plantilla' }}</button
                    ></div
                ></form
            >
        </StudioDialog>
        <StudioDialog v-if="contextActive && collabMounted" v-model="collabOpen" title="Compartir y comentarios" wide>
            <StudioCollabPanel
                v-if="collabOpen"
                :design-id="initialContext.designId"
                :access="access"
                :pages="documentData.pages.map((item, index) => ({ id: item.id, name: item.name || `Página ${index + 1}` }))"
                :page-id="pageId"
                :element-id="collabElement"
                :revision="revision"
                id-prefix="editor-collab"
                @restored="restoredVersion"
            />
        </StudioDialog>
        <VariantsDialog
            v-if="contextActive && variantsMounted"
            v-model="variantsOpen"
            :design-name="name"
            :template-fields="templateFields"
            :busy="automationBusy"
            :error="error"
            :results="variantsCreated"
            @create="createVariants"
        />
    </AuthenticatedLayout>
</template>
<style scoped>
.studio-stage {
    background-color: rgb(var(--color-editor-stage));
    background-image: radial-gradient(rgb(var(--color-editor-stage-dot)) 0.7px, transparent 0.7px);
    background-size: 14px 14px;
}

.tool {
    @apply inline-flex min-h-[30px] items-center justify-center rounded-lg border border-control-border bg-surface px-[9px] py-[5px] text-foreground-muted hover:border-focus hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-40;
}
</style>
