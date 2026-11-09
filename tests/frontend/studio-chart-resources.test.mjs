import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { createChartResources } from '../../resources/js/Studio/chart-resources.js';
import { createPreparedBarChartGeometry } from '../../resources/js/Studio/bar-geometry.js';
Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
globalThis.location = new URL('https://studio.test/editor');
const bytes = new TextEncoder().encode('{"columns":[{"id":"cat","label":"Category"},{"id":"s","label":"Series"}],"rows":[{"id":"r","values":["A",1.2500]}]}');
const hash = async data => Buffer.from(await webcrypto.subtle.digest('SHA-256', data)).toString('hex');
const sha = await hash(bytes);
const ref = (id = 1) => ({ id, revision: 2, sha256: sha });
const binding = () => ({ dataset: ref(), category_column: 'cat', series_columns: ['s'] });
const response = (data = bytes, options = {}) => ({ status: 200, url: 'https://studio.test/dataset', headers: new Headers({ 'content-type': 'application/json; charset=UTF-8', ...options.headers }), body: new ReadableStream({ start(c) { c.enqueue(data); c.close(); } }), ...options });
const defer = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return { promise, resolve, reject }; };

test('explicit offline empty descriptors reject before fetch', async () => {
    let calls = 0;
    const registry = createChartResources({ brandId: 7, offline: true, descriptors: [], fetchImpl: async () => { calls++; return response(); } });
    await assert.rejects(registry.resolve(ref()), /offline descriptors/);
    assert.equal(calls, 0);
    registry.dispose();
});

