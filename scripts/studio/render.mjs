import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const project = fileURLToPath(new URL('../../', import.meta.url));
const output = path.resolve(process.argv[2] ?? '');
// Called only by the server with its unique attempt directory. No file URL or user supplied script is loaded.
if (!path.isAbsolute(process.argv[2] ?? '') || !/[/\\]studio-exports[/\\]\d+[/\\]\d+[/\\][a-f0-9-]+$/i.test(output)) throw new Error('Directorio de exportación no válido.');
const chunks = [];
let size = 0;
for await (const chunk of process.stdin) {
    size += chunk.length;
    if (size > 90_000_000) throw new Error('El trabajo supera el tamaño de entrada permitido.');
    chunks.push(chunk);
}
const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
const origin = 'http://studio-renderer.invalid';
const resources = new Map();
let resourceBytes = 0;
input.assets = (input.assets ?? []).map(asset => {
    const id = Number(asset.id);
    const match = /^data:(image\/(?:png|jpeg|webp)|video\/(?:mp4|quicktime));base64,([A-Za-z0-9+/]*={0,2})$/.exec(asset.preview_url ?? '');
    if (!Number.isInteger(id) || id < 1 || !match || asset.mime !== match[1] || resources.has(`${origin}/assets/${id}`)) throw new Error('Recurso de render no válido.');
    const body = Buffer.from(match[2], 'base64');
    resourceBytes += body.length;
    if (!body.length || resourceBytes > 64_000_000) throw new Error('Los recursos superan el tamaño permitido.');
    const url = `${origin}/assets/${id}`;
    resources.set(url, { body, mime: match[1] });
    return { ...asset, studio_url: url, preview_url: url };
});
const html = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; img-src \'self\' data: blob:; media-src \'self\' data: blob:; font-src data:"></head><body></body></html>';
const browser = await chromium.launch({ headless: true, ...(process.env.STUDIO_CHROMIUM_PATH ? { executablePath: process.env.STUDIO_CHROMIUM_PATH } : {}) });
try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, acceptDownloads: false, serviceWorkers: 'block' });
    // Synthetic origin only: all responses are fulfilled from this closed in-memory map.
    // There is no listening socket and no request ever continues to the network.
    await context.route('**/*', route => {
        const request = route.request();
        if (request.method() !== 'GET') return route.abort();
        if (request.url() === `${origin}/`) return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
        const resource = resources.get(request.url());
        if (!resource) return route.abort();
        const range = request.headers().range;
        if (range) {
            const match = /^bytes=(\d+)-(\d*)$/.exec(range);
            if (!match) return route.fulfill({ status: 416, headers: { 'Content-Range': `bytes */${resource.body.length}` } });
            const start = Number(match[1]), end = match[2] ? Math.min(Number(match[2]), resource.body.length - 1) : resource.body.length - 1;
            if (start > end || start >= resource.body.length) return route.fulfill({ status: 416, headers: { 'Content-Range': `bytes */${resource.body.length}` } });
            return route.fulfill({ status: 206, contentType: resource.mime, body: resource.body.subarray(start, end + 1), headers: { 'Content-Range': `bytes ${start}-${end}/${resource.body.length}`, 'Accept-Ranges': 'bytes' } });
        }
        return route.fulfill({ status: 200, contentType: resource.mime, body: resource.body, headers: { 'Accept-Ranges': 'bytes' } });
    });
    const page = await context.newPage();
    page.setDefaultTimeout(90000);
    await page.goto(`${origin}/`);
    await page.addScriptTag({ path: path.join(project, 'public/build/studio-renderer.js') });
    let deadline;
    const files = await Promise.race([
        page.evaluate(data => globalThis.renderStudioFiles(data), input),
        new Promise((_, reject) => { deadline = setTimeout(() => reject(new Error('El render superó 80 segundos.')), 80000); }),
    ]).finally(() => clearTimeout(deadline));
    await fs.mkdir(output, { recursive: true });
    for (const [index, file] of files.entries()) {
        if (!new RegExp(`^page-${String(index + 1).padStart(3, '0')}\\.(png|jpg|webp|pdf|svg)$`).test(file.name)) throw new Error('Archivo de salida no válido.');
        const bytes = Buffer.from(file.data, 'base64');
        if (bytes.length < 1 || bytes.length > 64_000_000) throw new Error('Tamaño de salida no válido.');
        await fs.writeFile(path.join(output, file.name), bytes, { flag: 'wx' });
    }
    process.stdout.write(JSON.stringify({ files: files.map(({ name, mime }) => ({ name, mime })) }));
} finally {
    await browser.close();
}
