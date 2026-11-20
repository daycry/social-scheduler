const xsrfCookieName = 'XSRF-TOKEN';
const xsrfHeader = 'X-' + xsrfCookieName;
const defaultAppUrl = 'http://localhost/';

/** @typedef {{message?: string, errors?: Record<string, unknown>, asset?: {error?: string | null}, [key: string]: unknown}} HttpErrorData */
/** @typedef {{ok: boolean, status: number, headers: Pick<Headers, 'get'>, json: () => Promise<unknown>}} JsonResponse */
/** @typedef {{id: number, status: string, error?: string | null}} MediaAsset */
/** @typedef {{mode: 'local', url: string} | {mode: 'single', url: string, headers?: HeadersInit} | {mode: 'multipart', part_size: number, parts: {number: number, url: string}[]}} UploadTicket */
/** @typedef {{asset: MediaAsset, upload: UploadTicket}} MediaUploadStart */
/** @typedef {{asset?: MediaAsset}} MediaUploadResult */
/** @typedef {(data: HttpErrorData | null, status: number) => string | null | undefined} ErrorMessage */
/** @typedef {{json?: unknown, body?: BodyInit, signal?: AbortSignal, headers?: HeadersInit, csrf?: () => string, fetchImpl?: typeof fetch}} SessionOptions */
/** @typedef {{headers?: HeadersInit, session?: boolean, csrf: () => string, fetchImpl: typeof fetch, signal?: AbortSignal, onProgress?: (bytes: number) => void}} PutOptions */
/** @typedef {Omit<SessionOptions, 'body' | 'headers' | 'json'> & {storeUrl?: string, completeUrl?: (id: number) => string, onProgress?: (loaded: number, size: number) => void, onStatus?: (status: string) => void, errorDetails?: (data: HttpErrorData | null) => string}} UploadOptions */

export class HttpError extends Error {
    /**
     * @param {string} message
     * @param {number} status
     * @param {HttpErrorData | null} [data]
     * @param {ErrorOptions} [options]
     */
    constructor(message, status, data = {}, options) {
        super(message, options);
        this.name = 'HttpError';
        this.status = status;
        this.data = data;
        this.errors = data?.errors ?? {};
    }
}

export function readXsrfCookie(cookie = globalThis.document?.cookie ?? '') {
    const prefix = xsrfCookieName + '=';
    const token = cookie.split(';').map(part => part.trimStart()).find(part => part.startsWith(prefix));
    if (!token) return '';
    try {
        return decodeURIComponent(token.slice(prefix.length));
    } catch {
        return '';
    }
}

/** @param {string | URL} url */
function requestUrl(url) {
    return new URL(url, globalThis.location?.href ?? defaultAppUrl);
}

/** @param {string | URL} url */
function sameOrigin(url) {
    return requestUrl(url).origin === requestUrl('').origin;
}

/** @param {HeadersInit | undefined} headers @param {() => string} csrf */
function sessionHeaders(headers, csrf) {
    const result = new Headers(headers);
    if (!result.has('Accept')) result.set('Accept', 'application/json');
    if (!result.has('X-Requested-With')) result.set('X-Requested-With', 'XMLHttpRequest');
    result.set(xsrfHeader, csrf());
    return result;
}

/** @param {string} method @param {string | URL} url @param {SessionOptions} [options] */
export async function sessionFetch(method, url, { json, body, signal, headers, csrf = readXsrfCookie, fetchImpl = globalThis.fetch } = {}) {
    if (!sameOrigin(url)) throw new Error('La petición de sesión debe usar el origen de la aplicación.');
    if (json !== undefined && body !== undefined) throw new TypeError('Usa un solo cuerpo por petición.');
    const outgoing = sessionHeaders(headers, csrf);
    if (json !== undefined && !outgoing.has('Content-Type')) outgoing.set('Content-Type', 'application/json');
    return fetchImpl(url, {
        method,
        credentials: 'same-origin',
        redirect: 'error',
        signal,
        headers: outgoing,
        body: json === undefined ? body : JSON.stringify(json),
    });
}

/** @param {Pick<JsonResponse, "json">} response */
export async function readJson(response) {
    try {
        return await response.json();
    } catch (error) {
        if (!(error instanceof SyntaxError)) throw error;
        return null;
    }
}

/** @param {HttpErrorData | null | undefined} data */
export function fieldErrorDetails(data) {
    return Object.values(data?.errors ?? {}).flat().filter(value => typeof value === 'string').join(' ');
}

/** @param {JsonResponse} response @param {ErrorMessage} [errorMessage] */
export async function assertOk(response, errorMessage) {
    if (response.ok) return;
    const data = /** @type {HttpErrorData | null} */ (await readJson(response));
    const message = errorMessage?.(data, response.status) || data?.message || 'No se pudo completar la operación.';
    throw new HttpError(message, response.status, data);
}

/** @param {string} method @param {string | URL} url @param {SessionOptions & {errorMessage?: ErrorMessage}} [options] */
export async function requestJson(method, url, { errorMessage, ...options } = {}) {
    const response = await sessionFetch(method, url, options);
    await assertOk(response, errorMessage);
    return readJson(response);
}

/**
 * @param {string} url
 * @param {Blob} body
 * @param {Headers} headers
 * @param {AbortSignal | undefined} signal
 * @param {(bytes: number) => void} onProgress
 * @returns {Promise<JsonResponse>}
 */
