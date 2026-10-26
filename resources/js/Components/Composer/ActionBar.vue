<script setup>
import { BRANDED_CONTENT_URL, MUSIC_USAGE_URL } from '@/Composables/tiktokCreator';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import DangerButton from '@/Components/DangerButton.vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Barra de acciones: botón dividido. El botón principal programa; el menú (`aria-haspopup="menu"`, flechas, Inicio/Fin y Escape)
 * trae Guardar borrador, Guardar en biblioteca y Publicar ahora. Programar y Publicar ahora se deshabilitan mientras haya
 * errores en vivo (`ErrorsPanel`); Guardar borrador y Guardar en biblioteca siguen disponibles. Enviar a revisión y
 * publicaciones recurrentes quedan fuera del alcance.
 */
const { post, form, accounts, editable, submit, remove, publishNow, sendingNow, hasBlockingErrors, scheduleBlocked, uploadingMedia, libraryName, savingToLibrary, libraryErrors, saveToLibrary } = useComposerContext();

// Consentimiento que TikTok exige mostrar antes de publicar con Direct Post (Content Sharing Guidelines).
const tiktokDirect = computed(() => form.targets.filter((t) => accounts.value.find((a) => a.id === t.account_id && a.network === 'tiktok' && a.direct_post)));
const tiktokBranded = computed(() => tiktokDirect.value.some((t) => t.options?.brand_content_toggle === true));

const open = ref(false);
const root = ref(null);
const toggle = ref(null);
const menu = ref(null);

const busy = computed(() => form.processing || sendingNow.value);
// Programar también se bloquea con la fecha pasada; Publicar ahora y Guardar borrador no dependen de la fecha. Con una subida de
// media en curso se bloquean TODAS las acciones (también Guardar borrador, que no guardaría nada) y se avisa.
const blocked = computed(() => busy.value || scheduleBlocked.value);
const canPublishNow = computed(() => !busy.value && !hasBlockingErrors.value && form.targets.length > 0);

const items = computed(() => [
    { key: 'draft', label: 'Guardar borrador', disabled: busy.value || uploadingMedia.value, run: () => submit(false) },
    { key: 'library', label: 'Guardar en biblioteca', disabled: savingToLibrary.value || uploadingMedia.value, run: () => openLibrary() },
    { key: 'now', label: 'Publicar ahora', disabled: !canPublishNow.value, run: () => publishNow() },
]);

const menuButtons = () => [...(menu.value?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? [])];
const closeMenu = (focusToggle = true) => {
    open.value = false;
    if (focusToggle) nextTick(() => toggle.value?.focus());
};
const openMenu = async (last = false) => {
    open.value = true;
    await nextTick();
    const buttons = menuButtons();
    (last ? buttons[buttons.length - 1] : buttons[0])?.focus();
};
const onToggleKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); openMenu(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); openMenu(true); }
};
const onMenuKey = (e) => {
    const buttons = menuButtons();
    const i = buttons.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); buttons[(i + 1) % buttons.length]?.focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); buttons[(i - 1 + buttons.length) % buttons.length]?.focus(); }
    else if (e.key === 'Home') { e.preventDefault(); buttons[0]?.focus(); }
    else if (e.key === 'End') { e.preventDefault(); buttons[buttons.length - 1]?.focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(); }
    else if (e.key === 'Tab') closeMenu(false);
};
const choose = (item) => {
    if (item.disabled) return;
    closeMenu(item.key === 'draft');
    item.run();
};
const onOutside = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));

// ---- Guardar en biblioteca: pide un nombre (no crea ninguna publicación)
const libraryOpen = ref(false);
const nameInput = ref(null);
const libraryDialog = ref(null);
const openLibrary = async () => {
    libraryOpen.value = true;
    await nextTick();
    nameInput.value?.focus();
    nameInput.value?.select();
};
const closeLibrary = () => {
    libraryOpen.value = false;
    nextTick(() => toggle.value?.focus());
};
const confirmLibrary = () => saveToLibrary(libraryName.value, { onSaved: closeLibrary });
const onLibraryKey = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        closeLibrary();
    } else if (e.key === 'Tab') {
        const els = [...libraryDialog.value.querySelectorAll('button:not([disabled]), input')];
        if (e.shiftKey && document.activeElement === els[0]) { e.preventDefault(); els[els.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === els[els.length - 1]) { e.preventDefault(); els[0].focus(); }
    }
};
</script>

