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
    error: { type: Object, default: null },
});

const form = useForm({
    ...props.params,
    decision: 'approve',
    abilities: [...(props.presets[props.defaultPreset]?.abilities ?? [])],
});

const applyPreset = (preset) => {
    form.abilities = [...preset.abilities];
};
const presetActive = (preset) =>
    form.abilities.length === preset.abilities.length && preset.abilities.every((a) => form.abilities.includes(a));

const heading = ref(null);
onMounted(() => nextTick(() => heading.value?.focus()));

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
                <p class="mt-1 text-xs text-slate-500">Empieza solo con lectura. Puedes cambiar las casillas; el acceso caduca a los 30 días y lo puedes revocar en «API keys».</p>

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
                            <span class="min-w-0 break-words">{{ description }} <code class="text-xs text-slate-400">{{ ability }}</code></span>
                        </label>
                    </li>
                </ul>
                <InputError class="mt-2" :message="form.errors.abilities || form.errors['abilities.0']" />
            </fieldset>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <SecondaryButton type="button" :disabled="form.processing" @click="submit('deny')">Denegar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing || noneSelected">Autorizar</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
