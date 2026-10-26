<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LineChart from '@/Pages/Dashboard/Partials/LineChart.vue';
import { fmt, NETWORK_COLORS, UNAVAILABLE } from '@/Pages/Dashboard/Partials/format';

/** Panel de campaña (C-03, CA-11): KPIs del último snapshot de cada destino. «Sin datos» nunca se pinta como 0. */
const props = defineProps({
    campaign: Object,
    brand: Object,
    summary: Object,
});

const k = computed(() => props.summary.kpis);
const pct = (v) => (v === null || v === undefined ? UNAVAILABLE : `${fmt(v, 2)} %`);

const cards = computed(() => [
    { key: 'posts', label: 'Publicaciones', value: fmt(k.value.posts), plain: true },
    { key: 'views', label: 'Visualizaciones', value: fmt(k.value.views), none: k.value.views === null },
    { key: 'reach', label: 'Alcance acumulado', value: fmt(k.value.reach), none: k.value.reach === null, hint: 'Suma del alcance de cada publicación; no son usuarios únicos.' },
    { key: 'interactions', label: 'Interacciones', value: fmt(k.value.interactions), none: k.value.interactions === null },
    { key: 'likes', label: 'Me gusta', value: fmt(k.value.likes), none: k.value.likes === null },
    { key: 'comments', label: 'Comentarios', value: fmt(k.value.comments), none: k.value.comments === null },
    { key: 'shares', label: 'Compartidos', value: fmt(k.value.shares), none: k.value.shares === null },
    { key: 'saved', label: 'Guardados', value: fmt(k.value.saved), none: k.value.saved === null },
    { key: 'engagement', label: 'Engagement', value: pct(k.value.engagement_rate), none: k.value.engagement_rate === null },
]);

const chart = (metric, name) => [{ name, color: NETWORK_COLORS.total, points: props.summary.daily.map((d) => ({ date: d.date, value: d[metric] })) }];
const range = computed(() => {
    const { starts_on: a, ends_on: b } = props.campaign;
    if (a && b) return `${a} → ${b}`;
    if (a) return `Desde ${a}`;
    if (b) return `Hasta ${b}`;
    return 'Sin fechas';
});
</script>

