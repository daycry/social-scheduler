<script setup>
import { exportFailure } from '@/Studio/export-conflict';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import StudioDialog from './StudioDialog.vue';
import NativeExportJobs from './NativeExportJobs.vue';
import { EXPORT_FORMATS, exportDesign } from '@/Studio/exports';
import { studioRequest, uploadStudioFile, downloadStudioBlob } from '@/Studio/api';
import { compileTimeline } from '@/Studio/timeline';
import { createStudioExportClient } from '@/Studio/export-jobs';

defineOptions({ name: 'StudioExportDialog' });
const props = defineProps({ modelValue: Boolean, document: Object, assets: Array, fonts: { type: Array, default: () => [] }, chartResources: { type: Object, default: null }, watermark: { type: Object, default: null }, designId: Number, sourcePostId: Number, prepare: Function, playhead: { type: Number, default: 0 } });
const emit = defineEmits(['update:modelValue', 'busy', 'registered', 'conflict']);
const publicationClient = createStudioExportClient();
const watermarkHintId = useId();
const format = ref('png');
const scale = ref(1);
const quality = ref(0.94);
const transparent = ref(false);
const selectedPages = ref(props.document.pages.map(page => page.id));
const pageFrameTimes = ref(Object.create(null));
const busy = ref(false);
const status = ref('');
const error = ref('');
const savedExportId = ref(null);
const draftAction = ref(null);
const raster = computed(() => ['png', 'jpeg', 'webp'].includes(format.value));
// Con la marca de agua del kit, la imagen se renderiza en el servidor (el mismo camino que el lote y la API) y no en el navegador.
const watermarkAvailable = computed(() => props.watermark?.logo_id != null);
const applyWatermark = ref(Boolean(props.watermark?.enabled));
const serverImage = computed(() => raster.value && watermarkAvailable.value && applyWatermark.value);
// Trabajos que prepara el servidor en segundo plano (cola): vídeo, GIF, PowerPoint e imágenes con marca de agua. El resto se renderiza en el navegador.
const native = computed(() => ['mp4', 'gif', 'pptx'].includes(format.value) || serverImage.value);
const video = computed(() => ['mp4', 'gif'].includes(format.value));
const formats = [...EXPORT_FORMATS, { value: 'pptx', label: 'PPTX · una diapositiva por página' }, { value: 'mp4', label: 'MP4 · vídeo con audio' }, { value: 'gif', label: 'GIF · animación sin audio' }];
const pageOrder = ref(props.document.pages.map(page => page.id));
const orderedPages = computed(() => native.value ? pageOrder.value.map(id => props.document.pages.find(page => page.id === id)).filter(Boolean) : props.document.pages);
const outputSize = computed(() => {
    const dimension = value => video.value ? Math.ceil(Math.round(value * scale.value) / 2) * 2 : Math.round(value * scale.value);
    return `${dimension(props.document.width)} × ${dimension(props.document.height)} px`;
});
watch(() => props.document.pages.map(page => page.id).join(','), () => { selectedPages.value = props.document.pages.map(page => page.id); });
watch(() => props.document.pages.map(page => page.id).join(','), () => { pageOrder.value = props.document.pages.map(page => page.id); });
function movePage(id, direction) {
    const index = pageOrder.value.indexOf(id), target = index + direction;
    if (index < 0 || target < 0 || target >= pageOrder.value.length) return;
    const order = [...pageOrder.value]; [order[index], order[target]] = [order[target], order[index]]; pageOrder.value = order;
}
async function nativeOptions() {
    if (!selectedPages.value.length) throw new Error('Selecciona al menos una página.');
    const snapshot = await props.prepare();
    const existing = new Set(snapshot.document.pages.map(page => page.id));
    const pages = pageOrder.value.filter(id => selectedPages.value.includes(id) && existing.has(id));
    if (!pages.length) throw new Error('Selecciona al menos una página existente.');
    const frames = explicitFrames(snapshot.document, pages);
    const image = serverImage.value ? { transparent: transparent.value && format.value !== 'jpeg', watermark: true, ...frames } : {};
    return { revision: snapshot.revision, format: format.value, pages, scale: Number(scale.value), quality: Number(quality.value), ...image };
}
/** Solo las páginas con un instante escrito fijan fotograma; el resto se exporta en reposo (animaciones completadas). */
function explicitFrames(document, pageIds) {
    if (document.schema_version !== 2) return {};
    const frame_times = pageIds.filter(id => Number.isFinite(pageFrameTimes.value[id])).map(page_id => ({ page_id, time: pageFrameTimes.value[page_id] }));
    return frame_times.length ? { frame_times } : {};
}
function initializeFrames() {
    const times = Object.create(null);
    if (props.document.schema_version === 2) for (const page of compileTimeline(props.document).pages) {
        const local = props.playhead - page.start;
        // Un cabezal sin mover (0) no es un instante elegido a propósito: el campo queda vacío y se exporta en reposo.
        times[page.id] = local > 0 && local < page.source.duration ? Math.floor(local * 1e6) / 1e6 : '';
    }
    pageFrameTimes.value = times;
}
watch(() => props.modelValue, open => {
    if (open) initializeFrames();
    else savedExportId.value = null;
}, { immediate: true });

