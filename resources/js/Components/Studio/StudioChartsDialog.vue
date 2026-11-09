<script setup>
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import StudioDialog from '@/Components/Studio/StudioDialog.vue';
import { normalizeChartSpec } from '@/Studio/chart-spec';
import { blankDocument, newElement } from '@/Studio/document';
import { renderStudioPage } from '@/Studio/canvas';
import * as tableCore from '@/Studio/dataset-table';
const props = defineProps({ modelValue: Boolean, brandId: Number, chart: Object, source: Object, chartResources: Object, fonts: { type: Array, default: () => [] }, kit: Object });
const emit = defineEmits(['update:modelValue', 'apply', 'choose-data']);
const draft = ref(null), tab = ref('data'), error = ref(''), previewUrl = ref(''), previewBusy = ref(false), resolvedKey = ref(''), warnings = ref([]);
const dataTable = shallowRef(null), dataOpen = ref(false), dataPage = ref(0), dataBusy = ref(false);
let sequence = 0, controller = null, timer = null, scene = null, disposed = false;
let dataSequence = 0, dataController = null;
const copy = value => JSON.parse(JSON.stringify(value));
const kinds = [['column','Columnas'],['bar','Barras'],['column-grouped','Columnas agrupadas'],['bar-grouped','Barras agrupadas'],['column-stacked','Columnas apiladas'],['bar-stacked','Barras apiladas'],['column-proportional','Columnas proporcionales'],['bar-proportional','Barras proporcionales'],['column-multicolor','Columnas multicolor'],['bar-multicolor','Barras multicolor']];
const columns = computed(() => props.source?.columns ?? dataTable.value?.columns.map((c,i) => ({...c,numeric:dataTable.value.rows.every(r => r.values[i] === null || tableCore.cellType(r.values[i]) === 'number')})) ?? []);
const numericColumns = computed(() => columns.value.filter(c => c.numeric));
const columnsKnown = computed(() => !!props.source || !!dataTable.value);
const missingCategory = computed(() => columnsKnown.value && !!draft.value && !columns.value.some(c => c.id === draft.value.binding.category_column));
const unavailableSeries = computed(() => columnsKnown.value ? draft.value?.binding.series_columns.filter(id => !numericColumns.value.some(c => c.id === id)) ?? [] : []);
const configuration = computed(() => { try {
 const spec = normalizeChartSpec(copy(draft.value));
 if (missingCategory.value || unavailableSeries.value.length) return { spec:null, error:'Hay columnas seleccionadas que no están disponibles en esta revisión. Elige una categoría disponible y retira o sustituye las series indicadas.' };
 return { spec, error: '' };
 } catch(e) { return { spec:null, error:e.message }; } });
