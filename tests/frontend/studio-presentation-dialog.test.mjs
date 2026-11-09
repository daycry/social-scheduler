import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
const url=new URL('../../resources/js/Studio/presentation-audience.js',import.meta.url);
const subject=await import(url).catch(e=>{if(e.code==='ERR_MODULE_NOT_FOUND'&&e.url===url.href)return {};throw e;});
function fakeWindow(){
 const events=new Map(),draw=[];let closes=0;const context={drawImage:(...a)=>draw.push(a),fillRect:(...a)=>draw.push(['black',...a])};
 const listeners=new Map();
 const canvas={style:{},setAttribute(){},getContext:()=>context,addEventListener:(key,fn)=>listeners.set(key,fn),removeEventListener:key=>listeners.delete(key)};
 const popup={closed:false,document:{title:'',body:{style:{},replaceChildren(...nodes){this.children=nodes;}},createElement:()=>canvas},
  addEventListener:(key,fn)=>events.set(key,fn),removeEventListener:key=>events.delete(key),close(){closes++;this.closed=true;}};
 return {popup,canvas,events,draw,get closes(){return closes;}};
}
const create=(...args)=>{assert.equal(typeof subject.createAudienceWindow,'function','audience window capability is absent');return subject.createAudienceWindow(...args);};
test('blocked audience window reports an actionable error',()=>{assert.throws(()=>create({opener:{open:()=>null}}),/bloquead/i);});
test('audience receives only canvas pixels, with blackout and owned-window cleanup',()=>{
 const f=fakeWindow();let key;const audience=create({opener:{open:()=>f.popup},onKey:e=>{key=e.key;}});
 assert.deepEqual(f.popup.document.body.children,[f.canvas]);audience.paint({width:320,height:180},{blackout:false});
 assert.equal(f.draw.length,1);audience.paint({width:320,height:180},{blackout:true});assert.equal(f.draw.at(-1)[0],'black');
 f.events.get('keydown')({key:'b'});assert.equal(key,'b');audience.close();audience.close();assert.equal(f.closes,1);assert.equal(f.events.size,0);
});
test('fullscreen failures are surfaced and manually closed popup is safe',async()=>{
 const f=fakeWindow(),audience=create({opener:{open:()=>f.popup}});
 await assert.rejects(audience.fullscreen(),/pantalla completa/i);f.popup.closed=true;assert.equal(audience.paint({},{}),false);audience.close();assert.equal(f.closes,0);
});
test('presentation and editor SFC compile, with real modal, text notes and guarded editor shortcuts',async()=>{
 for(const file of ['resources/js/Components/Studio/PresentationDialog.vue','resources/js/Pages/Studio/Editor.vue']){
  let source='';try{source=await readFile(new URL('../../'+file,import.meta.url),'utf8');}catch(e){if(e.code!=='ENOENT')throw e;}
  assert.ok(source.length>0,`${file} presentation UI is absent`);const {descriptor,errors}=parse(source);assert.deepEqual(errors,[]);
  const script=compileScript(descriptor,{id:'presentation-test'});assert.ok(script.content.length>0);
  const template=compileTemplate({source:descriptor.template.content,filename:file,id:'presentation-test',compilerOptions:{bindingMetadata:script.bindings}});assert.deepEqual(template.errors,[]);
  if(file.includes('PresentationDialog')) {assert.match(source,/showModal\(/);assert.match(source,/presentationKey/);assert.match(source,/state\.notes/);assert.match(source,/offline: true, renderScale: presentationPreviewScale/);assert.doesNotMatch(source,/v-html|innerHTML/);}
  else {assert.match(source,/presentationOpen\.value/);assert.match(source,/Notas del presentador/);assert.match(source,/Array\.from\(value\)\.length/);}
 }
});
