<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import PasskeysForm from './Partials/PasskeysForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { useSecureContext } from '@/Composables/useSecureContext';
import { Head } from '@inertiajs/vue3';

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
});

// Sin contexto seguro (https) ni WebAuthn no se pinta la tarjeta de passkeys: solo una linea discreta (CA-20).
const { canUsePasskeys, httpsNotice } = useSecureContext();
</script>

<template>
    <Head title="Perfil" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900">Perfil</h1>
        </template>

        <div>
            <div class="w-full max-w-3xl space-y-6">
                <div
                    class="card p-4 sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="card p-4 sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="card p-4 sm:p-8"
                    aria-label="Seguridad"
                >
                    <TwoFactorForm
                        :two-factor="twoFactor"
                        :setup="twoFactorSetup"
                        :recovery-codes="recoveryCodes"
                        class="max-w-xl"
                    />

                    <PasskeysForm v-if="canUsePasskeys" :passkeys="passkeys" class="mt-10 max-w-xl border-t border-slate-200 pt-8" />
                    <p v-else class="mt-8 max-w-xl text-xs text-slate-500" role="note">{{ httpsNotice }}</p>
                </div>

                <div
                    class="card p-4 sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
