<script setup>
import { computed } from 'vue';

/** Insignia de estado de publicación / cuenta con color semántico. */
const props = defineProps({ status: { type: String, required: true }, label: { type: String, default: null } });

/** @type {Partial<Record<string, string>>} */
const STYLES = {
    borrador: 'bg-surface-hover text-foreground-secondary ring-border',
    programada: 'bg-info-soft text-info-ink ring-info-border',
    publicando: 'bg-warning-soft text-warning-ink ring-warning-border',
    procesando: 'bg-warning-soft text-warning-ink ring-warning-border',
    publicada: 'bg-success-soft text-success-ink ring-success-border',
    fallida: 'bg-danger-soft text-danger-ink ring-danger-border',
    activa: 'bg-success-soft text-success-ink ring-success-border',
    requiere_reconexion: 'bg-warning-soft text-warning-ink ring-warning-border',
    desconectada: 'bg-surface-hover text-foreground-muted ring-border',
};
const classes = computed(() => STYLES[props.status] ?? 'bg-surface-hover text-foreground-secondary ring-border');
const text = computed(() => props.label ?? props.status.replaceAll('_', ' '));
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
        :class="classes"
        >{{ text }}</span
    >
</template>
