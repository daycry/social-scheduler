<script setup>
import { computed } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Notas internas del post (C-09): texto plano con saltos de línea, hasta 5.000 caracteres. NUNCA se publican. Con un post guardado
 * se guardan con su propio botón (también cuando el post ya está publicado y no se puede editar); sin post, viajan con el formulario.
 */
const { post, form, NOTES_MAX, savingNotes, notesError, notesSaved, saveNotes } = useComposerContext();

const length = computed(() => [...(form.notes ?? '')].length);
const over = computed(() => length.value > NOTES_MAX);
</script>

<template>
    <section class="card min-w-0 p-4" data-testid="notes-panel">
        <InputLabel for="notes" value="Notas internas" />
        <p id="notes-hint" class="mt-1 text-xs text-slate-600">Solo las ves tú y tu equipo. No se publican en ninguna red.</p>
        <textarea
            id="notes"
            v-model="form.notes"
            rows="4"
            class="field mt-2 min-h-[6rem] resize-y"
            aria-describedby="notes-hint notes-count"
            :aria-invalid="over || !!notesError"
            data-testid="notes-input"
        ></textarea>
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            <p id="notes-count" class="text-xs" :class="over ? 'font-medium text-red-700' : 'text-slate-600'" data-testid="notes-count">{{ length }} / {{ NOTES_MAX }} caracteres<span v-if="over"> · supera el máximo</span></p>
            <button
                v-if="post"
                type="button"
                class="btn btn-secondary ms-auto px-3 py-1 text-sm"
                :disabled="savingNotes || over"
                data-testid="notes-save"
                @click="saveNotes"
            >{{ savingNotes ? 'Guardando…' : 'Guardar notas' }}</button>
        </div>
        <p v-if="notesSaved && post" class="mt-1 text-xs text-emerald-800" role="status" data-testid="notes-saved">Notas guardadas.</p>
        <p v-if="notesError" class="mt-1 text-xs text-red-700" role="alert">{{ notesError }}</p>
    </section>
</template>
