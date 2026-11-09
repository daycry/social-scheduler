<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { studioRequest } from '@/Studio/api';

const props = defineProps({ brandId: Number, sourceId: Number, sourceName: String, requestToken: Number });
const emit = defineEmits(['busy', 'ready', 'add', 'close']);
const job = ref(null);
const error = ref('');
const paused = ref(false);
const requesting = ref(false);
const status = computed(() => job.value?.status === 'ready' ? 'Imagen sin fondo preparada.' : job.value?.status === 'failed' ? 'No se ha podido quitar el fondo.' : job.value?.status === 'processing' ? 'Separando el sujeto y el fondo…' : 'Imagen en cola para quitar el fondo…');
let disposed = false;
let generation = 0;
let timer;
let controller;
let startedAt = 0;

function stop() { clearTimeout(timer); controller?.abort(); }
function accept(result) {
    job.value = result;
    if (result.status === 'ready') {
        if (!result.output_asset) { error.value = 'El trabajo terminó sin un archivo disponible. Vuelve a consultar el resultado.'; paused.value = true; return; }
        emit('ready', result.output_asset); emit('busy', false);
    } else if (result.status === 'failed') {
        error.value = result.error || 'No se pudo procesar esta imagen. El original se conserva.';
        emit('busy', false);
    }
}
async function check(token) {
    if (disposed || token !== generation || !job.value || requesting.value) return;
    if (performance.now() - startedAt > 5 * 60 * 1000) { paused.value = true; return; }
    requesting.value = true; controller = new AbortController();
    try {
        const response = await fetch(route('studio.image-jobs.show', { brand: props.brandId, job: job.value.id }), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || 'No se pudo consultar el trabajo de imagen.');
        if (disposed || token !== generation) return;
        accept(result.job);
        if (['queued', 'processing'].includes(result.job.status)) timer = setTimeout(() => check(token), 1500);
    } catch (e) { if (!disposed && token === generation && e.name !== 'AbortError') { error.value = e.message; paused.value = true; } }
    finally { if (!disposed && token === generation) requesting.value = false; }
}
async function begin() {
    stop();
    const token = ++generation;
    job.value = null; error.value = ''; paused.value = false; requesting.value = true;
    emit('busy', true);
    try {
        const result = await studioRequest('POST', route('studio.image-jobs.store', props.brandId), { media_id: props.sourceId, operation: 'remove_background' });
        if (disposed || token !== generation) return;
        accept(result.job);
        startedAt = performance.now();
        if (['queued', 'processing'].includes(result.job.status)) timer = setTimeout(() => check(token), 1500);
    } catch (e) { if (!disposed && token === generation) { error.value = e.message; emit('busy', false); } }
    finally { if (!disposed && token === generation) requesting.value = false; }
}
function resume() {
    if (requesting.value || !job.value) return;
    error.value = ''; paused.value = false; startedAt = performance.now();
    check(generation);
}
function close() { stop(); generation++; emit('busy', false); emit('close'); }
watch(() => [props.sourceId, props.requestToken], () => { if (props.sourceId) begin(); }, { immediate: true });
onBeforeUnmount(() => { disposed = true; generation++; stop(); });
</script>

<template>
    <section class="space-y-3 rounded-2xl border border-violet-200 bg-violet-50/50 p-4" aria-label="Seguimiento de eliminación de fondo">
        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="text-sm font-semibold text-violet-950">Quitar fondo</h3><p class="mt-1 break-words text-xs text-slate-500">{{ sourceName }}</p></div><button type="button" class="rounded-lg px-2 py-1 text-xs text-slate-500 hover:bg-white" aria-label="Ocultar seguimiento de imagen" @click="close">×</button></div>
        <p v-if="job || requesting" role="status" class="text-xs leading-relaxed text-violet-800">{{ status }}</p>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-xs leading-relaxed text-red-800">{{ error }}</p>
        <p v-if="paused" class="text-xs leading-relaxed text-slate-600">La consulta automática se ha detenido. El trabajo puede continuar en el servidor; puedes volver a consultar sin crear otro.</p>
        <button v-if="paused && job" type="button" class="btn-secondary text-xs" :disabled="requesting" @click="resume">Volver a consultar imagen</button>
        <template v-if="job?.status === 'ready' && job.output_asset">
            <img :src="job.output_asset.preview_url" :alt="`Resultado sin fondo de ${sourceName}`" class="max-h-48 w-full rounded-xl border border-slate-200 bg-white object-contain" />
            <button type="button" class="w-full rounded-xl bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white hover:bg-violet-800" @click="emit('add',job.output_asset)">Añadir imagen sin fondo</button>
        </template>
        <p class="text-[10px] leading-relaxed text-slate-500">El original se conserva. Al ocultar este panel no se cancela el procesamiento; la imagen terminada aparece en los recursos de esta marca.</p>
    </section>
</template>
