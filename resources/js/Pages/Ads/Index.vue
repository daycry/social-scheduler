<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Meta Ads, solo lectura (PA-11): cuentas publicitarias, campañas y métricas básicas (gasto, impresiones, clics, CTR, CPC). */
const props = defineProps({
    overview: Object,
    periods: Array,
});

const o = computed(() => props.overview);
const currency = computed(() => o.value.selected?.currency ?? 'EUR');
const money = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('es-ES', { style: 'currency', currency: currency.value, maximumFractionDigits: 2 }).format(v));
const num = (v, d = 0) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('es-ES', { maximumFractionDigits: d }).format(v));
const pct = (v) => (v === null || v === undefined ? '—' : `${num(v, 2)} %`);

const go = (patch) => {
    const q = { ad_account: o.value.selected?.id, days: o.value.period, ...patch };
    router.get(route('ads.index'), q, { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <Head title="Meta Ads" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Meta Ads (solo lectura)</h2>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!o.available && ['sin_permiso', 'reconectar'].includes(o.reason)"
                title="Meta Ads no disponible"
                :description="o.message"
                network="facebook"
                :cards="[
                    { title: 'Gasto y alcance', text: 'Gasto, impresiones y clics por periodo.', kind: 'chart' },
                    { title: 'Campañas', text: 'Estado y métricas de cada campaña.', kind: 'report' },
                    { title: 'Solo lectura', text: 'No se crea ni se modifica ningún anuncio.', kind: 'link' },
                ]"
            />
            <p v-else-if="!o.available" class="card p-5 text-sm text-slate-700" role="alert">{{ o.message }}</p>

            <template v-else>
                <p v-if="o.message" class="card p-5 text-sm text-slate-700">{{ o.message }}</p>

                <div v-if="o.selected" class="card flex flex-wrap items-center gap-4 p-4 text-sm">
                    <label class="flex items-center gap-2">
                        <NetworkIcon network="facebook" size="sm" />
                        <span class="text-xs font-medium text-slate-500">Cuenta publicitaria</span>
                        <select class="field w-auto" :value="o.selected.id" @change="go({ ad_account: $event.target.value })">
                            <option v-for="a in o.ad_accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.id }})</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Periodo</span>
                        <select class="field w-auto" :value="o.period" @change="go({ days: Number($event.target.value) })">
                            <option v-for="p in periods" :key="p" :value="p">Últimos {{ p }} días</option>
                        </select>
                    </label>
                </div>

                <div v-if="o.selected" class="grid gap-4 md:grid-cols-3 xl:grid-cols-5" data-testid="ads-kpis">
                    <section class="card p-5">
                        <h3 class="text-sm font-medium text-slate-500">Gasto</h3>
                        <p class="mt-0.5 text-3xl font-semibold tabular-nums text-slate-900">{{ money(o.totals?.spend) }}</p>
                    </section>
                    <KpiCard label="Impresiones" :total="o.totals?.impressions ?? null" :available="o.totals?.impressions != null" />
                    <KpiCard label="Clics" :total="o.totals?.clicks ?? null" :available="o.totals?.clicks != null" />
                    <section class="card p-5">
                        <h3 class="text-sm font-medium text-slate-500">CTR</h3>
                        <p class="mt-0.5 text-3xl font-semibold tabular-nums text-slate-900">{{ pct(o.totals?.ctr) }}</p>
                    </section>
                    <section class="card p-5">
                        <h3 class="text-sm font-medium text-slate-500">CPC</h3>
                        <p class="mt-0.5 text-3xl font-semibold tabular-nums text-slate-900">{{ money(o.totals?.cpc) }}</p>
                    </section>
                </div>

                <section v-if="o.selected" class="card overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Campañas y métricas del periodo</caption>
                        <thead class="border-b text-xs text-slate-500">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Campaña</th>
                                <th scope="col" class="px-3 py-3 font-medium">Estado</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Gasto</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Impresiones</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Clics</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">CTR</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">CPC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="o.campaigns.length === 0">
                                <td colspan="7" class="px-5 py-4 text-slate-500">Esta cuenta publicitaria no tiene campañas.</td>
                            </tr>
                            <tr v-for="c in o.campaigns" :key="c.id">
                                <td class="px-5 py-3 font-medium text-slate-900">{{ c.name }}<span v-if="c.objective" class="block text-xs font-normal text-slate-500">{{ c.objective }}</span></td>
                                <td class="px-3 py-3">{{ c.status ?? '—' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ money(c.insights?.spend) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ num(c.insights?.impressions) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ num(c.insights?.clicks) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ pct(c.insights?.ctr) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ money(c.insights?.cpc) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
