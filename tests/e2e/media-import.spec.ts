import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Importar media desde una URL (C-04a, E2E-08): usuarios `e2e-sec-*` y marcas `e2e-composer-*`. El rechazo de `UrlGuard` se prueba contra el servidor real:
 * la importación real (descarga, tipo, tamaño, Range) se cubre en Pest (`Http::fake` y un servidor PHP local). El progreso, el error, el atasco y la
 * cancelación se simulan con `page.route` (sin ninguna petición a ninguna URL).
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

/**
 * Progreso, éxito, error, atasco y cancelación de una importación (revisión de la Fase 5, gaps 3, 6, 7, 8 y 14). El servidor no
 * descarga nada: `page.route` simula `POST /media/import-url`, `GET /media/{id}` y `DELETE /media/{id}`, así que no hay ninguna red.
 */
const FAKE_ID = 990001;
const fakeAsset = (over: Record<string, unknown> = {}) => ({
    id: FAKE_ID, name: 'foto.jpg', kind: 'image', mime: 'image/jpeg', size: null, width: 640, height: 480, duration: null, fps: null,
    status: 'pendiente', error: null, source: 'url', bytes: 0, total: null, preview_url: null, ...over,
});
type Step = { status?: number; asset?: Record<string, unknown> };

/** Simula la importación: el POST responde 202 y cada GET sigue `steps` (el último se repite). Devuelve los contadores. */
async function mockImport(page: Page, steps: Step[]) {
    const seen = { gets: 0, deletes: 0 };
    await page.route('**/media/import-url', (route) => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ asset: fakeAsset() }) }));
    await page.route(`**/media/${FAKE_ID}`, (route) => {
        if (route.request().method() === 'DELETE') {
            seen.deletes++;
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
        }
        const step = steps[Math.min(seen.gets++, steps.length - 1)];
        return route.fulfill({ status: step.status ?? 200, contentType: 'application/json', body: JSON.stringify(step.status && step.status >= 400 ? { message: 'Error' } : { asset: step.asset }) });
    });

    return seen;
}

async function startFakeImport(page: Page) {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    await page.getByLabel('Desde URL').fill('https://cdn.example.com/foto.jpg');
    await page.keyboard.press('Enter');
}

test('E2E-08 · el progreso avanza, un fallo suelto del sondeo se tolera y al terminar la media pasa a la publicación', async ({ page }) => {
    const seen = await mockImport(page, [
        { asset: fakeAsset({ bytes: 500, total: 2000 }) },
        { status: 503 }, // un error suelto de red/servidor no mata la importación
        { asset: fakeAsset({ bytes: 1500, total: 2000 }) },
        { asset: fakeAsset({ status: 'listo', bytes: 2000, total: 2000, size: 2000 }) },
    ]);
    await startFakeImport(page);

    const item = page.getByTestId('media-import-item');
    await expect(item).toContainText('Importando foto.jpg');
    await expect(item).toContainText('25 %', { timeout: 15_000 });
    await expect(item.locator('progress')).toHaveAttribute('value', '500');
    await expect(item).toContainText('75 %', { timeout: 20_000 });
    await expect(item).not.toContainText('No se pudo importar');

    // Termina: el elemento de progreso desaparece y la media queda UNA sola vez en la lista de la publicación.
    await expect(item).toHaveCount(0, { timeout: 20_000 });
    await expect(page.getByText('1. foto.jpg')).toBeVisible();
    await expect(page.getByText('2. foto.jpg')).toHaveCount(0);
    const getsAtEnd = seen.gets;
    await page.waitForTimeout(4000);
    expect(seen.gets, 'el sondeo se detiene al terminar').toBe(getsAtEnd);
});

test('E2E-08 · una importación que acaba «invalida» muestra el motivo y se puede descartar', async ({ page }) => {
    await mockImport(page, [
        { asset: fakeAsset({ bytes: 100, total: 1000 }) },
        { asset: fakeAsset({ status: 'invalido', error: 'El servidor remoto respondió 404.' }) },
    ]);
    await startFakeImport(page);

    const item = page.getByTestId('media-import-item');
    await expect(item).toContainText('No se pudo importar foto.jpg: El servidor remoto respondió 404.', { timeout: 20_000 });
    await expect(item.getByRole('alert')).toBeVisible();
    await expectNoOverflow(page);

    await item.getByRole('button', { name: 'Descartar' }).click();
    await expect(item).toHaveCount(0);
    await expect(page.getByText('Todavía no has añadido archivos')).toBeVisible();
});

