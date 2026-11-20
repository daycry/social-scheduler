import { computed, nextTick, onScopeDispose, ref, watch } from 'vue';
import { HttpError } from '../lib/http.js';

const defaultName = 'Diseño sin título';
const defaultSize = 1080;
const transportCodes = ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'];

export function useStudioCreation({ getBrandId, save, router, busy, error, getLocation = () => location.href }) {
    const createOpen = ref(false);
    const createError = ref('');
    const openDesignError = ref('');
    const createdDesign = ref(null);
    const opening = ref(false);
    const creationNotice = computed(() => {
        if (!createdDesign.value) return '';
        return opening.value ? 'Abriendo el editor…' : 'El diseño está guardado.';
    });
    const createName = ref(defaultName);
    const width = ref(defaultSize);
    const height = ref(defaultSize);
    const createNameInput = ref(null);
    const openCreatedButton = ref(null);
    const openCreatedBannerButton = ref(null);
    let stopWatchingNetwork;
    let disposed = false;

    function stopWatching() {
        stopWatchingNetwork?.();
        stopWatchingNetwork = undefined;
    }

    function clearCreatedDesign() {
        createdDesign.value = null;
        openDesignError.value = '';
    }

    watch(getBrandId, clearCreatedDesign);
    watch(createOpen, () => { createError.value = ''; });
    watch([createOpen, createdDesign, openDesignError], ([open, saved, failure]) => {
        if (!saved) return;
        if (open) openCreatedButton.value?.focus();
        else if (failure) openCreatedBannerButton.value?.focus();
    }, { flush: 'post' });
    onScopeDispose(() => {
        disposed = true;
        stopWatching();
    });

    function visitCreatedDesign() {
        const saved = createdDesign.value;
        if (!saved || busy.value) return;
        let opened = false;
        router.visit(saved.href, {
            onStart() {
                stopWatching();
                opening.value = true;
                busy.value = true;
                openDesignError.value = '';
                const base = getLocation();
                const target = new URL(saved.href, base).href;
                stopWatchingNetwork = router.on('exception', ({ detail: { exception } }) => {
                    const { code, config } = exception;
                    if (!transportCodes.includes(code) || config?.method !== 'get' || !config.url) return;
                    try {
                        // Este GET tiene su propio aviso y reintento en lugar del manejo por defecto de Inertia.
                        if (new URL(config.url, base).href === target) return false;
                    } catch {
                        // Otras visitas conservan el comportamiento de Inertia.
                    }
                });
            },
            onSuccess(page) {
                opened = page.component === 'Studio/Editor';
                if (opened && createdDesign.value === saved) router.flash('success', saved.message);
            },
            onFinish(visit) {
                stopWatching();
                opening.value = false;
                busy.value = false;
                // Otra navegación puede interrumpir esta visita sin un fallo de red.
                if (!disposed && !opened && createdDesign.value === saved && !visit.cancelled && !visit.interrupted) {
                    openDesignError.value = 'No se pudo abrir el editor. Inténtalo de nuevo.';
                }
            },
        });
    }

    async function startAnotherDesign() {
        if (busy.value) return;
        clearCreatedDesign();
        createName.value = defaultName;
        width.value = defaultSize;
        height.value = defaultSize;
        createOpen.value = true;
        await nextTick();
        createNameInput.value?.focus();
    }

    async function create(template = null) {
        const brandId = getBrandId();
        if (!brandId || busy.value || createdDesign.value) return;
        busy.value = true;
        createError.value = '';
        error.value = '';
        try {
            const created = await save({
                brandId, template, name: template ? template.name : (createName.value.trim() || defaultName),
                width: Number(width.value), height: Number(height.value),
            });
            if (disposed || brandId !== getBrandId()) return;
            createdDesign.value = created;
        } catch (failure) {
            if (disposed || brandId !== getBrandId()) return;
            const message = failure instanceof HttpError ? failure.message : 'No se pudo crear el diseño. Inténtalo de nuevo.';
            const destination = createOpen.value ? createError : error;
            destination.value = message;
            return;
        } finally {
            busy.value = false;
        }
        visitCreatedDesign();
    }

    return {
        createOpen, createError, openDesignError, createdDesign, creationNotice, createName, width, height,
        createNameInput, openCreatedButton, openCreatedBannerButton, create, visitCreatedDesign, startAnotherDesign,
    };
}
