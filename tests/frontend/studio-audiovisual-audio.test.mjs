import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { mixAudioSegment } from '../../scripts/studio/av-audio.mjs';
import { prepareAudioSource, prepareAudioSources, pcmWindowReader } from '../../scripts/studio/av-pcm.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory;
const rate = 48000;
function wav(seconds, frequency) {
    const count = seconds * rate, bytes = Buffer.alloc(44 + count * 8);
    bytes.write('RIFF'); bytes.writeUInt32LE(bytes.length - 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16);
    bytes.writeUInt16LE(3, 20); bytes.writeUInt16LE(2, 22); bytes.writeUInt32LE(rate, 24); bytes.writeUInt32LE(rate * 8, 28);
    bytes.writeUInt16LE(8, 32); bytes.writeUInt16LE(32, 34); bytes.write('data', 36); bytes.writeUInt32LE(count * 8, 40);
    for (let n = 0; n < count; n++) for (let channel = 0; channel < 2; channel++) bytes.writeFloatLE(0.1 * Math.sin(2 * Math.PI * frequency * n / rate), 44 + n * 8 + channel * 4);
    return bytes;
}
before(async () => {
    directory = path.join(base, crypto.randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    for (const [id, frequency] of [[1, 220], [2, 880], [3, 443]]) await fs.writeFile(path.join(directory, 'assets', `${id}.bin`), wav(4, frequency));
    await fs.writeFile(path.join(directory, 'assets/4.bin'), wav(0.1, 440));
    for (const [id, sampleRate, codec, format] of [[5, 44100, 'aac', 'ipod'], [6, 48000, 'aac', 'ipod'], [7, 44100, 'libmp3lame', 'mp3']]) {
        execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', `sine=frequency=997:sample_rate=${sampleRate}:duration=6`,
            '-c:a', codec, '-f', format, path.join(directory, `assets/${id}.bin`)], { timeout: 15000 });
    }
});

test('native audio rejects truncated decoder output instead of filling the missing tail with a held sample', async () => {
    const source = { id: 4, file: 'assets/4.bin', kind: 'audio', status: 'listo', audio_codec: 'pcm_f32le', duration: 4, mime: 'audio/wav' };
    const compiled = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30,
        audio_tracks: [{ id: 'short', asset_id: 4, start: 0, duration: 2 }] }, pages: [{ id: 'p', duration: 2, background: '#000000', elements: [] }] }, [source]);
    await assert.rejects(async () => {
        const audioSources = await prepareAudioSources({ directory, plan: compiled, assets: [source] });
        return mixAudioSegment({ directory, plan: compiled, audioSources, segment: compiled.segments[0], name: 'truncated' });
    }, /incomplet|muestras|truncad|duración/i);
    await assert.rejects(fs.access(path.join(directory, 'segments/truncated-000000.wav')));
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const assets = [1, 2, 3].map(id => ({ id, kind: 'audio', status: 'listo', audio_codec: 'pcm_f32le', duration: 4, file: `assets/${id}.bin`, mime: 'audio/wav' }));
function plan(tracks, segmentFrames = 15) {
    return compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: tracks },
        pages: [{ id: 'p', duration: 2, background: '#000000', elements: [] }] }, assets, { segmentFrames });
}
async function collect(compiled, label) {
    const samples = [], reads = [];
    const audioSources = await prepareAudioSources({ directory, plan: compiled, assets });
    for (const segment of compiled.segments) {
        const result = await mixAudioSegment({ directory, plan: compiled, audioSources, segment, name: label, onRead: window => reads.push(window) });
        const bytes = await fs.readFile(path.join(directory, result.file));
        assert.equal(bytes.subarray(0, 4).toString(), 'RIFF'); assert.equal(bytes.length, 44 + segment.sampleCount * 8);
        assert.equal(result.sampleCount, segment.sampleCount);
        for (let offset = 44; offset < bytes.length; offset += 8) samples.push(bytes.readFloatLE(offset));
    }
    return { samples, reads };
}
function magnitude(samples, from, to, frequency) {
    let real = 0, imag = 0;
    for (let n = Math.round(from * rate); n < Math.round(to * rate); n++) {
        real += samples[n] * Math.cos(2 * Math.PI * frequency * n / rate);
        imag += samples[n] * Math.sin(2 * Math.PI * frequency * n / rate);
    }
    return Math.hypot(real, imag) * 2 / Math.round((to - from) * rate);
}

