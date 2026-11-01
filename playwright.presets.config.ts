import { defineConfig } from '@playwright/test';
import audio from './playwright.audio.config';

// Same disposable server/fake providers; exercise the restyled sections and their existing interactions.
export default defineConfig({
    ...audio,
    testMatch: ['composer-presets.spec.ts', 'composer.spec.ts', 'instagram-audio.spec.ts', 'tiktok-audio.spec.ts'],
    grep: /Presets:|Audio Reel:|TikTok |E2E-04|E2E-09|E2E-10/,
});
