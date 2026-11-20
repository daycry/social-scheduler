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
        class="m-auto w-[calc(100vw_-_2rem)] max-w-md rounded-lg border-0 bg-white p-5 shadow-xl backdrop:bg-slate-900/50"
        data-testid="confirm-dialog"
        @close="pending.cancel"
    >
        <h2 id="confirm-title" class="text-lg font-semibold text-slate-900">{{ pending.title }}</h2>
        <p id="confirm-message" class="mt-2 break-words text-sm text-slate-700">{{ pending.message }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button
                data-dialog-initial-focus
                type="button"
                class="btn btn-secondary"
                @click="pending.cancel"
                >{{ pending.cancelLabel }}</button
            >
            <button type="button" class="btn btn-danger" @click="pending.accept">{{ pending.confirmLabel }}</button>
        </div>
    </BaseDialog>
</template>
