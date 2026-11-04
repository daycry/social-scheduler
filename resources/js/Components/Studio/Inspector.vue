<script setup>
import { computed } from 'vue';
const props = defineProps({ selection: { type: Array, default: () => [] }, kit: Object });
const emit = defineEmits(['patch', 'action']);
const item = computed(() => props.selection[0]);
const patch = (key, value) => emit('patch', { [key]: value });
const numeric = (key, event) => { const value = Number(event.target.value); if (Number.isFinite(value) && event.target.value !== '') patch(key, value); };
const solid = computed(() => typeof item.value?.fill === 'string' ? item.value.fill : item.value?.fill?.stops?.[0]?.color ?? '#7c3aed');
const gradient = (field, value) => {
    const existing = typeof item.value.fill === 'object' ? JSON.parse(JSON.stringify(item.value.fill)) : { type: 'linear', angle: 45, stops: [{ offset: 0, color: solid.value }, { offset: 1, color: '#ffffff' }] };
    if (field === 'angle') existing.angle = Number(value);
    else existing.stops[field === 'start' ? 0 : existing.stops.length - 1].color = value;
    patch('fill', existing);
};
const shadow = (key, value) => patch('shadow', { color: '#00000066', blur: 12, offsetX: 0, offsetY: 4, ...item.value.shadow, [key]: value });
const filter = (key, value) => patch('filters', { ...item.value.filters, [key]: value });
const colors = computed(() => props.kit?.palette ?? []);
const fonts = computed(() => [...new Set(['Arial', 'Georgia', 'Verdana', 'Trebuchet MS', 'Times New Roman', ...(props.kit?.fonts ?? [])])]);
</script>
<template>
    <section aria-label="Propiedades del elemento" class="space-y-5 text-xs">
        <h2 class="text-sm font-semibold">{{ selection.length > 1 ? `${selection.length} elementos seleccionados` : 'Propiedades' }}</h2>
        <p v-if="!item" class="rounded-xl border border-dashed border-slate-200 p-5 leading-relaxed text-slate-500">Selecciona un elemento en el lienzo o en Capas para editarlo.</p>
        <template v-else>
            <label class="block space-y-1 font-medium">Nombre de la capa<input class="field text-xs" :value="item.name" maxlength="255" @change="patch('name', $event.target.value)" /></label>
            <p v-if="item.locked" class="rounded-lg bg-amber-50 p-3 text-amber-800">Este elemento está bloqueado. <button type="button" class="font-semibold underline" @click="patch('locked', false)">Desbloquear</button></p>
            <fieldset :disabled="item.locked" class="space-y-5 disabled:opacity-50">
                <template v-if="item.type === 'text'">
                    <label class="block space-y-1 font-medium">Contenido del texto<textarea class="field text-xs" :value="item.text" rows="4" maxlength="10000" @input="patch('text', $event.target.value)" /></label>
                    <label class="block space-y-1 font-medium">Tipografía<select class="field text-xs" :value="item.fontFamily" @change="patch('fontFamily', $event.target.value)"><option v-for="font in fonts" :key="font">{{ font }}</option></select></label>
                    <div class="grid grid-cols-2 gap-2"><label class="space-y-1">Tamaño de letra<input type="number" class="field text-xs" :value="item.fontSize" min="1" max="1000" @change="numeric('fontSize', $event)" /></label><label class="space-y-1">Alineación del texto<select class="field text-xs" :value="item.textAlign" @change="patch('textAlign', $event.target.value)"><option value="left">Izquierda</option><option value="center">Centro</option><option value="right">Derecha</option><option value="justify">Justificado</option></select></label></div>
                    <div class="flex gap-2"><button type="button" class="toggle font-bold" :aria-pressed="item.fontWeight === 'bold'" @click="patch('fontWeight', item.fontWeight === 'bold' ? 'normal' : 'bold')">Negrita</button><button type="button" class="toggle italic" :aria-pressed="item.fontStyle === 'italic'" @click="patch('fontStyle', item.fontStyle === 'italic' ? 'normal' : 'italic')">Cursiva</button><button type="button" class="toggle underline" :aria-pressed="item.underline" @click="patch('underline', !item.underline)">Subrayado</button></div>
                    <div class="grid grid-cols-2 gap-2"><label class="space-y-1">Interlineado<input class="field text-xs" type="number" min="0.5" max="5" step="0.1" :value="item.lineHeight" @change="numeric('lineHeight', $event)" /></label><label class="space-y-1">Espaciado<input class="field text-xs" type="number" min="-200" max="2000" :value="item.charSpacing" @change="numeric('charSpacing', $event)" /></label></div>
                </template>
                <div class="grid grid-cols-2 gap-2"><label v-for="field in [{key:'x',label:'Posición X'},{key:'y',label:'Posición Y'},{key:'width',label:'Ancho del elemento'},{key:'height',label:'Alto del elemento'},{key:'scaleX',label:'Escala horizontal'},{key:'scaleY',label:'Escala vertical'},{key:'rotation',label:'Rotación'}]" :key="field.key" class="space-y-1">{{ field.label }}<input class="field text-xs" type="number" step="any" :value="Math.round(item[field.key] * 1000) / 1000" @change="numeric(field.key, $event)" /></label><label class="space-y-1">Opacidad<input class="field text-xs" type="number" min="0" max="1" step="0.05" :value="item.opacity" @change="numeric('opacity', $event)" /></label></div>
                <template v-if="!['image','video','group'].includes(item.type)">
                    <label class="flex items-center justify-between gap-2 font-medium">Color de relleno<input type="color" class="h-8 w-10 rounded border border-slate-200 p-0.5" :value="solid.slice(0,7)" @input="patch('fill', $event.target.value)" /></label>
                    <div class="flex flex-wrap gap-1.5"><button v-for="color in colors" :key="color" type="button" class="h-6 w-6 rounded-full border border-slate-200" :style="{ background: color }" :aria-label="`Aplicar color ${color}`" @click="patch('fill', color)" /></div>
                    <label class="flex items-center gap-2"><input type="checkbox" :checked="typeof item.fill === 'object'" @change="patch('fill', $event.target.checked ? {type:'linear',angle:45,stops:[{offset:0,color:solid},{offset:1,color:'#ffffff'}]} : solid)" />Degradado</label>
                    <div v-if="typeof item.fill === 'object'" class="grid grid-cols-3 gap-2"><label>Inicio<input type="color" class="mt-1 h-8 w-full" :value="solid.slice(0,7)" @input="gradient('start',$event.target.value)" /></label><label>Final<input type="color" class="mt-1 h-8 w-full" :value="item.fill.stops.at(-1).color.slice(0,7)" @input="gradient('end',$event.target.value)" /></label><label>Ángulo<input type="number" class="field mt-1 text-xs" min="-360" max="360" :value="item.fill.angle" @change="gradient('angle',$event.target.value)" /></label></div>
                    <div class="grid grid-cols-2 gap-2"><label>Color del borde<input type="color" class="mt-1 h-8 w-full" :value="item.stroke || '#000000'" @input="patch('stroke',$event.target.value)" /></label><label>Grosor del borde<input class="field mt-1 text-xs" type="number" min="0" max="1000" :value="item.strokeWidth" @change="numeric('strokeWidth',$event)" /></label></div>
                    <label v-if="item.type === 'rect'" class="block space-y-1">Esquinas redondeadas<input type="number" class="field text-xs" min="0" max="16000" :value="item.radius" @change="numeric('radius',$event)" /></label>
                </template>
                <template v-if="['image','video'].includes(item.type)">
                    <h3 class="font-semibold">Recorte y filtros</h3><div class="grid grid-cols-2 gap-2"><label v-for="key in ['cropX','cropY']" :key="key">{{ key === 'cropX' ? 'Recorte X' : 'Recorte Y' }}<input type="number" class="field mt-1 text-xs" min="0" max="32000" :value="item[key]" @change="numeric(key,$event)" /></label></div>
                    <label v-for="field in [{key:'brightness',label:'Brillo'},{key:'contrast',label:'Contraste'},{key:'saturation',label:'Saturación'},{key:'blur',label:'Desenfoque'}]" :key="field.key" class="block space-y-2">{{ field.label }}<input type="range" class="w-full accent-violet-600" :min="field.key === 'blur' ? 0 : -1" max="1" step="0.05" :value="item.filters?.[field.key] ?? 0" @change="filter(field.key,Number($event.target.value))" /></label>
                    <div class="flex flex-wrap gap-3"><label v-for="field in [{key:'grayscale',label:'Grises'},{key:'sepia',label:'Sepia'},{key:'invert',label:'Invertir'}]" :key="field.key" class="flex items-center gap-1.5"><input type="checkbox" :checked="item.filters?.[field.key]" @change="filter(field.key,$event.target.checked)" />{{ field.label }}</label></div>
                </template>
                <template v-if="item.type === 'video'"><h3 class="font-semibold">Vídeo · vista de fotograma</h3><div class="grid grid-cols-2 gap-2"><label>Inicio (segundos)<input type="number" class="field mt-1 text-xs" min="0" step="0.1" :value="item.trimStart" @change="numeric('trimStart',$event)" /></label><label>Fin (segundos)<input type="number" class="field mt-1 text-xs" min="0" step="0.1" :value="item.trimEnd" @change="patch('trimEnd',$event.target.value === '' ? null : Number($event.target.value))" /></label></div><label class="block space-y-1">Volumen<input type="range" min="0" max="1" step="0.05" class="w-full accent-violet-600" :value="item.volume" @change="numeric('volume',$event)" /></label><label class="flex items-center gap-2"><input type="checkbox" :checked="item.muted" @change="patch('muted',$event.target.checked)" />Silenciar vídeo</label></template>
                <div class="border-t border-slate-100 pt-4"><label class="flex items-center gap-2"><input type="checkbox" :checked="!!item.shadow" @change="patch('shadow',$event.target.checked ? {color:'#00000066',blur:12,offsetX:0,offsetY:4} : null)" />Sombra</label><div v-if="item.shadow" class="mt-3 grid grid-cols-2 gap-2"><label>Color de sombra<input type="color" class="mt-1 h-8 w-full" :value="item.shadow.color.slice(0,7)" @input="shadow('color',$event.target.value)" /></label><label v-for="field in [{key:'blur',label:'Difusión'},{key:'offsetX',label:'Desplazamiento X'},{key:'offsetY',label:'Desplazamiento Y'}]" :key="field.key">{{ field.label }}<input class="field mt-1 text-xs" type="number" :value="item.shadow[field.key]" @change="shadow(field.key,Number($event.target.value))" /></label></div></div>
                <div class="flex gap-2"><button type="button" class="toggle" @click="emit('action','flip','x')">Voltear horizontal</button><button type="button" class="toggle" @click="emit('action','flip','y')">Voltear vertical</button></div>
                <div class="grid grid-cols-3 gap-2"><button v-for="align in [{key:'left',label:'Izquierda'},{key:'center',label:'Centro'},{key:'right',label:'Derecha'},{key:'top',label:'Arriba'},{key:'middle',label:'Medio'},{key:'bottom',label:'Abajo'}]" :key="align.key" type="button" class="toggle" :aria-label="`Alinear ${align.label.toLowerCase()}`" @click="emit('action','align',align.key)">{{ align.label }}</button></div>
            </fieldset>
        </template>
    </section>
</template>
<style scoped>.toggle{border:1px solid #e2e8f0;border-radius:.5rem;padding:.5rem;font-size:11px}.toggle:hover{background:#f8fafc}.toggle[aria-pressed=true]{background:#ede9fe;border-color:#c4b5fd;color:#6d28d9}</style>
