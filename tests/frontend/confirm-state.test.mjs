import assert from 'node:assert/strict';
import test from 'node:test';
import { createApp, effectScope } from 'vue';
import { confirmKey, createConfirmState, useConfirm } from '../../resources/js/Composables/useConfirm.js';

test('confirmation resolves the caller decision and clears the dialog', async () => {
    const state = createConfirmState();
    const first = state.ask('¿Eliminar?');
    assert.equal(state.pending.value.message, '¿Eliminar?');
    state.answer(false);
    assert.equal(await first, false);
    assert.equal(state.pending.value, null);

    const second = state.ask({ title: 'Borrar carpeta', message: 'Los diseños se conservan.', confirmLabel: 'Borrar carpeta' });
    assert.equal(state.pending.value.confirmLabel, 'Borrar carpeta');
    state.answer(true);
    assert.equal(await second, true);
    assert.equal(state.pending.value, null);
});

test('a second destructive request cannot replace the question already visible', async () => {
    const state = createConfirmState();
    const current = state.ask('¿Eliminar la marca?');
    assert.equal(await state.ask('¿Eliminar el sitio?'), false);
    assert.equal(state.pending.value.message, '¿Eliminar la marca?');
    state.answer(true);
    assert.equal(await current, true);
});

test('leaving the requester cancels its question without affecting a later request', async () => {
    const state = createConfirmState();
    const previous = new AbortController();
    const current = state.ask('¿Eliminar el sitio?', previous.signal);
    previous.abort();
    assert.equal(await current, false);
    assert.equal(state.pending.value, null);
    assert.equal(await state.ask('¿Eliminar el sitio?', previous.signal), false);

    const future = state.ask('¿Revocar el acceso?');
    state.answer(true);
    assert.equal(await future, true);
});

test('useConfirm handles scope and caller cancellation without AbortSignal.any', async t => {
    const state = createConfirmState();
    const app = createApp({}).provide(confirmKey, state);
    const scope = effectScope();
    const laterScope = effectScope();
    const external = new AbortController();
    t.mock.method(AbortSignal, 'any', () => { throw new Error('AbortSignal.any is unavailable'); });
    t.after(() => { scope.stop(); laterScope.stop(); });
    const ask = scope.run(() => app.runWithContext(useConfirm));

    const externalQuestion = ask('Externa', external.signal);
    external.abort();
    assert.equal(await externalQuestion, false);
    assert.equal(await ask('Ya cancelada', external.signal), false);

    const beforeUnmount = ask('Desmontaje', new AbortController().signal);
    scope.stop();
    assert.equal(await beforeUnmount, false);

    const askLater = laterScope.run(() => app.runWithContext(useConfirm));
    const later = askLater('Pregunta posterior');
    assert.equal(state.pending.value.message, 'Pregunta posterior');
    state.answer(true);
    assert.equal(await later, true);
});

test('a late cancel event cannot cancel a subsequent question', async () => {
    const state = createConfirmState();
    const first = state.ask('Primera');
    const staleCancel = state.pending.value.cancel;
    state.answer(true);
    assert.equal(await first, true);

    const second = state.ask('Segunda');
    assert.equal(typeof staleCancel, 'function');
    staleCancel();
    assert.equal(state.pending.value.message, 'Segunda');
    state.pending.value.accept();
    assert.equal(await second, true);
});
