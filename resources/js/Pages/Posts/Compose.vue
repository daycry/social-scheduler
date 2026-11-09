<script setup>
import ComposerLayout from '@/Layouts/ComposerLayout.vue';
import ComposerHeader from '@/Components/Composer/ComposerHeader.vue';
import NetworkPicker from '@/Components/Composer/NetworkPicker.vue';
import TargetOptions from '@/Components/Composer/TargetOptions.vue';
import TextSection from '@/Components/Composer/TextSection.vue';
import MediaSection from '@/Components/Composer/MediaSection.vue';
import GlobalPresets from '@/Components/Composer/GlobalPresets.vue';
import ErrorsPanel from '@/Components/Composer/ErrorsPanel.vue';
import ActionBar from '@/Components/Composer/ActionBar.vue';
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
    firstCommentMax: { type: Object, default: () => ({}) },
    mediaMeta: { type: Object, default: () => ({}) },
    altMax: { type: Number, default: 1000 },
    presets: Object,
    drivePicker: { type: Object, default: null },
    giphy: { type: Object, default: () => ({ enabled: false }) },
    savedTexts: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
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
                <form class="min-w-0 space-y-6 lg:col-span-2" @submit.prevent="submit(true)">
                    <p v-if="flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                    <PostStatusHeader />
                    <NetworkPicker />
                    <TextSection />
                    <MediaSection />
                    <div class="space-y-3" data-testid="composer-presets">
                        <GlobalPresets />
                        <TargetOptions />
                    </div>
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
