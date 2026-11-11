import fs from 'node:fs/promises';
import path from 'node:path';
import { createReadStream } from 'node:fs';
import { createHash, randomUUID } from 'node:crypto';
import { attemptDirectory, boundedProcess, checkedNamespace, checkpointRelative, outputFolder, publishCheckpoint } from './av-files.mjs';
import { joinGifSegments } from './av-gif.mjs';
import { audiovisualRenderOptions } from '../../resources/js/Studio/audiovisual-plan.js';

async function hashFile(file) { const hash = createHash('sha256'); for await (const bytes of createReadStream(file)) hash.update(bytes); return hash.digest('hex'); }
export async function outputIntegrity(file, { signal, maxBytes = 8 * 1024 ** 3 } = {}) {
    signal?.throwIfAborted();
    const chunk_size = 16 * 1024 ** 2, chunk_sha256 = [], hash = createHash('sha256');
    let part = createHash('sha256'), partBytes = 0, total = 0;
    for await (const bytes of createReadStream(file, { highWaterMark: 1024 ** 2 })) {
        signal?.throwIfAborted(); total += bytes.length;
        if (total > maxBytes) throw new Error('El archivo final supera su presupuesto de integridad.');
        hash.update(bytes);
        for (let offset = 0; offset < bytes.length;) {
            const length = Math.min(chunk_size - partBytes, bytes.length - offset);
            part.update(bytes.subarray(offset, offset + length)); partBytes += length; offset += length;
            if (partBytes === chunk_size) { chunk_sha256.push(part.digest('hex')); part = createHash('sha256'); partBytes = 0; }
        }
    }
    signal?.throwIfAborted();
    if (total < 1) throw new Error('El archivo final está vacío.');
    if (partBytes) chunk_sha256.push(part.digest('hex'));
    return { sha256: hash.digest('hex'), chunk_size, chunk_sha256 };
}
async function verifiedFile(directory, result, expected) {
    if (!result || result.file !== expected || !/^[a-f0-9]{64}$/.test(result.sha256 ?? '') || !Number.isSafeInteger(result.size) || result.size < 1) throw new Error('El manifiesto de tramos está incompleto.');
    const file = path.join(directory, expected), real = await fs.realpath(file);
    if (process.platform === 'win32' ? real.toLowerCase() !== file.toLowerCase() : real !== file) throw new Error('El tramo no admite enlaces externos.');
    const stat = await fs.stat(file);
    if (!stat.isFile() || stat.size !== result.size || stat.size > 512 * 1024 ** 2 || await hashFile(file) !== result.sha256) throw new Error('Un tramo cambió o está incompleto.');
    return file;
}

