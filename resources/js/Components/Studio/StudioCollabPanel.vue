<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { studioRequest } from '@/Studio/api';

const props = defineProps({
    designId: { type: Number, default: null },
    link: { type: Object, default: null },
    access: { type: Object, required: true },
    pages: { type: Array, default: () => [] },
    pageId: { type: String, default: null },
    elementId: { type: String, default: null },
    revision: { type: Number, default: 1 },
    initialTab: { type: String, default: 'comments' },
    idPrefix: { type: String, default: 'collab' },
});
const emit = defineEmits(['restored', 'comments']);

const guest = computed(() => !!props.link);
const tab = ref(props.initialTab);
const tabs = computed(() => [['comments', 'Comentarios'], ...(props.access.is_owner ? [['share', 'Compartir']] : []), ...(guest.value ? [] : [['versions', 'Versiones']])]);
const error = ref('');
const notice = ref('');
const busy = ref(false);

const comments = ref([]);
const showResolved = ref(false);
const body = ref('');
const anchor = ref('design');
const guestName = ref('');
const replyTo = ref(null);
const replyBody = ref('');

const shares = ref([]);
const shareEmail = ref('');
const shareRole = ref('reader');
const links = ref([]);
const linkRole = ref('reader');
const linkExpires = ref('');
const createdLink = ref(null);

const versions = ref([]);

const roles = [['reader', 'Lector'], ['commenter', 'Comentarista'], ['editor', 'Editor']];
const roleLabel = role => ({ reader: 'Lector', commenter: 'Comentarista', editor: 'Editor' })[role] ?? role;
const when = value => value ? new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '';
const url = (name, params = {}) => props.link
    ? route(`studio.link.${name}`, { token: props.link.token, ...params })
    : route(`studio.${name}`, { design: props.designId, ...params });

const threads = computed(() => {
    const roots = comments.value.filter(c => c.parent_id === null && (showResolved.value || !c.resolved));
    return roots.map(root => ({ root, replies: comments.value.filter(c => c.parent_id === root.id) }));
});
const resolvedCount = computed(() => comments.value.filter(c => c.parent_id === null && c.resolved).length);
const pageName = id => props.pages.find(page => page.id === id)?.name ?? id;
const anchorLabel = comment => comment.element_id ? `${pageName(comment.page_id)} · elemento ${comment.element_id}` : comment.page_id ? pageName(comment.page_id) : 'Todo el diseño';

async function run(action, { quiet = false } = {}) {
    if (busy.value) return false;
    busy.value = true; error.value = ''; if (!quiet) notice.value = '';
    try { await action(); return true; }
    catch (e) { error.value = e.message; return false; }
    finally { busy.value = false; }
}

async function loadComments() {
    const data = await studioRequest('GET', url('comments.index'));
    comments.value = data.comments;
    emit('comments', data.comments);
}
async function loadShare() {
    if (!props.access.is_owner || guest.value) return;
    shares.value = (await studioRequest('GET', url('shares.index'))).shares;
    links.value = (await studioRequest('GET', url('links.index'))).links;
}
async function loadVersions() {
    if (guest.value) return;
    versions.value = (await studioRequest('GET', url('versions.index'))).versions;
}
const refresh = () => run(async () => { await Promise.all([loadComments(), loadShare(), loadVersions()]); }, { quiet: true });
onMounted(refresh);
watch(() => props.revision, () => { if (!guest.value) run(loadVersions, { quiet: true }); });

function commentPayload() {
    const payload = { body: body.value };
    if (anchor.value !== 'design' && props.pageId) payload.page_id = props.pageId;
    if (anchor.value === 'element' && props.elementId) payload.element_id = props.elementId;
    if (guest.value) payload.guest_name = guestName.value;
    return payload;
}
const addComment = () => run(async () => {
    await studioRequest('POST', url('comments.store'), commentPayload());
    body.value = ''; notice.value = 'Comentario publicado.';
    await loadComments();
});
const sendReply = parent => run(async () => {
    await studioRequest('POST', url('comments.store'), { body: replyBody.value, parent_id: parent.id, ...(guest.value ? { guest_name: guestName.value } : {}) });
    replyBody.value = ''; replyTo.value = null; notice.value = 'Respuesta publicada.';
    await loadComments();
});
const setResolved = (comment, resolved) => run(async () => {
    await studioRequest('POST', url(resolved ? 'comments.resolve' : 'comments.reopen', { comment: comment.id }));
    notice.value = resolved ? 'Hilo resuelto.' : 'Hilo reabierto.';
    await loadComments();
});
const removeComment = comment => run(async () => {
    await studioRequest('DELETE', url('comments.destroy', { comment: comment.id }));
    notice.value = 'Comentario eliminado.';
    await loadComments();
});

