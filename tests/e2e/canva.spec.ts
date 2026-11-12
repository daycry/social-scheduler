import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';
import { runRoot } from './isolated/runtime';

// T-16. Canva FALSO dentro del servidor desechable: con la bandera `canva-on` el bootstrap E2E define credenciales ficticias y atiende
// api.canva.com y export-download.canva.com con respuestas fijas (tests/e2e/isolated/fakes.php); sin la bandera la UI dice «no configurada».
// La autorizacion en canva.com se simula interceptando la navegacion (nunca sale a la red) y los trabajos se ejecutan con artisan
// (sin worker persistente). Un fake no acredita la autorizacion real de Canva: el smoke real lo hace el usuario.
// T-26: el `correlation_jwt` de retorno se verifica con el JWKS del fake (`/connect/keys`) y lo firma el propio fake (`generalCanvaJwt`).
// T-27 (E2E-39): el PDF exportado entra en la biblioteca como documento (no publicable) y Autofill sigue las capacidades del plan (`canva-templates`).
const flagOn = path.join(runRoot, 'canva-on');
const flagPro = path.join(runRoot, 'canva-pro');
const flagTemplates = path.join(runRoot, 'canva-templates');
const signedJwt = (claims: Record<string, unknown>) => artisan(`echo generalCanvaJwt('${runRoot.replace(/\\/g, '/')}', json_decode(base64_decode('${Buffer.from(JSON.stringify(claims)).toString('base64')}'), true));`);

test.afterEach(({}, info) => {
    fs.rmSync(flagOn, { force: true });
    fs.rmSync(flagPro, { force: true });
    fs.rmSync(flagTemplates, { force: true });
    const email = e2eUser(info.project.name).email;
    artisan(String.raw`$u = App\Models\User::where('email', '${email}')->first(); if ($u) { App\Models\MediaAsset::withoutUserScope()->where('user_id', $u->id)->where('source', 'canva')->get()->each(fn ($m) => app(App\Services\MediaUploadService::class)->delete($m)); foreach (['canva', 'canva-connect'] as $limiter) { Illuminate\Support\Facades\RateLimiter::clear(md5($limiter . $limiter . ':' . $u->id)); } }`);
    cleanComposerBrands(email);
});

const watchErrors = (page: Page) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => { if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(`console: ${message.text()}`); });
    return errors;
};
const axeClean = async (page: Page) => expect((await new AxeBuilder({ page }).analyze()).violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([]);

/** Conecta con el OAuth simulado: intercepta la ida a canva.com, comprueba PKCE/state y vuelve al callback con un codigo falso. */
async function connectCanva(page: Page, brandId: number) {
    let authorize = '';
    await page.route('https://www.canva.com/api/oauth/authorize**', async route => {
        authorize = route.request().url();
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Canva simulado</title><p>Autorización simulada</p>' });
    });
    await page.goto(`/brands/${brandId}/canva`);
    await page.getByRole('button', { name: 'Conectar con Canva' }).click();
    await expect.poll(() => authorize, { timeout: 15_000 }).not.toBe('');
    const query = new URL(authorize).searchParams;
    expect(query.get('code_challenge_method')).toBe('s256');
    expect(query.get('code_challenge')).toHaveLength(43);
    expect(query.get('scope')).toContain('design:content:read');
    expect(query.get('client_id')).toBe('e2e-canva-client');
    const state = query.get('state')!;
    await page.goto(`/canva/callback?code=e2e-code&state=${encodeURIComponent(state)}`);
    await expect(page.getByTestId('canva-connected')).toContainText('Cuenta Canva E2E');
    return state;
}

/** Lanza una accion que crea un trabajo de Canva (POST a `urlPart`) y devuelve la tarjeta de ESE trabajo, sin depender de su posicion en la lista. */
async function startedJob(page: Page, urlPart: string, action: () => Promise<void>) {
    const [response] = await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(urlPart)), action()]);
    return page.getByTestId(`canva-job-${(await response.json()).job.id}`);
}

/** Ejecuta los trabajos pendientes con artisan: Canva (crear y sondear la exportacion) y la descarga a la biblioteca. */
const runQueue = () => artisan(String.raw`for ($i = 0; $i < 40 && ($job = Illuminate\Support\Facades\Queue::connection('database')->pop('media')); $i++) { $job->fire(); } echo 'QUEUE:done';`);

