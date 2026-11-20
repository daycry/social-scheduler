import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseJs } from '@babel/parser';
import * as vue from 'vue';
import * as documentCore from '../../resources/js/Studio/document.js';
import { useStudioHistory } from '../../resources/js/Studio/composables/useStudioHistory.js';
import { compileTimeline } from '../../resources/js/Studio/timeline.js';
import { useTimelinePlayback } from '../../resources/js/Studio/composables/useTimelinePlayback.js';
import { useTimelineEditing } from '../../resources/js/Studio/composables/useTimelineEditing.js';
import { useStudioSave } from '../../resources/js/Studio/composables/useStudioSave.js';
import { useEditorDesignOperations } from '../../resources/js/Studio/composables/useEditorDesignOperations.js';
import { usePresentation } from '../../resources/js/Studio/composables/usePresentation.js';
import { useChartFlow } from '../../resources/js/Studio/composables/useChartFlow.js';
import { useEditorDialogs } from '../../resources/js/Studio/composables/useEditorDialogs.js';
import { useEditorLibrary } from '../../resources/js/Studio/composables/useEditorLibrary.js';
import { useEditorCanvas } from '../../resources/js/Studio/composables/useEditorCanvas.js';

// Execute the actual compiled Editor script/template. Only the asynchronous canvas
// construction and browser lifecycle boundary are controlled; this is not DOM QA.
const source = readFileSync(new URL('../../resources/js/Pages/Studio/Editor.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'editor-readiness-regression' });
const template = compileTemplate({ source: descriptor.template.content, filename: 'Editor.vue', id: 'editor-readiness-regression', compilerOptions: { bindingMetadata: script.bindings } });
assert.deepEqual(template.errors, []);

function withoutImports(code) {
    const imports = parseJs(code, { sourceType: 'module' }).program.body.filter(node => node.type === 'ImportDeclaration');
    const names = imports.flatMap(node => node.specifiers.map(specifier => specifier.local.name));
    for (const node of imports.reverse()) code = code.slice(0, node.start) + code.slice(node.end);
    return { code, names };
}

async function mountPendingEditor({ compile = compileTimeline, request, player, media = {} } = {}) {
    const mounted = [], unmounted = [], selections = [], loaded = [], released = [];
    const listeners = new Map();
    let resolveCanvas, canvasOptions;
    const canvasPromise = new Promise(resolve => { resolveCanvas = resolve; });
    const dependencies = { ...vue, ...documentCore, useStudioHistory, useStudioSave, useTimelineEditing, useEditorDialogs, useEditorLibrary,
        usePresentation, useEditorDesignOperations, studioRequest: request,
        useTimelinePlayback: options => useTimelinePlayback(options, {
            compile, createPreview: player?.create,
            now: player?.performance?.now,
            requestFrame: player?.requestAnimationFrame,
            cancelFrame: player?.cancelAnimationFrame,
        }),
        onMounted: callback => mounted.push(callback), onBeforeUnmount: callback => unmounted.push(callback),
        router: { on: (name, callback) => { listeners.set(name, callback); return () => listeners.delete(name); } },
        useChartFlow: options => useChartFlow(options, {
            createResources: ({ brandId }) => ({ dispose() { released.push(brandId); } }),
        }),
        useEditorCanvas: options => useEditorCanvas(options, {
            createCanvas: async (_element, options) => { canvasOptions = options; return canvasPromise; },
            createResizeObserver: () => ({ observe() {}, disconnect() {} }),
        }),
    };
    const compiled = withoutImports(script.content);
    const values = compiled.names.map(name => dependencies[name] ?? (name[0] === name[0].toUpperCase() ? { name } : () => { throw new Error(`Unexpected dependency ${name}`); }));
    const window = { addEventListener(name, callback) { listeners.set(name, callback); }, removeEventListener(name) { listeners.delete(name); } };
    const component = new Function(...compiled.names, 'window', 'route', compiled.code.replace('export default', 'return'))(...values, window, (name, id) => `/fixture/${name}/${id}`);
    const document = documentCore.blankDocument(1080, 1080);
    document.pages[0].elements = [documentCore.newElement('chart', { id: 'historic_chart', chart: {
        binding: { dataset: { id: 4, revision: 1, sha256: 'a'.repeat(64) }, category_column: 'category', series_columns: ['s0'] }, options: { kind: 'column-grouped' },
    } })];
    const props = vue.reactive({ design: { id: 1, name: 'Readiness fixture', document, revision: 4, is_template: false }, brand: { id: 1, name: 'Fictitious brand' }, kit: { palette: [], fonts: [] }, assets: [], fonts: [], folders: [], exports: [],
        access: { role: 'owner', role_label: 'Propietario', is_owner: true, can_edit: true, can_comment: true, owner_name: '' } });
    const scope = vue.effectScope();
    const state = scope.run(() => component.setup(props, { expose() {} }));
    const compiledTemplate = withoutImports(template.code);
    const templateDependencies = compiledTemplate.names.map(name => name === '_resolveComponent' ? value => ({ name: value }) : name === '_withDirectives' ? node => node : vue[name.replace(/^_/, '')]);
    const render = new Function(...compiledTemplate.names, compiledTemplate.code.replace('export function render', 'return function render'))(...templateDependencies);
    const context = new Proxy(vue.proxyRefs(state), { get(target, key) { return key === 'route' ? () => '/fixture' : Reflect.get(target, key); } });
    function view() {
        let grid, panel, layout;
        function walk(node) {
            if (!node || typeof node !== 'object') return;
            if (typeof node.props?.class === 'string' && node.props.class.includes('190px_minmax')) grid = node;
            if (node.type?.name === 'LayerPanel') panel = node;
            if (node.type?.name === 'AuthenticatedLayout') layout = node;
            if (Array.isArray(node.children)) node.children.forEach(walk);
            else if (typeof node.children?.default === 'function') node.children.default().forEach(walk);
        }
        walk(render(context, [], props, vue.proxyRefs(state), {}, {}));
        assert.ok(grid, 'Actual compiled editing grid must exist');
        assert.ok(panel, 'Actual compiled layer panel must exist');
        return { grid, panel, layout };
    }
    const initializing = mounted[0]();
    await vue.nextTick(); await Promise.resolve();
    assert.ok(canvasOptions, 'Initialization must have reached the deferred real factory boundary');
    const adapter = { canvas: { on() {} }, layers: () => document.pages[0].elements, zoom() {}, destroy: async () => {}, ...media,
        async loadDocument(next, pageId) { loaded.push({ next, pageId }); },
        select(ids) { selections.push(ids); const selected = new Set(Array.isArray(ids) ? ids : [ids]); canvasOptions.onSelection(document.pages[0].elements.filter(item => selected.has(item.id))); },
    };
    async function complete() { resolveCanvas(adapter); await initializing; }
    function detach() {
        unmounted.splice(0).forEach(callback => callback());
        scope.stop();
    }
    return { state, props, view, complete, selections, loaded, listeners, released, detach, async close() { await complete(); detach(); } };
}

test('saved layers remain inaccessible until the canvas adapter is ready, then the same chart is selectable', async () => {
    const editor = await mountPendingEditor();
    try {
        const before = JSON.stringify(editor.state.documentData.value);
        assert.equal(editor.state.ready.value, false);
        const pending = editor.view();
        assert.deepEqual(pending.panel.props.layers.map(item => item.id), ['historic_chart']);
        assert.equal(pending.grid.props.inert, true, 'Pending editor must block pointer and keyboard interaction with saved layers');
        assert.deepEqual(editor.selections, []);
        await editor.complete();
        assert.equal(editor.state.ready.value, true);
        const ready = editor.view();
        assert.notEqual(ready.grid.props.inert, true, 'Initialized editor must allow layer selection');
        ready.panel.props.onSelect('historic_chart'); await Promise.resolve();
        assert.deepEqual(editor.selections, [['historic_chart']]);
        assert.deepEqual(editor.state.selection.value.map(item => item.id), ['historic_chart']);
        assert.equal(editor.state.error.value, '');
        assert.equal(JSON.stringify(editor.state.documentData.value), before);
    } finally { await editor.close(); }
});

for (const busy of ['automationBusy', 'fontBusy']) {
    test(`initialized editor preserves its ${busy} interaction block`, async () => {
        const editor = await mountPendingEditor();
        try {
            await editor.complete();
            assert.notEqual(editor.view().grid.props.inert, true);
            editor.state[busy].value = true;
            assert.equal(editor.view().grid.props.inert, true);
            editor.state[busy].value = false;
            assert.notEqual(editor.view().grid.props.inert, true);
            assert.deepEqual(editor.selections, []);
        } finally { await editor.close(); }
    });
}

test('the editor compiles one timeline plan per change and reuses it for duration and page start', async () => {
    let compilations = 0;
    const editor = await mountPendingEditor({ compile: document => {
        compilations++;
        return compileTimeline(document);
    } });
    try {
        const doc = documentCore.blankDocument(1080, 1080);
        doc.pages.push({ ...structuredClone(doc.pages[0]), id: 'second', duration: 2 });
        editor.state.documentData.value = documentCore.validateDocument({
            ...doc, schema_version: 2, timeline: { fps: 30, audio_tracks: [] },
        });
        assert.equal(editor.state.timelineDuration.value, 7);
        assert.equal(editor.state.timelinePageStart.value, null, 'the stale page has no start in the new plan');
        editor.state.pageId.value = doc.pages[0].id;
        assert.equal(editor.state.timelinePageStart.value, 0);
        assert.equal(compilations, 1);
        editor.state.pageId.value = 'second';
        assert.equal(editor.state.timelinePageStart.value, 5);
        assert.equal(compilations, 1);
        editor.state.documentData.value.pages[0].duration = 4;
        assert.equal(editor.state.timelinePageStart.value, 4);
        assert.equal(editor.state.timelineDuration.value, 6);
        assert.equal(compilations, 2);
    } finally { await editor.close(); }
});

test('the editor still detects a video inside nested groups', async () => {
    const editor = await mountPendingEditor();
    try {
        const { state } = editor;
        assert.equal(state.hasVideo.value, false);
        const video = documentCore.newElement('video', { asset_id: 19 });
        state.documentData.value.pages[0].elements.push(documentCore.newElement('group', {
            elements: [documentCore.newElement('group', { elements: [video] })],
        }));
        assert.equal(state.hasVideo.value, true);
        state.documentData.value.pages[0].elements.pop();
        assert.equal(state.hasVideo.value, false);
        state.documentData.value.pages[0].elements.push({ id: 'legacy_group', type: 'group' });
        assert.equal(state.hasVideo.value, false);
    } finally { await editor.close(); }
});


test('a manual editor save waits for an in-flight autosave and still forces a revision', async () => {
    const writes = [];
    let acknowledge;
    const inFlight = new Promise(resolve => { acknowledge = resolve; });
    const editor = await mountPendingEditor({ request(method, url, payload) {
        assert.equal(method, 'PATCH');
        assert.equal(url, '/fixture/studio.designs.update/1');
        writes.push(payload);
        return payload.autosave ? inFlight : Promise.resolve({ design: { revision: 6, template_fields: ['Title'] } });
    } });

    try {
        const { state } = editor;
        state.name.value = 'Cambios en curso';
        state.commit();
        const automatic = state.saveNow(true);
        const manual = state.saveNow();
        assert.equal(writes.length, 1);
        assert.equal(writes[0].revision, 4);
        assert.equal(state.status.value, 'Guardando…');
        acknowledge({ design: { revision: 5, template_fields: [] } });
        assert.deepEqual(await Promise.all([automatic, manual]), [true, true]);
        assert.deepEqual(writes.map(({ autosave, revision, name }) => ({ autosave, revision, name })), [
            { autosave: true, revision: 4, name: 'Cambios en curso' },
            { autosave: false, revision: 5, name: 'Cambios en curso' },
        ]);
        assert.equal(state.revision.value, 6);
        assert.deepEqual(state.templateFields.value, ['Title']);
        assert.equal(state.status.value, 'Guardado');
        assert.equal(state.dirty.value, false);
    } finally { acknowledge({ design: { revision: 5 } }); await editor.close(); }
});

test('an editor revision conflict preserves the unsaved name and suppresses retries until recovery', async () => {
    let requests = 0;
    const editor = await mountPendingEditor({ request: async () => {
        requests++;
        throw Object.assign(new Error('El diseño cambió en otra sesión.'), { status: 409 });
    } });

    try {
        const { state } = editor;
        state.name.value = 'Borrador que hay que conservar';
        state.commit();
        assert.equal(await state.saveNow(), false);
        assert.equal(state.status.value, 'Conflicto de revisión');
        assert.equal(state.revision.value, 4);
        assert.equal(state.name.value, 'Borrador que hay que conservar');
        assert.equal(state.dirty.value, true);
        assert.equal(state.conflict.value, true);
        assert.equal(state.error.value, 'El diseño cambió en otra sesión.');
        assert.equal(await state.saveNow(), false);
        assert.equal(requests, 1);
    } finally { await editor.close(); }
});


test('editor undo restores metadata and selects a surviving page when the edited page disappears', async () => {
    const editor = await mountPendingEditor();
    try {
        await editor.complete();
        const { state } = editor;
        const initialPage = state.pageId.value;
        const initialName = state.name.value;
        const initialKit = state.kitRevision.value;
        state.name.value = 'Campaña revisada';
        state.template.value = true;
        state.kitRevision.value = 8;
        state.documentData.value.pages.push({ ...state.documentData.value.pages[0], id: 'page-to-undo', elements: [] });
        state.pageId.value = 'page-to-undo';
        state.commit();
        assert.equal(state.canUndo.value, true);
        await state.restoreHistory('undo');
        assert.equal(state.name.value, initialName);
        assert.equal(state.template.value, false);
        assert.equal(state.kitRevision.value, initialKit);
        assert.equal(state.pageId.value, initialPage);
        assert.equal(editor.loaded.at(-1).pageId, initialPage);
        assert.equal(state.canRedo.value, true);
        await state.restoreHistory('redo');
        assert.equal(state.name.value, 'Campaña revisada');
        assert.equal(state.template.value, true);
        assert.equal(state.kitRevision.value, 8);
        assert.equal(state.documentData.value.pages[1].id, 'page-to-undo');
        assert.equal(state.dirty.value, true);
    } finally { await editor.close(); }
});

test('the editor requests fullBleed from the authenticated layout', async () => {
    const editor = await mountPendingEditor();
    try {
        const layout = editor.view().layout;
        assert.ok(layout, 'the actual editor template uses AuthenticatedLayout');
        assert.ok(
            Object.hasOwn(layout.props ?? {}, 'full-bleed') || Object.hasOwn(layout.props ?? {}, 'fullBleed'),
            'the editor must request fullBleed on AuthenticatedLayout',
        );
    } finally { await editor.close(); }
});

async function mountTimelineEditor(player) {
    const editor = await mountPendingEditor({ player });
    await editor.complete();
    const document = documentCore.blankDocument(1080, 1080);
    editor.state.documentData.value = documentCore.validateDocument({ ...document, schema_version: 2, timeline: { fps: 30, audio_tracks: [] } });
    editor.state.pageId.value = editor.state.documentData.value.pages[0].id;
    editor.state.timelineOpen.value = true;
    return editor;
}

test('closing the timeline during its first seek leaves playback and preview stopped', async () => {
    const firstSeek = Promise.withResolvers();
    const enteredSeek = Promise.withResolvers();
    let frames = 0;
    let pauses = 0;
    const player = {
        create: async () => ({
            seek: () => { enteredSeek.resolve(); return firstSeek.promise; },
            pause: () => { pauses++; },
            dispose: async () => {},
        }),
        requestAnimationFrame: () => { frames++; return frames; },
        cancelAnimationFrame: () => {},
    };
    const editor = await mountTimelineEditor(player);
    try {
        const playing = editor.state.playTimeline();
        await enteredSeek.promise;
        await editor.state.openTimeline();
        firstSeek.resolve();
        await playing;
        assert.equal(editor.state.timelineOpen.value, false);
        assert.equal(editor.state.previewVisible.value, false);
        assert.equal(editor.state.playing.value, false);
        assert.equal(editor.state.previewBusy.value, false);
        assert.equal(frames, 0);
        assert.equal(pauses, 1);
    } finally {
        firstSeek.resolve();
        await editor.close();
    }
});

test('timeline playback subtracts buffering from elapsed time and cancels its scheduled frame', async () => {
    const frames = new Map();
    let time = 0;
    let nextFrame = 1;
    let pauses = 0;
    const seeks = [];
    const player = {
        performance: { now: () => time },
        create: async () => ({
            seek: async (position, options) => {
                seeks.push({ position, options });
                return { bufferingMs: seeks.length === 2 ? 500 : 0 };
            },
            pause: () => { pauses++; },
            dispose: async () => {},
        }),
        requestAnimationFrame: callback => { const id = nextFrame++; frames.set(id, callback); return id; },
        cancelAnimationFrame: id => { frames.delete(id); },
    };
    const editor = await mountTimelineEditor(player);
    try {
        await editor.state.playTimeline();
        assert.equal(editor.state.playing.value, true);
        assert.equal(seeks.length, 2);
        const first = frames.get(1);
        assert.equal(typeof first, 'function');
        frames.delete(1);
        time = 1500;
        await first();

        assert.equal(editor.state.timeline.value, 1);
        assert.deepEqual(seeks.at(-1), { position: 1, options: { play: true } });
        assert.deepEqual([...frames.keys()], [2]);

        await editor.state.playTimeline();
        assert.equal(editor.state.playing.value, false);
        assert.deepEqual([...frames.keys()], []);
        assert.equal(pauses, 1);
    } finally { await editor.close(); }
});

test('editor unmount prevents in-flight autosave completion from sending newer edits', async () => {
    const requests = [];
    let acknowledge;
    const response = new Promise(resolve => { acknowledge = resolve; });
    const editor = await mountPendingEditor({ request(method, url, payload) {
        requests.push({ method, url, payload });
        return response;
    } });
    editor.state.name.value = 'Primera edición';
    editor.state.commit();
    const pending = editor.state.saveNow(true);
    assert.equal(requests.length, 1);
    editor.state.name.value = 'Segunda edición';
    editor.state.commit();

    await editor.close();
    acknowledge({ design: { revision: 5 } });
    assert.equal(await pending, false);
    assert.equal(requests.length, 1);
    assert.equal(editor.state.revision.value, 4);
});


test('an editor detached while its canvas loads releases all navigation and keyboard listeners', async () => {
    const editor = await mountPendingEditor();
    editor.detach();
    await editor.complete();
    assert.equal(editor.state.ready.value, false);
    assert.equal(editor.listeners.size, 0);
});


test('changing editor brand context closes chart dialogs and disposes that brand registry', async () => {
    const editor = await mountPendingEditor();
    try {
        await editor.complete();
        assert.ok(editor.state.chartResources.value);
        editor.state.datasetsOpen.value = true;
        editor.state.chartsOpen.value = true;
        editor.props.brand = { id: 2, name: 'Otra marca' };
        await vue.nextTick();
        assert.equal(editor.state.datasetsOpen.value, false);
        assert.equal(editor.state.chartsOpen.value, false);
        assert.equal(editor.state.ready.value, false);
        await new Promise(setImmediate);
        assert.equal(editor.state.chartResources.value, null);
        assert.deepEqual(editor.released, [1]);
    } finally { await editor.close(); }
});


test('cancelled presentation cannot restart deferred canvas playback or save after it is closed', async () => {
    let release;
    const paused = [];
    const requests = [];
    const editor = await mountPendingEditor({
        media: {
            play: () => new Promise(resolve => { release = resolve; }),
            pause: () => paused.push('pause'),
        },
        request: (...args) => { requests.push(args); },
    });
    try {
        await editor.complete();
        const first = editor.state.togglePlayback();
        const repeated = editor.state.togglePlayback();
        const presentation = editor.state.openPresentation();
        assert.equal(editor.state.presentationOpening.value, true);
        editor.state.closePresentation();
        release();
        await Promise.all([first, repeated, presentation]);
        assert.equal(editor.state.playing.value, false);
        assert.equal(editor.state.presentationOpen.value, false);
        assert.deepEqual(requests, []);
        assert.ok(paused.length >= 2);
    } finally { await editor.close(); }
});


test('a canvas created after a brand switch is destroyed without restoring access to the old editor', async () => {
    let destroyed = 0;
    const editor = await mountPendingEditor({ media: { destroy: async () => { destroyed++; } } });
    try {
        editor.props.brand = { id: 2, name: 'Otra marca' };
        await vue.nextTick();
        await editor.complete();
        assert.equal(editor.state.ready.value, false);
        await new Promise(setImmediate);
        assert.deepEqual(editor.released, [1]);
        assert.equal(destroyed, 1);
    } finally { await editor.close(); }
});


test('a reused page never sends edits or copies to the design that replaced its initial context', async () => {
    const requests = [];
    const editor = await mountPendingEditor({ request: (...args) => { requests.push(args); } });
    try {
        await editor.complete();
        editor.state.name.value = 'Cambios de la marca anterior';
        editor.state.markDirty();
        editor.props.design = { ...editor.props.design, id: 77 };
        editor.props.brand = { id: 2, name: 'Otra marca' };
        const intercepted = [];
        editor.listeners.get('keydown')({ key: 's', ctrlKey: true, preventDefault: () => intercepted.push('key') });
        editor.listeners.get('before')({ detail: { visit: { method: 'get', url: '/studio' } }, preventDefault: () => intercepted.push('navigation') });
        assert.deepEqual(intercepted, []);
        assert.equal(await editor.state.saveNow(), false);
        await editor.state.saveAsCopy();
        assert.deepEqual(requests, []);
        assert.match(editor.state.error.value, /contexto del diseño cambió/);
    } finally { await editor.close(); }
});


test('chart resources stay live until asynchronous canvas destruction finishes', async () => {
    let finishDestroy;
    const destruction = new Promise(resolve => { finishDestroy = resolve; });
    const editor = await mountPendingEditor({ media: { destroy: () => destruction } });
    try {
        await editor.complete();
        editor.props.brand = { id: 2, name: 'Otra marca' };
        await new Promise(setImmediate);
        assert.deepEqual(editor.released, []);
        finishDestroy();
        await new Promise(setImmediate);
        assert.deepEqual(editor.released, [1]);
    } finally {
        finishDestroy();
        await editor.close();
    }
});


test('locked chart layers regain the lock when an update fails', async () => {
    const lockUpdates = [];
    const editor = await mountPendingEditor({ media: {
        updateSelection: async () => { throw Error('No se pudo editar'); },
        lock: value => { lockUpdates.push(value); },
    } });
    try {
        await editor.complete();
        editor.state.layers.value[0].locked = true;
        await editor.state.patchLayer('historic_chart', { width: 100 });
        assert.deepEqual(lockUpdates, [true]);
        assert.equal(editor.state.busy.value, false);
        assert.equal(editor.state.error.value, 'No se pudo editar');
    } finally { await editor.close(); }
});


test('a pending save keeps its original route and never resends edits after the context changes', async () => {
    let finish;
    const response = new Promise(resolve => { finish = resolve; });
    const requests = [];
    const editor = await mountPendingEditor({ request: (method, target) => {
        requests.push([method, target]);
        return response;
    } });
    try {
        await editor.complete();
        editor.state.name.value = 'Primera edición';
        editor.state.markDirty();
        const saving = editor.state.saveNow(true);
        editor.state.name.value = 'Segunda edición';
        editor.state.markDirty();
        const queued = editor.state.saveNow();
        editor.props.design = { ...editor.props.design, id: 77 };
        finish({ design: { revision: 5 } });
        assert.deepEqual(await Promise.all([saving, queued]), [false, false]);
        assert.deepEqual(requests, [['PATCH', '/fixture/studio.designs.update/1']]);
        assert.equal(editor.state.revision.value, 4);
        assert.match(editor.state.error.value, /contexto del diseño cambió/);
    } finally { await editor.close(); }
});

test('an error from a disposed canvas keeps the context change message', async () => {
    let reportError;
    const editor = await mountPendingEditor({ media: {
        canvas: { on(event, callback) { if (event === 'studio:error') reportError = callback; } },
    } });
    try {
        await editor.complete();
        reportError({ error: new Error('Active error') });
        assert.equal(editor.state.error.value, 'Active error');
        editor.props.brand = { id: 2, name: 'Otra marca' };
        await vue.nextTick();
        reportError({ error: new Error('Disposed error') });
        assert.match(editor.state.error.value, /El contexto del diseño cambió/);
    } finally { await editor.close(); }
});
