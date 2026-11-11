<script setup>
import { computed } from 'vue';
const props = defineProps({ layers: { type: Array, default: () => [] }, selection: { type: Array, default: () => [] } });
const emit = defineEmits(['select', 'patch', 'action']);
const selectedIds = computed(() => new Set(props.selection.map(item => item.id)));
const names = { text: 'Texto', rect: 'Rectángulo', ellipse: 'Elipse', triangle: 'Triángulo', line: 'Línea', path: 'Dibujo', image: 'Imagen', video: 'Vídeo', group: 'Grupo', chart: 'Gráfico', table: 'Tabla', connector: 'Conector' };
</script>
<template>
    <section aria-label="Capas del diseño" class="space-y-4">
        <div class="flex items-center justify-between"><h2 class="text-sm font-semibold">Capas</h2><span class="text-xs text-slate-400">{{ layers.length }} elementos</span></div>
        <p class="text-xs leading-relaxed text-slate-500">La primera capa está delante. Usa Ctrl o ⌘ para seleccionar varias.</p>
        <ol class="space-y-1.5">
            <li v-for="layer in [...layers].reverse()" :key="layer.id" data-testid="studio-layer" class="flex min-w-0 items-center gap-1 rounded-xl border p-1.5" :class="selectedIds.has(layer.id) ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'">
                <button type="button" class="flex min-w-0 flex-1 items-center gap-2 rounded-lg p-1.5 text-left" :aria-pressed="selectedIds.has(layer.id)" @click="emit('select', layer.id, $event.ctrlKey || $event.metaKey)">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-xs font-bold text-slate-500" aria-hidden="true">{{ layer.type === 'text' ? 'T' : layer.type === 'group' ? '▦' : layer.type === 'video' ? '▶' : '◇' }}</span>
                    <span class="min-w-0"><span class="block truncate text-xs font-medium">{{ layer.name || names[layer.type] }}</span><span class="block text-[10px] text-slate-400">{{ names[layer.type] }}{{ layer.locked ? ' · Bloqueado' : '' }}</span></span>
                </button>
                <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" :aria-label="`${layer.visible ? 'Ocultar' : 'Mostrar'} ${layer.name}`" @click="emit('patch', layer.id, { visible: !layer.visible })"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /><path v-if="!layer.visible" d="m3 3 18 18" /></svg></button>
                <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" :aria-label="`${layer.locked ? 'Desbloquear' : 'Bloquear'} ${layer.name}`" @click="emit('patch', layer.id, { locked: !layer.locked })"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path :d="layer.locked ? 'M8 10V7a4 4 0 0 1 8 0v3' : 'M8 10V7a4 4 0 0 1 7.5-2'" /><path d="M12 14v3" /></svg></button>
            </li>
        </ol>
        <p v-if="!layers.length" class="rounded-xl border border-dashed border-slate-200 px-3 py-7 text-center text-xs leading-relaxed text-slate-500">Tu lienzo está listo.<br>Añade texto, una forma o un recurso.</p>
        <div v-if="selection.length" class="grid grid-cols-2 gap-2 border-t border-slate-100 pt-4 text-xs">
            <button v-for="item in [{ mode: 'front', label: 'Al frente' }, { mode: 'forward', label: 'Adelantar' }, { mode: 'backward', label: 'Retroceder' }, { mode: 'back', label: 'Al fondo' }]" :key="item.mode" type="button" class="rounded-lg border border-slate-200 px-2 py-2 hover:bg-slate-50" @click="emit('action', 'reorder', item.mode)">{{ item.label }}</button>
            <button type="button" class="rounded-lg border border-slate-200 px-2 py-2 hover:bg-slate-50" @click="emit('action', 'group')">Agrupar</button><button type="button" class="rounded-lg border border-slate-200 px-2 py-2 hover:bg-slate-50" @click="emit('action', 'ungroup')">Desagrupar</button>
        </div>
    </section>
</template>
