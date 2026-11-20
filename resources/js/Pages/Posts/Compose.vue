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
import DuplicatePost from '@/Components/Composer/DuplicatePost.vue';
import { provideAiStatus } from '@/Composables/useAiStatus';
import { useComposer } from '@/Composables/useComposer';
import { Head } from '@inertiajs/vue3';
import { composeProps } from './compose-props';

const props = defineProps(composeProps);

// El estado vive en `useComposer` y se comparte con los componentes por provide/inject.
provideAiStatus();
const { submit, form } = useComposer(props);
</script>

<template>
    <Head :title="post ? 'Editar publicación' : 'Nueva publicación'" />

    <ComposerLayout>
        <template #header>
            <ComposerHeader>
                <DuplicatePost
                    v-if="post"
                    :post-id="post.id"
                    :brand-id="brand.id"
                    :dirty="form.isDirty"
                    :saving="form.processing"
                />
            </ComposerHeader>
        </template>

        <div>
            <p
                v-if="bestTimeNotice"
                role="alert"
                data-testid="best-time-notice"
                class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >{{ bestTimeNotice }}</p
            >
            <div class="grid gap-6 lg:grid-cols-3">
                <form class="min-w-0 space-y-6 lg:col-span-2" @submit.prevent="submit(true)">
                    <PostStatusHeader />
                    <NetworkPicker />
                    <TextSection />
                    <MediaSection :studio-origin-url="post?.studio_origin_url" :csrf-token="csrfToken" />
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
