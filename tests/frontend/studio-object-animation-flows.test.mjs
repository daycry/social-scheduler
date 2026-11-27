import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse } from '@babel/parser';
import { animationState, normalizeAnimation } from '../../resources/js/Studio/object-animation.js';
import { applyElementAnimation } from '../../resources/js/Studio/animation-scene.js';
import { blankDocument, newElement, validateDocument } from '../../resources/js/Studio/document.js';
import { clipMediaState, compileTimeline, restrictElementWindow, sampleTimeline, splitTimelineElement } from '../../resources/js/Studio/timeline.js';

const near = (actual, expected, message) => assert.ok(Math.abs(actual - expected) < 1e-9, `${message ?? ''} ${actual} != ${expected}`);
const window = { start: 1, duration: 4 };

function scene(animation, timing = window) {
    const doc = blankDocument(320, 180);
    doc.schema_version = 2;
    doc.timeline = { fps: 30, audio_tracks: [] };
    doc.pages[0].duration = 6;
    doc.pages[0].elements = [newElement('text', { id: 'title', text: 'Hola', ...(timing ? { timing } : {}), ...(animation ? { animation } : {}) })];
    return doc;
}

function fakeObject() {
    return {
        left: 10, top: 20, scaleX: 2, scaleY: 2, opacity: 0.8, dirty: false,
        getRelativeCenterPoint() { return { x: this.left + 50, y: this.top + 25 }; },
        set(values) { Object.assign(this, values); },
        setPositionByOrigin(point) { this.left = point.x - 50; this.top = point.y - 25; },
        setCoords() {},
    };
}

test('settled state is the identity: designed look with entrances completed and exits not started', () => {
    const animation = { in: { type: 'zoom', duration: 1 }, out: { type: 'slide_left', duration: 1 } };
    for (const time of [1, 1.5, 4.5]) assert.deepEqual(animationState(animation, time, window, { settled: true }), { opacity: 1, offsetX: 0, offsetY: 0, scale: 1 });
    assert.notDeepEqual(animationState(animation, 1, window), animationState(animation, 1, window, { settled: true }));
});

test('settled applications leave the object untouched even with an animation', () => {
    const object = fakeObject(), before = JSON.stringify(object);
    applyElementAnimation(object, { timing: window, animation: { in: { type: 'fade', duration: 1 } } }, 1, { width: 320, height: 180, pageDuration: 6, settled: true });
    assert.equal(JSON.stringify(object), before);
});

test('a child inside a group animates over the window shared with the group and hands that window on', () => {
    const size = { width: 320, height: 180, pageDuration: 6 };
    const groupWindow = applyElementAnimation(fakeObject(), { timing: { start: 1, duration: 3 } }, 2, size);
    assert.deepEqual(groupWindow, { start: 1, duration: 3 });
    const child = { timing: { start: 0, duration: 6 }, animation: { in: { type: 'fade', duration: 1, easing: 'linear' } } };
    const object = fakeObject();
    assert.deepEqual(applyElementAnimation(object, child, 1.5, { ...size, parent: groupWindow }), { start: 1, duration: 3 });
    near(object.opacity, 0.8 * 0.5, 'la entrada del hijo parte del inicio común, no del suyo');
});

test('ungrouping keeps a group entrance and a child exit when their envelopes fit', () => {
    const child = scene({ out: { type: 'zoom', duration: 0.5 } }).pages[0].elements[0];
    const parentAnimation = { in: { type: 'fade', duration: 1.5 }, out: { type: 'slide_left', duration: 1 } };
    const result = restrictElementWindow(child, { start: 0, duration: 6 }, { pageDuration: 6, parentAnimation });
    assert.deepEqual(result.animation, { in: { type: 'fade', duration: 1.5, delay: -1, clipped: true }, out: { type: 'zoom', duration: 0.5 } });
});

test('ungrouping reports a group phase that cannot be combined with the child phase', () => {
    const animation = { in: { type: 'fade', duration: 1 } };
    const child = scene(animation, { start: 0, duration: 4 }).pages[0].elements[0];
    const parentAnimation = { in: { type: 'slide_left', duration: 0.5 } };
    let losses = 0;
    const result = restrictElementWindow(child, { start: 0, duration: 4 }, {
        pageDuration: 6, parentAnimation, onAnimationLoss: () => losses++,
    });
    assert.equal(losses, 1);
    assert.deepEqual(result.animation, animation);
});

