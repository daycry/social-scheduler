const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createFrontend } = require('./http-server.cjs');
const listen = server => new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
const close = server => new Promise(resolve => { server.closeAllConnections(); server.close(resolve); });

test('loopback frontend reuses asset sockets and preserves application host, cookies and streamed body', async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'metricool-e2e-http-'));
    fs.writeFileSync(path.join(directory, 'bundle.js'), 'export const ready = true;');
    const backend = http.createServer((request, response) => {
        let body = '';
        request.on('data', chunk => { body += chunk; });
        request.on('end', () => { response.writeHead(201, { 'Set-Cookie': 'session=disposable; HttpOnly', Connection: 'close' }); response.end(JSON.stringify({ host: request.headers.host, body })); });
    });
    const backendPort = await listen(backend);
    const frontend = createFrontend(directory, backendPort);
    let connections = 0;
    frontend.on('connection', () => { connections++; });
    const port = await listen(frontend);
    const agent = new http.Agent({ keepAlive: true, maxSockets: 1 });
    const request = (url, body) => new Promise((resolve, reject) => {
        const call = http.request({ hostname: '127.0.0.1', port, path: url, method: body ? 'POST' : 'GET', agent, headers: { Host: 'localhost:8080' } }, response => {
            let data = '';
            response.on('data', chunk => { data += chunk; });
            response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, data }));
        });
        call.on('error', reject); call.end(body);
    });
    try {
        for (let i = 0; i < 20; i++) {
            const asset = await request('/bundle.js');
            assert.equal(asset.status, 200); assert.equal(asset.data, 'export const ready = true;');
            assert.notEqual(asset.headers.connection, 'close');
        }
        assert.equal(connections, 1, 'Twenty prefetches must not create twenty browser TCP sockets');
        const application = await request('/studio/designs/1', 'design-content');
        assert.equal(application.status, 201);
        assert.deepEqual(JSON.parse(application.data), { host: 'localhost:8080', body: 'design-content' });
        assert.equal(application.headers['set-cookie'][0], 'session=disposable; HttpOnly');
        assert.equal(connections, 1);
    } finally {
        agent.destroy(); await close(frontend); await close(backend);
        // Only the generated file and its own now-empty test directory are removed.
        fs.unlinkSync(path.join(directory, 'bundle.js')); fs.rmdirSync(directory);
    }
});

test('an interrupted backend response aborts the browser response instead of hanging or succeeding with partial bytes', async () => {
    const backend = http.createServer((request, response) => {
        response.writeHead(200); response.write('partial');
        setTimeout(() => response.destroy(), 10);
    });
    const backendPort = await listen(backend);
    const frontend = createFrontend(os.tmpdir(), backendPort);
    const port = await listen(frontend);
    try {
        const result = await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => resolve('hanging'), 400);
            const done = value => { clearTimeout(timeout); resolve(value); };
            const call = http.get({ hostname: '127.0.0.1', port, path: '/backend-cut', agent: false }, response => {
                response.resume();
                response.on('aborted', () => done('aborted'));
                response.on('error', () => done('aborted'));
                response.on('end', () => done('ended'));
            });
            call.on('error', reject);
        });
        assert.equal(result, 'aborted');
    } finally { await close(frontend); await close(backend); }
});
