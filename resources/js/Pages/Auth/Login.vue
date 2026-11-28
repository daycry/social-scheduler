<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialProviderIcon from '@/Components/SocialProviderIcon.vue';
import TextInput from '@/Components/TextInput.vue';
import { passkeyErrorMessage, useSecureContext } from '@/Composables/useSecureContext';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    // Proveedores de login social con credenciales (CA-10): sin ellos no se pinta ningún botón.
    socialProviders: {
        type: Array,
        default: () => [],
    },
});

const socialNames = { google: 'Google', facebook: 'Facebook' };

const socialButtonBase =
    'inline-flex w-full items-center justify-center gap-3 rounded-lg border px-4 py-2.5 text-sm font-semibold shadow-sm transition focus-visible:ring-focus';
const socialButtonClass = {
    google: `${socialButtonBase} google-sign-in`,
    facebook: `${socialButtonBase} facebook-sign-in`,
};

// Los rechazos del login social (cancelar, identidad sin cuenta, sesión caducada) llegan por redirección con el error
// `email` en la sesión, no por el formulario: `form.errors` solo recoge los de su propio envío.
const page = usePage();
const socialError = computed(() => (form.errors.email ? '' : (page.props.errors?.email ?? '')));

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

        <h1 class="text-2xl font-bold tracking-tight text-foreground-strong">Entra en tu cuenta</h1>
        <p class="mb-6 mt-1 text-sm text-foreground-muted">Bienvenido de nuevo a Social Scheduler.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-success-ink" role="status">
            {{ status }}
        </div>

        <div
            v-if="socialError"
            class="mb-4 rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-ink"
            role="alert"
            data-testid="login-error"
        >
            {{ socialError }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <FormField id="email" :error="form.errors.email" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="email" value="Correo electrónico" />

                    <TextInput
                        v-bind="control"
                        type="email"
                        class="mt-1 block w-full"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username webauthn"
                    />
                </FormField>
            </div>

            <div class="mt-4">
                <FormField id="password" :error="form.errors.password" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="password" value="Contraseña" />

                    <TextInput
                        v-bind="control"
                        type="password"
                        class="mt-1 block w-full"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                    />
                </FormField>
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-foreground-muted">Recordarme</span>
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link v-if="canResetPassword" :href="route('password.request')" class="link text-sm">
                    ¿Has olvidado tu contraseña?
                </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Entrar
                </PrimaryButton>
            </div>
        </form>

        <div v-if="socialProviders.length" class="mt-6 space-y-3 border-t border-border pt-6">
            <!-- Navegación completa (no Inertia): la ruta redirige a otro origen. -->
            <a
                v-for="provider in socialProviders"
                :key="provider"
                :href="route('social.redirect', provider)"
                :class="socialButtonClass[provider] ?? 'btn-secondary w-full'"
                :data-testid="`social-${provider}`"
            >
                <SocialProviderIcon :provider="provider" :variant="provider === 'facebook' ? 'light' : 'brand'" />
                <span>Continuar con {{ socialNames[provider] ?? provider }}</span>
            </a>
        </div>

        <div class="mt-6 border-t border-border pt-6">
            <template v-if="canUsePasskeys">
                <SecondaryButton class="w-full" :disabled="passkeyBusy" @click="startPasskey">
                    {{ passkeyBusy ? 'Esperando a tu dispositivo…' : 'Entrar con passkey' }}
                </SecondaryButton>
                <p class="mt-2 text-center text-xs text-foreground-subtle"
                    >Sin contraseña ni código: con tu huella, tu cara o el PIN del dispositivo.</p
                >
            </template>
            <p v-else class="text-center text-sm text-foreground-muted" role="status">{{ httpsNotice }}</p>

            <p class="mt-3 text-center text-sm text-danger" role="alert" aria-live="polite">{{ passkeyMessage }}</p>
        </div>

        <p v-if="$page.props.registrationEnabled" class="mt-6 text-center text-sm text-foreground-muted"
            >¿Aún no tienes cuenta? <Link :href="route('register')" class="link underline">Crear cuenta</Link></p
        >
    </GuestLayout>
</template>

<style scoped>
/* Cada proveedor conserva los colores de su botón de marca en ambas paletas. */
.google-sign-in {
    @apply border-slate-300 bg-white text-slate-800 hover:bg-slate-50;
}

.facebook-sign-in {
    @apply border-transparent bg-[#0866ff] text-white hover:bg-[#0657db];
}
</style>
