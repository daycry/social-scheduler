<script setup>
import { computed } from 'vue';

const props = defineProps({ tracks: Array, assets: Array, duration: Number, fps: Number, time: Number, selectedId: String, disabled: Boolean });
const emit = defineEmits(['select', 'patch', 'split', 'remove', 'fade']);
const selected = computed(() => props.tracks.find(track => track.id === props.selectedId));
const source = computed(() => props.assets.find(asset => asset.id === selected.value?.asset_id));
const label = track => props.assets.find(asset => asset.id === track.asset_id)?.name ?? 'Audio';
const number = (key, event) => emit('patch', { [key]: event.target.value === '' && key === 'trimEnd' ? null : Number(event.target.value) });
const canSplit = computed(() => selected.value && props.time >= selected.value.start + 1 / props.fps - 1e-9 && props.time <= selected.value.start + selected.value.duration - 1 / props.fps + 1e-9);
const fadeIn = computed(() => selected.value?.gainPoints?.[0]?.value === 0 ? selected.value.gainPoints.find(point => point.value === 1)?.time ?? 0 : 0);
const fadeOut = computed(() => selected.value?.gainPoints?.at(-1)?.value === 0 ? selected.value.duration - (selected.value.gainPoints.findLast(point => point.value === 1)?.time ?? selected.value.duration) : 0);
</script>

<template>
    <section aria-label="Pistas de audio" class="min-w-0 space-y-3 border-t border-border pt-4">
        <h3 class="text-xs font-semibold text-foreground-secondary"
            >Pistas de audio <span class="font-normal text-foreground-subtle">{{ tracks.length }}/64</span></h3
        >
        <div class="space-y-2">
            <div v-for="track in tracks" :key="track.id" class="relative h-9 rounded-lg bg-canvas">
                <button
                    type="button"
                    data-testid="timeline-audio-track"
                    :aria-label="`Seleccionar audio ${label(track)}`"
                    :aria-pressed="selectedId === track.id"
                    :disabled="disabled"
                    class="absolute top-0 flex h-9 min-w-[3px] items-center overflow-hidden rounded-md border px-2 text-left text-[11px] font-medium disabled:opacity-50"
                    :class="selectedId === track.id ? 'border-success-fill bg-success-fill text-on-success' : 'border-success-border bg-success-soft text-success-ink'"
                    :style="{ left: `${track.start / duration * 100}%`, width: `${track.duration / duration * 100}%` }"
                    @click="emit('select', track.id)"
                    ><span class="truncate">♫ {{ label(track) }}{{ track.muted ? ' · Silenciado' : '' }}</span></button
                >
            </div>
        </div>
        <p v-if="!tracks.length" class="text-xs text-foreground-subtle"
            >Sube un audio en Recursos y añádelo a la línea de tiempo.</p
        >
        <div v-if="selected" class="space-y-3 rounded-xl bg-success-soft/60 p-3">
            <p class="truncate text-xs font-semibold text-success-ink">{{ label(selected) }}</p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Inicio del audio<input
                        class="field min-w-0"
                        aria-label="Inicio del audio"
                        type="number"
                        min="0"
                        :max="duration - 1 / fps"
                        :step="1 / fps"
                        :value="selected.start"
                        :disabled="disabled"
                        @change="number('start', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Duración del audio<input
                        class="field min-w-0"
                        aria-label="Duración del audio"
                        type="number"
                        :min="1 / fps"
                        :max="duration - selected.start"
                        :step="1 / fps"
                        :value="selected.duration"
                        :disabled="disabled"
                        @change="number('duration', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Velocidad del audio<input
                        class="field min-w-0"
                        aria-label="Velocidad del audio"
                        type="number"
                        min="0.25"
                        max="4"
                        step="0.25"
                        :value="selected.playbackRate"
                        :disabled="disabled"
                        @change="number('playbackRate', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Recorte inicial del audio<input
                        class="field min-w-0"
                        aria-label="Recorte inicial del audio"
                        type="number"
                        min="0"
                        :max="(selected.trimEnd ?? source?.duration ?? 3600) - 0.001"
                        step="0.001"
                        :value="selected.trimStart"
                        :disabled="disabled"
                        @change="number('trimStart', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Recorte final del audio<input
                        class="field min-w-0"
                        aria-label="Recorte final del audio"
                        type="number"
                        :min="selected.trimStart + 0.001"
                        :max="source?.duration ?? 3600"
                        step="0.001"
                        :placeholder="String(source?.duration ?? '')"
                        :value="selected.trimEnd"
                        :disabled="disabled"
                        @change="number('trimEnd', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Volumen del audio<input
                        class="field min-w-0"
                        aria-label="Volumen del audio"
                        type="number"
                        min="0"
                        max="1"
                        step="0.05"
                        :value="selected.volume"
                        :disabled="disabled"
                        @change="number('volume', $event)"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Fundido de entrada<input
                        class="field min-w-0"
                        aria-label="Fundido de entrada"
                        type="number"
                        min="0"
                        :max="selected.duration - fadeOut"
                        step="0.1"
                        :value="fadeIn"
                        :disabled="disabled"
                        @change="emit('fade', { in: Number($event.target.value), out: fadeOut })"
                /></label>
                <label class="flex min-w-0 flex-col gap-1 text-xs text-foreground-muted"
                    >Fundido de salida<input
                        class="field min-w-0"
                        aria-label="Fundido de salida"
                        type="number"
                        min="0"
                        :max="selected.duration - fadeIn"
                        step="0.1"
                        :value="fadeOut"
                        :disabled="disabled"
                        @change="emit('fade', { in: fadeIn, out: Number($event.target.value) })"
                /></label>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-success-ink">
                <label class="flex items-center gap-2"
                    ><input
                        type="checkbox"
                        :checked="selected.loop"
                        :disabled="disabled"
                        @change="emit('patch', { loop: $event.target.checked })"
                    />Repetir audio</label
                >
                <label class="flex items-center gap-2"
                    ><input
                        type="checkbox"
                        :checked="selected.muted"
                        :disabled="disabled"
                        @change="emit('patch', { muted: $event.target.checked })"
                    />Silenciar audio</label
                >
                <button
                    type="button"
                    class="rounded-lg border border-success-border bg-surface px-3 py-2 disabled:opacity-40"
                    :disabled="disabled || !canSplit"
                    @click="emit('split')"
                    >Dividir audio</button
                >
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-danger disabled:opacity-40"
                    :disabled="disabled"
                    @click="emit('remove')"
                    >Eliminar audio</button
                >
            </div>
        </div>
    </section>
</template>
