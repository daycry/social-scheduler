<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import BaseDialog from '@/Components/BaseDialog.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-lg font-medium text-foreground-strong"> Eliminar cuenta </h2>

            <p class="mt-1 text-sm text-foreground-muted">
                Al eliminar tu cuenta se borrarán de forma permanente todos sus recursos y datos. Antes de hacerlo,
                descarga cualquier información que quieras conservar.
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">Eliminar cuenta</DangerButton>

        <BaseDialog
            v-if="confirmingUserDeletion"
            open
            close-on-backdrop
            class="dialog-panel max-w-2xl"
            aria-label="¿Seguro que quieres eliminar tu cuenta?"
            @close="closeModal"
        >
            <div class="p-6">
                <h2 class="text-lg font-medium text-foreground-strong"> ¿Seguro que quieres eliminar tu cuenta? </h2>

                <p class="mt-1 text-sm text-foreground-muted">
                    Esta acción es permanente. Introduce tu contraseña para confirmar que quieres eliminar tu cuenta.
                </p>

                <div class="mt-6">
                    <FormField
                        id="delete-account-password"
                        :error="form.errors.password"
                        error-class="mt-2"
                        v-slot="{ control }"
                    >
                        <InputLabel for="delete-account-password" value="Contraseña" class="sr-only" />

                        <TextInput
                            v-bind="control"
                            ref="passwordInput"
                            data-dialog-initial-focus
                            v-model="form.password"
                            type="password"
                            class="mt-1 block w-3/4"
                            placeholder="Contraseña"
                            @keyup.enter="deleteUser"
                        />
                    </FormField>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal"> Cancelar </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Eliminar cuenta
                    </DangerButton>
                </div>
            </div>
        </BaseDialog>
    </section>
</template>
