<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BaseDialog from '@/Components/BaseDialog.vue';
import { studioRequest } from '@/Studio/api';

const props = defineProps({ brandId: Number, selectedIds: Array, returnFocusTo: Object });
const emit = defineEmits(['select', 'close']);
const assets = ref([]);
const query = ref('');
const searchInput = ref(null);
const loading = ref(false);
const error = ref('');
let request = null;

const visibleAssets = computed(() => {
    const search = query.value.trim().toLocaleLowerCase();
    return assets.value.filter(asset => ['image', 'video'].includes(asset.kind) && asset.name.toLocaleLowerCase().includes(search));
});

async function load() {
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true;
    error.value = '';
    try {
        const result = await studioRequest('GET', route('studio.assets.index', props.brandId), undefined, { signal: current.signal });
        if (!current.signal.aborted) assets.value = result.assets;
    } catch (failure) {
        if (!current.signal.aborted) error.value = failure.message;
    } finally {
        if (!current.signal.aborted) loading.value = false;
    }
}

function retry() {
    searchInput.value?.focus();
    return load();
}

onMounted(load);
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <BaseDialog
        open
        :return-focus-to="returnFocusTo"
        aria-labelledby="media-library-title"
        class="m-auto w-[calc(100vw_-_2rem)] max-w-2xl rounded-lg border-0 bg-surface p-5 shadow-xl backdrop:bg-overlay-strong/50"
        @close="emit('close')"
    >
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 id="media-library-title" class="text-lg font-semibold text-foreground-strong">Desde la biblioteca</h2>
            <button type="button" class="btn-secondary" @click="emit('close')">Cerrar biblioteca</button>
        </header>
        <label for="media-library-search" class="mb-1 block text-sm font-medium">Buscar archivos</label>
        <input
            id="media-library-search"
            ref="searchInput"
            v-model="query"
            data-dialog-initial-focus
            type="search"
            class="field w-full"
        />
        <p v-if="loading" role="status" class="mt-3 text-sm text-foreground-muted">Cargando archivos…</p>
        <div v-else-if="error" class="mt-3">
            <p role="alert" class="text-sm text-danger">{{ error }}</p>
            <button type="button" class="btn-secondary mt-2" @click="retry">Reintentar</button>
        </div>
        <p v-else-if="!visibleAssets.length" class="mt-3 text-sm text-foreground-muted">
            {{ query ? 'No hay archivos con ese nombre.' : 'La marca todavía no tiene imágenes ni vídeos en Recursos.' }}
        </p>
        <ul v-else class="mt-3 grid max-h-72 grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3">
            <li v-for="asset in visibleAssets" :key="asset.id" class="min-w-0">
                <button
                    type="button"
                    :aria-label="selectedIds.includes(asset.id) ? `${asset.name}, ya añadido` : `Añadir ${asset.name}`"
                    :disabled="selectedIds.includes(asset.id)"
                    class="w-full overflow-hidden rounded-lg border border-control-border text-left disabled:opacity-60"
                    @click="emit('select', asset)"
                >
                    <img
                        v-if="asset.kind === 'image'"
                        :src="asset.preview_url"
                        alt=""
                        loading="lazy"
                        class="aspect-video w-full object-cover"
                    />
                    <span v-else class="flex aspect-video items-center justify-center bg-canvas text-sm">Vídeo</span>
                    <span class="block break-words p-2 text-sm">{{ asset.name }}</span>
                </button>
            </li>
        </ul>
    </BaseDialog>
</template>
