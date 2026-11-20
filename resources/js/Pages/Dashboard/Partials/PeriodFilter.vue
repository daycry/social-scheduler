<script setup>
/** @import { BrandRef } from '@/types/shell' */
/** @import { AnalyticsFilters } from '@/types/analytics' */
import FormField from '@/Components/FormField.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * Selector de periodo y comparación («Periodo principal» + «Comparar con»). La marca la elige el selector del
 * shell; aquí solo viaja en la query. Cada cambio recarga la página con los parámetros en la URL.
 */
const props = defineProps({
    routeName: { type: /** @type {import('vue').PropType<'dashboard' | 'dashboard.all' | 'dashboard.network'>} */ (String), required: true },
    routeParams: { type: /** @type {import('vue').PropType<{ network?: string }>} */ (Object), default: () => ({}) },
    brands: { type: /** @type {import('vue').PropType<BrandRef[]>} */ (Array), default: () => [] },
    filters: { type: /** @type {import('vue').PropType<AnalyticsFilters>} */ (Object), required: true },
});

const form = reactive({ ...props.filters });
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

/** @param {Date} d */
const iso = (d) => d.toISOString().slice(0, 10);
/** @param {number} days */
const preset = (days) => {
    const to = new Date();
    to.setUTCDate(to.getUTCDate() - 1);
    const from = new Date(to);
    from.setUTCDate(from.getUTCDate() - (days - 1));
    form.from = iso(from);
    form.to = iso(to);
    apply();
};

const apply = () =>
    router.get(route(props.routeName, props.routeParams), { brand_id: form.brand_id, from: form.from, to: form.to, compare: form.compare }, { preserveScroll: true, preserveState: 'errors', replace: true });
</script>

<template>
    <div class="card flex flex-wrap items-end gap-x-4 gap-y-3 p-4">
        <div class="text-sm">
            <label for="period-from" class="block text-xs font-medium text-slate-600">Periodo principal</label>
            <span class="flex flex-wrap items-center gap-1.5">
                <FormField id="period-from" :error="errors.from" v-slot="{ control }">
                    <input
                        v-bind="control"
                        v-model="form.from"
                        type="date"
                        class="field w-auto min-w-0 py-1.5"
                        aria-label="Desde"
                        @change="apply"
                    />
                </FormField>
                <span class="text-slate-400" aria-hidden="true">–</span>
                <FormField id="period-to" :error="errors.to" v-slot="{ control }">
                    <input
                        v-bind="control"
                        v-model="form.to"
                        type="date"
                        class="field w-auto py-1.5"
                        aria-label="Hasta"
                        @change="apply"
                    />
                </FormField>
            </span>
        </div>
        <div class="flex gap-1.5 text-xs" role="group" aria-label="Periodos rápidos">
            <button
                v-for="d in [7, 30, 90]"
                :key="d"
                type="button"
                class="rounded-lg border border-slate-300 bg-white px-2.5 py-2 font-medium text-slate-700 hover:bg-slate-50"
                @click="preset(d)"
                >{{ d }} días</button
            >
        </div>
        <div class="text-sm">
            <label for="period-compare" class="block text-xs font-medium text-slate-600">Comparar con</label>
            <select id="period-compare" v-model="form.compare" class="field w-auto py-1.5" @change="apply">
                <option value="none">Sin comparación</option>
                <option value="previous">Periodo anterior</option>
                <option value="year">Mismo periodo del año anterior</option>
            </select>
        </div>
    </div>
</template>
