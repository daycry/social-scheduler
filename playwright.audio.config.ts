import { defineConfig } from '@playwright/test';
import base from './playwright.config';
import { environment } from './tests/e2e/isolated/runtime';

// This focused suite enables photos only against the existing disposable server and fake providers.
environment.TIKTOK_VERIFIED_DOMAIN = 'https://media.e2e.invalid';
const webServer = base.webServer;
if (!webServer || Array.isArray(webServer)) throw new Error('Audio E2E requires the single disposable general server.');

export default defineConfig({
    ...base,
    testMatch: ['instagram-audio.spec.ts', 'tiktok-audio.spec.ts'],
    webServer: {
        ...webServer,
        env: { E2E_GENERAL_ENV: Buffer.from(JSON.stringify(environment)).toString('base64') },
    },
});
