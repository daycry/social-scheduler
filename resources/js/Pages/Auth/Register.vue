<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Crear cuenta" />

        <h1 class="text-2xl font-bold tracking-tight text-foreground-strong">Crea tu cuenta</h1>
        <p class="mb-6 mt-1 text-sm text-foreground-muted">Empieza a programar y medir tus redes en minutos.</p>

        <form @submit.prevent="submit">
            <div>
                <FormField id="name" :error="form.errors.name" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="name" value="Nombre" />

                    <TextInput
                        v-bind="control"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="name"
                    />
                </FormField>
            </div>

            <div class="mt-4">
                <FormField id="email" :error="form.errors.email" error-class="mt-2" v-slot="{ control }">
                    <InputLabel for="email" value="Correo electrónico" />

                    <TextInput
                        v-bind="control"
                        type="email"
                        class="mt-1 block w-full"
                        v-model="form.email"
                        required
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
                <Link :href="route('login')" class="link text-sm"> ¿Ya tienes cuenta? </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Crear cuenta
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
