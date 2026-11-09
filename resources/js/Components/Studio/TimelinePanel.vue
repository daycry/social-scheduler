<script setup>
import { computed } from 'vue';
import AudioTrackPanel from './AudioTrackPanel.vue';
import CaptionsPanel from './CaptionsPanel.vue';

const props = defineProps({ document: Object, page: Object, pageStart: Number, selectedId: String, time: Number, duration: Number, playing: Boolean, busy: Boolean, assets: Array, selectedAudioId: String, applyCaptions: Function, fonts: Array, kitFonts: Array });
const emit = defineEmits(['select', 'window', 'seek', 'play', 'split', 'page', 'fps', 'edit', 'audio-select', 'audio-patch', 'audio-split', 'audio-remove', 'audio-fade']);
const selected = computed(() => props.page.elements.find(element => element.id === props.selectedId));
const window = computed(() => selected.value?.timing ?? { start: 0, duration: props.page.duration });
const localTime = computed(() => props.time - props.pageStart);
const audioDuration = computed(() => props.document.pages.reduce((total, page) => total + page.duration - (page.transition?.duration ?? 0), 0));
const canSplit = computed(() => selected.value && localTime.value >= window.value.start + 1 / props.document.timeline.fps - 1e-9
    && localTime.value <= window.value.start + window.value.duration - 1 / props.document.timeline.fps + 1e-9);
const format = value => Number(value.toFixed(3));
</script>

<template>
    <section aria-label="Línea de tiempo" class="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="mr-auto"><h2 class="text-sm font-semibold text-slate-800">Línea de tiempo</h2><p class="mt-1 text-[11px] text-slate-500">{{ page.name }} · {{ format(duration) }} s en total</p></div>
            <button type="button" class="timeline-button" :disabled="busy" @click="emit('play')">{{ playing ? 'Pausar' : 'Reproducir' }}</button>
            <button type="button" class="timeline-button" :disabled="busy" @click="emit('edit')">Volver a editar</button>
            <label class="flex items-center gap-2 text-xs">FPS<select :value="document.timeline.fps" class="rounded-lg border-slate-200 py-1.5 text-xs" :disabled="busy || playing" @change="emit('fps', Number($event.target.value))"><option v-for="fps in [12,15,24,25,30,50,60]" :key="fps" :value="fps">{{ fps }}</option></select></label>
        </div>
        <div class="flex min-w-0 flex-wrap items-center gap-3">
            <label class="text-xs text-slate-600">Tiempo de reproducción<input type="number" min="0" :max="duration" step="0.001" :value="format(time)" class="ml-2 w-24 rounded-lg border-slate-200 py-1.5 text-xs" :disabled="busy" @change="emit('seek', Number($event.target.value))" /></label>
            <input type="range" aria-label="Desplazar cabezal" min="0" :max="duration" :step="1 / document.timeline.fps" :value="time" class="min-w-0 flex-1 accent-violet-700" :disabled="busy" @input="emit('seek', Number($event.target.value))" />
        </div>
        <div class="relative space-y-2 border-l border-slate-200 pl-2">
            <div v-if="localTime >= 0 && localTime <= page.duration" class="pointer-events-none absolute bottom-0 top-0 z-10 w-px bg-rose-500" :style="{ left: `${localTime / page.duration * 100}%` }" aria-hidden="true" />
            <div v-for="clip in page.elements" :key="clip.id" class="relative h-9 rounded-lg bg-slate-50">
                <button type="button" data-testid="timeline-clip" :aria-label="`Seleccionar clip ${clip.name}`" :aria-pressed="selectedId === clip.id" :disabled="busy || playing"
                    class="absolute top-0 flex h-9 min-w-[3px] items-center overflow-hidden rounded-md border px-2 text-left text-[11px] font-medium"
                    :class="selectedId === clip.id ? 'border-violet-600 bg-violet-600 text-white' : 'border-violet-200 bg-violet-100 text-violet-900'"
                    :style="{ left: `${(clip.timing?.start ?? 0) / page.duration * 100}%`, width: `${(clip.timing?.duration ?? page.duration) / page.duration * 100}%` }" @click="emit('select', clip.id)">
                    <span class="truncate">{{ clip.name }}</span>
                </button>
            </div>
            <p v-if="!page.elements.length" class="py-3 text-xs text-slate-500">Añade texto, imágenes o vídeo para crear clips.</p>
        </div>
        <div v-if="selected" class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3">
            <label class="space-y-1 text-xs text-slate-600"><span class="block">Inicio del clip</span><input type="number" min="0" :max="page.duration - 1 / document.timeline.fps" :step="1 / document.timeline.fps" :value="format(window.start)" :disabled="busy || playing" class="w-28 rounded-lg border-slate-200 text-xs" @change="emit('window', { start: Number($event.target.value) })" /></label>
            <label class="space-y-1 text-xs text-slate-600"><span class="block">Duración del clip</span><input type="number" :min="1 / document.timeline.fps" :max="page.duration - window.start" :step="1 / document.timeline.fps" :value="format(window.duration)" :disabled="busy || playing" class="w-28 rounded-lg border-slate-200 text-xs" @change="emit('window', { duration: Number($event.target.value) })" /></label>
            <button type="button" class="timeline-button" :disabled="busy || playing || !canSplit" @click="emit('split')">Dividir clip</button>
        </div>
        <p v-else class="text-xs text-slate-500">Selecciona un clip para ajustar sus tiempos o dividirlo.</p>
        <AudioTrackPanel :tracks="document.timeline.audio_tracks" :assets="assets ?? []" :duration="audioDuration" :fps="document.timeline.fps" :time="time" :selected-id="selectedAudioId" :disabled="busy || playing"
            @select="emit('audio-select', $event)" @patch="emit('audio-patch', $event)" @split="emit('audio-split')" @remove="emit('audio-remove')" @fade="emit('audio-fade', $event)" />
        <CaptionsPanel :document="document" :time="time" :duration="audioDuration" :apply="applyCaptions" :fonts="fonts ?? []" :kit-fonts="kitFonts ?? []" :disabled="busy || playing" @seek="emit('seek', $event)" />
        <p class="text-[10px] leading-relaxed text-slate-500">La reproducción recorre todas las páginas. Los tiempos de los clips pertenecen a la página seleccionada; los del audio, al proyecto completo.</p>
    </section>
</template>

<style scoped>
.timeline-button{border:1px solid #ddd6fe;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:500;color:#6d28d9;background:#faf5ff}.timeline-button:hover{background:#ede9fe}.timeline-button:disabled{opacity:.4;cursor:not-allowed}
</style>
