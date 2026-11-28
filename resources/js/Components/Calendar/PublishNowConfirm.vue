<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';

defineProps({
    item: { type: /** @type {import('vue').PropType<import('@/types/calendar').CalendarItem>} */ (Object), required: true },
    opener: { type: /** @type {import('vue').PropType<HTMLElement | SVGElement | null>} */ (Object), default: null },
});
const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <BaseDialog
        open
        :return-focus-to="opener"
        role="alertdialog"
        aria-labelledby="publish-now-title"
        aria-describedby="publish-now-desc"
        class="m-auto w-[calc(100vw_-_2rem)] max-w-md rounded-lg border-0 bg-surface p-5 shadow-xl backdrop:bg-overlay-strong/50"
        data-testid="publish-now-confirm"
        @close="emit('cancel')"
    >
        <h2 id="publish-now-title" class="text-lg font-semibold text-foreground-strong">¿Publicar ahora?</h2>
        <p id="publish-now-desc" class="mt-2 break-words text-sm text-foreground-secondary">
            «{{ item.text || 'Sin texto' }}» se enviará en unos segundos a
            {{ item.targets.map((t) => t.network_label).join(', ') }}, sin esperar a su hora programada. No se puede
            deshacer.
        </p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button data-dialog-initial-focus type="button" class="btn btn-secondary" @click="emit('cancel')"
                >Cancelar</button
            >
            <button type="button" class="btn btn-primary" @click="emit('confirm', item)">Publicar ahora</button>
        </div>
    </BaseDialog>
</template>
