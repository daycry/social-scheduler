<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useComposerContext } from '@/Composables/useComposer';

const props = defineProps({ account: { type: Object, required: true } });
const { brand, editable, targetFor } = useComposerContext();
const selection = computed(() => targetFor(props.account.id)?.options.instagram_audio ?? null);
const available = computed(() => props.account.instagram_audio?.available === true);
const open = ref(false);
const trigger = ref(null);
const input = ref(null);
const query = ref('');
const type = ref('all');
const results = ref([]);
const loading = ref(false);
const error = ref('');
const nextCursor = ref(null);
const track = ref(null);
const trackError = ref('');
const trackLoading = ref(false);
let searchSequence = 0;
let trackSequence = 0;
let focusTimer;
let searchTimer;
let searchAbort;

const endpoint = (audio) => route('brands.instagram-audio', { brand: brand.value.id, account: props.account.id, ...(audio ? { audio } : {}) });
async function read(url, signal) {
    const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.message || 'No se pudo consultar el catálogo de audio. Inténtalo de nuevo.');
    return json;
}
async function refreshTrack() {
    const mine = ++trackSequence;
    track.value = null;
    trackError.value = '';
    if (!selection.value?.audio_id) { trackLoading.value = false; return; }
    trackLoading.value = true;
    try {
        const json = await read(endpoint(selection.value.audio_id));
        if (mine === trackSequence) track.value = json.track;
    } catch (e) {
        if (mine === trackSequence) trackError.value = e.message || 'No se pudo recuperar la preescucha.';
    } finally {
        if (mine === trackSequence) trackLoading.value = false;
    }
}
watch(() => selection.value?.audio_id, refreshTrack, { immediate: true });

async function search(append = false) {
    if (!open.value || (append && (!nextCursor.value || loading.value))) return;
    clearTimeout(searchTimer);
    searchAbort?.abort();
    searchAbort = new AbortController();
    const mine = ++searchSequence;
    loading.value = true;
    error.value = '';
    const cursor = append ? nextCursor.value : null;
    if (!append) { results.value = []; nextCursor.value = null; }
    const params = new URLSearchParams({ type: type.value });
    if (query.value.trim()) params.set('q', query.value.trim());
    if (cursor) params.set('cursor', cursor);
    try {
        const json = await read(`${endpoint()}?${params}`, searchAbort.signal);
        if (mine === searchSequence) {
            if (!Array.isArray(json.results)) throw new Error('El catálogo de audio no devolvió resultados válidos.');
            results.value = [...new Map([...(append ? results.value : []), ...json.results].map(track => [track.audio_id, track])).values()];
            nextCursor.value = typeof json.next_cursor === 'string' && json.next_cursor ? json.next_cursor : null;
        }
    } catch (e) {
        if (mine === searchSequence) error.value = e.message || 'No se pudo conectar con el catálogo de audio.';
    } finally {
        if (mine === searchSequence) loading.value = false;
    }
}
watch([query, type], () => {
    if (!open.value) return;
    // Invalidate immediately: an old response must not appear under newly typed text.
    clearTimeout(searchTimer);
    searchAbort?.abort();
    ++searchSequence;
    results.value = [];
    nextCursor.value = null;
    error.value = '';
    loading.value = true;
    searchTimer = setTimeout(() => search(), 350);
});
function close() {
    open.value = false;
    ++searchSequence;
    loading.value = false;
    clearTimeout(searchTimer);
    searchAbort?.abort();
    clearTimeout(focusTimer);
    // The shared native dialog closes after its 200 ms transition.
    focusTimer = setTimeout(() => trigger.value?.focus(), 230);
}
async function show() {
    if (!editable.value || !available.value) return;
    open.value = true;
    await nextTick();
    await nextTick();
    input.value?.focus();
    search();
}
function choose(result) {
    if (!editable.value) return;
    targetFor(props.account.id).options.instagram_audio = {
        audio_id: result.audio_id,
        audio_volume: selection.value?.audio_volume ?? 100,
        video_volume: selection.value?.video_volume ?? 100,
    };
    close();
}
function remove() {
    targetFor(props.account.id).options.instagram_audio = null;
}
const duration = (ms) => ms == null ? '' : `${Math.floor(ms / 60000)}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}`;
onBeforeUnmount(() => { ++searchSequence; ++trackSequence; clearTimeout(focusTimer); clearTimeout(searchTimer); searchAbort?.abort(); });
</script>

