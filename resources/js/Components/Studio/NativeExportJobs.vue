<script setup>
import { exportFailure } from '@/Studio/export-conflict';
import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { createStudioExportClient, createExportMonitor } from '@/Studio/export-jobs';

const props = defineProps({ active: Boolean, designId: Number, format: String, prepare: Function });
const emit = defineEmits(['busy', 'registered', 'conflict']);
const client = createStudioExportClient();
const jobs = ref([]);
const pagination = ref({ current_page: 1, last_page: 1 });
const page = ref(1);
const tracking = ref({ loading: false, paused: false, error: '' });
const submitting = ref(false);
const action = ref(null);
const error = ref('');
let disposed = false;
let view = 0;
const current = token => !disposed && props.active && token === view;
const labels = { pending: 'En cola', processing: 'Procesando', ready: 'Lista', failed: 'Error', cancelled: 'Cancelada' };
const monitor = createExportMonitor({
    load: ({ signal }) => client.list(props.designId, page.value, signal),
    onUpdate: data => { jobs.value = data.exports; pagination.value = data.pagination; },
    onState: state => { tracking.value = state; },
});
const update = job => { jobs.value = [job, ...jobs.value.filter(item => item.id !== job.id)]; emit('registered', job); };

async function begin() {
    if (submitting.value || action.value) return;
    const token = view;
    submitting.value = true; error.value = ''; monitor.stop(); emit('busy', true);
    try {
        const options = await props.prepare();
        if (!current(token)) return;
        const job = await client.start(props.designId, options);
        if (current(token)) { page.value = 1; update(job); }
    } catch (e) { if (current(token)) { const failure = exportFailure(e); error.value = failure.message; if (failure.conflict) emit('conflict', failure.message); } }
    finally {
        submitting.value = false;
        if (!disposed) { emit('busy', false); if (props.active) monitor.start(); }
    }
}
async function control(job, operation) {
    if (submitting.value || action.value) return;
    const token = view;
    action.value = job.id; error.value = ''; monitor.stop();
    try {
        if (operation === 'post') {
            const result = await client.post(job.id);
            if (current(token)) router.visit(result.post.edit_url);
        } else {
            const current = await client[operation](job.id);
            if (token === view && !disposed && props.active) update(current);
        }
    } catch (e) { if (current(token)) { const failure = exportFailure(e); error.value = failure.message; if (failure.conflict) emit('conflict', failure.message); } }
    finally {
        action.value = null;
        if (!disposed && props.active) monitor.start();
    }
}
function turn(value) {
    if (submitting.value || action.value || value < 1 || value > pagination.value.last_page) return;
    page.value = value; monitor.start();
}
watch(() => [props.active, props.format, props.designId], ([active], previous) => {
    view++; monitor.stop();
    if (previous && previous[2] !== props.designId) { jobs.value = []; page.value = 1; }
    if (active && !submitting.value && action.value === null) monitor.start();
}, { immediate: true });
onBeforeUnmount(() => { disposed = true; view++; monitor.stop(); emit('busy', false); });
</script>

<template>
    <section class="space-y-4" aria-label="Exportación en segundo plano">
        <div class="rounded-xl border border-violet-200 bg-violet-50 p-4">
            <p class="text-sm leading-relaxed text-violet-950">{{ format === 'pptx' ? 'El archivo se prepara en segundo plano y se descarga desde esta lista; no se guarda en la biblioteca.' : 'El archivo se prepara en segundo plano y se guarda en la biblioteca de esta marca.' }} Puedes cerrar este diálogo y seguir editando.</p>
            <button type="button" class="btn-primary mt-3 bg-violet-700" :disabled="submitting || action !== null" @click="begin">{{ submitting ? 'Guardando y enviando…' : `Preparar ${format.toUpperCase()}` }}</button>
        </div>
        <p v-if="error || tracking.error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error || tracking.error }}</p>
        <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-sm font-semibold">Exportaciones del diseño</h3><button type="button" class="btn-secondary text-xs" :disabled="tracking.loading || submitting || action !== null" @click="monitor.refresh">Actualizar exportaciones</button></div>
        <p v-if="tracking.paused" role="status" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">La consulta automática se ha detenido. El trabajo puede continuar en el servidor. Pulsa Actualizar exportaciones para seguir sin crear otro trabajo.</p>
        <p v-if="!jobs.length" class="text-sm text-slate-500">{{ tracking.loading ? 'Consultando exportaciones…' : 'Todavía no hay exportaciones en esta página.' }}</p>
        <div class="space-y-3">
            <section v-for="job in jobs" :key="job.id" :aria-label="`Exportación ${job.id}`" class="min-w-0 space-y-3 rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2"><span class="text-sm font-semibold">{{ job.format.toUpperCase() }} <span class="font-normal text-slate-500">· Revisión {{ job.revision }}</span></span><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ labels[job.status] || job.status }}</span></div>
                <div v-if="job.progress && ['pending', 'processing'].includes(job.status)" class="space-y-1"><label :for="`export-progress-${job.id}`" class="text-xs text-slate-600">Progreso: {{ job.progress.percent }} %</label><progress :id="`export-progress-${job.id}`" :value="job.progress.percent" max="100" class="h-2 w-full accent-violet-600" /></div>
                <p v-if="job.error" class="break-words text-xs leading-relaxed text-red-800">{{ job.error }}</p>
                <div class="flex flex-wrap gap-2">
                    <button v-if="job.can_cancel" type="button" class="btn-secondary text-xs" :disabled="action !== null || submitting" @click="control(job, 'cancel')">Cancelar</button>
                    <button v-if="job.can_retry" type="button" class="btn-secondary text-xs" :disabled="action !== null || submitting" @click="control(job, 'retry')">Reintentar</button>
                    <a v-if="job.status === 'ready' && job.download_url" :href="job.download_url" class="btn-secondary text-xs" download>Descargar {{ job.format.toUpperCase() }}</a>
                    <button v-if="job.status === 'ready' && job.media_ids?.length && ['png', 'jpeg', 'webp', 'mp4', 'gif'].includes(job.format)" type="button" class="btn-primary bg-violet-700 text-xs" :disabled="action !== null || submitting" @click="control(job, 'post')">Crear borrador</button>
                </div>
            </section>
        </div>
        <nav v-if="pagination.last_page > 1" aria-label="Páginas de exportaciones" class="flex flex-wrap items-center justify-between gap-2 text-xs"><button type="button" class="btn-secondary text-xs" :disabled="page <= 1 || submitting || action !== null" @click="turn(page - 1)">Anteriores</button><span>Página {{ page }} de {{ pagination.last_page }}</span><button type="button" class="btn-secondary text-xs" :disabled="page >= pagination.last_page || submitting || action !== null" @click="turn(page + 1)">Siguientes</button></nav>
    </section>
</template>
