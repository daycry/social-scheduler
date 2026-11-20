<script setup>
import { computed, useId } from 'vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    id: String,
    error: String,
    describedBy: String,
    errorClass: String,
});

const uid = useId();
const fieldId = computed(() => props.id ?? uid);
const errorId = computed(() => `${fieldId.value}-error`);
const control = computed(() => ({
    id: fieldId.value,
    'aria-invalid': props.error ? /** @type {const} */ ('true') : undefined,
    'aria-describedby': [props.describedBy, props.error && errorId.value].filter(Boolean).join(' ') || undefined,
}));
const group = computed(() => ({
    id: fieldId.value,
    'aria-describedby': control.value['aria-describedby'],
    'data-error-target': control.value['aria-invalid'],
    tabindex: -1,
}));
</script>

<template>
    <slot :id="fieldId" :control="control" :group="group" />
    <InputError :id="errorId" :message="error" :class="errorClass" />
</template>