test('native PCM segments change rate and mix known tones at their global start without resetting phase', async () => {
    const { samples, reads } = await collect(plan([
        { id: 'rate', asset_id: 1, start: 0, duration: 2, playbackRate: 2, volume: 0.5 },
        { id: 'overlay', asset_id: 2, start: 1, duration: 1, volume: 0.25 },
    ]), 'tones');
    assert.equal(samples.length, rate * 2); assert.ok(reads.length > 0, 'real source windows must be decoded');
    assert.ok(Math.abs(magnitude(samples, 0.25, 0.75, 440) - 0.05) < 0.002);
    assert.ok(magnitude(samples, 0.25, 0.75, 880) < 0.0002);
    assert.ok(Math.abs(magnitude(samples, 1.25, 1.75, 440) - 0.05) < 0.002);
    assert.ok(Math.abs(magnitude(samples, 1.25, 1.75, 880) - 0.025) < 0.002);
    for (const n of [23999, 24000, 47999, 48000, 71999, 72000]) {
        const time = n / rate, expected = 0.05 * Math.sin(2 * Math.PI * 440 * time) + (time >= 1 ? 0.025 * Math.sin(2 * Math.PI * 880 * (time - 1)) : 0);
        assert.ok(Math.abs(samples[n] - expected) < 0.003, `seam ${n}: ${samples[n]} vs ${expected}`);
    }
});

test('native loop, trim, offset and envelope match a sample-clock reference across segment boundaries', async () => {
    const compiled = plan([{ id: 'loop', asset_id: 3, start: 0, duration: 2, trimStart: 0.2, trimEnd: 0.6,
        sourceOffset: 0.113, playbackRate: 1.5, loop: true, volume: 0.5, gainPoints: [{ time: 0, value: 0.2 }, { time: 2, value: 1 }] }], 7);
    const { samples, reads } = await collect(compiled, 'loop');
    let squared = 0;
    for (let n = 0; n < samples.length; n++) {
        const time = n / rate, source = 0.2 + (0.113 + time * 1.5) % 0.4;
        const expected = 0.05 * (0.2 + 0.4 * time) * Math.sin(2 * Math.PI * 443 * source);
        squared += (samples[n] - expected) ** 2;
    }
    assert.ok(Math.sqrt(squared / samples.length) < 0.002, `RMS error ${Math.sqrt(squared / samples.length)}`);
    assert.ok(reads.every(window => window.to - window.from <= 2 * 4 + 2 / rate), JSON.stringify(reads));
    // A single neighbour sample permits interpolation; report the actual decode window, not a masked range.
    assert.ok(reads.every(window => window.from >= 0.2 - 1 / rate && window.to <= 0.6 + 2 / rate));
});

