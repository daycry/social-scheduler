import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import crypto from 'node:crypto';
import { chromium } from 'playwright';
import { build } from 'vite';
import { blankDocument, newElement, duplicatePage } from '../../resources/js/Studio/document.js';

let browser, page, font, documentData, requests;
before(async () => {
    const bytes = await fs.readFile('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2');
    const origin = 'http://t10-font-regression.invalid';
    font = { id:17, brand_id:1, name:'Space', family:'Space Grotesk', weight:400, style:'normal', format:'woff2', mime:'font/woff2', size:bytes.length, sha256:crypto.createHash('sha256').update(bytes).digest('hex'), status:'ready', file_url:origin+'/fonts/17' };
    browser = await chromium.launch({headless:true});
    const context = await browser.newContext({serviceWorkers:'block'});
    await context.route('**/*', async route => {
        if (route.request().url() === origin+'/') return route.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'});
        if (route.request().url() !== font.file_url) return route.abort();
        requests++;
        await new Promise(resolve => setTimeout(resolve,20));
        return route.fulfill({contentType:font.mime,body:bytes,headers:{'Cache-Control':'private, no-store'}});
    });
    page = await context.newPage(); await page.goto(origin+'/');
    const bundle = await build({configFile:false,publicDir:false,logLevel:'silent',build:{write:false,lib:{entry:'resources/js/Studio/exports.js',name:'StudioExportsProbe',formats:['iife']}}});
    await page.addScriptTag({content:(Array.isArray(bundle)?bundle[0]:bundle).output[0].code});
    documentData = blankDocument(100,100);
    documentData.pages[0].elements=[newElement('text',{x:1,y:1,width:90,height:30,text:'Marca',font_id:17,fontFamily:font.family,fontSize:12})];
    for(let i=1;i<20;i++) documentData.pages.push(duplicatePage(documentData.pages[0]));
    delete documentData.pages[0].elements[0].font_id;
    documentData.pages[0].elements[0].fontFamily='Arial';
});
after(async () => { await browser?.close(); });
for (const format of ['png','pdf']) test(`multipage ${format} downloads one font once and releases its faces`, async () => {
    requests=0;
    const result=await page.evaluate(async ({doc,font,format}) => {
        const files=await StudioExportsProbe.exportDesign(doc,{fonts:[font],format});
        return {files:files.length,faces:Array.from(document.fonts).filter(face=>face.family.startsWith('StudioFont_')).length};
    },{doc:documentData,font,format});
    assert.equal(result.files,format==='pdf'?1:20);
    assert.equal(requests,1,'one font must be reused across all selected pages');
    assert.equal(result.faces,0,'completed exports must release their owned faces');
});
