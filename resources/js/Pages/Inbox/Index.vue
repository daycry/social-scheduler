<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import CommentNotes from '@/Components/Inbox/CommentNotes.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/** Inbox (PA-10): comentarios de publicaciones propias de Instagram y Facebook. Sin mensajes directos. */
const props = defineProps({
    brand: Object,
    filters: Object,
    unread_count: Number,
    comments: Object,
    note_max: { type: Number, default: 2000 },
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
                description="Conecta una cuenta de Instagram o una Página de Facebook para leer y responder los comentarios de tus publicaciones."
                :cards="[
                    { title: 'Un solo buzón', text: 'Comentarios de Instagram y Facebook juntos.', kind: 'inbox' },
                    { title: 'Responde y modera', text: 'Contesta u oculta sin salir de la herramienta.', kind: 'report' },
                    { title: 'Filtra', text: 'Por red y por no leídos.', kind: 'chart' },
                ]"
            />

            <template v-else>
                <div v-if="blocked.length" class="card border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert" data-testid="inbox-unavailable">
                    <p class="font-medium">No disponible para: {{ blocked.map((a) => a.name).join(', ') }}</p>
                    <p class="mt-1">
                        Falta el permiso de comentarios. Actívalo en los casos de uso de la app de Meta (<code>instagram_manage_comments</code>, <code>pages_read_user_content</code> y
                        <code>pages_manage_engagement</code>) y vuelve a conectar la cuenta.
                    </p>
                </div>
                <p v-if="reconnect.length" class="text-sm text-amber-800" role="alert">Reconecta {{ reconnect.map((a) => a.name).join(', ') }} para seguir sincronizando.</p>

                <div class="card flex flex-wrap items-center gap-3 p-4 text-sm">
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-500">Red</span>
                        <select class="field w-auto" :value="filters.network ?? ''" @change="setFilter({ network: $event.target.value || null })">
                            <option value="">Todas</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
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

                <section class="card">
                    <p v-if="list.length === 0" class="p-5 text-sm text-slate-500">No hay comentarios que mostrar. Se sincronizan cada pocos minutos desde tus publicaciones ya publicadas.</p>
                    <ul v-else class="divide-y">
                        <li v-for="c in list" :key="c.id" class="space-y-2 px-5 py-4 text-sm" :class="c.read ? '' : 'bg-indigo-50/40'">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <NetworkIcon :network="c.network" size="sm" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900">
                                            {{ c.author || 'Anónimo' }}
                                            <span v-if="!c.read" class="ms-1 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">nuevo</span>
                                            <span v-if="c.notes.length" class="ms-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900" data-testid="note-badge">con nota</span>
                                            <span v-if="c.hidden" class="ms-1 rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-700">oculto</span>
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
                                    <button type="button" class="text-red-600 underline" @click="toggleHide(c)">{{ c.hidden ? 'Mostrar' : 'Ocultar' }}</button>
                                </div>
                            </div>

                            <ul v-if="c.my_replies.length" class="ms-8 space-y-1 border-s-2 border-teal-300 ps-3">
                                <li v-for="r in c.my_replies" :key="r.id" class="text-slate-600"><span class="text-xs text-teal-700">Tú · {{ fmt(r.at) }}</span><br />{{ r.text }}</li>
                            </ul>

                            <CommentNotes :comment="c" :note-max="note_max" />

                            <form v-if="replying[c.id]" class="ms-8 space-y-2" @submit.prevent="sendReply(c)">
                                <label class="block">
                                    <span class="sr-only">Respuesta</span>
                                    <textarea v-model="form.text" rows="2" maxlength="2200" required class="field" placeholder="Escribe tu respuesta…"></textarea>
                                </label>
                                <InputError :message="form.errors.text || form.errors.account" />
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">Enviar respuesta</button>
                            </form>
                        </li>
                    </ul>
                    <nav v-if="comments?.next_page_url || comments?.prev_page_url" class="flex justify-between border-t px-5 py-3 text-sm" aria-label="Paginación">
                        <Link v-if="comments.prev_page_url" :href="comments.prev_page_url" preserve-scroll class="underline">Anteriores</Link>
                        <span v-else></span>
                        <Link v-if="comments.next_page_url" :href="comments.next_page_url" preserve-scroll class="underline">Siguientes</Link>
                    </nav>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
