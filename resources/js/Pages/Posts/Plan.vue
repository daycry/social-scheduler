<script setup>
import AiNotConfigured from '@/Components/AiNotConfigured.vue';
import FeatureEmpty from '@/Components/FeatureEmpty.vue';
import InfoNote from '@/Components/InfoNote.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { countHashtags, formatThousands } from '@/Composables/textCount';
import { countText } from '@/Composables/textLength';
import { useAiStatus } from '@/Composables/useAiStatus';
import { useConfirm } from '@/Composables/useConfirm';
import { newIdempotencyKey } from '@/Composables/useWorkflow';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { HttpError, requestJson } from '@/lib/http';
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';

/**
 * Plan de contenido en lenguaje natural (T-93): un briefing («la semana que viene, 3 posts sobre el lanzamiento de X por la mañana») y la IA
 * propone un lote de publicaciones de solo texto con fecha. NADA se crea al proponer: el usuario revisa cada una (fecha, cuentas, texto), la
 * edita, la desmarca y solo al confirmar el servidor revalida todo y crea las elegidas (borradores o programadas) en una transacción.
 * La confirmación lleva una clave de idempotencia por propuesta: un doble clic no crea el lote dos veces.
 */
const props = defineProps({
    brand: Object,
    accounts: Array,
    excluded: Array,
    maxItems: Number,
    maxBriefing: Number,
    maxText: Number,
});

const { enabled, loaded, remaining, call } = useAiStatus();
const ask = useConfirm();

const briefingId = useId();
const hintId = useId();
const briefing = ref('');
const loading = ref(false);
const error = ref('');
const items = ref(null);
const notices = ref([]);
const mode = ref('draft');
const confirming = ref(false);
const confirmError = ref('');
const created = ref(null);
const createdTitle = ref(null);
// Una confirmación anterior con esta clave ya creó el plan o sigue en curso: reconfirmar duplicaría el lote.
const alreadyUsed = ref(false);
let idempotencyKey = '';

const canPropose = computed(() => enabled.value && briefing.value.trim() !== '' && !loading.value && props.accounts.length > 0);
const accountById = computed(() => new Map(props.accounts.map((a) => [a.id, a])));

const propose = async () => {
    if (unresolved.value) {
        // Proponer de nuevo genera una clave nueva: si la confirmación pendiente sí llegó, el plan se crearía dos veces.
        const proceed = await ask({
            title: 'Hay una confirmación sin resolver',
            message: 'No sabemos si tu confirmación anterior creó el plan. Si sigues se olvida y, si ya se había creado, el nuevo plan podría duplicar sus publicaciones. Revisa antes el calendario.',
            confirmLabel: 'Olvidarla y proponer',
            cancelLabel: 'Volver',
        });
        if (!proceed) return;
        dismissUnresolved();
    }
    error.value = '';
    confirmError.value = '';
    created.value = null;
    alreadyUsed.value = false;
    items.value = null;
    loading.value = true;
    try {
        const data = await call(
            'ai.plan',
            { brand_id: props.brand.id, briefing: briefing.value },
            { errorMessage: (data) => data?.message ?? 'No se pudo preparar el plan.' },
        );
        items.value = (data?.items ?? []).map((item) => ({
            ...item,
            include: true,
            errors: [],
        }));
        notices.value = data?.notices ?? [];
        idempotencyKey = newIdempotencyKey();
    } catch (failure) {
        error.value = failure instanceof HttpError ? failure.message : 'No se pudo contactar con el asistente.';
    } finally {
        loading.value = false;
    }
};

