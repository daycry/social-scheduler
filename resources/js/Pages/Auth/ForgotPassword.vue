<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
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

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Recupera tu contraseña</h1>
        <p class="mb-6 mt-1 text-sm text-slate-600">Te enviaremos un enlace por correo.</p>

        <div class="mb-4 text-sm text-slate-600">
            Indícanos tu correo electrónico y te enviaremos un enlace para elegir una nueva.
        </div>

        <div
            v-if="status"
            class="mb-4 text-sm font-medium text-emerald-700"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Enviar enlace de recuperación
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
