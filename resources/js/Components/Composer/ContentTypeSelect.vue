<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';

// Desplegable del tipo de publicación de una red (T-43): patrón listbox colapsable de WAI-ARIA.
// Cada opción lleva icono, nombre y subtítulo. Dentro del <form> del compositor: el botón es type="button" y
// Enter nunca envía el formulario.
const props = defineProps({
    modelValue: { type: String, default: '' },
    types: { type: Array, required: true },
    label: { type: String, required: true }, // «Tipo de contenido en <red> (<cuenta>)»
    buttonId: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    savedLabel: { type: String, default: '' }, // nombre real del tipo guardado cuando ya no se ofrece
});
const emit = defineEmits(['update:modelValue']);

const uid = useId();
const open = ref(false);
const active = ref(0);
const root = ref(null);
const button = ref(null);
const listbox = ref(null);

// Solo es «actual» el tipo realmente elegido: si el valor guardado ya no se ofrece no se muestra otro en su lugar.
const current = computed(() => props.types.find((t) => t.value === props.modelValue) ?? null);
const shownLabel = computed(() => current.value?.label ?? (props.savedLabel ? `${props.savedLabel} (no disponible)` : 'Elige un tipo'));
// Etiqueta fija solo con un único tipo ya elegido, o sin posibilidad de cambiarlo (post no editable). Si el usuario ya
// abrió el desplegable (p. ej. para corregir un tipo no disponible), sigue siendo botón: desmontarlo perdería el foco.
const interacted = ref(false);
const fixed = computed(() => !interacted.value && (props.types.length === 0 || (props.types.length <= 1 && current.value !== null) || (props.disabled && current.value === null)));
const labelId = `${uid}-label`;
const btnId = computed(() => props.buttonId ?? `${uid}-btn`);
const optionId = (i) => `${uid}-opt-${i}`;

// Iconos simples de trazo (24x24), según la parte final del valor del tipo.
const ICONS = {
    image: 'M4 5h16v14H4z M4 16l5-5 4 4 3-3 4 4 M9 9.5h.01',
    photo: 'M4 5h16v14H4z M4 16l5-5 4 4 3-3 4 4 M9 9.5h.01',
    carousel: 'M8 6h12v12H8z M4 8v8 M22 9v6',
    reel: 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z M10 9l5 3-5 3z',
    story: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 8v8 M8 12h8',
    text: 'M5 6h14 M5 10h14 M5 14h14 M5 18h9',
    video: 'M3 6h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H3z M17 10l4-2v8l-4-2',
    short: 'M9 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z M11 9l4 3-4 3z',
};
const iconPath = (value) => {
    const kind = String(value ?? '').split('_').slice(1).join('_');
    return ICONS[kind] ?? ICONS.image;
};

function close(refocus = false) {
    open.value = false;
    document.removeEventListener('mousedown', onOutside);
    if (refocus) nextTick(() => button.value?.focus());
}
function onOutside(e) {
    if (root.value && !root.value.contains(e.target)) close();
}
async function openList() {
    if (props.disabled || open.value) return;
    active.value = Math.max(0, props.types.findIndex((t) => t.value === props.modelValue));
    interacted.value = true;
    open.value = true;
    document.addEventListener('mousedown', onOutside);
    await nextTick();
    listbox.value?.focus();
}
function toggle() {
    if (open.value) close(true);
    else openList();
}
function choose(i) {
    const t = props.types[i];
    if (t) emit('update:modelValue', t.value);
    close(true);
}
function onButtonKey(e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        openList();
    } else if (e.key === 'Enter') {
        e.preventDefault(); // nunca envía el formulario
        toggle();
    }
}
function onListKey(e) {
    const last = props.types.length - 1;
    switch (e.key) {
        case 'ArrowDown': active.value = Math.min(last, active.value + 1); break;
        case 'ArrowUp': active.value = Math.max(0, active.value - 1); break;
        case 'Home': active.value = 0; break;
        case 'End': active.value = last; break;
        case 'Enter':
        case ' ': choose(active.value); break;
        case 'Escape': close(true); e.stopPropagation(); break;
        case 'Tab': close(); return; // el foco sigue su camino
        default: return;
    }
    e.preventDefault();
}

onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <!-- Una sola opción: etiqueta fija, sin desplegable -->
    <span
        v-if="fixed"
        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700"
        :title="current?.description"
    >
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="iconPath(current?.value ?? modelValue)" /></svg>
        <span class="sr-only">{{ label }}: </span>{{ shownLabel }}
    </span>

    <div v-else ref="root" class="inline-block">
        <span :id="labelId" class="sr-only">{{ label }}</span>
        <button
            :id="btnId"
            ref="button"
            type="button"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-controls="open ? `${uid}-list` : undefined"
            :aria-labelledby="`${labelId} ${btnId}`"
            :disabled="disabled"
            class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-60"
            @click="toggle"
            @keydown="onButtonKey"
        >
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="iconPath(current?.value)" /></svg>
            <span class="truncate">{{ shownLabel }}</span>
            <svg class="h-3 w-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.5 7.5 10 12l4.5-4.5z" /></svg>
        </button>

        <ul
            v-show="open"
            :id="`${uid}-list`"
            ref="listbox"
            role="listbox"
            tabindex="-1"
            :aria-labelledby="labelId"
            :aria-activedescendant="open ? optionId(active) : undefined"
            class="absolute inset-x-0 z-30 mt-1 max-h-72 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg focus:outline-none sm:inset-x-auto sm:left-0 sm:w-72 sm:max-w-[calc(100vw-2rem)]"
            @keydown="onListKey"
        >
            <li
                v-for="(t, i) in types"
                :id="optionId(i)"
                :key="t.value"
                role="option"
                :aria-selected="t.value === modelValue"
                class="flex cursor-pointer items-start gap-2 px-3 py-2"
                :class="i === active ? 'bg-indigo-50' : ''"
                @mouseenter="active = i"
                @click="choose(i)"
            >
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="iconPath(t.value)" /></svg>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-slate-900">{{ t.label }}</span>
                    <span class="block text-xs text-slate-600">{{ t.description }}</span>
                </span>
                <svg v-if="t.value === modelValue" class="mt-0.5 h-4 w-4 shrink-0 text-indigo-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7.7 13.3 4.4 10l-1.1 1.1 4.4 4.4 9-9-1.1-1.1z" /></svg>
            </li>
        </ul>
    </div>
</template>
