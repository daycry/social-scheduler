<script setup>
import { nextTick, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import NetworkChip from '@/Components/NetworkChip.vue';
import InfoNote from '@/Components/InfoNote.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { fieldErrorDetails, HttpError, requestJson } from '@/lib/http';

/**
 * Importación de publicaciones por CSV (T-83): subir -> vista previa fila a fila (no crea nada) -> confirmar -> resumen.
 * La validación es la del compositor y vive en el servidor; aquí solo se pintan sus mensajes (siempre escapados con `{{ }}`).
 */
/** @import { PostImportOutcome, PostImportOutcomeRow, PostImportPageProps, PostImportPreview } from '@/types/post-import' */
const props = defineProps({
    brand: { type: /** @type {import('vue').PropType<PostImportPageProps['brand']>} */ (Object), required: true },
    limits: { type: /** @type {import('vue').PropType<PostImportPageProps['limits']>} */ (Object), required: true },
    columns: { type: /** @type {import('vue').PropType<PostImportPageProps['columns']>} */ (Array), required: true },
    required: { type: /** @type {import('vue').PropType<PostImportPageProps['required']>} */ (Array), required: true },
    accounts: { type: /** @type {import('vue').PropType<PostImportPageProps['accounts']>} */ (Array), required: true },
});
const ask = useConfirm();

const file = ref(/** @type {File | null} */ (null));
const fileInput = ref(/** @type {HTMLInputElement | null} */ (null));
const preview = ref(/** @type {PostImportPreview | null} */ (null));
const outcome = ref(/** @type {PostImportOutcome | null} */ (null));
const busy = ref(false);
const error = ref('');
const fileError = ref('');
const heading = ref(/** @type {HTMLElement | null} */ (null));

/** @param {Event} event */
const pick = (event) => {
    file.value = /** @type {HTMLInputElement} */ (event.target).files?.[0] ?? null;
};

/** @param {unknown} e */
const message = (e) => (e instanceof HttpError ? fieldErrorDetails(e.data) || e.message : 'No se pudo completar la operación.');

const focusResult = async () => {
    await nextTick();
    heading.value?.focus();
};

const analyze = async () => {
    if (busy.value || !file.value) return;
    busy.value = true;
    error.value = '';
    fileError.value = '';
    const body = new FormData();
    body.append('file', file.value);
    try {
        preview.value = /** @type {PostImportPreview} */ (
            await requestJson('POST', route('brands.posts.import.preview', props.brand.id), { body })
        );
        await focusResult();
    } catch (e) {
        preview.value = null;
        // Los errores del fichero (columnas, límites, codificación) van en su campo; el resto, en el aviso general.
        if (e instanceof HttpError && e.status === 422 && fieldErrorDetails(e.data)) fileError.value = fieldErrorDetails(e.data);
        else error.value = message(e);
    } finally {
        busy.value = false;
    }
};

const confirm = async () => {
    const current = preview.value;
    if (busy.value || !current || current.summary.valid === 0) return;
    const { valid, invalid } = current.summary;
    const accepted = await ask({
        title: 'Crear las publicaciones',
        message: `Se crearán ${valid} ${valid === 1 ? 'publicación' : 'publicaciones'}${invalid ? ` y se omitirán ${invalid} ${invalid === 1 ? 'fila con errores' : 'filas con errores'}` : ''}. ¿Continuar?`,
        confirmLabel: 'Crear publicaciones',
        cancelLabel: 'Revisar',
    });
    if (!accepted || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        outcome.value = /** @type {PostImportOutcome} */ (
            await requestJson('POST', route('brands.posts.import.confirm', props.brand.id), { json: { token: current.token } })
        );
        preview.value = null;
        await focusResult();
    } catch (e) {
        // 409 (ya confirmada) y 410 (caducada): la vista previa ya no sirve y no se puede reintentar con ella.
        if (e instanceof HttpError && (e.status === 409 || e.status === 410)) preview.value = null;
        // El 409 trae las filas ya procesadas: se pintan con la misma tabla de resultados.
        const done = e instanceof HttpError && e.status === 409 ? /** @type {PostImportOutcomeRow[] | undefined} */ (e.data?.rows) : undefined;
        if (done?.length) {
            const created = done.filter((r) => r.post_id !== null).length;
            outcome.value = { summary: { created, skipped: done.length - created }, rows: done, repeated: true };
            await focusResult();
        }
        error.value = message(e);
    } finally {
        busy.value = false;
    }
};

const reset = () => {
    preview.value = null;
    outcome.value = null;
    error.value = '';
    fileError.value = '';
    file.value = null;
    if (fileInput.value) fileInput.value.value = '';
};
</script>

<template>
    <Head title="Importar CSV" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-foreground-strong"
                >Importar publicaciones por CSV · {{ brand.name }}</h1
            >
        </template>

        <div class="max-w-5xl space-y-6">
            <p
                v-if="error"
                class="rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-ink"
                role="alert"
                data-testid="import-error"
                >{{ error }}</p
            >

            <EmptyState
                v-if="accounts.length === 0"
                title="Esta marca no tiene cuentas conectadas"
                description="Para importar publicaciones hace falta al menos una cuenta conectada en la marca."
            >
                <Link :href="route('brands.connections.index', brand.id)" class="btn-primary">Conectar una cuenta</Link>
            </EmptyState>

            <template v-else>
                <section v-if="!preview && !outcome" class="card space-y-4 p-4" aria-labelledby="import-upload">
                    <h2 id="import-upload" class="text-base font-semibold text-foreground-strong">Subir el fichero</h2>
                    <InfoNote>
                        Sube un CSV con las columnas <strong>{{ columns.join(', ') }}</strong> (obligatorias:
                        {{ required.join(', ') }}). Máximo {{ limits.max_rows }} filas y {{ limits.max_kb }} KB. Primero
                        verás una vista previa de cada fila y no se creará nada hasta que confirmes. Las horas se
                        interpretan en la zona de la marca ({{ brand.timezone ?? 'UTC' }}).
                    </InfoNote>
                    <form class="space-y-3" @submit.prevent="analyze">
                        <div>
                            <FormField id="import-file" :error="fileError" error-class="mt-1" v-slot="{ control }">
                                <label for="import-file" class="block text-sm font-medium text-foreground"
                                    >Fichero CSV</label
                                >
                                <input
                                    v-bind="control"
                                    ref="fileInput"
                                    type="file"
                                    accept=".csv,.txt,text/csv"
                                    class="field w-full"
                                    :disabled="busy"
                                    @change="pick"
                                />
                            </FormField>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary" :disabled="busy || !file"
                                >Analizar fichero</button
                            >
                            <a
                                :href="route('brands.posts.import.sample', brand.id)"
                                class="btn-secondary"
                                data-testid="import-sample"
                                >Descargar CSV de ejemplo</a
                            >
                        </div>
                    </form>

                    <div>
                        <h3 class="text-sm font-semibold text-foreground-strong"
                            >Cómo escribir las cuentas de esta marca</h3
                        >
                        <p class="mt-1 text-sm text-foreground-muted"
                            >Copia el identificador en la columna <code>cuentas</code>; con varias, sepáralas con
                            <code>|</code>.</p
                        >
                        <ul class="mt-2 space-y-1 text-sm text-foreground">
                            <li v-for="a in accounts" :key="a.id" class="flex flex-wrap items-center gap-2"
                                ><code class="rounded bg-surface-hover px-1">{{ a.identifier }}</code>
                                <NetworkChip :network="a.network" :label="a.network_label" /> {{ a.name }}</li
                            >
                        </ul>
                    </div>
                </section>

                <section v-if="preview" class="space-y-4" aria-labelledby="import-preview">
                    <h2
                        id="import-preview"
                        ref="heading"
                        tabindex="-1"
                        class="text-base font-semibold text-foreground-strong"
                        >Vista previa</h2
                    >
                    <p class="text-sm text-foreground-secondary" role="status" data-testid="import-summary">
                        {{ preview.summary.total }} {{ preview.summary.total === 1 ? 'fila' : 'filas' }}:
                        {{ preview.summary.valid }} {{ preview.summary.valid === 1 ? 'válida' : 'válidas' }} y
                        {{ preview.summary.invalid }} con errores. Todavía no se ha creado nada.
                    </p>
                    <div
                        class="card overflow-x-auto focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        role="region"
                        aria-label="Filas del CSV con su estado"
                        tabindex="0"
                    >
                        <table class="w-full text-left text-sm" data-testid="import-preview-table">
                            <caption class="sr-only">Filas del CSV con su estado</caption>
                            <thead class="border-b border-border text-xs uppercase text-foreground-muted">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Fila</th>
                                    <th scope="col" class="px-3 py-2">Fecha ({{ brand.timezone ?? 'UTC' }})</th>
                                    <th scope="col" class="px-3 py-2">Texto</th>
                                    <th scope="col" class="px-3 py-2">Cuentas</th>
                                    <th scope="col" class="px-3 py-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in preview.rows"
                                    :key="r.row"
                                    class="border-b border-border-subtle align-top"
                                    :data-testid="`import-row-${r.row}`"
                                >
                                    <td class="px-3 py-2">{{ r.row }}</td>
                                    <td class="px-3 py-2">{{ r.date ?? 'Borrador' }}</td>
                                    <td class="max-w-xs whitespace-pre-line break-words px-3 py-2">{{ r.text }}</td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-for="a in r.accounts"
                                            :key="a.id"
                                            class="mb-1 flex flex-wrap items-center gap-1"
                                            ><NetworkChip :network="a.network" :label="a.network_label" />
                                            {{ a.name }}</span
                                        >
                                    </td>
                                    <td class="px-3 py-2">
                                        <span v-if="r.valid" class="font-medium text-success-ink">Válida</span>
                                        <template v-else>
                                            <span class="font-medium text-danger-ink">Con errores (no se creará)</span>
                                            <ul class="mt-1 list-disc space-y-0.5 pl-4 text-danger-ink">
                                                <li v-for="(e, i) in r.errors" :key="i" class="break-words">{{ e }}</li>
                                            </ul>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="busy || preview.summary.valid === 0"
                            data-testid="import-confirm"
                            @click="confirm"
                        >
                            Crear {{ preview.summary.valid }}
                            {{ preview.summary.valid === 1 ? 'publicación' : 'publicaciones' }}
                        </button>
                        <button type="button" class="btn-secondary" :disabled="busy" @click="reset"
                            >Subir otro fichero</button
                        >
                    </div>
                </section>

                <section v-if="outcome" class="space-y-4" aria-labelledby="import-result">
                    <h2
                        id="import-result"
                        ref="heading"
                        tabindex="-1"
                        class="text-base font-semibold text-foreground-strong"
                        >Resultado de la importación</h2
                    >
                    <p class="text-sm text-foreground-secondary" role="status" data-testid="import-outcome">
                        {{ outcome.summary.created }}
                        {{ outcome.summary.created === 1 ? 'publicación creada' : 'publicaciones creadas' }} y
                        {{ outcome.summary.skipped }}
                        {{ outcome.summary.skipped === 1 ? 'fila omitida' : 'filas omitidas' }}.
                    </p>
                    <div
                        class="card overflow-x-auto focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        role="region"
                        aria-label="Resultado de cada fila"
                        tabindex="0"
                    >
                        <table class="w-full text-left text-sm" data-testid="import-result-table">
                            <caption class="sr-only">Resultado de cada fila</caption>
                            <thead class="border-b border-border text-xs uppercase text-foreground-muted">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Fila</th>
                                    <th scope="col" class="px-3 py-2">Texto</th>
                                    <th scope="col" class="px-3 py-2">Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in outcome.rows"
                                    :key="r.row"
                                    class="border-b border-border-subtle align-top"
                                    :data-testid="`import-result-${r.row}`"
                                >
                                    <td class="px-3 py-2">{{ r.row }}</td>
                                    <td class="max-w-xs whitespace-pre-line break-words px-3 py-2">{{ r.text }}</td>
                                    <td class="px-3 py-2">
                                        <Link
                                            v-if="r.post_id !== null"
                                            :href="route('posts.edit', r.post_id)"
                                            class="link"
                                            >Creada: abrir publicación</Link
                                        >
                                        <template v-else>
                                            <span class="font-medium text-danger-ink">Omitida</span>
                                            <ul class="mt-1 list-disc space-y-0.5 pl-4 text-danger-ink">
                                                <li v-for="(e, i) in r.errors" :key="i" class="break-words">{{ e }}</li>
                                            </ul>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('calendar.index', { brand_id: brand.id, view: 'list' })" class="btn-primary"
                            >Ver en el calendario</Link
                        >
                        <button type="button" class="btn-secondary" @click="reset">Importar otro fichero</button>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
