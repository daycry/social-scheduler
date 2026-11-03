import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { chromium } from 'playwright';
import { blankDocument, newElement } from '../../resources/js/Studio/document.js';

let browser;
let page;
before(async () => {
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => route.abort());
    page = await context.newPage();
    await page.setContent('<!doctype html><html><body></body></html>');
    await page.addScriptTag({ path: path.resolve('public/build/studio-renderer.js') });
});
after(async () => { await browser?.close(); });

async function render(document, format = 'png', options = {}) {
    return page.evaluate(async input => {
        const [file] = await globalThis.renderStudioFiles({ document: input.document, assets: [], format: input.format, ...input.options });
        const bitmap = await createImageBitmap(await fetch(`data:${file.mime};base64,${file.data}`).then(response => response.blob()));
        const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
        const context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0);
        const pixels = [...context.getImageData(0, 0, bitmap.width, bitmap.height).data];
        return { width: bitmap.width, height: bitmap.height, rgbTotal: pixels.reduce((sum, value, index) => index % 4 === 3 ? sum : sum + value, 0), hasContrast: pixels.some((value, index) => index % 4 !== 3 && value < 245), edgePixel: pixels.slice((10 * bitmap.width + bitmap.width - 1) * 4, (10 * bitmap.width + bitmap.width - 1) * 4 + 4) };
    }, { document, format, options });
}

test('fractional PNG JPEG and WebP exports round dimensions and preserve exact object scale', async () => {
    const document = blankDocument(41, 41);
    document.pages[0].elements.push(newElement('rect', { x: 40, y: 0, width: 1, height: 41, fill: '#ff0000' }));
    for (const format of ['png', 'jpeg', 'webp']) {
        const output = await render(document, format, { scale: 0.5, transparent: format === 'png' });
        assert.equal(output.width, 21, `${format} width`);
        assert.equal(output.height, 21, `${format} height`);
        if (format === 'png') {
            assert.equal(output.edgePixel[0], 255);
            assert.ok(output.edgePixel[3] >= 120 && output.edgePixel[3] <= 136, 'the half-width edge must retain fractional alpha instead of stretching the 20px bitmap');
        }
    }
});

test('default line and path exports have actual pixels different from the empty page', async () => {
    const document = blankDocument(400, 300);
    const blank = await render(document);
    for (const type of ['line', 'path']) {
        document.pages[0].elements = [newElement(type)];
        const output = await render(document);
        assert.notEqual(output.rgbTotal, blank.rgbTotal, `${type} must have visible pixels`);
        assert.ok(output.hasContrast, `${type} needs visible contrast`);
    }
});
