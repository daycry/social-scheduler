import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { computed, ref, watch, reactive, effectScope, nextTick } from 'vue';
import { blankDocument } from '../../resources/js/Studio/document.js';
import * as captions from '../../resources/js/Studio/captions.js';

function mount() {
    const { descriptor } = parse(readFileSync(new URL('../../resources/js/Components/Studio/CaptionsPanel.vue', import.meta.url), 'utf8'));
    const compiled = compileScript(descriptor, { id: 'captions-panel-test' }).content.replace(/^import .*?;\r?$/gm, '').replace('export default', 'return');
    const doc = blankDocument(320, 180); doc.schema_version = 2; doc.timeline = { fps: 30, audio_tracks: [] };
    const actions = [], downloads = [], hooks = [];
    const props = reactive({ document: doc, time: 0.5, duration: 5, disabled: false, fonts: [], kitFonts: [], apply: async op => { actions.push(op); } });
    const vars = { ref, computed, watch, onBeforeUnmount: hook => hooks.push(hook), ...captions, downloadBlob: (...args) => downloads.push(args) };
    const component = new Function(...Object.keys(vars), compiled)(...Object.values(vars));
    const scope = effectScope(), state = scope.run(() => component.setup(props, { expose() {}, emit() {} }));
    return { actions, downloads, props, state, close: () => { hooks.forEach(hook => hook()); scope.stop(); } };
}
test('caption panel adds a typed cue at the playhead through the shared history operation', async () => {
    const panel = mount();
    try {
        await panel.state.addCue();
        assert.equal(panel.actions.length, 1);
        assert.equal(panel.actions[0].action, 'upsert');
        assert.equal(panel.actions[0].cue.start, 0.5);
        assert.ok(panel.actions[0].cue.end > 0.5);
    } finally { panel.close(); }
});
test('caption panel imports UTF8 privately as text and reports invalid UTF8 without applying an operation', async () => {
    const panel = mount();
    try {
        const bytes = new TextEncoder().encode('1\n00:00:00,500 --> 00:00:01,500\nHola 🎵\n');
        await panel.state.importFile({ target: { files: [{ name: 'own.srt', size: bytes.length, arrayBuffer: async () => bytes.buffer }], value: 'own.srt' } });
        assert.equal(panel.actions.length, 1); assert.equal(panel.actions[0].format, 'srt'); assert.ok(panel.actions[0].text.includes('🎵'));
        const bad = new Uint8Array([255]);
        await panel.state.importFile({ target: { files: [{ name: 'bad.srt', size: 1, arrayBuffer: async () => bad.buffer }], value: 'bad.srt' } });
        assert.equal(panel.actions.length, 1); assert.ok(panel.state.error.value.length > 0);
    } finally { panel.close(); }
});
test('caption panel fences a pending local file read when its document changes', async () => {
    const panel = mount(); let finish;
    try {
        const bytes = new TextEncoder().encode('1\n00:00:00,500 --> 00:00:01,500\nHola\n');
        const operation = panel.state.importFile({ target: { files: [{ name: 'own.srt', size: bytes.length, arrayBuffer: () => new Promise(resolve => { finish = resolve; }) }], value: '' } });
        panel.props.document = JSON.parse(JSON.stringify(panel.props.document)); await nextTick();
        finish(bytes.buffer); await operation;
        assert.deepEqual(panel.actions, []);
    } finally { panel.close(); }
});
test('caption panel chooses a brand font by stable ID and exposes rejected edits locally', async () => {
    const panel = mount();
    try {
        panel.props.fonts = [{ id: 23, family: 'Brand Family', name: 'Propia' }];
        await panel.state.chooseFont('id:23');
        assert.equal(panel.actions[0].style.font_id, 23); assert.equal(panel.actions[0].style.fontFamily, 'Brand Family');
        panel.props.apply = async () => { throw new Error('Intervalo inválido'); };
        await panel.state.addCue();
        assert.equal(panel.state.error.value, 'Intervalo inválido');
        assert.equal(panel.props.document.timeline.captions, undefined);
    } finally { panel.close(); }
});
