import test from 'node:test';
import assert from 'node:assert/strict';

// Entorno minimo de navegador: un lienzo cuyo pixel es transparente (el video nunca presenta fotograma) y un rAF que NO dispara
// nunca, como en una pestana oculta. Se importa canvas.js real.
const context = { clearRect() {}, drawImage() {}, getImageData: () => ({ data: [0, 0, 0, 0] }) };
globalThis.document = { createElement: () => ({ width: 0, height: 0, getContext: () => context }) };
globalThis.requestAnimationFrame = () => 1;
globalThis.cancelAnimationFrame = () => {};
const { videoFrameReady } = await import('../../resources/js/Studio/canvas.js');
const stuckVideo = () => ({ error: null, readyState: 2, videoWidth: 320, videoHeight: 180 });

test('videoFrameReady vence por tiempo real aunque requestAnimationFrame no dispare nunca (pestana oculta)', async () => {
    const started = Date.now();
    await assert.rejects(videoFrameReady(stuckVideo(), { timeout: 60 }), /No se pudo preparar el fotograma/);
    assert.ok(Date.now() - started < 1000);
});

test('videoFrameReady se aborta con la senal sin esperar al limite ni a un fotograma', async () => {
    const controller = new AbortController();
    const pending = videoFrameReady(stuckVideo(), { timeout: 60000, signal: controller.signal });
    setTimeout(() => controller.abort(), 20);
    const started = Date.now();
    await assert.rejects(pending, { name: 'AbortError' });
    assert.ok(Date.now() - started < 1000);
});

test('videoFrameReady con la senal ya abortada rechaza de inmediato', async () => {
    const controller = new AbortController();
    controller.abort();
    await assert.rejects(videoFrameReady(stuckVideo(), { timeout: 60000, signal: controller.signal }), { name: 'AbortError' });
});

test('videoFrameReady resuelve en cuanto hay un fotograma pintable y no deja temporizadores vivos', async () => {
    let alpha = 0;
    context.getImageData = () => ({ data: [0, 0, 0, alpha] });
    globalThis.requestAnimationFrame = callback => { alpha = 255; queueMicrotask(callback); return 1; };
    await videoFrameReady(stuckVideo(), { timeout: 60000 });
    // Si quedara el temporizador de 60 s, el proceso de test no terminaria.
});
