import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { baseURL, runRoot, slots, workerCount } from './tests/e2e/isolated/runtime';

const RESPONSIVE = /@responsive/;

// Every invocation owns, PER WORKER, a new SQLite database, auth state, runRoot and managed loopback server (see isolated/runtime.ts).
// Workers: E2E_WORKERS (default 2, 1-16; each one is a php -S + Node frontend + Chromium + SQLite). Files run in parallel across
// workers; the tests of one file stay in order inside one worker (the 23 `serial` specs included). `--shard=i/n` splits files for CI.
// Mobile projects only run what is tagged @responsive (layout, overflow, mobile navigation); the rest is desktop-only.
export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: ['**/roadmap/**', '**/isolated/**'],
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: workerCount,
    retries: 0,
    reporter: [['list'], ['json', { outputFile: path.join(runRoot, 'results.json') }]],
    // One managed server per worker slot; all of them are started before the first test.
    webServer: slots.map(slot => ({
        cwd: path.resolve('.'),
        command: 'node tests/e2e/isolated/serve.cjs',
        url: `${slot.baseURL}/up`, reuseExistingServer: false, timeout: 90_000,
        stdout: 'ignore' as const, stderr: 'ignore' as const,
        env: { E2E_GENERAL_ENV: Buffer.from(JSON.stringify(slot.environment)).toString('base64') },
    })),
    use: {
        baseURL,
        // Match the disposable PHP listener without changing origin/RP ID or trusting another host.
        launchOptions: { args: ['--host-resolver-rules=MAP localhost 127.0.0.1'] },
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', grep: RESPONSIVE, use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
        // Movil estrecho (360 x 740: gama media de Android): mismo recorrido (solo @responsive), 0 px de desbordamiento horizontal.
        { name: 'mobile-360', grep: RESPONSIVE, use: { browserName: 'chromium', viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true } },
    ],
    outputDir: path.join(runRoot, 'artifacts'),
});
