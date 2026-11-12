<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PdfAsset from '@/Components/PdfAsset.vue';
import PermissionNotice from '@/Components/PermissionNotice.vue';
import { studioRequest } from '@/Studio/api';

const props = defineProps({ brand: Object, canva: Object, focus: { type: String, default: null }, flash: Object });
const page = usePage();
const flash = computed(() => props.flash ?? page.props.flash ?? {});

const STATUS = { queued: 'En cola', processing: 'Canva está trabajando…', importing: 'Guardando en la biblioteca…', ready: 'Listo', failed: 'Con error' };
const JOB_KIND = { export: 'Exportar', upload: 'Enviar a Canva', autofill: 'Rellenar plantilla' };
const PDF_NOTICE = [{ id: 'pdf', help: 'Los PDF se guardan para verlos y descargarlos; no se pueden adjuntar a una publicación. Para publicar, exporta el diseño como PNG, JPG o MP4.' }];
const EXPORT_FORMATS = [
    { value: 'png', label: 'PNG (imagen)' },
    { value: 'jpg', label: 'JPG (imagen)' },
    { value: 'mp4', label: 'MP4 (vídeo)' },
    { value: 'pdf', label: 'PDF (documento: se guarda en la biblioteca, no se publica en redes)' },
];

const connected = computed(() => props.canva.connected);
const features = computed(() => Object.fromEntries((props.canva.features ?? []).map(f => [f.key, f])));
const designs = ref([]);
const continuation = ref(null);
const query = ref('');
const loading = ref(false);
const loaded = ref(false);
const error = ref('');
const notice = ref('');
const jobs = ref([...(props.canva.jobs ?? [])]);
const documents = ref([...(props.canva.documents ?? [])]);
const designLinks = ref({});
const templates = ref([]);
const templateQuery = ref('');
const templatesLoaded = ref(false);
const templatesLoading = ref(false);
const template = ref(null);
const fields = ref([]);
const fieldValues = ref({});
const autofillTitle = ref('');
const createFormat = ref(props.canva.formats?.[0]?.key ?? 'square');
const createTitle = ref('');
const created = ref(null);
const exportFormat = ref({});
const transparent = ref({});
const library = ref([]);
const sendId = ref('');
const busy = ref(false);
let timer = null;

const formatOf = id => exportFormat.value[id] ?? 'png';
const hasPdf = computed(() => documents.value.length > 0 || jobs.value.some(j => j.media?.some(m => m.kind === 'pdf')));
const textFields = computed(() => fields.value.filter(f => f.supported));
const otherFields = computed(() => fields.value.filter(f => !f.supported));
const active = computed(() => jobs.value.some(j => ['queued', 'processing', 'importing'].includes(j.status)));
const fail = e => { error.value = e?.message || 'No se pudo completar la operación.'; };

async function loadDesigns(more = false) {
    if (loading.value) return;
    loading.value = true; error.value = '';
    try {
        const params = new URLSearchParams();
        if (query.value.trim()) params.set('query', query.value.trim());
        if (more && continuation.value) params.set('continuation', continuation.value);
        const data = await studioRequest('GET', `${route('canva.designs.index', props.brand.id)}${params.size ? `?${params}` : ''}`);
        designs.value = more ? [...designs.value, ...data.items] : data.items;
        continuation.value = data.continuation;
        loaded.value = true;
    } catch (e) { fail(e); } finally { loading.value = false; }
}

async function createDesign() {
    busy.value = true; error.value = ''; created.value = null;
    try {
        const data = await studioRequest('POST', route('canva.designs.store', props.brand.id), { format: createFormat.value, title: createTitle.value.trim() || undefined });
        created.value = data.design;
    } catch (e) { fail(e); } finally { busy.value = false; }
}

function track(job) {
    const i = jobs.value.findIndex(j => j.id === job.id);
    if (i >= 0) jobs.value[i] = job; else jobs.value.unshift(job);
    if (job.status === 'ready') { linkDesign(job); if (job.media?.some(m => m.kind === 'pdf')) loadDocuments(); }
    schedule();
}

/** Enlace fresco «Abrir en Canva» del diseño que ha creado Autofill (los de Canva son temporales). */
async function linkDesign(job) {
    if (job.kind !== 'autofill' || !job.design_id || designLinks.value[job.id]) return;
    try { designLinks.value[job.id] = (await studioRequest('GET', route('canva.designs.show', [props.brand.id, job.design_id]))).design.open_url; } catch { /* sin enlace: el diseño sigue en Canva */ }
}

