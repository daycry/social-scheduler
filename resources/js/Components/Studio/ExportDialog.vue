<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import StudioDialog from './StudioDialog.vue';
import { EXPORT_FORMATS, exportDesign } from '@/Studio/exports';
import { studioRequest, uploadStudioFile, downloadStudioBlob } from '@/Studio/api';

const props = defineProps({ modelValue: Boolean, document: Object, assets: Array, designId: Number, prepare: Function });
const emit = defineEmits(['update:modelValue', 'busy', 'registered']);
const format = ref('png');
const scale = ref(1);
const quality = ref(0.94);
const transparent = ref(false);
const selectedPages = ref(props.document.pages.map(page => page.id));
const busy = ref(false);
const status = ref('');
const error = ref('');
const raster = computed(() => ['png', 'jpeg', 'webp'].includes(format.value));
const outputSize = computed(() => `${Math.round(props.document.width * scale.value)} × ${Math.round(props.document.height * scale.value)} px`);
watch(() => props.document.pages.map(page => page.id).join(','), () => { selectedPages.value = props.document.pages.map(page => page.id); });

async function run(mode) {
    if (busy.value) return;
    if (!selectedPages.value.length) { error.value = 'Selecciona al menos una página.'; return; }
    if (mode !== 'download' && !raster.value) { error.value = 'Para la biblioteca y publicaciones elige PNG, JPG o WebP.'; return; }
    busy.value = true; emit('busy', true); error.value = '';
    try {
        status.value = 'Guardando la revisión del diseño…';
        const snapshot = await props.prepare();
        const pageIds = snapshot.document.pages.filter(page => selectedPages.value.includes(page.id)).map(page => page.id);
        status.value = 'Preparando fuentes, recursos y páginas…';
        const files = await exportDesign(snapshot.document, { format: format.value, pageIds, assets: props.assets, scale: Number(scale.value), quality: Number(quality.value), transparent: transparent.value && format.value !== 'jpeg', title: snapshot.name, onProgress: ({ completed, total }) => { status.value = `Renderizadas ${completed} de ${total} páginas.`; } });
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
        const result = await studioRequest('POST', route('studio.exports.store', props.designId), { revision: snapshot.revision, format: format.value, pages: pageIds, media_ids: ids, scale: Number(scale.value), quality: Number(quality.value), transparent: transparent.value && format.value !== 'jpeg' });
        emit('registered', result.export);
        if (mode === 'post') {
            status.value = 'Creando el borrador de publicación…';
            const publication = await studioRequest('POST', route('studio.exports.post', result.export.id), {});
            router.visit(publication.post.edit_url);
        } else status.value = 'Exportación guardada en la biblioteca de esta marca.';
    } catch (e) { error.value = e.message; status.value = ''; }
    finally { busy.value = false; emit('busy', false); }
}
function publish() {
    if (!raster.value) format.value = 'png';
    emit('update:modelValue', true);
    return run('post');
}
defineExpose({ publish });
</script>
<template>
    <StudioDialog :model-value="modelValue" title="Exportar diseño" @update:model-value="value => { if (!busy) emit('update:modelValue', value); }">
        <div class="space-y-5">
            <p class="text-sm leading-relaxed text-slate-500">Exporta las páginas elegidas. Los elementos de vídeo se incluyen como fotograma estático.</p>
            <label class="block space-y-1 text-sm font-medium">Formato<select v-model="format" aria-label="Formato" class="field" :disabled="busy"><option v-for="item in EXPORT_FORMATS" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <fieldset :disabled="busy" class="space-y-4">
                <div class="grid grid-cols-2 gap-4"><label class="space-y-1 text-sm font-medium">Escala<select v-model.number="scale" aria-label="Escala" class="field"><option :value="0.5">0,5 ×</option><option :value="1">1 ×</option><option :value="2">2 ×</option><option :value="3">3 ×</option><option :value="4">4 ×</option></select></label><label class="space-y-1 text-sm font-medium">Calidad<input v-model.number="quality" class="field" type="number" min="0.1" max="1" step="0.05" /></label></div>
                <p class="text-xs text-slate-500">{{ format === 'svg' ? `${document.width} × ${document.height} px · vector y medios embebidos` : outputSize }}{{ format === 'pdf' ? ' · un fotograma por página en PDF' : '' }}</p>
                <label v-if="format !== 'jpeg'" class="flex items-center gap-2 text-sm"><input v-model="transparent" type="checkbox" />Fondo transparente</label>
                <div><h3 class="mb-2 text-sm font-medium">Páginas</h3><div class="max-h-44 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3"><label v-for="(page, index) in document.pages" :key="page.id" class="flex items-center gap-2 text-sm"><input v-model="selectedPages" type="checkbox" :value="page.id" />{{ index + 1 }}. {{ page.name }}</label></div></div>
            </fieldset>
            <p v-if="status" role="status" class="rounded-xl bg-violet-50 p-3 text-sm text-violet-800">{{ status }}</p>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="btn-secondary" :disabled="busy" @click="emit('update:modelValue',false)">Cerrar exportación</button><button v-if="raster" type="button" class="btn-secondary" :disabled="busy" @click="run('library')">Guardar en biblioteca</button><button type="button" class="btn-primary bg-violet-700" :disabled="busy" @click="run('download')">{{ busy ? 'Preparando…' : 'Descargar' }}</button></div>
        </div>
    </StudioDialog>
</template>
