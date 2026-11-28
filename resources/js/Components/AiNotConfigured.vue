<script setup>
import InfoNote from '@/Components/InfoNote.vue';

/**
 * Aviso común de «IA no configurada» (estudio): una nota informativa con el motivo y que hace el administrador. Sin slot, el texto estándar
 * de los textos con IA. Los atributos (p. ej. `data-testid`) pasan a la nota.
 */
defineProps({ title: { type: String, default: '' } });
</script>

<template>
    <InfoNote :title="title">
        <slot>Textos con IA: no configurado. Requiere activar el asistente con la clave de Claude en el servidor.</slot>
    </InfoNote>
</template>
