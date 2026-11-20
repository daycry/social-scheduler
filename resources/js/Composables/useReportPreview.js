import { requestJson } from '../lib/http.js';
import { onScopeDispose, ref } from 'vue';

/**
 * El tipo de respuesta viene del contrato de reports.preview y se indica al usar el composable.
 * @template [T=unknown]
 * @param {() => unknown} getPayload
 */
export function useReportPreview(getPayload) {
    const preview = ref(/** @type {T | null} */ (null));
    const previewError = ref('');
    const previewing = ref(false);
    /** @type {AbortController | undefined} */
    let activeRequest;

    function resetPreview() {
        activeRequest?.abort();
        activeRequest = undefined;
        preview.value = null;
        previewError.value = '';
        previewing.value = false;
    }

    async function runPreview() {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        previewing.value = true;
        previewError.value = '';
        try {
            const result = await requestJson('POST', route('reports.preview'), {
                json: getPayload(),
                signal: request.signal,
                errorMessage: data => data?.message ?? 'No se pudo generar la vista previa.',
            });
            if (activeRequest === request) preview.value = /** @type {T | null} */ (result);
        } catch (error) {
            if (activeRequest !== request) return;
            preview.value = null;
            previewError.value = (/** @type {Error} */ (error)).message;
        } finally {
            if (activeRequest === request) {
                activeRequest = undefined;
                previewing.value = false;
            }
        }
    }

    onScopeDispose(resetPreview);
    return { preview, previewError, previewing, runPreview, resetPreview };
}
