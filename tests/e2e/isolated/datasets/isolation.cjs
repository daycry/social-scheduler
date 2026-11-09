const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const fontFixture=path.join(__dirname,'fixtures/google-inter-empty.css');
function knownFont(url){try{const u=new URL(url);return !u.username&&!u.password&&!u.hash&&u.origin==='https://fonts.googleapis.com'&&u.pathname==='/css2'&&u.searchParams.get('family')==='Inter:wght@400;500;600;700'&&u.searchParams.get('display')==='swap'&&[...u.searchParams.keys()].sort().join(',')==='display,family';}catch{return false;}}
function pathname(url){try{return new URL(url).pathname;}catch{return null;}}
function state(){return {applicationErrors:[],responses:[],browserDiagnostics:[],failedRequests:[],expectedHttp:[],expectedAborts:[],fixtures:[]};}
function expectHttp(s,method,path,status){const token={method,path,status,asserted:false};s.expectedHttp.push(token);return token;}
function expectAbort(s,method,path){const token={method,path,confirmed:false};s.expectedAborts.push(token);return token;}
function consoleError(s,text,url){
 const match=text.match(/^Failed to load resource: the server responded with a status of (\d{3})(?:\b.*)?$/);
 const abort=text==='Failed to load resource: net::ERR_ABORTED';
 if((match||abort)&&(!url||!pathname(url)?.startsWith('/build/')))s.browserDiagnostics.push({text,path:pathname(url),status:match?Number(match[1]):null,abort});
 else s.applicationErrors.push({text,path:pathname(url)});
}
function assess(s){
 const issues=s.applicationErrors.map(e=>({kind:'APPLICATION_OR_GUARD_ERROR',...e}));const matched=[],usedHttp=new Set();
 for(const response of s.responses){const index=s.expectedHttp.findIndex((e,i)=>!usedHttp.has(i)&&e.asserted&&e.method===response.method&&e.path===response.path&&e.status===response.status);if(index<0)issues.push({kind:'UNEXPECTED_HTTP_ERROR',...response});else{usedHttp.add(index);matched.push(response);}}
 s.expectedHttp.forEach((e,i)=>{if(!usedHttp.has(i))issues.push({kind:'UNAFFIRMED_OR_UNSEEN_EXPECTED_HTTP',...e});});
 const validAborts=[],usedExpectedAborts=new Set();
 for(const failure of s.failedRequests){const index=failure.error==='net::ERR_ABORTED'?s.expectedAborts.findIndex((e,i)=>!usedExpectedAborts.has(i)&&e.confirmed&&e.method===failure.method&&e.path===failure.path):-1;if(index>=0){usedExpectedAborts.add(index);validAborts.push(failure);}else issues.push({kind:'UNEXPECTED_REQUEST_FAILURE',...failure});}
 const consumedHttp=new Set(),consumedAbort=new Set();
 for(const diagnostic of s.browserDiagnostics){if(diagnostic.abort){const index=validAborts.findIndex((a,i)=>!consumedAbort.has(i)&&(!diagnostic.path||diagnostic.path===a.path));if(index<0)issues.push({kind:'UNMATCHED_BROWSER_ABORT_DIAGNOSTIC',...diagnostic});else consumedAbort.add(index);}else{const index=matched.findIndex((r,i)=>!consumedHttp.has(i)&&r.status===diagnostic.status&&(!diagnostic.path||diagnostic.path===r.path));if(index<0)issues.push({kind:'UNMATCHED_BROWSER_HTTP_DIAGNOSTIC',...diagnostic});else consumedHttp.add(index);}}
 return {issues,expected_http:matched,intentional_aborts:validAborts,browser_diagnostics:s.browserDiagnostics,isolated_font_fixtures:s.fixtures};
}
async function install(page,s){
 page.on('pageerror',error=>s.applicationErrors.push({text:error.message,path:null}));
 page.on('console',message=>{if(message.type()==='error')consoleError(s,message.text(),message.location().url);});
 page.on('response',response=>{if(response.status()>=400)s.responses.push({method:response.request().method(),path:pathname(response.url()),status:response.status()});});
 page.on('requestfailed',request=>s.failedRequests.push({method:request.method(),path:pathname(request.url()),error:request.failure()?.errorText??'unknown'}));
 await page.route('**/*',async route=>{
  const request=route.request(),url=request.url();
  if(request.method()==='GET'&&knownFont(url)){const body=fs.readFileSync(fontFixture);s.fixtures.push({kind:'empty-css-system-fallback',url,bytes:body.length,sha256:crypto.createHash('sha256').update(body).digest('hex'),external_Inter_loaded:false});await route.fulfill({status:200,contentType:'text/css; charset=utf-8',body});return;}
  const host=new URL(url).hostname;
  if(!['localhost','127.0.0.1','::1'].includes(host)){s.applicationErrors.push({text:`Unexpected external request host: ${host}`,path:pathname(url)});await route.abort();return;}
  await route.continue();
 });
}
module.exports={knownFont,pathname,state,expectHttp,expectAbort,consoleError,assess,install};
