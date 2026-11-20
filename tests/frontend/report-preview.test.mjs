import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope } from 'vue';
import { useReportPreview } from '../../resources/js/Composables/useReportPreview.js';

test('a preview received after changing brands cannot update the active draft', async (context) => {
    const calls = [];
    const previousFetch = globalThis.fetch;
    const previousRoute = globalThis.route;
    globalThis.route = () => '/reports/preview';
    globalThis.fetch = (_url, options) => new Promise(resolve => calls.push({ resolve, options }));
    const scope = effectScope();
    context.after(() => {
        scope.stop();
        globalThis.fetch = previousFetch;
        globalThis.route = previousRoute;
    });

    let brandId = 10;
    const preview = scope.run(() => useReportPreview(() => ({ brand_id: brandId })));
    const previous = preview.runPreview();
    brandId = 20;
    preview.resetPreview();
    const current = preview.runPreview();

    assert.equal(JSON.parse(calls[0].options.body).brand_id, 10);
    assert.equal(JSON.parse(calls[1].options.body).brand_id, 20);
    assert.equal(calls[0].options.signal.aborted, true);
    assert.equal(calls[1].options.signal.aborted, false);
    calls[0].resolve(Response.json({ brand: { id: 10 } }));
    await previous;
    assert.equal(preview.preview.value, null);
    assert.equal(preview.previewing.value, true);

    calls[1].resolve(Response.json({ brand: { id: 20 } }));
    await current;
    assert.equal(preview.preview.value.brand.id, 20);
    assert.equal(preview.previewing.value, false);
});
