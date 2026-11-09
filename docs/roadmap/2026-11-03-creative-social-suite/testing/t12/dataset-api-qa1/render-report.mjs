import {chromium} from 'playwright';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
const root=path.dirname(fileURLToPath(import.meta.url));
const browser=await chromium.launch();
try {
 const page=await browser.newPage();
 await page.emulateMedia({media:'print'});
 await page.goto(pathToFileURL(path.join(root,'report.html')).href);
 await page.evaluate(()=>document.fonts.ready);
 await page.pdf({path:path.join(root,'report.pdf'),format:'A4',printBackground:true,margin:{top:'18mm',bottom:'18mm',left:'16mm',right:'16mm'}});
} finally {await browser.close();}
