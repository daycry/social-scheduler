<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';
import Icon from '@/Components/Icon.vue';
import InfoNote from '@/Components/InfoNote.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import {
    MIN_LENGTH,
    PAGE_LIMIT,
    createRemoteSearch,
    fetchResults,
    filterPages,
    groupEntries,
    nextIndex,
    pageCommands,
    queryLength,
    restoreIndex,
} from '@/lib/commandPalette';
import { useNavigationContext } from '@/Composables/useNavigationContext';
import { isApplePlatform, searchShortcut } from '@/lib/shortcuts';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Paleta de comandos (T-85, F-11): botón de la cabecera + diálogo común (`BaseDialog`: foco atrapado, Esc cierra y el foco vuelve a quien la
 * abrió) con el patrón combobox/listbox de ARIA. Las páginas y acciones salen de `navigation.js` (el mismo menú, con sus permisos y condiciones)
 * y se filtran al instante; el contenido del usuario llega del servidor (`search`) con debounce y cancelación, ya acotado a lo que puede ver.
 * Todo se pinta como texto con `{{ }}`: nada de HTML del servidor.
 *
 * Los atajos se registran en GlobalActions. La navegación usa el router y pasa por UnsavedGuard en el compositor.
 */
defineProps({
    /** Botón para cabeceras claras (el compositor); por defecto, para la cabecera oscura del shell. */
    light: Boolean,
});
const { ctx } = useNavigationContext();

const LISTBOX_ID = 'command-palette-listbox';
const optionId = (/** @type {number} */ index) => `command-palette-option-${index}`;

const open = ref(false);
const query = ref('');
const active = ref(0);
const shortcut = searchShortcut(isApplePlatform());
const trigger = ref(/** @type {HTMLButtonElement | null} */ (null));
const returnTo = ref(/** @type {HTMLButtonElement | null} */ (null));
const remote = ref(/** @type {import('@/lib/commandPalette').RemoteState} */ ({ status: 'idle', query: '', results: [], error: '' }));

const search = createRemoteSearch({ request: fetchResults, onChange: (state) => (remote.value = state) });
const pages = computed(() => (open.value ? pageCommands(ctx.value) : []));
const groups = computed(() => groupEntries(filterPages(pages.value, query.value, PAGE_LIMIT), remote.value.results));
const flat = computed(() => groups.value.flatMap((group) => group.entries));
const typed = computed(() => queryLength(query.value));

/** Mensaje para el lector de pantalla (región `aria-live` educada); lo visible va aparte, en la propia lista. */
const announcement = computed(() => {
    const { status } = remote.value;
    // El error se anuncia por su propio `role="alert"`.
    if (status === 'error') return '';
    if (status === 'loading') return 'Buscando…';
    if (status === 'ready') return flat.value.length ? `${flat.value.length} resultados.` : `Sin resultados para «${remote.value.query}».`;
    return typed.value > 0 && typed.value < MIN_LENGTH ? `Escribe al menos ${MIN_LENGTH} caracteres para buscar en tu contenido.` : '';
});

// Al escribir, la primera opción; cuando solo llegan los resultados del servidor, se conserva la opción activa si sigue en la lista.
let resetActive = false;
watch(query, (text) => {
    resetActive = true;
    search.update(text);
});
watch(flat, (list, previous) => {
    active.value = resetActive ? 0 : restoreIndex(list, previous[active.value]?.key);
    resetActive = false;
});
watch(active, async (index) => {
    await nextTick();
    document.getElementById(optionId(index))?.scrollIntoView({ block: 'nearest' });
});

/** @param {HTMLButtonElement | null} [from] botón que la abre; sin él, el foco vuelve a donde estaba */
function openPalette(from = null) {
    if (open.value) return;
    returnTo.value = from;
    open.value = true;
}
defineExpose({ openPalette, open });

function close() {
    open.value = false;
    query.value = '';
}

/** @param {import('@/lib/commandPalette').PaletteEntry} entry */
function choose(entry) {
    close();
    if (entry.external) window.open(entry.url, '_blank', 'noopener');
    else router.visit(entry.url);
}

/** Flechas e Intro, desde el campo o desde la lista cuando recibe el foco (la región con scroll es enfocable para quien la recorre con teclado). @param {KeyboardEvent} event */
function onNavigationKeydown(event) {
    if (event.isComposing) return;
    if (event.key === 'Enter') {
        const entry = flat.value[active.value];
        if (entry) {
            event.preventDefault();
            choose(entry);
        }
        return;
    }
    const next = nextIndex(active.value, event.key, flat.value.length);
    if (next >= 0) {
        event.preventDefault();
        active.value = next;
    }
}

onBeforeUnmount(() => search.close());
</script>

