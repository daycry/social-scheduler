<script setup>
import FlashBanner from '@/Components/FlashBanner.vue';
import BrandLogo from '@/Components/BrandLogo.vue';
import GlobalActions from '@/Components/GlobalActions.vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

/**
 * Layout mínimo del compositor a pantalla completa: sin navegación de la aplicación, pero con la paleta de búsqueda (Ctrl+K). Sus
 * navegaciones pasan por el router, así que la protección de cambios sin guardar (`UnsavedGuard`) las intercepta como cualquier otra.
 * La cabecera (marca, Cerrar y acciones) llega por el slot `header`; el contenido, por el slot por defecto.
 */
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <a
            href="#contenido"
            class="sr-only z-[100] rounded bg-white px-3 py-2 text-sm font-medium text-indigo-700 focus:not-sr-only focus:fixed focus:left-2 focus:top-2"
            >Saltar al contenido</a
        >

        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-sm">
            <div class="mx-auto flex min-h-14 max-w-7xl items-center gap-3 px-3 py-2 sm:px-6">
                <BrandLogo compact class="shrink-0" />
                <GlobalActions light />
                <slot name="header" />
            </div>
        </header>

        <main id="contenido" class="mx-auto max-w-7xl px-3 py-6 sm:px-6">
            <FlashBanner
                :success="page.flash?.success ?? page.props.flash?.success"
                :error="page.flash?.error ?? page.props.flash?.error"
                class="mb-4"
                data-testid="flash"
            />
            <slot />
        </main>
    </div>
</template>
