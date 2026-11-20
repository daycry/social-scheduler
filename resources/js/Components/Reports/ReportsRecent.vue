<script setup>
import Pagination from '@/Components/Pagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePolling } from '@/Composables/usePolling';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    reports: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportRow[]>} */ (Array), required: true },
    pagination: { type: /** @type {import('vue').PropType<import('@/types/pagination').CursorPage>} */ (Object), required: true },
    brandId: /** @type {import('vue').PropType<number | string | null>} */ ([Number, String]),
    polling: Boolean,
});
const ask = useConfirm();

const STATUS = {
    pending: ['En cola', 'bg-slate-100 text-slate-700'],
    processing: ['Generando...', 'bg-blue-100 text-blue-700'],
    ready: ['Listo', 'bg-emerald-100 text-emerald-800'],
    failed: ['Error', 'bg-red-100 text-red-700'],
};

/** @param {AbortSignal} signal @returns {Promise<void>} */
function refreshReports(signal) {
    return new Promise(resolve => {
        /** @type {(() => void) | undefined} */
        let cancel;
        const abort = () => cancel?.();
        signal.addEventListener('abort', abort, { once: true });
        router.reload({
            // Si cambia la página visible, también cambian su cursor y su total.
            only: ['reports', 'polling', 'pagination'],
            onCancelToken: token => {
                cancel = token.cancel;
                if (signal.aborted) abort();
            },
            onFinish: () => {
                signal.removeEventListener('abort', abort);
                resolve();
            },
        });
    });
}

const { exhausted: pollingExhausted, restart: restartPolling } = usePolling(
    () => props.polling,
    refreshReports,
    { initialDelay: 4000, maxDelay: 30000, maxAttempts: 40 },
);

defineExpose({ restartPolling });

/** @param {import('@/types/reports').ReportRow} r */
const remove = async (r) => {
    if (await ask({ message: `¿Eliminar el informe de ${r.brand} (${r.format.toUpperCase()}, ${r.from} - ${r.to})? También se borra su fichero.`, confirmLabel: 'Eliminar informe' })) {
        router.delete(route('reports.destroy', r.id), { preserveScroll: true });
    }
};

/** @param {number | null} b */
const size = (b) => (b ? (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`) : '');
</script>

<template>
    <section class="card">
        <h2 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Informes recientes</h2>
        <div :class="pollingExhausted ? 'flex flex-wrap items-center gap-2 px-5 py-3' : 'sr-only'">
            <p
                role="status"
                class="text-sm text-slate-600"
                >{{ pollingExhausted ? 'Todavía hay informes pendientes.' : '' }}</p
            >
            <SecondaryButton v-if="pollingExhausted" type="button" @click="restartPolling"
                >Volver a consultar</SecondaryButton
            >
        </div>
        <p v-if="reports.length === 0" class="p-5 text-sm text-slate-600">Aún no has generado informes.</p>
        <ul v-else class="divide-y">
            <li
                v-for="r in reports"
                :key="r.id"
                class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm"
                :data-report="r.id"
            >
                <div>
                    <p class="font-medium text-slate-900">{{ r.brand }} · {{ r.format.toUpperCase() }}</p>
                    <p class="text-xs text-slate-600"
                        >{{ r.from }} - {{ r.to }} <span v-if="r.size">· {{ size(r.size) }}</span></p
                    >
                    <p v-if="r.status === 'failed' && r.error" class="text-xs text-red-700">{{ r.error }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="rounded-full px-2 py-0.5 text-xs"
                        :class="STATUS[r.status][1]"
                        >{{ STATUS[r.status][0] }}</span
                    >
                    <a v-if="r.download_url" :href="r.download_url" class="link">Descargar</a>
                    <button v-if="r.deletable" type="button" class="text-xs text-red-700 underline" @click="remove(r)"
                        >Eliminar</button
                    >
                </div>
            </li>
        </ul>
        <Pagination
            :page="pagination"
            route-name="reports.index"
            label="Paginación de informes recientes"
            :params="{ brand_id: brandId }"
            class="border-t px-5 py-3"
        />
    </section>
</template>
