<script setup>
import { ref, watch } from 'vue';
import StudioDialog from './StudioDialog.vue';

const props = defineProps({ modelValue: Boolean, template: Object, busy: Boolean, error: String });
const emit = defineEmits(['update:modelValue', 'create']);
const name = ref('');
const fields = ref({});
const applyKit = ref(false);
watch(() => [props.modelValue, props.template?.id], () => {
    if (!props.modelValue || !props.template) return;
    name.value = `${props.template.name.slice(0, 240)} — copia`;
    fields.value = Object.fromEntries((props.template.template_fields ?? []).map(key => [key, '']));
    applyKit.value = false;
}, { immediate: true });
function create() {
    if (props.busy) return;
    emit('create', { revision: props.template.revision, name: name.value.trim(), fields: { ...fields.value }, apply_brand_kit: applyKit.value });
}
</script>

<template>
    <StudioDialog :model-value="modelValue" title="Usar plantilla" @update:model-value="value => { if (!busy) emit('update:modelValue', value); }">
        <form class="space-y-5" @submit.prevent="create">
            <p class="text-sm leading-relaxed text-slate-500">Crea un diseño independiente a partir de <strong class="break-words font-medium text-slate-700">{{ template?.name }}</strong>. La plantilla conserva su contenido.</p>
            <fieldset class="min-w-0 space-y-5" :disabled="busy">
                <label class="block text-sm font-medium">Nombre del nuevo diseño<input v-model="name" class="field mt-1 min-w-0" maxlength="255" required autofocus /></label>
                <div v-if="template?.template_fields?.length" class="space-y-4 rounded-xl border border-violet-100 bg-violet-50/60 p-4">
                    <div><h3 class="text-sm font-semibold text-violet-950">Contenido de la plantilla</h3><p class="mt-1 text-xs leading-relaxed text-violet-800">Sustituye los campos por tu contenido. Puedes dejarlos vacíos; se conservan los espacios y saltos de línea.</p></div>
                    <label v-for="key in template.template_fields" :key="key" class="block break-words text-sm font-medium">Campo {{ key }}<textarea v-model="fields[key]" :aria-label="`Campo ${key}`" class="field mt-1 min-w-0 font-normal" rows="2" maxlength="10000" /></label>
                </div>
                <label class="flex items-start gap-3 text-sm"><input v-model="applyKit" type="checkbox" class="mt-0.5" aria-label="Aplicar identidad de marca" /><span>Aplicar identidad de marca<span class="mt-1 block text-xs leading-relaxed text-slate-500">Actualiza los colores, las tipografías y los logotipos vinculados al kit de esta marca.</span></span></label>
            </fieldset>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="btn-secondary" :disabled="busy" @click="emit('update:modelValue', false)">Cancelar</button><button type="submit" class="btn-primary bg-violet-700" :disabled="busy || !name.trim()">{{ busy ? 'Creando…' : 'Crear diseño' }}</button></div>
        </form>
    </StudioDialog>
</template>
