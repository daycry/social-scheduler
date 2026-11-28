<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useConfirm } from '@/Composables/useConfirm';

/** @import { GoalInput, GoalView, GoalsPageProps } from '@/types/goals' */
const props = defineProps({
    brands: { type: /** @type {import('vue').PropType<GoalsPageProps['brands']>} */ (Array), required: true },
    brand: { type: /** @type {import('vue').PropType<GoalsPageProps['brand']>} */ (Object), default: null },
    goals: { type: /** @type {import('vue').PropType<GoalsPageProps['goals']>} */ (Array), required: true },
    accounts: { type: /** @type {import('vue').PropType<GoalsPageProps['accounts']>} */ (Array), required: true },
    kinds: { type: /** @type {import('vue').PropType<GoalsPageProps['kinds']>} */ (Object), required: true },
    metrics: { type: /** @type {import('vue').PropType<GoalsPageProps['metrics']>} */ (Object), required: true },
    limits: { type: /** @type {import('vue').PropType<GoalsPageProps['limits']>} */ (Object), required: true },
    pagination: { type: /** @type {import('vue').PropType<GoalsPageProps['pagination']>} */ (Object), required: true },
});
const ask = useConfirm();
const editing = ref(/** @type {number | null} */ (null));
const deleting = ref(/** @type {number | null} */ (null));
const navigating = ref(false);
const selectedBrand = ref(props.brand?.id ?? null);
const nameInput = ref(/** @type {HTMLInputElement | null} */ (null));
const numbers = new Intl.NumberFormat('es-ES', { maximumFractionDigits: 4 });
/** @returns {GoalInput} */
const defaults = () => ({
    name: '',
    kind: 'milestone',
    metric: 'followers',
    threshold: 1000,
    social_account_id: null,
    enabled: true,
    mail_enabled: false,
});
const form = useForm(defaults());
const busy = computed(() => form.processing || navigating.value || deleting.value !== null);
const integer = computed(
    () => form.kind === 'token_expiry' || (form.kind === 'milestone' && form.metric === 'followers'),
);
const minimum = computed(() => (integer.value ? props.limits.min_integer : props.limits.min_decimal));
const errors = computed(() => Object.entries(form.errors));
const unavailableAccount = computed(() => editing.value !== null && form.social_account_id !== null
    && !props.accounts.some((account) => account.id === form.social_account_id)
    ? props.goals.find((goal) => goal.id === editing.value) : null);
const dates = computed(() => {
    try {
        return new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.brand?.timezone || 'UTC' });
    } catch {
        return new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' });
    }
});

/** @param {GoalView} goal */
function tokenObservation(goal) {
    if (goal.last_expires_at === null || goal.last_evaluated_at === null) return 'Caducidad no disponible.';
    const observed = Date.parse(goal.last_evaluated_at);
    const expiry = Date.parse(goal.last_expires_at);
    const label = goal.last_value !== null && goal.last_value < 0 ? 'Caducó el' : 'Caducidad conocida:';
    return Number.isFinite(observed) && Number.isFinite(expiry)
        ? `${label} ${dates.value.format(expiry)} (${dates.value.resolvedOptions().timeZone}) · observada el ${dates.value.format(observed)}.`
        : 'Caducidad no disponible.';
}
const ruleHint = computed(() => {
    if (form.kind === 'drop') return 'Avisa si la reducción relativa supera este porcentaje respecto al día anterior; no son puntos porcentuales.';
    if (form.kind === 'token_expiry') return 'Avisa una vez por plazo conocido que requiera reconectar. El acceso que se renueva automáticamente no genera este aviso.';
    return 'Avisa una vez al alcanzar este hito para la métrica y cuenta elegidas.';
});

/** @param {GoalView} goal */
function ruleDescription(goal) {
    const threshold = numbers.format(goal.threshold);
    if (goal.kind === 'drop') return `Caída superior al ${threshold}% · ${goal.metric ? props.metrics[goal.metric] : 'Métrica no disponible'}`;
    if (goal.kind === 'token_expiry') return `Avisar dentro de ${threshold} días`;
    return `Hito de ${threshold} · ${goal.metric ? props.metrics[goal.metric] : ''}`;
}

watch(
    () => props.brand?.id,
    (id) => {
        selectedBrand.value = id ?? null;
    },
);
watch(
    () => form.kind,
    (kind) => {
        form.metric = kind === 'token_expiry' ? null : (form.metric ?? 'followers');
    },
    { flush: 'sync' },
);

function reset() {
    editing.value = null;
    form.defaults(defaults());
    form.reset();
    form.clearErrors();
}

/** @param {GoalView} goal */
async function edit(goal) {
    if (busy.value) return;
    if (form.isDirty && !(await ask('¿Descartar los cambios pendientes del formulario?'))) return;
    const data = {
        name: goal.name,
        kind: goal.kind,
        metric: goal.metric,
        threshold: goal.threshold,
        social_account_id: goal.social_account_id,
        enabled: goal.enabled,
        mail_enabled: goal.mail_enabled,
    };
    form.defaults(data);
    form.reset();
    form.clearErrors();
    editing.value = goal.id;
    await nextTick();
    nameInput.value?.focus();
}

