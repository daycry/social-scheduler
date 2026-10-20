import { expect, test, type Page } from '@playwright/test';
import { AUTH_FILE, borrarMarcasE2e, DEMO, expectNoOverflow, settle, stamp, watch } from './helpers';

/**
 * (b) Flujos de usuario de punta a punta. Todo lo que se crea lleva el prefijo `e2e-` y se limpia al terminar.
 * Corre secuencialmente (workers: 1): los flujos de un mismo proyecto comparten la marca `e2e-…`.
 */

const aceptarDialogos = (page: Page) => page.on('dialog', (d) => d.accept());

test('registro: crea la cuenta y pide verificar el correo', async ({ page }, info) => {
    const w = watch(page);
    const email = `e2e-${stamp()}-${info.project.name}@example.test`;
    const password = `E2e-${stamp()}-Pass1!`;

    await page.goto('/register');
    await page.getByLabel('Nombre', { exact: true }).fill('e2e Registro');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña', { exact: true }).fill(password);
    await page.getByLabel('Confirmar contraseña').fill(password);
    await page.getByRole('button', { name: /Crear cuenta|Registrarme|Registrar/ }).click();

    await page.waitForURL(/verify-email/);
    await expect(page.getByRole('heading', { level: 1, name: 'Verifica tu correo' })).toBeVisible();
    await expect(page.getByText('verifica tu correo electrónico')).toBeVisible();
    await expectNoOverflow(page);

    // Sin verificar no se entra en el panel.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/verify-email/);

    // Limpieza: borrar la cuenta recién creada.
    await page.goto('/profile');
    await page.getByRole('button', { name: 'Eliminar cuenta' }).click();
    await page.getByLabel('Contraseña').last().fill(password);
    await page.getByRole('button', { name: 'Eliminar cuenta' }).last().click();
    await page.waitForURL((u) => u.pathname === '/');
    expect(w.errors).toEqual([]);
});