const share = () => run(async () => {
    await studioRequest('POST', url('shares.store'), { email: shareEmail.value, role: shareRole.value });
    notice.value = `Diseño compartido con ${shareEmail.value}.`; shareEmail.value = '';
    await loadShare();
});
const changeRole = (item, role) => run(async () => {
    await studioRequest('PATCH', url('shares.update', { share: item.id }), { role });
    notice.value = 'Rol actualizado.';
    await loadShare();
});
const revokeShare = item => run(async () => {
    await studioRequest('DELETE', url('shares.destroy', { share: item.id }));
    notice.value = `Acceso de ${item.email} revocado.`;
    await loadShare();
});
const createLink = () => run(async () => {
    const payload = { role: linkRole.value };
    if (linkExpires.value) payload.expires_at = new Date(`${linkExpires.value}T23:59:59`).toISOString();
    createdLink.value = (await studioRequest('POST', url('links.store'), payload)).link;
    linkExpires.value = '';
    await loadShare();
});
const revokeLink = item => run(async () => {
    await studioRequest('DELETE', url('links.destroy', { link: item.id }));
    notice.value = 'Enlace revocado: ya no da acceso.';
    if (createdLink.value?.id === item.id) createdLink.value = null;
    await loadShare();
});
async function copyLink() {
    try { await navigator.clipboard.writeText(createdLink.value.url); notice.value = 'Enlace copiado.'; }
    catch { notice.value = 'Selecciona el enlace y cópialo manualmente.'; }
}
const restore = version => run(async () => {
    const result = await studioRequest('POST', url('versions.restore', { version: version.revision }), { revision: props.revision });
    notice.value = `Versión ${version.revision} restaurada como una versión nueva.`;
    emit('restored', result.design);
    await loadVersions();
});
</script>

