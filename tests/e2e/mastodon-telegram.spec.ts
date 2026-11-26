import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch } from './helpers';
import { runRoot } from './isolated/runtime';

/**
 * T-87 · Mastodon y Telegram (E2E-26). Conectar cada red con el formulario de credenciales (el servidor E2E sustituye la llamada a la instancia y a la
 * Bot API por un proveedor simulado: no sale nunca a la red), componer con las opciones propias (visibilidad de Mastodon, contadores de cada red) y publicar
 * contra la red simulada, que registra tipo, texto y opciones recibidos. Datos `e2e-composer-*` del usuario E2E. Las APIs reales quedan para el smoke del usuario.
 */
test.describe.configure({ mode: 'serial' });

let brand: { id: number; name: string };

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `matg-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

const card = (page: Page, network: 'mastodon' | 'telegram') => page.locator(`section[data-network="${network}"]`);
const region = (page: Page, name: string) => page.getByRole('region', { name, exact: true });

/** Un borrador con texto e imagen común dirigido a las cuentas de Mastodon y Telegram de la marca (que ya existen). */
function seedPost() {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => 'mt.jpg', 'mime' => 'image/jpeg', 'path' => 'media/e2e/mt-' . uniqid() . '.jpg', 'width' => 400, 'height' => 400]); ` +
            String.raw`$im = imagecreatetruecolor(400, 400); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); ` +
            String.raw`$ma = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'mastodon')->firstOrFail(); ` +
            String.raw`$tg = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'telegram')->firstOrFail(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'Hola Mastodon y Telegram E2E', 'schedule' => false, 'media' => [$a->id], 'targets' => [` +
            String.raw`['account_id' => $ma->id, 'content_type' => 'mastodon_post'], ['account_id' => $tg->id, 'content_type' => 'telegram_photo']]]); ` +
            String.raw`echo 'SEED:' . json_encode(['post' => $p->id, 'maTarget' => $p->targets->firstWhere('social_account_id', $ma->id)->id, 'tgTarget' => $p->targets->firstWhere('social_account_id', $tg->id)->id]);`,
    );
    return JSON.parse(out.match(/SEED:(\{.*\})/)![1]) as { post: number; maTarget: number; tgTarget: number };
}

