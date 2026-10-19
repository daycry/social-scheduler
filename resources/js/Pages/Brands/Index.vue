<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ brands: Array });

const remove = (brand) => {
    if (confirm(`Eliminar la marca "${brand.name}"?`)) {
        router.delete(route('brands.destroy', brand.id));
    }
};
</script>

<template>
    <Head title="Marcas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Marcas</h2>
                <Link :href="route('brands.create')">
                    <PrimaryButton>Nueva marca</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <p v-if="brands.length === 0" class="p-6 text-gray-600">
                        Aun no tienes marcas. Crea la primera para conectar tus redes.
                    </p>
                    <ul v-else class="divide-y divide-gray-200">
                        <li v-for="brand in brands" :key="brand.id" class="flex items-center justify-between p-4">
                            <div>
                                <Link :href="route('brands.show', brand.id)" class="font-medium text-gray-900">
                                    {{ brand.name }}
                                </Link>
                                <p class="text-sm text-gray-500">{{ brand.timezone }}</p>
                            </div>
                            <div class="space-x-4 text-sm">
                                <Link :href="route('brands.edit', brand.id)" class="text-indigo-600">Editar</Link>
                                <button type="button" class="text-red-600" @click="remove(brand)">Eliminar</button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
