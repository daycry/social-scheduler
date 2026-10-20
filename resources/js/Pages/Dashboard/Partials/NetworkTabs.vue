<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link } from '@inertiajs/vue3';

/** Pestañas Resumen + una por red conectada. Solo en móvil: en escritorio lo cubre la barra lateral. */
defineProps({
    networks: { type: Array, required: true },
    active: { type: String, default: 'summary' },
    filters: { type: Object, required: true },
});

const query = (f) => ({ brand_id: f.brand_id, from: f.from, to: f.to, compare: f.compare });
</script>

<template>
    <nav class="flex gap-2 overflow-x-auto pb-1 text-sm lg:hidden" aria-label="Redes">
        <Link :href="route('dashboard', query(filters))" :class="['shrink-0 rounded-full px-3 py-1.5 font-medium', active === 'summary' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200']">
            Resumen
        </Link>
        <Link
            v-for="n in networks"
            :key="n.value"
            :href="route('dashboard.network', { network: n.value, ...query(filters) })"
            :class="['flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 font-medium', active === n.value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200']"
        >
            <NetworkIcon :network="n.value" size="sm" />{{ n.label }}
        </Link>
    </nav>
</template>
