import assert from 'node:assert/strict';
import test from 'node:test';
import { effectScope, nextTick, ref } from 'vue';
import { HttpError } from '../../resources/js/lib/http.js';
import { useStudioCreation } from '../../resources/js/Composables/useStudioCreation.js';

const ready = { href: '/studio/designs/9/edit', message: 'Diseño creado.' };
const finished = { cancelled: false, interrupted: false };

function mount(t, { save = async () => ready, page = 'Studio/Editor', waitForVisit = false, cancelBeforeStart = false, unmountDuringSwap = false } = {}) {
    const scope = effectScope();
    t.after(() => scope.stop());
    const brandId = ref(8);
    const busy = ref(false);
    const error = ref('Aviso anterior');
    const requests = [];
    const visits = [];
    const flashes = [];
    const listeners = new Set();
    let pending;
    const router = {
        on(event, callback) {
            assert.equal(event, 'exception');
            listeners.add(callback);
            return () => listeners.delete(callback);
        },
        visit(href, options) {
            visits.push(href);
            if (cancelBeforeStart) return;
            pending = options;
            options.onStart();
            if (!waitForVisit) {
                if (unmountDuringSwap) scope.stop();
                options.onSuccess({ component: page });
                options.onFinish(finished);
            }
        },
        flash: (kind, value) => flashes.push([kind, value]),
    };
    const state = scope.run(() => useStudioCreation({
        getBrandId: () => brandId.value, busy, error, router,
        getLocation: () => 'https://metricool.test/studio',
        save: data => { requests.push(data); return save(data); },
    }));
    return {
        ...state, brandId, busy, error, requests, visits, flashes, listeners,
        start: () => pending.onStart(),
        succeed: (component = 'Studio/Editor') => pending.onSuccess({ component }),
        finish: (visit = finished) => pending.onFinish(visit),
        stop: () => scope.stop(),
    };
}

test('Studio reports a typed HTTP error in the dialog', async t => {
    const panel = mount(t, { save: () => { throw new HttpError('Acceso denegado.', 403); } });
    panel.createOpen.value = true;
    await nextTick();
    await panel.create();
    assert.equal(panel.createError.value, 'Acceso denegado.');
    assert.equal(panel.error.value, '');
    assert.equal(panel.busy.value, false);
    assert.deepEqual(panel.visits, []);
});

test('Studio reports an inline template request failure on the listing', async t => {
    const panel = mount(t, { save: () => { throw new TypeError('Failed to fetch'); } });
    await panel.create({ key: 'template', name: 'Prueba' });
    assert.equal(panel.error.value, 'No se pudo crear el diseño. Inténtalo de nuevo.');
    assert.deepEqual(panel.flashes, []);
});

test('Studio announces success only when the editor opens', async t => {
    const editor = mount(t, { unmountDuringSwap: true });
    await editor.create();
    assert.deepEqual(editor.visits, [ready.href]);
    assert.deepEqual(editor.flashes, [['success', ready.message]]);
    assert.equal(editor.listeners.size, 0);

    const other = mount(t, { page: 'Errors/NotFound', unmountDuringSwap: true });
    await other.create();
    assert.deepEqual(other.flashes, []);
    assert.equal(other.openDesignError.value, '');
    assert.equal(other.listeners.size, 0);
});

