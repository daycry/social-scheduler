<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Mejores horas (PA-05): mapa de calor día de la semana x hora (zona de la marca) con el engagement medio de las
 * publicaciones propias. Con pocos datos muestra "no disponible" en vez de un mapa engañoso.
 */
const props = defineProps({
    brands: Array,
    brand: Object,
    networks: Array,
    network: String,
    days: Number,
    days_labels: Array,
    result: Object,
});

const max = computed(() => {
    const values = (props.result?.matrix ?? []).flat().filter((v) => v !== null);
    return values.length ? Math.max(...values) : 0;
});

const shade = (v) => {
    if (v === null || max.value === 0) return 'background-color: #f1f5f9; color: #94a3b8;';
    const alpha = 0.15 + 0.85 * (v / max.value);
    return `background-color: rgba(79, 70, 229, ${alpha.toFixed(2)}); color: ${alpha > 0.55 ? '#fff' : '#1e1b4b'};`;
};

const change = (params) => router.get(route('best-times.index'), { brand_id: props.brand?.id, network: props.network, ...params }, { preserveState: true, preserveScroll: true });
</script>

<template>
    <Head title="Mejores horas" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Mejores horas para publicar</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Crea una marca para descubrir tus mejores horas"
                description="Con el engagement de tus propias publicaciones, te mostramos qué día y a qué hora funcionan mejor en cada red."
                :cards="[
                    { title: 'Mapa de calor semanal', text: 'Siete días por veinticuatro horas, con la zona horaria de tu marca.', kind: 'calendar' },
                    { title: 'Basado en tus datos', text: 'Solo cuenta tus publicaciones, sin estimaciones genéricas.', kind: 'chart' },
                    { title: 'Mejores franjas', text: 'Las horas con más interacciones, listas para programar.', kind: 'report' },
                ]"
            >
                <template #cta><Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link></template>
            </FeatureEmpty>

            <template v-else>
                <div class="card flex flex-wrap items-end gap-4 p-5">
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Red social">
                        <button
                            v-for="n in networks"
                            :key="n.value"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border py-1 pe-3 ps-1 text-sm font-medium transition"
                            :class="n.value === network ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                            :aria-pressed="n.value === network"
                            @click="change({ network: n.value })"
                        ><NetworkIcon :network="n.value" size="sm" />{{ n.label }}</button>
                    </div>
                    <p class="text-xs text-slate-600">Últimos {{ result.days }} días · horas en {{ result.timezone }}</p>
                </div>

                <div v-if="!result.available" class="card p-6 text-sm text-slate-700" role="status">
                    <p class="font-semibold text-slate-900">Datos insuficientes</p>
                    <p class="mt-1">{{ result.reason }}</p>
                </div>

                <template v-else>
                    <section class="card p-5">
                        <h2 class="text-sm font-medium text-slate-700">Mejores franjas</h2>
                        <ul class="mt-2 space-y-1 text-sm text-slate-700">
                            <li v-for="t in result.top" :key="`${t.dow}-${t.hour}`">
                                {{ t.day }} a las {{ String(t.hour).padStart(2, '0') }}:00 · {{ t.avg_engagement }} interacciones de media ({{ t.posts }} publicaciones)
                            </li>
                        </ul>
                    </section>

                    <section class="card overflow-x-auto p-5" tabindex="0" role="region" aria-label="Mapa de calor desplazable">
                        <table class="w-full border-separate border-spacing-1 text-center text-xs" aria-label="Mapa de calor de engagement medio por día y hora">
                            <caption class="sr-only">Engagement medio por día de la semana y hora</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="w-16"></th>
                                    <th v-for="h in 24" :key="h" scope="col" class="font-normal text-slate-600">{{ h - 1 }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(day, d) in days_labels" :key="day">
                                    <th scope="row" class="pr-2 text-right font-medium text-slate-600">{{ day.slice(0, 3) }}</th>
                                    <td v-for="(v, h) in result.matrix[d]" :key="h" class="h-8 min-w-7 rounded" :style="shade(v)" :title="v === null ? 'Sin datos' : `${day} ${h}:00 · ${v} de media (${result.counts[d][h]} publicaciones)`">
                                        {{ v === null ? '' : Math.round(v) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-3 text-xs text-slate-600">Cada celda es el promedio de interacciones por publicación. Basado en {{ result.posts }} publicaciones.</p>
                    </section>
                </template>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
