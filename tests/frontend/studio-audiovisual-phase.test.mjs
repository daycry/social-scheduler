import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync, spawn } from 'node:child_process';
import { randomUUID, createHash } from 'node:crypto';

const base = path.resolve('storage/app/private/studio-exports/0/0'), python = process.env.STUDIO_TEST_PYTHON ?? 'python';
let directory;
before(async () => { directory = path.join(base, randomUUID()); await fs.mkdir(path.join(directory, 'work'), { recursive: true }); });
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const manifest = () => ({ document: { schema_version: 2, width: 64, height: 48, timeline: { fps: 12, audio_tracks: [] },
    pages: [{ id: 'a', duration: 0.25, background: '#ff0000', elements: [] }, { id: 'b', duration: 0.25, background: '#0000ff', elements: [] }] },
    assets: [], fonts: [], options: { pageIds: ['b', 'a'], scale: 2, quality: 1 }, format: 'mp4', audioSources: [], segments: [] });
async function request(input, phase, index = 0, options = {}) {
    const namespace = randomUUID(); await fs.writeFile(path.join(directory, 'work', `${namespace}.json`), JSON.stringify(input), { flag: 'wx' });
    let raw;
    try { raw = execFileSync(python, ['scripts/studio/av-runtime.py', directory, namespace, phase, String(index)], { encoding: 'utf8', timeout: 15000, windowsHide: true, ...options }); }
    catch (error) { raw = error.stdout; }
    return { namespace, result: JSON.parse(raw) };
}

test('managed phase runner compiles, renders and joins immutable lease outputs under the OS group bound', { skip: process.platform !== 'win32' }, async () => {
    const input = manifest();
    const planned = await request(input, 'plan');
    assert.equal(planned.result.ok, true, JSON.stringify(planned.result));
    assert.equal(planned.result.result.frameCount, 6); assert.equal(planned.result.result.render.width, 128);
    assert.deepEqual(planned.result.result.selection, ['b', 'a']);
    assert.ok(planned.result.memory.limit > 0 && planned.result.memory.limit <= 3 * 1024 ** 3);
    const visual = await request(input, 'render');
    assert.equal(visual.result.ok, true, JSON.stringify(visual.result));
    input.segments = [{ video: visual.result.result }];
    const final = await request(input, 'assemble');
    assert.equal(final.result.ok, true, JSON.stringify(final.result));
    const result = final.result.result;
    assert.equal(result.file, `outputs/${final.namespace}/project.mp4`);
    assert.equal(result.frameCount, 6); assert.equal(result.width, 128); assert.equal(result.duration, 0.5);
    const bytes = await fs.readFile(path.join(directory, result.file));
    assert.equal(createHash('sha256').update(bytes).digest('hex'), result.sha256);
    const decoded = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, result.file), '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
    const first = [...decoded.subarray(0, 3)], last = [...decoded.subarray(decoded.length - 3)];
    assert.ok(first[2] > 230 && first[0] < 25); assert.ok(last[0] > 230 && last[2] < 25);
});

test('managed runner applies one deadline including startup and publishes no checkpoint on expiry', { skip: process.platform !== 'win32' }, async () => {
    const before = Date.now(), input = manifest();
    const response = await request(input, 'render', 0, { env: { ...process.env, STUDIO_AV_BUDGET_MS: '100' } });
    assert.equal(response.result.ok, false); assert.equal(response.result.error_code, 'phase_timeout');
    assert.ok(Date.now() - before < 6500);
    await assert.rejects(fs.access(path.join(directory, 'segments', response.namespace, 'video-000000.mp4')));
});

test('managed runner observes per-lease cancellation before native work and keeps errors private', { skip: process.platform !== 'win32' }, async () => {
    const namespace = randomUUID(); await fs.writeFile(path.join(directory, 'work', `${namespace}.json`), JSON.stringify(manifest()));
    await fs.writeFile(path.join(directory, 'work', `${namespace}.cancel`), '1');
    let output;
    try { output = execFileSync(python, ['scripts/studio/av-runtime.py', directory, namespace, 'render', '0'], { encoding: 'utf8', timeout: 10000, windowsHide: true }); }
    catch (error) { output = error.stdout; }
    const result = JSON.parse(output);
    assert.equal(result.ok, false); assert.equal(result.error_code, 'phase_cancelled');
    assert.equal(output.includes(directory), false); assert.equal(output.includes('stack'), false);
    await assert.rejects(fs.access(path.join(directory, 'segments', namespace, 'video-000000.mp4')));
});

test('managed GIF skips music snapshots while still rendering selected visual pages', { skip: process.platform !== 'win32' }, async () => {
    const input = manifest(); input.format = 'gif';
    input.document.timeline.audio_tracks = [{ id: 'song', asset_id: 1, start: 0, duration: 0.5 }];
    input.assets = [{ id: 1, kind: 'audio', duration: 1, audio_codec: 'pcm_f32le', status: 'listo', mime: 'audio/wav', file: 'assets/1.bin' }];
    const plan = await request(input, 'plan');
    assert.equal(plan.result.ok, true, JSON.stringify(plan.result));
    assert.deepEqual(plan.result.result.audioAssetIds, []); assert.deepEqual(plan.result.result.assetIds, []);
    const video = await request(input, 'render'); assert.equal(video.result.ok, true, JSON.stringify(video.result));
    input.segments = [{ video: video.result.result }];
    const joined = await request(input, 'assemble'); assert.equal(joined.result.ok, true, JSON.stringify(joined.result));
    assert.equal(joined.result.result.audioCodec, null); assert.equal(joined.result.result.frameCount, 6);
});

