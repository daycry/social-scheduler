<script setup>
import { computed, nextTick, ref, useId } from 'vue';
import { useStudioAssetLinks } from '@/Composables/useStudioAssetLinks';
import AiSuggest from '@/Components/AiSuggest.vue';
import AiAdapt from '@/Components/Composer/AiAdapt.vue';
import MediaUploader from '@/Components/MediaUploader.vue';
import EmojiPicker from '@/Components/Composer/EmojiPicker.vue';
import UtmModal from '@/Components/Composer/UtmModal.vue';
import SavedTextsMenu from '@/Components/Composer/SavedTextsMenu.vue';
import HashtagSuggestions from '@/Components/Composer/HashtagSuggestions.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countHashtags } from '@/Composables/textCount';

/**
 * Barra de iconos del editor: media, emoji, textos guardados, hashtags sugeridos del histórico, enlace con UTM, IA (sugerir y adaptar a cada red) y recuento de hashtags. El emoji se inserta con `@emoji`
 * (lo recoge `TextSection` y lo pasa al cursor del `TextEditor`); la IA y la media usan los controles que ya existían.
 */
const emit = defineEmits(['emoji', 'insert']);
const { brand, form, editable, selectedNetwork, onUploaded, uploadingMedia } = useComposerContext();
const setUploading = (b) => { uploadingMedia.value = b; };
const uploader = ref(null);
const { pendingLinks, announcement, announcementBrandId, linkAsset, dismiss } = useStudioAssetLinks();
const warningId = useId();
const visibleLinks = computed(() => pendingLinks.value.filter(link => link.brandId === brand.value.id));
const registerUpload = (asset, file) => {
    onUploaded(asset, file);
    void linkAsset(brand.value.id, asset);
};
const returnFocus = async (button, rows, list) => {
    await nextTick();
    if (button.isConnected || document.activeElement !== document.body) return;
    const dismissal = rows.map(row => row?.querySelector('[data-dismiss-link]')).find(candidate => candidate?.isConnected)
        ?? list?.querySelector('[data-dismiss-link]');
    if (dismissal?.isConnected) dismissal.focus();
    else uploader.value?.focusPicker();
};
const dismissLink = (link, { currentTarget: button }) => {
    const row = button.closest('li');
    const neighbors = [row.nextElementSibling, row.previousElementSibling];
    const list = row.parentElement;
    dismiss(link.key);
    void returnFocus(button, neighbors, list);
};
const retryLink = async (link, { currentTarget: button }) => {
    const row = button.closest('li');
    const list = row.parentElement;
    await linkAsset(link.brandId, link.asset);
    await returnFocus(button, [row], list);
};

const emojiOpen = ref(false);
const emojiButton = ref(null);
const utmOpen = ref(false);
const utmButton = ref(null);
const hashtags = computed(() => countHashtags(form.text));
</script>

<template>
    <div
        v-if="editable"
        class="relative mt-2 flex flex-wrap items-start gap-2"
        role="toolbar"
        aria-label="Herramientas del editor"
        data-testid="editor-toolbar"
    >
        <div>
            <MediaUploader
                :key="brand.id"
                ref="uploader"
                label="Añadir imagen o vídeo"
                @uploaded="registerUpload"
                @busy="setUploading"
            />
            <p
                class="sr-only"
                role="status"
                aria-atomic="true"
                >{{ announcementBrandId === brand.id ? announcement : '' }}</p
            >
            <ul v-if="visibleLinks.length" class="mt-1 space-y-1 text-sm text-foreground-secondary">
                <li v-for="link in visibleLinks" :key="link.key">
                    <span v-if="link.state === 'linking'">Añadiendo {{ link.asset.name }} a Recursos…</span>
                    <span v-else :id="`${warningId}-${link.key}`">
                        {{ link.asset.name }} se subió. No se pudo añadir a Recursos. {{ link.error }}
                    </span>
                    <button
                        v-if="link.retry"
                        type="button"
                        class="btn-secondary text-xs aria-disabled:opacity-60"
                        :aria-disabled="link.state === 'linking'"
                        :aria-describedby="link.state === 'failed' ? `${warningId}-${link.key}` : undefined"
                        :aria-label="`Reintentar vínculo de ${link.asset.name}`"
                        @click="retryLink(link, $event)"
                    >
                        Reintentar vínculo
                    </button>
                    <button
                        v-if="link.state === 'failed'"
                        data-dismiss-link
                        type="button"
                        class="btn-secondary text-xs"
                        :aria-label="`Descartar aviso de ${link.asset.name}`"
                        @click="dismissLink(link, $event)"
                    >
                        Descartar aviso
                    </button>
                </li>
            </ul>
        </div>

        <div class="sm:relative">
            <button
                ref="emojiButton"
                type="button"
                class="btn btn-secondary"
                aria-haspopup="dialog"
                :aria-expanded="emojiOpen"
                @click="emojiOpen = !emojiOpen"
                ><span aria-hidden="true">🙂 </span>Insertar emoji</button
            >
            <EmojiPicker
                :open="emojiOpen"
                :return-focus-to="emojiButton"
                @pick="(c) => emit('emoji', c)"
                @close="emojiOpen = false"
            />
        </div>

        <div>
            <button
                ref="utmButton"
                type="button"
                class="btn btn-secondary"
                aria-haspopup="dialog"
                :aria-expanded="utmOpen"
                data-testid="utm-open"
                @click="utmOpen = true"
                ><span aria-hidden="true">🔗 </span>Enlace con UTM</button
            >
            <UtmModal
                :open="utmOpen"
                :return-focus-to="utmButton"
                @insert="(u) => emit('insert', u)"
                @close="utmOpen = false"
            />
        </div>

        <SavedTextsMenu @insert="(t) => emit('insert', t)" />

        <HashtagSuggestions @insert="(t, o) => emit('insert', t, o)" />

        <AiSuggest :brand-id="brand.id" :network="selectedNetwork" @use="(t) => (form.text = t)" />

        <AiAdapt />

        <p class="ms-auto self-center text-xs text-foreground-muted" data-testid="hashtag-count" aria-live="polite"
            >{{ hashtags }} {{ hashtags === 1 ? 'hashtag' : 'hashtags' }}</p
        >
    </div>
</template>