async function loadDocuments() {
    try { documents.value = (await studioRequest('GET', route('canva.status', props.brand.id))).documents ?? []; } catch { /* se recarga con la página */ }
}

async function removeDocument(doc) {
    if (!confirm(`¿Eliminar «${doc.name}» de la biblioteca?`)) return;
    error.value = '';
    try { await studioRequest('DELETE', route('media.destroy', doc.id)); documents.value = documents.value.filter(d => d.id !== doc.id); } catch (e) { fail(e); }
}

async function loadTemplates() {
    if (templatesLoading.value) return;
    templatesLoading.value = true; error.value = '';
    try {
        const params = new URLSearchParams();
        if (templateQuery.value.trim()) params.set('query', templateQuery.value.trim());
        templates.value = (await studioRequest('GET', `${route('canva.templates.index', props.brand.id)}${params.size ? `?${params}` : ''}`)).items;
        templatesLoaded.value = true;
    } catch (e) { fail(e); } finally { templatesLoading.value = false; }
}

async function pickTemplate(t) {
    template.value = t; fields.value = []; fieldValues.value = {}; error.value = '';
    try { fields.value = (await studioRequest('GET', route('canva.templates.dataset', [props.brand.id, t.id]))).fields; } catch (e) { fail(e); }
}

async function startAutofill() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const data = await studioRequest('POST', route('canva.autofills.store', props.brand.id), { brand_template_id: template.value.id, fields: fieldValues.value, title: autofillTitle.value.trim() || undefined });
        track(data.job);
        notice.value = `Rellenando «${template.value.title}» en Canva.`;
    } catch (e) { fail(e); } finally { busy.value = false; }
}

async function startExport(design) {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const format = formatOf(design.id);
        const data = await studioRequest('POST', route('canva.exports.store', props.brand.id), {
            design_id: design.id, format, title: design.title, transparent: format === 'png' && !!transparent.value[design.id] || undefined,
        });
        track(data.job);
        notice.value = `Exportación de «${design.title}» en marcha.`;
    } catch (e) { fail(e); } finally { busy.value = false; }
}

async function loadLibrary() {
    try { library.value = (await studioRequest('GET', route('media.index'))).media; } catch { library.value = []; }
}

async function sendToCanva() {
    if (!sendId.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const data = await studioRequest('POST', route('canva.uploads.store', props.brand.id), { media_id: Number(sendId.value) });
        track(data.job);
        notice.value = 'Recurso enviándose a Canva.';
    } catch (e) { fail(e); } finally { busy.value = false; }
}

async function refreshJobs() {
    for (const job of jobs.value.filter(j => ['queued', 'processing', 'importing'].includes(j.status))) {
        try { track((await studioRequest('GET', route('canva.jobs.show', [props.brand.id, job.id]))).job); } catch { /* se reintenta en el siguiente ciclo */ }
    }
}

function schedule() {
    if (timer || !active.value) return;
    timer = setInterval(async () => {
        await refreshJobs();
        if (!active.value) { clearInterval(timer); timer = null; }
    }, 3000);
}

const connect = () => router.post(route('canva.connect', props.brand.id));
const disconnect = () => { if (confirm('¿Desconectar Canva de esta marca? Se borrarán las credenciales guardadas.')) router.delete(route('canva.disconnect', props.brand.id)); };

