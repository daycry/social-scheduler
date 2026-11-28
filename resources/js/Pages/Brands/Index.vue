<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

defineProps({ brands: Array });

const remove = async (brand) => {
    if (await ask({ message: `¿Eliminar la marca «${brand.name}»?`, confirmLabel: 'Eliminar marca' })) {
        router.delete(route('brands.destroy', brand.id));
    }
};
</script>

<template>
    <Head title="Marcas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-foreground-strong">Marcas</h1>
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
            <ul class="divide-y divide-border">
                <li
                    v-for="brand in brands"
                    :key="brand.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            loading="lazy"
                            v-if="brand.avatar_url"
                            :src="brand.avatar_url"
                            alt=""
                            class="h-10 w-10 shrink-0 rounded-lg object-cover"
                        />
                        <span
                            v-else
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-marker font-bold text-accent-strong"
                            aria-hidden="true"
                            >{{ brand.name.slice(0, 1).toUpperCase() }}</span
                        >
                        <div class="min-w-0">
                            <Link
                                :href="route('brands.show', brand.id)"
                                class="block truncate font-medium text-foreground-strong hover:text-accent-strong"
                                >{{ brand.name }}</Link
                            >
                            <p class="text-sm text-foreground-subtle">{{ brand.timezone }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <Link :href="route('brands.connections.index', brand.id)" class="link">Conexiones</Link>
                        <Link :href="route('brands.edit', brand.id)" class="link">Editar</Link>
                        <button
                            type="button"
                            class="rounded font-medium text-danger hover:underline"
                            @click="remove(brand)"
                            >Eliminar</button
                        >
                    </div>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