<template>
    <Head :title="`Campaña ${campaign.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="h-4 w-4 shrink-0 rounded-full border border-slate-400" :style="{ backgroundColor: campaign.color }" aria-hidden="true"></span>
                <h1 class="min-w-0 break-words text-xl font-bold text-slate-900">{{ campaign.name }}</h1>
                <span v-if="campaign.archived" class="rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-700">Archivada</span>
                <span class="text-sm text-slate-600">{{ brand?.name }} · {{ range }}</span>
                <Link :href="route('campaigns.index', { brand_id: campaign.brand_id })" class="ms-auto text-sm font-medium text-indigo-700 underline">Todas las campañas</Link>
            </div>
        </template>

        <div class="space-y-5">
            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" data-testid="campaign-frozen-note">
                Las métricas de cada publicación se actualizan durante {{ summary.frozen_days }} días tras publicarla; después se quedan congeladas con el último dato leído.
                El alcance se rotula «acumulado» porque suma el de cada publicación y no cuenta usuarios únicos.
            </p>

            <section aria-label="Resumen de la campaña" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="campaign-kpis">
                <div v-for="c in cards" :key="c.key" class="card p-4" :data-testid="`kpi-${c.key}`">
                    <p class="text-xs font-medium text-slate-600">{{ c.label }}</p>
                    <p class="mt-1 font-semibold" :class="c.none ? 'text-base text-slate-600' : 'text-2xl text-slate-900'">{{ c.value }}</p>
                    <p v-if="c.hint" class="mt-1 text-xs text-slate-600">{{ c.hint }}</p>
                </div>
            </section>

            <p v-if="k.posts === 0" class="card p-5 text-sm text-slate-600">Esta campaña aún no tiene publicaciones. Asígnala desde el compositor para ver aquí sus métricas.</p>

            <template v-else>
                <section class="card p-5" aria-labelledby="camp-network">
                    <h2 id="camp-network" class="text-sm font-medium text-slate-700">Por red</h2>
                    <p v-if="summary.by_network.length === 0" class="mt-2 text-sm text-slate-600">Ninguna publicación de la campaña está publicada todavía.</p>
                    <div v-else class="mt-3 overflow-x-auto" tabindex="0" role="region" aria-label="Métricas de la campaña por red">
                        <table class="min-w-full text-sm" data-testid="campaign-by-network">
                            <thead class="text-left text-xs text-slate-600">
                                <tr>
                                    <th class="py-1 pr-3">Red</th><th class="pr-3 text-right">Destinos</th><th class="pr-3 text-right">Visualiz.</th>
                                    <th class="pr-3 text-right">Alcance acum.</th><th class="pr-3 text-right">Interacc.</th><th class="text-right">Engagement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="n in summary.by_network" :key="n.network" class="border-t border-gray-100">
                                    <th scope="row" class="py-1.5 pr-3 text-left font-medium">{{ n.network_label }}</th>
                                    <td class="pr-3 text-right">{{ fmt(n.targets) }}</td>
                                    <td class="pr-3 text-right">{{ fmt(n.views) }}</td>
                                    <td class="pr-3 text-right">{{ fmt(n.reach) }}</td>
                                    <td class="pr-3 text-right">{{ fmt(n.interactions) }}</td>
                                    <td class="text-right">{{ pct(n.engagement_rate) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <p class="text-xs text-slate-600" data-testid="campaign-daily-note">
                    La evolución diaria suma lo leído cada día de cada destino; un destino sin lectura ese día (p. ej. ya congelado) no suma, así que su último punto puede no coincidir con los totales de arriba, que usan el último dato de cada destino.
                </p>
                <section class="grid gap-4 lg:grid-cols-2" aria-label="Evolución diaria">
                    <div class="card p-5">
                        <h2 class="text-sm font-medium text-slate-700">Visualizaciones leídas por día</h2>
                        <div class="mt-3"><LineChart :series="chart('views', 'Visualizaciones')" :height="180" /></div>
                    </div>
                    <div class="card p-5">
                        <h2 class="text-sm font-medium text-slate-700">Interacciones leídas por día</h2>
                        <div class="mt-3"><LineChart :series="chart('interactions', 'Interacciones')" :height="180" /></div>
                    </div>
                </section>

                <section class="card p-5" aria-labelledby="camp-posts">
                    <h2 id="camp-posts" class="text-sm font-medium text-slate-700">Publicaciones</h2>
                    <p v-if="summary.posts_truncated" class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900" role="status" data-testid="campaign-posts-truncated">
                        Se muestran las {{ summary.posts_limit }} publicaciones más recientes de {{ summary.posts_total }}. Los totales y las gráficas incluyen todas.
                    </p>
                    <ul class="mt-3 divide-y">
                        <li v-for="p in summary.posts" :key="p.id" class="py-3 text-sm" data-testid="campaign-post">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <Link :href="route('posts.edit', p.id)" class="min-w-0 break-words font-medium text-indigo-700 underline">{{ p.text || 'Sin texto' }}</Link>
                                <span class="text-xs text-slate-600">{{ p.status_label }} · {{ p.scheduled_local ? `${p.scheduled_local} (${p.timezone})` : 'Sin fecha' }}</span>
                            </div>
                            <div v-if="p.targets.length" class="mt-2 overflow-x-auto" tabindex="0" role="region" :aria-label="`Métricas por destino de la publicación ${p.id}`">
                                <table class="min-w-full text-xs">
                                    <thead class="text-left text-slate-600">
                                        <tr><th class="py-1 pr-3">Destino</th><th class="pr-3 text-right">Visualiz.</th><th class="pr-3 text-right">Alcance</th><th class="pr-3 text-right">Interacc.</th><th class="text-right">Engagement</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="t in p.targets" :key="t.id" class="border-t border-gray-100">
                                            <th scope="row" class="py-1 pr-3 text-left font-medium">
                                                <a v-if="t.url" :href="t.url" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline">{{ t.network_label }}</a>
                                                <template v-else>{{ t.network_label }}</template>
                                                <span v-if="t.status !== 'publicada'" class="ms-1 font-normal text-slate-600">({{ t.status_label }})</span>
                                            </th>
                                            <td class="pr-3 text-right">{{ fmt(t.metrics.views) }}</td>
                                            <td class="pr-3 text-right">{{ fmt(t.metrics.reach) }}</td>
                                            <td class="pr-3 text-right">{{ fmt(t.metrics.interactions) }}</td>
                                            <td class="text-right">{{ pct(t.engagement_rate) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
