import { StudioApiError, studioRequest } from './api.js';

export const isStudioAudio = file => file.type.startsWith('audio/') || /\.(mp3|wav|m4a|aac|ogg)$/i.test(file.name);
const csrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');

export async function completeStudioAudio(ticket) {
    try {
        const result = await studioRequest('POST', ticket.complete_url, { parts: ticket.parts ?? [] });
        if (result.asset?.status !== 'listo') throw new StudioApiError(result.asset?.error || 'El audio todavía no está listo.', 422);
        return result.asset;
    } catch (error) { if (error.status === 503 || error instanceof TypeError) error.audioTicket = ticket; throw error; }
}

/** Binary upload URLs receive no session headers. Retrying validation reuses the completed ticket. */
export async function uploadStudioAudio(brandId, file, onStatus = () => {}) {
    const mime = file.type || ({ mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', aac: 'audio/aac', ogg: 'audio/ogg' })[file.name.split('.').at(-1).toLowerCase()];
    onStatus('Preparando el audio…');
    const ticket = await studioRequest('POST', route('studio.audio.store', brandId), { name: file.name, mime, size: file.size });
    const { upload } = ticket;
    if (upload.mode === 'local') {
        onStatus('Subiendo y validando el audio…');
        const response = await fetch(upload.url, { method: 'PUT', credentials: 'same-origin', headers: {
            Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'Content-Type': mime }, body: file });
        const result = await response.json().catch(() => ({}));
        if (response.ok && result.asset?.status === 'listo') return result.asset;
        const error = new StudioApiError(result.message || result.asset?.error || 'No se pudo validar el audio.', response.status);
        if (response.status === 503) error.audioTicket = ticket;
        throw error;
    }
    ticket.parts = [];
    if (upload.mode === 'single') {
        onStatus('Subiendo el audio…');
        const response = await fetch(upload.url, { method: 'PUT', credentials: 'omit', headers: upload.headers ?? {}, body: file });
        if (!response.ok) throw new StudioApiError('El almacenamiento rechazó el audio.', response.status);
    } else if (upload.mode === 'multipart') {
        for (const part of upload.parts) {
            onStatus(`Subiendo audio: parte ${part.number} de ${upload.parts.length}…`);
            const start = (part.number - 1) * upload.part_size;
            const response = await fetch(part.url, { method: 'PUT', credentials: 'omit', body: file.slice(start, Math.min(start + upload.part_size, file.size)) });
            const etag = response.headers.get('ETag');
            if (!response.ok || !etag) throw new StudioApiError('No se pudo confirmar una parte del audio.', response.status);
            ticket.parts.push({ number: part.number, etag });
        }
    } else throw new StudioApiError('Este modo de subida de audio no está disponible.', 422);
    onStatus('Validando el audio…');
    return completeStudioAudio(ticket);
}
