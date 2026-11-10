import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import isolation from './isolated/datasets/isolation.cjs';
import osEnvironment from './isolated/datasets/os-environment.cjs';
import { environment } from './isolated/runtime';
import { expect, test, type Page } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

// Explicit disposable native settings; no environment file or private helper imports.
osEnvironment.applyTo(environment);
Object.assign(environment, { STUDIO_PYTHON_BINARY: path.resolve('storage/framework/studio-python/Scripts/python.exe'), STUDIO_BACKGROUND_MODEL_PATH: path.resolve('storage/app/private/studio-models/birefnet-general-lite.onnx'), STUDIO_BACKGROUND_MODEL_SHA256: '5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333' });
const policies = new WeakMap<Page, ReturnType<typeof isolation.state>>();
const fontReads = new WeakMap<Page, Promise<any>[]>();
test.beforeEach(async ({ page }, info) => {
    const state = isolation.state(); policies.set(page, state); fontReads.set(page, []);
    await isolation.install(page, state);
    page.on('response', response => {
        if (response.request().method() !== 'GET' || !/^\/brands\/\d+\/studio\/fonts\/\d+\/file$/.test(new URL(response.url()).pathname)) return;
        const file = `font-response-${fontReads.get(page)!.length}.woff2`;
        const task = (async () => { const bytes = await response.body(); fs.writeFileSync(info.outputPath(file), bytes); return { url: new URL(response.url()).pathname, status: response.status(), mime: response.headers()['content-type'], bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), signature: bytes.subarray(0, 4).toString('ascii'), file }; })();
        fontReads.get(page)!.push(task); task.catch(() => {});
    });
});
test.afterEach(async ({ page }, info) => {
    fs.writeFileSync(info.outputPath('viewport.json'), JSON.stringify({ viewport: page.viewportSize(), project: info.project.name, case: info.title.startsWith('Studio imaging: real private font') ? 'E2E-09' : 'E2E-10' }));
    const network = isolation.assess(policies.get(page)!);
    fs.writeFileSync(info.outputPath('network-observer.json'), JSON.stringify(network, null, 2));
    const settled = await Promise.allSettled(fontReads.get(page)!);
    const fonts = settled.filter(entry => entry.status === 'fulfilled').map(entry => (entry as PromiseFulfilledResult<any>).value);
    const errors = settled.filter(entry => entry.status === 'rejected').map(entry => String((entry as PromiseRejectedResult).reason));
    fs.writeFileSync(info.outputPath('font-responses.json'), JSON.stringify({ fonts, errors }, null, 2));
    await expectNoOverflow(page);
    expect(errors, 'Font byte observer failures remain visible').toEqual([]);
    expect(network.issues, 'Preserve all unexpected HTTP, application and browser failures').toEqual([]);
    for (const font of fonts) { expect(font.status).toBe(200); expect(font.mime).toMatch(/^font\/woff2/); expect(font.signature).toBe('wOF2'); expect(font.bytes).toBeGreaterThan(100); }
});
async function audit(page: Page, selector: string, info: any, name: string) {
    // Axe cannot resolve the background of nodes scrolled out of an overflow container ("partially obscured"): audit with a
    // viewport tall enough to lay the whole audited region out, then restore it. No rule is excluded.
    const viewport = page.viewportSize()!;
    await page.setViewportSize({ width: viewport.width, height: 3600 });
    await page.evaluate(selector => {
        const root = document.querySelector(selector)!;
        const scrollers = [...root.querySelectorAll('*'), root, ...Array.from({ length: 12 }).reduce<Element[]>((all, _, i) => { const parent = (all.at(-1) ?? root).parentElement; return parent ? [...all, parent] : all; }, [])];
        for (const element of scrollers) if (element.scrollTop) element.scrollTop = 0;
        return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    }, selector);
    const result = await new AxeBuilder({ page }).include(selector).analyze().finally(() => page.setViewportSize(viewport));
    fs.writeFileSync(info.outputPath(`${name}-axe.json`), JSON.stringify(result, null, 2));
    expect(result.violations, 'No excluded axe rules').toEqual([]);
    // Axe leaves color-contrast "incomplete" (no violation) when it cannot read the background: a native select paints its
    // chevron as a background image, and a text block can partially overlap a neighbouring box. Those nodes, and only those
    // reasons of that one rule, are measured here from their computed colours over the nearest opaque ancestor and must reach
    // WCAG AA. Every other incomplete result still fails.
    const measurable = (item: any) => item.id === 'color-contrast' && item.nodes.length > 0 && item.nodes.every((node: any) => node.any.every((check: any) => ['bgImage', 'elmPartiallyObscuring'].includes(check.data?.messageKey)));
    expect(result.incomplete.filter(item => !measurable(item)), 'No incomplete axe results silently discarded').toEqual([]);
    for (const item of result.incomplete.filter(measurable)) for (const node of item.nodes) {
        const measured = await page.locator(String(node.target[0])).first().evaluate(el => {
            const channels = (value: string) => (value.match(/[\d.]+/g) ?? []).map(Number);
            const luminance = ([r, g, b]: number[]) => { const f = (c: number) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
            const style = getComputedStyle(el), fg = channels(style.color);
            let walker: Element | null = el, bg = [255, 255, 255];
            while (walker) { const c = channels(getComputedStyle(walker).backgroundColor); if (c.length >= 3 && (c.length === 3 || c[3] > 0.99)) { bg = c.slice(0, 3); break; } walker = walker.parentElement; }
            const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, c) => c - a);
            return (hi + 0.05) / (lo + 0.05);
        });
        expect(measured, `contrast of ${node.target[0]} measured from computed styles`).toBeGreaterThanOrEqual(4.5);
    }
}
async function fontReference(page: Page, documentData: any, info: any, font: any) {
    const original = fs.readFileSync(path.resolve('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf'));
    const observed = await page.evaluate(async ({ encoded, documentData }) => {
        const e = documentData.pages[0].elements[0];
        const bytes = Uint8Array.from(atob(encoded), c => c.charCodeAt(0));
        const face = new FontFace('QAOriginalSpaceGrotesk', bytes, { weight: '400', style: 'normal' });
        await face.load(); document.fonts.add(face);
        const draw = (family: string) => { const c = document.createElement('canvas'); c.width = documentData.width; c.height = Math.ceil(e.fontSize * 2); const ctx = c.getContext('2d')!; ctx.font = `${e.fontStyle} ${e.fontWeight} ${e.fontSize}px "${family}"`; ctx.fillStyle = e.fill; const width = ctx.measureText(e.text).width; ctx.fillText(e.text, 8, e.fontSize * 1.4); return { png: c.toDataURL('image/png').split(',')[1], width }; };
        try { return { original: draw('QAOriginalSpaceGrotesk'), fallback: draw('Arial'), status: face.status }; }
        finally { document.fonts.delete(face); }
    }, { encoded: original.toString('base64'), documentData });
    expect(observed.status).toBe('loaded');
    expect(observed.original.width).toBeLessThan(documentData.pages[0].elements[0].width - 4);
    fs.writeFileSync(info.outputPath('font-original-reference.png'), Buffer.from(observed.original.png, 'base64'));
    fs.writeFileSync(info.outputPath('font-fallback-reference.png'), Buffer.from(observed.fallback.png, 'base64'));
    fs.writeFileSync(info.outputPath('font-oracle.json'), JSON.stringify({ document: documentData, font, original_sha256: crypto.createHash('sha256').update(original).digest('hex'), measured_width: observed.original.width }, null, 2));
}