// Contador y avisos de cada cuenta marcada, con la regla de su red (caracteres según su modo de conteo, bytes y hashtags): el mismo
// criterio que el servidor aplica al confirmar, recalculado en cuanto cambian el texto o las cuentas.
const countersOf = (item) =>
    item.account_ids.flatMap((id) => {
        const account = accountById.value.get(id);
        if (!account) return [];
        const limit = account.limit ?? {};
        const chars = countText(item.text, limit.count, limit.url_length);
        const bytes = new TextEncoder().encode(item.text.trim()).length;
        const hashtags = countHashtags(item.text);
        const problems = [];
        if (limit.max != null && chars > limit.max) problems.push(`${formatThousands(chars)} caracteres: admite como máximo ${formatThousands(limit.max)}.`);
        if (limit.max_bytes != null && bytes > limit.max_bytes) problems.push(`${formatThousands(bytes)} bytes: admite como máximo ${formatThousands(limit.max_bytes)}.`);
        if (limit.max_hashtags != null && hashtags > limit.max_hashtags) problems.push(`${hashtags} hashtags: admite como máximo ${limit.max_hashtags}.`);
        return [{ account_id: id, network: account.network, label: `${account.network_label} · ${account.name}`, chars, max: limit.max ?? null, problems }];
    });
const rows = computed(() =>
    (items.value ?? []).map((item) => {
        const counters = countersOf(item);
        return { item, counters, problems: counters.flatMap((c) => c.problems.map((message) => ({ account_id: c.account_id, label: c.label, message }))) };
    }),
);
const selected = computed(() => rows.value.filter((r) => r.item.include));
const blocked = computed(() => selected.value.some((r) => r.problems.length > 0 || r.item.account_ids.length === 0 || r.item.text.trim() === ''));
const canConfirm = computed(() => !confirming.value && selected.value.length > 0 && !blocked.value);

const toggleAccount = (item, id, on) => {
    item.account_ids = on ? [...item.account_ids, id] : item.account_ids.filter((x) => x !== id);
};

const pendingStore = `content-plan-pending:${props.brand?.id}`;
const readPending = () => {
    try {
        return JSON.parse(sessionStorage.getItem(pendingStore) ?? 'null');
    } catch {
        return null;
    }
};
const writePending = (value) => {
    try {
        if (value) sessionStorage.setItem(pendingStore, JSON.stringify(value));
        else sessionStorage.removeItem(pendingStore);
    } catch {
        // Sin almacenamiento de sesión la protección dura solo mientras la página siga abierta.
    }
};

// Confirmación sin resolver: la petición se envió pero no llegó respuesta (error de red). El plan quizá ya se creó, así que se conservan su
// clave y su cuerpo (también en `sessionStorage`, para que recargar no pierda la protección): reintentarla es seguro (idempotente).
const unresolved = ref(readPending());
if (unresolved.value) idempotencyKey = unresolved.value.key;

const NETWORK_UNKNOWN =
    'No sabemos si el plan se ha creado: reintenta «Confirmar» sin cambios (es seguro) o revisa el calendario antes de volver a proponer.';

/** Envía el lote. Con respuesta (éxito o error HTTP) la confirmación queda resuelta; sin respuesta queda sin resolver. */
const send = async (key, body, chosen = []) => {
    confirming.value = true;
    confirmError.value = '';
    writePending({ key, body });
    try {
        const data = await requestJson('POST', route('content-plan.store'), {
            json: { ...body, idempotency_key: key },
            errorMessage: (data) => data?.message ?? 'No se pudo crear el plan.',
        });
        writePending(null);
        unresolved.value = null;
        created.value = { count: data.created, mode: data.mode };
        items.value = null;
        briefing.value = '';
        idempotencyKey = newIdempotencyKey();
        // El botón que se pulsó desaparece: el foco pasa al título del resultado.
        await nextTick();
        createdTitle.value?.focus();
    } catch (failure) {
        if (!(failure instanceof HttpError)) {
            unresolved.value = { key, body };
            confirmError.value = NETWORK_UNKNOWN;
            return;
        }
        writePending(null);
        unresolved.value = null;
        if (failure.status === 422 && failure.errors) {
            // Los errores llegan por posición dentro de lo ENVIADO (`items.N`): se devuelven a su elemento.
            Object.entries(failure.errors).forEach(([name, messages]) => {
                const index = Number(name.match(/^items\.(\d+)/)?.[1]);
                if (chosen[index]) chosen[index].errors = [].concat(messages);
            });
        }
        if (failure.status === 409 || (failure.status === 422 && failure.data?.already_used)) {
            // La clave está reservada por una confirmación que ya creó el plan o sigue en curso: NO se renueva ni se invita a reconfirmar.
            alreadyUsed.value = true;
            items.value = null;
            await nextTick();
            createdTitle.value?.focus();
        } else {
            // Error de validación: no se creó nada y la clave quedó libre; se renueva para corregir y confirmar de nuevo.
            if (failure.status === 422) idempotencyKey = newIdempotencyKey();
            confirmError.value = failure.message;
        }
    } finally {
        confirming.value = false;
    }
};

