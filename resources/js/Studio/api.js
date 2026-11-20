import { fieldErrorDetails, HttpError, requestJson, uploadMedia } from '../lib/http.js';

export class StudioApiError extends HttpError {
    constructor(message, status, data, options) {
        super(message, status, data, options);
        this.name = 'StudioApiError';
    }
}

export async function withStudioErrors(request) {
    try {
        return await request;
    } catch (error) {
        if (error instanceof HttpError && !(error instanceof StudioApiError)) {
            throw new StudioApiError(error.message, error.status, error.data, { cause: error });
        }
        throw error;
    }
}

export async function studioRequest(method, url, body, { signal } = {}) {
    return (await withStudioErrors(requestJson(method, url, {
        json: body,
        signal,
        errorMessage: fieldErrorDetails,
    }))) ?? {};
}

export async function attachStudioAsset(brandId, mediaId, { signal } = {}) {
    const { asset } = await studioRequest('POST', route('studio.assets.store', brandId), { media_id: mediaId }, { signal });
    return asset;
}

export function uploadStudioFile(file, onStatus = () => {}) {
    return withStudioErrors(uploadMedia(file, { onStatus, errorDetails: fieldErrorDetails }));
}

export function downloadStudioBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = name.replace(/[\\/:*?"<>|\r\n]/g, '_');
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
