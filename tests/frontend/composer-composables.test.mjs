import assert from 'node:assert/strict';
import test from 'node:test';
import { router } from '@inertiajs/vue3';
import { createRenderer, h, ref } from 'vue';
import { useComposerCampaigns } from '../../resources/js/Composables/composer/useComposerCampaigns.js';
import { useComposerMedia } from '../../resources/js/Composables/composer/useComposerMedia.js';

function mountMedia(input) {
    const renderer = createRenderer({ createElement: () => ({}), insert() {}, remove() {} });
    let media;
    const app = renderer.createApp({
        setup() {
            media = useComposerMedia({ ...input, mediaMetaSource: ref(), altMaxSource: ref() });
            return () => h('div');
        },
    });
    app.mount({});
    return { media, unmount: () => app.unmount() };
}

function useRouteForTest(context) {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'route');
    globalThis.route = (name, id) => id === undefined ? name : `${name}/${id}`;
    context.after(() => {
        if (original) Object.defineProperty(globalThis, 'route', original);
        else delete globalThis.route;
    });
}

test('keeps detached Studio assets available for target preview and revokes temporary previews at unmount', context => {
    const common = { id: 1, kind: 'image' };
    const separate = { id: 2, kind: 'video' };
    const post = ref({
        studio_origin_url: '/studio/1', media: [common], target_media_assets: [separate],
        targets: [{ account_id: 5, content_type: 'reel', media: [separate] }],
    });
    const form = { media: [1], detached_media: [] };
    const { media, unmount } = mountMedia({ form, post });
    const create = context.mock.method(URL, 'createObjectURL', () => 'blob:preview');
    const revoke = context.mock.method(URL, 'revokeObjectURL', () => {});
    context.after(() => {
        unmount();
        assert.deepEqual(revoke.mock.calls.map(call => call.arguments), [['blob:preview']]);
    });
    const uploaded = { id: 3, kind: 'image' };
    const file = new Blob(['preview']);
    media.onUploaded(uploaded, file);
    media.onUploaded(uploaded, file);
    assert.deepEqual(form.media, [1, 3]);
    assert.equal(media.previews.value[3], 'blob:preview');
    assert.equal(create.mock.callCount(), 1);

    const target = { account_id: 5, content_type: 'reel', media: [{ id: 2 }] };
    media.removeTargetMedia(target, 0);
    assert.deepEqual(target.detached_media, [2]);
    media.removeMedia(1);
    assert.deepEqual(form.detached_media, [1]);
    assert.deepEqual(media.mediaById(1), common);
    assert.deepEqual(media.mediaById(2), separate);
    media.customizeMedia(target);
    assert.deepEqual(target.media, [{ id: 3, alt: '', cover_id: null }]);
    media.resetMedia(target);
    assert.equal(target.media, null);
    assert.deepEqual(media.effectiveMedia(target), [{ id: 3, alt: '', cover_id: null }]);
    assert.deepEqual(target.detached_media, [2]);

});

test('the media selector retries an error and reuses the loaded library', async context => {
    useRouteForTest(context);
    const { media, unmount } = mountMedia({ form: { media: [] }, post: ref(null) });
    context.after(unmount);
    const asset = { id: 7, kind: 'image' };
    const fetch = context.mock.method(globalThis, 'fetch', async () => fetch.mock.callCount() === 0
        ? new Response('', { status: 503 })
        : Response.json({ media: [asset] }));
    await media.loadLibrary();
    assert.equal(media.libraryState.value, 'error');
    await Promise.all([media.loadLibrary(), media.loadLibrary()]);
    assert.equal(media.libraryState.value, 'ready');
    await media.loadLibrary();
    assert.equal(fetch.mock.callCount(), 2);
    const target = { media: [] };
    assert.deepEqual(media.addableMedia(target), [asset]);
    media.addTargetMedia(target, asset.id);
    assert.deepEqual(media.mediaById(asset.id), asset);
    assert.deepEqual(media.addableMedia(target), []);
});

test('a campaign on a fixed post rolls back to the last value saved by the server', context => {
    useRouteForTest(context);
    const accepted = [];
    const form = { campaign_id: 2, defaults: (...args) => accepted.push(args) };
    const editable = ref(true);
    const patch = context.mock.method(router, 'patch', (_url, _payload, options) => options.onStart());
    const campaigns = useComposerCampaigns({ form, post: ref({ id: 9, campaign_id: 1 }), editable, brand: ref(), campaignSource: ref([]) });
    campaigns.saveCampaign();
    assert.equal(patch.mock.callCount(), 0);
    editable.value = false;
    campaigns.saveCampaign();
    campaigns.saveCampaign();
    assert.equal(patch.mock.callCount(), 1);
    const [url, payload, first] = patch.mock.calls[0].arguments;
    assert.equal(url, 'posts.campaign/9');
    assert.deepEqual(payload, { campaign_id: 2 });
    first.onSuccess();
    first.onFinish();
    assert.deepEqual(accepted, [['campaign_id', 2]]);

    form.campaign_id = 3;
    campaigns.saveCampaign();
    const retry = patch.mock.calls[1].arguments[2];
    retry.onError({ campaign_id: 'Campaña rechazada' });
    retry.onFinish();
    assert.equal(form.campaign_id, 2);
    assert.equal(campaigns.campaignError.value, 'Campaña rechazada');
});

test('creating a campaign uses the current brand and selects the new campaign after reloading the options', context => {
    useRouteForTest(context);
    const form = { campaign_id: 1 };
    const brand = ref({ id: 2 });
    const campaignSource = ref([{ id: 1 }]);
    const post = context.mock.method(router, 'post', (_url, _payload, options) => options.onStart());
    const campaigns = useComposerCampaigns({ form, post: ref(null), editable: ref(true), brand, campaignSource });
    brand.value = { id: 4 };
    const onCreated = context.mock.fn();
    campaigns.createCampaign('  Lanzamiento  ', { onCreated });
    const [url, payload, options] = post.mock.calls[0].arguments;
    assert.equal(url, 'campaigns.store');
    assert.equal(payload.brand_id, 4);
    assert.equal(payload.name, 'Lanzamiento');
    assert.deepEqual(options.only, ['campaigns', 'flash']);
    campaignSource.value = [{ id: 1 }, { id: 8 }];
    options.onSuccess();
    options.onFinish();
    assert.equal(form.campaign_id, 8);
    assert.equal(onCreated.mock.callCount(), 1);
});
