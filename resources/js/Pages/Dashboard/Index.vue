<script setup>
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import KpiCard from '@/Components/KpiCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import LineChart from './Partials/LineChart.vue';
import NetworkTabs from './Partials/NetworkTabs.vue';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { NETWORK_COLORS } from './Partials/format';

/**
 * Resumen de marca: una tarjeta por métrica con el total grande, el desglose por red en chips de color y la
 * línea temporal debajo. Lo que una API no da se muestra como «No disponible» (nunca 0).
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

const onboarding = [
    { title: 'Agrupa tus cuentas', text: 'Una marca reúne las cuentas de un cliente o proyecto con su zona horaria.', kind: 'link' },
    { title: 'Conecta tus redes', text: 'Instagram, Facebook, YouTube y TikTok con su acceso oficial.', kind: 'chart' },
    { title: 'Programa y mide', text: 'Calendario, estadísticas e informes en un mismo lugar.', kind: 'calendar' },
];
const connectCards = [
    { title: 'Estadísticas por red', text: 'Seguidores, visualizaciones y alcance con comparación de periodos.', kind: 'chart' },
    { title: 'Publicaciones programadas', text: 'Crea y reprograma contenido desde el calendario.', kind: 'calendar' },
    { title: 'Informes PDF y CSV', text: 'Descárgalos por marca cuando los necesites.', kind: 'report' },
];
</script>

<template>
    <Head title="Analítica" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Resumen{{ brand ? ` · ${brand.name}` : '' }}</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea tu primera marca"
                description="Una marca agrupa las redes sociales de un cliente o proyecto. Créala y conecta tus cuentas para ver estadísticas."
                :cards="onboarding"
            >
                <template #cta><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template>
            </FeatureEmpty>

            <template v-else>
                <PeriodFilter route-name="dashboard" :brands="brands" :filters="filters" />
                <NetworkTabs :networks="summary.networks" active="summary" :filters="filters" />

                <FeatureEmpty
                    v-if="summary.networks.length === 0"
                    title="Conecta tu primera red"
                    :description="`«${brand.name}» aún no tiene redes conectadas. Conecta una para empezar a ver sus estadísticas.`"
                    :cards="connectCards"
                >
                    <template #cta><Link :href="route('brands.connections.index', brand.id)" class="btn-primary">Conectar una red</Link></template>
                </FeatureEmpty>

                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <KpiCard
                        v-for="card in summary.cards"
                        :key="card.key"
                        :data-card="card.key"
                        :label="card.label"
                        :total="card.total"
                        :available="card.available"
                        :delta-pct="card.delta_pct"
                        :comparing="!!summary.comparison"
                        :previous="card.previous"
                        :comparison-label="summary.comparison ? `Periodo de comparación (${summary.comparison.from} - ${summary.comparison.to})` : ''"
                        :breakdown="card.by_network"
                    >
                        <LineChart :series="seriesFor(card)" :height="180" />
                    </KpiCard>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
