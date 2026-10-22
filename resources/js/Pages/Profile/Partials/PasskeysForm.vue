<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { passkeyErrorMessage, useSecureContext } from '@/Composables/useSecureContext';
import { router, useForm } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    // { confirmed: bool, items: [{ id, name, created_at, last_used_at }] }: nunca material de la credencial.
    passkeys: { type: Object, required: true },
});

// Sin contexto seguro la tarjeta entera se oculta (la pagina de perfil deja una linea discreta en su lugar, CA-20).
const { canUsePasskeys } = useSecureContext();

const adding = ref(false);
const addName = ref('');
const addNameInput = ref(null);
const addError = ref('');
const renaming = ref(null);
const deleting = ref(null);
const notice = ref('');

const renameForm = useForm({ name: '' });
const renameInput = ref(null);
const deleteForm = useForm({});

const { register, isLoading: registering } = usePasskeyRegister({
    onSuccess: () => {
        adding.value = false;
        addName.value = '';
        addError.value = '';
        notice.value = 'Passkey añadida. Ya puedes entrar con ella desde la pantalla de acceso.';
        router.reload({ only: ['passkeys'], preserveScroll: true });
    },
    onError: (error) => {
        const message = passkeyErrorMessage(error);
        if (message === 'password-confirmation') {
            // La confirmación caducó con la página abierta: se vuelve a pedir y se retoma el alta.
            askPassword('add');
            return;
        }
        addError.value = message ?? '';
    },
});

const dateFormat = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium' });
const formatDate = (iso) => (iso ? dateFormat.format(new Date(iso)) : null);

/** Lleva a confirmar la contraseña y vuelve al perfil (al alta si `then` es "add"). */
const askPassword = (then) => {
    router.visit(route('passkeys.confirm', then ? { then } : {}));
};

const openAdd = () => {
    if (!props.passkeys.confirmed) {
        return askPassword('add');
    }
    notice.value = '';
    addError.value = '';
    adding.value = true;
    nextTick(() => addNameInput.value?.focus());
};

const closeAdd = () => {
    if (registering.value) {
        return;
    }
    adding.value = false;
    addError.value = '';
    addName.value = '';
};

const submitAdd = async () => {
    const name = addName.value.trim();
    if (name === '') {
        addError.value = 'Ponle un nombre a la passkey, por ejemplo «Portátil de casa».';
        return;
    }
    addError.value = '';
    await register(name);
};

const openRename = (passkey) => {
    if (!props.passkeys.confirmed) {
        return askPassword();
    }
    notice.value = '';
    renaming.value = passkey;
    renameForm.name = passkey.name;
    renameForm.clearErrors();
    nextTick(() => renameInput.value?.focus());
};

const closeRename = () => {
    renaming.value = null;
    renameForm.clearErrors();
};

const submitRename = () => {
    renameForm.patch(route('passkeys.update', renaming.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeRename();
            notice.value = 'Passkey renombrada.';
        },
        onError: () => nextTick(() => renameInput.value?.focus()),
    });
};

const openDelete = (passkey) => {
    if (!props.passkeys.confirmed) {
        return askPassword();
    }
    notice.value = '';
    deleting.value = passkey;
};

const closeDelete = () => {
    deleting.value = null;
};

const submitDelete = () => {
    deleteForm.delete(route('passkeys.destroy', deleting.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeDelete();
            notice.value = 'Passkey eliminada. Ya no sirve para entrar.';
        },
    });
};

onMounted(() => {
    // Al volver de confirmar la contraseña (?passkey=add) se abre directamente el alta.
    const url = new URL(window.location.href);
    if (url.searchParams.get('passkey') === 'add') {
        url.searchParams.delete('passkey');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        if (canUsePasskeys.value && props.passkeys.confirmed) {
            openAdd();
        }
    }
});
</script>

