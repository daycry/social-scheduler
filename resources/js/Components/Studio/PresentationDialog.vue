<script setup>
import BaseDialog from '@/Components/BaseDialog.vue';
import { nextTick, onBeforeUnmount, ref, shallowRef } from 'vue';
import { createPresentationPlayer, presentationKey, presentationPreviewScale } from '@/Studio/presentation-player';
import { createTimelinePreview } from '@/Studio/timeline-preview';
import { createAudienceWindow } from '@/Studio/presentation-audience';
import { createPresentationTools, presentationPoint, paintPresentationTools, presentationCursorStyle } from '@/Studio/presentation-tools';
import { createPresentationTimer } from '@/Studio/presentation-timer';

const props = defineProps({ document: { type: Object, required: true }, assets: { type: Array, default: () => [] }, fonts: { type: Array, default: () => [] }, chartResources: Object });
const emit = defineEmits(['close']);
const presentationOpen = ref(true), currentCanvas = ref(null), nextCanvas = ref(null);
const state = shallowRef({ pageIds: [], notes: '', nextPageId: null, playing: false });
const mode = ref('presenter'), busy = ref(true), message = ref(''), musicMessage = ref('');
let player = null, nextPlayer = null, audience = null, lastPixels = null, closed = false, generation = 0;
let nextPageId = null, compositionFrame = null, composing = false, activePointer = null;
const tools = createPresentationTools({ onChange: value => { toolState.value = value; scheduleComposition(); } });
const toolState = shallowRef(tools.snapshot());
const timer = createPresentationTimer({ assets: props.assets, onChange: value => { timerState.value = value; scheduleComposition(); },
    onError: error => { if (!closed) musicMessage.value = error?.message ?? String(error); } });
