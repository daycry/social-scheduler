<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Verifica tu correo" />

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Verifica tu correo</h1>
        <p class="mb-6 mt-1 text-sm text-slate-600">Un último paso antes de entrar.</p>

        <div class="mb-4 text-sm text-slate-600">
            ¡Gracias por registrarte! Antes de empezar, verifica tu correo electrónico pulsando el enlace que te acabamos de enviar. Si no lo has recibido, te enviamos otro encantados.
        </div>

        <div
            class="mb-4 text-sm font-medium text-emerald-700"
            v-if="verificationLinkSent"
        >
            Te hemos enviado un nuevo enlace de verificación al correo con el que te registraste.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Reenviar correo de verificación
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="link text-sm"
                    >Salir</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
