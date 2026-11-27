<script setup>
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-foreground-strong"> Cambiar contraseña </h2>

            <p class="mt-1 text-sm text-foreground-muted">
                Usa una contraseña larga y aleatoria para mantener tu cuenta segura.
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-6">
            <div>
                <FormField
                    id="current_password"
                    :error="form.errors.current_password"
                    error-class="mt-2"
                    v-slot="{ control }"
                >
                    <InputLabel for="current_password" value="Contraseña actual" />

                    <TextInput
                        v-bind="control"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                </FormField>
            </div>

            <div>
                <FormField id="password" :error="form.errors.password" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="password" value="Nueva contraseña" />

                    <TextInput
                        v-bind="control"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />
                </FormField>
            </div>

            <div>
                <FormField
                    id="password_confirmation"
                    :error="form.errors.password_confirmation"
                    error-class="mt-2"
                    v-slot="{ control }"
                >
                    <InputLabel for="password_confirmation" value="Confirmar contraseña" />

                    <TextInput
                        v-bind="control"
                        v-model="form.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />
                </FormField>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-foreground-muted"> Guardado. </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
