const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');

test('the general config runs one server per worker and only @responsive on mobile projects', () => {
    const config = fs.readFileSync('playwright.config.ts', 'utf8');
    assert.match(config, /workers: workerCount/, 'workers must come from E2E_WORKERS');
    assert.match(config, /webServer: slots\.map/, 'one managed server per worker slot');
    assert.match(config, /const RESPONSIVE = \/@responsive\//);
    for (const name of ['mobile', 'mobile-360']) assert.match(config, new RegExp(`name: '${name}', grep: RESPONSIVE`), `${name} must filter by @responsive`);
    assert.doesNotMatch(config, /name: 'desktop', grep/, 'desktop runs everything');
});

test('every worker checks its own server identity and the setup refuses more workers than servers', () => {
    const runtime = fs.readFileSync('tests/e2e/isolated/runtime.ts', 'utf8');
    assert.match(runtime, /TEST_PARALLEL_INDEX/);
    assert.match(runtime, /if \(isWorker\) verifyRunIdentitySync\(baseURL, runRoot\)/);
    const setup = fs.readFileSync('tests/e2e/global-setup.ts', 'utf8');
    assert.match(setup, /config\.workers > slots\.length/);
    assert.match(fs.readFileSync('tests/e2e/global-teardown.ts', 'utf8'), /\.\.\.slots\.map\(slot => slot\.runRoot\)/, 'the purge must protect every slot root');
});

test('--list: mobile projects list only @responsive tests, a subset of the desktop ones', () => {
    const list = project => {
        const run = spawnSync('npx', ['playwright', 'test', '--list', `--project=${project}`, '--reporter=json'], { encoding: 'utf8', shell: true, maxBuffer: 64 * 1024 * 1024, env: { ...process.env, E2E_WORKERS: '2' } });
        assert.equal(run.status, 0, run.stderr);
        const tests = [];
        const walk = suite => { for (const spec of suite.specs ?? []) tests.push(spec); for (const child of suite.suites ?? []) walk(child); };
        for (const suite of JSON.parse(run.stdout).suites) walk(suite);
        return tests;
    };
    const desktop = list('desktop');
    for (const project of ['mobile', 'mobile-360']) {
        const mobile = list(project);
        assert.ok(mobile.length > 0 && mobile.length < desktop.length, `${project}: ${mobile.length} vs desktop ${desktop.length}`);
        for (const spec of mobile) assert.ok((spec.tags ?? []).includes('responsive'), `${project}: ${spec.title} is not tagged @responsive`);
    }
});
