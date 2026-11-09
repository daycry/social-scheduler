import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { newElement } from '../../resources/js/Studio/document.js';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { renderVisualSegment } from '../../scripts/studio/av-video.mjs';
import { mixAudioSegment } from '../../scripts/studio/av-audio.mjs';
import { assembleProject } from '../../scripts/studio/av-finalize.mjs';
import { prepareAudioSources } from '../../scripts/studio/av-pcm.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory, assets;
before(async () => {
    directory = path.join(base, crypto.randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    const source = path.join(directory, 'assets/1.bin');
    execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-nostdin', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=12:duration=2',
        '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
        '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-movflags', '+faststart', '-f', 'mp4', source], { timeout: 20000 });
    assets = [{ id: 1, file: 'assets/1.bin', kind: 'video', status: 'listo', mime: 'video/mp4', duration: 2, audio_codec: 'aac', width: 64, height: 64, size: (await fs.stat(source)).size }];
});

test('native MP4 assembly preserves all frames, AAC sound, duration and faststart after joining segments', { timeout: 90000 }, async () => {
    const plan = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12,
        audio_tracks: [{ id: 'music', asset_id: 1, start: 0, duration: 0.5, volume: 0.5 }] },
        pages: [{ id: 'p', duration: 0.5, background: '#ff0000', elements: [] }] }, assets, { segmentFrames: 3 });
    const segments = [];
    const audioSources = await prepareAudioSources({ directory, plan, assets });
    for (const segment of plan.segments) segments.push({ video: await renderVisualSegment({ directory, plan, assets, segment }),
        audio: await mixAudioSegment({ directory, plan, audioSources, segment }) });
    const reference = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, 'assets/1.bin'), '-t', '0.5', '-vn', '-ac', '2', '-ar', '48000', '-f', 'f32le', 'pipe:1'], { timeout: 15000 });
    for (const [index, result] of segments.entries()) {
        const bytes = await fs.readFile(path.join(directory, result.audio.file)), segment = plan.segments[index]; let squared = 0;
        for (let n = 0; n < segment.sampleCount; n++) squared += (bytes.readFloatLE(44 + n * 8) - reference.readFloatLE((n + segment.sampleStart) * 8) * 0.5) ** 2;
        assert.ok(Math.sqrt(squared / segment.sampleCount) < 0.002, `AAC source phase drift in segment ${index}: RMS=${Math.sqrt(squared / segment.sampleCount)}`);
    }
    const result = await assembleProject({ directory, plan, segments, format: 'mp4' });
    const bytes = await fs.readFile(path.join(directory, result.file));
    assert.ok(bytes.indexOf(Buffer.from('moov')) > 0 && bytes.indexOf(Buffer.from('moov')) < bytes.indexOf(Buffer.from('mdat')), 'MP4 must support progressive playback');
    assert.equal(result.frameCount, 6); assert.equal(result.width, 100); assert.equal(result.height, 100);
    assert.equal(result.videoCodec, 'h264'); assert.equal(result.audioCodec, 'aac');
    assert.ok(Math.abs(result.duration - 0.5) <= 1 / 12);
    const pcm = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, result.file), '-vn', '-ac', '1', '-ar', '48000', '-f', 'f32le', 'pipe:1'], { timeout: 15000, maxBuffer: 1024 ** 2 });
    let real = 0, imag = 0;
    for (let n = 4800; n < 19200; n++) { const value = pcm.readFloatLE(n * 4); real += value * Math.cos(2 * Math.PI * 440 * n / 48000); imag += value * Math.sin(2 * Math.PI * 440 * n / 48000); }
    const amplitude = Math.hypot(real, imag) * 2 / 14400;
    assert.ok(amplitude > 0.03, `actual AAC output must contain the source tone: amplitude=${amplitude}, samples=${pcm.length / 4}`);
});

test('native GIF segments retain palettes and use accumulated centisecond delays when joined', { timeout: 90000 }, async () => {
    const plan = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 30, audio_tracks: [] }, pages: [
        { id: 'red', duration: 0.5, background: '#ff0000', elements: [] },
        { id: 'blue', duration: 0.5, background: '#0000ff', elements: [] },
    ] }, assets, { segmentFrames: 7 });
    const segments = [];
    for (const segment of plan.segments) {
        const video = await renderVisualSegment({ directory, plan, assets, segment, format: 'gif' });
        assert.match(video.file, /\.gif$/); segments.push({ video });
    }
    const result = await assembleProject({ directory, plan, segments, format: 'gif' });
    assert.equal(result.videoCodec, 'gif'); assert.equal(result.audioCodec, null); assert.equal(result.frameCount, 30);
    assert.ok(Math.abs(result.duration - 1) <= 0.01, JSON.stringify(result));
    const file = path.join(directory, result.file), bytes = await fs.readFile(file);
    assert.equal(bytes.subarray(0, 6).toString(), 'GIF89a');
    assert.equal(bytes.indexOf(Buffer.from('GIF89a'), 6), -1, 'joining must not concatenate several complete GIF files');
    const raw = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-fps_mode', 'passthrough', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 15000, maxBuffer: 4 * 1024 ** 2 });
    assert.equal(raw.length, 30 * 30000);
    for (const n of [0, 14, 15, 29]) { const pixel = Array.from(raw.subarray(n * 30000, n * 30000 + 3));
        assert.ok(n < 15 ? pixel[0] > 230 && pixel[2] < 20 : pixel[2] > 230 && pixel[0] < 20, `${n}: ${pixel}`); }
});

