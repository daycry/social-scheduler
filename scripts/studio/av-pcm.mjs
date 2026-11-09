import fs from 'node:fs/promises';
import { createReadStream } from 'node:fs';
import path from 'node:path';
import { createHash, randomUUID } from 'node:crypto';
import { attemptDirectory, sourceFile, boundedProcess, checkedNamespace, checkpointRelative, outputFolder, publishCheckpoint } from './av-files.mjs';

const rate = 48000, frameBytes = 8, limit = 8 * 1024 ** 3;
const same = (a, b) => process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b;

/** A separate checkpointable preparation phase. Never decode a compressed seek as an exact PCM clock. */
export async function prepareAudioSource({ directory, asset, signal, namespace = null }) {
    checkedNamespace(namespace);
    directory = await attemptDirectory(directory); signal?.throwIfAborted();
    if (!['audio', 'video'].includes(asset?.kind) || !Number.isFinite(asset.duration) || asset.duration <= 0) throw new Error('Origen de audio no válido.');
    const frames = Math.round(asset.duration * rate), size = frames * frameBytes;
    if (!Number.isSafeInteger(size) || size < frameBytes || size > limit) throw new Error('El audio normalizado supera 8 GiB.');
    const source = await sourceFile(directory, asset), original = await fs.stat(source);
    const probe = JSON.parse((await boundedProcess(process.env.STUDIO_FFPROBE_BINARY ?? 'ffprobe', ['-v', 'error',
        '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'wav,mov,mp3,ogg,aac', '-show_entries',
        'stream=codec_type,duration:format=duration', '-of', 'json', source], { signal, maxBytes: 65536 })).toString());
    const stream = probe.streams?.find(stream => stream.codec_type === asset.kind);
    const audio = probe.streams?.find(stream => stream.codec_type === 'audio');
    const seconds = Number(stream?.duration ?? probe.format?.duration);
    if (!audio || !Number.isFinite(seconds) || Math.abs(seconds - asset.duration) > 0.1) throw new Error('La duración del origen de audio está incompleta o cambió respecto al recurso.');
    const folder = await outputFolder(directory, 'audio-sources', namespace);
    let used = 0, entries = 0;
    const name = `${asset.id}.pcm`;
    async function count(current, nested = false) {
        let contents;
        try { contents = await fs.readdir(current, { withFileTypes: true }); }
        catch (error) { if (nested && error.code === 'ENOENT') return; throw error; }
        for (const entry of contents) {
            signal?.throwIfAborted();
            if (++entries > 8192 || entry.isSymbolicLink()) throw new Error('El directorio PCM contiene una entrada ajena.');
            const file = path.join(current, entry.name);
            if (entry.isDirectory() && !nested) { checkedNamespace(entry.name); await count(file, true); }
            else if (!entry.isFile()) throw new Error('El directorio PCM contiene una entrada ajena.');
            else if (namespace || file !== path.join(folder, name)) {
                try { used += (await fs.stat(file)).size; }
                catch (error) { if (error.code !== 'ENOENT') throw error; }
            }
        }
    }
    await count(path.join(directory, 'audio-sources'));
    if (used + size > limit) throw new Error('Los intermediarios PCM del intento superan 8 GiB.');
    const temporary = path.join(folder, `${randomUUID()}.part.pcm`), target = path.join(folder, name);
    try {
        // No -ss: resampling and codec priming share the same continuous reference as a full decode.
        // A short video soundtrack ends in real silence; a stale/corrupt media duration failed above.
        await boundedProcess(process.env.STUDIO_FFMPEG_BINARY ?? 'ffmpeg', ['-hide_banner', '-loglevel', 'error', '-nostdin', '-n', '-xerror',
            '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'wav,mov,mp3,ogg,aac', '-err_detect', 'explode', '-i', source,
            '-map', '0:a:0', '-vn', '-sn', '-dn', '-threads', '1', '-map_metadata', '-1', '-ac', '2', '-ar', String(rate),
            '-af', `aresample=${rate},apad=whole_len=${frames}`, '-t', (frames / rate).toFixed(12), '-fs', String(size + frameBytes), '-f', 'f32le', temporary],
        { signal, timeout: 80000, maxBytes: 65536 });
        const after = await fs.stat(source), stat = await fs.stat(temporary);
        if (original.size !== after.size || original.mtimeMs !== after.mtimeMs || stat.size !== size) throw new Error('El origen cambió o la normalización PCM está incompleta.');
        const hash = createHash('sha256');
        for await (const bytes of createReadStream(temporary)) { signal?.throwIfAborted(); hash.update(bytes); }
        signal?.throwIfAborted(); await publishCheckpoint(temporary, target, namespace);
        return { id: asset.id, file: checkpointRelative('audio-sources', name, namespace), namespace, size, frames, sampleRate: rate, channels: 2, codec: 'pcm_f32le',
            sha256: hash.digest('hex'), sourceSize: original.size, sourceMtimeMs: original.mtimeMs };
    } finally { await fs.rm(temporary, { force: true }); }
}

