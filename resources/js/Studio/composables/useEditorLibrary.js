import { computed, onScopeDispose, ref, watch } from 'vue';
import { attachStudioAsset, uploadStudioFile } from '../api.js';
import { completeStudioAudio, isStudioAudio, uploadStudioAudio } from '../audio-api.js';

export function useEditorLibrary(
    { initialAssets, initialFonts, brandId, busy, error, leftPanel, getAdapter, run, isActive },
    {
        uploadFile = uploadStudioFile,
        attachAsset = attachStudioAsset,
        uploadAudio = uploadStudioAudio,
        completeAudio = completeStudioAudio,
    } = {},
) {
    const fileInput = ref(null);
    const localAssets = ref(initialAssets ?? []);
    const localFonts = ref([...initialFonts]);

    const imageJobSource = ref(null);
    const aiPresetId = ref(null);
    const aiPresetToken = ref(0);
    const imageJobToken = ref(0);
    const imageBusy = ref(false);
    const assetQuery = ref('');
    const visibleAssets = computed(() =>
        localAssets.value.filter((asset) =>
            asset.name?.toLocaleLowerCase().includes(assetQuery.value.toLocaleLowerCase()),
        ),
    );

    const uploadStatus = ref('');
    const audioTickets = ref([]);
    const pendingAudio = computed(() => audioTickets.value[0] ?? null);
    const waitingBatches = [];
    let generation = 0;

    function clearQueue() {
        generation++;
        waitingBatches.length = 0;
        audioTickets.value = [];
        uploadStatus.value = '';
    }

    onScopeDispose(clearQueue);
    watch([isActive, brandId], clearQueue, { flush: 'sync' });
    // Canvas work and uploads share busy. Each release starts the oldest waiting selection.
    watch(busy, (value) => {
        if (!value) void flushUploads();
    });

    const isCurrent = (batch) => isActive() && generation === batch.generation && brandId() === batch.brand;

    async function upload(event) {
        const input = event.target;
        const files = [...(input.files ?? [])];
        input.value = '';
        if (!isActive() || !files.length) return;
        error.value = '';
        waitingBatches.push({ brand: brandId(), files, generation });
        if (busy.value) uploadStatus.value = 'Recursos pendientes de subir…';
        await flushUploads();
    }

    async function flushUploads() {
        if (busy.value || !waitingBatches.length) return;
        const batch = waitingBatches.shift();
        if (!isCurrent(batch)) return;
        const updateStatus = (value) => {
            if (isCurrent(batch)) uploadStatus.value = value;
        };
        busy.value = true;
        try {
            for (const file of batch.files) {
                if (!isCurrent(batch)) return;
                if (isStudioAudio(file)) {
                    try {
                        const asset = await uploadAudio(batch.brand, file, updateStatus);
                        if (isCurrent(batch)) registerAsset(asset);
                    } catch (cause) {
                        if (isCurrent(batch) && cause?.audioTicket) audioTickets.value.push(cause.audioTicket);
                        throw cause;
                    }
                    continue;
                }
                const uploaded = await uploadFile(file, updateStatus);
                if (!isCurrent(batch)) return;
                const resource = await attachAsset(batch.brand, uploaded.id);
                if (isCurrent(batch)) registerAsset(resource);
            }
            updateStatus('Recursos añadidos a esta marca.');
        } catch (cause) {
            if (isCurrent(batch)) error.value = cause instanceof Error ? cause.message : String(cause);
        } finally {
            busy.value = false;
        }
    }

    async function retryAudioValidation() {
        if (busy.value || !isActive() || !pendingAudio.value) return;
        const batch = { brand: brandId(), generation };
        const ticket = pendingAudio.value;
        busy.value = true;
        try {
            await run(async () => {
                const asset = await completeAudio(ticket);
                if (!isCurrent(batch)) return;
                registerAsset(asset);
                audioTickets.value.shift();
                uploadStatus.value = 'Audio validado y listo.';
            });
        } finally {
            busy.value = false;
        }
    }

    async function updateFonts({ fonts = [], deletedIds = [] }) {
        if (!isActive()) return;
        const next = new Map(localFonts.value.map((font) => [font.id, font]));
        fonts.forEach((font) => next.set(font.id, font));
        deletedIds.forEach((id) => next.delete(id));
        localFonts.value = [...next.values()];
        await run(async () => {
            await getAdapter()?.setFonts(localFonts.value);
        });
    }
    function registerAsset(asset) {
        if (!isActive()) return;
        localAssets.value = [asset, ...localAssets.value.filter((existing) => existing.id !== asset.id)];
        getAdapter()?.setAssets(localAssets.value);
    }
    function removeBackground(assetId) {
        if (!isActive() || imageBusy.value) return;
        const asset = localAssets.value.find((entry) => entry.id === assetId && entry.kind === 'image');
        if (!asset) {
            error.value = 'Selecciona una imagen de los recursos de esta marca.';
            return;
        }
        imageJobSource.value = asset;
        imageJobToken.value++;
        leftPanel.value = 'assets';
    }
    function editWithAi(assetId) {
        if (!isActive()) return;
        aiPresetId.value = assetId;
        aiPresetToken.value++;
        leftPanel.value = 'ai';
    }

    return {
        fileInput,
        localAssets,
        localFonts,
        assetQuery,
        visibleAssets,
        uploadStatus,
        pendingAudio,
        imageJobSource,
        aiPresetId,
        aiPresetToken,
        imageJobToken,
        imageBusy,
        upload,
        retryAudioValidation,
        updateFonts,
        registerImageResult: registerAsset,
        removeBackground,
        editWithAi,
    };
}
