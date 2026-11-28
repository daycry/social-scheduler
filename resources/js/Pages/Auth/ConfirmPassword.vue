<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirmar contraseña" />

        <h1 class="text-2xl font-bold tracking-tight text-foreground-strong">Confirma tu contraseña</h1>
        <p class="mb-6 mt-1 text-sm text-foreground-muted">Por seguridad, necesitamos que lo confirmes.</p>

        <div class="mb-4 text-sm text-foreground-muted">
            Esta es una zona segura de la aplicación. Confirma tu contraseña antes de continuar.
        </div>

        <form @submit.prevent="submit">
            <div>
                <FormField id="password" :error="form.errors.password" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="password" value="Contraseña" />
                    <TextInput
                        v-bind="control"
                        type="password"
                        class="mt-1 block w-full"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        autofocus
                    />
                </FormField>
            </div>

            <div class="mt-4 flex justify-end">
                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Confirmar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
