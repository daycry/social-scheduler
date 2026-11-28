<script setup>
import ReportPreview from '@/Components/Reports/ReportPreview.vue';
import ReportsRecent from '@/Components/Reports/ReportsRecent.vue';
import { useReportPreview } from '@/Composables/useReportPreview';
import { createDateFormatter } from '@/lib/format';
import { firstGroupError, focusFirstFormError } from '@/lib/form-errors';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FormField from '@/Components/FormField.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

/**
 * Informes (T-40 + T-21): constructor con bloques elegibles y ordenables, plantillas por marca, vista previa, generacion de
 * PDF/CSV en segundo plano (descarga con enlace firmado de unos minutos) y envios programados por email. Mientras haya
 * informes en curso la lista se refresca sola.
 */
const props = defineProps({
    pagination: { type: /** @type {import('vue').PropType<import('@/types/pagination').CursorPage>} */ (Object), required: true },
    brands: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportBrand[]>} */ (Array), required: true },
    reports: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportRow[]>} */ (Array), required: true },
    defaults: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportDefaults>} */ (Object), required: true },
    polling: Boolean,
    templates: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportTemplate[]>} */ (Array), default: () => [] },
    schedules: { type: /** @type {import('vue').PropType<import('@/types/reports').ReportSchedule[]>} */ (Array), default: () => [] },
    blockTypes: { type: /** @type {import('vue').PropType<string[]>} */ (Array), default: () => [] },
    limits: { type: /** @type {import('vue').PropType<{blocks: number, text: number, title: number, recipients: number}>} */ (Object), default: () => ({ blocks: 20, text: 2000, title: 80, recipients: 5 }) },
});

/** @type {Partial<Record<string, string>>} */
const BLOCK_LABELS = {
    kpis: 'Indicadores por red',
    evolution: 'Evolución',
    top_posts: 'Publicaciones destacadas',
    competitors: 'Competidores',
    ads: 'Anuncios (Meta Ads)',
    text: 'Nota de texto',
};
/** @param {string} t */
const label = (t) => BLOCK_LABELS[t] ?? t;

let uid = 0;
/** @param {string} type @param {string | null} [title] @param {string | null} [text] */
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
const reportFormElement = ref(/** @type {HTMLFormElement | null} */ (null));
const recentReports = ref(/** @type {InstanceType<typeof ReportsRecent> | null} */ (null));
const scheduleFormElement = ref(/** @type {HTMLFormElement | null} */ (null));
const toPayload = () => blocks.value.map((b) => ({ type: b.type, title: b.title || null, text: b.type === 'text' ? b.text : null }));

const submit = () => {
    form.transform(data => ({ ...data, blocks: toPayload(), limit: props.pagination?.limit }))
        .post(route('reports.store'), {
            preserveScroll: true,
            onSuccess: () => recentReports.value?.restartPolling(),
            onError: () => focusFirstFormError(reportFormElement.value),
        });
};

// --- Bloques
const newType = ref('text');
const addBlock = () => {
    if (blocks.value.length < props.limits.blocks) blocks.value.push(makeBlock(newType.value));
};
/** @param {number} i */
const removeBlock = (i) => blocks.value.splice(i, 1);
/** @param {number} i @param {number} d */
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
const rangeDays = computed(() => Math.max(1, Math.round((new Date(form.to).getTime() - new Date(form.from).getTime()) / 86400000) + 1) || 30);
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
const deleteTemplate = async () => {
    const t = brandTemplates.value.find((x) => x.id === Number(templateId.value));
    if (t && await ask({ message: `¿Eliminar la plantilla «${t.name}»? También se borran sus envíos programados.`, confirmLabel: 'Eliminar plantilla' })) {
        router.delete(route('report-templates.destroy', t.id), { preserveScroll: true, onSuccess: () => (templateId.value = '') });
    }
};