test('Canva: sin credenciales la funcion aparece como no configurada y no ofrece conectar', { tag: '@responsive' }, async ({ page }, info) => {
    const errors = watchErrors(page);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `canva-off-${stamp()}-${info.project.name}`);
    await loginAs(page, owner);
    await page.goto(`/brands/${brand.id}/canva`);

    await expect(page.getByTestId('canva-unconfigured')).toContainText('Canva: no configurada');
    await expect(page.getByRole('button', { name: /Conectar con Canva|Crear en Canva|Exportar/ })).toHaveCount(0);
    const status = await page.evaluate(async id => (await fetch(`/brands/${id}/canva/status`, { headers: { Accept: 'application/json' } })).json(), brand.id);
    expect(status).toMatchObject({ configured: false, connected: false, status: 'not_configured' });
    await axeClean(page);
    await expectNoOverflow(page);
    expect(errors).toEqual([]);
});

test('Canva: conectar (OAuth simulado), plan, listar y buscar, crear, retorno firmado, exportar a la biblioteca con origen y desconectar', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(240_000);
    fs.writeFileSync(flagOn, 'Canva falso');
    const errors = watchErrors(page);
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `canva-${stamp()}-${info.project.name}`);
    await loginAs(page, owner);

    // 1. Conectar: PKCE, state de un solo uso (repetir el callback ya no conecta) y estado "conectado".
    const state = await connectCanva(page, brand.id);
    await page.goto(`/canva/callback?code=e2e-code&state=${encodeURIComponent(state)}`);
    await expect(page.getByRole('alert').filter({ hasText: 'ha caducado o no es válida' })).toBeVisible();

    // 2. Capacidades del plan: PNG transparente desactivado con su motivo (plan gratuito del fake) y plantillas de marca también.
    await page.goto(`/brands/${brand.id}/canva`);
    await expect(page.getByTestId('canva-feature-export')).toHaveAttribute('data-available', 'true');
    await expect(page.getByTestId('canva-feature-transparent_png')).toHaveAttribute('data-available', 'false');
    await expect(page.getByTestId('canva-feature-transparent_png')).toContainText('Requiere un plan de pago de Canva');
    await expect(page.getByTestId('canva-feature-brand_templates')).toContainText('No disponible');
    await expect(page.getByTestId('canva-feature-autofill')).toHaveAttribute('data-available', 'false');
    await expect(page.getByTestId('canva-templates')).toHaveCount(0); // sin capacidad del plan no se ofrecen plantillas ni Autofill

    // 3. Listar y buscar: los dos diseños; el filtro deja uno. «Abrir en Canva» es un enlace externo explícito.
    const rebajas = page.getByTestId('canva-design-DAFe2eRebaj1');
    await expect(rebajas).toContainText('Rebajas E2E');
    await expect(page.getByTestId('canva-design-DAFe2eHisto2')).toBeVisible();
    const open = rebajas.getByRole('link', { name: /Abrir en Canva/ });
    await expect(open).toHaveAttribute('target', '_blank');
    await expect(open).toHaveAttribute('rel', /noopener/);
    const href = (await open.getAttribute('href'))!;
    expect(href).toMatch(/^https:\/\/www\.canva\.com\/api\/design\/token-DAFe2eRebaj1\/edit\?correlation_state=/);
    await page.getByLabel('Buscar').fill('historia');
    await page.getByRole('button', { name: 'Buscar', exact: true }).click();
    await expect(page.getByTestId('canva-design-DAFe2eHisto2')).toBeVisible();
    await expect(rebajas).toHaveCount(0);
    await page.getByLabel('Buscar').fill('');
    await page.getByRole('button', { name: 'Buscar', exact: true }).click();
    await expect(rebajas).toBeVisible();

    // 4. Crear en Canva con el tamaño de un formato social.
    await page.getByTestId('canva-create-format').selectOption('story');
    await page.getByRole('button', { name: 'Crear en Canva', exact: true }).click();
    const created = page.getByTestId('canva-created');
    await expect(created).toContainText('Diseño creado');
    await expect(created.getByRole('link', { name: /Abrir en Canva/ })).toHaveAttribute('target', '_blank');

    // 5. Retorno desde Canva: correlation_state firmado ligado a usuario y marca; el manipulado se rechaza.
    const correlation = new URL(href).searchParams.get('correlation_state')!;
    expect(correlation.length).toBeLessThanOrEqual(50);
    const jwt = (cs: string) => signedJwt({ correlation_state: cs });
    await page.goto(`/canva/return?correlation_jwt=${jwt(correlation)}`);
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.id}/canva\\?design=DAFe2eRebaj1`));
    await expect(page.getByTestId('canva-returned')).toBeVisible();
    await expect(page.getByTestId('canva-design-DAFe2eRebaj1')).toHaveClass(/border-violet-500/);
    await page.goto(`/canva/return?correlation_jwt=${jwt(correlation.slice(0, -3) + 'AAA')}`);
    await expect(page.getByRole('alert').filter({ hasText: 'retorno desde Canva no es válido' })).toBeVisible();
    // T-26: el estado correcto pero sin firma verificable (JWT sin firmar, otra audiencia o caducado) tampoco vale.
    const unsigned = `h.${Buffer.from(JSON.stringify({ type: 'rti', aud: 'e2e-canva-client', exp: Math.floor(Date.now() / 1000) + 3600, correlation_state: correlation })).toString('base64url')}.s`;
    for (const bad of [unsigned, signedJwt({ correlation_state: correlation, aud: 'otra-app' }), signedJwt({ correlation_state: correlation, exp: Math.floor(Date.now() / 1000) - 60 })]) {
        await page.goto(`/canva/return?correlation_jwt=${bad}`);
        await expect(page.getByRole('alert').filter({ hasText: 'retorno desde Canva no es válido' })).toBeVisible();
    }

    // 6. Exportar a la biblioteca: el trabajo queda en cola hasta que se ejecuta, luego importa un PNG real con su origen.
    await page.goto(`/brands/${brand.id}/canva`);
    await page.getByRole('button', { name: 'Exportar a la biblioteca' }).first().click();
    const job = page.locator('[data-testid^="canva-job-"]').first();
    await expect(job.getByRole('status')).toHaveText('En cola');
    await axeClean(page);
    await expectNoOverflow(page);
    expect(runQueue()).toContain('QUEUE:done');
    await expect(job.getByRole('status')).toHaveText('Listo', { timeout: 20_000 });
    const imported = job.getByTestId('canva-imported');
    await expect(imported).toContainText('Rebajas E2E.png');
    const origin = imported.getByTestId('canva-origin');
    await expect(origin).toHaveText('Origen: Canva');
    await expect(origin).toHaveAttribute('href', 'https://www.canva.com/design/DAFe2eRebaj1/edit');
    await expect(origin).toHaveAttribute('rel', /noopener/);

    // 7. En la biblioteca: PNG real, origen Canva con el id del diseño y ninguna publicación creada.
    const meta = JSON.parse(artisan(String.raw`$u = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id})->user_id; $m = App\Models\MediaAsset::withoutUserScope()->where('user_id', $u)->where('source', 'canva')->latest('id')->firstOrFail(); $d = Illuminate\Support\Facades\Storage::disk($m->disk); echo json_encode(['status' => $m->status, 'mime' => $m->mime, 'png' => str_starts_with($d->get($m->path), "\x89PNG"), 'design' => $m->origin['design_id'], 'posts' => App\Models\Post::withoutUserScope()->where('brand_id', ${brand.id})->count()]);`));
    expect(meta).toEqual({ status: 'listo', mime: 'image/png', png: true, design: 'DAFe2eRebaj1', posts: 0 });
    const listed = await page.evaluate(async () => (await (await fetch('/media', { headers: { Accept: 'application/json' } })).json()).media.find((m: { source: string }) => m.source === 'canva'));
    expect(listed.origin).toMatchObject({ label: 'Canva', design_id: 'DAFe2eRebaj1' });

    // 7b. PDF (E2E-39): entra en la biblioteca como documento con icono, vista previa y descarga; no es media publicable y se avisa con el aviso común.
    await page.goto(`/brands/${brand.id}/canva`);
    await page.getByTestId('canva-design-DAFe2eRebaj1').getByLabel('Formato de Rebajas E2E').selectOption('pdf');
    await expect(page.getByTestId('canva-design-DAFe2eRebaj1').getByLabel('Formato de Rebajas E2E')).toContainText('no se publica en redes');
    const pdfJob = await startedJob(page, '/canva/exports', () => page.getByTestId('canva-design-DAFe2eRebaj1').getByRole('button', { name: 'Exportar a la biblioteca' }).click());
    await expect(pdfJob.getByRole('status')).toHaveText('En cola');
    expect(runQueue()).toContain('QUEUE:done');
    await expect(pdfJob.getByRole('status')).toHaveText('Listo', { timeout: 20_000 });
    await expect(pdfJob.getByTestId('pdf-asset')).toContainText('Rebajas E2E.pdf');
    const documents = page.getByTestId('canva-documents');
    await expect(documents.getByTestId('permission-notice')).toContainText('Los PDF no se publican en redes');
    await expect(documents.getByTestId('pdf-asset')).toContainText('PDF ·');
    const download = documents.getByRole('link', { name: 'Descargar Rebajas E2E.pdf' });
    const pdf = await page.request.get((await download.getAttribute('href'))!);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-disposition']).toContain('attachment');
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
    const pdfMeta = JSON.parse(artisan(String.raw`$u = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id})->user_id; $m = App\Models\MediaAsset::withoutUserScope()->where('user_id', $u)->where('kind', 'pdf')->latest('id')->firstOrFail(); echo json_encode(['status' => $m->status, 'mime' => $m->mime, 'design' => $m->origin['design_id']]);`));
    expect(pdfMeta).toEqual({ status: 'listo', mime: 'application/pdf', design: 'DAFe2eRebaj1' });
    const publishable = await page.evaluate(async () => (await (await fetch('/media', { headers: { Accept: 'application/json' } })).json()).media.map((m: { kind: string }) => m.kind));
    expect(publishable).not.toContain('pdf');
    await axeClean(page);
    await expectNoOverflow(page);

    // 8. Desconectar borra las credenciales.
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Desconectar', exact: true }).click();
    await expect(page.getByTestId('canva-disconnected')).toBeVisible();
    expect(artisan(String.raw`echo 'CONN:' . App\Models\Canva\CanvaConnection::withoutUserScope()->where('brand_id', ${brand.id})->count();`)).toContain('CONN:0');
    await axeClean(page);
    expect(errors).toEqual([]);
});

test('Canva: con un plan de pago el PNG transparente se activa y la exportación lo pide', async ({ page }, info) => {
    fs.writeFileSync(flagOn, 'Canva falso');
    fs.writeFileSync(flagPro, 'plan de pago');
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `canva-pro-${stamp()}-${info.project.name}`);
    await loginAs(page, owner);
    await connectCanva(page, brand.id);

    await expect(page.getByTestId('canva-feature-transparent_png')).toHaveAttribute('data-available', 'true');
    const box = page.getByLabel('Fondo transparente de Rebajas E2E');
    await expect(box).toBeEnabled();
    await box.check();
    await page.getByTestId('canva-design-DAFe2eRebaj1').getByRole('button', { name: 'Exportar a la biblioteca' }).click();
    await expect(page.locator('[data-testid^="canva-job-"]').first()).toBeVisible();
    const options = artisan(String.raw`echo json_encode(App\Models\Canva\CanvaJob::withoutUserScope()->where('brand_id', ${brand.id})->latest('id')->firstOrFail()->options);`);
    expect(JSON.parse(options)).toEqual({ transparent: true });
});

test('Canva: Brand Templates y Autofill solo con la capacidad del plan; rellena una plantilla y ofrece el diseño nuevo', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(240_000);
    fs.writeFileSync(flagOn, 'Canva falso');
    fs.writeFileSync(flagTemplates, 'plan con plantillas de marca');
    const owner = e2eUser(info.project.name);
    cleanComposerBrands(owner.email);
    const brand = seedComposerBrand(owner.email, `canva-autofill-${stamp()}-${info.project.name}`);
    await loginAs(page, owner);
    await connectCanva(page, brand.id);

    await expect(page.getByTestId('canva-feature-brand_templates')).toHaveAttribute('data-available', 'true');
    await expect(page.getByTestId('canva-feature-autofill')).toHaveAttribute('data-available', 'true');
    const template = page.getByTestId('canva-template-BTe2eCiudad1');
    await expect(template).toContainText('Ciudad del día E2E');
    await template.getByRole('button', { name: 'Rellenar Ciudad del día E2E' }).click();

    const form = page.getByTestId('canva-autofill-form');
    await expect(form.getByLabel('CITY')).toBeVisible();
    await expect(form.getByLabel('TEMPERATURE')).toBeVisible();
    await expect(page.getByTestId('canva-autofill-skipped')).toContainText('BACKGROUND (image)'); // solo los campos de texto se rellenan
    await form.getByLabel('CITY').fill('Sídney');
    const job = await startedJob(page, '/canva/autofills', () => form.getByRole('button', { name: 'Crear diseño en Canva' }).click());
    await expect(job.getByRole('status')).toHaveText('En cola');
    expect(runQueue()).toContain('QUEUE:done');
    await expect(job.getByRole('status')).toHaveText('Listo', { timeout: 20_000 });
    await expect(job).toContainText('Rellenar plantilla');
    const open = job.getByTestId('canva-autofill-open');
    await expect(open).toHaveAttribute('target', '_blank');
    await expect(open).toHaveAttribute('href', /^https:\/\/www\.canva\.com\/api\/design\/token-DAFe2eAutof4\/edit\?correlation_state=/);
    const sent = JSON.parse(artisan(String.raw`echo json_encode(App\Models\Canva\CanvaJob::withoutUserScope()->where('brand_id', ${brand.id})->where('kind', 'autofill')->latest('id')->firstOrFail()->options);`));
    expect(sent).toEqual({ template_id: 'BTe2eCiudad1', fields: { CITY: 'Sídney' } });
    await page.mouse.move(0, 0); // sin el hover del boton principal (indigo-500 al pasar el raton queda en 4,46:1)
    await axeClean(page);
    await expectNoOverflow(page);
});
