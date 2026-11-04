import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import { PDFDocument } from 'pdf-lib';
import { blankDocument, newElement, duplicatePage } from '../../resources/js/Studio/document.js';

const base = path.resolve('storage/app/private/studio-exports/0/0');
async function render(document, format, pageIds, assets = []) {
    const directory = path.join(base, crypto.randomUUID());
    assert.ok(directory.startsWith(`${base}${path.sep}`));
    const input = { document, format, pageIds, assets };
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
    document.pages[0].elements.push(newElement('image', { asset_id: 42, x: 10, y: 10, width: 100, height: 100 }));
    const files = await render(document, 'png', [document.pages[0].id], [{ id: 42, kind: 'image', mime: 'image/png', width: 1, height: 1,
        preview_url: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nDkAAAAASUVORK5CYII=' }]);
    assert.equal(files.length, 1);
    assert.equal(files[0].bytes.readUInt32BE(16), 120);
});
