<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Selector de fecha y hora accesible por teclado. Valor `Y-m-d\TH:i` (el mismo que un `input datetime-local`) interpretado en la
 * zona horaria de la marca: muestra esa zona, bloquea los días anteriores a «hoy» en ESA zona y, si la fecha elegida es hoy, no
 * deja una hora ya pasada. Cuadrícula de días con flechas, Inicio/Fin y AvPág/RePág; Escape cierra y devuelve el foco.
 * `native` pinta en su lugar un `input datetime-local` del navegador (respaldo si el selector propio falla en accesibilidad).
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    timezone: { type: String, default: 'UTC' },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: 'scheduled_at' },
    native: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const pad = (n) => String(n).padStart(2, '0');
const ymd = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
const parseYmd = (s) => { const [y, m, d] = s.split('-').map(Number); return { y, m, d }; };
const addDays = (s, n) => { const { y, m, d } = parseYmd(s); const t = new Date(Date.UTC(y, m - 1, d + n)); return ymd(t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate()); };
const addMonths = (s, n) => {
    const { y, m, d } = parseYmd(s);
    const t = new Date(Date.UTC(y, m - 1 + n, 1));
    const last = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate();
    return ymd(t.getUTCFullYear(), t.getUTCMonth() + 1, Math.min(d, last));
};
// Lunes = 0 (la semana de este calendario empieza en lunes, como el calendario de la aplicación).
const weekday = (s) => { const { y, m, d } = parseYmd(s); return (new Date(Date.UTC(y, m - 1, d)).getUTCDay() + 6) % 7; };
const longDate = (s) => new Date(`${s}T00:00:00Z`).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });

const zone = computed(() => {
    try {
        new Intl.DateTimeFormat('en-CA', { timeZone: props.timezone });
        return props.timezone || 'UTC';
    } catch {
        return 'UTC';
    }
});

/** Fecha y hora actuales en la zona de la marca. */
const nowInZone = () => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: zone.value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).map((p) => [p.type, p.value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, minutes: Number(parts.hour) * 60 + Number(parts.minute) };
};
const now = ref(nowInZone());
const today = computed(() => now.value.date);

const open = ref(false);
const root = ref(null);
const trigger = ref(null);
const dialog = ref(null);

const value = computed(() => {
    const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/.exec(props.modelValue ?? '');
    return m ? { date: m[1], hour: Number(m[2]), minute: Number(m[3]) } : null;
});

const focusDate = ref(today.value);
const view = computed(() => parseYmd(focusDate.value));
const monthTitle = computed(() => new Date(Date.UTC(view.value.y, view.value.m - 1, 1)).toLocaleDateString('es', { month: 'long', year: 'numeric', timeZone: 'UTC' }));
const WEEKDAYS = [['Lun', 'lunes'], ['Mar', 'martes'], ['Mié', 'miércoles'], ['Jue', 'jueves'], ['Vie', 'viernes'], ['Sáb', 'sábado'], ['Dom', 'domingo']];

/** Semanas (filas de 7) del mes que se está viendo; `null` rellena los huecos. */
const weeks = computed(() => {
    const first = ymd(view.value.y, view.value.m, 1);
    const days = new Date(Date.UTC(view.value.y, view.value.m, 0)).getUTCDate();
    const cells = [...Array(weekday(first)).fill(null), ...Array.from({ length: days }, (_, i) => ymd(view.value.y, view.value.m, i + 1))];
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, i) => cells.slice(i * 7, i * 7 + 7));
});
const isPast = (date) => date < today.value;
// No se navega a meses enteramente pasados.
const canGoPrev = computed(() => addMonths(ymd(view.value.y, view.value.m, 1), -1).slice(0, 7) >= today.value.slice(0, 7));

const label = computed(() => {
    if (!value.value) return 'Elegir fecha y hora';
    const day = new Date(`${value.value.date}T00:00:00Z`).toLocaleDateString('es', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    return `${day} · ${pad(value.value.hour)}:${pad(value.value.minute)}`;
});

/** Primera hora válida de un día: si es hoy, la próxima franja de 5 minutos; si no, las 00:00. */
const minMinutesFor = (date) => (date === today.value ? Math.min(23 * 60 + 55, Math.ceil((now.value.minutes + 1) / 5) * 5) : 0);

const commit = (date, hour, minute) => {
    const minutes = Math.max(hour * 60 + minute, minMinutesFor(date));
    emit('update:modelValue', `${date}T${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`);
};

const pickDay = (date) => {
    if (isPast(date)) return;
    const current = value.value;
    // Sin hora previa: las 09:00 (o la próxima franja si es hoy y ya han pasado).
    commit(date, current?.hour ?? 9, current?.minute ?? 0);
    focusDate.value = date;
};
const setHour = (e) => value.value && commit(value.value.date, Number(e.target.value), value.value.minute);
const setMinute = (e) => value.value && commit(value.value.date, value.value.hour, Number(e.target.value));
const clear = () => { emit('update:modelValue', ''); closeAndFocus(); };

const hours = Array.from({ length: 24 }, (_, i) => i);
const minutesList = Array.from({ length: 12 }, (_, i) => i * 5);
const hourDisabled = (h) => !!value.value && value.value.date === today.value && h * 60 + 55 < minMinutesFor(today.value);
const minuteDisabled = (m) => !!value.value && value.value.date === today.value && value.value.hour * 60 + m < minMinutesFor(today.value);

const focusCell = async () => {
    await nextTick();
    dialog.value?.querySelector(`[data-date="${focusDate.value}"]`)?.focus();
};
const moveFocus = (target) => {
    focusDate.value = target < today.value ? today.value : target;
    focusCell();
};
const onGridKey = (e) => {
    const d = focusDate.value;
    const map = {
        ArrowLeft: () => addDays(d, -1), ArrowRight: () => addDays(d, 1), ArrowUp: () => addDays(d, -7), ArrowDown: () => addDays(d, 7),
        PageUp: () => addMonths(d, -1), PageDown: () => addMonths(d, 1),
        Home: () => addDays(d, -weekday(d)), End: () => addDays(d, 6 - weekday(d)),
    };
    if (map[e.key]) {
        e.preventDefault();
        moveFocus(map[e.key]());
    }
};
const shiftMonth = (n) => {
    const target = addMonths(focusDate.value, n);
    focusDate.value = target < today.value ? today.value : target;
};

const openPicker = async () => {
    if (props.disabled) return;
    now.value = nowInZone();
    focusDate.value = value.value?.date && value.value.date >= today.value ? value.value.date : today.value;
    open.value = true;
    await focusCell();
};
const closeAndFocus = () => {
    open.value = false;
    nextTick(() => trigger.value?.focus());
};
const onDialogKey = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        closeAndFocus();
    } else if (e.key === 'Tab') {
        const items = [...dialog.value.querySelectorAll('button:not([disabled]):not([tabindex="-1"]), select:not([disabled])')];
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
};
const onOutside = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
watch(() => props.disabled, (d) => d && (open.value = false));

