import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { READONLY, artisan, createE2eUsers } from './helpers';
import { verifyRunIdentity } from './isolated/identity';
import { slots, workerCount, type Slot } from './isolated/runtime';

/** Persist an independent readonly session in one slot; security scenarios use three separate disposable users per slot. */
async function prepareSlot(slot: Slot) {
    // Another concurrent run could own this port: confirm the server is ours (and is THIS slot's) before touching anything.
    await verifyRunIdentity(slot.baseURL, slot.runRoot);
    createE2eUsers(slot);
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL: slot.baseURL, locale: 'es-ES' });
        const page = await context.newPage();
        artisan(`$u = App\\Models\\User::factory()->create(['name' => 'e2e Readonly', 'email' => '${READONLY.email}', 'password' => Illuminate\\Support\\Facades\\Hash::make('${READONLY.password}'), 'email_verified_at' => now()]);`, slot.environment);
        artisan(`$u = App\\Models\\User::where('email', '${READONLY.email}')->firstOrFail(); $b = App\\Models\\Brand::factory()->for($u)->create(['name' => 'e2e Readonly fixture', 'timezone' => 'Europe/Madrid']); foreach (['instagram', 'facebook', 'youtube', 'tiktok', 'bluesky', 'threads'] as $network) { App\\Models\\SocialAccount::factory()->for($u)->for($b)->create(['network' => $network, 'external_id' => 'e2e-readonly-'.$network, 'name' => 'e2e Readonly '.ucfirst($network), 'status' => 'activa', 'access_token' => 'e2e-fake-access', 'refresh_token' => 'e2e-fake-refresh', 'token_expires_at' => now()->addDays(60)]); }`, slot.environment);
        // Same form login as helpers.login(), against this slot's origin (the module-level baseURL belongs to slot 0).
        await page.goto('/login');
        await page.getByLabel('Correo electrónico').fill(READONLY.email);
        await page.getByLabel('Contraseña').fill(READONLY.password);
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
        await page.waitForURL(/\/dashboard/);
        fs.mkdirSync(path.dirname(slot.authFile), { recursive: true });
        await context.storageState({ path: slot.authFile });
    } finally {
        await browser.close();
    }
}

/** One server, SQLite and runRoot per worker: every slot the run can use is prepared (the worker proves its own again on start). */
export default async function globalSetup(config: FullConfig) {
    if (config.workers > slots.length) {
        throw new Error(`--workers=${config.workers} supera los servidores E2E (${slots.length}, E2E_WORKERS=${workerCount}): sube E2E_WORKERS en vez de --workers.`);
    }
    for (const slot of slots.slice(0, config.workers)) await prepareSlot(slot);
}
