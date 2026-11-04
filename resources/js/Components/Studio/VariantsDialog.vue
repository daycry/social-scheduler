<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import StudioDialog from './StudioDialog.vue';
import { STUDIO_FORMAT_PRESETS, STUDIO_RESIZE_MODES } from '@/Studio/format-presets';

const props = defineProps({ modelValue: Boolean, designName: String, templateFields: Array, busy: Boolean, error: String, results: Array });
const emit = defineEmits(['update:modelValue', 'create']);
const selected = ref([]);
const mode = ref('contain');
const custom = ref(false);
const customName = ref('');
const customWidth = ref(1080);
const customHeight = ref(1080);
const fields = ref({});
const applyKit = ref(false);
const count = computed(() => selected.value.length + (custom.value ? 1 : 0));
watch(() => props.modelValue, value => {
    if (!value) return;
    selected.value = []; mode.value = 'contain'; custom.value = false;
    customName.value = `${(props.designName ?? '').slice(0, 220)} — personalizado`;
    fields.value = Object.fromEntries((props.templateFields ?? []).map(key => [key, '']));
    applyKit.value = false;
});
function create() {
    if (props.busy || !count.value) return;
    const variants = STUDIO_FORMAT_PRESETS.filter(preset => selected.value.includes(preset.key)).map(preset => ({
        name: `${(props.designName ?? '').slice(0, 200)} — ${preset.label}`, width: preset.w, height: preset.h, mode: mode.value,
    }));
    if (custom.value) variants.push({ name: customName.value.trim(), width: Number(customWidth.value), height: Number(customHeight.value), mode: mode.value });
    emit('create', { variants, fields: { ...fields.value }, apply_brand_kit: applyKit.value });
}
</script>

<template>
    <StudioDialog :model-value="modelValue" title="Crear variantes" wide @update:model-value="value => { if (!busy) emit('update:modelValue', value); }">
        <div v-if="results?.length" class="space-y-5">
            <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ results.length }} {{ results.length === 1 ? 'variante creada' : 'variantes creadas' }}. El diseño original se conserva.</p>
            <ul class="space-y-2"><li v-for="design in results" :key="design.id"><Link :href="route('studio.designs.edit', design.id)" :aria-label="design.name" class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium text-violet-800 hover:border-violet-300 hover:bg-violet-50"><span class="min-w-0 break-words">{{ design.name }}</span><span class="shrink-0 text-xs font-normal text-slate-500">Abrir ↗</span></Link></li></ul>
            <div class="flex justify-end"><button type="button" class="btn-secondary" @click="emit('update:modelValue', false)">Cerrar variantes</button></div>
        </div>
        <form v-else class="space-y-5" @submit.prevent="create">
            <p class="text-sm leading-relaxed text-slate-500">Adapta todas las páginas a varios formatos. Cada variante será un diseño independiente dentro de esta marca.</p>
            <fieldset class="min-w-0 space-y-5" :disabled="busy">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label v-for="preset in STUDIO_FORMAT_PRESETS" :key="preset.key" class="flex min-w-0 cursor-pointer items-center gap-3 rounded-xl border p-3 transition" :class="selected.includes(preset.key) ? 'border-violet-300 bg-violet-50' : 'border-slate-200 hover:border-violet-200'"><input v-model="selected" type="checkbox" :value="preset.key" :aria-label="preset.label" /><span class="min-w-0"><span class="block text-sm font-medium">{{ preset.label }}</span><span class="mt-1 block text-xs text-slate-500">{{ preset.w }} × {{ preset.h }} px</span></span></label></div>
                <div class="space-y-4 rounded-xl border border-slate-200 p-4"><label class="flex items-center gap-2 text-sm font-medium"><input v-model="custom" type="checkbox" />Tamaño personalizado</label><div v-if="custom" class="space-y-3"><label class="block text-sm">Nombre de la variante<input v-model="customName" class="field mt-1" maxlength="255" required /></label><div class="grid grid-cols-2 gap-3"><label class="min-w-0 text-sm">Ancho de la variante<input v-model.number="customWidth" class="field mt-1 min-w-0" type="number" min="40" max="8000" step="1" required /></label><label class="min-w-0 text-sm">Alto de la variante<input v-model.number="customHeight" class="field mt-1 min-w-0" type="number" min="40" max="8000" step="1" required /></label></div></div></div>
                <div><label class="block text-sm font-medium">Adaptación del contenido<select v-model="mode" aria-label="Adaptación del contenido" class="field mt-1"><option v-for="option in STUDIO_RESIZE_MODES" :key="option.value" :value="option.value">{{ option.label }}</option></select></label><p class="mt-2 text-xs leading-relaxed text-slate-500">{{ mode === 'contain' ? 'Escala y centra el contenido conservando sus proporciones. Pueden quedar márgenes.' : mode === 'cover' ? 'Escala y centra conservando proporciones. Lo que quede fuera del lienzo no aparecerá en la exportación.' : 'Cambia las dimensiones del lienzo y conserva posiciones y tamaños de las capas.' }}</p></div>
                <div v-if="templateFields?.length" class="space-y-3 rounded-xl bg-violet-50 p-4"><h3 class="text-sm font-semibold text-violet-950">Contenido para todas las variantes</h3><label v-for="key in templateFields" :key="key" class="block break-words text-sm">Campo {{ key }}<textarea v-model="fields[key]" :aria-label="`Campo ${key}`" class="field mt-1" rows="2" maxlength="10000" /></label></div>
                <label class="flex items-start gap-3 text-sm"><input v-model="applyKit" type="checkbox" class="mt-0.5" aria-label="Aplicar identidad de marca" /><span>Aplicar identidad de marca<span class="mt-1 block text-xs leading-relaxed text-slate-500">Usa los estilos y logotipos vinculados al kit actual en las nuevas copias.</span></span></label>
            </fieldset>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4"><span class="text-xs text-slate-500">{{ count }} {{ count === 1 ? 'formato seleccionado' : 'formatos seleccionados' }}</span><button type="submit" class="btn-primary bg-violet-700" :disabled="busy || !count">{{ busy ? 'Creando…' : 'Crear variantes' }}</button></div>
        </form>
    </StudioDialog>
</template>
