import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { babelParse, compileScript, parse } from '@vue/compiler-sfc';
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue';
import { firstGroupError } from '../../resources/js/lib/form-errors.js';

function mount(t, path, initialProps, extra = {}) {
    const source = readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const result = compileScript(descriptor, { id: path });
    const statements = babelParse(result.content, { sourceType: 'module' }).program.body;
    let script = result.content;
    for (const statement of statements.reverse()) {
        if (statement.type === 'ImportDeclaration') script = script.slice(0, statement.start) + script.slice(statement.end);
        if (statement.type === 'ExportDefaultDeclaration') {
            script = script.slice(0, statement.start) + 'return ' + script.slice(statement.declaration.start);
        }
    }
    const answer = Promise.withResolvers();
    const requests = [];
    const props = reactive(initialProps);
    const dependencies = {
        computed, reactive, ref, watch, onMounted() {}, useForm: data => data, AuthenticatedLayout: {},
        PrimaryButton: {}, SecondaryButton: {}, StatusBadge: {}, CredentialsForm: {}, NetworkIcon: {}, Head: {}, Link: {},
        useId: () => 'fixture-id', focusFirstFormError() {}, focusEditedBlock() {}, createBlockKey: key => key,
        createDateFormatter: () => () => '', firstGroupError,
        useConfirm: () => () => answer.promise, nextTick,
        onTabsKeydown() {}, useTabs: () => ({ tabProps: () => ({}), panelProps: () => ({}) }),
        route: (name, params) => JSON.stringify({ name, params }),
        router: { delete: target => requests.push(target), post: target => requests.push(target), reload() {} },
        usePolling: () => ({ exhausted: ref(false), restart() {} }),
        studioRequest: async (method, target) => { requests.push({ method, target }); return { comments: [] }; },
        newIdempotencyKey: () => 'test-key', workflowError: () => 'Error',
        workflowRequest: async (method, target, body) => { requests.push({ method, target, body }); return { ok: true, data: {} }; },
        ...extra,
    };
    for (const [identifier, imported] of Object.entries(result.imports)) {
        if (!(identifier in dependencies) && imported.source.endsWith('.vue')) dependencies[identifier] = {};
        assert.ok(identifier in dependencies, `Falta inyectar ${identifier} desde ${imported.source} en ${path}`);
    }
    const component = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies));
    const scope = effectScope();
    t.after(() => { answer.resolve(false); scope.stop(); });
    const state = scope.run(() => component.setup(props, { expose() {}, emit() {} }));
    return { answer, props, requests, state };
}

test('cancelling a Studio revocation preserves the previous feedback and leaves the panel idle', async t => {
    const panel = mount(t, 'Components/Studio/StudioCollabPanel.vue', { designId: 8, access: {} });
    panel.state.notice.value = 'Rol actualizado.';
    panel.state.error.value = 'Error anterior.';
    const action = panel.state.revokeLink({ id: 3 });
    const awaiting = { busy: panel.state.busy.value, notice: panel.state.notice.value };
    panel.answer.resolve(false);
    await action;
    assert.deepEqual(awaiting, { busy: false, notice: 'Rol actualizado.' });
    assert.equal(panel.state.notice.value, 'Rol actualizado.');
    assert.equal(panel.state.error.value, 'Error anterior.');
    assert.deepEqual(panel.requests, []);
});

test('a Studio revocation does not run after the design changes during confirmation', async t => {
    const panel = mount(t, 'Components/Studio/StudioCollabPanel.vue', { designId: 8, access: {} });
    const action = panel.state.removeComment({ id: 3 });
    panel.props.designId = 9;
    panel.answer.resolve(true);
    await action;
    assert.deepEqual(panel.requests, []);
});

test('a Studio revocation runs once for the confirmed design and sets its success feedback', async t => {
    const panel = mount(t, 'Components/Studio/StudioCollabPanel.vue', { designId: 8, access: {} });
    const action = panel.state.removeComment({ id: 3 });
    panel.answer.resolve(true);
    await action;
    const deletes = panel.requests.filter(request => request.method === 'DELETE');
    assert.deepEqual(deletes.map(request => JSON.parse(request.target)), [{ name: 'studio.comments.destroy', params: { design: 8, comment: 3 } }]);
    assert.equal(panel.state.notice.value, 'Comentario eliminado.');
});

test('disconnect targets the brand for which the user opened the confirmation', async t => {
    const page = mount(t, 'Pages/Brands/Connections.vue', { brand: { id: 8 } });
    const action = page.state.disconnect({ id: 3, name: 'Cuenta', network_label: 'Instagram' });
    page.props.brand = { id: 9 };
    page.answer.resolve(true);
    await action;
    assert.deepEqual(page.requests.map(value => JSON.parse(value)), [{ name: 'brands.connections.destroy', params: { brand: 8, account: 3 } }]);
});

test('a confirmed batch deletion keeps its selection and omits fields from a later action', async t => {
    const batch = mount(t, 'Components/Calendar/BatchBar.vue', { selected: [8], items: [] });
    batch.state.action.value = 'delete';
    const action = batch.state.run();
    batch.state.action.value = 'reject';
    batch.props.selected = [9];
    batch.answer.resolve(true);
    await action;
    assert.deepEqual(batch.requests.map(request => request.body), [{ action: 'delete', post_ids: [8] }]);
});

