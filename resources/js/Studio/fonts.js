import { StudioDocumentError } from './document.js';

export const STUDIO_FONT_LIMITS = Object.freeze({ fileBytes: 8 * 1024 * 1024, documentBytes: 32 * 1024 * 1024, cacheBytes: 64 * 1024 * 1024, faces: 64 });
const stores = new WeakMap();
const list = fonts => fonts instanceof Map ? fonts : new Map((Array.isArray(fonts) ? fonts : Object.values(fonts ?? {})).map(font => [Number(font.id), font]));
const elementStyle = element => element.type === 'chart' ? element.chart?.style ?? {} : element;
let clock = 0;

export function studioFontFamily(font) {
    if (!Number.isSafeInteger(Number(font?.id)) || Number(font.id) < 1 || !/^[a-f0-9]{64}$/i.test(font?.sha256 ?? '')) throw new StudioDocumentError('La fuente privada no tiene una identidad válida.');
    return `StudioFont_${Number(font.id)}_${font.sha256.toLowerCase().slice(0, 16)}`;
}

function checked(font) {
    const family = studioFontFamily(font);
    if (font.status !== 'ready' || font.mime !== 'font/woff2' || font.format !== 'woff2' || !Number.isSafeInteger(font.size) || font.size < 1 || font.size > STUDIO_FONT_LIMITS.fileBytes) throw new StudioDocumentError(`La fuente ${font.id} no está disponible o supera 8 MiB.`);
    if (!['normal', 'italic'].includes(font.style) || !Number.isInteger(font.weight) || font.weight < 100 || font.weight > 900 || (font.css_family && font.css_family !== family)) throw new StudioDocumentError(`Los estilos de la fuente ${font.id} no son válidos.`);
    const origin = globalThis.location?.origin ?? 'http://localhost';
    let url;
    try { url = new URL(font.file_url, origin); } catch { throw new StudioDocumentError('La fuente necesita una URL privada de la aplicación.'); }
    if (!font.file_url || !['http:', 'https:'].includes(url.protocol) || url.origin !== origin || url.username || url.password) throw new StudioDocumentError('La fuente necesita una URL privada del mismo origen de la aplicación.');
    return { family, url: url.href };
}

function storeFor(set) {
    if (!stores.has(set)) stores.set(set, new Map());
    return stores.get(set);
}

function remove(set, store, record) {
    if (record.face) set.delete(record.face);
    store.delete(record.family);
}

function makeRoom(set, store, bytes) {
    const used = () => [...store.values()].reduce((sum, record) => sum + record.size, 0);
    const unused = [...store.values()].filter(record => record.refs === 0 && record.face).sort((a, b) => a.touched - b.touched);
    while ((used() + bytes > STUDIO_FONT_LIMITS.cacheBytes || store.size >= STUDIO_FONT_LIMITS.faces) && unused.length) remove(set, store, unused.shift());
    if (used() + bytes > STUDIO_FONT_LIMITS.cacheBytes || store.size >= STUDIO_FONT_LIMITS.faces) throw new StudioDocumentError('Hay demasiadas fuentes abiertas. Cierra otros diseños o reduce las fuentes utilizadas.');
}

async function bytesFor(font, url, signal) {
    const response = await fetch(url, { credentials: 'same-origin', redirect: 'error', signal, headers: { Accept: 'font/woff2' } });
    if (!response.ok || !response.body) throw new StudioDocumentError(`No se pudo leer la fuente ${font.id}.`);
    const chunks = [];
    let size = 0;
    const reader = response.body.getReader();
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            size += value.byteLength;
            if (size > font.size || size > STUDIO_FONT_LIMITS.fileBytes) throw new StudioDocumentError(`La fuente ${font.id} supera el tamaño declarado.`);
            chunks.push(value);
        }
    } catch (error) { await reader.cancel().catch(() => {}); throw error; }
    finally { reader.releaseLock(); }
    if (size !== font.size) throw new StudioDocumentError(`La fuente ${font.id} está incompleta.`);
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    if (String.fromCharCode(...bytes.subarray(0, 4)) !== 'wOF2') throw new StudioDocumentError(`La fuente ${font.id} no contiene un archivo WOFF2 válido.`);
    return bytes.buffer;
}

