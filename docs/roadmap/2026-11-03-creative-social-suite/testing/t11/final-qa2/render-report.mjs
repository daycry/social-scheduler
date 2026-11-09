import {chromium} from 'playwright';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
const root=path.dirname(fileURLToPath(import.meta.url));
const browser=await chromium.launch();
try {
  const page=await browser.newPage({viewport:{width:673,height:980}});
  await page.emulateMedia({media:'print'});
  await page.goto(pathToFileURL(path.join(root,'report.html')).href);
  await page.evaluate(()=>document.fonts.ready);
  const missing=await page.locator('img').evaluateAll(images=>images.filter(i=>!i.complete||!i.naturalWidth).map(i=>i.src));
  if(missing.length) throw new Error(`Missing report images: ${missing}`);
  await page.screenshot({path:path.join(root,'raw/report-preview.png')});
  await page.pdf({path:path.join(root,'report.pdf'),format:'A4',printBackground:true,margin:{top:'18mm',bottom:'18mm',left:'16mm',right:'16mm'}});
  console.log('PDF created; images verified loaded.');
} finally {await browser.close();}
