<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';
import { padForInsert } from '@/Composables/textCount';

/**
 * Área de texto grande del compositor. Expone `insertAtCursor(texto)`: sustituye la selección actual (o inserta en el
 * cursor, que el navegador conserva aunque el foco esté en otro control) y deja el cursor tras lo insertado.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    // `id` del textarea (el texto común es `text`; el de una red, `text-target-<cuenta>`) y etiqueta accesible cuando no hay un <label>.
    id: { type: String, default: 'text' },
    label: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const area = ref(null);

const insertAtCursor = async (raw, { pad = false } = {}) => {
    const el = area.value;
    if (!el) return;
    // Los offsets de la selección son sobre `el.value` (siempre LF), así que se calcula sobre ese mismo valor.
    const value = el.value;
    const start = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? start;
    const text = pad ? padForInsert(value, start, end, raw) : raw;
    emit('update:modelValue', value.slice(0, start) + text + value.slice(end));
    await nextTick();
    // No se roba el foco: tras elegir un emoji el foco vuelve al botón de la barra; el cursor queda tras lo insertado.
    el.setSelectionRange(start + text.length, start + text.length);
};

// «Campo activo» del compositor: al recibir el foco, este editor pasa a ser el destino de los textos guardados.
const composer = useComposerContext();
const self = { insertAtCursor };
const onFocus = () => composer?.registerField(self);
onBeforeUnmount(() => composer?.unregisterField(self));

defineExpose({ insertAtCursor, focus: () => area.value?.focus() });
</script>

<template>
    <textarea
        :id="id"
        :aria-label="label"
        ref="area"
        :value="modelValue"
        :disabled="disabled"
        rows="9"
        class="field mt-1 min-h-[12rem] resize-y text-base"
        @focus="onFocus"
        @input="emit('update:modelValue', $event.target.value)"
    ></textarea>
</template>
