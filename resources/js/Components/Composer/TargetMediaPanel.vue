<script setup>
import { computed, watch } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';

/**
 * Media por destino (T-17). Por defecto cada red publica la media común; «Personalizar media para esta red» copia esa lista para que
 * la red tenga su propia selección, orden, texto alternativo y portada. «Volver a la media común» descarta la propia. Lo que se
 * valida, se previsualiza y se publica es siempre la media EFECTIVA de la red.
 */
const props = defineProps({ row: { type: Object, required: true } });
const {
    form, editable, mediaById, mediaMeta, altMax, hasOwnMedia, customizeMedia, resetMedia, moveTargetMedia, removeTargetMedia,
    addTargetMedia, addableMedia, loadLibrary, libraryState,
} = useComposerContext();

const target = computed(() => props.row.target);
const own = computed(() => hasOwnMedia(target.value));
const meta = computed(() => mediaMeta.value[target.value.content_type] ?? { alt: false, cover: false });
const covers = computed(() => [...form.media, ...(target.value.media ?? []).map((m) => m.cover_id).filter(Boolean)]
    .map((id) => mediaById(id)).filter((m, i, all) => m && m.kind === 'image' && all.findIndex((x) => x.id === m.id) === i));
const idBase = computed(() => `target-media-${props.row.key}`);
const name = (id) => mediaById(id)?.name ?? `Archivo ${id}`;
const kindLabel = (id) => (mediaById(id)?.kind === 'video' ? 'vídeo' : 'imagen');
const isVideo = (id) => mediaById(id)?.kind === 'video';
watch(own, (value) => { if (value) loadLibrary(); }, { immediate: true });
const onAdd = (e) => {
    addTargetMedia(target.value, Number(e.target.value));
    e.target.value = '';
};
</script>

<template>
    <section class="mt-4 rounded border border-slate-200 p-3" :aria-labelledby="`${idBase}-title`" data-testid="target-media-panel">
        <h3 :id="`${idBase}-title`" class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900">
            Media de {{ row.label }}
            <span v-if="own" class="rounded bg-indigo-100 px-1.5 text-xs text-indigo-800" data-testid="target-media-own">media propia</span>
            <span v-else class="rounded bg-slate-100 px-1.5 text-xs text-slate-700" data-testid="target-media-inherited">media común</span>
        </h3>

        <template v-if="!own">
            <p class="mt-1 text-xs text-slate-700">Esta red publica la media común ({{ form.media.length }} {{ form.media.length === 1 ? 'archivo' : 'archivos' }}).</p>
            <button v-if="editable" type="button" class="btn btn-secondary mt-2 px-3 py-1 text-sm" data-testid="target-media-customize" @click="customizeMedia(target)">Personalizar media para esta red</button>
        </template>

        <template v-else>
            <p class="mt-1 text-xs text-slate-700">Solo esta red publica estos archivos, en este orden.</p>
            <p v-if="!target.media.length" class="mt-2 text-sm text-slate-700" data-testid="target-media-empty">Esta red no publicará ningún archivo.</p>
            <ul v-else class="mt-2 space-y-2">
                <li v-for="(item, i) in target.media" :key="item.id" class="rounded border border-slate-200 p-2 text-sm" data-testid="target-media-item" :data-media-id="item.id">
                    <div class="flex items-center justify-between gap-2">
                        <span class="min-w-0 break-all">{{ i + 1 }}. {{ name(item.id) }} <span class="text-slate-600">({{ kindLabel(item.id) }})</span></span>
                        <span v-if="editable" class="shrink-0 space-x-2">
                            <button type="button" class="text-slate-700" :aria-label="`Subir el archivo ${i + 1} de ${row.label}`" @click="moveTargetMedia(target, i, -1)">↑</button>
                            <button type="button" class="text-slate-700" :aria-label="`Bajar el archivo ${i + 1} de ${row.label}`" @click="moveTargetMedia(target, i, 1)">↓</button>
                            <button type="button" class="text-red-700" :aria-label="`Quitar el archivo ${i + 1} de ${row.label}`" @click="removeTargetMedia(target, i)">Quitar</button>
                        </span>
                    </div>
                    <div v-if="meta.alt && !isVideo(item.id)" class="mt-2">
                        <label :for="`${idBase}-alt-${item.id}`" class="block text-xs font-medium text-slate-900">Texto alternativo ({{ row.label }})</label>
                        <textarea :id="`${idBase}-alt-${item.id}`" v-model="item.alt" rows="2" :maxlength="altMax" :disabled="!editable" class="field mt-1 w-full text-sm" :data-testid="`target-media-alt-${item.id}`" />
                    </div>
                    <div v-if="meta.cover && isVideo(item.id)" class="mt-2">
                        <label :for="`${idBase}-cover-${item.id}`" class="block text-xs font-medium text-slate-900">Portada del vídeo ({{ row.label }})</label>
                        <select :id="`${idBase}-cover-${item.id}`" v-model="item.cover_id" :disabled="!editable" class="field mt-1 w-full text-sm" :data-testid="`target-media-cover-${item.id}`">
                            <option :value="null">Sin portada propia</option>
                            <option v-for="c in covers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </li>
            </ul>

            <div v-if="editable" class="mt-2 flex flex-wrap items-center gap-2">
                <template v-if="addableMedia(target).length">
                    <label :for="`${idBase}-add`" class="text-xs font-medium text-slate-900">Añadir archivo a esta red</label>
                    <select :id="`${idBase}-add`" class="field text-sm" data-testid="target-media-add" @change="onAdd">
                        <option value="">Elige un archivo</option>
                        <option v-for="m in addableMedia(target)" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </template>
                <span v-else-if="libraryState === 'loading'" class="text-xs text-slate-700" role="status">Cargando la biblioteca…</span>
                <span v-else-if="libraryState === 'error'" class="text-xs text-red-700" role="alert">No se pudo cargar la biblioteca.</span>
                <button type="button" class="btn btn-secondary px-3 py-1 text-sm" data-testid="target-media-reset" @click="resetMedia(target)">Volver a la media común</button>
            </div>
        </template>
    </section>
</template>
