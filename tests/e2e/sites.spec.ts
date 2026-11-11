import { expect, test, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * T-15 · Sitios: construir un sitio de 2 páginas con navegación y bloques (incluido uno interactivo), vista previa en
 * móvil/tablet/escritorio, publicar, visitar como anónimo, editar sin que cambie lo publicado, republicar, proteger con contraseña
 * y con token, revocar y despublicar. Servidor propio, sin red real (el reproductor de YouTube se simula).
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
let slug: string;

const IMAGE = 'e2e-sitio.jpg';

function seedMedia(email: string, brandId: number) {
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => '${IMAGE}', 'mime' => 'image/jpeg', 'path' => 'media/e2e/sitio-' . uniqid() . '.jpg', 'width' => 300, 'height' => 200]); ` +
            String.raw`$im = imagecreatetruecolor(300, 200); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); ` +
            String.raw`$d = App\Models\Studio\Design::unguarded(fn () => App\Models\Studio\Design::create(['user_id' => $u->id, 'brand_id' => ${brandId}, 'name' => 'Cartel E2E', 'document' => ['pages' => []], 'width' => 300, 'height' => 200, 'page_count' => 1, 'revision' => 1])); ` +
            String.raw`App\Models\Studio\DesignExport::create(['user_id' => $u->id, 'brand_id' => ${brandId}, 'design_id' => $d->id, 'revision' => 1, 'format' => 'jpeg', 'pages' => ['p1'], 'media_ids' => [$a->id], 'status' => 'ready']); echo 'OK';`,
    );
}

function cleanMedia(email: string) {
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->first(); if ($u) { foreach (App\Models\MediaAsset::withoutUserScope()->where('user_id', $u->id)->where('original_name', '${IMAGE}')->get() as $m) { Illuminate\Support\Facades\Storage::disk($m->disk)->delete($m->path); $m->delete(); } } echo 'OK';`,
    );
}

/** Contexto anónimo con el mismo viewport/emulación que el proyecto y el reproductor de YouTube simulado. */
async function anonymous(browser: Browser, info: TestInfo): Promise<BrowserContext> {
    const use = info.project.use;
    const ctx = await browser.newContext({ baseURL: use.baseURL, viewport: use.viewport ?? undefined, isMobile: use.isMobile, hasTouch: use.hasTouch, locale: 'es-ES' });
    await ctx.route('https://www.youtube-nocookie.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>video simulado</title><p>video simulado</p>' }));
    return ctx;
}

const imageLoaded = (page: Page, alt: string) => page.getByAltText(alt).evaluate((el) => (el as HTMLImageElement).complete && (el as HTMLImageElement).naturalWidth > 0);

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    cleanMedia(user.email);
    brand = seedComposerBrand(user.email, `site-${stamp()}-${info.project.name}`);
    seedMedia(user.email, brand.id);
    slug = `e2e-${stamp()}-${info.project.name}`.toLowerCase();
    // La vista previa del editor también incrusta el reproductor: nada sale a la red real.
    await page.route('https://www.youtube-nocookie.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>video simulado</title><p>video simulado</p>' }));
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    const email = e2eUser(info.project.name).email;
    cleanComposerBrands(email);
    cleanMedia(email);
});

