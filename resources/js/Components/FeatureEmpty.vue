<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';

/**
 * Estado vacío de módulo reutilizable: banner con título + descripción + CTA (slot `cta`) y tres tarjetas de
 * valor con ilustración CSS propia. `cards`: [{ title, text, kind: 'chart' | 'calendar' | 'report' | 'inbox' | 'link' }]
 * `network`: valor de red opcional para mostrar su icono en el banner.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    network: { type: String, default: null },
    cards: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="space-y-6" data-testid="feature-empty">
        <section class="card flex flex-col items-start gap-5 overflow-hidden bg-gradient-to-br from-white to-indigo-50 p-6 sm:flex-row sm:items-center sm:p-8">
            <NetworkIcon v-if="network" :network="network" size="lg" />
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-bold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-sm text-slate-600">{{ description }}</p>
            </div>
            <div v-if="$slots.cta" class="shrink-0"><slot name="cta" /></div>
        </section>

        <div v-if="cards.length" class="grid gap-4 md:grid-cols-3">
            <article v-for="c in cards" :key="c.title" class="card p-5">
                <div class="mb-4 flex h-24 items-end gap-2 rounded-lg bg-slate-50 p-3" aria-hidden="true">
                    <template v-if="c.kind === 'chart'">
                        <span v-for="h in [30, 45, 38, 60, 52, 78, 90]" :key="h" class="flex-1 rounded-t bg-gradient-to-t from-indigo-500 to-teal-400" :style="{ height: h + '%' }"></span>
                    </template>
                    <template v-else-if="c.kind === 'calendar'">
                        <div class="grid h-full w-full grid-cols-4 gap-1.5">
                            <span v-for="i in 8" :key="i" class="rounded" :class="[2, 5, 6].includes(i) ? 'bg-indigo-400' : 'bg-slate-200'"></span>
                        </div>
                    </template>
                    <template v-else-if="c.kind === 'inbox'">
                        <div class="flex h-full w-full flex-col justify-center gap-2">
                            <span class="h-3 w-3/4 rounded-full rounded-bl-none bg-indigo-300"></span>
                            <span class="ms-auto h-3 w-2/3 rounded-full rounded-br-none bg-teal-300"></span>
                            <span class="h-3 w-1/2 rounded-full rounded-bl-none bg-slate-300"></span>
                        </div>
                    </template>
                    <template v-else-if="c.kind === 'link'">
                        <div class="flex h-full w-full items-center justify-center gap-2">
                            <span class="h-8 w-8 rounded-full bg-indigo-300"></span>
                            <span class="h-1.5 w-8 rounded bg-slate-300"></span>
                            <span class="h-8 w-8 rounded-lg bg-teal-300"></span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex h-full w-full flex-col justify-center gap-2">
                            <span class="h-2 w-2/3 rounded bg-slate-300"></span>
                            <span class="h-2 w-full rounded bg-slate-200"></span>
                            <span class="h-2 w-5/6 rounded bg-slate-200"></span>
                            <span class="h-2 w-1/3 rounded bg-teal-400"></span>
                        </div>
                    </template>
                </div>
                <h3 class="font-semibold text-slate-900">{{ c.title }}</h3>
                <p class="mt-1 text-sm text-slate-600">{{ c.text }}</p>
            </article>
        </div>
    </div>
</template>
