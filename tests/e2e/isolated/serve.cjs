const { spawn, spawnSync } = require('node:child_process');

// Playwright merges webServer.env with its parent environment. The PHP processes receive
// only the explicit manifest, so inherited credentials and service settings cannot leak in.
const env = JSON.parse(Buffer.from(process.env.E2E_GENERAL_ENV || '', 'base64').toString('utf8'));
if (env.APP_ENV !== 'e2e' || env.APP_URL !== 'http://localhost:8080' || !env.E2E_GENERAL_RUN_ROOT) {
    throw new Error('Missing disposable general E2E environment manifest.');
}
const init = spawnSync('php', ['tests/e2e/isolated/cli.php', 'init'], { env, stdio: 'inherit' });
if (init.error) throw init.error;
if (init.status !== 0) process.exit(init.status ?? 1);
const server = spawn('php', ['-S', 'localhost:8080', '-t', 'public', 'tests/e2e/isolated/server.php'], { env, stdio: 'inherit' });
server.on('error', (error) => { console.error(error); process.exitCode = 1; });
server.on('exit', (code) => { process.exitCode = code ?? 1; });
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => server.kill(signal));