test('a possible duplicate comment waits for explicit confirmation before posting', async t => {
    const mutations = [];
    const post = ref({ id: 8, targets: [{ id: 3, first_comment_status: 'fallido', first_comment_may_exist: true }] });
    const panel = mount(t, 'Components/Composer/TargetStatusPanel.vue', {}, {
        useComposerContext: () => ({ post, accountName() {}, firstCommentMax: ref({}) }),
        countChars: text => text.length,
        router: { post: (target, data) => mutations.push([JSON.parse(target), data]) },
    });
    const action = panel.state.askForce({ id: 3 });
    assert.deepEqual(mutations, []);
    panel.answer.resolve(true);
    await action;
    assert.deepEqual(mutations, [[{ name: 'posts.first-comment.retry', params: [8, 3] }, { force: true }]]);
});

for (const [path, prop, otherProps, method, routeName, args = []] of [
    ['Pages/Sites/Edit.vue', 'site', { pages: [] }, 'destroy', 'sites.destroy'],
    ['Components/Sites/SiteAccessPanel.vue', 'site', {}, 'revokeAll', 'sites.revoke-access'],
    ['Pages/SmartLinks/Index.vue', 'link', {}, 'destroy', 'smartlinks.destroy'],
    ['Pages/Canva/Index.vue', 'brand', {}, 'disconnect', 'canva.disconnect'],
]) {
    test(`${routeName} keeps the target of the displayed confirmation`, async t => {
        const page = mount(t, path, { [prop]: { id: 8, ...otherProps }, defaults: {}, canva: {} });
        const action = page.state[method](...args);
        page.props[prop] = { id: 9, ...otherProps };
        assert.deepEqual(page.requests, []);
        page.answer.resolve(true);
        await action;
        assert.deepEqual(page.requests.map(value => JSON.parse(value)), [{ name: routeName, params: 8 }]);
    });
}

test('a failed Studio revocation does not show an old design error on a new design', async t => {
    const request = Promise.withResolvers();
    t.after(() => { request.resolve({ comments: [] }); });
    const panel = mount(t, 'Components/Studio/StudioCollabPanel.vue', { designId: 8, access: {} }, {
        studioRequest: () => request.promise,
    });
    const action = panel.state.removeComment({ id: 3 });
    panel.answer.resolve(true);
    await Promise.resolve();
    assert.equal(panel.state.busy.value, true);
    panel.props.designId = 9;
    request.reject(new Error('El diseño anterior ya no existe.'));
    await action;
    assert.equal(panel.state.error.value, '');
    assert.equal(panel.state.busy.value, false);
});

test('removing a page before the current page keeps the same page selected', async t => {
    const pages = [{ key: 'a' }, { key: 'b' }, { key: 'c' }, { key: 'd' }];
    const view = mount(t, 'Pages/Sites/Edit.vue', { site: { id: 8, pages } });
    view.state.current.value = 2;
    const action = view.state.removePage(0);
    view.answer.resolve(true);
    await action;
    assert.equal(view.state.current.value, 1);
    assert.equal(view.state.page.value.key, 'c');
});

test('a duplicate comment is not retried if the same target becomes published during confirmation', async t => {
    const target = { id: 3, first_comment_status: 'fallido', first_comment_may_exist: true };
    const post = ref({ id: 8, targets: [target] });
    const mutations = [];
    const panel = mount(t, 'Components/Composer/TargetStatusPanel.vue', {}, {
        useComposerContext: () => ({ post, accountName() {}, firstCommentMax: ref({}) }),
        countChars: text => text.length,
        router: { post: (...args) => mutations.push(args) },
    });
    const action = panel.state.askForce(target);
    post.value.targets = [{ ...target, first_comment_status: 'publicado' }];
    panel.answer.resolve(true);
    await action;
    assert.deepEqual(mutations, []);
});

test('changing Studio context and returning to the prior design invalidates the old confirmation', async t => {
    const panel = mount(t, 'Components/Studio/StudioCollabPanel.vue', { designId: 8, access: {} });
    const action = panel.state.removeComment({ id: 3 });
    panel.props.designId = 9;
    panel.props.designId = 8;
    panel.answer.resolve(true);
    await action;
    assert.deepEqual(panel.requests, []);
});


for (const [path, prop, method, routeName, args] of [
    ['Components/Sites/SiteContactInbox.vue', 'siteId', 'clearMessages', 'sites.messages.clear', [true]],
    ['Components/SmartLinks/SmartLinkSubscribers.vue', 'linkId', 'clearSubscribers', 'smartlinks.subscribers.clear', []],
]) {
    test(`${routeName} keeps the confirmed resource after its parent navigates`, async t => {
        const panel = mount(t, path, { [prop]: 8 });
        const action = panel.state[method](...args);
        panel.props[prop] = 9;
        assert.equal(panel.state.props[prop], 9);
        assert.deepEqual(panel.requests, []);
        panel.answer.resolve(true);
        await action;
        assert.deepEqual(panel.requests.map(value => JSON.parse(value)), [{ name: routeName, params: 8 }]);
    });

    test(`${routeName} does not mutate when its confirmation is cancelled`, async t => {
        const panel = mount(t, path, { [prop]: 8 });
        const action = panel.state[method](...args);
        assert.deepEqual(panel.requests, []);
        panel.answer.resolve(false);
        await action;
        assert.deepEqual(panel.requests, []);
    });
}