export async function prepareAudioSources({ directory, plan, assets, signal, namespace = null }) {
    checkedNamespace(namespace);
    const resources = new Map(assets.map(asset => [asset.id, asset])), profiles = [];
    for (const id of new Set(plan.audio.map(input => input.assetId))) profiles.push(await prepareAudioSource({ directory, asset: resources.get(id), signal, namespace }));
    return profiles;
}

export async function pcmFile(directory, profile) {
    if (!Number.isSafeInteger(profile?.id) || profile.id < 1 || profile.file !== checkpointRelative('audio-sources', `${profile.id}.pcm`, profile.namespace) || profile.sampleRate !== rate
        || profile.channels !== 2 || profile.codec !== 'pcm_f32le' || !Number.isSafeInteger(profile.frames) || profile.frames < 1
        || profile.size !== profile.frames * frameBytes || profile.size > limit || !/^[a-f0-9]{64}$/.test(profile.sha256 ?? '')) throw new Error('Perfil PCM no válido o incompleto.');
    const file = path.join(directory, profile.file), real = await fs.realpath(file), stat = await fs.stat(real);
    if (!same(file, real) || !stat.isFile() || stat.size !== profile.size) throw new Error('El PCM privado cambió o está incompleto.');
    return file;
}

/** A segment-local LRU: identical windows share bytes; total retained PCM never exceeds16MiB. */
export function pcmWindowReader(directory, profiles, { signal, onRead = () => {} } = {}) {
    const resources = new Map(), cache = new Map(); let used = 0;
    if (!Array.isArray(profiles)) throw new Error('Prepara los orígenes PCM antes de mezclar.');
    for (const profile of profiles) { if (resources.has(profile.id)) throw new Error('Perfil PCM duplicado.'); resources.set(profile.id, profile); }
    return async (id, from, to) => {
        signal?.throwIfAborted();
        const profile = resources.get(id);
        const first = Math.max(0, Math.floor(from * rate + 1e-7)), end = Math.min(profile?.frames ?? 0, Math.ceil(to * rate - 1e-7) + 1);
        if (!profile || first >= end || to * rate > profile.frames + 1.001 || (end - first) * frameBytes > 4 * 1024 ** 2) throw new Error('Ventana PCM fuera del origen o del presupuesto de lectura.');
        const key = `${id}:${first}:${end}`;
        if (cache.has(key)) { const hit = cache.get(key); cache.delete(key); cache.set(key, hit); return hit; }
        const file = await pcmFile(directory, profile), count = (end - first) * frameBytes, buffer = Buffer.alloc(count), handle = await fs.open(file, 'r');
        try {
            let offset = 0;
            while (offset < count) { signal?.throwIfAborted(); const { bytesRead } = await handle.read(buffer, offset, count - offset, first * frameBytes + offset); if (!bytesRead) throw new Error('La lectura PCM está truncada.'); offset += bytesRead; }
        } finally { await handle.close(); }
        onRead({ assetId: id, from: first / rate, to: end / rate });
        const result = { from: first / rate, to: end / rate, pcm: new Float32Array(buffer.buffer, buffer.byteOffset, buffer.length / 4) };
        while (used + count > 16 * 1024 ** 2 && cache.size) { const oldest = cache.keys().next().value; used -= cache.get(oldest).pcm.byteLength; cache.delete(oldest); }
        cache.set(key, result); used += count;
        return result;
    };
}
