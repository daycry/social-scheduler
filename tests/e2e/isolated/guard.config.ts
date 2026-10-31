import { defineConfig } from '@playwright/test';
import path from 'node:path';
import general from '../../../playwright.config';
import { runRoot } from './runtime';

export default defineConfig({
    ...general, testDir: '.', testMatch: '**/*.guard.ts', testIgnore: [],
    globalSetup: undefined, globalTeardown: undefined,
    reporter: [['list'], ['json', { outputFile: path.join(runRoot, 'guards.json') }]],
    projects: [{ name: 'desktop', use: { browserName: 'chromium' } }],
});
