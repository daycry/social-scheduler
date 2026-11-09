import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { chromium } from 'playwright';
import { build } from 'vite';
import { blankDocument, newElement } from '../../resources/js/Studio/document.js';

let browser;
let page;
let font;
const origin = 'http://studio-testing.invalid';
const resources = new Map();
before(async () => {
    const bytes = await fs.readFile('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2');
    font = { id: 17, brand_id: 1, name: 'Space Grotesk', family: 'Space Grotesk', weight: 400, style: 'normal', format: 'woff2', mime: 'font/woff2', size: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), status: 'ready', file_url: `${origin}/fonts/17` };
    resources.set(font.file_url, { mime: font.mime, bytes });
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => {
        if (route.request().url() === `${origin}/`) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>' });
        const item = resources.get(route.request().url());
        if (!item || route.request().method() !== 'GET') return route.abort();
        return route.fulfill({ contentType: item.mime, body: item.bytes });
    });
    page = await context.newPage();
    await page.goto(`${origin}/`);
    await page.addScriptTag({ path: path.resolve('public/build/studio-renderer.js') });
    const bundle = await build({ configFile: false, publicDir: false, logLevel: 'silent', build: { write: false, lib: { entry: 'resources/js/Studio/canvas.js', name: 'StudioCanvas', formats: ['iife'] } } });
    await page.addScriptTag({ content: (Array.isArray(bundle) ? bundle[0] : bundle).output[0].code });
    const image = await page.evaluate(() => {
        const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 64;
        const context = canvas.getContext('2d');
        context.fillStyle = '#c85028'; context.fillRect(0, 0, 64, 64);
        context.fillStyle = '#2846b4'; context.fillRect(32, 0, 32, 64);
        return canvas.toDataURL('image/png').split(',')[1];
    });
    resources.set(`${origin}/assets/1`, { mime: 'image/png', bytes: Buffer.from(image, 'base64') });
});
after(async () => { await browser?.close(); });

async function render(document, options = {}) {
    return page.evaluate(async input => {
        const [file] = await globalThis.renderStudioFiles({ document: input.document, assets: [{ id: 1, width: 64, height: 64, status: 'listo', kind: 'image', studio_url: `${location.origin}/assets/1` }], fonts: input.fonts, format: input.format ?? 'png', transparent: true });
        if (file.mime === 'image/svg+xml') return atob(file.data);
        const bitmap = await createImageBitmap(await fetch(`data:${file.mime};base64,${file.data}`).then(response => response.blob()));
        const canvas = new OffscreenCanvas(bitmap.width, bitmap.height); const context = canvas.getContext('2d'); context.drawImage(bitmap, 0, 0);
        return { width: bitmap.width, height: bitmap.height, pixels: [...context.getImageData(0, 0, bitmap.width, bitmap.height).data] };
    }, { document, fonts: options.fonts ?? [], format: options.format });
}
const pixel = (image, x, y) => image.pixels.slice((y * image.width + x) * 4, (y * image.width + x) * 4 + 4);

test('real private WOFF2 changes raster glyphs and portable SVG embeds the exact font bytes', async () => {
    const document = blankDocument(400, 160);
    document.pages[0].elements = [newElement('text', { x: 10, y: 10, width: 380, height: 120, text: 'Marca 123 MmW', fontFamily: 'Space Grotesk', fontSize: 48, fill: '#111111' })];
    const fallback = await render(document);
    document.pages[0].elements[0].font_id = font.id;
    const explicit = await render(document, { fonts: [font] });
    assert.notDeepEqual(explicit.pixels, fallback.pixels, 'explicit bytes must replace the system fallback, not merely register an unused face');
    const svg = await render(document, { fonts: [font], format: 'svg' });
    assert.match(svg, /@font-face/);
    assert.ok(svg.includes(`StudioFont_${font.id}_${font.sha256.slice(0, 16)}`));
    assert.ok(svg.includes('data:font/woff2;base64,'));
    assert.equal(svg.includes('/fonts/17'), false);
});

