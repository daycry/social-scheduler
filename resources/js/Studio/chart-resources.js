/** Private, pinned dataset resources for one brand/editor/export lifetime. */
import { parseDatasetTable } from './dataset-table.js';
import { normalizeChartBinding } from './chart-binding.js';
import { prepareBarChart } from './bar-geometry.js';

const MAX_BYTES = 52000000, TOTAL_BYTES = 64000000, MAX_REFS = 32;
const fail = message => { throw new TypeError(`Chart resources: ${message}`); };
const abortError = () => new DOMException('Chart resource operation aborted.', 'AbortError');
function fields(value, expected) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail('own data object required.');
    const descriptors = Object.getOwnPropertyDescriptors(value);
    if (Reflect.ownKeys(descriptors).length !== expected.length) fail('unknown or missing fields.');
    const out = {};
    for (const key of expected) {
        const d = descriptors[key];
        if (!d || !Object.hasOwn(d, 'value') || !d.enumerable) fail('own enumerable data fields required.');
        out[key] = d.value;
    }
    return out;
}
function reference(value) {
    const out = fields(value, ['id', 'revision', 'sha256']);
    for (const key of ['id', 'revision']) if (!Number.isSafeInteger(out[key]) || out[key] < 1) fail('positive safe identity required.');
    if (typeof out.sha256 !== 'string' || out.sha256.length !== 64 || !/^[a-f0-9]{64}$/.test(out.sha256)) fail('invalid SHA-256.');
    return Object.freeze(out);
}
const keyFor = ref => `${ref.id}:${ref.revision}:${ref.sha256}`;
function waitFor(promise, signal) {
    if (!signal) return promise;
    if (signal.aborted) return Promise.reject(abortError());
    return new Promise((resolve, reject) => {
        let settled = false;
        const done = () => { if (settled) return false; settled = true; signal.removeEventListener('abort', aborted); return true; };
        const aborted = () => { if (done()) reject(abortError()); };
        signal.addEventListener('abort', aborted, { once: true });
        promise.then(value => { if (done()) resolve(value); }, error => { if (done()) reject(error); });
        if (signal.aborted) aborted();
    });
}
export function createChartResources({ brandId, fetchImpl = globalThis.fetch, descriptors = [], offline: offlineRequested = false }) {
    if (!Number.isSafeInteger(brandId) || brandId < 1 || typeof fetchImpl !== 'function') fail('brand and fetch required.');
    const origin = new URL(globalThis.location?.href ?? fail('HTTP origin required.'));
    if (!['http:', 'https:'].includes(origin.protocol)) fail('HTTP origin required.');
    function ownURL(value) {
        if (typeof value !== 'string') fail('URL must be text.');
        const url = new URL(value, origin);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin.origin || url.username || url.password || url.hash) fail('same-origin HTTP URL required.');
        return url.href;
    }
    if (!Array.isArray(descriptors)) fail('descriptor list required.');
    const list = Object.getOwnPropertyDescriptors(descriptors), length = list.length.value;
    if (Reflect.ownKeys(list).length !== length + 1) fail('dense descriptor list required.');
    const offline = offlineRequested === true || length > 0, allowed = new Map(), identities = new Set();
    for (let i = 0; i < length; i++) {
        const d = list[i];
        if (!d || !Object.hasOwn(d, 'value') || !d.enumerable) fail('dense own descriptor data required.');
        const entry = fields(d.value, ['id', 'revision', 'sha256', 'bytes', 'url']);
        const ref = reference({ id: entry.id, revision: entry.revision, sha256: entry.sha256 });
        if (!Number.isSafeInteger(entry.bytes) || entry.bytes < 1 || entry.bytes > MAX_BYTES) fail('invalid descriptor bytes.');
        const identity = `${ref.id}:${ref.revision}`;
        if (identities.has(identity)) fail('duplicate or ambiguous descriptor.');
        identities.add(identity);
        allowed.set(keyFor(ref), Object.freeze({ ...ref, bytes: entry.bytes, url: ownURL(entry.url) }));
    }
    const resources = new Map(), projections = new Map(), metadata = new Map();
    let disposed = false, total = 0;
    const active = () => { if (disposed) throw abortError(); };
    function remember(key, info) {
        metadata.delete(key); metadata.set(key, info);
        while (metadata.size > 500) metadata.delete(metadata.keys().next().value);
        return info;
    }
    function makeRoom(increase, slots = 0, except = null) {
        while (total + increase > TOTAL_BYTES || resources.size + slots > MAX_REFS) {
            const candidate = Array.from(resources).find(([, entry]) => entry.complete && entry !== except);
            if (!candidate) fail(resources.size + slots > MAX_REFS ? '32 reference budget exceeded.' : 'canonical byte budget exceeded.');
            evict(...candidate);
        }
    }
    function reserve(entry, size) {
        const increase = size - entry.bytes;
        if (size > MAX_BYTES) fail('canonical byte budget exceeded.');
        makeRoom(increase, 0, entry);
        total += increase; entry.bytes = size;
    }
    function evict(key, entry) {
        if (resources.get(key) !== entry) return;
        resources.delete(key); total -= entry.bytes;
    }
    async function load(ref, descriptor, entry) {
        let response, reader;
        const timer = setTimeout(() => entry.controller.abort(), 30000);
        const signal = entry.controller.signal;
        try {
            const target = descriptor?.url ?? `/brands/${brandId}/studio/datasets/${ref.id}/export?format=json&revision=${ref.revision}`;
            response = await waitFor(Promise.resolve().then(() => { active(); if (signal.aborted) throw abortError(); return fetchImpl(target, { method: 'GET', credentials: 'same-origin', redirect: 'error', signal }); }), signal);
            active(); if (signal.aborted) throw abortError();
            if (response.status !== 200) fail('HTTP 200 required.');
            if (typeof response.url !== 'string' || !/^https?:\/\//i.test(response.url)) fail('absolute HTTP response URL required.');
            ownURL(response.url);
            if (!/^application\/json(?:\s*;[^\r\n]*)?$/i.test(response.headers.get('content-type') ?? '')) fail('application/json required.');
            const declared = response.headers.get('content-length');
            if (declared !== null) {
                if (!/^[0-9]+$/.test(declared) || !Number.isSafeInteger(Number(declared)) || Number(declared) < 1 || Number(declared) > MAX_BYTES) fail('invalid content length.');
                if (descriptor && Number(declared) !== descriptor.bytes) fail('descriptor size mismatch.');
                reserve(entry, Number(declared));
            }
            if (!response.body || typeof response.body.getReader !== 'function') fail('readable byte body required.');
            reader = response.body.getReader();
            const chunks = []; let size = 0;
            for (;;) {
                const result = await waitFor(Promise.resolve().then(() => reader.read()), signal);
                active(); if (signal.aborted) throw abortError();
                if (result.done) break;
                const chunk = result.value;
                if (!Number.isSafeInteger(chunk?.byteLength) || chunk.byteLength < 1 || size + chunk.byteLength > MAX_BYTES) fail('stream byte budget exceeded.');
                size += chunk.byteLength;
                if (descriptor && size > descriptor.bytes) fail('descriptor size mismatch.');
                if (size > entry.bytes) reserve(entry, size);
                if (!(chunk instanceof Uint8Array)) fail('byte chunk required.');
                chunks.push(chunk.slice());
            }
            if (!size || (descriptor && size !== descriptor.bytes) || (declared !== null && size !== Number(declared))) fail('body size mismatch.');
            reserve(entry, size);
            const data = new Uint8Array(size); let offset = 0;
            for (const chunk of chunks) { data.set(chunk, offset); offset += chunk.byteLength; }
            const digest = await waitFor(globalThis.crypto.subtle.digest('SHA-256', data), signal);
            active(); if (signal.aborted) throw abortError();
            const sha = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
            if (sha !== ref.sha256) fail('SHA-256 mismatch.');
            const table = parseDatasetTable(new TextDecoder('utf-8', { fatal: true }).decode(data));
            active(); if (signal.aborted) throw abortError();
            entry.info = remember(keyFor(ref), Object.freeze({ ...ref, bytes: size, row_count: table.rows.length, column_count: table.columns.length }));
            entry.complete = true;
            return Object.freeze({ ...ref, table });
        } catch (error) {
            // End rejected HTTP work before its cache entry can be evicted. Header
            // and budget validation can fail before a reader has been acquired.
            entry.controller.abort();
            throw error;
        } finally {
            const body = reader ?? response?.body;
            if (body) { try { Promise.resolve(body.cancel()).catch(() => {}); } catch { /* Failed/cancelled stream has no remaining data. */ } }
            if (reader) { try { reader.releaseLock(); } catch { /* Already released. */ } }
            // Cancellation must not delay settlement if a stream's cancel promise
            // never resolves; failed requests have already had their signal aborted.
            clearTimeout(timer);
        }
    }
    function resource(ref) {
        active(); const key = keyFor(ref);
        if (resources.has(key)) {
            const entry = resources.get(key);
            resources.delete(key); resources.set(key, entry);
            return entry.promise;
        }
        const descriptor = allowed.get(key);
        if (offline && !descriptor) fail('reference missing from offline descriptors.');
        makeRoom(0, 1);
        const entry = { bytes: 0, complete: false, controller: new AbortController(), promise: null };
        if (descriptor) reserve(entry, descriptor.bytes);
        resources.set(key, entry);
        entry.promise = load(ref, descriptor, entry).catch(error => { evict(key, entry); throw error; });
        return entry.promise;
    }
    async function resolve(value, { signal } = {}) {
        active(); if (signal?.aborted) throw abortError();
        return waitFor(resource(reference(value)), signal);
    }
    async function describe(value, { signal } = {}) {
        active(); if (signal?.aborted) throw abortError();
        const ref = reference(value), key = keyFor(ref);
        const info = metadata.get(key) ?? Array.from(projections.values()).find(entry => entry.resourceKey === key && entry.info)?.info;
        if (info) return waitFor(Promise.resolve().then(() => { active(); if (signal?.aborted) throw abortError(); return remember(key, info); }), signal);
        const pending = resource(ref), entry = resources.get(key);
        return waitFor(pending.then(() => { active(); if (signal?.aborted) throw abortError(); return entry.info; }), signal);
    }
    async function prepare(value, { signal } = {}) {
        active(); if (signal?.aborted) throw abortError();
        const binding = normalizeChartBinding(value);
        // Binding's original validator accepts a final newline in its regex; enforce full ASCII IDs here.
        for (const id of [binding.category_column, ...binding.series_columns]) if (!/^[A-Za-z0-9_-]{1,64}$/.test(id) || /[^A-Za-z0-9_-]/.test(id)) fail('complete column IDs required.');
        const key = JSON.stringify([keyFor(binding.dataset), binding.category_column, binding.series_columns, binding.precision]);
        let entry = projections.get(key);
        if (!entry) {
            entry = { complete: false, resourceKey: keyFor(binding.dataset) };
            const pending = resource(binding.dataset), source = resources.get(entry.resourceKey);
            entry.promise = pending.then(snapshot => {
                active();
                const handle = prepareBarChart(binding, snapshot);
                entry.info = source.info;
                entry.complete = true;
                if (projections.get(key) !== entry) return handle;
                projections.delete(key); projections.set(key, entry);
                // Only completed selections enter LRU eviction. Delivered G5 handles own
                // their projections independently, and in-flight consumers remain shared.
                let completed = 0;
                for (const cached of projections.values()) if (cached.complete) completed++;
                for (const [oldKey, cached] of projections) {
                    if (completed <= 500) break;
                    if (cached.complete) { projections.delete(oldKey); completed--; }
                }
                return handle;
            }).catch(error => { if (projections.get(key) === entry) projections.delete(key); throw error; });
            projections.set(key, entry);
        }
        if (entry.complete) {
            projections.delete(key); projections.set(key, entry);
            const snapshot = resources.get(entry.resourceKey);
            if (snapshot) { resources.delete(entry.resourceKey); resources.set(entry.resourceKey, snapshot); }
        }
        return waitFor(entry.promise, signal);
    }
    function dispose() {
        if (disposed) return;
        disposed = true;
        for (const entry of resources.values()) entry.controller.abort();
        resources.clear(); projections.clear(); metadata.clear(); total = 0;
    }
    function stats() { return Object.freeze({ brandId, disposed, offline, descriptors: allowed.size, resources: resources.size, projections: projections.size, metadata: metadata.size, bytes: total }); }
    return Object.freeze({ resolve, describe, prepare, dispose, stats });
}



