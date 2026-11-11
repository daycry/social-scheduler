import { StudioApiError } from './api.js';

const cookieToken = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
const integer = (value, label, min = 1, max = Number.MAX_SAFE_INTEGER) => {
    if (!Number.isSafeInteger(value) || value < min || value > max) throw new Error(`${label} no es válido.`);
    return value;
};
const base = brandId => `/brands/${integer(brandId, 'La marca')}/studio/datasets`;
const item = (brandId, datasetId) => `${base(brandId)}/${integer(datasetId, 'La tabla')}`;

/** Web endpoints only: no caller supplied URL, bearer token or provider request. */
export function createDatasetClient({ fetchImpl = globalThis.fetch, csrf = cookieToken, createAssembler, tableChunks } = {}) {
    async function request(method, url, { signal, body, raw = false } = {}) {
        const response = await fetchImpl(url, { method, credentials: 'same-origin', signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf() },
            ...(body === undefined ? {} : { body }),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const detail = Object.values(data.errors ?? {}).flat().filter(value => typeof value === 'string').join(' ');
            throw new StudioApiError(detail || data.message || 'No se pudo completar la operación con la tabla.', response.status, data.errors);
        }
        return raw ? response.text() : response.json();
    }
    return {
        get(brandId, datasetId, { signal } = {}) { return request('GET', item(brandId, datasetId), { signal }); },
        history(brandId, datasetId, { page = 1, signal } = {}) {
            const params = new URLSearchParams({ page: String(integer(page, 'La página')), per_page: '20' });
            return request('GET', `${item(brandId, datasetId)}/revisions?${params}`, { signal });
        },
        control(brandId, datasetId, action) {
            if (!['archive', 'restore'].includes(action)) throw new Error('La acción no está disponible.');
            return request(action === 'archive' ? 'DELETE' : 'POST', `${item(brandId, datasetId)}${action === 'restore' ? '/restore' : ''}`);
        },
        exportUrl(brandId, datasetId, { revision, format = 'csv', mode = 'spreadsheet' }) {
            const url = item(brandId, datasetId); integer(revision, 'La revisión');
            if (!['csv', 'tsv', 'json'].includes(format)) throw new Error('El formato de descarga no está disponible.');
            if (!['spreadsheet', 'text'].includes(mode)) throw new Error('El modo de descarga no está disponible.');
            const params = new URLSearchParams({ revision: String(revision), format, ...(format === 'json' ? {} : { mode }) });
            return `${url}/export?${params}`;
        },
        async upload(brandId, { file, name, format, header = true }, { signal } = {}) {
            if (!['csv', 'tsv', 'json'].includes(format)) throw new Error('El formato de importación no está disponible.');
            const max = format === 'json' ? 52000000 : 25000000;
            if (!(file instanceof Blob) || file.size > max) throw new Error(`El archivo supera el límite de ${format === 'json' ? '52' : '25'} MB.`);
            const body = new FormData(); body.append('name', name); body.append('format', format); body.append('file', file, `datos.${format}`);
            if (format !== 'json') body.append('header', header ? '1' : '0');
            return request('POST', `${base(brandId)}/import`, { signal, body });
        },
        async save(brandId, datasetId, { name, revision, table }, { signal } = {}) {
            if (typeof tableChunks !== 'function') throw new Error('No está disponible el guardado de tablas.');
            const blob = new Blob([...tableChunks(table)], { type: 'application/json' });
            if (blob.size > 52000000) throw new Error('La tabla supera el límite de 52 MB.');
            const body = new FormData(); body.append('name', name); body.append('format', 'json'); body.append('file', blob, 'tabla.json');
            const url = datasetId === null ? base(brandId) : item(brandId, datasetId);
            if (datasetId !== null) body.append('revision', String(integer(revision, 'La revisión')));
            return request('POST', `${url}/import`, { signal, body });
        },
        async load(brandId, datasetId, revision, { signal, onProgress = () => {} } = {}) {
            const url = item(brandId, datasetId); integer(revision, 'La revisión');
            if (typeof createAssembler !== 'function') throw new Error('No está disponible el lector de tablas.');
            const assembler = createAssembler({ datasetId, revision });
            let previous = -1;
            while (!assembler.complete) {
                signal?.throwIfAborted();
                const offset = integer(assembler.nextOffset, 'La posición', 0, 1000);
                if (offset <= previous) throw new Error('La tabla devolvió una paginación inconsistente.');
                previous = offset;
                const params = new URLSearchParams({ revision: String(revision), offset: String(offset), limit: '100' });
                const raw = await request('GET', `${url}/table?${params}`, { signal, raw: true });
                signal?.throwIfAborted(); assembler.append(raw);
                onProgress(assembler.complete ? assembler.table.rows.length : assembler.nextOffset);
            }
            return { table: assembler.table, revision: assembler.revision, sha256: assembler.sha256 };
        },
        list(brandId, { query = '', page = 1, signal } = {}) {
            const params = new URLSearchParams({ query, page: String(integer(page, 'La página')), per_page: '20' });
            return request('GET', `${base(brandId)}?${params}`, { signal });
        },
    };
}
