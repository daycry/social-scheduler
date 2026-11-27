<script setup>
/** @import { CalendarFeedState } from '@/types/calendar' */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import InfoNote from '@/Components/InfoNote.vue';
import { useConfirm } from '@/Composables/useConfirm';

const props = defineProps({
    feed: { type: /** @type {import('vue').PropType<CalendarFeedState>} */ (Object), required: true },
    marketingHidden: { type: Boolean, required: true },
});

const ask = useConfirm();
const busy = ref(false);
// La URL secreta llega una sola vez en las props (el servidor solo guarda el hash). Se copia a estado local para que siga visible mientras el
// usuario no salga de la página, aunque otra petición (p. ej. cambiar la casilla) recargue las props; se descarta al revocar el enlace.
const shownUrl = ref(props.feed.url);
watch(
    () => props.feed,
    (feed) => {
        if (feed.url) shownUrl.value = feed.url;
        else if (!feed.active) shownUrl.value = null;
    },
);
const copied = ref(false);
const copyFailed = ref(false);
const dates = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' });
/** @param {string | null} value */
const generatedLabel = (value) => (value && Number.isFinite(Date.parse(value)) ? dates.format(Date.parse(value)) : '');

/** @param {Event} event */
const toggleMarketing = (event) => {
    const input = event.currentTarget;
    if (!(input instanceof HTMLInputElement)) return;
    router.patch(
        route('calendar.marketing-dates.update'),
        { hidden: !input.checked },
        {
            preserveScroll: true,
            preserveState: true,
            // Si la petición falla, la casilla vuelve al estado del servidor en vez de quedar desincronizada.
            onFinish: () => {
                input.checked = !props.marketingHidden;
            },
        },
    );
};

const finish = () => {
    busy.value = false;
};

const create = () => {
    if (busy.value) return;
    busy.value = true;
    copied.value = false;
    router.post(route('calendar.feed.store'), {}, { preserveScroll: true, preserveState: true, onFinish: finish });
};

const regenerate = async () => {
    if (busy.value) return;
    const accepted = await ask({
        title: 'Regenerar el enlace',
        message: 'El enlace actual dejará de funcionar y tendrás que volver a suscribir tu calendario con el nuevo. ¿Regenerarlo?',
        confirmLabel: 'Regenerar',
    });
    if (accepted) create();
};

const revoke = async () => {
    if (busy.value) return;
    const accepted = await ask({
        title: 'Revocar el enlace',
        message: 'El enlace dejará de funcionar y tu calendario externo dejará de actualizarse. ¿Revocarlo?',
        confirmLabel: 'Revocar',
    });
    if (!accepted) return;
    busy.value = true;
    router.delete(route('calendar.feed.destroy'), { preserveScroll: true, preserveState: true, onFinish: finish });
};

/** @param {FocusEvent} event */
const selectAll = (event) => {
    if (event.currentTarget instanceof HTMLInputElement) event.currentTarget.select();
};

const copy = async () => {
    try {
        await navigator.clipboard.writeText(shownUrl.value ?? '');
        copyFailed.value = false;
        copied.value = true;
    } catch {
        // Sin Clipboard API (origen http no seguro) o permiso denegado: se dice, en vez de no hacer nada.
        copied.value = false;
        copyFailed.value = true;
    }
};
</script>

<template>
    <section class="card space-y-3 p-3 sm:p-4" aria-labelledby="calendar-feed-title" data-testid="calendar-feed-panel">
        <h2 id="calendar-feed-title" class="text-sm font-semibold text-slate-900">Fechas señaladas y suscripción</h2>

        <label class="flex items-start gap-2 text-sm text-slate-700" for="show-marketing-dates">
            <Checkbox id="show-marketing-dates" :checked="!marketingHidden" @change="toggleMarketing" />
            <span>
                Mostrar las fechas señaladas (San Valentín, Black Friday, Día de la Madre…)
                <span class="block text-xs text-slate-600"
                    >Se ven en el calendario y también en tu enlace de suscripción.</span
                >
            </span>
        </label>

        <div class="space-y-2 border-t border-slate-100 pt-3">
            <p class="text-sm font-medium text-slate-800"
                >Ver tus publicaciones en Google Calendar, Outlook o Apple Calendar</p
            >
            <InfoNote>
                El enlace es secreto: quien lo tenga puede ver el resumen de tus publicaciones programadas y publicadas.
                Solo contiene las tuyas.
            </InfoNote>

            <button
                v-if="!feed.active"
                type="button"
                class="btn btn-secondary px-3 py-1.5 text-sm"
                :disabled="busy"
                @click="create"
            >
                Crear enlace de suscripción
            </button>
            <template v-else>
                <div v-if="shownUrl" class="space-y-1" data-testid="calendar-feed-new">
                    <label for="calendar-feed-url" class="block text-xs font-medium text-slate-700">
                        Tu enlace (cópialo ahora: no se vuelve a mostrar)
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            id="calendar-feed-url"
                            type="text"
                            readonly
                            class="min-w-0 flex-1 rounded-lg border-slate-300 text-xs"
                            :value="shownUrl"
                            @focus="selectAll"
                        />
                        <button type="button" class="btn btn-secondary px-3 py-1.5 text-sm" @click="copy"
                            >Copiar enlace</button
                        >
                    </div>
                    <p v-if="copied" role="status" class="text-xs text-emerald-800">Enlace copiado.</p>
                    <p v-if="copyFailed" role="status" class="text-xs text-amber-800"
                        >No se pudo copiar: selecciona el enlace y cópialo a mano.</p
                    >
                </div>
                <p v-else class="text-xs text-slate-600" data-testid="calendar-feed-active">
                    Enlace activo{{ feed.generated_at ? `, creado el ${generatedLabel(feed.generated_at)}` : '' }}. Por
                    seguridad solo se muestra al crearlo: si lo has perdido, regenera uno nuevo.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="btn btn-secondary px-3 py-1.5 text-sm"
                        :disabled="busy"
                        @click="regenerate"
                    >
                        Regenerar enlace
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary px-3 py-1.5 text-sm"
                        :disabled="busy"
                        @click="revoke"
                    >
                        Revocar enlace
                    </button>
                </div>
            </template>
        </div>
    </section>
</template>
