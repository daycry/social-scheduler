import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { baseURL, environment, runRoot } from './tests/e2e/isolated/runtime';

// Every invocation owns a new SQLite database, auth state and managed loopback server.
export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: ['**/roadmap/**', '**/isolated/**'],
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['json', { outputFile: path.join(runRoot, 'results.json') }]],
    webServer: {
        cwd: path.resolve('.'),
        command: 'node tests/e2e/isolated/serve.cjs',
        url: `${baseURL}/up`, reuseExistingServer: false, timeout: 90_000,
        stdout: 'ignore', stderr: 'ignore',
        env: { E2E_GENERAL_ENV: Buffer.from(JSON.stringify(environment)).toString('base64') },
    },
    use: {
        baseURL,
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
        // Movil estrecho (360 x 740: gama media de Android): mismo recorrido, 0 px de desbordamiento horizontal.
        { name: 'mobile-360', use: { browserName: 'chromium', viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true } },
    ],
    outputDir: path.join(runRoot, 'artifacts'),
});
