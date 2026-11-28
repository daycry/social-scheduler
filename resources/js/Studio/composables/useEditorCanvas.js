import { computed, ref } from 'vue';
import { containsVideo, findPage } from '../document.js';
import { createStudioCanvas } from '../canvas.js';

export function useEditorCanvas(
    {
        documentData,
        page,
        pageId,
        getKit,
        getChartResources,
        getAssets,
        getFonts,
        error,
        run,
        commit,
        isDisposed,
        invalidatePreview,
        pauseTimeline,
        isPresenting,
        isPlaying,
        setPlaying,
    },
    { createCanvas = createStudioCanvas, createResizeObserver = (callback) => new ResizeObserver(callback) } = {},
) {
    const selection = ref([]);
    const layers = ref(page.value.elements);

    const canvasElement = ref(null);
    const stage = ref(null);
    const ready = ref(false);
    const busy = ref(false);
    const fontBusy = ref(false);
    const canvasWarning = ref('');

    const zoom = ref(50);
    const fitMode = ref(true);
    const drawing = ref(false);
    const drawColor = ref(getKit()?.palette?.[0] ?? '#7c3aed');
    const drawWidth = ref(8);

    const hasVideo = computed(() => containsVideo(page.value.elements));
    let adapter;
    let resizeObserver;
    let mounting;
    let disposing;
    let canvasGeneration = 0;
    let playbackGeneration = 0;
    let playbackPromise;

    function refreshLayers() {
        layers.value = adapter?.layers() ?? page.value.elements;
    }
    function fit() {
        if (!adapter || !stage.value) return;
        fitMode.value = true;
        const width = Math.max(80, stage.value.clientWidth - 48);
        const height = Math.max(180, stage.value.clientHeight - 72);
        zoom.value = Math.max(
            5,
            Math.min(
                100,
                Math.floor(Math.min(width / documentData.value.width, height / documentData.value.height) * 100),
            ),
        );
        adapter.zoom(zoom.value / 100);
    }
    function setZoom(value) {
        fitMode.value = false;
        zoom.value = Math.max(5, Math.min(400, Number(value)));
        adapter?.zoom(zoom.value / 100);
    }
    async function reloadCanvas() {
        if (!adapter) return;
        canvasWarning.value = '';
        invalidatePreview();
        busy.value = true;
        setPlaying(false);
        try {
            await adapter.loadDocument(documentData.value, pageId.value);
            refreshLayers();
            if (fitMode.value) fit();
        } finally {
            busy.value = false;
        }
    }

    const action = (method, value) =>
        run(async () => {
            if (busy.value) return;
            canvasWarning.value = '';
            busy.value = true;
            try {
                await adapter?.[method](value);
                refreshLayers();
            } finally {
                busy.value = false;
            }
        });
    const patchSelection = (patch) =>
        run(async () => {
            if (fontBusy.value) return;
            const loadsFont = Object.hasOwn(patch, 'font_id');
            if (loadsFont) fontBusy.value = true;
            try {
                await adapter.updateSelection(patch);
                refreshLayers();
            } finally {
                if (loadsFont) fontBusy.value = false;
            }
        });
    const addText = (content) =>
        run(async () => {
            if (fontBusy.value) return;
            const fontId = getKit()?.font_ids?.[0];
            if (fontId) fontBusy.value = true;
            try {
                await adapter.addText(typeof content === 'string' ? content : 'Escribe tu texto', {
                    fontFamily: getKit()?.fonts?.[0] ?? 'Arial',
                    ...(fontId ? { font_id: fontId } : {}),
                    fill: getKit()?.palette?.[0] ?? '#111827',
                });
                refreshLayers();
            } finally {
                if (fontId) fontBusy.value = false;
            }
        });
    const addShape = (type) => action('addShape', type);
    function selectLayer(id, multiple = false) {
        pauseTimeline(true);
        run(() => {
            const ids = new Set(multiple ? selection.value.map((item) => item.id) : []);
            if (ids.has(id)) ids.delete(id);
            else ids.add(id);
            adapter.select([...ids]);
        });
    }
    function patchLayer(id, patch) {
        return run(async () => {
            if (busy.value || !adapter) return;
            const layer = layers.value.find((item) => item.id === id);
            if (!layer) throw new Error('La capa ya no está en el diseño.');
            const scene = adapter;
            const restoreLock = layer.locked && patch.locked === undefined;
            busy.value = true;
            try {
                scene.select(id);
                try {
                    await scene.updateSelection(restoreLock ? { ...patch, locked: false } : patch);
                } finally {
                    if (scene === adapter && restoreLock) await scene.lock(true);
                }
                refreshLayers();
            } finally {
                busy.value = false;
            }
        });
    }

    function toggleDrawing() {
        drawing.value = !drawing.value;
        adapter?.setDrawing(drawing.value, drawColor.value, drawWidth.value);
    }

    async function togglePlayback() {
        if (isPresenting() || !adapter || isDisposed()) return;
        if (playbackPromise) return playbackPromise;
        const scene = adapter;
        const generation = playbackGeneration;
        playbackPromise = run(async () => {
            if (isPlaying()) {
                scene.pause();
                setPlaying(false);
                return;
            }
            await scene.play();
            if (isDisposed() || scene !== adapter) return;
            if (generation !== playbackGeneration) {
                scene.pause();
                return;
            }
            setPlaying(true);
        });
        try {
            await playbackPromise;
        } finally {
            playbackPromise = undefined;
        }
    }

    async function suspendPlayback() {
        ++playbackGeneration;
        adapter?.pause();
        setPlaying(false);
        await playbackPromise;
        adapter?.pause();
    }

    function mountCanvas() {
        mounting ??= initializeCanvas().finally(() => {
            mounting = undefined;
        });
        return mounting;
    }

    async function initializeCanvas() {
        const generation = canvasGeneration;
        const scene = await createCanvas(canvasElement.value, {
            chartResources: getChartResources(),
            document: documentData.value,
            pageId: pageId.value,
            assets: getAssets(),
            fonts: getFonts(),
            onChange(elements, meta) {
                if (isDisposed()) return;
                const changedPage = findPage(documentData.value, meta.pageId);
                if (!changedPage) return;
                changedPage.elements = elements;
                changedPage.background = meta.background;
                layers.value = elements;
                commit();
            },
            onSelection(items) {
                if (!isDisposed()) selection.value = items;
            },
        });
        if (isDisposed() || generation !== canvasGeneration) {
            await scene.destroy();
            return;
        }
        adapter = scene;
        adapter.canvas.on('studio:error', ({ error: e }) => {
            if (!isDisposed() && adapter === scene) error.value = e.message;
        });
        adapter.canvas.on('studio:warning', ({ message }) => {
            if (!isDisposed() && adapter === scene) canvasWarning.value = message;
        });
        ready.value = true;
        refreshLayers();
        fit();
        resizeObserver = createResizeObserver(() => {
            if (fitMode.value) fit();
        });
        resizeObserver.observe(stage.value);
    }

    function disposeCanvas() {
        if (disposing) return disposing;
        ++canvasGeneration;
        ++playbackGeneration;
        const scene = adapter;
        adapter = undefined;
        resizeObserver?.disconnect();
        resizeObserver = undefined;
        disposing = Promise.allSettled([mounting, Promise.resolve().then(() => scene?.destroy())]).finally(() => {
            disposing = undefined;
        });
        return disposing;
    }

    const getAdapter = () => adapter;

    return {
        selection,
        layers,
        canvasElement,
        stage,
        ready,
        busy,
        fontBusy,
        canvasWarning,
        zoom,
        drawing,
        drawColor,
        drawWidth,
        hasVideo,
        getAdapter,
        refreshLayers,
        fit,
        setZoom,
        reloadCanvas,
        action,
        patchSelection,
        addText,
        addShape,
        selectLayer,
        patchLayer,
        toggleDrawing,
        mountCanvas,
        disposeCanvas,
        togglePlayback,
        suspendPlayback,
    };
}
