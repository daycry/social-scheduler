<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="card flex flex-col items-center px-6 py-12 text-center">
        <div v-if="$slots.icon" class="mb-4 text-indigo-500"><slot name="icon" /></div>
        <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-md text-sm text-slate-600">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-3"><slot /></div>
    </div>
</template>
