<script setup>
import NetworkChips from '@/Components/NetworkChips.vue';
import { useNetworks } from '@/Composables/useNetworks';
import { computed } from 'vue';

/**
 * Chips horizontales de Resumen + TODAS las redes. Solo en móvil: en escritorio lo cubre la barra lateral. Una red conectada
 * lleva a su analítica; una que falta lleva a conectarla («+ Conectar»), para que el móvil no oculte que existen. El dibujo es el
 * componente común `NetworkChips` (el mismo que usa Competidores).
 */
const props = defineProps({
    networks: { type: Array, required: true },
    active: { type: String, default: 'summary' },
    filters: { type: Object, required: true },
});

const catalog = useNetworks();

const query = (f) => ({ brand_id: f.brand_id, from: f.from, to: f.to, compare: f.compare });
const connected = computed(() => new Set(props.networks.map((n) => n.value)));
const items = computed(() =>
    catalog.list().map((n) => ({
        value: n.value,
        label: n.label,
        connected: connected.value.has(n.value),
        href: route('dashboard.network', { network: n.value, ...query(props.filters) }),
        connectHref: props.filters.brand_id ? route('brands.connections.index', props.filters.brand_id) : null,
    })),
);
const summary = computed(() => ({ label: 'Resumen', href: route('dashboard', query(props.filters)) }));
</script>

<template>
    <NetworkChips class="lg:hidden" :items="items" :active="active" :summary="summary" label="Redes" />
</template>
