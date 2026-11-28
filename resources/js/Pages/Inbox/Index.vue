<script setup>
/** @import { InboxAccount, InboxComment, InboxCoverage, InboxFilters, InboxPage, SavedReply } from '@/types/inbox' */
/** @import { BrandRef, NetworkOption } from '@/types/shell' */
import { createDateFormatter } from '@/lib/format';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FormField from '@/Components/FormField.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import CommentNotes from '@/Components/Inbox/CommentNotes.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import PermissionNotice from '@/Components/PermissionNotice.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/** Inbox (PA-10, T-20): comentarios de publicaciones propias de Instagram, Facebook, Threads, Bluesky y YouTube. Sin mensajes directos. */
const props = defineProps({
    brand: { type: /** @type {import('vue').PropType<(BrandRef & { timezone: string | null }) | null>} */ (Object), default: null },
    filters: { type: /** @type {import('vue').PropType<InboxFilters>} */ (Object), required: true },
    unread_count: { type: Number, required: true },
    comments: { type: /** @type {import('vue').PropType<InboxPage>} */ (Object), required: true },
    note_max: { type: Number, default: 2000 },
    networks: { type: /** @type {import('vue').PropType<Pick<NetworkOption, 'value' | 'label'>[]>} */ (Array), default: () => [] },
    coverage: { type: /** @type {import('vue').PropType<InboxCoverage[]>} */ (Array), default: () => [] },
    saved_replies: { type: /** @type {import('vue').PropType<SavedReply[]>} */ (Array), default: () => [] },
    assignees: { type: /** @type {import('vue').PropType<{ id: number; name: string }[]>} */ (Array), default: () => [] },
    labels: { type: /** @type {import('vue').PropType<string[]>} */ (Array), default: () => [] },
    accounts: { type: /** @type {import('vue').PropType<InboxAccount[]>} */ (Array), required: true },
});

const networkNames = computed(() => {
    const labels = props.networks.map((n) => n.label);
    return labels.length > 1 ? `${labels.slice(0, -1).join(', ')} o ${labels.at(-1)}` : (labels[0] ?? '');
});
const list = computed(() => props.comments.data);
const blocked = computed(() => props.accounts.filter((a) => !a.can_read));
const reconnect = computed(() => props.accounts.filter((a) => a.needs_reconnect));

/**
 * @param {Partial<Omit<InboxFilters, 'status'>> & { status?: string | null }} patch
 */
const setFilter = (patch) => {
    const next = { ...props.filters, ...patch };
    // Conserva el filtro explícito hidden=0. La validación de Laravel recibe los booleanos de la URL como 0 o 1.
    const query = Object.fromEntries(
        Object.entries(next)
            .filter(([key, value]) => value !== null && value !== '' && (value !== false || key === 'hidden'))
            .map(([key, value]) => [key, typeof value === 'boolean' ? Number(value) : value]),
    );
    router.get(route('inbox.index'), query, { preserveScroll: true, preserveState: true });
};

/**
 * @param {'network' | 'status' | 'assigned' | 'label'} key
 * @param {Event} event
 */
const filterBySelect = (key, event) => {
    if (event.currentTarget instanceof HTMLSelectElement) {
        setFilter({ [key]: event.currentTarget.value || null });
    }
};

/** @param {'unread' | 'hidden' | 'has_notes'} key @param {Event} event */
const filterByCheckbox = (key, event) => {
    if (!(event.currentTarget instanceof HTMLInputElement)) return;
    const { checked } = event.currentTarget;
    setFilter({ [key]: key === 'hidden' && !checked ? null : checked });
};

const selected = ref(/** @type {number[]} */ ([]));
const allSelected = computed(() => list.value.length > 0 && list.value.every((c) => selected.value.includes(c.id)));
/** @param {Event} event */
const toggleAll = (event) => {
    if (event.currentTarget instanceof HTMLInputElement) {
        selected.value = event.currentTarget.checked ? list.value.map((c) => c.id) : [];
    }
};
/** @param {'read' | 'close' | 'reopen'} action */
const batch = (action) =>
    router.post(route('inbox.batch'), { action, ids: selected.value }, { preserveScroll: true, onSuccess: () => { selected.value = []; } });
