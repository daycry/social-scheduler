import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Buscador de GIFs de Giphy (C-04b, E2E-09). El servidor de pruebas no tiene `GIPHY_API_KEY`: sin clave el botón «GIF» no existe. Con clave se
 * simula la página (se inyecta `giphy: { enabled: true }` en las props de Inertia, sin tocar `.env`), `GET /gifs` y `POST /media/import-gif`;
 * las miniaturas de Giphy se sirven desde un GIF de 1 píxel. Nada llega a Giphy.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `gifpick-${stamp()}-${info.project.name}`);
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

const FAKE_ID = 990002;
const PIXEL = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
const MP4 = 'https://media2.giphy.com/media/gato1/giphy.mp4';
const gif = (n: number, title: string) => ({
    id: `gato${n}`, title, preview: `https://media2.giphy.com/media/gato${n}/100w.gif`, width: 100, height: 80, mp4: n === 1 ? MP4 : `https://media2.giphy.com/media/gato${n}/giphy.mp4`,
});
const fakeAsset = (over: Record<string, unknown> = {}) => ({
    id: FAKE_ID, name: 'giphy-gato1.mp4', kind: 'video', mime: 'video/mp4', size: null, width: null, height: null, duration: null, fps: null,
    status: 'pendiente', error: null, source: 'giphy', bytes: 0, total: null, preview_url: null, ...over,
});

/** Activa Giphy en la página y simula el servidor de GIFs. `search` decide la respuesta de `GET /gifs`. */
async function enableFakeGiphy(page: Page, seen: { queries: string[]; imported: string | null; external: string[] }, search: (q: string) => { status?: number; body: unknown }) {
    // Se reescribe `data-page` en cuanto el parser crea el contenedor de Inertia, antes de que arranque la aplicación.
    await page.addInitScript(() => {
        const patch = () => {
            const el = document.getElementById('app');
            const raw = el?.getAttribute('data-page');
            if (!el || !raw || el.dataset.giphyStub) return;
            el.dataset.giphyStub = '1';
            const data = JSON.parse(raw);
            data.props.giphy = { enabled: true };
            el.setAttribute('data-page', JSON.stringify(data));
        };
        new MutationObserver(patch).observe(document, { childList: true, subtree: true, attributes: true });
    });
    await page.route(/media\d*\.giphy\.com/, (route) => {
        seen.external.push(route.request().url());
        return route.fulfill({ status: 200, contentType: 'image/gif', body: PIXEL });
    });
    await page.route('**/gifs?*', (route) => {
        const q = new URL(route.request().url()).searchParams.get('q') ?? '';
        seen.queries.push(q);
        const r = search(q);
        return route.fulfill({ status: r.status ?? 200, contentType: 'application/json', body: JSON.stringify(r.body) });
    });
    await page.route('**/gifs', (route) => {
        seen.queries.push('');
        const r = search('');
        return route.fulfill({ status: r.status ?? 200, contentType: 'application/json', body: JSON.stringify(r.body) });
    });
    await page.route('**/media/import-gif', (route) => {
        seen.imported = route.request().postDataJSON().url;
        return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ asset: fakeAsset() }) });
    });
    await page.route(`**/media/${FAKE_ID}`, (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ asset: fakeAsset({ status: 'listo', bytes: 10, total: 10, size: 10, duration: 2, width: 480, height: 480 }) }),
    }));
}

const newSeen = () => ({ queries: [] as string[], imported: null as string | null, external: [] as string[] });
const catalog = (q: string) => ({ body: { results: q === 'xyz' ? [] : [gif(1, 'Gato saltando'), gif(2, 'Gato dormido'), gif(3, 'Gato curioso')], next_offset: null } });