test('E2E-08 · una importación que lleva minutos «pendiente» sin un byte avisa de que la cola no procesa, sigue comprobando despacio y se completa si el worker vuelve', async ({ page }) => {
    test.setTimeout(90_000);
    const seen = await mockImport(page, [
        { asset: fakeAsset() }, { asset: fakeAsset() },
        { asset: fakeAsset({ status: 'listo', bytes: 2000, total: 2000, size: 2000 }) },
    ]);
    await startFakeImport(page);

    const item = page.getByTestId('media-import-item');
    await expect(item).toContainText('Importando foto.jpg');
    await expect.poll(() => seen.gets, { timeout: 15_000 }).toBeGreaterThan(0);

    // Se adelanta el reloj de la página 4 minutos (el umbral es 3): el siguiente sondeo ve «pendiente» sin bytes ni latido pasado el plazo.
    await page.evaluate(() => {
        const real = Date.now;
        Date.now = () => real() + 4 * 60 * 1000;
    });
    await expect(item).toContainText('La cola de importación no está procesando', { timeout: 20_000 });
    await expect(item.getByRole('alert')).toBeVisible();

    // Sigue comprobando, pero con intervalo largo (20 s): en 6 s no hay más de una consulta.
    const getsAtStall = seen.gets;
    await page.waitForTimeout(6000);
    expect(seen.gets - getsAtStall, 'sondeo lento tras el aviso').toBeLessThanOrEqual(1);

    // El worker vuelve y la importación se completa: el aviso desaparece y la media se añade.
    await expect(item).toHaveCount(0, { timeout: 40_000 });
    await expect(page.getByText('Todavía no has añadido archivos')).toHaveCount(0);
});

test('E2E-08 · un aviso de atasco se descarta con «Descartar»', async ({ page }) => {
    const seen = await mockImport(page, [{ asset: fakeAsset() }]);
    await startFakeImport(page);

    const item = page.getByTestId('media-import-item');
    await expect.poll(() => seen.gets, { timeout: 15_000 }).toBeGreaterThan(0);
    await page.evaluate(() => {
        const real = Date.now;
        Date.now = () => real() + 4 * 60 * 1000;
    });
    await expect(item).toContainText('La cola de importación no está procesando', { timeout: 20_000 });

    // «Descartar» cancela el asset (DELETE) para liberar el cupo de importaciones.
    await item.getByRole('button', { name: 'Descartar' }).click();
    await expect(item).toHaveCount(0);
    expect(seen.deletes).toBe(1);
});

test('E2E-08 · se puede cancelar una importación en curso', async ({ page }) => {
    const seen = await mockImport(page, [{ asset: fakeAsset({ bytes: 100, total: 1000 }) }]);
    await startFakeImport(page);

    const item = page.getByTestId('media-import-item');
    await expect(item).toContainText('Importando foto.jpg');
    await item.getByRole('button', { name: 'Cancelar la importación de foto.jpg' }).click();

    await expect(item).toHaveCount(0);
    expect(seen.deletes).toBe(1);
    await expect(page.getByText('Todavía no has añadido archivos')).toBeVisible();
});

/**
 * Google Drive (C-04b, E2E-09). El servidor de pruebas no tiene `GOOGLE_PICKER_API_KEY`: sin clave el botón no existe. Con clave se simula la
 * página (se inyecta `drivePicker` en las props de Inertia), `window.google`/`window.gapi` (stub del Picker, sin cargar ningún script de Google)
 * y `POST /media/import-drive`: nada llega a Google.
 */
