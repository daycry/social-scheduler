import { onScopeDispose, ref, watch } from 'vue';
import { HttpError, requestJson } from '../lib/http.js';

/** @import { AvailableBestTimeSuggestion, BestTimeContext, BestTimeSuggestion } from '../types/best-time' */

/**
 * @param {() => BestTimeContext} getContext
 * @param {(value: AvailableBestTimeSuggestion) => void} apply
 */
export function useBestTimeSuggestion(getContext, apply) {
    const busy = ref(false);
    const notice = ref('');
    const error = ref('');
    /** @type {AbortController | undefined} */
    let activeRequest;
    let disposed = false;
    let dateRevision = 0;
    /** @type {string | undefined} */
    let appliedDate;
    watch(
        () => getContext().value,
        (value) => {
            dateRevision++;
            if (!activeRequest && appliedDate !== undefined && value !== appliedDate) {
                notice.value = '';
                appliedDate = undefined;
            }
        },
        { flush: 'sync' },
    );

    function reset() {
        activeRequest?.abort();
        activeRequest = undefined;
        appliedDate = undefined;
        busy.value = false;
        notice.value = '';
        error.value = '';
    }

    watch(
        [
            () => getContext().brandId,
            () => getContext().network,
            () => getContext().timezone,
            () => getContext().disabled,
        ],
        reset,
    );
    onScopeDispose(() => {
        disposed = true;
        reset();
    });

    async function suggest() {
        const snapshot = { ...getContext() };
        const revision = dateRevision;
        if (disposed || busy.value || snapshot.disabled || snapshot.brandId === null || !snapshot.network) return;
        const request = new AbortController();
        activeRequest = request;
        appliedDate = undefined;
        busy.value = true;
        notice.value = '';
        error.value = '';
        try {
            const data = /** @type {BestTimeSuggestion | null} */ (
                await requestJson(
                    'GET',
                    route('best-times.suggest', {
                        brand: snapshot.brandId,
                        network: snapshot.network,
                    }),
                    { signal: request.signal },
                )
            );
            if (activeRequest !== request) return;
            const current = getContext();
            if (
                current.disabled ||
                current.brandId !== snapshot.brandId ||
                current.network !== snapshot.network ||
                current.timezone !== snapshot.timezone
            )
                return;
            if (
                !data ||
                typeof data.available !== 'boolean' ||
                data.brand_id !== snapshot.brandId ||
                data.network !== snapshot.network ||
                data.timezone !== snapshot.timezone
            ) {
                throw new Error('Invalid suggestion context');
            }
            if (!data.available) {
                notice.value = data.reason || 'No hay datos suficientes para proponer una fecha.';
                return;
            }
            if (
                typeof data.scheduled_at !== 'string' ||
                !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(data.scheduled_at) ||
                typeof data.scheduled_at_utc !== 'string' ||
                !Number.isFinite(Date.parse(data.scheduled_at_utc)) ||
                Date.parse(data.scheduled_at_utc) <= Date.now()
            ) {
                throw new Error('Invalid or expired suggestion');
            }
            if (dateRevision !== revision || current.value !== snapshot.value) {
                notice.value = 'No se ha aplicado la propuesta porque cambiaste la fecha.';
                return;
            }
            appliedDate = data.scheduled_at;
            notice.value = `Fecha propuesta: ${data.scheduled_at.replace('T', ' ')} (${data.timezone}).`;
            apply(data);
        } catch (failure) {
            if (activeRequest !== request) return;
            notice.value = '';
            error.value =
                failure instanceof HttpError
                    ? failure.message
                    : 'No se pudo obtener una propuesta válida. Inténtalo de nuevo.';
        } finally {
            if (activeRequest === request) {
                activeRequest = undefined;
                busy.value = false;
            }
        }
    }

    return { busy, notice, error, suggest };
}
