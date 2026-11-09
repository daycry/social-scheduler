import { validateDocument } from './document.js';
import { clipMediaState, compileTimeline } from './timeline.js';
import { remapCaptions } from './captions.js';

const clamp = value => Math.max(0, Math.min(1, value));
const fail = message => { throw new Error(message); };

export function audiovisualRenderOptions(document, { scale = 1, quality = 0.94 } = {}) {
    if (!Number.isFinite(scale) || scale < 0.1 || scale > 4) fail('La escala audiovisual debe estar entre 0,1 y 4.');
    if (!Number.isFinite(quality) || quality < 0.1 || quality > 1) fail('La calidad audiovisual debe estar entre 0,1 y 1.');
    const width = Math.round(document.width * scale), height = Math.round(document.height * scale);
    const paddedWidth = Math.ceil(width / 2) * 2, paddedHeight = Math.ceil(height / 2) * 2;
    if (!Number.isSafeInteger(width) || !Number.isSafeInteger(height) || width < 1 || height < 1 || paddedWidth > 16000 || paddedHeight > 16000
        || paddedWidth * paddedHeight > 16000000) fail('La resolución audiovisual supera el límite de 16 megapíxeles.');
    return { scale, quality, width, height, paddedWidth, paddedHeight, crf: Math.round(40 - (quality - 0.1) * 24 / 0.9),
        paletteColors: 2 ** Math.round(4 + (quality - 0.1) * 4 / 0.9) };
}

function selectedPages(document, value) {
    const ids = new Set(document.pages.map(page => page.id));
    if (value === undefined) return [...ids];
    if (!Array.isArray(value) || !value.length || value.length > 100 || new Set(value).size !== value.length
        || Array.from(value).some(id => typeof id !== 'string' || !ids.has(id))) fail('Selecciona una lista de páginas existentes sin repeticiones.');
    return [...value];
}

function legacyTimeline(document, selection, resources) {
    if (document.schema_version === 2) return document;
    const walk = elements => elements.map(element => {
        if (element.type === 'group') return { ...element, elements: walk(element.elements) };
        if (element.type !== 'video') return element;
        const asset = resources.get(element.asset_id);
        if (!asset || asset.kind !== 'video' || asset.status !== 'listo' || !Number.isFinite(asset.duration) || asset.duration <= 0) fail('Falta un recurso de vídeo listo para convertir el póster antiguo.');
        if ((element.trimStart ?? 0) < asset.duration) return element;
        return { ...element, trimStart: Math.max(0, asset.duration - 0.0001), trimEnd: asset.duration, muted: true };
    });
    const wanted = new Set(selection);
    return validateDocument({ ...document, schema_version: 2, timeline: { fps: 30, audio_tracks: [] },
        pages: document.pages.map(page => wanted.has(page.id) ? { ...page, elements: walk(page.elements) } : page) });
}