test('pinned lossless snapshots deduplicate privately by brand', async () => {
    const calls = []; const fetchImpl = async (...args) => { calls.push(args); return response(); };
    const a = createChartResources({ brandId: 7, fetchImpl }); const b = createChartResources({ brandId: 8, fetchImpl });
    const [x,y,z] = await Promise.all([a.resolve(ref()),a.resolve(ref()),b.resolve(ref())]);
    assert.equal(x,y); assert.notEqual(x,z); assert.equal(x.table.rows[0].values[1].raw,'1.2500');
    assert.ok(Object.isFrozen(x.table.rows)); assert.equal(calls.length,2);
    assert.equal(calls[0][0],'/brands/7/studio/datasets/1/export?format=json&revision=2');
    assert.equal(calls[0][1].redirect,'error'); assert.equal(calls[0][1].credentials,'same-origin');
    assert.equal(a.stats().resources,1); a.dispose(); b.dispose();
});
test('references and binding identifiers are closed and complete', async () => {
    const r = createChartResources({ brandId: 7, fetchImpl: async () => response() });
    for (const value of [{ ...ref(), revision:0 },{ ...ref(),sha256:sha+'\n' },{ ...ref(),url:'/evil' },{ id:1,revision:2 }]) await assert.rejects(r.resolve(value));
    await assert.rejects(r.prepare({ ...binding(),category_column:'cat\n' }));
    await assert.rejects(r.prepare({ ...binding(),series_columns:['s\n'] }));
    assert.equal(r.stats().resources,0); r.dispose();
});
test('verifies HTTP MIME origin SHA UTF8 and body sizes before trusting table', async () => {
    for (const options of [{status:201},{headers:new Headers({'content-type':'text/plain'})},{url:'https://evil.test/x'},{url:'file:///tmp/x'}, {headers:new Headers({'content-type':'application/json','content-length':'52000001'})}]) {
        const r = createChartResources({brandId:7,fetchImpl:async()=>response(bytes,options)}); await assert.rejects(r.resolve(ref())); assert.equal(r.stats().resources,0); r.dispose();
    }
    for (const data of [new Uint8Array(),new Uint8Array([0xff]),new TextEncoder().encode('{}')]) {
        const r=createChartResources({brandId:7,fetchImpl:async()=>response(data)}); await assert.rejects(r.resolve({...ref(),sha256: data.length===1 ? await hash(data) : sha})); r.dispose();
    }
});
test('offline descriptors are closed scoped exact and never fall through', async () => {
    let calls=0; const descriptor={...ref(),bytes:bytes.length,url:'https://studio.test/private/a'};
    const r=createChartResources({brandId:7,descriptors:[descriptor],fetchImpl:async url=>{calls++;assert.equal(url,descriptor.url);return response();}});
    await r.resolve(ref()); await assert.rejects(r.resolve(ref(2))); assert.equal(calls,1); r.dispose();
    for(const descriptors of [[descriptor,descriptor],[{...descriptor,url:'https://evil.test/x'}],[{...descriptor,url:'file:///tmp/a'}],[{...descriptor,bytes:0}],[{...descriptor,extra:1}],new Array(1)]) assert.throws(()=>createChartResources({brandId:7,descriptors}));
    const wrong=createChartResources({brandId:7,descriptors:[{...descriptor,bytes:bytes.length+1}],fetchImpl:async()=>response()});await assert.rejects(wrong.resolve(ref()));wrong.dispose();
});
test('resource and projection promises deduplicate while styles need no fetch', async () => {
    let calls=0; const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response();}});
    const [a,b]=await Promise.all([r.prepare(binding()),r.prepare(binding())]);assert.equal(a,b);
    assert.equal(createPreparedBarChartGeometry(a,{palette:['#123']}).bars[0].raw,'1.2500');
    createPreparedBarChartGeometry(a,{palette:['#fff']});assert.equal(calls,1);assert.equal(r.stats().projections,1);
    const other=await r.prepare({...binding(),precision:'approximate'});assert.notEqual(a,other);r.dispose();
});
test('consumer abort is isolated preabort starts nothing and listeners are removed', async () => {
    const gate=defer();let calls=0;const r=createChartResources({brandId:7,fetchImpl:()=>{calls++;return gate.promise;}});
    const pre=new AbortController();pre.abort();await assert.rejects(r.resolve(ref(),{signal:pre.signal}),{name:'AbortError'});assert.equal(calls,0);
    const c=new AbortController();let add=0,remove=0;const signal={get aborted(){return c.signal.aborted;},addEventListener(...a){add++;c.signal.addEventListener(...a);},removeEventListener(...a){remove++;c.signal.removeEventListener(...a);}};
    const first=r.resolve(ref(),{signal});const second=r.resolve(ref());c.abort();await assert.rejects(first,{name:'AbortError'});gate.resolve(response());await second;assert.equal(calls,1);assert.equal(add,remove);r.dispose();
});
test('failures evict own entries retries are manual and disposal invalidates late promotion', async () => {
    let calls=0;const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;if(calls===1)throw new Error('offline');return response();}});
    await assert.rejects(r.resolve(ref()));assert.equal(calls,1);await r.resolve(ref());assert.equal(calls,2);r.dispose();
    const gate=defer();let ownSignal;const late=createChartResources({brandId:7,fetchImpl:(url,options)=>{ownSignal=options.signal;return gate.promise;}});
    const pending=late.prepare(binding());await Promise.resolve();late.dispose();assert.ok(ownSignal.aborted);gate.resolve(response());await assert.rejects(pending);await assert.rejects(late.resolve(ref()));assert.equal(late.stats().resources,0);assert.equal(late.stats().bytes,0);
});
test('reference and aggregate budgets reserve concurrent offline work before fetching', async () => {
    let calls=0;const gates=[];const descriptors=Array.from({length:33},(_,i)=>({...ref(i+1),bytes:1,url:`https://studio.test/${i}`}));
    const r=createChartResources({brandId:7,descriptors,fetchImpl:()=>{calls++;const g=defer();gates.push(g);return g.promise;}});
    const pending=Array.from({length:32},(_,i)=>r.resolve(ref(i+1)));await assert.rejects(r.resolve(ref(33)));assert.equal(calls,32);r.dispose();for(const g of gates)g.resolve(response());await Promise.allSettled(pending);
    let n=0;const large=createChartResources({brandId:7,descriptors:[{...ref(1),bytes:32000001,url:'https://studio.test/1'},{...ref(2),bytes:32000000,url:'https://studio.test/2'}],fetchImpl:()=>{n++;return new Promise(()=>{});}});
    const p=large.resolve(ref());await assert.rejects(large.resolve(ref(2)));assert.equal(n,1);assert.equal(large.stats().bytes,32000001);large.dispose();await assert.rejects(p);assert.equal(large.stats().bytes,0);
});
test('stream cap rejects before allocating a declared oversized chunk and cancels reader', async()=>{
    let cancelled=0;const r=createChartResources({brandId:7,fetchImpl:async()=>response(bytes,{body:{getReader:()=>({read:async()=>({done:false,value:{byteLength:52000001}}),cancel:async()=>{cancelled++;},releaseLock(){}})}})});
    await assert.rejects(r.resolve(ref()));assert.equal(cancelled,1);assert.equal(r.stats().bytes,0);r.dispose();
});
test('timeout is bounded at 30000ms and aborts its private request', async()=>{
    const originalSet=globalThis.setTimeout,originalClear=globalThis.clearTimeout;
    let timeout,delay,requestSignal,cleared=0;
    globalThis.setTimeout=(fn,ms)=>{timeout=fn;delay=ms;return 42;};globalThis.clearTimeout=id=>{assert.equal(id,42);cleared++;};
    try {
        const r=createChartResources({brandId:7,fetchImpl:(_url,options)=>{requestSignal=options.signal;return new Promise(()=>{});}});
        const p=r.resolve(ref());await Promise.resolve();assert.equal(delay,30000);timeout();await assert.rejects(p,{name:'AbortError'});assert.ok(requestSignal.aborted);assert.equal(cleared,1);assert.equal(r.stats().resources,0);r.dispose();
    } finally {globalThis.setTimeout=originalSet;globalThis.clearTimeout=originalClear;}
});
test('online concurrent declared sizes reserve aggregate budget before stream materialization', async()=>{
    const gates=[defer(),defer()];let next=0;let reads=0;
    const r=createChartResources({brandId:7,fetchImpl:async()=>{
        const gate=gates[next++];return response(bytes,{headers:new Headers({'content-type':'application/json','content-length':'32000001'}),body:{getReader:()=>({read:()=>{reads++;return gate.promise;},cancel:async()=>{},releaseLock(){}})}});
    }});
    const first=r.resolve(ref());const second=r.resolve(ref(2));await assert.rejects(second,/budget/);assert.equal(reads,1);assert.equal(r.stats().bytes,32000001);r.dispose();await assert.rejects(first,{name:'AbortError'});
});
test('brand descriptors and references reject getters sparse hidden and trailing SHA without executing code',async()=>{
    for(const brandId of [0,-1,1.5,'7',Number.MAX_SAFE_INTEGER+1])assert.throws(()=>createChartResources({brandId}));
    let calls=0;const getter={};Object.defineProperty(getter,'id',{enumerable:true,get(){calls++;return 1;}});getter.revision=2;getter.sha256=sha;
    const r=createChartResources({brandId:7,fetchImpl:async()=>response()});await assert.rejects(r.resolve(getter));assert.equal(calls,0);
    const d={...ref(),bytes:bytes.length,url:'https://studio.test/private'};const accessor=[d];Object.defineProperty(accessor,0,{enumerable:true,get(){calls++;return d;}});
    assert.throws(()=>createChartResources({brandId:7,descriptors:accessor}));assert.equal(calls,0);
    for(const url of ['data:application/json,{}','https://user:pass@studio.test/file','https://studio.test/file#fragment'])assert.throws(()=>createChartResources({brandId:7,descriptors:[{...d,url}]}));r.dispose();
});
test('response URL must identify an actual same-origin HTTP response',async()=>{
    for(const url of ['', '/relative']){
        const r=createChartResources({brandId:7,fetchImpl:async()=>response(bytes,{url})});await assert.rejects(r.resolve(ref()));r.dispose();
    }
});
test('more than 500 valid selections use completed LRU without rejecting or invalidating handles',async()=>{
    const text=JSON.stringify({columns:[{id:'cat',label:'Category'},...Array.from({length:9},(_,i)=>({id:`s${i}`,label:`Series ${i}`}))],rows:[{id:'r',values:['A',...Array(9).fill(1)]}]});
    const data=new TextEncoder().encode(text);const dataset={...ref(),sha256:await hash(data)};let calls=0;
    const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response(data);}});
    const select=mask=>({dataset,category_column:'cat',series_columns:Array.from({length:9},(_,i)=>`s${i}`).filter((_,i)=>mask&(1<<i))});
    let first;
    for(let mask=1;mask<=501;mask++){const handle=await r.prepare(select(mask));if(mask===1)first=handle;}
    assert.ok(r.stats().projections<=500);assert.equal(calls,1);assert.equal(createPreparedBarChartGeometry(first).bars[0].raw,'1');
    await r.prepare(select(1));assert.equal(calls,1);assert.ok(r.stats().projections<=500);r.dispose();
});

