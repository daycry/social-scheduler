<script setup>
import { computed, ref } from 'vue';
import AiSuggest from '@/Components/AiSuggest.vue';
import MediaUploader from '@/Components/MediaUploader.vue';
import EmojiPicker from '@/Components/Composer/EmojiPicker.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countHashtags } from '@/Composables/textCount';

/**
 * Barra de iconos del editor: media, emoji, IA y recuento de hashtags. El emoji se inserta con `@emoji`
 * (lo recoge `TextSection` y lo pasa al cursor del `TextEditor`); la IA y la media usan los controles que ya existían.
 */
const emit = defineEmits(['emoji']);
const { brand, form, editable, selectedNetwork, onUploaded } = useComposerContext();

const emojiOpen = ref(false);
const emojiButton = ref(null);
const hashtags = computed(() => countHashtags(form.text));
</script>

<template>
    <div v-if="editable" class="mt-2 flex flex-wrap items-start gap-2" role="toolbar" aria-label="Herramientas del editor" data-testid="editor-toolbar">
        <MediaUploader label="Añadir imagen o vídeo" @uploaded="onUploaded" />

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

        <AiSuggest :brand-id="brand.id" :network="selectedNetwork" @use="(t) => (form.text = t)" />

        <p class="ms-auto self-center text-xs text-slate-600" data-testid="hashtag-count">{{ hashtags }} {{ hashtags === 1 ? 'hashtag' : 'hashtags' }}</p>
    </div>
</template>
