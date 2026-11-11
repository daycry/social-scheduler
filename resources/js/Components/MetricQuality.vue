<script setup>
import { computed } from 'vue';
import { metricQuality } from '@/Pages/Dashboard/Partials/quality';
const props = defineProps({ coverage: Object, freshness: Object, comparison: Object, compact: Boolean });
const quality = computed(() => metricQuality(props.coverage, props.freshness));
const comparisonMessage = computed(() => ({
    unequal_coverage: 'Comparación omitida: faltan observaciones equivalentes en los dos periodos.',
    matched_partial: 'Comparación sobre muestras parciales compatibles; no representa todos los días y cuentas del periodo.',
    not_due: 'La comparación requiere días cerrados en ambos periodos.',
})[props.comparison?.status] ?? null);
</script>

<template>
    <div v-if="quality" class="mt-2 text-xs leading-relaxed" :class="quality.warning ? 'text-amber-800' : 'text-slate-500'" data-testid="metric-quality">
        <details v-if="compact">
            <summary class="cursor-pointer rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">{{ quality.label }}</summary>
            <p class="mt-1 max-w-md">{{ quality.detail }}</p>
        </details>
        <template v-else>
            <p class="font-medium">{{ quality.label }}</p>
            <p>{{ quality.detail }}</p>
        </template>
        <p v-if="comparisonMessage" class="mt-1 text-amber-800">{{ comparisonMessage }}</p>
    </div>
</template>
