<script setup>
/** @import { BrandRef } from '@/types/shell' */
/** @import { AnalyticsFilters, SummaryMetric, DashboardSummary } from '@/types/analytics' */
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import KpiCard from '@/Components/KpiCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import LineChart from './Partials/LineChart.vue';
import { seriesByNetwork } from './Partials/series';
import NetworkTabs from './Partials/NetworkTabs.vue';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { useNetworks } from '@/Composables/useNetworks';

/**
 * Resumen de marca: una tarjeta por métrica con el total grande, el desglose por red en chips de color y la
 * línea temporal debajo. Lo que una API no da se muestra como «No disponible» (nunca 0).
 */
const props = defineProps({
    brands: { type: /** @type {import('vue').PropType<BrandRef[]>} */ (Array), required: true },
    brand: { type: /** @type {import('vue').PropType<BrandRef | null>} */ (Object), default: null },
    filters: { type: /** @type {import('vue').PropType<AnalyticsFilters>} */ (Object), required: true },
    summary: { type: /** @type {import('vue').PropType<DashboardSummary | null>} */ (Object), default: null },
});

const networkCatalog = useNetworks();

/** @param {SummaryMetric} card */
const seriesFor = (card) => seriesByNetwork(card, props.summary?.networks ?? [], networkCatalog.color);

/** @type {import('@/Components/FeatureEmpty.vue').FeatureCard[]} */
const onboarding = [
    { title: 'Agrupa tus cuentas', text: 'Una marca reúne las cuentas de un cliente o proyecto con su zona horaria.', kind: 'link' },
    { title: 'Conecta tus redes', text: 'Instagram, Facebook, YouTube y TikTok con su acceso oficial.', kind: 'chart' },
    { title: 'Programa y mide', text: 'Calendario, estadísticas e informes en un mismo lugar.', kind: 'calendar' },
];
/** @type {import('@/Components/FeatureEmpty.vue').FeatureCard[]} */
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
            <h1 class="text-xl font-bold text-foreground-strong">Resumen{{ brand ? ` · ${brand.name}` : '' }}</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea tu primera marca"
                description="Una marca agrupa las redes sociales de un cliente o proyecto. Créala y conecta tus cuentas para ver estadísticas."
                :cards="onboarding"
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <template v-else-if="summary">
                <div class="flex flex-wrap justify-end gap-2">
                    <Link
                        v-if="brands.length > 1"
                        :href="route('dashboard.all', { from: filters.from, to: filters.to, compare: filters.compare })"
                        class="btn-secondary"
                        data-testid="all-brands-link"
                        >Todas las marcas</Link
                    >
                    <a
                        :href="route('exports.analytics', { brand_id: brand.id, from: filters.from, to: filters.to, compare: filters.compare })"
                        class="btn-secondary"
                        data-testid="export-analytics"
                        >Exportar CSV</a
                    >
                </div>
                <PeriodFilter route-name="dashboard" :brands="brands" :filters="filters" />
                <NetworkTabs :networks="summary.networks" active="summary" :filters="filters" />

                <FeatureEmpty
                    v-if="summary.networks.length === 0"
                    title="Conecta tu primera red"
                    :description="`«${brand.name}» aún no tiene redes conectadas. Conecta una para empezar a ver sus estadísticas.`"
                    :cards="connectCards"
                >
                    <template #cta
                        ><Link :href="route('brands.connections.index', brand.id)" class="btn-primary"
                            >Conectar una red</Link
                        ></template
                    >
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
                        :coverage="card.coverage"
                        :freshness="card.freshness"
                        :comparison-quality="card.comparison_quality"
                    >
                        <LineChart :series="seriesFor(card)" :height="180" />
                    </KpiCard>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
