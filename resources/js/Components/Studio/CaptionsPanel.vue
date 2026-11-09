<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { CAPTION_LIMITS, compileCaptions, parseCaptionFile, encodeCaptionFile } from '@/Studio/captions';
import { downloadBlob } from '@/Studio/exports';
const props = defineProps({ document: Object, time: Number, duration: Number, apply: Function, disabled: Boolean, fonts: Array, kitFonts: Array });
const emit = defineEmits(['seek']);
const error = ref(''), warnings = ref([]), saving = ref(false), selectedId = ref(''), query = ref(''), page = ref(1), mode = ref('append'), offset = ref(0);
const captions = computed(() => props.document.timeline.captions);
const cues = computed(() => captions.value?.cues ?? []);
const style = computed(() => captions.value?.style ?? { fontFamily: 'Arial' });
const index = computed(() => compileCaptions(cues.value));
const activeIds = computed(() => new Set(index.value.at(props.time ?? 0).map(cue => cue.id)));
const filtered = computed(() => cues.value.filter(cue => cue.text.toLocaleLowerCase().includes(query.value.toLocaleLowerCase())));
const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / 50)));
const rows = computed(() => filtered.value.slice((page.value - 1) * 50, page.value * 50));
const selected = computed(() => cues.value.find(cue => cue.id === selectedId.value));
const blocked = computed(() => props.disabled || saving.value);
const families = computed(() => [...new Set(['Arial', 'Georgia', 'Verdana', ...(props.kitFonts ?? [])])]);
let generation = 0;
watch(query, () => { page.value = 1; });
watch(() => props.document, () => { generation++; page.value = Math.min(page.value, lastPage.value); });
onBeforeUnmount(() => { generation++; });
async function apply(operation) {
    if (blocked.value) return;
    error.value = ''; warnings.value = []; saving.value = true;
    try { await props.apply(operation); }
    catch (e) { error.value = e.message; }
    finally { saving.value = false; }
}
async function addCue() {
    const start = props.time < props.duration ? props.time : 0;
    const cue = { id: `cue_${crypto.randomUUID()}`, start, end: Math.min(props.duration, start + 2), text: 'Nuevo subtítulo' };
    await apply({ action: 'upsert', cue });
    selectedId.value = cue.id;
}
async function importFile(event) {
    const file = event.target.files?.[0];
    if (!file || blocked.value) return;
    error.value = ''; warnings.value = [];
    const request = ++generation;
    try {
        if (file.size > CAPTION_LIMITS.importBytes || !/\.(?:srt|vtt)$/i.test(file.name)) throw new Error('Elige un archivo SRT o VTT de hasta512KiB.');
        const bytes = await file.arrayBuffer();
        if (request !== generation) return;
        if (bytes.byteLength > CAPTION_LIMITS.importBytes) throw new Error('El archivo supera512KiB.');
        const text = new TextDecoder('utf-8', { fatal: true }).decode(bytes), format = /\.vtt$/i.test(file.name) ? 'vtt' : 'srt';
        const parsed = parseCaptionFile(text, format);
        await apply({ action: 'import', format, text, mode: mode.value });
        if (!error.value) warnings.value = parsed.warnings;
    } catch (e) { error.value = e.message; }
    finally { event.target.value = ''; }
}
function patchCue(patch) { if (selected.value) return apply({ action: 'upsert', cue: { ...selected.value, ...patch } }); }
function patchStyle(key, value) { return apply({ action: 'style', style: { ...style.value, [key]: value } }); }
function chooseFont(value) {
    const next = { ...style.value };
    if (value.startsWith('id:')) {
        const font = props.fonts?.find(font => font.id === Number(value.slice(3)));
        if (!font) { error.value = 'La tipografía no está disponible.'; return; }
        next.font_id = font.id; next.fontFamily = font.family;
    } else { delete next.font_id; next.fontFamily = value; }
    return apply({ action: 'style', style: next });
}
function exportFile(format) {
    error.value = '';
    try {
        const text = encodeCaptionFile(cues.value, format);
        downloadBlob(new Blob([text], { type: format === 'vtt' ? 'text/vtt;charset=utf-8' : 'application/x-subrip;charset=utf-8' }), `captions.${format}`);
    } catch (e) { error.value = e.message; }
}
</script>
<template>
    <section aria-label="Subtítulos" class="min-w-0 space-y-3 border-t border-slate-200 pt-4" data-testid="captions-panel">
        <div class="flex flex-wrap items-center gap-2"><h3 class="mr-auto text-xs font-semibold text-slate-700">Subtítulos <span class="font-normal text-slate-400">{{ cues.length }}/5000</span></h3><button type="button" class="caption-button" :disabled="blocked" @click="addCue">Añadir subtítulo</button></div>
        <p class="text-[11px] text-slate-500">Una pista para todo el proyecto. Importa SRT/VTT o escribe texto; el reconocimiento de voz y la traducción aún no están disponibles.</p>
        <div class="flex flex-wrap items-end gap-2">
            <label class="caption-label">Al importar<select v-model="mode" :disabled="blocked"><option value="append">Añadir a la pista</option><option value="replace">Reemplazar la pista</option></select></label>
            <label class="caption-label min-w-0 flex-1">Archivo SRT o VTT<input type="file" accept=".srt,.vtt" aria-label="Importar subtítulos" :disabled="blocked" class="max-w-full text-xs" @change="importFile" /></label>
            <button type="button" class="caption-button" :disabled="blocked || !cues.length" @click="exportFile('srt')">Descargar SRT</button><button type="button" class="caption-button" :disabled="blocked || !cues.length" @click="exportFile('vtt')">Descargar VTT</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-2 text-xs text-red-800">{{ error }}</p><p v-for="warning in warnings" :key="warning" role="status" class="text-xs text-amber-800">{{ warning }}</p>
        <label class="caption-label">Buscar subtítulos<input v-model="query" type="search" aria-label="Buscar subtítulos" /></label>
        <div class="max-h-52 space-y-1 overflow-y-auto">
            <button v-for="cue in rows" :key="cue.id" type="button" :aria-label="`Editar subtítulo ${cue.id}`" :aria-pressed="selectedId === cue.id" :disabled="blocked" data-testid="caption-row"
                class="flex w-full min-w-0 gap-2 rounded-lg border p-2 text-left text-xs" :class="activeIds.has(cue.id) ? 'border-violet-500 bg-violet-50' : 'border-slate-100'" @click="selectedId = cue.id">
                <span class="shrink-0 text-[10px] text-slate-500">{{ cue.start.toFixed(3) }}–{{ cue.end.toFixed(3) }}</span><span class="min-w-0 truncate">{{ cue.text }}</span><span v-if="activeIds.has(cue.id)" class="sr-only">Activo</span>
            </button><p v-if="!rows.length" class="text-xs text-slate-500">No hay subtítulos para mostrar.</p>
        </div>
        <div v-if="lastPage > 1" class="flex items-center gap-2 text-xs"><button type="button" class="caption-button" :disabled="page <= 1" @click="page--">Anterior</button><span>Página {{ page }} de {{ lastPage }}</span><button type="button" class="caption-button" :disabled="page >= lastPage" @click="page++">Siguiente</button></div>
        <div v-if="selected" class="space-y-2 rounded-xl bg-violet-50/50 p-3">
            <label class="caption-label">Texto del subtítulo<textarea :value="selected.text" aria-label="Texto del subtítulo" rows="3" :disabled="blocked" @change="patchCue({ text: $event.target.value })" /></label>
            <div class="grid grid-cols-2 gap-2"><label class="caption-label">Inicio del subtítulo<input type="number" min="0" :max="duration" step="0.001" :value="selected.start" :disabled="blocked" @change="patchCue({ start: Number($event.target.value) })" /></label><label class="caption-label">Fin del subtítulo<input type="number" min="0" :max="duration" step="0.001" :value="selected.end" :disabled="blocked" @change="patchCue({ end: Number($event.target.value) })" /></label></div>
            <div class="flex flex-wrap gap-2"><button type="button" class="caption-button" :disabled="blocked" @click="emit('seek', selected.start)">Ir al subtítulo</button><button type="button" class="caption-button text-red-700" :disabled="blocked" @click="apply({ action: 'delete', ids: [selected.id] })">Eliminar subtítulo</button></div>
        </div>
        <div class="flex flex-wrap items-end gap-2"><label class="caption-label">Desplazar toda la pista (segundos)<input v-model.number="offset" type="number" step="0.001" :disabled="blocked" /></label><button type="button" class="caption-button" :disabled="blocked || !cues.length" @click="apply({ action: 'offset', seconds: offset })">Aplicar desplazamiento</button></div>
        <details v-if="captions" class="rounded-lg border border-slate-100 p-2"><summary class="cursor-pointer text-xs font-medium">Estilo de subtítulos</summary>
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <label class="caption-label col-span-2">Fuente de subtítulos<select :value="style.font_id ? `id:${style.font_id}` : style.fontFamily ?? 'Arial'" :disabled="blocked" @change="chooseFont($event.target.value)"><option v-for="family in families" :key="family" :value="family">{{ family }}</option><option v-for="font in fonts" :key="font.id" :value="`id:${font.id}`">{{ font.name }} · {{ font.family }}</option></select></label>
                <label class="caption-label">Tamaño<input type="number" min="1" max="1000" :value="style.fontSize ?? 32" :disabled="blocked" @change="patchStyle('fontSize', Number($event.target.value))" /></label>
                <label class="caption-label">Color<input type="color" :value="style.fill ?? '#ffffff'" :disabled="blocked" @change="patchStyle('fill', $event.target.value)" /></label>
                <label class="caption-label">Contorno<input type="color" :value="style.stroke ?? '#000000'" :disabled="blocked" @change="patchStyle('stroke', $event.target.value)" /></label>
                <label class="caption-label">Grosor<input type="number" min="0" max="20" step="0.5" :value="style.strokeWidth ?? 2" :disabled="blocked" @change="patchStyle('strokeWidth', Number($event.target.value))" /></label>
                <label class="caption-label">Posición<select :value="style.position ?? 'bottom'" :disabled="blocked" @change="patchStyle('position', $event.target.value)"><option value="top">Arriba</option><option value="center">Centro</option><option value="bottom">Abajo</option></select></label>
                <label class="caption-label">Alineación<select :value="style.textAlign ?? 'center'" :disabled="blocked" @change="patchStyle('textAlign', $event.target.value)"><option value="left">Izquierda</option><option value="center">Centro</option><option value="right">Derecha</option></select></label>
                <label class="caption-label">Margen seguro<input type="number" min="0" max="0.25" step="0.01" :value="style.margin ?? 0.05" :disabled="blocked" @change="patchStyle('margin', Number($event.target.value))" /></label>
                <label class="caption-label">Fondo<input type="color" :value="style.background ?? '#000000'" :disabled="blocked" @change="patchStyle('background', $event.target.value)" /></label>
                <label class="caption-label">Peso<select :value="style.fontWeight ?? 'normal'" :disabled="blocked" @change="patchStyle('fontWeight', $event.target.value)"><option value="normal">Normal</option><option value="bold">Negrita</option></select></label>
                <label class="caption-label">Estilo<select :value="style.fontStyle ?? 'normal'" :disabled="blocked" @change="patchStyle('fontStyle', $event.target.value)"><option value="normal">Normal</option><option value="italic">Cursiva</option></select></label>
            </div>
        </details>
    </section>
</template>
<style scoped>
.caption-label{display:flex;min-width:0;flex-direction:column;gap:4px;font-size:11px;color:#475569}.caption-label input,.caption-label select,.caption-label textarea{width:100%;min-width:0;border-radius:8px;border-color:#cbd5e1;padding:6px 8px;font-size:12px}.caption-button{border:1px solid #ddd6fe;border-radius:8px;padding:7px 10px;font-size:11px;color:#6d28d9;background:white}.caption-button:disabled{opacity:.4;cursor:not-allowed}
</style>
