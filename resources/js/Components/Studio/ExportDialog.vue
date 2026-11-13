<script setup>
import { exportFailure } from '@/Studio/export-conflict';
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import StudioDialog from './StudioDialog.vue';
import NativeExportJobs from './NativeExportJobs.vue';
import { EXPORT_FORMATS, exportDesign } from '@/Studio/exports';
import { studioRequest, uploadStudioFile, downloadStudioBlob } from '@/Studio/api';
import { compileTimeline } from '@/Studio/timeline';

const props = defineProps({ modelValue: Boolean, document: Object, assets: Array, fonts: { type: Array, default: () => [] }, chartResources: { type: Object, default: null }, designId: Number, prepare: Function, playhead: { type: Number, default: 0 } });
const emit = defineEmits(['update:modelValue', 'busy', 'registered', 'conflict']);
const format = ref('png');
const scale = ref(1);
const quality = ref(0.94);
const transparent = ref(false);
const selectedPages = ref(props.document.pages.map(page => page.id));
const pageFrameTimes = ref(Object.create(null));
const busy = ref(false);
const status = ref('');
const error = ref('');
const raster = computed(() => ['png', 'jpeg', 'webp'].includes(format.value));
// Trabajos que prepara el servidor en segundo plano (cola): vídeo, GIF y PowerPoint. El resto se renderiza en el navegador.
const native = computed(() => ['mp4', 'gif', 'pptx'].includes(format.value));
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
    return { revision: snapshot.revision, format: format.value, pages, scale: Number(scale.value), quality: Number(quality.value) };
}
function initializeFrames() {
    const times = Object.create(null);
    if (props.document.schema_version === 2) for (const page of compileTimeline(props.document).pages) {
        const local = props.playhead - page.start;
        times[page.id] = local >= 0 && local < page.source.duration ? Math.floor(local * 1e6) / 1e6 : 0;
    }
    pageFrameTimes.value = times;
}
watch(() => props.modelValue, open => { if (open) initializeFrames(); }, { immediate: true });

