import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseJs } from '@babel/parser';
import * as vue from 'vue';
import * as documentCore from '../../resources/js/Studio/document.js';
import { createHistory } from '../../resources/js/Studio/history.js';

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

async function mountPendingEditor() {
    const mounted = [], unmounted = [], selections = [];
    let resolveCanvas, canvasOptions;
    const canvasPromise = new Promise(resolve => { resolveCanvas = resolve; });
    const dependencies = { ...vue, ...documentCore, createHistory,
        onMounted: callback => mounted.push(callback), onBeforeUnmount: callback => unmounted.push(callback),
        router: { on: () => () => {} }, createChartResources: () => ({ dispose() {} }),
        createStudioCanvas: async (_element, options) => { canvasOptions = options; return canvasPromise; },
    };
    const compiled = withoutImports(script.content);
    const values = compiled.names.map(name => dependencies[name] ?? (name[0] === name[0].toUpperCase() ? { name } : () => { throw new Error(`Unexpected dependency ${name}`); }));
    const window = { addEventListener() {}, removeEventListener() {} };
    class ResizeObserver { observe() {} disconnect() {} }
    const component = new Function(...compiled.names, 'window', 'ResizeObserver', compiled.code.replace('export default', 'return'))(...values, window, ResizeObserver);
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
        let grid, panel;
        function walk(node) {
            if (!node || typeof node !== 'object') return;
            if (typeof node.props?.class === 'string' && node.props.class.includes('190px_minmax')) grid = node;
            if (node.type?.name === 'LayerPanel') panel = node;
            if (Array.isArray(node.children)) node.children.forEach(walk);
            else if (typeof node.children?.default === 'function') node.children.default().forEach(walk);
        }
        walk(render(context, [], props, vue.proxyRefs(state), {}, {}));
        assert.ok(grid, 'Actual compiled editing grid must exist');
        assert.ok(panel, 'Actual compiled layer panel must exist');
        return { grid, panel };
    }
    const initializing = mounted[0]();
    await vue.nextTick(); await Promise.resolve();
    assert.ok(canvasOptions, 'Initialization must have reached the deferred real factory boundary');
    const adapter = { canvas: { on() {} }, layers: () => document.pages[0].elements, zoom() {}, destroy: async () => {},
        select(ids) { selections.push(ids); const selected = new Set(Array.isArray(ids) ? ids : [ids]); canvasOptions.onSelection(document.pages[0].elements.filter(item => selected.has(item.id))); },
    };
    async function complete() { resolveCanvas(adapter); await initializing; }
    return { state, view, complete, selections, async close() { await complete(); unmounted.forEach(callback => callback()); scope.stop(); } };
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
