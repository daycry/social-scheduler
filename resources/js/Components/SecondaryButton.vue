<script setup>
defineProps({
    type: {
        type: /** @type {import('vue').PropType<'button' | 'submit' | 'reset'>} */ (String),
        default: 'button',
    },
});
</script>

<template>
    <button :type="type" class="btn-secondary">
        <slot />
    </button>
</template>