export async function assembleProject({ directory, plan, segments, format, signal, namespace = null }) {
    checkedNamespace(namespace);
    directory = await attemptDirectory(directory); signal?.throwIfAborted();
    if (!['mp4', 'gif'].includes(format) || !Array.isArray(segments) || segments.length !== plan.segments.length) throw new Error('Selecciona todos los tramos ordenados y un formato admitido.');
    const folder = namespace ? await outputFolder(directory, 'outputs', namespace) : directory;
    const id = randomUUID(), temporary = path.join(folder, `${id}.part.${format}`), target = path.join(folder, `project.${format}`);
    // Keep concat paths relative to the workspace (-safe 1) and attributable after a killed process.
    const control = namespace ? `${namespace}-${id}` : id;
    const videoList = path.join(directory, `${control}.video.txt`), audioList = path.join(directory, `${control}.audio.txt`);
    const render = audiovisualRenderOptions(plan.document, plan.options), width = render.paddedWidth, height = render.paddedHeight;
    try {
        const videos = [], audio = [];
        for (const [index, item] of segments.entries()) {
            signal?.throwIfAborted();
            const segment = plan.segments[index], suffix = String(index).padStart(6, '0');
            if (item.video?.scale !== render.scale || item.video?.quality !== render.quality) throw new Error('Los tramos usan opciones de escala o calidad distintas.');
            if (item.video?.frameStart !== segment.frameStart || item.video?.frameCount !== segment.frameCount || item.video?.fps !== plan.fps || item.video?.width !== width || item.video?.height !== height) throw new Error('Los tramos no siguen el reloj y las dimensiones del proyecto.');
            videos.push(await verifiedFile(directory, item.video, checkpointRelative('segments', `video-${suffix}.${format}`, item.video?.namespace)));
            if (format === 'mp4' && plan.audio.length) {
                if (item.audio?.sampleStart !== segment.sampleStart || item.audio?.sampleCount !== segment.sampleCount || item.audio?.size !== 44 + segment.sampleCount * 8) throw new Error('Faltan muestras de audio del proyecto.');
                audio.push(await verifiedFile(directory, item.audio, checkpointRelative('segments', `audio-${suffix}.wav`, item.audio?.namespace)));
            }
        }
        if (format === 'gif') await joinGifSegments(videos, temporary, { width, height, fps: plan.fps, frameCount: plan.frameCount, counts: plan.segments.map(segment => segment.frameCount), signal });
        else {
            const list = (files, file) => fs.writeFile(file, files.map(file => `file '${path.relative(directory, file).split(path.sep).join('/')}'`).join('\n') + '\n', { flag: 'wx' });
            await list(videos, videoList); if (audio.length) await list(audio, audioList);
            await boundedProcess(process.env.STUDIO_FFMPEG_BINARY ?? 'ffmpeg', ['-hide_banner', '-loglevel', 'error', '-nostdin', '-n',
                '-f', 'concat', '-safe', '1', '-protocol_whitelist', 'file,pipe', '-i', videoList,
                ...(audio.length ? ['-f', 'concat', '-safe', '1', '-protocol_whitelist', 'file,pipe', '-i', audioList, '-map', '0:v:0', '-map', '1:a:0', '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2'] : ['-map', '0:v:0', '-an']),
                '-c:v', 'copy', '-t', plan.duration.toFixed(12), '-movflags', '+faststart', '-fs', String(8 * 1024 ** 3), temporary], { signal, timeout: 80000, maxBytes: 65536 });
        }
        signal?.throwIfAborted();
        const probe = JSON.parse((await boundedProcess(process.env.STUDIO_FFPROBE_BINARY ?? 'ffprobe', ['-v', 'error', '-count_packets', '-show_entries',
            'stream=codec_type,codec_name,width,height,nb_read_packets,sample_rate,channels:format=duration', '-of', 'json', temporary], { signal, timeout: 30000, maxBytes: 65536 })).toString());
        const video = probe.streams?.find(stream => stream.codec_type === 'video'), audioStream = probe.streams?.find(stream => stream.codec_type === 'audio');
        const audioExpected = format === 'mp4' && plan.audio.length > 0, duration = Number(probe.format?.duration);
        if (video?.codec_name !== (format === 'mp4' ? 'h264' : 'gif') || Number(video?.nb_read_packets) !== plan.frameCount || video?.width !== width || video?.height !== height
            || !Number.isFinite(duration) || Math.abs(duration - plan.duration) > (format === 'gif' ? 0.011 : 1 / plan.fps)
            || probe.streams?.length !== (audioExpected ? 2 : 1) || (audioExpected && (audioStream?.codec_name !== 'aac' || Number(audioStream.sample_rate) !== 48000 || audioStream.channels !== 2))) throw new Error('El archivo final no coincide con sus pistas, duración, dimensiones o fotogramas.');
        const size = (await fs.stat(temporary)).size;
        if (size < 1 || size > 8 * 1024 ** 3) throw new Error('El archivo final supera su presupuesto de disco.');
        const integrity = await outputIntegrity(temporary, { signal }); signal?.throwIfAborted(); await publishCheckpoint(temporary, target, namespace);
        return { file: namespace ? checkpointRelative('outputs', `project.${format}`, namespace) : `project.${format}`, namespace, size, ...integrity, frameCount: plan.frameCount, fps: plan.fps, width, height, duration,
            videoCodec: video.codec_name, audioCodec: audioStream?.codec_name ?? null, scale: render.scale, quality: render.quality };
    } finally { await Promise.all([temporary, videoList, audioList].map(file => fs.rm(file, { force: true }))); }
}
