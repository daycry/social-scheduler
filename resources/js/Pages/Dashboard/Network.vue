<script setup>
/** @import { BrandRef } from '@/types/shell' */
/** @import { AnalyticsFilters, ConnectedNetwork, NetworkDetail, NetworkMetric } from '@/types/analytics' */
import { createDateFormatter } from '@/lib/format';
import ConnectNetwork from '@/Components/ConnectNetwork.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import MetricQuality from '@/Components/MetricQuality.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LineChart from './Partials/LineChart.vue';
import NetworkTabs from './Partials/NetworkTabs.vue';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { useNetworks } from '@/Composables/useNetworks';
import { deltaClass, fmt, fmtPct, UNAVAILABLE } from './Partials/format';

/**
 * Pagina por red (T-37, inspirada en Analytics > Facebook de Metricool): Crecimiento (seguidores), Visibilidad,
 * Interacciones, KPIs derivados (por dia / por publicacion) y tabla de publicaciones con sus metricas.
 * Cada metrica sin dato en el periodo se muestra como «No disponible».
 */
const props = defineProps({
    brands: { type: /** @type {import('vue').PropType<BrandRef[]>} */ (Array), required: true },
    brand: { type: /** @type {import('vue').PropType<(BrandRef & { timezone: string | null }) | null>} */ (Object), default: null },
    filters: { type: /** @type {import('vue').PropType<AnalyticsFilters>} */ (Object), required: true },
    networks: { type: /** @type {import('vue').PropType<ConnectedNetwork[]>} */ (Array), required: true },
    detail: { type: /** @type {import('vue').PropType<NetworkDetail | null>} */ (Object), default: null },
});

const catalog = useNetworks();
const networkInfo = computed(() => catalog.find(props.detail?.network.value));
const color = computed(() => catalog.color(props.detail?.network.value));
/** @param {string} key */
const metric = (key) => props.detail?.metrics.find((m) => m.key === key);
const GROUPS = [
    { title: 'Visibilidad', keys: ['views', 'reach', 'profile_views', 'watch_minutes'] },
    { title: 'Interacciones', keys: ['interactions', 'likes', 'comments', 'shares', 'clicks', 'accounts_engaged'] },
    { title: 'Contenido', keys: ['video_count'] },
];
/** @param {{ keys: string[] }} g */
const group = (g) => g.keys.map(metric).filter((entry) => entry !== undefined);
/** @param {NetworkMetric} m */
const chart = (m) => [{ name: m.label, color: color.value, points: m.series }];
const growthSeries = computed(() => {
    const out = [];
    const f = metric('followers');
    if (f) out.push({ name: f.label, color: color.value, points: f.series });
    return out;
});
const gainedLost = computed(() =>
    ['followers_gained', 'followers_lost']
        .map(metric)
        .filter((entry) => entry !== undefined)
        .map((m) => ({ name: m.label, color: m.key === 'followers_gained' ? 'rgb(var(--color-success-fill))' : 'rgb(var(--color-danger-fill))', points: m.series })),
);
const dt = computed(() => createDateFormatter(props.brand?.timezone, { dateStyle: 'short', timeStyle: 'short' }));
</script>