async function recordFor(font, { timeout = 20000 } = {}) {
    const { family, url } = checked(font);
    const set = globalThis.document?.fonts;
    if (!set || typeof globalThis.FontFace !== 'function') throw new StudioDocumentError('Este navegador no permite cargar las fuentes privadas del diseño.');
    const store = storeFor(set);
    let record = store.get(family);
    if (!record) {
        makeRoom(set, store, font.size);
        record = { family, id: Number(font.id), size: font.size, style: font.style, weight: font.weight, bytes: null, face: null, refs: 0, touched: ++clock, promise: null };
        store.set(family, record);
        record.promise = (async () => {
            const controller = new AbortController();
            let timer;
            const deadline = new Promise((_, reject) => {
                timer = setTimeout(() => { controller.abort(); reject(new StudioDocumentError(`La fuente ${font.id} tardó demasiado en cargarse.`)); }, timeout);
            });
            try {
                const bytes = await Promise.race([bytesFor(font, url, controller.signal), deadline]);
                const face = new FontFace(family, bytes, { weight: String(font.weight), style: font.style });
                await Promise.race([face.load(), deadline]);
                if (controller.signal.aborted || face.status !== 'loaded') throw new StudioDocumentError(`No se pudo cargar la fuente ${font.id}.`);
                set.add(face);
                record.face = face;
                record.bytes = bytes;
                return record;
            } catch (error) {
                store.delete(family);
                if (error instanceof StudioDocumentError) throw error;
                throw new StudioDocumentError(`No se pudo cargar la fuente ${font.id}. Comprueba el archivo y vuelve a intentarlo.`);
            } finally { clearTimeout(timer); }
        })();
    }
    record.touched = ++clock;
    return record.promise;
}

function abortable(promise, signal) {
    if (signal?.aborted) return Promise.reject(new DOMException('Carga cancelada', 'AbortError'));
    if (!signal) return promise;
    return new Promise((resolve, reject) => {
        const cancel = () => { signal.removeEventListener('abort', cancel); reject(new DOMException('Carga cancelada', 'AbortError')); };
        signal.addEventListener('abort', cancel, { once: true });
        promise.then(value => { signal.removeEventListener('abort', cancel); resolve(value); }, error => { signal.removeEventListener('abort', cancel); reject(error); });
    });
}

/** Explicit preview loads one selected resource, never every font in a library listing. */
export async function loadStudioFont(font, options = {}) {
    return (await abortable(recordFor(font, options), options.signal)).family;
}

export function usedStudioFonts(elements, fonts = []) {
    const resources = list(fonts);
    const ids = new Set();
    const collect = items => items.forEach(element => { const style = elementStyle(element); if (['text', 'chart'].includes(element.type) && style.font_id != null) ids.add(Number(style.font_id)); if (element.type === 'group') collect(element.elements); });
    collect(elements);
    let bytes = 0;
    return [...ids].map(id => {
        const font = resources.get(id);
        if (!font) throw new StudioDocumentError(`La fuente privada ${id} ya no está disponible en esta marca.`);
        checked(font);
        bytes += font.size;
        if (bytes > STUDIO_FONT_LIMITS.documentBytes) throw new StudioDocumentError('Las fuentes del diseño superan 32 MiB. Reduce las familias utilizadas.');
        return font;
    });
}

export async function ensureStudioFonts(elements, fonts = [], options = {}) {
    const resources = usedStudioFonts(elements, fonts);
    await Promise.all(resources.map(font => loadStudioFont(font, options)));
}

/** Each canvas holds its own references; disposing one never unloads another editor's font. */
export function createStudioFontRegistry(initial = []) {
    let resources = list(initial);
    let disposed = false;
    const held = new Set();
    return {
        setFonts(next) { resources = list(next); },
        family(element) { const style = elementStyle(element); return style.font_id == null ? style.fontFamily ?? 'Arial' : studioFontFamily(resources.get(Number(style.font_id))); },
        style(element) {
            const style = elementStyle(element);
            if (style.font_id == null) return { fontFamily: style.fontFamily ?? 'Arial', fontWeight: style.fontWeight ?? 'normal', fontStyle: style.fontStyle ?? 'normal' };
            const font = resources.get(Number(style.font_id));
            const { family } = checked(font);
            return { fontFamily: family, fontWeight: font.weight, fontStyle: font.style };
        },
        async ensure(elements, options = {}) {
            if (disposed) throw new StudioDocumentError('El diseño ya está cerrado.');
            const needed = usedStudioFonts(elements, resources);
            const records = await abortable(Promise.all(needed.map(font => recordFor(font, options))), options.signal);
            if (disposed) throw new DOMException('Carga cancelada', 'AbortError');
            for (const record of records) if (!held.has(record)) { record.refs++; held.add(record); }
        },
        releaseUnused(elements) {
            const ids = new Set(usedStudioFonts(elements, resources).map(font => Number(font.id)));
            for (const record of held) if (!ids.has(record.id)) {
                record.refs--; held.delete(record);
                const set = globalThis.document?.fonts;
                if (record.refs === 0 && set) remove(set, storeFor(set), record);
            }
        },
        svgFaces() {
            return [...held].map(record => ({ family: record.family, style: record.style, weight: record.weight, bytes: record.bytes }));
        },
        dispose() {
            if (disposed) return;
            disposed = true;
            for (const record of held) {
                record.refs--;
                if (record.refs === 0) {
                    const set = globalThis.document?.fonts;
                    if (set) remove(set, storeFor(set), record);
                }
            }
            held.clear();
        },
    };
}