const key = computed(() => configuration.value.spec ? JSON.stringify(configuration.value.spec) : '');
const canApply = computed(() => !!key.value && key.value === resolvedKey.value && !previewBusy.value && !error.value);
const fontValue = computed(() => draft.value?.style.font_id ? `font:${draft.value.style.font_id}` : `local:${draft.value?.style.fontFamily}`);
const families = computed(() => [...new Set(['Arial','Georgia','Verdana','Times New Roman',...(props.kit?.fonts ?? []),draft.value?.style.fontFamily].filter(f=> f && !/^StudioFont_/i.test(f)))]);
const paletteText = computed({get:()=>draft.value?.options.palette.join(', ') ?? '',set:value=>{draft.value.options.palette=value.split(',').map(c=>c.trim());}});
function warningLabel(warning) {
 const labels = {
  TEXT_FITTED: 'El texto se ha ajustado al espacio disponible.',
  CATEGORY_LABEL_SHORTENED: 'Algunas etiquetas se han acortado. Los datos originales siguen completos en la tabla.',
  CATEGORY_LABELS_SAMPLED: 'Se muestra una selección de etiquetas para facilitar la lectura. La tabla conserva todas las categorías.',
  LEGEND_SUMMARIZED: 'La leyenda se ha resumido por espacio. Consulta la tabla para ver todas las categorías o series.',
  ZERO_TOTAL: 'Una categoría tiene un total de cero; no puede mostrar proporciones.',
  APPROXIMATE_COORDINATE: 'Se han aproximado algunos números para dibujarlos. La tabla conserva los valores originales.',
  UNDERFLOW_COORDINATE: 'Algunos valores son demasiado pequeños para representarlos con precisión. La tabla conserva sus valores originales.',
  ZERO_SIZE_BAR: 'Algunos valores son demasiado pequeños para mostrar una barra visible. Consulta sus valores originales en la tabla.',
  ZERO_VALUE_LABEL_ANCHORED: 'Los valores cero se muestran junto al eje porque no tienen área visible.',
  VALUE_LABELS_CROWDED: 'Hay poco espacio para las etiquetas. Reduce las series o el tamaño del texto, o prueba otra posición.',
 };
 return labels[warning?.code] ?? 'Hay un aviso sobre la representación del gráfico. Consulta los datos originales en la tabla.';
}
function chooseFont(value) {
 if (value.startsWith('font:')) { const font=props.fonts.find(f=>f.id===Number(value.slice(5))); if (!font) return;
 Object.assign(draft.value.style,{font_id:font.id,fontFamily:font.family,fontStyle:font.style==='italic'?'italic':'normal',fontWeight:font.weight>=600?'bold':'normal'});
 } else {delete draft.value.style.font_id;draft.value.style.fontFamily=value.slice(6);}
}
function invalidate() {sequence++;controller?.abort();clearTimeout(timer);resolvedKey.value='';previewBusy.value=false;}
function invalidateData() {dataSequence++;dataController?.abort();dataController=null;dataBusy.value=false;}
function disposeScene() {const old=scene;scene=null;if(old) Promise.resolve(old.dispose()).catch(()=>{});}
function close() {invalidate();invalidateData();disposeScene();previewUrl.value='';dataTable.value=null;emit('update:modelValue',false);}
function apply() {if(canApply.value) {emit('apply',copy(configuration.value.spec));close();}}
function toggleSeries(id, checked) {const values=draft.value.binding.series_columns;draft.value.binding.series_columns=checked ? [...values,id] : values.filter(value=>value!==id);}
async function viewData() {
 invalidateData();const ticket=dataSequence, resources=props.chartResources, reference=copy(draft.value.binding.dataset);dataController=new AbortController();const signal=dataController.signal;dataBusy.value=true;error.value='';
 try {const snapshot=await resources.resolve(reference,{signal});const current=draft.value?.binding.dataset;if(disposed||signal.aborted||!props.modelValue||ticket!==dataSequence||resources!==props.chartResources||reference.id!==current?.id||reference.revision!==current?.revision||reference.sha256!==current?.sha256)return;dataTable.value=snapshot.table;dataOpen.value=true;dataPage.value=0;}
 catch(e){if(ticket===dataSequence && e.name!=='AbortError')error.value=e.message;}finally{if(ticket===dataSequence){dataBusy.value=false;dataController=null;}}
}
const displayedColumns = computed(() => {const ids=[draft.value?.binding.category_column,...(draft.value?.binding.series_columns??[])];return ids.map(id=>({id,label:dataTable.value?.columns.find(c=>c.id===id)?.label??id,index:dataTable.value?.columns.findIndex(c=>c.id===id)}));});
const displayedRows = computed(() => dataTable.value?.rows.slice(dataPage.value*50,(dataPage.value+1)*50)??[]);
function cellLabel(cell) {if(cell===null)return 'Vacío (null)'; const type=tableCore.cellType(cell);return `${type==='number'?'Número':'Texto'}: ${cell===''?'""':tableCore.cellText(cell)}`;}
async function refreshPreview() {
 invalidate(); const ticket=sequence;controller=new AbortController();const signal=controller.signal;
 error.value=configuration.value.error; if(!configuration.value.spec || !props.modelValue || !props.chartResources)return;
 const spec=copy(configuration.value.spec), currentKey=key.value, resources=props.chartResources;
 previewBusy.value=true;let created;
 try {
 const document=blankDocument(800,500);document.pages[0].elements=[newElement('chart',{x:0,y:0,width:800,height:500,chart:spec})];
 created=await renderStudioPage(document,document.pages[0].id,[],props.fonts,{chartResources:resources,signal});
 if(disposed||signal.aborted||ticket!==sequence||resources!==props.chartResources||!props.modelValue){await created.dispose();return;}
 const url=created.canvas.toDataURL({format:'png',multiplier:1});
 const messages=created.canvas.getObjects().flatMap(object=>object.studioChartScene?.warnings??[]);
 disposeScene();scene=created;created=null;previewUrl.value=url;warnings.value=messages;resolvedKey.value=currentKey;
 }catch(e){if(created)await created.dispose();if(ticket===sequence&&!signal.aborted)error.value=e.message;}
 finally{if(ticket===sequence)previewBusy.value=false;}
}
watch(() => [props.modelValue,props.brandId,props.chart,props.source,props.chartResources],()=>{
 invalidate();invalidateData();disposeScene();dataTable.value=null;dataOpen.value=false;previewUrl.value='';warnings.value=[];error.value='';
 if(!props.modelValue)return;
 if(props.chart)draft.value=copy(normalizeChartSpec(copy(props.chart)));
 else if(props.source){const category=props.source.columns.find(c=>!c.numeric)??props.source.columns[0];const series=props.source.columns.find(c=>c.numeric&&c.id!==category?.id);
 if(!series){draft.value=null;error.value='No hay una columna numérica distinta de la categoría. Convierte una columna a Número en el editor de datos y guarda la revisión.';return;}
 draft.value=copy(normalizeChartSpec({binding:{dataset:copy(props.source.dataset),category_column:category.id,series_columns:[series.id],precision:'safe'}}));}
 else {draft.value=null;return;}
 tab.value='data';timer=setTimeout(refreshPreview,200);
},{immediate:true});
watch(draft,()=>{invalidate();error.value=configuration.value.error;timer=setTimeout(refreshPreview,200);},{deep:true});
watch(()=>props.fonts,()=>{invalidate();disposeScene();timer=setTimeout(refreshPreview,200);},{deep:true});
onBeforeUnmount(()=>{disposed=true;invalidate();invalidateData();disposeScene();dataTable.value=null;});
</script>
<template>
 <StudioDialog :model-value="modelValue" title="Diseñar gráfico" wide @update:model-value="close">
  <div class="space-y-5 min-w-0">
   <nav class="flex gap-2 rounded-xl bg-slate-100 p-1" aria-label="Pasos del gráfico"><button type="button" class="chart-tab" :aria-pressed="tab==='data'" @click="tab='data'">1 · Datos</button><button type="button" class="chart-tab" :aria-pressed="tab==='style'" @click="tab='style'">2 · Estilo</button></nav>
   <template v-if="draft">
    <section v-show="tab==='data'" class="space-y-4" aria-label="Datos del gráfico">
     <div class="rounded-xl border border-violet-200 bg-violet-50 p-4"><p class="text-sm font-semibold">Tabla {{ draft.binding.dataset.id }} · Revisión {{ draft.binding.dataset.revision }} fija</p><p class="mt-1 break-all font-mono text-[10px]">SHA-256 {{ draft.binding.dataset.sha256 }}</p><button type="button" class="chart-button mt-3" @click="emit('choose-data',copy(draft))">Elegir otra revisión guardada</button><p class="mt-2 text-xs text-slate-600">La revisión sólo cambia al elegirla y aplicar el gráfico. Puedes deshacer ese cambio.</p></div>
     <button type="button" class="chart-button" :disabled="dataBusy" @click="viewData">Ver datos del gráfico</button>
     <p v-if="!columns.length" class="text-sm text-slate-600">Abre «Ver datos del gráfico» para cargar las columnas de esta revisión histórica.</p>
     <label class="chart-label">Tipo de gráfico<select v-model="draft.options.kind" class="field"><option v-for="[value,label] in kinds" :key="value" :value="value">{{ label }}</option></select></label>
     <label class="chart-label">Categoría<select v-model="draft.binding.category_column" class="field"><option v-if="missingCategory" :value="draft.binding.category_column" disabled>{{ draft.binding.category_column }} · No disponible en esta revisión</option><option v-for="c in columns" :key="c.id" :value="c.id">{{ c.label }}</option></select></label>
     <p v-if="missingCategory" role="status" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">La categoría {{ draft.binding.category_column }} no está disponible en esta revisión. Elige su nueva columna en el selector de categoría.</p>
     <section v-if="unavailableSeries.length" class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3" aria-label="Series que necesitan asignación"><p class="text-sm text-amber-900">Estas columnas no están disponibles como series numéricas en esta revisión. Retíralas y elige sus sustitutas entre las series de abajo. El estilo se conserva.</p><div v-for="id in unavailableSeries" :key="id" class="flex flex-wrap items-center justify-between gap-2 text-sm"><span class="break-all">{{ id }} · No disponible como serie numérica</span><button type="button" class="chart-button" :aria-label="`Retirar serie ${id}`" @click="toggleSeries(id,false)">Retirar serie {{ id }}</button></div></section>
     <fieldset class="rounded-xl border border-slate-200 p-3"><legend class="px-1 text-sm font-semibold">Series numéricas · entre 1 y 12</legend><label v-for="c in numericColumns" :key="c.id" class="flex gap-2 py-2 text-sm"><input type="checkbox" :checked="draft.binding.series_columns.includes(c.id)" :disabled="c.id===draft.binding.category_column && !draft.binding.series_columns.includes(c.id)" @change="toggleSeries(c.id,$event.target.checked)" />{{ c.label }}</label><p v-if="!numericColumns.length" class="text-sm">Sin columnas numéricas: conviértelas a Número en el editor de datos y guarda una revisión.</p></fieldset>
     <label class="chart-label">Precisión<select v-model="draft.binding.precision" class="field"><option value="safe">Segura · rechazar pérdida de precisión</option><option value="approximate">Aproximada · acepto aproximar números</option></select></label>
    </section>
    <section v-show="tab==='style'" class="space-y-4" aria-label="Estilo del gráfico">
     <fieldset class="chart-fieldset"><legend>Texto y tipografía</legend><label v-for="[field,label] in [['title','Título'],['subtitle','Subtítulo'],['source','Fuente de datos'],['x_title','Título del eje X'],['y_title','Título del eje Y']]" :key="field" class="chart-label">{{label}}<input v-model="draft.style[field]" maxlength="512" class="field" /></label>
      <label class="chart-label">Tipografía<select :value="fontValue" class="field" @change="chooseFont($event.target.value)"><optgroup label="Locales y kit"><option v-for="family in families" :key="family" :value="`local:${family}`">{{family}}</option></optgroup><optgroup label="Archivos de marca"><option v-for="font in fonts.filter(f=>f.status==='ready'&&(!f.archived_at||f.id===draft.style.font_id))" :key="font.id" :value="`font:${font.id}`">{{font.name}} · {{font.family}}</option></optgroup></select></label>
      <label class="chart-label">Tamaño de letra<input v-model.number="draft.style.fontSize" type="number" min="8" max="72" class="field" /></label><label class="chart-label">Peso<select v-model="draft.style.fontWeight" class="field"><option value="normal">Normal</option><option value="bold">Negrita</option></select></label><label class="chart-label">Estilo<select v-model="draft.style.fontStyle" class="field"><option value="normal">Normal</option><option value="italic">Cursiva</option></select></label>
     </fieldset>
     <fieldset class="chart-fieldset"><legend>Colores</legend><label class="chart-label">Paleta propia · hex separados por comas<input v-model="paletteText" class="field font-mono" placeholder="#7c3aed, #10b98188" /></label><button type="button" class="chart-button" :disabled="!kit?.palette?.length" @click="draft.options.palette=[...kit.palette]">Usar paleta del kit</button><label v-for="[field,label] in [['text_color','Color del texto'],['axis_color','Color de ejes'],['grid_color','Color de rejilla'],['background','Fondo']]" :key="field" class="chart-label">{{label}}<input v-model="draft.style[field]" class="field font-mono" placeholder="#11223388" /></label><p class="text-xs text-slate-500">Se conserva la transparencia de los colores hexadecimales.</p></fieldset>
     <fieldset class="chart-fieldset"><legend>Ejes, leyenda y etiquetas</legend><label v-for="[field,label] in [['legend','Mostrar leyenda'],['show_grid','Mostrar rejilla'],['show_axis','Mostrar ejes'],['category_labels','Mostrar categorías']]" :key="field" class="flex items-center gap-2 text-sm"><input v-model="draft.style[field]" type="checkbox" />{{label}}</label><label class="chart-label">Posición de leyenda<select v-model="draft.style.legend_position" class="field"><option value="bottom">Abajo</option><option value="right">Derecha</option></select></label><label class="chart-label">Etiquetas de valores<select v-model="draft.style.value_labels" class="field"><option value="none">Ocultas</option><option value="raw">Valor original</option><option value="percentage">Porcentaje · sólo proporcionales</option></select></label><label class="chart-label">Posición de etiquetas<select v-model="draft.style.label_position" class="field"><option value="outside">Fuera</option><option value="inside">Dentro</option></select></label><label class="chart-label">Límite visual de categoría<input v-model.number="draft.style.category_label_limit" type="number" min="1" max="80" class="field" /></label></fieldset>
     <fieldset class="chart-fieldset"><legend>Rango y separación</legend><label v-for="[field,label] in [['domain_min','Mínimo del dominio'],['domain_max','Máximo del dominio']]" :key="field" class="chart-label">{{label}} · vacío = automático<input :value="draft.options[field]" type="number" step="any" class="field" @input="draft.options[field]=$event.target.value===''?null:Number($event.target.value)" /></label><label class="chart-label">Número de marcas del eje<input v-model.number="draft.options.tick_count" type="number" min="2" max="21" class="field" /></label><label v-for="[field,label] in [['band_gap','Separación de categorías'],['series_gap','Separación de series']]" :key="field" class="chart-label">{{label}}<input v-model.number="draft.options[field]" type="number" min="0" max="0.9" step="0.05" class="field" /></label></fieldset>
    </section>
    <section class="rounded-xl border border-slate-200 bg-slate-50 p-3" aria-label="Vista previa del gráfico"><p v-if="previewBusy" role="status" class="text-sm">Preparando vista previa…</p><img v-if="previewUrl" :src="previewUrl" alt="Vista previa del gráfico con los datos guardados" class="w-full rounded-lg" /><p v-else class="py-8 text-center text-sm text-slate-500">La vista previa aparecerá al validar los datos.</p><ul v-if="warnings.length" class="mt-2 space-y-1 text-xs text-amber-800"><li v-for="(warning,index) in warnings" :key="index">{{warningLabel(warning)}}</li></ul></section>
    <section v-if="dataOpen && dataTable" class="space-y-3 bg-white" aria-label="Tabla accesible del gráfico"><h3 class="font-semibold">Datos originales de la revisión {{draft.binding.dataset.revision}}</h3><div class="max-h-80 overflow-auto rounded-xl border" role="region" aria-label="Datos originales del gráfico" tabindex="0"><table class="w-full text-left text-xs"><caption class="sr-only">Categorías y series seleccionadas, tipos y valores originales</caption><thead><tr><th scope="col" class="p-2">Fila</th><th v-for="c in displayedColumns" :key="c.id" scope="col" class="p-2">{{c.label}}</th></tr></thead><tbody><tr v-for="row in displayedRows" :key="row.id"><th scope="row" class="p-2">{{row.id}}</th><td v-for="c in displayedColumns" :key="c.id" class="p-2 whitespace-pre-wrap">{{c.index < 0 ? 'Columna no disponible en esta revisión' : cellLabel(row.values[c.index])}}</td></tr></tbody><tfoot v-if="dataTable.rows.length===0"><tr><td :colspan="displayedColumns.length+1" class="p-2">Esta revisión no contiene filas.</td></tr></tfoot></table></div><nav class="flex flex-wrap gap-3 text-xs" aria-label="Páginas de datos del gráfico"><button type="button" class="chart-button" :disabled="dataPage===0" @click="dataPage--">Anterior</button><span role="status">Página {{dataPage+1}} de {{Math.max(1,Math.ceil(dataTable.rows.length/50))}} · {{dataTable.rows.length}} filas</span><button type="button" class="chart-button" :disabled="(dataPage+1)*50>=dataTable.rows.length" @click="dataPage++">Siguiente</button></nav></section>
   </template>
   <p v-if="error || configuration.error" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{error || configuration.error}}</p>
   <footer class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t bg-white py-3"><button type="button" class="chart-button" @click="close">Cancelar</button><button type="button" class="rounded-lg bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40" :disabled="!canApply" @click="apply">{{chart?'Aplicar gráfico':'Insertar gráfico'}}</button></footer>
  </div>
 </StudioDialog>
</template>
<style scoped>
select.field{appearance:auto;-webkit-appearance:auto;background-image:none}
.chart-label{display:block;font-size:.8rem;font-weight:500}.chart-label .field{display:block;width:100%;margin-top:.35rem}.chart-fieldset{display:grid;gap:1rem;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem;min-width:0}.chart-fieldset legend{font-size:.85rem;font-weight:600;padding:0 .3rem}.chart-button,.chart-tab{border:1px solid #e2e8f0;border-radius:.5rem;padding:.55rem .8rem;font-size:.75rem;background:white}.chart-tab{flex:1}.chart-tab[aria-pressed=true]{color:#6d28d9;border-color:#c4b5fd}.chart-button:disabled{opacity:.4}
</style>
