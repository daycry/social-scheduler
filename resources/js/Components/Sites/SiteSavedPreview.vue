<script setup>
import { useSitePreview } from '@/Composables/useSitePreview';

const props = defineProps({ site: { type: Object, required: true }, activePage: { type: Number, required: true } });
const { size, previewSrc, sizes } = useSitePreview(() => props.site, () => props.activePage);
</script>

<template>
    <section class="card space-y-3 p-4" aria-labelledby="s-preview">
        <h2 id="s-preview" class="text-base font-semibold text-foreground-strong">Vista previa</h2>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Tamaño de la vista previa">
            <button
                v-for="(v, k) in sizes"
                :key="k"
                type="button"
                class="btn"
                :class="size === k ? 'btn-primary' : 'btn-secondary'"
                :aria-pressed="size === k"
                :data-testid="`preview-${k}`"
                @click="size = k"
                >{{ v[0] }}</button
            >
        </div>
        <p class="text-xs text-foreground-muted"
            >Muestra el último borrador guardado con el mismo render del sitio publicado.</p
        >
        <div class="overflow-hidden rounded border border-control-border bg-canvas">
            <iframe
                :key="previewSrc"
                :src="previewSrc"
                title="Vista previa del sitio"
                class="mx-auto block h-[28rem] max-w-full border-0 bg-white"
                :style="{ width: sizes[size][1] }"
                :data-size="size"
                data-testid="preview-frame"
            ></iframe>
        </div>
    </section>
</template>
