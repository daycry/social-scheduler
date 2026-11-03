<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
const props = defineProps({ modelValue: Boolean, title: { type: String, required: true }, wide: Boolean });
const emit = defineEmits(['update:modelValue']);
const dialog = ref(null);
watch(() => props.modelValue, async value => {
    await nextTick();
    if (value && !dialog.value?.open) dialog.value?.showModal();
    else if (!value && dialog.value?.open) dialog.value.close();
}, { immediate: true });
onBeforeUnmount(() => dialog.value?.close());
</script>
<template>
    <Teleport to="body"><dialog ref="dialog" :aria-label="title" class="w-[calc(100vw_-_2rem)] rounded-2xl border-0 bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/40" :class="wide ? 'max-w-3xl' : 'max-w-lg'" @close="emit('update:modelValue', false)" @cancel="emit('update:modelValue', false)"><div class="max-h-[85vh] overflow-y-auto p-6"><header class="mb-6 flex items-center justify-between gap-3"><h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2><button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100" :aria-label="`Cerrar ${title.toLowerCase()}`" @click="emit('update:modelValue', false)">×</button></header><slot /></div></dialog></Teleport>
</template>
