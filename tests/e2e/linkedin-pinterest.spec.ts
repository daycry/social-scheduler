import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch } from './helpers';
import { runRoot } from './isolated/runtime';

/**
 * T-18 · LinkedIn y Pinterest. Conectar una cuenta de cada red con el proveedor simulado del servidor E2E (el botón recorre
 * autorizar -> callback -> cuenta guardada, sin salir a ninguna red), componer con las opciones propias de cada red (visibilidad de
 * LinkedIn; tablero, título y enlace de Pinterest) y publicar contra la red simulada, que registra tipo, texto y opciones recibidos.
 * Datos `e2e-composer-*` del usuario E2E. OAuth y publicación reales quedan para el smoke del usuario.
 */
test.describe.configure({ mode: 'serial' });

let brand: { id: number; name: string };
let post: number;
let ids: { li: number; pi: number; liTarget: number; piTarget: number };

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `lipin-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

function seedPost() {
    seedExtraAccount(brand.id, 'linkedin', 'e2e LinkedIn');
    seedExtraAccount(brand.id, 'pinterest', 'e2e Pinterest');
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => 'lp.jpg', 'mime' => 'image/jpeg', 'path' => 'media/e2e/lp-' . uniqid() . '.jpg', 'width' => 400, 'height' => 400]); ` +
            String.raw`$im = imagecreatetruecolor(400, 400); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); ` +
            String.raw`$li = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'linkedin')->firstOrFail(); ` +
            String.raw`$pi = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'pinterest')->firstOrFail(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'Receta de tarta E2E', 'schedule' => false, 'media' => [$a->id], 'targets' => [` +
            String.raw`['account_id' => $li->id, 'content_type' => 'linkedin_image'], ['account_id' => $pi->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '549755885175']]]]); ` +
            String.raw`echo 'SEED:' . json_encode(['post' => $p->id, 'li' => $li->id, 'pi' => $pi->id, 'liTarget' => $p->targets->firstWhere('social_account_id', $li->id)->id, 'piTarget' => $p->targets->firstWhere('social_account_id', $pi->id)->id]);`,
    );
    const seed = JSON.parse(out.match(/SEED:(\{.*\})/)![1]);
    post = seed.post;
    ids = seed;
}

const region = (page: Page, name: string) => page.getByRole('region', { name, exact: true });

test('T-18 · Conexiones: LinkedIn y Pinterest se conectan con el proveedor simulado y quedan como cuentas conectadas', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    await page.goto(`/brands/${brand.id}/connections`);
    await settle(page);

    for (const [network, label] of [['linkedin', 'LinkedIn'], ['pinterest', 'Pinterest']] as const) {
        const card = page.locator(`section[data-network="${network}"]`);
        await expect(card.getByRole('heading', { name: label })).toBeVisible();
        await card.getByRole('button', { name: '+ Conectar', exact: true }).click();
        await page.waitForURL(new RegExp(`/brands/${brand.id}/connections`));
        await settle(page);
        await expect(page.locator(`section[data-network="${network}"]`)).toContainText(`T43 ${label}`);
        await expect(page.locator(`section[data-network="${network}"]`)).toContainText('Conectada');
    }
    const html = await page.content();
    expect(html).not.toContain('access_token');
    expect(html).not.toContain('t43-fake-access');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Conexiones con LinkedIn y Pinterest');
    // El 409 es la redireccion de Inertia al proveedor (X-Inertia-Location), no un fallo.
    expect(w.errors.filter((e) => !/409/.test(e)), w.errors.join('\n')).toEqual([]);
});

test('T-18 · compositor: opciones de LinkedIn y Pinterest, errores del tablero y publicar en simulado', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    seedPost();
    await page.goto(`/posts/${post}/edit`);
    await settle(page);

    // LinkedIn: visibilidad y bloqueo de comparticion.
    const li = region(page, 'Opciones de LinkedIn (e2e LinkedIn)');
    await expect(li).toBeVisible();
    await li.getByLabel('Visibilidad').selectOption('CONNECTIONS');
    await li.getByLabel('Impedir que se comparta de nuevo').check();

    // Pinterest: el tablero es obligatorio; quitarlo bloquea y lo explica.
    const pi = region(page, 'Opciones de Pinterest (e2e Pinterest)');
    await expect(pi.getByLabel('Tablero (obligatorio)')).toHaveValue('549755885175');
    await pi.getByLabel('Título del pin (por defecto, la primera línea del texto)').fill('Tarta de queso');
    await pi.getByLabel('Enlace de destino (opcional)').fill('http://inseguro.example.com');
    await expect(page.getByTestId('errors-panel')).toContainText('URL https valida', { timeout: 10_000 });
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    await pi.getByLabel('Enlace de destino (opcional)').fill('https://example.com/receta');
    await pi.getByLabel('Tablero (obligatorio)').selectOption({ label: 'Viajes e2e' });
    await expect(page.getByTestId('errors-panel')).toHaveText('', { timeout: 10_000 });
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'compositor con LinkedIn y Pinterest');

    // Publicar ahora: la red simulada recibe tipo, texto y opciones de cada destino.
    await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
    await page.getByRole('menuitem', { name: 'Publicar ahora', exact: true }).click();
    await page.waitForURL(/\/calendar/);
    artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'publish','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
    const status = artisan(String.raw`echo 'ST:' . App\Models\PostTarget::withoutUserScope()->where('post_id', ${post})->get()->map(fn ($t) => $t->status->value . '/' . $t->error_message)->implode(',');`);
    expect(status, status).toMatch(/^ST:publicada\/,publicada\/$/m);

    const records = JSON.parse(fs.readFileSync(path.join(runRoot, 'externals.json'), 'utf8')).records;
    expect(records[String(ids.liTarget)]).toMatchObject({ type: 'linkedin_image', text: 'Receta de tarta E2E', options: { linkedin_visibility: 'CONNECTIONS', linkedin_disable_reshare: true } });
    expect(records[String(ids.piTarget)]).toMatchObject({
        type: 'pinterest_pin',
        options: { pinterest_board_id: '549755885176', pinterest_title: 'Tarta de queso', pinterest_link: 'https://example.com/receta' },
    });
    expect(w.errors.filter((e) => !/422/.test(e)), w.errors.join('\n')).toEqual([]);
});

test('T-18 · compositor: LinkedIn y Pinterest se ofrecen con su contador de caracteres propio y sin desbordar', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    seedPost();
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
    await expect(page.getByRole('button', { name: /^LinkedIn: e2e LinkedIn/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Pinterest: e2e Pinterest/ })).toBeVisible();
    await page.getByRole('button', { name: /^LinkedIn: e2e LinkedIn/ }).click();
    await page.getByRole('button', { name: /^Pinterest: e2e Pinterest/ }).click();
    await page.locator('#text').fill('Hola desde LinkedIn y Pinterest');
    const lc = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'LinkedIn' });
    await expect(lc.getByTestId('counter-chars')).toHaveText(/^31 \/ 3[.,]?000$/);
    const pc = page.getByTestId('network-counters').getByRole('listitem').filter({ hasText: 'Pinterest' });
    await expect(pc.getByTestId('counter-chars')).toHaveText('31 / 800');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'compositor con contadores de LinkedIn y Pinterest');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
