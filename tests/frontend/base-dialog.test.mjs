import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { effectScope, markRaw, nextTick, reactive, ref, watch } from 'vue';

function mount(filename, initialProps, extra = {}) {
    const { descriptor } = parse(readFileSync(new URL('../../resources/js/Components/' + filename, import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: filename }).content
        .replace(/^import .*?;\r?$/gm, '').replace('export default', 'return');
    const scope = effectScope();
    const hooks = [];
    const events = [];
    const props = reactive(initialProps);
    const dependencies = { ref, watch, onBeforeUnmount: hook => hooks.push(hook), BaseDialog: {}, ...extra };
    const component = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies));
    const state = scope.run(() => component.setup(props, { expose() {}, emit: event => events.push(event) }));
    return { props, state, events, close: () => { hooks.forEach(hook => hook()); scope.stop(); } };
}

function openedDialog(panel, opener) {
    const element = markRaw({
        isConnected: true,
        open: false,
        ownerDocument: { activeElement: opener },
        showModal() { this.open = true; },
        close() { this.open = false; },
        querySelector() { return null; },
    });
    panel.state.dialog.value = element;
    return element;
}

test('a browser-closed dialog stays modal pending the parent decision and restores the invoker once accepted', async () => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: true, returnFocusTo: { isConnected: false } });
    const focusCalls = [];
    const dialog = openedDialog(panel, { isConnected: true, focus: options => focusCalls.push(options) });
    try {
        await nextTick();
        assert.equal(dialog.open, true);
        dialog.open = false;
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.equal(dialog.open, true);
        assert.deepEqual(panel.events, ['opened', 'close']);
        assert.deepEqual(focusCalls, []);

        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.deepEqual(panel.events, ['opened', 'close']);
        panel.props.open = false;
        await nextTick();
        assert.equal(dialog.open, false);
        assert.deepEqual(focusCalls, [{ preventScroll: true }]);
    } finally {
        panel.close();
    }
});

test('an unexpected native close cannot dismiss a dialog that blocks closing', async () => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: false });
    const dialog = openedDialog(panel, { isConnected: true });
    try {
        await nextTick();
        dialog.open = false;
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.equal(dialog.open, true);
        assert.deepEqual(panel.events, ['opened']);
    } finally {
        panel.close();
    }
});

test('closing GIF search aborts its request and ignores a response that still arrives', async () => {
    const pending = Promise.withResolvers();
    const requests = [];
    const component = mount('Composer/GifPicker.vue', { show: true }, {
        route: () => '/gifs/search',
        fetch: (url, options) => { requests.push({ url, ...options }); return pending.promise; },
    });
    try {
        assert.equal(requests.length, 1);
        component.props.show = false;
        await nextTick();
        assert.equal(requests[0].signal?.aborted, true);
        assert.equal(component.state.loading.value, false);
        pending.resolve(Response.json({ results: [{ id: 'late' }] }));
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(component.state.results.value, []);
    } finally {
        pending.resolve(Response.json({ results: [] }));
        component.close();
    }
});

function tabFixture(t, items, current) {
    const panel = mount('BaseDialog.vue', { open: true });
    t.after(panel.close);
    const focused = [];
    const controls = items.map((item, index) => ({
        tabIndex: item.tabIndex ?? 0,
        name: item.name ?? '',
        checked: false,
        form: null,
        isContentEditable: false,
        matches: selector => selector === 'input[type="radio"]' && Boolean(item.name),
        closest: () => null,
        getClientRects: () => [1],
        hasAttribute: name => name === 'tabindex' && item.tabIndex !== undefined,
        checkVisibility: () => !item.hidden,
        compareDocumentPosition(other) {
            const position = controls.indexOf(other);
            return position === index ? 0 : position < index ? 2 : 4;
        },
        focus: () => focused.push(item.id),
    }));
    const ownerDocument = {
        activeElement: controls[current],
        defaultView: {
            Node: { DOCUMENT_POSITION_PRECEDING: 2, DOCUMENT_POSITION_FOLLOWING: 4 },
            getComputedStyle: () => ({ visibility: 'visible' }),
        },
    };
    panel.state.dialog.value = markRaw({
        open: true,
        ownerDocument,
        querySelectorAll: () => controls,
        contains: element => controls.includes(element),
        close() { this.open = false; },
    });
    const pressTab = shiftKey => {
        let prevented = false;
        panel.state.handleKeydown({ key: 'Tab', shiftKey, preventDefault: () => { prevented = true; } });
        return prevented;
    };
    return { focused, pressTab };
}

test('reverse wrap targets the last unchecked radio in the last group', t => {
    const { focused, pressTab } = tabFixture(t, [{ id: 'first' }, { id: 'radio-first', name: 'choice' }, { id: 'radio-last', name: 'choice' }], 0);
    assert.equal(pressTab(true), true);
    assert.deepEqual(focused, ['radio-last']);
});

test('a programmatically focused element inside the tab order retains native navigation', t => {
    const { focused, pressTab } = tabFixture(t, [{ id: 'first' }, { id: 'internal', tabIndex: -1 }, { id: 'last' }], 1);
    assert.equal(pressTab(false), false);
    assert.deepEqual(focused, []);
});

test('a hidden final control does not prevent wrapping from the last visible control', t => {
    const { focused, pressTab } = tabFixture(t, [{ id: 'first' }, { id: 'last' }, { id: 'hidden', hidden: true }], 1);
    assert.equal(pressTab(false), true);
    assert.deepEqual(focused, ['first']);
});

