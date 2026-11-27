import { ACCOUNT_MODULE, activeModule, sideItems, visibleModules } from '../navigation.js';
import { HttpError, requestJson } from './http.js';

/**
 * Lógica pura de la paleta de comandos (T-85): páginas y acciones sacadas de `navigation.js` (la fuente única del menú), filtrado sin tildes,
 * agrupación, movimiento por teclado y la búsqueda remota con debounce que descarta las respuestas fuera de orden.
 */

/**
 * @typedef {import('../navigation.js').NavigationContext} NavigationContext
 * @typedef {{type: string, id: number, title: string, brand: string | null, date: string | null, status: string | null, url: string, external: boolean}} SearchResult
 * @typedef {{key: string, type: string, title: string, meta: string, url: string, external: boolean, icon?: string, network?: string}} PaletteEntry
 * @typedef {PaletteEntry & {index: number}} IndexedEntry
 * @typedef {{type: string, label: string, entries: IndexedEntry[]}} PaletteGroup
 * @typedef {{status: 'idle' | 'loading' | 'ready' | 'error', query: string, results: SearchResult[], error: string}} RemoteState
 */

export const MIN_LENGTH = 2;
export const MAX_LENGTH = 100;
export const DEBOUNCE_MS = 250;
export const PAGE_LIMIT = 8;

const PAGE_TYPE = 'page';

/** Encabezado de cada grupo, en el orden en que se muestran. */
export const GROUP_LABELS = /** @type {const} */ ({
    page: 'Páginas y acciones',
    post: 'Publicaciones',
    brand: 'Marcas',
    design: 'Diseños',
    media: 'Archivos',
    campaign: 'Campañas',
    template: 'Plantillas',
    site: 'Sitios',
    smartlink: 'Smart links',
});

/** Icono de cada tipo de resultado del servidor. */
export const TYPE_ICONS = /** @type {Record<string, string>} */ ({
    post: 'doc',
    brand: 'tag',
    design: 'palette',
    media: 'folder',
    campaign: 'megaphone',
    template: 'bookmark',
    site: 'grid',
    smartlink: 'link',
});

/** Minúsculas y sin tildes, para comparar «Campañas» con «campanas». @param {string} text */
export function fold(text) {
    return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

/**
 * Páginas y acciones de la app tal y como las muestra el menú: mismas condiciones (`when`), mismos permisos y mismos enlaces, porque se
 * resuelven con `sideItems` de `navigation.js`, con las del módulo actual primero. Sin entradas repetidas (varias secciones enlazan la misma pantalla con el mismo nombre).
 * @param {NavigationContext} context
 * @returns {PaletteEntry[]}
 */
export function pageCommands(context) {
    /** @type {Map<string, PaletteEntry>} */
    const entries = new Map();
    // El módulo en el que estás va primero: sus pantallas caben siempre en la primera lista, aunque el menú tenga muchas.
    const current = activeModule(context);
    const modules = [...visibleModules(context), ACCOUNT_MODULE];
    for (const module of [current, ...modules.filter((candidate) => candidate !== current)]) {
        for (const item of sideItems(module, context)) {
            const key = `${module.key}:${item.key}`;
            const url = item.href;
            if (entries.has(`${url}|${item.label}`)) continue;
            entries.set(`${url}|${item.label}`, {
                key: `page-${key}`,
                type: PAGE_TYPE,
                title: item.label,
                meta: module.label,
                url,
                external: false,
                ...(item.network !== undefined ? { network: item.network } : { icon: item.icon }),
            });
        }
    }
    return [...entries.values()];
}

/**
 * Filtra las páginas por todas las palabras de la consulta (sin tildes ni mayúsculas); primero las que empiezan por ella y luego las que la
 * contienen en el título. Sin consulta devuelve las primeras.
 * @param {PaletteEntry[]} pages
 * @param {string} query
 * @param {number} [limit]
 */
export function filterPages(pages, query, limit = PAGE_LIMIT) {
    const words = fold(query).split(/\s+/).filter(Boolean);
    if (words.length === 0) return pages.slice(0, limit);
    return pages
        .map((page, order) => {
            const title = fold(page.title);
            const haystack = `${title} ${fold(page.meta)}`;
            const rank = title.startsWith(words[0]) ? 0 : title.includes(words[0]) ? 1 : 2;
            return { page, order, rank, match: words.every((word) => haystack.includes(word)) };
        })
        .filter((candidate) => candidate.match)
        .sort((a, b) => a.rank - b.rank || a.order - b.order)
        .slice(0, limit)
        .map((candidate) => candidate.page);
}

/**
 * Fecha local de la marca (`2026-11-19 20:30` o `2026-11-19`) en texto legible. El servidor ya la dio en la zona de la marca: aquí no se
 * convierte nada, solo se escribe.
 * @param {string | null} value
 */
export function formatLocalDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?$/.exec(value ?? '');
    if (!match) return '';
    const [, year, month, day, hours, minutes] = match;
    // Se construye y se escribe en UTC: la hora de la marca no pasa por la zona del navegador (que podría saltarse esa hora en un cambio de horario).
    const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hours ?? 0), Number(minutes ?? 0)));
    return new Intl.DateTimeFormat('es', { timeZone: 'UTC', dateStyle: 'medium', ...(hours === undefined ? {} : { timeStyle: 'short' }) }).format(date);
}

