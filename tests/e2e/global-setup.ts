import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { AUTH_FILE, borrarMarcasE2e, login } from './helpers';

/** Inicia sesión una vez con el usuario demo y guarda la sesión para los specs autenticados. */
export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL as string;
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL, locale: 'es-ES' });
    const page = await context.newPage();
    await login(page);
    await borrarMarcasE2e(page); // restos de ejecuciones anteriores
    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
    await context.storageState({ path: AUTH_FILE });
    await browser.close();
}