test('native page audio fades on the shared project clock and includes the original video soundtrack', async () => {
    const layer = (id, volume) => newElement('video', { id, asset_id: 1, volume });
    const plan = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12, audio_tracks: [] }, pages: [
        { id: 'a', duration: 1, background: '#ffffff', transition: { type: 'slide_left', duration: 0.5 }, elements: [layer('one', 1)] },
        { id: 'b', duration: 1, background: '#ffffff', elements: [layer('two', 0.5)] },
    ] }, assets, { segmentFrames: 3 });
    const reference = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, 'assets/1.bin'), '-vn', '-ac', '2', '-ar', '48000', '-f', 'f32le', 'pipe:1'], { timeout: 15000 });
    let squared = 0, samples = 0;
    const audioSources = await prepareAudioSources({ directory, plan, assets });
    for (const segment of plan.segments) {
        const result = await mixAudioSegment({ directory, plan, audioSources, segment, name: 'pagefade' });
        const bytes = await fs.readFile(path.join(directory, result.file));
        for (let n = 0; n < segment.sampleCount; n++) {
            const global = n + segment.sampleStart, time = global / 48000;
            let expected = 0;
            if (time < 1) expected += reference.readFloatLE(global * 8) * (time < 0.5 ? 1 : (1 - time) / 0.5);
            if (time >= 0.5) expected += reference.readFloatLE((global - 24000) * 8) * 0.5 * (time < 1 ? (time - 0.5) / 0.5 : 1);
            squared += (bytes.readFloatLE(44 + n * 8) - expected) ** 2; samples++;
        }
    }
    assert.equal(samples, 72000); assert.ok(Math.sqrt(squared / samples) < 0.002, `page crossfade RMS=${Math.sqrt(squared / samples)}`);
});

test('a video soundtrack shorter than its visual stream becomes silence after audio EOF', async () => {
    const file = path.join(directory, 'assets/2.bin');
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=12:duration=2', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=96000:duration=0.5',
        '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-c:a', 'aac', '-f', 'mp4', file], { timeout: 15000 });
    const source = { ...assets[0], id: 2, file: 'assets/2.bin', duration: 2 };
    delete source.size;
    const plan = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12, audio_tracks: [] },
        pages: [{ id: 'p', duration: 2, background: '#000000', elements: [newElement('video', { id: 'short_audio', asset_id: 2 })] }] }, [source]);
    const audioSources = await prepareAudioSources({ directory, plan, assets: [source] });
    const result = await mixAudioSegment({ directory, plan, audioSources, segment: plan.segments[0], name: 'shortaudio' });
    const bytes = await fs.readFile(path.join(directory, result.file)); let energy = 0, tail = 0;
    for (let n = 4800; n < 19200; n++) energy += bytes.readFloatLE(44 + n * 8) ** 2;
    for (let n = 48000; n < 96000; n++) tail = Math.max(tail, Math.abs(bytes.readFloatLE(44 + n * 8)));
    assert.ok(Math.sqrt(energy / 14400) > 0.01); assert.ok(tail < 1e-6, `audio EOF peak=${tail}`);
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });

test('native visual segments render moving video, timed overlays and a page transition on the project frame clock', { timeout: 90000 }, async () => {
    const plan = compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12, audio_tracks: [] }, pages: [
        { id: 'red', background: '#ff0000', duration: 1, transition: { type: 'fade', duration: 0.5 }, elements: [
            newElement('video', { id: 'motion', asset_id: 1, x: 40, y: 40, width: 60, height: 60, muted: true, trimStart: 0.1, trimEnd: 1.5, playbackRate: 1.5 }),
            newElement('rect', { id: 'overlay', x: 0, y: 0, width: 30, height: 30, fill: '#00ff00', timing: { start: 0.25, duration: 0.5 } }),
        ] }, { id: 'blue', background: '#0000ff', duration: 1, elements: [] },
    ] }, assets, { segmentFrames: 6 });
    const before = JSON.stringify(plan.document), frames = [], summaries = [];
    for (const segment of plan.segments) {
        const result = await renderVisualSegment({ directory, plan, assets, segment }); summaries.push(result);
        const bytes = execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-i', path.join(directory, result.file), '-an', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1'], { timeout: 15000, maxBuffer: 4 * 1024 ** 2 });
        assert.equal(bytes.length, 100 * 100 * 3 * segment.frameCount);
        for (let frame = 0; frame < segment.frameCount; frame++) frames.push(bytes.subarray(frame * 30000, (frame + 1) * 30000));
    }
    assert.equal(frames.length, 18); assert.equal(JSON.stringify(plan.document), before);
    const pixel = (frame, x = 10, y = 10) => Array.from(frames[frame].subarray((y * 100 + x) * 3, (y * 100 + x) * 3 + 3));
    assert.ok(pixel(0)[0] > 230 && pixel(0)[2] < 20, JSON.stringify(pixel(0)));
    assert.ok(pixel(3)[1] > 230 && pixel(3)[0] < 20, JSON.stringify(pixel(3)));
    const middle = pixel(9); assert.ok(middle[0] > 100 && middle[0] < 155 && middle[2] > 100 && middle[2] < 155, JSON.stringify(middle));
    assert.ok(pixel(15)[2] > 230 && pixel(15)[0] < 20, JSON.stringify(pixel(15)));
    assert.notDeepEqual(frames[0].subarray(40 * 100 * 3), frames[3].subarray(40 * 100 * 3), 'real source frames must move');
    assert.ok(summaries.every(result => result.frameCount === 6 && result.fps === 12 && result.width === 100 && result.height === 100));
});
