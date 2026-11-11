import test from 'node:test';
import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { blankDocument, newElement } from '../../resources/js/Studio/document.js';
import { prepareBarChart } from '../../resources/js/Studio/bar-geometry.js';
import { datasetNumber } from '../../resources/js/Studio/dataset-table.js';

// In-memory Fabric surface only. Real text rasterization/export remains independent QA.
class ObjectSurface {
    constructor(options = {}) { Object.assign(this, { left: 0, top: 0, width: 0, height: 0, scaleX: 1, scaleY: 1, angle: 0, skewX: 0, skewY: 0, flipX: false, flipY: false, strokeWidth: 0, visible: true }, options); }
    set(a, b) { Object.assign(this, typeof a === 'string' ? { [a]: b } : a); return this; }
    setCoords() {} dispose() { this.disposed = true; }
}
class Group extends ObjectSurface { constructor(children, options) { super(options); this.children = children; } getObjects() { return this.children; } }
class Textbox extends ObjectSurface { constructor(text, options) { super(options); this.text = text; this.width = text === 'Valores' ? this.fontSize * 62.802 / 18 : Math.max(1, text.length * this.fontSize * 0.6); this.height = this.fontSize * (text === 'Valores' ? 1.13 : 1.2); } }
class Canvas extends ObjectSurface {
    constructor(element, options) { super(options); this.children = []; this.upperCanvasEl = { setAttribute() {}, addEventListener() {}, removeEventListener() {} }; }
    add(...objects) { this.children.push(...objects); }
    insertAt(index, ...objects) { this.children.splice(index, 0, ...objects); }
    getObjects() { return this.children; } remove(...objects) { this.children = this.children.filter(o => !objects.includes(o)); }
    getActiveObjects() { return this.selected ?? []; } getActiveObject() { return this.active; }
    discardActiveObject() { this.selected = []; this.active = null; } setActiveObject(o) { this.active = o; this.selected = o instanceof fabric.ActiveSelection ? o.getObjects() : [o]; }
    requestRenderAll() {} renderAll() {} setDimensions() {} setViewportTransform() {} on() {} off() {}
}
const names = ['Rect', 'Ellipse', 'Triangle', 'Line', 'Path', 'FabricImage', 'Gradient', 'Shadow', 'PencilBrush', 'Point', 'LayoutManager', 'FixedLayout'];
const fabric = { Canvas, StaticCanvas: Canvas, Group, Textbox, FabricText: Textbox, ActiveSelection: class extends Group {}, util: {}, filters: {} };
for (const name of names) fabric[name] = class extends ObjectSurface {};
fabric.FabricImage = class extends ObjectSurface { constructor(element, options) { super(options); this.element = element; this.filters = []; } getElement() { return this.element; } applyFilters() {} };
globalThis.__t12Fabric = fabric;
const hook = registerHooks({ resolve(specifier, context, next) {
    if (specifier === 'fabric' && context.parentURL?.includes('t12ChartSurface')) return { url: 't12:fabric', shortCircuit: true };
    const resolved = next(specifier, context);
    if (specifier === './chart-renderer.js' && context.parentURL?.includes('t12ChartSurface')) return { ...resolved, url: `${resolved.url}?t12ChartSurface` };
    return resolved;
}, load(url, context, next) { return url === 't12:fabric' ? { format: 'module', source: `export const {${Object.keys(fabric).join(',')}}=globalThis.__t12Fabric;`, shortCircuit: true } : next(url, context); } });
const { createStudioCanvas, elementToFabric, studioObjectToElement, waitForStudioFonts, renderStudioPage } = await import('../../resources/js/Studio/canvas.js?t12ChartSurface');
const { createChartFabric } = await import('../../resources/js/Studio/chart-renderer.js?t12ChartSurface');
hook.deregister();
delete globalThis.__t12Fabric;
const { createTimelinePreview } = await import('../../resources/js/Studio/timeline-preview.js');
const binding = { dataset: { id: 1, revision: 1, sha256: 'a'.repeat(64) }, category_column: 'c', series_columns: ['v'] };
const chart = title => ({ binding, style: { title } });
const handle = prepareBarChart(binding, { ...binding.dataset, table: { columns: [{ id: 'c', label: 'C' }, { id: 'v', label: 'V' }], rows: [{ id: 'r', values: ['A', datasetNumber('2.00')] }] } });
const resources = {
    resolve: async () => ({ table: { rows: [null] } }),
    async describe(ref) { const snapshot = await this.resolve(ref); return Object.freeze({ ...ref, bytes: 111, row_count: snapshot.table.rows.length, column_count: 13 }); },
    prepare: async () => handle, dispose() { throw Error('borrowed registry disposed'); },
};
const layer = (title = 'Old') => newElement('chart', { id: 'chart_layer', x: 12, y: 24, width: 400, height: 300, scaleX: 0.8, rotation: 12, chart: chart(title) });
const doc = () => { const value = blankDocument(800, 600); value.pages[0].elements = [layer()]; return value; };