test.describe('con sesión demo', () => {
    test.use({ storageState: AUTH_FILE });
    test.skip(!DEMO.password, 'Falta DEMO_USER_PASSWORD en .env');

    test('marca → borrador → calendario/lista → papelera → restaurar → biblioteca', async ({ page }, info) => {
        const w = watch(page);
        aceptarDialogos(page);
        const brand = `e2e-marca-${stamp()}-${info.project.name}`;
        const text = `e2e-borrador-${stamp()}`;

        try {
            // Crear marca
            await page.goto('/brands/create');
            await page.getByLabel('Nombre', { exact: true }).fill(brand);
            await page.getByLabel('Zona horaria').fill('Europe/Madrid');
            await page.getByRole('button', { name: 'Guardar' }).click();
            await page.waitForURL((u) => !u.pathname.endsWith('/create'));
            await page.goto('/brands');
            await expect(page.getByRole('main').getByRole('link', { name: brand })).toBeVisible();
            const brandId = (await page.getByRole('main').getByRole('link', { name: brand }).getAttribute('href'))!.match(/brands\/(\d+)/)![1];

            // Crear borrador (sin cuentas conectadas basta con texto)
            await page.goto(`/brands/${brandId}/posts/create`);
            await page.getByLabel('Texto').fill(text);
            await page.getByRole('button', { name: 'Guardar borrador' }).click();
            await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();

            // Verlo en el calendario (lista)
            await page.goto(`/calendar?view=list&brand_id=${brandId}`);
            await expect(page.getByRole('link', { name: text })).toBeVisible();
            await expectNoOverflow(page);

            // Guardar en biblioteca desde la lista del calendario
            await page.getByRole('button', { name: /Guardar la publicación \d+ en la biblioteca/ }).click();
            await page.waitForURL(/\/library/);
            await expect(page.getByText('Guardado en la biblioteca.')).toBeVisible();
            await expect(page.getByText(text).first()).toBeVisible();
            await expectNoOverflow(page);

            // Enviarlo a la papelera desde el editor
            await page.goto(`/calendar?view=list&brand_id=${brandId}`);
            await page.getByRole('link', { name: text }).click();
            await page.getByRole('button', { name: 'Eliminar' }).click();
            await page.waitForURL((u) => !u.pathname.includes('/posts/'));
            await page.goto('/trash');
            await expect(page.getByText(text)).toBeVisible();
            await expectNoOverflow(page);

            // Restaurar
            await page.getByText(text).locator('xpath=ancestor::li').getByRole('button', { name: 'Restaurar' }).click();
            await settle(page);
            await expect(page.getByText(text)).toHaveCount(0);
            await page.goto(`/calendar?view=list&brand_id=${brandId}`);
            await expect(page.getByRole('link', { name: text })).toBeVisible();

            // «Usar» una plantilla crea un borrador y abre el editor
            await page.goto(`/library?brand_id=${brandId}`);
            await page.getByRole('button', { name: 'Usar' }).first().click();
            await expect(page.getByRole('heading', { level: 1, name: /Editar publicación/ })).toBeVisible();
        } finally {
            await limpiar(page, brand);
        }
        expect(w.errors).toEqual([]);
    });

    test('SmartLink: crear con un bloque, abrir sin sesión y el clic redirige', async ({ page, browser, baseURL }, info) => {
        const w = watch(page);
        aceptarDialogos(page);
        const brand = `e2e-sl-${stamp()}-${info.project.name}`;
        const slug = `e2e-${stamp()}${info.project.name === 'desktop' ? 'd' : info.project.name === 'mobile' ? 'm' : 'n'}`;

        try {
            await page.goto('/brands/create');
            await page.getByLabel('Nombre', { exact: true }).fill(brand);
            await page.getByLabel('Zona horaria').fill('Europe/Madrid');
            await page.getByRole('button', { name: 'Guardar' }).click();
            await page.waitForURL((u) => !u.pathname.endsWith('/create'));
            await page.goto('/brands');
            const brandId = (await page.getByRole('main').getByRole('link', { name: brand }).getAttribute('href'))!.match(/brands\/(\d+)/)![1];

            await page.goto(`/smartlinks?brand_id=${brandId}`);
            await page.getByLabel('Dirección de tu página').fill(slug);
            await page.getByLabel('Título').fill('e2e Mi página');
            await page.getByRole('button', { name: 'Añadir enlace' }).click();
            await page.getByLabel('Texto del bloque 1').fill('e2e Mi web');
            await page.getByLabel('URL del bloque 1').fill('https://example.com/e2e');
            await page.getByRole('button', { name: 'Guardar', exact: true }).click();
            await expect(page.getByText(`/l/${slug}`).first()).toBeVisible();
            await expectNoOverflow(page);

            // Sin sesión ni cookies.
            const anon = await browser.newContext({ baseURL, viewport: info.project.use.viewport ?? undefined });
            const pub = await anon.newPage();
            const res = await pub.goto(`/l/${slug}`);
            expect(res?.status()).toBe(200);
            expect(res?.headers()['set-cookie'], 'la página pública no debe fijar cookies').toBeUndefined();
            await expect(pub.getByText('e2e Mi web')).toBeVisible();
            await expectNoOverflow(pub);
            // El clic pasa por /l/{slug}/go/{bloque} y redirige al destino (sin seguir la redirección a internet).
            const go = await pub.getByRole('link', { name: 'e2e Mi web' }).getAttribute('href');
            expect(go).toMatch(/\/l\/.+\/go\/\d+$/);
            const redirect = await anon.request.get(go!, { maxRedirects: 0 });
            expect(redirect.status()).toBeGreaterThanOrEqual(301);
            expect(redirect.status()).toBeLessThan(400);
            expect(redirect.headers()['location']).toBe('https://example.com/e2e');
            await anon.close();

            // El clic quedó contado
            await page.goto(`/smartlinks?brand_id=${brandId}`);
            await expect(page.getByText('1 clics')).toBeVisible();
        } finally {
            await page.goto('/brands').catch(() => {});
            await limpiar(page, brand);
        }
        expect(w.errors).toEqual([]);
    });

    test('API keys: crear y revocar', async ({ page }, info) => {
        const w = watch(page);
        aceptarDialogos(page);
        const name = `e2e-key-${stamp()}-${info.project.name}`;

        await page.goto('/api-keys');
        await page.getByLabel(/Nombre \(p\. ej\./).fill(name);
        await page.getByRole('button', { name: 'Crear API key' }).click();
        await expect(page.getByTestId('new-key')).toBeVisible();
        await expect(page.getByText(name)).toBeVisible();
        await expectNoOverflow(page);

        await page.getByText(name, { exact: true }).locator('xpath=ancestor::li').getByRole('button', { name: 'Revocar' }).click();
        await settle(page);
        await expect(page.getByText(name, { exact: true })).toHaveCount(0);
        expect(w.errors).toEqual([]);
    });
});

/** Borra la marca e2e (y con ella sus publicaciones, plantillas y SmartLink). */
async function limpiar(page: Page, brand: string) {
    await borrarMarcasE2e(page, brand);
}
