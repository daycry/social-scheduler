<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { newIdempotencyKey } from '@/Composables/useWorkflow';

defineProps({ brandId: Number, media: Object, csrfToken: String });

const opening = ref(false);
const requestKey = newIdempotencyKey();
let reopen;
function submit(event) {
    if (opening.value) {
        event.preventDefault();
        return;
    }
    opening.value = true;
    // La navegación nativa sucede en otra pestaña y no emite un fin de visita en esta.
    reopen = setTimeout(() => { opening.value = false; }, 800);
}
onBeforeUnmount(() => clearTimeout(reopen));
</script>

<template>
    <form
        v-if="brandId && media?.kind === 'image' && media.status === 'listo'"
        :action="route('studio.designs.from-image', brandId)"
        method="post"
        target="_blank"
        rel="noopener"
        class="inline"
        @submit="submit"
    >
        <input type="hidden" name="_token" :value="csrfToken" />
        <input type="hidden" name="media_id" :value="media.id" />
        <input type="hidden" name="request_key" :value="requestKey" />
        <button
            type="submit"
            :aria-label="`Abrir en el Estudio, ${media.name}, abre en una pestaña nueva`"
            :aria-disabled="opening"
            class="text-sm text-accent-secondary-ink underline"
        >
            Abrir en el Estudio
        </button>
        <span class="sr-only" role="status">{{ opening ? 'Abriendo el Estudio en otra pestaña…' : '' }}</span>
    </form>
</template>