function putWithProgress(url, body, headers, signal, onProgress) {
    return new Promise((resolve, reject) => {
        signal?.throwIfAborted();
        const xhr = new XMLHttpRequest();
        const abort = () => xhr.abort();
        /** @param {() => void} callback */
        const finish = callback => {
            signal?.removeEventListener('abort', abort);
            xhr.upload.onprogress = null;
            callback();
        };
        xhr.open('PUT', url);
        for (const [name, value] of headers) xhr.setRequestHeader(name, value);
        xhr.upload.onprogress = event => {
            if (event.lengthComputable) onProgress(event.loaded);
        };
        xhr.onload = () => finish(() => resolve({
            ok: xhr.status >= 200 && xhr.status < 300,
            status: xhr.status,
            headers: { get: name => xhr.getResponseHeader(name) },
            json: async () => JSON.parse(xhr.responseText),
        }));
        xhr.onerror = () => finish(() => reject(new TypeError('Error de red durante la subida.')));
        xhr.onabort = () => finish(() => reject(signal?.reason ?? new DOMException('Subida cancelada.', 'AbortError')));
        signal?.addEventListener('abort', abort, { once: true });
        try {
            xhr.send(body);
        } catch (error) {
            finish(() => reject(error));
        }
    });
}

/** @param {string} url @param {Blob} body @param {PutOptions} options */
async function putMedia(url, body, { headers, session = false, csrf, fetchImpl, signal, onProgress }) {
    const outgoing = session ? sessionHeaders(headers, csrf) : new Headers(headers);
    if (!session) {
        outgoing.delete(xsrfHeader);
        outgoing.delete('X-Requested-With');
    } else if (!sameOrigin(url)) {
        throw new Error('La subida local debe usar el origen de la aplicación.');
    }
    // XHR envía cookies a su propio origen incluso con withCredentials=false.
    // Un objeto firmado del mismo origen se sube con fetch y credenciales omitidas.
    if (onProgress && (session || !sameOrigin(url))) {
        return putWithProgress(url, body, outgoing, signal, onProgress);
    }
    const response = await fetchImpl(url, {
        method: 'PUT', body, headers: outgoing, signal,
        credentials: session ? 'same-origin' : 'omit', redirect: 'error',
    });
    if (response.ok) onProgress?.(body.size);
    return response;
}

/** @param {File} file @param {UploadOptions} [options] */
export async function uploadMedia(file, {
    storeUrl = route('media.store'),
    completeUrl = id => route('media.complete', id),
    signal,
    onProgress,
    onStatus = () => {},
    errorDetails,
    csrf = readXsrfCookie,
    fetchImpl = globalThis.fetch,
} = {}) {
    const transport = { signal, csrf, fetchImpl };
    /** @type {((bytes: number) => void) | undefined} */
    const report = onProgress ? bytes => onProgress(bytes, file.size) : undefined;
    /** @param {string} fallback @returns {ErrorMessage} */
    const mediaError = fallback => data => errorDetails?.(data) || data?.message || data?.asset?.error || fallback;
    onStatus('Preparando el archivo…');
    const { asset, upload } = /** @type {MediaUploadStart} */ (await requestJson('POST', storeUrl, {
        ...transport,
        json: { name: file.name, mime: file.type, size: file.size },
        errorMessage: mediaError('No se pudo preparar la subida.'),
    }));

    let ready;
    if (upload.mode === 'local') {
        onStatus('Subiendo el archivo…');
        const response = await putMedia(upload.url, file, {
            ...transport, session: true, headers: file.type ? { 'Content-Type': file.type } : undefined, onProgress: report,
        });
        await assertOk(response, mediaError('La subida fue rechazada.'));
        ready = (/** @type {MediaUploadResult | null} */ (await readJson(response)))?.asset;
    } else {
        const parts = [];
        if (upload.mode === 'single') {
            onStatus('Subiendo el archivo…');
            const response = await putMedia(upload.url, file, { ...transport, headers: upload.headers, onProgress: report });
            if (!response.ok) throw new HttpError('El almacenamiento rechazó la subida.', response.status);
        } else if (upload.mode === 'multipart') {
            let done = 0;
            for (const part of upload.parts) {
                const start = (part.number - 1) * upload.part_size;
                const body = file.slice(start, Math.min(start + upload.part_size, file.size));
                onStatus(`Subiendo parte ${part.number} de ${upload.parts.length}…`);
                const response = await putMedia(part.url, body, {
                    ...transport, onProgress: report ? bytes => report(done + bytes) : undefined,
                });
                const etag = response.headers.get('ETag');
                if (!response.ok || !etag) throw new HttpError('No se pudo confirmar una parte de la subida.', response.status);
                parts.push({ number: part.number, etag });
                done += body.size;
            }
        } else {
            throw new HttpError('El modo de subida no está disponible.', 422);
        }
        onStatus('Validando el archivo…');
        const data = /** @type {MediaUploadResult | null} */ (await requestJson('POST', completeUrl(asset.id), {
            ...transport, json: upload.mode === 'multipart' ? { parts } : {},
            errorMessage: mediaError('No se pudo completar la subida.'),
        }));
        ready = data?.asset;
    }

    if (ready?.status !== 'listo') throw new HttpError(ready?.error || 'El fichero no es válido.', 422, { asset: ready });
    return ready;
}
