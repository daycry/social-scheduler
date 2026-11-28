import { defineConfig } from '@playwright/test';
import path from 'node:path';

// This suite owns an entirely separate database and never loads the demo session.
const root = path.resolve(process.env.T43_RUN_ROOT ?? `storage/framework/testing/t43/run-${Date.now()}-${process.pid}`);
// T-43 keeps 8081 by default (so it can coexist with the general battery); E2E_ROADMAP_PORT overrides it.
const port = Number(process.env.E2E_ROADMAP_PORT || 8081);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('E2E_ROADMAP_PORT must be an integer between 1024 and 65535.');
const baseURL = `http://localhost:${port}`;
Object.assign(process.env, {
    T43_RUN_ROOT: root,
    APP_ENV: 'e2e',
    APP_NAME: 'Social Scheduler T43', APP_LOCALE: 'es', APP_FALLBACK_LOCALE: 'es',
    APP_URL: baseURL,
    APP_KEY: `base64:${Buffer.alloc(32, 43).toString('base64')}`,
    APP_CONFIG_CACHE: path.join(root, 'config.php'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(root, 'db.sqlite'), DB_URL: '',
    CACHE_STORE: 'file', CACHE_LIMITER_STORE: 'file', SESSION_DRIVER: 'database', SESSION_CONNECTION: 'sqlite',
    QUEUE_CONNECTION: 'database', DB_QUEUE_CONNECTION: 'sqlite',
    SESSION_SECURE_COOKIE: 'false', MAIL_MAILER: 'array', MEDIA_DISK: 'local',
    SOCIAL_BACKFILL_ON_CONNECT: 'false', TRUSTED_PROXIES: '',
    GOOGLE_CLIENT_ID: 't43-google', GOOGLE_CLIENT_SECRET: 't43-not-a-secret',
    META_APP_ID: 't43-meta', META_APP_SECRET: 't43-not-a-secret',
    TIKTOK_CLIENT_KEY: 't43-tiktok', TIKTOK_CLIENT_SECRET: 't43-not-a-secret',
    VIEW_COMPILED_PATH: path.join(root, 'views'),
});

export default defineConfig({
    testDir: './tests/e2e/roadmap', testMatch: '**/*.spec.ts',
    timeout: 90_000, expect: { timeout: 15_000 },
    workers: 1, fullyParallel: false, retries: 0,
    reporter: [['list'], ['json', { outputFile: path.join(root, 'results.json') }]],
    outputDir: path.join(root, 'artifacts'),
    webServer: {
        command: `php tests/e2e/roadmap/cli.php init && php -S localhost:${port} -t public tests/e2e/roadmap/server.php`,
        url: `${baseURL}/up`, reuseExistingServer: false, timeout: 90_000,
        // Request access logs are noisy; application exceptions remain in <run>/logs/laravel.log.
        stdout: 'ignore', stderr: 'ignore',
        env: Object.fromEntries(Object.entries(process.env).filter((entry): entry is [string, string] => entry[1] !== undefined)),
    },
    use: { baseURL, locale: 'es-ES', timezoneId: 'Europe/Madrid', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
        { name: 'mobile-360', use: { browserName: 'chromium', viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true } },
    ],
});
