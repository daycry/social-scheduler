// Independent portable SVG observation: no product renderer imports, no network.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';
const root=path.dirname(fileURLToPath(import.meta.url));
const files=[];
for(const project of ['desktop','mobile','mobile-360']) {
  const directory=path.join(root,'samples/captions',project);
  if(!fs.existsSync(directory)) continue;
  for(const name of fs.readdirSync(directory)) if(/^independent-vector-\d+\.svg$/.test(name)) files.push(path.join(directory,name));
}
const browser=await chromium.launch();
const observations=[];
try {
  const context=await browser.newContext({viewport:{width:400,height:300},deviceScaleFactor:1,serviceWorkers:'block',javaScriptEnabled:false});
  await context.route('**/*',route=>route.abort());
  const page=await context.newPage();
  for(const file of files) {
    const svg=fs.readFileSync(file,'utf8').replace(/<\?xml[^>]*\?>/g,'').replace(/<!DOCTYPE[\s\S]*?>/g,'');
    await page.setContent(`<style>body{margin:0}</style>${svg}`);
    await page.evaluate(()=>document.fonts.ready);
    const target=file.replace(/\.svg$/,'.png');
    await page.locator('svg').first().screenshot({path:target});
    observations.push({file:path.relative(root,file),png:path.relative(root,target),
      fonts:await page.evaluate(()=>[...document.fonts].map(f=>({family:f.family,status:f.status,weight:f.weight})))});
  }
} finally {await browser.close();}
fs.writeFileSync(path.join(root,'raw/svg-raster-observations.json'),JSON.stringify(observations,null,2));
console.log(`Independently rasterized ${observations.length} SVG pages without product helpers or network`);
