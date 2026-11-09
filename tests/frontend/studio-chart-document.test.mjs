import test from 'node:test';
import assert from 'node:assert/strict';
import { blankDocument, newElement, validateDocument, cloneDocument, duplicatePage, StudioDocumentError } from '../../resources/js/Studio/document.js';
import { usedStudioFonts, createStudioFontRegistry } from '../../resources/js/Studio/fonts.js';
const chart = () => ({ binding: { dataset: { id: 1, revision: 2, sha256: 'a'.repeat(64) }, category_column: 'category', series_columns: ['value'] } });
const documentWithChart = () => { const doc = blankDocument(); doc.pages[0].elements = [newElement('chart', { chart: chart() })]; return doc; };

test('C1 document chart defaults and typed configuration survive clone, duplicate and JSON reopen', () => {
    const input = documentWithChart(); const output = validateDocument(input); const element = output.pages[0].elements[0];
    assert.equal(element.name, 'Gráfico'); assert.equal(element.width, 800); assert.equal(element.height, 500);
    assert.equal(element.chart.options.kind, 'column'); assert.equal(element.chart.binding.dataset.revision, 2);
    assert.deepEqual(validateDocument(JSON.parse(JSON.stringify(output))), output);
    const clone = cloneDocument(output, { newIds: true }); const page = duplicatePage(output.pages[0]);
    assert.notEqual(clone.pages[0].elements[0].id, element.id); assert.notEqual(page.elements[0].id, element.id);
    assert.deepEqual(page.elements[0].chart, element.chart);
    page.elements[0].chart.binding.dataset.id = 99; assert.equal(element.chart.binding.dataset.id, 1);
    assert.equal(Object.hasOwn(newElement('chart'), 'chart'), false);
    assert.throws(() => validateDocument({ ...input, pages: [{ ...input.pages[0], elements: [newElement('chart')] }] }), StudioDocumentError);
});
test('C1 document chart geometry, identities and configuration errors remain typed', () => {
    for (const patch of [{ width: 159 }, { height: 8001 }, { chart: { ...chart(), html: '<svg/>' } }, { chart: { ...chart(), options: {} } }, { elements: [] }, { table: [] }]) {
        const doc = documentWithChart(); Object.assign(doc.pages[0].elements[0], patch);
        assert.throws(() => validateDocument(doc), StudioDocumentError);
    }
    const doc = documentWithChart(); doc.pages[0].elements[0].width = 160; doc.pages[0].elements[0].height = 8000;
    assert.equal(validateDocument(doc).pages[0].elements[0].height, 8000);
    doc.pages[0].elements.push(structuredClone(doc.pages[0].elements[0])); assert.throws(() => validateDocument(doc), StudioDocumentError);
    const invalid = documentWithChart(); invalid.pages[0].elements[0].chart.style = { font_id: null };
    assert.throws(() => validateDocument(invalid), error => error instanceof StudioDocumentError && /pages.0.elements.0.chart/.test(error.message));
});
test('C1 temporal chart keeps timing, transition and captions through the existing v2 validator', () => {
    const input = documentWithChart(); input.schema_version = 2;
    input.timeline = { fps: 30, audio_tracks: [], captions: { style: { fontSize: 24, position: 'bottom' }, cues: [{ id: 'cue_1', start: 0.5, end: 1.5, text: 'Hola 🎵' }] } };
    input.pages.push({ id: 'second_page', name: 'Segunda', background: '#ffffff', duration: 5, elements: [] });
    input.pages[0].transition = { type: 'fade', duration: 0.5 };
    input.pages[0].elements[0].timing = { start: 1, duration: 2 };
    const output = validateDocument(input);
    assert.equal(output.schema_version, 2); assert.deepEqual(output.timeline.audio_tracks, []);
    assert.deepEqual(output.timeline.captions, input.timeline.captions);
    assert.deepEqual(output.pages[0].transition, input.pages[0].transition);
    assert.deepEqual(output.pages[0].elements[0].timing, input.pages[0].elements[0].timing);
    assert.equal(output.pages[0].elements[0].chart.options.kind, 'column');
});
test('C1 fonts walker collects nested chart identities and registry resolves actual family, weight and style', () => {
    const font = { id: 7, sha256: 'a'.repeat(64), status: 'ready', mime: 'font/woff2', format: 'woff2', size: 32, weight: 600, style: 'italic', file_url: '/fonts/7' };
    const element = newElement('chart', { chart: { ...chart(), style: { font_id: 7, fontFamily: 'Arial', fontWeight: 'normal', fontStyle: 'normal' } } });
    const elements = [newElement('group', { elements: [element, newElement('text', { font_id: 7 })] })];
    assert.deepEqual(usedStudioFonts(elements, [font]), [font]);
    assert.throws(() => usedStudioFonts(elements, []), StudioDocumentError);
    const registry = createStudioFontRegistry([font]);
    assert.equal(registry.family(element), 'StudioFont_7_aaaaaaaaaaaaaaaa');
    assert.deepEqual(registry.style(element), { fontFamily: 'StudioFont_7_aaaaaaaaaaaaaaaa', fontWeight: 600, fontStyle: 'italic' });
    assert.deepEqual(registry.style(newElement('chart', { chart: chart() })), { fontFamily: 'Arial', fontWeight: 'normal', fontStyle: 'normal' });
    registry.dispose();
});

test('characterization: charts retain the existing 2 MiB document byte ceiling', () => {
    const input = documentWithChart();
    input.pages[0].elements = Array.from({ length: 500 }, (_, i) => newElement('chart', {
        id: `chart_${i}`, chart: { ...chart(), style: Object.fromEntries(['title', 'subtitle', 'source', 'x_title', 'y_title'].map(key => [key, '😀'.repeat(256)])) },
    }));
    assert.throws(() => validateDocument(input), error => error instanceof StudioDocumentError && /2 MiB/.test(error.message));
});
