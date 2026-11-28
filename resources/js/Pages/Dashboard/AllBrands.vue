<script setup>
/** @import { BrandRef } from '@/types/shell' */
/** @import { AnalyticsFilters, SummaryMetric, AggregateDashboardSummary } from '@/types/analytics' */
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import KpiCard from '@/Components/KpiCard.vue';
import { useNetworks } from '@/Composables/useNetworks';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import LineChart from './Partials/LineChart.vue';
import { seriesByNetwork } from './Partials/series';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { fmt, UNAVAILABLE } from './Partials/format';

/**
 * «Todas las marcas» (C-03b): la analítica de todas las marcas del usuario sumada. Cada métrica es la suma de las marcas
 * que la tienen («No disponible» si ninguna; nunca 0). Los días son días UTC, como los snapshots, y «acumulado»
 * (seguidores, vídeos, publicaciones) suma el último valor de cada cuenta: no son personas únicas.
 */
const props = defineProps({
    brands: { type: /** @type {import('vue').PropType<BrandRef[]>} */ (Array), required: true },
    filters: { type: /** @type {import('vue').PropType<AnalyticsFilters>} */ (Object), required: true },
    summary: { type: /** @type {import('vue').PropType<AggregateDashboardSummary | null>} */ (Object), default: null },
});

const networkCatalog = useNetworks();

/** @param {SummaryMetric} card */
const seriesFor = (card) => seriesByNetwork(card, props.summary?.networks ?? [], networkCatalog.color);

const columns = () => [{ key: 'followers', label: 'Seguidores (acumulado)' }, { key: 'views', label: 'Visualizaciones' }, { key: 'reach', label: 'Alcance' }, { key: 'interactions', label: 'Interacciones' }];
</script>

<template>
    <Head title="Todas las marcas" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Todas las marcas</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!summary"
                title="Crea tu primera marca"
                description="Una marca agrupa las redes sociales de un cliente o proyecto. Créala y conecta tus cuentas para ver estadísticas."
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <template v-else>
                <PeriodFilter route-name="dashboard.all" :brands="brands" :filters="filters" />
                <div class="flex justify-end">
                    <a
                        :href="route('exports.analytics', { from: filters.from, to: filters.to, compare: filters.compare })"
                        class="btn-secondary"
                        data-testid="export-analytics"
                        >Exportar CSV</a
                    >
                </div>

                <p class="text-xs text-foreground-muted" data-testid="all-brands-note">
                    Suma de {{ summary.brands.length }} {{ summary.brands.length === 1 ? 'marca' : 'marcas' }} en días
                    UTC. «Acumulado» suma el último valor de cada cuenta: no son personas únicas. Lo que una marca o red
                    no ofrece figura como «{{ UNAVAILABLE }}», nunca como 0.
                </p>

                <div class="grid gap-4 lg:grid-cols-2">
                    <KpiCard
                        v-for="card in summary.cards"
                        :key="card.key"
                        :data-card="card.key"
                        :label="card.kind === 'stock' ? `${card.label} (acumulado)` : card.label"
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

                <section class="card p-5" data-section="by-brand">
                    <h2 class="text-sm font-semibold text-foreground-secondary">Por marca</h2>
                    <div class="mt-3 overflow-x-auto" role="region" aria-label="Analítica por marca" tabindex="0">
                        <table class="w-full min-w-[34rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-border text-xs text-foreground-muted">
                                    <th scope="col" class="py-2 pr-3 font-medium">Marca</th>
                                    <th
                                        v-for="c in columns()"
                                        :key="c.key"
                                        scope="col"
                                        class="px-3 py-2 text-right font-medium"
                                        >{{ c.label }}</th
                                    >
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="b in summary.brands"
                                    :key="b.id"
                                    class="border-b border-border-subtle"
                                    :data-brand-row="b.id"
                                >
                                    <th scope="row" class="py-2 pr-3 font-medium text-foreground-strong">
                                        <Link
                                            :href="route('dashboard', { brand_id: b.id, from: filters.from, to: filters.to, compare: filters.compare })"
                                            class="text-accent-strong hover:underline"
                                            >{{ b.name }}</Link
                                        >
                                    </th>
                                    <td
                                        v-for="c in columns()"
                                        :key="c.key"
                                        class="px-3 py-2 text-right tabular-nums"
                                        :class="b.values[c.key] === null ? 'text-foreground-muted' : 'text-foreground-strong'"
                                        :data-metric="c.key"
                                        >{{ fmt(b.values[c.key]) }}</td
                                    >
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