<template>
    <div class="space-y-2 border-t border-slate-200 pt-3" data-testid="instagram-audio-field">
        <h3 class="text-sm font-medium text-slate-900">Audio del Reel</h3>
        <p class="text-xs text-slate-600">Puedes conservar el sonido de tu vídeo o añadir una pista del catálogo autorizado de Meta.</p>
        <p v-if="!available" class="text-sm text-amber-800">{{ account.instagram_audio?.reason || 'Reconecta Instagram mediante Facebook para usar el catálogo de audio.' }}</p>
        <div v-if="selection" class="space-y-2 rounded border border-slate-200 p-3" data-testid="selected-instagram-audio">
            <p class="break-words text-sm font-medium">{{ track?.title || `Audio ${selection.audio_id}` }}<span v-if="track?.artist" class="font-normal"> · {{ track.artist }}</span></p>
            <p v-if="trackLoading" role="status" class="text-xs">Consultando la pista…</p>
            <p v-if="trackError" role="alert" class="text-sm text-red-700">{{ trackError }}</p>
            <audio v-if="track?.preview_url" :key="track.preview_url" :src="track.preview_url" controls preload="none" aria-label="Preescucha de la pista seleccionada" class="w-full" @error="trackError = 'La preescucha ha caducado o no está disponible. Pulsa Actualizar preescucha.'" />
            <a v-else-if="track?.platform_url" :href="track.platform_url" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-700 underline">Escuchar en Instagram</a>
            <p v-else-if="track && !trackLoading" class="text-xs text-slate-600">Meta no ofrece preescucha para esta pista.</p>
            <button type="button" class="text-sm text-indigo-700 underline" :disabled="trackLoading || !available" @click="refreshTrack">Actualizar preescucha</button>
            <div class="flex flex-wrap gap-4">
                <label class="flex flex-col gap-1 text-sm">Volumen de la canción
                    <input :value="selection.audio_volume ?? 100" :disabled="!editable" type="number" min="1" max="100" step="1" class="field w-24" @input="selection.audio_volume = $event.target.value === '' ? '' : Number($event.target.value)" @keydown.enter.prevent />
                </label>
                <label class="flex flex-col gap-1 text-sm">Volumen del vídeo original
                    <input :value="selection.video_volume ?? 100" :disabled="!editable" type="number" min="1" max="100" step="1" class="field w-24" @input="selection.video_volume = $event.target.value === '' ? '' : Number($event.target.value)" @keydown.enter.prevent />
                </label>
            </div>
            <p class="text-xs text-slate-600">De 1 a 100. La preescucha reproduce solo la pista; la mezcla final se aplica al publicar.</p>
            <button type="button" :disabled="!editable" class="btn btn-secondary" @click="remove">Quitar audio</button>
        </div>
        <button ref="trigger" type="button" :disabled="!editable || !available" class="inline-flex items-center gap-2 rounded-lg border border-dashed border-violet-300 bg-violet-50/50 px-3 py-2 text-sm font-medium text-violet-800 transition hover:border-violet-500 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50" @click="show">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13M9 9l12-2" /><ellipse cx="6" cy="18" rx="3" ry="2" /><ellipse cx="18" cy="16" rx="3" ry="2" /></svg>
            {{ selection ? 'Cambiar audio' : 'Añadir audio' }}
        </button>

        <Modal :show="open" max-width="2xl" @close="close">
            <div class="space-y-3 p-4 sm:p-6" data-testid="instagram-audio-picker" role="group" :aria-labelledby="`audio-title-${account.id}`">
                <div class="flex items-center justify-between gap-2">
                    <h2 :id="`audio-title-${account.id}`" class="text-lg font-medium text-slate-900">Añadir audio al Reel</h2>
                    <button type="button" class="btn btn-secondary" @click="close">Cerrar</button>
                </div>
                <label class="block text-sm font-medium">Buscar canción o audio
                    <input ref="input" v-model="query" type="search" maxlength="200" class="field mt-1 w-full" placeholder="Título o artista" @keydown.enter.prevent.stop="search()" />
                </label>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-sm">Tipo de audio
                        <select v-model="type" class="field ml-2">
                            <option value="all">Todos</option><option value="music">Música</option><option value="original_sound">Audio original</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-secondary" :disabled="loading" @click="search()">Buscar audio</button>
                </div>
                <p class="text-xs text-slate-600">Sin texto se muestran tendencias. El catálogo puede diferir del de Instagram. El audio empieza desde el principio; no hay previsualización de la mezcla final.</p>
                <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <p v-else-if="loading" role="status" class="text-sm">Buscando audio…</p>
                <p v-else-if="!results.length" role="status" class="text-sm">No hay audios disponibles para esta búsqueda.</p>
                <ul class="max-h-[45vh] space-y-3 overflow-y-auto" aria-label="Resultados de audio">
                    <li v-for="result in results" :key="result.audio_id" class="space-y-2 rounded border border-slate-200 p-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <img v-if="result.artwork_url" :src="result.artwork_url" alt="" referrerpolicy="no-referrer" class="h-10 w-10 shrink-0 rounded" loading="lazy" />
                            <div class="min-w-0 flex-1 break-words text-sm"><p class="font-medium">{{ result.title || result.audio_id }}</p><p>{{ result.artist }} <span v-if="result.duration_ms != null">· {{ duration(result.duration_ms) }}</span></p></div>
                        </div>
                        <audio v-if="result.preview_url" :src="result.preview_url" controls preload="none" :aria-label="`Preescuchar ${result.title || result.audio_id}`" class="w-full" />
                        <a v-else-if="result.platform_url" :href="result.platform_url" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-700 underline">Escuchar en Instagram</a>
                        <p v-else class="text-xs text-slate-600">Sin preescucha disponible.</p>
                        <button type="button" class="btn btn-secondary" :aria-label="`Elegir ${result.title || result.audio_id}`" @click="choose(result)">Elegir audio</button>
                    </li>
                </ul>
                <p v-if="results.length" role="status" class="text-xs text-slate-600">{{ results.length }} audios cargados<span v-if="nextCursor"> · Hay más resultados disponibles</span></p>
                <button v-if="nextCursor" type="button" class="btn btn-secondary" :disabled="loading" @click="search(true)">Cargar más audios</button>
            </div>
        </Modal>
    </div>
</template>
