import { expect, test, type Page } from '@playwright/test';
import { axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedThreadsScenario, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Threads (C-02, E2E-08): superficies de la red nueva junto a Bluesky: Conexiones, calendario (icono y filtro), dashboard (métricas) y
 * compositor (500 caracteres con los emojis contados por bytes). Usa los usuarios `e2e-sec-*` y marcas `e2e-composer-*` con cuentas
 * SEMBRADAS (factoría): ni Threads ni Bluesky se llaman nunca y el botón «Conectar» no se pulsa (redirigiría a threads.com).
 * Requiere `THREADS_APP_ID` en el entorno del servidor: sin él la red no se ofrece.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
let scheduledDate: string;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `threads-${stamp()}-${info.project.name}`);
    scheduledDate = seedThreadsScenario(user.email, brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const card = (page: Page) => page.locator('section[data-network="threads"]');

test('E2E-08 · Conexiones: la tarjeta de Threads y su cuenta conectada, sin desborde', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/connections`);
    await settle(page);

    await expect(card(page).getByRole('heading', { name: 'Threads' })).toBeVisible();
    await expect(card(page).getByRole('button', { name: '+ Conectar' })).toBeVisible(); // OAuth: se pulsa en el smoke real, nunca aquí
    await expect(card(page)).toContainText('e2e Threads');
    await expect(card(page)).toContainText('Conectada');
    await expect(card(page).getByRole('button', { name: 'Desconectar' })).toBeVisible();
    // La tarjeta de Bluesky sigue ahí, con su formulario de credenciales (otra forma de conectar).
    await expect(page.locator('section[data-network="bluesky"]').getByRole('heading', { name: 'Bluesky' })).toBeVisible();
    const html = await page.content();
    expect(html).not.toContain('access_token');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Conexiones con Threads y Bluesky');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-08 · calendario: iconos de Threads y Bluesky y filtro por red', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    await settle(page);

    await expect(page.getByText('e2e programada en Threads').first()).toBeVisible();
    await expect(page.getByText('e2e programada en Bluesky').first()).toBeVisible();
    // Cada fila lleva el chip de su red (nombre y abreviatura del catálogo).
    const fila = (texto: string) => page.locator(`xpath=//a[contains(normalize-space(.), "${texto}")]/ancestor::tr[1]`);
    await expect(fila('e2e programada en Threads')).toContainText('Threads');
    await expect(fila('e2e programada en Threads')).toContainText('TH');
    await expect(fila('e2e programada en Bluesky')).toContainText('Bluesky');
    await expect(fila('e2e programada en Bluesky')).toContainText('BS');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Calendario con Threads y Bluesky');

    const red = page.getByLabel('Red', { exact: true });
    await expect(red.locator('option', { hasText: 'Threads' })).toHaveCount(1);
    await red.selectOption('threads');
    await page.waitForURL(/network=threads/);
    await settle(page);
    await expect(page.getByText('e2e programada en Threads').first()).toBeVisible();
    await expect(page.getByText('e2e programada en Bluesky')).toHaveCount(0);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Calendario filtrado por Threads');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
    expect(scheduledDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
});

test('E2E-08 · dashboard de Threads: visitas al perfil (no visualizaciones), clics y la publicación con sus cifras', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/dashboard/threads?brand_id=${brand.id}`);
    await settle(page);

    await expect(page.getByRole('heading', { level: 1 })).toContainText('Threads');
    // 3 días sembrados: 3 × 40 visitas al perfil; Threads no da visualizaciones de cuenta, así que esa métrica ni se lista.
    await expect(page.locator('[data-metric="profile_views"]')).toContainText('120');
    await expect(page.locator('[data-metric="profile_views"]')).toContainText('Visitas al perfil');
    await expect(page.locator('[data-metric="clicks"]')).toContainText('33');
    await expect(page.locator('[data-metric="likes"]')).toContainText('36');
    await expect(page.locator('[data-metric="views"]')).toHaveCount(0);
    await expect(page.locator('[data-metric="reach"]')).toHaveCount(0);
    await expect(page.getByText('e2e publicada en Threads')).toBeVisible();
    await expect(page.locator('[data-section="posts"] tbody tr').first()).toContainText('100');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Dashboard de Threads');

    // Resumen: Threads y Bluesky entran en las pestañas de red (móvil) o en la barra lateral (escritorio).
    await page.goto(`/dashboard?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByRole('link', { name: /Threads/ }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /Bluesky/ }).first()).toBeVisible();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Resumen con Threads y Bluesky');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-08 · compositor: Threads cuenta 500 caracteres con cada emoji por sus bytes UTF-8', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const threads = page.getByRole('button', { name: /^Threads: e2e Threads/ });
    await expect(threads).toHaveAttribute('aria-pressed', 'false');
    await threads.click();
    await expect(threads).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: /Tipo de contenido en Threads/ })).toBeVisible();

    // «Hola » (5) + cohete (1 carácter, 4 bytes UTF-8) = 9.
    await page.locator('#text').fill('Hola \u{1F680}');
    const contador = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'Threads' });
    await expect(contador.getByTestId('counter-chars')).toHaveText('9 / 500');

    // 126 cohetes son 126 caracteres pero 504 bytes: no caben.
    await page.locator('#text').fill('\u{1F680}'.repeat(126));
    await expect(contador.getByTestId('counter-chars')).toContainText('504 / 500');
    await expect(contador.getByTestId('counter-chars')).toContainText('supera el máximo');

    await page.locator('#text').fill('a'.repeat(500));
    await expect(contador.getByTestId('counter-chars')).toHaveText('500 / 500');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Compositor con Threads');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-08 · compositor: con 2 etiquetas en Threads aparece el aviso de que solo se usa la primera (CA-14)', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const threads = page.getByRole('button', { name: /^Threads: e2e Threads/ });
    await threads.click();
    await expect(threads).toHaveAttribute('aria-pressed', 'true');

    const contador = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'Threads' });

    // Con 1 etiqueta, sin aviso.
    await page.locator('#text').fill('Hola #uno');
    await expect(contador.getByTestId('counter-hashtags-advice')).toHaveCount(0);

    // Con 2, el aviso nombra el límite de Threads (1 etiqueta de tema por publicación).
    await page.locator('#text').fill('Hola #uno y #dos');
    const aviso = contador.getByTestId('counter-hashtags-advice');
    await expect(aviso).toBeVisible();
    await expect(aviso).toContainText('Threads solo admite 1 etiqueta de tema por publicación: se usa la primera.');

    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Compositor con Threads y aviso de etiquetas');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