<template>
    <section v-if="canUsePasskeys" aria-labelledby="passkeys-title">
        <header>
            <h2 id="passkeys-title" class="text-lg font-medium text-slate-900">Passkeys</h2>

            <p class="mt-1 text-sm text-slate-600">
                Entra con tu huella, tu cara o el PIN del dispositivo, sin escribir la contraseña ni el código del doble factor.
                Cada passkey es de un dispositivo o gestor de contraseñas.
            </p>
        </header>

        <template v-if="canUsePasskeys">
            <p class="mt-4 text-sm text-emerald-700" role="status" aria-live="polite">{{ notice }}</p>

            <EmptyState
                v-if="passkeys.items.length === 0"
                class="mt-4"
                title="Aún no tienes passkeys"
                description="Añade una para entrar sin contraseña. Sigues pudiendo entrar con tu contraseña."
            >
                <PrimaryButton @click="openAdd">Añadir passkey</PrimaryButton>
            </EmptyState>

            <div v-else class="mt-4">
                <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label="Tus passkeys">
                    <li v-for="passkey in passkeys.items" :key="passkey.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-slate-900">{{ passkey.name }}</p>
                            <p class="text-sm text-slate-600">
                                Añadida el {{ formatDate(passkey.created_at) }} ·
                                <template v-if="passkey.last_used_at">último uso el {{ formatDate(passkey.last_used_at) }}</template>
                                <template v-else>sin usar todavía</template>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton :aria-label="`Renombrar la passkey ${passkey.name}`" @click="openRename(passkey)">Renombrar</SecondaryButton>
                            <DangerButton type="button" :aria-label="`Eliminar la passkey ${passkey.name}`" @click="openDelete(passkey)">Eliminar</DangerButton>
                        </div>
                    </li>
                </ul>
                <div class="mt-4">
                    <SecondaryButton @click="openAdd">Añadir otra passkey</SecondaryButton>
                </div>
            </div>
        </template>

        <!-- Alta: el nombre se pide antes; el diálogo del navegador (biometría) se abre al enviar. -->
        <Modal :show="adding" max-width="md" @close="closeAdd">
            <form class="p-6" novalidate @submit.prevent="submitAdd">
                <h2 class="text-lg font-medium text-slate-900">Añadir una passkey</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Ponle un nombre que te ayude a reconocerla y, al continuar, tu dispositivo te pedirá la huella, la cara o el PIN.
                </p>

                <div class="mt-6">
                    <InputLabel for="passkey_name" value="Nombre de la passkey" />
                    <TextInput
                        id="passkey_name"
                        ref="addNameInput"
                        v-model="addName"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        class="mt-1 block w-full"
                        placeholder="Portátil de casa"
                        required
                        :aria-invalid="addError ? 'true' : undefined"
                        aria-describedby="passkey_name_error"
                    />
                    <div id="passkey_name_error" role="alert" aria-live="polite">
                        <InputError :message="addError" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton :disabled="registering" @click="closeAdd">Cancelar</SecondaryButton>
                    <PrimaryButton :disabled="registering">{{ registering ? 'Esperando a tu dispositivo…' : 'Continuar' }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Renombrar -->
        <Modal :show="renaming !== null" max-width="md" @close="closeRename">
            <form class="p-6" novalidate @submit.prevent="submitRename">
                <h2 class="text-lg font-medium text-slate-900">Renombrar la passkey</h2>

                <div class="mt-6">
                    <InputLabel for="passkey_rename" value="Nombre de la passkey" />
                    <TextInput
                        id="passkey_rename"
                        ref="renameInput"
                        v-model="renameForm.name"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        class="mt-1 block w-full"
                        required
                        :aria-invalid="renameForm.errors.name ? 'true' : undefined"
                        aria-describedby="passkey_rename_error"
                    />
                    <div id="passkey_rename_error" role="alert" aria-live="polite">
                        <InputError :message="renameForm.errors.name" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeRename">Cancelar</SecondaryButton>
                    <PrimaryButton :disabled="renameForm.processing">Guardar</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Eliminar -->
        <Modal :show="deleting !== null" max-width="md" @close="closeDelete">
            <div class="p-6">
                <h2 class="text-lg font-medium text-slate-900">Eliminar la passkey</h2>
                <p class="mt-1 text-sm text-slate-600">
                    ¿Eliminar «{{ deleting?.name }}»? Dejará de servir para entrar. Podrás volver a añadirla cuando quieras y seguirás
                    pudiendo entrar con tu contraseña.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeDelete">Cancelar</SecondaryButton>
                    <DangerButton type="button" :class="{ 'opacity-25': deleteForm.processing }" :disabled="deleteForm.processing" @click="submitDelete">Eliminar</DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
