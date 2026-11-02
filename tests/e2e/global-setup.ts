import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { AUTH_FILE, READONLY, artisan, createE2eUsers, login } from './helpers';

/** Persist an independent readonly session; security scenarios use three separate disposable users. */
export default async function globalSetup(config: FullConfig) {
    createE2eUsers();
    const baseURL = config.projects[0].use.baseURL as string;
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL, locale: 'es-ES' });
        const page = await context.newPage();
        artisan(`$u = App\\Models\\User::factory()->create(['name' => 'e2e Readonly', 'email' => '${READONLY.email}', 'password' => Illuminate\\Support\\Facades\\Hash::make('${READONLY.password}'), 'email_verified_at' => now()]);`);
        artisan(`$u = App\\Models\\User::where('email', '${READONLY.email}')->firstOrFail(); $b = App\\Models\\Brand::factory()->for($u)->create(['name' => 'e2e Readonly fixture', 'timezone' => 'Europe/Madrid']); foreach (['instagram', 'facebook', 'youtube', 'tiktok', 'bluesky', 'threads'] as $network) { App\\Models\\SocialAccount::factory()->for($u)->for($b)->create(['network' => $network, 'external_id' => 'e2e-readonly-'.$network, 'name' => 'e2e Readonly '.ucfirst($network), 'status' => 'activa', 'access_token' => 'e2e-fake-access', 'refresh_token' => 'e2e-fake-refresh', 'token_expires_at' => now()->addDays(60)]); }`);
        await login(page);
        fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
        await context.storageState({ path: AUTH_FILE });
    } finally {
        await browser.close();
    }
}
