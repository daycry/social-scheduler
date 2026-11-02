import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Programar en lenguaje natural (C-04c, E2E-07): «Escribe cuándo» propone una fecha y unas redes y SOLO las aplica al confirmar.
 * La IA no se llama nunca: `page.route` simula `GET ai/status` y `POST ai/schedule` en el navegador (la IA real es la prueba manual M-05).
 * Usuarios `e2e-sec-*` y marcas `e2e-composer-*`.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `nl-${stamp()}-${info.project.name}`);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

/** El primer viernes a partir de dentro de 2 semanas (siempre futuro), en formato `Y-m-d`. */
function futureFriday(): string {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + 14);
    while (d.getUTCDay() !== 5) d.setUTCDate(d.getUTCDate() + 1);
    return d.toISOString().slice(0, 10);
}

const proposal = () => ({
    scheduled_at: `${futureFriday()}T18:00`,
    timezone: 'Europe/Madrid',
    utc: `${futureFriday()}T17:00:00Z`,
    label: 'viernes, 18:00',
    networks: ['instagram', 'bluesky'],
    ignored_networks: [] as string[],
});

/** Simula la IA en el navegador: `null` = desactivada; si no, `POST ai/schedule` responde con lo dado. Devuelve las peticiones recibidas. */
async function fakeAi(page: Page, reply: { status?: number; body: unknown } | null = { body: { proposal: proposal(), remaining: 4 } }) {
    const calls: Array<Record<string, unknown>> = [];
    await page.route('**/ai/status', (route) => route.fulfill({ json: { enabled: reply !== null, remaining: 5 } }));
    await page.route('**/ai/schedule', async (route) => {
        calls.push(route.request().postDataJSON());
        await route.fulfill({ status: reply?.status ?? 200, contentType: 'application/json', body: JSON.stringify(reply?.body) });
    });
    return calls;
}

async function axeNow(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400);
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(bad, `${label}: ${JSON.stringify(bad.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) })))}`).toEqual([]);
}

const pill = (page: Page, name: RegExp) => page.getByRole('button', { name });
const instagram = /^Instagram: e2e Instagram/;
const facebook = /^Facebook: e2e Facebook/;
const bluesky = /^Bluesky: e2e Bluesky/;

test('E2E-07 · la propuesta se ve antes de aplicarse y al confirmar se rellenan fecha y redes', async ({ page }) => {
    const w = watch(page);
    const calls = await fakeAi(page);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const when = page.getByTestId('nl-input');
    await expect(when).toBeVisible();
    await expect(page.getByLabel('Escribe cuándo')).toBeVisible();
    await expect(pill(page, instagram)).toHaveAttribute('aria-pressed', 'false');

    // Enter propone (no envía el formulario del compositor).
    await when.fill('el viernes a las 18 en Instagram y Bluesky');
    await when.press('Enter');
    const card = page.getByTestId('nl-proposal');
    await expect(card).toBeVisible();
    await expect(page.getByTestId('nl-proposal-date')).toContainText('18:00');
    await expect(page.getByTestId('nl-proposal-date')).toContainText('Europe/Madrid');
    await expect(page.getByTestId('nl-proposal-networks')).toContainText('Instagram, Bluesky');
    expect(calls).toEqual([{ phrase: 'el viernes a las 18 en Instagram y Bluesky', brand_id: brand.id }]);
    await expect(page).toHaveURL(/\/posts\/create/); // no se envió el formulario

    // La propuesta NO está aplicada todavía: ni fecha ni redes.
    await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', '');
    await expect(pill(page, instagram)).toHaveAttribute('aria-pressed', 'false');
    await expect(pill(page, bluesky)).toHaveAttribute('aria-pressed', 'false');
    await expectNoOverflow(page);
    await axeNow(page, 'propuesta visible');

    // Confirmar: rellena la fecha en la zona de la marca y marca SOLO las redes propuestas.
    await page.getByTestId('nl-apply').click();
    await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', `${futureFriday()}T18:00`);
    await expect(pill(page, instagram)).toHaveAttribute('aria-pressed', 'true');
    await expect(pill(page, bluesky)).toHaveAttribute('aria-pressed', 'true');
    await expect(pill(page, facebook)).toHaveAttribute('aria-pressed', 'false');
    await expect(card).toHaveCount(0);
    await expect(page.getByTestId('nl-applied')).toContainText('Aplicado');
    await expect(page.getByText('Te quedan 4 usos de IA hoy')).toBeVisible();
    await expectNoOverflow(page);
    await axeNow(page, 'propuesta aplicada');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-07 · descartar la propuesta no cambia nada', async ({ page }) => {
    await fakeAi(page);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await page.getByTestId('nl-input').fill('el viernes a las 18 en Instagram y Bluesky');
    await page.getByTestId('nl-propose').click();
    await expect(page.getByTestId('nl-proposal')).toBeVisible();

    await page.getByTestId('nl-discard').click();
    await expect(page.getByTestId('nl-proposal')).toHaveCount(0);
    await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', '');
    await expect(pill(page, instagram)).toHaveAttribute('aria-pressed', 'false');
    await expect(pill(page, bluesky)).toHaveAttribute('aria-pressed', 'false');
    await expectNoOverflow(page);
});

test('E2E-07 · una red sin conectar se avisa y no se aplica; sin redes válidas solo cambia la fecha', async ({ page }) => {
    await fakeAi(page, { body: { proposal: { ...proposal(), networks: [], ignored_networks: ['tiktok'] }, remaining: 4 } });
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await page.getByTestId('nl-input').fill('el viernes a las 18 en TikTok');
    await page.getByTestId('nl-propose').click();
    await expect(page.getByTestId('nl-proposal-networks')).toContainText('elige las redes a mano');
    await expect(page.getByTestId('nl-proposal-ignored')).toContainText('no las tiene conectadas');
    await expectNoOverflow(page);

    await page.getByTestId('nl-apply').click();
    await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', `${futureFriday()}T18:00`);
    await expect(pill(page, instagram)).toHaveAttribute('aria-pressed', 'false');
});

test('E2E-07 · un error de la IA (fecha pasada, límite diario) se explica y no deja ninguna propuesta', async ({ page }) => {
    await fakeAi(page, { status: 422, body: { message: 'La fecha propuesta ya ha pasado. Indica una fecha futura.', remaining: 3 } });
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await page.getByTestId('nl-input').fill('ayer a las 10');
    await page.getByTestId('nl-propose').click();
    await expect(page.getByTestId('nl-error')).toContainText('ya ha pasado');
    await expect(page.getByTestId('nl-proposal')).toHaveCount(0);
    await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', '');
    await expect(page.getByText('Te quedan 3 usos de IA hoy')).toBeVisible();
    await expectNoOverflow(page);
    await axeNow(page, 'error de la IA');
});

test('E2E-07 · con la IA desactivada el campo no aparece', async ({ page }) => {
    await fakeAi(page, null);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await expect(page.locator('#scheduled_at')).toBeVisible();
    await expect(page.getByTestId('nl-schedule')).toHaveCount(0);
    await expect(page.getByLabel('Escribe cuándo')).toHaveCount(0);
});
