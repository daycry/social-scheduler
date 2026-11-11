import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { expect } from '@playwright/test';
import { runRoot } from '../isolated/runtime';
export function holdRealResponse(method:string,route:string){
 const token=crypto.randomUUID(),file=path.join(runRoot,'chart-hold-control.json'),log=path.join(runRoot,'chart-http-observer.jsonl');
 const offset=fs.existsSync(log)?fs.statSync(log).size:0;fs.writeFileSync(file,JSON.stringify({method,path:route,token}));
 const rows=()=>fs.existsSync(log)?fs.readFileSync(log).subarray(offset).toString('utf8').split('\n').filter(Boolean).map(line=>JSON.parse(line)).filter(row=>row.token===token):[];
 const markRelease=()=>fs.writeFileSync(path.join(runRoot,`hold-${token}.release`),'release');
 const release=async()=>{markRelease();await expect.poll(()=>rows().filter(r=>r.kind==='hold-released').length).toBe(1);const released=rows().find(r=>r.kind==='hold-released');expect(released.deadline_release).toBe(false);return rows();};
 return {async captured(){await expect.poll(()=>rows().filter(r=>r.kind==='hold-captured-real-response').length).toBe(1);const row=rows().find(r=>r.kind==='hold-captured-real-response');expect(row.status).toBe(200);expect(row.upstream_status).toBe(200);expect(row.bytes).toBeGreaterThan(0);expect(row.sha256).toMatch(/^[a-f0-9]{64}$/);return row;},async cancelled(){await expect.poll(()=>rows().filter(r=>r.kind==='hold-client-cancelled').length).toBe(1);},release,async cleanup(){markRelease();const captured=rows().some(row=>row.kind==='hold-captured-real-response');if(!captured)return{captured:false,released:false,events:rows()};const events=await release();return{captured:true,released:true,events};}};
}
