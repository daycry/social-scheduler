import { computed, nextTick, ref } from 'vue';
import { compileTimeline } from '../timeline.js';
import { createTimelinePreview } from '../timeline-preview.js';

/** Aísla la escena de previsualización del lienzo que guarda el editor. */
export function useTimelinePlayback({
    documentData, pageId, localAssets, localFonts, chartResources, error, run, isDisposed, isPresenting,
}, {
    compile = compileTimeline,
    createPreview = createTimelinePreview,
    now = () => performance.now(),
    requestFrame = callback => requestAnimationFrame(callback),
    cancelFrame = id => cancelAnimationFrame(id),
} = {}) {
    const playing = ref(false);
    const timeline = ref(0);
    const timelineOpen = ref(documentData.value.schema_version === 2);
    const previewVisible = ref(false);
    const previewBusy = ref(false);
    const previewElement = ref(null);
    const timelinePlan = computed(() => documentData.value.schema_version === 2 ? compile(documentData.value) : null);
    const timelineDuration = computed(() => timelinePlan.value?.duration ?? 0);
    const timelinePageStart = computed(() => timelinePlan.value?.pages.find(item => item.id === pageId.value)?.start ?? null);

    let preview = null;
    let previewPromise = null;
    let previewCleanup = Promise.resolve();
    let previewGeneration = 0;
    let clockGeneration = 0;
    let clockFrame = null;

    function pauseTimeline(hide = false) {
        playing.value = false; ++clockGeneration;
        if (clockFrame !== null) cancelFrame(clockFrame);
        clockFrame = null; preview?.pause();
        if (hide) previewVisible.value = false;
    }
    function invalidatePreview() {
        pauseTimeline(true); ++previewGeneration;
        const old = preview; preview = null;
        if (old) previewCleanup = previewCleanup.then(() => old.dispose());
    }
    async function ensurePreview() {
        if (preview) return preview;
        if (previewPromise) return previewPromise;
        const generation = previewGeneration;
        previewPromise = (async () => {
            await previewCleanup; await nextTick();
            const created = await createPreview(previewElement.value, { document: documentData.value, assets: localAssets.value, fonts: localFonts.value, chartResources: chartResources.value });
            if (isDisposed() || generation !== previewGeneration) { await created.dispose(); throw new Error('El diseño cambió durante la previsualización.'); }
            preview = created; return created;
        })().finally(() => { previewPromise = null; });
        return previewPromise;
    }
    async function seekTimeline(seconds) {
        pauseTimeline();
        return run(async () => {
            timeline.value = Math.max(0, Math.min(timelineDuration.value, seconds));
            previewBusy.value = true; previewVisible.value = true;
            try { await (await ensurePreview()).seek(timeline.value); }
            finally { previewBusy.value = false; }
        });
    }
    async function playTimeline() {
        if (isPresenting()) return;
        if (playing.value) { pauseTimeline(); return; }
        const generation = ++clockGeneration;
        await run(async () => {
            if (timeline.value >= timelineDuration.value) timeline.value = 0;
            previewBusy.value = true;
            let instance;
            try {
                instance = await ensurePreview();
                await instance.seek(timeline.value);
            }
            finally { previewBusy.value = false; }
            if (isDisposed() || generation !== clockGeneration || !timelineOpen.value) return;
            previewVisible.value = true; playing.value = true;
            let origin = now() - timeline.value * 1000;
            const frame = async () => {
                if (isDisposed() || generation !== clockGeneration || !playing.value) return;
                const time = Math.min(timelineDuration.value, (now() - origin) / 1000);
                try {
                    const result = await instance.seek(time, { play: time < timelineDuration.value });
                    if (generation !== clockGeneration || isDisposed()) return;
                    origin += result?.bufferingMs ?? 0;
                    timeline.value = time;
                    if (time >= timelineDuration.value) { pauseTimeline(); return; }
                    clockFrame = requestFrame(frame);
                } catch (e) { pauseTimeline(); error.value = e.message; }
            };
            await frame();
        });
    }

    const waitForPreviewCreation = () => previewPromise?.catch(() => {});
    const waitForPreviewCleanup = () => previewCleanup;

    return {
        playing, timeline, timelineOpen, previewVisible, previewBusy, previewElement,
        timelineDuration, timelinePageStart, pauseTimeline, invalidatePreview, seekTimeline, playTimeline,
        waitForPreviewCreation, waitForPreviewCleanup,
    };
}
