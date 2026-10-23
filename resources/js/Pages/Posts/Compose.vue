<script setup>
import ComposerLayout from '@/Layouts/ComposerLayout.vue';
import ComposerHeader from '@/Components/Composer/ComposerHeader.vue';
import NetworkPicker from '@/Components/Composer/NetworkPicker.vue';
import TargetOptions from '@/Components/Composer/TargetOptions.vue';
import TextSection from '@/Components/Composer/TextSection.vue';
import MediaSection from '@/Components/Composer/MediaSection.vue';
import ScheduleSection from '@/Components/Composer/ScheduleSection.vue';
import ErrorsPanel from '@/Components/Composer/ErrorsPanel.vue';
import ActionBar from '@/Components/Composer/ActionBar.vue';
import NotesPanel from '@/Components/Composer/NotesPanel.vue';
import PostStatusHeader from '@/Components/Composer/PostStatusHeader.vue';
import PreviewPanel from '@/Components/Composer/PreviewPanel.vue';
import TargetStatusPanel from '@/Components/Composer/TargetStatusPanel.vue';
import UnsavedGuard from '@/Components/Composer/UnsavedGuard.vue';
import { useComposer } from '@/Composables/useComposer';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    brand: Object,
    accounts: Array,
    contentTypes: Object,
    typeLabels: { type: Object, default: () => ({}) },
    limits: Object,
    presets: Object,
    post: Object,
    flash: Object,
});

// El estado vive en `useComposer` y se comparte con los componentes por provide/inject.
const { submit } = useComposer(props);
</script>

<template>
    <Head :title="post ? 'Editar publicación' : 'Nueva publicación'" />

    <ComposerLayout>
        <template #header>
            <ComposerHeader />
        </template>

        <div>
            <div class="grid gap-6 lg:grid-cols-3">
                <form class="space-y-6 lg:col-span-2" @submit.prevent="submit(true)">
                    <p v-if="flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                    <PostStatusHeader />
                    <NetworkPicker />
                    <TextSection />
                    <MediaSection />
                    <TargetOptions />
                    <ScheduleSection />
                    <NotesPanel />
                    <ErrorsPanel />
                    <ActionBar />
                </form>

                <!-- Previsualización y estado por red -->
                <aside class="space-y-4">
                    <PreviewPanel />
                    <TargetStatusPanel />
                </aside>
            </div>
        </div>
        <UnsavedGuard />
    </ComposerLayout>
</template>
