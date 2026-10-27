<script setup>
import { computed } from 'vue';
import { useNetworks } from '@/Composables/useNetworks';

/** Icono de red: cuadrado con el color oficial de la red y un glifo simple propio. */
const props = defineProps({
    network: { type: String, required: true },
    size: { type: String, default: 'md' },
    // Solo para paginas sin sesion (portada), donde no hay `shell`: el color llega por prop.
    color: { type: String, default: null },
});

const networks = useNetworks();
const bg = computed(() => props.color ?? networks.color(props.network));
const dim = computed(() => ({ sm: 'h-5 w-5', md: 'h-7 w-7', lg: 'h-10 w-10' })[props.size] ?? 'h-7 w-7');
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center rounded-lg text-white" :class="dim" :style="{ backgroundColor: bg }" aria-hidden="true">
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
            <path v-else-if="network === 'bluesky'" d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8Z" fill="currentColor" stroke="none" />
            <circle v-else cx="12" cy="12" r="6" />
        </svg>
    </span>
</template>
