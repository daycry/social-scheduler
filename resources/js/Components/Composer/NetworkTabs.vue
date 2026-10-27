<script setup>
import { computed, nextTick, ref } from 'vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import TextEditor from '@/Components/Composer/TextEditor.vue';
import { useComposerContext } from '@/Composables/useComposer';
import { countChars } from '@/Composables/textCount';

/**
 * Texto por red (C-03). Por defecto hay UN texto común (la plantilla) y todas las redes lo publican. «Editar por red» abre pestañas
 * ARIA: «Texto común» y una por red. En cada pestaña de red, la red está VINCULADA al texto común (`text = null`, se muestra ese texto)
 * o PERSONALIZADA con su propio texto (indicador visible y «Volver al texto común»). Se pueden mezclar; si se edita el texto común,
 * solo cambian las redes vinculadas.
 */
const { form, accounts, editable, byNetwork, currentTab, isUnlinked, hasOwnText, unlink, relink, unlinkedCount, textOf, openTargetText, charsOf } = useComposerContext();

const editor = ref(null);
const confirming = ref(null); // cuenta cuyo «Volver al texto común» espera confirmación

const rows = computed(() => form.targets.map((t) => {
    const account = accounts.value.find((a) => a.id === t.account_id);
    return { key: t.account_id, target: t, network: account?.network, label: account ? `${account.network_label} · ${account.name}` : 'Cuenta eliminada' };
}));
const tabs = computed(() => [{ key: 'template', label: 'Texto común' }, ...rows.value]);
const active = computed(() => rows.value.find((r) => r.key === currentTab.value) ?? null);
const tabId = (key) => `network-tab-${key}`;

const toggle = () => {
    byNetwork.value = !byNetwork.value;
    confirming.value = null;
};

const selectTab = (key, focus = false) => {
    confirming.value = null;
    openTargetText(key); // 'template' (texto común) o el id de la cuenta de la pestaña
    if (focus) nextTick(() => document.getElementById(tabId(key))?.focus());
};

const onKey = (e) => {
    const keys = tabs.value.map((t) => t.key);
    // La pestaña con el foco (la seleccionada, salvo que se haya enfocado otra sin activarla) es el punto de partida.
    const focused = keys.findIndex((k) => tabId(k) === document.activeElement?.id);
    const i = focused >= 0 ? focused : keys.indexOf(currentTab.value);
    let to = null;
    if (e.key === 'ArrowRight') to = keys[(i + 1) % keys.length];
    else if (e.key === 'ArrowLeft') to = keys[(i - 1 + keys.length) % keys.length];
    else if (e.key === 'Home') to = keys[0];
    else if (e.key === 'End') to = keys[keys.length - 1];
    if (to === null) return;
    e.preventDefault();
    selectTab(to, true);
};

// Volver al texto común: si el texto propio tiene contenido se pide confirmación (se perderá); si está vacío, no hay nada que perder.
const askRelink = (row) => {
    if (hasOwnText(row.target)) confirming.value = row.key;
    else relink(row.target);
};
const confirmRelink = (row) => {
    relink(row.target);
    confirming.value = null;
    nextTick(() => document.getElementById(`personalize-${row.key}`)?.focus());
};

const count = computed(() => (active.value ? charsOf(active.value.target) : countChars(form.text)));
const summary = computed(() => (unlinkedCount.value === 1 ? '1 red con texto propio' : `${unlinkedCount.value} redes con texto propio`));

defineExpose({ insertAtCursor: (text) => editor.value?.insertAtCursor(text), focus: () => editor.value?.focus() });
</script>

