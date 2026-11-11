import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomUUID, createHash } from 'node:crypto';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { renderVisualSegment } from '../../scripts/studio/av-video.mjs';
import { assembleProject } from '../../scripts/studio/av-finalize.mjs';
import { prepareAudioSource, pcmFile } from '../../scripts/studio/av-pcm.mjs';
import { mixAudioSegment } from '../../scripts/studio/av-audio.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory;
before(async () => {
    directory = path.join(base, randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    const wav = Buffer.alloc(44 + 48000 * 8);
    wav.write('RIFF'); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8); wav.writeUInt32LE(16, 16);
    wav.writeUInt16LE(3, 20); wav.writeUInt16LE(2, 22); wav.writeUInt32LE(48000, 24); wav.writeUInt32LE(384000, 28);
    wav.writeUInt16LE(8, 32); wav.writeUInt16LE(32, 34); wav.write('data', 36); wav.writeUInt32LE(wav.length - 44, 40);
    for (let n = 0; n < 48000; n++) for (let c = 0; c < 2; c++) wav.writeFloatLE(0.1 * Math.sin(2 * Math.PI * 440 * n / 48000), 44 + n * 8 + c * 4);
    await fs.writeFile(path.join(directory, 'assets/1.bin'), wav);
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const asset = { id: 1, file: 'assets/1.bin', kind: 'audio', duration: 1, status: 'listo', audio_codec: 'pcm_f32le' };
const plan = color => compileAudiovisual({ schema_version: 2, width: 64, height: 48, timeline: { fps: 12,
    audio_tracks: [{ id: 'music', asset_id: 1, start: 0, duration: 0.25 }] },
    pages: [{ id: 'p', duration: 0.25, background: color, elements: [] }] }, [asset]);
const hash = async file => createHash('sha256').update(await fs.readFile(path.join(directory, file))).digest('hex');

test('a late visual worker cannot overwrite a segment from another lease', async () => {
    const namespace = randomUUID(), late = randomUUID(), compiled = plan('#ff0000');
    const first = await renderVisualSegment({ directory, plan: compiled, segment: compiled.segments[0], namespace });
    const accepted = await hash(first.file), otherPlan = plan('#0000ff');
    const second = await renderVisualSegment({ directory, plan: otherPlan, segment: otherPlan.segments[0], namespace: late });
    assert.equal(first.file, `segments/${namespace}/video-000000.mp4`);
    assert.equal(second.file, `segments/${late}/video-000000.mp4`);
    assert.equal(await hash(first.file), accepted); assert.notEqual(first.sha256, second.sha256);
    await assert.rejects(renderVisualSegment({ directory, plan: otherPlan, segment: otherPlan.segments[0], namespace }), /exist|publicad|checkpoint/i);
    assert.equal(await hash(first.file), accepted);
});

test('PCM and mixed audio checkpoints keep independent lease identities and reject forged paths', async () => {
    const namespace = randomUUID(), late = randomUUID(), compiled = plan('#ff0000');
    const a = await prepareAudioSource({ directory, asset, namespace });
    const b = await prepareAudioSource({ directory, asset, namespace: late });
    assert.equal(a.file, `audio-sources/${namespace}/1.pcm`); assert.notEqual(a.file, b.file);
    assert.equal(await hash(a.file), a.sha256); assert.equal(await hash(b.file), b.sha256);
    assert.equal(await pcmFile(directory, a), path.join(directory, a.file));
    await assert.rejects(pcmFile(directory, { ...a, file: b.file }), /perfil/i);
    const first = await mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [a], namespace });
    const second = await mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [a], namespace: late });
    assert.equal(first.file, `segments/${namespace}/audio-000000.wav`); assert.notEqual(first.file, second.file);
    assert.equal(first.sha256, second.sha256);
    await assert.rejects(mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [a], namespace }), /exist|publicad|checkpoint/i);
});

