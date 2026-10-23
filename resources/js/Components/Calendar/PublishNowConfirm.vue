<script setup>
import { nextTick, onMounted, ref } from 'vue';

/**
 * Confirmación de «Publicar ahora» (calendario y lista). Diálogo modal accesible: el foco entra en «Cancelar» (la opción segura),
 * el Tab no sale del diálogo, Escape cancela y el foco vuelve al botón que lo abrió.
 */
const props = defineProps({
    item: { type: Object, required: true },
    opener: { type: Object, default: null },
});
const emit = defineEmits(['confirm', 'cancel']);

const dialog = ref(null);
const cancelButton = ref(null);

const cancel = () => {
    emit('cancel');
    nextTick(() => props.opener?.focus?.());
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
    } else if (e.key === 'Tab') {
        const buttons = [...dialog.value.querySelectorAll('button')];
        if (e.shiftKey && document.activeElement === buttons[0]) { e.preventDefault(); buttons[buttons.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === buttons[buttons.length - 1]) { e.preventDefault(); buttons[0].focus(); }
    }
};
onMounted(() => cancelButton.value?.focus());
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4" data-testid="publish-now-confirm">
        <div ref="dialog" role="alertdialog" aria-modal="true" aria-labelledby="publish-now-title" aria-describedby="publish-now-desc" class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl" @keydown="onKeydown">
            <h2 id="publish-now-title" class="text-lg font-semibold text-slate-900">¿Publicar ahora?</h2>
            <p id="publish-now-desc" class="mt-2 break-words text-sm text-slate-700">
                «{{ item.text || 'Sin texto' }}» se enviará en unos segundos a {{ item.targets.map((t) => t.network_label).join(', ') }}, sin esperar a su hora programada. No se puede deshacer.
            </p>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button ref="cancelButton" type="button" class="btn btn-secondary" @click="cancel">Cancelar</button>
                <button type="button" class="btn btn-primary" @click="emit('confirm', item)">Publicar ahora</button>
            </div>
        </div>
    </div>
</template>
