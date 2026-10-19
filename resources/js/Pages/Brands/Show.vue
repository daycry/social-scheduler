<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ brand: Object });
</script>

<template>
    <Head :title="brand.name" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ brand.name }}</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-2 bg-white p-6 shadow-sm sm:rounded-lg">
                <p class="text-gray-700">{{ brand.description }}</p>
                <p class="text-sm text-gray-500">Zona horaria: {{ brand.timezone }}</p>
                <div class="space-x-4">
                    <Link :href="route('brands.posts.create', brand.id)" class="text-indigo-600">Nueva publicación</Link>
                    <Link :href="route('brands.connections.index', brand.id)" class="text-indigo-600">Conexiones</Link>
                    <Link :href="route('brands.edit', brand.id)" class="text-indigo-600">Editar</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
