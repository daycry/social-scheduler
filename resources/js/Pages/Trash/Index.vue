<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import { Head, Link, router } from '@inertiajs/vue3';

/** Papelera (PA-06): publicaciones borradas; se pueden restaurar o eliminar para siempre. Se purgan solas pasados 30 días. */
defineProps({
    posts: Array,
    retention_days: Number,
    flash: Object,
});

const restore = (p) => router.post(route('trash.restore', p.id), {}, { preserveScroll: true });
const destroy = (p) => {
    if (confirm('¿Eliminar esta publicación para siempre? No se puede deshacer.')) router.delete(route('trash.destroy', p.id), { preserveScroll: true });
};
const when = (iso) => (iso ? new Date(iso).toLocaleDateString('es-ES') : '');
</script>

<template>
    <Head title="Papelera" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Papelera</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>

            <FeatureEmpty
                v-if="posts.length === 0"
                title="La papelera está vacía"
                :description="`Las publicaciones que borres desde el calendario o el editor aparecerán aquí y se eliminan para siempre a los ${retention_days} días.`"
                :cards="[
                    { title: 'Restaura cuando quieras', text: 'Recupera una publicación borrada por error; si ya venció vuelve como borrador.', kind: 'calendar' },
                    { title: 'Limpieza automática', text: `Pasados ${retention_days} días se eliminan solas, junto con su contenido.`, kind: 'report' },
                    { title: 'Siempre bajo control', text: 'También puedes eliminarlas para siempre manualmente.', kind: 'chart' },
                ]"
            >
                <template #cta><Link :href="route('calendar.index')" class="btn-secondary">Ir al calendario</Link></template>
            </FeatureEmpty>

            <section v-else class="card">
                <h2 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Se eliminan para siempre a los {{ retention_days }} días</h2>
                <ul class="divide-y">
                    <li v-for="p in posts" :key="p.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="line-clamp-2 font-medium text-slate-900">{{ p.text || '(sin texto)' }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <NetworkChip v-for="n in p.networks" :key="n" :network="n" />
                                <span class="text-xs text-slate-500">{{ p.brand }} · borrada el {{ when(p.deleted_at) }} · quedan {{ p.days_left }} días</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="restore(p)">Restaurar</button>
                            <button type="button" class="text-xs font-medium text-red-700 underline" @click="destroy(p)">Eliminar para siempre</button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
