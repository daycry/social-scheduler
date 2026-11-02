<script setup>
import { nextTick, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

/**
 * Buscador de GIFs de Giphy (C-04b). La búsqueda pasa por el servidor (`gifs.search`): la clave de Giphy nunca llega al navegador. Al
 * elegir un GIF se emite su MP4 (`pick`); quien lo usa lo importa por el camino de importación del servidor. «Powered by GIPHY» es
 * obligatorio y va siempre visible en el pie.
 */
const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'pick']);

const query = ref('');
const results = ref([]);
const nextOffset = ref(null);
const loading = ref(false);
const error = ref('');
const searched = ref(''); // texto de la última búsqueda lanzada (para el mensaje de «sin resultados»)
const input = ref(null);
let seq = 0; // descarta respuestas viejas si el usuario lanza otra búsqueda antes de que lleguen

const load = async (offset = 0) => {
    const mine = ++seq;
    loading.value = true;
    error.value = '';
    const q = query.value.trim();
    searched.value = q;
    try {
        const response = await fetch(route('gifs.search', { q: q || undefined, offset: offset || undefined }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const json = await response.json().catch(() => ({}));
        if (mine !== seq) return;
        if (!response.ok) {
            error.value = json.message || 'No se pudo buscar GIFs. Inténtalo de nuevo.';
            if (!offset) results.value = [];
            nextOffset.value = null;
            return;
        }
        results.value = offset ? [...results.value, ...json.results] : json.results;
        nextOffset.value = json.next_offset ?? null;
    } catch {
        if (mine !== seq) return;
        error.value = 'No se pudo conectar para buscar GIFs. Revisa tu conexión.';
    } finally {
        if (mine === seq) loading.value = false;
    }
};

watch(() => props.show, async (open) => {
    if (!open) return;
    // El modal monta su contenido tras el cambio de `show`: se espera a que exista el campo antes de darle el foco.
    await nextTick();
    await nextTick();
    input.value?.focus();
    if (!results.value.length && !loading.value) load();
});

const pick = (gif) => emit('pick', { id: gif.id, title: gif.title, mp4: gif.mp4 });
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-4 sm:p-6" data-testid="gif-picker" role="group" aria-labelledby="gif-picker-title">
            <div class="flex items-start justify-between gap-2">
                <h2 id="gif-picker-title" class="text-lg font-medium text-slate-900">Buscar un GIF</h2>
                <button type="button" class="btn btn-secondary" data-testid="gif-picker-close" @click="emit('close')">Cerrar</button>
            </div>

            <form class="mt-3 flex flex-wrap items-end gap-2" novalidate @submit.prevent="load()">
                <div class="min-w-0 flex-1 basis-48">
                    <label for="gif-picker-q" class="mb-1 block text-sm font-medium text-slate-900">Buscar GIFs</label>
                    <input id="gif-picker-q" ref="input" v-model="query" type="search" maxlength="100" autocomplete="off" class="field w-full"
                        placeholder="Por ejemplo: gato" aria-describedby="gif-picker-hint">
                </div>
                <button type="submit" class="btn btn-secondary" :disabled="loading">Buscar</button>
            </form>
            <p id="gif-picker-hint" class="mt-1 text-xs text-slate-600">
                Sin texto verás los GIF del momento. Se importa como vídeo MP4. Los GIF suelen durar menos de 3 s: los Reels de Instagram y TikTok los rechazan.
            </p>

            <p v-if="error" role="alert" class="mt-3 text-sm text-red-700" data-testid="gif-picker-error">{{ error }}</p>
            <p v-else-if="loading" role="status" class="mt-3 text-sm text-slate-700">Buscando GIFs…</p>
            <p v-else-if="!results.length" role="status" class="mt-3 text-sm text-slate-700" data-testid="gif-picker-empty">
                {{ searched ? `No hay GIFs para «${searched}». Prueba con otra palabra.` : 'No hay GIFs ahora mismo.' }}
            </p>

            <ul v-if="results.length" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3" aria-label="Resultados de GIFs">
                <li v-for="gif in results" :key="gif.id">
                    <button type="button" class="block w-full overflow-hidden rounded border border-slate-200 bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        :aria-label="`Elegir el GIF ${gif.title || gif.id}`" data-testid="gif-result" @click="pick(gif)">
                        <img :src="gif.preview" alt="" loading="lazy" :width="gif.width" :height="gif.height" class="h-28 w-full object-cover">
                    </button>
                </li>
            </ul>

            <div v-if="nextOffset !== null && !loading" class="mt-3">
                <button type="button" class="btn btn-secondary" data-testid="gif-picker-more" @click="load(nextOffset)">Ver más</button>
            </div>

            <p class="mt-4 border-t border-slate-200 pt-3 text-sm font-semibold text-slate-800" data-testid="giphy-attribution">Powered by GIPHY</p>
        </div>
    </Modal>
</template>
