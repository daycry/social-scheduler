<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Informes (T-40 + T-21): constructor con bloques elegibles y ordenables, plantillas por marca, vista previa, generacion de
 * PDF/CSV en segundo plano (descarga con enlace firmado de unos minutos) y envios programados por email. Mientras haya
 * informes en curso la lista se refresca sola.
 */
const props = defineProps({
    brands: Array,
    reports: Array,
    defaults: Object,
    polling: Boolean,
    flash: Object,
    templates: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    blockTypes: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ blocks: 20, text: 2000, title: 80, recipients: 5 }) },
});

const BLOCK_LABELS = {
    kpis: 'Indicadores por red',
    evolution: 'Evolución',
    top_posts: 'Publicaciones destacadas',
    competitors: 'Competidores',
    ads: 'Anuncios (Meta Ads)',
    text: 'Nota de texto',
};
const label = (t) => BLOCK_LABELS[t] ?? t;

let uid = 0;
const makeBlock = (type, title = '', text = '') => ({ key: ++uid, type, title: title ?? '', text: text ?? '' });
const defaultBlocks = () => ['kpis', 'evolution', 'top_posts'].map((t) => makeBlock(t));

const form = useForm({
    brand_id: props.defaults.brand_id ?? '',
    from: props.defaults.from,
    to: props.defaults.to,
    compare: props.defaults.compare,
    formats: ['pdf', 'csv'],
    blocks: [],
});
const blocks = ref(defaultBlocks());
const toPayload = () => blocks.value.map((b) => ({ type: b.type, title: b.title || null, text: b.type === 'text' ? b.text : null }));

const submit = () => {
    form.transform((d) => ({ ...d, blocks: toPayload() })).post(route('reports.store'), { preserveScroll: true });
};

// --- Bloques
const newType = ref('text');
const addBlock = () => {
    if (blocks.value.length < props.limits.blocks) blocks.value.push(makeBlock(newType.value));
};
const removeBlock = (i) => blocks.value.splice(i, 1);
const moveBlock = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= blocks.value.length) return;
    const [b] = blocks.value.splice(i, 1);
    blocks.value.splice(j, 0, b);
};

// --- Plantillas de la marca elegida
const brandTemplates = computed(() => props.templates.filter((t) => t.brand_id === Number(form.brand_id)));
const brandSchedules = computed(() => props.schedules.filter((s) => s.brand_id === Number(form.brand_id)));
const templateId = ref('');
const templateName = ref('');
const rangeDays = computed(() => Math.max(1, Math.round((new Date(form.to) - new Date(form.from)) / 86400000) + 1) || 30);
const tplForm = useForm({ brand_id: '', name: '', blocks: [], range_days: 30, compare: 'none' });

const loadTemplate = () => {
    const t = brandTemplates.value.find((x) => x.id === Number(templateId.value));
    if (!t) return;
    blocks.value = t.blocks.map((b) => makeBlock(b.type, b.title, b.text));
    templateName.value = t.name;
    form.compare = t.compare;
    const to = new Date(form.to);
    to.setDate(to.getDate() - (t.range_days - 1));
    form.from = to.toISOString().slice(0, 10);
    preview.value = null;
};
const saveTemplate = () => {
    const existing = brandTemplates.value.find((x) => x.name === templateName.value.trim());
    tplForm
        .transform(() => ({ brand_id: existing ? undefined : form.brand_id, name: templateName.value.trim(), blocks: toPayload(), range_days: rangeDays.value, compare: form.compare }))
        [existing ? 'put' : 'post'](existing ? route('report-templates.update', existing.id) : route('report-templates.store'), { preserveScroll: true });
};
const deleteTemplate = () => {
    const t = brandTemplates.value.find((x) => x.id === Number(templateId.value));
    if (t && confirm(`Eliminar la plantilla "${t.name}"? También se borran sus envíos programados.`)) {
        router.delete(route('report-templates.destroy', t.id), { preserveScroll: true, onSuccess: () => (templateId.value = '') });
    }
};

