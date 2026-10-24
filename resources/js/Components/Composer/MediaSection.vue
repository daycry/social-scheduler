<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

// La subida vive ahora en la barra del editor (`EditorToolbar`); aquí queda la lista ordenable de lo elegido
// y la importación «Desde URL» (C-04a): el servidor descarga en segundo plano y aquí se sondea hasta `listo` o `invalido`.
const { form, editable, mediaById, move, removeMedia, onUploaded } = useComposerContext();

const url = ref('');
const sending = ref(false);
const error = ref('');
const imports = ref([]); // { id, name, bytes, total, status, error }
const POLL_MS = 2000;
let timer = null;

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
        throw new Error(json.message || json.errors?.url?.[0] || 'No se pudo importar la URL.');
    }
    return json;
};

const percent = (item) => (item.total ? Math.min(100, Math.round((item.bytes / item.total) * 100)) : null);
const megabytes = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;

const stopPolling = () => {
    if (timer) clearInterval(timer);
    timer = null;
};

const poll = async () => {
    for (const item of imports.value.filter((i) => i.status === 'pendiente')) {
        try {
            const { asset } = await call(route('media.show', item.id), 'GET');
            Object.assign(item, { name: asset.name, bytes: asset.bytes ?? 0, total: asset.total, status: asset.status, error: asset.error });
            if (asset.status === 'listo') {
                onUploaded(asset, null);
                imports.value = imports.value.filter((i) => i.id !== item.id);
            }
        } catch {
            Object.assign(item, { status: 'invalido', error: 'No se pudo consultar el estado de la importación.' });
        }
    }
    if (!imports.value.some((i) => i.status === 'pendiente')) stopPolling();
};

const startImport = async () => {
    const value = url.value.trim();
    if (!value || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const { asset } = await call(route('media.import-url'), 'POST', { url: value });
        imports.value.push({ id: asset.id, name: asset.name, bytes: 0, total: null, status: 'pendiente', error: null });
        url.value = '';
        timer ??= setInterval(poll, POLL_MS);
    } catch (e) {
        error.value = e.message;
    } finally {
        sending.value = false;
    }
};

const dismiss = (id) => {
    imports.value = imports.value.filter((i) => i.id !== id);
};

onBeforeUnmount(stopPolling);
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
                    <template v-if="item.status === 'pendiente'">
                        <span class="break-all">Importando {{ item.name }}…
                            <span class="text-slate-600">{{ percent(item) !== null ? `${percent(item)} %` : megabytes(item.bytes) }}</span>
                        </span>
                        <progress class="mt-1 block h-2 w-full" :aria-label="`Progreso de ${item.name}`" :max="item.total || 100" :value="item.total ? item.bytes : undefined" />
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
