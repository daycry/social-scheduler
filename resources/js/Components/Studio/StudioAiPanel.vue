<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { studioRequest } from '@/Studio/api';

const props = defineProps({
    brandId: Number, designId: Number, revision: Number, isTemplate: Boolean, assets: { type: Array, default: () => [] },
    presetMediaId: { type: Number, default: null }, presetToken: { type: Number, default: 0 }, active: { type: Boolean, default: false },
});
const emit = defineEmits(['ready', 'add', 'text']);

const KINDS = { generate: 'Generar imagen', edit: 'Rellenar una zona', outpaint: 'Ampliar el lienzo', variation: 'Variaciones' };
const STATES = { queued: 'En cola', processing: 'En curso', ready: 'Listo', failed: 'Error' };
const STYLES = { natural: 'Natural', photo: 'Fotografía', illustration: 'Ilustración', '3d': 'Render 3D', minimal: 'Minimalista', watercolor: 'Acuarela' };

const status = ref(null);
const loadError = ref('');
const form = reactive({ kind: 'generate', provider: '', prompt: '', aspect: '1:1', style: 'natural', count: 1, media_id: null, mask: { x: 25, y: 25, width: 50, height: 50 } });
const jobs = ref([]);
const submitting = ref(false);
const error = ref('');
let timer = null;
let disposed = false;

const providers = computed(() => (status.value?.providers ?? []).filter(p => p.configured));
const provider = computed(() => providers.value.find(p => p.id === form.provider) ?? null);
const kinds = computed(() => Object.keys(KINDS).filter(k => provider.value?.capabilities?.includes(k === 'edit' ? 'edit' : k)));
const images = computed(() => props.assets.filter(a => a.kind === 'image'));
const source = computed(() => images.value.find(a => a.id === form.media_id) ?? null);
const needsSource = computed(() => form.kind !== 'generate');
const needsPrompt = computed(() => ['generate', 'edit'].includes(form.kind));
const canSubmit = computed(() => !submitting.value && provider.value && provider.value.remaining >= form.count
    && (!needsSource.value || source.value) && (!needsPrompt.value || form.prompt.trim().length >= 3));
const maskStyle = computed(() => ({ left: `${form.mask.x}%`, top: `${form.mask.y}%`, width: `${form.mask.width}%`, height: `${form.mask.height}%` }));
const active = computed(() => jobs.value.some(j => ['queued', 'processing'].includes(j.status)));

async function load() {
    try {
        status.value = await studioRequest('GET', route('studio.ai.status', props.brandId));
        const first = providers.value[0];
        if (first && !form.provider) form.provider = first.id;
    } catch (e) { loadError.value = e.message; }
}
watch(kinds, list => { if (list.length && !list.includes(form.kind)) form.kind = list[0]; });
watch(() => props.presetToken, () => {
    if (!props.presetMediaId) return;
    form.media_id = props.presetMediaId;
    if (kinds.value.includes('edit')) form.kind = 'edit'; else if (kinds.value.includes('variation')) form.kind = 'variation';
});

function key() { return (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40).padEnd(8, 'x'); }
async function submit() {
    if (!canSubmit.value) return;
    submitting.value = true; error.value = '';
    const body = { kind: form.kind, provider: form.provider, count: form.count, idempotency_key: key() };
    if (needsPrompt.value || form.prompt.trim()) body.prompt = form.prompt.trim();
    if (form.kind === 'generate' || form.kind === 'outpaint') body.aspect = form.aspect;
    if (form.kind === 'generate') body.style = form.style;
    if (needsSource.value) body.media_id = form.media_id;
    if (form.kind === 'edit') body.mask = { x: form.mask.x / 100, y: form.mask.y / 100, width: form.mask.width / 100, height: form.mask.height / 100 };
    try {
        const result = await studioRequest('POST', route('studio.ai-jobs.store', props.brandId), body);
        track(result.job);
        schedule();
        load();
    } catch (e) { error.value = e.message; } finally { submitting.value = false; }
}
function track(job) {
    const index = jobs.value.findIndex(j => j.id === job.id);
    if (index >= 0) jobs.value.splice(index, 1, job); else jobs.value.unshift(job);
    if (job.status === 'ready') job.output_assets.forEach(asset => emit('ready', asset));
}
function schedule() { clearTimeout(timer); if (!disposed && active.value) timer = setTimeout(poll, 1500); }
async function poll() {
    const pending = jobs.value.filter(j => ['queued', 'processing'].includes(j.status));
    for (const job of pending) {
        try {
            const result = await studioRequest('GET', route('studio.ai-jobs.show', { brand: props.brandId, job: job.id }));
            if (disposed) return;
            track(result.job);
            if (['ready', 'failed'].includes(result.job.status)) load();
        } catch (e) { error.value = e.message; }
    }
    schedule();
}

