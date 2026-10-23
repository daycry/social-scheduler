import { computed, ref, watch } from 'vue';

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

    const validateNow = async () => {
        if (!enabled() || form.targets.length === 0) {
            liveErrors.value = {};
            return;
        }
        const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        try {
            const response = await fetch(route('brands.posts.validate', brandId()), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                // La fecha pendiente no es un error mientras se escribe: se valida solo contenido y destinos.
                body: JSON.stringify({ text: form.text, schedule: false, media: form.media, targets: form.targets }),
            });
            liveErrors.value = response.ok ? (await response.json()).errors : {};
        } catch {
            liveErrors.value = {};
        }
    };

    watch(() => [form.text, form.media, form.targets], () => {
        clearTimeout(timer);
        timer = setTimeout(validateNow, 500);
    }, { deep: true });

    if (immediate) validateNow();

    // Los mismos errores, normalizados `{ targetIndex, accountId, network, message }` (consumen ErrorsPanel y ActionBar).
    const errorList = computed(() => normalizeErrors(liveErrors.value, describeTarget));

    return { liveErrors, errorList, validateNow };
}
