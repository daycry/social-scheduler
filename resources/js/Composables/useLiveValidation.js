import { requestJson } from '../lib/http.js';
import { computed, getCurrentScope, onScopeDispose, ref, watch } from 'vue';

const asList = (v) => (Array.isArray(v) ? v : [v]).filter((m) => typeof m === 'string' && m !== '');

/**
 * Normaliza errores por campo (`targets.0`, `media`, `scheduled_at`, `post`...) a una lista plana
 * `{ id, scope, targetIndex, accountId, network, networkLabel, accountName, message, focusId }` que consumen
 * `ErrorsPanel` y el estado deshabilitado de `ActionBar`. `describeTarget(i)` resuelve la cuenta de un destino.
 */
export function normalizeErrors(errors, describeTarget = () => null) {
    const out = [];
    for (const [key, raw] of Object.entries(errors ?? {})) {
        const target = /^targets\.(\d+)/.exec(key);
        for (const message of asList(raw)) {
            const base = { id: `${key}|${message}`, message, targetIndex: null, accountId: null, network: null, networkLabel: null, accountName: null };
            if (target) {
                const info = describeTarget(Number(target[1]));
                out.push({ ...base, scope: 'target', targetIndex: Number(target[1]), accountId: info?.accountId ?? null, network: info?.network ?? null, networkLabel: info?.networkLabel ?? null, accountName: info?.accountName ?? null, focusId: info ? `account-pill-${info.accountId}` : 'picker-title' });
            } else {
                const focusId = { text: 'text', media: 'media-title', scheduled_at: 'scheduled_at', targets: 'picker-title' }[key] ?? null;
                out.push({ ...base, scope: key === 'media' ? 'media' : key === 'scheduled_at' ? 'date' : 'general', focusId });
            }
        }
    }
    return out;
}

/**
 * Validación en vivo (sin guardar): límites de media por red y tipo, y texto demasiado largo.
 * Mismo endpoint (`brands.posts.validate`) y mismo debounce de 500 ms sobre [texto, media, destinos].
 */
export function useLiveValidation(form, { brandId, enabled, immediate = false, describeTarget = () => null }) {
    const liveErrors = ref({});
    let timer = null;
    let pending = null;

    const validateNow = async () => {
        pending?.abort();
        pending = null;
        if (!enabled() || form.targets.length === 0) {
            liveErrors.value = {};
            return;
        }
        const request = new AbortController();
        pending = request;
        try {
            const result = await requestJson('POST', route('brands.posts.validate', brandId()), {
                signal: request.signal,
                // La fecha pendiente se valida al enviar, no mientras se escribe.
                json: { text: form.text, schedule: false, media: form.media, targets: form.targets },
            });
            if (!request.signal.aborted) liveErrors.value = result?.errors ?? {};
        } catch {
            if (!request.signal.aborted) liveErrors.value = {};
        } finally {
            if (pending === request) pending = null;
        }
    };

    watch(() => [form.text, form.media, form.targets], () => {
        pending?.abort();
        clearTimeout(timer);
        timer = setTimeout(validateNow, 500);
    }, { deep: true });

    if (getCurrentScope()) {
        onScopeDispose(() => {
            clearTimeout(timer);
            pending?.abort();
            pending = null;
        });
    }
    if (immediate) validateNow();

    // Los mismos errores, normalizados `{ targetIndex, accountId, network, message }` (consumen ErrorsPanel y ActionBar).
    const errorList = computed(() => normalizeErrors(liveErrors.value, describeTarget));

    return { liveErrors, errorList, validateNow };
}
