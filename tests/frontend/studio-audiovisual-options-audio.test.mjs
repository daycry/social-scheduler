import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { prepareAudioSources } from '../../scripts/studio/av-pcm.mjs';
import { mixAudioSegment } from '../../scripts/studio/av-audio.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory, source;
const asset = { id: 1, file: 'assets/1.bin', kind: 'audio', status: 'listo', duration: 4, audio_codec: 'pcm_f32le' };
before(async () => {
    directory = path.join(base, crypto.randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    source = Buffer.alloc(44 + 4 * 48000 * 8); source.write('RIFF'); source.writeUInt32LE(source.length - 8, 4); source.write('WAVEfmt ', 8);
    source.writeUInt32LE(16, 16); source.writeUInt16LE(3, 20); source.writeUInt16LE(2, 22); source.writeUInt32LE(48000, 24);
    source.writeUInt32LE(384000, 28); source.writeUInt16LE(8, 32); source.writeUInt16LE(32, 34); source.write('data', 36); source.writeUInt32LE(source.length - 44, 40);
    for (let n = 0; n < 4 * 48000; n++) { const time = n / 48000, value = 0.08 * Math.sin(2 * Math.PI * (300 * time + 50 * time * time));
        source.writeFloatLE(value, 44 + n * 8); source.writeFloatLE(value, 48 + n * 8); }
    await fs.writeFile(path.join(directory, asset.file), source);
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const document = () => ({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12,
    audio_tracks: [{ id: 'music', asset_id: 1, start: 0, duration: 2.75 }] }, pages: [
    { id: 'a', duration: 1, background: '#ff0000', transition: { type: 'fade', duration: 0.25 }, elements: [] },
    { id: 'b', duration: 1, background: '#00ff00', elements: [] },
    { id: 'c', duration: 1, background: '#0000ff', elements: [] },
] });
const at = seconds => source.readFloatLE(44 + Math.round(seconds * 48000) * 8);

for (const pageIds of [['c', 'a'], ['a', 'c']]) test(`native selected music matches its original source and join envelope: ${pageIds.join(',')}`, async () => {
    const input = document(), before = JSON.stringify(input), plan = compileAudiovisual(input, [asset], { pageIds, segmentFrames: 5 });
    const audioSources = await prepareAudioSources({ directory, plan, assets: [asset] });
    let squared = 0, count = 0;
    for (const segment of plan.segments) {
        const result = await mixAudioSegment({ directory, plan, audioSources, segment }); const bytes = await fs.readFile(path.join(directory, result.file));
        for (let n = 0; n < segment.sampleCount; n++) {
            const time = (segment.sampleStart + n) / 48000;
            let expected;
            if (pageIds[0] === 'c') expected = time < 1 ? at(1.75 + time) : at(time - 1);
            else if (time < 0.75) expected = at(time);
            else if (time < 1) expected = at(time) * (1 - time) / 0.25 + at(time + 1) * (time - 0.75) / 0.25;
            else expected = at(time + 1);
            squared += (bytes.readFloatLE(44 + n * 8) - expected) ** 2; count++;
        }
    }
    assert.equal(count, Math.round(plan.duration * 48000));
    assert.ok(Math.sqrt(squared / count) < 1e-7, `selected source-clock RMS=${Math.sqrt(squared / count)}`);
    assert.equal(JSON.stringify(input), before);
});
