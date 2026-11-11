<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import CommentNotes from '@/Components/Inbox/CommentNotes.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/** Inbox (PA-10, T-20): comentarios de publicaciones propias de Instagram, Facebook, Threads, Bluesky y YouTube. Sin mensajes directos. */
const props = defineProps({
    brand: Object,
    filters: Object,
    unread_count: Number,
    comments: Object,
    note_max: { type: Number, default: 2000 },
    coverage: { type: Array, default: () => [] },
    saved_replies: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    accounts: Array,
    flash: Object,
});

const list = computed(() => props.comments?.data ?? []);
const blocked = computed(() => props.accounts.filter((a) => !a.can_read));
const reconnect = computed(() => props.accounts.filter((a) => a.needs_reconnect));

const setFilter = (patch) => {
    const next = { ...props.filters, ...patch };
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== false && v !== ''));
    router.get(route('inbox.index'), query, { preserveScroll: true, preserveState: true });
};

const selected = ref([]);
const allSelected = computed(() => list.value.length > 0 && list.value.every((c) => selected.value.includes(c.id)));
const toggleAll = (on) => (selected.value = on ? list.value.map((c) => c.id) : []);
const batch = (action) =>
    router.post(route('inbox.batch'), { action, ids: selected.value }, { preserveScroll: true, onSuccess: () => (selected.value = []) });
const toggleClose = (c) => router.post(route('inbox.close', c.id), { closed: !c.closed }, { preserveScroll: true });
const assign = (c, id) => router.post(route('inbox.assign', c.id), { assigned_user_id: id || null }, { preserveScroll: true });
const newLabel = reactive({});
const addLabel = (c) => {
    const label = (newLabel[c.id] ?? '').trim();
    if (!label) return;
    router.post(route('inbox.label', c.id), { label }, { preserveScroll: true, onSuccess: () => (newLabel[c.id] = '') });
};
const removeLabel = (c, label) => router.post(route('inbox.label', c.id), { label, remove: 1 }, { preserveScroll: true });
const assigneeName = (id) => props.assignees.find((a) => a.id === id)?.name ?? null;

const savedForm = useForm({ title: '', body: '' });
const saveReply = () => savedForm.post(route('inbox.saved-replies.store'), { preserveScroll: true, onSuccess: () => savedForm.reset() });
const removeSaved = (r) => router.delete(route('inbox.saved-replies.destroy', r.id), { preserveScroll: true });
const useSaved = (id) => {
    const r = props.saved_replies.find((x) => String(x.id) === String(id));
    if (r) form.text = r.body;
};
const saveCurrent = () => {
    savedForm.body = form.text;
    savedForm.title = (form.text.split(/\s+/).slice(0, 4).join(' ') || 'Respuesta').slice(0, 60);
    saveReply();
};

const replying = reactive({});
const form = useForm({ text: '' });
const openReply = (c) => {
    replying[c.id] = !replying[c.id];
    form.reset();
    form.clearErrors();
};
const sendReply = (c) =>
    form.post(route('inbox.reply', c.id), {
        preserveScroll: true,
        onSuccess: () => {
            replying[c.id] = false;
            form.reset();
        },
    });
const toggleRead = (c) => router.post(route('inbox.read', c.id), { read: !c.read }, { preserveScroll: true });
const toggleHide = (c) => router.post(route('inbox.hide', c.id), { hidden: !c.hidden }, { preserveScroll: true });
const readAll = () => router.post(route('inbox.read-all'), { network: props.filters?.network ?? null }, { preserveScroll: true });
const sync = () => router.post(route('inbox.sync'), {}, { preserveScroll: true });
const fmt = (iso) => (iso ? new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '');
</script>

