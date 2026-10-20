<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Chips horizontales de Resumen + TODAS las redes. Solo en móvil: en escritorio lo cubre la barra lateral. Una red conectada
 * lleva a su analítica; una que falta lleva a conectarla («+ Conectar»), para que el móvil no oculte que existen.
 */
const props = defineProps({
    networks: { type: Array, required: true },
    active: { type: String, default: 'summary' },
    filters: { type: Object, required: true },
});

const ALL = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'youtube', label: 'YouTube' },
    { value: 'tiktok', label: 'TikTok' },
];

const query = (f) => ({ brand_id: f.brand_id, from: f.from, to: f.to, compare: f.compare });
const connected = computed(() => new Set(props.networks.map((n) => n.value)));
const chips = computed(() => ALL.map((n) => ({ ...n, connected: connected.value.has(n.value) })));
</script>

<template>
    <nav class="flex gap-2 overflow-x-auto pb-1 text-sm lg:hidden" aria-label="Redes">
        <Link :href="route('dashboard', query(filters))" :class="['shrink-0 rounded-full px-3 py-1.5 font-medium', active === 'summary' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200']">
            Resumen
        </Link>
        <template v-for="n in chips" :key="n.value">
            <Link
                v-if="n.connected"
                :href="route('dashboard.network', { network: n.value, ...query(filters) })"
                :class="['flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 font-medium', active === n.value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200']"
            >
                <NetworkIcon :network="n.value" size="sm" />{{ n.label }}
            </Link>
            <Link
                v-else-if="filters.brand_id"
                :href="route('brands.connections.index', filters.brand_id)"
                :aria-label="`Conectar ${n.label}`"
                class="flex shrink-0 items-center gap-1.5 rounded-full bg-white px-3 py-1.5 font-medium text-slate-600 ring-1 ring-dashed ring-slate-300"
            >
                <NetworkIcon :network="n.value" size="sm" />{{ n.label }} <span aria-hidden="true">+</span>
            </Link>
        </template>
    </nav>
</template>
