<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { EMOJI_CATEGORIES, searchEmojis } from '@/Components/Composer/emojiData';

/**
 * Selector de emoji propio: lista curada por categorías con buscador. Se abre desde un botón de la barra del editor
 * (`open` + `@close`); al cerrarse con Escape o al elegir, el foco vuelve al botón que lo abrió (`returnFocusTo`).
 * Los emojis son texto plano (se insertan con `@pick`); nada se pinta como HTML.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    returnFocusTo: { type: Object, default: null },
});
const emit = defineEmits(['pick', 'close']);

const query = ref('');
const category = ref(EMOJI_CATEGORIES[0].id);
const searchInput = ref(null);
const root = ref(null);

const results = computed(() => (query.value.trim() ? searchEmojis(query.value) : null));
const current = computed(() => EMOJI_CATEGORIES.find((c) => c.id === category.value) ?? EMOJI_CATEGORIES[0]);
const visible = computed(() => results.value ?? current.value.items.map(([char, name]) => ({ char, name })));

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        query.value = '';
        await nextTick();
        searchInput.value?.focus();
    }
});

const close = () => {
    emit('close');
    nextTick(() => props.returnFocusTo?.focus?.());
};
const pick = (char) => {
    emit('pick', char);
    close();
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
};
// Atrapa el foco dentro del selector mientras está abierto.
const onTab = (e) => {
    const focusable = [...root.value.querySelectorAll('button, input')].filter((el) => !el.disabled);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
};
</script>

<template>
    <div
        v-if="open"
        ref="root"
        role="dialog"
        aria-label="Selector de emoji"
        class="absolute left-0 top-full z-30 mt-1 w-[min(22rem,calc(100vw-1.5rem))] rounded-lg border border-slate-200 bg-white p-3 shadow-lg"
        data-testid="emoji-picker"
        @keydown="onKeydown"
        @keydown.tab="onTab"
    >
        <label class="block text-xs font-medium text-slate-600" for="emoji-search">Buscar emoji</label>
        <input id="emoji-search" ref="searchInput" v-model="query" type="search" autocomplete="off" class="field mt-1" placeholder="Por ejemplo: fuego, tarta, gracias" />

        <div v-if="!results" class="mt-2 flex flex-wrap gap-1" role="group" aria-label="Categorías de emoji">
            <button
                v-for="c in EMOJI_CATEGORIES"
                :key="c.id"
                type="button"
                :aria-pressed="category === c.id"
                :aria-label="c.label"
                :title="c.label"
                class="rounded px-2 py-1 text-lg leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                :class="category === c.id ? 'bg-indigo-100' : 'hover:bg-slate-100'"
                @click="category = c.id"
            ><span aria-hidden="true">{{ c.icon }}</span></button>
        </div>
        <p v-if="!results" class="mt-2 text-xs font-medium text-slate-600">{{ current.label }}</p>

        <ul class="mt-1 grid max-h-48 grid-cols-7 gap-0.5 overflow-y-auto" aria-label="Emojis">
            <li v-for="e in visible" :key="e.char + e.name">
                <button
                    type="button"
                    :aria-label="e.name"
                    :title="e.name"
                    class="flex h-9 w-9 items-center justify-center rounded text-xl leading-none hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                    @click="pick(e.char)"
                ><span aria-hidden="true">{{ e.char }}</span></button>
            </li>
        </ul>
        <p v-if="results && !results.length" class="mt-2 text-sm text-slate-600" role="status">No hay emojis con ese nombre.</p>

        <p class="mt-2 border-t border-slate-100 pt-2 text-xs text-slate-600">
            Para ver todos los emojis, en Windows pulsa <kbd class="rounded border border-slate-300 px-1">Win</kbd> + <kbd class="rounded border border-slate-300 px-1">.</kbd>
        </p>
    </div>
</template>
