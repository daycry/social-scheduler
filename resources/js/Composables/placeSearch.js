// Búsqueda de lugares de Meta para `location_id` (Instagram) y `place` (Facebook). Sin permiso de Meta el servidor responde 422 con el
// motivo y el compositor deja pegar el id a mano: nunca se envía nada a Meta desde el navegador.
export const PLACE_ID_PATTERN = /^\d{1,20}$/;

/** @returns {Promise<{places: Array<{id: string, name: string, detail: ?string}>, error: ?string}>} */
export async function searchPlaces(brandId, accountId, query) {
    const q = String(query ?? '').trim();
    if (q.length < 2) return { places: [], error: 'Escribe al menos 2 caracteres para buscar.' };
    try {
        const response = await fetch(`${route('brands.connections.places', { brand: brandId, account: accountId })}?${new URLSearchParams({ q })}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (response.ok && Array.isArray(body?.places)) return { places: body.places, error: null };
        const message = typeof body?.message === 'string' && body.message.trim() ? body.message : 'No se pudo buscar lugares. Pega el id del lugar a mano.';
        return { places: [], error: message };
    } catch {
        return { places: [], error: 'No se pudo buscar lugares. Pega el id del lugar a mano.' };
    }
}