async function openPublication(exportId, postId) {
    status.value = postId ? 'Actualizando la publicación…' : 'Creando el borrador de publicación…';
    const publication = await publicationClient.post(exportId, postId);
    router.visit(publication.post.edit_url);
}

async function run(mode) {
    if (busy.value) return;
    const reuse = mode === 'draft' ? savedExportId.value : null;
    if (!reuse && !selectedPages.value.length) { error.value = 'Selecciona al menos una página.'; return; }
    if (!reuse && mode !== 'download' && !raster.value) { error.value = 'Para la biblioteca y publicaciones elige PNG, JPG o WebP.'; return; }
    let registeredId = null;
    busy.value = true; emit('busy', true); error.value = '';
    try {
        if (reuse) {
            await openPublication(reuse);
            return;
        }
        status.value = 'Guardando la revisión del diseño…';
        const snapshot = await props.prepare();
        const pageIds = snapshot.document.pages.filter(page => selectedPages.value.includes(page.id)).map(page => page.id);
        const temporal = explicitFrames(snapshot.document, pageIds);
        status.value = 'Preparando fuentes, recursos y páginas…';
        const files = await exportDesign(snapshot.document, { format: format.value, pageIds, assets: props.assets, fonts: props.fonts, chartResources: props.chartResources, scale: Number(scale.value), quality: Number(quality.value), transparent: transparent.value && format.value !== 'jpeg', title: snapshot.name, ...temporal, onProgress: ({ completed, total }) => { status.value = `Renderizadas ${completed} de ${total} páginas.`; } });
        if (mode === 'download') {
            files.forEach((output, i) => downloadStudioBlob(output.blob, `${snapshot.name}${files.length > 1 ? `-${i + 1}` : ''}.${output.extension}`));
            status.value = `${files.length} ${files.length === 1 ? 'archivo preparado' : 'archivos preparados'}.`;
            return;
        }
        const ids = [];
        for (let i = 0; i < files.length; i++) {
            const output = files[i];
            const asset = await uploadStudioFile(new File([output.blob], `${snapshot.name}-${i + 1}.${output.extension}`, { type: output.mime }), message => { status.value = `${i + 1}/${files.length} · ${message}`; });
            ids.push(asset.id);
        }
        status.value = 'Registrando la exportación en esta marca…';
        const result = await studioRequest('POST', route('studio.exports.store', props.designId), { revision: snapshot.revision, format: format.value, pages: pageIds, media_ids: ids, scale: Number(scale.value), quality: Number(quality.value), transparent: transparent.value && format.value !== 'jpeg', ...temporal });
        emit('registered', result.export);
        registeredId = result.export.id;
        if (mode === 'draft' || mode === 'update') {
            await openPublication(registeredId, mode === 'update' ? props.sourcePostId : undefined);
        } else status.value = 'Exportación guardada en la biblioteca de esta marca.';
    } catch (e) {
        const failure = exportFailure(e);
        error.value = failure.message;
        if (mode === 'update' && registeredId && e?.status === 422) savedExportId.value = registeredId;
        status.value = savedExportId.value ? 'Exportación guardada en la biblioteca de esta marca.' : '';
        if (failure.conflict) emit('conflict', failure.message);
    }
    finally {
        busy.value = false;
        emit('busy', false);
        if (mode === 'update' && savedExportId.value) {
            await nextTick();
            draftAction.value?.focus();
        }
    }
}
function publish() {
    if (!raster.value && !native.value) format.value = 'png';
    initializeFrames();
    emit('update:modelValue', true);
    if (!native.value && !props.sourcePostId) return run('draft');
}
defineExpose({ publish });
</script>
<template>
    <StudioDialog
        :model-value="modelValue"
        title="Exportar diseño"
        @update:model-value="value => { if (!busy) emit('update:modelValue', value); }"
    >
        <div class="space-y-5">
            <p
                class="text-sm leading-relaxed text-foreground-subtle"
                >{{ native ? (serverImage ? 'Se renderiza en el servidor con la marca de agua de la marca y se guarda en la biblioteca.' : format === 'pptx' ? 'Exporta cada página como una diapositiva de PowerPoint con la imagen del diseño; el texto no es editable en PowerPoint.' : format === 'gif' ? 'Exporta la secuencia de páginas como una animación sin sonido.' : 'Exporta la secuencia de páginas con vídeo, transiciones y mezcla de audio.') : 'Exporta las páginas elegidas. Los elementos de vídeo se incluyen como fotograma estático.' }}</p
            >
            <label class="block space-y-1 text-sm font-medium"
                >Formato<select v-model="format" aria-label="Formato" class="field" :disabled="busy"
                    ><option
                        v-for="item in formats"
                        :key="item.value"
                        :value="item.value"
                        >{{ item.label }}</option
                    ></select
                ></label
            >
            <fieldset :disabled="busy" class="space-y-4">
                <div class="grid grid-cols-2 gap-4"
                    ><label class="space-y-1 text-sm font-medium"
                        >Escala<select v-model.number="scale" aria-label="Escala" class="field"
                            ><option :value="0.5">0,5 ×</option
                            ><option :value="1">1 ×</option
                            ><option :value="2">2 ×</option
                            ><option :value="3">3 ×</option
                            ><option :value="4">4 ×</option></select
                        ></label
                    ><label class="space-y-1 text-sm font-medium"
                        >Calidad<input
                            v-model.number="quality"
                            class="field"
                            type="number"
                            min="0.1"
                            max="1"
                            step="0.05" /></label
                ></div>
                <p class="text-xs text-foreground-subtle"
                    >{{ format === 'svg' ? `${document.width} × ${document.height} px · vector y medios embebidos` : outputSize
                    }}{{ format === 'pdf' ? ' · un fotograma por página en PDF' : '' }}</p
                >
                <label v-if="(!native || serverImage) && format !== 'jpeg'" class="flex items-center gap-2 text-sm"
                    ><input v-model="transparent" type="checkbox" />Fondo transparente</label
                ><div v-if="raster" class="rounded-xl border border-border p-3"
                    ><label class="flex items-center gap-2 text-sm font-medium"
                        ><input
                            v-model="applyWatermark"
                            type="checkbox"
                            :disabled="!watermarkAvailable"
                            :aria-describedby="watermarkHintId"
                        />Aplicar la marca de agua de la marca</label
                    ><p
                        :id="watermarkHintId"
                        class="mt-1 text-xs leading-relaxed text-foreground-subtle"
                        >{{ !watermarkAvailable ? 'La marca no tiene marca de agua configurada: la imagen no llevará marca de agua.' : applyWatermark ? 'La imagen llevará la marca de agua. Se prepara en el servidor, en segundo plano.' : 'La imagen no llevará marca de agua.' }}</p
                    ></div
                >
                <div
                    ><h3 class="mb-2 text-sm font-medium">Páginas</h3
                    ><p v-if="native && !serverImage" class="mb-2 text-xs text-foreground-subtle"
                        >Selecciona páginas y ajusta su orden con las flechas.</p
                    ><p v-else-if="document.schema_version === 2" class="mb-2 text-xs text-foreground-subtle"
                        >Elige un segundo local por página. Las páginas donde está el cabezal parten de ese instante.</p
                    ><div class="max-h-60 space-y-3 overflow-y-auto rounded-xl border border-border p-3"
                        ><div v-for="(page, index) in orderedPages" :key="page.id" class="min-w-0 space-y-2"
                            ><div class="flex items-center justify-between gap-2"
                                ><label class="flex min-w-0 items-center gap-2 text-sm"
                                    ><input v-model="selectedPages" type="checkbox" :value="page.id" /><span
                                        class="break-words"
                                        >{{ index + 1 }}. {{ page.name }}</span
                                    ></label
                                ><span v-if="native" class="flex shrink-0 gap-1"
                                    ><button
                                        type="button"
                                        :aria-label="`Subir página ${page.name}`"
                                        :disabled="index === 0"
                                        class="rounded-lg border border-border px-2 py-1 disabled:opacity-30"
                                        @click="movePage(page.id, -1)"
                                        >↑</button
                                    ><button
                                        type="button"
                                        :aria-label="`Bajar página ${page.name}`"
                                        :disabled="index === orderedPages.length - 1"
                                        class="rounded-lg border border-border px-2 py-1 disabled:opacity-30"
                                        @click="movePage(page.id, 1)"
                                        >↓</button
                                    ></span
                                ></div
                            ><label
                                v-if="(!native || serverImage) && document.schema_version === 2"
                                class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-foreground-muted"
                                ><span>Fotograma (s)</span
                                ><input
                                    v-model.number="pageFrameTimes[page.id]"
                                    :aria-label="`Fotograma de ${page.name} (s)`"
                                    type="number"
                                    min="0"
                                    :max="page.duration - 0.000001"
                                    step="0.001"
                                    class="field w-28 text-xs"
                                    :disabled="!selectedPages.includes(page.id)"
                                /><span
                                    >antes de {{ page.duration }} s ·
                                    {{ Number.isFinite(pageFrameTimes[page.id]) ? 'se exporta ese instante, con sus animaciones' : 'vacío = diseño en reposo (animaciones completadas)' }}</span
                                ></label
                            ></div
                        ></div
                    ></div
                >
            </fieldset>
            <NativeExportJobs
                v-if="native"
                :active="modelValue"
                :design-id="designId"
                :source-post-id="sourcePostId"
                :format="format"
                :prepare="nativeOptions"
                @busy="value => { busy = value; emit('busy', value); }"
                @registered="emit('registered', $event)"
                @conflict="message => emit('conflict', message)"
            />
            <p
                v-if="status"
                role="status"
                class="rounded-xl bg-accent-secondary-soft p-3 text-sm text-accent-secondary-ink"
                >{{ status }}</p
            >
            <p v-if="error" role="alert" class="rounded-xl bg-danger-soft p-3 text-sm text-danger-ink">{{ error }}</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4"
                ><button type="button" class="btn-secondary" :disabled="busy" @click="emit('update:modelValue',false)"
                    >Cerrar exportación</button
                ><button
                    v-if="raster && !native"
                    type="button"
                    class="btn-secondary"
                    :disabled="busy"
                    @click="run('library')"
                    >Guardar en biblioteca</button
                ><button
                    v-if="raster && !native && sourcePostId && !savedExportId"
                    type="button"
                    class="btn-secondary-accent"
                    data-dialog-initial-focus
                    :disabled="busy"
                    @click="run('update')"
                    >Actualizar publicación</button
                ><button
                    v-if="raster && !native && sourcePostId"
                    ref="draftAction"
                    type="button"
                    :class="savedExportId ? 'btn-secondary-accent' : 'btn-secondary'"
                    :disabled="busy"
                    @click="run('draft')"
                    >{{ savedExportId ? 'Crear borrador con esta exportación' : 'Crear borrador nuevo' }}</button
                ><button
                    v-if="!native"
                    type="button"
                    class="btn-secondary-accent"
                    :disabled="busy"
                    @click="run('download')"
                    >{{ busy ? 'Preparando…' : 'Descargar' }}</button
                ></div
            >
        </div>
    </StudioDialog>
</template>