test('a drag from inside the panel or a click on its own scrollbar does not dismiss the dialog', t => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: true, closeOnBackdrop: true });
    t.after(panel.close);
    const dialog = markRaw({
        open: true,
        getBoundingClientRect: () => ({ left: 10, top: 10, right: 90, bottom: 90 }),
        close() { this.open = false; },
    });
    panel.state.dialog.value = dialog;
    const point = (clientX, clientY) => ({ target: dialog, clientX, clientY });

    panel.state.markPointer(point(89, 50));
    panel.state.closeFromBackdrop(point(4, 50));
    assert.deepEqual(panel.events, []);
    panel.state.markPointer(point(89, 50));
    panel.state.closeFromBackdrop(point(89, 50));
    assert.deepEqual(panel.events, []);
    panel.state.markPointer(point(4, 50));
    panel.state.closeFromBackdrop(point(4, 50));
    assert.deepEqual(panel.events, ['close']);
});

test('a non-cancelable native cancel followed by close asks the parent only once', async () => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: true });
    const dialog = openedDialog(panel, { isConnected: true });
    try {
        await nextTick();
        const cancel = { cancelable: false, preventDefault() {} };
        panel.state.handleCancel(cancel);
        dialog.open = false;
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.deepEqual(panel.events, ['opened', 'close']);
    } finally {
        panel.close();
    }
});

test('reopening after a native veto preserves the control focused inside the dialog', async () => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: true });
    const dialog = openedDialog(panel, { isConnected: true });
    const focusCalls = [];
    const control = { focus: options => focusCalls.push(options) };
    dialog.contains = item => item === control;
    try {
        await nextTick();
        panel.state.rememberFocus({ target: control });
        dialog.open = false;
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.deepEqual(focusCalls, [{ preventScroll: true }]);
        panel.props.open = false;
        await nextTick();
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.equal(dialog.open, false);
        assert.deepEqual(panel.events, ['opened', 'close']);
    } finally {
        panel.close();
    }
});

test('reopening GIF search retries an aborted query instead of leaving unrelated results visible', async () => {
    const pending = Promise.withResolvers();
    const queries = [];
    const panel = mount('Composer/GifPicker.vue', { show: true }, {
        route: (_name, { q }) => q ?? '',
        fetch: query => {
            queries.push(query);
            if (query && queries.length === 2) return pending.promise;
            return Promise.resolve(Response.json({ results: [{ id: query || 'catalog' }] }));
        },
    });
    const flush = () => new Promise(resolve => setImmediate(resolve));
    try {
        await flush();
        assert.deepEqual(panel.state.results.value.map(item => item.id), ['catalog']);
        panel.state.query.value = 'new';
        const oldSearch = panel.state.load();
        panel.props.show = false;
        await nextTick();
        panel.props.show = true;
        await flush();
        assert.deepEqual(queries, ['', 'new', 'new']);
        assert.deepEqual(panel.state.results.value.map(item => item.id), ['new']);
        pending.resolve(Response.json({ results: [{ id: 'late' }] }));
        await oldSearch;
        assert.deepEqual(panel.state.results.value.map(item => item.id), ['new']);
    } finally {
        pending.resolve(Response.json({ results: [] }));
        panel.close();
    }
});

test('a native close is handled even if the browser had no active opener', async () => {
    const panel = mount('BaseDialog.vue', { open: true, closeable: true });
    const dialog = openedDialog(panel, null);
    try {
        await nextTick();
        dialog.open = false;
        panel.state.handleNativeClose({ currentTarget: dialog });
        assert.equal(dialog.open, true);
        assert.deepEqual(panel.events, ['opened', 'close']);
    } finally {
        panel.close();
    }
});

test('reopening the GIF picker does not submit text that the user only typed', async () => {
    const queries = [];
    const panel = mount('Composer/GifPicker.vue', { show: true }, {
        route: (_name, { q }) => q ?? '',
        fetch: async query => {
            queries.push(query);
            return Response.json({ results: [{ id: 'catalog' }] });
        },
    });
    const flush = () => new Promise(resolve => setImmediate(resolve));
    try {
        await flush();
        panel.state.query.value = 'unsubmitted';
        panel.props.show = false;
        await nextTick();
        panel.props.show = true;
        await flush();
        assert.deepEqual(queries, ['']);
        assert.equal(panel.state.query.value, 'unsubmitted');
    } finally {
        panel.close();
    }
});

test('reopening retries a failed GIF catalog without submitting later draft text', async () => {
    const queries = [];
    const panel = mount('Composer/GifPicker.vue', { show: true }, {
        route: (_name, { q }) => q ?? '',
        fetch: async query => {
            queries.push(query);
            if (queries.length === 1) throw new TypeError('Offline');
            return Response.json({ results: [{ id: 'catalog' }] });
        },
    });
    const flush = () => new Promise(resolve => setImmediate(resolve));
    try {
        await flush();
        assert.ok(panel.state.error.value);
        panel.state.query.value = 'unsubmitted';
        panel.props.show = false;
        await nextTick();
        panel.props.show = true;
        await flush();
        assert.deepEqual(queries, ['', '']);
        assert.deepEqual(panel.state.results.value.map(item => item.id), ['catalog']);
        assert.equal(panel.state.error.value, '');
    } finally {
        panel.close();
    }
});