// --- Vista previa
const preview = ref(null);
const previewError = ref('');
const previewing = ref(false);
const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
const runPreview = async () => {
    previewing.value = true;
    previewError.value = '';
    try {
        const res = await fetch(route('reports.preview'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ brand_id: form.brand_id, from: form.from, to: form.to, compare: form.compare, blocks: toPayload() }),
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? 'No se pudo generar la vista previa.');
        preview.value = json;
    } catch (e) {
        preview.value = null;
        previewError.value = e.message;
    } finally {
        previewing.value = false;
    }
};
// Cambiar de marca nunca arrastra la vista previa, la plantilla ni los envios de la anterior.
watch(
    () => form.brand_id,
    () => {
        preview.value = null;
        templateId.value = '';
        templateName.value = '';
        scheduleTemplate.value = '';
    },
);
const fmt = (v) => (v === null || v === undefined ? 'No disponible' : new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1 }).format(v));

// --- Envios programados
const scheduleTemplate = ref('');
const scheduleForm = useForm({ brand_id: '', report_template_id: '', frequency: 'weekly', recipients: [] });
const recipientsText = ref('');
const createSchedule = () => {
    scheduleForm
        .transform(() => ({
            brand_id: form.brand_id,
            report_template_id: scheduleTemplate.value,
            frequency: scheduleForm.frequency,
            recipients: recipientsText.value.split(/[\s,;]+/).filter(Boolean),
        }))
        .post(route('report-schedules.store'), { preserveScroll: true, onSuccess: () => (recipientsText.value = '') });
};
const toggleSchedule = (s) => router.put(route('report-schedules.update', s.id), { active: !s.active }, { preserveScroll: true });
const removeSchedule = (s) => {
    if (confirm(`Eliminar el envío ${s.frequency === 'weekly' ? 'semanal' : 'mensual'} de ${s.brand}?`)) router.delete(route('report-schedules.destroy', s.id), { preserveScroll: true });
};
const when = (iso) => (iso ? new Date(iso).toLocaleString('es-ES', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const STATUS = {
    pending: ['En cola', 'bg-slate-100 text-slate-700'],
    processing: ['Generando...', 'bg-blue-100 text-blue-700'],
    ready: ['Listo', 'bg-emerald-100 text-emerald-800'],
    failed: ['Error', 'bg-red-100 text-red-700'],
};

let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (props.polling) router.reload({ only: ['reports', 'polling'], preserveScroll: true });
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));

const remove = (r) => {
    if (confirm(`Eliminar el informe de ${r.brand} (${r.format.toUpperCase()}, ${r.from} - ${r.to})? También se borra su fichero.`)) {
        router.delete(route('reports.destroy', r.id), { preserveScroll: true });
    }
};

