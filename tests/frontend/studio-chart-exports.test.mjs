import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
const project=fileURLToPath(new URL('../../',import.meta.url));
const source=await fs.readFile(new URL('../../scripts/studio/render.mjs',import.meta.url),'utf8');
const digest=data=>createHash('sha256').update(data).digest('hex');
async function fixture() {
 const root=path.join(project,'storage/framework/testing/t12-chart-static-exports/node-'+Math.random().toString(16).slice(2));
 const output=path.join(root,'studio-exports/1/7/11111111-1111-4111-8111-111111111111');
 await fs.mkdir(path.join(output,'datasets'),{recursive:true});
 const bytes=Buffer.from('{"columns":[],"rows":[]}');const sha256=digest(bytes);
 const descriptor={id:1,revision:2,sha256,bytes:bytes.length,name:`datasets/1-2-${sha256}.json`};
 await fs.writeFile(path.join(output,descriptor.name),bytes,{flag:'wx'});
 return {output,descriptor};
}
async function prepare(output,descriptors, fileSystem = fs) {
 const body=source.slice(source.indexOf('const project ='),source.indexOf('const html =')).replaceAll('import.meta.url',JSON.stringify(pathToFileURL(path.join(project,'scripts/studio/render.mjs')).href));
 const run=new (Object.getPrototypeOf(async function(){}).constructor)('fs','path','fileURLToPath','createHash','process',body+';return {input,resources};');
 return run(fileSystem,path,fileURLToPath,createHash,{argv:['node','render.mjs',output],stdin:(async function*(){yield Buffer.from(JSON.stringify({chartFiles:descriptors,brandId:1}));})()});
}
test('server maps verified canonical chart files to closed synthetic URLs and removes private names',async()=>{
 const {output,descriptor}=await fixture();const {input,resources}=await prepare(output,[descriptor]);
 assert.equal(input.chartFiles,undefined);assert.equal(input.chartDescriptors.length,1);
 const d=input.chartDescriptors[0];assert.equal(d.name,undefined);assert.match(d.url,/^https:\/\/studio-renderer.invalid\/datasets\//);
 assert.equal(resources.get(d.url).mime,'application/json');assert.equal(resources.get(d.url).body.length,descriptor.bytes);
});
test('server rejects traversal unknown fields SHA size and symlink chart files',async()=>{
 const {output,descriptor}=await fixture();
 for(const patch of [{name:'../escape.json'},{extra:'private'},{bytes:descriptor.bytes+1},{sha256:'a'.repeat(64)}, {id:0}]) await assert.rejects(prepare(output,[{...descriptor,...patch}]));
 await fs.writeFile(path.join(output,descriptor.name),'corrupt');await assert.rejects(prepare(output,[descriptor]));
 await assert.rejects(prepare(output,Array(33).fill(descriptor)));
});

const loadBody=async relative=>(await fs.readFile(new URL('../../'+relative,import.meta.url),'utf8')).replace(/^import .*;\r?\n/gm,'').replaceAll('export async function','async function').replaceAll('export function','function').replaceAll('export const','const');
const AsyncFunction=Object.getPrototypeOf(async function(){}).constructor;
test('static export passes the shared chart context as frame options without disposing it',async()=>{
 const body=await loadBody('resources/js/Studio/exports.js'); const chartResources={dispose(){throw new Error('editor context disposed');}};
 let received; const scene={canvas:{backgroundColor:'',renderAll(){},toSVG(){return '<svg/>';},getObjects(){return [];},getZoom(){return 1;}},fontFaces(){return [];},async dispose(){}};
 const factory=new AsyncFunction('validateDocument','frameTimes','renderStudioFrame','StudioDocumentError',body+';return exportPage;');
 const exportPage=await factory(x=>x,()=>new Map([['p',0]]),async(...args)=>{received=args;return scene;},Error);
 await exportPage({width:800,height:500,pages:[]},'p',[],{format:'svg',chartResources});
 assert.equal(received[5].chartResources,chartResources);
});
test('frame rendering forwards chart resources in old and timeline schemas',async()=>{
 const body=await loadBody('resources/js/Studio/frame-renderer.js'); const chartResources={};let received;
 const scene={canvas:{getObjects:()=>[],renderAll(){}},async dispose(){}};
 const run=new AsyncFunction('renderStudioPage','frameElements','compileTimeline','attachFrameCaptions',body+';return renderStudioFrame;');
 const frame=await run(async(...args)=>{received=args;return scene;},x=>x,()=>({pages:[{id:'p',start:0}]}),async s=>s);
 for(const schema_version of [1,2]) {
  await frame({schema_version,timeline:{fps:30},pages:[{id:'p',duration:2,elements:[]}]},'p',0,[],[],{chartResources});
  assert.equal(received[4].chartResources,chartResources);
 }
});
test('isolated render creates an explicitly offline registry and disposes only its own on failure',async()=>{
 const body=await loadBody('resources/js/Studio/render-entry.js');let opts,passed,disposed=0;
 const own={dispose(){disposed++;}};
 const run=new AsyncFunction('exportDesign','createChartResources',body);
 await run(async(_doc,options)=>{passed=options;throw new Error('export failed');},options=>{opts=options;return own;});
 await assert.rejects(globalThis.renderStudioFiles({document:{pages:[{elements:[{type:'chart'}]}]},assets:[],brandId:7,chartDescriptors:[]}),/export failed/);
 assert.equal(opts.offline,true);assert.deepEqual(opts.descriptors,[]);assert.equal(passed.chartResources,own);assert.equal(disposed,1);
});
test('server rejects non UUID export attempts even with no chart files',async()=>{
 const {output}=await fixture();
 await assert.rejects(prepare(output.replace('11111111-1111-4111-8111-111111111111','---'),[]));
});

test('server rejects a symlink at any dataset ancestor before reading bytes',async()=>{
 const {output,descriptor}=await fixture();let reads=0;
 const fileSystem={...fs,readFile:async(...args)=>{reads++;return fs.readFile(...args);},lstat:async filename=>filename===path.join(output,'datasets') ? {isSymbolicLink:()=>true,isDirectory:()=>true}:fs.lstat(filename)};
 await assert.rejects(prepare(output,[descriptor],fileSystem),/datos/);assert.equal(reads,0);
});
test('dataset aggregate budget is independent and checked before file reads',async()=>{
 const {output,descriptor}=await fixture();let reads=0;
 const descriptors=[1,2].map(id=>({...descriptor,id,name:`datasets/${id}-2-${descriptor.sha256}.json`,bytes:32000001}));
 await assert.rejects(prepare(output,descriptors,{...fs,readFile:async()=>{reads++;}}),/64 MB/);assert.equal(reads,0);
});
test('exportDesign shares one editor chart context across selected SVG pages',async()=>{
 const body=await loadBody('resources/js/Studio/exports.js');const chartResources={dispose(){throw new Error('shared context disposed');}};let renders=0,disposed=0;
 const scene=()=>({canvas:{width:800,height:500,getZoom:()=>1,getObjects:()=>[],toSVG:()=>'<svg/>'},fontFaces:()=>[],dispose:async()=>{disposed++;}});
 const run=new AsyncFunction('validateDocument','selectPages','frameTimes','renderStudioFrame','StudioDocumentError','createStudioFontRegistry',body+';return exportDesign;');
 const exportDesign=await run(x=>x,(d,ids)=>d.pages.filter(p=>ids.includes(p.id)),()=>new Map([['p',0],['q',0]]),async(...args)=>{assert.equal(args[5].chartResources,chartResources);renders++;return scene();},Error,()=>({ensure:async()=>{},dispose(){}}));
 const result=await exportDesign({width:800,height:500,pages:[{id:'p',elements:[]},{id:'q',elements:[]}]},{format:'svg',pageIds:['p','q'],chartResources});
 assert.equal(result.length,2);assert.equal(renders,2);assert.equal(disposed,2);
});

test('completed resource LRU admits reference 33 while preserving delivered snapshots',async()=>{
 const {createChartResources}=await import('../../resources/js/Studio/chart-resources.js');
 globalThis.location=new URL('https://studio.test/editor');
 const bytes=new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"}],"rows":[{"id":"r","values":["A"]}]}');
 const sha256=digest(bytes);let calls=0;
 // Supply an explicit same-origin response wrapper without Node's readonly Response.url.
 const response=()=>({status:200,url:'https://studio.test/data',headers:new Headers({'content-type':'application/json'}),body:new ReadableStream({start(c){c.enqueue(bytes);c.close();}})});
 const cache=createChartResources({brandId:1,fetchImpl:async()=>{calls++;return response();}});
 let first;
 for(let id=1;id<=33;id++){const snapshot=await cache.resolve({id,revision:1,sha256});if(id===1)first=snapshot;}
 assert.equal(first.table.rows[0].values[0],'A');assert.equal(cache.stats().resources,32);
 await cache.resolve({id:33,revision:1,sha256});assert.equal(calls,33);
 await cache.resolve({id:1,revision:1,sha256});assert.equal(calls,34);cache.dispose();
});
test('completed resource LRU releases canonical byte quota for a new pinned revision',async()=>{
 let code=await fs.readFile(new URL('../../resources/js/Studio/chart-resources.js',import.meta.url),'utf8');
 const bytes=new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"}],"rows":[{"id":"r","values":["A"]}]}');
 // Scale only the canonical accounting limits; exercise actual byte loading/hash/parser with tiny snapshots.
 code=code.replace('MAX_BYTES = 52000000, TOTAL_BYTES = 64000000',`MAX_BYTES = ${bytes.length}, TOTAL_BYTES = ${Math.floor(bytes.length*64/52)}`);
 code=code.replaceAll("'./dataset-table.js'",JSON.stringify(new URL('../../resources/js/Studio/dataset-table.js',import.meta.url).href)).replaceAll("'./chart-binding.js'",JSON.stringify(new URL('../../resources/js/Studio/chart-binding.js',import.meta.url).href)).replaceAll("'./bar-geometry.js'",JSON.stringify(new URL('../../resources/js/Studio/bar-geometry.js',import.meta.url).href));
 const {createChartResources}=await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'));
 globalThis.location=new URL('https://studio.test/editor');const sha256=digest(bytes);let calls=0;
 const cache=createChartResources({brandId:1,fetchImpl:async()=>{calls++;return {status:200,url:'https://studio.test/data',headers:new Headers({'content-type':'application/json'}),body:new ReadableStream({start(c){c.enqueue(bytes);c.close();}})};}});
 const first=await cache.resolve({id:1,revision:1,sha256});await cache.resolve({id:1,revision:2,sha256});
 assert.equal(cache.stats().bytes,bytes.length);assert.equal(cache.stats().resources,1);assert.equal(calls,2);assert.equal(first.table.rows.length,1);cache.dispose();
});
test('using a cached projection keeps its completed resource recently used',async()=>{
 const {createChartResources}=await import('../../resources/js/Studio/chart-resources.js');globalThis.location=new URL('https://studio.test/editor');
 const bytes=new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"},{"id":"val","label":"Value"}],"rows":[{"id":"r","values":["A",1]}]}');
 const sha256=digest(bytes);let calls=0;const cache=createChartResources({brandId:1,fetchImpl:async()=>{calls++;return {status:200,url:'https://studio.test/data',headers:new Headers({'content-type':'application/json'}),body:new ReadableStream({start(c){c.enqueue(bytes);c.close();}})};}});
 const binding={dataset:{id:1,revision:1,sha256},category_column:'cat',series_columns:['val']};
 const first=await cache.prepare(binding);
 for(let id=2;id<=32;id++)await cache.resolve({id,revision:1,sha256});
 assert.equal(await cache.prepare(binding),first);await cache.resolve({id:33,revision:1,sha256});
 assert.equal(await cache.prepare(binding),first);assert.equal(calls,33);cache.dispose();
});
test('descriptor identities reject trailing newline before filesystem access',async()=>{
 const {output,descriptor}=await fixture();let stats=0;
 const sha256=descriptor.sha256+'\n';const bad={...descriptor,sha256,name:`datasets/1-2-${sha256}.json`};
 await assert.rejects(prepare(output,[bad],{...fs,lstat:async(...args)=>{stats++;return fs.lstat(...args);}}));assert.equal(stats,0);
 await assert.rejects(prepare(output+'\n',[]));
});

test('exportDesign checks selected temporal pages together before producing any page',async()=>{
 const {assertChartDocumentBudget}=await import('../../resources/js/Studio/chart-scene.js');
 const body=await loadBody('resources/js/Studio/exports.js');let renders=0,fonts=0,resolves=0;
 const ref={id:1,revision:1,sha256:'1'.repeat(64)};
 const chart={type:'chart',chart:{binding:{dataset:ref,category_column:'cat',series_columns:['val']}}};
 const document={schema_version:2,width:800,height:500,timeline:{fps:30},pages:Array.from({length:5},(_,i)=>({id:`p${i}`,duration:1,elements:Array.from({length:100},(_,j)=>({...chart,id:`chart-${i}-${j}`}))}))};
 const registry={describe:async reference=>{resolves++;return Object.freeze({...reference,bytes:512,row_count:107,column_count:2});}};
 const run=new AsyncFunction('validateDocument','selectPages','frameTimes','frameElements','renderStudioFrame','StudioDocumentError','createStudioFontRegistry','assertChartDocumentBudget',body+';return exportDesign;');
 const exportDesign=await run(x=>x,(d,ids)=>d.pages.filter(p=>ids.includes(p.id)),()=>new Map(document.pages.map(p=>[p.id,0])),x=>x,async()=>{renders++;return {canvas:{getObjects:()=>[],getZoom:()=>1,toSVG:()=>'<svg/>'},fontFaces:()=>[],dispose:async()=>{}};},Error,()=>{fonts++;return {ensure:async()=>{},dispose(){}};},assertChartDocumentBudget);
 await assert.rejects(exportDesign(document,{format:'svg',pageIds:document.pages.map(p=>p.id),chartResources:registry}),/document primitive budget exceeds 200,000/);
 assert.equal(renders,0);assert.equal(fonts,0);assert.equal(resolves,1);
});
test('exportDesign invokes document preflight once and preserves resource failures before rendering',async()=>{
 const body=await loadBody('resources/js/Studio/exports.js');let calls=0,renders=0;
 const chart={type:'chart',chart:{binding:{dataset:{id:1,revision:1,sha256:'1'.repeat(64)},category_column:'cat',series_columns:['val']}}};
 const document={schema_version:2,width:800,height:500,timeline:{fps:30},pages:[{id:'p',duration:1,elements:[{type:'group',elements:[chart]}]},{id:'q',duration:1,elements:[chart]}]};
 const registry={};const controller=new AbortController();const sentinel=new Error('snapshot unavailable');let fail=false;
 const run=new AsyncFunction('validateDocument','selectPages','frameTimes','frameElements','renderStudioFrame','StudioDocumentError','createStudioFontRegistry','assertChartDocumentBudget',body+';return exportDesign;');
 const exportDesign=await run(x=>x,d=>d.pages,()=>new Map([['p',0],['q',0]]),x=>x,async()=>{renders++;return {canvas:{getObjects:()=>[],getZoom:()=>1,toSVG:()=>'<svg/>'},fontFaces:()=>[],dispose:async()=>{}};},Error,()=>({ensure:async()=>{},dispose(){}}),async(d,r,options)=>{calls++;assert.deepEqual(d.pages,document.pages);assert.equal(r,registry);assert.equal(options.signal,controller.signal);if(fail)throw sentinel;});
 const files=await exportDesign(document,{format:'svg',pageIds:['p','q'],chartResources:registry,signal:controller.signal});
 assert.equal(files.length,2);assert.equal(calls,1);assert.equal(renders,2);
 fail=true;calls=0;renders=0;
 await assert.rejects(exportDesign(document,{format:'svg',pageIds:['p','q'],chartResources:registry,signal:controller.signal}),error=>error===sentinel);
 assert.equal(calls,1);assert.equal(renders,0);
});

test('selected page budget never resolves an unselected offline chart reference',async()=>{
 const {assertChartDocumentBudget}=await import('../../resources/js/Studio/chart-scene.js');
 const {createChartResources}=await import('../../resources/js/Studio/chart-resources.js');globalThis.location=new URL('https://studio.test/editor');
 const bytes=new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"},{"id":"val","label":"Value"}],"rows":[{"id":"r","values":["A",1]}]}');
 const sha256=digest(bytes);let fetches=0,renders=0;
 const registry=createChartResources({brandId:1,offline:true,descriptors:[{id:1,revision:1,sha256,bytes:bytes.length,url:'https://studio.test/selected'}],fetchImpl:async()=>{fetches++;return {status:200,url:'https://studio.test/selected',headers:new Headers({'content-type':'application/json'}),body:new ReadableStream({start(c){c.enqueue(bytes);c.close();}})};}});
 const chart=id=>({type:'chart',chart:{binding:{dataset:{id,revision:1,sha256},category_column:'cat',series_columns:['val']}}});
 const document={schema_version:2,width:800,height:500,timeline:{fps:30},pages:[{id:'p',duration:1,elements:[chart(1)]},{id:'hidden',duration:1,elements:[chart(2)]}]};
 const body=await loadBody('resources/js/Studio/exports.js');
 const run=new AsyncFunction('validateDocument','selectPages','frameTimes','frameElements','renderStudioFrame','StudioDocumentError','createStudioFontRegistry','assertChartDocumentBudget',body+';return exportDesign;');
 const exportDesign=await run(x=>x,(d,ids)=>d.pages.filter(p=>ids.includes(p.id)),()=>new Map([['p',0]]),x=>x,async(d)=>{assert.equal(d,document);renders++;return {canvas:{getObjects:()=>[],getZoom:()=>1,toSVG:()=>'<svg/>'},fontFaces:()=>[],dispose:async()=>{}};},Error,()=>({ensure:async()=>{},dispose(){}}),assertChartDocumentBudget);
 try {const files=await exportDesign(document,{format:'svg',pageIds:['p'],chartResources:registry});assert.equal(files.length,1);assert.equal(fetches,1);assert.equal(renders,1);}finally{registry.dispose();}
});
test('real frame and page render functions keep unselected chart refs offline in schemas one and two',async()=>{
 const {assertChartDocumentBudget}=await import('../../resources/js/Studio/chart-scene.js');
 const {createChartResources}=await import('../../resources/js/Studio/chart-resources.js');
 const {frameTimes,frameElements}=await import('../../resources/js/Studio/frames.js');
 const {compileTimeline}=await import('../../resources/js/Studio/timeline.js');
 globalThis.location=new URL('https://studio.test/editor');
 const bytes=new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"},{"id":"val","label":"Value"}],"rows":[{"id":"r","values":["A",1]}]}');const sha256=digest(bytes);let fetches=0;
 const registry=createChartResources({brandId:1,offline:true,descriptors:[{id:1,revision:1,sha256,bytes:bytes.length,url:'https://studio.test/selected'}],fetchImpl:async()=>{fetches++;return {status:200,url:'https://studio.test/selected',headers:new Headers({'content-type':'application/json'}),body:new ReadableStream({start(c){c.enqueue(bytes);c.close();}})};}});
 const canvasSource=await fs.readFile(new URL('../../resources/js/Studio/canvas.js',import.meta.url),'utf8');
 const pageBody=canvasSource.slice(canvasSource.indexOf('export async function renderStudioPage('),canvasSource.indexOf('export async function createStudioCanvas(')).replace('export async function','async function');
 class CanvasMock {constructor(_element,options){Object.assign(this,options);this.objects=[];}setViewportTransform(){}add(value){this.objects.push(value);}renderAll(){}getObjects(){return this.objects;}getZoom(){return 1;}toSVG(){return '<svg/>';}async dispose(){}}
 const fontRegistry=()=>({ensure:async()=>{},svgFaces:()=>[],dispose(){}});
 const pageRun=new AsyncFunction('validateDocument','findPage','assertChartDocumentBudget','StudioDocumentError','createStudioFontRegistry','waitForStudioFonts','StaticCanvas','toFabricFill','elementToFabric','cleanVideos',pageBody+';return renderStudioPage;');
 const renderStudioPage=await pageRun(x=>x,(d,id)=>d.pages.find(p=>p.id===id),assertChartDocumentBudget,Error,fontRegistry,async()=>{},CanvasMock,x=>x,async(_layer,options)=>{assert.equal(options.chartResources,registry);return {};},()=>{});
 const frameBody=await loadBody('resources/js/Studio/frame-renderer.js');
 const frameRun=new AsyncFunction('renderStudioPage','frameElements','compileTimeline','attachFrameCaptions',frameBody+';return renderStudioFrame;');
 const renderStudioFrame=await frameRun(renderStudioPage,frameElements,compileTimeline,async scene=>scene);
 const exportBody=await loadBody('resources/js/Studio/exports.js');
 const exportRun=new AsyncFunction('validateDocument','selectPages','frameTimes','frameElements','renderStudioFrame','StudioDocumentError','createStudioFontRegistry','assertChartDocumentBudget','Group','FabricImage',exportBody+';return exportDesign;');
 const exportDesign=await exportRun(x=>x,(d,ids)=>d.pages.filter(p=>ids.includes(p.id)),frameTimes,frameElements,renderStudioFrame,Error,fontRegistry,assertChartDocumentBudget,class {},class {});
 const chart=id=>({type:'chart',chart:{binding:{dataset:{id,revision:1,sha256},category_column:'cat',series_columns:['val']}}});
 const oldDocument=globalThis.document;globalThis.document={createElement:()=>({})};
 try {
  for(const schema_version of [1,2]) {
   const document={schema_version,width:800,height:500,timeline:{fps:30,audio_tracks:[]},pages:[{id:'p',duration:1,background:'#fff',elements:[chart(1)]},{id:'hidden',duration:1,background:'#fff',elements:[chart(2)]}]};
   const files=await exportDesign(document,{format:'svg',pageIds:['p'],chartResources:registry});assert.equal(files.length,1);
  }
  assert.equal(fetches,1);assert.equal(registry.stats().disposed,false);
 }finally{registry.dispose();if(oldDocument===undefined)delete globalThis.document;else globalThis.document=oldDocument;}
});