for (const [id, codec] of [[5, 'm4a44100'], [6, 'm4a48000'], [7, 'mp344100']]) {
    test(`native ${codec} preserves the continuous PCM sample clock in short and default segments`, async () => {
        const asset = { id, kind: 'audio', status: 'listo', audio_codec: id === 7 ? 'mp3' : 'aac', duration: 6, file: `assets/${id}.bin` };
        const reference = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, asset.file), '-ac', '2', '-ar', '48000', '-f', 'f32le', 'pipe:1'], { timeout: 15000, maxBuffer: 3 * 1024 ** 2 });
        let audioSources;
        for (const options of [{ segmentFrames: 7 }, {}]) {
            const compiled = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30,
                audio_tracks: [{ id: 'tone', asset_id: id, start: 0, duration: 4 }] }, pages: [{ id: 'p', duration: 4, background: '#000000', elements: [] }] }, [asset], options);
            audioSources ??= await prepareAudioSources({ directory, plan: compiled, assets: [asset] });
            for (const segment of compiled.segments) {
                const result = await mixAudioSegment({ directory, plan: compiled, audioSources, segment, name: codec });
                const bytes = await fs.readFile(path.join(directory, result.file)); let squared = 0;
                for (let n = 0; n < segment.sampleCount; n++) squared += (bytes.readFloatLE(44 + n * 8) - reference.readFloatLE((segment.sampleStart + n) * 8)) ** 2;
                assert.ok(Math.sqrt(squared / segment.sampleCount) < 0.0001, `${codec}/${options.segmentFrames ?? 60}/${segment.index}: RMS=${Math.sqrt(squared / segment.sampleCount)}`);
            }
        }
    });
}

test('64 clips sharing one source window read that window only once per segment', async () => {
    const compiled = plan(Array.from({ length: 64 }, (_, i) => ({ id: `same_${i}`, asset_id: 1, start: 0, duration: 2 })), 60), reads = [];
    const audioSources = await prepareAudioSources({ directory, plan: compiled, assets });
    assert.equal(audioSources.length, 1, 'one normalisation for the shared resource');
    await mixAudioSegment({ directory, plan: compiled, audioSources, segment: compiled.segments[0], name: 'shared', onRead: window => reads.push(window) });
    assert.equal(reads.length, 1, `one unique source window caused ${reads.length} reads`);
});

test('normalised PCM profiles reject altered lengths, arbitrary paths and duplicate identities', async () => {
    await fs.copyFile(path.join(directory, 'assets/1.bin'), path.join(directory, 'assets/9.bin'));
    const profile = await prepareAudioSource({ directory, asset: { ...assets[0], id: 9, file: 'assets/9.bin' } });
    assert.equal(profile.frames, 4 * 48000); assert.equal(profile.size, 4 * 48000 * 8);
    const read = pcmWindowReader(directory, [profile]);
    await assert.rejects(pcmWindowReader(directory, [{ ...profile, file: '../outside.pcm' }])(9, 0, 0.1), /perfil/i);
    assert.throws(() => pcmWindowReader(directory, [profile, profile]), /duplicado/i);
    await fs.truncate(path.join(directory, profile.file), profile.size - 8);
    await assert.rejects(read(9, 0, 0.1), /incompleto/i);
});

test('cancelled normalisation leaves no usable PCM or partial file', async () => {
    await fs.copyFile(path.join(directory, 'assets/1.bin'), path.join(directory, 'assets/8.bin'));
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30);
    try { await assert.rejects(prepareAudioSource({ directory, asset: { ...assets[0], id: 8, file: 'assets/8.bin' }, signal: controller.signal })); }
    finally { clearTimeout(timer); }
    await assert.rejects(fs.access(path.join(directory, 'audio-sources/8.pcm')));
    assert.equal((await fs.readdir(path.join(directory, 'audio-sources'))).some(name => name.includes('.part.')), false);
});

test('a truncated compressed stream cannot be padded into a successful normalisation', async () => {
    const file = path.join(directory, 'assets/10.bin');
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=997:sample_rate=48000:duration=6', '-c:a', 'aac',
        '-movflags', '+faststart', '-f', 'ipod', file], { timeout: 15000 });
    const size = (await fs.stat(file)).size; await fs.truncate(file, Math.floor(size * 0.55));
    const asset = { id: 10, file: 'assets/10.bin', kind: 'audio', status: 'listo', audio_codec: 'aac', duration: 6, size: (await fs.stat(file)).size };
    await assert.rejects(prepareAudioSource({ directory, asset }), /proceso|decod|incomplet|corrupt/i);
    await assert.rejects(fs.access(path.join(directory, 'audio-sources/10.pcm')));
    assert.equal((await fs.readdir(path.join(directory, 'audio-sources'))).some(name => name.includes('.part.')), false);
});
