<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    // Estado, sin secretos: { enabled, pending, setupLocked, recoveryRemaining }.
    twoFactor: { type: Object, required: true },
    // Solo mientras la activación está a medias (lo sirve el perfil desde el usuario): { secret, qrSvg }.
    setup: { type: Object, default: null },
    // Solo en la respuesta que genera los códigos: lista de 8 códigos en claro.
    recoveryCodes: { type: Array, default: null },
});

const enableForm = useForm({});
const confirmForm = useForm({ code: '' });
const regenerateForm = useForm({});
const cancelForm = useForm({});
const disableForm = useForm({ password: '', code: '', recovery_code: '' });

const confirmInput = ref(null);
const disablePasswordInput = ref(null);
const disableCodeInput = ref(null);
const disableRecoveryInput = ref(null);
const confirmingDisable = ref(false);
const disableWithRecovery = ref(false);
const copied = ref('');

const qrSrc = computed(() => (props.setup?.qrSvg ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(props.setup.qrSvg) : null));

const enable = () => {
    enableForm.post(route('two-factor.enable'), {
        preserveScroll: true,
        onSuccess: () => nextTick(() => confirmInput.value?.focus()),
    });
};

const confirm = () => {
    confirmForm.post(route('two-factor.confirm'), {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
        onError: () => {
            confirmForm.reset();
            nextTick(() => confirmInput.value?.focus());
        },
    });
};

const cancelSetup = () => {
    cancelForm.delete(route('two-factor.cancel'), { preserveScroll: true });
};

const regenerate = () => {
    regenerateForm.post(route('two-factor.recovery-codes'), { preserveScroll: true });
};

const openDisable = () => {
    confirmingDisable.value = true;
    nextTick(() => disablePasswordInput.value?.focus());
};

const closeDisable = () => {
    confirmingDisable.value = false;
    disableWithRecovery.value = false;
    disableForm.clearErrors();
    disableForm.reset();
};

const toggleDisableMode = () => {
    disableWithRecovery.value = !disableWithRecovery.value;
    disableForm.reset('code', 'recovery_code');
    disableForm.clearErrors('code', 'recovery_code');
    nextTick(() => (disableWithRecovery.value ? disableRecoveryInput : disableCodeInput).value?.focus());
};

const disable = () => {
    disableForm
        .transform((data) => (disableWithRecovery.value ? { password: data.password, recovery_code: data.recovery_code } : { password: data.password, code: data.code }))
        .delete(route('two-factor.disable'), {
            preserveScroll: true,
            onSuccess: () => closeDisable(),
            onError: () => {
                disableForm.reset('password', 'code', 'recovery_code');
                nextTick(() => disablePasswordInput.value?.focus());
            },
        });
};

const copy = async (text, what) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
    } catch {
        copied.value = '';
    }
};

