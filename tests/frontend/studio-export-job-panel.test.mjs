import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { ref, watch, reactive, nextTick, effectScope } from 'vue';
import { createExportMonitor } from '../../resources/js/Studio/export-jobs.js';

function mountPanel(client) {
    const source = readFileSync(new URL('../../resources/js/Components/Studio/NativeExportJobs.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'export-panel-regression' }).content
        .replace(/^import .*?;\r?$/gm, '').replace('export default', 'return');
    const visits = [], hooks = [], props = reactive({ active: true, format: 'mp4', designId: 7, prepare: async () => ({}) });
    const factory = new Function('ref', 'watch', 'onBeforeUnmount', 'router', 'createStudioExportClient', 'createExportMonitor', compiled);
    const component = factory(ref, watch, fn => hooks.push(fn), { visit: url => visits.push(url) }, () => client, createExportMonitor);
    const scope = effectScope();
    const state = scope.run(() => component.setup(props, { expose: () => {}, emit: () => {} }));
    return { props, state, visits, close() { for (const hook of hooks) hook(); scope.stop(); } };
}

test('closing the panel while a draft is pending preserves the draft without navigating away later', async () => {
    let finish;
    const panel = mountPanel({ list: async () => ({ exports: [], pagination: { current_page: 1, last_page: 1 } }), post: () => new Promise(resolve => { finish = resolve; }) });
    try {
        const action = panel.state.control({ id: 19 }, 'post');
        panel.props.active = false; await nextTick();
        finish({ post: { id: 88, edit_url: '/posts/88/edit' } }); await action;
        assert.deepEqual(panel.visits, [], 'a response from a closed view must not navigate');
    } finally { panel.close(); }
});

test('changing MP4 to GIF fences the previous read before restarting the same design history', async () => {
    const requests = [];
    const panel = mountPanel({ list: (_designId, _page, signal) => new Promise(resolve => requests.push({ signal, resolve })) });
    try {
        assert.equal(requests.length, 1);
        panel.props.format = 'gif'; await nextTick();
        requests[0].resolve({ exports: [{ id: 19, status: 'ready' }], pagination: { current_page: 1, last_page: 1 } });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(panel.state.jobs.value.some(job => job.id === 19), false, 'old format response must be ignored');
        assert.equal(requests[0].signal?.aborted, true);
        assert.equal(requests.length, 2);
        requests[1].resolve({ exports: [], pagination: { current_page: 1, last_page: 1 } });
        await new Promise(resolve => setImmediate(resolve));
    } finally { panel.close(); for (const request of requests) request.resolve({ exports: [], pagination: { current_page: 1, last_page: 1 } }); }
});
