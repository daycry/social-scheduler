/** Parámetros UTM, en el orden en que se escriben en la URL. `required` marca los que hacen falta para poder añadir. */
export const UTM_FIELDS = [
    { key: 'utm_source', label: 'Fuente', required: true, hint: 'Dónde se publica, por ejemplo instagram' },
    { key: 'utm_medium', label: 'Medio', required: true, hint: 'Tipo de canal, por ejemplo social' },
    { key: 'utm_campaign', label: 'Campaña', required: true, hint: 'Nombre de la campaña, por ejemplo rebajas-otono' },
    { key: 'utm_term', label: 'Término', required: false, hint: 'Palabra clave (opcional)' },
    { key: 'utm_content', label: 'Contenido', required: false, hint: 'Para distinguir enlaces o anuncios (opcional)' },
];

/** Interpreta una URL y solo acepta http(s): `javascript:`, `data:`, `file:`… y las rutas relativas se rechazan. */
export function parseHttpUrl(raw) {
    const value = String(raw ?? '').trim();
    if (!value || /[\u0000-\u001f\s]/.test(value)) return null;
    let url;
    try {
        url = new URL(value);
    } catch {
        return null;
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
    return url.hostname ? url : null;
}

/**
 * Construye la URL con UTM con `URL` y `URLSearchParams` (los valores se codifican solos: espacios, `&`, `=`, acentos…). Conserva
 * los parámetros y el `#fragmento` que ya traía y reemplaza (no duplica) los `utm_*` de este generador. Devuelve `null` si la URL
 * no es http(s) o falta un parámetro obligatorio.
 */
export function buildUtmUrl(rawUrl, params) {
    const url = parseHttpUrl(rawUrl);
    if (!url) return null;
    const values = Object.fromEntries(UTM_FIELDS.map((f) => [f.key, String(params?.[f.key] ?? '').trim()]));
    if (UTM_FIELDS.some((f) => f.required && values[f.key] === '')) return null;
    // La query original se conserva tal cual (sin re-serializar): solo se quitan los pares `utm_*` de este generador del texto crudo.
    const owned = new Set(UTM_FIELDS.map((f) => f.key));
    const kept = url.search.replace(/^\?/, '').split('&').filter((pair) => {
        if (pair === '') return false;
        let name = pair.split('=')[0];
        try { name = decodeURIComponent(name.replace(/\+/g, ' ')); } catch { /* nombre mal codificado: se conserva */ }
        return !owned.has(name);
    });
    const added = UTM_FIELDS.filter((f) => values[f.key] !== '').map((f) => `${f.key}=${encodeURIComponent(values[f.key])}`);
    const query = [...kept, ...added].join('&');
    const hash = url.hash;
    url.hash = '';
    url.search = '';
    return `${url.toString()}?${query}${hash}`;
}
