<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Nueva contraseña" />

        <h1 class="text-2xl font-bold tracking-tight text-foreground-strong">Elige una nueva contraseña</h1>
        <p class="mb-6 mt-1 text-sm text-foreground-muted">Escribe una contraseña segura para tu cuenta.</p>

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
                        autocomplete="username"
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
                        autocomplete="new-password"
                    />
                </FormField>
            </div>

            <div class="mt-4">
                <FormField
                    id="password_confirmation"
                    :error="form.errors.password_confirmation"
                    error-class="mt-2"
                    v-slot="{ control }"
                >
                    <InputLabel for="password_confirmation" value="Confirmar contraseña" />

                    <TextInput
                        v-bind="control"
                        type="password"
                        class="mt-1 block w-full"
                        v-model="form.password_confirmation"
                        required
                        autocomplete="new-password"
                    />
                </FormField>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Restablecer contraseña
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
