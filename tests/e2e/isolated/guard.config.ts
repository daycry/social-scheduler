import { defineConfig } from '@playwright/test';
import path from 'node:path';
import general from '../../../playwright.config';
import { runRoot } from './runtime';

export default defineConfig({
    ...general, testDir: '.', testMatch: '**/*.guard.ts', testIgnore: [],
    workers: 1, webServer: (general.webServer as any[]).slice(0, 1), // the guards use slot 0 only
    globalSetup: undefined, globalTeardown: undefined,
    reporter: [['list'], ['json', { outputFile: path.join(runRoot, 'guards.json') }]],
    projects: [{ name: 'desktop', use: { browserName: 'chromium' } }],
});
