import { expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { runRoot } from '../isolated/runtime';
import svgOracle from '../isolated/charts/svg-oracle.cjs';

// DOM XML + native SVG transform matrices only. No chart geometry/render imports.
// Tspan measured in the page: text, explicit x/y, CTM (6 numbers) and the effective font.
type InkSpan = { text: string; x: number; y: number; matrix: number[]; font: string; fontReady: boolean; fontKerning?: string; letterSpacing?: string; wordSpacing?: string };

export async function observeSvg(page: Page, xml: string, layer: any, table: any) {
    const observed = await page.evaluate(async source => {
        // Pure metric union: testable without a browser, never substitutes getBBox.
        function measureSvgTextInk(context: CanvasRenderingContext2D, spans: InkSpan[], rootMatrix: number[]) {
            const finiteMatrix = (matrix: unknown) => Array.isArray(matrix) && matrix.length === 6 && (matrix as number[]).every(Number.isFinite);
            if (!context || typeof context.measureText !== 'function' || !finiteMatrix(rootMatrix)) throw Error('Finite Canvas ink context/CTM required');
            for (const span of spans) {
                if (span.fontReady !== true || typeof span.font !== 'string' || !span.font) throw Error('Effective text font is not ready');
                if (typeof span.text !== 'string' || !Number.isFinite(span.x) || !Number.isFinite(span.y) || !finiteMatrix(span.matrix)) throw Error('Finite tspan coordinates/CTM required');
            }
            context.textAlign = 'left'; context.textBaseline = 'alphabetic';
            const points = [], fonts = [...new Set(spans.map((span: InkSpan) => span.font))];
            const project = (matrix: number[], x: number, y: number) => ({ x: matrix[0] * x + matrix[2] * y + matrix[4], y: matrix[1] * x + matrix[3] * y + matrix[5] });
            for (const span of spans) {
                if (!span.text.trim()) continue; // Spaces have advance but no drawn ink.
                context.font = span.font;
                if (span.fontKerning) context.fontKerning = span.fontKerning as CanvasFontKerning;
                if (span.letterSpacing) context.letterSpacing = span.letterSpacing;
                if (span.wordSpacing) context.wordSpacing = span.wordSpacing;
                const metrics = context.measureText(span.text);
                const values = ['actualBoundingBoxLeft','actualBoundingBoxRight','actualBoundingBoxAscent','actualBoundingBoxDescent'].map(key => metrics[key as keyof TextMetrics] as number);
                if (!values.every(Number.isFinite)) throw Error('Finite actual Canvas TextMetrics ink required');
                const [left, right, ascent, descent] = values;
                const x0 = span.x - left, x1 = span.x + right, y0 = span.y - ascent, y1 = span.y + descent;
                if (!(x1 > x0 && y1 > y0)) throw Error('Non-whitespace text has no visible Canvas ink');
                for (const [x,y] of [[x0,y0],[x1,y0],[x0,y1],[x1,y1]]) points.push(project(span.matrix,x,y));
            }
            if (!points.length) {
                const anchor = spans.length ? project(spans[0].matrix,spans[0].x,spans[0].y) : project(rootMatrix,0,0);
                return { ...anchor, width:0, height:0, empty:true, fonts };
            }
            const x = Math.min(...points.map(point => point.x)), y = Math.min(...points.map(point => point.y));
            const width = Math.max(...points.map(point => point.x)) - x, height = Math.max(...points.map(point => point.y)) - y;
            if (![x,y,width,height].every(Number.isFinite)) throw Error('Finite transformed Canvas ink required');
            return { x,y,width,height,empty:false,fonts };
        }
        const parsed = new DOMParser().parseFromString(source, 'image/svg+xml');
        if (parsed.querySelector('parsererror')) throw Error('Export is not well-formed SVG XML');
        const root = document.importNode(parsed.documentElement, true) as unknown as SVGSVGElement;
        root.style.position = 'fixed'; root.style.left = '-20000px'; document.body.append(root);
        try {
            root.getBoundingClientRect(); // Establish effective SVG styles before loading their faces.
            // fonts.ready alone can resolve while an embedded CSS face remains unloaded
            // when the page already contains a loaded FontFace with the same family.
            const neededFonts = new Map<string, Set<string>>();
            for (const span of Array.from(root.querySelectorAll('text tspan'))) {
                const effective = getComputedStyle(span);
                const font = `${effective.fontStyle} ${effective.fontWeight} ${effective.fontSize} ${effective.fontFamily}`;
                if (!neededFonts.has(font)) neededFonts.set(font, new Set());
                for (const character of span.textContent || ' ') neededFonts.get(font)!.add(character);
            }
            for (const [font, characters] of neededFonts) {
                const unique = Array.from(characters);
                // Preserve every required code point, with bounded load strings rather
                // than one asynchronous load for each of the dense chart's 12k labels.
                for (let offset = 0; offset < unique.length; offset += 4096) {
                    await document.fonts.load(font, unique.slice(offset, offset + 4096).join(''));
                }
            }
            await document.fonts.ready;
            const textContext = document.createElement('canvas').getContext('2d');
            if (!textContext) throw Error('Canvas text ink context unavailable');
            return { width: root.viewBox.baseVal.width || Number(root.getAttribute('width')), height: root.viewBox.baseVal.height || Number(root.getAttribute('height')), items: Array.from(root.querySelectorAll('[id]')).map(node => {
                const el = node as SVGGraphicsElement, shape = (['rect','line','text'].includes(el.tagName) ? el : el.querySelector('rect,line,text')) as SVGGraphicsElement | null;
                if (!shape) return { id:el.id, tag:el.tagName };
                const matrix=shape.getCTM(); if(!matrix)throw Error('Missing export transform');
                const box=shape.getBBox(),points=[[box.x,box.y],[box.x+box.width,box.y],[box.x,box.y+box.height],[box.x+box.width,box.y+box.height]].map(([x,y])=>({x:matrix.a*x+matrix.c*y+matrix.e,y:matrix.b*x+matrix.d*y+matrix.f}));
                const style=getComputedStyle(shape);
                const spans = Array.from(shape.querySelectorAll('tspan')).map(s=>({text:s.textContent??'',x:Number(s.getAttribute('x')??0),y:Number(s.getAttribute('y')??0)}));
                let ink;
                if (shape.tagName === 'text') {
                    const normalizeFamily = (family: string) => family.trim().replace(/^(['"])(.*)\1$/, '$2');
                    const positioned = Array.from(shape.querySelectorAll('tspan')).map(s => {
                        if (s.querySelector('tspan')) throw Error('Nested tspan ink positioning is unsupported');
                        const effective = getComputedStyle(s), font = `${effective.fontStyle} ${effective.fontWeight} ${effective.fontSize} ${effective.fontFamily}`;
                        const families = effective.fontFamily.split(',').map(normalizeFamily), privateFamilies = families.filter(family => /^StudioFont_/i.test(family));
                        const fontReady = document.fonts.check(font,s.textContent||' ') && privateFamilies.every(family => Array.from(document.fonts as unknown as Iterable<FontFace>).some(face => normalizeFamily(face.family) === family && face.status === 'loaded'));
                        const coordinate = (name: string) => { const value=s.getAttribute(name);if(value===null||!value.trim()||/[\s,]/.test(value.trim()))throw Error('Single explicit tspan ink coordinate required');return Number(value); };
                        const ctm=(s as SVGGraphicsElement).getCTM();if(!ctm)throw Error('Tspan ink CTM unavailable');
                        return {text:s.textContent??'',x:coordinate('x'),y:coordinate('y'),matrix:[ctm.a,ctm.b,ctm.c,ctm.d,ctm.e,ctm.f],font,fontReady,fontKerning:effective.fontKerning,letterSpacing:effective.letterSpacing==='normal'?'0px':effective.letterSpacing,wordSpacing:effective.wordSpacing==='normal'?'0px':effective.wordSpacing};
                    });
                    if (!positioned.length && shape.textContent?.trim()) throw Error('Unpositioned SVG text ink is unsupported');
                    ink=measureSvgTextInk(textContext,positioned,[matrix.a,matrix.b,matrix.c,matrix.d,matrix.e,matrix.f]);
                }
                return {id:el.id,tag:shape.tagName,text:shape.tagName==='text'?spans.map(span=>span.text).join(''):shape.textContent?.trim(),fill:style.fill,fontFamily:style.fontFamily,fontSize:style.fontSize,fontWeight:style.fontWeight,fontStyle:style.fontStyle,spans,matrix:[matrix.a,matrix.b,matrix.c,matrix.d,matrix.e,matrix.f],x:Math.min(...points.map(p=>p.x)),y:Math.min(...points.map(p=>p.y)),width:Math.max(...points.map(p=>p.x))-Math.min(...points.map(p=>p.x)),height:Math.max(...points.map(p=>p.y))-Math.min(...points.map(p=>p.y)),...(ink?{ink}:{})};
            }) };
        } finally { root.remove(); }
    },xml);
    return svgOracle.check(observed,layer,table);
}
export async function privateFontReference(page:Page,xml:string,observed:any,font:any,prefix:string){
 const embedded=Array.from(xml.matchAll(/url\(['"]?data:font\/woff2;base64,([A-Za-z0-9+/=]+)['"]?\)/g)).map(m=>Buffer.from(m[1],'base64'));
 expect(embedded.some(bytes=>crypto.createHash('sha256').update(bytes).digest('hex')===font.sha256&&bytes.length===font.size)).toBe(true);
 const original=fs.readFileSync(path.resolve('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf'));
 const glyph=observed.items.find((item:any)=>item.tag==='text'&&item.text==='QA 2026 é Ω');expect(glyph).toBeTruthy();expect(glyph.spans.length).toBeGreaterThan(0);
 // Loaded only AFTER both real exports. Distinct family, then removed in finally.
 const reference=await page.evaluate(async({glyph,encoded,width,height,background})=>{const family='QA_T12_Original_Reference',face=new FontFace(family,`url(data:font/ttf;base64,${encoded})`,{weight:'100 900'});await face.load();document.fonts.add(face);try{const draw=(family:string)=>{const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;const ctx=canvas.getContext('2d')!;ctx.fillStyle=background;ctx.fillRect(0,0,width,height);ctx.setTransform(...glyph.matrix as [number,number,number,number,number,number]);ctx.fillStyle=glyph.fill;ctx.font=`${glyph.fontStyle} ${glyph.fontWeight} ${glyph.fontSize} "${family}"`;ctx.textBaseline='alphabetic';for(const span of glyph.spans)ctx.fillText(span.text,span.x,span.y);return canvas.toDataURL('image/png').split(',')[1];};return{original:draw(family),fallback:draw('Arial')};}finally{document.fonts.delete(face);}}, {glyph,encoded:original.toString('base64'),width:observed.width,height:observed.height,background:observed.background});
 fs.writeFileSync(`${prefix}-font-original.png`,Buffer.from(reference.original,'base64'));fs.writeFileSync(`${prefix}-font-fallback.png`,Buffer.from(reference.fallback,'base64'));
 fs.writeFileSync(`${prefix}-glyph-oracle.json`,JSON.stringify({box:{x:glyph.x,y:glyph.y,width:glyph.width,height:glyph.height},original_ttf_sha256:crypto.createHash('sha256').update(original).digest('hex'),exported_woff2_sha256:font.sha256,svg_embedded_match:true,min_iou:0.8,min_fallback_margin:0.03,scope:'Original TTF Canvas2D after delivery; independent distinct family removed, Arial discriminated'},null,2));
}
export async function delivered(page: Page, action:()=>Promise<any>,file:string) {
    const log=path.join(runRoot,'chart-http-observer.jsonl'),offset=fs.existsSync(log)?fs.statSync(log).size:0;
    const [download]=await Promise.all([page.waitForEvent('download'),Promise.resolve().then(action)]);await download.saveAs(file);expect(await download.failure()).toBeNull();
    const bytes=fs.readFileSync(file),hash=crypto.createHash('sha256').update(bytes).digest('hex');let observed:any;
    if(download.url().startsWith('http:')){const pathname=new URL(download.url()).pathname;const rows=()=>fs.existsSync(log)?fs.readFileSync(log).subarray(offset).toString('utf8').split('\n').filter(Boolean).map(line=>JSON.parse(line)).filter(row=>row.kind==='download'&&row.path===pathname):[];await expect.poll(()=>rows().length).toBe(1);observed=rows()[0];expect(observed).toMatchObject({status:200,upstream_status:200,upstream_ended:true,response_finished:true,bytes:bytes.length,sha256:hash});expect(observed.headers['content-disposition']).toMatch(/attachment/i);expect(observed.headers['content-type']).toMatch(/^(image\/(svg\+xml|png)|application\/pdf)/);}
    return {bytes,meta:{url:download.url(),filename:download.suggestedFilename(),bytes:bytes.length,sha256:hash,headers:observed?.headers??'Not observable for browser Blob delivery; no synthetic GET performed',native_response:observed??null}};
}
