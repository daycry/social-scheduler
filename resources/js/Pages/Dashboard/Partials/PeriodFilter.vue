<script setup>
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Selector global de marca, periodo y comparacion (ui-reference.md: «Main period» + «Comparison period»).
 * Cada cambio recarga la pagina con los parametros en la query (la URL es compartible).
 */
const props = defineProps({
    routeName: { type: String, required: true },
    routeParams: { type: Object, default: () => ({}) },
    brands: { type: Array, required: true },
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

const changeBrand = () => apply();
</script>

<template>
    <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
        <label class="text-sm">
            <span class="block text-xs font-medium text-gray-500">Marca</span>
            <select v-model="form.brand_id" class="rounded-md border-gray-300 text-sm" @change="changeBrand">
                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-xs font-medium text-gray-500">Periodo principal</span>
            <span class="flex items-center gap-1">
                <input v-model="form.from" type="date" class="rounded-md border-gray-300 text-sm" @change="apply" />
                <span class="text-gray-400">-</span>
                <input v-model="form.to" type="date" class="rounded-md border-gray-300 text-sm" @change="apply" />
            </span>
        </label>
        <div class="flex gap-1 text-xs">
            <button v-for="d in [7, 30, 90]" :key="d" type="button" class="rounded border border-gray-300 px-2 py-1.5 hover:bg-gray-50" @click="preset(d)">{{ d }} d</button>
        </div>
        <label class="text-sm">
            <span class="block text-xs font-medium text-gray-500">Comparar con</span>
            <select v-model="form.compare" class="rounded-md border-gray-300 text-sm" @change="apply">
                <option value="none">Sin comparacion</option>
                <option value="previous">Periodo anterior</option>
                <option value="year">Mismo periodo del ano anterior</option>
            </select>
        </label>
    </div>
</template>
