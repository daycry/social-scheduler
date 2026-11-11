import { renderStudioPage, seekVideoFrame } from './canvas.js';
import { frameElements } from './frames.js';
import { clipMediaState, compileTimeline } from './timeline.js';
import { attachFrameCaptions } from './captions-renderer.js';

/** A static page frame uses the same geometry and private media as the editor; it never plays audio. */
export async function renderStudioFrame(document, pageId, time, assets, fonts, options = {}) {
    if (document.schema_version !== 2) return renderStudioPage(document, pageId, assets, fonts, options);
    const page = document.pages.find(page => page.id === pageId);
    const { transition: ignored, ...source } = page;
    const elements = frameElements(page.elements, time, page.duration);
    const scene = await renderStudioPage({ ...document, timeline: { fps: document.timeline.fps, audio_tracks: [] }, pages: [{ ...source, elements }] }, pageId, assets, fonts, options);
    try {
        const update = async (objects, layers) => {
            for (let i = 0; i < objects.length; i++) {
                const object = objects[i], layer = layers[i];
                if (layer.type === 'group') { await update(object.getObjects(), layer.elements); object.dirty = true; }
                if (!object.studioVideo) continue;
                const video = object.studioVideo, state = clipMediaState(layer, time, video.duration, page.duration);
                video.pause(); video.muted = true;
                const start = layer.trimStart ?? 0, end = Math.min(layer.trimEnd ?? video.duration, video.duration);
                if (start >= end) throw new Error('El recorte del vídeo no contiene un fotograma disponible.');
                const last = end - Math.min(0.0001, (end - start) / 2);
                await seekVideoFrame(video, Math.min(state.sourceTime, last), { positionTolerance: Math.min(0.0001, (end - start) / 4), endTolerance: 0 });
                object.dirty = true;
            }
        };
        await update(scene.canvas.getObjects(), elements);
        scene.canvas.renderAll();
        const globalTime = compileTimeline(document).pages.find(item => item.id === pageId).start + time;
        return await attachFrameCaptions(scene, document, globalTime, fonts);
    } catch (error) { await scene.dispose(); throw error; }
}