// --- Vista previa
/** @type {ReturnType<typeof useReportPreview<import('@/types/reports').ReportPreview>>} */
const { preview, previewError, previewing, runPreview, resetPreview } = useReportPreview(() => ({
    brand_id: form.brand_id,
    from: form.from,
    to: form.to,
    compare: form.compare,
    blocks: toPayload(),
}));
// Cambiar de marca nunca arrastra la vista previa, la plantilla ni los envios de la anterior.
watch(
    () => form.brand_id,
    () => {
        resetPreview();
        templateId.value = '';
        templateName.value = '';
        scheduleTemplate.value = '';
    },
);

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
        .post(route('report-schedules.store'), {
            preserveScroll: true,
            onSuccess: () => (recipientsText.value = ''),
            onError: () => focusFirstFormError(scheduleFormElement.value),
        });
};
/** @param {import('@/types/reports').ReportSchedule} s */
const toggleSchedule = (s) => router.put(route('report-schedules.update', s.id), { active: !s.active }, { preserveScroll: true });
/** @param {import('@/types/reports').ReportSchedule} s */
const removeSchedule = async (s) => {
    if (await ask({ message: `¿Eliminar el envío ${s.frequency === 'weekly' ? 'semanal' : 'mensual'} de ${s.brand}?`, confirmLabel: 'Eliminar envío' })) router.delete(route('report-schedules.destroy', s.id), { preserveScroll: true });
};
const when = computed(() => {
    const brand = props.brands.find(item => item.id === Number(form.brand_id));
    return createDateFormatter(brand?.timezone, { dateStyle: 'medium', timeStyle: 'short' }, '—');
});
</script>

