import { studioRequest } from './api.js';

/** Native jobs always use the immutable server revision, never the browser's static frame renderer. */
export function createStudioExportClient({ request = studioRequest, url = (name, id) => route(name, id) } = {}) {
    return {
        async start(designId, options) {
            const { revision, format, pages, scale, quality } = options;
            const result = await request('POST', url('studio.exports.render', designId), { revision, format, pages, scale, quality, transparent: false });
            return result.export;
        },
        list: (designId, page = 1, signal) => signal
            ? request('GET', `${url('studio.exports.index', designId)}?page=${page}`, undefined, { signal })
            : request('GET', `${url('studio.exports.index', designId)}?page=${page}`),
        async cancel(id) { return (await request('POST', url('studio.exports.cancel', id), {})).export; },
        async retry(id) { return (await request('POST', url('studio.exports.retry', id), {})).export; },
        post: id => request('POST', url('studio.exports.post', id), {}),
    };
}

/** Poll a whole history page, stop on terminal results, and fence every view against late responses. */
export function createExportMonitor({ load, onUpdate, onState = () => {}, schedule = setTimeout, cancel = clearTimeout, now = Date.now, interval = 2000, timeout = 300000 }) {
    let generation = 0, active = false, timer, startedAt, inFlight, controller;
    let state = { loading: false, paused: false, error: '' };
    const notify = patch => { state = { ...state, ...patch }; onState(state); };
    const clear = () => { cancel(timer); timer = undefined; };
    async function tick(token) {
        if (!active || token !== generation) return false;
        if (inFlight) {
            await inFlight;
            return tick(token);
        }
        if (now() - startedAt > timeout) { notify({ loading: false, paused: true }); return false; }
        let finish;
        inFlight = new Promise(resolve => { finish = resolve; });
        controller = new AbortController();
        notify({ loading: true });
        try {
            const data = await load({ signal: controller.signal });
            if (!active || token !== generation) return false;
            onUpdate(data);
            if (data.exports.some(job => ['pending', 'processing'].includes(job.status))) timer = schedule(() => tick(token), interval);
            return true;
        } catch (error) {
            if (active && token === generation) notify({ paused: true, error: error.message || 'No se pudo consultar el historial.' });
            return false;
        } finally {
            inFlight = null; controller = null;
            if (active && token === generation) notify({ loading: false });
            finish();
        }
    }
    function stop() { generation++; active = false; clear(); controller?.abort(); notify({ loading: false }); }
    return {
        start() {
            stop(); active = true; startedAt = now(); notify({ loading: true, paused: false, error: '' });
            return tick(generation);
        },
        refresh() {
            if (!active || inFlight) return Promise.resolve(false);
            clear(); startedAt = now(); notify({ paused: false, error: '' });
            return tick(generation);
        },
        stop,
    };
}
