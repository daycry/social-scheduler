import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, newElement, validateDocument } from '../../resources/js/Studio/document.js';
import { studioObjectToElement } from '../../resources/js/Studio/canvas.js';

test('explicit private font references survive validation and can be removed without changing text', () => {
    const document = blankDocument();
    document.pages[0].elements.push(newElement('text', { text: 'Identidad de marca', font_id: 42, fontFamily: 'Archivo de marca' }));
    const explicit = validateDocument(document);
    assert.equal(explicit.pages[0].elements[0].font_id, 42);
    explicit.pages[0].elements[0].font_id = null;
    const local = validateDocument(explicit).pages[0].elements[0];
    assert.equal(Object.hasOwn(local, 'font_id'), false);
    assert.equal(local.text, 'Identidad de marca');
    for (const value of [0, -1, 1.5, '42']) {
        document.pages[0].elements[0].font_id = value;
        assert.throws(() => validateDocument(document), /font_id/);
    }
});

test('image masks use known shapes only, roundtrip radius and support explicit removal', () => {
    const document = blankDocument();
    document.pages[0].elements.push(newElement('image', { asset_id: 1, mask: { type: 'rounded_rect', radius: 23 } }));
    const masked = validateDocument(document);
    assert.deepEqual(masked.pages[0].elements[0].mask, { type: 'rounded_rect', radius: 23 });
    masked.pages[0].elements[0].mask = { type: 'ellipse' };
    assert.deepEqual(validateDocument(masked).pages[0].elements[0].mask, { type: 'ellipse' });
    masked.pages[0].elements[0].mask = null;
    assert.equal(Object.hasOwn(validateDocument(masked).pages[0].elements[0], 'mask'), false);
    for (const value of [{ type: 'path', path: '<svg />' }, { type: 'ellipse', absolutePositioned: true }, { type: 'rounded_rect', radius: -1 }]) {
        document.pages[0].elements[0].mask = value;
        assert.throws(() => validateDocument(document), /mask/);
    }
});

test('canvas serialization preserves the private font identity and mask descriptor instead of engine internals', () => {
    const base = { left: 0, top: 0, width: 64, height: 64, angle: 0, scaleX: 1, scaleY: 1, skewX: 0, skewY: 0, opacity: 1, visible: true, fill: '#112233', stroke: '', strokeWidth: 0 };
    const text = { ...base, studioType: 'text', studioId: 'title', text: 'Marca', fontFamily: 'StudioFont_17_aaaaaaaaaaaaaaaa', studioFontFamily: 'Space Grotesk', studioFontId: 17, fontSize: 32, fontWeight: 'normal', fontStyle: 'normal', textAlign: 'left', underline: false, lineHeight: 1.2, charSpacing: 0 };
    const savedText = studioObjectToElement(text);
    assert.equal(savedText.font_id, 17);
    assert.equal(savedText.fontFamily, 'Space Grotesk');
    const image = { ...base, studioType: 'image', studioId: 'picture', studioAssetId: 1, studioMask: { type: 'ellipse' }, studioFilters: {} };
    assert.deepEqual(studioObjectToElement(image).mask, { type: 'ellipse' });
});

test('documents cannot impersonate a previously loaded private face through a local family name', () => {
    const document = blankDocument();
    document.pages[0].elements = [newElement('text', { fontFamily: 'StudioFont_17_aaaaaaaaaaaaaaaa', text: 'No retained font' })];
    assert.throws(() => validateDocument(document), /familia.*privada|reservad/i);
});

test('font family syntax rejects quoted names and reserved private fallback families', () => {
    for (const fontFamily of ['"StudioFont_17_1234567890123456"', 'Arial, StudioFont_17_1234567890123456', ' Arial', 'Arial\n']) {
        const document = blankDocument();
        document.pages[0].elements = [newElement('text', { fontFamily })];
        assert.throws(() => validateDocument(document), /fontFamily/);
    }
});
