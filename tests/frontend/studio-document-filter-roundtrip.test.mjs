import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { blankDocument, newElement, validateDocument, StudioDocumentError } from '../../resources/js/Studio/document.js';

function scene(version, nested = false) {
    const document = blankDocument(320, 180);
    const media = [newElement('image', { id: 'still', asset_id: 1 }), newElement('video', { id: 'clip', asset_id: 2 })];
    document.pages[0].elements = nested ? [newElement('group', { id: 'media_group', elements: media })] : media;
    if (version === 2) {
        document.schema_version = 2;
        document.timeline = { fps: 30, audio_tracks: [] };
    }
    return document;
}

function media(document) {
    const elements = document.pages[0].elements;
    return elements[0].type === 'group' ? elements[0].elements : elements;
}

function phpArrayRoundtrip(document) {
    // PHP associative decoding, also used by the server's array cast, loses empty JSON object identity.
    // No framework bootstrap, ini, environment file, database or filesystem access is involved.
    const result = spawnSync('php', ['-n', '-d', 'memory_limit=256M', '-r',
        'echo json_encode(json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);'],
    { input: JSON.stringify(document), encoding: 'utf8', timeout: 10000, maxBuffer: 2 * 1024 * 1024, windowsHide: true });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr);
    return JSON.parse(result.stdout);
}

for (const version of [1, 2]) for (const nested of [false, true]) {
    test(`PHP array roundtrip reopens empty image and video filters in v${version}${nested ? ' nested groups' : ''}`, () => {
        const original = scene(version, nested);
        const expected = validateDocument(original);
        const wire = phpArrayRoundtrip(expected);
        for (const element of media(wire)) assert.deepEqual(element.filters, []);
        const before = structuredClone(wire);
        const result = validateDocument(wire);
        assert.deepEqual(result, expected);
        assert.deepEqual(wire, before, 'normalization must not rewrite the received document');
        for (const element of media(result)) assert.deepEqual(element.filters, {});
        assert.deepEqual(validateDocument(phpArrayRoundtrip(result)), expected, 'subsequent save and reopen stays stable');
    });
}

test('missing, empty object and populated filters keep their canonical values', () => {
    const populated = { brightness: -1, contrast: 1, saturation: 0.2, blur: 1, grayscale: true, sepia: false, invert: true };
    for (const version of [1, 2]) for (const nested of [false, true]) for (const filters of [undefined, {}, populated]) {
        const document = scene(version, nested);
        for (const element of media(document)) {
            if (filters === undefined) delete element.filters;
            else element.filters = structuredClone(filters);
        }
        const before = structuredClone(document);
        const result = validateDocument(document);
        for (const element of media(result)) assert.deepEqual(element.filters, filters ?? {});
        assert.deepEqual(document, before);
    }
});

test('filter compatibility rejects nonempty arrays and other invalid filter values', () => {
    const decorated = [];
    decorated.brightness = 0.5;
    const unknown = [];
    unknown.untrusted = true;
    for (const version of [1, 2]) for (const type of ['image', 'video']) {
        for (const filters of [[0], [{}], [null], Array(1), decorated, unknown, '', 0, false,
            { untrusted: true }, { brightness: 1.01 }, { blur: -0.01 }, { grayscale: 1 }]) {
            const document = scene(version);
            const element = media(document).find(element => element.type === type);
            element.filters = structuredClone(filters);
            assert.throws(() => validateDocument(document), error => error instanceof StudioDocumentError && /filters/.test(error.message));
        }
    }
});

test('empty-list compatibility is local to filters and keeps document and object validation strict', () => {
    for (const mutate of [
        document => { document.pages[0].elements[0].shadow = []; },
        document => { document.pages[0].elements[0].mask = []; },
        document => { document.pages[0].elements[0].fill = []; },
        document => { document.pages[0].untrusted = []; },
        document => { document.untrusted = []; },
    ]) {
        const document = scene(1);
        mutate(document);
        assert.throws(() => validateDocument(document), StudioDocumentError);
    }
});