test('E2E-09 · Drive: sin GOOGLE_PICKER_API_KEY no hay botón «Google Drive» ni se cargan scripts de Google', async ({ page }, info) => {
    const google: string[] = [];
    page.on('request', (r) => {
        if (/accounts\.google\.com|apis\.google\.com/.test(r.url())) google.push(r.url());
    });
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await expect(page.getByTestId('media-import')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Google Drive' })).toHaveCount(0);
    await expect(page.getByTestId('media-import-drive')).toHaveCount(0);
    await expect(page.getByText('También puedes elegir un fichero de Google Drive')).toHaveCount(0);
    expect(google, 'no se carga nada de Google').toEqual([]);

    await page.getByTestId('media-import').scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await axeNow(page, `compositor sin Drive (${info.project.name})`);
});

/** Activa Drive en la página: reescribe las props de Inertia del HTML y sustituye Google por un stub que «elige» un fichero. */
async function enableFakeDrive(page: Page, seen: { token: string | null; clicks: number; body: Record<string, string> | null }, pick: 'file' | 'cancel' = 'file') {
    // Se reescribe `data-page` en cuanto el parser crea el contenedor de Inertia, antes de que arranque la aplicación (el HTML no se
    // intercepta: `route.fetch` no acepta el certificado autofirmado de metricool.test).
    await page.addInitScript(() => {
        const patch = () => {
            const el = document.getElementById('app');
            const raw = el?.getAttribute('data-page');
            if (!el || !raw || el.dataset.driveStub) return;
            el.dataset.driveStub = '1';
            const data = JSON.parse(raw);
            data.props.drivePicker = { api_key: 'test-key', app_id: '123', client_id: 'cid.apps.googleusercontent.com' };
            el.setAttribute('data-page', JSON.stringify(data));
        };
        new MutationObserver(patch).observe(document, { childList: true, subtree: true, attributes: true });
    });
    await page.addInitScript((mode) => {
        const w = window as any;
        w.__drive = { token: null, clicks: 0 };
        w.google = {
            accounts: { oauth2: { initTokenClient: (cfg: any) => ({ requestAccessToken: () => { w.__drive.clicks++; cfg.callback({ access_token: 'ya29.stub-token' }); } }) } },
            picker: {
                ViewId: { DOCS: 'docs' }, Response: { ACTION: 'action', DOCUMENTS: 'docs' }, Action: { PICKED: 'picked', CANCEL: 'cancel' }, Document: { ID: 'id', NAME: 'name' },
                DocsView: class { setMimeTypes() { return this; } setIncludeFolders() { return this; } },
                PickerBuilder: class {
                    cb: any;
                    setOAuthToken(t: string) { w.__drive.token = t; return this; }
                    setDeveloperKey() { return this; } setLocale() { return this; } setAppId() { return this; } addView() { return this; }
                    setCallback(cb: any) { this.cb = cb; return this; }
                    build() { return { setVisible: () => setTimeout(() => this.cb(mode === 'file' ? { action: 'picked', docs: [{ id: 'FILE_id-1', name: 'clip.mp4' }] } : { action: 'cancel' }), 0) }; }
                },
            },
        };
        w.gapi = { load: (_: string, cb: () => void) => cb() };
    }, pick);
    await page.route('**/media/import-drive', (route) => {
        seen.body = route.request().postDataJSON();
        return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ asset: fakeAsset({ name: 'clip.mp4', kind: 'video', mime: 'video/mp4', source: 'drive' }) }) });
    });
    await page.route(`**/media/${FAKE_ID}`, (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ asset: fakeAsset({ name: 'clip.mp4', kind: 'video', mime: 'video/mp4', status: 'listo', bytes: 10, total: 10, size: 10, duration: 3, source: 'drive' }) }),
    }));
}

test('E2E-09 · Drive: con la clave configurada el botón aparece, se usa con teclado e importa el fichero elegido sin tocar Google', async ({ page }, info) => {
    const seen = { token: null as string | null, clicks: 0, body: null as Record<string, string> | null };
    await enableFakeDrive(page, seen);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const button = page.getByRole('button', { name: 'Google Drive' });
    await expect(button).toBeVisible();
    await button.scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await axeNow(page, `compositor con Drive (${info.project.name})`);

    // Accesible por teclado: foco y Intro.
    await button.focus();
    await expect(button).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByText('1. clip.mp4')).toBeVisible({ timeout: 20_000 });
    expect(seen.body).toEqual({ file_id: 'FILE_id-1', access_token: 'ya29.stub-token' });
    // El token no queda en la URL, el almacenamiento ni el DOM.
    expect(page.url()).not.toContain('ya29');
    const leaked = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }) + document.documentElement.outerHTML.includes('ya29'));
    expect(leaked).not.toContain('ya29');
    expect(leaked).toContain('false');
    await expectNoOverflow(page);
});

test('E2E-09 · Drive: cancelar el Picker no importa nada ni muestra error', async ({ page }) => {
    const seen = { token: null as string | null, clicks: 0, body: null as Record<string, string> | null };
    await enableFakeDrive(page, seen, 'cancel');
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await page.getByRole('button', { name: 'Google Drive' }).click();
    await expect.poll(() => page.evaluate(() => (window as any).__drive.clicks)).toBe(1);
    await expect(page.getByTestId('media-import-item')).toHaveCount(0);
    await expect(page.getByTestId('media-import-error')).toHaveText('');
    expect(seen.body).toBeNull();
});
