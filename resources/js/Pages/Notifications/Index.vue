<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ notifications: Array });

const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });
const markAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
const title = (n) => (n.type === 'publish_failed' ? `No se pudo publicar en ${n.network_label}` : n.type === 'reconnect_required' ? `Reconecta ${n.network_label ?? 'tu cuenta'}` : 'Aviso');
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
                <article v-for="n in notifications" :key="n.id" class="card p-4" :class="n.read ? 'opacity-70' : 'border-l-4 border-red-400'">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-slate-900">{{ title(n) }}</h3>
                            <p class="text-sm text-slate-700">{{ n.message }}</p>
                            <p v-if="n.excerpt" class="mt-1 text-xs text-slate-500">«{{ n.excerpt }}»<span v-if="n.scheduled_at"> · programada para {{ n.scheduled_at }}</span></p>
                            <p v-if="n.brand_name || n.account_name" class="text-xs text-slate-500">{{ n.brand_name }} {{ n.account_name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-sm">
                            <Link v-if="n.post_id" :href="route('posts.edit', n.post_id)" class="link">Ver publicación</Link>
                            <Link v-else-if="n.brand_id" :href="route('brands.connections.index', n.brand_id)" class="link">Conexiones</Link>
                            <button v-if="!n.read" type="button" class="text-slate-600" @click="markRead(n.id)">Marcar leído</button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