<template>
    <Head title="Informes" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Informes</h1>
        </template>

        <div>
            <div class="space-y-5">
                <div v-if="brands.length === 0" class="rounded-lg bg-surface p-6 text-foreground-secondary shadow-sm">
                    Aún no tienes marcas.
                    <Link :href="route('brands.create')" class="link">Crea una marca</Link> para generar informes.
                </div>

                <template v-else>
                    <form ref="reportFormElement" class="space-y-4 card p-5" @submit.prevent="submit">
                        <div class="flex flex-wrap items-end gap-4">
                            <FormField :error="form.errors.brand_id" v-slot="{ control }">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted">Marca</span>
                                    <select
                                        v-bind="control"
                                        v-model="form.brand_id"
                                        aria-label="Marca"
                                        class="field w-auto max-w-full py-1.5"
                                    >
                                        <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                                    </select>
                                </label>
                            </FormField>
                            <FormField :error="form.errors.from" v-slot="{ control }">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted">Desde</span>
                                    <input
                                        v-bind="control"
                                        v-model="form.from"
                                        type="date"
                                        class="field w-auto max-w-full py-1.5"
                                    />
                                </label>
                            </FormField>
                            <FormField :error="form.errors.to" v-slot="{ control }">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted">Hasta</span>
                                    <input
                                        v-bind="control"
                                        v-model="form.to"
                                        type="date"
                                        class="field w-auto max-w-full py-1.5"
                                    />
                                </label>
                            </FormField>
                            <label class="min-w-0 max-w-full text-sm">
                                <span class="block text-xs font-medium text-foreground-muted">Comparar con</span>
                                <select v-model="form.compare" class="field w-auto max-w-full py-1.5">
                                    <option value="none">Sin comparación</option>
                                    <option value="previous">Periodo anterior</option>
                                    <option value="year">Mismo periodo del año anterior</option>
                                </select>
                            </label>
                            <FormField :error="firstGroupError(form.errors, 'formats')" v-slot="{ group }">
                                <fieldset v-bind="group" class="flex gap-3 text-sm">
                                    <legend class="mb-1 block text-xs font-medium text-foreground-muted"
                                        >Formato</legend
                                    >
                                    <label class="flex items-center gap-1"
                                        ><input
                                            v-model="form.formats"
                                            type="checkbox"
                                            value="pdf"
                                            class="rounded border-control-border"
                                        />
                                        PDF</label
                                    >
                                    <label class="flex items-center gap-1"
                                        ><input
                                            v-model="form.formats"
                                            type="checkbox"
                                            value="csv"
                                            class="rounded border-control-border"
                                        />
                                        CSV</label
                                    >
                                </fieldset>
                            </FormField>
                        </div>

                        <FormField :error="form.errors.blocks || tplForm.errors.blocks" v-slot="{ group }">
                            <fieldset v-bind="group" class="space-y-3 rounded-lg border border-border p-4" data-builder>
                                <legend class="px-1 text-sm font-medium text-foreground"
                                    >Bloques del informe PDF</legend
                                >
                                <ol class="space-y-2">
                                    <li
                                        v-for="(b, i) in blocks"
                                        :key="b.key"
                                        class="rounded-md border border-border bg-canvas p-3"
                                        data-block
                                        :data-block-type="b.type"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="text-sm font-medium text-foreground"
                                                >{{ i + 1 }}. {{ label(b.type) }}</span
                                            >
                                            <span class="flex flex-wrap gap-1">
                                                <button
                                                    type="button"
                                                    class="rounded border border-control-border bg-surface px-2 py-1 text-xs text-foreground-secondary disabled:opacity-50"
                                                    :disabled="i === 0"
                                                    :aria-label="`Subir bloque ${i + 1}: ${label(b.type)}`"
                                                    @click="moveBlock(i, -1)"
                                                    >Subir</button
                                                >
                                                <button
                                                    type="button"
                                                    class="rounded border border-control-border bg-surface px-2 py-1 text-xs text-foreground-secondary disabled:opacity-50"
                                                    :disabled="i === blocks.length - 1"
                                                    :aria-label="`Bajar bloque ${i + 1}: ${label(b.type)}`"
                                                    @click="moveBlock(i, 1)"
                                                    >Bajar</button
                                                >
                                                <button
                                                    type="button"
                                                    class="rounded border border-danger-border bg-surface px-2 py-1 text-xs text-danger"
                                                    :aria-label="`Quitar bloque ${i + 1}: ${label(b.type)}`"
                                                    @click="removeBlock(i)"
                                                    >Quitar</button
                                                >
                                            </span>
                                        </div>
                                        <label class="mt-2 block text-xs font-medium text-foreground-muted">
                                            Título (opcional)
                                            <input
                                                v-model="b.title"
                                                type="text"
                                                :maxlength="limits.title"
                                                class="field mt-1 py-1.5"
                                                :aria-label="`Título del bloque ${i + 1}`"
                                            />
                                        </label>
                                        <label
                                            v-if="b.type === 'text'"
                                            class="mt-2 block text-xs font-medium text-foreground-muted"
                                        >
                                            Texto de la nota
                                            <textarea
                                                v-model="b.text"
                                                rows="3"
                                                :maxlength="limits.text"
                                                class="field mt-1"
                                                :aria-label="`Texto del bloque ${i + 1}`"
                                            />
                                        </label>
                                    </li>
                                </ol>
                                <p v-if="blocks.length === 0" class="text-sm text-foreground-muted"
                                    >Añade al menos un bloque.</p
                                >
                                <div class="flex flex-wrap items-end gap-3">
                                    <label class="min-w-0 max-w-full text-sm">
                                        <span class="block text-xs font-medium text-foreground-muted"
                                            >Añadir bloque</span
                                        >
                                        <select
                                            v-model="newType"
                                            class="field w-auto max-w-full py-1.5"
                                            aria-label="Tipo de bloque"
                                        >
                                            <option v-for="t in blockTypes" :key="t" :value="t">{{ label(t) }}</option>
                                        </select>
                                    </label>
                                    <SecondaryButton
                                        type="button"
                                        :disabled="blocks.length >= limits.blocks"
                                        @click="addBlock"
                                        >Añadir bloque</SecondaryButton
                                    >
                                    <SecondaryButton
                                        type="button"
                                        :disabled="previewing || blocks.length === 0"
                                        @click="runPreview"
                                        >Vista previa</SecondaryButton
                                    >
                                </div>
                            </fieldset>
                        </FormField>

                        <FormField :error="previewError" />

                        <fieldset class="space-y-2 rounded-lg border border-border p-4" data-templates>
                            <legend class="px-1 text-sm font-medium text-foreground">Plantillas de esta marca</legend>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted"
                                        >Plantilla guardada</span
                                    >
                                    <select
                                        v-model="templateId"
                                        class="field w-auto max-w-full py-1.5"
                                        aria-label="Plantilla guardada"
                                    >
                                        <option value="">— Ninguna —</option>
                                        <option
                                            v-for="t in brandTemplates"
                                            :key="t.id"
                                            :value="t.id"
                                            >{{ t.name }}</option
                                        >
                                    </select>
                                </label>
                                <SecondaryButton type="button" :disabled="!templateId" @click="loadTemplate"
                                    >Cargar plantilla</SecondaryButton
                                >
                                <SecondaryButton type="button" :disabled="!templateId" @click="deleteTemplate"
                                    >Eliminar plantilla</SecondaryButton
                                >
                            </div>
                            <div class="flex flex-wrap items-end gap-3">
                                <FormField :error="tplForm.errors.name" v-slot="{ control }">
                                    <label class="min-w-0 max-w-full text-sm">
                                        <span class="block text-xs font-medium text-foreground-muted"
                                            >Nombre de la plantilla</span
                                        >
                                        <input
                                            v-bind="control"
                                            v-model="templateName"
                                            type="text"
                                            maxlength="80"
                                            class="field py-1.5"
                                            aria-label="Nombre de la plantilla"
                                        />
                                    </label>
                                </FormField>
                                <SecondaryButton
                                    type="button"
                                    :disabled="tplForm.processing || !templateName.trim() || blocks.length === 0"
                                    @click="saveTemplate"
                                    >Guardar plantilla</SecondaryButton
                                >
                            </div>
                        </fieldset>

                        <div class="flex flex-wrap items-center gap-3">
                            <PrimaryButton
                                :disabled="form.processing || form.formats.length === 0 || blocks.length === 0"
                                >Generar informe</PrimaryButton
                            >
                        </div>
                    </form>

                    <ReportPreview :preview="preview" :label="label" />

                    <section class="card space-y-3 p-5" data-schedules>
                        <h2 class="text-sm font-medium text-foreground">Informes programados por correo</h2>
                        <p v-if="brandTemplates.length === 0" class="text-sm text-foreground-muted"
                            >Guarda una plantilla de esta marca para poder programar su envío semanal o mensual.</p
                        >
                        <form
                            v-else
                            ref="scheduleFormElement"
                            class="flex flex-wrap items-end gap-3"
                            @submit.prevent="createSchedule"
                        >
                            <FormField :error="scheduleForm.errors.report_template_id" v-slot="{ control }">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted"
                                        >Plantilla a enviar</span
                                    >
                                    <select
                                        v-bind="control"
                                        v-model="scheduleTemplate"
                                        class="field w-auto max-w-full py-1.5"
                                        aria-label="Plantilla a enviar"
                                    >
                                        <option value="">— Elige —</option>
                                        <option
                                            v-for="t in brandTemplates"
                                            :key="t.id"
                                            :value="t.id"
                                            >{{ t.name }}</option
                                        >
                                    </select>
                                </label>
                            </FormField>
                            <FormField :error="scheduleForm.errors.frequency" v-slot="{ control }">
                                <label class="min-w-0 max-w-full text-sm">
                                    <span class="block text-xs font-medium text-foreground-muted">Frecuencia</span>
                                    <select
                                        v-bind="control"
                                        v-model="scheduleForm.frequency"
                                        class="field w-auto max-w-full py-1.5"
                                        aria-label="Frecuencia"
                                    >
                                        <option value="weekly">Semanal (lunes, semana anterior)</option>
                                        <option value="monthly">Mensual (día 1, mes anterior)</option>
                                    </select>
                                </label>
                            </FormField>
                            <FormField :error="firstGroupError(scheduleForm.errors, 'recipients')" v-slot="{ control }">
                                <label class="w-full min-w-0 text-sm sm:w-auto sm:flex-1">
                                    <span class="block text-xs font-medium text-foreground-muted"
                                        >Destinatarios (máximo {{ limits.recipients }}, separados por comas)</span
                                    >
                                    <input
                                        v-bind="control"
                                        v-model="recipientsText"
                                        type="text"
                                        class="field py-1.5"
                                        aria-label="Destinatarios"
                                        autocomplete="off"
                                    />
                                </label>
                            </FormField>
                            <PrimaryButton
                                :disabled="scheduleForm.processing || !scheduleTemplate || !recipientsText.trim()"
                                >Programar informe</PrimaryButton
                            >
                        </form>
                        <ul v-if="brandSchedules.length" class="divide-y">
                            <li
                                v-for="s in brandSchedules"
                                :key="s.id"
                                class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                                :data-schedule="s.id"
                            >
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-foreground-strong"
                                        >{{ s.template }} · {{ s.frequency === 'weekly' ? 'Semanal' : 'Mensual' }} ·
                                        {{ s.active ? 'Activo' : 'En pausa' }}</p
                                    >
                                    <p class="break-all text-xs text-foreground-muted">{{ s.recipients.join(', ') }}</p>
                                    <p class="text-xs text-foreground-muted"
                                        >Próximo envío: {{ when(s.next_run_at) }} · Último: {{ when(s.last_run_at) }}</p
                                    >
                                    <p v-if="s.last_error" class="text-xs text-danger">{{ s.last_error }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded border border-control-border bg-surface px-2 py-1 text-xs text-foreground-secondary"
                                        @click="toggleSchedule(s)"
                                        >{{ s.active ? 'Pausar' : 'Reanudar' }}</button
                                    >
                                    <button
                                        type="button"
                                        class="rounded border border-danger-border bg-surface px-2 py-1 text-xs text-danger"
                                        @click="removeSchedule(s)"
                                        >Eliminar envío</button
                                    >
                                </div>
                            </li>
                        </ul>
                    </section>
                </template>

                <ReportsRecent
                    ref="recentReports"
                    :reports="reports"
                    :pagination="pagination"
                    :brand-id="defaults.brand_id"
                    :polling="polling"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
