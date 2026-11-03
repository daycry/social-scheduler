const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const types = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.map': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.txt': 'text/plain; charset=utf-8' };

/** Test-only loopback frontend: keep static asset connections alive, stream application requests to owned PHP. */
function createFrontend(publicDirectory, backendPort) {
    const root = fs.realpathSync(publicDirectory);
    return http.createServer((request, response) => {
        if (!['127.0.0.1', '::1'].includes(request.socket.remoteAddress)) {
            response.writeHead(403); response.end(); return;
        }
        let file, pathname;
        try {
            pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
            const candidate = path.resolve(root, `.${pathname}`);
            if (candidate.startsWith(root + path.sep) && !pathname.split('/').some(part => part.startsWith('.'))) {
                const resolved = fs.realpathSync(candidate);
                if (resolved.startsWith(root + path.sep) && types[path.extname(resolved).toLowerCase()] && fs.statSync(resolved).isFile()) file = resolved;
            }
        } catch { /* A route or missing file belongs to Laravel. */ }
        if (file && ['GET', 'HEAD'].includes(request.method)) {
            response.writeHead(200, { 'Content-Type': types[path.extname(file).toLowerCase()], 'Content-Length': fs.statSync(file).size,
                'X-Content-Type-Options': 'nosniff', 'Cache-Control': pathname.startsWith('/build/assets/') ? 'public, max-age=31536000, immutable' : 'no-cache' });
            if (request.method === 'HEAD') { response.end(); return; }
            const stream = fs.createReadStream(file);
            stream.on('error', () => response.destroy());
            stream.pipe(response);
            return;
        }
        const upstream = http.request({ hostname: '127.0.0.1', port: backendPort, method: request.method, path: request.url,
            headers: { ...request.headers, connection: 'close' } }, incoming => {
            // PHP's close applies to its upstream socket, not to the browser's independent frontend connection.
            const headers = { ...incoming.headers };
            delete headers.connection;
            delete headers['keep-alive'];
            response.writeHead(incoming.statusCode, headers);
            incoming.on('aborted', () => response.destroy());
            incoming.on('error', () => response.destroy());
            incoming.pipe(response);
        });
        upstream.on('error', () => {
            if (response.headersSent) { response.destroy(); return; }
            response.writeHead(502); response.end();
        });
        request.on('aborted', () => upstream.destroy());
        response.on('close', () => { if (!response.writableFinished) upstream.destroy(); });
        request.pipe(upstream);
    });
}

module.exports = { createFrontend };
