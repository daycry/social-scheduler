import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import * as realCore from '../../resources/js/Studio/dataset-table.js';
import { computed, ref, shallowRef, watch, reactive, nextTick, effectScope } from 'vue';

const blank = () => ({ columns: [{ id: 'c1', label: 'Columna 1' }], rows: [{ id: 'r1', values: [''] }] });
const fakeCore = {
    blankDatasetTable: blank,
    createDatasetHistory(initial) {
        let table = initial; const undo = [], redo = [];
        return { get table() { return table; }, get canUndo() { return undo.length > 0; }, get canRedo() { return redo.length > 0; }, limit: 30,
            commit(next) { undo.push(table); table = next; redo.length = 0; }, reset(next) { table = next; undo.length = redo.length = 0; },
            undo() { if (undo.length) { redo.push(table); table = undo.pop(); } return table; }, redo() { if (redo.length) { undo.push(table); table = redo.pop(); } return table; },
        };
    },
};
function mount(client = {}, initial = {}, core = fakeCore) {
    const source = readFileSync(new URL('../../resources/js/Components/Studio/StudioDatasetsDialog.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'datasets-dialog-regression' }).content.replace(/^import .*?;\r?$/gm, '').replace('export default', 'return');
    const downloads = [], windowListeners = new Map();
    const fakeWindow = { addEventListener: (name, fn) => windowListeners.set(name, fn), removeEventListener: name => windowListeners.delete(name) };
    const props = reactive({ modelValue: true, brandId: 3, brandName: 'Marca A', ...initial }), hooks = [], emitted = [];
    const defaults = { list: async () => ({ datasets: [], pagination: { current_page: 1, last_page: 1, total: 0 } }), ...client };
    const factory = new Function('computed', 'nextTick', 'onBeforeUnmount', 'ref', 'shallowRef', 'watch', 'createDatasetClient', 'tableCore', 'downloadStudioBlob', 'window', compiled);
    const component = factory(computed, nextTick, fn => hooks.push(fn), ref, shallowRef, watch, () => defaults, core, (...args) => downloads.push(args), fakeWindow);
    const scope = effectScope(); const state = scope.run(() => component.setup(props, { expose() {}, emit: (...args) => emitted.push(args) }));
    return { state, props, emitted, downloads, windowListeners, close() { hooks.forEach(hook => hook()); scope.stop(); } };
}
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); };

