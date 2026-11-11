<script setup>
import { useComposerContext } from '@/Composables/useComposer';

const { firstPreview, mediaById, previewText, previewItems, previewTarget, accountName } = useComposerContext();
</script>

<template>
    <div class="card p-4" data-testid="preview-panel">
        <h2 class="mb-2 font-medium text-slate-900">Previsualización<template v-if="previewTarget"> de {{ accountName(previewTarget.account_id) }}</template></h2>
        <div class="space-y-2 rounded border border-slate-200 p-3">
            <video v-if="firstPreview?.kind === 'video'" :key="firstPreview.url" :src="firstPreview.url" controls playsinline preload="metadata" aria-label="Vídeo de la publicación" class="max-h-72 w-full rounded bg-black object-contain" />
            <img v-else-if="firstPreview" :src="firstPreview.url" :alt="firstPreview.alt" class="max-h-48 w-full rounded object-cover" />
            <p v-else-if="previewItems.length" class="rounded bg-slate-100 p-6 text-center text-sm text-slate-600">{{ previewItems[0].asset?.kind === 'video' ? 'Vídeo' : 'Imagen' }}</p>
            <ol v-if="previewItems.length" class="space-y-1 text-xs text-slate-700" aria-label="Media que publica esta red" data-testid="preview-media-list">
                <li v-for="(item, i) in previewItems" :key="item.id" data-testid="preview-media-item" :data-media-id="item.id" class="[overflow-wrap:anywhere]">
                    {{ i + 1 }}. {{ item.asset?.name ?? `Archivo ${item.id}` }}<template v-if="item.alt"> · alt: <span data-testid="preview-media-alt">{{ item.alt }}</span></template><template v-if="item.cover_id"> · portada: {{ mediaById(item.cover_id)?.name ?? item.cover_id }}</template>
                </li>
            </ol>
            <p class="whitespace-pre-line [overflow-wrap:anywhere] text-sm text-slate-800">{{ previewText }}</p>
        </div>
    </div>
</template>