async function run(mode) {
    if (busy.value) return;
    if (!selectedPages.value.length) { error.value = 'Selecciona al menos una página.'; return; }
    if (mode !== 'download' && !raster.value) { error.value = 'Para la biblioteca y publicaciones elige PNG, JPG o WebP.'; return; }
    busy.value = true; emit('busy', true); error.value = '';
    try {
        status.value = 'Guardando la revisión del diseño…';
        const snapshot = await props.prepare();
        const pageIds = snapshot.document.pages.filter(page => selectedPages.value.includes(page.id)).map(page => page.id);
        const temporal = snapshot.document.schema_version === 2
            ? { frame_times: pageIds.map(page_id => ({ page_id, time: pageFrameTimes.value[page_id] ?? 0 })) } : {};
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
        if (mode === 'post') {
            status.value = 'Creando el borrador de publicación…';
            const publication = await studioRequest('POST', route('studio.exports.post', result.export.id), {});
            router.visit(publication.post.edit_url);
        } else status.value = 'Exportación guardada en la biblioteca de esta marca.';
    } catch (e) { const failure = exportFailure(e); error.value = failure.message; status.value = ''; if (failure.conflict) emit('conflict', failure.message); }
    finally { busy.value = false; emit('busy', false); }
}
function publish() {
    if (!raster.value && !native.value) format.value = 'png';
    initializeFrames();
    emit('update:modelValue', true);
    if (!native.value) return run('post');
}
defineExpose({ publish });
</script>
<template>
    <StudioDialog :model-value="modelValue" title="Exportar diseño" @update:model-value="value => { if (!busy) emit('update:modelValue', value); }">
        <div class="space-y-5">
            <p class="text-sm leading-relaxed text-slate-500">{{ native ? (format === 'pptx' ? 'Exporta cada página como una diapositiva de PowerPoint con la imagen del diseño; el texto no es editable en PowerPoint.' : format === 'gif' ? 'Exporta la secuencia de páginas como una animación sin sonido.' : 'Exporta la secuencia de páginas con vídeo, transiciones y mezcla de audio.') : 'Exporta las páginas elegidas. Los elementos de vídeo se incluyen como fotograma estático.' }}</p>
            <label class="block space-y-1 text-sm font-medium">Formato<select v-model="format" aria-label="Formato" class="field" :disabled="busy"><option v-for="item in formats" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <fieldset :disabled="busy" class="space-y-4">
                <div class="grid grid-cols-2 gap-4"><label class="space-y-1 text-sm font-medium">Escala<select v-model.number="scale" aria-label="Escala" class="field"><option :value="0.5">0,5 ×</option><option :value="1">1 ×</option><option :value="2">2 ×</option><option :value="3">3 ×</option><option :value="4">4 ×</option></select></label><label class="space-y-1 text-sm font-medium">Calidad<input v-model.number="quality" class="field" type="number" min="0.1" max="1" step="0.05" /></label></div>
                <p class="text-xs text-slate-500">{{ format === 'svg' ? `${document.width} × ${document.height} px · vector y medios embebidos` : outputSize }}{{ format === 'pdf' ? ' · un fotograma por página en PDF' : '' }}</p>
                <label v-if="!native && format !== 'jpeg'" class="flex items-center gap-2 text-sm"><input v-model="transparent" type="checkbox" />Fondo transparente</label>
                <div><h3 class="mb-2 text-sm font-medium">Páginas</h3><p v-if="native" class="mb-2 text-xs text-slate-500">Selecciona páginas y ajusta su orden con las flechas.</p><p v-else-if="document.schema_version === 2" class="mb-2 text-xs text-slate-500">Elige un segundo local por página. Las páginas donde está el cabezal parten de ese instante.</p><div class="max-h-60 space-y-3 overflow-y-auto rounded-xl border border-slate-200 p-3"><div v-for="(page, index) in orderedPages" :key="page.id" class="min-w-0 space-y-2"><div class="flex items-center justify-between gap-2"><label class="flex min-w-0 items-center gap-2 text-sm"><input v-model="selectedPages" type="checkbox" :value="page.id" /><span class="break-words">{{ index + 1 }}. {{ page.name }}</span></label><span v-if="native" class="flex shrink-0 gap-1"><button type="button" :aria-label="`Subir página ${page.name}`" :disabled="index === 0" class="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-30" @click="movePage(page.id, -1)">↑</button><button type="button" :aria-label="`Bajar página ${page.name}`" :disabled="index === orderedPages.length - 1" class="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-30" @click="movePage(page.id, 1)">↓</button></span></div><label v-if="!native && document.schema_version === 2" class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-slate-600"><span>Fotograma (s)</span><input v-model.number="pageFrameTimes[page.id]" :aria-label="`Fotograma de ${page.name} (s)`" type="number" min="0" :max="page.duration - 0.000001" step="0.001" class="field w-28 text-xs" :disabled="!selectedPages.includes(page.id)" /><span>antes de {{ page.duration }} s</span></label></div></div></div>
            </fieldset>
            <NativeExportJobs v-if="native" :active="modelValue" :design-id="designId" :format="format" :prepare="nativeOptions" @busy="value => { busy = value; emit('busy', value); }" @registered="emit('registered', $event)"  @conflict="message => emit('conflict', message)" />
            <p v-if="status" role="status" class="rounded-xl bg-violet-50 p-3 text-sm text-violet-800">{{ status }}</p>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="btn-secondary" :disabled="busy" @click="emit('update:modelValue',false)">Cerrar exportación</button><button v-if="raster" type="button" class="btn-secondary" :disabled="busy" @click="run('library')">Guardar en biblioteca</button><button v-if="!native" type="button" class="btn-primary bg-violet-700" :disabled="busy" @click="run('download')">{{ busy ? 'Preparando…' : 'Descargar' }}</button></div>
        </div>
    </StudioDialog>
</template>