const timerState = shallowRef(timer.snapshot());
function report(error) { if (!closed) message.value = error?.message ?? String(error); }
function scheduleComposition() {
    if (closed || composing || !lastPixels || compositionFrame !== null) return;
    compositionFrame = requestAnimationFrame(() => { compositionFrame = null; compose(); });
}
function compose() {
    if (closed || !lastPixels || !currentCanvas.value) return;
    composing = true;
    try {
        tools.tick();
        const output = currentCanvas.value;
        if (output.width !== lastPixels.width) output.width = lastPixels.width;
        if (output.height !== lastPixels.height) output.height = lastPixels.height;
        const context = output.getContext('2d');
        if (!context) throw new Error('No se pudo dibujar la diapositiva.');
        if (state.value.blackout) { context.fillStyle = '#000'; context.fillRect(0, 0, output.width, output.height); }
        else {
            context.clearRect(0, 0, output.width, output.height);
            context.drawImage(lastPixels, 0, 0);
            paintPresentationTools(context, toolState.value, { width: output.width, height: output.height, timer: timerState.value });
        }
        const cursor = state.value.blackout ? 'none' : presentationCursorStyle(toolState.value);
        output.style.cursor = cursor;
        audience?.setCursor(cursor);
        if (audience && !audience.paint(output, state.value)) audience = null;
    } catch (error) { report(error); }
    finally { composing = false; }
    if (toolState.value.needsFrame) scheduleComposition();
}
function paint(canvas, value) {
    if (closed) return;
    if (value.pageId !== toolState.value.pageId) { releasePointer(); tools.setPage(value.pageId); }
    if (value.blackout && !state.value.blackout) { releasePointer(); tools.pointerLeave(); }
    // Renderer staging is mutable. Retain an owned copy of the last committed
    // real frame so pointer/timer changes never sample an in-flight seek.
    if (!lastPixels) lastPixels = globalThis.document.createElement('canvas');
    if (lastPixels.width !== canvas.width) lastPixels.width = canvas.width;
    if (lastPixels.height !== canvas.height) lastPixels.height = canvas.height;
    const baseContext = lastPixels.getContext('2d');
    if (!baseContext) throw new Error('No se pudo conservar la diapositiva.');
    baseContext.clearRect(0, 0, lastPixels.width, lastPixels.height);
    baseContext.drawImage(canvas, 0, 0);
    state.value = value;
    scheduleComposition();
}
function releasePointer() {
    if (!activePointer) return;
    const { canvas, id } = activePointer; activePointer = null;
    if (canvas.hasPointerCapture?.(id)) canvas.releasePointerCapture(id);
}
function pointer(event, canvas = event.currentTarget) {
    if (closed || state.value.blackout || !canvas) return;
    if (activePointer && (event.pointerId !== activePointer.id || canvas !== activePointer.canvas)) return;
    const point = presentationPoint(canvas.getBoundingClientRect(), canvas.width, canvas.height, event.clientX, event.clientY);
    if (event.type === 'pointerdown') {
        if (!point || (event.button !== undefined && event.button !== 0)) return;
        event.preventDefault?.(); activePointer = { canvas, id: event.pointerId };
        canvas.setPointerCapture?.(event.pointerId); tools.pointerDown(point, event.pointerId);
    } else if (event.type === 'pointermove') {
        if (!point && activePointer) { tools.pointerUp(event.pointerId); releasePointer(); tools.pointerLeave(); }
        else tools.pointerMove(point, event.pointerId);
    } else if (event.type === 'pointerup') {
        if (point) tools.pointerMove(point, event.pointerId);
        tools.pointerUp(event.pointerId); releasePointer();
        if (!point) tools.pointerLeave();
    } else if (event.type === 'pointercancel' || event.type === 'pointerleave' || event.type === 'lostpointercapture') {
        tools.pointerUp(event.pointerId); releasePointer(); tools.pointerLeave();
    }
}
function toolAct(command, value) {
    if (closed) return;
    try { if (command === 'setTool' || command === 'clear') releasePointer(); tools[command](value); }
    catch (error) { report(error); }
}
function timerAct(command, value) {
    if (closed) return;
    try {
        if (command === 'start' || command === 'setMusic') musicMessage.value = '';
        // Call before awaiting: native play must remain inside the user gesture.
        const pending = timer[command](value);
        Promise.resolve(pending).catch(error => { if (!closed) musicMessage.value = error?.message ?? String(error); });
    } catch (error) { if (!closed) musicMessage.value = error?.message ?? String(error); }
}
function update(value) {
    if (closed) return;
    if (value.pageId !== state.value.pageId) { releasePointer(); tools.setPage(value.pageId); }
    if (value.blackout && !state.value.blackout) { releasePointer(); tools.pointerLeave(); }
    state.value = value;
    scheduleComposition();
    if (value.error) report(new Error(value.error));
    if (nextPlayer && value.nextPageId && nextPageId !== value.nextPageId) {
        nextPageId = value.nextPageId;
        void nextPlayer.select(nextPageId).catch(report);
    }
}
async function act(command, value) {
    if (!player || closed) return;
    try {
        if (command === 'togglePlay') { if (state.value.playing) player.pause(); else await player.play(); }
        else if (command === 'toggleBlackout') await player.setBlackout(!state.value.blackout);
        else await player[command](value);
    } catch (error) { report(error); }
}
function keydown(event) {
    // A modal owns every key; Editor's bubbling window shortcuts cannot leak.
    event.stopPropagation();
    if ((event.key === ' ' || event.key === 'Spacebar') && event.target?.closest?.('button, summary')) return;
    if (event.key === ' ' && /^(BUTTON|SUMMARY)$/.test(event.target?.tagName)) return;
    const command = presentationKey(event);
    if (!command) return;
    event.preventDefault();
    if (command === 'close') close(); else void act(command);
}
function openAudience() {
    try {
        audience?.close(); audience = null;
        if (compositionFrame !== null) { cancelAnimationFrame(compositionFrame); compositionFrame = null; compose(); }
        audience = createAudienceWindow({ onKey: keydown, onPointer: pointer, onError: report });
        audience.setCursor(state.value.blackout ? 'none' : presentationCursorStyle(toolState.value));
        if (lastPixels) audience.paint(currentCanvas.value, state.value);
        message.value = '';
    } catch (error) { report(error); }
}
async function fullscreen() {
    const token = generation, target = audience;
    try {
        if (target) {
            if (await target.fullscreen() === false) return;
        }
        else {
            if (!currentCanvas.value?.requestFullscreen) throw new Error('La pantalla completa no está disponible en este navegador.');
            await currentCanvas.value.requestFullscreen();
        }
        if (closed || token !== generation || audience !== target) return;
        message.value = '';
    } catch (error) { if (token === generation && audience === target) report(error); }
}
function cleanup() {
    if (closed) return;
    closed = true; ++generation;
    if (compositionFrame !== null) cancelAnimationFrame(compositionFrame);
    compositionFrame = null; releasePointer(); tools.dispose(); timer.dispose();
    if (lastPixels) { lastPixels.width = 0; lastPixels.height = 0; }
    audience?.close(); audience = null; lastPixels = null;
    void player?.dispose().catch(() => {}); void nextPlayer?.dispose().catch(() => {});
    presentationOpen.value = false;
}
function close() { if (closed) return; cleanup(); emit('close'); }
async function initialize() {
    await nextTick();
    if (closed) return;
    const token = ++generation;
    try {
        player = createPresentationPlayer({ document: props.document, output: currentCanvas.value, assets: props.assets, fonts: props.fonts, chartResources: props.chartResources,
            createRenderer: createTimelinePreview, publish: paint, onState: update });
        // The next-slide scene is explicitly offline: audio tracks never play,
        // video is muted, and it has its own disposable canvas/resources.
        nextPlayer = createPresentationPlayer({ document: props.document, output: nextCanvas.value, assets: props.assets, fonts: props.fonts, chartResources: props.chartResources,
            createRenderer: (output, options) => createTimelinePreview(output, { ...options, offline: true, renderScale: presentationPreviewScale(options.document.width, options.document.height) }),
            onState: value => { if (value.error) { player?.pause(); report(new Error(value.error)); } } });
        await nextTick(); if (closed || token !== generation) return;
        await player.ready(); if (closed || token !== generation) return;
        if (state.value.nextPageId) { nextPageId = state.value.nextPageId; await nextPlayer.select(nextPageId); }
        if (closed || token !== generation) return;
        busy.value = false;
    } catch (error) { if (token === generation) { player?.pause(); report(error); busy.value = false; } }
}
defineExpose({ close });
onBeforeUnmount(cleanup);
const secondaryButtonClass =
    'btn-secondary border-chrome-border bg-chrome-panel px-3 text-xs font-medium text-chrome-text shadow-none hover:bg-chrome-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-focus disabled:opacity-40';
