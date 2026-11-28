import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

test('the composer library uses the current brand, lists publishable assets, and cancels loading when closed', async () => {
    const source = readFileSync(new URL('../../resources/js/Components/Composer/MediaLibraryPicker.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const script = compileScript(descriptor, { id: 'composer-library-test' }).content
        .replace(/^import .*?;\r?$/gm, '')
        .replace('export default', 'return');
    const mounted = [], beforeUnmount = [], calls = [];
    const dependencies = {
        computed, ref, BaseDialog: {},
        onMounted: callback => mounted.push(callback),
        onBeforeUnmount: callback => beforeUnmount.push(callback),
        route: (name, brandId) => `${name}/${brandId}`,
        studioRequest: async (method, url, body, { signal }) => {
            calls.push({ method, url, body, signal });
            return { assets: [
                { id: 5, kind: 'image', name: 'Cartel' },
                { id: 6, kind: 'audio', name: 'Música' },
                { id: 7, kind: 'video', name: 'Anuncio' },
            ] };
        },
    };
    const subject = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies));
    const state = subject.setup({ brandId: 42, selectedIds: [] }, { expose() {}, emit() {} });

    await mounted[0]();
    assert.deepEqual(calls.map(({ method, url }) => ({ method, url })), [{ method: 'GET', url: 'studio.assets.index/42' }]);
    assert.deepEqual(state.visibleAssets.value.map(asset => asset.id), [5, 7]);
    state.query.value = 'cart';
    assert.deepEqual(state.visibleAssets.value.map(asset => asset.id), [5]);
    beforeUnmount[0]();
    assert.equal(calls[0].signal.aborted, true);
});
