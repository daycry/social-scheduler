<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

/**
 * Notas internas de un comentario del inbox (C-04d): texto plano, hasta `noteMax` caracteres. Solo las ve el equipo:
 * no se envían a la red. Orden cronológico (la más antigua arriba), con alta y borrado.
 */
const props = defineProps({
    comment: { type: Object, required: true },
    noteMax: { type: Number, default: 2000 },
});

const open = ref(false);
const form = useForm({ body: '' });
const fmt = (iso) => (iso ? new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '');

const toggle = () => {
    open.value = !open.value;
    form.reset();
    form.clearErrors();
};
const add = () =>
    form.post(route('inbox.notes.store', props.comment.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
const remove = (note) => router.delete(route('inbox.notes.destroy', note.id), { preserveScroll: true });
</script>

<template>
    <div class="ms-8 space-y-2" :data-testid="`comment-notes-${comment.id}`">
        <ul v-if="comment.notes.length" class="space-y-1 border-s-2 border-amber-300 ps-3" :aria-label="`Notas internas de ${comment.author || 'Anónimo'}`">
            <li v-for="n in comment.notes" :key="n.id" class="flex items-start justify-between gap-3 text-slate-700" data-testid="comment-note">
                <span class="min-w-0">
                    <span class="text-xs text-amber-800">Nota interna · {{ fmt(n.at) }}</span><br />
                    <span class="whitespace-pre-line break-words">{{ n.body }}</span>
                </span>
                <button type="button" class="shrink-0 text-xs text-red-700 underline" data-testid="comment-note-delete" @click="remove(n)">
                    Borrar<span class="sr-only"> nota</span>
                </button>
            </li>
        </ul>

        <button type="button" class="text-xs underline" :aria-expanded="open" data-testid="comment-note-toggle" @click="toggle">
            {{ open ? 'Cancelar nota' : 'Añadir nota interna' }}
        </button>

        <form v-if="open" class="space-y-2" @submit.prevent="add">
            <label class="block">
                <span class="sr-only">Nota interna</span>
                <textarea v-model="form.body" rows="2" :maxlength="noteMax" required class="field" placeholder="Solo la ve tu equipo; no se publica…" data-testid="comment-note-input"></textarea>
            </label>
            <p class="text-xs text-slate-600">{{ [...form.body].length }} / {{ noteMax }} caracteres</p>
            <InputError :message="form.errors.body" />
            <button type="submit" class="btn btn-primary" :disabled="form.processing || !form.body.trim()" data-testid="comment-note-save">Guardar nota</button>
        </form>
    </div>
</template>
