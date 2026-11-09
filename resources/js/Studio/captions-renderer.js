import { StaticCanvas, Rect } from 'fabric';
import { elementToFabric, waitForStudioFonts } from './canvas.js';
import { createStudioFontRegistry } from './fonts.js';
import { compileCaptions } from './captions.js';

const defaults = { fontFamily: 'Arial', fontSize: 32, fontWeight: 'normal', fontStyle: 'normal', fill: '#ffffff', stroke: '#000000', strokeWidth: 2, textAlign: 'center', lineHeight: 1.2, position: 'bottom', margin: 0.05 };

/** Reuse the native Fabric text adapter; these objects belong only to temporary render canvases. */
async function objectsFor(document, cues, registry) {
    if (!cues.length) return [];
    const style = { ...defaults, ...document.timeline.captions.style };
    const marginX = document.width * style.margin, marginY = document.height * style.margin;
    const layers = cues.map((cue, index) => ({ id: `caption_view_${index}`, type: 'text', x: marginX, y: 0, width: document.width - 2 * marginX, height: 0,
        text: cue.text, fontFamily: style.fontFamily, ...(style.font_id != null ? { font_id: style.font_id } : {}), fontSize: style.fontSize,
        fontWeight: style.fontWeight, fontStyle: style.fontStyle, fill: style.fill, stroke: style.stroke, strokeWidth: style.strokeWidth, textAlign: style.textAlign, lineHeight: style.lineHeight }));
    await waitForStudioFonts(layers, { registry });
    const texts = await Promise.all(layers.map(layer => elementToFabric(layer, { fontRegistry: registry })));
    const padding = 3, gap = 4;
    const total = texts.reduce((height, text) => height + text.height + 2 * padding, 0) + Math.max(0, texts.length - 1) * gap;
    if (total > document.height - 2 * marginY) throw new Error('Los subtítulos no caben en la zona segura. Reduce su tamaño o el número de líneas simultáneas.');
    let y = style.position === 'top' ? marginY : style.position === 'center' ? (document.height - total) / 2 : document.height - marginY - total;
    const result = [];
    for (const text of texts) {
        const height = text.height + 2 * padding;
        if (style.background) result.push(new Rect({ left: marginX, top: y, width: document.width - 2 * marginX, height, fill: style.background, strokeWidth: 0, selectable: false, evented: false }));
        // Outline first keeps the requested fill visible inside narrow glyphs, also in SVG.
        text.set({ top: y + padding, paintFirst: 'stroke', selectable: false, evented: false }); text.setCoords(); result.push(text);
        y += height + gap;
    }
    return result;
}

export async function createCaptionLayer(documentData, fonts = [], { renderScale = 1 } = {}) {
    if (!documentData.timeline?.captions) return null;
    const index = compileCaptions(documentData.timeline.captions.cues), registry = createStudioFontRegistry(fonts);
    const element = globalThis.document.createElement('canvas');
    const canvas = new StaticCanvas(element, { width: Math.round(documentData.width * renderScale), height: Math.round(documentData.height * renderScale), enableRetinaScaling: false, renderOnAddRemove: false });
    canvas.setViewportTransform([renderScale, 0, 0, renderScale, 0, 0]);
    let signature = null, disposed = false;
    return {
        canvas,
        needsPaint(time) { return index.at(time).map(cue => cue.id).join(',') !== signature; },
        async paint(time) {
            if (disposed) return;
            const cues = index.at(time), next = cues.map(cue => cue.id).join(',');
            if (next === signature) return;
            const objects = await objectsFor(documentData, cues, registry);
            if (disposed) return;
            canvas.remove(...canvas.getObjects()); canvas.add(...objects); canvas.renderAll(); signature = next;
        },
        async dispose() { disposed = true; await canvas.dispose(); registry.dispose(); },
    };
}

/** Static captions stay as vector Fabric objects across renderAll, scaling, transparency and SVG export. */
export async function attachFrameCaptions(scene, document, globalTime, fonts = []) {
    if (!document.timeline?.captions) return scene;
    const cues = compileCaptions(document.timeline.captions.cues).at(globalTime);
    if (!cues.length) return scene;
    const registry = createStudioFontRegistry(fonts);
    try {
        const objects = await objectsFor(document, cues, registry);
        scene.canvas.add(...objects); scene.canvas.renderAll();
        return { ...scene, fontFaces: () => [...new Map([...scene.fontFaces(), ...registry.svgFaces()].map(face => [face.family, face])).values()],
            dispose: async () => { try { await scene.dispose(); } finally { registry.dispose(); } } };
    } catch (error) { registry.dispose(); throw error; }
}
