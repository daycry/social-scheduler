import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomUUID, createHash } from 'node:crypto';
import * as finalize from '../../scripts/studio/av-finalize.mjs';
import { compileAudiovisual } from '../../resources/js/Studio/audiovisual-plan.js';

const digest = value => createHash('sha256').update(value).digest('hex');

test('final output pins SHA256 for the whole file and each fixed 16MiB transfer part', async () => {
    const folder = path.resolve('storage/framework/testing/integrity-' + randomUUID());
    await fs.mkdir(folder, { recursive: true });
    const first = Buffer.alloc(16 * 1024 ** 2, 0x37), last = Buffer.from('tail');
    const file = path.join(folder, 'output.bin');
    try {
        await fs.writeFile(file, Buffer.concat([first, last]));
        const result = await finalize.outputIntegrity(file);
        assert.equal(result.sha256, digest(Buffer.concat([first, last])));
        assert.equal(result.chunk_size, 16 * 1024 ** 2);
        assert.deepEqual(result.chunk_sha256, [digest(first), digest(last)]);
    } finally { await fs.rm(folder, { recursive: true, force: true }); }
});

test('final output hashing respects cancellation and refuses files beyond its byte budget', async () => {
    const folder = path.resolve('storage/framework/testing/integrity-' + randomUUID());
    await fs.mkdir(folder, { recursive: true });
    const file = path.join(folder, 'output.bin');
    try {
        await fs.writeFile(file, 'too large');
        await assert.rejects(finalize.outputIntegrity(file, { signal: AbortSignal.abort() }), { name: 'AbortError' });
        await assert.rejects(finalize.outputIntegrity(file, { maxBytes: 4 }), /presupuesto/i);
    } finally { await fs.rm(folder, { recursive: true, force: true }); }
});

test('assembly still checks the segment hash before starting native finalization', async () => {
    const base = path.resolve('storage/app/private/studio-exports/0/0'), folder = path.join(base, randomUUID());
    const namespace = randomUUID(), output = randomUUID(), file = `segments/${namespace}/video-000000.mp4`;
    await fs.mkdir(path.dirname(path.join(folder, file)), { recursive: true });
    try {
        await fs.writeFile(path.join(folder, file), 'corrupt');
        const plan = compileAudiovisual({ schema_version: 2, width: 64, height: 48, timeline: { fps: 12, audio_tracks: [] },
            pages: [{ id: 'one', duration: 0.25, background: '#ffffff', elements: [] }] }, []);
        const video = { namespace, file, size: 7, sha256: digest('trusted'), frameStart: 0, frameCount: 3, fps: 12,
            width: 64, height: 48, scale: 1, quality: 0.94 };
        await assert.rejects(finalize.assembleProject({ directory: folder, plan, segments: [{ video }], format: 'mp4', namespace: output }), /tramo cambió|incompleto/i);
    } finally {
        assert.equal(path.dirname(folder), base);
        await fs.rm(folder, { recursive: true, force: true });
    }
});
