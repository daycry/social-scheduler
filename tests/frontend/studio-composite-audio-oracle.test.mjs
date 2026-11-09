import test from 'node:test';
import assert from 'node:assert/strict';

async function oracle() {
    let result;
    try { result = await import('../e2e/support/studio-composite-audio.mjs'); }
    catch (error) { if (error.code !== 'ERR_MODULE_NOT_FOUND') throw error; }
    assert.equal(typeof result?.assertCompositeAudio, 'function', 'discriminating stereo/envelope/trim acceptance is unavailable');
    return result;
}

// Analytic output fixture, independent of WAV generation and of the production mixer.
function output({ envelope = true, trimStart = true, trimEnd = true, loop = true, right = 1 } = {}) {
    const pcm = Buffer.alloc(168000 * 8);
    for (let i = 0; i < 168000; i++) {
        const time = i / 48000, local = time - 0.25;
        let value = time < 2 ? 0.05 * Math.sin(2 * Math.PI * 440 * time) : 0;
        if (local >= 0 && local < 3) {
            const start = trimStart ? 0.25 : 0, end = trimEnd ? 1.25 : 2, length = end - start;
            const gain = envelope ? Math.max(0, Math.min(1, local / 0.25, (3 - local) / 0.5)) : 1;
            if (loop || local < length) {
                const source = start + (loop ? local % length : local), frequency = source >= 0.25 && source < 1.25 ? 880 : 1760;
                value += 0.05 * gain * Math.sin(2 * Math.PI * frequency * source);
            }
        }
        pcm.writeFloatLE(value, i * 8); pcm.writeFloatLE(value * right, i * 8 + 4);
    }
    return pcm;
}

test('composite oracle accepts the calibrated two-channel trimmed loop with measurable gain ramps', async () => {
    const { measureCompositeAudio, assertCompositeAudio } = await oracle();
    const measurements = measureCompositeAudio(output());
    assertCompositeAudio(measurements);
    assert.ok(Math.abs(measurements.left.attack.amplitude - 0.02) < 0.001);
    assert.ok(Math.abs(measurements.right.release.amplitude - 0.02) < 0.001);
    assert.equal(measurements.channelDifferenceRms, 0);
});

for (const [name, changes, error] of [
    ['missing gain envelope', { envelope: false }, /attack|release/],
    ['missing trim start', { trimStart: false }, /attack|trimMarker/],
    ['missing trim end', { trimEnd: false }, /release/],
    ['missing loop', { loop: false }, /musicAlone|release/],
    ['silent right channel', { right: 0 }, /right\./],
    ['inverted right channel', { right: -1 }, /channelDifferenceRms/],
]) test(`composite oracle rejects ${name}`, async () => {
    const { measureCompositeAudio, assertCompositeAudio } = await oracle();
    assert.throws(() => assertCompositeAudio(measureCompositeAudio(output(changes))), error);
});
