import { validateCaptions } from './captions.js';
/** Time is document data. No URLs, expressions, executable filters or browser clocks are persisted. */
export const TIMELINE_FPS = Object.freeze([12, 15, 24, 25, 30, 50, 60]);
const EPS = 1e-9;
const idPattern = /^[A-Za-z0-9_-]{1,64}$/;
const own = (object, key) => Object.hasOwn(object, key);
const plain = value => value !== null && typeof value === 'object' && [Object.prototype, null].includes(Object.getPrototypeOf(value));
const fail = (path, message) => { throw new Error(`${path}: ${message}`); };
function keys(value, allowed, path) {
    if (!plain(value) || Array.isArray(value)) fail(path, 'debe ser un objeto temporal.');
    for (const key of Object.keys(value)) if (!allowed.includes(key)) fail(`${path}.${key}`, 'propiedad temporal no admitida.');
}
function number(value, min, max, path, fallback) {
    const result = value === undefined ? fallback : value;
    if (!Number.isFinite(result) || result < min || result > max) fail(path, `valor temporal fuera de ${min}–${max}.`);
    return result;
}
function flag(value, path, fallback = false) {
    if (value === undefined) return fallback;
    if (typeof value !== 'boolean') fail(path, 'debe ser booleano.');
    return value;
}
function identify(value, ids, path) {
    if (typeof value !== 'string' || !idPattern.test(value) || ids.has(value)) fail(path, 'ID temporal inválido o repetido.');
    ids.add(value);
}

export function normalizeGainPoints(points, duration, path = 'gainPoints') {
    if (!Array.isArray(points) || points.length < 2 || points.length > 64) fail(path, 'indica entre 2 y 64 puntos de ganancia.');
    let previous = -1;
    const result = Array.from(points, (point, i) => {
        keys(point, ['time', 'value'], `${path}.${i}`);
        const time = number(point.time, 0, duration, `${path}.${i}.time`);
        if (time <= previous) fail(path, 'los tiempos de ganancia deben crecer estrictamente.');
        previous = time;
        return { time, value: number(point.value, 0, 1, `${path}.${i}.value`) };
    });
    if (result[0].time !== 0 || Math.abs(result.at(-1).time - duration) > EPS) fail(path, 'la envolvente debe cubrir la duración completa del clip.');
    return result;
}

function mediaFields(input, duration, path) {
    const result = { playbackRate: number(input.playbackRate, 0.25, 4, `${path}.playbackRate`, 1),
        sourceOffset: number(input.sourceOffset, 0, 86400, `${path}.sourceOffset`, 0), loop: flag(input.loop, `${path}.loop`) };
    if (own(input, 'gainPoints')) result.gainPoints = normalizeGainPoints(input.gainPoints, duration, `${path}.gainPoints`);
    return result;
}

