import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import { PDFDocument } from 'pdf-lib';
import { chromium } from 'playwright';
import { blankDocument, newElement, duplicatePage } from '../../resources/js/Studio/document.js';

const base = path.resolve('storage/app/private/studio-exports/0/0');
async function render(document, format, pageIds, assets = [], fonts = [], options = {}) {
    const directory = path.join(base, crypto.randomUUID());
    assert.ok(directory.startsWith(`${base}${path.sep}`));
    const input = { document, format, pageIds, assets, fonts, ...options };
    let stdout = '', stderr = '';
    const child = spawn(process.execPath, ['scripts/studio/render.mjs', directory], { cwd: process.cwd(), stdio: ['pipe', 'pipe', 'pipe'] });
    child.stdout.on('data', value => { stdout += value; });
    child.stderr.on('data', value => { stderr += value; });
    child.stdin.end(JSON.stringify(input));
    try {
        const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('close', resolve); });
        assert.equal(code, 0, stderr);
        const manifest = JSON.parse(stdout);
        const files = await Promise.all(manifest.files.map(async file => ({ ...file, bytes: await fs.readFile(path.join(directory, file.name)) })));
        return files;
    } finally {
        // Scope is the generated UUID directory under the test's fixed base.
        assert.equal(path.dirname(directory), base);
        await fs.rm(directory, { recursive: true, force: true });
    }
}

test('isolated server browser creates real PNG pages, SVG text and ordered multipage PDF', { timeout: 120000 }, async () => {
    const document = blankDocument(320, 240);
    document.pages[0].background = '#ff0000';
    document.pages[0].elements.push(newElement('text', { text: 'Hola <marca>', x: 10, y: 10, width: 280, height: 60, fontSize: 24 }));
    const second = duplicatePage(document.pages[0]);
    second.background = '#0000ff';
    document.pages.push(second);
    const order = [second.id, document.pages[0].id];
    const images = await render(document, 'png', order);
    assert.equal(images.length, 2);
    for (const file of images) {
        assert.equal(file.bytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        assert.equal(file.bytes.readUInt32BE(16), 320);
        assert.equal(file.bytes.readUInt32BE(20), 240);
    }
    assert.notDeepEqual(images[0].bytes, images[1].bytes, 'Different backgrounds must render different pixels');
    const svg = (await render(document, 'svg', [document.pages[0].id]))[0].bytes.toString();
    assert.match(svg, /<svg/);
    assert.match(svg, /Hola &lt;marca&gt;/);
    assert.doesNotMatch(svg, /(?:href|src)=["']https?:/);
    const pdfBytes = (await render(document, 'pdf', order))[0].bytes;
    assert.equal(pdfBytes.subarray(0, 5).toString(), '%PDF-');
    assert.equal((await PDFDocument.load(pdfBytes)).getPageCount(), 2);
});

test('server render can load a registered private image without a cookie or external network', { timeout: 45000 }, async () => {
    const document = blankDocument(120, 120);
    document.pages[0].background = '#0000ff';
    document.pages[0].elements.push(newElement('image', { asset_id: 42, x: 10, y: 10, width: 1, height: 1, scaleX: 100, scaleY: 100 }));
    const files = await render(document, 'png', [document.pages[0].id], [{ id: 42, kind: 'image', mime: 'image/png', width: 1, height: 1,
        preview_url: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nDkAAAAASUVORK5CYII=' }]);
    assert.equal(files.length, 1);
    assert.equal(files[0].bytes.readUInt32BE(16), 120);
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const pixels = await page.evaluate(async data => {
            const bitmap = await createImageBitmap(await fetch(`data:image/png;base64,${data}`).then(response => response.blob()));
            const canvas = new OffscreenCanvas(120, 120); const context = canvas.getContext('2d'); context.drawImage(bitmap, 0, 0);
            return { center: [...context.getImageData(60, 60, 1, 1).data], outside: [...context.getImageData(1, 1, 1, 1).data] };
        }, files[0].bytes.toString('base64'));
        assert.deepEqual(pixels.center, [255, 255, 255, 255]);
        assert.deepEqual(pixels.outside, [0, 0, 255, 255]);
    } finally { await browser.close(); }
});

test('isolated server renderer honors page frame times and does not load inactive media', { timeout: 45000 }, async () => {
    const document = blankDocument(100, 100);
    document.schema_version = 2; document.timeline = { fps: 30, audio_tracks: [] };
    document.pages[0].duration = 4;
    document.pages[0].elements = [newElement('rect', { id: 'red', x: 0, y: 0, width: 60, height: 60, fill: '#ff0000', timing: { start: 1, duration: 1 } }),
        newElement('video', { id: 'future', asset_id: 999, x: 0, y: 0, width: 60, height: 60, timing: { start: 3, duration: 1 } })];
    const images = [];
    for (const time of [0, 1.5, 2]) images.push((await render(document, 'png', [document.pages[0].id], [], [], { frame_times: [{ page_id: document.pages[0].id, time }] }))[0].bytes.toString('base64'));
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const pixels = await page.evaluate(async images => {
            const output = [];
            for (const data of images) {
                const bitmap = await createImageBitmap(await fetch(`data:image/png;base64,${data}`).then(response => response.blob()));
                try { const canvas = document.createElement('canvas'); canvas.width = canvas.height = 100; const context = canvas.getContext('2d');
                    context.drawImage(bitmap, 0, 0); output.push([...context.getImageData(10, 10, 1, 1).data]); }
                finally { bitmap.close(); }
            }
            return output;
        }, images);
        assert.deepEqual(pixels, [[255, 255, 255, 255], [255, 0, 0, 255], [255, 255, 255, 255]]);
    } finally { await browser.close(); }
});

test('isolated server browser renders packaged private WOFF2 and embeds its exact bytes in portable SVG', { timeout: 120000 }, async () => {
    const bytes = await fs.readFile('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2');
    const font = { id: 17, brand_id: 1, name: 'Space Grotesk', family: 'Space Grotesk', weight: 400, style: 'normal', format: 'woff2', mime: 'font/woff2', size: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), status: 'ready', file_url: `data:font/woff2;base64,${bytes.toString('base64')}` };
    const document = blankDocument(400, 160);
    document.pages[0].elements = [newElement('text', { x: 10, y: 10, width: 380, height: 120, text: 'Marca 123 MmW', fontFamily: font.family, fontSize: 48, fill: '#111111' })];
    const [fallback] = await render(document, 'png', [document.pages[0].id]);
    document.pages[0].elements[0].font_id = font.id;
    const [explicit] = await render(document, 'png', [document.pages[0].id], [], [font]);
    assert.notDeepEqual(explicit.bytes, fallback.bytes);
    const [svg] = await render(document, 'svg', [document.pages[0].id], [], [font]);
    assert.ok(svg.bytes.toString().includes(font.file_url));
    assert.ok(svg.bytes.toString().includes('@font-face'));
    assert.equal(svg.bytes.toString().includes('studio-renderer.invalid/fonts'), false);
    await assert.rejects(render(document, 'png', [document.pages[0].id], [], [{ ...font, sha256: '0'.repeat(64) }]), /hash|identidad|fuente/i);
});
