import { inject, onMounted, onScopeDispose, provide, readonly, ref } from 'vue';
import { HttpError, requestJson } from '../lib/http.js';

/** @type {import('vue').InjectionKey<ReturnType<typeof createAiStatus>>} */
const aiStatusKey = Symbol('aiStatus');

/** @typedef {Extract<keyof import('ziggy-js').RouteList, `ai.${string}`>} AiRoute */
/** @typedef {{enabled?: boolean, remaining?: number | null, vision?: boolean}} AiStatusPayload */

/** @param {{transport?: typeof requestJson, resolveRoute?: (name: AiRoute) => string}} [options] */
export function createAiStatus({ transport = requestJson, resolveRoute = name => route(name) } = {}) {
    const enabled = ref(false);
    // El modelo configurado admite imágenes (texto alternativo).
    const vision = ref(false);
    // Hasta que llega el estado no se sabe si la IA está configurada: la UI no debe mostrar «no configurada» antes de tiempo.
    const loaded = ref(false);
    const remaining = ref(0);
    const lifecycle = new AbortController();
    /** @type {Promise<void> | undefined} */
    let statusRequest;

    /** @param {{remaining?: number | null} | null} data */
    function syncRemaining(data) {
        if (data?.remaining != null) remaining.value = data.remaining;
    }

    async function fetchStatus() {
        try {
            const data = /** @type {AiStatusPayload | null} */ (await transport('GET', resolveRoute('ai.status'), { signal: lifecycle.signal }));
            enabled.value = Boolean(data?.enabled);
            vision.value = Boolean(data?.vision);
            syncRemaining(data);
        } catch {
            enabled.value = false;
            vision.value = false;
        } finally {
            loaded.value = true;
        }
    }

    /**
     * @param {AiRoute} name
     * @param {unknown} json
     * @param {{errorMessage?: import('../lib/http.js').ErrorMessage}} [options]
     */
    async function call(name, json, { errorMessage } = {}) {
        try {
            const data = await transport('POST', resolveRoute(name), { json, errorMessage, signal: lifecycle.signal });
            syncRemaining(/** @type {AiStatusPayload | null} */ (data));
            return data;
        } catch (error) {
            if (error instanceof HttpError) syncRemaining(/** @type {AiStatusPayload | null} */ (error.data));
            throw error;
        }
    }

    return {
        enabled: readonly(enabled),
        vision: readonly(vision),
        loaded: readonly(loaded),
        remaining: readonly(remaining),
        load: () => (statusRequest ??= fetchStatus()),
        call,
        cancel: () => lifecycle.abort(),
    };
}

function localStatus() {
    const status = createAiStatus();
    onMounted(status.load);
    onScopeDispose(status.cancel);
    return status;
}

export function provideAiStatus() {
    provide(aiStatusKey, localStatus());
}

export function useAiStatus() {
    return inject(aiStatusKey, null) ?? localStatus();
}
