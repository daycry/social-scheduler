import { validateDocument } from './document.js';
import { renderStudioPage, seekVideoFrame } from './canvas.js';
import { clipMediaState, compileTimeline, sampleTimeline } from './timeline.js';
import { createTimelineAudio } from './timeline-audio.js';
import { createCaptionLayer } from './captions-renderer.js';
import { assertChartDocumentBudget } from './chart-scene.js';

/** A separate, disposable scene: preview state can never leak into saved Fabric objects. */
export async function createTimelinePreview(output, { document: input, assets = [], fonts = [], chartResources, offline = false, renderScale = 1 } = {}) {
    if (typeof offline !== 'boolean') throw new Error('El modo offline debe ser booleano.');
    if (!Number.isFinite(renderScale) || renderScale < 0.1 || renderScale > 4 || (!offline && renderScale !== 1)) throw new Error('La escala de render solo se admite en la exportación offline.');
    const document = validateDocument(input);
    await assertChartDocumentBudget(document, chartResources);
    const plan = compileTimeline(document);
    const context = output.getContext('2d');
    if (!context) throw new Error('No se pudo preparar la previsualización.');
    output.width = Math.round(document.width * renderScale); output.height = Math.round(document.height * renderScale);
    const captions = await createCaptionLayer(document, fonts, { renderScale });
    const cache = new Map();
    let disposed = false, playing = false, generation = 0, tail = Promise.resolve(), media = [];
    const walk = (objects, visit) => objects.forEach(object => { visit(object); if (object.getObjects) walk(object.getObjects(), visit); });
    const pauseMedia = () => {
        for (const entry of cache.values()) walk(entry.scene.canvas.getObjects(), object => object.studioVideo?.pause());
    };
    // Offline export advances a deterministic frame clock. Audio is mixed separately, never played.
    const audio = createTimelineAudio(offline ? [] : document.timeline.audio_tracks, assets, { onBuffer: pauseMedia });
    const pause = () => { ++generation; playing = false; pauseMedia(); audio.pause(); };
    function visibleElements(elements, time, duration) {
        const result = [];
        for (const element of elements) {
            const timing = element.timing ?? { start: 0, duration };
            if (element.visible === false || time < timing.start || time >= timing.start + timing.duration) continue;
            if (element.type !== 'group') result.push(element);
            else {
                const children = visibleElements(element.elements, time, duration);
                if (children.length) result.push({ ...element, elements: children });
            }
        }
        return result;
    }
    async function load(page, time) {
        const elements = visibleElements(page.elements, time, page.duration);
        const ids = [];
        const collect = items => items.forEach(item => { ids.push(item.id); if (item.type === 'group') collect(item.elements); });
        collect(elements);
        const signature = ids.join(',');
        const cached = cache.get(page.id);
        if (cached?.signature === signature) return { ...cached, bufferingMs: 0 };
        pauseMedia();
        audio.pauseMedia();
        const started = performance.now();
        if (cached) { cache.delete(page.id); await cached.scene.dispose(); }
        const { transition: ignored, ...source } = page;
        const scene = await renderStudioPage({ ...document, timeline: { fps: plan.fps, audio_tracks: [] }, pages: [{ ...source, elements }] }, page.id, assets, fonts, { renderScale, chartResources });
        if (disposed) { await scene.dispose(); return null; }
        const entry = { scene, signature, elements };
        cache.set(page.id, entry);
        return { ...entry, bufferingMs: performance.now() - started };
    }
    async function paint(time, request) {
        if (disposed || request !== generation) return { bufferingMs: 0 };
        const frame = sampleTimeline(plan, time);
        const wanted = new Set(frame.pages.map(page => page.id));
        // Release inactive pages before opening the next ones; keep at most the transition pair.
        for (const [id, entry] of cache) if (!wanted.has(id)) { cache.delete(id); await entry.scene.dispose(); }
        const scenes = [];
        let captionBufferingMs = 0;
        if (captions?.needsPaint(time)) {
            // A first private caption face can buffer too: stop every native clock before waiting,
            // then let the normal scene/audio preparation resume them at the same project instant.
            pauseMedia(); audio.pauseMedia();
            const started = performance.now();
            await captions.paint(time);
            captionBufferingMs = performance.now() - started;
            if (disposed || request !== generation) return { bufferingMs: captionBufferingMs };
        }
        const audioPrepared = await audio.prepare(time, { play: playing });
        if (disposed || request !== generation) { audio.pause(); return { bufferingMs: 0 }; }
        let bufferingMs = captionBufferingMs + audioPrepared.bufferingMs;
        media = [];
        // Prepare the whole active pair before starting any native media clock.
        // Buffering pauses all decoders, and its elapsed time is returned to the common clock.
        for (const sample of frame.pages) {
            const source = document.pages[sample.index];
            const entry = await load(source, sample.localTime);
            if (!entry || disposed || request !== generation) return { bufferingMs: 0 };
            bufferingMs += entry.bufferingMs;
            scenes.push({ ...entry, sample, source });
        }
        for (const { scene, elements, sample, source } of scenes) {
            async function update(objects, elements, inherited = true) {
                for (let i = 0; i < objects.length; i++) {
                    const object = objects[i], element = elements[i];
                    const timing = element.timing ?? { start: 0, duration: source.duration };
                    const visible = inherited && element.visible !== false && sample.localTime >= timing.start && sample.localTime < timing.start + timing.duration;
                    object.set('visible', visible);
                    if (element.type === 'group') await update(object.getObjects(), element.elements, visible);
                    if (object.studioVideo) {
                        const video = object.studioVideo;
                        const state = clipMediaState(element, sample.localTime, video.duration, source.duration);
                        video.playbackRate = state.playbackRate;
                        video.preservesPitch = false;
                        if (offline) video.muted = true;
                        video.volume = visible ? state.gain * sample.audioGain : 0;
                        const shouldPlay = playing && visible && state.audible;
                        // Muted video must still advance visually. Audio EOF holds the final frame.
                        const visualPlay = playing && visible && (element.loop || state.sourceTime < (element.trimEnd ?? video.duration) - 0.01);
                        if (!visualPlay) video.pause();
                        if (offline) {
                            const from = element.trimStart ?? 0, end = Math.min(element.trimEnd ?? video.duration, video.duration);
                            if (from >= end) throw new Error('El recorte del vídeo no contiene un fotograma disponible.');
                            const last = end - Math.min(0.0001, (end - from) / 2);
                            await seekVideoFrame(video, Math.min(state.sourceTime, last), { positionTolerance: Math.min(0.0001, (end - from) / 4), endTolerance: 0 });
                        } else if (!playing || video.paused || Math.abs(video.currentTime - state.sourceTime) > 0.08) await seekVideoFrame(video, state.sourceTime);
                        if (disposed || request !== generation) { video.pause(); return; }
                        if (visualPlay && video.paused) await video.play();
                        if (!playing || disposed || request !== generation) video.pause();
                        media.push({ id: element.id, active: visible && state.active, audible: shouldPlay,
                            sourceTime: video.currentTime, gain: video.volume, paused: video.paused });
                        object.dirty = true;
                    }
                    // Fabric group caches must be invalidated when a child's frame/visibility changes.
                    if (element.type === 'group') object.dirty = true;
                }
            }
            await update(scene.canvas.getObjects(), elements);
            if (disposed || request !== generation) return;
            scene.canvas.renderAll();
        }
        if (disposed || request !== generation) return;
        context.clearRect(0, 0, output.width, output.height);
        context.save();
        context.globalCompositeOperation = 'lighter';
        for (const { scene, sample } of scenes) {
            context.globalAlpha = sample.opacity;
            context.drawImage(scene.canvas.lowerCanvasEl, sample.translateX * renderScale, 0);
        }
        context.restore();
        context.save(); context.globalCompositeOperation = 'destination-over'; context.fillStyle = '#000000';
        context.fillRect(0, 0, output.width, output.height); context.restore();
        if (captions) {
            context.save(); context.globalAlpha = 1; context.globalCompositeOperation = 'source-over';
            context.drawImage(captions.canvas.lowerCanvasEl, 0, 0); context.restore();
        }
        if (playing) await audio.play();
        else audio.pause();
        if (disposed || request !== generation || !playing) audio.pause();
        return { bufferingMs };
    }
    return {
        duration: plan.duration,
        seek(time, { play = false } = {}) {
            if (!Number.isFinite(time) || time < 0 || time > plan.duration) return Promise.reject(new Error('Tiempo de reproducción fuera del diseño.'));
            if (!play) pause();
            playing = offline ? false : play;
            const request = ++generation;
            const work = tail.catch(() => {}).then(() => paint(time, request)).catch(error => { if (request === generation) pause(); throw error; });
            tail = work;
            return work;
        },
        pause,
        mediaState: () => [...media.map(item => ({ ...item })), ...audio.state()],
        async dispose() {
            disposed = true; ++generation; pause();
            await Promise.all([tail.catch(() => {}), audio.dispose()]);
            for (const entry of cache.values()) await entry.scene.dispose();
            await captions?.dispose();
            cache.clear(); media = [];
        },
    };
}
