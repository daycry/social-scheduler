<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import { firstGroupError, focusFirstFormError } from '@/lib/form-errors';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

// Consentimiento OAuth del MCP (ADR-004). Con `error` es la pantalla de error propia: nunca redirige al cliente.
const props = defineProps({
    client: { type: Object, default: null },
    redirectHost: { type: String, default: '' },
    params: { type: Object, default: () => ({}) },
    abilities: { type: Object, default: () => ({}) },
    presets: { type: Object, default: () => ({}) },
    defaultPreset: { type: String, default: 'read' },
    tokenDayOptions: { type: Array, default: () => [30] },
    defaultTokenDays: { type: Number, default: 30 },
    error: { type: Object, default: null },
});

const form = useForm({
    ...props.params,
    decision: 'approve',
    token_days: props.defaultTokenDays,
    abilities: [...(props.presets[props.defaultPreset]?.abilities ?? [])],
});

const applyPreset = (preset) => {
    form.abilities = [...preset.abilities];
};
const presetActive = (preset) =>
    form.abilities.length === preset.abilities.length && preset.abilities.every((a) => form.abilities.includes(a));

const heading = ref(null);
const formElement = ref(null);
onMounted(() => nextTick(() => heading.value?.focus()));

const durationLabel = (days) => (days === 365 ? '1 año' : days === 180 ? '6 meses' : `${days} días`);

const noneSelected = computed(() => form.abilities.length === 0);
const abilitiesError = computed(() => firstGroupError(form.errors, 'abilities'));

// Aprobar o denegar viaja por POST (con CSRF); la respuesta es una redirección de ventana completa al cliente.
const submit = (decision) => {
    form.decision = decision;
    if (decision === 'deny') {
        form.abilities = [];
    }
    form.post(route('mcp.oauth.authorize.decide'), { onError: () => focusFirstFormError(formElement.value) });
};
</script>

<template>
    <Head title="Autorizar acceso" />

    <GuestLayout>
        <div v-if="error" role="alert">
            <h1 ref="heading" tabindex="-1" class="text-xl font-bold text-foreground-strong focus:outline-none"
                >No se puede autorizar esta conexión</h1
            >
            <p class="mt-3 text-sm text-foreground-muted">{{ error.message }}</p>
            <p class="mt-3 text-sm text-foreground-muted"
                >No se ha enviado nada a la aplicación que lo solicitó. Vuelve a ella e inténtalo de nuevo.</p
            >
        </div>

        <form v-else ref="formElement" class="space-y-6" @submit.prevent="submit('approve')">
            <div>
                <h1 ref="heading" tabindex="-1" class="text-xl font-bold text-foreground-strong focus:outline-none"
                    >Autorizar acceso al agente</h1
                >
                <p class="mt-3 break-words text-sm text-foreground-muted">
                    Una aplicación se presenta como
                    <strong class="break-all text-foreground-strong">«{{ client.name }}»</strong>
                    <span class="text-foreground-subtle">
                        (nombre declarado por la propia aplicación, no verificado)</span
                    >
                    y pide acceso a tu cuenta de Social Scheduler mediante el MCP.
                </p>
                <p class="mt-2 break-words text-sm text-foreground-muted">
                    Al terminar, volverás a
                    <strong class="break-all text-foreground-strong">{{ redirectHost }}</strong>. Autoriza solo si has
                    iniciado tú esta conexión.
                </p>
            </div>

            <FormField :error="abilitiesError" error-class="mt-2" v-slot="{ group }">
                <fieldset v-bind="group">
                    <legend class="text-sm font-semibold text-foreground-strong">Permisos</legend>
                    <p class="mt-1 text-xs text-foreground-subtle"
                        >Empieza solo con lectura. Puedes cambiar las casillas; lo puedes revocar en «API keys».</p
                    >

                    <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Conjuntos de permisos">
                        <button
                            v-for="(preset, key) in presets"
                            :key="key"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition"
                            :class="presetActive(preset) ? 'border-accent-fill bg-accent-soft text-accent-strong' : 'border-control-border text-foreground-muted hover:bg-canvas'"
                            :aria-pressed="presetActive(preset)"
                            @click="applyPreset(preset)"
                        >
                            {{ preset.label }}
                        </button>
                    </div>

                    <ul class="mt-3 space-y-2">
                        <li v-for="(description, ability) in abilities" :key="ability">
                            <label class="flex items-start gap-2 text-sm text-foreground-secondary">
                                <Checkbox v-model:checked="form.abilities" :value="ability" class="mt-0.5 shrink-0" />
                                <span class="min-w-0 break-words"
                                    >{{ description }}
                                    <code class="text-xs text-foreground-muted">{{ ability }}</code></span
                                >
                            </label>
                        </li>
                    </ul>
                </fieldset>
            </FormField>

            <div>
                <FormField
                    id="token-days"
                    described-by="token-days-help"
                    error-class="mt-2"
                    :error="form.errors.token_days"
                    v-slot="{ control }"
                >
                    <label for="token-days" class="block text-sm font-semibold text-foreground-strong"
                        >Duración del acceso</label
                    >
                    <select
                        v-bind="control"
                        v-model.number="form.token_days"
                        class="mt-2 block w-full max-w-full rounded-md border-control-border text-sm shadow-sm focus:border-focus focus:ring-focus"
                    >
                        <option
                            v-for="days in tokenDayOptions"
                            :key="days"
                            :value="days"
                            >{{ durationLabel(days) }}</option
                        >
                    </select>
                    <p id="token-days-help" class="mt-1 text-xs text-foreground-subtle"
                        >Pasado ese tiempo el acceso caduca y la aplicación tendrá que pedirlo de nuevo. Puedes
                        revocarlo antes en «API keys».</p
                    >
                </FormField>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <SecondaryButton type="button" :disabled="form.processing" @click="submit('deny')"
                    >Denegar</SecondaryButton
                >
                <PrimaryButton type="submit" :disabled="form.processing || noneSelected">Autorizar</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
