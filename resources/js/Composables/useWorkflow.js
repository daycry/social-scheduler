import { HttpError, sessionFetch } from '../lib/http.js';

/** @typedef {{ message?: string, errors?: Record<string, string | string[]> }} WorkflowErrorData */
/** @typedef {{ ok: false, status: number, data: WorkflowErrorData | null, error?: HttpError }} WorkflowFailure */
/**
 * @template T
 * @typedef {{ ok: true, status: number, data: T | null } | WorkflowFailure} WorkflowResult
 */

/**
 * Peticiones JSON del flujo de publicación (T-19) con la sesión web: aprobar, rechazar, marcar publicado, reintentar, lotes, series
 * y colaboradores. Devuelve el estado y los datos de la respuesta, si los hay, sin lanzar.
 * @template [T=unknown]
 * @param {string} method
 * @param {string} url
 * @param {unknown} [body]
 * @param {HeadersInit} [headers]
 * @returns {Promise<WorkflowResult<T>>}
 */
export async function workflowRequest(method, url, body = null, headers = {}) {
    try {
        const response = await sessionFetch(method, url, { json: body === null ? undefined : body, headers });
        /** @type {unknown} */
        let data = null;
        try {
            data = await response.json();
        } catch {
            data = null;
        }

        if (!response.ok) {
            const failure = /** @type {WorkflowErrorData | null} */ (data);
            const error = new HttpError(failure?.message ?? 'No se pudo completar la acción.', response.status, failure);
            return { ok: false, status: response.status, data: failure, error };
        }
        return { ok: true, status: response.status, data: /** @type {T | null} */ (data) };
    } catch {
        return { ok: false, status: 0, data: { message: 'No se pudo conectar. Inténtalo de nuevo.' } };
    }
}

/**
 * Primer mensaje legible de una respuesta de error (422 con `errors`, o `message`).
 * @param {WorkflowFailure | null | undefined} result
 * @param {string} [fallback]
 */
export function workflowError(result, fallback = 'No se pudo completar la acción.') {
    const errors = result?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    if (result?.status === 429) return 'Demasiadas peticiones. Espera un momento.';

    return result?.data?.message ?? fallback;
}

/** Clave de idempotencia nueva para una acción de lote. */
export function newIdempotencyKey() {
    return `web-${crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
}
