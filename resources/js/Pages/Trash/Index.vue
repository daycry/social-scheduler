<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

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
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Papelera</h2>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>

            <section class="card">
                <h3 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Se eliminan para siempre a los {{ retention_days }} días</h3>
                <p v-if="posts.length === 0" class="p-5 text-sm text-slate-500">La papelera está vacía.</p>
                <ul v-else class="divide-y">
                    <li v-for="p in posts" :key="p.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="line-clamp-2 font-medium text-slate-900">{{ p.text || '(sin texto)' }}</p>
                            <p class="text-xs text-slate-500">{{ p.brand }} · {{ p.networks.join(', ') || 'sin redes' }} · borrada el {{ when(p.deleted_at) }} · quedan {{ p.days_left }} días</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn btn-secondary" @click="restore(p)">Restaurar</button>
                            <button type="button" class="text-xs text-red-600 underline" @click="destroy(p)">Eliminar para siempre</button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
