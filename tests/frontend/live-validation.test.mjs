import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope, nextTick, reactive } from 'vue';
import { useLiveValidation } from '../../resources/js/Composables/useLiveValidation.js';

test('a form change cancels the previous validation before the debounce runs', async t => {
    const scope = effectScope();
    const form = reactive({ text: 'initial', media: [], targets: [{}] });
    let pending;
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        pending = options.signal;
        return new Promise((_, reject) => pending.addEventListener('abort', () => reject(pending.reason), { once: true }));
    });
    const route = globalThis.route;
    globalThis.route = () => '/brands/1/posts/validate';
    t.after(() => {
        scope.stop();
        if (route === undefined) delete globalThis.route;
        else globalThis.route = route;
    });
    scope.run(() => useLiveValidation(form, { brandId: () => 1, enabled: () => true, immediate: true }));

    form.text = 'replacement';
    await nextTick();
    assert.equal(pending.aborted, true);
});
