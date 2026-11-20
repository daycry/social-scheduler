import assert from 'node:assert/strict';
import test from 'node:test';
import { reactive, ref } from 'vue';
import { useSitePreview } from '../../resources/js/Composables/useSitePreview.js';

test('the preview uses the saved active page and refreshes only for a different page or revision', (context) => {
    const previousRoute = globalThis.route;
    globalThis.route = (name, params, absolute) => ({ name, params, absolute });
    context.after(() => { globalThis.route = previousRoute; });

    const site = reactive({
        id: 12, revision: 1, pages: [{ key: 'home', slug: 'inicio' }, { key: 'info', slug: 'contacto' }],
    });
    const activePage = ref(0);
    const { size, sizes, previewSrc } = useSitePreview(() => site, () => activePage.value);

    assert.deepEqual(previewSrc.value, { name: 'sites.preview', params: { site: 12, page: undefined, v: 1 }, absolute: false });
    activePage.value = 1;
    assert.deepEqual(previewSrc.value.params, { site: 12, page: 'contacto', v: 1 });
    const pendingPreview = previewSrc.value;
    size.value = 'desktop';
    assert.equal(sizes[size.value][1], '100%');
    assert.equal(previewSrc.value, pendingPreview);
    site.revision = 2;
    assert.deepEqual(previewSrc.value.params, { site: 12, page: 'contacto', v: 2 });
    activePage.value = 3;
    assert.deepEqual(previewSrc.value.params, { site: 12, page: undefined, v: 2 });
});
