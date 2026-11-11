// Passive observation of the owned frontend's existing IncomingMessage.pipe(response).
// No route interception, second GET, response mutation, cookies or bodies in evidence.
const fs=require('node:fs'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const ALLOW=['content-type','cache-control','x-content-type-options','content-disposition'];
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
function relative(url){const u=new URL(url,'http://localhost');assert.match(u.pathname,/^\/brands\/[1-9]\d*\/studio\/datasets\/[1-9]\d*\/export$/);const rules={revision:/^[1-9]\d*$/,format:/^(csv|tsv|json)$/,mode:/^(spreadsheet|text)$/,header:/^(true|false|0|1)$/};const seen=new Set();for(const [k,v] of u.searchParams){assert(rules[k]&&!seen.has(k)&&rules[k].test(v),'Invalid export query');seen.add(k);}return u.pathname+u.search;}
function publicHeaders(headers){return Object.fromEntries(ALLOW.filter(k=>headers[k]!==undefined).map(k=>[k,Array.isArray(headers[k])?headers[k].join(', '):String(headers[k])]));}
function attach(frontend,logFile,{record}={}){
 const commit=record??(row=>fs.appendFileSync(logFile,JSON.stringify(row)+'\n'));
 const listener=(request,response)=>{
  if(request.method!=='GET')return;let url;try{url=relative(request.url);}catch{return;}
  let pipeCount=0,ended=false,finished=false,invalid=false,committed=false,total=0,upstreamStatus,headers;const hash=crypto.createHash('sha256');
  const maybe=()=>queueMicrotask(()=>{if(committed||invalid||pipeCount!==1||!ended||!finished)return;committed=true;try{commit({relative_url:url,status:response.statusCode,headers,bytes:total,sha256:hash.digest('hex'),pipe_count:pipeCount,upstream_ended:true,response_finished:true,upstream_status:upstreamStatus});}catch(error){console.error('download-observer write failed: '+error.name);}});
  response.on('pipe',incoming=>{
   if(++pipeCount!==1){invalid=true;return;}
   upstreamStatus=incoming.statusCode;headers=publicHeaders(incoming.headers);
   incoming.on('data',chunk=>{if(invalid||committed)return;const bytes=Buffer.isBuffer(chunk)?chunk:Buffer.from(chunk);total+=bytes.length;hash.update(bytes);});
   incoming.once('end',()=>{ended=true;maybe();});incoming.once('error',()=>{invalid=true;});incoming.once('aborted',()=>{invalid=true;});incoming.once('close',()=>{if(!ended)invalid=true;});
  });
  response.once('finish',()=>{finished=true;maybe();});response.once('close',()=>{if(!finished)invalid=true;});response.once('error',()=>{invalid=true;});
 };
 frontend.prependListener('request',listener);return ()=>frontend.removeListener('request',listener);
}
function cursor(log){return fs.existsSync(log)?fs.statSync(log).size:0;}
function rowsSince(log,start,href){const target=relative(href);if(!fs.existsSync(log))return [];const bytes=fs.readFileSync(log);assert(bytes.length>=start,'Observer log truncated');return bytes.subarray(start).toString('utf8').split('\n').filter(Boolean).map(line=>JSON.parse(line)).filter(row=>row.relative_url===target);}
function exactlyOne(rows){assert.equal(rows.length,1,`Expected one new complete export observation, found ${rows.length}`);return rows[0];}
function verifyResponse(meta,href,format){
 const u=new URL(href),id=u.pathname.match(/\/datasets\/(\d+)\/export$/)[1];relative(href);assert.equal(meta.status,200,`Unexpected export HTTP status ${meta.status}`);assert.equal(meta.upstream_status,meta.status);assert.equal(meta.pipe_count,1);assert.equal(meta.upstream_ended,true);assert.equal(meta.response_finished,true);
 const h=meta.headers;assert.match(h['cache-control']??'',/\bprivate\b/i);assert.match(h['cache-control']??'',/\bno-store\b/i);assert.equal(h['x-content-type-options'],'nosniff');const mime={json:'application/json',csv:'text/csv',tsv:'text/tab-separated-values'}[format];assert(mime);assert((h['content-type']??'').toLowerCase().startsWith(mime));assert.match(h['content-type'],/charset=utf-8/i);assert.match(h['content-disposition']??'',/\battachment\b/i);assert((h['content-disposition']??'').includes(`dataset-${id}-r${u.searchParams.get('revision')}.${format}`));
}
function verifyFile(meta,file){const bytes=fs.readFileSync(file);assert.equal(bytes.length,meta.bytes,'Downloaded byte length differs from passive response');assert.equal(sha(bytes),meta.sha256,'Downloaded SHA differs from passive response');return bytes;}
async function observe(page,link,{file,format,logFile,timeout=30000}){
 const href=new URL(await link.getAttribute('href'),page.url()).href;assert.equal(new URL(href).origin,new URL(page.url()).origin);assert(['localhost','127.0.0.1','[::1]'].includes(new URL(href).hostname));relative(href);const start=cursor(logFile);let resolveDownload,rejectDownload;
 const downloaded=new Promise((resolve,reject)=>{resolveDownload=resolve;rejectDownload=reject;});downloaded.catch(()=>{});const listener=download=>{if(download.url()===href)resolveDownload(download);};page.on('download',listener);const deadline=Date.now()+timeout;const timer=setTimeout(()=>rejectDownload(new Error('Native download deadline')),timeout);
 try{await link.click({timeout});let records;while(Date.now()<deadline){records=rowsSince(logFile,start,href);if(records.length)break;await new Promise(resolve=>setTimeout(resolve,10));}const meta=exactlyOne(records??[]);verifyResponse(meta,href,format);const download=await downloaded;assert.equal(download.url(),href);assert.equal(await download.failure(),null);await download.saveAs(file);const bytes=verifyFile(meta,file);exactlyOne(rowsSince(logFile,start,href));return {meta,bytes,filename:download.suggestedFilename()};}
 finally{clearTimeout(timer);page.off('download',listener);}
}
module.exports={attach,cursor,rowsSince,exactlyOne,verifyResponse,verifyFile,observe,sha,relative,publicHeaders};
