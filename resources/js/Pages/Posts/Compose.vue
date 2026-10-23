<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AccountsSection from '@/Components/Composer/AccountsSection.vue';
import TextSection from '@/Components/Composer/TextSection.vue';
import MediaSection from '@/Components/Composer/MediaSection.vue';
import ScheduleSection from '@/Components/Composer/ScheduleSection.vue';
import ActionBar from '@/Components/Composer/ActionBar.vue';
import PostStatusHeader from '@/Components/Composer/PostStatusHeader.vue';
import PreviewPanel from '@/Components/Composer/PreviewPanel.vue';
import LibraryPanel from '@/Components/Composer/LibraryPanel.vue';
import TargetStatusPanel from '@/Components/Composer/TargetStatusPanel.vue';
import { useComposer } from '@/Composables/useComposer';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    brand: Object,
    accounts: Array,
    contentTypes: Object,
    post: Object,
    flash: Object,
});

// El estado vive en `useComposer` y se comparte con los componentes por provide/inject.
const { submit } = useComposer(props);
</script>

<template>
    <Head :title="post ? 'Editar publicación' : 'Nueva publicación'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900">
                    {{ post ? 'Editar publicación' : 'Nueva publicación' }} · {{ brand.name }}
                </h1>
                <Link :href="route('brands.show', brand.id)" class="link text-sm">Volver a la marca</Link>
            </div>
        </template>

        <div>
            <div class="grid gap-6 lg:grid-cols-3">
                <form class="space-y-6 lg:col-span-2" @submit.prevent="submit(true)">
                    <p v-if="flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                    <PostStatusHeader />
                    <AccountsSection />
                    <TextSection />
                    <MediaSection />
                    <ScheduleSection />
                    <ActionBar />
                </form>

                <!-- Previsualización y estado por red -->
                <aside class="space-y-4">
                    <PreviewPanel />
                    <LibraryPanel />
                    <TargetStatusPanel />
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