<template>
    <Head :title="`Estadísticas ${detail?.network.label ?? ''}`" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong"
                >{{ detail?.network.label }}{{ brand ? ` · ${brand.name}` : '' }}</h1
            >
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea tu primera marca"
                description="Una marca agrupa las redes de un cliente o proyecto. Créala y conecta tus cuentas para ver estadísticas."
            >
                <template #cta
                    ><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template
                >
            </FeatureEmpty>

            <template v-else-if="detail">
                <PeriodFilter
                    route-name="dashboard.network"
                    :route-params="{ network: detail.network.value }"
                    :brands="brands"
                    :filters="filters"
                />
                <NetworkTabs :networks="networks" :active="detail.network.value" :filters="filters" />

                <ConnectNetwork
                    v-if="detail.accounts.length === 0 && networkInfo"
                    :network="networkInfo"
                    :brand="brand"
                />
                <div v-else-if="detail.accounts.length === 0" class="card p-6 text-foreground-secondary"
                    >No hay ninguna cuenta de {{ detail.network.label }} conectada en esta marca.</div
                >

                <template v-else>
                    <!-- Crecimiento -->
                    <section class="card p-5" data-section="growth">
                        <h2 class="text-sm font-semibold text-foreground-secondary">Crecimiento</h2>
                        <MetricQuality
                            :coverage="metric('followers')?.coverage"
                            :freshness="metric('followers')?.freshness"
                        />
                        <div class="mt-2 grid gap-4 sm:grid-cols-4">
                            <div>
                                <p class="text-xs text-foreground-subtle">Seguidores (último dato conocido)</p>
                                <p
                                    class="text-2xl font-semibold"
                                    :class="detail.growth.end === null ? 'text-foreground-subtle text-lg' : ''"
                                    >{{ fmt(detail.growth.end) }}</p
                                >
                            </div>
                            <div>
                                <p class="text-xs text-foreground-subtle">Seguidores (primer dato conocido)</p>
                                <p
                                    class="text-2xl font-semibold"
                                    :class="detail.growth.start === null ? 'text-foreground-subtle text-lg' : ''"
                                    >{{ fmt(detail.growth.start) }}</p
                                >
                            </div>
                            <div>
                                <p class="text-xs text-foreground-subtle">Crecimiento</p>
                                <p
                                    class="text-2xl font-semibold"
                                    :class="detail.growth.change === null ? 'text-foreground-subtle text-lg' : ''"
                                    >{{ fmt(detail.growth.change) }}</p
                                >
                            </div>
                            <div>
                                <p class="text-xs text-foreground-subtle">Crecimiento %</p>
                                <p
                                    class="text-2xl font-semibold"
                                    :class="detail.growth.pct === null ? 'text-foreground-subtle text-lg' : ''"
                                    >{{ detail.growth.pct === null ? UNAVAILABLE : fmtPct(detail.growth.pct) }}</p
                                >
                            </div>
                        </div>
                        <p v-if="detail.growth.status === 'partial'" class="mt-2 text-xs text-warning-ink"
                            >Crecimiento parcial: {{ detail.growth.unmeasured_accounts }} cuenta(s) sin dos
                            observaciones. La variación solo incluye las cuentas medibles; el saldo conserva los
                            seguidores conocidos.</p
                        >
                        <p v-else-if="detail.growth.status === 'insufficient'" class="mt-2 text-xs text-warning-ink"
                            >Faltan dos observaciones por cuenta para medir el crecimiento.</p
                        >
                        <p
                            v-if="detail.comparison && detail.growth.previous"
                            class="mt-1 text-xs text-foreground-subtle"
                        >
                            Periodo de comparación:
                            {{ fmt(detail.growth.previous.change) }}
                            ({{ fmtPct(detail.growth.previous.pct) ?? UNAVAILABLE }})
                        </p>
                        <div class="mt-3"><LineChart :series="growthSeries" /></div>
                        <div v-if="gainedLost.length" class="mt-4">
                            <p class="mb-1 text-xs font-medium text-foreground-subtle">Ganados / perdidos por dia</p>
                            <LineChart :series="gainedLost" :height="150" />
                        </div>
                    </section>

                    <!-- Visibilidad / Interacciones / Contenido -->
                    <section
                        v-for="g in GROUPS.filter((x) => group(x).length)"
                        :key="g.title"
                        class="card p-5"
                        :data-section="g.title"
                    >
                        <h2 class="text-sm font-semibold text-foreground-secondary">{{ g.title }}</h2>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div v-for="m in group(g)" :key="m.key" :data-metric="m.key">
                                <div class="flex items-baseline justify-between">
                                    <p class="text-xs font-medium text-foreground-subtle">{{ m.label }}</p>
                                    <span
                                        v-if="detail.comparison && m.delta_pct !== null"
                                        class="rounded px-1.5 text-xs"
                                        :class="deltaClass(m.delta_pct)"
                                        >{{ fmtPct(m.delta_pct) }}</span
                                    >
                                </div>
                                <p
                                    class="text-2xl font-semibold"
                                    :class="m.available ? 'text-foreground-strong' : 'text-foreground-subtle text-lg'"
                                    >{{ fmt(m.total) }}</p
                                >
                                <MetricQuality
                                    :coverage="m.coverage"
                                    :freshness="m.freshness"
                                    :comparison="m.comparison_quality"
                                />
                                <LineChart :series="chart(m)" :height="140" />
                            </div>
                        </div>
                    </section>

                    <!-- KPIs derivados -->
                    <section class="card p-5" data-section="kpis">
                        <h2 class="text-sm font-semibold text-foreground-secondary">KPIs</h2>
                        <dl class="mt-3 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                            <div
                                ><dt class="text-xs text-foreground-subtle">Interacciones por día con datos</dt
                                ><dd class="text-lg font-semibold"
                                    >{{ fmt(detail.kpis.per_day.interactions, 1)
                                    }}<p
                                        v-if="detail.kpis.per_day_basis?.interactions"
                                        class="text-xs font-normal text-foreground-subtle"
                                        >{{ detail.kpis.per_day_basis.interactions.observed_days }} de
                                        {{ detail.kpis.per_day_basis.interactions.requested_days }} días</p
                                    ></dd
                                ></div
                            >
                            <div
                                ><dt class="text-xs text-foreground-subtle">Visualizaciones por día con datos</dt
                                ><dd class="text-lg font-semibold"
                                    >{{ fmt(detail.kpis.per_day.views, 1)
                                    }}<p
                                        v-if="detail.kpis.per_day_basis?.views"
                                        class="text-xs font-normal text-foreground-subtle"
                                        >{{ detail.kpis.per_day_basis.views.observed_days }} de
                                        {{ detail.kpis.per_day_basis.views.requested_days }} días</p
                                    ></dd
                                ></div
                            >
                            <div
                                ><dt class="text-xs text-foreground-subtle">Engagement (cuenta)</dt
                                ><dd
                                    class="text-lg font-semibold"
                                    >{{ detail.kpis.engagement_rate === null ? UNAVAILABLE : `${fmt(detail.kpis.engagement_rate, 2)} %` }}</dd
                                ></div
                            >
                            <div
                                ><dt class="text-xs text-foreground-subtle">Visualizaciones por publicacion</dt
                                ><dd class="text-lg font-semibold">{{ fmt(detail.kpis.per_post.views, 1) }}</dd></div
                            >
                            <div
                                ><dt class="text-xs text-foreground-subtle">Interacciones por publicacion</dt
                                ><dd
                                    class="text-lg font-semibold"
                                    >{{ fmt(detail.kpis.per_post.interactions, 1) }}</dd
                                ></div
                            >
                            <div
                                ><dt class="text-xs text-foreground-subtle">Engagement por publicacion</dt
                                ><dd
                                    class="text-lg font-semibold"
                                    >{{ detail.kpis.per_post.engagement_rate === null ? UNAVAILABLE : `${fmt(detail.kpis.per_post.engagement_rate, 2)} %` }}</dd
                                ></div
                            >
                        </dl>
                        <p class="mt-2 text-xs text-foreground-subtle"
                            >Engagement = interacciones / visualizaciones, usando cuentas y días con ambas métricas. Las
                            medias por publicación solo incluyen las publicadas desde esta aplicación con datos.</p
                        >
                    </section>

                    <!-- Publicaciones -->
                    <section class="card p-5" data-section="posts">
                        <h2 class="text-sm font-semibold text-foreground-secondary"
                            >Publicaciones del periodo ({{ detail.posts_total }})</h2
                        >
                        <p
                            v-if="detail.posts_truncated"
                            class="mt-1 text-xs text-warning-ink"
                            data-testid="posts-truncated"
                        >
                            Se muestran las {{ detail.posts.length }} con más interacciones de {{ detail.posts_total }}.
                            Los KPIs por publicación incluyen todas.
                        </p>
                        <p v-if="detail.posts.length === 0" class="mt-2 text-sm text-foreground-subtle"
                            >No hay publicaciones de esta red en el periodo.</p
                        >
                        <div
                            v-else
                            class="mt-3 overflow-x-auto"
                            tabindex="0"
                            role="region"
                            aria-label="Publicaciones con métricas"
                        >
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-xs text-foreground-subtle">
                                    <tr>
                                        <th class="py-1 pr-3">Fecha</th><th class="pr-3">Contenido</th>
                                        <th class="pr-3 text-right">Visualiz.</th
                                        ><th class="pr-3 text-right">Alcance</th>
                                        <th class="pr-3 text-right">Me gusta</th
                                        ><th class="pr-3 text-right">Coment.</th>
                                        <th class="pr-3 text-right">Compart.</th
                                        ><th class="pr-3 text-right">Interacc.</th
                                        ><th class="text-right">Engagement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="p in detail.posts"
                                        :key="p.id"
                                        class="border-t border-border-subtle"
                                        :class="p.has_data ? '' : 'text-foreground-subtle'"
                                    >
                                        <td class="whitespace-nowrap py-1.5 pr-3">{{ dt(p.published_at) }}</td>
                                        <td class="max-w-xs truncate pr-3">
                                            <a
                                                v-if="p.url"
                                                :href="p.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-accent hover:underline"
                                                >{{ p.text || p.content_type }}</a
                                            >
                                            <span v-else>{{ p.text || p.content_type }}</span>
                                        </td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.views) }}</td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.reach) }}</td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.likes) }}</td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.comments) }}</td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.shares) }}</td>
                                        <td class="pr-3 text-right">{{ fmt(p.metrics.interactions) }}</td>
                                        <td
                                            class="text-right"
                                            >{{ p.engagement_rate === null ? UNAVAILABLE : `${fmt(p.engagement_rate, 2)} %` }}</td
                                        >
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
