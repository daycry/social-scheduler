<script setup>
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Selector de periodo y comparación («Periodo principal» + «Comparar con»). La marca la elige el selector del
 * shell; aquí solo viaja en la query. Cada cambio recarga la página con los parámetros en la URL.
 */
const props = defineProps({
    routeName: { type: String, required: true },
    routeParams: { type: Object, default: () => ({}) },
    brands: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
});

const form = reactive({ ...props.filters });

const iso = (d) => d.toISOString().slice(0, 10);
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
    router.get(route(props.routeName, props.routeParams), { brand_id: form.brand_id, from: form.from, to: form.to, compare: form.compare }, { preserveScroll: true, replace: true });
</script>

<template>
    <div class="card flex flex-wrap items-end gap-x-4 gap-y-3 p-4">
        <div class="text-sm">
            <label for="period-from" class="block text-xs font-medium text-slate-600">Periodo principal</label>
            <span class="flex flex-wrap items-center gap-1.5">
                <input id="period-from" v-model="form.from" type="date" class="field w-auto min-w-0 py-1.5" aria-label="Desde" @change="apply" />
                <span class="text-slate-400" aria-hidden="true">–</span>
                <input v-model="form.to" type="date" class="field w-auto py-1.5" aria-label="Hasta" @change="apply" />
            </span>
        </div>
        <div class="flex gap-1.5 text-xs" role="group" aria-label="Periodos rápidos">
            <button v-for="d in [7, 30, 90]" :key="d" type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-2 font-medium text-slate-700 hover:bg-slate-50" @click="preset(d)">{{ d }} días</button>
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
