<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
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
});
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