<template>
    <div v-if="editable" class="flex flex-wrap items-center gap-3" data-testid="action-bar">
        <div ref="root" class="relative inline-flex">
            <button type="submit" class="btn-primary rounded-r-none" :disabled="blocked">{{ sendingNow ? 'Enviando…' : 'Programar' }}</button>
            <button
                id="actions-menu-button"
                ref="toggle"
                type="button"
                class="btn-primary rounded-l-none border-l border-indigo-400 px-2.5"
                aria-haspopup="menu"
                aria-controls="actions-menu"
                :aria-expanded="open"
                aria-label="Más acciones de publicación"
                :disabled="busy"
                @click="open ? closeMenu() : openMenu()"
                @keydown="onToggleKey"
            ><span aria-hidden="true">▾</span></button>

            <ul v-show="open" id="actions-menu" ref="menu" role="menu" aria-labelledby="actions-menu-button" class="absolute left-0 top-full z-30 mt-1 min-w-[14rem] rounded-lg border border-slate-200 bg-white py-1 shadow-lg" @keydown="onMenuKey">
                <li v-for="item in items" :key="item.key" role="none">
                    <button type="button" role="menuitem" tabindex="-1" :disabled="item.disabled" class="block w-full px-4 py-2 text-left text-sm text-slate-800 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none disabled:cursor-not-allowed disabled:text-slate-500" @click="choose(item)">{{ item.label }}</button>
                </li>
            </ul>
        </div>

        <p v-if="tiktokDirect.length" class="basis-full text-xs text-slate-700" data-testid="tiktok-consent">
            Al publicar en TikTok, aceptas
            <template v-if="tiktokBranded">la <a :href="BRANDED_CONTENT_URL" target="_blank" rel="noopener noreferrer" class="underline">Política de contenido de marca (Branded Content Policy)</a> y </template>la
            <a :href="MUSIC_USAGE_URL" target="_blank" rel="noopener noreferrer" class="underline">Confirmación de uso de música de TikTok (Music Usage Confirmation)</a>.
        </p>
        <p v-if="uploadingMedia" role="status" class="text-sm text-slate-700" data-testid="uploading-note">Espera a que termine la subida.</p>
        <p v-if="sendingNow" role="status" class="text-sm text-slate-700" data-testid="sending-now">Enviando… no cierres esta página.</p>
        <DangerButton v-if="post" type="button" class="ml-auto" @click="remove">Eliminar</DangerButton>
    </div>

    <div v-if="libraryOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4" data-testid="library-dialog">
        <div ref="libraryDialog" role="dialog" aria-modal="true" aria-labelledby="library-title" class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl" @keydown="onLibraryKey">
            <h2 id="library-title" class="text-lg font-semibold text-slate-900">Guardar en la biblioteca</h2>
            <p class="mt-1 text-sm text-slate-700">Se guarda una copia del texto, los archivos y las redes actuales. No se crea ninguna publicación.</p>
            <label class="mt-3 block text-sm">
                <span class="block text-xs font-medium text-slate-700">Nombre de la plantilla</span>
                <input ref="nameInput" v-model="libraryName" type="text" maxlength="120" class="field" :aria-invalid="!!libraryErrors.name" aria-describedby="library-error" @keydown.enter.prevent="confirmLibrary" />
            </label>
            <p id="library-error" class="mt-1 min-h-[1.25rem] text-sm text-red-700" role="alert">{{ libraryErrors.name ?? libraryErrors.brand ?? '' }}</p>
            <div class="mt-3 flex flex-wrap justify-end gap-2">
                <button type="button" class="btn btn-secondary" @click="closeLibrary">Cancelar</button>
                <button type="button" class="btn btn-primary" :disabled="savingToLibrary || !libraryName.trim()" @click="confirmLibrary">Guardar</button>
            </div>
        </div>
    </div>
</template>