const downloadCodes = () => {
    const blob = new Blob([props.recoveryCodes.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'codigos-de-recuperacion.txt';
    a.click();
    // Revocar en el mismo tick puede abortar la descarga en algunos navegadores.
    setTimeout(() => URL.revokeObjectURL(url), 1000);
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-slate-900">Doble factor de autenticación</h2>

            <p class="mt-1 text-sm text-slate-600">
                Añade un segundo paso al entrar: además de la contraseña, un código temporal de una app como Google Authenticator, Authy o 1Password.
            </p>
        </header>

        <!-- Códigos de recuperación recién generados: solo se muestran esta vez. -->
        <div v-if="recoveryCodes" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4" role="region" aria-labelledby="recovery-title">
            <h3 id="recovery-title" class="text-sm font-semibold text-amber-900">Guarda tus códigos de recuperación</h3>
            <p class="mt-1 text-sm text-amber-900">
                Cada código sirve una sola vez si pierdes el móvil. No volverán a mostrarse: guárdalos ahora en un lugar seguro.
            </p>

            <ul class="mt-3 grid grid-cols-1 gap-2 font-mono text-sm text-slate-900 sm:grid-cols-2" aria-label="Códigos de recuperación">
                <li v-for="code in recoveryCodes" :key="code" class="break-all rounded bg-white px-3 py-1.5">{{ code }}</li>
            </ul>

            <div class="mt-4 flex flex-wrap gap-3">
                <SecondaryButton @click="copy(recoveryCodes.join('\n'), 'codes')">Copiar códigos</SecondaryButton>
                <SecondaryButton @click="downloadCodes">Descargar .txt</SecondaryButton>
            </div>
            <p class="mt-2 text-sm text-amber-900" role="status" aria-live="polite">
                <template v-if="copied === 'codes'">Códigos copiados al portapapeles.</template>
            </p>
        </div>

        <!-- Estado: desactivado -->
        <div v-if="!twoFactor.enabled && !twoFactor.pending" class="mt-6">
            <p class="text-sm text-slate-700">El doble factor está <strong>desactivado</strong>.</p>
            <PrimaryButton class="mt-4" :disabled="enableForm.processing" @click="enable">Activar doble factor</PrimaryButton>
        </div>

        <!-- Estado: pendiente de confirmar -->
        <div v-else-if="!twoFactor.enabled" class="mt-6 space-y-4">
            <template v-if="setup">
                <ol class="list-decimal space-y-1 ps-5 text-sm text-slate-700">
                    <li>Escanea el código QR con tu app de autenticación, o escribe la clave a mano.</li>
                    <li>Escribe el código de 6 dígitos que te muestre para terminar de activarlo.</li>
                </ol>

                <img
                    v-if="qrSrc"
                    :src="qrSrc"
                    alt="Código QR para configurar la app de autenticación. Si no puedes escanearlo, usa la clave de configuración que aparece debajo."
                    width="192"
                    height="192"
                    class="h-48 w-48 rounded border border-slate-200 bg-white p-1"
                />

                <div>
                    <p class="text-sm text-slate-600">Clave de configuración</p>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <code class="break-all rounded bg-slate-100 px-2 py-1 font-mono text-sm text-slate-900">{{ setup.secret }}</code>
                        <SecondaryButton @click="copy(setup.secret, 'secret')">Copiar clave</SecondaryButton>
                    </div>
                    <p class="mt-1 text-sm text-emerald-700" role="status" aria-live="polite">
                        <template v-if="copied === 'secret'">Clave copiada al portapapeles.</template>
                    </p>
                </div>
            </template>
            <p v-else-if="twoFactor.setupLocked" class="text-sm text-slate-700" role="status">
                Tienes una activación a medias. Por seguridad, la clave solo se muestra tras confirmar tu contraseña: si ya la
                añadiste a tu app, escribe el código; si no, pulsa «Generar otro QR».
            </p>

            <form class="space-y-3" @submit.prevent="confirm" novalidate>
                <div>
                    <InputLabel for="tf_code" value="Código de verificación" />
                    <TextInput
                        id="tf_code"
                        ref="confirmInput"
                        v-model="confirmForm.code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="8"
                        autocomplete="one-time-code"
                        class="mt-1 block w-full max-w-xs tracking-widest"
                        required
                        :aria-invalid="confirmForm.errors.code ? 'true' : undefined"
                        aria-describedby="tf_code_error"
                    />
                    <div id="tf_code_error" aria-live="polite">
                        <InputError class="mt-2" :message="confirmForm.errors.code" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <PrimaryButton :disabled="confirmForm.processing">Confirmar y activar</PrimaryButton>
                    <SecondaryButton :disabled="enableForm.processing" @click="enable">Generar otro QR</SecondaryButton>
                    <SecondaryButton :disabled="cancelForm.processing" @click="cancelSetup">Cancelar activación</SecondaryButton>
                </div>
            </form>
        </div>

        <!-- Estado: activo -->
        <div v-else class="mt-6">
            <p class="text-sm text-slate-700">
                El doble factor está <strong class="text-emerald-700">activado</strong>.
                Te quedan <strong>{{ twoFactor.recoveryRemaining }}</strong>
                {{ twoFactor.recoveryRemaining === 1 ? 'código de recuperación' : 'códigos de recuperación' }}.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <SecondaryButton :disabled="regenerateForm.processing" @click="regenerate">Generar códigos nuevos</SecondaryButton>
                <DangerButton @click="openDisable">Desactivar doble factor</DangerButton>
            </div>
        </div>

        <Modal :show="confirmingDisable" @close="closeDisable">
            <form class="p-6" @submit.prevent="disable" novalidate>
                <h2 class="text-lg font-medium text-slate-900">Desactivar el doble factor</h2>
                <p class="mt-1 text-sm text-slate-600">Para confirmarlo, escribe tu contraseña y un código de tu app (o uno de recuperación).</p>

                <div class="mt-6">
                    <InputLabel for="tf_disable_password" value="Contraseña" />
                    <TextInput
                        id="tf_disable_password"
                        ref="disablePasswordInput"
                        v-model="disableForm.password"
                        type="password"
                        autocomplete="current-password"
                        class="mt-1 block w-full"
                        required
                    />
                    <InputError :message="disableForm.errors.password" class="mt-2" />
                </div>

                <div v-if="!disableWithRecovery" class="mt-4">
                    <InputLabel for="tf_disable_code" value="Código de verificación" />
                    <TextInput
                        id="tf_disable_code"
                        ref="disableCodeInput"
                        v-model="disableForm.code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="8"
                        autocomplete="one-time-code"
                        class="mt-1 block w-full tracking-widest"
                        required
                    />
                    <div aria-live="polite"><InputError :message="disableForm.errors.code" class="mt-2" /></div>
                </div>
                <div v-else class="mt-4">
                    <InputLabel for="tf_disable_recovery" value="Código de recuperación" />
                    <TextInput
                        id="tf_disable_recovery"
                        ref="disableRecoveryInput"
                        v-model="disableForm.recovery_code"
                        type="text"
                        maxlength="32"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        class="mt-1 block w-full"
                        required
                    />
                    <div aria-live="polite"><InputError :message="disableForm.errors.recovery_code" class="mt-2" /></div>
                </div>

                <button type="button" class="link mt-3 text-sm" @click="toggleDisableMode">
                    {{ disableWithRecovery ? 'Usar el código de mi app' : 'Usar un código de recuperación' }}
                </button>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeDisable">Cancelar</SecondaryButton>
                    <DangerButton type="submit" :class="{ 'opacity-25': disableForm.processing }" :disabled="disableForm.processing">Desactivar</DangerButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
