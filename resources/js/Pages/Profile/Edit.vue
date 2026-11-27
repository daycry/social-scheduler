<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ThemePreference from '@/Components/ThemePreference.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import LinkedAccountsForm from './Partials/LinkedAccountsForm.vue';
import PasskeysForm from './Partials/PasskeysForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { useSecureContext } from '@/Composables/useSecureContext';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    // Estado del doble factor, sin secretos. `twoFactorSetup` y `recoveryCodes` solo llegan en la respuesta que los genera.
    twoFactor: {
        type: Object,
        default: () => ({ enabled: false, pending: false, recoveryRemaining: 0 }),
    },
    twoFactorSetup: {
        type: Object,
        default: null,
    },
    recoveryCodes: {
        type: Array,
        default: null,
    },
    // Passkeys: { confirmed, items: [{ id, name, created_at, last_used_at }] }, sin material de la credencial.
    passkeys: {
        type: Object,
        default: () => ({ confirmed: false, items: [] }),
    },
    // Cuentas vinculadas (login social): [{ provider, enabled, linked, email, linked_at }].
    socialAccounts: {
        type: Array,
        default: () => [],
    },
});

// Sin contexto seguro (https) ni WebAuthn no se pinta la tarjeta de passkeys: solo una linea discreta (CA-20).
const { canUsePasskeys, httpsNotice } = useSecureContext();
</script>

<template>
    <Head title="Perfil" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-foreground-strong">Perfil</h1>
        </template>

        <div>
            <div class="w-full max-w-3xl space-y-6">
                <section class="card p-4 sm:p-8" aria-labelledby="appearance-title">
                    <h2 id="appearance-title" class="mb-3 text-lg font-medium text-foreground-strong">Apariencia</h2>
                    <ThemePreference class="max-w-xs" />
                </section>

                <div class="card p-4 sm:p-8">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="card p-4 sm:p-8">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <section class="card p-4 sm:p-8" aria-label="Seguridad">
                    <TwoFactorForm
                        :two-factor="twoFactor"
                        :setup="twoFactorSetup"
                        :recovery-codes="recoveryCodes"
                        class="max-w-xl"
                    />

                    <PasskeysForm
                        v-if="canUsePasskeys"
                        :passkeys="passkeys"
                        class="mt-10 max-w-xl border-t border-border pt-8"
                    />
                    <p v-else class="mt-8 max-w-xl text-xs text-foreground-subtle" role="note">{{ httpsNotice }}</p>
                </section>

                <div class="card p-4 sm:p-8">
                    <LinkedAccountsForm :accounts="socialAccounts" :status="status" class="max-w-xl" />
                </div>

                <section class="card p-4 sm:p-8" aria-labelledby="export-data-title">
                    <div class="max-w-xl space-y-2">
                        <h2 id="export-data-title" class="text-lg font-medium text-foreground-strong"
                            >Exportar mis datos</h2
                        >
                        <p class="text-sm text-foreground-muted"
                            >Descarga una copia completa de tus datos (marcas, publicaciones, informes, media y diseños)
                            en un ZIP.</p
                        >
                        <Link :href="route('profile.export')" class="btn-secondary inline-block"
                            >Ir a la exportación</Link
                        >
                    </div>
                </section>

                <div class="card p-4 sm:p-8">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
