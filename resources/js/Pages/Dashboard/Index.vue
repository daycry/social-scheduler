<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import LineChart from './Partials/LineChart.vue';
import NetworkTabs from './Partials/NetworkTabs.vue';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { deltaClass, fmt, fmtPct, NETWORK_COLORS, UNAVAILABLE } from './Partials/format';

/**
 * Resumen de marca (T-37, inspirado en Analytics > Summary de Metricool): una tarjeta por metrica con el total
 * grande, el desglose por red en chips de color y la linea temporal debajo. Lo que una API no da se muestra como
 * «No disponible» (nunca 0). Mapa de calor de mejores horas: fuera de alcance de la v1.
 */
const props = defineProps({
    brands: Array,
    brand: Object,
    filters: Object,
    summary: Object,
});

const seriesFor = (card) =>
    (props.summary.networks ?? [])
        .filter((n) => card.by_network.find((b) => b.network === n.value)?.available)
        .map((n) => ({
            name: n.label,
            color: NETWORK_COLORS[n.value],
            points: card.series.map((p) => ({ date: p.date, value: p.values[n.value] ?? null })),
        }));
</script>

<template>
    <Head title="Estadisticas" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Estadisticas{{ brand ? ` · ${brand.name}` : '' }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="!brand" class="rounded-lg bg-white p-6 text-gray-700 shadow-sm">
                    Aun no tienes marcas.
                    <Link :href="route('brands.create')" class="text-indigo-600 underline">Crea una marca</Link> y conecta tus redes para ver estadisticas.
                </div>

                <template v-else>
                    <PeriodFilter route-name="dashboard" :brands="brands" :filters="filters" />
                    <NetworkTabs :networks="summary.networks" active="summary" :filters="filters" />

                    <div v-if="summary.networks.length === 0" class="rounded-lg bg-white p-6 text-gray-700 shadow-sm">
                        Esta marca no tiene redes conectadas.
                        <Link :href="route('brands.connections.index', brand.id)" class="text-indigo-600 underline">Conecta una red</Link>.
                    </div>

                    <div v-else class="grid gap-4 lg:grid-cols-2">
                        <section v-for="card in summary.cards" :key="card.key" class="rounded-lg bg-white p-5 shadow-sm" :data-card="card.key">
                            <header class="flex items-start justify-between">
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">{{ card.label }}</h3>
                                    <p class="text-3xl font-semibold" :class="card.available ? 'text-gray-900' : 'text-gray-400 text-xl'">{{ fmt(card.total) }}</p>
                                </div>
                                <span v-if="summary.comparison && card.delta_pct !== null" class="rounded px-2 py-0.5 text-xs font-medium" :class="deltaClass(card.delta_pct)">
                                    {{ fmtPct(card.delta_pct) }}
                                </span>
                            </header>
                            <p v-if="summary.comparison && card.previous !== null" class="mt-1 text-xs text-gray-500">
                                Periodo de comparacion ({{ summary.comparison.from }} - {{ summary.comparison.to }}): {{ fmt(card.previous) }}
                            </p>

                            <ul class="mt-3 flex flex-wrap gap-2">
                                <li
                                    v-for="b in card.by_network"
                                    :key="b.network"
                                    class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                    :class="b.available ? 'border-gray-300 text-gray-800' : 'border-dashed border-gray-300 text-gray-400'"
                                >
                                    <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: NETWORK_COLORS[b.network] }"></span>
                                    {{ b.label }}: {{ b.available ? fmt(b.value) : UNAVAILABLE }}
                                    <span v-if="summary.comparison && b.delta_pct !== null" class="rounded px-1" :class="deltaClass(b.delta_pct)">{{ fmtPct(b.delta_pct) }}</span>
                                </li>
                            </ul>

                            <div class="mt-4">
                                <LineChart :series="seriesFor(card)" :height="180" />
                            </div>
                        </section>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
