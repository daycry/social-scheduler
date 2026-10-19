<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Informes (T-40, inspirado en Reporting de Metricool): elegir marca y rango, generar PDF y/o CSV en segundo plano,
 * ver el estado y descargar (al pulsar se genera un enlace firmado nuevo de unos minutos). Mientras haya informes en curso la lista se refresca sola.
 */
const props = defineProps({
    brands: Array,
    reports: Array,
    defaults: Object,
    polling: Boolean,
    flash: Object,
});

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    from: props.defaults.from,
    to: props.defaults.to,
    compare: props.defaults.compare,
    formats: ['pdf', 'csv'],
});

const submit = () => form.post(route('reports.store'), { preserveScroll: true });

const STATUS = {
    pending: ['En cola', 'bg-gray-100 text-gray-700'],
    processing: ['Generando...', 'bg-blue-100 text-blue-700'],
    ready: ['Listo', 'bg-green-100 text-green-700'],
    failed: ['Error', 'bg-red-100 text-red-700'],
};

let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (props.polling) router.reload({ only: ['reports', 'polling'], preserveScroll: true });
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));

const remove = (r) => {
    if (confirm(`Eliminar el informe de ${r.brand} (${r.format.toUpperCase()}, ${r.from} - ${r.to})? Tambien se borra su fichero.`)) {
        router.delete(route('reports.destroy', r.id), { preserveScroll: true });
    }
};

const size = (b) => (b ? (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`) : '');
</script>

<template>
    <Head title="Informes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Informes</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="brands.length === 0" class="rounded-lg bg-white p-6 text-gray-700 shadow-sm">
                    Aun no tienes marcas.
                    <Link :href="route('brands.create')" class="text-indigo-600 underline">Crea una marca</Link> para generar informes.
                </div>

                <form v-else class="space-y-4 rounded-lg bg-white p-5 shadow-sm" @submit.prevent="submit">
                    <div class="flex flex-wrap items-end gap-4">
                        <label class="text-sm">
                            <span class="block text-xs font-medium text-gray-500">Marca</span>
                            <select v-model="form.brand_id" class="rounded-md border-gray-300 text-sm">
                                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="block text-xs font-medium text-gray-500">Desde</span>
                            <input v-model="form.from" type="date" class="rounded-md border-gray-300 text-sm" />
                        </label>
                        <label class="text-sm">
                            <span class="block text-xs font-medium text-gray-500">Hasta</span>
                            <input v-model="form.to" type="date" class="rounded-md border-gray-300 text-sm" />
                        </label>
                        <label class="text-sm">
                            <span class="block text-xs font-medium text-gray-500">Comparar con</span>
                            <select v-model="form.compare" class="rounded-md border-gray-300 text-sm">
                                <option value="none">Sin comparacion</option>
                                <option value="previous">Periodo anterior</option>
                                <option value="year">Mismo periodo del ano anterior</option>
                            </select>
                        </label>
                        <fieldset class="flex gap-3 text-sm">
                            <legend class="mb-1 block text-xs font-medium text-gray-500">Formato</legend>
                            <label class="flex items-center gap-1"><input v-model="form.formats" type="checkbox" value="pdf" class="rounded border-gray-300" /> PDF</label>
                            <label class="flex items-center gap-1"><input v-model="form.formats" type="checkbox" value="csv" class="rounded border-gray-300" /> CSV</label>
                        </fieldset>
                        <PrimaryButton :disabled="form.processing || form.formats.length === 0">Generar informe</PrimaryButton>
                    </div>
                    <InputError :message="form.errors.from || form.errors.to || form.errors.formats || form.errors.brand_id" />
                    <p v-if="flash?.success" class="text-sm text-green-700">{{ flash.success }}</p>
                </form>

                <section class="rounded-lg bg-white shadow-sm">
                    <h3 class="border-b px-5 py-3 text-sm font-medium text-gray-700">Informes recientes</h3>
                    <p v-if="reports.length === 0" class="p-5 text-sm text-gray-500">Aun no has generado informes.</p>
                    <ul v-else class="divide-y">
                        <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm" :data-report="r.id">
                            <div>
                                <p class="font-medium text-gray-900">{{ r.brand }} · {{ r.format.toUpperCase() }}</p>
                                <p class="text-xs text-gray-500">{{ r.from }} - {{ r.to }} <span v-if="r.size">· {{ size(r.size) }}</span></p>
                                <p v-if="r.status === 'failed' && r.error" class="text-xs text-red-600">{{ r.error }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}</span>
                                <a v-if="r.download_url" :href="r.download_url" class="text-indigo-600 underline">Descargar</a>
                                <button v-if="r.deletable" type="button" class="text-xs text-red-600 underline" @click="remove(r)">Eliminar</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
