<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormField from '@/Components/FormField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Recuperar contraseña" />

        <h1 class="text-2xl font-bold tracking-tight text-foreground-strong">Recupera tu contraseña</h1>
        <p class="mb-6 mt-1 text-sm text-foreground-muted">Te enviaremos un enlace por correo.</p>

        <div class="mb-4 text-sm text-foreground-muted">
            Indícanos tu correo electrónico y te enviaremos un enlace para elegir una nueva.
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-success-ink">
            {{ status }}
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
                        autocomplete="username"
                    />
                </FormField>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Enviar enlace de recuperación
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
