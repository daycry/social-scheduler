<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import InfoNote from '@/Components/InfoNote.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { activatePush, pushSupported, revokeBrowserPush } from '@/lib/push';

/** @import {PushState, PushDevice, NotificationPreferences, NotificationChannels} from '@/types/push' */
const props = defineProps({
    push: { type: /** @type {import('vue').PropType<PushState>} */ (Object), required: true },
    subscriptions: { type: /** @type {import('vue').PropType<PushDevice[]>} */ (Array), required: true },
    preferences: { type: /** @type {import('vue').PropType<NotificationPreferences>} */ (Object), required: true },
    notificationChannels: { type: /** @type {import('vue').PropType<NotificationChannels>} */ (Object), required: true },
    notificationTypes: { type: /** @type {import('vue').PropType<Record<string, string>>} */ (Object), required: true },
    aiAvailable: { type: Boolean, required: true },
});
const prefs = useForm({
    ...props.preferences,
    notification_channels: Object.fromEntries(Object.entries(props.notificationChannels).map(([type, channels]) => [type, { ...channels }])),
});
const channelNames = { database: 'En la app', push: 'Push', mail: 'Correo' };
const name = ref('Este navegador');
const busy = ref(false);
const messages = ref(/** @type {string[]} */ ([]));
const supported = pushSupported();
const canActivate = computed(() => props.push.enabled && supported && !busy.value);
const ask = useConfirm();
const dates = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' });
/** @param {string | null} value */
const date = (value) => value && Number.isFinite(Date.parse(value)) ? dates.format(Date.parse(value)) : 'Aún no enviado';

function savePreferences() {
    prefs.transform((data) => {
        /** @type {NotificationChannels} */
        const changed = {};
        for (const [type, channels] of Object.entries(data.notification_channels)) {
            for (const channel of /** @type {(keyof typeof channelNames)[]} */ (Object.keys(channels))) {
                if (channels[channel] !== props.notificationChannels[type]?.[channel]) {
                    (changed[type] ??= {})[channel] = channels[channel];
                }
            }
        }
        return { ...data, notification_channels: changed };
    }).patch(route('profile.notification-preferences'), { preserveScroll: true });
}

async function activate() {
    if (!canActivate.value || !props.push.public_key) return;
    busy.value = true;
    messages.value = [];
    try {
        const subscription = await activatePush(props.push.public_key);
        router.post(route('profile.push-subscriptions.store'), { ...subscription, device_name: name.value }, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => { messages.value = Object.values(errors); },
            onFinish: () => { busy.value = false; },
        });
    } catch (error) {
        messages.value = [error instanceof Error ? error.message : 'No se pudo activar este navegador.'];
        busy.value = false;
    }
}

