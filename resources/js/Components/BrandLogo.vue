<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({ dark: { type: Boolean, default: false } });
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <ApplicationLogo class="h-8 w-8 shrink-0" />
        <span class="text-base font-bold tracking-tight" :class="dark ? 'text-white' : 'text-slate-900'">
            Social<span :class="dark ? 'text-indigo-300' : 'text-indigo-600'"> Scheduler</span>
        </span>
    </span>
</template>
