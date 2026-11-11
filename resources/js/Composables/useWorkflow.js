/**
 * Peticiones JSON del flujo de publicación (T-19) con la sesión web: aprobar, rechazar, marcar publicado, reintentar, lotes, series
 * y colaboradores. Devuelve `{ ok, status, data }` sin lanzar: la pantalla decide cómo mostrar el error.
 */
export async function workflowRequest(method, url, body = null, headers = {}) {
    const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
                ...headers,
            },
            body: body === null ? undefined : JSON.stringify(body),
        });
        let data = null;
        try {
            data = await response.json();
        } catch {
            data = null;
        }

        return { ok: response.ok, status: response.status, data };
    } catch {
        return { ok: false, status: 0, data: { message: 'No se pudo conectar. Inténtalo de nuevo.' } };
    }
}

/** Primer mensaje legible de una respuesta de error (422 con `errors`, o `message`). */
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
