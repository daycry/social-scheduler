<script setup>
import { computed } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Publicación manual (T-19): el destino no se envía a la red. A la hora programada llega un aviso con el texto para copiar y la media
 * para descargar; tú publicas y lo marcas como publicado.
 */
const props = defineProps({ account: { type: Object, required: true } });
const { targetFor, editable } = useComposerContext();

const target = computed(() => targetFor(props.account.id));
const id = computed(() => `manual-${props.account.id}`);
</script>

<template>
    <div v-if="target" class="flex items-start gap-2 rounded-xl bg-canvas px-3 py-3">
        <input
            :id="id"
            v-model="target.manual"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-control-border text-accent"
            :disabled="!editable"
            :data-testid="`manual-${account.id}`"
        />
        <label :for="id" class="text-sm text-foreground">
            Publicación manual
            <span class="block text-xs text-foreground-muted"
                >No se envía a {{ account.network_label ?? 'la red' }}: a la hora programada te avisamos con el
                contenido listo para copiar.</span
            >
        </label>
    </div>
</template>
