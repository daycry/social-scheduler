<script setup>
import { computed } from 'vue';
import { NETWORK_COLORS } from '@/Pages/Dashboard/Partials/format';

/** Icono de red: cuadrado con el color oficial de la red y un glifo simple propio. */
const props = defineProps({
    network: { type: String, required: true },
    size: { type: String, default: 'md' },
});

const color = computed(() => NETWORK_COLORS[props.network] ?? '#64748b');
const dim = computed(() => ({ sm: 'h-5 w-5', md: 'h-7 w-7', lg: 'h-10 w-10' })[props.size] ?? 'h-7 w-7');
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center rounded-lg text-white" :class="dim" :style="{ backgroundColor: color }" aria-hidden="true">
        <svg viewBox="0 0 24 24" class="h-[62%] w-[62%]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <template v-if="network === 'instagram'">
                <rect x="4" y="4" width="16" height="16" rx="5" />
                <circle cx="12" cy="12" r="3.6" />
                <circle cx="17" cy="7" r="0.6" fill="currentColor" />
            </template>
            <path v-else-if="network === 'facebook'" d="M14 8h2V4.5h-2.4C11 4.5 10 6 10 8v2H8v3.5h2V20h3.5v-6.5H16l.5-3.5h-3V8.6c0-.4.2-.6.5-.6z" fill="currentColor" stroke="none" />
            <template v-else-if="network === 'youtube'">
                <rect x="3" y="6" width="18" height="12" rx="4" />
                <path d="M10.5 9.5v5l4.2-2.5z" fill="currentColor" />
            </template>
            <path v-else-if="network === 'tiktok'" d="M14 4v10.2a3.2 3.2 0 11-3.2-3.2M14 4c.4 2.4 1.9 3.9 4.5 4.1" />
            <circle v-else cx="12" cy="12" r="6" />
        </svg>
    </span>
</template>
