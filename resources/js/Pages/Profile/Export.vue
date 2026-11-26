<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InfoNote from '@/Components/InfoNote.vue';
import Checkbox from '@/Components/Checkbox.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePolling } from '@/Composables/usePolling';
import { createDateFormatter } from '@/lib/format';

/** @import {FullExportLatest, FullExportDownload, FullExportLimits} from '@/types/full-export' */
const props = defineProps({
    latest: { type: /** @type {import('vue').PropType<FullExportLatest | null>} */ (Object), default: null },
    download: { type: /** @type {import('vue').PropType<FullExportDownload | null>} */ (Object), default: null },
    excluded: { type: /** @type {import('vue').PropType<string[]>} */ (Array), required: true },
    limits: { type: /** @type {import('vue').PropType<FullExportLimits>} */ (Object), required: true },
});

// Sin marca de por medio, la hora se muestra en la zona del navegador (patrón de T-54).
const formatDate = createDateFormatter(null, { dateStyle: 'medium', timeStyle: 'short' }, '—');
const active = computed(() => props.latest?.status === 'pending' || props.latest?.status === 'processing');
const includeMedia = ref(true);
const ask = useConfirm();

const STATUS = {
    pending: 'En cola',
    processing: 'Preparando el ZIP…',
    ready: 'Lista para descargar',
    failed: 'No se pudo completar',
    expired: 'Caducada',
};

/** @param {number | null} bytes */
const size = (bytes) => {
    if (!bytes) return '';
    return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
};

/** @param {AbortSignal} signal @returns {Promise<void>} */
function refresh(signal) {
    return new Promise((resolve) => {
        /** @type {(() => void) | undefined} */
        let cancel;
        const abort = () => cancel?.();
        signal.addEventListener('abort', abort, { once: true });
        router.reload({
            only: ['latest', 'download'],
            onCancelToken: (token) => {
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

const { exhausted: pollingExhausted, restart: restartPolling } = usePolling(active, refresh, {
    initialDelay: 3000,
    maxDelay: 30000,
    maxAttempts: 40,
});

async function request() {
    if (
        props.download &&
        !(await ask({
            title: 'Pedir una exportación nueva',
            message:
                'Tu copia actual sigue disponible hasta que la nueva esté lista; entonces se sustituye y su descarga deja de valer.',
            confirmLabel: 'Pedir exportación',
        }))
    )
        return;
    router.post(route('profile.export.store'), { include_media: includeMedia.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Exportar mis datos" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900">Exportar mis datos</h1></template>
        <div class="max-w-3xl space-y-6">
            <section class="card space-y-4 p-4 sm:p-6" aria-labelledby="export-intro-title">
                <h2 id="export-intro-title" class="text-lg font-semibold text-slate-900"
                    >Copia de seguridad completa</h2
                >
                <p class="text-sm text-slate-600"
                    >Descarga un ZIP con todos tus datos: marcas, publicaciones con sus destinos y textos, campañas,
                    informes y sus PDF, la biblioteca de media (los ficheros originales), los diseños del Estudio y sus
                    exportaciones, sitios, smart links, plantillas, objetivos y textos guardados. Los datos van en JSON
                    legible, un fichero por tipo, y un manifiesto con el SHA-256 de cada fichero.</p
                >
                <InfoNote title="Qué no incluye">
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="line in excluded" :key="line">{{ line }}</li>
                    </ul>
                </InfoNote>
                <p class="text-sm text-slate-600"
                    >El ZIP se prepara en segundo plano (máximo {{ limits.max_gb }} GB) y estará disponible
                    {{ limits.retention_hours }} horas; después se borra. Te avisaremos cuando esté listo.</p
                >
            </section>

            <section class="card space-y-3 p-4 sm:p-6" aria-labelledby="export-state-title">
                <h2 id="export-state-title" class="text-lg font-semibold text-slate-900">Tu exportación</h2>
                <p v-if="!latest && !download" class="text-sm text-slate-600">Aún no has pedido ninguna exportación.</p>
                <div v-if="latest" class="space-y-1 text-sm" data-testid="export-state">
                    <p class="font-medium text-slate-900"
                        >Estado: <span data-testid="export-status">{{ STATUS[latest.status] }}</span></p
                    >
                    <p class="text-slate-600"
                        >Pedida el
                        {{ formatDate(latest.created_at)
                        }}{{ latest.include_media ? '' : ' · sin media' }}</p
                    >
                    <p v-if="latest.status === 'failed' && latest.error" role="alert" class="text-red-700">{{
                        latest.error
                    }}</p>
                </div>
                <div v-if="download" class="space-y-1 text-sm" data-testid="export-download-info">
                    <p class="text-slate-600"
                        >{{ size(download.size) }} · {{ download.file_count }} ficheros{{
                            download.include_media ? '' : ' (sin media)'
                        }}
                        · disponible hasta el {{ formatDate(download.expires_at) }}</p
                    >
                    <a :href="download.url" class="btn-primary" data-testid="export-download">Descargar ZIP</a>
                    <p class="text-xs text-slate-500"
                        >El enlace de descarga caduca a los {{ limits.link_minutes }} minutos y solo funciona con tu
                        sesión; pulsa Descargar para obtener uno nuevo.</p
                    >
                </div>
                <div v-if="pollingExhausted" class="flex flex-wrap items-center gap-2">
                    <p role="status" class="text-sm text-slate-600">Sigue en preparación.</p>
                    <SecondaryButton type="button" @click="restartPolling">Volver a consultar</SecondaryButton>
                </div>
                <label class="flex items-start gap-2 pt-1 text-sm text-slate-800" for="export-include-media">
                    <Checkbox id="export-include-media" v-model:checked="includeMedia" :disabled="active" />
                    <span
                        >Incluir archivos de media (imágenes, vídeos, informes generados y exportaciones). Sin ellos
                        solo se exportan los datos en JSON y el ZIP es mucho más pequeño.</span
                    >
                </label>
                <SecondaryButton type="button" :disabled="active" @click="request">{{
                    active ? 'Exportación en curso…' : download || latest ? 'Pedir una exportación nueva' : 'Pedir exportación'
                }}</SecondaryButton>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
