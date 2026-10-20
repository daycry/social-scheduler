<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-2">
        <!-- Panel de marca (solo escritorio) -->
        <aside class="relative hidden overflow-hidden bg-night-900 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-indigo-500/30 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-teal-500/20 blur-3xl"></div>
            <Link href="/" class="relative z-10 w-fit" aria-label="Social Scheduler, inicio"><BrandLogo dark /></Link>
            <div class="relative z-10 max-w-md">
                <h2 class="text-3xl font-bold leading-tight">Programa, publica y mide tus redes desde un solo sitio.</h2>
                <p class="mt-4 text-slate-300">Instagram, Facebook, YouTube y TikTok con calendario, analítica por red, informes y un agente MCP para automatizarlo.</p>
                <ul class="mt-8 space-y-3 text-sm text-slate-300">
                    <li class="flex items-center gap-3"><span class="h-2 w-2 rounded-full bg-teal-400"></span>Calendario con arrastrar y soltar</li>
                    <li class="flex items-center gap-3"><span class="h-2 w-2 rounded-full bg-indigo-400"></span>Estadísticas con comparación de periodos</li>
                    <li class="flex items-center gap-3"><span class="h-2 w-2 rounded-full bg-sky-400"></span>Informes PDF y CSV por marca</li>
                </ul>
            </div>
            <p class="relative z-10 text-xs text-slate-500">© {{ new Date().getFullYear() }} Social Scheduler</p>
        </aside>

        <main class="flex flex-col items-center justify-center bg-slate-50 px-4 py-10 sm:px-6">
            <Link href="/" class="mb-8 lg:hidden" aria-label="Social Scheduler, inicio"><BrandLogo /></Link>
            <div class="card w-full max-w-md p-6 sm:p-8">
                <slot />
            </div>
            <p class="mt-6 text-xs text-slate-500">
                <a href="/privacy" class="link">Privacidad</a> · <a href="/terms" class="link">Condiciones</a>
            </p>
        </main>
    </div>
</template>
