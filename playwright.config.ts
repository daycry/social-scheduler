import { defineConfig } from '@playwright/test';
import dotenv from 'dotenv';

// Credenciales del usuario demo (DEMO_USER_*) del .env; nunca se versionan ni se imprimen.
dotenv.config({ path: '.env' });

/**
 * Batería E2E contra una app ya en marcha (php artisan serve + npm run build).
 *   E2E_BASE_URL=http://127.0.0.1:8080 npm run test:e2e
 */
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080',
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
    outputDir: 'test-results',
});