test('assembly accepts selected checkpoints from different leases and publishes an immutable final generation', async () => {
    const compiled = plan('#00ff00'), visual = randomUUID(), audio = randomUUID(), final = randomUUID();
    const video = await renderVisualSegment({ directory, plan: compiled, segment: compiled.segments[0], namespace: visual });
    const profile = await prepareAudioSource({ directory, asset, namespace: randomUUID() });
    const mixed = await mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [profile], namespace: audio });
    const segments = [{ video, audio: mixed }];
    const result = await assembleProject({ directory, plan: compiled, segments, format: 'mp4', namespace: final });
    assert.equal(result.file, `outputs/${final}/project.mp4`); assert.equal(result.audioCodec, 'aac');
    assert.equal(await hash(result.file), result.sha256);
    await assert.rejects(assembleProject({ directory, plan: compiled, segments, format: 'mp4', namespace: final }), /exist|publicad|checkpoint/i);
    await assert.rejects(assembleProject({ directory, plan: compiled, segments: [{ video: { ...video, namespace: audio }, audio: mixed }], format: 'mp4', namespace: randomUUID() }), /manifiesto/i);
    assert.equal(await hash(result.file), result.sha256);
});

test('namespace validation rejects paths, URLs, empty values and malformed UUIDs before heavy work', async () => {
    const compiled = plan('#ff0000');
    for (const namespace of ['', '../escape', 'assets', 'https://example.com', 5, 'a'.repeat(36)]) {
        await assert.rejects(renderVisualSegment({ directory, plan: compiled, segment: compiled.segments[0], namespace }), /namespace|lease/i);
        await assert.rejects(prepareAudioSource({ directory, asset, namespace }), /namespace|lease/i);
        await assert.rejects(mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [], namespace }), /namespace|lease/i);
        await assert.rejects(assembleProject({ directory, plan: compiled, segments: [], format: 'mp4', namespace }), /namespace|lease/i);
    }
});

test('assembly concat controls remain attributable to their lease after an interrupted process', async () => {
    const compiled = plan('#0055ff'), namespace = randomUUID();
    const video = await renderVisualSegment({ directory, plan: compiled, segment: compiled.segments[0], namespace: randomUUID() });
    const profile = await prepareAudioSource({ directory, asset, namespace: randomUUID() });
    const audio = await mixAudioSegment({ directory, plan: compiled, segment: compiled.segments[0], audioSources: [profile], namespace: randomUUID() });
    const writeFile = fs.writeFile, written = [];
    fs.writeFile = async function (file, ...args) {
        if (/\.(?:video|audio)\.txt$/.test(String(file))) written.push(path.basename(file));
        return writeFile.call(this, file, ...args);
    };
    try { await assembleProject({ directory, plan: compiled, segments: [{ video, audio }], format: 'mp4', namespace }); }
    finally { fs.writeFile = writeFile; }
    assert.equal(written.length, 2);
    for (const name of written) assert.ok(name.startsWith(namespace + '-'), `Untraceable concat control: ${name}`);
});

test('a different lease cleaning its temporary during PCM accounting cannot abort valid normalisation', async () => {
    const other = randomUUID(), current = randomUUID();
    const folder = path.join(directory, 'audio-sources', other), temporary = path.join(folder, `${randomUUID()}.part.pcm`);
    await fs.mkdir(folder, { recursive: true }); await fs.writeFile(temporary, Buffer.alloc(128));
    const readdir = fs.readdir; let cleaned = false;
    fs.readdir = async function (file, ...options) {
        const entries = await readdir.call(this, file, ...options);
        if (file === folder) { await fs.rm(temporary); cleaned = true; }
        return entries;
    };
    try {
        const result = await prepareAudioSource({ directory, asset, namespace: current });
        assert.equal(cleaned, true); assert.equal(result.frames, 48000);
        assert.equal(await hash(result.file), result.sha256);
    } finally { fs.readdir = readdir; }
});
