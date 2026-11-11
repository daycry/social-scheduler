<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { workflowError, workflowRequest } from '@/Composables/useWorkflow';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Aprobaciones (T-19): bandeja de publicaciones pendientes que puedes decidir (las tuyas y las de las marcas donde eres aprobador),
 * las tuyas ya decididas y la gestión de aprobadores invitados por marca. Aprobar/rechazar son idempotentes; rechazar exige comentario.
 */
const props = defineProps({
    pending: { type: Array, default: () => [] },
    mine: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
});

const message = ref('');
const error = ref('');
const busy = ref(false);
const rejecting = ref(null);
const comment = ref('');
const invite = ref({}); // por marca: email

const done = (text) => {
    message.value = text;
    error.value = '';
    router.reload({ preserveScroll: true, only: ['pending', 'mine', 'brands'] });
};
const fail = (result) => {
    error.value = workflowError(result);
    message.value = '';
};

const approve = async (post) => {
    if (busy.value) return;
    busy.value = true;
    const result = await workflowRequest('POST', route('workflow.approval.approve', post.id), { signature: post.content_signature });
    busy.value = false;
    result.ok ? done('Publicación aprobada.') : fail(result);
};
const startReject = (post) => {
    rejecting.value = post.id;
    comment.value = '';
};
const reject = async (post) => {
    if (busy.value) return;
    busy.value = true;
    const result = await workflowRequest('POST', route('workflow.approval.reject', post.id), { comment: comment.value, signature: post.content_signature });
    busy.value = false;
    if (result.ok) {
        rejecting.value = null;
        done('Publicación rechazada.');
    } else {
        fail(result);
    }
};

const sendInvite = async (brand) => {
    const email = (invite.value[brand.id] ?? '').trim();
    if (!email || busy.value) return;
    busy.value = true;
    const result = await workflowRequest('POST', route('workflow.collaborators.store', brand.id), { email, role: 'aprobador' });
    busy.value = false;
    if (result.ok) {
        invite.value = { ...invite.value, [brand.id]: '' };
        done(result.data?.message ?? 'Invitación enviada.');
    } else {
        fail(result);
    }
};
const revoke = async (brand, collaborator) => {
    if (busy.value || !confirm(`¿Quitar a ${collaborator.name ?? collaborator.email} como aprobador?`)) return;
    busy.value = true;
    const result = await workflowRequest('DELETE', route('workflow.collaborators.destroy', [brand.id, collaborator.id]));
    busy.value = false;
    result.ok ? done('Aprobador retirado.') : fail(result);
};
const when = (local) => (local ? local.replace('T', ' ') : 'Sin fecha');
</script>

<template>
    <Head title="Aprobaciones" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Aprobaciones</h1>
        </template>

        <div class="max-w-4xl space-y-6">
            <p v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-testid="approvals-message">{{ message }}</p>
            <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert" data-testid="approvals-error">{{ error }}</p>

            <section aria-labelledby="pending-title">
                <h2 id="pending-title" class="mb-2 text-base font-semibold text-slate-900">Pendientes de aprobar</h2>
                <EmptyState v-if="pending.length === 0" title="No hay nada pendiente" description="Cuando alguien pida tu aprobación, la publicación aparecerá aquí." />
                <ul v-else class="space-y-3">
                    <li v-for="p in pending" :key="p.id" class="card p-4" :data-testid="`pending-${p.id}`">
                        <p class="line-clamp-3 whitespace-pre-line text-sm text-slate-900">{{ p.text || '(sin texto)' }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ p.brand_name }} · {{ p.networks.join(', ') || 'sin destinos' }} · {{ when(p.scheduled_at_local) }} · pide {{ p.requested_by }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" class="btn btn-primary px-3 py-1 text-sm" :disabled="busy" :aria-label="`Aprobar la publicación ${p.id}`" data-testid="approve" @click="approve(p)">Aprobar</button>
                            <button type="button" class="btn btn-secondary px-3 py-1 text-sm" :disabled="busy" :aria-label="`Rechazar la publicación ${p.id}`" data-testid="reject-start" @click="startReject(p)">Rechazar</button>
                            <Link :href="route('posts.edit', p.id)" class="link text-sm">Ver detalle</Link>
                        </div>
                        <form v-if="rejecting === p.id" class="mt-3 space-y-2" @submit.prevent="reject(p)">
                            <label :for="`reject-comment-${p.id}`" class="block text-sm font-medium text-slate-800">Motivo del rechazo</label>
                            <textarea :id="`reject-comment-${p.id}`" v-model="comment" rows="3" maxlength="2000" class="field" required data-testid="reject-comment"></textarea>
                            <div class="flex gap-2">
                                <button type="submit" class="btn btn-primary px-3 py-1 text-sm" :disabled="busy || !comment.trim()" data-testid="reject-confirm">Confirmar rechazo</button>
                                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="rejecting = null">Cancelar</button>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>

            <section v-if="mine.length" aria-labelledby="mine-title">
                <h2 id="mine-title" class="mb-2 text-base font-semibold text-slate-900">Tus decisiones recientes</h2>
                <ul class="space-y-2">
                    <li v-for="p in mine" :key="p.id" class="card p-3 text-sm">
                        <span class="font-medium" :class="p.approval_status === 'aprobado' ? 'text-emerald-800' : 'text-red-800'">{{ p.approval_status === 'aprobado' ? 'Aprobada' : 'Rechazada' }}</span>
                        · {{ p.text || '(sin texto)' }}
                        <span v-if="p.approval_comment" class="block text-xs text-slate-600">Motivo: {{ p.approval_comment }}</span>
                        <Link :href="route('posts.edit', p.id)" class="link ms-2 text-xs">Abrir</Link>
                    </li>
                </ul>
            </section>

            <section v-if="brands.length" aria-labelledby="team-title">
                <h2 id="team-title" class="mb-2 text-base font-semibold text-slate-900">Aprobadores por marca</h2>
                <p class="mb-3 text-xs text-slate-600">Invita por email a alguien que ya tenga cuenta. Siempre verás el mismo mensaje, exista o no la cuenta.</p>
                <div class="space-y-4">
                    <article v-for="b in brands" :key="b.id" class="card p-4" :data-testid="`brand-${b.id}`">
                        <h3 class="text-sm font-medium text-slate-900">{{ b.name }}</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="c in b.collaborators" :key="c.id" class="flex items-center justify-between gap-2">
                                <span class="min-w-0 truncate">{{ c.name }} <span class="text-xs text-slate-600">{{ c.email }} · aprobador</span></span>
                                <button type="button" class="text-xs font-medium text-red-700 underline" :aria-label="`Quitar a ${c.email}`" @click="revoke(b, c)">Quitar</button>
                            </li>
                            <li v-if="b.collaborators.length === 0" class="text-xs text-slate-600">Solo tú apruebas las publicaciones de esta marca.</li>
                        </ul>
                        <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="sendInvite(b)">
                            <div class="min-w-0 flex-1">
                                <label :for="`invite-${b.id}`" class="block text-xs font-medium text-slate-700">Email del aprobador</label>
                                <input :id="`invite-${b.id}`" v-model="invite[b.id]" type="email" class="field w-full" autocomplete="off" data-testid="invite-email" />
                            </div>
                            <button type="submit" class="btn btn-secondary px-3 py-1.5 text-sm" :disabled="busy" data-testid="invite-send">Invitar</button>
                        </form>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
