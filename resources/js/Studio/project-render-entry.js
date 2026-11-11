import { compileTimeline } from './timeline.js';
import { createTimelinePreview } from './timeline-preview.js';

let renderer = null, plan = null, output = null;
globalThis.prepareStudioProject = async ({ document, assets = [], fonts = [], renderScale = 1 }) => {
    if (renderer) throw new Error('El render de este intento ya está preparado.');
    output = globalThis.document.createElement('canvas'); output.id = 'studio-project-output';
    globalThis.document.body.append(output);
    renderer = await createTimelinePreview(output, { document, assets, fonts, offline: true, renderScale });
    plan = compileTimeline(document);
    return { fps: plan.fps, frameCount: plan.frameCount, width: output.width, height: output.height };
};
globalThis.paintStudioProjectFrame = async frame => {
    if (!renderer || !Number.isSafeInteger(frame) || frame < 0 || frame >= plan.frameCount) throw new Error('Fotograma fuera del proyecto.');
    await renderer.seek(frame / plan.fps);
};
globalThis.disposeStudioProject = async () => { await renderer?.dispose(); renderer = null; plan = null; output?.remove(); output = null; };
