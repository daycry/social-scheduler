<script setup>
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { createDateFormatter } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Reciclado inteligente (T-92): los posts publicados de la marca con mejor engagement (interacciones / visualizaciones) y métricas
 * suficientes, fuera del intervalo mínimo desde su última publicación o reciclado. «Reciclar» crea un borrador: nada se publica solo.
 */
const props = defineProps({
    brand: Object,
    proposals: Array,
    total: Number,
    not_analyzed: Number,
    excluded: Array,
    rules: Object,
});

const fmtDate = computed(() => createDateFormatter(props.brand?.timezone, { dateStyle: 'medium' }, ''));
const number = new Intl.NumberFormat('es-ES');
const percent = (v) => (v === null || v === undefined ? 'n/d' : `${v.toLocaleString('es-ES', { maximumFractionDigits: 2 })} %`);
const label = (p) => p.text || 'publicación sin texto';

const busy = ref(null);
const send = (name, p) => {
    busy.value = p.post_id;
    router.post(route(name, p.post_id), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
};
</script>

<template>
    <Head title="Reciclado de posts" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Reciclado de posts</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Reciclado de posts"
                description="Crea una marca para descubrir qué publicaciones merece la pena volver a publicar."
                :cards="[
                    { title: 'Lo que mejor funcionó', text: 'Ordenado por engagement, con tus propias métricas.', kind: 'chart' },
                    { title: 'Sin repetir de más', text: 'Un intervalo mínimo entre repeticiones del mismo contenido.', kind: 'calendar' },
                    { title: 'Solo un borrador', text: 'Nada se publica sin que lo revises.', kind: 'report' },
                ]"
            />

            <template v-else>
                <section class="card space-y-2 p-5" aria-labelledby="recycle-rules">
                    <h2 id="recycle-rules" class="text-sm font-medium text-slate-700"
                        >Cómo se eligen · {{ brand.name }}</h2
                    >
                    <p class="text-sm text-slate-600">
                        Se proponen publicaciones con al menos {{ number.format(rules.min_views) }} visualizaciones,
                        publicadas hace más de {{ rules.min_age_days }} días (para que sus métricas hayan madurado) y
                        que no se hayan publicado ni reciclado en los últimos {{ rules.min_interval_days }} días. Se
                        ordenan por engagement, es decir, interacciones entre visualizaciones, que se puede comparar
                        entre redes.
                    </p>
                    <p class="text-sm text-slate-600">
                        «Reciclar» crea un borrador con el mismo texto y archivos para que lo revises: no se publica ni
                        se programa solo.
                        <Link :href="route('autolists.index', { brand_id: brand.id })" class="underline"
                            >Ir a las autolistas</Link
                        >
                    </p>
                </section>

                <section class="space-y-3" aria-labelledby="recycle-proposals">
                    <h2 id="recycle-proposals" class="text-sm font-medium text-slate-700">Propuestas</h2>

                    <div v-if="proposals.length === 0" class="card p-5 text-sm text-slate-600">
                        <p class="font-medium text-slate-800">Ahora mismo no hay publicaciones para reciclar.</p>
                        <p class="mt-1">
                            Aparecerán cuando haya publicaciones con métricas suficientes, maduras y fuera del intervalo
                            mínimo. Revisa abajo por qué se han quedado fuera.
                        </p>
                    </div>

                    <ul v-else class="space-y-3" aria-label="Publicaciones propuestas para reciclar">
                        <li
                            v-for="p in proposals"
                            :key="p.post_id"
                            class="card space-y-3 p-5"
                            data-testid="recycle-proposal"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-sm font-medium text-slate-900">
                                        {{ p.text || '(solo archivos)' }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Publicada el {{ fmtDate(p.published_at) }}
                                        <template v-if="p.media_count > 0">
                                            · {{ p.media_count }} archivo{{ p.media_count === 1 ? '' : 's' }}</template
                                        >
                                        <template v-if="p.recycled_times > 0">
                                            · reciclada {{ p.recycled_times }}
                                            {{ p.recycled_times === 1 ? 'vez' : 'veces' }}</template
                                        >
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        :disabled="busy === p.post_id"
                                        :aria-label="`Reciclar: ${label(p)}`"
                                        @click="send('autolists.recycle.store', p)"
                                        >Reciclar</button
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        :disabled="busy === p.post_id"
                                        :aria-label="`Descartar: ${label(p)}`"
                                        @click="send('autolists.recycle.dismiss', p)"
                                        >Descartar</button
                                    >
                                </div>
                            </div>

                            <dl class="grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs text-slate-500">Visualizaciones</dt>
                                    <dd class="font-semibold text-slate-900">{{ number.format(p.views) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Interacciones</dt>
                                    <dd class="font-semibold text-slate-900">{{ number.format(p.interactions) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Engagement</dt>
                                    <dd class="font-semibold text-emerald-700">{{ percent(p.engagement_rate) }}</dd>
                                </div>
                            </dl>

                            <ul
                                class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500"
                                aria-label="Destinos con métricas"
                            >
                                <li v-for="t in p.targets" :key="t.id" class="flex items-center gap-1">
                                    <NetworkIcon :network="t.network" size="sm" />
                                    <span class="break-words"
                                        >{{ t.account ?? t.network }} · {{ percent(t.engagement_rate) }}</span
                                    >
                                </li>
                            </ul>
                        </li>
                    </ul>
                    <p v-if="total > proposals.length" class="text-xs text-slate-500">
                        Se muestran las {{ proposals.length }} mejores de {{ total }}.
                    </p>
                </section>

                <p v-if="not_analyzed > 0" class="text-xs text-slate-500">
                    Se han analizado los destinos publicados más recientes; {{ not_analyzed }}
                    {{ not_analyzed === 1 ? 'destino más antiguo' : 'destinos más antiguos' }} quedan sin analizar.
                </p>

                <section v-if="excluded.length" class="card p-5" aria-labelledby="recycle-excluded">
                    <h2 id="recycle-excluded" class="text-sm font-medium text-slate-700">Fuera de las propuestas</h2>
                    <ul class="mt-2 space-y-1 text-sm text-slate-600">
                        <li v-for="e in excluded" :key="e.reason">
                            <span class="font-semibold text-slate-800">{{ e.count }}</span> · {{ e.label }}
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