test('D1 acquired invalid HTTP responses abort requests and cancel unlocked bodies before eviction', async () => {
    const originalSet=globalThis.setTimeout,originalClear=globalThis.clearTimeout;const deadlines=new Set();let next=0;
    globalThis.setTimeout=(callback,delay)=>{assert.equal(delay,30000);const id=++next;deadlines.add(id);return id;};globalThis.clearTimeout=id=>deadlines.delete(id);
    try {
        for (const invalid of [{status:503},{url:'https://evil.test/private'},{headers:new Headers({'content-type':'text/plain'})},{headers:new Headers({'content-type':'application/json','content-length':'52000001'})}]) {
            let cancelled=0,reads=0,signal;
            const body=new ReadableStream({cancel(){cancelled++;}});const originalGet=body.getReader.bind(body);body.getReader=(...args)=>{reads++;return originalGet(...args);};
            const r=createChartResources({brandId:7,fetchImpl:async(_url,options)=>{signal=options.signal;return response(bytes,{body,...invalid});}});
            await assert.rejects(r.resolve(ref()));
            assert.ok(signal.aborted,'rejected acquired response must terminate actual request signal');
            assert.equal(cancelled,1,'unlocked response stream must receive cancel');assert.equal(reads,0);assert.equal(body.locked,false);
            assert.equal(deadlines.size,0);assert.equal(r.stats().resources,0);assert.equal(r.stats().bytes,0);r.dispose();
        }
    } finally {globalThis.setTimeout=originalSet;globalThis.clearTimeout=originalClear;}
});
test('D1 aggregate budget rejection terminates every rejected acquired stream while preserving pending owner',async()=>{
    const signals=[],streams=[];let cancelled=0,readers=0;
    const r=createChartResources({brandId:7,fetchImpl:async(_url,options)=>{
        signals.push(options.signal);const body=new ReadableStream({cancel(){cancelled++;}});streams.push(body);
        const originalGet=body.getReader.bind(body);body.getReader=(...args)=>{readers++;return originalGet(...args);};
        return response(bytes,{body,headers:new Headers({'content-type':'application/json','content-length':'33000000'})});
    }});
    const pending=r.resolve(ref());pending.catch(()=>{});
    try {
    for(let id=2;id<=11;id++){
        await assert.rejects(r.resolve(ref(id)),/budget/);assert.ok(signals[id-1].aborted);assert.equal(streams[id-1].locked,false);assert.equal(cancelled,id-1);
    }
    assert.equal(readers,1);assert.equal(signals[0].aborted,false);assert.equal(r.stats().resources,1);assert.equal(r.stats().bytes,33000000);
    r.dispose();await assert.rejects(pending,{name:'AbortError'});assert.ok(signals.every(signal=>signal.aborted));assert.equal(cancelled,11);assert.equal(r.stats().bytes,0);
    } finally {r.dispose();await pending.catch(()=>{});}
});
test('D1 failures after reader acquisition also abort request without affecting successful cache reuse',async()=>{
    for(const data of [new TextEncoder().encode('{}'),new Uint8Array([255]),bytes]){
        let signal;const dataset={...ref(),sha256:data===bytes?'0'.repeat(64):await hash(data)};
        const r=createChartResources({brandId:7,fetchImpl:async(_url,options)=>{signal=options.signal;return response(data);}});
        await assert.rejects(r.resolve(dataset));assert.ok(signal.aborted);assert.equal(r.stats().resources,0);assert.equal(r.stats().bytes,0);r.dispose();
    }
    let signal,calls=0;const r=createChartResources({brandId:7,fetchImpl:async(_url,options)=>{calls++;signal=options.signal;return response();}});
    const snapshot=await r.resolve(ref());assert.equal(signal.aborted,false);assert.equal(await r.resolve(ref()),snapshot);assert.equal(calls,1);r.dispose();
});
test('D1 cleanup does not await an indefinitely pending stream cancellation',async()=>{
    let signal,cancelled=0;const body=new ReadableStream({cancel(){cancelled++;return new Promise(()=>{});}});
    const r=createChartResources({brandId:7,fetchImpl:async(_url,options)=>{signal=options.signal;return response(bytes,{status:503,body});}});
    await assert.rejects(r.resolve(ref()),/HTTP 200/);assert.equal(cancelled,1);assert.ok(signal.aborted);assert.equal(r.stats().resources,0);r.dispose();
});

