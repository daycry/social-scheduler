/** Session-owned monotonic countdown. No resources are acquired until start(). */
export function createPresentationTimer({
    assets = [], now = () => performance.now(),
    requestTick = callback => setTimeout(callback, 100), cancelTick = clearTimeout,
    createAudio = () => document.createElement('audio'), onChange = () => {}, onError = () => {},
} = {}) {
    let durationMs = 300000, remainingMs = durationMs, status = 'idle';
    let startedAt = 0, startedRemaining = remainingMs, tick = null, tickGeneration = 0;
    let volume = .4, muted = false, visible = true, musicId = null;
    // Copy only the small authorized descriptor needed by this session. Never retain DTOs.
    const music = new Map();
    for (const asset of Array.isArray(assets) ? assets : []) {
        if (!asset || !Number.isSafeInteger(asset.id) || asset.id < 1 || asset.kind !== 'audio'
            || asset.status !== 'listo' || typeof asset.audio_codec !== 'string' || !asset.audio_codec.trim()
            || !Number.isFinite(asset.duration) || asset.duration <= 0) continue;
        const source = asset.studio_url ?? asset.preview_url;
        if (typeof source !== 'string') continue;
        const match = /^\/brands\/([1-9]\d*)\/studio\/assets\/([1-9]\d*)\/file$/.exec(source);
        if (!match || !Number.isSafeInteger(Number(match[1])) || Number(match[2]) !== asset.id) continue;
        if (!music.has(asset.id)) music.set(asset.id, Object.freeze({ id: asset.id,
            name: typeof asset.name === 'string' ? asset.name : `Audio ${asset.id}`, source }));
    }
    const musicOptions = Object.freeze([...music.values()].map(({ id, name }) => Object.freeze({ id, name })));
    let activeAudio = null, audioGeneration = 0;
    const currentRemaining = () => status === 'running'
        ? Math.max(0, startedRemaining - Math.max(0, now() - startedAt)) : remainingMs;
    function snapshot() {
        const remaining = currentRemaining(), seconds = Math.ceil(remaining / 1000);
        const pad = value => String(value).padStart(2, '0');
        const label = seconds >= 3600
            ? `${pad(Math.floor(seconds / 3600))}:${pad(Math.floor(seconds / 60) % 60)}:${pad(seconds % 60)}`
            : `${pad(Math.floor(seconds / 60))}:${pad(seconds % 60)}`;
        return Object.freeze({ durationMs, remainingMs: remaining, elapsedMs: durationMs - remaining,
            status, label, musicId, musicOptions, volume, muted, visible });
    }
    const publish = () => { if (status !== 'disposed') onChange(snapshot()); };
    function release(node, errorListener) {
        // Cleanup remains synchronous, even when an older play() promise is unresolved.
        node.removeEventListener('error', errorListener);
        node.pause(); node.removeAttribute('src'); node.load();
    }
    function stopMusic() {
        audioGeneration++;
        const previous = activeAudio; activeAudio = null;
        if (previous) release(previous.node, previous.errorListener);
    }
    function audioFailure(error, generation) {
        if (generation !== audioGeneration || (status !== 'running' && status !== 'paused')) return;
        pause(); stopMusic(); onError(error instanceof Error ? error : new Error('No se pudo reproducir la música.'));
    }
    async function playMusic() {
        if (musicId === null || status !== 'running') return;
        const generation = ++audioGeneration;
        let node;
        try {
            if (activeAudio) node = activeAudio.node;
            else {
                node = createAudio();
                const owner = { node, errorListener: null };
                owner.errorListener = () => {
                    if (activeAudio === owner) audioFailure(new Error('No se pudo cargar la m\u00fasica.'), audioGeneration);
                };
                activeAudio = owner;
                node.addEventListener('error', owner.errorListener);
                node.loop = true; node.volume = volume; node.muted = muted;
                node.src = music.get(musicId).source; node.load();
            }
            if (generation !== audioGeneration || status !== 'running') return;
            // This call occurs synchronously in the start/select gesture, before any await.
            await node.play();
            if (status !== 'running' || activeAudio?.node !== node) node.pause();
        } catch (error) {
            if (generation !== audioGeneration || status !== 'running') {
                // An obsolete play result cannot interrupt a newer gesture on this node.
                if (status !== 'running' || activeAudio?.node !== node) node?.pause();
                return;
            }
            audioFailure(error, generation);
        }
    }
    function cancel() { tickGeneration++; if (tick !== null) cancelTick(tick); tick = null; }
    function schedule() {
        if (status !== 'running' || tick !== null) return;
        const generation = tickGeneration;
        tick = requestTick(() => {
            if (generation !== tickGeneration || status !== 'running') return;
            tick = null;
            remainingMs = currentRemaining();
            if (remainingMs === 0) { status = 'finished'; stopMusic(); }
            publish(); schedule();
        }, 100);
    }
    function pause() {
        if (status !== 'running') return;
        remainingMs = currentRemaining(); status = remainingMs === 0 ? 'finished' : 'paused';
        cancel();
        if (status === 'finished') stopMusic();
        else { audioGeneration++; activeAudio?.node.pause(); }
        publish();
    }
    function reset() {
        if (status === 'disposed') return;
        cancel(); stopMusic(); status = 'idle'; remainingMs = durationMs; publish();
    }
    return {
        snapshot,
        configure(seconds) {
            if (status === 'disposed') return;
            if (!Number.isInteger(seconds) || seconds < 1 || seconds > 86400) throw new RangeError('La duración debe ser de 1 a 86400 segundos enteros.');
            durationMs = seconds * 1000; reset();
        },
        async start() {
            if (status === 'disposed' || status === 'running') return;
            if (status === 'finished') remainingMs = durationMs;
            startedAt = now(); startedRemaining = remainingMs; status = 'running';
            const playing = playMusic(); schedule(); publish(); await playing;
        },
        pause, reset,
        async setMusic(id) {
            if (status === 'disposed') return;
            if (id !== null && !music.has(id)) throw new RangeError('Audio no autorizado.');
            if (musicId === id) return;
            stopMusic(); musicId = id;
            const playing = playMusic(); publish(); await playing;
        },
        setVolume(value) {
            if (status === 'disposed') return;
            if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > 1) throw new RangeError('Volumen inválido.');
            volume = value; if (activeAudio) activeAudio.node.volume = volume; publish();
        },
        setMuted(value) { if (status === 'disposed') return; if (typeof value !== 'boolean') throw new TypeError('Silencio inválido.'); muted = value; if (activeAudio) activeAudio.node.muted = muted; publish(); },
        setVisible(value) { if (status === 'disposed') return; if (typeof value !== 'boolean') throw new TypeError('Visibilidad inválida.'); visible = value; publish(); },
        dispose() { if (status === 'disposed') return; remainingMs = currentRemaining(); cancel(); status = 'disposed'; stopMusic(); },
    };
}
