<script setup>
import { computed, ref } from 'vue';
import AiSuggest from '@/Components/AiSuggest.vue';
import MediaUploader from '@/Components/MediaUploader.vue';
import EmojiPicker from '@/Components/Composer/EmojiPicker.vue';
import UtmModal from '@/Components/Composer/UtmModal.vue';
import SavedTextsMenu from '@/Components/Composer/SavedTextsMenu.vue';
import HashtagSuggestions from '@/Components/Composer/HashtagSuggestions.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countHashtags } from '@/Composables/textCount';

/**
 * Barra de iconos del editor: media, emoji, textos guardados, hashtags sugeridos del histórico, enlace con UTM, IA y recuento de hashtags. El emoji se inserta con `@emoji`
 * (lo recoge `TextSection` y lo pasa al cursor del `TextEditor`); la IA y la media usan los controles que ya existían.
 */
const emit = defineEmits(['emoji', 'insert']);
const { brand, form, editable, selectedNetwork, onUploaded, uploadingMedia } = useComposerContext();
const setUploading = (b) => { uploadingMedia.value = b; };

const emojiOpen = ref(false);
const emojiButton = ref(null);
const utmOpen = ref(false);
const utmButton = ref(null);
const hashtags = computed(() => countHashtags(form.text));
</script>

<template>
    <div v-if="editable" class="mt-2 flex flex-wrap items-start gap-2" role="toolbar" aria-label="Herramientas del editor" data-testid="editor-toolbar">
        <MediaUploader label="Añadir imagen o vídeo" @uploaded="onUploaded" @busy="setUploading" />

        <div class="relative">
            <button
                ref="emojiButton"
                type="button"
                class="btn btn-secondary"
                aria-haspopup="dialog"
                :aria-expanded="emojiOpen"
                @click="emojiOpen = !emojiOpen"
            ><span aria-hidden="true">🙂 </span>Insertar emoji</button>
            <EmojiPicker :open="emojiOpen" :return-focus-to="emojiButton" @pick="(c) => emit('emoji', c)" @close="emojiOpen = false" />
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
            ><span aria-hidden="true">🔗 </span>Enlace con UTM</button>
            <UtmModal :open="utmOpen" :return-focus-to="utmButton" @insert="(u) => emit('insert', u)" @close="utmOpen = false" />
        </div>

        <SavedTextsMenu @insert="(t) => emit('insert', t)" />

        <HashtagSuggestions @insert="(t) => emit('insert', t)" />

        <AiSuggest :brand-id="brand.id" :network="selectedNetwork" @use="(t) => (form.text = t)" />

        <p class="ms-auto self-center text-xs text-slate-600" data-testid="hashtag-count" aria-live="polite">{{ hashtags }} {{ hashtags === 1 ? 'hashtag' : 'hashtags' }}</p>
    </div>
</template>
