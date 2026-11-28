<script setup>
import FormField from '@/Components/FormField.vue';
import { firstGroupError, focusFirstFormError } from '@/lib/form-errors';
import { createDateFormatter } from '@/lib/format';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { onTabsKeydown, useTabs } from '@/Composables/useTabs';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { mcpClients, testConnection } from './mcpClients';

import { useConfirm } from '@/Composables/useConfirm';

const ask = useConfirm();

const props = defineProps({
    keys: Array,
    abilities: Object,
    presets: Object,
    endpoint: String,
    newKey: { type: String, default: null },
});

const form = useForm({
    name: '',
    abilities: Object.keys(props.abilities),
    expires_at: '',
});

const formElement = ref(null);
const abilitiesError = computed(() => firstGroupError(form.errors, 'abilities'));

const create = () => form.post(route('api-keys.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset('name', 'expires_at'),
    onError: () => focusFirstFormError(formElement.value),
});

/** «Manual» (creada aquí) u «OAuth · <cliente>» (emitida al autorizar un cliente MCP). */
const originLabel = (key) => (key.origin === 'oauth' ? `OAuth · ${key.oauth_client ?? 'cliente'}` : 'Manual');

const revoke = async (key) => {
    if (await ask({ message: `¿Revocar la API key «${key.name}»? El agente que la use dejará de funcionar.`, confirmLabel: 'Revocar API key' })) {
        router.delete(route('api-keys.destroy', key.id), {
            preserveScroll: true,
            // Revocar la recién creada (texto plano «<id>|…») la retira también del aviso y de las plantillas.
            onSuccess: () => {
                if (shownKey.value?.startsWith(`${key.id}|`)) {
                    shownKey.value = null;
                    probe.value = { running: false, result: null };
                }
            },
        });
    }
};

// Atajo de permisos: deja marcado exactamente el conjunto (las casillas siguen editables).
const applyPreset = (preset) => {
    form.abilities = [...preset.abilities];
};
const presetActive = (preset) =>
    form.abilities.length === preset.abilities.length && preset.abilities.every((a) => form.abilities.includes(a));

// La key recién creada vive solo en memoria de esta vista: se borra del estado de página que Inertia guarda en
// `history.state`, para que volver atrás o restaurar la pestaña no la muestre de nuevo. Tras la visita (en el propio
// montaje Inertia escribe después ese estado y lo pisaría).
const shownKey = ref(null);
const takeKey = (key) => {
    if (key) {
        shownKey.value = key;
        probe.value = { running: false, result: null };
        setTimeout(() => router.replaceProp('newKey', null));
    }
};
// Llega al montar (carga directa) o como cambio de prop (tras crearla, Inertia reutiliza la vista).
watch(() => props.newKey, takeKey);
onMounted(() => takeKey(props.newKey));

// Instalación por cliente: con la key recién creada las plantillas ya la llevan; si no, un marcador.
const clients = computed(() => mcpClients(props.endpoint, shownKey.value));
const activeClient = ref('claude-code');
const { tabProps, panelProps } = useTabs(activeClient, { sharedPanel: true });
const client = computed(() => clients.value.find((c) => c.id === activeClient.value));

const copied = ref(null);
const copyFailed = ref(false);
const copy = async (code, idx) => {
    try {
        await navigator.clipboard.writeText(code);
        copyFailed.value = false;
        copied.value = `${activeClient.value}-${idx}`;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        // Sin Clipboard API (origen http no seguro) o permiso denegado: se dice, en vez de no hacer nada.
        copied.value = null;
        copyFailed.value = true;
    }
};

const probe = ref({ running: false, result: null });
const runProbe = async () => {
    probe.value = { running: true, result: null };
    probe.value = { running: false, result: await testConnection(props.endpoint, shownKey.value) };
};

const page = usePage();
const fmt = computed(() => createDateFormatter(page.props.shell?.currentBrand?.timezone, { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }, 'nunca'));
</script>

