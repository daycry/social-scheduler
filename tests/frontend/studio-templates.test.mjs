import test from 'node:test';
import assert from 'node:assert/strict';
import { STUDIO_TEMPLATES, createFromTemplate } from '../../resources/js/Studio/templates.js';
import { validateDocument } from '../../resources/js/Studio/document.js';
import { renderStudioFrame } from '../../resources/js/Studio/frame-renderer.js';
import { setEnv } from 'fabric';
import { getEnv } from 'fabric/node';

test('at least twelve original templates create valid nonempty independent documents', () => {
    assert.ok(STUDIO_TEMPLATES.length >= 12);
    assert.equal(new Set(STUDIO_TEMPLATES.map(t => t.key)).size, STUDIO_TEMPLATES.length);
    for (const template of STUDIO_TEMPLATES) {
        const first = validateDocument(createFromTemplate(template.key));
        const second = createFromTemplate(template.key);
        assert.ok(first.pages[0].elements.some(e => e.type === 'text'));
        assert.notEqual(first.pages[0].id, second.pages[0].id);
    }
});

test('templates use supplied brand colors/fonts without mutating the kit', () => {
    const kit = { palette: ['#102030', '#dca123', '#fafafa'], fonts: ['Georgia'] };
    const original = structuredClone(kit);
    const document = createFromTemplate(STUDIO_TEMPLATES[0].key, kit);
    assert.ok(JSON.stringify(document).includes('#102030'));
    assert.ok(document.pages[0].elements.filter(e => e.type === 'text').every(e => e.fontFamily === 'Georgia'));
    assert.deepEqual(kit, original);
    assert.throws(() => createFromTemplate('missing-template'), /plantilla/i);
});

test('brand templates preserve the first uploaded font identity on every generated page', () => {
    const kit = { palette: ['#102030', '#dca123', '#fafafa'], fonts: ['Space Grotesk'], font_ids: [17] };
    for (const key of ['aurora-launch', 'carousel-story', 'deck-agenda', 'carousel-guide']) {
        const document = createFromTemplate(key, kit);
        for (const page of document.pages) for (const element of page.elements.filter(item => item.type === 'text')) {
            assert.equal(element.font_id, 17, `${key}/${page.name}/${element.text}`);
        }
    }
});

test('las 50 plantillas nuevas conservan colores hexadecimales y la fuente propia al validar el documento', () => {
    const templates = STUDIO_TEMPLATES.filter(template => template.layout);
    assert.equal(templates.length, 50);

    for (const palette of [['#124', '#d86e', '#fff7e7'], ['#172238f0', '#e6a040', '#ffe']]) {
        const [dark, accent, paper] = palette;
        const kit = { palette, fonts: ['Space Grotesk'], font_ids: [17] };
        for (const { key, layout, pages } of templates) {
            const design = validateDocument(createFromTemplate(key, kit));
            assert.equal(design.pages.length, pages, key);
            for (const [index, page] of design.pages.entries()) {
                const context = `${key}/${page.name}`;
                const background = layout === 'launch' || (layout === 'carousel' && index === 2) ? dark : paper;
                assert.equal(page.background, background, context);
                assert.ok(page.elements.some(element => element.fill === accent), `${context}: falta el acento`);
                const text = page.elements.filter(element => element.type === 'text');
                assert.ok(text.length, `${context}: falta el texto`);
                for (const element of text) {
                    assert.equal(element.fontFamily, kit.fonts[0], context);
                    assert.equal(element.font_id, kit.font_ids[0], context);
                }
            }
        }
    }
});

test('las cajas de texto reservadas no se solapan en las 50 plantillas nuevas', () => {
    const overlaps = [];
    for (const { key } of STUDIO_TEMPLATES.filter(template => template.layout)) {
        const design = validateDocument(createFromTemplate(key));
        for (const [pageIndex, page] of design.pages.entries()) {
            const texts = page.elements.filter(element => element.type === 'text');
            for (const [index, first] of texts.entries()) {
                for (const second of texts.slice(index + 1)) {
                    const width = Math.min(first.x + first.width, second.x + second.width) - Math.max(first.x, second.x);
                    const height = Math.min(first.y + first.height, second.y + second.height) - Math.max(first.y, second.y);
                    if (width > 0 && height > 0) {
                        overlaps.push(`${key}/${pageIndex + 1} (${design.width}×${design.height}) · ${first.name} y ${second.name} · ${width.toFixed(1)}×${height.toFixed(1)} px`);
                    }
                }
            }
        }
    }
    assert.deepEqual(overlaps, []);
});

test('cada página del catálogo se valida y renderiza en Fabric, en reposo y con animación', async (t) => {
    const animated = STUDIO_TEMPLATES.filter(template => createFromTemplate(template.key).pages.some(page => page.elements.some(item => item.animation)));
    assert.ok(animated.length >= 5, 'el catálogo necesita plantillas animadas en los formatos principales');

    // Fabric compone y dibuja la escena real sobre una superficie ráster simulada.
    const env = getEnv();
    setEnv(env);
    t.mock.method(env.window.HTMLCanvasElement.prototype, 'getContext', function () {
        const drawing = () => {};
        return {
            canvas: this, measureText: text => ({ width: text.length * 220 }),
            save: drawing, restore: drawing, clearRect: drawing, fillRect: drawing, translate: drawing,
            transform: drawing, setTransform: drawing, scale: drawing, beginPath: drawing, closePath: drawing, moveTo: drawing,
            lineTo: drawing, bezierCurveTo: drawing, arc: drawing, fill: drawing, fillText: drawing, stroke: drawing,
            setLineDash: drawing, drawImage: drawing, createLinearGradient: () => ({ addColorStop: drawing }),
        };
    });
    const previousDocument = globalThis.document;
    globalThis.document = env.document;
    try {
        for (const template of STUDIO_TEMPLATES) {
            const document = validateDocument(createFromTemplate(template.key));
            for (const page of document.pages) {
                const frames = [{ time: 0, settled: true }];
                if (document.schema_version === 2) frames.push({ time: 0 }, { time: page.duration - 0.1 });
                for (const { time, settled } of frames) {
                    const scene = await renderStudioFrame(document, page.id, time, [], [], { settled, renderScale: 0.25 });
                    try {
                        const objects = scene.canvas.getObjects();
                        assert.equal(objects.length, page.elements.length, template.key);
                        const svg = scene.canvas.toSVG();
                        assert.match(svg, /<text[\s>]/u, template.key);
                        assert.doesNotMatch(svg, /NaN|Infinity|<image\b/u, template.key);
                        for (const [index, layer] of page.elements.entries()) {
                            if (settled || !layer.animation) continue;
                            if (time === 0 && layer.animation.in) assert.equal(objects[index].opacity, 0, template.key);
                            if (time > 0 && layer.animation.out) {
                                assert.ok(objects[index].opacity > 0 && objects[index].opacity < layer.opacity, template.key);
                            }
                        }
                    } finally {
                        await scene.dispose();
                    }
                }
            }
        }
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    }
});
