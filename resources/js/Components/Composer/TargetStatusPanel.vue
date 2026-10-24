<script setup>
import { nextTick, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countChars } from '@/Composables/textCount';

const { post, accountName, firstCommentMax } = useComposerContext();

// Primer comentario (C-01b): solo un comentario `fallido` se edita y se reintenta; el estado del destino no cambia.
const COMMENT_STYLES = {
    pendiente: 'bg-sky-50 text-sky-800 ring-sky-200',
    comentando: 'bg-amber-50 text-amber-800 ring-amber-200',
    publicado: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    fallido: 'bg-red-50 text-red-800 ring-red-200',
    no_admitido: 'bg-slate-100 text-slate-700 ring-slate-200',
};
const edit = reactive({}); // id de destino -> { open, text, busy }

const startEdit = async (t) => {
    edit[t.id] = { open: true, text: t.first_comment ?? '', busy: false };
    await nextTick();
    document.getElementById(`fc-edit-${t.id}`)?.focus();
};
const cancelEdit = (t) => {
    delete edit[t.id];
    nextTick(() => document.getElementById(`fc-edit-btn-${t.id}`)?.focus());
};
const maxOf = (t) => firstCommentMax.value[t.content_type] ?? null;
const overMax = (t) => maxOf(t) !== null && countChars(edit[t.id]?.text ?? '') > maxOf(t);

const saveEdit = (t) => {
    const e = edit[t.id];
    if (!e || e.busy || overMax(t) || e.text.trim() === '') return;
    e.busy = true;
    router.patch(route('posts.first-comment.update', [post.value.id, t.id]), { first_comment: e.text }, {
        preserveScroll: true,
        onSuccess: () => delete edit[t.id],
        onFinish: () => { if (edit[t.id]) edit[t.id].busy = false; },
    });
};
const stateOf = (t) => (edit[t.id] ??= { open: false, text: '', busy: false });
const retry = (t, force = false) => {
    const e = stateOf(t);
    if (e.busy) return;
    e.busy = true;
    router.post(route('posts.first-comment.retry', [post.value.id, t.id]), force ? { force: true } : {}, {
        preserveScroll: true,
        onFinish: () => { if (edit[t.id]) { edit[t.id].busy = false; edit[t.id].confirmForce = false; } },
    });
};
// «Ya esta publicado»: lo marca hecho sin llamar a la red.
const markPublished = (t) => {
    const e = stateOf(t);
    if (e.busy) return;
    e.busy = true;
    router.post(route('posts.first-comment.published', [post.value.id, t.id]), {}, {
        preserveScroll: true,
        onFinish: () => { if (edit[t.id]) edit[t.id].busy = false; },
    });
};
// «Publicar igualmente»: segundo clic con aviso de duplicado (sin confirm() del navegador).
const askForce = async (t) => {
    stateOf(t).confirmForce = true;
    await nextTick();
    document.getElementById(`fc-force-confirm-${t.id}`)?.focus();
};
const cancelForce = (t) => {
    stateOf(t).confirmForce = false;
    nextTick(() => document.getElementById(`fc-force-btn-${t.id}`)?.focus());
};
</script>

<template>
    <div v-if="post && post.targets.length" class="card p-4">
        <h2 class="mb-2 font-medium text-slate-900">Estado por red</h2>
        <ul class="space-y-2 text-sm">
            <li v-for="t in post.targets" :key="`${t.account_id}-${t.content_type}`">
                <span class="font-medium">{{ accountName(t.account_id) }}</span>
                <StatusBadge class="ml-2" :status="t.status" :label="t.status_label" />
                <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="link ml-2">Ver</a>
                <p v-if="t.status_note" class="text-xs text-amber-700">{{ t.status_note }}</p>
                <p v-if="t.error_message" class="text-xs text-red-600">{{ t.error_message }}</p>

                <!-- Primer comentario (C-01b) -->
                <div v-if="t.first_comment_status" class="mt-1 border-l-2 border-slate-200 pl-2" data-testid="first-comment-status">
                    <p class="text-xs text-slate-700">
                        Primer comentario:
                        <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="COMMENT_STYLES[t.first_comment_status] ?? COMMENT_STYLES.no_admitido" data-testid="first-comment-badge">{{ t.first_comment_status_label }}</span>
                    </p>
                    <p v-if="t.first_comment" class="mt-0.5 line-clamp-3 whitespace-pre-line text-xs text-slate-600 [overflow-wrap:anywhere]">{{ t.first_comment }}</p>
                    <p v-if="t.first_comment_error && t.first_comment_status !== 'publicado'" class="text-xs" :class="t.first_comment_status === 'fallido' ? 'text-red-700' : 'text-slate-600'" data-testid="first-comment-error">{{ t.first_comment_error }}</p>

                    <div v-if="t.first_comment_status === 'fallido'" class="mt-1">
                        <div v-if="edit[t.id]?.open" class="space-y-1">
                            <label :for="`fc-edit-${t.id}`" class="block text-xs font-medium text-slate-800">Texto del primer comentario</label>
                            <textarea :id="`fc-edit-${t.id}`" v-model="edit[t.id].text" rows="3" class="field resize-y" :aria-invalid="overMax(t) ? 'true' : undefined" :aria-describedby="`fc-edit-count-${t.id}`" @keydown.esc.stop="cancelEdit(t)"></textarea>
                            <p :id="`fc-edit-count-${t.id}`" class="text-right text-xs" :class="overMax(t) ? 'font-medium text-red-700' : 'text-slate-600'" aria-live="polite">{{ countChars(edit[t.id].text) }} / {{ maxOf(t) }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary px-3 py-1 text-sm" :disabled="edit[t.id].busy || overMax(t) || edit[t.id].text.trim() === ''" @click="saveEdit(t)">Guardar</button>
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="cancelEdit(t)">Cancelar</button>
                            </div>
                        </div>
                        <div v-else class="flex flex-wrap gap-2">
                            <button :id="`fc-edit-btn-${t.id}`" type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="startEdit(t)">Editar</button>
                            <button type="button" class="btn btn-primary px-3 py-1 text-sm" :disabled="edit[t.id]?.busy" @click="retry(t)">Reintentar</button>
                            <template v-if="t.first_comment_may_exist">
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" :disabled="edit[t.id]?.busy" @click="markPublished(t)">Ya está publicado</button>
                                <button :id="`fc-force-btn-${t.id}`" type="button" class="btn btn-secondary px-3 py-1 text-sm" :disabled="edit[t.id]?.busy" @click="askForce(t)">Publicar igualmente</button>
                            </template>
                        </div>
                        <div v-if="edit[t.id]?.confirmForce" class="mt-2 space-y-1 rounded border border-amber-300 bg-amber-50 p-2" role="group" :aria-labelledby="`fc-force-msg-${t.id}`" data-testid="first-comment-force">
                            <p :id="`fc-force-msg-${t.id}`" class="text-xs text-amber-900">Si el comentario ya existe en la red, quedará duplicado. Comprueba antes la publicación. ¿Publicarlo igualmente?</p>
                            <div class="flex flex-wrap gap-2">
                                <button :id="`fc-force-confirm-${t.id}`" type="button" class="btn btn-primary px-3 py-1 text-sm" :disabled="edit[t.id].busy" @keydown.esc.stop="cancelForce(t)" @click="retry(t, true)">Sí, publicar igualmente</button>
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="cancelForce(t)">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