const confirm = async () => {
    if (!canConfirm.value) return;
    items.value.forEach((i) => (i.errors = []));
    const chosen = selected.value.map((r) => r.item);
    await send(idempotencyKey, {
        brand_id: props.brand.id,
        mode: mode.value,
        items: chosen.map((i) => ({ scheduled_at: i.scheduled_at, account_ids: i.account_ids, text: i.text })),
    }, chosen);
};

const retryUnresolved = () => send(unresolved.value.key, unresolved.value.body);
const dismissUnresolved = () => {
    writePending(null);
    unresolved.value = null;
    confirmError.value = '';
};

const discard = () => {
    items.value = null;
    confirmError.value = '';
};
</script>

<template>
    <Head title="Plan de contenido" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Plan de contenido</h1>
        </template>

        <div class="space-y-5">
            <FeatureEmpty
                v-if="!brand"
                title="Plan de contenido"
                description="Crea una marca para planificar varias publicaciones a la vez con el asistente."
                :cards="[
                    { title: 'Escribe tu idea', text: 'Cuántas publicaciones, de qué y cuándo, con tus palabras.', kind: 'report' },
                    { title: 'Revisa y edita', text: 'Cada publicación con su fecha, cuentas y texto.', kind: 'calendar' },
                    { title: 'Confirma', text: 'Nada se crea hasta que lo confirmas.', kind: 'chart' },
                ]"
            />

            <template v-else>
                <section class="card space-y-3 p-5" aria-labelledby="plan-briefing-title" data-testid="plan-form">
                    <h2 id="plan-briefing-title" class="text-sm font-medium text-slate-700"
                        >Describe tu plan · {{ brand.name }}</h2
                    >

                    <AiNotConfigured
                        v-if="loaded && !enabled"
                        class="max-w-xl text-xs"
                        data-testid="plan-unconfigured"
                    />

                    <InfoNote v-if="accounts.length === 0" title="Sin cuentas para un plan de texto">
                        Esta marca no tiene cuentas activas que publiquen solo texto (Bluesky, Mastodon, Threads,
                        LinkedIn, Facebook o Telegram). Conecta una para usar el plan.
                    </InfoNote>

                    <label :for="briefingId" class="block text-sm font-medium text-slate-800">Briefing</label>
                    <textarea
                        :id="briefingId"
                        v-model="briefing"
                        rows="3"
                        :maxlength="maxBriefing"
                        class="field"
                        :disabled="!enabled || accounts.length === 0"
                        :aria-describedby="hintId"
                        placeholder="La semana que viene, 3 posts sobre el lanzamiento de X en Bluesky y Mastodon, por la mañana"
                        data-testid="plan-briefing"
                    ></textarea>
                    <p :id="hintId" class="text-xs text-slate-600">
                        Cuenta cuántas publicaciones, de qué y cuándo (hasta {{ maxItems }}). No incluyas datos
                        personales: se quitan los correos, teléfonos y enlaces antes de enviarlo. El briefing se envía
                        al proveedor de IA (Anthropic) para generar el plan.
                        <template v-if="enabled">Te quedan {{ remaining }} usos hoy.</template>
                    </p>
                    <p class="text-xs text-slate-600" data-testid="plan-accounts">
                        Se publicará en:
                        <span
                            v-for="(a, i) in accounts"
                            :key="a.id"
                            class="inline-flex items-center gap-1 align-middle"
                        >
                            <NetworkIcon :network="a.network" size="sm" /><span>{{ a.name }}</span
                            ><span v-if="i < accounts.length - 1">, </span>
                        </span>
                    </p>
                    <p v-if="excluded.length" class="text-xs text-slate-600" data-testid="plan-excluded">
                        Fuera del plan:
                        <template v-for="(a, i) in excluded" :key="a.id"
                            >{{ a.name }} ({{ a.reason }})<template v-if="i < excluded.length - 1"
                                >;
                            </template></template
                        >
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!canPropose"
                            :aria-busy="loading"
                            data-testid="plan-propose"
                            @click="propose"
                            >{{ loading ? 'Preparando el plan…' : 'Proponer plan' }}</button
                        >
                    </div>
                    <p v-if="error" class="text-sm text-rose-700" role="alert" data-testid="plan-error">{{ error }}</p>
                </section>

                <div role="status">
                    <section
                        v-if="alreadyUsed"
                        class="card space-y-2 p-5"
                        aria-labelledby="plan-created-title"
                        data-testid="plan-already"
                    >
                        <h2
                            id="plan-created-title"
                            ref="createdTitle"
                            tabindex="-1"
                            class="text-sm font-medium text-slate-700"
                        >
                            Este plan ya se ha creado o se está creando
                        </h2>
                        <p class="text-sm text-slate-600">
                            Tu confirmación anterior ya llegó al servidor: no la repitas para no duplicar las
                            publicaciones. Revisa el
                            <Link :href="route('calendar.index', { brand_id: brand.id })" class="underline"
                                >calendario</Link
                            >
                            y, si falta algo, propón el plan de nuevo.
                        </p>
                    </section>

                    <section
                        v-if="created"
                        class="card space-y-2 p-5"
                        aria-labelledby="plan-created-title"
                        data-testid="plan-created"
                    >
                        <h2
                            id="plan-created-title"
                            ref="createdTitle"
                            tabindex="-1"
                            class="text-sm font-medium text-slate-700"
                            >Plan creado</h2
                        >
                        <p class="text-sm text-emerald-800">
                            {{
                            created.mode === 'schedule'
                                ? `Se ${created.count === 1 ? 'ha' : 'han'} programado ${created.count} ${created.count === 1 ? 'publicación' : 'publicaciones'}.`
                                : `Se ${created.count === 1 ? 'ha' : 'han'} creado ${created.count} ${created.count === 1 ? 'borrador' : 'borradores'}.`
                            }}
                        </p>
                        <p class="text-sm text-slate-600">
                            Puedes revisarlos y editarlos en el
                            <Link :href="route('calendar.index', { brand_id: brand.id })" class="underline"
                                >calendario</Link
                            >.
                        </p>
                    </section>
                </div>

                <section
                    v-if="unresolved"
                    class="card space-y-2 p-5"
                    aria-labelledby="plan-unresolved-title"
                    data-testid="plan-unresolved"
                >
                    <h2 id="plan-unresolved-title" class="text-sm font-medium text-slate-700"
                        >Confirmación sin resolver</h2
                    >
                    <p class="text-sm text-slate-600">
                        {{ NETWORK_UNKNOWN }}
                        <Link :href="route('calendar.index', { brand_id: brand.id })" class="underline"
                            >Ir al calendario</Link
                        >
                    </p>
                    <div v-if="!items" class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" :disabled="confirming" @click="retryUnresolved"
                            >Reintentar la confirmación</button
                        >
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="confirming"
                            @click="dismissUnresolved"
                            >Ya lo he comprobado</button
                        >
                    </div>
                </section>

                <section v-if="items" class="space-y-3" aria-labelledby="plan-review-title" data-testid="plan-review">
                    <h2 id="plan-review-title" class="text-sm font-medium text-slate-700">Revisa el plan</h2>
                    <p class="text-sm text-slate-600">
                        Nada se ha creado todavía. Edita lo que quieras, desmarca lo que no te sirva y confirma.
                    </p>
                    <InfoNote v-for="notice in notices" :key="notice">{{ notice }}</InfoNote>

                    <ul class="space-y-3" aria-label="Publicaciones propuestas">
                        <li
                            v-for="(row, index) in rows"
                            :key="row.item.key"
                            class="card space-y-3 p-4"
                            data-testid="plan-item"
                        >
                            <label class="flex items-center gap-2 text-sm font-medium text-slate-900">
                                <input
                                    v-model="row.item.include"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    data-testid="plan-item-include"
                                />
                                Incluir la publicación {{ index + 1 }}
                            </label>

                            <div :class="{ 'opacity-60': !row.item.include }" class="space-y-3">
                                <label class="block">
                                    <span class="block text-xs font-medium text-slate-500"
                                        >Fecha y hora ({{ brand.timezone }})</span
                                    >
                                    <input
                                        v-model="row.item.scheduled_at"
                                        type="datetime-local"
                                        class="field"
                                        :disabled="!row.item.include"
                                        :aria-label="`Fecha y hora de la publicación ${index + 1}`"
                                        data-testid="plan-item-date"
                                    />
                                </label>

                                <fieldset :disabled="!row.item.include" class="min-w-0">
                                    <legend class="text-xs font-medium text-slate-500">Cuentas</legend>
                                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                        <label
                                            v-for="a in accounts"
                                            :key="a.id"
                                            class="flex items-center gap-1.5 text-sm text-slate-800"
                                        >
                                            <input
                                                type="checkbox"
                                                class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                :checked="row.item.account_ids.includes(a.id)"
                                                @change="toggleAccount(row.item, a.id, $event.target.checked)"
                                            />
                                            <NetworkIcon :network="a.network" size="sm" />
                                            <span class="break-words">{{ a.network_label }} · {{ a.name }}</span>
                                        </label>
                                    </div>
                                </fieldset>

                                <label class="block">
                                    <span class="block text-xs font-medium text-slate-500">Texto</span>
                                    <textarea
                                        v-model="row.item.text"
                                        rows="4"
                                        :maxlength="maxText"
                                        class="field"
                                        :disabled="!row.item.include"
                                        :aria-label="`Texto de la publicación ${index + 1}`"
                                        data-testid="plan-item-text"
                                    ></textarea>
                                    <span
                                        class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs"
                                        data-testid="plan-item-counters"
                                    >
                                        <span
                                            v-for="c in row.counters"
                                            :key="c.account_id"
                                            class="flex min-w-0 items-center gap-1.5"
                                        >
                                            <NetworkIcon :network="c.network" size="sm" />
                                            <span class="truncate text-slate-600">{{ c.label }}</span>
                                            <span
                                                class="rounded px-1.5 py-0.5 font-medium"
                                                :class="c.problems.length ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-700'"
                                                >{{ formatThousands(c.chars)
                                                }}<template v-if="c.max != null">
                                                    / {{ formatThousands(c.max) }}</template
                                                ></span
                                            >
                                        </span>
                                    </span>
                                </label>

                                <div v-if="row.item.include">
                                    <p
                                        v-for="p in row.problems"
                                        :key="p.account_id"
                                        class="text-xs text-amber-800"
                                        data-testid="plan-item-problem"
                                        >{{ p.label }}: {{ p.message }} Edita el texto para poder confirmarlo.</p
                                    >
                                    <p
                                        v-for="message in row.item.errors"
                                        :key="message"
                                        class="text-xs text-rose-700"
                                        data-testid="plan-item-error"
                                        >{{ message }}</p
                                    >
                                </div>
                            </div>
                        </li>
                    </ul>

                    <fieldset class="space-y-1">
                        <legend class="text-sm font-medium text-slate-800">Al confirmar</legend>
                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="mode"
                                type="radio"
                                value="draft"
                                class="border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            Guardar como borradores con su fecha
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="mode"
                                type="radio"
                                value="schedule"
                                class="border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            Programar para esa fecha
                        </label>
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!canConfirm"
                            :aria-busy="confirming"
                            data-testid="plan-confirm"
                            @click="confirm"
                            >{{
                                confirming
                                    ? 'Creando…'
                                    : `${mode === 'schedule' ? 'Programar' : 'Crear borradores de'} ${selected.length} ${selected.length === 1 ? 'publicación' : 'publicaciones'}`
                            }}</button
                        >
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="confirming"
                            data-testid="plan-discard"
                            @click="discard"
                            >Descartar el plan</button
                        >
                    </div>
                    <p v-if="blocked" class="text-xs text-slate-600">
                        Revisa las publicaciones marcadas: alguna no cabe en su red o le falta texto o cuentas.
                    </p>
                    <p
                        v-if="confirmError"
                        class="text-sm text-rose-700"
                        role="alert"
                        data-testid="plan-confirm-error"
                        >{{ confirmError }}</p
                    >
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
