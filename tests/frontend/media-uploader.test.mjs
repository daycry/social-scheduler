import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { effectScope, nextTick, ref, watch } from 'vue';
import { useStudioAssetLinks } from '../../resources/js/Composables/useStudioAssetLinks.js';

function uploaderScript() {
    const source = readFileSync(new URL('../../resources/js/Components/MediaUploader.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    return compileScript(descriptor, { id: 'media-uploader-test' }).content
        .replace(/^import .*?;\r?$/gm, '')
        .replace('export default', 'return');
}

test('media selection keeps a single upload active and cancels it on unmount', async () => {
    const script = uploaderScript();
    const scope = effectScope();
    const hooks = [];
    const uploads = [];
    const events = [];
    const pending = Promise.withResolvers();
    const dependencies = {
        ref, watch,
        onBeforeUnmount: hook => hooks.push(hook),
        uploadMedia: (file, options) => {
            uploads.push({ file, signal: options.signal });
            return pending.promise;
        },
    };
    const component = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies));
    const state = scope.run(() => component.setup({}, { expose() {}, emit: (...event) => events.push(event) }));
    const select = name => state.onFile({ target: { files: [new File(['media'], name)], value: name } });

    try {
        const first = select('first.png');
        const second = select('second.png');
        assert.deepEqual(uploads.map(upload => upload.file.name), ['first.png']);
        assert.equal(state.busy.value, true);
        await nextTick();
        hooks.forEach(hook => hook());
        scope.stop();
        assert.equal(uploads[0].signal.aborted, true);
        pending.resolve({ id: 7 });
        await Promise.all([first, second]);
        assert.deepEqual(events, [['busy', true], ['busy', false]]);
    } finally {
        pending.resolve();
        scope.stop();
    }
});

test('the composer releases the upload while the Studio link is still pending', async t => {
    const scope = effectScope();
    t.after(() => scope.stop());
    let finishLink;
    const linking = new Promise(resolve => { finishLink = resolve; });
    const calls = [];
    const register = scope.run(() => useStudioAssetLinks({
        attach: async (brandId, assetId) => {
            calls.push([brandId, assetId]);
            await linking;
        },
    }));
    const dependencies = {
        ref, watch,
        onBeforeUnmount() {},
        uploadMedia: async () => ({ id: 7, name: 'imagen.png' }),
    };
    const component = new Function(...Object.keys(dependencies), uploaderScript())(...Object.values(dependencies));
    let attaching;
    const uploader = scope.run(() => component.setup({}, {
        expose() {},
        emit: (event, asset) => { if (event === 'uploaded') attaching = register.linkAsset(42, asset); },
    }));
    const file = new File(['media'], 'imagen.png', { type: 'image/png' });

    try {
        await uploader.onFile({ target: { files: [file], value: file.name } });
        assert.equal(uploader.busy.value, false);
        assert.equal(register.pendingLinks.value[0]?.state, 'linking');
        await register.linkAsset(42, { id: 7, name: file.name });
        assert.deepEqual(calls, [[42, 7]]);
    } finally {
        finishLink();
        await attaching;
    }
    assert.deepEqual(register.pendingLinks.value, []);
});

for (const status of [408, 429, 503]) {
    test(`Studio retries a failed link with status ${status} without uploading again`, async t => {
        const scope = effectScope();
        t.after(() => scope.stop());
        const requests = [];
        const state = scope.run(() => useStudioAssetLinks({
            attach: async (brandId, assetId) => {
                requests.push([brandId, assetId]);
                if (requests.length === 1) throw Object.assign(new Error('No se pudo vincular.'), { status });
            },
        }));
        const asset = { id: 7, name: 'imagen.png' };

        await state.linkAsset(42, asset);
        assert.equal(state.pendingLinks.value[0]?.retry, true);
        await state.linkAsset(42, asset);
        assert.deepEqual(state.pendingLinks.value, []);
        assert.deepEqual(requests, [[42, 7], [42, 7]]);
        assert.match(state.announcement.value, /imagen\.png se añadió/);
    });
}

test('Studio preserves per-file validation errors and permits a new request after its deadline', async t => {
    const scope = effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => useStudioAssetLinks({
        timeoutMs: 25,
        attach: async (_brandId, id, { signal }) => {
            if (id === 7) throw Object.assign(new Error('Formato incompatible.'), { status: 422 });
            await new Promise((_, reject) => signal.addEventListener('abort', () => reject(signal.reason), { once: true }));
        },
    }));
    await state.linkAsset(42, { id: 7, name: 'incompatible.png' });
    await state.linkAsset(42, { id: 8, name: 'lenta.png' });
    assert.deepEqual(state.pendingLinks.value.map(({ asset, retry, error }) => [asset.id, retry, error]), [
        [7, false, 'Formato incompatible.'],
        [8, true, ''],
    ]);
});

test('Studio explains an expired session and lets the user dismiss the failed link', async t => {
    const scope = effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => useStudioAssetLinks({
        attach: async () => { throw Object.assign(new Error('Page Expired'), { status: 419 }); },
    }));

    await state.linkAsset(42, { id: 7, name: 'imagen.png' });
    const [link] = state.pendingLinks.value;
    assert.equal(link.retry, false);
    assert.match(state.announcement.value, /vuelve a iniciar sesión/i);
    assert.equal(state.announcementBrandId.value, 42);
    state.dismiss(link.key);
    assert.deepEqual(state.pendingLinks.value, []);
});
