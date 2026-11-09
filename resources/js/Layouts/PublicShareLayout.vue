<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-800">
        <a href="#contenido-compartido" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2">Saltar al contenido</a>
        <header class="border-b border-slate-200 bg-white px-4 py-3 sm:px-6"><BrandLogo /></header>
        <main id="contenido-compartido" class="mx-auto max-w-[1400px] px-3 py-5 sm:px-6">
            <slot />
        </main>
    </div>
</template>