/** Reuse the v1 spatial validator before accepting any temporal metadata. */
export function normalizeTimelineDocument(input, spatialValidator) {
    if (!plain(input)) fail('document', 'debe ser un documento.');
    let encoded;
    try { encoded = JSON.stringify(input); } catch { fail('document', 'JSON temporal inválido.'); }
    if (new TextEncoder().encode(encoded).byteLength > 2 * 1024 * 1024) fail('document', 'supera 2 MiB.');
    keys(input.timeline, ['fps', 'audio_tracks', 'captions'], 'timeline');
    const fps = input.timeline.fps;
    if (!Number.isInteger(fps) || !TIMELINE_FPS.includes(fps)) fail('timeline.fps', 'fps no admitidos.');
    if (!Array.isArray(input.timeline.audio_tracks) || input.timeline.audio_tracks.length > 64) fail('timeline.audio_tracks', 'se admiten hasta 64 pistas.');
    if (!Array.isArray(input.pages)) fail('pages', 'debe ser una lista.');
    const strip = (element, path) => {
        if (!plain(element)) fail(path, 'elemento no válido.');
        const result = { ...element };
        delete result.timing;
        if (element.type === 'video') for (const key of ['playbackRate', 'sourceOffset', 'loop', 'gainPoints']) delete result[key];
        if (element.type === 'group' && Array.isArray(element.elements)) result.elements = Array.from(element.elements, (child, i) => strip(child, `${path}.elements.${i}`));
        return result;
    };
    const base = { ...input, schema_version: 1, pages: Array.from(input.pages, (page, i) => {
        if (!plain(page)) fail(`pages.${i}`, 'página no válida.');
        const result = { ...page };
        delete result.transition;
        if (Array.isArray(page.elements)) result.elements = Array.from(page.elements, (element, j) => strip(element, `pages.${i}.elements.${j}`));
        return result;
    }) };
    delete base.timeline;
    const normalized = spatialValidator(base);
    const ids = new Set();
    const apply = (element, original, duration, path) => {
        ids.add(element.id);
        if (own(original, 'timing')) {
            keys(original.timing, ['start', 'duration'], `${path}.timing`);
            const start = number(original.timing.start, 0, duration, `${path}.timing.start`);
            const length = number(original.timing.duration, 0, duration + EPS, `${path}.timing.duration`);
            if (length <= 0 || start + length <= start || start + length > duration + EPS) fail(`${path}.timing`, 'la ventana debe ser positiva y quedar dentro de la página.');
            element.timing = { start, duration: length };
        }
        if (element.type === 'video') Object.assign(element, mediaFields(original, element.timing?.duration ?? duration, path));
        if (element.type === 'group') element.elements.forEach((child, i) => apply(child, original.elements[i], duration, `${path}.elements.${i}`));
    };
    normalized.pages.forEach((page, i) => {
        ids.add(page.id);
        const original = input.pages[i];
        page.elements.forEach((element, j) => apply(element, original.elements[j], page.duration, `pages.${i}.elements.${j}`));
        if (!own(original, 'transition')) return;
        keys(original.transition, ['type', 'duration'], `pages.${i}.transition`);
        const { type } = original.transition;
        if (!['cut', 'fade', 'slide_left', 'slide_right'].includes(type)) fail(`pages.${i}.transition.type`, 'transición no admitida.');
        const seconds = number(original.transition.duration, 0, page.duration / 2 + EPS, `pages.${i}.transition.duration`);
        if (type === 'cut' ? seconds !== 0 : i === normalized.pages.length - 1 || seconds < 1 / fps - EPS || seconds > normalized.pages[i + 1].duration / 2 + EPS) fail(`pages.${i}.transition`, 'duración o posición de transición inválida.');
        page.transition = { type, duration: seconds };
    });
    const total = normalized.pages.reduce((sum, page) => sum + page.duration - (page.transition?.duration ?? 0), 0);
    const tracks = Array.from(input.timeline.audio_tracks, (track, i) => {
        const path = `timeline.audio_tracks.${i}`;
        keys(track, ['id', 'asset_id', 'start', 'duration', 'trimStart', 'trimEnd', 'playbackRate', 'sourceOffset', 'loop', 'volume', 'muted', 'gainPoints'], path);
        identify(track.id, ids, `${path}.id`);
        if (!Number.isSafeInteger(track.asset_id) || track.asset_id < 1) fail(`${path}.asset_id`, 'recurso de audio inválido.');
        const start = number(track.start, 0, total, `${path}.start`);
        const duration = number(track.duration, 1 / fps - EPS, total + EPS, `${path}.duration`);
        if (start + duration > total + EPS) fail(path, 'la pista supera el proyecto.');
        const trimStart = number(track.trimStart, 0, 86400, `${path}.trimStart`, 0);
        const trimEnd = track.trimEnd == null ? null : number(track.trimEnd, 0.001, 86400, `${path}.trimEnd`);
        if (trimEnd !== null && trimEnd <= trimStart) fail(path, 'recorte de audio inválido.');
        return { id: track.id, asset_id: track.asset_id, start, duration, trimStart, trimEnd,
            ...mediaFields(track, duration, path), volume: number(track.volume, 0, 1, `${path}.volume`, 1), muted: flag(track.muted, `${path}.muted`) };
    });
    const result = { ...normalized, schema_version: 2, timeline: { fps, audio_tracks: tracks } };
    if (own(input.timeline, 'captions')) result.timeline.captions = validateCaptions(input.timeline.captions, total, ids);
    if (new TextEncoder().encode(JSON.stringify(result)).byteLength > 2 * 1024 * 1024) fail('document', 'supera 2 MiB normalizados.');
    return result;
}