test('palette preparation survives raw LRU without refetching or reparsing',async()=>{
 let calls=0;const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response();}});
 const first=await r.prepare(binding());
 for(let id=2;id<=33;id++)await r.resolve(ref(id));
 assert.equal(calls,33);assert.equal(await r.prepare(binding()),first);assert.equal(calls,33);
 assert.equal(createPreparedBarChartGeometry(first,{palette:['#fff']}).bars[0].raw,'1.2500');r.dispose();
});
test('describe returns verified original UTF8 bytes and frozen light metadata without changing snapshots',async()=>{
 const text='{"columns":[{"id":"cat","label":"Caf\u00e9"},{"id":"s","label":"Series"}],"rows":[{"id":"r","values":["\\u2028\\u2029",1.2500]}]}';
 const original=new TextEncoder().encode(text);
 const reference={id:1,revision:2,sha256:await hash(original)};let calls=0;
 const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response(original);}});
 assert.equal(typeof r.describe,'function','describe must exist');
 const [info,again,snapshot]=await Promise.all([r.describe(reference),r.describe(reference),r.resolve(reference)]);
 assert.equal(calls,1);assert.equal(info,again);assert.deepEqual(info,{...reference,bytes:original.length,row_count:1,column_count:2});
 assert.ok(original.length>text.length,'UTF8 multibyte is measured as bytes');
 assert.notEqual(info.bytes,new TextEncoder().encode(JSON.stringify(JSON.parse(text))).length,'JSON reserialization changes escaped separators and numeric lexemes');
 assert.ok(Object.isFrozen(info));assert.equal(Object.getPrototypeOf(info),Object.prototype);assert.equal(info.table,undefined);
 assert.deepEqual(Object.keys(snapshot).sort(),['id','revision','sha256','table']);
 await assert.rejects(r.describe({...reference,sha256:'f'.repeat(64)}),/SHA-256/);r.dispose();
});
test('describe uses verified lightweight metadata after raw and general metadata LRU eviction',async()=>{
 let calls=0;const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response();}});
 assert.equal(typeof r.describe,'function','describe must exist');
 const first=await r.prepare(binding());const info=await r.describe(ref());
 for(let id=2;id<=503;id++)await r.describe(ref(id));
 const before=calls;assert.equal(await r.describe(ref()),info);assert.equal(await r.prepare(binding()),first);assert.equal(calls,before);
 assert.equal(r.stats().resources,32);assert.ok(r.stats().metadata<=500);r.dispose();
});
test('describe abort is consumer-local and dispose prevents late metadata publication',async()=>{
 const gate=defer();let calls=0;const r=createChartResources({brandId:7,fetchImpl:()=>{calls++;return gate.promise;}});
 assert.equal(typeof r.describe,'function','describe must exist');
 const pre=new AbortController();pre.abort();await assert.rejects(r.describe(ref(),{signal:pre.signal}),{name:'AbortError'});assert.equal(calls,0);
 const abort=new AbortController();const a=r.describe(ref(),{signal:abort.signal}),b=r.describe(ref());abort.abort();await assert.rejects(a,{name:'AbortError'});
 gate.resolve(response());assert.equal((await b).bytes,bytes.length);assert.equal(calls,1);r.dispose();await assert.rejects(r.describe(ref()),{name:'AbortError'});
 const late=defer();const x=createChartResources({brandId:7,fetchImpl:()=>late.promise});const pending=x.describe(ref());await Promise.resolve();x.dispose();late.resolve(response());await assert.rejects(pending,{name:'AbortError'});
});
test('describe never caches rejected body metadata and retries remain explicit',async()=>{
 let calls=0;const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return calls===1?response(bytes,{headers:new Headers({'content-type':'application/json','content-length':String(bytes.length+1)})}):response();}});
 await assert.rejects(r.describe(ref()),/size mismatch/);assert.equal(r.stats().metadata,0);assert.equal(r.stats().resources,0);
 assert.equal((await r.describe(ref())).bytes,bytes.length);assert.equal(calls,2);
 const cached=r.describe(ref());r.dispose();await assert.rejects(cached,{name:'AbortError'});assert.equal(r.stats().metadata,0);
});
test('prepared selection LRU remains bounded at 500 independently of raw and metadata caches',async()=>{
 let calls=0;const r=createChartResources({brandId:7,fetchImpl:async()=>{calls++;return response();}});
 const first=await r.prepare(binding());
 for(let id=2;id<=501;id++)await r.prepare({...binding(),dataset:ref(id)});
 assert.equal(r.stats().projections,500);assert.equal(r.stats().metadata,500);assert.equal(r.stats().resources,32);
 assert.equal(createPreparedBarChartGeometry(first,{palette:['#abc']}).bars[0].raw,'1.2500');
 const count=calls;await r.describe(ref(501));await r.prepare({...binding(),dataset:ref(501)});assert.equal(calls,count);
 r.dispose();assert.equal(r.stats().projections,0);assert.equal(r.stats().metadata,0);
});
