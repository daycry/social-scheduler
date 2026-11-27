<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import StudioDialog from './StudioDialog.vue';
import { studioRequest } from '@/Studio/api';
import { exportFailure } from '@/Studio/export-conflict';
import { createExportMonitor, createStudioExportClient } from '@/Studio/export-jobs';
import { STUDIO_FORMAT_PRESETS, STUDIO_RESIZE_MODES } from '@/Studio/format-presets';

defineOptions({ name: 'StudioBatchExportDialog' });
const props = defineProps({ modelValue: Boolean, document: Object, designId: Number, watermark: { type: Object, default: null }, prepare: Function });
const emit = defineEmits(['update:modelValue', 'busy', 'conflict']);
const client = createStudioExportClient();
const labels = { pending: 'En cola', processing: 'Procesando', ready: 'Lista', failed: 'Error', cancelled: 'Cancelada' };
const MAX_FORMATS = 8;
const selected = ref(['square', 'portrait', 'story']);
const selectedPages = ref(props.document.pages.map(page => page.id));
const mode = ref('contain');
const imageFormat = ref('png');
const watermarkAvailable = computed(() => props.watermark?.logo_id != null);
const applyWatermark = ref(Boolean(props.watermark?.enabled));
const busy = ref(false);
const error = ref('');
const items = ref([]);
const tracking = ref({ loading: false, paused: false, error: '' });
let disposed = false;

const failed = computed(() => items.value.filter(item => ['failed', 'cancelled'].includes(item.export.status)));
const finished = computed(() => items.value.filter(item => item.export.status === 'ready').length);
const working = computed(() => items.value.some(item => ['pending', 'processing'].includes(item.export.status)));
const summary = computed(() => `${finished.value} de ${items.value.length} formatos listos en la biblioteca${failed.value.length ? `, ${failed.value.length} con error` : ''}.`);
const monitor = createExportMonitor({
    load: ({ signal }) => client.list(props.designId, 1, signal),
    onUpdate: data => {
        const current = new Map(data.exports.map(job => [job.id, job]));
        items.value = items.value.map(item => ({ ...item, export: current.get(item.export.id) ?? item.export }));
    },
    onState: state => { tracking.value = state; },
});
const sizeLabel = preset => `${preset.w} × ${preset.h} px`;

watch(() => props.document.pages.map(page => page.id).join(','), () => { selectedPages.value = props.document.pages.map(page => page.id); });
watch(() => props.modelValue, open => {
    if (open && items.value.length && working.value) monitor.start();
    else if (!open) monitor.stop();
});

async function start() {
    if (busy.value) return;
    if (!selected.value.length) { error.value = 'Elige al menos un formato.'; return; }
    if (selected.value.length > MAX_FORMATS) { error.value = `Elige como máximo ${MAX_FORMATS} formatos.`; return; }
    if (!selectedPages.value.length) { error.value = 'Selecciona al menos una página.'; return; }
    busy.value = true; error.value = ''; monitor.stop(); emit('busy', true);
    try {
        const snapshot = await props.prepare();
        const pages = snapshot.document.pages.filter(page => selectedPages.value.includes(page.id)).map(page => page.id);
        const chosen = STUDIO_FORMAT_PRESETS.filter(preset => selected.value.includes(preset.key));
        const result = await studioRequest('POST', route('studio.exports.batch', props.designId), {
            revision: snapshot.revision, pages, format: imageFormat.value, mode: mode.value,
            watermark: applyWatermark.value && watermarkAvailable.value,
            formats: chosen.map(preset => ({ width: preset.w, height: preset.h })),
        });
        if (disposed) return;
        items.value = chosen.map((preset, index) => ({ preset, export: result.exports[index] }));
        monitor.start();
    } catch (e) {
        const failure = exportFailure(e);
        error.value = failure.message;
        if (failure.conflict) emit('conflict', failure.message);
    } finally {
        busy.value = false;
        if (!disposed) emit('busy', false);
    }
}
onBeforeUnmount(() => { disposed = true; monitor.stop(); emit('busy', false); });
</script>

