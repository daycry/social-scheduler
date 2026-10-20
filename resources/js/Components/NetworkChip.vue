<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Chip de red. Sin `label`, usa el nombre de la red que viene en `shell.networks`. */
const props = defineProps({
    network: { type: String, required: true },
    label: { type: String, default: null },
});

const page = usePage();
const text = computed(() => props.label ?? (page.props.shell?.networks ?? []).find((n) => n.value === props.network)?.label ?? props.network);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-0.5 pe-2.5 ps-0.5 text-xs font-medium text-slate-700">
        <NetworkIcon :network="network" size="sm" />{{ text }}
    </span>
</template>
