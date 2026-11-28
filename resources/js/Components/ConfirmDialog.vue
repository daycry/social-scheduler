<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';
import { inject } from 'vue';
import { confirmKey } from '@/Composables/useConfirm';

const state = inject(confirmKey);
if (!state) throw new Error('Falta el diálogo de confirmación de la aplicación.');
const { pending } = state;
</script>

<template>
    <BaseDialog
        v-if="pending"
        :key="pending.id"
        open
        role="alertdialog"
        aria-labelledby="confirm-title"
        aria-describedby="confirm-message"
        class="dialog-panel m-auto max-w-md p-5 backdrop:bg-overlay-strong/50"
        data-testid="confirm-dialog"
        @close="pending.cancel"
    >
        <h2 id="confirm-title" class="text-lg font-semibold text-foreground-strong">{{ pending.title }}</h2>
        <p id="confirm-message" class="mt-2 break-words text-sm text-foreground-secondary">{{ pending.message }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button
                data-dialog-initial-focus
                type="button"
                class="btn-secondary"
                @click="pending.cancel"
                >{{ pending.cancelLabel }}</button
            >
            <button type="button" class="btn-danger" @click="pending.accept">{{ pending.confirmLabel }}</button>
        </div>
    </BaseDialog>
</template>
