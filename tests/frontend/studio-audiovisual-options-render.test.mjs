import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { newElement } from '../../resources/js/Studio/document.js';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { renderVisualSegment } from '../../scripts/studio/av-video.mjs';
import { assembleProject } from '../../scripts/studio/av-finalize.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory, assets;
before(async () => {
    directory = path.join(base, crypto.randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    const file = path.join(directory, 'assets/1.bin');
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=12:duration=1', '-c:v', 'libx264', '-threads', '1',
        '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4', file], { timeout: 15000 });
    assets = [{ id: 1, file: 'assets/1.bin', mime: 'video/mp4', kind: 'video', duration: 1, status: 'listo', audio_codec: null, size: (await fs.stat(file)).size }];
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const page = (id, background) => ({ id, background, duration: 0.25, elements: [] });
const doc = pages => ({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12, audio_tracks: [] }, pages });
const decode = file => execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-fps_mode', 'passthrough', '-an', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 15000, maxBuffer: 4 * 1024 ** 2 });

test('native selected page export applies scale to the real pixels and keeps explicit order', async () => {
    const first = page('a', '#ff0000'); first.elements = [newElement('rect', { id: 'shape', x: 20, y: 20, width: 20, height: 20, fill: '#00ff00' })];
    const input = doc([first, page('b', '#00ff00'), page('c', '#0000ff')]), before = JSON.stringify(input);
    const plan = compileAudiovisual(input, [], { pageIds: ['c', 'a'], scale: 0.5 });
    const video = await renderVisualSegment({ directory, plan, segment: plan.segments[0] });
    assert.equal(video.width, 50); assert.equal(video.height, 50);
    const raw = decode(path.join(directory, video.file)); assert.equal(raw.length, 6 * 50 * 50 * 3);
    const pixel = frame => Array.from(raw.subarray(frame * 7500, frame * 7500 + 3));
    assert.ok(pixel(0)[2] > 230 && pixel(0)[0] < 20, JSON.stringify(pixel(0)));
    assert.ok(pixel(3)[0] > 230 && pixel(3)[2] < 20, JSON.stringify(pixel(3)));
    const inside = Array.from(raw.subarray(3 * 7500 + (15 * 50 + 15) * 3, 3 * 7500 + (15 * 50 + 15) * 3 + 3));
    assert.ok(inside[1] > 230 && inside[0] < 20, `scaled vector geometry: ${inside}`);
    assert.equal(JSON.stringify(input), before);
});

test('native quality changes H264 size and the real GIF colour palette', async () => {
    const input = doc([{ ...page('p', '#ffffff'), duration: 0.5, elements: [newElement('video', { id: 'v', asset_id: 1, x: 0, y: 0, width: 64, height: 64, muted: true })] }]);
    const outputs = {};
    for (const quality of [1, 0.1]) for (const format of ['mp4', 'gif']) {
        const plan = compileAudiovisual(input, assets, { quality });
        const result = await renderVisualSegment({ directory, plan, assets, segment: plan.segments[0], format });
        const raw = decode(path.join(directory, result.file));
        const colours = new Set(); for (let offset = 0; offset < 30000; offset += 3) colours.add(raw.subarray(offset, offset + 3).toString('hex'));
        outputs[`${quality}/${format}`] = { result, colours: colours.size };
    }
    assert.ok(outputs['0.1/mp4'].result.size < outputs['1/mp4'].result.size, JSON.stringify(outputs));
    assert.ok(outputs['0.1/gif'].colours <= 16, JSON.stringify(outputs));
    assert.ok(outputs['1/gif'].colours > outputs['0.1/gif'].colours + 32, JSON.stringify(outputs));
});

test('native assembly rejects segments made with different render options', async () => {
    const input = doc([page('a', '#ff0000'), page('b', '#0000ff')]);
    const high = compileAudiovisual(input, [], { quality: 1, segmentFrames: 3 }), low = compileAudiovisual(input, [], { quality: 0.1, segmentFrames: 3 });
    const first = await renderVisualSegment({ directory, plan: high, segment: high.segments[0] });
    const second = await renderVisualSegment({ directory, plan: low, segment: low.segments[1] });
    await assert.rejects(assembleProject({ directory, plan: high, segments: [{ video: first }, { video: second }], format: 'mp4' }), /opciones|calidad|escala/i);
});

test('native scaled slide transitions translate in scaled coordinates', async () => {
    const input = { ...doc([{ ...page('a', '#ff0000'), duration: 1, transition: { type: 'slide_left', duration: 0.5 } },
        { ...page('b', '#0000ff'), duration: 1 }]), width: 200 };
    const plan = compileAudiovisual(input, [], { scale: 0.5 });
    const result = await renderVisualSegment({ directory, plan, segment: plan.segments[0] });
    const raw = decode(path.join(directory, result.file)); assert.equal(raw.length, 18 * 100 * 50 * 3);
    const pixel = x => Array.from(raw.subarray(9 * 15000 + (25 * 100 + x) * 3, 9 * 15000 + (25 * 100 + x) * 3 + 3));
    assert.ok(pixel(25)[0] > 230 && pixel(25)[2] < 20, JSON.stringify(pixel(25)));
    assert.ok(pixel(75)[2] > 230 && pixel(75)[0] < 20, JSON.stringify(pixel(75)));
});

test('native legacy export holds an exhausted poster without mutating the saved v1 document', async () => {
    const input = { schema_version: 1, width: 100, height: 100, pages: [{ ...page('p', '#ffffff'), elements: [
        newElement('video', { id: 'old', asset_id: 1, x: 0, y: 0, width: 64, height: 64, trimStart: 100 }),
    ] }] }, before = JSON.stringify(input);
    const plan = compileAudiovisual(input, assets), result = await renderVisualSegment({ directory, plan, assets, segment: plan.segments[0] });
    const raw = decode(path.join(directory, result.file)); assert.equal(raw.length, 8 * 30000);
    let squared = 0;
    for (let i = 0; i < 30000; i++) squared += (raw[i] - raw[7 * 30000 + i]) ** 2;
    assert.ok(Math.sqrt(squared / 30000) < 2, 'the held source poster must not advance');
    assert.equal(plan.audio.length, 0); assert.equal(JSON.stringify(input), before);
});