test('managed normalization and mix checkpoints carry actual sound into the final AAC', { skip: process.platform !== 'win32' }, async () => {
    const input = manifest(); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1',
        '-af', 'volume=0.8', '-c:a', 'pcm_f32le', '-ac', '2', '-f', 'wav', path.join(directory, 'assets/1.bin')]);
    input.document.timeline.audio_tracks = [{ id: 'song', asset_id: 1, start: 0, duration: 0.5 }];
    input.assets = [{ id: 1, kind: 'audio', duration: 1, audio_codec: 'pcm_f32le', status: 'listo', mime: 'audio/wav', file: 'assets/1.bin' }];
    const normal = await request(input, 'normalize', 1);
    assert.equal(normal.result.ok, true, JSON.stringify(normal.result)); input.audioSources = [normal.result.result];
    const mixed = await request(input, 'mix'); assert.equal(mixed.result.ok, true, JSON.stringify(mixed.result));
    const visual = await request(input, 'render'); assert.equal(visual.result.ok, true, JSON.stringify(visual.result));
    input.segments = [{ video: visual.result.result, audio: mixed.result.result }];
    const joined = await request(input, 'assemble'); assert.equal(joined.result.ok, true, JSON.stringify(joined.result));
    assert.equal(joined.result.result.audioCodec, 'aac');
    const sound = execFileSync('ffmpeg', ['-v', 'error', '-i', path.join(directory, joined.result.result.file), '-map', '0:a', '-f', 'f32le', '-ac', '1', '-ar', '48000', 'pipe:1']);
    let sum = 0;
    for (let n = 4800; n < 19200; n++) sum += sound.readFloatLE(n * 4) ** 2;
    const rms = Math.sqrt(sum / 14400); assert.ok(rms > 0.04 && rms < 0.09, `AAC RMS ${rms}`);
    assert.ok(joined.result.memory.peak_commit > 0 && joined.result.memory.peak_commit <= 3 * 1024 ** 3);
});

test('cancelling after a managed visual phase starts leaves no published or partial output', { skip: process.platform !== 'win32' }, async () => {
    const input = manifest(), namespace = randomUUID();
    input.document.width = 640; input.document.height = 360; input.options.scale = 1;
    input.document.timeline.fps = 60; input.document.pages.forEach(page => { page.duration = 2; });
    await fs.writeFile(path.join(directory, 'work', `${namespace}.json`), JSON.stringify(input));
    const child = spawn(python, ['scripts/studio/av-runtime.py', directory, namespace, 'render', '0'], { windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '', stderr = ''; child.stdout.on('data', bytes => { stdout += bytes; }); child.stderr.on('data', bytes => { stderr += bytes; });
    const completion = new Promise((resolve, reject) => { child.on('error', reject); child.on('close', code => resolve(code)); });
    const folder = path.join(directory, 'segments', namespace);
    let observed = false;
    try {
        for (let n = 0; n < 200 && child.exitCode === null; n++) {
            // FFmpeg can buffer PNG probing until EOF; its output file is not a stable start marker.
            // The per-lease folder is created only after dispatch enters renderVisualSegment.
            if (await fs.stat(folder).then(stat => stat.isDirectory(), () => false)) { observed = true; break; }
            await new Promise(resolve => setTimeout(resolve, 25));
        }
        assert.equal(observed, true, `did not observe a running segment: ${stdout} ${stderr}`);
        await new Promise(resolve => setTimeout(resolve, 200));
        await fs.writeFile(path.join(directory, 'work', `${namespace}.cancel`), '1');
        const code = await completion; assert.notEqual(code, 0);
        const response = JSON.parse(stdout); assert.equal(response.error_code, 'phase_cancelled');
        assert.deepEqual(await fs.readdir(folder), []);
    } finally { if (child.exitCode === null) { child.kill(); await completion; } }
});

test('normalization accepts media IDs above six digits and rejects unsafe or noninteger indices', { skip: process.platform !== 'win32' }, async () => {
    const input = manifest(), id = 1000000;
    await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1', '-c:a', 'pcm_f32le', '-ac', '2', '-f', 'wav', path.join(directory, `assets/${id}.bin`)]);
    input.document.timeline.audio_tracks = [{ id: 'song', asset_id: id, start: 0, duration: 0.5 }];
    input.assets = [{ id, kind: 'audio', duration: 1, audio_codec: 'pcm_f32le', status: 'listo', mime: 'audio/wav', file: `assets/${id}.bin` }];
    const response = await request(input, 'normalize', id);
    assert.equal(response.result.ok, true, JSON.stringify(response.result));
    assert.equal(response.result.result.id, id); assert.equal(response.result.result.frames, 48000);
    for (const value of ['-1', 'x', '9007199254740992', '1e6', '1.5']) {
        const rejected = await request(input, 'normalize', value);
        assert.equal(rejected.result.error_code, 'invalid_request', `${value}: ${JSON.stringify(rejected.result)}`);
    }
});