/**
 * @param {SearchResult} result
 * @returns {PaletteEntry}
 */
export function resultEntry(result) {
    return {
        key: `${result.type}-${result.id}`,
        type: result.type,
        title: result.title,
        meta: [result.brand, formatLocalDate(result.date), result.status].filter(Boolean).join(' · '),
        url: result.url,
        external: result.external,
        icon: TYPE_ICONS[result.type] ?? 'grid',
    };
}

/**
 * Páginas primero y luego el contenido del usuario, agrupado por tipo en el orden de `GROUP_LABELS`, con un índice continuo para el teclado.
 * @param {PaletteEntry[]} pages
 * @param {SearchResult[]} results
 * @returns {PaletteGroup[]}
 */
export function groupEntries(pages, results) {
    const entries = [...pages, ...results.map(resultEntry)];
    let index = 0;
    return Object.entries(GROUP_LABELS)
        .map(([type, label]) => ({
            type,
            label,
            entries: entries.filter((entry) => entry.type === type).map((entry) => ({ ...entry, index: index++ })),
        }))
        .filter((group) => group.entries.length > 0);
}

/**
 * Opción activa tras una flecha (con vuelta al otro extremo). `-1` si la tecla no mueve nada: Inicio y Fin son del cursor del campo (combobox editable).
 * @param {number} current
 * @param {string} key
 * @param {number} count
 */
export function nextIndex(current, key, count) {
    if (count === 0) return -1;
    if (key === 'ArrowDown') return (current + 1) % count;
    if (key === 'ArrowUp') return (current - 1 + count) % count;
    return -1;
}

/**
 * Opción activa tras cambiar la lista: la misma si sigue en ella; si no, la primera.
 * @param {{key: string}[]} entries
 * @param {string | undefined} key clave de la opción que estaba activa
 */
export function restoreIndex(entries, key) {
    return Math.max(0, entries.findIndex((entry) => entry.key === key));
}

/**
 * Longitud de la consulta en puntos de código (como `mb_strlen` del servidor), no en unidades UTF-16: «😀» cuenta 1.
 * @param {string} text
 */
export function queryLength(text) {
    return [...text.trim()].length;
}

/** @param {unknown} error */
function searchErrorMessage(error) {
    if (error instanceof HttpError && error.status === 429) return 'Demasiadas búsquedas seguidas. Espera un momento.';
    return 'No se pudo buscar. Inténtalo de nuevo.';
}

/**
 * Petición real al servidor.
 * @param {string} query
 * @param {AbortSignal} signal
 * @returns {Promise<SearchResult[]>}
 */
export async function fetchResults(query, signal) {
    const data = /** @type {{results: SearchResult[]}} */ (await requestJson('GET', route('search', { q: query }, false), { signal }));
    return data.results;
}

/**
 * Búsqueda remota con debounce. Cada cambio de texto cancela el temporizador y la petición anterior (AbortController) y avanza una secuencia:
 * una respuesta que llega tras otra más reciente (o tras cerrar) se descarta aunque el transporte no respete la cancelación.
 * @param {{request: (query: string, signal: AbortSignal) => Promise<SearchResult[]>, onChange: (state: RemoteState) => void, delay?: number, setTimer?: typeof setTimeout, clearTimer?: typeof clearTimeout}} options
 */
export function createRemoteSearch({ request, onChange, delay = DEBOUNCE_MS, setTimer = setTimeout, clearTimer = clearTimeout }) {
    /** @type {ReturnType<typeof setTimeout> | null} */
    let timer = null;
    /** @type {AbortController | null} */
    let controller = null;
    let sequence = 0;
    /** @type {RemoteState} */
    const state = { status: 'idle', query: '', results: [], error: '' };

    /** @param {Partial<RemoteState>} patch */
    const publish = (patch) => {
        Object.assign(state, patch);
        onChange({ ...state });
    };
    const cancel = () => {
        if (timer !== null) clearTimer(timer);
        timer = null;
        controller?.abort();
        controller = null;
    };

    /** @param {string} query @param {number} id */
    async function run(query, id) {
        const mine = new AbortController();
        controller = mine;
        try {
            const results = await request(query, mine.signal);
            if (id === sequence) publish({ status: 'ready', results });
        } catch (error) {
            if (id === sequence && !mine.signal.aborted) publish({ status: 'error', results: [], error: searchErrorMessage(error) });
        } finally {
            if (controller === mine) controller = null;
        }
    }

    return {
        /** @param {string} text */
        update(text) {
            const query = [...text.trim()].slice(0, MAX_LENGTH).join('');
            sequence++;
            cancel();
            if (queryLength(query) < MIN_LENGTH) {
                publish({ status: 'idle', query, results: [], error: '' });
                return;
            }
            publish({ status: 'loading', query, results: [], error: '' });
            const id = sequence;
            timer = setTimer(() => {
                timer = null;
                run(query, id);
            }, delay);
        },
        close() {
            sequence++;
            cancel();
            publish({ status: 'idle', query: '', results: [], error: '' });
        },
    };
}
