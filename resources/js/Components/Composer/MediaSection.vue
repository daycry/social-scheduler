<script setup>
import MediaUploader from '@/Components/MediaUploader.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { form, editable, mediaById, move, removeMedia, onUploaded } = useComposerContext();
</script>

<template>
    <!-- Media -->
    <section class="card p-4">
        <h2 class="mb-2 font-medium text-slate-900">Imágenes y vídeos</h2>
        <ul v-if="form.media.length" class="mb-3 space-y-1">
            <li v-for="(id, i) in form.media" :key="id" class="flex items-center justify-between rounded border border-slate-200 px-2 py-1 text-sm">
                <span>{{ i + 1 }}. {{ mediaById(id)?.name ?? `Archivo ${id}` }}
                    <span class="text-slate-500">({{ mediaById(id)?.kind === 'video' ? `vídeo ${mediaById(id)?.duration ?? '?'} s` : 'imagen' }}<template v-if="mediaById(id)?.width"> · {{ mediaById(id).width }}×{{ mediaById(id).height }}</template>)</span>
                </span>
                <span v-if="editable" class="space-x-2">
                    <button type="button" class="text-slate-600" @click="move(i, -1)">↑</button>
                    <button type="button" class="text-slate-600" @click="move(i, 1)">↓</button>
                    <button type="button" class="text-red-600" @click="removeMedia(id)">Quitar</button>
                </span>
            </li>
        </ul>
        <MediaUploader v-if="editable" @uploaded="onUploaded" />
        <p v-if="form.errors.media" class="mt-1 text-sm text-red-600">{{ form.errors.media }}</p>
    </section>
</template>
