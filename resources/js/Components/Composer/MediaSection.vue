<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

// La subida vive ahora en la barra del editor (`EditorToolbar`); aquí queda la lista ordenable de lo elegido
// y la importación «Desde URL» (C-04a): el servidor descarga en segundo plano y aquí se sondea hasta `listo` o `invalido`.
const { form, editable, mediaById, move, removeMedia, onUploaded } = useComposerContext();

const url = ref('');
const sending = ref(false);
const error = ref('');
const imports = ref([]); // { id, name, bytes, total, status, error, stalled, cancelling, startedAt, failures }

// Sondeo con backoff: empieza a 2 s y, mientras no haya bytes nuevos, se alarga hasta 15 s; con progreso vuelve a 2 s.
const POLL_MIN_MS = 2000;
const POLL_MAX_MS = 15000;
// Sin un solo byte pasados estos minutos, lo más probable es que ningún worker esté atendiendo la cola `media`.
const STALL_MS = 3 * 60 * 1000;
// Errores de consulta seguidos que se toleran antes de dar la importación por perdida (un fallo suelto de red no la mata).
const MAX_POLL_FAILURES = 3;
let timer = null;
let polling = false;
let alive = true;
let delay = POLL_MIN_MS;

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) || [])[1] || '');

const call = async (target, method, body) => {
    const response = await fetch(target, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const failure = new Error(json.message || json.errors?.url?.[0] || 'No se pudo importar la URL.');
        failure.status = response.status;
        throw failure;
    }
    return json;
};

const percent = (item) => (item.total ? Math.min(100, Math.round((item.bytes / item.total) * 100)) : null);
const megabytes = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;

const active = (item) => item.status === 'pendiente' && !item.stalled;
const tracked = (id) => imports.value.some((i) => i.id === id);

const stopPolling = () => {
    if (timer) clearTimeout(timer);
    timer = null;
};

// Un candado impide que dos sondeos se solapen (un `onUploaded` doble duplicaría la media en el post).
const poll = async () => {
    if (polling) return;
    polling = true;
    let progressed = false;

    try {
        for (const item of imports.value.filter(active)) {
            try {
                const { asset } = await call(route('media.show', item.id), 'GET');
                // Cancelada, descartada o desmontado el compositor mientras se consultaba.
                if (!alive || !tracked(item.id)) continue;

                const bytes = asset.bytes ?? 0;
                progressed ||= bytes !== item.bytes;
                Object.assign(item, { name: asset.name, bytes, total: asset.total, status: asset.status, error: asset.error, failures: 0 });

                if (asset.status === 'listo') {
                    onUploaded(asset, null);
                    imports.value = imports.value.filter((i) => i.id !== item.id);
                } else if (asset.status === 'pendiente' && bytes === 0 && Date.now() - item.startedAt >= STALL_MS) {
                    item.stalled = true;
                }
            } catch (e) {
                if (!alive || !tracked(item.id)) continue;
                item.failures = (item.failures ?? 0) + 1;
                // Un 403/404 es definitivo (la poda la borró); un fallo suelto de red o un 5xx se reintenta en el siguiente sondeo.
                if (e.status === 403 || e.status === 404 || item.failures >= MAX_POLL_FAILURES) {
                    Object.assign(item, { status: 'invalido', error: 'No se pudo consultar el estado de la importación.' });
                }
            }
        }
    } finally {
        polling = false;
        delay = progressed ? POLL_MIN_MS : Math.min(POLL_MAX_MS, Math.round(delay * 1.5));
    }
};

const schedule = () => {
    if (timer || !alive) return;
    timer = setTimeout(async () => {
        timer = null;
        await poll();
        if (imports.value.some(active)) schedule();
    }, delay);
};

const startImport = async () => {
    const value = url.value.trim();
    if (!value || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const { asset } = await call(route('media.import-url'), 'POST', { url: value });
        imports.value.push({ id: asset.id, name: asset.name, bytes: 0, total: null, status: 'pendiente', error: null, stalled: false, cancelling: false, startedAt: Date.now(), failures: 0 });
        url.value = '';
        delay = POLL_MIN_MS;
        schedule();
    } catch (e) {
        error.value = e.message;
    } finally {
        sending.value = false;
    }
};

// Cancela una importación en curso (o atascada): borra el asset, así el worker la abandona y se libera su cupo de las 3 vivas.
const cancel = async (item) => {
    if (item.cancelling) return;
    item.cancelling = true;
    error.value = '';
    try {
        await call(route('media.destroy', item.id), 'DELETE');
        imports.value = imports.value.filter((i) => i.id !== item.id);
    } catch (e) {
        if (e.status === 404) {
            imports.value = imports.value.filter((i) => i.id !== item.id);
        } else {
            item.cancelling = false;
            error.value = e.message || 'No se pudo cancelar la importación.';
        }
    }
};

