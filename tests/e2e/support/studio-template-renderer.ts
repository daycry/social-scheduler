import { Textbox } from 'fabric';
import { renderStudioFrame } from '../../../resources/js/Studio/frame-renderer.js';
import { studioFontFamily } from '../../../resources/js/Studio/fonts.js';
import { SOCIAL_TEMPLATES } from '../../../resources/js/Studio/templates-social.js';
import { createFromTemplate } from '../../../resources/js/Studio/templates.js';

type TestFont = { size: number; sha256: string; file_url: string };

function hasBackgroundAndForeground(pixels: Uint8ClampedArray, background: Uint8ClampedArray): boolean {
    let hasBackground = false;
    let hasForeground = false;
    for (let i = 0; i < pixels.length; i += 4) {
        if (pixels[i] === background[0] && pixels[i + 1] === background[1]
            && pixels[i + 2] === background[2] && pixels[i + 3] === background[3]) {
            hasBackground = true;
        } else if (pixels[i + 3] !== 0) {
            hasForeground = true;
        }
        if (hasBackground && hasForeground) return true;
    }
    return false;
}

export async function checkSocialTemplateRender(fixture: TestFont) {
    const font = { ...fixture, id: 17, status: 'ready', format: 'woff2', mime: 'font/woff2', style: 'normal', weight: 400 };
    const family = studioFontFamily(font);
    const kit = { palette: ['#172238ff', '#d86e', '#fff7e7'], fonts: ['Space Grotesk'], font_ids: [font.id] };
    const sample = document.createElement('canvas').getContext('2d')!;
    const failures: string[] = [];
    const tolerance = 2;
    let renderedPages = 0;
    let checkedTexts = 0;

    for (const { key, pages } of SOCIAL_TEMPLATES) {
        const design = createFromTemplate(key, kit);
        if (design.pages.length !== pages) failures.push(`${key}: se esperaban ${pages} páginas`);
        for (const [index, page] of design.pages.entries()) {
            const label = `${key}/${index + 1} (${page.name})`;
            try {
                // El zoom solo reduce la lectura de píxeles. Fabric mide el texto en píxeles del diseño.
                const scene = await renderStudioFrame(design, page.id, 0, [], [font], { settled: true, renderScale: 0.25 });
                try {
                    const { canvas } = scene;
                    sample.clearRect(0, 0, 1, 1);
                    sample.fillStyle = page.background;
                    sample.fillRect(0, 0, 1, 1);
                    const background = sample.getImageData(0, 0, 1, 1).data;
                    const pixels = canvas.getContext().getImageData(0, 0, canvas.getWidth(), canvas.getHeight()).data;
                    if (!hasBackgroundAndForeground(pixels, background)) failures.push(`${label}: faltan píxeles de fondo o de contenido`);
                    if (!Array.from(document.fonts).some(face => face.family === family && face.status === 'loaded')) {
                        failures.push(`${label}: fuente privada sin cargar`);
                    }

                    const objects = canvas.getObjects();
                    const textBoxes: { label: string; left: number; top: number; right: number; bottom: number }[] = [];
                    if (objects.length !== page.elements.length) failures.push(`${label}: faltan objetos en Fabric`);
                    for (const [layerIndex, layer] of page.elements.entries()) {
                        if (layer.type !== 'text') continue;
                        const object = objects[layerIndex];
                        const text = `${label}/${layer.name} «${layer.text}»`;
                        if (!(object instanceof Textbox) || object.text !== layer.text || object.fontFamily !== family) {
                            failures.push(`${text}: falta el texto o no usa la fuente privada`);
                            continue;
                        }
                        checkedTexts++;
                        const lineWidth = Math.max(...object.textLines.map((_, line) => object.getLineWidth(line)));
                        const { left, top, width, height } = object.getBoundingRect();
                        if (!Number.isFinite(lineWidth + left + top + width + height)) {
                            failures.push(`${text}: medidas no finitas`);
                            continue;
                        }
                        if (Math.max(lineWidth, object.width) > layer.width + tolerance || object.height > layer.height + tolerance) {
                            failures.push(`${text}: desborda la caja (${object.width.toFixed(1)} × ${object.height.toFixed(1)} frente a ${layer.width} × ${layer.height}, línea de ${lineWidth.toFixed(1)} px)`);
                        }
                        const right = left + width;
                        const bottom = top + height;
                        if (left < -tolerance || top < -tolerance || right > design.width + tolerance || bottom > design.height + tolerance) {
                            failures.push(`${text}: desborda el lienzo de ${design.width} × ${design.height}`);
                        }
                        for (const other of textBoxes) {
                            const overlapWidth = Math.min(right, other.right) - Math.max(left, other.left);
                            const overlapHeight = Math.min(bottom, other.bottom) - Math.max(top, other.top);
                            if (overlapWidth > tolerance && overlapHeight > tolerance) {
                                failures.push(`${text}: se solapa con ${other.label} (${overlapWidth.toFixed(1)} × ${overlapHeight.toFixed(1)} px)`);
                            }
                        }
                        textBoxes.push({ label: `${layer.name} «${layer.text}»`, left, top, right, bottom });
                    }
                    renderedPages++;
                } finally {
                    await scene.dispose();
                }
            } catch (error) {
                failures.push(`${label}: ${String(error)}`);
            }
        }
    }

    return { templates: SOCIAL_TEMPLATES.length, renderedPages, checkedTexts, failures };
}
