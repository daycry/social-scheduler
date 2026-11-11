import { audioInputState } from '../../resources/js/Studio/audiovisual-plan.js';
import { attemptDirectory, checkedSegment, atomicBytes, checkedNamespace, checkpointRelative } from './av-files.mjs';
import { pcmWindowReader } from './av-pcm.mjs';

const rate = 48000;
function header(samples) {
    const bytes = Buffer.alloc(44);
    bytes.write('RIFF'); bytes.writeUInt32LE(36 + samples * 8, 4); bytes.write('WAVEfmt ', 8); bytes.writeUInt32LE(16, 16);
    bytes.writeUInt16LE(3, 20); bytes.writeUInt16LE(2, 22); bytes.writeUInt32LE(rate, 24); bytes.writeUInt32LE(rate * 8, 28);
    bytes.writeUInt16LE(8, 32); bytes.writeUInt16LE(32, 34); bytes.write('data', 36); bytes.writeUInt32LE(samples * 8, 40);
    return bytes;
}

/** Read at most eight source seconds per active clip from previously normalised disk PCM. */
export async function mixAudioSegment({ directory, plan, audioSources, segment, name = 'audio', signal, onRead = () => {}, namespace = null }) {
    checkedNamespace(namespace);
    directory = await attemptDirectory(directory); checkedSegment(plan, segment); signal?.throwIfAborted();
    if (!/^[a-z0-9_-]{1,32}$/.test(name)) throw new Error('Nombre de mezcla no válido.');
    const output = new Float64Array(segment.sampleCount * 2), readWindow = pcmWindowReader(directory, audioSources, { signal, onRead });
    const deadline = Date.now() + 75000;
    for (const input of plan.audio) {
        const clip = input.clip, speed = clip.playbackRate ?? 1, from = clip.trimStart ?? 0, to = clip.trimEnd ?? input.sourceDuration;
        const period = to - from;
        const first = Math.max(segment.sampleStart, Math.ceil(input.start * rate - 1e-7));
        let last = Math.min(segment.sampleStart + segment.sampleCount, Math.ceil(input.end * rate - 1e-7));
        if (!clip.loop) last = Math.min(last, Math.ceil((input.origin + (period - (clip.sourceOffset ?? 0)) / speed) * rate - 1e-7));
        if (last <= first) continue;
        signal?.throwIfAborted();
        if (Date.now() >= deadline) throw new Error('La mezcla del tramo superó su presupuesto de tiempo.');
        const position = (clip.sourceOffset ?? 0) + (first / rate - input.origin) * speed;
        const phase = clip.loop ? position % period : position, span = (last - first) / rate * speed;
        let intervals;
        if (clip.loop && span >= period) intervals = [[from, to]];
        else if (clip.loop && phase + span > period) intervals = [[from + phase, to], [from, from + phase + span - period]];
        else intervals = [[from + phase, Math.min(to, from + phase + span)]];
        const windows = [];
        for (const [begin, end] of intervals) {
            if (end <= begin || end - begin > 8 + 1e-9) throw new Error('La lectura de audio supera el presupuesto por tramo.');
            windows.push(await readWindow(input.assetId, begin, end));
        }
        for (let n = first; n < last; n++) {
            const state = audioInputState(input, n / rate); if (!state || state.gain === 0) continue;
            const window = windows.find(window => state.sourceTime >= window.from - 1e-9 && state.sourceTime < window.to + 1e-9);
            if (!window) throw new Error('Falta una ventana decodificada de audio.');
            const position = Math.max(0, (state.sourceTime - window.from) * rate), max = window.pcm.length / 2 - 1;
            if (position > max + 1.001) throw new Error('El decodificador devolvió muestras incompletas del origen.');
            const index = Math.min(Math.floor(position), max), next = Math.min(index + 1, max), fraction = Math.min(1, position - index);
            const target = (n - segment.sampleStart) * 2;
            for (let channel = 0; channel < 2; channel++) {
                const a = window.pcm[index * 2 + channel], b = window.pcm[next * 2 + channel];
                output[target + channel] += (a + (b - a) * fraction) * state.gain;
            }
        }
    }
    signal?.throwIfAborted();
    const bytes = Buffer.alloc(44 + output.length * 4); header(segment.sampleCount).copy(bytes);
    for (let i = 0; i < output.length; i++) bytes.writeFloatLE(Math.max(-1, Math.min(1, output[i])), 44 + i * 4);
    const result = await atomicBytes(directory, checkpointRelative('segments', `${name}-${String(segment.index).padStart(6, '0')}.wav`, namespace), bytes, namespace);
    return { ...result, sampleCount: segment.sampleCount, sampleStart: segment.sampleStart };
}
