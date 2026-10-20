<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ brands: Array });

const remove = (brand) => {
    if (confirm(`¿Eliminar la marca «${brand.name}»?`)) {
        router.delete(route('brands.destroy', brand.id));
    }
};
</script>

<template>
    <Head title="Marcas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Marcas</h1>
                <Link :href="route('brands.create')" class="btn-primary">Nueva marca</Link>
            </div>
        </template>

        <EmptyState
            v-if="brands.length === 0"
            title="Crea tu primera marca"
            description="Una marca agrupa las redes sociales de un cliente o proyecto. Créala para conectar tus cuentas y empezar a publicar."
        >
            <Link :href="route('brands.create')" class="btn-primary">Crear mi primera marca</Link>
        </EmptyState>

        <div v-else class="card overflow-hidden">
            <ul class="divide-y divide-slate-200">
                <li v-for="brand in brands" :key="brand.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 font-bold text-indigo-700" aria-hidden="true">{{ brand.name.slice(0, 1).toUpperCase() }}</span>
                        <div class="min-w-0">
                            <Link :href="route('brands.show', brand.id)" class="block truncate font-medium text-slate-900 hover:text-indigo-700">{{ brand.name }}</Link>
                            <p class="text-sm text-slate-500">{{ brand.timezone }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <Link :href="route('brands.connections.index', brand.id)" class="link">Conexiones</Link>
                        <Link :href="route('brands.edit', brand.id)" class="link">Editar</Link>
                        <button type="button" class="rounded font-medium text-red-600 hover:underline" @click="remove(brand)">Eliminar</button>
                    </div>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
