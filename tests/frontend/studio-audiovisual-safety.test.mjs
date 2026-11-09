import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { openResourceServer } from '../../scripts/studio/av-server.mjs';
import { attemptDirectory, sourceFile, boundedProcess } from '../../scripts/studio/av-files.mjs';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';
import { renderVisualSegment } from '../../scripts/studio/av-video.mjs';
import { assembleProject } from '../../scripts/studio/av-finalize.mjs';

const base = path.resolve('storage/app/private/studio-exports/0/0');
let directory, bytes;
before(async () => {
    directory = path.join(base, crypto.randomUUID()); await fs.mkdir(path.join(directory, 'assets'), { recursive: true });
    bytes = crypto.randomBytes(128000); await fs.writeFile(path.join(directory, 'assets/1.bin'), bytes);
});
after(async () => { assert.equal(path.dirname(directory), base); await fs.rm(directory, { recursive: true, force: true }); });
const asset = { id: 1, file: 'assets/1.bin', kind: 'image', mime: 'image/png', size: 128000, status: 'listo' };
const plan = () => compileAudiovisual({ schema_version: 2, width: 100, height: 100, timeline: { fps: 12, audio_tracks: [] },
    pages: [{ id: 'p', duration: 0.25, background: '#ff0000', elements: [] }] });

test('the private loopback origin serves only its closed map and honours byte ranges', async () => {
    const server = await openResourceServer(directory, [asset]);
    try {
        const url = server.assets[0].studio_url, full = await fetch(url);
        assert.deepEqual(Buffer.from(await full.arrayBuffer()), bytes);
        const range = await fetch(url, { headers: { Range: 'bytes=500-999' } });
        assert.equal(range.status, 206); assert.equal(range.headers.get('content-range'), 'bytes 500-999/128000');
        assert.deepEqual(Buffer.from(await range.arrayBuffer()), bytes.subarray(500, 1000));
        assert.equal((await fetch(url, { method: 'HEAD' })).headers.get('content-length'), '128000');
        for (const value of ['bytes=200000-', 'bytes=9-2', 'bytes=0-1,3-5', 'bytes=9007199254740993-']) {
            assert.equal((await fetch(url, { headers: { Range: value } })).status, 416);
        }
        assert.equal((await fetch(url, { method: 'POST' })).status, 405);
        assert.equal((await fetch(new URL('/assets/1', url))).status, 404);
        assert.equal(server.allows('https://example.com/'), false);
        assert.equal(server.allows(`${url}?different=1`), false);
        assert.equal(server.allows(url), true);
    } finally { await server.close(); }
});

test('native resources reject arbitrary paths, MIME injection, duplicate identities, wrong sizes and unrelated attempt folders', async () => {
    await assert.rejects(attemptDirectory(path.dirname(directory)), /directorio/i);
    for (const file of ['../secret', '/etc/passwd', 'assets/2.bin', 'file:///tmp/file', 'https://example.com/']) {
        await assert.rejects(sourceFile(directory, { ...asset, file }), /snapshot/i);
    }
    await assert.rejects(sourceFile(directory, { ...asset, size: 1 }), /tamañ/i);
    await assert.rejects(openResourceServer(directory, [asset, asset]), /duplicado/i);
    await assert.rejects(openResourceServer(directory, [{ ...asset, mime: 'image/png\r\nX-Foo: injected' }]), /formato/i);
});

test('native processes enforce a bounded output and abort the owned child', async () => {
    await assert.rejects(boundedProcess(process.execPath, ['-e', 'process.stdout.write(Buffer.alloc(65536))'], { maxBytes: 1000 }), /presupuesto/i);
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 30);
    try { await assert.rejects(boundedProcess(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { signal: controller.signal, timeout: 2000 }), error => error.name === 'AbortError'); }
    finally { clearTimeout(timeout); }
});

test('cancelled native render does not publish a segment or leave its part file', async () => {
    const compiled = plan(), controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 30);
    try { await assert.rejects(renderVisualSegment({ directory, plan: compiled, segment: compiled.segments[0], signal: controller.signal })); }
    finally { clearTimeout(timeout); }
    const files = await fs.readdir(path.join(directory, 'segments'));
    assert.deepEqual(files, []);
});

test('final assembly rejects altered, missing and unordered segment manifests before publishing', async () => {
    const compiled = plan(), segment = compiled.segments[0];
    const video = await renderVisualSegment({ directory, plan: compiled, segment });
    for (const item of [{ ...video, sha256: '0'.repeat(64) }, { ...video, frameStart: 4 }, { ...video, file: '../outside.mp4' }]) {
        await assert.rejects(assembleProject({ directory, plan: compiled, segments: [{ video: item }], format: 'mp4' }));
    }
    await assert.rejects(assembleProject({ directory, plan: compiled, segments: [], format: 'mp4' }));
    const files = await fs.readdir(directory);
    assert.equal(files.some(file => /project\.|\.part\.|\.txt$/.test(file)), false);
    assert.ok((await fs.stat(path.join(directory, video.file))).size > 0);
});
