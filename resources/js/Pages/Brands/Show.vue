<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ brand: Object });
</script>

<template>
    <Head :title="brand.name" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">{{ brand.name }}</h1>
        </template>

        <div>
            <div class="max-w-3xl space-y-3 card p-6">
                <p class="text-slate-700">{{ brand.description }}</p>
                <p class="text-sm text-slate-500">Zona horaria: {{ brand.timezone }}</p>
                <div class="space-x-4">
                    <Link :href="route('brands.posts.create', brand.id)" class="link">Nueva publicación</Link>
                    <Link :href="route('brands.connections.index', brand.id)" class="link">Conexiones</Link>
                    <Link :href="route('brands.edit', brand.id)" class="link">Editar</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