/** @param {InboxComment} c */
const toggleClose = (c) => router.post(route('inbox.close', c.id), { closed: !c.closed }, { preserveScroll: true });
/** @param {InboxComment} c @param {Event} event */
const assign = (c, event) => {
    if (event.currentTarget instanceof HTMLSelectElement) {
        router.post(route('inbox.assign', c.id), { assigned_user_id: event.currentTarget.value || null }, { preserveScroll: true });
    }
};
const newLabel = reactive(/** @type {Partial<Record<number, string>>} */ ({}));
/** @param {InboxComment} c */
const addLabel = (c) => {
    const label = (newLabel[c.id] ?? '').trim();
    if (!label) return;
    router.post(route('inbox.label', c.id), { label }, { preserveScroll: true, onSuccess: () => { newLabel[c.id] = ''; } });
};
/** @param {InboxComment} c @param {string} label */
const removeLabel = (c, label) => router.post(route('inbox.label', c.id), { label, remove: 1 }, { preserveScroll: true });
/** @param {number | null} id */
const assigneeName = (id) => props.assignees.find((a) => a.id === id)?.name ?? null;

const savedForm = useForm({ title: '', body: '' });
const saveReply = () => savedForm.post(route('inbox.saved-replies.store'), { preserveScroll: true, onSuccess: () => { savedForm.reset(); } });
/** @param {SavedReply} r */
const removeSaved = async (r) => {
    if (await ask({ message: `¿Eliminar la respuesta guardada «${r.title}»?`, confirmLabel: 'Eliminar respuesta' })) {
        router.delete(route('inbox.saved-replies.destroy', r.id), { preserveScroll: true });
    }
};
/** @param {Event} event */
const applySavedReply = (event) => {
    const select = event.currentTarget;
    if (!(select instanceof HTMLSelectElement)) return;
    const r = props.saved_replies.find((x) => String(x.id) === select.value);
    if (r) form.text = r.body;
};
const saveCurrent = () => {
    savedForm.body = form.text;
    savedForm.title = (form.text.split(/\s+/).slice(0, 4).join(' ') || 'Respuesta').slice(0, 60);
    saveReply();
};

const replying = reactive(/** @type {Partial<Record<number, boolean>>} */ ({}));
// El servidor puede devolver errors.account, aunque la cuenta no es un campo editable.
const form = useForm(/** @type {{ text: string; account?: never }} */ ({ text: '' }));
/** @param {InboxComment} c */
const openReply = (c) => {
    replying[c.id] = !replying[c.id];
    form.reset();
    form.clearErrors();
};
/** @param {InboxComment} c */
const sendReply = (c) =>
    form.post(route('inbox.reply', c.id), {
        preserveScroll: true,
        onSuccess: () => {
            replying[c.id] = false;
            form.reset();
        },
    });
/** @param {InboxComment} c */
const toggleRead = (c) => router.post(route('inbox.read', c.id), { read: !c.read }, { preserveScroll: true });
/** @param {InboxComment} c */
const toggleHide = (c) => router.post(route('inbox.hide', c.id), { hidden: !c.hidden }, { preserveScroll: true });
const readAll = () => router.post(route('inbox.read-all'), { network: props.filters.network }, { preserveScroll: true });
const sync = () => router.post(route('inbox.sync'), {}, { preserveScroll: true });
const fmt = computed(() => createDateFormatter(props.brand?.timezone, { dateStyle: 'medium', timeStyle: 'short' }));
</script>

