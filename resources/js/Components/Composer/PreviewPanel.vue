<script setup>
import { useComposerContext } from '@/Composables/useComposer';

const { form, firstPreview, mediaById, previewText } = useComposerContext();
</script>

<template>
    <div class="card p-4">
        <h2 class="mb-2 font-medium text-slate-900">Previsualización</h2>
        <div class="space-y-2 rounded border border-slate-200 p-3">
            <img v-if="firstPreview" :src="firstPreview" alt="" class="max-h-48 w-full rounded object-cover" />
            <p v-else-if="form.media.length" class="rounded bg-slate-100 p-6 text-center text-sm text-slate-600">{{ mediaById(form.media[0])?.kind === 'video' ? 'Vídeo' : 'Imagen' }}</p>
            <p class="whitespace-pre-line [overflow-wrap:anywhere] text-sm text-slate-800">{{ previewText }}</p>
        </div>
    </div>
</template>
