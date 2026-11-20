import { computed, ref } from 'vue';
import { cloneDocument, findPage, validateDocument } from '../document.js';
import { captionOperation } from '../captions.js';
import { clipMediaState, splitTimelineElement, splitTimelineAudio } from '../timeline.js';

/** Edita la cronología del documento y aplica el resultado al lienzo del editor. */
export function useTimelineEditing({
    documentData, page, pageId, selection, localAssets,
    timeline, timelineDuration, timelinePageStart, timelineOpen, pauseTimeline,
    error, run, commit, reloadCanvas, selectOnCanvas, isCaptionBlocked,
}) {
    const selectedAudioId = ref('');
    const selectedClipId = computed(() => selection.value.length === 1 ? selection.value[0].id : null);
    const audioProjectDuration = computed(() => documentData.value.pages.reduce((total, page) => total + page.duration - (page.transition?.duration ?? 0), 0));

    async function openTimeline() {
        if (timelineOpen.value) { timelineOpen.value = false; pauseTimeline(true); return; }
        await run(async () => {
            const selected = selectedClipId.value;
            if (documentData.value.schema_version === 1) {
                documentData.value = validateDocument({ ...cloneDocument(documentData.value), schema_version: 2, timeline: { fps: 30, audio_tracks: [] } });
                commit(); await reloadCanvas();
                if (selected) selectOnCanvas(selected);
            }
            timelineOpen.value = true;
        });
    }
    async function applyTimelineChange(next, selected = selectedClipId.value) {
        documentData.value = validateDocument(next); commit();
        timeline.value = Math.min(timeline.value, timelineDuration.value);
        await reloadCanvas(); if (selected) selectOnCanvas(selected);
    }
    async function applyCaptions(operation) {
        if (isCaptionBlocked()) throw new Error('Espera a que termine la operación actual antes de editar subtítulos.');
        await applyTimelineChange(captionOperation(documentData.value, operation));
    }
    function patchClipWindow(patch) {
        return run(async () => {
            const next = cloneDocument(documentData.value);
            const clip = findPage(next, pageId.value).elements.find(item => item.id === selectedClipId.value);
            if (!clip) return;
            const old = clip.timing ?? { start: 0, duration: page.value.duration };
            const timing = { ...old, ...patch };
            if (patch.start !== undefined) timing.duration = Math.min(timing.duration, page.value.duration - timing.start);
            if (clip.gainPoints && old.duration !== timing.duration) clip.gainPoints = clip.gainPoints.map(point => ({ ...point, time: point.time / old.duration * timing.duration }));
            clip.timing = timing;
            await applyTimelineChange(next);
        });
    }
    function splitClip() {
        return run(async () => {
            const pageStart = timelinePageStart.value;
            if (pageStart === null) throw new Error('La página no está en la línea de tiempo.');
            const clip = page.value.elements.find(item => item.id === selectedClipId.value);
            const sourceDuration = clip?.type === 'video' ? Number(localAssets.value.find(asset => asset.id === clip.asset_id)?.duration) : undefined;
            const next = splitTimelineElement(documentData.value, pageId.value, selectedClipId.value, timeline.value - pageStart, { sourceDuration });
            await applyTimelineChange(next);
        });
    }
    function patchTimelineFps(fps) { return run(() => applyTimelineChange({ ...cloneDocument(documentData.value), timeline: { ...documentData.value.timeline, fps } })); }

    function addAudioTrack(asset) {
        return run(async () => {
            pauseTimeline(true);
            const next = cloneDocument(documentData.value);
            if (next.schema_version === 1) { next.schema_version = 2; next.timeline = { fps: 30, audio_tracks: [] }; }
            const total = audioProjectDuration.value;
            const start = timeline.value < total - 1 / next.timeline.fps ? timeline.value : 0;
            const id = crypto.randomUUID();
            next.timeline.audio_tracks.push({ id, asset_id: asset.id, start, duration: Math.min(Number(asset.duration), total - start),
                trimStart: 0, trimEnd: null, playbackRate: 1, sourceOffset: 0, loop: false, volume: 1, muted: false });
            await applyTimelineChange(next); selectedAudioId.value = id; timelineOpen.value = true;
        });
    }
    function patchAudio(patch) {
        return run(async () => {
            const next = cloneDocument(documentData.value);
            const track = next.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
            if (!track) return;
            const duration = track.duration;
            Object.assign(track, patch);
            if (patch.start !== undefined) track.duration = Math.min(track.duration, audioProjectDuration.value - track.start);
            if (track.gainPoints && duration !== track.duration) track.gainPoints = track.gainPoints.map(point => ({ ...point, time: point.time / duration * track.duration }));
            const asset = localAssets.value.find(asset => asset.id === track.asset_id);
            clipMediaState(track, track.start, Number(asset?.duration));
            await applyTimelineChange(next);
        });
    }
    function fadeAudio(fades) {
        const track = documentData.value.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
        if (!track) return;
        if (![fades.in, fades.out].every(value => Number.isFinite(value) && value >= 0) || fades.in + fades.out > track.duration) {
            error.value = 'Los fundidos deben quedar dentro de la duración del audio.'; return;
        }
        const points = new Map([[0, fades.in ? 0 : 1], [track.duration, fades.out ? 0 : 1]]);
        if (fades.in) points.set(fades.in, 1);
        if (fades.out) points.set(track.duration - fades.out, 1);
        return patchAudio({ gainPoints: [...points].sort(([a], [b]) => a - b).map(([time, value]) => ({ time, value })) });
    }
    function splitAudio() {
        return run(async () => {
            const track = documentData.value.timeline.audio_tracks.find(item => item.id === selectedAudioId.value);
            if (!track) return;
            const asset = localAssets.value.find(asset => asset.id === track.asset_id);
            await applyTimelineChange(splitTimelineAudio(documentData.value, track.id, timeline.value, { sourceDuration: Number(asset?.duration) }));
        });
    }
    function removeAudio() {
        return run(async () => {
            const next = cloneDocument(documentData.value);
            next.timeline.audio_tracks = next.timeline.audio_tracks.filter(item => item.id !== selectedAudioId.value);
            await applyTimelineChange(next); selectedAudioId.value = '';
        });
    }

    return {
        selectedAudioId, selectedClipId, openTimeline, applyCaptions,
        patchClipWindow, splitClip, patchTimelineFps, addAudioTrack, patchAudio, fadeAudio, splitAudio, removeAudio,
    };
}