test.describe('Mastodon y Telegram', { tag: '@responsive' }, () => {
    test('E2E-26 · conectar Mastodon y Telegram con credenciales de prueba y publicar un post en las dos', async ({ page }, info) => {
        const w = watch(page);
        await page.goto(`/brands/${brand.id}/connections`);
        await settle(page);
        await expect(card(page, 'mastodon')).toContainText('Sin cuentas conectadas');
        await expect(card(page, 'telegram')).toContainText('Sin cuentas conectadas');
        await page.screenshot({ path: info.outputPath('mastodon-telegram-inicial.png'), fullPage: true });

        // Mastodon: instancia + token de acceso (el formulario es el mismo que el de Bluesky, con los campos del conector).
        await card(page, 'mastodon').getByRole('button', { name: '+ Conectar' }).click();
        await card(page, 'mastodon').getByLabel('Instancia de Mastodon').fill('mastodon.e2e.invalid');
        await card(page, 'mastodon').getByLabel('Token de acceso').fill('e2e-mastodon-token-0123456789');
        await card(page, 'mastodon').getByRole('button', { name: 'Conectar Mastodon' }).click();
        await settle(page);
        await expect(card(page, 'mastodon')).toContainText('T43 Mastodon');
        await expect(card(page, 'mastodon')).toContainText('Conectada');

        // Telegram: token del bot + canal.
        await card(page, 'telegram').getByRole('button', { name: '+ Conectar' }).click();
        await card(page, 'telegram').getByLabel('Token del bot').fill('123456789:E2E-fake-token-abcdefghijklmnop');
        await card(page, 'telegram').getByLabel('Canal').fill('@e2ecanal');
        await card(page, 'telegram').getByRole('button', { name: 'Conectar Telegram' }).click();
        await settle(page);
        await expect(card(page, 'telegram')).toContainText('T43 Telegram');
        await expect(card(page, 'telegram')).toContainText('Conectada');
        const html = await page.content();
        expect(html).not.toContain('e2e-mastodon-token');
        expect(html).not.toContain('E2E-fake-token');
        await expectNoOverflow(page);

        // Componer: la visibilidad de Mastodon es una opción del destino y los contadores usan el límite de cada red.
        const seed = seedPost();
        await page.goto(`/posts/${seed.post}/edit`);
        await settle(page);
        const options = region(page, 'Opciones de Mastodon (T43 Mastodon)');
        await expect(options).toBeVisible();
        await expect(options).toContainText('hasta 500 caracteres y 4 imágenes');
        await options.getByLabel('Visibilidad').selectOption({ label: 'Pública sin listar' });
        const counters = page.getByTestId('network-counters');
        await expect(counters.getByRole('listitem').filter({ hasText: 'Mastodon' }).getByTestId('counter-chars')).toHaveText('28 / 500');
        await expect(counters.getByRole('listitem').filter({ hasText: 'Telegram' }).getByTestId('counter-chars')).toHaveText(/^28 \/ 1[.,]?024$/);
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'compositor con Mastodon y Telegram');

        // Publicar ahora: la red simulada recibe tipo, texto y opciones, y los dos destinos quedan publicados.
        await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
        await page.getByRole('menuitem', { name: 'Publicar ahora', exact: true }).click();
        await page.waitForURL(/\/calendar/);
        artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'publish','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
        const status = artisan(String.raw`echo 'ST:' . App\Models\PostTarget::withoutUserScope()->where('post_id', ${seed.post})->get()->map(fn ($t) => $t->status->value . '/' . $t->error_message)->implode(',');`);
        expect(status, status).toMatch(/^ST:publicada\/,publicada\/$/m);

        const records = JSON.parse(fs.readFileSync(path.join(runRoot, 'externals.json'), 'utf8')).records;
        expect(records[String(seed.maTarget)]).toMatchObject({ type: 'mastodon_post', text: 'Hola Mastodon y Telegram E2E', options: { mastodon_visibility: 'unlisted' } });
        expect(records[String(seed.tgTarget)]).toMatchObject({ type: 'telegram_photo', text: 'Hola Mastodon y Telegram E2E' });
        await settle(page);
        await page.screenshot({ path: info.outputPath('mastodon-telegram-final.png'), fullPage: true });
        expect(w.errors.filter((e) => !/409|422/.test(e)), w.errors.join('\n')).toEqual([]);
    });

    test('E2E-26 · Conexiones: los formularios de Mastodon y Telegram son accesibles, piden secretos como contraseña y no desbordan', async ({ page }) => {
        const w = watch(page);
        await page.goto(`/brands/${brand.id}/connections`);
        await settle(page);

        await card(page, 'mastodon').getByRole('button', { name: '+ Conectar' }).click();
        const instance = card(page, 'mastodon').getByLabel('Instancia de Mastodon');
        const token = card(page, 'mastodon').getByLabel('Token de acceso');
        await expect(instance).toHaveJSProperty('required', true);
        await expect(token).toHaveAttribute('type', 'password');
        await expect(token).toHaveAttribute('autocomplete', 'new-password');
        await expect(card(page, 'mastodon')).toContainText('write:statuses, write:media y read:accounts');

        await card(page, 'telegram').getByRole('button', { name: '+ Conectar' }).click();
        const bot = card(page, 'telegram').getByLabel('Token del bot');
        await expect(bot).toHaveAttribute('type', 'password');
        await expect(card(page, 'telegram').getByLabel('Canal')).toHaveJSProperty('required', true);
        await expect(card(page, 'telegram')).toContainText('BotFather');
        await expect(card(page, 'telegram')).toContainText('administrador del canal');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Conexiones con los formularios de Mastodon y Telegram');

        // Cancelar cierra el formulario y borra lo escrito.
        await bot.fill('123456789:no-se-guarda');
        await card(page, 'telegram').getByRole('button', { name: 'Cancelar' }).click();
        await expect(bot).toHaveCount(0);
        await card(page, 'telegram').getByRole('button', { name: '+ Conectar' }).click();
        await expect(card(page, 'telegram').getByLabel('Token del bot')).toHaveValue('');
        expect(w.errors, w.errors.join('\n')).toEqual([]);
    });

    test('E2E-26 · compositor: Mastodon y Telegram se ofrecen con el contador de su red y sin desbordar', async ({ page }) => {
        const w = watch(page);
        seedExtraAccount(brand.id, 'mastodon', 'e2e Mastodon');
        seedExtraAccount(brand.id, 'telegram', 'e2e Telegram');
        await page.goto(`/brands/${brand.id}/posts/create`);
        await settle(page);
        await expect(page.getByRole('button', { name: /^Mastodon: e2e Mastodon/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Telegram: e2e Telegram/ })).toBeVisible();
        await page.getByRole('button', { name: /^Mastodon: e2e Mastodon/ }).click();
        await page.getByRole('button', { name: /^Telegram: e2e Telegram/ }).click();
        await page.locator('#text').fill('Hola desde Mastodon y Telegram');
        const counters = page.getByTestId('network-counters').getByRole('listitem');
        await expect(counters.filter({ hasText: 'Mastodon' }).getByTestId('counter-chars')).toHaveText('30 / 500');
        await expect(counters.filter({ hasText: 'Telegram' }).getByTestId('counter-chars')).toHaveText(/^30 \/ 4[.,]?096$/);
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'compositor con contadores de Mastodon y Telegram');
        expect(w.errors, w.errors.join('\n')).toEqual([]);
    });
});