// Textos con Claude
const text = reactive({ brief: '', busy: false, error: '', result: null, list: '', fill: null, fillBusy: false, fillError: '', created: {}, altId: null, alt: '', altBusy: false, altError: '' });
async function textRequest(body) { return (await studioRequest('POST', route('studio.ai-text', props.brandId), body)).result; }
async function generateCopy() {
    if (text.busy || !text.brief.trim()) return;
    text.busy = true; text.error = ''; text.result = null;
    try { text.result = await textRequest({ action: 'copy', brief: text.brief.trim(), count: 3 }); load(); } catch (e) { text.error = e.message; } finally { text.busy = false; }
}
async function fillTemplate() {
    const items = text.list.split('\n').map(s => s.trim()).filter(Boolean);
    if (text.fillBusy || !items.length) return;
    text.fillBusy = true; text.fillError = ''; text.fill = null; text.created = {};
    try { text.fill = await textRequest({ action: 'fill_template', design_id: props.designId, items }); load(); } catch (e) { text.fillError = e.message; } finally { text.fillBusy = false; }
}
async function createFromRow(index) {
    const row = text.fill.rows[index];
    text.fillError = '';
    try {
        const result = await studioRequest('POST', route('studio.designs.instantiate', props.designId), { revision: text.fill.revision, name: row.item.slice(0, 120), fields: row.fields });
        text.created = { ...text.created, [index]: result.design.id };
    } catch (e) { text.fillError = e.message; }
}
async function suggestAlt() {
    if (text.altBusy || !text.altId) return;
    text.altBusy = true; text.altError = ''; text.alt = '';
    try { text.alt = (await textRequest({ action: 'alt_text', media_id: text.altId })).alt; load(); } catch (e) { text.altError = e.message; } finally { text.altBusy = false; }
}
async function copyAlt() { try { await navigator.clipboard.writeText(text.alt); } catch { /* el texto sigue visible para copiarlo a mano */ } }

