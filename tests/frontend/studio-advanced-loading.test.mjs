import test from 'node:test';
import assert from 'node:assert/strict';
import { waitForStudioFonts } from '../../resources/js/Studio/canvas.js';
import { loadStudioFont } from '../../resources/js/Studio/fonts.js';

async function withFonts(work) {
    const original = globalThis.document;
    globalThis.document = { fonts: { load: () => Promise.resolve([]), ready: Promise.resolve() } };
    try { await work(); }
    finally { if (original === undefined) delete globalThis.document; else globalThis.document = original; }
}

test('an explicit private font never silently falls back when its registered resource is absent', async () => {
    await withFonts(async () => {
        await assert.rejects(waitForStudioFonts([{ type: 'text', text: 'Marca', fontFamily: 'Atlas', font_id: 42 }], { fonts: [] }), /fuente.*42|42.*fuente/i);
    });
});

test('private font loading rejects a resource URL outside the application before fetching', async () => {
    await withFonts(async () => {
        const font = { id: 42, family: 'Atlas', name: 'Atlas', weight: 400, style: 'normal', format: 'woff2', mime: 'font/woff2', size: 20, sha256: 'a'.repeat(64), status: 'ready', file_url: 'https://foreign.invalid/private.woff2' };
        await assert.rejects(waitForStudioFonts([{ type: 'text', text: 'Marca', fontFamily: 'Atlas', font_id: 42 }], { fonts: [font] }), /privada|origen|aplicación/i);
    });
});

test('private font decoding has a deadline even after the HTTP body finished downloading', async () => {
    const originals = { document: globalThis.document, FontFace: globalThis.FontFace, fetch: globalThis.fetch };
    const bytes = new Uint8Array(20); bytes.set([119, 79, 70, 50]);
    globalThis.document = { fonts: { add() {}, delete() {} } };
    globalThis.fetch = async () => new Response(bytes);
    globalThis.FontFace = class { load() { return new Promise(() => {}); } };
    try {
        const font = { id: 42, family: 'Atlas', name: 'Atlas', weight: 400, style: 'normal', format: 'woff2', mime: 'font/woff2', size: bytes.length, sha256: 'a'.repeat(64), status: 'ready', file_url: '/font/42' };
        const result = await Promise.race([
            loadStudioFont(font, { timeout: 10 }).then(() => 'loaded', () => 'rejected'),
            new Promise(resolve => setTimeout(() => resolve('hanging'), 100)),
        ]);
        assert.equal(result, 'rejected');
    } finally {
        for (const [key, value] of Object.entries(originals)) if (value === undefined) delete globalThis[key]; else globalThis[key] = value;
    }
});