/** Input has passed validateDocument. Accumulated boundaries avoid rounding drift between pages. */
export function compileTimeline(document) {
    const fps = document?.timeline?.fps;
    if (document?.schema_version !== 2 || !TIMELINE_FPS.includes(fps)) fail('timeline', 'valida un documento v2 antes de compilar.');
    let next = 0;
    const pages = document.pages.map((source, index) => {
        const start = next, end = start + source.duration;
        const transition = source.transition ?? { type: 'cut', duration: 0 };
        const first = Math.round(start * fps), last = Math.round(end * fps);
        const overlap = Math.round((end - transition.duration) * fps);
        next = end - transition.duration;
        return { id: source.id, index, source, frameStart: first, frames: last - first, start: first / fps, end: last / fps,
            transition: { type: transition.type, start: overlap / fps, end: last / fps, duration: (last - overlap) / fps } };
    });
    const frameCount = Math.round(next * fps);
    return { fps, width: document.width, height: document.height, pages, frameCount, duration: frameCount / fps };
}

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
export function sampleTimeline(plan, time) {
    number(time, 0, Number.MAX_VALUE, 'timeline.time');
    const pages = [];
    for (const page of plan.pages) {
        if (time < page.start || time >= page.end) continue;
        let opacity = 1, audioGain = 1, translateX = 0;
        const incoming = plan.pages[page.index - 1]?.transition;
        if (incoming?.duration > 0 && time < incoming.end) {
            const progress = clamp((time - incoming.start) / incoming.duration, 0, 1);
            audioGain *= progress;
            if (incoming.type === 'fade') opacity *= progress;
            if (incoming.type === 'slide_left') translateX += (1 - progress) * plan.width;
            if (incoming.type === 'slide_right') translateX -= (1 - progress) * plan.width;
        }
        const outgoing = page.transition;
        if (outgoing.duration > 0 && time >= outgoing.start) {
            const progress = clamp((time - outgoing.start) / outgoing.duration, 0, 1);
            audioGain *= 1 - progress;
            if (outgoing.type === 'fade') opacity *= 1 - progress;
            if (outgoing.type === 'slide_left') translateX -= progress * plan.width;
            if (outgoing.type === 'slide_right') translateX += progress * plan.width;
        }
        pages.push({ id: page.id, index: page.index, localTime: time - page.start, opacity, audioGain, translateX });
    }
    return { time, frame: Math.floor(time * plan.fps), ended: time >= plan.duration, pages };
}

function windowFor(clip, pageDuration) {
    return clip.timing ?? { start: clip.start ?? 0, duration: clip.duration ?? pageDuration };
}

function sourceWindow(clip, sourceDuration) {
    const from = number(clip.trimStart, 0, 86400, 'clip.trimStart', 0);
    const to = number(clip.trimEnd ?? sourceDuration, 0.001, Number.MAX_VALUE, 'clip.trimEnd');
    if (to <= from || (sourceDuration !== undefined && to > sourceDuration + 0.01)) fail('clip.trimEnd', 'recorte fuera del origen.');
    const offset = Math.min(to - from, number(clip.sourceOffset, 0, to - from + EPS, 'clip.sourceOffset', 0));
    return { from, to, length: to - from, offset };
}

/** Points are validated once with the document, then sampled without reallocating envelopes per frame. */
export function gainAt(points, time) {
    if (!points?.length) return 1;
    if (time <= points[0].time) return points[0].value;
    for (let i = 1; i < points.length; i++) {
        if (time > points[i].time) continue;
        const left = points[i - 1], right = points[i];
        return left.value + (right.value - left.value) * (time - left.time) / (right.time - left.time);
    }
    return points.at(-1).value;
}

export function clipMediaState(clip, time, sourceDuration, pageDuration) {
    if (!Number.isFinite(time)) fail('clip.time', 'tiempo no finito.');
    const window = windowFor(clip, pageDuration);
    const start = number(window.start, 0, 360000, 'clip.start');
    const duration = number(window.duration, Number.MIN_VALUE, 360000, 'clip.duration');
    const source = sourceWindow(clip, sourceDuration);
    const rate = number(clip.playbackRate, 0.25, 4, 'clip.playbackRate', 1);
    const elapsed = Math.max(0, time - start);
    const position = source.offset + elapsed * rate;
    const loop = flag(clip.loop, 'clip.loop');
    const active = time >= start && time < start + duration;
    const audible = active && !flag(clip.muted, 'clip.muted') && (loop || position < source.length);
    return { active, audible, sourceTime: source.from + (loop ? position % source.length : Math.min(position, source.length)),
        gain: audible ? number(clip.volume, 0, 1, 'clip.volume', 1) * gainAt(clip.gainPoints, elapsed) : 0, playbackRate: rate };
}

function sliceGain(points, from, to, duration = to - from) {
    const result = [{ time: 0, value: gainAt(points, from) }];
    for (const point of points) {
        if (point.time <= from || point.time >= to) continue;
        const time = point.time - from;
        if (time <= 0 || time >= duration) continue;
        if (result.at(-1).time === time) result.at(-1).value = point.value;
        else result.push({ time, value: point.value });
    }
    result.push({ time: duration, value: gainAt(points, to) });
    return result;
}

function collectIds(document) {
    const ids = new Set(document.timeline.audio_tracks.map(track => track.id));
    const walk = elements => elements.forEach(element => { ids.add(element.id); if (element.type === 'group') walk(element.elements); });
    document.pages.forEach(page => { ids.add(page.id); walk(page.elements); });
    return ids;
}

