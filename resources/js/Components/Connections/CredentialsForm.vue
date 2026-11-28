<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Formulario de credenciales para las redes que no usan OAuth (Bluesky, Mastodon y Telegram): los campos y el texto de introducción los declara el
 * conector. Los secretos (campos `password`) solo viajan en el POST: tras un error se vacían y el servidor nunca los devuelve.
 */
const props = defineProps({
    brandId: { type: Number, required: true },
    provider: { type: String, required: true },
    label: { type: String, required: true },
    fields: { type: Array, required: true },
    intro: { type: String, default: '' },
});

const open = ref(false);
const form = useForm(Object.fromEntries(props.fields.map((f) => [f.name, ''])));
const uid = `cred-${props.provider}`;
const generalError = computed(() => form.errors.credentials ?? null);

const submit = () =>
    form.post(route('brands.connections.credentials', { brand: props.brandId, provider: props.provider }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
        onError: () => form.reset(...props.fields.filter((f) => f.type === 'password').map((f) => f.name)),
    });
</script>

<template>
    <div>
        <SecondaryButton
            v-if="!open"
            type="button"
            :aria-expanded="open"
            :aria-controls="`${uid}-form`"
            @click="open = true"
            >+ Conectar</SecondaryButton
        >

        <form
            v-if="open"
            :id="`${uid}-form`"
            class="mt-4 w-full space-y-3 rounded-lg border border-border bg-canvas p-4"
            autocomplete="off"
            @submit.prevent="submit"
        >
            <p class="text-sm text-foreground-secondary">{{ intro }}</p>

            <div v-for="f in fields" :key="f.name">
                <label
                    :for="`${uid}-${f.name}`"
                    class="block text-sm font-medium text-foreground-secondary"
                    >{{ f.label }}</label
                >
                <input
                    :id="`${uid}-${f.name}`"
                    v-model="form[f.name]"
                    :type="f.type"
                    :name="f.name"
                    class="field mt-1 block w-full"
                    :autocomplete="f.type === 'password' ? 'new-password' : 'off'"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    :aria-describedby="`${uid}-${f.name}-help`"
                    :aria-invalid="form.errors[f.name] ? 'true' : undefined"
                />
                <p :id="`${uid}-${f.name}-help`" class="mt-1 text-xs text-foreground-muted">{{ f.help }}</p>
                <p
                    v-if="form.errors[f.name]"
                    class="mt-1 text-sm text-danger"
                    role="alert"
                    >{{ form.errors[f.name] }}</p
                >
            </div>

            <p
                v-if="generalError"
                class="rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-ink"
                role="alert"
                data-testid="credentials-error"
                >{{ generalError }}</p
            >

            <div class="flex flex-wrap items-center gap-3">
                <PrimaryButton :disabled="form.processing">Conectar {{ label }}</PrimaryButton>
                <button type="button" class="link text-sm" @click="open = false; form.reset(); form.clearErrors()"
                    >Cancelar</button
                >
            </div>
        </form>
    </div>
</template>
