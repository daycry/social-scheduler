import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),__dirname=fileURLToPath(new URL('.',import.meta.url));
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{EventEmitter}=require('node:events'),{PassThrough,Writable}=require('node:stream'),crypto=require('node:crypto'),observer=require('../e2e/isolated/datasets/download-observer.cjs');
const url='/brands/1/studio/datasets/2/export?revision=1&format=json',href='http://localhost:8080'+url;
const headers={'content-type':'application/json; charset=UTF-8','cache-control':'private, no-store','x-content-type-options':'nosniff','content-disposition':'attachment; filename="dataset-2-r1.json"','set-cookie':'not-recorded'};let checks=0;
const {test}=require('node:test');
test('public passive export observer: eighteen behavioral checks', async()=>{
 const front=new EventEmitter(),rows=[],written=[];observer.attach(front,'unused',{record:r=>rows.push(r)});
 const dest=new Writable({write(chunk,_encoding,callback){written.push(Buffer.from(chunk));callback();}});dest.statusCode=200;const write=dest.write,end=dest.end;front.emit('request',{method:'GET',url},dest);assert.equal(dest.write,write);assert.equal(dest.end,end);checks+=2;
 const source=new PassThrough();source.statusCode=200;source.headers=headers;const finished=new Promise(resolve=>dest.once('finish',resolve));source.pipe(dest);const data=Buffer.from('AéB');source.write(data.subarray(0,2));source.end(data.subarray(2));await finished;await new Promise(resolve=>setImmediate(resolve));assert.equal(rows.length,1);assert.equal(rows[0].bytes,data.length);assert.equal(rows[0].sha256,observer.sha(Buffer.concat(written)));assert.deepEqual(Object.keys(rows[0].headers).sort(),['cache-control','content-disposition','content-type','x-content-type-options']);checks+=4;
 observer.verifyResponse(rows[0],href,'json');checks++;assert.throws(()=>observer.exactlyOne([]));assert.throws(()=>observer.exactlyOne([rows[0],rows[0]]));checks+=2;
 for(const variant of [{...rows[0],status:500},{...rows[0],headers:{...rows[0].headers,'cache-control':'public'}},{...rows[0],headers:{...rows[0].headers,'content-type':'text/plain'}},{...rows[0],headers:{...rows[0].headers,'content-disposition':'inline'}}]){assert.throws(()=>observer.verifyResponse(variant,href,'json'));checks++;}
 for(const mode of ['premature-close','upstream-error','two-pipes']){
  const server=new EventEmitter(),records=[];observer.attach(server,'unused',{record:r=>records.push(r)});const response=new EventEmitter();response.statusCode=200;server.emit('request',{method:'GET',url},response);const incoming=new EventEmitter();incoming.statusCode=200;incoming.headers=headers;response.emit('pipe',incoming);incoming.emit('data',Buffer.from('x'));if(mode==='premature-close')response.emit('close');if(mode==='upstream-error')incoming.emit('error',new Error('own fixture'));if(mode==='two-pipes')response.emit('pipe',incoming);incoming.emit('end');response.emit('finish');await new Promise(resolve=>setImmediate(resolve));assert.equal(records.length,0);checks++;
 }
 const file=path.join(__dirname,'dataset-observer-'+crypto.randomUUID()+'.fixture');fs.writeFileSync(file,data);observer.verifyFile(rows[0],file);checks++;fs.writeFileSync(file,Buffer.from('bad'));assert.throws(()=>observer.verifyFile(rows[0],file));checks++;fs.unlinkSync(file);
 assert.equal(checks,18);
 for(const request of [{method:'POST',url},{method:'GET',url:'/studio'},{method:'GET',url:'/brands/1/studio/datasets/2/rows'}]){
  const server=new EventEmitter(),response=new EventEmitter(),records=[];observer.attach(server,'unused',{record:r=>records.push(r)});server.emit('request',request,response);const incoming=new EventEmitter();incoming.statusCode=200;incoming.headers=headers;response.emit('pipe',incoming);incoming.emit('data',Buffer.from('ignored'));incoming.emit('end');response.emit('finish');await new Promise(resolve=>setImmediate(resolve));assert.equal(records.length,0);
 }
});
const {applyTo}=require('../e2e/isolated/datasets/os-environment.cjs');
test('public OS environment: five behavioral checks, no credentials copied',()=>{
 for(const key of ['SystemDrive','SYSTEMDRIVE','systemdrive']){const target={EXISTING:'keep'};applyTo(target,{[key]:'C:',SECRET_TOKEN:'must-not-copy',APP_KEY:'must-not-copy'});assert.deepEqual(target,{EXISTING:'keep',[key]:'C:'});}
 assert.deepEqual(applyTo({}, {SystemDrive:'',SECRET:'hidden'}),{});
 assert.deepEqual(applyTo({APP_ENV:'e2e'}, {SystemDrive:'D:',APP_ENV:'production'}),{APP_ENV:'e2e',SystemDrive:'D:'});
});
