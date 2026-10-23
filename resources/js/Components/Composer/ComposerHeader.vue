<script setup>
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, post } = useComposerContext();
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-3">
        <h1 class="min-w-0 flex-1 truncate text-base font-bold text-slate-900 sm:text-lg">
            {{ post ? 'Editar publicación' : 'Nueva publicación' }} · {{ brand.name }}
        </h1>
        <!-- Hueco para las notas y las acciones de las fases siguientes -->
        <slot />
        <Link :href="route('brands.show', brand.id)" class="btn-secondary shrink-0" data-testid="composer-close">Cerrar</Link>
    </div>
</template>