onMounted(() => {
    if (connected.value) {
        loadDesigns();
        if (features.value.send?.available) loadLibrary();
        if (features.value.brand_templates?.available) loadTemplates();
        jobs.value.forEach(linkDesign);
        schedule();
    }
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<template>
    <Head :title="`Canva · ${brand.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900">Canva · {{ brand.name }}</h1>
                <Link :href="route('brands.connections.index', brand.id)" class="link text-sm">Conexiones de la marca</Link>
            </div>
        </template>

        <div class="max-w-4xl space-y-5">
            <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ flash.success }}</p>
            <p v-if="flash.error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ flash.error }}</p>

            <section v-if="!canva.configured" class="card space-y-2 p-6" data-testid="canva-unconfigured">
                <h2 class="font-semibold text-slate-900">Canva: no configurada</h2>
                <p class="text-sm text-slate-700">Esta instalación no tiene las credenciales de la app de Canva, así que la integración no está disponible. Quien administre el servidor puede activarla registrando la app en Canva Developers y definiendo <code>CANVA_CLIENT_ID</code> y <code>CANVA_CLIENT_SECRET</code>.</p>
            </section>

            <section v-else-if="!connected" class="card space-y-3 p-6" data-testid="canva-disconnected">
                <h2 class="font-semibold text-slate-900">{{ canva.status === 'expired' ? 'La conexión con Canva ha caducado' : 'Conecta tu cuenta de Canva' }}</h2>
                <p class="text-sm text-slate-700">Autoriza a esta marca a ver tus diseños de Canva, exportarlos a la biblioteca y enviarle recursos. Nunca compra nada ni publica por ti: todo lo que importas queda en la biblioteca para que decidas qué hacer.</p>
                <PrimaryButton type="button" @click="connect">{{ canva.status === 'expired' ? 'Reconectar con Canva' : 'Conectar con Canva' }}</PrimaryButton>
            </section>

            <template v-else>
                <section class="card flex flex-wrap items-center justify-between gap-3 p-6" data-testid="canva-connected">
                    <div>
                        <h2 class="font-semibold text-slate-900">Canva conectado</h2>
                        <p class="text-sm text-slate-700">Cuenta: {{ canva.name ?? 'sin nombre visible' }}</p>
                    </div>
                    <SecondaryButton type="button" @click="disconnect">Desconectar</SecondaryButton>
                </section>

                <p v-if="focus" class="rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900" role="status" data-testid="canva-returned">Has vuelto de Canva. Tu diseño aparece resaltado más abajo: ya puedes exportarlo a la biblioteca.</p>
                <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert" data-testid="canva-error">{{ error }}</p>
                <p v-if="notice" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-testid="canva-notice">{{ notice }}</p>

                <section class="card space-y-2 p-6" aria-labelledby="canva-plan">
                    <h2 id="canva-plan" class="font-semibold text-slate-900">Qué permite tu plan de Canva</h2>
                    <p v-if="!canva.capabilities_known" class="text-xs text-amber-700">No se han podido consultar las capacidades de tu plan; las funciones que las necesitan aparecen desactivadas.</p>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="f in canva.features" :key="f.key" class="flex flex-wrap items-baseline justify-between gap-2 py-2" :data-testid="`canva-feature-${f.key}`" :data-available="f.available">
                            <span :class="f.available ? 'text-slate-900' : 'text-slate-500'">{{ f.label }}</span>
                            <span v-if="f.available" class="text-xs font-medium text-emerald-700">Disponible</span>
                            <span v-else class="max-w-md text-xs text-slate-600">No disponible: {{ f.reason }}</span>
                        </li>
                    </ul>
                </section>

                <section class="card space-y-3 p-6" aria-labelledby="canva-create">
                    <h2 id="canva-create" class="font-semibold text-slate-900">Crear en Canva</h2>
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="createDesign">
                        <label class="text-sm font-medium text-slate-900">Formato
                            <select v-model="createFormat" data-testid="canva-create-format" class="field mt-1 block" :disabled="!features.create?.available">
                                <option v-for="f in canva.formats" :key="f.key" :value="f.key">{{ f.label }} · {{ f.width }}×{{ f.height }}</option>
                            </select>
                        </label>
                        <label class="min-w-0 flex-1 basis-48 text-sm font-medium text-slate-900">Título (opcional)
                            <input v-model="createTitle" class="field mt-1 w-full" maxlength="255" :disabled="!features.create?.available">
                        </label>
                        <PrimaryButton type="submit" :disabled="busy || !features.create?.available">Crear en Canva</PrimaryButton>
                    </form>
                    <p v-if="!features.create?.available" class="text-xs text-slate-600">{{ features.create?.reason }}</p>
                    <p v-if="created" class="text-sm text-slate-800" data-testid="canva-created">
                        Diseño creado: «{{ created.title }}».
                        <a v-if="created.open_url" :href="created.open_url" target="_blank" rel="noopener noreferrer" class="link font-medium">Abrir en Canva (pestaña nueva)</a>
                    </p>
                </section>

                <section class="card space-y-3 p-6" aria-labelledby="canva-designs">
                    <h2 id="canva-designs" class="font-semibold text-slate-900">Tus diseños de Canva</h2>
                    <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="loadDesigns()">
                        <label class="min-w-0 flex-1 basis-56 text-sm font-medium text-slate-900">Buscar
                            <input v-model="query" type="search" class="field mt-1 w-full" maxlength="255" placeholder="Nombre del diseño">
                        </label>
                        <SecondaryButton type="submit" :disabled="loading">Buscar</SecondaryButton>
                    </form>
                    <p v-if="loading" class="text-sm text-slate-600" role="status">Cargando diseños…</p>
                    <p v-else-if="loaded && !designs.length" class="text-sm text-slate-600" data-testid="canva-no-designs">No hay diseños que coincidan.</p>
                    <ul class="space-y-3">
                        <li v-for="d in designs" :key="d.id" class="rounded-lg border p-3 text-sm" :class="d.id === focus ? 'border-violet-500 bg-violet-50' : 'border-slate-200'" :data-testid="`canva-design-${d.id}`">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-slate-900">{{ d.title }}</p>
                                    <a v-if="d.open_url" :href="d.open_url" target="_blank" rel="noopener noreferrer" class="link text-xs">Abrir en Canva (pestaña nueva)</a>
                                </div>
                                <form class="flex flex-wrap items-end gap-2" @submit.prevent="startExport(d)">
                                    <label class="text-xs font-medium text-slate-700">Formato de {{ d.title }}
                                        <select class="field mt-1 block" :value="formatOf(d.id)" :disabled="!features.export?.available" @change="exportFormat[d.id] = $event.target.value">
                                            <option v-for="f in EXPORT_FORMATS" :key="f.value" :value="f.value">{{ f.label }}</option>
                                        </select>
                                    </label>
                                    <label v-if="formatOf(d.id) === 'png'" class="flex items-center gap-1 text-xs text-slate-700">
                                        <input v-model="transparent[d.id]" type="checkbox" :disabled="!features.transparent_png?.available" :aria-label="`Fondo transparente de ${d.title}`">
                                        Fondo transparente
                                        <span v-if="!features.transparent_png?.available" class="text-slate-500">(no disponible en tu plan)</span>
                                    </label>
                                    <SecondaryButton type="submit" :disabled="busy || !features.export?.available">Exportar a la biblioteca</SecondaryButton>
                                </form>
                            </div>
                        </li>
                    </ul>
                    <SecondaryButton v-if="continuation" type="button" :disabled="loading" @click="loadDesigns(true)">Cargar más</SecondaryButton>
                </section>

                <section v-if="features.brand_templates?.available" class="card space-y-3 p-6" aria-labelledby="canva-templates" data-testid="canva-templates">
                    <h2 id="canva-templates" class="font-semibold text-slate-900">Plantillas de marca (Autofill)</h2>
                    <p class="text-xs text-slate-600">Elige una plantilla de tu marca en Canva y rellena sus campos de texto: Canva crea un diseño nuevo que puedes abrir y exportar.</p>
                    <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="loadTemplates">
                        <label class="min-w-0 flex-1 basis-56 text-sm font-medium text-slate-900">Buscar plantilla
                            <input v-model="templateQuery" type="search" class="field mt-1 w-full" maxlength="255" placeholder="Nombre de la plantilla">
                        </label>
                        <SecondaryButton type="submit" :disabled="templatesLoading">Buscar plantillas</SecondaryButton>
                    </form>
                    <p v-if="templatesLoaded && !templates.length" class="text-sm text-slate-600" data-testid="canva-no-templates">No hay plantillas de marca que coincidan.</p>
                    <ul class="space-y-2">
                        <li v-for="t in templates" :key="t.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm" :class="template?.id === t.id ? 'border-violet-500 bg-violet-50' : 'border-slate-200'" :data-testid="`canva-template-${t.id}`">
                            <span class="min-w-0 break-words font-medium text-slate-900">{{ t.title }}</span>
                            <SecondaryButton type="button" :disabled="!features.autofill?.available" :aria-label="`Rellenar ${t.title}`" @click="pickTemplate(t)">Rellenar</SecondaryButton>
                        </li>
                    </ul>
                    <p v-if="!features.autofill?.available" class="text-xs text-slate-600" data-testid="canva-autofill-unavailable">Autofill no disponible: {{ features.autofill?.reason }}</p>
                    <form v-if="template && features.autofill?.available" class="space-y-3 border-t border-slate-100 pt-3" data-testid="canva-autofill-form" @submit.prevent="startAutofill">
                        <h3 class="text-sm font-semibold text-slate-900">Campos de «{{ template.title }}»</h3>
                        <p v-if="!textFields.length" class="text-xs text-slate-600">Esta plantilla no tiene campos de texto que rellenar desde aquí.</p>
                        <label v-for="f in textFields" :key="f.name" class="block text-sm font-medium text-slate-900">{{ f.name }}
                            <input v-model="fieldValues[f.name]" class="field mt-1 w-full" maxlength="2000">
                        </label>
                        <p v-if="otherFields.length" class="text-xs text-slate-600" data-testid="canva-autofill-skipped">Se conserva el valor de la plantilla en los campos que no son de texto: {{ otherFields.map(f => `${f.name} (${f.type})`).join(', ') }}.</p>
                        <label class="block text-sm font-medium text-slate-900">Título del diseño (opcional)
                            <input v-model="autofillTitle" class="field mt-1 w-full" maxlength="255">
                        </label>
                        <PrimaryButton type="submit" :disabled="busy || !textFields.length">Crear diseño en Canva</PrimaryButton>
                    </form>
                </section>

                <section class="card space-y-3 p-6" aria-labelledby="canva-send">
                    <h2 id="canva-send" class="font-semibold text-slate-900">Enviar a Canva</h2>
                    <p v-if="!features.send?.available" class="text-xs text-slate-600">{{ features.send?.reason }}</p>
                    <form v-else class="flex flex-wrap items-end gap-2" @submit.prevent="sendToCanva">
                        <label class="min-w-0 flex-1 basis-56 text-sm font-medium text-slate-900">Recurso de la biblioteca
                            <select v-model="sendId" class="field mt-1 w-full">
                                <option value="">Elige un recurso…</option>
                                <option v-for="m in library" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </label>
                        <SecondaryButton type="submit" :disabled="busy || !sendId">Enviar a Canva</SecondaryButton>
                    </form>
                </section>

                <section class="card space-y-3 p-6" aria-labelledby="canva-jobs">
                    <h2 id="canva-jobs" class="font-semibold text-slate-900">Trabajos recientes</h2>
                    <p v-if="!jobs.length" class="text-sm text-slate-600">Todavía no has exportado ni enviado nada.</p>
                    <ul class="space-y-2">
                        <li v-for="j in jobs" :key="j.id" class="rounded-lg border border-slate-200 p-3 text-sm" :data-testid="`canva-job-${j.id}`" :data-status="j.status">
                            <p class="font-medium text-slate-900">{{ JOB_KIND[j.kind] ?? j.kind }}: {{ j.title || j.design_id }} <span v-if="j.format" class="uppercase text-slate-500">{{ j.format }}</span></p>
                            <p role="status" :class="j.status === 'failed' ? 'text-red-700' : 'text-slate-700'">{{ STATUS[j.status] ?? j.status }}<template v-if="j.status === 'failed' && j.error">: {{ j.error }}</template></p>
                            <ul v-if="j.media?.length" class="mt-1 space-y-1">
                                <template v-for="m in j.media" :key="m.id">
                                    <li v-if="m.kind === 'pdf'" data-testid="canva-imported"><PdfAsset :asset="m" /></li>
                                    <li v-else data-testid="canva-imported">
                                        {{ m.name }} <span class="text-slate-500">({{ m.width }}×{{ m.height }})</span>
                                        <a v-if="m.origin?.url" :href="m.origin.url" target="_blank" rel="noopener noreferrer" class="link ml-2 text-xs" data-testid="canva-origin">Origen: Canva</a>
                                    </li>
                                </template>
                            </ul>
                            <p v-if="j.kind === 'autofill' && j.status === 'ready' && designLinks[j.id]" class="mt-1">
                                <a :href="designLinks[j.id]" target="_blank" rel="noopener noreferrer" class="link text-xs" data-testid="canva-autofill-open">Abrir el diseño en Canva (pestaña nueva)</a>
                            </p>
                            <p v-if="j.canva_asset_id && j.status === 'ready'" class="mt-1 text-xs text-slate-600">Ya está en tu biblioteca de Canva.</p>
                        </li>
                    </ul>
                </section>

                <section v-if="hasPdf" class="card space-y-3 p-6" aria-labelledby="canva-documents" data-testid="canva-documents">
                    <h2 id="canva-documents" class="font-semibold text-slate-900">Documentos PDF</h2>
                    <PermissionNotice title="Los PDF no se publican en redes" :items="PDF_NOTICE" />
                    <ul class="space-y-3">
                        <li v-for="d in documents" :key="d.id" class="rounded-lg border border-slate-200 p-3 text-sm" :data-testid="`canva-document-${d.id}`"><PdfAsset :asset="d" removable @remove="removeDocument" /></li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
