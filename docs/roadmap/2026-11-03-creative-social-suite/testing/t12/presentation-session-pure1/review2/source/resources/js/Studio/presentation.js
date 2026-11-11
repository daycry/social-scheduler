import { validateDocument } from './document.js';

const OPTION_FIELDS = ['pageIds', 'startPageId', 'loop'];
const fail = message => { throw new TypeError(message); };

function captureOptions(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)
        || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail('Opciones de presentación propias requeridas.');
    const result = {};
    for (const key of Reflect.ownKeys(value)) {
        const descriptor = Object.getOwnPropertyDescriptor(value, key);
        if (!OPTION_FIELDS.includes(key) || !descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) fail('Opciones de presentación de datos propios requeridas.');
        result[key] = descriptor.value;
    }
    if (Object.hasOwn(result, 'loop') && typeof result.loop !== 'boolean') fail('loop debe ser booleano.');
    return result;
}

function capturePageIds(value) {
    if (!Array.isArray(value) || ![Array.prototype, null].includes(Object.getPrototypeOf(value))) fail('La selección de páginas debe ser una lista propia.');
    const descriptors = Object.getOwnPropertyDescriptors(value);
    const length = descriptors.length.value;
    if (length < 1 || length > 100 || Reflect.ownKeys(descriptors).length !== length + 1) fail('Selecciona entre 1 y 100 páginas distintas.');
    const result = [];
    for (let index = 0; index < length; index++) {
        const descriptor = descriptors[index];
        if (!descriptor?.enumerable || !Object.hasOwn(descriptor, 'value') || typeof descriptor.value !== 'string') fail('La selección de páginas debe contener IDs propios sin huecos.');
        result.push(descriptor.value);
    }
    if (new Set(result).size !== result.length) fail('Selecciona páginas distintas.');
    return result;
}

/** A presentation clock driven explicitly by its UI, with no timers or browser ownership. */
export function createPresentationSession(document, options = {}) {
    const captured = captureOptions(options);
    const valid = validateDocument(document);
    const byId = new Map(valid.pages.map(page => [page.id, page]));
    const pageIds = Object.freeze(Object.hasOwn(captured, 'pageIds') ? capturePageIds(captured.pageIds) : valid.pages.map(page => page.id));
    const pages = pageIds.map(id => {
        const page = byId.get(id);
        if (!page) fail('La página seleccionada no existe en el documento.');
        return { id, name: page.name, durationMs: page.duration * 1000 };
    });
    let index = Object.hasOwn(captured, 'startPageId') ? pageIds.indexOf(captured.startPageId) : 0;
    if (index < 0) fail('La página inicial debe pertenecer a la selección.');
    const loop = captured.loop ?? false;
    const totalDuration = pages.reduce((total, page) => total + page.durationMs, 0);
    let elapsedMs = 0;
    let playing = false;
    let ended = false;
    let blackout = false;
    let disposed = false;

    function snapshot() {
        return Object.freeze({ pageIds, index, pageId: pages[index].id, pageName: pages[index].name,
            nextPageId: pages[index + 1]?.id ?? (loop ? pages[0].id : null), elapsedMs,
            durationMs: pages[index].durationMs, playing, ended, blackout, disposed });
    }
    function active() { if (disposed) fail('La sesión cerrada no admite más comandos.'); }
    function move(nextIndex) { index = nextIndex; elapsedMs = 0; ended = false; return snapshot(); }
    function finish() { index = pages.length - 1; elapsedMs = pages[index].durationMs; playing = false; ended = true; return snapshot(); }

    return Object.freeze({
        snapshot,
        play() { active(); if (ended) move(0); playing = true; return snapshot(); },
        pause() { active(); playing = false; return snapshot(); },
        next() { active(); return index + 1 < pages.length ? move(index + 1) : loop ? move(0) : finish(); },
        previous() { active(); return move(index > 0 ? index - 1 : loop ? pages.length - 1 : 0); },
        first() { active(); return move(0); },
        last() { active(); return move(pages.length - 1); },
        select(pageId) {
            active(); const selected = pageIds.indexOf(pageId);
            if (selected < 0) fail('La página seleccionada no pertenece a esta presentación.');
            return move(selected);
        },
        advance(deltaMs) {
            active();
            if (typeof deltaMs !== 'number' || !Number.isFinite(deltaMs) || deltaMs < 0 || deltaMs > Number.MAX_SAFE_INTEGER) fail('El tiempo delta debe ser milisegundos finitos no negativos y seguros.');
            if (!playing || deltaMs === 0) return snapshot();
            // Whole cycles leave the current page and fractional offset unchanged.
            // Reduce the delta before adding it to that offset, so huge deltas stay precise.
            let remaining = loop ? deltaMs % totalDuration : deltaMs;
            if (remaining === 0) return snapshot();
            // Consume only forward from the current page. Reconstructing an absolute
            // position by summing/subtracting earlier durations can round back a page.
            while (remaining >= pages[index].durationMs - elapsedMs) {
                remaining -= pages[index].durationMs - elapsedMs;
                if (index + 1 === pages.length && !loop) return finish();
                index = (index + 1) % pages.length;
                elapsedMs = 0;
            }
            elapsedMs += remaining;
            return snapshot();
        },
        setBlackout(value) {
            active(); if (typeof value !== 'boolean') fail('Blackout debe ser booleano.');
            blackout = value; return snapshot();
        },
        dispose() { playing = false; disposed = true; return snapshot(); },
    });
}
