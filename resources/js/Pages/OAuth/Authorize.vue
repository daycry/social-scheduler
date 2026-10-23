<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
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
onMounted(() => nextTick(() => heading.value?.focus()));

const durationLabel = (days) => (days === 365 ? '1 año' : days === 180 ? '6 meses' : `${days} días`);

const noneSelected = computed(() => form.abilities.length === 0);

// Aprobar o denegar viaja por POST (con CSRF); la respuesta es una redirección de ventana completa al cliente.
const submit = (decision) => {
    form.decision = decision;
    if (decision === 'deny') {
        form.abilities = [];
    }
    form.post(route('mcp.oauth.authorize.decide'));
};
</script>

<template>
    <Head title="Autorizar acceso" />

    <GuestLayout>
        <div v-if="error" role="alert">
            <h1 ref="heading" tabindex="-1" class="text-xl font-bold text-slate-900 focus:outline-none">No se puede autorizar esta conexión</h1>
            <p class="mt-3 text-sm text-slate-600">{{ error.message }}</p>
            <p class="mt-3 text-sm text-slate-600">No se ha enviado nada a la aplicación que lo solicitó. Vuelve a ella e inténtalo de nuevo.</p>
        </div>

        <form v-else class="space-y-6" @submit.prevent="submit('approve')">
            <div>
                <h1 ref="heading" tabindex="-1" class="text-xl font-bold text-slate-900 focus:outline-none">Autorizar acceso al agente</h1>
                <p class="mt-3 break-words text-sm text-slate-600">
                    Una aplicación se presenta como
                    <strong class="break-all text-slate-900">«{{ client.name }}»</strong>
                    <span class="text-slate-500"> (nombre declarado por la propia aplicación, no verificado)</span>
                    y pide acceso a tu cuenta de Social Scheduler mediante el MCP.
                </p>
                <p class="mt-2 break-words text-sm text-slate-600">
                    Al terminar, volverás a <strong class="break-all text-slate-900">{{ redirectHost }}</strong>.
                    Autoriza solo si has iniciado tú esta conexión.
                </p>
            </div>

            <fieldset>
                <legend class="text-sm font-semibold text-slate-900">Permisos</legend>
                <p class="mt-1 text-xs text-slate-500">Empieza solo con lectura. Puedes cambiar las casillas; lo puedes revocar en «API keys».</p>

                <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Conjuntos de permisos">
                    <button
                        v-for="(preset, key) in presets"
                        :key="key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition"
                        :class="presetActive(preset) ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                        :aria-pressed="presetActive(preset)"
                        @click="applyPreset(preset)"
                    >
                        {{ preset.label }}
                    </button>
                </div>

                <ul class="mt-3 space-y-2">
                    <li v-for="(description, ability) in abilities" :key="ability">
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <Checkbox v-model:checked="form.abilities" :value="ability" class="mt-0.5 shrink-0" />
                            <span class="min-w-0 break-words">{{ description }} <code class="text-xs text-slate-600">{{ ability }}</code></span>
                        </label>
                    </li>
                </ul>
                <InputError class="mt-2" :message="form.errors.abilities || form.errors['abilities.0']" />
            </fieldset>

            <div>
                <label for="token-days" class="block text-sm font-semibold text-slate-900">Duración del acceso</label>
                <select
                    id="token-days"
                    v-model.number="form.token_days"
                    class="mt-2 block w-full max-w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    aria-describedby="token-days-help"
                >
                    <option v-for="days in tokenDayOptions" :key="days" :value="days">{{ durationLabel(days) }}</option>
                </select>
                <p id="token-days-help" class="mt-1 text-xs text-slate-500">Pasado ese tiempo el acceso caduca y la aplicación tendrá que pedirlo de nuevo. Puedes revocarlo antes en «API keys».</p>
                <InputError class="mt-2" :message="form.errors.token_days" />
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <SecondaryButton type="button" :disabled="form.processing" @click="submit('deny')">Denegar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing || noneSelected">Autorizar</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
