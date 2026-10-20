<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';

defineProps({
    network: { type: String, required: true },
    label: { type: String, required: true },
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-0.5 pe-2.5 ps-0.5 text-xs font-medium text-slate-700">
        <NetworkIcon :network="network" size="sm" />{{ label }}
    </span>
</template>
