<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';

/**
 * Layout mínimo del compositor a pantalla completa: sin navegación de la aplicación.
 * La cabecera (marca, Cerrar y acciones) llega por el slot `header`; el contenido, por el slot por defecto.
 */
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <a href="#contenido" class="sr-only z-[100] rounded bg-white px-3 py-2 text-sm font-medium text-indigo-700 focus:not-sr-only focus:fixed focus:left-2 focus:top-2">Saltar al contenido</a>

        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-sm">
            <div class="mx-auto flex min-h-14 max-w-7xl items-center gap-3 px-3 py-2 sm:px-6">
                <BrandLogo compact class="shrink-0" />
                <slot name="header" />
            </div>
        </header>

        <main id="contenido" class="mx-auto max-w-7xl px-3 py-6 sm:px-6">
            <slot />
        </main>
    </div>
</template>
