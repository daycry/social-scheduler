import { onMounted, onScopeDispose, readonly, ref, toValue, watch } from 'vue';

/** @typedef {{initialDelay: number, maxDelay: number, maxAttempts: number, visibility?: Pick<Document, 'hidden' | 'addEventListener' | 'removeEventListener'>, onExhausted?: () => void}} PollingOptions */
/** @typedef {(signal: AbortSignal) => Promise<unknown>} PollingRequest */

/**
 * run recibe una señal y debe terminar pronto cuando se cancela para que el siguiente sondeo pueda empezar.
 * @param {PollingRequest} run
 * @param {PollingOptions} options
 */
export function createPolling(run, {
    initialDelay, maxDelay, maxAttempts, visibility = globalThis.document, onExhausted = () => {},
}) {
    let active = false;
    let disposed = false;
    /** @type {ReturnType<typeof setTimeout> | null} */
    let timer = null;
    /** @type {AbortController | null} */
    let inFlight = null;
    let attempts = 0;

    function canSchedule() {
        return active && !disposed && !visibility?.hidden && !inFlight && attempts < maxAttempts;
    }

    function clearTimer() {
        clearTimeout(/** @type {ReturnType<typeof setTimeout> | undefined} */ (timer));
        timer = null;
    }

    function schedule() {
        if (timer !== null || !canSchedule()) return;
        timer = setTimeout(check, Math.min(maxDelay, initialDelay * (2 ** attempts)));
    }

    async function check() {
        timer = null;
        if (!canSchedule()) return;
        const request = new AbortController();
        inFlight = request;
        try {
            await run(request.signal);
        } catch {
            // A failed poll consumes an attempt and uses the same backoff as a pending result.
        } finally {
            inFlight = null;
            if (!request.signal.aborted) {
                attempts++;
                if (active && !disposed && attempts === maxAttempts) onExhausted();
            }
            schedule();
        }
    }

    function visibilityChanged() {
        if (visibility.hidden) {
            clearTimer();
            inFlight?.abort();
        } else schedule();
    }

    visibility?.addEventListener('visibilitychange', visibilityChanged);

    return {
        /** @param {boolean} next */
        setActive(next) {
            if (active === next || disposed) return;
            active = next;
            attempts = 0;
            if (active) schedule();
            else {
                clearTimer();
                inFlight?.abort();
            }
        },
        restart() {
            attempts = 0;
            clearTimer();
            inFlight?.abort();
            schedule();
        },
        dispose() {
            disposed = true;
            clearTimer();
            inFlight?.abort();
            visibility?.removeEventListener('visibilitychange', visibilityChanged);
        },
    };
}

/** @param {import("vue").MaybeRefOrGetter<boolean>} enabled @param {PollingRequest} run @param {Omit<PollingOptions, "onExhausted">} options */
export function usePolling(enabled, run, options) {
    const exhausted = ref(false);
    const polling = createPolling(run, { ...options, onExhausted: () => { exhausted.value = true; } });
    let mounted = false;

    onMounted(() => {
        mounted = true;
        polling.setActive(toValue(enabled));
    });
    watch(() => toValue(enabled), active => {
        exhausted.value = false;
        if (mounted) polling.setActive(active);
    });
    onScopeDispose(polling.dispose);

    return {
        exhausted: readonly(exhausted),
        restart() {
            exhausted.value = false;
            polling.restart();
        },
    };
}
