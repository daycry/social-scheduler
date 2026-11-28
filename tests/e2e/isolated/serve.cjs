const { spawn, spawnSync } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const { createFrontend } = require('./http-server.cjs');

// Playwright merges webServer.env with its parent environment. The PHP processes receive
// only the explicit manifest, so inherited credentials and service settings cannot leak in.
const env = JSON.parse(Buffer.from(process.env.E2E_GENERAL_ENV || '', 'base64').toString('utf8'));
if (env.APP_ENV !== 'e2e' || !/^http:\/\/localhost:\d+$/.test(env.APP_URL || '') || !env.E2E_GENERAL_RUN_ROOT) {
    throw new Error('Missing disposable general E2E environment manifest.');
}
require('./datasets/os-environment.cjs').applyTo(env);
const init = spawnSync('php', ['tests/e2e/isolated/cli.php', 'init'], { env, stdio: 'inherit' });
if (init.error) throw init.error;
if (init.status !== 0) process.exit(init.status ?? 1);
// PHP's development server closes each asset response. A local keep-alive frontend avoids
// hundreds of short-lived browser sockets per page; PHP still handles every application route.
const reserve = net.createServer();
reserve.listen(0, '127.0.0.1', () => {
    const backendPort = reserve.address().port;
    reserve.close(() => {
        const php = spawn('php', ['-S', `127.0.0.1:${backendPort}`, '-t', 'public', 'tests/e2e/isolated/server.php'], { env, stdio: 'inherit', windowsHide: true });
        const frontend = createFrontend(path.resolve('public'), backendPort);
        require('./datasets/download-observer.cjs').attach(frontend, path.join(env.E2E_GENERAL_RUN_ROOT, 'dataset-downloads.jsonl'));
        frontend.listen(Number(new URL(env.APP_URL).port), '127.0.0.1');
        let stopping = false;
        const stop = () => {
            if (stopping) return;
            stopping = true;
            frontend.closeAllConnections(); frontend.close(); php.kill();
        };
        frontend.on('error', error => { console.error(error); process.exitCode = 1; stop(); });
        php.on('error', error => { console.error(error); process.exitCode = 1; stop(); });
        php.on('exit', code => { if (!stopping) { process.exitCode = code ?? 1; stop(); } });
        for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, stop);
    });
});
