import { expect, test } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * T-20 · SmartLinks: bloques enriquecidos (botón, video de YouTube validado, separador, iconos, formulario de email y posts),
 * reordenar, visibilidad programada, clics por bloque y formulario público. Sin red: el video es un iframe a youtube-nocookie que
 * no se carga (CSP/offline) y los clics se comprueban sin seguir la redirección.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
let slug: string;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `sl-${stamp()}-${info.project.name}`);
    slug = `e2e-${stamp()}-${info.project.name}`.toLowerCase();
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

test('E2E-20e · SmartLinks: bloques enriquecidos, reordenar, programar, formulario público y clics por bloque', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    await page.goto(`/smartlinks?brand_id=${brand.id}`);
    await settle(page);
    await page.locator('#sl-slug').fill(slug);

    const add = async (testid: string) => page.getByTestId(testid).click();
    await page.getByRole('button', { name: 'Añadir enlace' }).click();
    await add('add-youtube');
    await add('add-divider');
    await add('add-social');
    await add('add-email');
    await add('add-posts');
    await page.getByRole('button', { name: 'Añadir cabecera' }).click();
    await expect(page.getByTestId('block-type')).toHaveCount(7);

    await page.getByLabel('Texto del bloque 1').fill('Mi web');
    await page.getByLabel('URL del bloque 1').fill('https://example.com/web');
    await page.getByLabel('Texto del bloque 2').fill('Último video');
    // Una URL que parece de YouTube pero no lo es se rechaza en el servidor.
    await page.getByLabel('URL del bloque 2').fill('https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ');
    await page.getByLabel('Texto del bloque 3').fill('Redes');
    await page.getByLabel('URL del icono 1').fill('https://instagram.com/e2e');
    await page.getByLabel('Texto del bloque 4').fill('Sígueme');
    await page.getByLabel('Texto del bloque 5').fill('Boletín');
    await page.getByLabel('Texto del bloque 6').fill('Últimas publicaciones');
    await page.getByLabel('Texto del bloque 7').fill('Programado');
    // Visible solo en el futuro lejano: no aparece en la página pública.
    await page.getByLabel('Visible desde del bloque 7').fill('2030-01-01T10:00');
    await expectNoOverflow(page);
    await page.getByRole('button', { name: 'Guardar' }).click();
    await settle(page);
    await expect(page.getByText('Usa la URL de un video de YouTube')).toBeVisible();

    await page.getByLabel('URL del bloque 2').fill('https://youtu.be/dQw4w9WgXcQ');
    // Reordenar: «Redes» (3) sube una posición, por encima del video.
    await page.getByRole('button', { name: 'Subir bloque 3' }).click();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'SmartLinks (editor con bloques)');
    await page.getByRole('button', { name: 'Guardar' }).click();
    await settle(page);
    await expect(page.getByText(/SmartLink guardado/)).toBeVisible();
    await expect(page.getByTestId('block-visibility')).toHaveText('Programado: aún no visible');
    await expect(page.getByLabel('Texto del bloque 2')).toHaveValue('Redes');
    await expect(page.getByLabel('Texto del bloque 3')).toHaveValue('Último video');

    // Página pública.
    const pub = await page.context().newPage();
    // El reproductor de YouTube se simula: ninguna petición real sale del navegador.
    await pub.route('https://www.youtube-nocookie.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>video simulado</title><p>video simulado</p>' }));
    await pub.goto(`/l/${slug}`);
    await settle(pub);
    await expect(pub.locator('h1')).toBeVisible();
    await expect(pub.getByRole('link', { name: 'Mi web' })).toBeVisible();
    await expect(pub.locator('iframe[src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"]')).toHaveCount(1);
    await expect(pub.getByText('Programado')).toHaveCount(0);
    await expect(pub.getByRole('link', { name: 'Instagram' })).toBeVisible();
    await expectNoOverflow(pub);
    await axeSeriousCritical(pub, 'SmartLink público con bloques');

    // Formulario de email propio: respuesta uniforme.
    await pub.getByLabel('Boletín').fill('lector@example.com');
    await pub.getByRole('button', { name: 'Suscribirme' }).click();
    await settle(pub);
    await expect(pub.getByRole('status')).toContainText('Gracias');

    // Clics: el enlace va a /go (cuenta) y redirige solo al destino guardado.
    const href = await pub.getByRole('link', { name: 'Mi web' }).getAttribute('href');
    const res = await page.request.get(`${href}?url=https://evil.test`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe('https://example.com/web');
    const hidden = await page.request.get(href!.replace(/\/go\/\d+/, '/go/999999'), { maxRedirects: 0 });
    expect(hidden.status()).toBe(404);
    await pub.close();

    await page.reload();
    await settle(page);
    await expect(page.getByTestId('block-clicks').first()).toContainText('1 clics');
    await expect(page.getByText('1 suscritos')).toBeVisible();

    // El propietario ve la lista y puede borrar a un suscriptor (T-20, revisión).
    await expect(page.getByTestId('subscribers')).toContainText('lector@example.com');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'SmartLinks (suscriptores)');
    await page.getByRole('button', { name: 'Eliminar a lector@example.com' }).click();
    await settle(page);
    await expect(page.getByTestId('subscribers')).toHaveCount(0);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