test('ellipse and rounded rectangle masks remove corner pixels while retaining central subject pixels', async () => {
    const document = blankDocument(64, 64);
    const layer = newElement('image', { asset_id: 1, x: 0, y: 0, width: 64, height: 64 });
    document.pages[0].elements = [layer];
    const full = await render(document);
    assert.equal(pixel(full, 1, 1)[3], 255);
    for (const mask of [{ type: 'ellipse' }, { type: 'rounded_rect', radius: 20 }]) {
        layer.mask = mask;
        const output = await render(document);
        assert.equal(pixel(output, 1, 1)[3], 0, mask.type);
        assert.equal(pixel(output, 32, 32)[3], 255, mask.type);
        assert.deepEqual(pixel(output, 24, 32), pixel(full, 24, 32), mask.type);
    }
});

test('crop origins outside the actual raster reject while legacy oversized frames still render', async () => {
    const document = blankDocument(64, 64);
    document.pages[0].elements = [newElement('image', { asset_id: 1, x: 0, y: 0, width: 120, height: 120, cropX: 64 })];
    await assert.rejects(render(document), /recorte|crop/i);
    document.pages[0].elements[0].cropX = 0;
    const legacy = await render(document);
    assert.equal(pixel(legacy, 24, 32)[3], 255);
});

test('editor awaits real fonts, preserves masked transforms through copy and releases its private faces', async () => {
    const output = await page.evaluate(async font => {
        const element = document.createElement('canvas'); document.body.append(element);
        const documentData = { schema_version: 1, width: 300, height: 200, pages: [{ id: 'one', name: 'One', background: '#ffffff', duration: 5, elements: [] }] };
        const editor = await StudioCanvas.createStudioCanvas(element, { document: documentData, fonts: [font], assets: [{ id: 1, width: 64, height: 64, status: 'listo', kind: 'image', studio_url: `${location.origin}/assets/1` }] });
        try {
            await editor.addText('Private', { fontFamily: font.family, font_id: font.id });
            const privateSelection = editor.selection()[0];
            let rejected = false;
            try { await editor.updateSelection({ font_id: 999 }); } catch { rejected = true; }
            await editor.updateSelection({ font_id: null, fontFamily: 'Arial' });
            const localSelection = editor.selection()[0];
            await editor.addAsset({ id: 1, kind: 'image', width: 64, height: 64, studio_url: `${location.origin}/assets/1` }, { x: 5, y: 7, width: 64, height: 64, rotation: 20, scaleX: 1.2, mask: { type: 'ellipse' } });
            const copied = editor.copy();
            await editor.paste();
            const pasted = editor.selection()[0];
            return { privateSelection, localSelection, rejected, copied, pasted };
        } finally { await editor.destroy(); element.remove(); }
    }, font);
    assert.equal(output.privateSelection.font_id, font.id);
    assert.equal(output.privateSelection.fontFamily, font.family);
    assert.equal(output.rejected, true);
    assert.equal(Object.hasOwn(output.localSelection, 'font_id'), false);
    assert.deepEqual(output.pasted.mask, { type: 'ellipse' });
    assert.equal(output.pasted.rotation, output.copied[0].rotation);
    assert.equal(output.pasted.scaleX, output.copied[0].scaleX);
    const retained = await page.evaluate(() => [...document.fonts].filter(face => face.family.startsWith('StudioFont_')).length);
    assert.equal(retained, 0);
});

test('crop and every supported filter change decoded output pixels rather than only document metadata', async () => {
    const document = blankDocument(64, 64);
    const layer = newElement('image', { asset_id: 1, x: 0, y: 0, width: 64, height: 64 });
    document.pages[0].elements = [layer];
    const original = await render(document);
    for (const filters of [{ brightness: 0.2 }, { contrast: 0.4 }, { saturation: -0.7 }, { blur: 0.2 }, { grayscale: true }, { sepia: true }, { invert: true }]) {
        layer.filters = filters;
        const output = await render(document);
        assert.notDeepEqual(output.pixels, original.pixels, Object.keys(filters)[0]);
    }
    layer.filters = {};
    layer.cropX = 32; layer.width = 32;
    const cropped = await render(document);
    assert.deepEqual(pixel(cropped, 10, 10).slice(0, 3), pixel(original, 42, 10).slice(0, 3));
    assert.equal(pixel(cropped, 40, 10)[3], 0);
});
