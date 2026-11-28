<script setup>
import { ANIMATION_DEFAULT_EASING, ANIMATION_LIMITS } from '@/Studio/object-animation';

defineProps({ animation: Object, disabled: Boolean, step: { type: Number, default: 0.1 } });
const emit = defineEmits(['change']);
const TYPES = [
    ['fade', 'Fundido'], ['slide_left', 'Deslizar desde/hacia la izquierda'], ['slide_right', 'Deslizar desde/hacia la derecha'],
    ['slide_up', 'Deslizar desde/hacia arriba'], ['slide_down', 'Deslizar desde/hacia abajo'], ['zoom', 'Escalar (zoom)'],
];
const EASINGS = [['linear', 'Lineal'], ['ease_in', 'Acelera'], ['ease_out', 'Frena'], ['ease_in_out', 'Suave']];
const PHASES = [['in', 'Entrada'], ['out', 'Salida']];
const format = value => Number(Number(value).toFixed(3));
const change = (phase, patch) => emit('change', { phase, patch });
</script>

<template>
    <fieldset class="min-w-0 space-y-2 border-t border-border-subtle pt-3" data-testid="object-animation">
        <legend class="text-xs font-semibold text-foreground-secondary">Animación del objeto</legend>
        <div v-for="[phase, label] in PHASES" :key="phase" class="flex flex-wrap items-end gap-3">
            <label class="space-y-1 text-xs text-foreground-muted"
                ><span class="block">{{ label }}</span
                ><select
                    :value="animation?.[phase]?.type ?? ''"
                    :disabled="disabled"
                    :aria-label="label"
                    :data-testid="`animation-${phase}-type`"
                    class="w-56 rounded-lg border-border text-xs"
                    @change="change(phase, $event.target.value ? { type: $event.target.value } : null)"
                    ><option value="">Sin animación</option
                    ><option v-for="[type, name] in TYPES" :key="type" :value="type">{{ name }}</option></select
                ></label
            >
            <label class="space-y-1 text-xs text-foreground-muted"
                ><span class="block">Duración de la {{ label.toLowerCase() }} (s)</span
                ><input
                    type="number"
                    :min="ANIMATION_LIMITS.minDuration"
                    :max="ANIMATION_LIMITS.maxDuration"
                    :step="step"
                    :value="animation?.[phase] ? format(animation[phase].duration) : ''"
                    :disabled="disabled || !animation?.[phase]"
                    :data-testid="`animation-${phase}-duration`"
                    class="w-24 rounded-lg border-border text-xs"
                    @change="change(phase, { duration: Number($event.target.value) })"
            /></label>
            <template v-if="animation?.[phase]">
                <label class="space-y-1 text-xs text-foreground-muted"
                    ><span class="block">Retardo de la {{ label.toLowerCase() }} (s)</span
                    ><input
                        type="number"
                        :min="animation[phase].clipped ? -ANIMATION_LIMITS.maxDuration : 0"
                        :step="step"
                        :value="format(animation[phase].delay ?? 0)"
                        :disabled="disabled"
                        :data-testid="`animation-${phase}-delay`"
                        class="w-24 rounded-lg border-border text-xs"
                        @change="change(phase, { delay: Number($event.target.value) })"
                /></label>
                <label class="space-y-1 text-xs text-foreground-muted"
                    ><span class="block">Curva de la {{ label.toLowerCase() }}</span
                    ><select
                        :value="animation[phase].easing ?? ANIMATION_DEFAULT_EASING"
                        :disabled="disabled"
                        :aria-label="`Curva de la ${label.toLowerCase()}`"
                        :data-testid="`animation-${phase}-easing`"
                        class="w-32 rounded-lg border-border text-xs"
                        @change="change(phase, { easing: $event.target.value })"
                        ><option
                            v-for="[easing, name] in EASINGS"
                            :key="easing"
                            :value="easing"
                            >{{ name }}</option
                        ></select
                    ></label
                >
            </template>
        </div>
        <p class="text-[10px] leading-relaxed text-foreground-subtle"
            >La entrada empieza tras el retardo desde el inicio del clip; la salida termina el retardo indicado antes de
            su final. Mueve el cabezal para ver el resultado.</p
        >
        <p v-if="animation?.in?.clipped || animation?.out?.clipped" class="text-[10px] text-foreground-subtle"
            >Las fases recortadas muestran la duración original. Un retardo negativo sitúa parte de la curva fuera del
            clip.</p
        >
    </fieldset>
</template>
