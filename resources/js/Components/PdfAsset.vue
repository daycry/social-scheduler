<script setup>
import Icon from '@/Components/Icon.vue';

/**
 * Un PDF de la biblioteca (T-27): icono de documento (no se genera miniatura), nombre, tamaño, origen y las acciones de ver y descargar.
 * Un PDF no es media publicable en redes: por eso no lleva ninguna acción de «usar en una publicación».
 */
defineProps({
    asset: { type: Object, required: true },
    removable: { type: Boolean, default: false },
});
defineEmits(['remove']);

const size = bytes => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round((bytes ?? 0) / 1024))} KB`);
</script>

<template>
    <div class="flex flex-wrap items-center gap-3" data-testid="pdf-asset">
        <Icon name="doc" class="h-9 w-9 shrink-0 text-red-600" />
        <div class="min-w-0 flex-1">
            <p class="break-words font-medium text-slate-900">{{ asset.name }}</p>
            <p class="text-xs text-slate-600">
                PDF · {{ size(asset.size) }}
                <a v-if="asset.origin?.url" :href="asset.origin.url" target="_blank" rel="noopener noreferrer" class="link ml-2" data-testid="canva-origin">Origen: Canva</a>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-xs">
            <a v-if="asset.preview_url" :href="asset.preview_url" target="_blank" rel="noopener noreferrer" class="link" :aria-label="`Ver ${asset.name}`">Ver</a>
            <a v-if="asset.download_url" :href="asset.download_url" class="link" :aria-label="`Descargar ${asset.name}`" data-testid="pdf-download">Descargar</a>
            <button v-if="removable" type="button" class="link text-red-700" :aria-label="`Eliminar ${asset.name}`" @click="$emit('remove', asset)">Eliminar</button>
        </div>
    </div>
</template>