<template>
    <div class="min-w-0" data-testid="network-tabs">
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            <button
                id="edit-by-network"
                type="button"
                class="btn btn-secondary px-3 py-1 text-sm"
                :aria-pressed="byNetwork"
                :disabled="form.targets.length === 0"
                aria-describedby="edit-by-network-hint"
                @click="toggle"
            >Editar por red</button>
            <p id="edit-by-network-hint" class="min-w-0 flex-1 text-xs text-slate-600">
                <template v-if="form.targets.length === 0">Elige una red para poder personalizar su texto.</template>
                <template v-else-if="!byNetwork && unlinkedCount > 0" ><span data-testid="own-text-summary">{{ summary }}.</span> Pulsa «Editar por red» para verlo.</template>
                <template v-else-if="!byNetwork">Todas las redes publican el mismo texto.</template>
                <template v-else>Una pestaña por red: cada una usa el texto común o el suyo propio.</template>
            </p>
        </div>

        <!-- Pestañas ARIA (flechas, Inicio y Fin) -->
        <!-- `w-0 min-w-full`: la lista de pestañas puede ser más ancha que la pantalla y se desplaza dentro; su ancho no cuenta para el mínimo del formulario -->
        <div v-if="byNetwork" class="mt-2 flex w-0 min-w-full gap-1 overflow-x-auto border-b border-slate-200 pb-px" role="tablist" aria-label="Texto por red" @keydown="onKey">
            <button
                v-for="t in tabs"
                :id="tabId(t.key)"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="currentTab === t.key"
                aria-controls="network-panel"
                :tabindex="currentTab === t.key ? 0 : -1"
                class="inline-flex max-w-[14rem] shrink-0 items-center gap-1.5 rounded-t border border-b-0 px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                :class="currentTab === t.key ? 'border-slate-300 bg-white text-slate-900' : 'border-transparent bg-slate-50 text-slate-700 hover:bg-slate-100'"
                :data-testid="`tab-${t.key}`"
                @click="selectTab(t.key)"
            >
                <NetworkIcon v-if="t.network" :network="t.network" size="sm" />
                <span class="truncate">{{ t.label }}</span>
                <span v-if="t.target && isUnlinked(t.target)" class="shrink-0 rounded bg-indigo-100 px-1.5 text-xs text-indigo-800" data-testid="own-badge">propio</span>
            </button>
        </div>

        <div id="network-panel" :role="byNetwork ? 'tabpanel' : undefined" :aria-labelledby="byNetwork ? tabId(currentTab) : undefined" class="min-w-0">
            <!-- Texto común -->
            <template v-if="!active">
                <p v-if="byNetwork" class="mt-2 text-xs text-slate-600">Es el texto de todas las redes vinculadas. Si lo editas, solo cambian las redes vinculadas.</p>
                <TextEditor ref="editor" v-model="form.text" :disabled="!editable" />
            </template>

            <!-- Una red vinculada al texto común -->
            <div v-else-if="!isUnlinked(active.target)" class="mt-2" data-testid="linked-panel">
                <p class="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700" data-testid="linked-badge">Vinculada al texto común</span>
                    Esta red publica el texto común.
                </p>
                <p class="mt-2 max-h-48 min-h-[3rem] overflow-y-auto whitespace-pre-line rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800 [overflow-wrap:anywhere]" data-testid="linked-text">{{ form.text || 'El texto común está vacío.' }}</p>
                <button v-if="editable" :id="`personalize-${active.key}`" type="button" class="btn btn-secondary mt-2 px-3 py-1 text-sm" @click="unlink(active.target)">Personalizar el texto de esta red</button>
            </div>

            <!-- Una red con texto propio -->
            <div v-else class="mt-2" data-testid="own-panel">
                <p class="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                    <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800" data-testid="own-indicator">Texto propio</span>
                    Solo esta red publica este texto.
                </p>
                <p v-if="!hasOwnText(active.target)" class="mt-1 text-xs text-amber-800" data-testid="own-empty-note">Está vacío: al guardar se publicará el texto común.</p>
                <TextEditor :id="`text-target-${active.key}`" ref="editor" v-model="active.target.text" :disabled="!editable" :label="`Texto de ${active.label}`" />
                <div v-if="editable" class="mt-2">
                    <button v-if="confirming !== active.key" type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="askRelink(active)">Volver al texto común</button>
                    <div v-else role="alert" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" data-testid="relink-confirm">
                        <p>Se perderá el texto propio de esta red.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary px-3 py-1 text-sm" @click="confirmRelink(active)">Sí, volver al texto común</button>
                            <button type="button" class="btn btn-secondary px-3 py-1 text-sm" @click="confirming = null">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <p class="text-right text-xs text-slate-600">{{ count }} caracteres</p>
    </div>
</template>
