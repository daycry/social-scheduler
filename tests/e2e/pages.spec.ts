import { expect, test, type Page } from '@playwright/test';
import { AUTH_FILE, AUTH_PAGES, expectNoOverflow, PUBLIC_PAGES, settle, watch } from './helpers';

/** (a) Recorrido de todas las páginas: status < 400, sin errores de consola, sin desbordamiento y con un h1. */
async function check(page: Page, path: string) {
    const w = watch(page);
    const res = await page.goto(path);
    expect(res?.status(), `status de ${path}`).toBeLessThan(400);
    await settle(page);
    await expect(page.locator('h1').first(), `h1 en ${path}`).toBeVisible();
    await expectNoOverflow(page);
    expect(w.errors, `errores de consola en ${path}`).toEqual([]);
}

test.describe('páginas públicas', () => {
    for (const [name, path] of PUBLIC_PAGES) {
        test(name, async ({ page }) => check(page, path));
    }
});

test.describe('páginas autenticadas', () => {
    test.use({ storageState: AUTH_FILE });

    for (const [name, path] of AUTH_PAGES) {
        test(name, async ({ page }) => check(page, path));
    }

    test('conexiones de la marca, crear publicación y editar marca', async ({ page }) => {
        await page.goto('/brands');
        const href = await page.locator('a[href*="/connections"]').first().getAttribute('href');
        expect(href).toBeTruthy();
        const brandId = href!.match(/brands\/(\d+)\/connections/)![1];
        await check(page, `/brands/${brandId}/connections`);
        await check(page, `/brands/${brandId}/posts/create`);
        await check(page, `/brands/${brandId}/edit`);
    });

    test('el shell enlaza los módulos nuevos', async ({ page }, info) => {
        test.skip(info.project.name.startsWith('mobile'), 'La barra lateral de escritorio no existe en móvil');
        await page.goto('/calendar');
        const side = page.getByRole('complementary', { name: 'Navegación del módulo' });
        for (const label of ['Calendario', 'Lista', 'Crear publicación', 'Mejores horas', 'Biblioteca', 'Autolistas', 'Papelera']) {
            await expect(side.getByRole('link', { name: label, exact: true }), label).toBeVisible();
        }
        await page.goto('/dashboard');
        await expect(page.getByRole('complementary', { name: 'Navegación del módulo' }).getByRole('link', { name: 'Competidores' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Módulos' }).getByRole('link', { name: 'SmartLinks' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Módulos' }).getByRole('link', { name: /^Inbox/ })).toBeVisible();
        await expect(page.getByRole('complementary', { name: 'Navegación del módulo' }).getByRole('link', { name: 'Meta Ads' })).toBeVisible();
    });
});
