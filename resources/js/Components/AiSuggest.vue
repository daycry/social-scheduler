<script setup>
import { onMounted, ref } from 'vue';

/**
 * Botón «Sugerir texto» del asistente IA opcional (T-14). Es autónomo: consulta `ai.status` al montarse y NO renderiza
 * nada si el asistente no está configurado en el servidor. Uso en el compositor:
 *   <AiSuggest :brand-id="brand.id" network="instagram" @use="(t) => (form.text = t)" />
 * Solo se envía la idea escrita aquí (sin emails, teléfonos ni enlaces), el nombre de la marca y la red.
 */
const props = defineProps({
    brandId: { type: Number, default: null },
    network: { type: String, default: null },
});
const emit = defineEmits(['use']);

const enabled = ref(false);
const remaining = ref(0);
const open = ref(false);
const brief = ref('');
const loading = ref(false);
const error = ref('');
const suggestion = ref('');

const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
const call = (method, url, body) =>
    fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

onMounted(async () => {
    try {
        const res = await call('GET', route('ai.status'));
        if (res.ok) {
            const data = await res.json();
            enabled.value = !!data.enabled;
            remaining.value = data.remaining ?? 0;
        }
    } catch {
        enabled.value = false;
    }
});

const generate = async () => {
    error.value = '';
    suggestion.value = '';
    loading.value = true;
    try {
        const res = await call('POST', route('ai.suggest'), { brief: brief.value, brand_id: props.brandId, network: props.network });
        const data = await res.json().catch(() => ({}));
        if (res.ok) suggestion.value = data.text;
        else error.value = data.message ?? 'No se pudo generar el texto.';
        remaining.value = data.remaining ?? remaining.value;
    } catch {
        error.value = 'No se pudo contactar con el asistente.';
    } finally {
        loading.value = false;
    }
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
        <button type="button" class="btn btn-secondary" :aria-expanded="open" @click="open = !open">Sugerir texto</button>
        <div v-if="open" class="card space-y-2 p-3 text-sm">
            <label class="block">
                <span class="block text-xs font-medium text-slate-500">¿De qué trata la publicación? (no incluyas datos personales)</span>
                <textarea v-model="brief" rows="2" maxlength="500" class="field"></textarea>
            </label>
            <div class="flex items-center gap-3">
                <button type="button" class="btn btn-primary" :disabled="loading || !brief.trim()" @click="generate">{{ loading ? 'Generando…' : 'Generar' }}</button>
                <span class="text-xs text-slate-500">Te quedan {{ remaining }} hoy</span>
            </div>
            <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
            <div v-if="suggestion" class="space-y-2 rounded-lg bg-slate-50 p-3">
                <p class="whitespace-pre-line text-slate-800">{{ suggestion }}</p>
                <button type="button" class="btn btn-secondary" @click="use">Usar este texto</button>
            </div>
        </div>
    </div>
</template>