const dismiss = (id) => {
    imports.value = imports.value.filter((i) => i.id !== id);
};

onBeforeUnmount(() => {
    alive = false;
    stopPolling();
});
</script>

<template>
    <!-- Media -->
    <section class="card p-4">
        <h2 id="media-title" tabindex="-1" class="mb-2 font-medium text-slate-900">Imágenes y vídeos</h2>
        <p v-if="!form.media.length" class="text-sm text-slate-600">Todavía no has añadido archivos. Usa «Añadir imagen o vídeo» en la barra del editor.</p>
        <ul v-if="form.media.length" class="mb-3 space-y-1">
            <li v-for="(id, i) in form.media" :key="id" class="flex items-center justify-between rounded border border-slate-200 px-2 py-1 text-sm">
                <span>{{ i + 1 }}. {{ mediaById(id)?.name ?? `Archivo ${id}` }}
                    <span class="text-slate-500">({{ mediaById(id)?.kind === 'video' ? `vídeo ${mediaById(id)?.duration ?? '?'} s` : 'imagen' }}<template v-if="mediaById(id)?.width"> · {{ mediaById(id).width }}×{{ mediaById(id).height }}</template>)</span>
                </span>
                <span v-if="editable" class="space-x-2">
                    <button type="button" class="text-slate-600" :aria-label="`Subir el archivo ${i + 1}`" @click="move(i, -1)">↑</button>
                    <button type="button" class="text-slate-600" :aria-label="`Bajar el archivo ${i + 1}`" @click="move(i, 1)">↓</button>
                    <button type="button" class="text-red-600" @click="removeMedia(id)">Quitar</button>
                </span>
            </li>
        </ul>

        <!-- Importar desde una URL pública (C-04a) -->
        <div v-if="editable" class="mt-3 border-t border-slate-200 pt-3" data-testid="media-import">
            <form class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="startImport">
                <div class="min-w-0 flex-1 basis-56">
                    <label for="media-import-url" class="mb-1 block text-sm font-medium text-slate-900">Desde URL</label>
                    <input id="media-import-url" v-model="url" type="url" inputmode="url" autocomplete="off" maxlength="2048"
                        placeholder="https://…/imagen.jpg" class="field w-full" :aria-invalid="error ? 'true' : 'false'"
                        aria-describedby="media-import-help media-import-error">
                </div>
                <button type="submit" class="btn-secondary" :disabled="sending || !url.trim()">{{ sending ? 'Importando…' : 'Importar' }}</button>
            </form>
            <p id="media-import-help" class="mt-1 text-xs text-slate-600">Enlace público a una imagen JPG, PNG o WebP, o a un vídeo MP4 o MOV.</p>
            <p id="media-import-error" role="alert" class="mt-1 text-sm text-red-700" data-testid="media-import-error">{{ error }}</p>

            <ul v-if="imports.length" class="mt-2 space-y-2" aria-live="polite">
                <li v-for="item in imports" :key="item.id" class="rounded border border-slate-200 px-2 py-1 text-sm" data-testid="media-import-item">
                    <template v-if="item.status === 'pendiente' && !item.stalled">
                        <span class="break-all">Importando {{ item.name }}…
                            <span class="text-slate-600">{{ percent(item) !== null ? `${percent(item)} %` : megabytes(item.bytes) }}</span>
                        </span>
                        <button type="button" class="ml-2 text-slate-700 underline" :disabled="item.cancelling" :aria-label="`Cancelar la importación de ${item.name}`" @click="cancel(item)">Cancelar</button>
                        <progress class="mt-1 block h-2 w-full" :aria-label="`Progreso de ${item.name}`" :max="item.total || 100" :value="item.total ? item.bytes : undefined" />
                    </template>
                    <template v-else-if="item.stalled">
                        <span class="break-all text-red-700" role="alert">La cola de importación no está procesando la descarga de {{ item.name }}. Inténtalo de nuevo más tarde o descártala.</span>
                        <button type="button" class="ml-2 text-slate-700 underline" :disabled="item.cancelling" @click="cancel(item)">Descartar</button>
                    </template>
                    <template v-else>
                        <span class="break-all text-red-700" role="alert">No se pudo importar {{ item.name }}: {{ item.error }}</span>
                        <button type="button" class="ml-2 text-slate-700 underline" @click="dismiss(item.id)">Descartar</button>
                    </template>
                </li>
            </ul>
        </div>
    </section>
</template>
