<script setup>
import { nextTick, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Menú «Textos guardados» (C-01a) de la barra del editor: los de la marca más los de «Todas las marcas». Al elegir uno se inserta en
 * el cursor del campo activo (`insertIntoActive`); si ningún campo ha tenido el foco aún, cae al editor visible (`@insert`).
 * Se cierra con Escape y el foco vuelve al botón.
 */
const emit = defineEmits(['insert']);
const { savedTexts, insertIntoActive } = useComposerContext();

const open = ref(false);
const button = ref(null);
const root = ref(null);

const KIND_LABEL = { fragmento: 'Fragmento', hashtags: 'Hashtags' };

const close = () => {
    open.value = false;
    nextTick(() => button.value?.focus());
};
const toggle = async () => {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        root.value?.querySelector('button, a')?.focus();
    }
};
const pick = (t) => {
    if (!insertIntoActive(t.body)) emit('insert', t.body);
    close();
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
};
// Atrapa el foco mientras está abierto.
const onTab = (e) => {
    const focusable = [...root.value.querySelectorAll('button, a')];
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
    <div class="relative">
        <button
            ref="button"
            type="button"
            class="btn btn-secondary"
            aria-haspopup="dialog"
            :aria-expanded="open"
            data-testid="saved-texts-open"
            @click="toggle"
        ><span aria-hidden="true">📝 </span>Textos guardados</button>

        <div
            v-if="open"
            ref="root"
            role="dialog"
            aria-label="Textos guardados"
            class="absolute left-0 top-full z-30 mt-1 w-[min(22rem,calc(100vw-1.5rem))] rounded-lg border border-slate-200 bg-white p-3 shadow-lg"
            data-testid="saved-texts-menu"
            @keydown="onKeydown"
            @keydown.tab="onTab"
        >
            <p v-if="savedTexts.length === 0" class="text-sm text-slate-600">Aún no tienes textos guardados para esta marca.</p>
            <ul v-else class="max-h-60 divide-y divide-slate-100 overflow-y-auto" aria-label="Textos">
                <li v-for="t in savedTexts" :key="t.id">
                    <button
                        type="button"
                        class="block w-full rounded px-2 py-2 text-start hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        data-testid="saved-text-option"
                        @click="pick(t)"
                    >
                        <span class="block text-sm font-medium text-slate-900">{{ t.title }}</span>
                        <span class="block truncate text-xs text-slate-600">{{ KIND_LABEL[t.kind] ?? t.kind }} · {{ t.brand ?? 'Todas las marcas' }}</span>
                    </button>
                </li>
            </ul>
            <p class="mt-2 border-t border-slate-100 pt-2 text-xs text-slate-600">
                <Link :href="route('saved-texts.index')" class="font-medium text-indigo-700 underline">Gestionar textos guardados</Link>
            </p>
        </div>
    </div>
</template>
