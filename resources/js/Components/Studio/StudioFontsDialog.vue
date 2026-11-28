<script setup>
import { requestJson } from '@/lib/http';
import { onBeforeUnmount, ref, watch } from 'vue';
import StudioDialog from './StudioDialog.vue';
import { studioRequest } from '@/Studio/api';
import { loadStudioFont } from '@/Studio/fonts';
import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

const props = defineProps({ modelValue: Boolean, brandId: Number });
const emit = defineEmits(['update:modelValue', 'changed']);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const query = ref('');
const archived = ref(false);
const busy = ref(false);
const error = ref('');
const status = ref('');
const uploadName = ref('');
const fileInput = ref(null);
const previewFamilies = ref({});
const previewBusy = ref(null);
const blockedDeletes = ref({});
let generation = 0;
let disposed = false;
const requests = new Set();

async function load(page = 1) {
    if (!props.brandId) return;
    const token = ++generation;
    busy.value = true; error.value = '';
    try {
        const search = new URLSearchParams({ query: query.value.trim(), page: String(page), per_page: '20', archived: archived.value ? '1' : '0' });
        const result = await studioRequest('GET', `${route('studio.fonts.index', props.brandId)}?${search}`);
        if (disposed || token !== generation) return;
        rows.value = result.fonts ?? []; pagination.value = result.pagination ?? { current_page: 1, last_page: 1, total: rows.value.length };
        emit('changed', { fonts: rows.value });
    } catch (e) { if (!disposed && token === generation) error.value = e.message; }
    finally { if (!disposed && token === generation) busy.value = false; }
}
async function upload(event) {
    const file = event.target.files?.[0];
    if (!file || busy.value) return;
    if (file.size > 8 * 1024 * 1024) { error.value = 'La tipografía supera el límite de 8 MiB.'; event.target.value = ''; return; }
    const abort = new AbortController(); requests.add(abort);
    busy.value = true; error.value = ''; status.value = 'Validando y guardando la tipografía…';
    try {
        const body = new FormData(); body.append('file', file);
        if (uploadName.value.trim()) body.append('name', uploadName.value.trim());
        const result = await requestJson('POST', route('studio.fonts.store', props.brandId), {
            body,
            signal: abort.signal,
            errorMessage: data => Object.values(data?.errors ?? {}).flat().join(' ') || data?.message || 'No se pudo validar la tipografía.',
        });
        if (disposed) return;
        emit('changed', { fonts: [result.font] });
        uploadName.value = ''; query.value = ''; archived.value = false;
        status.value = 'Tipografía guardada en esta marca.';
        await load();
    } catch (e) { if (!disposed && e.name !== 'AbortError') { error.value = e.message; status.value = ''; } }
    finally { requests.delete(abort); if (!disposed) { busy.value = false; event.target.value = ''; } }
}
async function mutate(font, action) {
    if (busy.value) return;
    const brandId = props.brandId;
    if (action === 'delete' && !(await ask({ message: `¿Eliminar la tipografía «${font.name}»?`, confirmLabel: 'Eliminar tipografía' }))) return;
    if (disposed || !props.modelValue || busy.value || brandId !== props.brandId) return;
    busy.value = true; error.value = ''; status.value = '';
    try {
        if (action === 'delete') {
            await studioRequest('DELETE', route('studio.fonts.destroy', { brand: props.brandId, font: font.id }));
            emit('changed', { deletedIds: [font.id] });
            status.value = 'Tipografía eliminada.';
        } else {
            const result = await studioRequest('POST', route(`studio.fonts.${action}`, { brand: props.brandId, font: font.id }), {});
            emit('changed', { fonts: [result.font] });
            status.value = action === 'archive' ? 'Tipografía archivada. Los diseños que la usan se conservan.' : 'Tipografía restaurada.';
        }
        await load();
    } catch (e) {
        if (!disposed) {
            error.value = e.message;
            if (action === 'delete' && e.status === 409) blockedDeletes.value = { ...blockedDeletes.value, [font.id]: true };
        }
    }
    finally { if (!disposed) busy.value = false; }
}
async function preview(font) {
    if (previewBusy.value !== null) return;
    const abort = new AbortController(); requests.add(abort);
    previewBusy.value = font.id; previewFamilies.value = {}; error.value = '';
    try {
        const family = await loadStudioFont(font, { signal: abort.signal, timeout: 15000 });
        if (!disposed) previewFamilies.value = { [font.id]: family };
    } catch (e) { if (!disposed && e.name !== 'AbortError') error.value = e.message; }
    finally { requests.delete(abort); if (!disposed) previewBusy.value = null; }
}
watch(() => props.modelValue, value => {
    if (value) { status.value = ''; blockedDeletes.value = {}; load(); }
    else { generation++; requests.forEach(controller => controller.abort()); busy.value = false; previewBusy.value = null; }
}, { immediate: true });
onBeforeUnmount(() => { disposed = true; generation++; requests.forEach(controller => controller.abort()); });
</script>

