<script setup>
import { useNetworks } from '@/Composables/useNetworks';
import { deltaClass, fmt, fmtPct, UNAVAILABLE } from '@/Pages/Dashboard/Partials/format';

/**
 * Tarjeta KPI reutilizable: título, total grande, variación frente al periodo de comparación, desglose por red
 * en chips de color y un hueco (slot por defecto) para el gráfico.
 * `breakdown`: [{ network, label, value, available, delta_pct }]
 */
const catalog = useNetworks();

defineProps({
    label: { type: String, required: true },
    total: { type: Number, default: null },
    available: { type: Boolean, default: true },
    deltaPct: { type: Number, default: null },
    comparing: { type: Boolean, default: false },
    previous: { type: Number, default: null },
    comparisonLabel: { type: String, default: '' },
    breakdown: { type: Array, default: () => [] },
});
</script>

<template>
    <section class="card p-5">
        <header class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-medium text-slate-500">{{ label }}</h2>
                <p class="mt-0.5 font-semibold tabular-nums" :class="available ? 'text-3xl text-slate-900' : 'text-xl text-slate-500'">{{ fmt(total) }}</p>
            </div>
            <span v-if="comparing && deltaPct !== null" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="deltaClass(deltaPct)">{{ fmtPct(deltaPct) }}</span>
        </header>
        <p v-if="comparing && previous !== null" class="mt-1 text-xs text-slate-500">{{ comparisonLabel }}: {{ fmt(previous) }}</p>

        <ul v-if="breakdown.length" class="mt-3 flex flex-wrap gap-2">
            <li
                v-for="b in breakdown"
                :key="b.network"
                class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                :class="b.available ? 'border-slate-300 text-slate-800' : 'border-dashed border-slate-300 text-slate-500'"
            >
                <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: catalog.color(b.network) }"></span>
                {{ b.label }}: {{ b.available ? fmt(b.value) : UNAVAILABLE }}
                <span v-if="comparing && b.delta_pct !== null" class="rounded px-1" :class="deltaClass(b.delta_pct)">{{ fmtPct(b.delta_pct) }}</span>
            </li>
        </ul>

        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </section>
</template>
