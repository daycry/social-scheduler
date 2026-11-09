import fs from 'node:fs/promises';
import { createReadStream } from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { createHash, randomBytes } from 'node:crypto';
import { sourceFile } from './av-files.mjs';

/** A token-scoped loopback origin streams private files with Range/backpressure; no giant JSON assets. */
export async function openResourceServer(directory, assets = [], fonts = []) {
    const token = randomBytes(32).toString('hex'), resources = new Map(), ids = new Set();
    let bytes = 0;
    const media = [];
    for (const asset of assets) {
        if (ids.has(asset.id) || !['image/png', 'image/jpeg', 'image/webp', 'video/mp4', 'video/quicktime', 'audio/wav', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/aac'].includes(asset.mime)) throw new Error('Recurso duplicado o formato no permitido.');
        ids.add(asset.id);
        const file = await sourceFile(directory, asset), size = (await fs.stat(file)).size;
        bytes += size;
        if (bytes > 8 * 1024 ** 3) throw new Error('Los snapshots superan 8 GiB por intento.');
        resources.set(`/${token}/assets/${asset.id}`, { file, size, mime: asset.mime });
        media.push({ ...asset, file: undefined, studio_url: `/${token}/assets/${asset.id}`, preview_url: `/${token}/assets/${asset.id}` });
    }
    const fontData = [], fontIds = new Set();
    for (const font of fonts) {
        if (!Number.isSafeInteger(font.id) || font.id < 1 || fontIds.has(font.id) || font.file !== `fonts/${font.id}.woff2`
            || font.mime !== 'font/woff2' || !/^[a-f0-9]{64}$/i.test(font.sha256 ?? '') || !Number.isSafeInteger(font.size) || font.size < 1 || font.size > 8 * 1024 ** 2) throw new Error('Fuente privada inválida.');
        fontIds.add(font.id);
        const file = path.join(directory, font.file), real = await fs.realpath(file);
        if (process.platform === 'win32' ? real.toLowerCase() !== file.toLowerCase() : real !== file) throw new Error('La fuente no admite enlaces externos.');
        const stat = await fs.stat(file);
        if (!stat.isFile() || stat.size !== font.size) throw new Error('La fuente cambió de tamaño.');
        const content = await fs.readFile(file);
        if (content.subarray(0, 4).toString() !== 'wOF2' || createHash('sha256').update(content).digest('hex') !== font.sha256.toLowerCase()) throw new Error('El hash de la fuente no coincide.');
        resources.set(`/${token}/fonts/${font.id}`, { file, size: stat.size, mime: 'font/woff2' });
        fontData.push({ ...font, file: undefined, file_url: `/${token}/fonts/${font.id}` });
    }
    const home = `/${token}/`, html = '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;padding:0;overflow:hidden}canvas{display:block}</style></head><body></body></html>';
    const server = http.createServer(async (request, response) => {
        response.setHeader('Cache-Control', 'no-store'); response.setHeader('X-Content-Type-Options', 'nosniff');
        if (!['GET', 'HEAD'].includes(request.method)) { response.writeHead(405); response.end(); return; }
        if (request.url === home) {
            response.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self' data:");
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); response.end(request.method === 'HEAD' ? undefined : html); return;
        }
        const resource = resources.get(request.url);
        if (!resource) { response.writeHead(404); response.end(); return; }
        let start = 0, end = resource.size - 1, status = 200;
        if (request.headers.range) {
            const range = /^bytes=(\d+)-(\d*)$/.exec(request.headers.range);
            if (!range || !Number.isSafeInteger(Number(range[1])) || !Number.isSafeInteger(range[2] ? Number(range[2]) : end)) {
                response.writeHead(416, { 'Content-Range': `bytes */${resource.size}` }); response.end(); return;
            }
            start = Number(range[1]); end = range[2] ? Math.min(Number(range[2]), end) : end;
            if (start > end || start >= resource.size) { response.writeHead(416, { 'Content-Range': `bytes */${resource.size}` }); response.end(); return; }
            status = 206; response.setHeader('Content-Range', `bytes ${start}-${end}/${resource.size}`);
        }
        response.writeHead(status, { 'Content-Type': resource.mime, 'Content-Length': end - start + 1, 'Accept-Ranges': 'bytes' });
        if (request.method === 'HEAD') { response.end(); return; }
        const stream = createReadStream(resource.file, { start, end, highWaterMark: 64 * 1024 });
        stream.on('error', () => response.destroy()); response.on('close', () => stream.destroy()); stream.pipe(response);
    });
    server.headersTimeout = 10000; server.requestTimeout = 20000;
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const origin = `http://127.0.0.1:${server.address().port}`;
    return { url: origin + home, assets: media.map(asset => ({ ...asset, studio_url: origin + asset.studio_url, preview_url: origin + asset.preview_url })),
        fonts: fontData.map(font => ({ ...font, file_url: origin + font.file_url })),
        allows: url => url === origin + home || url.startsWith(origin) && resources.has(url.slice(origin.length)),
        async close() { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } };
}
