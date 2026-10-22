<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { passkeyErrorMessage, useSecureContext } from '@/Composables/useSecureContext';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { computed, onMounted, ref } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// Passkeys: solo en contexto seguro y con WebAuthn (CA-20). El autocompletado condicional ofrece la passkey desde el
// propio campo de correo (autocomplete="username webauthn"). La passkey sustituye a contraseña + 2FA: el servidor
// devuelve { redirect } y se navega ahí (sin doble redirección).
const { canUsePasskeys, httpsNotice } = useSecureContext();
const goTo = (response) => router.visit(response?.redirect ?? route('dashboard'));
const { verify: verifyPasskey, errorInstance: passkeyError } = usePasskeyVerify({
    remember: () => form.remember,
    onSuccess: goTo,
});
// `isLoading` del cliente también vale true mientras espera el autocompletado: el botón usa su propio estado.
const passkeyBusy = ref(false);
const autofillError = ref(null);

// El autofill se gestiona aquí (no con `autofill: true` del composable) para poder relanzarlo: el botón lo cancela al
// empezar y, si el usuario cancela o la passkey falla, se vuelve a ofrecer sin recargar la página.
const startAutofill = async () => {
    if (!canUsePasskeys.value) {
        return;
    }
    try {
        if (!Passkeys.isSupported() || !(await Passkeys.isAutofillSupported())) {
            return;
        }
        autofillError.value = null;
        const response = await Passkeys.autofill({ remember: () => form.remember });
        if (response) {
            goTo(response);
        }
    } catch (error) {
        autofillError.value = error;
    }
};
onMounted(startAutofill);

const startPasskey = async () => {
    passkeyBusy.value = true;
    autofillError.value = null;
    try {
        await verifyPasskey();
    } finally {
        passkeyBusy.value = false;
        // Si falló o se canceló (sigue en esta página), el autofill vuelve a estar disponible; si entró, se navega.
        if (passkeyError.value) {
            startAutofill();
        }
    }
};
const passkeyMessage = computed(() => passkeyErrorMessage(passkeyError.value ?? autofillError.value));

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Entrar" />

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Entra en tu cuenta</h1>
        <p class="mb-6 mt-1 text-sm text-slate-600">Bienvenido de nuevo a Social Scheduler.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-emerald-700" role="status">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username webauthn"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Contraseña" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-slate-600"
                        >Recordarme</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="link text-sm"
                >
                    ¿Has olvidado tu contraseña?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Entrar
                </PrimaryButton>
            </div>
        </form>

        <div class="mt-6 border-t border-slate-200 pt-6">
            <template v-if="canUsePasskeys">
                <SecondaryButton class="w-full" :disabled="passkeyBusy" @click="startPasskey">
                    {{ passkeyBusy ? 'Esperando a tu dispositivo…' : 'Entrar con passkey' }}
                </SecondaryButton>
                <p class="mt-2 text-center text-xs text-slate-500">Sin contraseña ni código: con tu huella, tu cara o el PIN del dispositivo.</p>
            </template>
            <p v-else class="text-center text-sm text-slate-600" role="status">{{ httpsNotice }}</p>

            <p class="mt-3 text-center text-sm text-red-600" role="alert" aria-live="polite">{{ passkeyMessage }}</p>
        </div>

        <p v-if="$page.props.registrationEnabled" class="mt-6 text-center text-sm text-slate-600">¿Aún no tienes cuenta? <Link :href="route('register')" class="link">Crear cuenta</Link></p>
    </GuestLayout>
</template>