<template>
    <div class="space-y-4 text-sm">
        <div class="flex gap-1 rounded-lg bg-slate-100 p-1" role="tablist" aria-label="Colaboración">
            <button v-for="[id, label] in tabs" :key="id" type="button" role="tab" :aria-selected="tab === id" class="flex-1 rounded-md px-2 py-2 text-xs font-medium" :class="tab === id ? 'bg-white shadow-sm text-slate-900' : 'text-slate-600'" @click="tab = id">{{ label }}</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">{{ error }}</p>
        <p v-if="notice" role="status" class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">{{ notice }}</p>

        <section v-show="tab === 'comments'" class="space-y-4" aria-label="Comentarios del diseño">
            <label v-if="resolvedCount" class="flex items-center gap-2 text-xs text-slate-600"><input v-model="showResolved" type="checkbox" class="rounded border-slate-300" /> Mostrar hilos resueltos ({{ resolvedCount }})</label>
            <p v-if="!threads.length" class="rounded-lg bg-slate-50 px-3 py-4 text-xs text-slate-500">{{ comments.length ? 'No hay hilos abiertos.' : 'Todavía no hay comentarios.' }}</p>
            <ul class="space-y-3">
                <li v-for="thread in threads" :key="thread.root.id" data-testid="studio-comment-thread" class="space-y-2 rounded-xl border border-slate-200 p-3" :class="thread.root.resolved ? 'bg-slate-50' : 'bg-white'">
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500"><span class="rounded-full bg-violet-50 px-2 py-0.5 font-medium text-violet-800">{{ anchorLabel(thread.root) }}</span><span v-if="thread.root.resolved" class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-800">Resuelto{{ thread.root.resolved_by ? ` por ${thread.root.resolved_by}` : '' }}</span></div>
                    <article v-for="item in [thread.root, ...thread.replies]" :key="item.id" class="space-y-1">
                        <p class="text-[11px] text-slate-500"><strong class="font-semibold text-slate-700">{{ item.author_name }}</strong><span v-if="item.is_guest"> (invitado)</span> · {{ when(item.created_at) }}</p>
                        <p class="whitespace-pre-wrap break-words text-sm text-slate-800">{{ item.body }}</p>
                        <button v-if="item.can_delete" type="button" class="text-[11px] font-medium text-red-700 hover:underline" :disabled="busy" @click="removeComment(item)">Eliminar<span class="sr-only"> comentario de {{ item.author_name }}</span></button>
                    </article>
                    <div class="flex flex-wrap gap-3 pt-1 text-xs font-medium">
                        <button v-if="access.can_comment" type="button" class="text-violet-800 hover:underline" @click="replyTo = replyTo === thread.root.id ? null : thread.root.id">Responder</button>
                        <button v-if="thread.root.can_resolve && !thread.root.resolved" type="button" class="text-emerald-800 hover:underline" :disabled="busy" @click="setResolved(thread.root, true)">Resolver</button>
                        <button v-if="thread.root.can_resolve && thread.root.resolved" type="button" class="text-slate-700 hover:underline" :disabled="busy" @click="setResolved(thread.root, false)">Reabrir</button>
                    </div>
                    <form v-if="replyTo === thread.root.id" class="space-y-2" @submit.prevent="sendReply(thread.root)">
                        <label class="block text-xs" :for="`${idPrefix}-reply-${thread.root.id}`">Respuesta</label>
                        <textarea :id="`${idPrefix}-reply-${thread.root.id}`" v-model="replyBody" rows="2" maxlength="2000" class="field w-full text-xs" required />
                        <button type="submit" class="btn-secondary text-xs" :disabled="busy || !replyBody.trim()">Publicar respuesta</button>
                    </form>
                </li>
            </ul>
            <form v-if="access.can_comment" class="space-y-2 rounded-xl border border-slate-200 p-3" @submit.prevent="addComment">
                <label v-if="guest" class="block space-y-1 text-xs" :for="`${idPrefix}-guest`">Tu nombre<input :id="`${idPrefix}-guest`" v-model="guestName" maxlength="80" class="field w-full text-xs" required /></label>
                <label class="block text-xs" :for="`${idPrefix}-body`">Nuevo comentario</label>
                <textarea :id="`${idPrefix}-body`" v-model="body" rows="3" maxlength="2000" class="field w-full text-xs" required />
                <div class="space-y-1 text-xs"><label class="block" :for="`${idPrefix}-anchor`">Dónde se aplica</label><select :id="`${idPrefix}-anchor`" v-model="anchor" class="field w-full text-xs"><option value="design">Todo el diseño</option><option value="page" :disabled="!pageId">Página actual{{ pageId ? ` (${pageName(pageId)})` : '' }}</option><option value="element" :disabled="!elementId">Elemento seleccionado{{ elementId ? ` (${elementId})` : '' }}</option></select></div>
                <button type="submit" class="btn-primary text-xs" :disabled="busy || !body.trim() || (guest && !guestName.trim())">Publicar comentario</button>
            </form>
            <p v-else class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">Tu acceso es de solo lectura: puedes ver los comentarios pero no escribir.</p>
        </section>

        <section v-if="access.is_owner && !guest" v-show="tab === 'share'" class="space-y-5" aria-label="Compartir el diseño">
            <form class="space-y-2" @submit.prevent="share">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invitar a un usuario registrado</h3>
                <label class="block space-y-1 text-xs" :for="`${idPrefix}-email`">Email<input :id="`${idPrefix}-email`" v-model="shareEmail" type="email" autocomplete="off" class="field w-full text-xs" required /></label>
                <div class="space-y-1 text-xs"><label class="block" :for="`${idPrefix}-role`">Rol</label><select :id="`${idPrefix}-role`" v-model="shareRole" class="field w-full text-xs"><option v-for="[id, label] in roles" :key="id" :value="id">{{ label }}</option></select></div>
                <button type="submit" class="btn-primary text-xs" :disabled="busy || !shareEmail.trim()">Compartir</button>
            </form>
            <ul class="space-y-2" aria-label="Personas con acceso">
                <li v-if="!shares.length" class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">Nadie más tiene acceso todavía.</li>
                <li v-for="item in shares" :key="item.id" data-testid="studio-share-row" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2 text-xs">
                    <span class="min-w-0 flex-1 truncate"><strong class="font-semibold">{{ item.name }}</strong> · {{ item.email }}</span>
                    <select :aria-label="`Rol de ${item.email}`" class="field text-xs" :value="item.role" :disabled="busy" @change="changeRole(item, $event.target.value)"><option v-for="[id, label] in roles" :key="id" :value="id">{{ label }}</option></select>
                    <button type="button" class="font-medium text-red-700 hover:underline" :disabled="busy" @click="revokeShare(item)">Quitar acceso<span class="sr-only"> a {{ item.email }}</span></button>
                </li>
            </ul>
            <form class="space-y-2 border-t border-slate-200 pt-4" @submit.prevent="createLink">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Enlace público</h3>
                <div class="space-y-1 text-xs"><label class="block" :for="`${idPrefix}-link-role`">Permiso del enlace</label><select :id="`${idPrefix}-link-role`" v-model="linkRole" class="field w-full text-xs"><option value="reader">Solo lectura</option><option value="commenter">Lectura y comentarios</option></select></div>
                <label class="block space-y-1 text-xs" :for="`${idPrefix}-link-expires`">Caduca el (opcional)<input :id="`${idPrefix}-link-expires`" v-model="linkExpires" type="date" class="field w-full text-xs" /></label>
                <button type="submit" class="btn-secondary text-xs" :disabled="busy">Crear enlace</button>
            </form>
            <div v-if="createdLink" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
                <p class="text-amber-900">Copia el enlace ahora: no se podrá volver a ver.</p>
                <label class="block" :for="`${idPrefix}-created-link`">Enlace creado</label>
                <input :id="`${idPrefix}-created-link`" data-testid="studio-created-link" readonly class="field w-full text-xs" :value="createdLink.url" @focus="$event.target.select()" />
                <button type="button" class="btn-secondary text-xs" @click="copyLink">Copiar enlace</button>
            </div>
            <ul class="space-y-2" aria-label="Enlaces creados">
                <li v-for="item in links" :key="item.id" data-testid="studio-link-row" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2 text-xs">
                    <span class="flex-1">{{ item.role === 'reader' ? 'Solo lectura' : 'Comentarios' }} · <span :class="item.status === 'active' ? 'text-emerald-800' : 'text-slate-500'">{{ item.status === 'active' ? 'Activo' : item.status === 'revoked' ? 'Revocado' : 'Caducado' }}</span><span v-if="item.expires_at"> · caduca {{ when(item.expires_at) }}</span></span>
                    <button v-if="item.status === 'active'" type="button" class="font-medium text-red-700 hover:underline" :disabled="busy" @click="revokeLink(item)">Revocar<span class="sr-only"> enlace creado {{ when(item.created_at) }}</span></button>
                </li>
            </ul>
        </section>

        <section v-if="!guest" v-show="tab === 'versions'" class="space-y-3" aria-label="Historial de versiones">
            <p class="text-xs text-slate-500">Restaurar crea una versión nueva con el contenido de la elegida; el historial no se reescribe.</p>
            <ul class="space-y-2">
                <li v-for="item in versions" :key="item.revision" data-testid="studio-version-row" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2 text-xs">
                    <span class="min-w-0 flex-1"><strong class="font-semibold">Versión {{ item.revision }}</strong><span v-if="item.current" class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">Actual</span><br /><span class="text-slate-500">{{ item.author ?? 'Autor desconocido' }} · {{ when(item.created_at) }}</span></span>
                    <button v-if="access.can_edit && !item.current" type="button" class="btn-secondary text-xs" :disabled="busy" @click="restore(item)">Restaurar<span class="sr-only"> versión {{ item.revision }}</span></button>
                </li>
            </ul>
        </section>
    </div>
</template>