test('E2E-15 · Sitios: construir, previsualizar, publicar, editar sin tocar lo publicado, proteger y revocar', async ({ page, browser }, info) => {
    test.setTimeout(240_000);
    const w = watch(page);
    page.on('dialog', (d) => d.accept().catch(() => {}));

    // 1) Crear el sitio.
    await page.goto(`/sites?brand_id=${brand.id}`);
    await settle(page);
    await page.getByTestId('new-site-title').fill('Sitio E2E');
    await page.getByTestId('new-site-slug').fill(slug);
    await expectNoOverflow(page);
    await page.getByRole('button', { name: 'Crear sitio' }).click();
    await page.waitForURL(/\/sites\/\d+$/);
    await settle(page);
    await expect(page.getByTestId('site-state')).toHaveText('Borrador');

    // 2) Página 2 (Contacto) con formulario; la portada con bloques.
    await page.getByTestId('add-page').click();
    await page.getByTestId('page-title').fill('Contacto');
    await page.getByTestId('page-slug').fill('contacto');
    await page.getByTestId('add-contact').click();
    await page.getByTestId('page-tab-0').click();

    for (const t of ['text', 'button', 'image', 'gallery', 'youtube', 'design', 'faq', 'poll']) await page.getByTestId(`add-${t}`).click();
    // El bloque 1 (título) ya existe en la portada recién creada.
    await expect(page.getByTestId('block-type')).toHaveCount(9);
    await page.getByLabel('Texto del bloque 1', { exact: true }).fill('Bienvenida al sitio E2E');
    await page.getByLabel('Texto del bloque 2', { exact: true }).fill('Hola <script>alert(1)</script> **mundo** [ejemplo](https://example.com)');
    await page.getByLabel('Texto del botón 3', { exact: true }).fill('Ir a contacto');
    await page.getByLabel('Destino del botón 3', { exact: true }).selectOption('page');
    await page.getByLabel('Página del botón 3', { exact: true }).selectOption({ label: 'Contacto' });
    await page.getByLabel('Imagen del bloque 4', { exact: true }).selectOption({ label: IMAGE });
    await page.getByLabel('Texto alternativo del bloque 4', { exact: true }).fill('Foto azul principal');
    await page.getByLabel('Imagen 1 de la galería 5', { exact: true }).selectOption({ label: IMAGE });
    await page.getByLabel('Texto alternativo de la imagen 1 de la galería 5', { exact: true }).fill('Foto azul de galería');
    await page.getByLabel('URL del video 6', { exact: true }).fill('https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ');
    await page.getByLabel('Título del video 6', { exact: true }).fill('Video de presentación');
    await page.getByLabel('Exportación del bloque 7', { exact: true }).selectOption({ index: 1 });
    await page.getByLabel('Texto alternativo del bloque 7', { exact: true }).fill('Cartel exportado del estudio');
    await page.getByLabel('Pregunta 1 del bloque 8', { exact: true }).fill('¿Cuánto tarda?');
    await page.getByLabel('Respuesta 1 del bloque 8', { exact: true }).fill('Dos días laborables.');
    await page.getByLabel('Pregunta de la encuesta 9', { exact: true }).fill('¿Te gusta el sitio?');
    await page.getByLabel('Opción 1 de la encuesta 9', { exact: true }).fill('Sí');
    await page.getByLabel('Opción 2 de la encuesta 9', { exact: true }).fill('No');

    // Reordenar: el título sube/baja (no cambia el contenido) y un video con host falso se rechaza en el servidor.
    await page.getByRole('button', { name: 'Bajar bloque 1' }).click();
    await expect(page.getByLabel('Texto del bloque 2', { exact: true }).first()).toHaveValue('Bienvenida al sitio E2E');
    await page.getByRole('button', { name: 'Subir bloque 2' }).click();
    await expect(page.getByLabel('Texto del bloque 1', { exact: true }).first()).toHaveValue('Bienvenida al sitio E2E');
    await expectNoOverflow(page);
    await page.getByTestId('save-draft').click();
    await settle(page);
    await expect(page.getByText('Usa la URL de un video de YouTube')).toBeVisible();
    await page.getByLabel('URL del video 6', { exact: true }).fill('https://youtu.be/dQw4w9WgXcQ');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Sitios (editor con bloques)');
    await page.getByTestId('save-draft').click();
    await settle(page);
    await expect(page.getByTestId('flash')).toContainText('Borrador guardado');
    await expect(page.getByTestId('dirty')).toHaveCount(0);

    // 3) Vista previa responsive con el mismo render que el sitio publicado.
    const frameEl = page.getByTestId('preview-frame');
    const vw = page.viewportSize()!.width;
    const widths: Record<string, number> = {};
    for (const size of ['mobile', 'tablet', 'desktop']) {
        await page.getByTestId(`preview-${size}`).click();
        await expect(frameEl).toHaveAttribute('data-size', size);
        widths[size] = (await frameEl.boundingBox())!.width;
        expect(widths[size]).toBeLessThanOrEqual(vw);
    }
    expect(widths.mobile).toBeLessThanOrEqual(376);
    if (vw >= 900) {
        expect(widths.tablet).toBeGreaterThan(widths.mobile + 100);
        expect(widths.desktop).toBeGreaterThan(widths.tablet + 100);
    }
    await page.getByTestId('preview-mobile').click();
    const frame = page.frameLocator('[data-testid=preview-frame]');
    await expect(frame.getByRole('heading', { level: 1, name: 'Inicio' })).toBeVisible();
    await expect(frame.getByText('Vista previa del borrador')).toBeVisible();
    await expect(frame.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Contacto' })).toBeVisible();
    await expect(frame.locator('script')).toHaveCount(0);
    await expect(frame.getByText('<script>alert(1)</script>')).toHaveCount(1); // texto literal, escapado: nunca se ejecuta
    await frame.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Contacto' }).click();
    await expect(frame.getByRole('heading', { level: 1, name: 'Contacto' })).toBeVisible();
    await expectNoOverflow(page);

    // 4) Publicar y visitar como anónimo.
    await page.getByTestId('publish').click();
    await settle(page);
    await expect(page.getByTestId('flash')).toContainText('Sitio publicado');
    await expect(page.getByTestId('site-state')).toHaveText('Publicado');
    const publicUrl = (await page.getByTestId('public-url').textContent())!.trim();
    expect(publicUrl).toMatch(new RegExp(`/s/${slug}$`));

    const ctx = await anonymous(browser, info);
    const pub = await ctx.newPage();
    const pw = watch(pub);
    const res = (await pub.goto(`/s/${slug}`))!;
    expect(res.status()).toBe(200);
    const csp = res.headers()['content-security-policy'];
    expect(csp).toContain("default-src 'none'");
    expect(csp).not.toContain('unsafe-inline');
    expect(csp).toContain("frame-ancestors 'none'");
    expect(res.headers()['x-content-type-options']).toBe('nosniff');
    expect(res.headers()['set-cookie']).toBeUndefined();
    await settle(pub);
    await expect(pub.getByRole('heading', { level: 1, name: 'Inicio' })).toBeVisible();
    await expect(pub.getByRole('heading', { level: 2, name: 'Bienvenida al sitio E2E' })).toBeVisible();
    await expect(pub.locator('strong', { hasText: 'mundo' })).toBeVisible();
    await expect(pub.locator('script')).toHaveCount(0);
    expect(await imageLoaded(pub, 'Foto azul principal')).toBe(true);
    expect(await imageLoaded(pub, 'Foto azul de galería')).toBe(true);
    expect(await imageLoaded(pub, 'Cartel exportado del estudio')).toBe(true);
    await expect(pub.locator('iframe[src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"]')).toHaveCount(1);
    await expectNoOverflow(pub);
    await axeSeriousCritical(pub, 'Sitio público (portada)');

    // Interactivo sin JS: acordeón y encuesta con resultados agregados.
    await pub.getByText('¿Cuánto tarda?').click();
    await expect(pub.getByText('Dos días laborables.')).toBeVisible();
    await pub.getByLabel('Sí', { exact: true }).check();
    await pub.getByRole('button', { name: 'Votar' }).click();
    await settle(pub);
    await expect(pub.getByRole('status')).toContainText('Gracias por votar');
    await expect(pub.getByText('Sí: 1 voto (100 %)')).toBeVisible();
    await expect(pub.getByText('No: 0 votos (0 %)')).toBeVisible();
    await expectNoOverflow(pub);
    await axeSeriousCritical(pub, 'Sitio público (resultados de la encuesta)');

    // Navegación entre páginas y formulario de contacto propio.
    await pub.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Contacto' }).click();
    await expect(pub).toHaveURL(new RegExp(`/s/${slug}/contacto$`));
    await expect(pub.getByRole('heading', { level: 1, name: 'Contacto' })).toBeVisible();
    await pub.getByLabel('Nombre').fill('Ana Visitante');
    await pub.getByLabel('Correo electrónico').fill('ana@example.com');
    await pub.getByLabel('Mensaje').fill('Quiero más información');
    await expectNoOverflow(pub);
    await axeSeriousCritical(pub, 'Sitio público (contacto)');
    await pub.waitForTimeout(2500); // tiempo mínimo anti-bot
    await pub.getByRole('button', { name: 'Enviar' }).click();
    await settle(pub);
    await expect(pub.getByRole('status')).toContainText('hemos recibido tu mensaje');
    expect(pw.errors, 'errores de consola/CSP en el sitio público').toEqual([]);
    const noPage = await pub.goto(`/s/${slug}/no-existe`);
    expect(noPage!.status()).toBe(404);

    // 5) Editar sin que cambie lo publicado; republicar.
    await page.getByLabel('Texto del bloque 1', { exact: true }).fill('Titular nuevo en el borrador');
    await page.getByTestId('save-draft').click();
    await settle(page);
    await expect(page.getByTestId('dirty-published')).toBeVisible();
    await expect(page.getByTestId('site-state')).toContainText('con cambios sin publicar');
    await pub.goto(`/s/${slug}`);
    await expect(pub.getByRole('heading', { level: 2, name: 'Bienvenida al sitio E2E' })).toBeVisible();
    await expect(pub.getByText('Titular nuevo en el borrador')).toHaveCount(0);
    await page.getByTestId('publish').click();
    await settle(page);
    await pub.goto(`/s/${slug}`);
    await expect(pub.getByRole('heading', { level: 2, name: 'Titular nuevo en el borrador' })).toBeVisible();

    // Analítica agregada y mensaje recibido en el panel (sin IP).
    await page.reload();
    await settle(page);
    expect(Number(await page.getByTestId('visits-total').textContent())).toBeGreaterThanOrEqual(3);
    await expect(page.getByTestId('visits-by-page')).toContainText('Inicio');
    await expect(page.getByTestId('visits-by-page')).toContainText('Contacto');
    await expect(page.getByTestId('messages')).toContainText('Quiero más información');
    await expectNoOverflow(page);

    // 6) Proteger con contraseña.
    await page.getByTestId('access-mode').selectOption('password');
    await page.getByTestId('access-password').fill('clave-e2e-segura');
    await page.getByTestId('access-save').click();
    await settle(page);
    await expect(page.getByTestId('flash')).toContainText('Acceso actualizado');
    const gate = (await pub.goto(`/s/${slug}`))!;
    expect(gate.status()).toBe(401);
    await expect(pub.getByRole('heading', { name: 'Contenido protegido' })).toBeVisible();
    await expect(pub.getByText('Bienvenida')).toHaveCount(0);
    await expectNoOverflow(pub);
    await axeSeriousCritical(pub, 'Sitio protegido (contraseña)');
    await pub.getByLabel('Contraseña').fill('equivocada');
    await pub.getByRole('button', { name: 'Entrar' }).click();
    await expect(pub.getByText('Contraseña incorrecta.')).toBeVisible();
    await pub.getByLabel('Contraseña').fill('clave-e2e-segura');
    await pub.getByRole('button', { name: 'Entrar' }).click();
    await expect(pub.getByRole('heading', { level: 2, name: 'Titular nuevo en el borrador' })).toBeVisible();
    expect((await pub.goto(`/s/${slug}/contacto`))!.status()).toBe(200);

    // 7) Por enlace con token: cambiar el modo cierra la sesión; el token se canjea sin quedar en la URL; revocar es inmediato.
    await page.getByTestId('access-mode').selectOption('token');
    await page.getByTestId('access-save').click();
    await settle(page);
    expect((await pub.goto(`/s/${slug}/contacto`))!.status()).toBe(403);
    await page.getByTestId('token-label').fill('Cliente E2E');
    await page.getByTestId('token-create').click();
    await settle(page);
    const tokenUrl = await page.getByTestId('new-token-url').inputValue();
    expect(tokenUrl).toContain('?t=st_');
    await expect(page.getByTestId('token-list')).toContainText('Cliente E2E');

    const ctx2 = await anonymous(browser, info);
    const viaToken = await ctx2.newPage();
    await viaToken.goto(tokenUrl);
    await expect(viaToken).toHaveURL(new RegExp(`/s/${slug}$`));
    expect(viaToken.url()).not.toContain('st_');
    await expect(viaToken.getByRole('heading', { level: 2, name: 'Titular nuevo en el borrador' })).toBeVisible();
    await viaToken.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Contacto' }).click();
    await expect(viaToken.getByRole('heading', { level: 1, name: 'Contacto' })).toBeVisible();

    await page.getByRole('button', { name: 'Revocar el enlace Cliente E2E' }).click();
    await settle(page);
    await expect(page.getByTestId('token-list')).toContainText('Revocado');
    const denied = await viaToken.reload();
    expect(denied!.status()).toBe(403);
    await expect(viaToken.getByText('Necesitas un enlace de acceso válido')).toBeVisible();
    expect((await viaToken.goto(tokenUrl))!.status()).toBe(403);
    await axeSeriousCritical(viaToken, 'Sitio protegido (token denegado)');

    // 8) Despublicar: 404 inmediato.
    await page.getByTestId('access-mode').selectOption('public');
    await page.getByTestId('access-noindex').check();
    await page.getByTestId('access-save').click();
    await settle(page);
    const noindexed = await pub.goto(`/s/${slug}`);
    expect(noindexed!.status()).toBe(200);
    await expect(pub.locator('meta[name=robots]')).toHaveAttribute('content', 'noindex, nofollow');
    await page.getByTestId('unpublish').click();
    await settle(page);
    await expect(page.getByTestId('site-state')).toHaveText('Borrador');
    expect((await pub.goto(`/s/${slug}`))!.status()).toBe(404);
    expect((await viaToken.goto(`/s/${slug}/contacto`))!.status()).toBe(404);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Sitios (panel de publicación)');
    await ctx.close();
    await ctx2.close();
    expect(w.errors, 'errores de consola en el editor').toEqual([]);
});

test('E2E-15b · Sitios: la lista y el editor son responsive y accesibles sin sitios ni bloques', async ({ page }) => {
    const w = watch(page);
    await page.goto(`/sites?brand_id=${brand.id}`);
    await settle(page);
    await expect(page.getByText('Todavía no tienes ningún sitio')).toBeVisible();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Sitios (lista vacía)');
    await page.getByTestId('new-site-title').fill('Vacío');
    await page.getByTestId('new-site-slug').fill('AB');
    await page.getByRole('button', { name: 'Crear sitio' }).click();
    await settle(page);
    await expect(page.getByText(/Usa de 3 a 40 caracteres/).first()).toBeVisible();
    expect(w.errors).toEqual([]);
});