let idSequence = 0;
function uniqueId(ids, createId = () => `clip_${globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}_${++idSequence}`}`) {
    for (let attempt = 0; attempt < 8; attempt++) {
        const id = createId();
        if (typeof id === 'string' && idPattern.test(id) && !ids.has(id)) { ids.add(id); return id; }
    }
    fail('clip.id', 'no se pudo generar un ID único.');
}

function halves(clip, window, cut, fps, sourceDuration) {
    if (!Number.isFinite(cut) || cut - window.start < 1 / fps - EPS || window.start + window.duration - cut < 1 / fps - EPS) fail('clip', 'el corte debe dejar al menos un fotograma a cada lado.');
    const left = JSON.parse(JSON.stringify(clip));
    const right = JSON.parse(JSON.stringify(clip));
    const before = cut - window.start, after = window.duration - before;
    if (clip.type === 'video' || !clip.type) {
        const source = sourceWindow(clip, sourceDuration);
        const position = source.offset + before * (clip.playbackRate ?? 1);
        right.sourceOffset = clip.loop ? position % source.length : Math.min(position, source.length);
    }
    if (clip.gainPoints) {
        left.gainPoints = sliceGain(clip.gainPoints, 0, before);
        right.gainPoints = sliceGain(clip.gainPoints, before, window.duration);
    }
    return { left, right, before, after };
}

function checkedResult(document) {
    // Geometry was validated before this immutable operation and is never changed by a split.
    const count = elements => elements.reduce((sum, element) => sum + 1 + (element.type === 'group' ? count(element.elements) : 0), 0);
    for (const page of document.pages) if (count(page.elements) > 500) fail('page', 'la división supera el límite de 500 capas.');
    return normalizeTimelineDocument(document, spatial => spatial);
}

/** Remove an ancestor without changing the child's effective time, media phase or envelope. */
export function restrictElementWindow(element, parentWindow, { pageDuration, sourceDuration } = {}) {
    const result = JSON.parse(JSON.stringify(element));
    const window = windowFor(element, pageDuration);
    const start = Math.max(window.start, parentWindow.start);
    const end = Math.min(window.start + window.duration, parentWindow.start + parentWindow.duration);
    if (end <= start) { result.visible = false; return result; }
    const from = start - window.start, to = end - window.start;
    result.timing = { start, duration: end - start };
    if (element.type === 'video') {
        const source = sourceWindow(element, sourceDuration);
        const position = source.offset + from * (element.playbackRate ?? 1);
        result.sourceOffset = element.loop ? position % source.length : Math.min(position, source.length);
        if (element.gainPoints) result.gainPoints = sliceGain(element.gainPoints, from, to, result.timing.duration);
    }
    return result;
}

export function splitTimelineElement(document, pageId, elementId, cut, options = {}) {
    if (document?.schema_version !== 2) fail('document', 'la división requiere v2.');
    const result = JSON.parse(JSON.stringify(document));
    const page = result.pages.find(page => page.id === pageId);
    if (!page) fail('page', 'la página no existe.');
    const find = elements => {
        for (let i = 0; i < elements.length; i++) {
            if (elements[i].id === elementId) return { elements, index: i, element: elements[i] };
            if (elements[i].type === 'group') { const child = find(elements[i].elements); if (child) return child; }
        }
    };
    const found = find(page.elements);
    if (!found) fail('clip', 'la capa no existe.');
    const window = windowFor(found.element, page.duration);
    const { left, right, before, after } = halves(found.element, window, cut, document.timeline.fps, options.sourceDuration);
    left.timing = { start: window.start, duration: before };
    right.timing = { start: cut, duration: after };
    const ids = collectIds(document);
    const remap = element => { element.id = uniqueId(ids, options.createId); if (element.type === 'group') element.elements.forEach(remap); };
    remap(right);
    found.elements.splice(found.index, 1, left, right);
    return checkedResult(result);
}

export function splitTimelineAudio(document, trackId, cut, options = {}) {
    if (document?.schema_version !== 2) fail('document', 'la división requiere v2.');
    const result = JSON.parse(JSON.stringify(document));
    const tracks = result.timeline.audio_tracks;
    const index = tracks.findIndex(track => track.id === trackId);
    if (index < 0) fail('audio', 'la pista no existe.');
    const track = tracks[index];
    const { left, right, before, after } = halves(track, windowFor(track), cut, document.timeline.fps, options.sourceDuration);
    left.duration = before;
    right.start = cut; right.duration = after;
    right.id = uniqueId(collectIds(document), options.createId);
    tracks.splice(index, 1, left, right);
    return checkedResult(result);
}
