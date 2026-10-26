<script setup>
import { reactive } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { YOUTUBE_CATEGORIES, hasYoutubeFields, switchesFor } from '@/Composables/composerOptions';
import { CREATOR_LOCKS, tiktokCreator } from '@/Composables/tiktokCreator';

const props = defineProps({ account: { type: Object, required: true } });
const { editable, targetFor } = useComposerContext();

const target = () => targetFor(props.account.id);
const switches = () => switchesFor(props.account, target());
// Interacciones que la cuenta tiene desactivadas en TikTok (`creator_info`): el interruptor sale marcado y bloqueado.
const lockedByTiktok = (s) => props.account.network === 'tiktok' && CREATOR_LOCKS[s.key] !== undefined
    && tiktokCreator(props.account.id).data?.[CREATOR_LOCKS[s.key]] === true;
const checked = (s) => lockedByTiktok(s) || (target().options[s.key] ?? s.default);
const set = (s, value) => { target().options[s.key] = value; };

// Los tags son un campo de texto separado por comas; la lista real vive en `options.tags` y no se recuerda entre publicaciones.
const tagText = reactive({ [props.account.id]: (target()?.options.tags ?? []).join(', ') });
const setTags = (value) => {
    tagText[props.account.id] = value;
    const tags = value.split(',').map((t) => t.trim()).filter(Boolean);
    if (tags.length) target().options.tags = tags;
    else delete target().options.tags;
};
const setCategory = (value) => {
    if (value && value !== '22') target().options.category_id = value;
    else delete target().options.category_id;
};
</script>

<template>
    <div v-if="target()" class="space-y-2">
        <div v-if="hasYoutubeFields(account)" class="grid gap-2 sm:grid-cols-2">
            <div>
                <InputLabel :for="`yt-category-${account.id}`" value="Categoría" />
                <select :id="`yt-category-${account.id}`" :value="target().options.category_id ?? '22'" :disabled="!editable" class="field mt-1 w-full" @change="setCategory($event.target.value)">
                    <option v-for="c in YOUTUBE_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </div>
            <div>
                <InputLabel :for="`yt-tags-${account.id}`" value="Etiquetas (separadas por comas)" />
                <input :id="`yt-tags-${account.id}`" :value="tagText[account.id]" :disabled="!editable" type="text" class="field mt-1 w-full" autocomplete="off" @keydown.enter.prevent @input="setTags($event.target.value)" />
            </div>
        </div>

        <fieldset v-if="switches().length" class="space-y-2 border-0 p-0" :disabled="!editable">
            <legend class="sr-only">Interruptores de {{ account.network_label }} ({{ account.name }})</legend>
            <div v-for="s in switches()" :key="s.key">
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox" role="switch" class="mt-0.5"
                        :checked="checked(s)" :disabled="lockedByTiktok(s)" :aria-describedby="s.hint && checked(s) ? `hint-${account.id}-${s.key}` : undefined"
                        @change="set(s, $event.target.checked)"
                    />
                    <span>{{ s.label }}</span>
                </label>
                <p v-if="lockedByTiktok(s)" class="ml-6 text-xs text-slate-600">Desactivado en los ajustes de tu cuenta de TikTok.</p>
                <p v-if="s.hint && checked(s)" :id="`hint-${account.id}-${s.key}`" class="ml-6 text-xs text-slate-600" role="status">{{ s.hint }}</p>
                <p v-if="s.warning && checked(s)" class="ml-6 text-xs text-amber-800" role="status">{{ s.warning }}</p>
            </div>
            <p class="text-xs text-slate-600">Los interruptores se recuerdan para esta marca y red en las próximas publicaciones.</p>
        </fieldset>
    </div>
</template>
