import { computed, nextTick, onScopeDispose, reactive, ref } from 'vue';
import { attachStudioAsset } from '../Studio/api.js';

export function useStudioAssetLinks({ attach = attachStudioAsset, timeoutMs = 15000 } = {}) {
    const links = reactive(new Map());
    const pendingLinks = computed(() => [...links.values()]);
    const announcement = ref('');
    const announcementBrandId = ref(null);
    let disposed = false;
    // Al cambiar de pantalla, deja que la vinculación ya iniciada termine con su propio plazo.
    onScopeDispose(() => { disposed = true; });

    async function announce(brandId, message) {
        announcement.value = '';
        await nextTick();
        if (!disposed) {
            announcementBrandId.value = brandId;
            announcement.value = message;
        }
    }

    async function linkAsset(brandId, asset) {
        const key = `${brandId}:${asset.id}`;
        const previous = links.get(key);
        if (!brandId || disposed || previous?.state === 'linking') return;

        const entry = { key, brandId, asset, state: 'linking', retry: previous?.retry ?? false, error: '' };
        links.set(key, entry);
        const controller = new AbortController();
        const deadline = setTimeout(() => controller.abort(), timeoutMs);
        try {
            await attach(brandId, asset.id, { signal: controller.signal });
            if (!disposed) {
                links.delete(key);
                if (entry.retry) await announce(brandId, `${asset.name} se añadió a Recursos.`);
            }
        } catch (error) {
            if (disposed) return;
            const status = error?.status;
            const temporary = !status || status >= 500 || [408, 425, 429].includes(status);
            const errorMessage = status === 419 ? 'La sesión ha caducado. Vuelve a iniciar sesión para añadirlo a Recursos.'
                : (temporary ? '' : (error?.message ?? ''));
            links.set(key, { ...entry, state: 'failed', retry: temporary, error: errorMessage });
            await announce(brandId, `${asset.name} se subió. No se pudo añadir a Recursos. ${errorMessage}`.trim());
        } finally {
            clearTimeout(deadline);
        }
    }

    function dismiss(key) {
        if (links.get(key)?.state === 'failed') links.delete(key);
    }

    return { pendingLinks, announcement, announcementBrandId, linkAsset, dismiss };
}
