import test from 'node:test';
import assert from 'node:assert/strict';
import { STUDIO_TEMPLATES, createFromTemplate } from '../../resources/js/Studio/templates.js';
import { validateDocument } from '../../resources/js/Studio/document.js';

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