/** @param {PushDevice} device */
async function revoke(device) {
    if (busy.value || !(await ask({ title: 'Revocar dispositivo', message: `¿Dejar de enviar avisos a «${device.device_name}»?`, confirmLabel: 'Revocar' }))) return;
    busy.value = true;
    messages.value = [];
    router.delete(route('profile.push-subscriptions.destroy', device.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (device.current) void revokeBrowserPush().catch(() => {
                messages.value = ['El servidor ha revocado el dispositivo. Revisa también el permiso de este navegador.'];
            });
        },
        onError: (errors) => { messages.value = Object.values(errors); },
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <Head title="Notificaciones del navegador" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900">Notificaciones del navegador</h1></template>
        <div class="max-w-3xl space-y-6">
            <section class="card space-y-4 p-4 sm:p-6" aria-labelledby="prefs-title">
                <h2 id="prefs-title" class="text-lg font-semibold text-slate-900">Qué avisos quieres recibir</h2>
                <form class="space-y-3" @submit.prevent="savePreferences">
                    <label class="flex items-start gap-2 text-sm text-slate-700" for="notify_weekly_digest">
                        <Checkbox
                            id="notify_weekly_digest"
                            v-model:checked="prefs.notify_weekly_digest"
                            data-testid="pref-weekly-digest"
                        />
                        <span
                            >Resumen semanal: cada lunes por la mañana (hora de tu marca principal), las cifras de la
                            semana anterior de cada marca comparadas con la previa.</span
                        >
                    </label>
                    <label
                        class="ml-6 flex items-start gap-2 text-sm text-slate-700"
                        for="weekly_digest_ai"
                        :class="{ 'opacity-60': !aiAvailable }"
                    >
                        <Checkbox
                            id="weekly_digest_ai"
                            v-model:checked="prefs.weekly_digest_ai"
                            :disabled="!aiAvailable"
                            data-testid="pref-weekly-digest-ai"
                        />
                        <span
                            >Añadir un párrafo escrito con IA. Se envía al proveedor solo el nombre de tus marcas y las
                            cifras agregadas.</span
                        >
                    </label>
                    <label class="flex items-start gap-2 text-sm text-slate-700" for="notify_manual_reminder">
                        <Checkbox
                            id="notify_manual_reminder"
                            v-model:checked="prefs.notify_manual_reminder"
                            data-testid="pref-manual-reminder"
                        />
                        <span
                            >Recordatorio de publicación manual: un aviso antes de la hora de una publicación que tienes
                            que hacer a mano.</span
                        >
                    </label>
                    <h3 class="pt-2 text-base font-semibold text-slate-900">Canales por tipo de aviso</h3>
                    <InfoNote>
                        Los correos de verificación, restablecimiento de contraseña y seguridad de la cuenta están
                        siempre activos. Para recibir push, activa cada dispositivo más abajo.
                    </InfoNote>
                    <fieldset
                        v-for="(channels, type) in prefs.notification_channels"
                        :key="type"
                        :disabled="
                            (type === 'weekly_digest' && !prefs.notify_weekly_digest) ||
                            (type === 'manual_reminder' && !prefs.notify_manual_reminder)
                        "
                        class="min-w-0 space-y-2 rounded-md border border-slate-200 px-3 pb-2 disabled:opacity-60"
                    >
                        <legend class="max-w-full px-1 text-sm font-medium text-slate-800">{{
                            notificationTypes[type]
                        }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            <label
                                v-for="(_, channel) in channels"
                                :key="channel"
                                class="flex items-center gap-2 text-sm text-slate-700"
                            >
                                <Checkbox
                                    :checked="channels[channel] ?? false"
                                    @update:checked="channels[channel] = $event === true"
                                />
                                {{ channelNames[channel] }}
                            </label>
                        </div>
                    </fieldset>
                    <InputError v-for="(message, field) in prefs.errors" :key="field" :message="message" />
                    <div class="flex flex-wrap items-center gap-4">
                        <PrimaryButton :disabled="prefs.processing" data-testid="pref-save"
                            >Guardar preferencias</PrimaryButton
                        >
                        <p v-if="prefs.recentlySuccessful" role="status" class="text-sm text-slate-600">Guardado.</p>
                    </div>
                </form>
            </section>
            <section class="card space-y-4 p-4 sm:p-6" aria-labelledby="push-enable-title">
                <h2 id="push-enable-title" class="text-lg font-semibold text-slate-900">Activar avisos push</h2>
                <p class="text-sm text-slate-600"
                    >Recibe un aviso genérico cuando haya novedades. El contenido privado se consulta al entrar en la
                    aplicación. El permiso solo se pide al pulsar Activar.</p
                >
                <p v-if="push.unavailable_reason" class="text-sm text-amber-800">{{ push.unavailable_reason }}</p>
                <p v-else-if="!supported" role="status" class="text-sm text-amber-800"
                    >Este navegador necesita HTTPS y compatibilidad con notificaciones push.</p
                >
                <form class="space-y-3" @submit.prevent="activate">
                    <div
                        ><label for="push-device-name" class="text-sm font-medium text-slate-800"
                            >Nombre de este dispositivo</label
                        >
                        <input
                            id="push-device-name"
                            v-model="name"
                            class="field mt-1 w-full"
                            maxlength="120"
                            required
                            :disabled="busy"
                            autocomplete="off"
                    /></div>
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="!canActivate"
                        >{{ busy ? 'Procesando…' : 'Activar en este navegador' }}</button
                    >
                </form>
                <div v-if="messages.length" role="alert" class="text-sm text-red-700"
                    ><ul class="space-y-1"
                        ><li v-for="(message, index) in messages" :key="index">{{ message }}</li></ul
                    ></div
                >
            </section>
            <section class="space-y-3" aria-labelledby="push-devices-title">
                <h2 id="push-devices-title" class="text-lg font-semibold text-slate-900">Dispositivos suscritos</h2>
                <p v-if="!subscriptions.length" class="text-sm text-slate-600">No tienes dispositivos suscritos.</p>
                <article
                    v-for="device in subscriptions"
                    :key="device.id"
                    class="card flex flex-wrap items-start justify-between gap-4 p-4"
                >
                    <div class="min-w-0"
                        ><h3 class="break-words font-semibold text-slate-900"
                            >{{ device.device_name
                            }}<span v-if="device.current" class="text-sm font-normal"> · Este navegador</span></h3
                        >
                        <p class="text-sm text-slate-600">Última suscripción: {{ date(device.last_seen_at) }}</p>
                        <p class="text-sm text-slate-600">Último envío: {{ date(device.last_sent_at) }}</p></div
                    >
                    <button
                        type="button"
                        class="btn-secondary"
                        :aria-label="`Revocar ${device.device_name}`"
                        :disabled="busy"
                        @click="revoke(device)"
                        >Revocar</button
                    >
                </article>
            </section>
            <p class="text-sm text-slate-600"
                >Puedes instalar la aplicación desde el menú de tu navegador. La disponibilidad depende del navegador y
                del dispositivo.</p
            >
            <Link :href="route('notifications.index')" class="text-sm font-medium text-indigo-700 underline"
                >Ver avisos en la aplicación</Link
            >
        </div>
    </AuthenticatedLayout>
</template>