const size = (b) => (b ? (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`) : '');
</script>

<template>
    <Head title="Informes" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Informes</h1>
        </template>

        <div>
            <div class="space-y-5">
                <div v-if="brands.length === 0" class="rounded-lg bg-white p-6 text-slate-700 shadow-sm">
                    Aún no tienes marcas.
                    <Link :href="route('brands.create')" class="link">Crea una marca</Link> para generar informes.
                </div>

                <template v-else>
                    <form class="space-y-4 card p-5" @submit.prevent="submit">
                        <div class="flex flex-wrap items-end gap-4">
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Marca</span>
                                <select v-model="form.brand_id" aria-label="Marca" class="field w-auto py-1.5">
                                    <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Desde</span>
                                <input v-model="form.from" type="date" class="field w-auto py-1.5" />
                            </label>
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Hasta</span>
                                <input v-model="form.to" type="date" class="field w-auto py-1.5" />
                            </label>
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Comparar con</span>
                                <select v-model="form.compare" class="field w-auto py-1.5">
                                    <option value="none">Sin comparación</option>
                                    <option value="previous">Periodo anterior</option>
                                    <option value="year">Mismo periodo del año anterior</option>
                                </select>
                            </label>
                            <fieldset class="flex gap-3 text-sm">
                                <legend class="mb-1 block text-xs font-medium text-slate-600">Formato</legend>
                                <label class="flex items-center gap-1"><input v-model="form.formats" type="checkbox" value="pdf" class="rounded border-slate-300" /> PDF</label>
                                <label class="flex items-center gap-1"><input v-model="form.formats" type="checkbox" value="csv" class="rounded border-slate-300" /> CSV</label>
                            </fieldset>
                        </div>

                        <fieldset class="space-y-3 rounded-lg border border-slate-200 p-4" data-builder>
                            <legend class="px-1 text-sm font-medium text-slate-800">Bloques del informe PDF</legend>
                            <ol class="space-y-2">
                                <li v-for="(b, i) in blocks" :key="b.key" class="rounded-md border border-slate-200 bg-slate-50 p-3" data-block :data-block-type="b.type">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-slate-800">{{ i + 1 }}. {{ label(b.type) }}</span>
                                        <span class="flex flex-wrap gap-1">
                                            <button type="button" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 disabled:opacity-50" :disabled="i === 0" :aria-label="`Subir bloque ${i + 1}: ${label(b.type)}`" @click="moveBlock(i, -1)">Subir</button>
                                            <button type="button" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 disabled:opacity-50" :disabled="i === blocks.length - 1" :aria-label="`Bajar bloque ${i + 1}: ${label(b.type)}`" @click="moveBlock(i, 1)">Bajar</button>
                                            <button type="button" class="rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700" :aria-label="`Quitar bloque ${i + 1}: ${label(b.type)}`" @click="removeBlock(i)">Quitar</button>
                                        </span>
                                    </div>
                                    <label class="mt-2 block text-xs font-medium text-slate-600">
                                        Título (opcional)
                                        <input v-model="b.title" type="text" :maxlength="limits.title" class="field mt-1 py-1.5" :aria-label="`Título del bloque ${i + 1}`" />
                                    </label>
                                    <label v-if="b.type === 'text'" class="mt-2 block text-xs font-medium text-slate-600">
                                        Texto de la nota
                                        <textarea v-model="b.text" rows="3" :maxlength="limits.text" class="field mt-1" :aria-label="`Texto del bloque ${i + 1}`" />
                                    </label>
                                </li>
                            </ol>
                            <p v-if="blocks.length === 0" class="text-sm text-slate-600">Añade al menos un bloque.</p>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Añadir bloque</span>
                                    <select v-model="newType" class="field w-auto py-1.5" aria-label="Tipo de bloque">
                                        <option v-for="t in blockTypes" :key="t" :value="t">{{ label(t) }}</option>
                                    </select>
                                </label>
                                <SecondaryButton type="button" :disabled="blocks.length >= limits.blocks" @click="addBlock">Añadir bloque</SecondaryButton>
                                <SecondaryButton type="button" :disabled="previewing || blocks.length === 0" @click="runPreview">Vista previa</SecondaryButton>
                            </div>
                            <InputError :message="form.errors.blocks || previewError" />
                        </fieldset>

                        <fieldset class="space-y-2 rounded-lg border border-slate-200 p-4" data-templates>
                            <legend class="px-1 text-sm font-medium text-slate-800">Plantillas de esta marca</legend>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Plantilla guardada</span>
                                    <select v-model="templateId" class="field w-auto py-1.5" aria-label="Plantilla guardada">
                                        <option value="">— Ninguna —</option>
                                        <option v-for="t in brandTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </label>
                                <SecondaryButton type="button" :disabled="!templateId" @click="loadTemplate">Cargar plantilla</SecondaryButton>
                                <SecondaryButton type="button" :disabled="!templateId" @click="deleteTemplate">Eliminar plantilla</SecondaryButton>
                            </div>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="text-sm">
                                    <span class="block text-xs font-medium text-slate-600">Nombre de la plantilla</span>
                                    <input v-model="templateName" type="text" maxlength="80" class="field py-1.5" aria-label="Nombre de la plantilla" />
                                </label>
                                <SecondaryButton type="button" :disabled="tplForm.processing || !templateName.trim() || blocks.length === 0" @click="saveTemplate">Guardar plantilla</SecondaryButton>
                            </div>
                            <InputError :message="tplForm.errors.name || tplForm.errors.blocks" />
                        </fieldset>

                        <div class="flex flex-wrap items-center gap-3">
                            <PrimaryButton :disabled="form.processing || form.formats.length === 0 || blocks.length === 0">Generar informe</PrimaryButton>
                        </div>
                        <InputError :message="form.errors.from || form.errors.to || form.errors.formats || form.errors.brand_id" />
                        <p v-if="flash?.success" class="text-sm text-emerald-800" role="status">{{ flash.success }}</p>
                    </form>

                    <section v-if="preview" class="card space-y-3 p-5" data-preview aria-label="Vista previa del informe">
                        <h2 class="text-sm font-medium text-slate-800">Vista previa · {{ preview.brand.name }} · {{ preview.period.from }} - {{ preview.period.to }}</h2>
                        <div v-for="(b, i) in preview.blocks" :key="i" class="min-w-0 rounded-md border border-slate-200 p-3 text-sm" :data-preview-block="b.type">
                            <h3 class="font-medium text-slate-800">{{ b.title || label(b.type) }}</h3>
                            <p v-if="b.type === 'text'" class="mt-1 whitespace-pre-wrap break-words text-slate-700">{{ b.text }}</p>
                            <ul v-else-if="b.type === 'kpis'" class="mt-1 grid gap-1 sm:grid-cols-2">
                                <li v-for="c in preview.cards" :key="c.key" class="text-slate-700">{{ c.label }}: <strong>{{ fmt(c.total) }}</strong></li>
                            </ul>
                            <p v-else-if="b.type === 'evolution'" class="mt-1 text-slate-700">
                                {{ preview.timeline.points.length ? `${preview.timeline.label}: ${preview.timeline.points.length} ${preview.timeline.granularity === 'week' ? 'semanas' : 'días'} con serie.` : 'Sin serie con datos en este periodo.' }}
                            </p>
                            <ul v-else-if="b.type === 'top_posts'" class="mt-1 space-y-1 text-slate-700">
                                <li v-for="n in preview.networks.filter((x) => x.posts.length)" :key="n.label">{{ n.label }}: {{ n.posts.length }} publicaciones destacadas</li>
                                <li v-if="!preview.networks.some((x) => x.posts.length)" class="text-slate-600">Sin publicaciones con métricas en este periodo.</li>
                            </ul>
                            <div v-else-if="b.type === 'competitors'" class="mt-1 overflow-x-auto">
                                <p v-if="!b.data.competitors.length && !b.data.own" class="text-slate-600">Sin competidores con datos.</p>
                                <ul v-else class="space-y-1 text-slate-700">
                                    <li v-if="b.data.own">@{{ b.data.own.username }} (tu cuenta): {{ fmt(b.data.own.followers_end) }} seguidores</li>
                                    <li v-for="c in b.data.competitors" :key="c.username">@{{ c.username }}: {{ fmt(c.followers_end) }} seguidores</li>
                                </ul>
                            </div>
                            <div v-else-if="b.type === 'ads'" class="mt-1 text-slate-700">
                                <p v-if="!b.data.available" class="break-words">{{ b.data.message }}</p>
                                <template v-else>
                                    <p>Cuenta {{ b.data.account }}: {{ b.data.campaigns.length }} campañas · {{ preview.period.from }} - {{ preview.period.to }}</p>
                                    <ul class="mt-1 space-y-1">
                                        <li v-for="c in b.data.campaigns" :key="c.name" class="break-words">{{ c.name }}</li>
                                    </ul>
                                </template>
                            </div>
                        </div>
                    </section>

                    <section class="card space-y-3 p-5" data-schedules>
                        <h2 class="text-sm font-medium text-slate-800">Informes programados por email</h2>
                        <p v-if="brandTemplates.length === 0" class="text-sm text-slate-600">Guarda una plantilla de esta marca para poder programar su envío semanal o mensual.</p>
                        <form v-else class="flex flex-wrap items-end gap-3" @submit.prevent="createSchedule">
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Plantilla a enviar</span>
                                <select v-model="scheduleTemplate" class="field w-auto py-1.5" aria-label="Plantilla a enviar">
                                    <option value="">— Elige —</option>
                                    <option v-for="t in brandTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="block text-xs font-medium text-slate-600">Frecuencia</span>
                                <select v-model="scheduleForm.frequency" class="field w-auto py-1.5" aria-label="Frecuencia">
                                    <option value="weekly">Semanal (lunes, semana anterior)</option>
                                    <option value="monthly">Mensual (día 1, mes anterior)</option>
                                </select>
                            </label>
                            <label class="w-full min-w-0 text-sm sm:w-auto sm:flex-1">
                                <span class="block text-xs font-medium text-slate-600">Destinatarios (máximo {{ limits.recipients }}, separados por comas)</span>
                                <input v-model="recipientsText" type="text" class="field py-1.5" aria-label="Destinatarios" autocomplete="off" />
                            </label>
                            <PrimaryButton :disabled="scheduleForm.processing || !scheduleTemplate || !recipientsText.trim()">Programar informe</PrimaryButton>
                        </form>
                        <InputError :message="scheduleForm.errors.recipients || scheduleForm.errors['recipients.0'] || scheduleForm.errors.report_template_id || scheduleForm.errors.frequency" />
                        <ul v-if="brandSchedules.length" class="divide-y">
                            <li v-for="s in brandSchedules" :key="s.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" :data-schedule="s.id">
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-slate-900">{{ s.template }} · {{ s.frequency === 'weekly' ? 'Semanal' : 'Mensual' }} · {{ s.active ? 'Activo' : 'En pausa' }}</p>
                                    <p class="break-all text-xs text-slate-600">{{ s.recipients.join(', ') }}</p>
                                    <p class="text-xs text-slate-600">Próximo envío: {{ when(s.next_run_at) }} · Último: {{ when(s.last_run_at) }}</p>
                                    <p v-if="s.last_error" class="text-xs text-red-700">{{ s.last_error }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700" @click="toggleSchedule(s)">{{ s.active ? 'Pausar' : 'Reanudar' }}</button>
                                    <button type="button" class="rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700" @click="removeSchedule(s)">Eliminar envío</button>
                                </div>
                            </li>
                        </ul>
                    </section>
                </template>

                <section class="card">
                    <h2 class="border-b px-5 py-3 text-sm font-medium text-slate-700">Informes recientes</h2>
                    <p v-if="reports.length === 0" class="p-5 text-sm text-slate-600">Aún no has generado informes.</p>
                    <ul v-else class="divide-y">
                        <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm" :data-report="r.id">
                            <div>
                                <p class="font-medium text-slate-900">{{ r.brand }} · {{ r.format.toUpperCase() }}</p>
                                <p class="text-xs text-slate-600">{{ r.from }} - {{ r.to }} <span v-if="r.size">· {{ size(r.size) }}</span></p>
                                <p v-if="r.status === 'failed' && r.error" class="text-xs text-red-700">{{ r.error }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}</span>
                                <a v-if="r.download_url" :href="r.download_url" class="link">Descargar</a>
                                <button v-if="r.deletable" type="button" class="text-xs text-red-700 underline" @click="remove(r)">Eliminar</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