test('E2E-09 · GIF: sin GIPHY_API_KEY no hay botón «GIF» ni se pide nada a Giphy', async ({ page }, info) => {
    const giphy: string[] = [];
    page.on('request', (r) => {
        if (/giphy\.com|\/gifs(\?|$)/.test(r.url())) giphy.push(r.url());
    });
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    await expect(page.getByTestId('media-import')).toBeVisible();
    await expect(page.getByTestId('media-import-gif')).toHaveCount(0);
    await expect(page.getByText('O busca un GIF en Giphy')).toHaveCount(0);
    expect(giphy, 'no se pide nada').toEqual([]);

    await page.getByTestId('media-import').scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await axeNow(page, `compositor sin GIF (${info.project.name})`);
});

test('E2E-09 · GIF: buscar, ver «Powered by GIPHY», elegir con teclado e importar el MP4 (axe y sin desborde)', async ({ page }, info) => {
    const w = watch(page);
    const seen = newSeen();
    await enableFakeGiphy(page, seen, catalog);
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const button = page.getByTestId('media-import-gif');
    await expect(button).toBeVisible();
    await button.scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await button.focus();
    await expect(button).toBeFocused();
    await page.keyboard.press('Enter');

    // El modal se abre con el foco en el campo de búsqueda, con las tendencias cargadas y la atribución visible.
    const dialog = page.getByTestId('gif-picker');
    await expect(dialog).toBeVisible();
    await expect(page.getByLabel('Buscar GIFs')).toBeFocused();
    await expect(page.getByTestId('gif-result')).toHaveCount(3);
    const attribution = page.getByTestId('giphy-attribution');
    await expect(attribution).toBeVisible();
    await expect(attribution).toHaveText('Powered by GIPHY');
    await attribution.scrollIntoViewIfNeeded();
    await expectNoOverflow(page);
    await axeNow(page, `selector de GIF (${info.project.name})`);

    // Buscar «gato» (Intro) y elegir el primer resultado con teclado.
    await page.getByLabel('Buscar GIFs').fill('gato');
    await page.keyboard.press('Enter');
    await expect.poll(() => seen.queries).toContain('gato');
    const first = page.getByRole('button', { name: 'Elegir el GIF Gato saltando' });
    await expect(first).toBeVisible();
    await first.focus();
    await page.keyboard.press('Enter');

    // Se importa el MP4 por el servidor, el modal se cierra y la media entra en el post.
    await expect(page.getByText('1. giphy-gato1.mp4')).toBeVisible({ timeout: 20_000 });
    expect(seen.imported).toBe(MP4);
    await expect(dialog).toBeHidden();
    await expect(button).toBeFocused();
    await expectNoOverflow(page);
    expect(seen.external.every((u) => /^https:\/\/media\d*\.giphy\.com\//.test(u))).toBe(true);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-09 · GIF: Esc cierra el modal y devuelve el foco; sin resultados y errores se explican', async ({ page }) => {
    const seen = newSeen();
    let mode: 'ok' | 'limit' = 'ok';
    await enableFakeGiphy(page, seen, (q) => (mode === 'limit' && q === 'limite'
        ? { status: 429, body: { message: 'Has hecho muchas búsquedas de GIF; prueba en unos minutos.' } }
        : catalog(q)));
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);

    const button = page.getByTestId('media-import-gif');
    await button.scrollIntoViewIfNeeded();
    await button.click();
    const search = page.getByLabel('Buscar GIFs');
    await expect(search).toBeFocused();

    await search.fill('xyz');
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('gif-picker-empty')).toContainText('No hay GIFs para «xyz»');

    mode = 'limit';
    await search.fill('limite');
    await page.keyboard.press('Enter');
    const error = page.getByTestId('gif-picker-error');
    await expect(error).toHaveText('Has hecho muchas búsquedas de GIF; prueba en unos minutos.');
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(page.getByTestId('giphy-attribution')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByTestId('gif-picker')).toBeHidden();
    await expect(button).toBeFocused();
    await expect(page.getByText('Todavía no has añadido archivos')).toBeVisible();
    expect(seen.imported).toBeNull();
});
