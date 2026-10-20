import { expect, type Page } from '@playwright/test';

export const DEMO = {
    email: process.env.DEMO_USER_EMAIL || 'demo@social-scheduler.test',
    password: process.env.DEMO_USER_PASSWORD || '',
};

export const AUTH_FILE = 'tests/e2e/.auth/demo.json';

/** Páginas públicas (sin sesión). */
export const PUBLIC_PAGES: Array<[string, string]> = [
    ['portada', '/'],
    ['login', '/login'],
    ['registro', '/register'],
    ['recuperar contraseña', '/forgot-password'],
    ['privacidad', '/privacy'],
    ['términos', '/terms'],
];

/** Páginas autenticadas del producto. */
export const AUTH_PAGES: Array<[string, string]> = [
    ['dashboard', '/dashboard'],
    ['dashboard instagram', '/dashboard/instagram'],
    ['dashboard facebook', '/dashboard/facebook'],
    ['dashboard youtube', '/dashboard/youtube'],
    ['dashboard tiktok', '/dashboard/tiktok'],
    ['calendario', '/calendar'],
    ['calendario lista', '/calendar?view=list'],
    ['calendario mes', '/calendar?view=month'],
    ['marcas', '/brands'],
    ['nueva marca', '/brands/create'],
    ['informes', '/reports'],
    ['api-keys', '/api-keys'],
    ['avisos', '/notifications'],
    ['perfil', '/profile'],
    ['mejores horas', '/best-times'],
    ['biblioteca', '/library'],
    ['papelera', '/trash'],
    ['smartlinks', '/smartlinks'],
    ['competidores', '/competitors'],
    ['autolistas', '/autolists'],
    ['inbox', '/inbox'],
    ['meta ads', '/ads'],
];

export type Watch = { errors: string[] };

/** Escucha errores de consola y excepciones de la página; devuelve la lista viva. */
export function watch(page: Page): Watch {
    const w: Watch = { errors: [] };
    page.on('console', (m) => {
        if (m.type() === 'error') w.errors.push(`console: ${m.text()}`);
    });
    page.on('pageerror', (e) => w.errors.push(`pageerror: ${e.message}`));
    return w;
}

/** Desbordamiento horizontal: el documento no debe ser más ancho que la ventana. */
export async function overflowX(page: Page): Promise<number> {
    return page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth);
}

export async function expectNoOverflow(page: Page) {
    expect(await overflowX(page), 'desbordamiento horizontal (px)').toBeLessThanOrEqual(0);
}

export async function login(page: Page, email = DEMO.email, password = DEMO.password) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.waitForURL(/\/dashboard/);
}

/** Espera a que la red quede en reposo (tras un router.post de Inertia). */
export async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
}

export const stamp = () => Date.now().toString(36);

/** Borra las marcas cuyo nombre contiene `prefix` (por defecto todas las `e2e-`); con ellas se van sus datos. */
export async function borrarMarcasE2e(page: Page, prefix = 'e2e-') {
    page.on('dialog', (d) => d.accept().catch(() => {}));
    for (let i = 0; i < 20; i++) {
        await page.goto('/brands');
        const row = page.getByRole('main').locator('li', { hasText: prefix }).first();
        if (!(await row.count())) return;
        await row.getByRole('button', { name: 'Eliminar' }).click();
        await settle(page);
    }
}