test('saved chart selection emits pinned metadata only and rejects text numbers and dirty drafts', async () => {
 const table = { columns: [{id:'cat',label:'Categor?a'},{id:'num',label:'Valor'},{id:'txt',label:'Texto'}], rows:[{id:'r1',values:['A',realCore.datasetNumber('2'),'3']},{id:'r2',values:['B',null,'4']}] };
 const panel=mount({load:async()=>({table,revision:2,sha256:'a'.repeat(64)})},{selectable:true},realCore);
 try {
 await panel.state.selectDataset({id:7,name:'Datos',revision:2});
 assert.equal(panel.state.canSelectChart?.value,true);
 panel.state.selectChart();
 const payload=panel.emitted.find(e=>e[0]==='select-chart')[1];
 assert.deepEqual(payload.dataset,{id:7,revision:2,sha256:'a'.repeat(64)});
 assert.deepEqual(payload.columns.map(c=>c.numeric),[false,true,false]);
 assert.equal('table' in payload,false);
 panel.state.name.value='Borrador'; panel.state.selectChart();
 assert.equal(panel.emitted.filter(e=>e[0]==='select-chart').length,1);
 } finally {panel.close();}
});
import { existsSync } from 'node:fs';
import { normalizeChartSpec } from '../../resources/js/Studio/chart-spec.js';
import { blankDocument, newElement } from '../../resources/js/Studio/document.js';
function chartMount(renderStudioPage, initial={}) {
 const path=new URL('../../resources/js/Components/Studio/StudioChartsDialog.vue',import.meta.url);
 const source=existsSync(path)?readFileSync(path,'utf8'):'<script setup>const draft = null;</script><template><div /></template>';
 const {descriptor}=parse(source); const compiled=compileScript(descriptor,{id:'charts-regression'}).content.replace(/^import .*?;\r?$/gm,'').replace('export default','return');
 const props=reactive({modelValue:true,brandId:3,chartResources:{},fonts:[],kit:{palette:['#11223388'],fonts:['Georgia']},chart:null,source:{dataset:{id:7,revision:2,sha256:'a'.repeat(64)},columns:[{id:'cat',label:'Categoría',numeric:false},{id:'val',label:'Valor',numeric:true}]},...initial});
 const hooks=[],emitted=[];
 const factory=new Function('computed','nextTick','onBeforeUnmount','ref','shallowRef','watch','normalizeChartSpec','blankDocument','newElement','renderStudioPage','StudioDialog','tableCore',compiled);
 const component=factory(computed,nextTick,fn=>hooks.push(fn),ref,shallowRef,watch,normalizeChartSpec,blankDocument,newElement,renderStudioPage,{},realCore);
 const scope=effectScope();const state=scope.run(()=>component.setup(props,{expose(){},emit:(...args)=>emitted.push(args)}));
 return {state,props,emitted,close(){hooks.forEach(h=>h());scope.stop();}};
}
test('chart configuration remains a clone and insertion requires a successful real preview',async()=>{
 const calls=[];const panel=chartMount(async(...args)=>{calls.push(args);return {canvas:{toDataURL:()=> 'data:image/png;base64,REAL',getObjects:()=>[]},dispose(){}};});
 try {
  assert.equal(panel.state.draft?.value?.binding.dataset.revision,2);
  assert.equal(panel.state.canApply.value,false);
  await panel.state.refreshPreview();
  assert.equal(calls[0][0].pages[0].elements[0].type,'chart');
  assert.equal(calls[0][4].chartResources,panel.props.chartResources);
  assert.equal(panel.state.canApply.value,true);panel.state.apply();
  assert.equal(panel.emitted.find(e=>e[0]==='apply')[1].binding.dataset.sha256,'a'.repeat(64));
 }finally{panel.close();}
});
test('chart rejects incompatible series and percentages instead of repairing them',async()=>{
 const panel=chartMount(async()=>{throw new Error('must not render invalid');});
 try{assert.ok(panel.state.draft?.value);panel.state.draft.value.style.value_labels='percentage';await panel.state.refreshPreview();assert.match(panel.state.error.value,/percentage|porcent/i);assert.equal(panel.state.canApply.value,false);}finally{panel.close();}
});
import { validateDocument, cloneDocument, findPage } from '../../resources/js/Studio/document.js';
import { createHistory } from '../../resources/js/Studio/history.js';
function editorMount() {
 const source=readFileSync(new URL('../../resources/js/Pages/Studio/Editor.vue',import.meta.url),'utf8');const {descriptor}=parse(source);
 const compiled=compileScript(descriptor,{id:'editor-chart'}).content.replace(/^import .*?;\r?$/gm,'').replace('export default','return');
 const imports=[...source.matchAll(/^import\s+(.*?)\s+from/gm)].flatMap(m=>m[1].replace(/[{}]/g,'').split(',').map(x=>x.trim())).filter(Boolean);
 const deps={computed,nextTick,ref,shallowRef,watch,onBeforeUnmount(){},onMounted(){},validateDocument,cloneDocument,findPage,createHistory};
 const names=[...new Set(imports)],values=names.map(name=>deps[name]??{});
 const component=new Function(...names,compiled)(...values);const props=reactive({design:{id:4,name:'Test',document:blankDocument(),revision:1},brand:{id:3,name:'Marca'},kit:{palette:[]},fonts:[],assets:[]});
 const scope=effectScope();const state=scope.run(()=>component.setup(props,{expose(){}}));return{state,props,close(){scope.stop();}};
}
test('editor opens a chart draft for the selected historical layer without mutation',()=>{
 const panel=editorMount();try{const chart={binding:{dataset:{id:7,revision:1,sha256:'b'.repeat(64)},category_column:'cat',series_columns:['val']}};panel.state.selection.value=[{id:'chart_1',type:'chart',chart}];
 assert.equal(typeof panel.state.editChart,'function');panel.state.editChart();assert.equal(panel.state.chartsOpen.value,true);assert.deepEqual(panel.state.chartDraft.value,chart);assert.notEqual(panel.state.chartDraft.value,chart);
 }finally{panel.close();}
});
import * as Vue from 'vue';
function rendered(panel, file='StudioChartsDialog.vue') {
 const {descriptor}=parse(readFileSync(new URL(`../../resources/js/Components/Studio/${file}`,import.meta.url),'utf8'));
 const script=compileScript(descriptor,{id:'chart-vnodes'});
 const result=compileTemplate({source:descriptor.template.content,filename:file,id:'chart-vnodes',compilerOptions:{bindingMetadata:script.bindings}});assert.deepEqual(result.errors,[]);
 const body=result.code.replace(/import\s*\{([\s\S]*?)\}\s*from\s*"vue"/g,(_,names)=>`const {${names.replace(/ as /g,': ')}}=Vue;`).replace('export function render','return function render');
 const render=new Function('Vue',body)(Vue);return render({},[],panel.props,Vue.proxyRefs(panel.state),{},{});
}
function nodes(vnode) {
 if(vnode===null||vnode===undefined||typeof vnode==='boolean')return[];
 if(Array.isArray(vnode))return vnode.flatMap(nodes);
 if(typeof vnode!=='object')return[];
 return [vnode,...nodes(vnode.children),...(vnode.children?.default?nodes(vnode.children.default()):[])];
}
function text(vnode){if(Array.isArray(vnode))return vnode.map(text).join('');if(vnode==null)return '';if(typeof vnode!=='object')return String(vnode);return text(vnode.children);}
test('compiled real Vue template exposes ten types, every style control and hex alpha palette',async()=>{
 const panel=chartMount(async()=>{throw Error('unused');});
 try{
 const tree=nodes(rendered(panel));const labels=tree.filter(n=>n.type==='label').map(text);
 for(const name of ['Categoría','Precisión','Título','Subtítulo','Fuente de datos','Título del eje X','Título del eje Y','Tipografía','Tamaño de letra','Peso','Estilo','Color del texto','Color de ejes','Color de rejilla','Fondo','Mostrar leyenda','Mostrar rejilla','Mostrar ejes','Mostrar categorías','Posición de leyenda','Etiquetas de valores','Posición de etiquetas','Límite visual de categoría','Mínimo del dominio','Máximo del dominio','Número de marcas del eje','Separación de categorías','Separación de series'])assert.ok(labels.some(label=>label.includes(name)),name);
 const type=tree.find(n=>n.type==='label'&&text(n).startsWith('Tipo de gráfico'));assert.equal(nodes(type).filter(n=>n.type==='option').length,10);
 const paletteButton=tree.find(n=>n.type==='button'&&text(n)==='Usar paleta del kit');paletteButton.props.onClick();assert.deepEqual([...panel.state.draft.value.options.palette],['#11223388']);
 }finally{panel.close();}
});
test('preview races discard old scenes and late errors while keeping the latest successful image',async()=>{
 const pending=[],disposed=[];const panel=chartMount((...args)=>new Promise((resolve,reject)=>pending.push({args,resolve,reject})));
 const scene=name=>({canvas:{toDataURL:()=>name,getObjects:()=>[{studioChartScene:{warnings:['dense']}}]},dispose(){disposed.push(name);}});
 try{
 const first=panel.state.refreshPreview();await flush();
 panel.state.draft.value.style.title='New';await flush();const second=panel.state.refreshPreview();
 assert.equal(pending[0].args[4].signal.aborted,true);
 pending[1].resolve(scene('new'));await second;assert.equal(panel.state.previewUrl.value,'new');
 pending[0].resolve(scene('old'));await first;assert.equal(panel.state.previewUrl.value,'new');assert.deepEqual(disposed,['old']);
 const third=panel.state.refreshPreview();panel.state.close();pending[2].reject(Error('late'));await third;assert.equal(panel.state.error.value,'');assert.equal(panel.state.previewUrl.value,'');assert.ok(disposed.includes('new'));
 }finally{panel.close();}
});
test('cancel preserves original chart and never disposes the editor resource registry',()=>{
 let disposals=0;const chart={binding:{dataset:{id:7,revision:1,sha256:'b'.repeat(64)},category_column:'cat',series_columns:['val']}};
 const panel=chartMount(async()=>{}, {chart,chartResources:{dispose(){disposals++;}}});
 try{panel.state.draft.value.style.title='Draft';panel.state.close();assert.equal(chart.style,undefined);assert.equal(disposals,0);assert.equal(panel.emitted.some(e=>e[0]==='apply'),false);}finally{panel.close();}
});
test('data view retains typed immutable tokens, duplicates and null distinctly with local pagination',async()=>{
 const number=realCore.datasetNumber('-0.00');const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:Array.from({length:101},(_,i)=>({id:`r${i}`,values:['Same',i===0?number:i===1?null:i===2?'':realCore.datasetNumber('0')]}))};
 const panel=chartMount(async()=>{}, {chartResources:{resolve:async()=>({table})}});
 try{await panel.state.viewData();assert.equal(panel.state.dataTable.value,table);assert.equal(panel.state.cellLabel(number),'Número: -0.00');assert.equal(panel.state.cellLabel(null),'Vacío (null)');assert.equal(panel.state.cellLabel(''),'Texto: ""');assert.equal(panel.state.displayedRows.value.length,50);panel.state.dataPage.value=2;assert.equal(panel.state.displayedRows.value.length,1);const tree=nodes(rendered(panel));assert.ok(tree.some(n=>n.type==='table'));assert.ok(tree.some(n=>n.type==='nav'&&n.props['aria-label']==='Páginas de datos del gráfico'));}finally{panel.close();}
});
test('chart preview surfaces warnings produced by the shared scene',async()=>{
 const panel=chartMount(async()=>({canvas:{toDataURL:()=> 'ok',getObjects:()=>[{studioChartScene:{warnings:['Dense labels']}}]},dispose(){}}));
 try{await panel.state.refreshPreview();assert.deepEqual([...panel.state.warnings.value],['Dense labels']);}finally{panel.close();}
});
test('closing while data loads clears the pending indicator and ignores its late table',async()=>{
 let finish;const panel=chartMount(async()=>{}, {chartResources:{resolve:()=>new Promise(resolve=>finish=resolve)}});
 try{const pending=panel.state.viewData();assert.equal(panel.state.dataBusy.value,true);panel.state.close();assert.equal(panel.state.dataBusy.value,false);finish({table:{columns:[],rows:[]}});await pending;assert.equal(panel.state.dataTable.value,null);}finally{panel.close();}
});
test('editor applies the whole chart once, serializes insertion and fences a changed selection',async()=>{
 const panel=editorMount();const calls=[];let finish;
 try{
 panel.state.ready.value=true;panel.state.chartResources.value={};panel.state.adapter={addChart:chart=>{calls.push(['add',chart]);return new Promise(resolve=>finish=resolve);},updateSelection:async patch=>calls.push(['patch',patch]),layers:()=>[]};
 const chart={binding:{dataset:{id:7,revision:2,sha256:'a'.repeat(64)},category_column:'cat',series_columns:['val']}};
 panel.state.startChart();const pending=panel.state.applyChart(chart);await panel.state.applyChart(chart);assert.equal(calls.length,1);finish();await pending;
 panel.state.selection.value=[{id:'a',type:'chart',chart}];panel.state.editChart();await panel.state.applyChart(chart);assert.deepEqual(calls[1],['patch',{chart}]);
 panel.state.editChart();panel.state.selection.value=[{id:'b',type:'chart',chart}];await panel.state.applyChart(chart);assert.equal(calls.length,2);assert.match(panel.state.error.value,/selección cambió/);
 }finally{panel.close();}
});
test('saved after edit requires an explicit reload before chart selection regains its SHA',async()=>{
 const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:[{id:'r1',values:['A',realCore.datasetNumber('1')]}]};
 const panel=mount({load:async(_b,_id,revision)=>({table,revision,sha256:'b'.repeat(64)}),save:async()=>({dataset:{id:7,name:'New',revision:3}}),get:async()=>({dataset:{id:7,name:'New',revision:3}})},{selectable:true},realCore);
 try{await panel.state.selectDataset({id:7,name:'Old',revision:2});panel.state.name.value='New';await panel.state.saveTable();assert.equal(panel.state.canSelectChart.value,false);panel.state.selectChart();assert.equal(panel.emitted.some(e=>e[0]==='select-chart'),false);await panel.state.reloadSaved();assert.equal(panel.state.canSelectChart.value,true);panel.state.selectChart();assert.equal(panel.emitted.find(e=>e[0]==='select-chart')[1].dataset.revision,3);}finally{panel.close();}
});
test('scene warning codes render readable Spanish notices without technical payloads',async()=>{
 const codes=['TEXT_FITTED','CATEGORY_LABEL_SHORTENED','CATEGORY_LABELS_SAMPLED','LEGEND_SUMMARIZED','ZERO_TOTAL','APPROXIMATE_COORDINATE','UNDERFLOW_COORDINATE','ZERO_SIZE_BAR','FUTURE_UNKNOWN'];
 const panel=chartMount(async()=>({canvas:{toDataURL:()=> 'ok',getObjects:()=>[{studioChartScene:{warnings:codes.map(code=>({code,raw:'private value',row_id:'r1'}))}}]},dispose(){}}));
 try{await panel.state.refreshPreview();const notices=nodes(rendered(panel)).filter(n=>n.type==='li').map(text);assert.equal(notices.length,codes.length);assert.match(notices[0],/texto.*espacio/i);assert.match(notices[1],/etiquetas.*acort/i);assert.match(notices[2],/selecci\u00f3n.*etiquetas/i);assert.match(notices[3],/leyenda.*resum/i);assert.match(notices[4],/total.*cero/i);assert.match(notices[5],/aproxima/i);assert.match(notices[6],/peque\u00f1os/i);assert.match(notices[7],/peque\u00f1os/i);assert.match(notices[8],/aviso/i);for(const notice of notices){assert.equal(notice.includes('{'),false);assert.equal(notice.includes('private value'),false);assert.equal(codes.some(code=>notice.includes(code)),false);}}finally{panel.close();}
});
test('all chart text controls admit 256 Unicode code points and report 257 without truncating',async()=>{
 const panel=chartMount(async()=>({canvas:{toDataURL:()=> 'ok',getObjects:()=>[]},dispose(){}}));
 try{const labels=nodes(rendered(panel)).filter(n=>n.type==='label');for(const labelText of ['T\u00edtulo','Subt\u00edtulo','Fuente de datos','T\u00edtulo del eje X','T\u00edtulo del eje Y']){const label=labels.find(node=>text(node)===labelText);assert.ok(label,labelText);const input=nodes(label).find(node=>node.type==='input');assert.ok(input.props.maxlength===undefined||Number(input.props.maxlength)>=512,`${labelText} must allow 512 UTF-16 units`);input.props['onUpdate:modelValue']('\u{1f600}'.repeat(256));assert.equal(panel.state.configuration.value.error,'');input.props['onUpdate:modelValue']('\u{1f600}'.repeat(257));assert.match(panel.state.configuration.value.error,/256/);const alert=nodes(rendered(panel)).find(node=>node.props?.role==='alert');assert.ok(alert);assert.match(text(alert),/256/);input.props['onUpdate:modelValue']('');} }finally{panel.close();}
});
test('switching saved datasets explicitly remaps missing category and removes stale series while preserving style',async()=>{
 const editor=editorMount();
 const original=normalizeChartSpec({binding:{dataset:{id:7,revision:1,sha256:'a'.repeat(64)},category_column:'old_cat',series_columns:['old_val']},style:{title:'My title',subtitle:'Keep subtitle',fontFamily:'Georgia',background:'#11223388'}});
 const nextSource={dataset:{id:9,revision:3,sha256:'b'.repeat(64)},columns:[{id:'new_cat',label:'New category',numeric:false},{id:'new_val',label:'New value',numeric:true}]};
 let panel;const renders=[];
 try{
 editor.state.selection.value=[{id:'chart_1',type:'chart',chart:original}];editor.state.editChart();editor.state.chooseChartData(editor.state.chartDraft.value);editor.state.selectChartData(nextSource);
 panel=chartMount(async(document)=>{const chart=document.pages[0].elements[0].chart;renders.push(chart);if(chart.binding.category_column!=='new_cat'||chart.binding.series_columns.join(',')!=='new_val')throw Error('Columns missing');return {canvas:{toDataURL:()=> 'ok',getObjects:()=>[]},dispose(){}};},{chart:editor.state.chartDraft.value,source:editor.state.chartSource.value});
 assert.equal(panel.state.draft.value.binding.category_column,'old_cat');assert.deepEqual([...panel.state.draft.value.binding.series_columns],['old_val']);assert.equal(panel.state.canApply.value,false);
 let tree=nodes(rendered(panel));const category=tree.find(n=>n.type==='label'&&text(n).startsWith('Categor\u00eda'));
 assert.ok(nodes(category).some(n=>n.type==='option'&&n.props.value==='old_cat'&&text(n).includes('old_cat')),'missing category remains visible until explicitly remapped');
 const categorySelect=nodes(category).find(n=>n.type==='select');categorySelect.props['onUpdate:modelValue']('new_cat');
 tree=nodes(rendered(panel));const remove=tree.find(n=>n.type==='button'&&n.props?.['aria-label']==='Retirar serie old_val');assert.ok(remove,'stale series can be explicitly removed');remove.props.onClick();
 tree=nodes(rendered(panel));const series=tree.find(n=>n.type==='label'&&text(n)==='New value');nodes(series).find(n=>n.type==='input').props.onChange({target:{checked:true}});
 await flush();assert.equal(panel.state.canApply.value,false);await panel.state.refreshPreview();assert.equal(panel.state.canApply.value,true);assert.equal(renders.length,1);
 assert.deepEqual(panel.state.draft.value.style,original.style);assert.equal(original.binding.dataset.id,7);assert.deepEqual([...original.binding.series_columns],['old_val']);panel.state.apply();
 const applied=panel.emitted.find(e=>e[0]==='apply')[1];assert.equal(applied.binding.dataset.id,9);assert.equal(applied.binding.category_column,'new_cat');assert.deepEqual(applied.binding.series_columns,['new_val']);assert.deepEqual(applied.style,original.style);
 }finally{panel?.close();editor.close();}
});
test('viewing replacement dataset before remapping marks absent columns without interpreting them as null',async()=>{
 const chart={binding:{dataset:{id:9,revision:3,sha256:'b'.repeat(64)},category_column:'old_cat',series_columns:['old_val']}};
 const table={columns:[{id:'new_cat',label:'New category'},{id:'new_val',label:'New value'}],rows:[{id:'r1',values:['A',realCore.datasetNumber('1')]}]};
 const panel=chartMount(async()=>{}, {chart,source:{dataset:chart.binding.dataset,columns:table.columns.map((c,i)=>({...c,numeric:i===1}))},chartResources:{resolve:async()=>({table})}});
 try{await panel.state.viewData();const cells=nodes(rendered(panel)).filter(n=>n.type==='td').map(text);assert.deepEqual(cells,['Columna no disponible en esta revisi\u00f3n','Columna no disponible en esta revisi\u00f3n']);assert.equal(panel.state.canApply.value,false);}finally{panel.close();}
});
test('a selected series can be unchecked after explicitly becoming the category',async()=>{
 const panel=chartMount(async()=>({canvas:{toDataURL:()=> 'ok',getObjects:()=>[]},dispose(){}}),{source:{dataset:{id:7,revision:2,sha256:'a'.repeat(64)},columns:[{id:'cat',label:'Category',numeric:true},{id:'val',label:'Value',numeric:true}]}});
 try{
 const style=JSON.parse(JSON.stringify(panel.state.draft.value.style));let tree=nodes(rendered(panel));const category=tree.find(n=>n.type==='label'&&text(n).startsWith('Categor\u00eda'));nodes(category).find(n=>n.type==='select').props['onUpdate:modelValue']('val');await flush();assert.equal(panel.state.canApply.value,false);
 tree=nodes(rendered(panel));let checkbox=nodes(tree.find(n=>n.type==='label'&&text(n)==='Value')).find(n=>n.type==='input');assert.equal(checkbox.props.checked,true);assert.equal(checkbox.props.disabled,false,'selected category series remains removable');checkbox.props.onChange({target:{checked:false}});
 tree=nodes(rendered(panel));checkbox=nodes(tree.find(n=>n.type==='label'&&text(n)==='Value')).find(n=>n.type==='input');assert.equal(checkbox.props.checked,false);assert.equal(checkbox.props.disabled,true,'category cannot be added as a series');nodes(tree.find(n=>n.type==='label'&&text(n)==='Category')).find(n=>n.type==='input').props.onChange({target:{checked:true}});await flush();assert.equal(panel.state.canApply.value,false);await panel.state.refreshPreview();assert.equal(panel.state.canApply.value,true);assert.deepEqual(panel.state.draft.value.style,style);
 }finally{panel.close();}
});
test('chart data scroll container is a named keyboard-focusable region',async()=>{
 const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:[{id:'r1',values:['A',realCore.datasetNumber('1')]}]};
 const panel=chartMount(async()=>{},{chartResources:{resolve:async()=>({table})}});
 try{await panel.state.viewData();const tree=nodes(rendered(panel));const region=tree.find(node=>node.props?.role==='region'&&nodes(node).some(child=>child.type==='table'));assert.ok(region,'scrolling table has a region');assert.equal(Number(region.props.tabindex),0);assert.ok(region.props['aria-label']||region.props['aria-labelledby'],'focusable region has an accessible name');}finally{panel.close();}
});
test('opening source data survives scheduled preview startup',async(t)=>{ t.mock.timers.enable({apis:['setTimeout']}); let finish; const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:[{id:'r1',values:['Original',realCore.datasetNumber('-20')]}]}; const panel=chartMount(async()=>({canvas:{toDataURL:()=> 'data:image/png;base64,preview',getObjects:()=>[]},dispose(){}}),{chartResources:{resolve:()=>new Promise(resolve=>{finish=resolve;})}}); try{  const pending=panel.state.viewData();assert.equal(panel.state.dataBusy.value,true);  t.mock.timers.tick(200);await flush();  finish({table});await pending;await flush();  assert.equal(panel.state.dataTable.value,table,'starting the preview cannot discard the requested source table');  assert.equal(panel.state.dataOpen.value,true);  const rows=nodes(rendered(panel)).filter(node=>node.type==='td').map(text);  assert.deepEqual(rows,['Texto: Original','Número: -20']); }finally{panel.close();t.mock.timers.reset();}});test('viewing an empty historical dataset preserves its valid numeric binding and style',async()=>{ const chart=normalizeChartSpec({binding:{dataset:{id:7,revision:1,sha256:'b'.repeat(64)},category_column:'cat',series_columns:['val']},style:{title:'Empty history',fontFamily:'Georgia',background:'#11223388'}});
 const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:[]};let renders=0;
 const panel=chartMount(async()=>{renders++;return {canvas:{toDataURL:()=> 'ok',getObjects:()=>[]},dispose(){}};},{chart,source:null,chartResources:{resolve:async()=>({table})}});
 try{await panel.state.refreshPreview();assert.equal(panel.state.canApply.value,true,'empty historical chart valid before inspecting data');const before=JSON.parse(JSON.stringify(panel.state.draft.value));await panel.state.viewData();await flush();assert.equal(panel.state.configuration.value.error,'','inspecting zero rows cannot invalidate numeric roles');assert.deepEqual(panel.state.draft.value,before);assert.equal(panel.state.dataTable.value,table);assert.equal(table.rows.length,0);assert.ok(nodes(rendered(panel)).some(n=>n.type==='table'));await panel.state.refreshPreview();assert.equal(panel.state.canApply.value,true);assert.equal(renders,2);panel.state.apply();const applied=panel.emitted.find(e=>e[0]==='apply')[1];assert.deepEqual(applied,before);assert.equal(chart.binding.dataset.revision,1);}finally{panel.close();}
});
test('saved empty tables expose numeric roles without coercing existing text cells',async()=>{
 const columns=[{id:'cat',label:'Category'},{id:'val',label:'Value'}];
 const panel=mount({load:async(_brand,id,revision)=>({table:{columns,rows:id===7?[]:[{id:'r1',values:['A','1']}]},revision,sha256:'b'.repeat(64)})},{selectable:true},realCore);
 try{await panel.state.selectDataset({id:7,name:'Empty',revision:1});assert.equal(panel.state.canSelectChart.value,true);panel.state.selectChart();const empty=panel.emitted.find(e=>e[0]==='select-chart')[1];assert.deepEqual(empty.columns.map(c=>c.numeric),[true,true]);assert.equal('rows' in empty,false);await panel.state.selectDataset({id:8,name:'Text',revision:1});assert.deepEqual(panel.state.chartColumns.value.map(c=>c.numeric),[false,false]);assert.equal(panel.state.table.value.rows[0].values[1],'1');}finally{panel.close();}
});
test('empty historical chart data renders a table-wide footer without inventing tbody rows',async()=>{ const chart=normalizeChartSpec({binding:{dataset:{id:7,revision:1,sha256:'b'.repeat(64)},category_column:'cat',series_columns:['val']}}); const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'},{id:'unused',label:'Unselected'},{id:'extra',label:'Another series'}],rows:[]}; const panel=chartMount(async()=>{}, {chart,source:null,chartResources:{resolve:async()=>({table})}}); try{  const before=JSON.parse(JSON.stringify(panel.state.draft.value));await panel.state.viewData();  let renderedTable=nodes(rendered(panel)).find(n=>n.type==='table');assert.ok(renderedTable);  assert.equal(renderedTable.props?.role,undefined);assert.equal(nodes(renderedTable).find(n=>n.type==='caption')?.children,'Categorías y series seleccionadas, tipos y valores originales');  assert.deepEqual(nodes(nodes(renderedTable).find(n=>n.type==='thead')).filter(n=>n.type==='th').map(text),['Fila','Category','Value']);  const body=nodes(renderedTable).find(n=>n.type==='tbody');assert.equal(nodes(body).filter(n=>n.type==='tr').length,0);  let footer=nodes(renderedTable).find(n=>n.type==='tfoot');assert.ok(footer,'empty revision needs a visible table-wide footer');  assert.equal(nodes(footer).filter(n=>n.type==='tr').length,1);let cells=nodes(footer).filter(n=>n.type==='td');assert.equal(cells.length,1);assert.equal(Number(cells[0].props.colspan),3,'span includes only Fila and selected columns');assert.equal(text(cells[0]),'Esta revisión no contiene filas.');  assert.deepEqual(panel.state.draft.value,before);assert.equal(table.rows.length,0);assert.ok(nodes(rendered(panel)).some(n=>n.props?.role==='status'&&text(n).includes('0 filas')));  panel.state.draft.value.binding.series_columns.push('extra');  renderedTable=nodes(rendered(panel)).find(n=>n.type==='table');footer=nodes(renderedTable).find(n=>n.type==='tfoot');cells=nodes(footer).filter(n=>n.type==='td');assert.equal(Number(cells[0].props.colspan),4,'footer tracks changed selected series'); }finally{panel.close();}});test('nonempty chart data keeps typed cells and headers without an empty footer even on an empty page',async()=>{ const table={columns:[{id:'cat',label:'Category'},{id:'val',label:'Value'}],rows:[{id:'r1',values:['Original',realCore.datasetNumber('-20')]}]}; const panel=chartMount(async()=>{}, {chartResources:{resolve:async()=>({table})}}); try{  await panel.state.viewData();let renderedTable=nodes(rendered(panel)).find(n=>n.type==='table');  assert.equal(nodes(renderedTable).some(n=>n.type==='tfoot'),false);assert.deepEqual(nodes(nodes(renderedTable).find(n=>n.type==='thead')).filter(n=>n.type==='th').map(text),['Fila','Category','Value']);  assert.deepEqual(nodes(nodes(renderedTable).find(n=>n.type==='tbody')).filter(n=>n.type==='td').map(text),['Texto: Original','Número: -20']);assert.equal(nodes(nodes(renderedTable).find(n=>n.type==='tbody')).filter(n=>n.type==='tr').length,1);  panel.state.dataPage.value=9;assert.equal(panel.state.displayedRows.value.length,0);renderedTable=nodes(rendered(panel)).find(n=>n.type==='table');assert.equal(nodes(renderedTable).some(n=>n.type==='tfoot'),false,'empty visible page does not mean the revision is empty');assert.equal(table.rows.length,1); }finally{panel.close();}});