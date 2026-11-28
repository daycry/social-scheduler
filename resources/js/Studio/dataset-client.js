import { assertOk, fieldErrorDetails, readXsrfCookie, sessionFetch } from '../lib/http.js';
import { StudioApiError, withStudioErrors } from './api.js';

const integer = (value, label, min = 1, max = Number.MAX_SAFE_INTEGER) => {
    if (!Number.isSafeInteger(value) || value < min || value > max) throw new Error(`${label} no es válido.`);
    return value;
};

/** Web endpoints only: no caller supplied URL, bearer token or provider request. */
export function createDatasetClient({
    fetchImpl = globalThis.fetch, csrf = readXsrfCookie, createAssembler, tableChunks, routeFor,
} = {}) {
    const brandParams = brandId => ({ brand: integer(brandId, 'La marca') });
    const datasetParams = (brandId, datasetId) => ({
        ...brandParams(brandId), dataset: integer(datasetId, 'La tabla'),
    });
    const url = (name, params, query) => {
        const resolveRoute = routeFor === undefined ? globalThis.route : routeFor;
        if (typeof resolveRoute !== 'function') throw new StudioApiError('No están disponibles las rutas de tablas.', 0, null);
        // Ziggy elimina el prefijo del despliegue al pedir una ruta relativa.
        const resolved = new URL(resolveRoute(`studio.datasets.${name}`, { ...params, ...(query ? { _query: query } : {}) }, true));
        return resolved.pathname + resolved.search;
    };

    async function request(method, target, { signal, body, raw = false } = {}) {
        const response = await sessionFetch(method, target, { signal, body, fetchImpl, csrf });
        await withStudioErrors(assertOk(response, data =>
            fieldErrorDetails(data) || data?.message || 'No se pudo completar la operación con la tabla.'));
        return raw ? response.text() : response.json();
    }

    return {
        async get(brandId, datasetId, { signal } = {}) {
            return request('GET', url('get', datasetParams(brandId, datasetId)), { signal });
        },
        async history(brandId, datasetId, { page = 1, signal } = {}) {
            const target = url('history', datasetParams(brandId, datasetId), { page: integer(page, 'La página'), per_page: 20 });
            return request('GET', target, { signal });
        },
        async control(brandId, datasetId, action) {
            if (!['archive', 'restore'].includes(action)) throw new Error('La acción no está disponible.');
            return request(action === 'archive' ? 'DELETE' : 'POST', url(action, datasetParams(brandId, datasetId)));
        },
        exportUrl(brandId, datasetId, { revision, format = 'csv', mode = 'spreadsheet' }) {
            if (!['csv', 'tsv', 'json'].includes(format)) throw new Error('El formato de descarga no está disponible.');
            if (!['spreadsheet', 'text'].includes(mode)) throw new Error('El modo de descarga no está disponible.');
            return url('export', datasetParams(brandId, datasetId), {
                revision: integer(revision, 'La revisión'), format, ...(format === 'json' ? {} : { mode }),
            });
        },
        async upload(brandId, { file, name, format, header = true }, { signal } = {}) {
            if (!['csv', 'tsv', 'json'].includes(format)) throw new Error('El formato de importación no está disponible.');
            const max = format === 'json' ? 52000000 : 25000000;
            if (!(file instanceof Blob) || file.size > max) throw new Error(`El archivo supera el límite de ${format === 'json' ? '52' : '25'} MB.`);
            const body = new FormData(); body.append('name', name); body.append('format', format); body.append('file', file, `datos.${format}`);
            if (format !== 'json') body.append('header', header ? '1' : '0');
            return request('POST', url('import-create', brandParams(brandId)), { signal, body });
        },
        async save(brandId, datasetId, { name, revision, table }, { signal } = {}) {
            if (typeof tableChunks !== 'function') throw new Error('No está disponible el guardado de tablas.');
            const blob = new Blob([...tableChunks(table)], { type: 'application/json' });
            if (blob.size > 52000000) throw new Error('La tabla supera el límite de 52 MB.');
            const body = new FormData(); body.append('name', name); body.append('format', 'json'); body.append('file', blob, 'tabla.json');
            const target = datasetId === null ? url('import-create', brandParams(brandId)) : url('import-replace', datasetParams(brandId, datasetId));
            if (datasetId !== null) body.append('revision', String(integer(revision, 'La revisión')));
            return request('POST', target, { signal, body });
        },
        async load(brandId, datasetId, revision, { signal, onProgress = () => {} } = {}) {
            const params = datasetParams(brandId, datasetId);
            integer(revision, 'La revisión');
            if (typeof createAssembler !== 'function') throw new Error('No está disponible el lector de tablas.');
            const assembler = createAssembler({ datasetId, revision });
            let previous = -1;
            while (!assembler.complete) {
                signal?.throwIfAborted();
                const offset = integer(assembler.nextOffset, 'La posición', 0, 1000);
                if (offset <= previous) throw new Error('La tabla devolvió una paginación inconsistente.');
                previous = offset;
                const target = url('rows', params, { revision, offset, limit: 100 });
                const raw = await request('GET', target, { signal, raw: true });
                signal?.throwIfAborted(); assembler.append(raw);
                onProgress(assembler.complete ? assembler.table.rows.length : assembler.nextOffset);
            }
            return { table: assembler.table, revision: assembler.revision, sha256: assembler.sha256 };
        },
        async list(brandId, { query = '', page = 1, signal } = {}) {
            const target = url('list', brandParams(brandId), {
                ...(query.trim() === '' ? {} : { query }), page: integer(page, 'La página'), per_page: 20,
            });
            return request('GET', target, { signal });
        },
    };
}