async function createDesign(page: Page, brandId: number) {
    await page.goto(`/studio?brand_id=${brandId}`);
    await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
    await page.getByRole('dialog', { name: 'Nuevo diseño', exact: true }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
    await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
    await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
    return Number(page.url().match(/designs\/(\d+)/)![1]);
}

async function readDesign(page: Page, id: number) {
    return page.evaluate(async designId => {
        const response = await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Design read failed: ${response.status}`);
        return (await response.json()).design;
    }, id);
}

async function save(page: Page) {
    await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
    await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
}

test('Studio imaging: real private font upload, preview, search, kit, archive and protected deletion', async ({ page }, info) => {
    test.setTimeout(100_000);
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-fonts-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Tipografías de marca', exact: true }).click();
        const library = page.getByRole('dialog', { name: 'Tipografías de marca', exact: true });
        await expect(library.getByLabel('Subir tipografía', { exact: true })).toBeEnabled();
        await library.getByLabel('Nombre de la tipografía').fill('Editorial de prueba');
        const upload = page.waitForResponse(response => /\/brands\/\d+\/studio\/fonts$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await library.getByLabel('Subir tipografía', { exact: true }).setInputFiles(path.resolve('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf'));
        const uploaded = await upload;
        expect(uploaded.status()).toBe(201);
        const font = (await uploaded.json()).font;
        expect(font.format).toBe('woff2');
        await library.getByRole('button', { name: `Vista previa de tipografía ${font.name}`, exact: true }).click();
        await expect(library.getByText('Una idea con personalidad. Aa Bb 0123', { exact: true })).toBeVisible();
        expect(await page.evaluate(family => Array.from(document.fonts).some(face => face.family === family && face.status === 'loaded'), font.css_family)).toBe(true);
        await library.getByLabel('Buscar tipografías', { exact: true }).fill('no existe esta familia');
        await library.getByRole('button', { name: 'Buscar tipografías', exact: true }).click();
        await expect(library.getByText('No hay tipografías con esa búsqueda.', { exact: true })).toBeVisible();
        await library.getByLabel('Buscar tipografías', { exact: true }).fill('Editorial');
        await library.getByRole('button', { name: 'Buscar tipografías', exact: true }).click();
        await library.getByRole('button', { name: `Archivar tipografía ${font.name}`, exact: true }).click();
        await library.getByLabel('Mostrar tipografías archivadas').check();
        await library.getByRole('button', { name: `Restaurar tipografía ${font.name}`, exact: true }).click();
        await library.getByLabel('Mostrar tipografías archivadas').uncheck();
        await expect(library.getByRole('heading', { name: font.name, exact: true })).toBeVisible();
        await library.evaluate(el => el.setAttribute('data-t10-a11y', 'fonts'));
        await audit(page, '[data-t10-a11y="fonts"]', info, 'fonts');
        await page.screenshot({ path: info.outputPath('font-library.png'), fullPage: true });
        await library.getByRole('button', { name: 'Cerrar tipografías de marca', exact: true }).click();
        await page.getByRole('button', { name: /Identidad de marca/ }).click();
        const kit = page.getByRole('dialog', { name: 'Identidad de marca', exact: true });
        await kit.getByLabel('Tipografías', { exact: true }).fill('');
        await kit.getByLabel(`Incluir tipografía ${font.name} en la identidad`, { exact: true }).check();
        await kit.getByRole('button', { name: 'Guardar identidad', exact: true }).click();
        await expect(kit).not.toBeVisible();
        const id = await createDesign(page, brand.id);
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await expect(page.getByRole('combobox', { name: 'Tipografía del texto', exact: true })).toHaveValue(`font:${font.id}`);
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Marca propia: Ñandú 0123');
        await save(page);
        expect((await readDesign(page, id)).document.pages[0].elements[0].font_id).toBe(font.id);
        await page.reload();
        expect((await readDesign(page, id)).fonts.map((entry: {id: number}) => entry.id)).toContain(font.id);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const exporter = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        const download = page.waitForEvent('download');
        await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
        const artifact = await download;
        const output = info.outputPath('private-font-design.png');
        await artifact.saveAs(output);
        expect(fs.readFileSync(output).subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        const fontSnapshot = await readDesign(page, id);
        await fontReference(page, fontSnapshot.document, info, font);
        const actualFontReads = await Promise.all(fontReads.get(page)!);
        expect(actualFontReads.some(entry => entry.sha256 === font.sha256 && entry.bytes === font.size && entry.status === 200)).toBe(true);
        fs.writeFileSync(info.outputPath('font-render-observer.json'), JSON.stringify({ font_id: font.id, woff2_sha256: font.sha256, real_loopback_bytes_observed: true, loaded_faces: await page.evaluate(family => Array.from(document.fonts).filter(f => f.family === family).map(f => ({ family: f.family, status: f.status })), font.css_family) }, null, 2));
        await exporter.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.getByRole('button', { name: 'Tipografías de marca', exact: true }).click();
        const expectedDeletion = isolation.expectHttp(policies.get(page)!, 'DELETE', `/brands/${brand.id}/studio/fonts/${font.id}`, 409);
        const deletion = page.waitForResponse(response => new URL(response.url()).pathname.endsWith(`/studio/fonts/${font.id}`) && response.request().method() === 'DELETE');
        await library.getByRole('button', { name: `Eliminar tipografía ${font.name}`, exact: true }).click();
        expect((await deletion).status()).toBe(409); expectedDeletion.asserted = true;
        await expect(library.getByRole('button', { name: `Eliminar tipografía ${font.name}`, exact: true })).toBeDisabled();
        await expect(library.getByText(/Esta fuente sigue en uso/)).toBeVisible();
    } finally { cleanComposerBrands(user.email); }
});

test('Studio imaging: real background inference preserves original and a masked filtered result persists and exports', async ({ page }, info) => {
    test.setTimeout(100_000);
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-images-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        const id = await createDesign(page, brand.id);
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        const uploaded = page.waitForResponse(response => /\/brands\/\d+\/studio\/assets$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles(path.resolve('tests/Fixtures/images/astronaut.png'));
        const upload = await uploaded;
        expect(upload.status()).toBe(201);
        const original = (await upload.json()).asset;
        await page.getByLabel('Buscar recursos', { exact: true }).fill('astronaut');
        await expect(page.getByRole('button', { name: `Añadir ${original.name}`, exact: true })).toBeVisible();
        const started = page.waitForResponse(response => /\/brands\/\d+\/studio\/image-jobs$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await page.getByRole('button', { name: `Quitar fondo de ${original.name}`, exact: true }).click();
        const start = await started;
        expect(start.status()).toBe(202);
        const job = (await start.json()).job;
        // The runner guards its disposable database; invoke the real worker service without external queues.
        artisan(`app(App\\Services\\Studio\\StudioImages::class)->process(${job.id});`);
        await expect(page.getByRole('button', { name: 'Añadir imagen sin fondo', exact: true })).toBeVisible({ timeout: 20_000 });
        const result = await page.evaluate(async ({ brandId, jobId }) => {
            const response = await fetch(`/brands/${brandId}/studio/image-jobs/${jobId}`, { headers: { Accept: 'application/json' } });
            return (await response.json()).job;
        }, { brandId: brand.id, jobId: job.id });
        expect(result.status).toBe('ready');
        expect(result.model_sha256).toBe('5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333');
        expect(result.output_asset.id).not.toBe(original.id);
        const pixels = await page.evaluate(async ({ output, source }) => {
            const response = await fetch(output);
            const image = await createImageBitmap(await response.blob());
            const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
            const context = canvas.getContext('2d')!; context.drawImage(image, 0, 0);
            const alpha = (x: number, y: number) => context.getImageData(x, y, 1, 1).data[3];
            const original = await fetch(source);
            const hash = await crypto.subtle.digest('SHA-256', await original.arrayBuffer());
            return { width: image.width, height: image.height, background: alpha(15, 15), face: alpha(220, 130),
                originalHash: Array.from(new Uint8Array(hash)).map(value => value.toString(16).padStart(2, '0')).join('') };
        }, { output: result.output_asset.preview_url, source: original.preview_url });
        expect(pixels.width).toBe(512); expect(pixels.height).toBe(512);
        expect(pixels.background).toBeLessThan(30); expect(pixels.face).toBeGreaterThan(220);
        expect(pixels.originalHash).toBe('88431cd9653ccd539741b555fb0a46b61558b301d4110412b5bc28b5e3ea6cb5');
        expect(new URL(result.output_asset.preview_url, page.url()).origin).toBe(new URL(page.url()).origin);
        const png = await page.request.get(result.output_asset.preview_url);
        expect(png.status()).toBe(200);
        fs.writeFileSync(info.outputPath('background-result.png'), await png.body());
        await page.getByRole('button', { name: 'Añadir imagen sin fondo', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByRole('combobox', { name: 'Máscara de imagen', exact: true }).selectOption('ellipse');
        await page.getByLabel('Sepia', { exact: true }).check();
        await page.getByLabel('Recorte X', { exact: true }).fill('10');
        await page.getByLabel('Recorte X', { exact: true }).press('Tab');
        await save(page);
        const saved = (await readDesign(page, id)).document.pages[0].elements[0];
        expect(saved.mask).toEqual({ type: 'ellipse' }); expect(saved.filters.sepia).toBe(true); expect(saved.cropX).toBe(10);
        expect(saved.asset_id).toBe(result.output_asset.id);
        await audit(page, 'section[aria-label="Propiedades del elemento"]', info, 'image-properties');
        await page.reload();
        expect((await readDesign(page, id)).document.pages[0].elements[0]).toEqual(saved);
        fs.writeFileSync(info.outputPath('image-oracle.json'), JSON.stringify({ document: (await readDesign(page, id)).document, original, result, original_fixture_sha256: pixels.originalHash }, null, 2));
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const exporter = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
        const download = page.waitForEvent('download');
        await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
        const artifact = await download;
        await artifact.saveAs(info.outputPath('masked-filtered-design.png'));
        // The downloaded PNG itself carries the mask and the sepia filter: decode it and measure the pixels of the image box.
        const exportedBytes = fs.readFileSync(info.outputPath('masked-filtered-design.png'));
        const doc = (await readDesign(page, id)).document;
        const box = { x: saved.x, y: saved.y, width: saved.width, height: saved.height };
        const exported = await page.evaluate(async ({ encoded, box }) => {
            const image = await createImageBitmap(new Blob([Uint8Array.from(atob(encoded), c => c.charCodeAt(0))], { type: 'image/png' }));
            const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
            const context = canvas.getContext('2d')!; context.drawImage(image, 0, 0);
            const pixel = (x: number, y: number) => Array.from(context.getImageData(Math.round(x), Math.round(y), 1, 1).data);
            const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
            const samples: number[][] = [];
            for (let dx = -12; dx <= 12; dx += 6) for (let dy = -12; dy <= 12; dy += 6) samples.push(pixel(cx + dx, cy + dy));
            return { width: image.width, height: image.height, corner: pixel(box.x + 2, box.y + 2), outside: pixel(box.x - 4, box.y - 4), centre: samples };
        }, { encoded: exportedBytes.toString('base64'), box });
        expect(exported.width).toBe(doc.width); expect(exported.height).toBe(doc.height);
        // Ellipse mask: the bounding-box corner lies outside the ellipse, so it shows the page background, like the point outside the box.
        expect(exported.corner).toEqual(exported.outside);
        // Sepia: every opaque sample of the subject has the warm cast (red >= green >= blue), with a real colour spread.
        const opaque = exported.centre.filter(p => p[3] > 200);
        expect(opaque.length).toBeGreaterThan(0);
        for (const [r, g, b] of opaque) { expect(r).toBeGreaterThanOrEqual(g); expect(g).toBeGreaterThanOrEqual(b); }
        expect(opaque.some(([r, , b]) => r - b > 10)).toBe(true);
        await exporter.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.screenshot({ path: info.outputPath('image-editor.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});
