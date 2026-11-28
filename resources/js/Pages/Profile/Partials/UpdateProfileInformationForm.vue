<script setup>
import FormField from '@/Components/FormField.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    notify_by_mail: Boolean(user.notify_by_mail),
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-foreground-strong"> Datos del perfil </h2>

            <p class="mt-1 text-sm text-foreground-muted"> Actualiza tu nombre y tu correo electrónico. </p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-6">
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

            <div>
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

            <label class="flex items-start gap-2 text-sm text-foreground-secondary" for="notify_by_mail">
                <Checkbox
                    id="notify_by_mail"
                    name="notify_by_mail"
                    v-model:checked="form.notify_by_mail"
                    data-testid="notify-by-mail"
                />
                <span>
                    Activar por defecto el correo para fallos de trabajos, caducidad de conexiones, objetivos,
                    recordatorios y resumen semanal. Puedes elegir los canales de cada tipo en
                    <Link :href="route('profile.notifications')" class="underline">Notificaciones del navegador</Link>.
                </span>
            </label>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-foreground">
                    Tu correo electrónico no está verificado.
                    <Link :href="route('verification.send')" method="post" as="button" class="link text-sm">
                        Reenviar el correo de verificación.
                    </Link>
                </p>

                <div v-show="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-success-ink">
                    Te hemos enviado un nuevo enlace de verificación.
                </div>
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