// Respaldo nativo: el valor de un `datetime-local` ya es `Y-m-d\TH:i`.
const minNative = computed(() => `${today.value}T${pad(Math.floor(minMinutesFor(today.value) / 60))}:${pad(minMinutesFor(today.value) % 60)}`);
</script>

<template>
    <input v-if="native" :id="id" :value="modelValue" :min="minNative" :disabled="disabled" type="datetime-local" class="field mt-1 w-auto" @input="emit('update:modelValue', $event.target.value)" />

    <div v-else ref="root" class="relative mt-1 inline-block max-w-full" data-testid="datetime-picker">
        <button
            :id="id"
            ref="trigger"
            type="button"
            class="field flex w-auto max-w-full items-center gap-2 text-left disabled:opacity-60"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :disabled="disabled"
            :data-value="modelValue"
            @click="open ? closeAndFocus() : openPicker()"
        >
            <span aria-hidden="true">📅</span>
            <span class="truncate" :class="value ? 'text-slate-900' : 'text-slate-600'">{{ label }}</span>
        </button>
        <p class="mt-1 text-xs text-slate-600" data-testid="datetime-zone">Zona horaria: {{ zone }}</p>

        <div
            v-if="open"
            ref="dialog"
            role="dialog"
            aria-label="Elegir fecha y hora"
            class="absolute left-0 z-30 mt-1 w-[19rem] max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white p-3 shadow-lg"
            @keydown="onDialogKey"
        >
            <div class="mb-2 flex items-center justify-between">
                <button type="button" class="btn-secondary px-2 py-1" aria-label="Mes anterior" :disabled="!canGoPrev" @click="shiftMonth(-1)">‹</button>
                <span class="inline-block text-sm font-medium text-slate-900 first-letter:uppercase" aria-live="polite">{{ monthTitle }}</span>
                <button type="button" class="btn-secondary px-2 py-1" aria-label="Mes siguiente" @click="shiftMonth(1)">›</button>
            </div>

            <table role="grid" class="w-full table-fixed border-collapse text-center text-sm" :aria-label="`Días de ${monthTitle}`" @keydown="onGridKey">
                <thead>
                    <tr role="row">
                        <th v-for="w in WEEKDAYS" :key="w[0]" scope="col" role="columnheader" class="pb-1 text-xs font-medium text-slate-600" :abbr="w[1]"><span aria-hidden="true">{{ w[0] }}</span><span class="sr-only">{{ w[1] }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(week, i) in weeks" :key="i" role="row">
                        <td v-for="(date, j) in week" :key="j" role="gridcell" class="p-0.5" :aria-selected="date && value?.date === date ? 'true' : undefined">
                            <button
                                v-if="date"
                                type="button"
                                :data-date="date"
                                :tabindex="date === focusDate ? 0 : -1"
                                :disabled="isPast(date)"
                                :aria-label="longDate(date) + (isPast(date) ? ' (pasado)' : '')"
                                :aria-current="date === today ? 'date' : undefined"
                                class="h-8 w-full rounded text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:text-slate-500 disabled:line-through"
                                :class="value?.date === date ? 'bg-indigo-600 font-semibold text-white' : date === today ? 'border border-indigo-300 text-indigo-800 hover:bg-indigo-50' : 'text-slate-800 hover:bg-slate-100'"
                                @click="pickDay(date)"
                                @focus="focusDate = date"
                            >{{ Number(date.slice(8)) }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <label class="flex items-center gap-1">
                    <span class="text-slate-700">Hora</span>
                    <select class="field w-auto py-1" :disabled="!value" :value="value?.hour ?? 9" @change="setHour">
                        <option v-for="h in hours" :key="h" :value="h" :disabled="hourDisabled(h)">{{ pad(h) }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-1">
                    <span class="text-slate-700">Minutos</span>
                    <select class="field w-auto py-1" :disabled="!value" :value="value ? Math.floor(value.minute / 5) * 5 : 0" @change="setMinute">
                        <option v-for="m in minutesList" :key="m" :value="m" :disabled="minuteDisabled(m)">{{ pad(m) }}</option>
                    </select>
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-600">Hora de {{ zone }}. Los días pasados no se pueden elegir.</p>

            <div class="mt-3 flex justify-between gap-2">
                <button type="button" class="btn-secondary px-3 py-1" :disabled="!value" @click="clear">Quitar fecha</button>
                <button type="button" class="btn-primary px-3 py-1" @click="closeAndFocus">Listo</button>
            </div>
        </div>
    </div>
</template>