function submit() {
    if (busy.value || !props.brand) return;
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value !== null) form.put(route('goals.update', editing.value), options);
    else form.post(route('brands.goals.store', props.brand.id), options);
}

async function changeBrand() {
    if (busy.value || selectedBrand.value === props.brand?.id) return;
    if (form.isDirty && !(await ask('¿Descartar los cambios pendientes y cambiar de marca?'))) {
        selectedBrand.value = props.brand?.id ?? null;
        return;
    }
    router.get(
        route('goals.index'),
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

/** @param {GoalView} goal */
async function remove(goal) {
    if (
        busy.value ||
        !(await ask({
            title: 'Eliminar objetivo',
            message: `¿Eliminar «${goal.name}»?`,
            confirmLabel: 'Eliminar objetivo',
        }))
    )
        return;
    router.delete(route('goals.destroy', goal.id), {
        preserveScroll: true,
        onStart: () => {
            deleting.value = goal.id;
        },
        onFinish: () => {
            deleting.value = null;
        },
        onSuccess: () => {
            if (editing.value === goal.id) reset();
        },
    });
}
</script>

<template>
    <Head title="Objetivos y alertas" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-foreground-strong">Objetivos y alertas</h1></template>
        <div class="space-y-6">
            <div v-if="brands.length" class="max-w-md space-y-1">
                <label for="goals-brand" class="block text-sm font-medium text-foreground">Marca</label>
                <select
                    id="goals-brand"
                    v-model.number="selectedBrand"
                    class="field w-full"
                    :disabled="busy"
                    @change="changeBrand"
                >
                    <option v-for="option in brands" :key="option.id" :value="option.id">{{ option.name }}</option>
                </select>
            </div>
            <p v-if="!brand" class="rounded-lg border border-border bg-surface p-4 text-sm text-foreground-secondary"
                >Crea una marca para configurar objetivos.
                <Link :href="route('brands.create')" class="link">Crear marca</Link></p
            >
            <template v-else>
                <p class="text-sm text-foreground-muted"
                    >Evaluación diaria con la última fecha cerrada en la marca ({{ brand.timezone || 'UTC' }}) y sus
                    redes. Sin datos suficientes no se genera una alerta.</p
                >
                <form
                    class="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <h2
                        class="text-lg font-semibold text-foreground-strong sm:col-span-2"
                        >{{ editing === null ? 'Nuevo objetivo' : 'Editar objetivo' }}</h2
                    >
                    <div class="min-w-0 space-y-1 sm:col-span-2">
                        <label for="goal-name" class="block text-sm font-medium text-foreground"
                            >Nombre del objetivo</label
                        >
                        <input
                            id="goal-name"
                            ref="nameInput"
                            v-model="form.name"
                            :aria-invalid="form.errors.name ? 'true' : undefined"
                            :aria-describedby="form.errors.name ? 'goal-error-name' : undefined"
                            class="field w-full"
                            maxlength="120"
                            required
                            :disabled="busy"
                        />
                    </div>
                    <div class="min-w-0 space-y-1">
                        <label for="goal-kind" class="block text-sm font-medium text-foreground"
                            >Tipo de objetivo</label
                        >
                        <select
                            id="goal-kind"
                            v-model="form.kind"
                            :aria-invalid="form.errors.kind ? 'true' : undefined"
                            :aria-describedby="form.errors.kind ? 'goal-error-kind' : undefined"
                            class="field w-full"
                            required
                            :disabled="busy"
                        >
                            <option v-for="(label, value) in kinds" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div v-if="form.kind !== 'token_expiry'" class="min-w-0 space-y-1">
                        <label for="goal-metric" class="block text-sm font-medium text-foreground">Métrica</label>
                        <select
                            id="goal-metric"
                            v-model="form.metric"
                            :aria-invalid="form.errors.metric ? 'true' : undefined"
                            :aria-describedby="form.errors.metric ? 'goal-error-metric' : undefined"
                            class="field w-full"
                            required
                            :disabled="busy"
                        >
                            <option v-for="(label, value) in metrics" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <label for="goal-account" class="block text-sm font-medium text-foreground">Cuenta</label>
                        <select
                            id="goal-account"
                            v-model.number="form.social_account_id"
                            :aria-invalid="form.errors.social_account_id ? 'true' : undefined"
                            :aria-describedby="form.errors.social_account_id ? 'goal-error-social_account_id' : undefined"
                            class="field w-full"
                            :required="form.kind === 'token_expiry'"
                            :disabled="busy"
                        >
                            <option
                                :value="null"
                                >{{ form.kind === 'token_expiry' ? 'Elige una cuenta' : 'Toda la marca' }}</option
                            >
                            <option v-if="unavailableAccount" :value="form.social_account_id"
                                >Cuenta no disponible:
                                {{ unavailableAccount.account_name ?? 'Referencia conservada' }}</option
                            >
                            <option v-for="account in accounts" :key="account.id" :value="account.id"
                                >{{ account.network_label }}: {{ account.name }}</option
                            >
                        </select>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <label
                            for="goal-threshold"
                            class="block text-sm font-medium text-foreground"
                            >{{ form.kind === 'token_expiry' ? 'Umbral (días)' : 'Umbral' }}</label
                        >
                        <input
                            id="goal-threshold"
                            v-model.number="form.threshold"
                            :aria-invalid="form.errors.threshold ? 'true' : undefined"
                            :aria-describedby="form.errors.threshold ? 'goal-error-threshold' : undefined"
                            type="number"
                            class="field w-full"
                            :min="minimum"
                            :max="limits.max[form.kind]"
                            :step="integer ? 1 : 'any'"
                            required
                            :disabled="busy"
                        />
                    </div>
                    <p class="text-sm text-foreground-muted sm:col-span-2">{{ ruleHint }}</p>
                    <label class="flex items-center gap-2 text-sm text-foreground"
                        ><input
                            v-model="form.enabled"
                            :aria-invalid="form.errors.enabled ? 'true' : undefined"
                            :aria-describedby="form.errors.enabled ? 'goal-error-enabled' : undefined"
                            type="checkbox"
                            :disabled="busy"
                            class="rounded border-control-border"
                        />
                        Activo</label
                    >
                    <label class="flex items-center gap-2 text-sm text-foreground"
                        ><input
                            v-model="form.mail_enabled"
                            :aria-invalid="form.errors.mail_enabled ? 'true' : undefined"
                            :aria-describedby="form.errors.mail_enabled ? 'goal-error-mail_enabled' : undefined"
                            type="checkbox"
                            :disabled="busy"
                            class="rounded border-control-border"
                        />
                        Correo opcional</label
                    >
                    <p class="text-xs text-foreground-muted sm:col-span-2"
                        >El correo requiere también la preferencia de avisos por correo y una dirección verificada.</p
                    >
                    <div v-if="errors.length" role="alert" class="text-sm text-danger sm:col-span-2"
                        ><p
                            v-for="[field, error] in errors"
                            :id="'goal-error-' + field"
                            :key="field"
                            >{{ error }}</p
                        ></div
                    >
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="busy"
                            >{{ form.processing ? 'Guardando…' : editing === null ? 'Crear objetivo' : 'Guardar cambios' }}</button
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
                <section class="space-y-3" aria-labelledby="goals-list-title">
                    <h2 id="goals-list-title" class="text-lg font-semibold text-foreground-strong"
                        >Objetivos de la marca</h2
                    >
                    <p v-if="!goals.length" class="text-sm text-foreground-muted">No hay objetivos en esta página.</p>
                    <article
                        v-for="goal in goals"
                        :key="goal.id"
                        :aria-labelledby="'goal-title-' + goal.id"
                        class="space-y-2 rounded-lg border border-border bg-surface p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3
                                    :id="'goal-title-' + goal.id"
                                    class="break-words font-semibold text-foreground-strong"
                                    >{{ goal.name }}</h3
                                >
                                <p class="text-sm text-foreground-muted"
                                    >{{ kinds[goal.kind] }} · {{ goal.enabled ? 'Activo' : 'Pausado' }} ·
                                    {{ goal.social_account_id === null ? 'Toda la marca' : goal.account_name ?? 'Cuenta no disponible' }}</p
                                >
                            </div>
                            <div class="flex gap-2"
                                ><button
                                    type="button"
                                    class="btn-secondary"
                                    :disabled="busy"
                                    :aria-label="`Editar «${goal.name}»`"
                                    @click="edit(goal)"
                                    >Editar</button
                                ><button
                                    type="button"
                                    class="btn-secondary"
                                    :disabled="busy"
                                    :aria-label="`Eliminar «${goal.name}»`"
                                    @click="remove(goal)"
                                    >Eliminar</button
                                ></div
                            >
                        </div>
                        <p class="text-sm text-foreground-secondary">{{ ruleDescription(goal) }}</p>
                        <p v-if="goal.account_requires_reconnection" class="text-sm text-warning-ink"
                            >Cuenta pendiente de reconexión; consulta Conexiones.</p
                        >
                        <p v-if="!goal.last_evaluated_at" class="text-sm text-foreground-muted">Aún sin evaluar.</p>
                        <p v-else-if="goal.last_value === null" class="text-sm text-warning-ink"
                            >Sin datos suficientes · periodo {{ goal.last_period }}.</p
                        >
                        <p
                            v-else-if="goal.kind === 'token_expiry'"
                            class="text-sm text-foreground-secondary"
                            >{{ tokenObservation(goal) }}</p
                        >
                        <p v-else class="text-sm text-foreground-secondary"
                            >Último valor:
                            {{ numbers.format(goal.last_value)
                            }}
                            · periodo {{ goal.last_period }}.</p
                        >
                    </article>
                    <Pagination
                        :page="pagination"
                        route-name="goals.index"
                        :params="{ brand_id: brand.id }"
                        label="Paginación de objetivos"
                    />
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