<template>
    <Head title="Inbox" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Inbox de comentarios</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand || accounts.length === 0"
                title="Inbox de comentarios"
                :description="`Conecta una cuenta de ${networkNames} para leer y responder los comentarios de tus publicaciones.`"
                :cards="[
                    { title: 'Un solo buzón', text: `Comentarios de ${networks.length} redes juntos.`, kind: 'inbox' },
                    { title: 'Responde y modera', text: 'Contesta u oculta sin salir de la herramienta.', kind: 'report' },
                    { title: 'Filtra y ordena', text: 'Por red, no leídos, asignado y etiqueta.', kind: 'chart' },
                ]"
            />

            <template v-else>
                <PermissionNotice
                    v-if="blocked.length"
                    :title="`No disponible para: ${blocked.map((a) => a.name).join(', ')}`"
                    :items="blocked.map((a) => ({ id: a.id, name: a.name, help: a.help, brand_id: a.brand_id }))"
                    data-testid="inbox-unavailable"
                />
                <p v-if="reconnect.length" class="text-sm text-warning-ink" role="alert"
                    >Reconecta {{ reconnect.map((a) => a.name).join(', ') }} para seguir sincronizando.</p
                >

                <div class="card flex flex-wrap items-center gap-3 p-4 text-sm">
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-foreground-subtle">Red</span>
                        <select
                            class="field w-auto"
                            data-testid="filter-network"
                            :value="filters.network ?? ''"
                            @change="filterBySelect('network', $event)"
                        >
                            <option value="">Todas</option>
                            <option v-for="n in networks" :key="n.value" :value="n.value">{{ n.label }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-foreground-subtle">Estado</span>
                        <select
                            class="field w-auto"
                            data-testid="filter-status"
                            :value="filters.status ?? ''"
                            @change="filterBySelect('status', $event)"
                        >
                            <option value="">Todos</option>
                            <option value="open">Abiertos</option>
                            <option value="closed">Cerrados</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-foreground-subtle">Asignado</span>
                        <select
                            class="field w-auto"
                            data-testid="filter-assigned"
                            :value="filters.assigned ?? ''"
                            @change="filterBySelect('assigned', $event)"
                        >
                            <option value="">Todos</option>
                            <option value="none">Sin asignar</option>
                            <option value="me">A mí</option>
                        </select>
                    </label>
                    <label v-if="labels.length" class="flex items-center gap-2">
                        <span class="text-xs font-medium text-foreground-subtle">Etiqueta</span>
                        <select
                            class="field w-auto"
                            data-testid="filter-label"
                            :value="filters.label ?? ''"
                            @change="filterBySelect('label', $event)"
                        >
                            <option value="">Todas</option>
                            <option v-for="l in labels" :key="l" :value="l">{{ l }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" :checked="filters.unread" @change="filterByCheckbox('unread', $event)" />
                        Solo no leídos ({{ unread_count }})
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            :checked="filters.hidden === true"
                            @change="filterByCheckbox('hidden', $event)"
                        />
                        Solo ocultos
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            :checked="filters.has_notes === true"
                            data-testid="filter-has-notes"
                            @change="filterByCheckbox('has_notes', $event)"
                        />
                        Solo con nota
                    </label>
                    <span class="ms-auto flex items-center gap-2">
                        <button type="button" class="btn btn-secondary" @click="readAll">Marcar todo como leído</button>
                        <button type="button" class="btn btn-secondary" @click="sync">Actualizar</button>
                    </span>
                </div>

                <div
                    v-if="list.length"
                    class="card flex flex-wrap items-center gap-3 p-3 text-sm"
                    data-testid="batch-bar"
                >
                    <label class="flex items-center gap-2">
                        <input type="checkbox" data-testid="select-all" :checked="allSelected" @change="toggleAll" />
                        Seleccionar todos
                    </label>
                    <span class="text-xs text-foreground-muted" role="status">{{ selected.length }} seleccionados</span>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-testid="batch-read"
                        :disabled="!selected.length"
                        @click="batch('read')"
                        >Marcar leídos</button
                    >
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-testid="batch-close"
                        :disabled="!selected.length"
                        @click="batch('close')"
                        >Cerrar</button
                    >
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-testid="batch-reopen"
                        :disabled="!selected.length"
                        @click="batch('reopen')"
                        >Reabrir</button
                    >
                </div>

                <section class="card">
                    <p v-if="list.length === 0" class="p-5 text-sm text-foreground-subtle"
                        >No hay comentarios que mostrar. Se sincronizan cada pocos minutos desde tus publicaciones ya
                        publicadas.</p
                    >
                    <ul v-else class="divide-y">
                        <li
                            v-for="c in list"
                            :key="c.id"
                            class="space-y-2 px-5 py-4 text-sm"
                            :class="c.read ? '' : 'bg-accent-soft/40'"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <input
                                        v-model="selected"
                                        type="checkbox"
                                        :value="c.id"
                                        class="mt-1"
                                        data-testid="select-comment"
                                        :aria-label="`Seleccionar comentario de ${c.author || 'anónimo'}`"
                                    />
                                    <NetworkIcon :network="c.network" size="sm" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-foreground-strong">
                                            {{ c.author || 'Anónimo' }}
                                            <span
                                                v-if="!c.read"
                                                class="ms-1 rounded-full bg-accent-fill px-1.5 py-0.5 text-[10px] font-semibold text-on-accent"
                                                >nuevo</span
                                            >
                                            <span
                                                v-if="c.notes.length"
                                                class="ms-1 rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-semibold text-warning-ink"
                                                data-testid="note-badge"
                                                >con nota</span
                                            >
                                            <span
                                                v-if="c.hidden"
                                                class="ms-1 rounded-full bg-surface-pressed px-1.5 py-0.5 text-[10px] text-foreground-secondary"
                                                >oculto</span
                                            >
                                            <span
                                                v-if="c.closed"
                                                class="ms-1 rounded-full bg-success-soft px-1.5 py-0.5 text-[10px] font-semibold text-success-ink"
                                                data-testid="closed-badge"
                                                >cerrado</span
                                            >
                                            <span
                                                v-if="c.is_reply"
                                                class="ms-1 text-xs font-normal text-foreground-subtle"
                                                >(respuesta en un hilo)</span
                                            >
                                        </p>
                                        <p class="whitespace-pre-line text-foreground-secondary">{{ c.text }}</p>
                                        <p class="mt-1 text-xs text-foreground-subtle">
                                            {{ fmt(c.commented_at) }} · {{ c.account }}
                                            <template v-if="c.post_text"> · en «{{ c.post_text }}»</template>
                                            <a
                                                v-if="c.post_url"
                                                :href="c.post_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="ms-1 underline"
                                                >ver publicación</a
                                            >
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 text-xs">
                                    <button type="button" class="btn btn-secondary" @click="openReply(c)"
                                        >Responder</button
                                    >
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="toggleRead(c)"
                                        >{{ c.read ? 'Marcar no leído' : 'Marcar leído' }}</button
                                    >
                                    <button
                                        type="button"
                                        class="underline"
                                        data-testid="toggle-close"
                                        @click="toggleClose(c)"
                                        >{{ c.closed ? 'Reabrir' : 'Cerrar' }}</button
                                    >
                                    <button
                                        v-if="c.can_hide"
                                        type="button"
                                        class="text-danger underline"
                                        @click="toggleHide(c)"
                                        >{{ c.hidden ? 'Mostrar' : 'Ocultar' }}</button
                                    >
                                </div>
                            </div>

                            <ul v-if="c.my_replies.length" class="ms-8 space-y-1 border-s-2 border-success-border ps-3">
                                <li v-for="r in c.my_replies" :key="r.id" class="text-foreground-muted"
                                    ><span class="text-xs text-success-ink">Tú · {{ fmt(r.at) }}</span
                                    ><br />{{ r.text }}</li
                                >
                            </ul>

                            <div class="ms-8 flex flex-wrap items-center gap-2 text-xs" data-testid="triage">
                                <span
                                    v-for="l in c.labels"
                                    :key="l"
                                    class="inline-flex items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-foreground"
                                    data-testid="label-chip"
                                >
                                    {{ l }}
                                    <button
                                        type="button"
                                        class="font-bold"
                                        :aria-label="`Quitar etiqueta ${l}`"
                                        @click="removeLabel(c, l)"
                                        >×</button
                                    >
                                </span>
                                <form class="flex items-center gap-1" @submit.prevent="addLabel(c)">
                                    <label>
                                        <span class="sr-only">Nueva etiqueta</span>
                                        <input
                                            v-model="newLabel[c.id]"
                                            type="text"
                                            maxlength="40"
                                            placeholder="Etiqueta"
                                            data-testid="label-input"
                                            class="field w-28 py-1 text-xs"
                                        />
                                    </label>
                                    <button type="submit" class="underline" data-testid="label-add">Añadir</button>
                                </form>
                                <label v-if="assignees.length" class="flex items-center gap-1">
                                    <span class="text-foreground-muted">Asignado a</span>
                                    <select
                                        class="field w-auto py-1 text-xs"
                                        data-testid="assign-select"
                                        :value="c.assigned_user_id ?? ''"
                                        @change="assign(c, $event)"
                                    >
                                        <option value="">Nadie</option>
                                        <option v-for="a in assignees" :key="a.id" :value="a.id">{{ a.name }}</option>
                                    </select>
                                </label>
                            </div>

                            <CommentNotes :comment="c" :note-max="note_max" />

                            <form v-if="replying[c.id]" class="ms-8 space-y-2" @submit.prevent="sendReply(c)">
                                <FormField :error="form.errors.text" v-slot="{ control }">
                                    <label class="block">
                                        <span class="sr-only">Respuesta</span>
                                        <textarea
                                            v-bind="control"
                                            v-model="form.text"
                                            rows="2"
                                            maxlength="2200"
                                            required
                                            class="field"
                                            placeholder="Escribe tu respuesta…"
                                        ></textarea>
                                    </label>
                                </FormField>
                                <FormField :error="form.errors.account" />
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="btn btn-primary" :disabled="form.processing"
                                        >Enviar respuesta</button
                                    >
                                    <label v-if="saved_replies.length" class="flex items-center gap-1 text-xs">
                                        <span class="text-foreground-muted">Respuesta guardada</span>
                                        <select
                                            class="field w-auto py-1 text-xs"
                                            data-testid="saved-select"
                                            @change="applySavedReply"
                                        >
                                            <option value="">Elegir…</option>
                                            <option
                                                v-for="r in saved_replies"
                                                :key="r.id"
                                                :value="r.id"
                                                >{{ r.title }}</option
                                            >
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        class="text-xs underline"
                                        data-testid="save-current"
                                        :disabled="!form.text.trim()"
                                        @click="saveCurrent"
                                        >Guardar como respuesta</button
                                    >
                                </div>
                            </form>
                        </li>
                    </ul>
                    <nav
                        v-if="comments?.next_page_url || comments?.prev_page_url"
                        class="flex justify-between border-t px-5 py-3 text-sm"
                        aria-label="Paginación"
                    >
                        <Link
                            v-if="comments.prev_page_url"
                            :href="comments.prev_page_url"
                            preserve-scroll
                            class="underline"
                            >Anteriores</Link
                        >
                        <span v-else></span>
                        <Link
                            v-if="comments.next_page_url"
                            :href="comments.next_page_url"
                            preserve-scroll
                            class="underline"
                            >Siguientes</Link
                        >
                    </nav>
                </section>

                <section class="card space-y-3 p-5 text-sm" data-testid="saved-replies">
                    <h2 class="text-base font-semibold text-foreground-strong">Respuestas guardadas</h2>
                    <ul v-if="saved_replies.length" class="space-y-1">
                        <li
                            v-for="r in saved_replies"
                            :key="r.id"
                            class="flex flex-wrap items-center justify-between gap-2"
                            data-testid="saved-item"
                        >
                            <span class="min-w-0"
                                ><strong>{{ r.title }}</strong
                                >&#32;<span class="text-foreground-muted">{{ r.body.slice(0, 80) }}</span></span
                            >
                            <button
                                type="button"
                                class="text-xs text-danger underline"
                                :aria-label="`Eliminar la respuesta guardada ${r.title}`"
                                @click="removeSaved(r)"
                                >Eliminar</button
                            >
                        </li>
                    </ul>
                    <p v-else class="text-foreground-muted"
                        >Aún no tienes ninguna. Guarda una desde el cuadro de respuesta o créala aquí.</p
                    >
                    <form class="space-y-2" @submit.prevent="saveReply">
                        <FormField :error="savedForm.errors.title" v-slot="{ control }">
                            <label class="block">
                                <span class="block text-xs font-medium text-foreground-muted">Título</span>
                                <input
                                    v-bind="control"
                                    v-model="savedForm.title"
                                    type="text"
                                    maxlength="60"
                                    required
                                    class="field max-w-xs"
                                    data-testid="saved-title"
                                />
                            </label>
                        </FormField>
                        <FormField :error="savedForm.errors.body" v-slot="{ control }">
                            <label class="block">
                                <span class="block text-xs font-medium text-foreground-muted">Texto</span>
                                <textarea
                                    v-bind="control"
                                    v-model="savedForm.body"
                                    rows="2"
                                    maxlength="2200"
                                    required
                                    class="field"
                                    data-testid="saved-body"
                                ></textarea>
                            </label>
                        </FormField>
                        <button
                            type="submit"
                            class="btn btn-secondary"
                            data-testid="saved-add"
                            :disabled="savedForm.processing"
                            >Guardar respuesta</button
                        >
                    </form>
                </section>

                <section class="card p-5 text-sm" data-testid="coverage">
                    <h2 class="text-base font-semibold text-foreground-strong">Cobertura por red</h2>
                    <ul class="mt-2 space-y-1">
                        <li
                            v-for="c in coverage"
                            :key="c.network"
                            class="flex flex-wrap gap-x-2"
                            :data-testid="`coverage-${c.network}`"
                        >
                            <strong class="w-24 shrink-0">{{ c.label }}</strong>
                            <span
                                class="font-medium"
                                :class="c.read ? 'text-success-ink' : 'text-foreground-secondary'"
                                >{{ c.read ? (c.hide ? 'Leer, responder y ocultar' : 'Leer y responder') : 'No disponible' }}</span
                            >
                            <span class="min-w-0 text-foreground-muted">{{ c.note }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
