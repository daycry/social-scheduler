import { chromium, type FullConfig } from '@playwright/test';
import { AUTH_FILE, borrarMarcasE2e, deleteE2eUsers } from './helpers';

/** Al terminar la batería borra los usuarios E2E de seguridad y las marcas `e2e-` que hayan quedado (con ellas se van sus datos), pase o falle. */
export default async function globalTeardown(config: FullConfig) {
    try {
        deleteE2eUsers(); // usuarios `e2e-sec-*` (con sus passkeys y datos)
    } catch (error) {
        console.warn('globalTeardown: no se pudo borrar los usuarios e2e-sec-', error);
    }
    const baseURL = config.projects[0].use.baseURL as string;
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL, locale: 'es-ES', storageState: AUTH_FILE });
        const page = await context.newPage();
        await borrarMarcasE2e(page);
    } catch (error) {
        console.warn('globalTeardown: no se pudo limpiar los datos e2e-', error);
    } finally {
        await browser.close();
    }
}
