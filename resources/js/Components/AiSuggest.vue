<script setup>
import { useAiStatus } from '@/Composables/useAiStatus';
import { HttpError } from '@/lib/http';
import { ref } from 'vue';

/**
 * Botón «Sugerir texto» del asistente IA opcional (T-14). Comparte el estado y la cuota con el resto del compositor. Uso en el compositor:
 *   <AiSuggest :brand-id="brand.id" network="instagram" @use="(t) => (form.text = t)" />
 * Solo se envía la idea escrita aquí (sin emails, teléfonos ni enlaces), el nombre de la marca y la red.
 */
const props = defineProps({
    brandId: { type: Number, default: null },
    network: { type: String, default: null },
});
const emit = defineEmits(['use']);

const trigger = ref(null);
const { enabled, remaining, call } = useAiStatus();
const open = ref(false);
const brief = ref('');
const loading = ref(false);
const error = ref('');
const suggestion = ref('');

const generate = async () => {
    error.value = '';
    suggestion.value = '';
    loading.value = true;
    try {
        const data = await call('ai.suggest', { brief: brief.value, brand_id: props.brandId, network: props.network }, {
            errorMessage: data => data?.message ?? 'No se pudo generar el texto.',
        });
        suggestion.value = data?.text ?? '';
    } catch (failure) {
        if (failure instanceof HttpError) {
            error.value = failure.message;
        } else {
            error.value = 'No se pudo contactar con el asistente.';
        }
    } finally {
        loading.value = false;
    }
};
// Escape cierra el panel y devuelve el foco al botón que lo abrió.
const closePanel = () => {
    open.value = false;
    trigger.value?.focus();
};
const use = () => {
    emit('use', suggestion.value);
    open.value = false;
    brief.value = '';
    suggestion.value = '';
};
</script>

<template>
    <div v-if="enabled" class="space-y-2" data-testid="ai-suggest">
        <button ref="trigger" type="button" class="btn btn-secondary" :aria-expanded="open" @click="open = !open"
            >Sugerir texto</button
        >
        <div v-if="open" class="card space-y-2 p-3 text-sm" @keydown.esc.stop="closePanel">
            <label class="block">
                <span class="block text-xs font-medium text-foreground-subtle"
                    >¿De qué trata la publicación? (no incluyas datos personales)</span
                >
                <textarea v-model="brief" rows="2" maxlength="500" class="field"></textarea>
            </label>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="loading || !brief.trim()"
                    @click="generate"
                    >{{ loading ? 'Generando…' : 'Generar' }}</button
                >
                <span class="text-xs text-foreground-subtle">Te quedan {{ remaining }} hoy</span>
            </div>
            <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
            <div v-if="suggestion" class="space-y-2 rounded-lg bg-canvas p-3">
                <p class="whitespace-pre-line text-foreground">{{ suggestion }}</p>
                <button type="button" class="btn btn-secondary" @click="use">Usar este texto</button>
            </div>
        </div>
    </div>
</template>
