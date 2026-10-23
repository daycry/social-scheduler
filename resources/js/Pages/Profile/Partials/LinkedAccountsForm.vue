<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialProviderIcon from '@/Components/SocialProviderIcon.vue';
import InputError from '@/Components/InputError.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    // [{ provider, enabled, linked, email, linked_at }]: solo lo que pinta la lista, nunca ids ni tokens del proveedor.
    accounts: { type: Array, required: true },
    status: { type: String, default: null },
});

const names = { google: 'Google', facebook: 'Facebook' };
const nameOf = (provider) => names[provider] ?? provider;

const page = usePage();
const error = computed(() => page.props.errors?.social ?? '');

const busy = ref(false);
const unlinking = ref(null);

const dateFormat = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium' });
const formatDate = (iso) => (iso ? dateFormat.format(new Date(iso)) : null);

// Vincular es un POST de Inertia: la ruta pide la contraseña (password.confirm) y, ya confirmada, el servidor responde con
// una navegación completa al proveedor (X-Inertia-Location). Sin confirmar, redirige a /confirm-password y vuelve aquí.
const link = (account) => {
    busy.value = true;
    router.post(route('social.link', account.provider), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

const confirmUnlink = () => {
    const account = unlinking.value;
    router.delete(route('social.unlink', account.provider), {
        preserveScroll: true,
        onFinish: () => (unlinking.value = null),
    });
};
</script>

<template>
    <section aria-labelledby="linked-accounts-title">
        <header>
            <h2 id="linked-accounts-title" class="text-lg font-medium text-slate-900">Cuentas vinculadas</h2>

            <p class="mt-1 text-sm text-slate-600">
                Vincula tu cuenta de Google o de Facebook para entrar con un clic. Solo se vinculan cuentas que ya tienen acceso a esta
                aplicación; el segundo factor, si lo tienes activo, se sigue pidiendo.
            </p>
        </header>

        <p v-if="status && /vinculado/.test(status)" class="mt-4 text-sm text-emerald-700" role="status" aria-live="polite">{{ status }}</p>
        <div role="alert" aria-live="polite"><InputError :message="error" class="mt-4" /></div>

        <ul class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label="Tus cuentas vinculadas">
            <li v-for="account in accounts" :key="account.provider" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" :data-testid="`linked-${account.provider}`">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white">
                        <SocialProviderIcon :provider="account.provider" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ nameOf(account.provider) }}</p>
                        <p v-if="account.linked" class="break-words text-sm text-slate-600">
                            Vinculada<template v-if="account.linked_at"> el {{ formatDate(account.linked_at) }}</template>
                            <template v-if="account.email"> · {{ account.email }}</template>
                        </p>
                        <p v-else-if="account.enabled" class="text-sm text-slate-600">Sin vincular</p>
                        <p v-else class="text-sm text-slate-500">No disponible: este acceso no está configurado.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <DangerButton v-if="account.linked" type="button" :aria-label="`Desvincular ${nameOf(account.provider)}`" @click="unlinking = account">Desvincular</DangerButton>
                    <SecondaryButton v-else-if="account.enabled" :disabled="busy" :aria-label="`Vincular ${nameOf(account.provider)}`" @click="link(account)">Vincular</SecondaryButton>
                </div>
            </li>
        </ul>

        <Modal :show="unlinking !== null" max-width="md" @close="unlinking = null">
            <div class="p-6">
                <h2 class="text-lg font-medium text-slate-900">Desvincular {{ nameOf(unlinking?.provider) }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Dejarás de poder entrar con esa cuenta y no se volverá a vincular sola. Podrás vincularla de nuevo desde aquí cuando quieras.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="unlinking = null">Cancelar</SecondaryButton>
                    <DangerButton type="button" @click="confirmUnlink">Desvincular</DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