/** One integer frame/sample clock, also when resuming in the middle of a page or transition. */
export function compileAudiovisual(input, assets = [], options = {}) {
    if (!options || typeof options !== 'object' || Array.isArray(options) || ![Object.prototype, null].includes(Object.getPrototypeOf(options))
        || Object.keys(options).some(key => !['segmentFrames', 'pageIds', 'scale', 'quality'].includes(key))) fail('Opciones de exportación audiovisual no admitidas.');
    const original = validateDocument(input), selection = selectedPages(original, options.pageIds);
    const resources = new Map();
    if (!Array.isArray(assets)) fail('Indica una lista de recursos de audio.');
    for (const asset of assets) {
        if (!asset || !Number.isSafeInteger(asset.id) || asset.id < 1 || resources.has(asset.id)) fail('ID de recurso inválido o duplicado.');
        resources.set(asset.id, asset);
    }
    const source = legacyTimeline(original, selection, resources), sourceTimeline = compileTimeline(source);
    const fullSelection = selection.length === source.pages.length && selection.every((id, index) => id === source.pages[index].id);
    const pagesById = new Map(source.pages.map(page => [page.id, page]));
    const selected = selection.map((id, index) => {
        const page = { ...pagesById.get(id) };
        if (index === selection.length - 1) delete page.transition;
        return page;
    });
    let document = fullSelection ? source : validateDocument({ ...source, timeline: { fps: source.timeline.fps, audio_tracks: [] }, pages: selected });
    const timeline = compileTimeline(document), render = audiovisualRenderOptions(document, options);
    if (!fullSelection && source.timeline.captions) {
        document = validateDocument({ ...document, timeline: { ...document.timeline, captions: remapCaptions(source, sourceTimeline, document, timeline) } });
    }
    const size = options.segmentFrames ?? timeline.fps * 2;
    if (!Number.isInteger(size) || size < 1 || size > timeline.fps * 2) throw new Error('El tramo debe contener entre 1 y dos segundos de fotogramas.');
    const sampleRate = 48000, segments = [];
    for (let first = 0; first < timeline.frameCount; first += size) {
        const count = Math.min(size, timeline.frameCount - first);
        const sampleStart = Math.round(first * sampleRate / timeline.fps);
        segments.push({ index: segments.length, frameStart: first, frameCount: count,
            start: first / timeline.fps, end: (first + count) / timeline.fps, sampleStart,
            sampleCount: Math.round((first + count) * sampleRate / timeline.fps) - sampleStart });
    }
    const audio = [];
    const add = (clip, origin, start, end, page = null, video = false, identity = clip.id) => {
        if (clip.muted || clip.volume === 0 || start >= end) return;
        const asset = resources.get(clip.asset_id);
        if (!asset || asset.status !== 'listo' || !['audio', 'video'].includes(asset.kind)
            || !Number.isFinite(asset.duration) || asset.duration <= 0) fail(`Falta el recurso de audio listo ${clip.asset_id}.`);
        if (!asset.audio_codec) { if (video) return; fail('El recurso no contiene audio.'); }
        const from = clip.trimStart ?? 0, to = clip.trimEnd ?? asset.duration;
        if (from >= to || to > asset.duration + 1e-9 || (clip.sourceOffset ?? 0) > to - from + 1e-9) fail('El recorte de audio supera el origen.');
        audio.push({ id: identity, sourceId: clip.id, assetId: clip.asset_id, sourceDuration: asset.duration, origin, start, end,
            clip: { ...clip, timing: { start: 0, duration: clip.timing?.duration ?? clip.duration } },
            fadeIn: page ? timeline.pages[page.index - 1]?.transition ?? null : null, fadeOut: page?.transition ?? null });
    };
    for (const page of timeline.pages) {
        const walk = (elements, inheritedStart, inheritedEnd) => {
            for (const element of elements) {
                if (element.visible === false) continue;
                const timing = element.timing ?? { start: 0, duration: page.source.duration };
                const start = Math.max(inheritedStart, timing.start), end = Math.min(inheritedEnd, timing.start + timing.duration);
                if (start >= end) continue;
                if (element.type === 'group') walk(element.elements, start, end);
                if (element.type === 'video') add({ ...element, timing }, page.start + timing.start,
                    page.start + start, page.start + end, page, true);
            }
        };
        walk(page.source.elements, 0, Math.min(page.source.duration, page.end - page.start));
    }
    if (fullSelection) {
        for (const track of source.timeline.audio_tracks) add(track, track.start, track.start, Math.min(track.start + track.duration, timeline.duration));
    } else {
        // A contiguous source run shares ONE offset even when recompiled fractional boundaries round differently.
        // Only the joins between disjoint/reordered runs crossfade global music; internal page fades never double it.
        const sourceIndices = new Map(source.pages.map((page, index) => [page.id, index])), runs = [];
        for (const page of timeline.pages) {
            const sourceIndex = sourceIndices.get(page.id), previous = runs.at(-1);
            if (previous && sourceIndex === previous.lastSourceIndex + 1) { previous.last = page; previous.lastSourceIndex = sourceIndex; }
            else runs.push({ first: page, last: page, sourceStart: sourceTimeline.pages[sourceIndex].start, lastSourceIndex: sourceIndex });
        }
        for (const [index, run] of runs.entries()) for (const track of source.timeline.audio_tracks) {
            const origin = run.first.start + track.start - run.sourceStart;
            add(track, origin, Math.max(run.first.start, origin), Math.min(run.last.end, origin + track.duration),
                { index: run.first.index, transition: run.last.transition }, false, `audio:${index}:${track.id}`);
        }
    }
    return { document, timeline, fps: timeline.fps, frameCount: timeline.frameCount, duration: timeline.duration, sampleRate, segments, audio,
        selection, sourceVersion: original.schema_version, render, options: { scale: render.scale, quality: render.quality, segmentFrames: size, pageIds: selection } };
}

/** Reference sampler shared by native mix acceptance tests; source phase never resets at a segment. */
export function audioStateAt(plan, time) {
    if (!Number.isFinite(time) || time < 0 || time > plan.duration) fail('Tiempo de audio fuera del proyecto.');
    const result = [];
    for (const input of plan.audio) {
        if (time < input.start || time >= input.end) continue;
        const state = audioInputState(input, time);
        if (state) result.push(state);
    }
    return result;
}

export function audioInputState(input, time) {
    if (time < input.start || time >= input.end) return null;
    const state = clipMediaState(input.clip, time - input.origin, input.sourceDuration);
    if (!state.audible) return null;
    const incoming = input.fadeIn, outgoing = input.fadeOut;
    const fadeIn = incoming?.duration > 0 ? clamp((time - incoming.start) / incoming.duration) : 1;
    const fadeOut = outgoing?.duration > 0 ? clamp((outgoing.end - time) / outgoing.duration) : 1;
    return { id: input.id, assetId: input.assetId, sourceTime: state.sourceTime, gain: state.gain * fadeIn * fadeOut, playbackRate: state.playbackRate };
}
