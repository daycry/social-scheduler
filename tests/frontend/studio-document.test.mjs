import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, cloneDocument, duplicatePage, findPage, newElement, selectPages, validateDocument, StudioDocumentError } from '../../resources/js/Studio/document.js';

test('line and path defaults are visible strokes while explicit styles remain unchanged', () => {
    for (const type of ['line', 'path']) {
        const element = newElement(type);
        assert.match(element.stroke, /^#[0-9a-f]{6}$/i);
        assert.ok(element.strokeWidth > 0);
        assert.equal(element.fill, '#00000000');
        const custom = newElement(type, { fill: '#ff0000', stroke: '', strokeWidth: 0 });
        assert.equal(custom.fill, '#ff0000');
        assert.equal(custom.stroke, '');
        assert.equal(custom.strokeWidth, 0);
        const document = blankDocument();
        document.pages[0].elements.push({ id: `default_${type}`, type, ...(type === 'path' ? { path: element.path } : {}) });
        assert.equal(validateDocument(document).pages[0].elements[0].stroke, element.stroke);
        assert.equal(validateDocument(document).pages[0].elements[0].strokeWidth, element.strokeWidth);
    }
});

test('blank document uses requested dimensions and independent page identities', () => {
    const first = blankDocument(1080, 1920);
    const second = blankDocument(1080, 1920);
    assert.equal(first.schema_version, 1);
    assert.equal(first.width, 1080);
    assert.equal(first.height, 1920);
    assert.equal(first.pages.length, 1);
    assert.deepEqual(first.pages[0].elements, []);
    assert.notEqual(first.pages[0].id, second.pages[0].id);
    assert.deepEqual(validateDocument(first), first);
});

test('clone and duplicate page replace every nested id without sharing mutable state', () => {
    const doc = blankDocument();
    const group = newElement('group', { elements: [newElement('text', { text: 'Árbol 👩‍🚀' })] });
    doc.pages[0].elements.push(group);
    const cloned = cloneDocument(doc, { newIds: true });
    const copy = duplicatePage(doc.pages[0]);
    assert.notEqual(cloned.pages[0].id, doc.pages[0].id);
    assert.notEqual(cloned.pages[0].elements[0].elements[0].id, group.elements[0].id);
    assert.notEqual(copy.elements[0].id, group.id);
    cloned.pages[0].elements[0].elements[0].text = 'Otro';
    assert.equal(group.elements[0].text, 'Árbol 👩‍🚀');
});

test('page selection preserves explicit order and rejects absent or repeated ids', () => {
    const doc = blankDocument();
    doc.pages.push(duplicatePage(doc.pages[0]));
    const ids = doc.pages.map(p => p.id);
    assert.deepEqual(selectPages(doc, [...ids].reverse()).map(p => p.id), [...ids].reverse());
    assert.equal(findPage(doc, ids[0]).id, ids[0]);
    assert.throws(() => selectPages(doc, ['missing']), StudioDocumentError);
    assert.throws(() => selectPages(doc, [ids[0], ids[0]]), StudioDocumentError);
});

test('validator rejects nonfinite geometry, duplicate ids, external image URLs and excessive nesting', () => {
    for (const modify of [
        d => { d.width = 9000; },
        d => { d.pages[0].elements = [newElement('rect', { x: Infinity })]; },
        d => { const e = newElement('rect'); d.pages[0].elements = [e, structuredClone(e)]; },
        d => { d.pages[0].elements = [newElement('image', { asset_id: 1, src: 'https://untrusted.invalid/image.png' })]; },
        d => { let e = newElement('rect'); for (let i = 0; i < 10; i++) e = newElement('group', { elements: [e] }); d.pages[0].elements = [e]; },
    ]) {
        const doc = blankDocument();
        modify(doc);
        assert.throws(() => validateDocument(doc), StudioDocumentError);
    }
});

test('validation preserves gradients, filters, affine transforms and vector paths', () => {
    const doc = blankDocument();
    doc.pages[0].background = { type: 'linear', angle: 45, stops: [{ offset: 0, color: '#ffffff' }, { offset: 1, color: '#7c3aed' }] };
    doc.pages[0].elements = [newElement('image', { asset_id: 19, skewX: 12, filters: { brightness: 0.2, saturation: -0.4, grayscale: true } }), newElement('path', { path: [['M', 0, 0], ['Q', 25, 10, 50, 50], ['Z']] })];
    const result = validateDocument(doc);
    assert.deepEqual(result.pages[0].background, doc.pages[0].background);
    assert.equal(result.pages[0].elements[0].filters.saturation, -0.4);
    assert.equal(result.pages[0].elements[0].skewX, 12);
    assert.deepEqual(result.pages[0].elements[1].path, doc.pages[0].elements[1].path);
});

test('server-valid shadow offsets and ten thousand vector commands can reopen in the editor', () => {
    const document = blankDocument();
    document.pages[0].elements.push(newElement('rect', { shadow: { color: '#00000080', blur: 500, offsetX: 2000, offsetY: -2000 } }));
    document.pages[0].elements.push(newElement('path', { path: Array.from({ length: 10000 }, (_, i) => ['L', i, i]) }));
    assert.equal(validateDocument(document).pages[0].elements[0].shadow.offsetX, 2000);
    assert.equal(validateDocument(document).pages[0].elements[1].path.length, 10000);
});

test('a minimally trimmed short video shares the server end validation', () => {
    const document = blankDocument();
    document.pages[0].elements.push(newElement('video', { asset_id: 1, trimStart: 2, trimEnd: 2.0001 }));
    assert.equal(validateDocument(document).pages[0].elements[0].trimEnd, 2.0001);
});

test('text limits count Unicode code points like the server rather than UTF-16 code units', () => {
    const document = blankDocument();
    document.pages[0].elements.push(newElement('text', { text: '🌿'.repeat(10000) }));
    assert.equal(validateDocument(document).pages[0].elements[0].text, '🌿'.repeat(10000));
    document.pages[0].elements[0].text += '🌿';
    assert.throws(() => validateDocument(document), StudioDocumentError);
});
