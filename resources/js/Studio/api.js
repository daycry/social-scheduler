const csrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');

export class StudioApiError extends Error {
    constructor(message, status, errors = {}) { super(message); this.name = 'StudioApiError'; this.status = status; this.errors = errors; }
}

/** Same-origin JSON transport; bearer URLs from storage never receive the application's CSRF token. */
export async function studioRequest(method, url, body, { signal } = {}) {
    const response = await fetch(url, {
        method, credentials: 'same-origin', signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrf(), ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const details = Object.values(data.errors ?? {}).flat().filter(v => typeof v === 'string').join(' ');
        throw new StudioApiError(details || data.message || 'No se pudo completar la operación.', response.status, data.errors);
    }
    return data;
}

export async function uploadStudioFile(file, onStatus = () => {}) {
    onStatus('Preparando el archivo…');
    const { asset, upload } = await studioRequest('POST', route('media.store'), { name: file.name, mime: file.type, size: file.size });
    if (upload.mode === 'local') {
        onStatus('Subiendo el archivo…');
        const response = await fetch(upload.url, { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'Content-Type': file.type }, body: file });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.asset?.status !== 'listo') throw new StudioApiError(data.message || data.asset?.error || 'No se pudo validar el archivo exportado.', response.status);
        return data.asset;
    }
    if (upload.mode === 'single') {
        onStatus('Subiendo el archivo…');
        const response = await fetch(upload.url, { method: 'PUT', headers: upload.headers ?? {}, body: file, credentials: 'omit' });
        if (!response.ok) throw new StudioApiError('El almacenamiento rechazó la subida.', response.status);
        const result = await studioRequest('POST', route('media.complete', asset.id), {});
        if (result.asset?.status !== 'listo') throw new StudioApiError(result.asset?.error || 'El archivo no está listo.', 422);
        return result.asset;
    }
    if (upload.mode === 'multipart') {
        const parts = [];
        for (const part of upload.parts) {
            onStatus(`Subiendo parte ${part.number} de ${upload.parts.length}…`);
            const start = (part.number - 1) * upload.part_size;
            const response = await fetch(part.url, { method: 'PUT', body: file.slice(start, Math.min(start + upload.part_size, file.size)), credentials: 'omit' });
            const etag = response.headers.get('ETag');
            if (!response.ok || !etag) throw new StudioApiError('No se pudo confirmar una parte de la subida. Revisa el acceso del almacenamiento.', response.status);
            parts.push({ number: part.number, etag });
        }
        const result = await studioRequest('POST', route('media.complete', asset.id), { parts });
        if (result.asset?.status !== 'listo') throw new StudioApiError(result.asset?.error || 'El archivo no está listo.', 422);
        return result.asset;
    }
    throw new StudioApiError('El modo de subida no está disponible.', 422);
}

export function downloadStudioBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = name.replace(/[\\/:*?"<>|\r\n]/g, '_');
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
