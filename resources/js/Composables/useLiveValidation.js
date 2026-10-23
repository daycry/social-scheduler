import { ref, watch } from 'vue';

/**
 * Validación en vivo (sin guardar): límites de media por red y tipo, y texto demasiado largo.
 * Mismo endpoint (`brands.posts.validate`) y mismo debounce de 500 ms sobre [texto, media, destinos].
 */
export function useLiveValidation(form, { brandId, enabled, immediate = false }) {
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

    return { liveErrors, validateNow };
}