// La configuracion solo se pide al abrir la pestaña por primera vez (no en cada apertura del editor).
let loaded = false;
watch(() => props.active, open => { if (open && !loaded) { loaded = true; load(); } }, { immediate: true });
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <section class="space-y-4" aria-label="IA creativa">
        <p v-if="!status && !loadError" class="text-xs text-slate-500" role="status">Cargando la configuración de IA…</p>
        <p v-if="loadError" role="alert" class="rounded-lg bg-red-50 p-3 text-xs text-red-800">{{ loadError }}</p>

        <template v-if="status">
            <div v-if="!status.configured" data-testid="ai-unconfigured" class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 text-xs leading-relaxed text-slate-600">
                <h3 class="mb-1 text-xs font-semibold text-slate-800">Imágenes con IA: no configurada</h3>
                Para generar o editar imágenes, el administrador debe configurar la clave de OpenAI o de Google en el servidor. Mientras tanto no hay botones de generación.
            </div>

            <form v-else class="space-y-3" aria-label="Generar o editar imagen con IA" @submit.prevent="submit">
                <h3 class="text-xs font-semibold text-slate-800">Imágenes con IA</h3>
                <div>
                    <label class="mb-1 block text-xs font-medium" for="ai-provider">Proveedor</label>
                    <select id="ai-provider" v-model="form.provider" class="field text-xs"><option v-for="p in providers" :key="p.id" :value="p.id">{{ p.label }} · {{ p.model }}</option></select>
                    <p v-if="provider" class="mt-1 text-[10px] text-slate-500">Cuota de hoy: quedan {{ provider.remaining }} de {{ provider.daily_limit }} imágenes.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium" for="ai-kind">Qué quieres hacer</label>
                    <select id="ai-kind" v-model="form.kind" class="field text-xs"><option v-for="k in kinds" :key="k" :value="k">{{ KINDS[k] }}</option></select>
                    <p v-if="provider && !provider.capabilities.includes('edit')" class="mt-1 text-[10px] text-slate-500">{{ provider.label }} no admite máscara: para rellenar una zona elige otro proveedor.</p>
                </div>
                <div v-if="needsSource">
                    <label class="mb-1 block text-xs font-medium" for="ai-source">Imagen de origen</label>
                    <select id="ai-source" v-model.number="form.media_id" class="field text-xs"><option :value="null" disabled>Elige una imagen de los recursos</option><option v-for="a in images" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                </div>
                <div v-if="form.kind === 'edit' && source" class="space-y-2">
                    <div class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-100" data-testid="ai-mask-preview"><img :src="source.preview_url" :alt="`Zona a rellenar sobre ${source.name}`" class="block w-full" /><span class="pointer-events-none absolute border-2 border-violet-600 bg-violet-500/30" :style="maskStyle" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[10px] font-medium">Izquierda (%)<input v-model.number="form.mask.x" type="number" min="0" max="98" class="field mt-1 text-xs" aria-label="Zona: izquierda en porcentaje" /></label>
                        <label class="text-[10px] font-medium">Arriba (%)<input v-model.number="form.mask.y" type="number" min="0" max="98" class="field mt-1 text-xs" aria-label="Zona: arriba en porcentaje" /></label>
                        <label class="text-[10px] font-medium">Ancho (%)<input v-model.number="form.mask.width" type="number" min="2" max="100" class="field mt-1 text-xs" aria-label="Zona: ancho en porcentaje" /></label>
                        <label class="text-[10px] font-medium">Alto (%)<input v-model.number="form.mask.height" type="number" min="2" max="100" class="field mt-1 text-xs" aria-label="Zona: alto en porcentaje" /></label>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium" for="ai-prompt">{{ form.kind === 'variation' ? 'Indicaciones (opcional)' : 'Descripción' }}</label>
                    <textarea id="ai-prompt" v-model="form.prompt" rows="3" maxlength="1000" class="field text-xs" :placeholder="form.kind === 'edit' ? 'Qué debe aparecer en la zona marcada' : 'Describe la imagen'" />
                </div>
                <div v-if="form.kind === 'generate' || form.kind === 'outpaint'" class="grid grid-cols-2 gap-2">
                    <div><label class="mb-1 block text-xs font-medium" for="ai-aspect">{{ form.kind === 'outpaint' ? 'Ampliar a' : 'Proporción' }}</label><select id="ai-aspect" v-model="form.aspect" class="field text-xs"><option v-for="a in status.aspects" :key="a" :value="a">{{ a }}</option></select></div>
                    <div v-if="form.kind === 'generate'"><label class="mb-1 block text-xs font-medium" for="ai-style">Estilo</label><select id="ai-style" v-model="form.style" class="field text-xs"><option v-for="s in status.styles" :key="s" :value="s">{{ STYLES[s] ?? s }}</option></select></div>
                </div>
                <div><label class="mb-1 block text-xs font-medium" for="ai-count">Variantes</label><input id="ai-count" v-model.number="form.count" type="number" min="1" :max="status.max_variants" class="field w-20 text-xs" /></div>
                <button type="submit" class="w-full rounded-xl bg-violet-700 px-3 py-2.5 text-xs font-semibold text-white hover:bg-violet-800 disabled:opacity-50" :disabled="!canSubmit">{{ KINDS[form.kind] }}</button>
                <p class="text-[10px] leading-relaxed text-slate-500">La descripción y, al editar, la imagen elegida se envían al proveedor seleccionado. El resultado se guarda en los recursos de la marca y el original no cambia.</p>
                <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-xs text-red-800">{{ error }}</p>
            </form>

            <ul v-if="jobs.length" class="space-y-2" aria-label="Trabajos de IA">
                <li v-for="job in jobs" :key="job.id" class="rounded-xl border border-slate-200 p-3 text-xs" :data-status="job.status">
                    <div class="flex items-start justify-between gap-2"><span class="min-w-0 break-words font-medium">{{ KINDS[job.kind] }}: {{ job.prompt || 'sin descripción' }}</span><span role="status" class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="{ 'bg-slate-100 text-slate-700': job.status === 'queued', 'bg-amber-100 text-amber-800': job.status === 'processing', 'bg-emerald-100 text-emerald-800': job.status === 'ready', 'bg-red-100 text-red-800': job.status === 'failed' }">{{ STATES[job.status] }}</span></div>
                    <p v-if="job.attempts > 1 && job.status === 'processing'" class="mt-1 text-[10px] text-slate-500">Reintentando (intento {{ job.attempts }})…</p>
                    <p v-if="job.status === 'failed'" role="alert" class="mt-2 rounded-lg bg-red-50 p-2 text-red-800">{{ job.error }}</p>
                    <div v-if="job.status === 'ready'" class="mt-2 grid grid-cols-2 gap-2">
                        <figure v-for="(asset, i) in job.output_assets" :key="asset.id" class="min-w-0"><img :src="asset.preview_url" :alt="`Resultado ${i + 1}: ${asset.name}`" class="aspect-square w-full rounded-lg border border-slate-200 object-cover" /><button type="button" class="mt-1 w-full rounded-lg bg-violet-700 px-2 py-1.5 text-[10px] font-semibold text-white hover:bg-violet-800" :aria-label="`Insertar resultado ${i + 1} en el diseño`" @click="emit('add', asset)">Insertar en el diseño</button></figure>
                    </div>
                </li>
            </ul>

            <div class="space-y-3 border-t border-slate-100 pt-4" aria-label="Textos con IA">
                <h3 class="text-xs font-semibold text-slate-800">Textos con IA</h3>
                <div v-if="!status.text.enabled" data-testid="ai-text-unconfigured" class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 text-xs leading-relaxed text-slate-600">Textos con IA: no configurado. Requiere activar el asistente con la clave de Claude en el servidor.</div>
                <template v-else>
                    <p class="text-[10px] text-slate-500">Quedan {{ status.text.remaining }} peticiones hoy.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium" for="ai-brief">Brief para titulares y copies</label>
                        <textarea id="ai-brief" v-model="text.brief" rows="3" maxlength="500" class="field text-xs" placeholder="De qué trata el diseño" />
                        <button type="button" class="mt-2 w-full rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 disabled:opacity-50" :disabled="text.busy || !text.brief.trim()" @click="generateCopy">{{ text.busy ? 'Generando…' : 'Generar textos' }}</button>
                        <p v-if="text.error" role="alert" class="mt-2 rounded-lg bg-red-50 p-2 text-xs text-red-800">{{ text.error }}</p>
                        <ul v-if="text.result" class="mt-2 space-y-1" aria-label="Textos propuestos">
                            <li v-for="(line, i) in [...text.result.headlines, ...text.result.copies]" :key="i" class="flex items-start gap-2 rounded-lg border border-slate-200 p-2 text-xs"><span class="min-w-0 flex-1 break-words">{{ line }}</span><button type="button" class="shrink-0 rounded-lg border border-violet-200 px-2 py-1 text-[10px] font-semibold text-violet-800" :aria-label="`Insertar texto: ${line}`" @click="emit('text', line)">Insertar</button></li>
                        </ul>
                    </div>
                    <div v-if="isTemplate" class="space-y-2 border-t border-slate-100 pt-3">
                        <label class="block text-xs font-medium" for="ai-list">Lista para rellenar la plantilla (uno por línea)</label>
                        <textarea id="ai-list" v-model="text.list" rows="4" class="field text-xs" placeholder="Café, 2 euros&#10;Té, 3 euros" />
                        <button type="button" class="w-full rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 disabled:opacity-50" :disabled="text.fillBusy || !text.list.trim()" @click="fillTemplate">{{ text.fillBusy ? 'Rellenando…' : 'Rellenar plantilla' }}</button>
                        <p v-if="text.fillError" role="alert" class="rounded-lg bg-red-50 p-2 text-xs text-red-800">{{ text.fillError }}</p>
                        <ul v-if="text.fill" class="space-y-1" aria-label="Filas propuestas">
                            <li v-for="(row, i) in text.fill.rows" :key="i" class="rounded-lg border border-slate-200 p-2 text-xs"><p class="font-medium">{{ row.item }}</p><p class="break-words text-slate-600">{{ Object.entries(row.fields).map(([k, v]) => `${k}: ${v}`).join(' · ') }}</p><a v-if="text.created[i]" :href="route('studio.designs.edit', text.created[i])" class="mt-1 inline-block text-[10px] font-semibold text-violet-700 underline">Abrir diseño creado</a><button v-else type="button" class="mt-1 rounded-lg border border-violet-200 px-2 py-1 text-[10px] font-semibold text-violet-800" @click="createFromRow(i)">Crear diseño</button></li>
                        </ul>
                    </div>
                    <div v-if="status.text.vision && images.length" class="space-y-2 border-t border-slate-100 pt-3">
                        <label class="block text-xs font-medium" for="ai-alt-source">Texto alternativo de una imagen</label>
                        <select id="ai-alt-source" v-model.number="text.altId" class="field text-xs"><option :value="null" disabled>Elige una imagen</option><option v-for="a in images" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                        <button type="button" class="w-full rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 disabled:opacity-50" :disabled="text.altBusy || !text.altId" @click="suggestAlt">{{ text.altBusy ? 'Analizando…' : 'Sugerir texto alternativo' }}</button>
                        <p class="text-[10px] text-slate-500">La imagen elegida, reducida, se envía a Claude.</p>
                        <p v-if="text.altError" role="alert" class="rounded-lg bg-red-50 p-2 text-xs text-red-800">{{ text.altError }}</p>
                        <p v-if="text.alt" class="rounded-lg border border-slate-200 p-2 text-xs" data-testid="ai-alt-result">{{ text.alt }} <button type="button" class="ml-1 text-[10px] font-semibold text-violet-700 underline" @click="copyAlt">Copiar</button></p>
                    </div>
                </template>
            </div>
        </template>
    </section>
</template>
