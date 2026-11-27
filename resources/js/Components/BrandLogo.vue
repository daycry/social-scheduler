<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

/** `compact`: en pantallas muy estrechas solo el icono (el nombre vuelve desde `sm`). */
defineProps({ dark: { type: Boolean, default: false }, compact: { type: Boolean, default: false } });
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <ApplicationLogo class="h-8 w-8 shrink-0" />
        <span
            class="text-base font-bold tracking-tight"
            :class="[compact ? 'hidden sm:inline' : '', dark ? 'text-chrome-strong' : 'text-foreground-strong']"
        >
            Social<span :class="dark ? 'text-chrome-accent' : 'text-accent'"> Scheduler</span>
        </span>
    </span>
</template>
