<script setup>
import { nextTick, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countChars } from '@/Composables/textCount';
import { useConfirm } from '@/Composables/useConfirm';

const { post, accountName, firstCommentMax } = useComposerContext();
const ask = useConfirm();

// Primer comentario (C-01b): solo un comentario `fallido` se edita y se reintenta; el estado del destino no cambia.
const COMMENT_STYLES = {
    pendiente: 'bg-info-soft text-info-ink ring-info-border',
    comentando: 'bg-warning-soft text-warning-ink ring-warning-border',
    publicado: 'bg-success-soft text-success-ink ring-success-border',
    fallido: 'bg-danger-soft text-danger-ink ring-danger-border',
    no_admitido: 'bg-surface-hover text-foreground-secondary ring-border',
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
        onFinish: () => { if (edit[t.id]) edit[t.id].busy = false; },
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
const askForce = async (target) => {
    const postId = post.value.id;
    if (stateOf(target).busy) return;
    const confirmed = await ask({
        title: 'Posible comentario duplicado',
        message: 'Si el comentario ya existe en la red, quedará duplicado. Comprueba antes la publicación.',
        confirmLabel: 'Publicar igualmente',
    });
    if (!confirmed || post.value?.id !== postId) return;
    const current = post.value.targets.find(item => item.id === target.id);
    if (current?.first_comment_status === 'fallido' && current.first_comment_may_exist) retry(current, true);
};
</script>

<template>
    <div v-if="post && post.targets.length" class="card p-4">
        <h2 class="mb-2 font-medium text-foreground-strong">Estado por red</h2>
        <ul class="space-y-2 text-sm">
            <li v-for="t in post.targets" :key="`${t.account_id}-${t.content_type}`">
                <span class="font-medium">{{ accountName(t.account_id) }}</span>
                <StatusBadge class="ml-2" :status="t.status" :label="t.status_label" />
                <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="link ml-2">Ver</a>
                <p v-if="t.status_note" class="text-xs text-warning-ink">{{ t.status_note }}</p>
                <p v-if="t.error_message" class="text-xs text-danger">{{ t.error_message }}</p>

                <!-- Primer comentario (C-01b) -->
                <div
                    v-if="t.first_comment_status"
                    class="mt-1 border-l-2 border-border pl-2"
                    data-testid="first-comment-status"
                >
                    <p class="text-xs text-foreground-secondary">
                        Primer comentario:
                        <span
                            class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="COMMENT_STYLES[t.first_comment_status] ?? COMMENT_STYLES.no_admitido"
                            data-testid="first-comment-badge"
                            >{{ t.first_comment_status_label }}</span
                        >
                    </p>
                    <p
                        v-if="t.first_comment"
                        class="mt-0.5 line-clamp-3 whitespace-pre-line text-xs text-foreground-muted [overflow-wrap:anywhere]"
                        >{{ t.first_comment }}</p
                    >
                    <p
                        v-if="t.first_comment_error && t.first_comment_status !== 'publicado'"
                        class="text-xs"
                        :class="t.first_comment_status === 'fallido' ? 'text-danger' : 'text-foreground-muted'"
                        data-testid="first-comment-error"
                        >{{ t.first_comment_error }}</p
                    >

                    <div v-if="t.first_comment_status === 'fallido'" class="mt-1">
                        <div v-if="edit[t.id]?.open" class="space-y-1">
                            <label :for="`fc-edit-${t.id}`" class="block text-xs font-medium text-foreground"
                                >Texto del primer comentario</label
                            >
                            <textarea
                                :id="`fc-edit-${t.id}`"
                                v-model="edit[t.id].text"
                                rows="3"
                                class="field resize-y"
                                :aria-invalid="overMax(t) ? 'true' : undefined"
                                :aria-describedby="`fc-edit-count-${t.id}`"
                                @keydown.esc.stop="cancelEdit(t)"
                            ></textarea>
                            <p
                                :id="`fc-edit-count-${t.id}`"
                                class="text-right text-xs"
                                :class="overMax(t) ? 'font-medium text-danger' : 'text-foreground-muted'"
                                aria-live="polite"
                                >{{ countChars(edit[t.id].text) }} / {{ maxOf(t) }}</p
                            >
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="btn btn-primary px-3 py-1 text-sm"
                                    :disabled="edit[t.id].busy || overMax(t) || edit[t.id].text.trim() === ''"
                                    @click="saveEdit(t)"
                                    >Guardar</button
                                >
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="cancelEdit(t)"
                                    >Cancelar</button
                                >
                            </div>
                        </div>
                        <div v-else class="flex flex-wrap gap-2">
                            <button
                                :id="`fc-edit-btn-${t.id}`"
                                type="button"
                                class="btn btn-secondary px-3 py-1 text-sm"
                                @click="startEdit(t)"
                                >Editar</button
                            >
                            <button
                                type="button"
                                class="btn btn-primary px-3 py-1 text-sm"
                                :disabled="edit[t.id]?.busy"
                                @click="retry(t)"
                                >Reintentar</button
                            >
                            <template v-if="t.first_comment_may_exist">
                                <button
                                    type="button"
                                    class="btn btn-secondary px-3 py-1 text-sm"
                                    :disabled="edit[t.id]?.busy"
                                    @click="markPublished(t)"
                                    >Ya está publicado</button
                                >
                                <button
                                    type="button"
                                    class="btn btn-secondary px-3 py-1 text-sm"
                                    :disabled="edit[t.id]?.busy"
                                    @click="askForce(t)"
                                    >Publicar igualmente</button
                                >
                            </template>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
