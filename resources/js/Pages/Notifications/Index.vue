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
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Avisos</h2>
                <SecondaryButton type="button" @click="markAll">Marcar todo como leído</SecondaryButton>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-3 sm:px-6 lg:px-8">
                <p v-if="notifications.length === 0" class="rounded-lg bg-white p-6 text-gray-600 shadow-sm">No tienes avisos.</p>
                <article v-for="n in notifications" :key="n.id" class="rounded-lg bg-white p-4 shadow-sm" :class="n.read ? 'opacity-70' : 'border-l-4 border-red-400'">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">{{ title(n) }}</h3>
                            <p class="text-sm text-gray-700">{{ n.message }}</p>
                            <p v-if="n.excerpt" class="mt-1 text-xs text-gray-500">«{{ n.excerpt }}»<span v-if="n.scheduled_at"> · programada para {{ n.scheduled_at }}</span></p>
                            <p v-if="n.brand_name || n.account_name" class="text-xs text-gray-500">{{ n.brand_name }} {{ n.account_name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-sm">
                            <Link v-if="n.post_id" :href="route('posts.edit', n.post_id)" class="text-indigo-600">Ver publicación</Link>
                            <Link v-else-if="n.brand_id" :href="route('brands.connections.index', n.brand_id)" class="text-indigo-600">Conexiones</Link>
                            <button v-if="!n.read" type="button" class="text-gray-600" @click="markRead(n.id)">Marcar leído</button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
