<script setup>
import { computed, ref } from 'vue';
import { fmt, shortDate, UNAVAILABLE } from './format';

/**
 * Grafico de lineas en SVG (sin dependencias). Los dias sin dato (`null`) cortan la linea: no se dibujan como 0.
 * series: [{ name, color, points: [{ date, value }] }]
 */
const props = defineProps({
    series: { type: Array, required: true },
    height: { type: Number, default: 200 },
});

const W = 640;
const PAD = { top: 12, right: 12, bottom: 24, left: 44 };

const dates = computed(() => props.series[0]?.points.map((p) => p.date) ?? []);
const values = computed(() => props.series.flatMap((s) => s.points.map((p) => p.value)).filter((v) => v !== null));
const hasData = computed(() => values.value.length > 0);

const scale = computed(() => {
    const max = Math.max(...values.value, 0);
    const min = Math.min(...values.value, 0);
    // Los seguidores (stock) no empiezan en 0: se ajusta el rango si todo es positivo y poco variable.
    const lo = min >= 0 && max > 0 && min / max > 0.6 ? min - (max - min || 1) * 0.2 : Math.min(min, 0);
    const hi = max === lo ? lo + 1 : max;
    return { lo, hi };
});

const x = (i) => PAD.left + (dates.value.length <= 1 ? (W - PAD.left - PAD.right) / 2 : (i * (W - PAD.left - PAD.right)) / (dates.value.length - 1));
const y = (v) => PAD.top + (1 - (v - scale.value.lo) / (scale.value.hi - scale.value.lo)) * (props.height - PAD.top - PAD.bottom);

// Segmentos continuos (se parten en los null).
const paths = computed(() =>
    props.series.map((s) => {
        const segments = [];
        let current = [];
        s.points.forEach((p, i) => {
            if (p.value === null) {
                if (current.length) segments.push(current);
                current = [];
            } else {
                current.push([x(i), y(p.value)]);
            }
        });
        if (current.length) segments.push(current);
        return { name: s.name, color: s.color, segments, dots: segments.filter((seg) => seg.length === 1) };
    }),
);

const ticks = computed(() => {
    const { lo, hi } = scale.value;
    return [0, 0.5, 1].map((t) => ({ y: y(lo + (hi - lo) * t), label: fmt(lo + (hi - lo) * t, hi - lo < 10 ? 1 : 0) }));
});

const xLabels = computed(() => {
    const n = dates.value.length;
    if (n === 0) return [];
    const step = Math.max(1, Math.ceil(n / 6));
    return dates.value.map((d, i) => ({ d, i })).filter(({ i }) => i % step === 0 || i === n - 1).map(({ d, i }) => ({ x: x(i), label: shortDate(d) }));
});

// Tooltip sencillo: dia bajo el cursor.
const hover = ref(null);
const onMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - rect.left) / rect.width) * W;
    const n = dates.value.length;
    if (n === 0) return;
    const i = Math.round(((px - PAD.left) / (W - PAD.left - PAD.right)) * (n - 1));
    hover.value = Math.min(n - 1, Math.max(0, i));
};
const hoverRows = computed(() =>
    hover.value === null ? [] : props.series.map((s) => ({ name: s.name, color: s.color, value: s.points[hover.value]?.value ?? null })),
);
</script>

<template>
    <div>
        <div v-if="!hasData" class="flex items-center justify-center rounded border border-dashed border-gray-300 bg-gray-50 text-sm text-gray-500" :style="{ height: `${height}px` }">
            {{ UNAVAILABLE }} en este periodo
        </div>
        <div v-else class="relative">
            <svg :viewBox="`0 0 ${W} ${height}`" class="w-full" role="img" @mousemove="onMove" @mouseleave="hover = null">
                <g v-for="t in ticks" :key="t.y">
                    <line :x1="PAD.left" :x2="W - PAD.right" :y1="t.y" :y2="t.y" stroke="#e5e7eb" />
                    <text :x="PAD.left - 6" :y="t.y + 3" text-anchor="end" font-size="10" fill="#6b7280">{{ t.label }}</text>
                </g>
                <text v-for="l in xLabels" :key="l.x" :x="l.x" :y="height - 6" text-anchor="middle" font-size="10" fill="#6b7280">{{ l.label }}</text>
                <g v-for="p in paths" :key="p.name">
                    <polyline v-for="(seg, i) in p.segments.filter((s) => s.length > 1)" :key="i" :points="seg.map((pt) => pt.join(',')).join(' ')" fill="none" :stroke="p.color" stroke-width="2" stroke-linejoin="round" />
                    <circle v-for="(seg, i) in p.dots" :key="`d${i}`" :cx="seg[0][0]" :cy="seg[0][1]" r="3" :fill="p.color" />
                </g>
                <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="PAD.top" :y2="height - PAD.bottom" stroke="#9ca3af" stroke-dasharray="3 3" />
            </svg>
            <div v-if="hover !== null" class="pointer-events-none absolute right-2 top-1 rounded border border-gray-200 bg-white/95 px-2 py-1 text-xs shadow">
                <div class="font-medium text-gray-700">{{ dates[hover] }}</div>
                <div v-for="r in hoverRows" :key="r.name" class="flex items-center gap-1">
                    <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: r.color }"></span>
                    <span class="text-gray-600">{{ r.name }}:</span>
                    <span :class="r.value === null ? 'text-gray-400' : 'text-gray-900'">{{ fmt(r.value) }}</span>
                </div>
            </div>
            <div v-if="series.length > 1" class="mt-1 flex flex-wrap gap-3 text-xs text-gray-600">
                <span v-for="s in series" :key="s.name" class="flex items-center gap-1">
                    <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: s.color }"></span>{{ s.name }}
                </span>
            </div>
        </div>
    </div>
</template>
