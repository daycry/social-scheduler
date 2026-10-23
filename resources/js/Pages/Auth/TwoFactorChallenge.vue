<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const usingRecovery = ref(false);
const codeInput = ref(null);
const recoveryInput = ref(null);

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggle = () => {
    usingRecovery.value = !usingRecovery.value;
    form.clearErrors();
    form.reset();
    nextTick(() => (usingRecovery.value ? recoveryInput : codeInput).value?.focus());
};

const submit = () => {
    // Solo viaja el campo en uso: el servidor decide por la presencia de recovery_code.
    form.transform((data) => (usingRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code })).post(route('two-factor.challenge.store'), {
        onError: () => (usingRecovery.value ? recoveryInput : codeInput).value?.focus(),
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Verificación en dos pasos" />

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Verificación en dos pasos</h1>
        <p class="mb-6 mt-1 text-sm text-slate-600">
            <template v-if="!usingRecovery">Escribe el código de 6 dígitos que muestra tu app de autenticación.</template>
            <template v-else>Escribe uno de los códigos de recuperación que guardaste al activar el doble factor. Cada uno solo sirve una vez.</template>
        </p>

        <form @submit.prevent="submit" novalidate>
            <div v-if="!usingRecovery">
                <InputLabel for="code" value="Código de verificación" />
                <TextInput
                    id="code"
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="8"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full tracking-widest"
                    autofocus
                    required
                    :aria-invalid="form.errors.code ? 'true' : undefined"
                    aria-describedby="code-error"
                />
                <div id="code-error" aria-live="polite">
                    <InputError class="mt-2" :message="form.errors.code" />
                </div>
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Código de recuperación" />
                <TextInput
                    id="recovery_code"
                    ref="recoveryInput"
                    v-model="form.recovery_code"
                    type="text"
                    maxlength="32"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    class="mt-1 block w-full"
                    autofocus
                    required
                    :aria-invalid="form.errors.recovery_code ? 'true' : undefined"
                    aria-describedby="recovery-error"
                />
                <div id="recovery-error" aria-live="polite">
                    <InputError class="mt-2" :message="form.errors.recovery_code" />
                </div>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="link text-sm" @click="toggle">
                    {{ usingRecovery ? 'Usar el código de mi app' : 'Usar un código de recuperación' }}
                </button>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Verificar
                </PrimaryButton>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-slate-600">
            <Link :href="route('login')" class="link">Volver al inicio de sesión</Link>
        </p>
    </GuestLayout>
</template>