<template>
    <button
        ref="trigger"
        type="button"
        class="flex shrink-0 items-center gap-2 rounded-lg p-2 2xl:border 2xl:px-2.5 2xl:py-1.5"
        :class="
            light
                ? 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 2xl:border-slate-300'
                : 'text-slate-300 hover:bg-white/10 hover:text-white 2xl:border-white/15 2xl:bg-white/5'
        "
        :title="`Buscar (${shortcut.keys})`"
        aria-label="Buscar en la aplicación"
        aria-haspopup="dialog"
        :aria-keyshortcuts="shortcut.aria"
        data-testid="command-palette-trigger"
        @click="openPalette(trigger)"
    >
        <Icon name="search" class="h-5 w-5" />
        <span class="hidden text-sm 2xl:inline">Buscar</span>
        <kbd
            class="hidden rounded border px-1.5 text-[11px] 2xl:inline"
            :class="light ? 'border-slate-300 text-slate-600' : 'border-white/20 text-slate-300'"
            aria-hidden="true"
            >{{
            shortcut.keys
            }}</kbd
        >
    </button>

    <BaseDialog
        v-if="open"
        open
        role="alertdialog"
        close-on-backdrop
        :return-focus-to="returnTo"
        class="dialog-panel max-w-2xl max-sm:my-0 max-sm:h-dvh max-sm:max-h-none max-sm:w-screen max-sm:max-w-none max-sm:rounded-none sm:mt-20"
        aria-labelledby="command-palette-title"
        aria-describedby="command-palette-hint"
        @close="close"
    >
        <div class="relative flex h-full min-w-0 flex-col" data-testid="command-palette">
            <h2 id="command-palette-title" class="sr-only">Buscar</h2>
            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2">
                <Icon name="search" class="h-5 w-5 shrink-0 text-slate-500" />
                <label for="command-palette-input" class="sr-only"
                    >Buscar páginas, publicaciones, marcas, archivos y más</label
                >
                <input
                    id="command-palette-input"
                    v-model="query"
                    data-dialog-initial-focus
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-haspopup="listbox"
                    :aria-expanded="flat.length > 0"
                    :aria-controls="flat.length > 0 ? LISTBOX_ID : undefined"
                    :aria-activedescendant="flat.length > 0 ? optionId(active) : undefined"
                    aria-describedby="command-palette-hint"
                    :maxlength="100"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    enterkeyhint="go"
                    placeholder="Buscar…"
                    class="min-h-11 min-w-0 flex-1 rounded-md border-0 bg-transparent px-1 text-base text-slate-900 placeholder:text-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:ring-offset-0 sm:text-sm"
                    data-testid="command-palette-input"
                    @keydown="onNavigationKeydown"
                />
                <button
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100"
                    aria-label="Cerrar la búsqueda"
                    data-testid="command-palette-close"
                    @click="close"
                >
                    <Icon name="x" class="h-5 w-5" />
                </button>
            </div>

            <p id="command-palette-hint" class="px-4 pt-2 text-xs text-slate-600">
                Escribe para buscar entre todas las páginas; publicaciones, marcas, archivos y diseños se buscan desde
                {{ MIN_LENGTH }} letras.
            </p>

            <div class="flex min-h-0 flex-1 flex-col pb-2">
                <div
                    v-if="flat.length > 0"
                    :id="LISTBOX_ID"
                    role="listbox"
                    aria-label="Resultados"
                    tabindex="0"
                    :aria-activedescendant="optionId(active)"
                    class="min-h-0 overflow-y-auto sm:max-h-[min(26rem,60dvh)]"
                    data-testid="command-palette-list"
                    @keydown="onNavigationKeydown"
                >
                    <div
                        v-for="group in groups"
                        :key="group.type"
                        role="group"
                        :aria-labelledby="`command-palette-group-${group.type}`"
                    >
                        <div
                            :id="`command-palette-group-${group.type}`"
                            role="presentation"
                            class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500"
                            >{{ group.label }}</div
                        >
                        <div
                            v-for="entry in group.entries"
                            :id="optionId(entry.index)"
                            :key="entry.key"
                            role="option"
                            :aria-selected="entry.index === active"
                            class="flex min-h-11 cursor-pointer items-center gap-3 border-s-2 px-4 py-2"
                            :class="entry.index === active ? 'border-indigo-600 bg-indigo-50' : 'border-transparent'"
                            data-testid="command-palette-option"
                            @click="choose(entry)"
                            @mousemove="active = entry.index"
                        >
                            <NetworkIcon v-if="entry.network !== undefined" :network="entry.network" size="sm" />
                            <Icon v-else :name="entry.icon ?? 'grid'" class="h-5 w-5 shrink-0 text-slate-500" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ entry.title }}</span>
                                <span v-if="entry.meta" class="block truncate text-xs text-slate-600">{{
                                    entry.meta
                                }}</span>
                            </span>
                            <span v-if="entry.external" class="sr-only">(se abre en otra pestaña)</span>
                        </div>
                    </div>
                </div>

                <p
                    v-if="remote.status === 'loading'"
                    class="px-4 py-3 text-sm text-slate-600"
                    data-testid="command-palette-loading"
                    >Buscando en tu contenido…</p
                >
                <p
                    v-else-if="remote.status === 'error'"
                    role="alert"
                    class="px-4 py-3 text-sm text-red-700"
                    data-testid="command-palette-error"
                    >{{ remote.error }}</p
                >
                <div
                    v-else-if="remote.status === 'ready' && remote.results.length === 0"
                    class="px-4 pt-3"
                    data-testid="command-palette-empty"
                >
                    <InfoNote
                        >No hay publicaciones, marcas, archivos ni diseños tuyos que coincidan con «{{ remote.query
                        }}».</InfoNote
                    >
                </div>
            </div>

            <p
                aria-live="polite"
                role="status"
                class="sr-only"
                data-testid="command-palette-status"
                >{{ announcement }}</p
            >
            <p class="hidden border-t border-slate-200 px-4 py-2 text-xs text-slate-600 sm:block">
                ↑ ↓ para moverte · Intro para abrir · Esc para cerrar
            </p>
        </div>
    </BaseDialog>
</template>
