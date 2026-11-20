import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick, reactive, ref, useId, watch } from 'vue';
import { useBestTimeSuggestion } from '../../resources/js/Composables/useBestTimeSuggestion.js';

const source = readFileSync(new URL('../../resources/js/Components/BestTimeSuggestion.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'best-time-mounted-state' })
    .content.replace(/^import .*?;\r?$/gm, '')
    .replace('export default', 'return');
const compile = new Function('ref', 'watch', 'useId', 'useBestTimeSuggestion', script);
const choices = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'facebook', label: 'Facebook' },
];

function mount(t, extra = {}) {
    const component = compile(ref, watch, useId, useBestTimeSuggestion);
    const originalSetup = component.setup;
    let state;
    component.setup = (props, context) => {
        state = originalSetup(props, context);
        // Mount the actual SFC setup and lifecycle; the real template has browser coverage.
        return () => h('div');
    };
    const input = reactive({ brand: { id: 7, name: 'Marca', timezone: 'UTC' }, networks: choices, ...extra });
    const renderer = createRenderer({
        createElement: () => ({}),
        insert() {},
        remove() {},
        patchProp() {},
        parentNode: () => null,
        nextSibling: () => null,
    });
    const app = renderer.createApp({ render: () => h(component, input) });
    app.mount({});
    t.after(() => app.unmount());
    return { input, state };
}

test('mounted best-time component defaults, preserves a valid choice and replaces a removed network', async (t) => {
    const { input, state } = mount(t);
    assert.equal(state.network.value, 'instagram');
    state.network.value = 'facebook';
    input.networks = [{ value: 'threads', label: 'Threads' }, ...choices];
    await nextTick();
    assert.equal(state.network.value, 'facebook');
    input.networks = [{ value: 'threads', label: 'Threads' }];
    await nextTick();
    assert.equal(state.network.value, 'threads');
});

test('mounted best-time component requires an explicit network when requested by the calendar', async (t) => {
    const { input, state } = mount(t, { requireNetworkChoice: true });
    assert.equal(state.network.value, '');
    state.network.value = 'facebook';
    input.networks = [choices[0]];
    await nextTick();
    assert.equal(state.network.value, '');
    input.requireNetworkChoice = false;
    await nextTick();
    assert.equal(state.network.value, 'instagram');
});
