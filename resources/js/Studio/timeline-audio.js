import { clipMediaState } from './timeline.js';

/** Streaming decoders only for the current window. Never decode a whole library into JS buffers. */
export function createTimelineAudio(tracks, assets, { onBuffer = () => {} } = {}) {
    const resources = new Map(assets.map(asset => [asset.id, asset]));
    const entries = new Map();
    let context = null, disposed = false, generation = 0, states = new Map();
    const paused = () => { for (const entry of entries.values()) entry.node.pause(); };
    function release(id) {
        const entry = entries.get(id);
        if (!entry) return;
        entries.delete(id); entry.abort.abort(); entry.node.pause();
        entry.source?.disconnect(); entry.gain?.disconnect();
        entry.node.removeAttribute('src'); entry.node.load();
    }
    function wait(node, event, ready, signal) {
        if (ready()) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const cleanup = () => { clearTimeout(timer); node.removeEventListener(event, done); node.removeEventListener('error', failed); signal.removeEventListener('abort', cancelled); };
            const done = () => { cleanup(); resolve(); };
            const failed = () => { cleanup(); reject(new Error('No se pudo cargar o decodificar el audio de esta pista.')); };
            const cancelled = () => { cleanup(); reject(new DOMException('Audio cancelado', 'AbortError')); };
            const timer = setTimeout(failed, 10000);
            node.addEventListener(event, done, { once: true }); node.addEventListener('error', failed, { once: true });
            signal.addEventListener('abort', cancelled, { once: true });
            if (signal.aborted) cancelled();
        });
    }
    async function prepare(time, { play = false } = {}) {
        if (disposed) throw new Error('La reproducción de audio ya terminó.');
        const wanted = tracks.filter(track => time >= track.start && time < track.start + track.duration);
        const ids = new Set(wanted.map(track => track.id));
        for (const id of entries.keys()) if (!ids.has(id)) release(id);
        states = new Map();
        let buffering = false, began = 0;
        const buffer = () => { if (!buffering) { buffering = true; began = performance.now(); paused(); onBuffer(); } };
        try {
            await Promise.all(wanted.map(async track => {
                const asset = resources.get(track.asset_id);
                if (!asset || asset.status !== 'listo' || !['audio', 'video'].includes(asset.kind) || (asset.kind === 'audio' && !asset.audio_codec)
                    || !(asset.studio_url ?? asset.preview_url)) throw new Error('Falta un recurso de audio válido de esta marca.');
                const planned = clipMediaState(track, time, Number(asset.duration));
                states.set(track.id, planned);
                // Muted/exhausted tracks retain their timeline state without opening a decoder.
                if (!planned.audible) { release(track.id); return; }
                let entry = entries.get(track.id);
                if (!entry) {
                    buffer();
                    const node = globalThis.document.createElement('audio');
                    node.preload = 'auto'; node.loop = false; node.preservesPitch = false;
                    entry = { node, abort: new AbortController(), source: null, gain: null, state: null };
                    entries.set(track.id, entry);
                    const ready = wait(node, 'loadeddata', () => node.readyState >= 2, entry.abort.signal);
                    node.src = asset.studio_url ?? asset.preview_url; node.load();
                    await ready;
                }
                const { node } = entry;
                if (node.error || !Number.isFinite(node.duration) || node.duration <= 0) throw new Error('El navegador no puede reproducir este audio.');
                const state = clipMediaState(track, time, node.duration);
                states.set(track.id, state);
                node.playbackRate = state.playbackRate;
                const target = Math.min(state.sourceTime, Math.max(0, node.duration - 0.001));
                const tolerance = play && !node.paused ? state.playbackRate / 60 : 0.0005;
                if (Math.abs(node.currentTime - target) > tolerance || node.seeking) {
                    buffer();
                    const ready = wait(node, 'seeked', () => !node.seeking && Math.abs(node.currentTime - target) <= 0.0005, entry.abort.signal);
                    node.currentTime = target;
                    await ready;
                }
                entry.state = state;
                if (entry.gain) { node.volume = 1; entry.gain.gain.setValueAtTime(state.gain, context.currentTime); }
                else node.volume = state.gain;
                if (!state.audible) node.pause();
            }));
        } catch (error) {
            paused();
            for (const id of entries.keys()) release(id);
            states.clear();
            throw error;
        }
        return { bufferingMs: buffering ? performance.now() - began : 0 };
    }
    async function play() {
        const request = ++generation;
        const audible = [...entries.values()].filter(entry => entry.state?.audible);
        if (!audible.length || disposed) return;
        if (!context) {
            const Constructor = globalThis.AudioContext ?? globalThis.webkitAudioContext;
            if (!Constructor) throw new Error('Este navegador no dispone de mezcla Web Audio.');
            context = new Constructor();
        }
        let timer;
        try {
            await Promise.race([context.resume(), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Pulsa Reproducir para permitir el audio del navegador.')), 5000); })]);
            if (disposed || request !== generation) return;
            await Promise.all(audible.map(async entry => {
                if (!entry.source) {
                    entry.source = context.createMediaElementSource(entry.node); entry.gain = context.createGain();
                    entry.source.connect(entry.gain); entry.gain.connect(context.destination);
                }
                entry.node.volume = 1; entry.gain.gain.setValueAtTime(entry.state.gain, context.currentTime);
                if (entry.node.paused) await entry.node.play();
                if (disposed || request !== generation) entry.node.pause();
            }));
        } catch (error) { paused(); throw error; }
        finally { clearTimeout(timer); }
    }
    return {
        prepare, play,
        pauseMedia: paused,
        pause() { ++generation; paused(); },
        state: () => [...states].map(([id, state]) => { const node = entries.get(id)?.node; return { id, kind: 'audio', active: state.active,
            audible: !!node && !node.paused && state.audible, sourceTime: node?.currentTime ?? state.sourceTime, gain: state.gain, paused: node?.paused ?? true }; }),
        async dispose() { disposed = true; ++generation; for (const id of entries.keys()) release(id); states.clear(); await context?.close(); context = null; },
    };
}