test('Studio retries the same created design after a transport error without another POST', async t => {
    const panel = mount(t, { waitForVisit: true });
    await panel.create();
    assert.equal(panel.busy.value, true);
    await panel.create();
    assert.equal(panel.requests.length, 1);

    const [onException] = panel.listeners;
    const exception = (url, code = 'ERR_NETWORK', method = 'get') => ({ detail: { exception: { code, config: { method, url } } } });
    assert.equal(onException(exception('/reports')), undefined);
    assert.equal(onException(exception('https://[')), undefined);
    assert.equal(onException(exception(ready.href, 'ERR_NETWORK', 'post')), undefined);
    assert.equal(onException(exception(ready.href, 'ERR_CANCELED')), undefined);
    assert.equal(onException(exception('https://elsewhere.test' + ready.href, 'ETIMEDOUT')), undefined);
    assert.equal(onException(exception(ready.href)), false);
    assert.equal(onException(exception(ready.href, 'ECONNABORTED')), false);
    assert.equal(onException(exception('https://metricool.test' + ready.href, 'ETIMEDOUT')), false);
    panel.finish();
    assert.equal(panel.listeners.size, 0);
    assert.equal(panel.busy.value, false);
    assert.match(panel.openDesignError.value, /no se pudo abrir el editor/i);

    await panel.create({ key: 'another' });
    assert.equal(panel.requests.length, 1);
    panel.visitCreatedDesign();
    assert.deepEqual(panel.visits, [ready.href, ready.href]);
    assert.equal(panel.openDesignError.value, '');
    panel.finish({ cancelled: true });
    assert.equal(panel.listeners.size, 0);
    assert.equal(panel.openDesignError.value, '');
});

test('Studio resets the form and focuses its name when the user asks to create another design', async t => {
    const panel = mount(t, { cancelBeforeStart: true });
    panel.createName.value = 'Diseño anterior';
    panel.width.value = 320;
    panel.height.value = 240;
    let focuses = 0;
    panel.createNameInput.value = { focus() { focuses++; } };
    await panel.create();
    assert.equal(panel.createdDesign.value.href, ready.href);
    await panel.startAnotherDesign();
    assert.equal(panel.createdDesign.value, null);
    assert.equal(panel.createOpen.value, true);
    assert.equal(panel.createName.value, 'Diseño sin título');
    assert.deepEqual([panel.width.value, panel.height.value], [1080, 1080]);
    assert.equal(focuses, 1);
});

test('Studio discards the previous brand state and a creation response arriving after a brand change', async t => {
    const previous = mount(t, { cancelBeforeStart: true });
    await previous.create();
    previous.brandId.value = 18;
    await nextTick();
    assert.equal(previous.createdDesign.value, null);

    let resolve;
    const pending = new Promise(done => { resolve = done; });
    const panel = mount(t, { save: () => pending });
    const creating = panel.create();
    panel.brandId.value = 18;
    resolve(ready);
    await creating;
    assert.equal(panel.createdDesign.value, null);
    assert.equal(panel.busy.value, false);
    assert.deepEqual(panel.visits, []);
});

test('Studio unsubscribes from transport events if the listing unmounts during a visit', async t => {
    const panel = mount(t, { waitForVisit: true });
    await panel.create();
    assert.equal(panel.listeners.size, 1);
    panel.stop();
    assert.equal(panel.listeners.size, 0);
});

test('Studio shows progress while opening a template and recovery after a failed visit', async t => {
    const panel = mount(t, { waitForVisit: true });
    await panel.create({ key: 'template', name: 'Prueba' });
    assert.equal(panel.creationNotice.value, 'Abriendo el editor…');
    assert.deepEqual(panel.flashes, []);

    let focused = false;
    panel.openCreatedBannerButton.value = { focus: () => { focused = true; } };
    panel.finish();
    await nextTick();
    assert.equal(panel.creationNotice.value, 'El diseño está guardado.');
    panel.busy.value = true;
    assert.equal(panel.creationNotice.value, 'El diseño está guardado.');
    panel.busy.value = false;
    assert.equal(focused, true);
    panel.visitCreatedDesign();
    assert.equal(panel.creationNotice.value, 'Abriendo el editor…');
    panel.succeed();
    panel.finish();
    assert.deepEqual(panel.flashes, [['success', ready.message]]);
});

test('Studio keeps one transport listener and ignores an interrupted visit after changing brand', async t => {
    const panel = mount(t, { waitForVisit: true });
    await panel.create();
    panel.start();
    assert.equal(panel.listeners.size, 1);

    panel.brandId.value = 18;
    await nextTick();
    panel.succeed();
    panel.finish({ interrupted: true });
    assert.deepEqual(panel.flashes, []);
    assert.equal(panel.openDesignError.value, '');
    assert.equal(panel.listeners.size, 0);
});