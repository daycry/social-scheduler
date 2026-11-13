<script setup>
import { computed, ref } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';
import { MAX_USER_TAGS, clamp01, normalizeUsername, pointToCoordinates, removeTag, upsertTag } from '@/Composables/userTags';

// Editor de `user_tags` de Instagram (solo imágenes): se escribe el usuario y se pulsa sobre la imagen para colocar la etiqueta
// (x e y entre 0 y 1 desde la esquina superior izquierda). Sin ratón, «Añadir en el centro» y los campos x/y.
const props = defineProps({ account: { type: Object, required: true } });
const { editable, targetFor, effectiveMedia, mediaById, previews } = useComposerContext();
const target = computed(() => targetFor(props.account.id));
const tags = computed(() => target.value.options.user_tags ?? []);
const max = computed(() => props.account.instagram_presets?.user_tags?.max ?? MAX_USER_TAGS);
const image = computed(() => {
    const item = effectiveMedia(target.value).find((m) => mediaById(m.id)?.kind === 'image');
    const asset = item ? mediaById(item.id) : null;
    return asset ? { url: previews.value[asset.id] ?? asset.preview_url ?? null, name: asset.name ?? `Archivo ${asset.id}` } : null;
});
const username = ref('');
const error = ref('');
const base = computed(() => `ig-tags-${props.account.id}`);
const full = computed(() => tags.value.length >= max.value && !tags.value.some((t) => t.username === normalizeUsername(username.value)));

function commit(next) {
    if (next.length) target.value.options.user_tags = next;
    else delete target.value.options.user_tags;
}
function place(x, y) {
    if (!editable.value) return;
    const outcome = upsertTag(tags.value, username.value, x, y, max.value);
    error.value = outcome.error ?? '';
    if (outcome.error) return;
    commit(outcome.tags);
    username.value = '';
}
function onImageClick(event) {
    const { x, y } = pointToCoordinates(event.currentTarget.getBoundingClientRect(), event.clientX, event.clientY);
    place(x, y);
}
function setCoordinate(tag, key, raw) {
    commit(tags.value.map((t) => (t.username === tag.username ? { ...t, [key]: clamp01(raw) } : t)));
}
</script>

<template>
    <div class="space-y-2" data-testid="instagram-user-tags">
        <p class="text-sm font-medium text-slate-900">Etiquetar usuarios en la imagen</p>
        <p v-if="!image" class="text-xs text-slate-600">Adjunta una imagen a este destino para etiquetar usuarios.</p>
        <template v-else>
            <div class="flex flex-wrap gap-2">
                <div class="min-w-0 flex-1 basis-40">
                    <label :for="`${base}-username`" class="sr-only">Usuario de Instagram a etiquetar</label>
                    <input :id="`${base}-username`" v-model="username" :disabled="!editable" class="field w-full" type="text" maxlength="31" placeholder="@usuario" autocomplete="off" @keydown.enter.prevent.stop="place(0.5, 0.5)" @input="error = ''" />
                </div>
                <button type="button" class="btn btn-secondary" :disabled="!editable || full" @click="place(0.5, 0.5)">Añadir en el centro</button>
            </div>
            <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
            <!-- La imagen es un área de clic para ratón y táctil; los campos x/y de abajo son la alternativa de teclado. -->
            <div class="relative mx-auto w-full max-w-sm overflow-hidden rounded-xl border border-slate-200 bg-slate-100" :class="editable ? 'cursor-crosshair' : ''" :data-testid="`${base}-canvas`" @click="onImageClick">
                <img v-if="image.url" :src="image.url" :alt="`Imagen para etiquetar: ${image.name}`" class="block w-full select-none" draggable="false" />
                <span v-for="tag in tags" :key="tag.username" class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full bg-slate-900/90 px-2 py-0.5 text-[11px] font-medium text-white" :style="{ left: `${tag.x * 100}%`, top: `${tag.y * 100}%` }" :data-testid="`${base}-marker-${tag.username}`">@{{ tag.username }}</span>
            </div>
            <p class="text-xs leading-relaxed text-slate-600">Escribe el usuario y pulsa sobre la imagen donde quieras la etiqueta (hasta {{ max }}). Instagram solo etiqueta cuentas públicas y rechaza la publicación si alguna no existe.</p>
            <ul v-if="tags.length" class="space-y-2" aria-label="Etiquetas de usuario">
                <li v-for="tag in tags" :key="tag.username" class="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                    <span class="min-w-0 flex-1 basis-24 break-all font-medium">@{{ tag.username }}</span>
                    <label class="text-xs">x
                        <input :value="tag.x" :disabled="!editable" type="number" min="0" max="1" step="0.01" class="field ml-1 w-20" :aria-label="`Posición x de ${tag.username} (0 a 1)`" @change="setCoordinate(tag, 'x', $event.target.value)" @keydown.enter.prevent />
                    </label>
                    <label class="text-xs">y
                        <input :value="tag.y" :disabled="!editable" type="number" min="0" max="1" step="0.01" class="field ml-1 w-20" :aria-label="`Posición y de ${tag.username} (0 a 1)`" @change="setCoordinate(tag, 'y', $event.target.value)" @keydown.enter.prevent />
                    </label>
                    <button type="button" :disabled="!editable" class="btn btn-secondary" :aria-label="`Quitar etiqueta de ${tag.username}`" @click="commit(removeTag(tags, tag.username))">Quitar</button>
                </li>
            </ul>
        </template>
    </div>
</template>
