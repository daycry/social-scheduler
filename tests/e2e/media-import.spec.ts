import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Importar media desde una URL (C-04a, E2E-08): usuarios `e2e-sec-*` y marcas `e2e-composer-*`. Solo se prueba el rechazo de `UrlGuard`:
 * la importación real (descarga, tipo, tamaño, Range) se cubre en Pest con `Http::fake`. No se hace ninguna petición a ninguna URL.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `mediaimp-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

async function axeNow(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const detail = bad.map((v) => `${v.id} [${v.impact}] ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ${n.html.slice(0, 200)}`).join('\n   ')}`).join('\n');
    expect(bad, `violaciones de accesibilidad en ${label}:\n${detail}`).toEqual([]);
}

test('E2E-08 · una URL a una IP interna se rechaza con un error en español y sin crear ningún asset', async ({ page }, info) => {
    const w = watch(page);
    const imports: string[] = [];
    page.on('request', (r) => {
        if (r.url().includes('/media/import-url')) imports.push(r.url());
    });

    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const section = page.getByTestId('media-import');
    const input = page.getByLabel('Desde URL');
    await expect(section).toBeVisible();
    await expect(input).toBeVisible();

    // Accesible por teclado: se llega al campo con Tab/foco y se envía con Intro.
    await input.focus();
    await expect(input).toBeFocused();

    for (const url of ['http://127.0.0.1/imagen.jpg', 'http://169.254.169.254/latest/meta-data/', 'http://localhost/imagen.jpg']) {
        await input.fill(url);
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('media-import-error')).toContainText('La URL apunta a una direccion no permitida.');
        await expect(page.getByTestId('media-import-item')).toHaveCount(0);
    }
    expect(imports.length).toBe(3);

    // El campo queda marcado como inválido y el error se anuncia.
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByTestId('media-import-error')).toHaveAttribute('role', 'alert');

    // No se ha añadido ninguna media a la publicación.
    await expect(page.getByText('Todavía no has añadido archivos')).toBeVisible();

    await section.scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await axeNow(page, `compositor con error de importación (${info.project.name})`);
    await page.screenshot({ path: `test-results/media-import-${info.project.name}.png`, fullPage: true });

    expect(w.errors.filter((e) => !/Failed to load resource: the server responded with a status of 422/.test(e)), w.errors.join('\n')).toEqual([]);
});

test('E2E-08 · el botón no envía con el campo vacío y un esquema distinto de http se rechaza', async ({ page }) => {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const submit = page.getByTestId('media-import').getByRole('button', { name: 'Importar' });
    await expect(submit).toBeDisabled();

    await page.getByLabel('Desde URL').fill('ftp://example.com/imagen.jpg');
    await expect(submit).toBeEnabled();
    await submit.click();
    await expect(page.getByTestId('media-import-error')).toContainText('http');
    await expectNoOverflow(page);
});
