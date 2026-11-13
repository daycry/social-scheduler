<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ notifications: Array });

const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });
const markAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
const WORKFLOW = {
    approval_requested: 'Aprobación pendiente',
    approval_approved: 'Publicación aprobada',
    approval_rejected: 'Publicación rechazada',
    manual_due: 'Toca publicar a mano',
    collaborator_invited: 'Invitación para aprobar',
    collaborator_revoked: 'Acceso retirado',
    series_failed: 'Una serie no se ha podido generar',
    media_missing: 'Falta un archivo en una publicación',
    studio_design_shared: 'Te han compartido un diseño',
    studio_design_role: 'Ha cambiado tu rol en un diseño',
    studio_design_revoked: 'Te han retirado el acceso a un diseño',
    studio_design_comment: 'Comentario nuevo en un diseño',
};
// Texto del enlace genérico (`n.url`) por tipo; el resto abre con «Abrir».
const LINK = {
    series_failed: 'Ver series',
    connection_expiring: 'Reconectar',
    studio_design_shared: 'Abrir diseño',
    studio_design_role: 'Abrir diseño',
    studio_design_comment: 'Abrir diseño',
    job_failed_report: 'Ver informes',
    job_failed_studio_export: 'Abrir diseño',
    job_failed_studio_ai: 'Abrir el estudio',
    job_failed_canva: 'Abrir Canva',
    job_failed_import: 'Ver biblioteca',
    job_failed_summary: 'Ver detalle',
    job_done_studio_export: 'Abrir diseño',
    job_done_studio_ai: 'Abrir el estudio',
    job_done_canva: 'Abrir Canva',
    job_done_import: 'Ver biblioteca',
};
// Un aviso de una publicación lleva siempre «Ver publicación» (aunque traiga también `url`); el resto, la etiqueta de su tipo.
const linkLabel = (n) => (n.post_id ? 'Ver publicación' : (LINK[n.type] ?? 'Abrir'));
const title = (n) => WORKFLOW[n.type] ?? (n.type === 'connection_expiring' ? `Reconecta ${n.network_label ?? 'tu cuenta'} antes de que caduque` : (n.type?.startsWith('job_failed_') || n.type?.startsWith('job_done_')) && n.title ? n.title : n.type === 'publish_failed' ? `No se pudo publicar en ${n.network_label}` : n.type === 'first_comment_failed' ? `No se pudo dejar el primer comentario en ${n.network_label}` : n.type === 'reconnect_required' ? `Reconecta ${n.network_label ?? 'tu cuenta'}` : 'Aviso');
</script>

<template>
    <Head title="Avisos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900">Avisos</h1>
                <SecondaryButton type="button" @click="markAll">Marcar todo como leído</SecondaryButton>
            </div>
        </template>

        <div>
            <div class="max-w-3xl space-y-3">
                <p v-if="notifications.length === 0" class="card p-6 text-slate-600">No tienes avisos.</p>
                <article v-for="n in notifications" :key="n.id" class="card p-4" :class="n.read ? 'opacity-70' : (n.type?.startsWith('job_done_') ? 'border-l-4 border-emerald-400' : 'border-l-4 border-red-400')">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-medium text-slate-900">{{ title(n) }}</h2>
                            <p class="text-sm text-slate-700">{{ n.message }}</p>
                            <p v-if="n.excerpt" class="mt-1 text-xs text-slate-500">«{{ n.excerpt }}»<span v-if="n.scheduled_at"> · programada para {{ n.scheduled_at }}</span></p>
                            <p v-if="n.brand_name || n.account_name" class="text-xs text-slate-500">{{ n.brand_name }} {{ n.account_name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-sm">
                            <Link v-if="n.type === 'manual_due' && n.url" :href="n.url" class="link" data-testid="manual-notice-link">Abrir aviso</Link>
                            <Link v-else-if="n.type === 'approval_requested' && n.url" :href="n.url" class="link">Revisar</Link>
                            <Link v-else-if="n.url" :href="n.url" class="link" :data-testid="`notice-link-${n.type}`">{{ linkLabel(n) }}</Link>
                            <Link v-else-if="n.post_id" :href="route('posts.edit', n.post_id)" class="link">Ver publicación</Link>
                            <Link v-else-if="n.brand_id" :href="route('brands.connections.index', n.brand_id)" class="link">Conexiones</Link>
                            <button v-if="!n.read" type="button" class="text-slate-600" @click="markRead(n.id)">Marcar leído</button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
