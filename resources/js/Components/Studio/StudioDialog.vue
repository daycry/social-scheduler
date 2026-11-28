<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';
defineProps({ modelValue: Boolean, title: { type: String, required: true }, wide: Boolean });
const emit = defineEmits(['update:modelValue']);
</script>
<template>
    <BaseDialog
        :open="modelValue"
        :aria-label="title"
        class="w-[calc(100vw_-_2rem)] rounded-2xl border-0 p-0 shadow-2xl"
        :class="wide ? 'max-w-3xl' : 'max-w-lg'"
        @close="emit('update:modelValue', false)"
        ><div class="max-h-[85vh] overflow-y-auto p-6"
            ><header class="mb-6 flex items-center justify-between gap-3"
                ><h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2
                ><button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-foreground-subtle hover:bg-surface-hover"
                    :aria-label="`Cerrar ${title.toLowerCase()}`"
                    @click="emit('update:modelValue', false)"
                    >×</button
                ></header
            ><slot
        /></div>
    </BaseDialog>
</template>
