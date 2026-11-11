import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

const samePath = (a, b) => process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b;
export async function attemptDirectory(directory) {
    if (typeof directory !== 'string' || !path.isAbsolute(directory)
        || !/[/\\]studio-exports[/\\]\d+[/\\]\d+[/\\][a-f0-9-]+$/i.test(directory)) throw new Error('Directorio privado de exportación no válido.');
    const resolved = path.resolve(directory), real = await fs.realpath(resolved);
    if (!samePath(resolved, real) || !(await fs.stat(real)).isDirectory()) throw new Error('El intento no admite enlaces ni directorios ajenos.');
    return real;
}

export async function sourceFile(directory, asset) {
    if (!Number.isSafeInteger(asset?.id) || asset.id < 1 || asset.file !== `assets/${asset.id}.bin`) throw new Error('El recurso necesita un snapshot privado con nombre generado.');
    const file = path.join(directory, 'assets', `${asset.id}.bin`), real = await fs.realpath(file), stat = await fs.stat(real);
    if (!samePath(file, real) || !stat.isFile() || stat.size < 1 || stat.size > 2 * 1024 ** 3) throw new Error('Snapshot ausente, enlazado o fuera del límite de 2 GiB.');
    if (asset.size !== undefined && asset.size !== stat.size) throw new Error('El tamaño del snapshot no coincide con el registrado.');
    return file;
}

/** No shell, no unbounded stdout, and the caller's abort/deadline also kills the owned process. */
export async function boundedProcess(binary, args, { timeout = 15000, maxBytes = 4 * 1024 ** 2, signal, input = null } = {}) {
    signal?.throwIfAborted();
    return new Promise((resolve, reject) => {
        const child = spawn(binary, args, { windowsHide: true, stdio: [input ? 'pipe' : 'ignore', 'pipe', 'pipe'] });
        const chunks = []; let size = 0, stderr = '', failure = null;
        const stop = error => { failure ??= error; child.kill('SIGKILL'); };
        const abort = () => stop(new DOMException('Exportación cancelada.', 'AbortError'));
        const timer = setTimeout(() => stop(new Error('El proceso de medios superó su tiempo permitido.')), timeout);
        const cleanup = () => { clearTimeout(timer); signal?.removeEventListener('abort', abort); };
        signal?.addEventListener('abort', abort, { once: true });
        child.on('error', error => { cleanup(); reject(error); });
        child.stdout.on('data', chunk => { size += chunk.length; if (size > maxBytes) stop(new Error('El proceso de medios superó su presupuesto de salida.')); else chunks.push(chunk); });
        child.stderr.on('data', chunk => { if (stderr.length < 8192) stderr += chunk.toString().slice(0, 8192 - stderr.length); });
        const writing = input ? pipeline(Readable.from(input), child.stdin, ...(signal ? [{ signal }] : [])).catch(error => { stop(error); }) : Promise.resolve();
        child.on('close', async code => { cleanup(); if (failure) reject(failure); else if (code !== 0) reject(new Error(`El proceso de medios falló (${code}): ${stderr}`)); else { await writing; if (failure) reject(failure); else resolve(Buffer.concat(chunks)); } });
        if (signal?.aborted) abort();
    });
}

export function checkedNamespace(namespace = null) {
    if (namespace !== null && (typeof namespace !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(namespace))) throw new Error('Namespace de lease no válido.');
    return namespace;
}

export function checkpointRelative(kind, name, namespace = null) {
    checkedNamespace(namespace);
    const valid = kind === 'segments' ? /^(?:video-\d{6}\.(?:mp4|gif)|[a-z0-9_-]{1,32}-\d{6}\.wav)$/.test(name)
        : kind === 'audio-sources' ? /^[1-9]\d*\.pcm$/.test(name)
            : kind === 'outputs' && /^project\.(?:mp4|gif)$/.test(name);
    if (!valid) throw new Error('Nombre de checkpoint no válido.');
    return `${kind}/${namespace ? namespace + '/' : ''}${name}`;
}

export async function outputFolder(directory, kind, namespace = null) {
    checkedNamespace(namespace);
    if (!['segments', 'audio-sources', 'outputs'].includes(kind)) throw new Error('Directorio de checkpoint no válido.');
    const parent = path.join(directory, kind); await fs.mkdir(parent, { recursive: true });
    if (!samePath(await fs.realpath(parent), parent)) throw new Error('La salida no admite enlaces.');
    if (!namespace) return parent;
    const folder = path.join(parent, namespace); await fs.mkdir(folder, { recursive: true });
    if (!samePath(await fs.realpath(folder), folder)) throw new Error('La salida no admite enlaces.');
    return folder;
}

export const segmentFolder = (directory, namespace = null) => outputFolder(directory, 'segments', namespace);

/** link() atomically creates a complete checkpoint and refuses any existing destination. */
export async function publishCheckpoint(temporary, target, namespace = null) {
    checkedNamespace(namespace);
    if (namespace) await fs.link(temporary, target);
    else await fs.rename(temporary, target);
}

export function checkedSegment(plan, segment) {
    const expected = plan?.segments?.[segment?.index];
    if (!expected || plan.sampleRate !== 48000 || ![12, 15, 24, 25, 30, 50, 60].includes(plan.fps)
        || ['frameStart', 'frameCount', 'sampleStart', 'sampleCount', 'start', 'end'].some(key => expected[key] !== segment[key])
        || !Number.isInteger(segment.frameCount) || segment.frameCount < 1 || segment.frameCount > plan.fps * 2
        || segment.sampleCount !== segment.frameCount * 48000 / plan.fps) throw new Error('Tramo fuera del plan de fotogramas validado.');
    return expected;
}

export async function atomicBytes(directory, relative, bytes, namespace = null) {
    const name = relative.split('/').at(-1);
    if (!/^[a-z0-9_-]{1,32}-\d{6}\.wav$/.test(name) || relative !== checkpointRelative('segments', name, namespace)) throw new Error('Nombre de tramo no válido.');
    await segmentFolder(directory, namespace);
    const target = path.join(directory, relative), temporary = `${target}.${randomUUID()}.part`;
    try { await fs.writeFile(temporary, bytes, { flag: 'wx' }); await publishCheckpoint(temporary, target, namespace); }
    finally { await fs.rm(temporary, { force: true }); }
    return { file: relative, namespace, size: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
}
