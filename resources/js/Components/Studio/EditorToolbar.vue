<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const name = defineModel('name', { type: String, required: true });

defineProps({
    brand: { type: Object, required: true },
    active: Boolean,
    status: { type: String, required: true },
    revision: { type: Number, required: true },
    fontBusy: Boolean,
    automationBusy: Boolean,
    busy: Boolean,
    exportBusy: Boolean,
    exportLoading: Boolean,
    publishPending: Boolean,
    conflict: Boolean,
    dirty: Boolean,
    ready: Boolean,
    canUndo: Boolean,
    canRedo: Boolean,
    presentationOpening: Boolean,
    isOwner: Boolean,
    updatesPost: Boolean,
});

defineEmits(['rename', 'undo', 'redo', 'save', 'present', 'export', 'publish']);
const historyButtonClass =
    'btn-secondary min-h-[30px] border-border px-[9px] py-[5px] text-sm font-normal text-foreground-muted shadow-none transition-none hover:border-accent-secondary-border disabled:opacity-40';
</script>

<template>
    <header
        class="flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-surface p-3"
        :inert="fontBusy || !active || undefined"
    >
        <Link
            :href="route('studio.index', { brand_id: brand.id })"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border text-foreground-subtle hover:bg-canvas"
            aria-label="Volver al estudio"
            ><Icon name="chevronLeft" class="h-4 w-4"
        /></Link>
        <div class="min-w-0 flex-1"
            ><label class="sr-only" for="studio-design-name">Nombre del diseño</label
            ><input
                id="studio-design-name"
                v-model="name"
                class="w-full min-w-0 rounded-md border-transparent bg-transparent px-1 py-0.5 text-sm font-semibold focus:border-focus focus:ring-focus"
                maxlength="255"
                :disabled="automationBusy"
                @input="$emit('rename')"
            /><div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-[10px] text-foreground-subtle"
                ><span>{{ brand.name }}</span
                ><span
                    data-testid="studio-save-status"
                    role="status"
                    :class="conflict ? 'text-danger' : dirty ? 'text-warning-ink' : 'text-success-ink'"
                    >{{ status }}</span
                ><span>Revisión {{ revision }}</span></div
            ></div
        >
        <div class="flex flex-wrap items-center gap-2"
            ><button
                type="button"
                :class="historyButtonClass"
                :disabled="!canUndo || busy || automationBusy"
                aria-label="Deshacer"
                title="Deshacer (Ctrl+Z)"
                @click="$emit('undo')"
                >↶</button
            ><button
                type="button"
                :class="historyButtonClass"
                :disabled="!canRedo || busy || automationBusy"
                aria-label="Rehacer"
                title="Rehacer (Ctrl+Mayús+Z)"
                @click="$emit('redo')"
                >↷</button
            ><button
                type="button"
                class="btn-secondary text-xs"
                :disabled="!active || conflict || automationBusy"
                @click="$emit('save')"
                >Guardar ahora</button
            ><button
                type="button"
                class="btn-secondary text-xs"
                :disabled="!ready || busy || exportBusy || automationBusy"
                :aria-busy="presentationOpening || undefined"
                @click="$emit('present')"
                >{{ presentationOpening ? 'Preparando...' : 'Presentar' }}</button
            ><button
                v-if="isOwner"
                type="button"
                class="btn-secondary text-xs"
                :disabled="!ready || busy || exportBusy || automationBusy"
                @click="$emit('export')"
                >Exportar</button
            ><button
                v-if="isOwner"
                type="button"
                class="rounded-lg bg-accent-secondary-fill px-3 py-2.5 text-xs font-semibold text-on-accent-secondary hover:bg-accent-secondary-hover disabled:opacity-50"
                :disabled="!ready || busy || exportBusy || automationBusy"
                :aria-busy="exportLoading || publishPending || undefined"
                @click="$emit('publish')"
                >{{ updatesPost ? 'Actualizar publicación' : 'Usar en publicación' }}</button
            ></div
        >
    </header>
</template>
