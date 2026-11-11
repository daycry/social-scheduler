// Owned frontend only: passive downloads and an explicitly armed exact-response hold.
// The real PHP request executes once. Delivery replays unchanged write/end chunks.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
function attach(frontend,root){
 const append=row=>fs.appendFileSync(path.join(root,'chart-http-observer.jsonl'),JSON.stringify(row)+'\n');
 const listener=(req,res)=>{
  const route=new URL(req.url,'http://localhost').pathname;let control;
  try{control=JSON.parse(fs.readFileSync(path.join(root,'chart-hold-control.json'),'utf8'));}catch{}
  const hold=control&&control.method===req.method&&control.path===route&&/^[a-f0-9-]{36}$/.test(control.token)&&!fs.existsSync(path.join(root,`hold-${control.token}.claimed`));
  const download=req.method==='GET'&&/^\/studio\/downloads\/\d+\/\d+$/.test(route);
  if(!hold&&!download)return;
  const hash=crypto.createHash('sha256');let bytes=0,ended=false,finished=false,upstreamStatus,headers,held=[],captured=false;
  if(hold)fs.writeFileSync(path.join(root,`hold-${control.token}.claimed`),'claimed',{flag:'wx'});
  res.on('pipe',source=>{upstreamStatus=source.statusCode;headers=Object.fromEntries(['content-type','content-disposition','cache-control','x-content-type-options'].filter(k=>source.headers[k]!==undefined).map(k=>[k,source.headers[k]]));source.on('data',chunk=>{const b=Buffer.from(chunk);bytes+=b.length;hash.update(b);});source.once('end',()=>{ended=true;});});
  res.once('finish',()=>{finished=true;});
  res.once('close',()=>{if(download)append({kind:'download',method:req.method,path:route,status:res.statusCode,upstream_status:upstreamStatus,headers,bytes,sha256:ended?hash.digest('hex'):null,upstream_ended:ended,response_finished:finished});if(hold&&captured&&!finished)append({kind:'hold-client-cancelled',token:control.token,real_response_captured:true});});
  if(!hold)return;
  const write=res.write,end=res.end;let timer,total=0;
  res.write=function(chunk,...args){const data=Buffer.from(chunk,typeof args[0]==='string'?args[0]:undefined);total+=data.length;if(total>2*1024*1024)throw Error('QA hold fixture exceeds 2 MiB');held.push({data,args});const callback=args.find(a=>typeof a==='function');callback?.();return true;};
  res.end=function(chunk,...args){if(chunk&&typeof chunk!=='function')this.write(chunk,...args.filter(a=>typeof a!=='function'));captured=true;const body=Buffer.concat(held.map(h=>h.data));append({kind:'hold-captured-real-response',token:control.token,method:req.method,path:route,status:res.statusCode,upstream_status:upstreamStatus,bytes:body.length,sha256:sha(body),headers});
   const start=Date.now();timer=setInterval(()=>{if(!fs.existsSync(path.join(root,`hold-${control.token}.release`))&&Date.now()-start<15000)return;clearInterval(timer);res.write=write;res.end=end;const closed=res.destroyed;if(!closed){for(const h of held)write.call(res,h.data,...h.args.filter(a=>typeof a!=='function'));end.call(res);}append({kind:'hold-released',token:control.token,cancelled_delivery:closed,unchanged_bytes:body.length,sha256:sha(body),deadline_release:Date.now()-start>=15000});held=[];},20);return res;
  };
 };
 frontend.prependListener('request',listener);return ()=>frontend.removeListener('request',listener);
}
module.exports={attach,sha};
