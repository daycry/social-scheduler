// Passive renderer stdin observer. Does not attach data listeners or change flow.
// No raw document, environment, cookies, table contents or font bytes are logged.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
function summarize(input,read,directory){
 if(!input||typeof input!=='object'||!Array.isArray(input.pageIds)||!Array.isArray(input.chartFiles))throw Error('Invalid native manifest observation');
 return {format:input.format,selected_pages:input.pageIds,chart_files:input.chartFiles.map(file=>{if(!/^datasets\/[A-Za-z0-9._-]+\.json$/.test(file.name))throw Error('Observed descriptor name escapes datasets');const bytes=read(path.join(directory,file.name));const hash=crypto.createHash('sha256').update(bytes).digest('hex');if(hash!==file.sha256||bytes.length!==file.bytes)throw Error('Materialized original bytes mismatch');return{id:file.id,revision:file.revision,sha256:file.sha256,bytes:file.bytes,name:file.name,observed_bytes:bytes.length,observed_sha256:hash};}),font_ids:(input.fonts??[]).map(font=>font.id),document_sha256:crypto.createHash('sha256').update(JSON.stringify(input.document)).digest('hex')};
}
function install(){
 if(path.basename(process.argv[1]??'')!=='render.mjs')return false;
 const root=process.env.E2E_GENERAL_RUN_ROOT;
 if(!root||!/^run-general-[a-f0-9-]{36}$/.test(path.basename(root)))throw Error('Passive chart observer needs owned runtime');
 const original=process.stdin.emit;let chunks=[],size=0,ended=false;
 process.stdin.emit=function(event,...args){if(event==='data'){const chunk=Buffer.from(args[0]);size+=chunk.length;if(size>90*1024*1024)throw Error('Observed renderer input exceeds existing budget');chunks.push(chunk);}if(event==='end')ended=true;return original.call(this,event,...args);};
 process.on('exit',()=>{let record;try{if(!ended)throw Error('Renderer stdin did not end');const directory=path.resolve(process.argv[2]??''),prefix=path.resolve(root,'media','studio-exports')+path.sep;if(!path.isAbsolute(process.argv[2]??'')||!directory.toLowerCase().startsWith(prefix.toLowerCase())||!/^[1-9]\d*[\\/][1-9]\d*[\\/][a-f0-9-]{36}$/.test(path.relative(prefix,directory)))throw Error('Native observer refuses directory outside owned export');if(fs.realpathSync(directory).toLowerCase()!==directory.toLowerCase())throw Error('Native observer refuses export symlink');const read=file=>{const actual=fs.realpathSync(file),stat=fs.lstatSync(file);if(!actual.toLowerCase().startsWith(directory.toLowerCase()+path.sep)||stat.isSymbolicLink()||!stat.isFile()||stat.size>52000000)throw Error('Native observer refuses unsafe materialized file');return fs.readFileSync(actual);};const data=Buffer.concat(chunks);record={kind:'native-render-stdin-passive',pid:process.pid,owned_directory:true,input_bytes:data.length,input_sha256:crypto.createHash('sha256').update(data).digest('hex'),...summarize(JSON.parse(data.toString('utf8')),read,directory)};}catch(error){record={kind:'native-render-stdin-passive',error:error.message};}fs.appendFileSync(path.join(root,'chart-native-observer.jsonl'),JSON.stringify(record)+'\n');});
 return true;
}
module.exports={summarize,install};install();
