<script setup>
/**
 * Nota informativa común (no es un error ni exige acción): tarjeta sobria con un título opcional y el texto en el slot. No usa
 * `role="alert"` (no interrumpe a los lectores de pantalla); para permisos que faltan está `PermissionNotice`.
 */
defineProps({
    title: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900" role="note" data-testid="info-note">
        <p v-if="title" class="font-medium">{{ title }}</p>
        <div :class="title ? 'mt-1' : ''"><slot /></div>
    </div>
</template>