<template>
    <StudioDialog
        :model-value="modelValue"
        title="Exportar en lote"
        wide
        @update:model-value="value => { if (!busy) emit('update:modelValue', value); }"
    >
        <form class="space-y-5" @submit.prevent="start">
            <p class="text-sm leading-relaxed text-slate-500"
                >Genera de una vez un archivo por formato a partir de este diseño. Cada archivo se prepara en segundo
                plano y se guarda en la biblioteca de esta marca. Repetir el lote no duplica los archivos que ya
                están.</p
            >
            <fieldset class="min-w-0 space-y-5" :disabled="busy">
                <fieldset class="space-y-2"
                    ><legend class="mb-2 text-sm font-medium">Formatos</legend
                    ><div class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                        ><label
                            v-for="preset in STUDIO_FORMAT_PRESETS"
                            :key="preset.key"
                            class="flex min-w-0 cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                            :class="selected.includes(preset.key) ? 'border-violet-300 bg-violet-50' : 'border-slate-200 hover:border-violet-200'"
                            ><input
                                v-model="selected"
                                type="checkbox"
                                :value="preset.key"
                                :aria-label="`Formato ${preset.label}`"
                                :disabled="!selected.includes(preset.key) && selected.length >= MAX_FORMATS"
                            /><span class="min-w-0"
                                ><span class="block text-sm font-medium">{{ preset.label }}</span
                                ><span class="mt-1 block text-xs text-slate-500">{{ sizeLabel(preset) }}</span></span
                            ></label
                        ></div
                    ></fieldset
                >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                    ><label class="block space-y-1 text-sm font-medium"
                        >Ajuste al formato<select v-model="mode" aria-label="Ajuste al formato" class="field"
                            ><option v-for="item in STUDIO_RESIZE_MODES" :key="item.value" :value="item.value">{{
                                item.label
                            }}</option></select
                        ></label
                    ><label class="block space-y-1 text-sm font-medium"
                        >Tipo de archivo<select v-model="imageFormat" aria-label="Tipo de archivo" class="field"
                            ><option value="png">PNG</option
                            ><option value="jpeg">JPG</option
                            ><option value="webp">WebP</option></select
                        ></label
                    ></div
                >
                <fieldset v-if="document.pages.length > 1" class="space-y-2"
                    ><legend class="mb-2 text-sm font-medium">Páginas</legend
                    ><p class="text-xs text-slate-500">Cada formato incluye un archivo por página elegida.</p
                    ><div class="max-h-40 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3"
                        ><label
                            v-for="(page, index) in document.pages"
                            :key="page.id"
                            class="flex items-center gap-2 text-sm"
                            ><input v-model="selectedPages" type="checkbox" :value="page.id" /><span class="break-words"
                                >{{ index + 1 }}. {{ page.name }}</span
                            ></label
                        ></div
                    ></fieldset
                >
                <div class="rounded-xl border border-slate-200 p-3"
                    ><label class="flex items-center gap-2 text-sm font-medium"
                        ><input v-model="applyWatermark" type="checkbox" :disabled="!watermarkAvailable" />Aplicar la
                        marca de agua de la marca</label
                    ><p class="mt-1 text-xs leading-relaxed text-slate-500">{{ watermarkAvailable
                            ? 'Se superpone el logotipo del kit con la posición, opacidad y tamaño configurados en Identidad de marca.'
                            : 'Configura un logotipo para la marca de agua en Identidad de marca para poder aplicarla.' }}</p></div
                >
            </fieldset>
            <section v-if="items.length" aria-label="Estado del lote" class="space-y-3">
                <p role="status" class="rounded-xl bg-violet-50 p-3 text-sm text-violet-800">{{ summary }}</p>
                <ul class="space-y-2"
                    ><li
                        v-for="item in items"
                        :key="item.export.id"
                        class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm"
                        ><span class="min-w-0 break-words"
                            >{{ item.preset.label }}
                            <span class="text-xs text-slate-500">· {{ sizeLabel(item.preset) }}</span></span
                        ><span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="item.export.status === 'ready' ? 'bg-emerald-50 text-emerald-800' : ['failed', 'cancelled'].includes(item.export.status) ? 'bg-red-50 text-red-800' : 'bg-slate-100 text-slate-700'"
                            >{{ labels[item.export.status] ?? item.export.status }}</span
                        ><span
                            v-if="item.export.error"
                            class="w-full text-xs text-red-700"
                            >{{ item.export.error }}</span
                        ></li
                    ></ul
                >
                <p v-if="tracking.error" role="alert" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800"
                    >{{ tracking.error }}
                    <button type="button" class="font-medium underline" @click="monitor.start()"
                        >Actualizar estado</button
                    ></p
                >
                <p v-else-if="tracking.paused && working" class="text-xs text-slate-500"
                    >Dejamos de consultar el estado. El lote sigue en segundo plano.
                    <button type="button" class="font-medium text-violet-700 underline" @click="monitor.start()"
                        >Actualizar estado</button
                    ></p
                >
            </section>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"
                ><button type="button" class="btn-secondary" :disabled="busy" @click="emit('update:modelValue', false)"
                    >Cerrar exportación en lote</button
                ><button
                    type="submit"
                    class="btn-primary bg-violet-700"
                    :disabled="busy || working"
                    data-dialog-initial-focus
                    >{{ busy ? 'Preparando…' : failed.length ? 'Reintentar los fallidos' : items.length ? 'Exportar de nuevo' : 'Exportar en lote' }}</button
                ></div
            >
        </form>
    </StudioDialog>
</template>