test('ungrouping preserves a group zoom for a centered child and reports the approximation otherwise', () => {
    const child = scene(null, { start: 0, duration: 4 }).pages[0].elements[0];
    const parentAnimation = { in: { type: 'zoom', duration: 1 } };
    const parentWindow = { start: 0, duration: 4 };
    let losses = 0;
    const options = { pageDuration: 6, parentAnimation, onAnimationLoss: () => losses++ };
    const centered = restrictElementWindow(child, parentWindow, { ...options, centered: true });
    assert.deepEqual(centered.animation, parentAnimation);
    assert.equal(losses, 0);
    const offCenter = restrictElementWindow(child, parentWindow, options);
    assert.deepEqual(offCenter.animation, { in: { type: 'fade', duration: 1 } });
    assert.equal(losses, 1);
});

test('cropped phases use the same validation as the server', () => {
    const entrance = { type: 'slide_left', duration: 2, delay: -0.75, clipped: true };
    const exit = { type: 'zoom', duration: 1, delay: 2, clipped: true };
    assert.deepEqual(normalizeAnimation({ in: entrance }, 0.5), { in: entrance });
    assert.deepEqual(normalizeAnimation({ out: exit }, 0.5), { out: exit });
    for (const animation of [
        { in: { ...entrance, clipped: false } },
        { in: { ...entrance, delay: -11 } },
        { in: entrance, out: exit },
    ]) assert.throws(() => normalizeAnimation(animation, 0.5));
});

for (const [name, animation, cut] of [
    ['completed exit', { out: { type: 'fade', duration: 1, delay: 2 } }, 3],
    ['delayed entrance', { in: { type: 'fade', duration: 1, delay: 2 } }, 1],
    ['eased slide in progress', { in: { type: 'slide_left', duration: 2, delay: 0.5, easing: 'ease_in' } }, 1],
    ['eased zoom in progress', { out: { type: 'zoom', duration: 2, delay: 0.5, easing: 'ease_out' } }, 3],
    ['entrance with less than 0.1 s before the cut', { in: { type: 'fade', duration: 1, delay: 0.95 } }, 1],
]) {
    test(`splitting preserves each frame of a ${name}`, () => {
        const timing = { start: 0, duration: 4 };
        const doc = scene(animation, timing);
        const result = splitTimelineElement(doc, doc.pages[0].id, 'title', cut, { createId: () => 'second_half' });
        for (const half of result.pages[0].elements) {
            for (let frame = 0; frame <= 60; frame++) {
                const time = half.timing.start + half.timing.duration * frame / 60;
                const expected = animationState(animation, time, timing);
                const actual = animationState(half.animation, time, half.timing);
                for (const key of Object.keys(expected)) near(actual[key], expected[key], `${key} at ${time}`);
            }
        }
    });
}

test('splitting a group keeps a timed child on its original curve', () => {
    const animation = { in: { type: 'zoom', duration: 2 } };
    const doc = scene(animation, { start: 0, duration: 4 });
    doc.pages[0].elements = [newElement('group', { id: 'group', timing: { start: 1, duration: 3 }, elements: doc.pages[0].elements })];
    let id = 0;
    const result = splitTimelineElement(doc, doc.pages[0].id, 'group', 2, { createId: () => `split_${++id}` });
    for (const [index, time] of [1.5, 2.5].entries()) {
        const group = result.pages[0].elements[index];
        const object = fakeObject();
        applyElementAnimation(object, group.elements[0], time, { width: 320, height: 180, pageDuration: 6, parent: group.timing });
        const expected = animationState(animation, time, { start: 1, duration: 3 });
        near(object.opacity, 0.8 * expected.opacity);
        near(object.scaleX, 2 * expected.scale);
    }
});

test('ungrouping does not replay a completed group entrance on a late child', () => {
    const child = scene(null, { start: 2, duration: 2 }).pages[0].elements[0];
    const parentAnimation = { in: { type: 'fade', duration: 0.5 } };
    const result = restrictElementWindow(child, { start: 0, duration: 4 }, { pageDuration: 6, parentAnimation });
    assert.equal(animationState(result.animation, 2.1, result.timing).opacity, 1);
});

test('a short clip can be animated: the default duration is whatever fits, never below 0.1 s', async () => {
    const { ref } = await import('vue');
    const { useTimelineEditing } = await import('../../resources/js/Studio/composables/useTimelineEditing.js');
    const doc = scene(null, { start: 1, duration: 0.3 });
    const documentData = ref(doc), page = ref(doc.pages[0]);
    const editing = useTimelineEditing({
        documentData, page, pageId: ref(doc.pages[0].id), selection: ref([{ id: 'title' }]), localAssets: ref([]),
        timeline: ref(0), timelineDuration: ref(6), timelinePageStart: ref(0), timelineOpen: ref(true), pauseTimeline() {}, error: ref(''),
        run: fn => fn(), commit() {}, reloadCanvas: async () => {}, selectOnCanvas() {}, isCaptionBlocked: () => false,
    });
    await editing.patchClipAnimation({ phase: 'in', patch: { type: 'zoom' } });
    assert.equal(documentData.value.pages[0].elements[0].animation.in.duration, 0.3);
});

