import { validateDocument } from './document.js';
import { createPresentationSession } from './presentation.js';
import { compileTimeline, sampleTimeline } from './timeline.js';

// Offline preview accepts a minimum scale of .1. Keep the next-slide surface
// near 640px on its longest edge without altering full-resolution audience.
export const presentationPreviewScale = (width, height) => Math.max(.1, Math.min(1, 640 / Math.max(width, height)));

export function presentationKey(event) {
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(event.target?.tagName) || event.target?.isContentEditable || event.ctrlKey || event.metaKey || event.altKey) return null;
    return ({ ArrowRight: 'next', ArrowDown: 'next', PageDown: 'next', ArrowLeft: 'previous', ArrowUp: 'previous', PageUp: 'previous',
        Home: 'first', End: 'last', ' ': 'togglePlay', b: 'toggleBlackout', B: 'toggleBlackout', Escape: 'close' })[event.key] ?? null;
}

/** P1 owns navigation/loop/blackout. Its additive elapsedMs is deliberately NOT a
 * media clock: compiled overlap/quantization owns mediaTime; dominant audioGain
 * (incoming wins ties) maps that time back to P1 pageId and hence presenter notes.
 * A private staging canvas is published only after a current generation finishes.
 */
export function createPresentationPlayer({ document: input, output, assets = [], fonts = [], chartResources,
    createRenderer = async (...args) => (await import('./timeline-preview.js')).createTimelinePreview(...args),
    createCanvas = () => globalThis.document.createElement('canvas'),
    now = () => performance.now(), requestFrame = callback => requestAnimationFrame(callback), cancelFrame = id => cancelAnimationFrame(id),
    publish, onState = () => {} } = {}) {
    const captured = validateDocument(input);
    const document = captured.schema_version === 1 ? validateDocument({ ...captured, schema_version: 2, timeline: { fps: 30, audio_tracks: [] } }) : captured;
    const plan = compileTimeline(document);
    // Tests can supply a plain output + publish; the real renderer always receives a private canvas.
    const canvas = publish && !globalThis.document ? {} : createCanvas();
    let loop = false, session = createPresentationSession(document), mediaTime = 0;
    let renderer = null, creating = null, tail = Promise.resolve(), closing = null;
    let generation = 0, disposed = false, frame = null, stamp = 0, error = '';
    const lastFrame = Math.max(0, (plan.frameCount - 1) / plan.fps);
    function snapshot() {
        const state = session.snapshot();
        return Object.freeze({ ...state, mediaTime, mediaDuration: plan.duration, loop, error,
            notes: document.pages.find(page => page.id === state.pageId)?.notes ?? '',
            nextPageName: document.pages.find(page => page.id === state.nextPageId)?.name ?? '' });
    }
    const notify = () => onState(snapshot());
    function stopClock() { if (frame !== null) cancelFrame(frame); frame = null; }
    function invalidate() { ++generation; stopClock(); renderer?.pause(); }
    function fail(cause, token) {
        if (disposed || token !== generation) return;
        invalidate(); session.pause(); error = cause?.message ?? String(cause); notify();
    }
    async function ensure() {
        if (renderer) return renderer;
        if (!creating) creating = (async () => {
            const created = await createRenderer(canvas, { document, assets, fonts, chartResources });
            if (disposed) { created.pause(); await created.dispose(); return null; }
            renderer = created; return created;
        })();
        return creating;
    }
    function mapPage() {
        const visible = sampleTimeline(plan, Math.min(mediaTime, lastFrame)).pages;
        const dominant = visible.reduce((best, page) => !best || page.audioGain >= best.audioGain ? page : best, null);
        if (dominant && dominant.id !== session.snapshot().pageId) session.select(dominant.id);
    }
    function paint(state) {
        if (publish) { publish(canvas, state); return; }
        output.width = canvas.width; output.height = canvas.height;
        const context = output.getContext('2d');
        if (!context) throw new Error('No se pudo preparar la pantalla de audiencia.');
        if (state.blackout) { context.fillStyle = '#000000'; context.fillRect(0, 0, output.width, output.height); }
        else context.drawImage(canvas, 0, 0);
    }
    async function render(token) {
        const instance = await ensure();
        if (!instance || disposed || token !== generation) { instance?.pause(); return null; }
        const state = session.snapshot();
        const result = await instance.seek(Math.min(mediaTime, lastFrame), { play: state.playing && !state.ended });
        if (disposed || token !== generation) { instance.pause(); return null; }
        paint(snapshot()); notify(); return result ?? {};
    }
    function enqueue(token, operation) {
        const work = tail.catch(() => {}).then(async () => {
            if (disposed || token !== generation) return;
            try { return await operation(); } catch (cause) { fail(cause, token); }
        });
        tail = work; return work;
    }
    function schedule(token) {
        if (disposed || token !== generation || !session.snapshot().playing) return;
        frame = requestFrame(async () => {
            frame = null;
            return enqueue(token, async () => {
                const current = now(); const delta = Math.max(0, current - stamp) / 1000; stamp = current;
                mediaTime += delta;
                if (mediaTime >= plan.duration) {
                    if (loop) mediaTime %= plan.duration;
                    else { mediaTime = lastFrame; session.last(); session.next(); }
                }
                mapPage();
                const result = await render(token);
                if (!result || disposed || token !== generation) return;
                // Preparation already paused native media. Subtract only reported
                // buffering, retaining ordinary decode/render elapsed wall time.
                stamp += Math.max(0, result.bufferingMs ?? 0);
                schedule(token);
            });
        });
    }
    function command(action, { seek = false } = {}) {
        if (disposed) return Promise.resolve();
        invalidate(); error = ''; action();
        if (seek) {
            const index = session.snapshot().index;
            // Incoming transition belongs to previous page; at next.start a fade
            // can still show ONLY the outgoing page. Its end is fully visible.
            mediaTime = session.snapshot().ended ? lastFrame : Math.min(lastFrame, Math.max(plan.pages[index].start, plan.pages[index - 1]?.transition.end ?? 0));
        }
        const token = generation; notify();
        return enqueue(token, async () => { const result = await render(token); if (result && token === generation && !disposed) { stamp = now(); schedule(token); } });
    }
    const api = {
        snapshot,
        ready: () => command(() => {}),
        play: () => command(() => { if (session.snapshot().ended) mediaTime = 0; session.play(); }),
        pause() { if (disposed) return; invalidate(); session.pause(); notify(); },
        next: () => command(() => session.next(), { seek: true }),
        previous: () => command(() => session.previous(), { seek: true }),
        first: () => command(() => session.first(), { seek: true }),
        last: () => command(() => session.last(), { seek: true }),
        select: id => { if (!session.snapshot().pageIds.includes(id)) return Promise.reject(new Error('La página no pertenece a la presentación.')); return command(() => session.select(id), { seek: true }); },
        setBlackout: value => command(() => session.setBlackout(value)),
        setLoop: value => command(() => {
            if (typeof value !== 'boolean') throw new TypeError('Bucle debe ser booleano.');
            const previous = session.snapshot(); loop = value;
            session.dispose(); session = createPresentationSession(document, { startPageId: previous.pageId, loop });
            session.setBlackout(previous.blackout); if (previous.playing) session.play();
        }),
        dispose() {
            if (closing) return closing;
            disposed = true; invalidate(); session.dispose(); notify();
            closing = (async () => { await tail.catch(() => {}); const instance = renderer ?? await creating?.catch(() => null); if (instance) { instance.pause(); await instance.dispose(); } renderer = null; })();
            return closing;
        },
    };
    return Object.freeze(api);
}