<template>
    <Head title="API keys" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">API keys (MCP)</h1>
        </template>

        <div>
            <div class="max-w-4xl space-y-5">
                <div
                    v-if="shownKey"
                    class="rounded-lg border border-warning-panel-border border-l-4 border-l-warning-outline bg-warning-soft p-4"
                    role="alert"
                >
                    <p class="font-medium text-warning-ink">Copia ahora tu API key: no volverás a verla.</p>
                    <code
                        class="mt-2 block break-all rounded bg-surface p-2 text-sm"
                        data-testid="new-key"
                        >{{ shownKey }}</code
                    >
                </div>

                <section class="card p-4 sm:p-8" aria-labelledby="instalar-titulo">
                    <h2 id="instalar-titulo" class="text-lg font-medium text-foreground-strong"
                        >Conectar un agente de IA</h2
                    >
                    <p class="mt-1 text-sm text-foreground-muted">
                        Servidor MCP por HTTP en
                        <code class="break-all rounded bg-surface-hover px-1">{{ endpoint }}</code>. El agente solo ve y
                        modifica tus datos, con los permisos de la key.
                        <template v-if="!shownKey"
                            >Crea una API key abajo y estas instrucciones la incluirán ya rellena.</template
                        >
                    </p>

                    <div
                        class="mt-4 flex flex-wrap gap-1 border-b border-border"
                        role="tablist"
                        aria-label="Cliente"
                        @keydown="onTabsKeydown"
                    >
                        <button
                            v-for="c in clients"
                            :key="c.id"
                            type="button"
                            v-bind="tabProps(c.id)"
                            class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                            :class="activeClient === c.id ? 'border-accent-fill text-accent-strong' : 'border-transparent text-foreground-muted hover:text-foreground-strong'"
                            @click="activeClient = c.id"
                        >
                            {{ c.label }}
                        </button>
                    </div>

                    <div v-bind="panelProps(client.id)" class="mt-4 space-y-4" data-testid="mcp-install">
                        <p class="text-sm text-foreground-secondary">{{ client.intro }}</p>
                        <div v-for="(step, idx) in client.steps" :key="`${client.id}-${idx}`">
                            <div class="flex items-center justify-between gap-3">
                                <p
                                    class="min-w-0 break-words text-xs font-medium text-foreground-subtle"
                                    >{{ step.label }}</p
                                >
                                <button type="button" class="link shrink-0 text-xs" @click="copy(step.code, idx)">
                                    {{ copied === `${client.id}-${idx}` ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                            <pre
                                tabindex="0"
                                :aria-label="step.label"
                                class="mt-1 overflow-x-auto rounded-lg bg-chrome p-3 text-xs text-chrome-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"
                            ><code>{{ step.code }}</code></pre>
                        </div>
                        <p
                            v-for="note in client.notes"
                            :key="note"
                            class="text-xs text-foreground-subtle"
                            >{{ note }}</p
                        >
                        <p v-if="copyFailed" class="text-xs text-danger" role="status">
                            Tu navegador no permite copiar desde aquí: selecciona el texto del bloque y pulsa Ctrl+C.
                        </p>
                    </div>

                    <div v-if="shownKey" class="mt-5 flex flex-wrap items-center gap-3 border-t border-border pt-4">
                        <SecondaryButton type="button" :disabled="probe.running" @click="runProbe">
                            {{ probe.running ? 'Probando…' : 'Probar conexión' }}
                        </SecondaryButton>
                        <p
                            v-if="probe.result"
                            class="text-sm"
                            :class="probe.result.ok ? 'text-success-ink' : 'text-danger'"
                            role="status"
                            data-testid="mcp-probe"
                        >
                            {{ probe.result.ok ? `Conexión correcta: el servidor ofrece ${probe.result.tools} herramientas (los permisos de la key se aplican al usar cada una).` : probe.result.message }}
                        </p>
                    </div>
                </section>

                <section class="card p-4 sm:p-8">
                    <h2 class="text-lg font-medium text-foreground-strong">Nueva API key</h2>
                    <form ref="formElement" class="mt-4 max-w-xl space-y-4" @submit.prevent="create">
                        <div>
                            <FormField id="name" :error="form.errors.name" error-class="mt-2" v-slot="{ control }">
                                <InputLabel for="name" value="Nombre (p. ej. Claude Desktop)" />
                                <TextInput v-bind="control" v-model="form.name" class="mt-1 block w-full" required />
                            </FormField>
                        </div>
                        <FormField :error="abilitiesError" error-class="mt-2" v-slot="{ group }">
                            <fieldset v-bind="group">
                                <legend class="text-sm font-medium text-foreground-secondary">Permisos</legend>
                                <div class="mt-2 flex flex-wrap gap-2" role="group" aria-label="Permisos predefinidos">
                                    <button
                                        v-for="(preset, id) in presets"
                                        :key="id"
                                        type="button"
                                        class="rounded-full border px-3 py-1 text-xs font-medium"
                                        :class="presetActive(preset) ? 'border-accent-fill bg-accent-fill text-on-accent' : 'border-control-border bg-surface text-foreground-secondary hover:bg-canvas'"
                                        :aria-pressed="presetActive(preset)"
                                        @click="applyPreset(preset)"
                                    >
                                        {{ preset.label }}
                                    </button>
                                </div>
                                <label
                                    v-for="(label, ability) in abilities"
                                    :key="ability"
                                    class="mt-2 flex items-start gap-2 text-sm text-foreground-secondary"
                                >
                                    <input
                                        v-model="form.abilities"
                                        type="checkbox"
                                        :value="ability"
                                        class="mt-0.5 rounded border-control-border"
                                    />
                                    <span
                                        ><code>{{ ability }}</code> - {{ label }}</span
                                    >
                                </label>
                            </fieldset>
                        </FormField>
                        <div>
                            <FormField
                                id="expires_at"
                                :error="form.errors.expires_at"
                                error-class="mt-2"
                                v-slot="{ control }"
                            >
                                <InputLabel for="expires_at" value="Caduca el (opcional; por defecto 1 año)" />
                                <TextInput v-bind="control" v-model="form.expires_at" type="date" class="mt-1 block" />
                            </FormField>
                        </div>
                        <PrimaryButton :disabled="form.processing">Crear API key</PrimaryButton>
                    </form>
                </section>

                <section class="card p-4 sm:p-8">
                    <h2 class="text-lg font-medium text-foreground-strong">Tus API keys</h2>
                    <p v-if="keys.length === 0" class="mt-2 text-sm text-foreground-muted">Aún no tienes ninguna.</p>
                    <ul v-else class="mt-2 divide-y divide-border">
                        <li
                            v-for="key in keys"
                            :key="key.id"
                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                            :data-testid="`key-${key.id}`"
                        >
                            <div class="min-w-0">
                                <p class="break-words font-medium text-foreground-strong">{{ key.name }}</p>
                                <p class="mt-1">
                                    <span
                                        class="inline-block max-w-full break-words rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="key.origin === 'oauth' ? 'bg-accent-soft text-accent-strong' : 'bg-surface-hover text-foreground-secondary'"
                                        data-testid="key-origin"
                                        >Origen: {{ originLabel(key) }}</span
                                    >
                                </p>
                                <p class="mt-1 break-words text-xs text-foreground-subtle">
                                    {{ (key.abilities || []).join(', ') }} · último uso: {{ fmt(key.last_used_at) }} ·
                                    caduca: {{ fmt(key.expires_at) }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="self-start text-sm font-medium text-danger hover:underline sm:self-center"
                                :aria-label="`Revocar ${key.name}`"
                                @click="revoke(key)"
                                >Revocar</button
                            >
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