const loadBody = async relative => (await readFile(new URL('../../' + relative, import.meta.url), 'utf8')).replace(/^import .*;\r?\n/gm, '')
    .replaceAll('export async function', 'async function').replaceAll('export function', 'function').replaceAll('export const', 'const');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

test('static frames render animations settled unless the page has an explicit instant', async () => {
    const body = await loadBody('resources/js/Studio/exports.js');
    const calls = [];
    const rendered = { canvas: { backgroundColor: '', renderAll() {}, toSVG: () => '<svg/>', getObjects: () => [], getZoom: () => 1 }, fontFaces: () => [], async dispose() {} };
    const factory = new AsyncFunction('validateDocument', 'frameTimes', 'renderStudioFrame', 'StudioDocumentError', body + ';return exportPage;');
    const exportPage = await factory(x => x, () => new Map([['p', 0]]), async (...args) => { calls.push(args[5].settled); return rendered; }, Error);
    const design = { width: 100, height: 100, pages: [] };
    await exportPage(design, 'p', [], { format: 'svg' });
    await exportPage(design, 'p', [], { format: 'svg', frame_times: [{ page_id: 'p', time: 0 }] });
    await exportPage(design, 'p', [], { format: 'svg', frame_times: [{ page_id: 'other', time: 0 }] });
    assert.deepEqual(calls, [true, false, true]);
});

test('renderStudioFrame applies animations at its instant, none when settled, and never forwards the flag to the page renderer', async () => {
    const body = await loadBody('resources/js/Studio/frame-renderer.js');
    const applied = [];
    let received;
    const rendered = { canvas: { getObjects: () => [{ id: 1 }], renderAll() {} }, async dispose() {} };
    const run = new AsyncFunction('renderStudioPage', 'frameElements', 'compileTimeline', 'attachFrameCaptions', 'applyElementAnimation', body + ';return renderStudioFrame;');
    const frame = await run(async (...args) => { received = args; return rendered; }, x => x, () => ({ pages: [{ id: 'p', start: 0 }] }), async s => s,
        (_object, _layer, time, options) => { applied.push([time, options.settled]); });
    const doc = { schema_version: 2, width: 100, height: 100, timeline: { fps: 30 }, pages: [{ id: 'p', duration: 4, elements: [{ id: 'a', type: 'rect', animation: { in: { type: 'fade', duration: 1 } } }] }] };
    await frame(doc, 'p', 0.5, [], [], {});
    await frame(doc, 'p', 0.5, [], [], { settled: true });
    assert.deepEqual(applied, [[0.5, false], [0.5, true]]);
    assert.equal(Object.hasOwn(received[4], 'settled'), false);
});

test('the offline project render (MP4/GIF) applies the animation of each object at every sampled instant', async () => {
    const source = await readFile(new URL('../../resources/js/Studio/timeline-preview.js', import.meta.url), 'utf8');
    const factory = parse(source, { sourceType: 'module' }).program.body.find(node => node.type === 'ExportNamedDeclaration' && node.declaration?.id?.name === 'createTimelinePreview').declaration;
    const instantiate = Function('validateDocument', 'renderStudioPage', 'seekVideoFrame', 'videoFrameReady', 'clipMediaState', 'compileTimeline', 'sampleTimeline',
        'createTimelineAudio', 'createCaptionLayer', 'assertChartDocumentBudget', 'applyElementAnimation', `return (${source.slice(factory.start, factory.end)});`);
    const doc = scene({ in: { type: 'fade', duration: 1, easing: 'linear' } });
    const object = fakeObject();
    const seen = [];
    const create = instantiate(validateDocument, async () => ({ canvas: { getObjects: () => [object], renderAll() {}, lowerCanvasEl: {} }, async dispose() {} }),
        async () => {}, async () => {}, clipMediaState, compileTimeline, sampleTimeline,
        () => ({ prepare: async () => ({ bufferingMs: 0 }), pause() {}, pauseMedia() {}, play: async () => {}, state: () => [], dispose: async () => {} }),
        async () => null, async () => {},
        (target, element, time, options) => { const result = applyElementAnimation(target, element, time, options); seen.push([time, target.opacity]); return result; });
    const context = { clearRect() {}, save() {}, restore() {}, drawImage() {}, fillRect() {} };
    const preview = await create({ getContext: () => context }, { document: doc, offline: true });
    await preview.seek(1.5);
    await preview.seek(3);
    await preview.dispose();
    assert.equal(seen.length, 2);
    near(seen[0][0], 1.5); near(seen[0][1], 0.8 * 0.5, 'a mitad de la entrada');
    near(seen[1][0], 3); near(seen[1][1], 0.8, 'tras la entrada');
});