<template>
    <Head title="Inbox" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Inbox de comentarios</h1>
        </template>

        <div class="space-y-5">
            <p v-if="flash?.success" class="text-sm text-emerald-700" role="status">{{ flash.success }}</p>
            <p v-if="flash?.error" class="text-sm text-red-600" role="alert">{{ flash.error }}</p>

            <FeatureEmpty
                v-if="!brand || accounts.length === 0"
                title="Inbox de comentarios"
                description="Conecta una cuenta de Instagram, Facebook, Threads, Bluesky o YouTube para leer y responder los comentarios de tus publicaciones."
                :cards="[
                    { title: 'Un solo buzón', text: 'Comentarios de cinco redes juntos.', kind: 'inbox' },
                    { title: 'Responde y modera', text: 'Contesta u oculta sin salir de la herramienta.', kind: 'report' },
                    { title: 'Filtra y ordena', text: 'Por red, no leídos, asignado y etiqueta.', kind: 'chart' },
                ]"
            />

            <template v-else>
                <div v-if="blocked.length" class="card border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert" data-testid="inbox-unavailable">
                    <p class="font-medium">No disponible para: {{ blocked.map((a) => a.name).join(', ') }}</p>
                    <p class="mt-1">
                        Falta el permiso de comentarios. Actívalo en los casos de uso de la app de Meta (<code>instagram_manage_comments</code>, <code>pages_read_user_content</code> y
                        <code>pages_manage_engagement</code>; en Threads <code>threads_read_replies</code>; en YouTube <code>youtube.force-ssl</code>) y vuelve a conectar la cuenta.
                    </p>
                </div>
                <p v-if="reconnect.length" class="text-sm text-amber-800" role="alert">Reconecta {{ reconnect.map((a) => a.name).join(', ') }} para seguir sincronizando.</p>

                <div class="card flex flex-wrap items-center gap-3 p-4 text-sm">
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Red</span>
                        <select class="field w-auto" data-testid="filter-network" :value="filters.network ?? ''" @change="setFilter({ network: $event.target.value || null })">
                            <option value="">Todas</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="threads">Threads</option>
                            <option value="bluesky">Bluesky</option>
                            <option value="youtube">YouTube</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Estado</span>
                        <select class="field w-auto" data-testid="filter-status" :value="filters.status ?? ''" @change="setFilter({ status: $event.target.value || null })">
                            <option value="">Todos</option>
                            <option value="open">Abiertos</option>
                            <option value="closed">Cerrados</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Asignado</span>
                        <select class="field w-auto" data-testid="filter-assigned" :value="filters.assigned ?? ''" @change="setFilter({ assigned: $event.target.value || null })">
                            <option value="">Todos</option>
                            <option value="none">Sin asignar</option>
                            <option value="me">A mí</option>
                        </select>
                    </label>
                    <label v-if="labels.length" class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Etiqueta</span>
                        <select class="field w-auto" data-testid="filter-label" :value="filters.label ?? ''" @change="setFilter({ label: $event.target.value || null })">
                            <option value="">Todas</option>
                            <option v-for="l in labels" :key="l" :value="l">{{ l }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" :checked="filters.unread" @change="setFilter({ unread: $event.target.checked })" />
                        Solo no leídos ({{ unread_count }})
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" :checked="filters.hidden === true" @change="setFilter({ hidden: $event.target.checked ? 1 : null })" />
                        Solo ocultos
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" :checked="filters.has_notes === true" data-testid="filter-has-notes" @change="setFilter({ has_notes: $event.target.checked ? 1 : null })" />
                        Solo con nota
                    </label>
                    <span class="ms-auto flex items-center gap-2">
                        <button type="button" class="btn btn-secondary" @click="readAll">Marcar todo como leído</button>
                        <button type="button" class="btn btn-secondary" @click="sync">Actualizar</button>
                    </span>
                </div>

                <div v-if="list.length" class="card flex flex-wrap items-center gap-3 p-3 text-sm" data-testid="batch-bar">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" data-testid="select-all" :checked="allSelected" @change="toggleAll($event.target.checked)" />
                        Seleccionar todos
                    </label>
                    <span class="text-xs text-slate-600" role="status">{{ selected.length }} seleccionados</span>
                    <button type="button" class="btn btn-secondary" data-testid="batch-read" :disabled="!selected.length" @click="batch('read')">Marcar leídos</button>
                    <button type="button" class="btn btn-secondary" data-testid="batch-close" :disabled="!selected.length" @click="batch('close')">Cerrar</button>
                    <button type="button" class="btn btn-secondary" data-testid="batch-reopen" :disabled="!selected.length" @click="batch('reopen')">Reabrir</button>
                </div>

                <section class="card">
                    <p v-if="list.length === 0" class="p-5 text-sm text-slate-500">No hay comentarios que mostrar. Se sincronizan cada pocos minutos desde tus publicaciones ya publicadas.</p>
                    <ul v-else class="divide-y">
                        <li v-for="c in list" :key="c.id" class="space-y-2 px-5 py-4 text-sm" :class="c.read ? '' : 'bg-indigo-50/40'">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <input v-model="selected" type="checkbox" :value="c.id" class="mt-1" data-testid="select-comment" :aria-label="`Seleccionar comentario de ${c.author || 'anónimo'}`" />
                                    <NetworkIcon :network="c.network" size="sm" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900">
                                            {{ c.author || 'Anónimo' }}
                                            <span v-if="!c.read" class="ms-1 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">nuevo</span>
                                            <span v-if="c.notes.length" class="ms-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900" data-testid="note-badge">con nota</span>
                                            <span v-if="c.hidden" class="ms-1 rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-700">oculto</span>
                                            <span v-if="c.closed" class="ms-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-900" data-testid="closed-badge">cerrado</span>
                                            <span v-if="c.is_reply" class="ms-1 text-xs font-normal text-slate-500">(respuesta en un hilo)</span>
                                        </p>
                                        <p class="whitespace-pre-line text-slate-700">{{ c.text }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ fmt(c.commented_at) }} · {{ c.account }}
                                            <template v-if="c.post_text"> · en «{{ c.post_text }}»</template>
                                            <a v-if="c.post_url" :href="c.post_url" target="_blank" rel="noopener noreferrer" class="ms-1 underline">ver publicación</a>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 text-xs">
                                    <button type="button" class="btn btn-secondary" @click="openReply(c)">Responder</button>
                                    <button type="button" class="underline" @click="toggleRead(c)">{{ c.read ? 'Marcar no leído' : 'Marcar leído' }}</button>
                                    <button type="button" class="underline" data-testid="toggle-close" @click="toggleClose(c)">{{ c.closed ? 'Reabrir' : 'Cerrar' }}</button>
                                    <button v-if="c.can_hide" type="button" class="text-red-600 underline" @click="toggleHide(c)">{{ c.hidden ? 'Mostrar' : 'Ocultar' }}</button>
                                </div>
                            </div>

                            <ul v-if="c.my_replies.length" class="ms-8 space-y-1 border-s-2 border-teal-300 ps-3">
                                <li v-for="r in c.my_replies" :key="r.id" class="text-slate-600"><span class="text-xs text-teal-700">Tú · {{ fmt(r.at) }}</span><br />{{ r.text }}</li>
                            </ul>

                            <div class="ms-8 flex flex-wrap items-center gap-2 text-xs" data-testid="triage">
                                <span v-for="l in c.labels" :key="l" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-slate-800" data-testid="label-chip">
                                    {{ l }}
                                    <button type="button" class="font-bold" :aria-label="`Quitar etiqueta ${l}`" @click="removeLabel(c, l)">×</button>
                                </span>
                                <form class="flex items-center gap-1" @submit.prevent="addLabel(c)">
                                    <label>
                                        <span class="sr-only">Nueva etiqueta</span>
                                        <input v-model="newLabel[c.id]" type="text" maxlength="40" placeholder="Etiqueta" data-testid="label-input" class="field w-28 py-1 text-xs" />
                                    </label>
                                    <button type="submit" class="underline" data-testid="label-add">Añadir</button>
                                </form>
                                <label v-if="assignees.length" class="flex items-center gap-1">
                                    <span class="text-slate-600">Asignado a</span>
                                    <select class="field w-auto py-1 text-xs" data-testid="assign-select" :value="c.assigned_user_id ?? ''" @change="assign(c, $event.target.value)">
                                        <option value="">Nadie</option>
                                        <option v-for="a in assignees" :key="a.id" :value="a.id">{{ a.name }}</option>
                                    </select>
                                </label>
                            </div>

                            <CommentNotes :comment="c" :note-max="note_max" />

                            <form v-if="replying[c.id]" class="ms-8 space-y-2" @submit.prevent="sendReply(c)">
                                <label class="block">
                                    <span class="sr-only">Respuesta</span>
                                    <textarea v-model="form.text" rows="2" maxlength="2200" required class="field" placeholder="Escribe tu respuesta…"></textarea>
                                </label>
                                <InputError :message="form.errors.text || form.errors.account" />
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Enviar respuesta</button>
                                    <label v-if="saved_replies.length" class="flex items-center gap-1 text-xs">
                                        <span class="text-slate-600">Respuesta guardada</span>
                                        <select class="field w-auto py-1 text-xs" data-testid="saved-select" @change="useSaved($event.target.value)">
                                            <option value="">Elegir…</option>
                                            <option v-for="r in saved_replies" :key="r.id" :value="r.id">{{ r.title }}</option>
                                        </select>
                                    </label>
                                    <button type="button" class="text-xs underline" data-testid="save-current" :disabled="!form.text.trim()" @click="saveCurrent">Guardar como respuesta</button>
                                </div>
                            </form>
                        </li>
                    </ul>
                    <nav v-if="comments?.next_page_url || comments?.prev_page_url" class="flex justify-between border-t px-5 py-3 text-sm" aria-label="Paginación">
                        <Link v-if="comments.prev_page_url" :href="comments.prev_page_url" preserve-scroll class="underline">Anteriores</Link>
                        <span v-else></span>
                        <Link v-if="comments.next_page_url" :href="comments.next_page_url" preserve-scroll class="underline">Siguientes</Link>
                    </nav>
                </section>

                <section class="card space-y-3 p-5 text-sm" data-testid="saved-replies">
                    <h2 class="text-base font-semibold text-slate-900">Respuestas guardadas</h2>
                    <ul v-if="saved_replies.length" class="space-y-1">
                        <li v-for="r in saved_replies" :key="r.id" class="flex flex-wrap items-center justify-between gap-2" data-testid="saved-item">
                            <span class="min-w-0"><strong>{{ r.title }}</strong> <span class="text-slate-600">{{ r.body.slice(0, 80) }}</span></span>
                            <button type="button" class="text-xs text-red-700 underline" :aria-label="`Eliminar la respuesta guardada ${r.title}`" @click="removeSaved(r)">Eliminar</button>
                        </li>
                    </ul>
                    <p v-else class="text-slate-600">Aún no tienes ninguna. Guarda una desde el cuadro de respuesta o créala aquí.</p>
                    <form class="space-y-2" @submit.prevent="saveReply">
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-600">Título</span>
                            <input v-model="savedForm.title" type="text" maxlength="60" required class="field max-w-xs" data-testid="saved-title" />
                        </label>
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-600">Texto</span>
                            <textarea v-model="savedForm.body" rows="2" maxlength="2200" required class="field" data-testid="saved-body"></textarea>
                        </label>
                        <InputError :message="savedForm.errors.title || savedForm.errors.body" />
                        <button type="submit" class="btn btn-secondary" data-testid="saved-add" :disabled="savedForm.processing">Guardar respuesta</button>
                    </form>
                </section>

                <section class="card p-5 text-sm" data-testid="coverage">
                    <h2 class="text-base font-semibold text-slate-900">Cobertura por red</h2>
                    <ul class="mt-2 space-y-1">
                        <li v-for="c in coverage" :key="c.network" class="flex flex-wrap gap-x-2" :data-testid="`coverage-${c.network}`">
                            <strong class="w-24 shrink-0">{{ c.label }}</strong>
                            <span class="font-medium" :class="c.read ? 'text-emerald-800' : 'text-slate-700'">{{ c.read ? (c.hide ? 'Leer, responder y ocultar' : 'Leer y responder') : 'No disponible' }}</span>
                            <span class="min-w-0 text-slate-600">{{ c.note }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
