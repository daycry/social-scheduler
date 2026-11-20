<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';
import { computed, nextTick, reactive, ref } from 'vue';
import { UTM_FIELDS, buildUtmUrl, parseHttpUrl } from '@/Composables/utm';

/**
 * Generador de URL con UTM (C-05a). Todo ocurre en el cliente: no hay modelo ni endpoint (el acortador está aplazado). Es un diálogo
 * modal con el foco atrapado; Escape lo cierra y el foco vuelve al botón que lo abrió (`returnFocusTo`). Al añadir, emite `insert` con
 * la URL construida con `URL`/`URLSearchParams` y el compositor la inserta en el cursor.
 */
defineProps({
    open: { type: Boolean, default: false },
    returnFocusTo: { type: Object, default: null },
});
const emit = defineEmits(['insert', 'close']);

const blank = () => ({ url: '', utm_source: '', utm_medium: '', utm_campaign: '', utm_term: '', utm_content: '' });
const fields = reactive(blank());
const touchedUrl = ref(false);
const urlInput = ref(null);

const urlOk = computed(() => parseHttpUrl(fields.url) !== null);
const result = computed(() => buildUtmUrl(fields.url, fields));
const urlError = computed(() => (touchedUrl.value && fields.url.trim() !== '' && !urlOk.value ? 'Escribe una dirección que empiece por http:// o https://.' : ''));

const clear = () => {
    Object.assign(fields, blank());
    touchedUrl.value = false;
    nextTick(() => urlInput.value?.focus());
};
const close = () => emit('close');
const add = () => {
    if (!result.value) return;
    emit('insert', result.value);
    Object.assign(fields, blank());
    touchedUrl.value = false;
    close();
};

// Enter añade el enlace si está listo.
const addOnEnter = () => {
    if (result.value) add();
};
</script>

<template>
    <BaseDialog
        v-if="open"
        :open="open"
        :return-focus-to="returnFocusTo"
        close-on-backdrop
        aria-labelledby="utm-title"
        class="bottom-3 top-auto mx-auto my-0 max-h-[calc(100dvh_-_1.5rem)] w-[calc(100vw_-_1.5rem)] max-w-lg overflow-y-auto rounded-lg border-0 bg-white p-0 shadow-xl backdrop:bg-slate-900/40 sm:inset-y-0 sm:my-auto"
        data-testid="utm-modal"
        @close="close"
    >
        <div class="p-4">
            <h2 id="utm-title" class="text-base font-semibold text-slate-900">Generar enlace con UTM</h2>
            <p class="mt-1 text-xs text-slate-600"
                >Añade parámetros de seguimiento a un enlace. Se inserta en el texto, donde tengas el cursor.</p
            >

            <div class="mt-3">
                <label for="utm-url" class="block text-sm font-medium text-slate-800"
                    >Dirección (URL) <span class="text-red-700" aria-hidden="true">*</span></label
                >
                <input
                    id="utm-url"
                    ref="urlInput"
                    data-dialog-initial-focus
                    v-model="fields.url"
                    type="url"
                    inputmode="url"
                    autocomplete="off"
                    required
                    class="field mt-1"
                    placeholder="https://ejemplo.com/oferta"
                    :aria-invalid="!!urlError"
                    aria-describedby="utm-url-error"
                    @blur="touchedUrl = true"
                    @keydown.enter.prevent="addOnEnter"
                />
                <p
                    id="utm-url-error"
                    class="mt-1 text-xs text-red-700"
                    role="alert"
                    data-testid="utm-url-error"
                    >{{ urlError }}</p
                >
            </div>

            <div v-for="f in UTM_FIELDS" :key="f.key" class="mt-3">
                <label :for="`utm-${f.key}`" class="block text-sm font-medium text-slate-800"
                    >{{ f.label }} <span class="font-normal text-slate-600">({{ f.key }})</span
                    ><span v-if="f.required" class="text-red-700" aria-hidden="true"> *</span></label
                >
                <input
                    :id="`utm-${f.key}`"
                    v-model="fields[f.key]"
                    type="text"
                    autocomplete="off"
                    :required="f.required"
                    class="field mt-1"
                    @keydown.enter.prevent="addOnEnter"
                    :aria-describedby="`utm-${f.key}-hint`"
                />
                <p :id="`utm-${f.key}-hint`" class="mt-0.5 text-xs text-slate-600">{{ f.hint }}</p>
            </div>

            <div class="mt-3">
                <p class="text-sm font-medium text-slate-800">Resultado</p>
                <p
                    class="mt-1 min-h-[2.5rem] break-all rounded border border-slate-200 bg-slate-50 p-2 text-xs text-slate-800"
                    data-testid="utm-result"
                    aria-live="polite"
                    >{{ result || 'Completa la dirección y los campos obligatorios para ver el enlace.' }}</p
                >
            </div>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" data-testid="utm-clear" @click="clear"
                    >Limpiar</button
                >
                <button
                    type="button"
                    class="btn btn-secondary px-3 py-1 text-sm"
                    data-testid="utm-cancel"
                    @click="close"
                    >Cancelar</button
                >
                <button
                    type="button"
                    class="btn btn-primary px-3 py-1 text-sm"
                    :disabled="!result"
                    data-testid="utm-add"
                    @click="add"
                    >Añadir</button
                >
            </div>
        </div>
    </BaseDialog>
</template>