</script>

<template>
    <BaseDialog
        :open="presentationOpen"
        aria-label="Presentación"
        class="m-auto w-[calc(100vw_-_1rem)] max-w-7xl rounded-2xl border-0 bg-chrome p-0 text-chrome-strong shadow-2xl backdrop:bg-overlay-strong/80"
        @keydown="keydown"
        @opened="initialize"
        @close="close"
    >
        <div class="flex max-h-[95dvh] flex-col overflow-y-auto p-3 sm:p-5">
            <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div
                    ><h2 class="text-lg font-semibold">Presentación</h2
                    ><p
                        class="text-xs text-chrome-muted"
                        role="status"
                        >{{ busy ? 'Preparando medios…' : `${state.index + 1} / ${state.pageIds.length} · ${state.pageName}` }}</p
                    ></div
                >
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <label class="sr-only" for="presentation-view">Vista de presentación</label
                    ><select
                        id="presentation-view"
                        v-model="mode"
                        class="field w-auto border-chrome-border bg-chrome-panel text-xs text-chrome-text"
                        ><option value="presenter">Presentador</option
                        ><option value="audience">Audiencia</option></select
                    >
                    <button type="button" :class="secondaryButtonClass" @click="openAudience">Abrir audiencia</button>
                    <button type="button" :class="secondaryButtonClass" @click="fullscreen">Pantalla completa</button>
                    <button type="button" :class="secondaryButtonClass" aria-label="Cerrar presentación" @click="close"
                        >Cerrar</button
                    >
                </div>
            </header>
            <p
                v-if="message"
                role="alert"
                class="mb-3 rounded-lg bg-danger-soft p-3 text-sm text-danger-ink"
                >{{ message }}</p
            >
            <details class="mb-4 rounded-xl bg-chrome p-3">
                <summary class="cursor-pointer text-sm font-semibold">Herramientas</summary>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <fieldset class="flex min-w-0 flex-wrap items-center gap-3 text-xs">
                        <legend class="mb-2 font-semibold text-chrome-muted">Cursor y trazos</legend>
                        <label
                            >Cursor<select
                                aria-label="Cursor de presentación"
                                class="field ml-2 inline-block w-auto border-chrome-border bg-chrome-panel text-xs text-chrome-text"
                                :value="toolState.cursor"
                                @change="toolAct('setCursor', $event.target.value)"
                                ><option value="normal">Normal</option
                                ><option value="laser">Láser</option
                                ><option value="hidden">Oculto</option></select
                            ></label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                aria-label="Dibujar"
                                type="checkbox"
                                :checked="toolState.tool === 'pen'"
                                @change="toolAct('setTool', $event.target.checked ? 'pen' : 'pointer')"
                            />Dibujar</label
                        >
                        <label class="flex items-center gap-2"
                            >Color<input
                                aria-label="Color del trazo"
                                type="color"
                                :value="toolState.penColor"
                                @input="toolAct('setPenColor', $event.target.value)"
                        /></label>
                        <label class="flex items-center gap-2"
                            >Grosor<input
                                aria-label="Grosor del trazo"
                                class="w-24"
                                type="range"
                                min="0.001"
                                max="0.04"
                                step="0.001"
                                :value="toolState.penWidth"
                                @input="toolAct('setPenWidth', Number($event.target.value))"
                        /></label>
                        <label
                            >Duración del trazo (s)<input
                                aria-label="Duración de los trazos en segundos"
                                class="ml-2 w-16 rounded bg-chrome-panel text-xs"
                                type="number"
                                min="1"
                                max="10"
                                step="1"
                                :value="toolState.trailSeconds"
                                @change="toolAct('setTrailSeconds', Number($event.target.value))"
                        /></label>
                        <button :class="secondaryButtonClass" type="button" @click="toolAct('clear')"
                            >Borrar trazos</button
                        >
                    </fieldset>
                    <fieldset class="flex min-w-0 flex-wrap items-center gap-3 text-xs">
                        <legend class="mb-2 font-semibold text-chrome-muted">Temporizador</legend>
                        <output aria-label="Tiempo restante" class="font-mono text-lg">{{ timerState.label }}</output>
                        <label
                            >Duración (s)<input
                                aria-label="Duración del temporizador en segundos"
                                class="ml-2 w-24 rounded bg-chrome-panel text-xs"
                                type="number"
                                min="1"
                                max="86400"
                                step="1"
                                :value="timerState.durationMs / 1000"
                                @change="timerAct('configure', Number($event.target.value))"
                        /></label>
                        <button
                            v-if="timerState.status !== 'running'"
                            :class="secondaryButtonClass"
                            type="button"
                            @click="timerAct('start')"
                            >Iniciar temporizador</button
                        >
                        <button v-else :class="secondaryButtonClass" type="button" @click="timerAct('pause')"
                            >Pausar temporizador</button
                        >
                        <button :class="secondaryButtonClass" type="button" @click="timerAct('reset')"
                            >Reiniciar temporizador</button
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                aria-label="Mostrar temporizador a la audiencia"
                                type="checkbox"
                                :checked="timerState.visible"
                                @change="timerAct('setVisible', $event.target.checked)"
                            />Mostrar a la audiencia</label
                        >
                        <label class="min-w-0"
                            >Música<select
                                aria-label="Música del temporizador"
                                class="field ml-2 inline-block w-auto max-w-full border-chrome-border bg-chrome-panel text-xs text-chrome-text"
                                :value="timerState.musicId ?? ''"
                                @change="timerAct('setMusic', $event.target.value === '' ? null : Number($event.target.value))"
                                ><option value="">Sin música</option
                                ><option
                                    v-for="audio in timerState.musicOptions"
                                    :key="audio.id"
                                    :value="audio.id"
                                    >{{ audio.name }}</option
                                ></select
                            ></label
                        >
                        <label class="flex items-center gap-2"
                            >Volumen<input
                                aria-label="Volumen de la música"
                                class="w-24"
                                type="range"
                                min="0"
                                max="1"
                                step="0.05"
                                :value="timerState.volume"
                                @input="timerAct('setVolume', Number($event.target.value))"
                        /></label>
                        <label class="flex items-center gap-2"
                            ><input
                                aria-label="Silenciar música"
                                type="checkbox"
                                :checked="timerState.muted"
                                @change="timerAct('setMuted', $event.target.checked)"
                            />Silenciar</label
                        >
                        <p
                            v-if="musicMessage"
                            role="alert"
                            class="w-full rounded bg-danger-soft p-2 text-danger-ink"
                            >{{ musicMessage }}</p
                        >
                    </fieldset>
                </div>
            </details>
            <div
                class="grid shrink-0 gap-4"
                :class="mode === 'presenter' ? 'lg:grid-cols-[minmax(0,2fr)_minmax(200px,1fr)]' : ''"
            >
                <section class="min-w-0" aria-label="Diapositiva actual"
                    ><div
                        class="flex aspect-video max-h-[60dvh] items-center justify-center overflow-hidden rounded-xl bg-black"
                        ><canvas
                            ref="currentCanvas"
                            class="h-full w-full touch-none object-contain"
                            :style="{ cursor: state.blackout ? 'none' : presentationCursorStyle(toolState) }"
                            role="img"
                            aria-label="Diapositiva de audiencia"
                            @pointerdown="pointer"
                            @pointermove="pointer"
                            @pointerup="pointer"
                            @pointercancel="pointer"
                            @pointerleave="pointer"
                            @lostpointercapture="pointer" /></div
                ></section>
                <aside v-show="mode === 'presenter'" class="min-w-0 space-y-3">
                    <section aria-label="Diapositiva siguiente"
                        ><h3
                            class="mb-1 text-xs font-medium text-chrome-muted"
                            >{{ state.nextPageId ? `Siguiente · ${state.nextPageName}` : 'Fin de la presentación' }}</h3
                        ><canvas
                            v-show="state.nextPageId"
                            ref="nextCanvas"
                            class="aspect-video w-full rounded-lg bg-black object-contain"
                            role="img"
                            aria-label="Vista previa siguiente"
                    /></section>
                    <section
                        aria-label="Notas del presentador"
                        class="max-h-64 overflow-y-auto rounded-xl bg-chrome p-4"
                        ><h3 class="mb-2 text-sm font-semibold text-chrome-muted">Notas del presentador</h3
                        ><p
                            class="whitespace-pre-wrap break-words text-sm leading-relaxed"
                            data-testid="presentation-notes"
                            >{{ state.notes || 'Sin notas en esta página.' }}</p
                        ></section
                    >
                </aside>
            </div>
            <footer
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-chrome-border pt-4 text-xs"
            >
                <div class="flex flex-wrap items-center gap-2"
                    ><button
                        :class="secondaryButtonClass"
                        type="button"
                        :disabled="busy"
                        aria-label="Primera diapositiva"
                        @click="act('first')"
                        >Inicio</button
                    ><button
                        :class="secondaryButtonClass"
                        type="button"
                        :disabled="busy"
                        aria-label="Diapositiva anterior"
                        @click="act('previous')"
                        >Anterior</button
                    ><button
                        class="btn-secondary-accent px-3 text-xs font-medium hover:bg-accent-secondary-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-focus disabled:opacity-40"
                        type="button"
                        :disabled="busy"
                        @click="act('togglePlay')"
                        >{{ state.playing ? 'Pausar presentación' : 'Reproducir presentación' }}</button
                    ><button
                        :class="secondaryButtonClass"
                        type="button"
                        :disabled="busy"
                        aria-label="Diapositiva siguiente"
                        @click="act('next')"
                        >Siguiente</button
                    ><button
                        :class="secondaryButtonClass"
                        type="button"
                        :disabled="busy"
                        aria-label="Última diapositiva"
                        @click="act('last')"
                        >Final</button
                    ></div
                >
                <div class="flex flex-wrap items-center gap-3"
                    ><label class="flex items-center gap-2"
                        >Diapositiva<select
                            class="field w-auto max-w-36 border-chrome-border bg-chrome-panel text-xs text-chrome-text"
                            :value="state.pageId"
                            :disabled="busy"
                            @change="act('select', $event.target.value)"
                            ><option v-for="(id, index) in state.pageIds" :key="id" :value="id"
                                >{{ index + 1 }} · {{ document.pages.find(page => page.id === id)?.name }}</option
                            ></select
                        ></label
                    ><label class="flex items-center gap-2"
                        ><input
                            type="checkbox"
                            :checked="state.loop"
                            :disabled="busy"
                            @change="act('setLoop', $event.target.checked)"
                        />Bucle</label
                    ><button
                        :class="secondaryButtonClass"
                        type="button"
                        :disabled="busy"
                        :aria-pressed="!!state.blackout"
                        @click="act('toggleBlackout')"
                        >{{ state.blackout ? 'Mostrar diapositiva' : 'Pantalla negra' }}</button
                    ></div
                >
                <p class="w-full text-chrome-muted"
                    >Flechas / RePág / AvPág: navegar · Espacio: reproducir · B: pantalla negra · Esc: cerrar</p
                >
            </footer>
        </div>
    </BaseDialog>
</template>
