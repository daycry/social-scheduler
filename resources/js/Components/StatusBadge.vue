<script setup>
import { computed } from 'vue';

/** Insignia de estado de publicación / cuenta con color semántico. */
const props = defineProps({ status: { type: String, required: true }, label: { type: String, default: null } });

const STYLES = {
    borrador: 'bg-slate-100 text-slate-700 ring-slate-200',
    programada: 'bg-sky-50 text-sky-800 ring-sky-200',
    publicando: 'bg-amber-50 text-amber-800 ring-amber-200',
    procesando: 'bg-amber-50 text-amber-800 ring-amber-200',
    publicada: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    fallida: 'bg-red-50 text-red-800 ring-red-200',
    activa: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    requiere_reconexion: 'bg-amber-50 text-amber-800 ring-amber-200',
    desconectada: 'bg-slate-100 text-slate-600 ring-slate-200',
};
const classes = computed(() => STYLES[props.status] ?? 'bg-slate-100 text-slate-700 ring-slate-200');
const text = computed(() => props.label ?? props.status.replaceAll('_', ' '));
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset" :class="classes">{{ text }}</span>
</template>
