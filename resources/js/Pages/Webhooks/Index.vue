<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useConfirm } from '@/Composables/useConfirm';

/** @import { WebhookInput, WebhookView, WebhooksPageProps } from '@/types/webhooks' */
const props = defineProps({
    brands: { type: /** @type {import('vue').PropType<WebhooksPageProps['brands']>} */ (Array), required: true },
    brand: { type: /** @type {import('vue').PropType<WebhooksPageProps['brand']>} */ (Object), default: null },
    webhooks: { type: /** @type {import('vue').PropType<WebhooksPageProps['webhooks']>} */ (Array), required: true },
    events: { type: /** @type {import('vue').PropType<WebhooksPageProps['events']>} */ (Object), required: true },
    delivery_states: {
        type: /** @type {import('vue').PropType<WebhooksPageProps['delivery_states']>} */ (Object),
        required: true,
    },
    max_per_user: { type: Number, required: true },
    pagination: {
        type: /** @type {import('vue').PropType<WebhooksPageProps['pagination']>} */ (Object),
        required: true,
    },
});
const ask = useConfirm();
const editing = ref(/** @type {number | null} */ (null));
const selectedBrand = ref(props.brand?.id ?? null);
const navigating = ref(false);
const deleting = ref(/** @type {number | null} */ (null));
const showSecret = ref(false);
const generationError = ref('');
const nameInput = ref(/** @type {HTMLInputElement | null} */ (null));
/** @returns {WebhookInput} */
const defaults = () => ({ name: '', url: '', secret: '', events: ['post.published'], enabled: true });
const form = useForm(defaults());
const busy = computed(() => form.processing || navigating.value || deleting.value !== null);
const errors = computed(() => [
    ...Object.values(form.errors),
    ...(generationError.value ? [generationError.value] : []),
]);
const dates = new Intl.DateTimeFormat('es-ES', { dateStyle: 'short', timeStyle: 'short' });

watch(
    () => props.brand?.id,
    (id) => {
        selectedBrand.value = id ?? null;
    },
);

function reset() {
    editing.value = null;
    showSecret.value = false;
    generationError.value = '';
    form.defaults(defaults());
    form.reset();
    form.clearErrors();
}

