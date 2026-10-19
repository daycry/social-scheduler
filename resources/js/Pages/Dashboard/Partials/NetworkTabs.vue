<script setup>
import { Link } from '@inertiajs/vue3';
import { NETWORK_COLORS } from './format';

/** Pestanas: Resumen + una por red conectada (ui-reference.md: barra lateral de Analytics). */
defineProps({
    networks: { type: Array, required: true },
    active: { type: String, default: 'summary' },
    filters: { type: Object, required: true },
});

const query = (f) => ({ brand_id: f.brand_id, from: f.from, to: f.to, compare: f.compare });
</script>

<template>
    <nav class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 text-sm">
        <Link :href="route('dashboard', query(filters))" :class="['rounded px-3 py-1.5', active === 'summary' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100']">
            Resumen
        </Link>
        <Link
            v-for="n in networks"
            :key="n.value"
            :href="route('dashboard.network', { network: n.value, ...query(filters) })"
            :class="['flex items-center gap-1.5 rounded px-3 py-1.5', active === n.value ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100']"
        >
            <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: NETWORK_COLORS[n.value] }"></span>{{ n.label }}
        </Link>
    </nav>
</template>
