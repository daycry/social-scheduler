import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { babelParse, parse } from '@vue/compiler-sfc';
import { createRenderer, defineAsyncComponent, getCurrentInstance, h, nextTick, onErrorCaptured, ref, watch } from 'vue';

const source = readFileSync(new URL('../../resources/js/Studio/composables/useEditorDialogs.js', import.meta.url), 'utf8');
const exportUrl = new URL('../../resources/js/Components/Studio/ExportDialog.vue', import.meta.url);
const exportScript = parse(readFileSync(exportUrl, 'utf8')).descriptor.scriptSetup.content;
const exportOptions = babelParse(exportScript, { sourceType: 'module' }).program.body
    .find(node => node.type === 'ExpressionStatement' && node.expression.callee?.name === 'defineOptions');
const exportName = exportOptions?.expression.arguments[0].properties.find(property => property.key.name === 'name')?.value.value;
assert.ok(exportName, 'ExportDialog declares the public name used to scope error capture');

const exportLoader = "const loadExportDialog = () => import('@/Components/Studio/ExportDialog.vue');";
assert.ok(source.includes(exportLoader), 'The harness replaces the dynamic export loader');
const createDialogs = new Function(
    'defineAsyncComponent', 'getCurrentInstance', 'onErrorCaptured', 'ref', 'watch', 'loadExport',
    source
        .replace(/^import .*?;\r?$/gm, '')
        .replace(exportLoader, 'const loadExportDialog = loadExport;')
        .replace('export function useEditorDialogs', 'function useEditorDialogs') + '\nreturn useEditorDialogs;',
);
const renderer = createRenderer({
    createElement: () => ({}),
    insert() {},
    remove() {},
    patchProp() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
});

function mount(t) {
    const error = ref('');
    const panelFailure = ref(false);
    const exportFailure = ref(false);
    const exportChildFailure = ref(false);
    const failures = [];
    function failingChild(name, trigger, children = []) {
        return {
            name,
            setup() {
                watch(trigger, () => { throw new Error(name); });
                return () => h('div', children.map(child => h(child)));
            },
        };
    }
    const sidePanel = failingChild('StudioSidePanel', panelFailure);
    const exportChild = failingChild('NativeExportJobs', exportChildFailure);
    const exportDialog = failingChild(exportName, exportFailure, [exportChild]);
    let dialogs;
    const app = renderer.createApp({
        setup() {
            const useEditorDialogs = createDialogs(defineAsyncComponent, getCurrentInstance, onErrorCaptured, ref, watch, async () => ({}));
            dialogs = useEditorDialogs({
                error, isDisposed: () => false, templateSaveOpen: ref(false),
                variantsOpen: ref(false), datasetsOpen: ref(false), chartsOpen: ref(false),
            });
            return () => h('main', [h(sidePanel), h(exportDialog)]);
        },
    });
    app.config.errorHandler = failure => failures.push(failure.message);
    app.mount({});
    t.after(() => app.unmount());
    return { dialogs, error, failures, panelFailure, exportFailure, exportChildFailure };
}

test('an unrelated editor panel error does not cancel a pending publish', async (t) => {
    const { dialogs, error, failures, panelFailure } = mount(t);
    await dialogs.publishFromEditor();
    assert.equal(dialogs.publishPending.value, true);
    panelFailure.value = true;
    await nextTick();

    assert.deepEqual(failures, ['StudioSidePanel']);
    assert.equal(dialogs.publishPending.value, true);
    assert.equal(error.value, '');

    let publishes = 0;
    dialogs.exporter.value = { publish() { publishes++; } };
    await nextTick();
    assert.equal(publishes, 1);
    assert.equal(dialogs.publishPending.value, false);
});

test('an export dialog initialization error clears the pending publish', async (t) => {
    const { dialogs, error, failures, exportFailure } = mount(t);
    await dialogs.publishFromEditor();
    exportFailure.value = true;
    await nextTick();

    assert.deepEqual(failures, [exportName]);
    assert.equal(dialogs.publishPending.value, false);
    assert.match(error.value, /No se pudo abrir la exportación/);
});

test('a rejected publish is reported after the dialog has mounted', async (t) => {
    const { dialogs, error, failures } = mount(t);
    await dialogs.publishFromEditor();
    dialogs.exporter.value = { async publish() { throw new Error('publish failure'); } };
    await nextTick();

    assert.equal(dialogs.publishPending.value, false);
    assert.match(error.value, /No se pudo iniciar la publicación/);
    assert.deepEqual(failures, []);
});


test('a descendant of the export dialog can fail while the publish is pending', async (t) => {
    const { dialogs, error, failures, exportChildFailure } = mount(t);
    await dialogs.publishFromEditor();
    exportChildFailure.value = true;
    await nextTick();

    assert.deepEqual(failures, ['NativeExportJobs']);
    assert.equal(dialogs.publishPending.value, false);
    assert.match(error.value, /No se pudo abrir la exportación/);
});
