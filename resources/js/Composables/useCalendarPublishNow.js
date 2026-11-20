import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/** @import { CalendarItem, ScheduledCalendarItem, UndatedCalendarItem } from '@/types/calendar' */
/** @import { SessionFlash } from '@/types/shell' */

/**
 * @param {{
 *     getItems: () => ScheduledCalendarItem[],
 *     getUndated: () => UndatedCalendarItem[],
 *     getFlash: () => SessionFlash | undefined,
 * }} options
 */
export function useCalendarPublishNow({ getItems, getUndated, getFlash }) {
    // Confirmación -> POST posts/{id}/publish-now. La tarjeta muestra «Enviando…» mientras la petición va y, después, hasta que el
    // worker reclama el destino (el estado deja de ser borrador/programada): entre tanto se refresca el calendario cada pocos segundos.
    /** @type {import('vue').Ref<CalendarItem | null>} */
    const confirming = ref(null);
    /** @type {import('vue').Ref<HTMLElement | SVGElement | null>} */
    const opener = ref(null);
    /** @type {import('vue').Ref<Set<number>>} */
    const sending = ref(new Set());
    /** @param {CalendarItem} item @param {Event} [event] */
    const askPublishNow = (item, event) => {
        const target = event?.currentTarget;
        opener.value = target instanceof HTMLElement || target instanceof SVGElement ? target : null;
        confirming.value = item;
    };
    const PENDING = ['borrador', 'programada'];
    /** @type {ReturnType<typeof setInterval> | undefined} */
    let pollTimer;
    let polls = 0;
    const stopPolling = () => {
        clearInterval(pollTimer);
        pollTimer = undefined;
    };
    const startPolling = () => {
        if (pollTimer) return;
        polls = 0;
        pollTimer = setInterval(() => {
            if (sending.value.size === 0) return stopPolling();
            if (++polls > 15) {
                // Se agotaron las vueltas: la tarjeta deja de decir «Enviando…» aunque el estado no haya cambiado.
                sending.value = new Set();
                return stopPolling();
            }
            router.reload({ only: ['items', 'undated', 'flash'] });
        }, 4000);
    };
    /** @param {CalendarItem} item */
    const publishNow = (item) => {
        confirming.value = null;
        sending.value = new Set(sending.value).add(item.id);
        router.post(route('posts.publish-now', item.id), {}, {
            preserveScroll: true,
            onSuccess: () => startPolling(),
            onError: () => stopSending(item.id),
        });
    };
    /** @param {number} id */
    const stopSending = (id) => {
        const next = new Set(sending.value);
        next.delete(id);
        sending.value = next;
    };
    // Un error del servidor (flash.error) o un estado distinto de pendiente liberan la tarjeta.
    watch(() => [getItems(), getUndated(), getFlash()?.error], () => {
        const all = [...getItems(), ...getUndated()];
        for (const id of sending.value) {
            const item = all.find((i) => i.id === id);
            if (!item || !PENDING.includes(item.status) || getFlash()?.error) stopSending(id);
        }
    });
    // «Publicar ahora» desde el compositor redirige aquí con el id del post: se sondea igual que desde el calendario.
    onMounted(() => {
        const id = getFlash()?.publishing_post_id;
        if (id) {
            sending.value = new Set(sending.value).add(Number(id));
            startPolling();
        }
    });
    onBeforeUnmount(stopPolling);

    return { confirming, opener, sending, askPublishNow, publishNow };
}
