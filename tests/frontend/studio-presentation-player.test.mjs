import test from 'node:test';
import assert from 'node:assert/strict';
import { compileTimeline, sampleTimeline } from '../../resources/js/Studio/timeline.js';
import { validateDocument } from '../../resources/js/Studio/document.js';
const url = new URL('../../resources/js/Studio/presentation-player.js', import.meta.url);
const subject = await import(url).catch(e => { if(e.code === 'ERR_MODULE_NOT_FOUND' && e.url === url.href) return {}; throw e; });
const doc = () => ({ schema_version:2, width:320, height:180, timeline:{fps:30,audio_tracks:[]}, pages:[
 {id:'a',name:'First',duration:1.01,background:'#ff0000',elements:[],transition:{type:'fade',duration:0.2}},
 {id:'b',name:'Second',duration:1.01,background:'#0000ff',elements:[]}
]});
function fixture(options={}) {
 assert.equal(typeof subject.createPresentationPlayer,'function','presentation player is absent');
 let now=0, scheduled=null; const seeks=[],published=[]; let pauses=0,disposals=0;
 const renderer={seek:async(time,options)=>{seeks.push({time,...options}); return {bufferingMs:0};},pause(){pauses++;},async dispose(){disposals++;}};
 const player=subject.createPresentationPlayer({document:doc(),output:{},createRenderer:async()=>renderer,
  now:()=>now,requestFrame:callback=>{scheduled=callback;return 1;},cancelFrame:()=>{scheduled=null;},
  publish:(_canvas,state)=>published.push(state),...options});
 return {player,seeks,published,renderer,setNow:v=>{now=v;},tick:async v=>{now=v;const cb=scheduled;scheduled=null;await cb?.();},get scheduled(){return scheduled;},get pauses(){return pauses;},get disposals(){return disposals;}};
}
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
test('manual navigation seeks incoming transition end and preserves quantized plan',async()=>{
 const f=fixture();await f.player.ready();await f.player.next();const plan=compileTimeline(doc());
 assert.equal(f.seeks.at(-1).time,plan.pages[0].end);
 assert.deepEqual(sampleTimeline(plan,f.seeks.at(-1).time).pages.map(p=>p.id),['b']);
 assert.equal(f.player.snapshot().pageId,'b');await f.player.dispose();
});
test('autoplay uses overlapping media timeline and dominant visible page',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();await f.tick(850);
 assert.equal(f.player.snapshot().pageId,'a');await f.tick(950);assert.equal(f.player.snapshot().pageId,'b');
 assert.equal(f.player.snapshot().mediaTime,.95);await f.player.dispose();
});
test('terminal holds last visible frame, stops media and restarts at first',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();await f.tick(3000);
 const plan=compileTimeline(doc());assert.equal(f.player.snapshot().ended,true);assert.equal(f.player.snapshot().playing,false);
 assert.equal(f.seeks.at(-1).time,(plan.frameCount-1)/plan.fps);assert.equal(f.seeks.at(-1).play,false);
 assert.equal(sampleTimeline(plan,f.seeks.at(-1).time).pages.at(-1).id,'b');
 await f.player.play();assert.equal(f.player.snapshot().mediaTime,0);await f.player.dispose();
});
test('manual next after last keeps a visible terminal frame',async()=>{
 const f=fixture();await f.player.ready();await f.player.last();await f.player.next();
 const plan=compileTimeline(doc());assert.equal(f.player.snapshot().ended,true);
 assert.equal(f.seeks.at(-1).time,(plan.frameCount-1)/plan.fps);await f.player.dispose();
});
test('buffering is removed from monotonic clock and renders never overlap',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();
 f.renderer.seek=async time=>{f.seeks.push({time});f.setNow(600);return {bufferingMs:500};};
 await f.tick(100);f.renderer.seek=async time=>{f.seeks.push({time});return {bufferingMs:0};};
 await f.tick(700);assert.ok(Math.abs(f.player.snapshot().mediaTime-.2)<1e-8);await f.player.dispose();
});
test('loop wraps the compiled duration rather than summed page durations',async()=>{
 const f=fixture();await f.player.ready();await f.player.setLoop(true);await f.player.play();
 await f.tick(2000);assert.ok(Math.abs(f.player.snapshot().mediaTime-(2-compileTimeline(doc()).duration))<1e-8);
 assert.equal(f.player.snapshot().ended,false);await f.player.dispose();
});
test('pause invalidates a pending seek and cannot republish or reschedule',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();const wait=deferred();
 f.renderer.seek=()=>wait.promise;const pending=f.tick(100);const count=f.published.length;
 f.player.pause();wait.resolve({bufferingMs:0});await pending;
 assert.equal(f.published.length,count);assert.equal(f.scheduled,null);assert.equal(f.player.snapshot().playing,false);assert.ok(f.pauses>0);await f.player.dispose();
});
test('newer navigation cancels stale render and serializes destination seek',async()=>{
 const f=fixture();await f.player.ready();const wait=deferred();let active=0,max=0;
 f.renderer.seek=async()=>{active++;max=Math.max(max,active);await wait.promise;active--;return {};};
 const old=f.player.next();await Promise.resolve();await Promise.resolve();const latest=f.player.first();wait.resolve({});await Promise.all([old,latest]);
 assert.equal(max,1);assert.equal(f.player.snapshot().pageId,'a');assert.equal(f.published.at(-1).pageId,'a');await f.player.dispose();
});
test('late renderer creation is paused and disposed after close, idempotently',async()=>{
 const wait=deferred();const f=fixture({createRenderer:()=>wait.promise});const ready=f.player.ready();await Promise.resolve();await Promise.resolve();
 const closed=f.player.dispose();wait.resolve(f.renderer);await Promise.all([ready,closed]);await f.player.dispose();
 assert.equal(f.disposals,1);assert.ok(f.pauses>0);assert.equal(f.published.length,0);
});
test('renderer errors stop playback without unhandled rejection or RAF retry',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();f.renderer.seek=async()=>{throw Error('decoder failed');};await f.tick(100);
 assert.equal(f.player.snapshot().error,'decoder failed');assert.equal(f.player.snapshot().playing,false);assert.equal(f.scheduled,null);await f.player.dispose();
});
test('blackout publishes black state while retaining timeline and toggles back',async()=>{
 const f=fixture();await f.player.ready();await f.player.setBlackout(true);assert.equal(f.published.at(-1).blackout,true);
 await f.player.play();await f.tick(250);assert.equal(f.player.snapshot().mediaTime,.25);
 await f.player.setBlackout(false);assert.equal(f.published.at(-1).blackout,false);await f.player.dispose();
});
test('v1 is upgraded only in captured session copy',async()=>{
 const input=doc();input.schema_version=1;delete input.timeline;delete input.pages[0].transition;
 let captured;const f=fixture({document:input,createRenderer:async(_output,options)=>{captured=options.document;return {seek:async()=>({}),pause(){},async dispose(){}};}});
 await f.player.ready();assert.equal(captured.schema_version,2);assert.equal(captured.timeline.fps,30);assert.equal(input.schema_version,1);assert.equal(input.timeline,undefined);
 input.pages[0].name='mutated';assert.equal(f.player.snapshot().pageName,'First');await f.player.dispose();
});
test('renderer receives exact v2 transitions, timing, captions and brand resources; notes are captured text',async()=>{
 const input=doc();input.pages[0].notes='🎵\n<b>plain</b>  ';input.pages[1].notes='second';
 input.timeline.audio_tracks=[{id:'music',asset_id:7,start:0,duration:1}];
 input.timeline.captions={style:{fontSize:20,position:'bottom'},cues:[{id:'cue',start:.1,end:.5,text:'Caption Ω'}]};
 const assets=[{id:7}],fonts=[{id:8}],chartResources={brand:9};let captured;
 const f=fixture({document:input,assets,fonts,chartResources,createRenderer:async(_canvas,options)=>{captured=options;return {seek:async()=>({}),pause(){},async dispose(){}};}});
 await f.player.ready();assert.deepEqual(captured.document,validateDocument(input));
 assert.equal(captured.assets,assets);assert.equal(captured.fonts,fonts);assert.equal(captured.chartResources,chartResources);
 input.pages[0].notes='changed after opening';assert.equal(f.player.snapshot().notes,'🎵\n<b>plain</b>  ');
 await f.player.next();assert.equal(f.player.snapshot().notes,'second');await f.player.dispose();
});
test('closing an active seek prevents publication and drains disposal exactly once',async()=>{
 const f=fixture();await f.player.ready();await f.player.play();const wait=deferred();
 f.renderer.seek=()=>wait.promise;const pending=f.tick(100);await Promise.resolve();await Promise.resolve();
 const count=f.published.length,closed=f.player.dispose();wait.resolve({bufferingMs:0});await Promise.all([closed,pending]);await f.player.dispose();
 assert.equal(f.published.length,count);assert.equal(f.disposals,1);assert.equal(f.scheduled,null);assert.equal(f.player.snapshot().disposed,true);
});
test('keyboard navigation ignores typing and modifiers',()=>{
 assert.equal(typeof subject.presentationKey,'function','presentation key mapping is absent');
 assert.equal(subject.presentationKey({key:'ArrowRight',target:{tagName:'INPUT'}}),null);
 assert.equal(subject.presentationKey({key:'z',ctrlKey:true,target:{}}),null);
 assert.equal(subject.presentationKey({key:'PageDown',target:{}}),'next');
 assert.equal(subject.presentationKey({key:' ',target:{}}),'togglePlay');
 assert.equal(subject.presentationKey({key:'B',target:{}}),'toggleBlackout');
});
test('next-slide thumbnail uses bounded offline scale while preserving small scenes',()=>{
 assert.equal(typeof subject.presentationPreviewScale,'function','next-slide preview scale is absent');
 assert.equal(subject.presentationPreviewScale(320,180),1);
 assert.equal(subject.presentationPreviewScale(1920,1080),1/3);
 assert.equal(subject.presentationPreviewScale(8000,8000),.1);
});