test('renderer owns a FixedLayout semantic group, fitted real text and centered primitives', async () => {
    const group = await createChartFabric(layer(), { chartResources: resources, options: { left: 12, top: 24 } });
    assert.equal(group.studioType, 'chart'); assert.deepEqual(group.studioChart, chart('Old'));
    assert.ok(group.layoutManager instanceof fabric.LayoutManager);
    const text = group.getObjects().find(o => o instanceof Textbox);
    assert.ok(text); assert.equal(text.scaleX, text.scaleY); assert.ok(text.scaleX <= 1);
    assert.equal(group.getObjects()[0].left, -200); assert.equal(group.getObjects()[0].top, -150);
    assert.equal(group.getObjects().find(o => o.id === 'chart_layer:bar:r:v').fill, '#7c3aed');
});
test('vertical y-title renderer centers minus ninety degrees and fits uniformly inside its semantic box', async () => {
    // Production renderer with a controlled measurement seam only. This does
    // not assert native glyph ink, shaping, group layout or CTM composition.
    for (const sample of [
        { width: 800, height: 500, fontSize: 18, title: 'Valores', expectedScale: 1 },
        { width: 160, height: 160, fontSize: 18, title: 'Valores' },
        { width: 800, height: 500, fontSize: 72, title: 'Valores' },
        { width: 160, height: 160, fontSize: 72, title: 'Un título de eje extremadamente largo' },
    ]) {
        const element = { ...layer(), width: sample.width, height: sample.height, chart: { binding, style: { fontSize: sample.fontSize, y_title: sample.title, x_title: 'Categorías' } } };
        const options = { left: 12, top: 24, angle: 37, scaleX: 0.8, scaleY: 1.25 };
        const group = await createChartFabric(element, { chartResources: resources, options });
        const p = group.studioChartScene.primitives.find(p => p.role === 'y-title');
        const text = group.getObjects().find(o => o.id === `${element.id}:${p.id}`);
        assert.equal(text.angle, -90, 'Vertical title angle');
        assert.equal(text.originX, 'center'); assert.equal(text.originY, 'center');
        assert.equal(text.left, p.x + p.width / 2 - sample.width / 2);
        assert.equal(text.top, p.y + p.height / 2 - sample.height / 2);
        assert.equal(text.scaleX, text.scaleY);
        assert.ok(Number.isFinite(text.scaleX) && text.scaleX > 0 && text.scaleX <= 1);
        const expected = Math.min(1, p.height / text.width, p.width / text.height);
        assert.equal(text.scaleX, expected, 'Swapped available fit dimensions');
        if (sample.expectedScale !== undefined) assert.equal(text.scaleX, sample.expectedScale, 'Canonical title keeps requested 18px');
        // A centered -90deg layout envelope is (text.height,text.width).
        assert.ok(text.height * text.scaleX <= p.width + 1e-9);
        assert.ok(text.width * text.scaleY <= p.height + 1e-9);
        for (const [key, value] of Object.entries(options)) assert.equal(group[key], value, `Preserved group option ${key}`);
        const xTitle = group.getObjects().find(o => o.text === 'Categorías');
        assert.equal(xTitle.angle, 0, 'Horizontal axis title unchanged');
    }
});
test('chart serialization never includes derived children and groups preserve chart semantic payload', async () => {
    const object = await elementToFabric(layer(), { chartResources: resources });
    const result = studioObjectToElement(object); assert.deepEqual(result.chart, chart('Old')); assert.equal(result.elements, undefined);
    const groupElement = newElement('group', { elements: [layer()] });
    const group = await elementToFabric(groupElement, { chartResources: resources });
    assert.deepEqual(studioObjectToElement(group).elements[0].chart, chart('Old'));
});
test('addChart resolves before inserting and emits exactly one document change', async () => {
    const d = doc(); d.pages[0].elements = []; let changes = 0, release;
    const adapter = await createStudioCanvas({}, { document: d, chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => new Promise(r => { release = r; }) }, onChange() { changes++; } });
    const pending = adapter.addChart(chart('Added')); await new Promise(r => setImmediate(r));
    assert.equal(adapter.layers().length, 0); assert.equal(changes, 0);
    release(handle); await pending;
    assert.equal(adapter.layers().length, 1); assert.equal(changes, 1); assert.equal(adapter.selection()[0].chart.style.title, 'Added');
    assert.equal(adapter.ungroup(), false); await adapter.destroy();
});
test('out-of-order chart edits preserve newest config, ID transform index and selection', async () => {
    const waiting = []; let defer = false;
    const adapter = await createStudioCanvas({}, { document: doc(), chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => defer ? new Promise(r => waiting.push(r)) : Promise.resolve(handle) } });
    adapter.select('chart_layer'); defer = true;
    const first = adapter.updateSelection({ chart: chart('First') }); const second = adapter.updateSelection({ chart: chart('Second') });
    await new Promise(r => setImmediate(r)); assert.equal(waiting.length, 2);
    waiting[1](handle); await second; waiting[0](handle); await first;
    const [result] = adapter.layers(); assert.equal(result.chart.style.title, 'Second'); assert.equal(result.id, 'chart_layer'); assert.equal(result.x, 12); assert.equal(result.rotation, 12); assert.equal(result.scaleX, 0.8); assert.equal(adapter.selection()[0].id, result.id);
    await adapter.destroy();
});
test('late chart edit cannot resurrect deleted object or steal a changed selection', async () => {
    let release, defer = false;
    const adapter = await createStudioCanvas({}, { document: doc(), chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => defer ? new Promise(r => { release = r; }) : Promise.resolve(handle) } });
    adapter.select('chart_layer'); defer = true;
    const pending = adapter.updateSelection({ chart: chart('Late') }); await new Promise(r => setImmediate(r));
    adapter.delete(); release(handle); await pending; assert.equal(adapter.layers().length, 0); assert.equal(adapter.selection().length, 0);
    await adapter.destroy();
});
test('font waits use chart style and registry actual weight/style, including nested chart', async () => {
    const old = globalThis.document, declarations = [];
    globalThis.document = { fonts: { load: async d => declarations.push(d), ready: Promise.resolve() } };
    try {
        await waitForStudioFonts([newElement('group', { elements: [layer()] })], { registry: { ensure: async () => {}, family: () => 'StudioFont_7', style: () => ({ fontWeight: 700, fontStyle: 'italic' }) } });
        assert.deepEqual(declarations, ['italic 700 16px "StudioFont_7"']);
    } finally { if (old === undefined) delete globalThis.document; else globalThis.document = old; }
});
test('static chart render borrows resources and releases only its own scene', async () => {
    const old = globalThis.document; globalThis.document = { createElement: () => ({}) };
    try { const d = doc(); const scene = await renderStudioPage(d, d.pages[0].id, [], [], { chartResources: resources }); assert.equal(scene.canvas.getObjects()[0].studioType, 'chart'); await scene.dispose(); }
    finally { if (old === undefined) delete globalThis.document; else globalThis.document = old; }
});
test('failed resolution leaves previous config and canvas untouched', async () => {
    let fail = false; const changes = [];
    const adapter = await createStudioCanvas({}, { document: doc(), chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: async () => { if (fail) throw Error('private revision missing'); return handle; } }, onChange: (_, m) => changes.push(m.reason) });
    adapter.select('chart_layer'); fail = true;
    await assert.rejects(adapter.updateSelection({ chart: chart('Broken') }), /private revision missing/);
    assert.equal(adapter.layers()[0].chart.style.title, 'Old'); assert.deepEqual(changes, []);
    await adapter.destroy();
});
test('changing selection while chart resolves does not steal user focus', async () => {
    let defer = false, release;
    const d = doc(); d.pages[0].elements.push(newElement('rect', { id: 'other' }));
    const adapter = await createStudioCanvas({}, { document: d, chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => defer ? new Promise(r => { release = r; }) : Promise.resolve(handle) } });
    adapter.select('chart_layer'); defer = true;
    const pending = adapter.updateSelection({ chart: chart('Updated') }); await new Promise(r => setImmediate(r));
    adapter.select('other'); release(handle); await pending;
    assert.equal(adapter.selection()[0].id, 'other'); assert.equal(adapter.layers()[0].chart.style.title, 'Updated'); await adapter.destroy();
});
test('new page generation invalidates pending chart edit and releases derived objects', async () => {
    let defer = false, release;
    const adapter = await createStudioCanvas({}, { document: doc(), chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => defer ? new Promise(r => { release = r; }) : Promise.resolve(handle) } });
    adapter.select('chart_layer'); defer = true;
    const pending = adapter.updateSelection({ chart: chart('Late') }); await new Promise(r => setImmediate(r));
    const empty = blankDocument(800, 600); await adapter.loadDocument(empty); release(handle); await pending;
    assert.equal(adapter.layers().length, 0); await adapter.destroy();
});
test('static render honors caller abort before resources and publishes no obsolete scene', async () => {
    const c = new AbortController(); c.abort(); let calls = 0;
    const old = globalThis.document; globalThis.document = { createElement: () => ({}) };
    try {
        const d = doc();
        await assert.rejects(renderStudioPage(d, d.pages[0].id, [], [], { signal: c.signal, chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: async () => { calls++; return handle; } } }), { name: 'AbortError' });
        assert.equal(calls, 0);
    } finally { if (old === undefined) delete globalThis.document; else globalThis.document = old; }
});
test('canvas preflights aggregate page and document budgets before publishing charts', async () => {
    const large = { binding: { ...binding, series_columns: Array.from({ length: 12 }, (_, i) => `s${i}`) }, options: { kind: 'column-grouped' } };
    const bigLayer = id => newElement('chart', { id, chart: large });
    const registry = { ...resources, resolve: async () => ({ table: { rows: Array(1000) } }) };
    const d = doc(); d.pages[0].elements = [bigLayer('first'), bigLayer('second')];
    await assert.rejects(createStudioCanvas({}, { document: d, chartResources: registry }), /page.*budget/i);
    d.pages[0].elements.pop();
    const adapter = await createStudioCanvas({}, { document: d, chartResources: registry });
    await assert.rejects(adapter.addChart(large), /page.*budget/i);
    assert.equal(adapter.layers().length, 1); await adapter.destroy();
});
test('addChart validates the resulting document layer limit atomically', async () => {
    const d = doc(); d.pages[0].elements = Array.from({ length: 500 }, (_, i) => newElement('rect', { id: `rect_${i}` }));
    const adapter = await createStudioCanvas({}, { document: d, chartResources: resources });
    await assert.rejects(adapter.addChart(chart('Too many')), /capas|element|500/i);
    assert.equal(adapter.layers().length, 500); await adapter.destroy();
});
test('a transform committed while chart data resolves survives reconstruction', async () => {
    let defer = false, release;
    const adapter = await createStudioCanvas({}, { document: doc(), chartResources: { describe: resources.describe, resolve: resources.resolve, prepare: () => defer ? new Promise(r => { release = r; }) : Promise.resolve(handle) } });
    adapter.select('chart_layer'); defer = true;
    const pending = adapter.updateSelection({ chart: chart('After transform') }); await new Promise(r => setImmediate(r));
    adapter.flip('x'); assert.equal(adapter.layers()[0].flipX, true);
    release(handle); await pending;
    assert.equal(adapter.layers()[0].flipX, true); assert.equal(adapter.layers()[0].chart.style.title, 'After transform'); await adapter.destroy();
});
test('timeline preflights complete document once before creating any output surface', async () => {
    const d = doc(); d.schema_version = 2; d.timeline = { fps: 30, audio_tracks: [] };
    const large = { binding: { ...binding, series_columns: Array.from({ length: 12 }, (_, i) => `s${i}`) }, options: { kind: 'column-grouped' } };
    d.pages = Array.from({ length: 8 }, (_, i) => ({ ...d.pages[0], id: `page_${i}`, elements: [newElement('chart', { id: `chart_${i}`, chart: large })] }));
    await assert.rejects(createTimelinePreview({ getContext() { throw Error('surface created before budget'); } }, { document: d, chartResources: { ...resources, resolve: async () => ({ table: { rows: Array(1000) } }) } }), /document.*budget/i);
});
test('static v1 render resolves only the drawn page and leaves hidden offline references untouched', async () => {
    const d = doc();
    const hiddenChart = { binding: { ...binding, dataset: { ...binding.dataset, id: 2 } } };
    d.pages.push({ ...d.pages[0], id: 'hidden_page', elements: [newElement('chart', { id: 'hidden_chart', chart: hiddenChart })] });
    const before = JSON.stringify(d), resolved = [], prepared = [];
    const offline = {
        describe: resources.describe,
        resolve: async ref => { resolved.push(ref.id); if (ref.id !== 1) throw Error('Hidden B has no offline descriptor'); return { table: { rows: [null] } }; },
        prepare: async binding => { prepared.push(binding.dataset.id); if (binding.dataset.id !== 1) throw Error('Hidden B prepared'); return handle; },
    };
    const old = globalThis.document; globalThis.document = { createElement: () => ({}) };
    try {
        const scene = await renderStudioPage(d, d.pages[0].id, [], [], { chartResources: offline });
        assert.deepEqual(resolved, [1]); assert.deepEqual(prepared, [1]);
        assert.equal(scene.canvas.getObjects().length, 1); assert.equal(JSON.stringify(d), before);
        await scene.dispose();
    } finally { if (old === undefined) delete globalThis.document; else globalThis.document = old; }
});
test('static v2 already sampled single-page view remains unchanged by page preflight', async () => {
    const d = doc(); d.schema_version = 2; d.timeline = { fps: 30, audio_tracks: [] };
    const before = JSON.stringify(d), resolved = [];
    const old = globalThis.document; globalThis.document = { createElement: () => ({}) };
    try {
        const scene = await renderStudioPage(d, d.pages[0].id, [], [], { chartResources: { ...resources, resolve: async ref => { resolved.push(ref.id); return resources.resolve(ref); } } });
        assert.deepEqual(resolved, [1]); assert.equal(JSON.stringify(d), before); await scene.dispose();
    } finally { if (old === undefined) delete globalThis.document; else globalThis.document = old; }
});
test('B1 concurrent chart inserts publish only one when their combined bound exceeds page quota', async () => {
    const largeBinding = { ...binding, series_columns: Array.from({ length: 12 }, (_, i) => `s${i}`) };
    const snapshot = { ...binding.dataset, table: { columns: [{ id: 'c', label: 'C' }, ...largeBinding.series_columns.map(id => ({ id, label: id }))], rows: Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, values: [`C${i}`, ...Array.from({ length: 12 }, () => datasetNumber('1'))] })) } };
    const bigHandle = prepareBarChart(largeBinding, snapshot), large = { binding: largeBinding, options: { kind: 'column-grouped' } };
    const d = doc(); d.pages[0].elements = [];
    const adapter = await createStudioCanvas({}, { document: d, chartResources: { describe: resources.describe, resolve: async () => snapshot, prepare: async () => bigHandle } });
    try {
        const results = await Promise.allSettled([adapter.addChart(large), adapter.addChart(large)]);
        assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
        assert.match(results.find(r => r.status === 'rejected').reason.message, /page.*budget/i);
        assert.equal(adapter.layers().length, 1);
    } finally { await adapter.destroy(); }
});
test('B1 concurrent additions at 499 layers cannot publish layer 501', async () => {
    const d = doc(); d.pages[0].elements = Array.from({ length: 499 }, (_, i) => newElement('rect', { id: `r_${i}` }));
    const adapter = await createStudioCanvas({}, { document: d, chartResources: resources });
    try {
        const results = await Promise.allSettled([adapter.addChart(chart('A')), adapter.addChart(chart('B'))]);
        assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
        assert.equal(adapter.layers().length, 500);
    } finally { await adapter.destroy(); }
});
test('B3 mixed chart and video common edit preserves the playing visible source and destroy cleanup', async () => {
    class VideoSurface extends EventTarget {
        duration = 5; currentTime = 0; videoWidth = 400; videoHeight = 300; paused = true; ended = false; plays = 0; cleared = 0; src = '';
        load() { if (this.src) this.dispatchEvent(new Event('loadeddata')); }
        async play() { this.paused = false; this.plays++; }
        pause() { this.paused = true; }
        removeAttribute(name) { if (name === 'src') { this.src = ''; this.cleared++; } }
    }
    const oldDocument = globalThis.document, oldRequest = globalThis.requestAnimationFrame, oldCancel = globalThis.cancelAnimationFrame, created = [];
    globalThis.document = { createElement(type) { assert.equal(type, 'video'); const video = new VideoSurface(); created.push(video); return video; } };
    globalThis.requestAnimationFrame = () => 1; globalThis.cancelAnimationFrame = () => {};
    let adapter;
    try {
        const d = doc(); d.pages[0].elements.push(newElement('video', { id: 'video_layer', asset_id: 7, width: 400, height: 300 }));
        let failChart = false;
        adapter = await createStudioCanvas({}, { document: d, assets: [{ id: 7, kind: 'video', status: 'ready', studio_url: '/video/7' }], chartResources: { ...resources, prepare: async () => { if (failChart) throw Error('chart revision unavailable'); return handle; } } });
        const original = adapter.canvas.getObjects().find(o => o.studioId === 'video_layer');
        adapter.select(['chart_layer', 'video_layer']); await adapter.updateSelection({ opacity: 0.4 });
        const visible = adapter.canvas.getObjects().find(o => o.studioId === 'video_layer');
        assert.equal(visible, original); assert.equal(created.length, 1); assert.equal(visible.opacity, 0.4);
        await adapter.play(); assert.equal(visible.studioVideo.plays, 1); assert.equal(visible.studioVideo.paused, false);
        failChart = true;
        await assert.rejects(adapter.updateSelection({ opacity: 0.2 }), /chart revision unavailable/);
        assert.equal(adapter.canvas.getObjects().find(o => o.studioId === 'video_layer'), original);
        assert.equal(visible.opacity, 0.4); assert.notEqual(visible.studioVideo.src, ''); assert.equal(created.length, 1);
        await adapter.destroy(); adapter = null;
        assert.ok(created.every(video => video.src === '' && video.paused && video.cleared === 1));
    } finally {
        if (adapter) await adapter.destroy();
        if (oldDocument === undefined) delete globalThis.document; else globalThis.document = oldDocument;
        if (oldRequest === undefined) delete globalThis.requestAnimationFrame; else globalThis.requestAnimationFrame = oldRequest;
        if (oldCancel === undefined) delete globalThis.cancelAnimationFrame; else globalThis.cancelAnimationFrame = oldCancel;
    }
});
test('B1 shared commit continuation also fences concurrent ordinary text shape and paste additions', async () => {
    for (const kind of ['shape', 'text', 'paste']) {
        const d = doc(); d.pages[0].elements = Array.from({ length: 499 }, (_, i) => newElement('rect', { id: `r_${i}` }));
        const adapter = await createStudioCanvas({}, { document: d, chartResources: resources });
        const add = () => kind === 'shape' ? adapter.addShape('rect') : kind === 'text' ? adapter.addText('A') : adapter.paste([newElement('rect')]);
        try {
            const results = await Promise.allSettled([add(), add()]);
            assert.equal(results.filter(r => r.status === 'fulfilled').length, 1, kind);
            assert.equal(adapter.layers().length, 500, kind);
        } finally { await adapter.destroy(); }
    }
});