<template>
    <StudioDialog
        :model-value="modelValue"
        title="Tipografías de marca"
        wide
        @update:model-value="value => emit('update:modelValue', value)"
    >
        <div class="space-y-6">
            <p class="text-sm leading-relaxed text-foreground-subtle"
                >Sube los archivos tipográficos de esta marca para usarlos en el editor y en sus exportaciones. Los
                diseños conservan la referencia al archivo original.</p
            >
            <fieldset
                class="space-y-3 rounded-2xl border border-dashed border-accent-secondary-border bg-accent-secondary-soft/50 p-4"
                :disabled="busy"
            >
                <legend class="px-1 text-sm font-semibold text-accent-secondary-ink">Añadir una tipografía</legend>
                <label class="block text-sm font-medium"
                    >Nombre de la tipografía <span class="font-normal text-foreground-subtle">(opcional)</span
                    ><input
                        v-model="uploadName"
                        class="field mt-1"
                        maxlength="255"
                        placeholder="Nombre para tu biblioteca"
                /></label>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".ttf,.otf,.woff,.woff2"
                    aria-label="Subir tipografía"
                    class="block w-full min-w-0 text-xs text-foreground-muted file:mr-3 file:rounded-lg file:border-0 file:bg-accent-secondary-fill file:px-3 file:py-2 file:font-semibold file:text-on-accent-secondary"
                    @change="upload"
                />
                <p class="text-xs leading-relaxed text-foreground-subtle"
                    >TTF, OTF, WOFF o WOFF2 · hasta 8 MiB. Se comprueba el archivo antes de incorporarlo.</p
                >
            </fieldset>
            <form class="flex flex-wrap items-center gap-3" role="search" @submit.prevent="load()">
                <label class="sr-only" for="studio-font-search">Buscar tipografías</label
                ><input
                    id="studio-font-search"
                    v-model="query"
                    type="search"
                    class="field min-w-0 flex-1"
                    placeholder="Buscar por nombre o familia…"
                />
                <button type="submit" class="btn-secondary" :disabled="busy">Buscar tipografías</button>
                <label class="flex w-full items-center gap-2 text-xs text-foreground-muted"
                    ><input v-model="archived" type="checkbox" :disabled="busy" @change="load()" />Mostrar tipografías
                    archivadas</label
                >
            </form>
            <p
                v-if="error"
                role="alert"
                class="rounded-xl border border-danger-border bg-danger-soft p-3 text-sm text-danger-ink"
                >{{ error }}</p
            >
            <p
                v-if="status"
                role="status"
                class="rounded-xl bg-accent-secondary-soft p-3 text-sm text-accent-secondary-ink"
                >{{ status }}</p
            >
            <p v-if="busy" role="status" class="text-sm text-foreground-subtle">Preparando tipografías…</p>
            <ul v-if="rows.length" class="space-y-3">
                <li v-for="font in rows" :key="font.id" class="min-w-0 space-y-3 rounded-2xl border border-border p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3"
                        ><div class="min-w-0"
                            ><h3 class="break-words text-sm font-semibold">{{ font.name }}</h3
                            ><p class="mt-1 break-words text-xs text-foreground-subtle"
                                >{{ font.family }} · {{ font.style }} · {{ font.weight }}
                                <span v-if="font.archived_at">· Archivada</span></p
                            ></div
                        ><span
                            class="rounded-full bg-surface-hover px-2 py-1 text-[10px] font-medium uppercase text-foreground-muted"
                            >{{ font.format }}</span
                        ></div
                    >
                    <p
                        v-if="previewFamilies[font.id]"
                        class="break-words rounded-xl bg-canvas px-3 py-4 text-2xl"
                        :style="{ fontFamily: previewFamilies[font.id], fontWeight: font.weight, fontStyle: font.style }"
                        >Una idea con personalidad. Aa Bb 0123</p
                    >
                    <div class="flex flex-wrap gap-2"
                        ><button
                            type="button"
                            class="btn-secondary text-xs"
                            :aria-label="`Vista previa de tipografía ${font.name}`"
                            :disabled="busy || previewBusy !== null"
                            @click="preview(font)"
                            >{{ previewBusy === font.id ? 'Cargando fuente…' : 'Cargar vista previa' }}</button
                        ><button
                            type="button"
                            class="btn-secondary text-xs"
                            :aria-label="`${font.archived_at ? 'Restaurar' : 'Archivar'} tipografía ${font.name}`"
                            :disabled="busy"
                            @click="mutate(font, font.archived_at ? 'restore' : 'archive')"
                            >{{ font.archived_at ? 'Restaurar' : 'Archivar' }}</button
                        ><button
                            type="button"
                            class="rounded-lg px-3 py-2 text-xs font-medium text-danger hover:bg-danger-soft disabled:opacity-50"
                            :aria-label="`Eliminar tipografía ${font.name}`"
                            :disabled="busy || blockedDeletes[font.id]"
                            @click="mutate(font, 'delete')"
                            >Eliminar</button
                        ></div
                    >
                    <p v-if="blockedDeletes[font.id]" class="text-xs leading-relaxed text-warning-ink"
                        >Esta fuente sigue en uso. Puedes archivarla; su eliminación estará disponible cuando dejen de
                        referenciarla los diseños, revisiones y la identidad de marca.</p
                    >
                </li>
            </ul>
            <p
                v-else-if="!busy"
                class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-foreground-subtle"
                >{{ query ? 'No hay tipografías con esa búsqueda.' : archived ? 'No hay tipografías archivadas.' : 'Sube tu primera tipografía para empezar.' }}</p
            >
            <nav
                v-if="pagination.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 text-xs"
                aria-label="Paginación de tipografías"
                ><button
                    type="button"
                    class="btn-secondary text-xs"
                    aria-label="Página anterior de tipografías"
                    :disabled="busy || pagination.current_page <= 1"
                    @click="load(pagination.current_page - 1)"
                    >Anterior</button
                ><span
                    >{{ pagination.current_page }} / {{ pagination.last_page }} ·
                    {{ pagination.total }} tipografías</span
                ><button
                    type="button"
                    class="btn-secondary text-xs"
                    aria-label="Página siguiente de tipografías"
                    :disabled="busy || pagination.current_page >= pagination.last_page"
                    @click="load(pagination.current_page + 1)"
                    >Siguiente</button
                ></nav
            >
            <p class="border-t border-border-subtle pt-4 text-xs leading-relaxed text-foreground-subtle"
                >Archivar oculta una fuente del selector sin alterar los diseños existentes. Para eliminar sus bytes,
                primero debe dejar de estar referenciada por diseños, revisiones o la identidad de marca.</p
            >
        </div>
    </StudioDialog>
</template>