function generateSecret() {
    generationError.value = '';
    if (!globalThis.crypto?.getRandomValues) {
        generationError.value =
            'No se puede generar un secreto en este navegador. Introduce uno propio de al menos 32 caracteres.';
        return;
    }
    form.secret = Array.from(crypto.getRandomValues(new Uint8Array(32)), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

/** @param {WebhookView} hook */
async function edit(hook) {
    if (busy.value) return;
    if (form.isDirty && !(await ask('¿Descartar los cambios pendientes del formulario?'))) return;
    form.defaults({ name: hook.name, url: hook.url, secret: '', events: [...hook.events], enabled: hook.enabled });
    form.reset();
    form.clearErrors();
    generationError.value = '';
    editing.value = hook.id;
    await nextTick();
    nameInput.value?.focus();
}

function submit() {
    if (busy.value || !props.brand) return;
    form.transform((data) =>
        editing.value === null ? data : { name: data.name, events: data.events, enabled: data.enabled },
    );
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.post(route('brands.webhooks.store', props.brand.id), options);
    else form.put(route('webhooks.update', editing.value), options);
}

async function changeBrand() {
    if (busy.value || selectedBrand.value === props.brand?.id) return;
    if (form.isDirty && !(await ask('¿Descartar los cambios pendientes y cambiar de marca?'))) {
        selectedBrand.value = props.brand?.id ?? null;
        return;
    }
    router.get(
        route('webhooks.index'),
        { brand_id: selectedBrand.value },
        {
            preserveState: false,
            onStart: () => {
                navigating.value = true;
            },
            onFinish: () => {
                navigating.value = false;
            },
        },
    );
}

/** @param {WebhookView} hook */
async function remove(hook) {
    if (
        busy.value ||
        !(await ask({
            title: 'Eliminar webhook',
            message: `¿Eliminar «${hook.name}» y sus entregas pendientes?`,
            confirmLabel: 'Eliminar webhook',
        }))
    )
        return;
    router.delete(route('webhooks.destroy', hook.id), {
        preserveScroll: true,
        onStart: () => {
            deleting.value = hook.id;
        },
        onFinish: () => {
            deleting.value = null;
        },
        onSuccess: () => {
            if (editing.value === hook.id) reset();
        },
    });
}

function refresh() {
    if (busy.value) return;
    router.reload({
        only: ['webhooks', 'pagination'],
        onStart: () => {
            navigating.value = true;
        },
        onFinish: () => {
            navigating.value = false;
        },
    });
}
</script>

<template>
    <Head title="Webhooks salientes" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-slate-900">Webhooks salientes</h1></template>
        <div class="space-y-6">
            <div v-if="brands.length" class="max-w-md space-y-1">
                <label for="webhooks-brand" class="block text-sm font-medium text-slate-800">Marca</label>
                <select
                    id="webhooks-brand"
                    v-model.number="selectedBrand"
                    class="field w-full"
                    :disabled="busy"
                    @change="changeBrand"
                >
                    <option v-for="option in brands" :key="option.id" :value="option.id">{{ option.name }}</option>
                </select>
            </div>
            <p v-if="!brand" class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
                Crea una marca para configurar webhooks.
                <Link :href="route('brands.create')" class="link">Crear marca</Link>
            </p>
            <template v-else>
                <p class="text-sm text-slate-600"
                    >Recibe eventos de esta marca en un receptor HTTPS público. Máximo {{ max_per_user }} webhooks entre
                    todas tus marcas.</p
                >
                <form
                    class="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2"
                    aria-label="Configuración del webhook"
                    @submit.prevent="submit"
                >
                    <h2
                        class="text-lg font-semibold text-slate-900 sm:col-span-2"
                        >{{ editing === null ? 'Nuevo webhook' : 'Editar webhook' }}</h2
                    >
                    <div class="min-w-0 space-y-1 sm:col-span-2">
                        <label for="webhook-name" class="block text-sm font-medium text-slate-800"
                            >Nombre del webhook</label
                        >
                        <input
                            id="webhook-name"
                            ref="nameInput"
                            v-model="form.name"
                            class="field w-full"
                            required
                            maxlength="120"
                            :disabled="busy"
                        />
                    </div>
                    <template v-if="editing === null">
                        <div class="min-w-0 space-y-1 sm:col-span-2">
                            <label for="webhook-url" class="block text-sm font-medium text-slate-800"
                                >URL del receptor</label
                            >
                            <input
                                id="webhook-url"
                                v-model="form.url"
                                type="url"
                                class="field w-full"
                                required
                                maxlength="2048"
                                placeholder="https://tu-receptor.example/eventos"
                                :disabled="busy"
                            />
                        </div>
                        <div class="min-w-0 space-y-2 sm:col-span-2">
                            <label for="webhook-secret" class="block text-sm font-medium text-slate-800"
                                >Secreto de firma</label
                            >
                            <input
                                id="webhook-secret"
                                v-model="form.secret"
                                :type="showSecret ? 'text' : 'password'"
                                class="field w-full"
                                autocomplete="new-password"
                                :spellcheck="false"
                                required
                                minlength="32"
                                maxlength="128"
                                pattern="[A-Za-z0-9_\-]{32,128}"
                                aria-describedby="webhook-secret-help"
                                :disabled="busy"
                            />
                            <div class="flex flex-wrap items-center gap-4">
                                <button type="button" class="btn-secondary" :disabled="busy" @click="generateSecret"
                                    >Generar secreto</button
                                >
                                <label class="flex items-center gap-2 text-sm text-slate-800"
                                    ><input
                                        v-model="showSecret"
                                        type="checkbox"
                                        class="rounded border-slate-300"
                                        :disabled="busy"
                                    />
                                    Mostrar secreto</label
                                >
                            </div>
                            <p id="webhook-secret-help" class="text-xs text-slate-600"
                                >Copia el secreto antes de crear el webhook: el servidor no vuelve a mostrarlo. Usa de
                                32 a 128 letras, números, guiones o guiones bajos.</p
                            >
                        </div>
                    </template>
                    <p v-else class="break-all text-sm text-slate-600 sm:col-span-2"
                        >{{ form.url }} · Para cambiar de URL o secreto, crea un webhook nuevo.</p
                    >
                    <fieldset class="space-y-2 sm:col-span-2" :disabled="busy">
                        <legend class="mb-2 text-sm font-medium text-slate-800">Eventos que quieres recibir</legend>
                        <label
                            v-for="(label, eventName) in events"
                            :key="eventName"
                            class="flex items-center gap-2 text-sm text-slate-800"
                        >
                            <input
                                v-model="form.events"
                                type="checkbox"
                                :value="eventName"
                                class="rounded border-slate-300"
                            />
                            {{ label }}
                        </label>
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm text-slate-800 sm:col-span-2"
                        ><input
                            v-model="form.enabled"
                            type="checkbox"
                            class="rounded border-slate-300"
                            :disabled="busy"
                        />
                        Activo</label
                    >
                    <div v-if="errors.length" role="alert" class="space-y-1 text-sm text-red-700 sm:col-span-2"
                        ><p v-for="(error, index) in errors" :key="index">{{ error }}</p></div
                    >
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="busy"
                            >{{ form.processing ? 'Guardando…' : editing === null ? 'Crear webhook' : 'Guardar cambios' }}</button
                        >
                        <button
                            v-if="editing !== null"
                            type="button"
                            class="btn-secondary"
                            :disabled="busy"
                            @click="reset"
                            >Cancelar edición</button
                        >
                    </div>
                </form>
                <section class="space-y-3" aria-labelledby="webhooks-list-title">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="webhooks-list-title" class="text-lg font-semibold text-slate-900"
                            >Receptores de la marca</h2
                        >
                        <button type="button" class="btn-secondary" :disabled="busy" @click="refresh"
                            >Actualizar estado</button
                        >
                    </div>
                    <p v-if="!webhooks.length" class="text-sm text-slate-600">No hay webhooks en esta página.</p>
                    <article
                        v-for="hook in webhooks"
                        :key="hook.id"
                        class="space-y-2 rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0"
                                ><h3 class="break-words font-semibold text-slate-900">{{ hook.name }}</h3
                                ><p class="text-sm text-slate-600">{{ hook.enabled ? 'Activo' : 'Pausado' }}</p></div
                            >
                            <div class="flex gap-2"
                                ><button
                                    type="button"
                                    class="btn-secondary"
                                    :aria-label="`Editar ${hook.name}`"
                                    :disabled="busy"
                                    @click="edit(hook)"
                                    >Editar</button
                                ><button
                                    type="button"
                                    class="btn-secondary"
                                    :aria-label="`Eliminar ${hook.name}`"
                                    :disabled="busy"
                                    @click="remove(hook)"
                                    >Eliminar</button
                                ></div
                            >
                        </div>
                        <p class="break-all text-sm text-slate-700">{{ hook.url }}</p>
                        <ul class="flex flex-wrap gap-x-3 text-sm text-slate-600"
                            ><li v-for="eventName in hook.events" :key="eventName">{{ events[eventName] }}</li></ul
                        >
                        <div v-if="hook.last_delivery" class="space-y-1 text-sm text-slate-700">
                            <p
                                >Última entrega:
                                {{ delivery_states[hook.last_delivery.status] ?? 'Estado no disponible' }} · Intentos:
                                {{ hook.last_delivery.attempts
                                }}<span v-if="hook.last_delivery.http_status !== null">
                                    · HTTP {{ hook.last_delivery.http_status }}</span
                                ></p
                            >
                            <p>Actualizado: {{ dates.format(new Date(hook.last_delivery.updated_at)) }}</p>
                            <p v-if="hook.last_delivery.reason">{{ hook.last_delivery.reason }}</p>
                            <p v-if="hook.last_delivery.next_attempt_at"
                                >Próximo intento: {{ dates.format(new Date(hook.last_delivery.next_attempt_at)) }}</p
                            >
                        </div>
                        <p v-else class="text-sm text-slate-600">Aún sin entregas.</p>
                    </article>
                    <Pagination
                        :page="pagination"
                        route-name="webhooks.index"
                        :params="{ brand_id: brand.id }"
                        label="Paginación de webhooks"
                    />
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
