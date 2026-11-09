import assert from 'node:assert/strict';

/** Marker 1760 Hz exists outside the intended [0.25, 1.25] trim; only 880 Hz belongs in the result. */
export function stereoWave() {
    const buffer = Buffer.alloc(44 + 32000 * 4);
    buffer.write('RIFF'); buffer.writeUInt32LE(buffer.length - 8, 4); buffer.write('WAVEfmt ', 8); buffer.writeUInt32LE(16, 16);
    buffer.writeUInt16LE(1, 20); buffer.writeUInt16LE(2, 22); buffer.writeUInt32LE(16000, 24); buffer.writeUInt32LE(64000, 28);
    buffer.writeUInt16LE(4, 32); buffer.writeUInt16LE(16, 34); buffer.write('data', 36); buffer.writeUInt32LE(buffer.length - 44, 40);
    for (let i = 0; i < 32000; i++) {
        const frequency = i >= 4000 && i < 20000 ? 880 : 1760;
        const value = Math.round(8192 * Math.sin(2 * Math.PI * frequency * i / 16000));
        buffer.writeInt16LE(value, 44 + i * 4); buffer.writeInt16LE(value, 46 + i * 4);
    }
    return buffer;
}

function spectrum(bytes, start, duration, frequency, channel) {
    const first = Math.round(start * 48000), count = Math.round(duration * 48000);
    let real = 0, imaginary = 0, power = 0;
    for (let i = 0; i < count; i++) {
        const sample = bytes.readFloatLE((first + i) * 8 + channel * 4), angle = 2 * Math.PI * frequency * i / 48000;
        real += sample * Math.cos(angle); imaginary += sample * Math.sin(angle); power += sample * sample;
    }
    return { amplitude: 2 * Math.hypot(real, imaginary) / count, rms: Math.sqrt(power / count) };
}

export function measureCompositeAudio(bytes) {
    assert.ok(Buffer.isBuffer(bytes) && bytes.length >= 168000 * 8 && bytes.length % 8 === 0, 'Expected at least 3.5 seconds of float32 stereo at 48 kHz');
    const channels = [0, 1].map(channel => ({
        video: spectrum(bytes, 0.75, 0.5, 440, channel), music: spectrum(bytes, 0.75, 0.5, 880, channel),
        attack: spectrum(bytes, 0.3, 0.1, 880, channel), release: spectrum(bytes, 3, 0.1, 880, channel),
        afterVideo: spectrum(bytes, 2.1, 0.5, 440, channel), musicAlone: spectrum(bytes, 2.1, 0.5, 880, channel),
        tail: spectrum(bytes, 3.35, 0.1, 880, channel), trimMarker: spectrum(bytes, 0.3, 0.1, 1760, channel),
    }));
    let difference = 0;
    for (let i = 0; i < 168000; i++) difference += (bytes.readFloatLE(i * 8) - bytes.readFloatLE(i * 8 + 4)) ** 2;
    return { left: channels[0], right: channels[1], channelDifferenceRms: Math.sqrt(difference / 168000) };
}

export function assertCompositeAudio(measurements) {
    const errors = [];
    const within = (name, value, min, max) => { if (!(Number.isFinite(value) && value >= min && value <= max)) errors.push(`${name}=${value}, expected ${min}..${max}`); };
    for (const channel of ['left', 'right']) {
        const data = measurements[channel];
        for (const band of ['video', 'music', 'musicAlone']) within(`${channel}.${band}`, data[band].amplitude, 0.04, 0.06);
        for (const band of ['attack', 'release']) within(`${channel}.${band}`, data[band].amplitude, 0.015, 0.025);
        within(`${channel}.afterVideo`, data.afterVideo.amplitude, 0, 0.002);
        within(`${channel}.tail`, data.tail.rms, 0, 0.002);
        within(`${channel}.trimMarker`, data.trimMarker.amplitude, 0, 0.003);
    }
    within('channelDifferenceRms', measurements.channelDifferenceRms, 0, 0.001);
    assert.equal(errors.length, 0, `Audio acceptance failed: ${errors.join('; ')}`);
}
