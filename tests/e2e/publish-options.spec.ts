import { expect, test, type Page } from '@playwright/test';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch } from './helpers';

/**
 * T-34 · Opciones de publicación: lugar (`location_id` de Instagram y `place` de Facebook, con la búsqueda de Meta o el id pegado a mano)
 * y editor de `user_tags` de Instagram. La búsqueda de lugares se intercepta en el navegador (`page.route`): ninguna petición llega a Meta.
 * La publicación real (location_id y user_tags) la valida el usuario en el smoke (CS-M-05, M-04).
 */
test.describe.configure({ mode: 'serial' });

let brand: { id: number; name: string };
let post: number;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `pubopt-${stamp()}-${info.project.name}`);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

function seedPost() {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => 'po.jpg', 'mime' => 'image/jpeg', 'path' => 'media/e2e/po-' . uniqid() . '.jpg', 'width' => 400, 'height' => 400]); ` +
            String.raw`$im = imagecreatetruecolor(400, 400); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$fb = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'Opciones de publicacion E2E', 'schedule' => false, 'media' => [$a->id], 'targets' => [` +
            String.raw`['account_id' => $ig->id, 'content_type' => 'instagram_image'], ['account_id' => $fb->id, 'content_type' => 'facebook_photo']]]); ` +
            String.raw`echo 'SEED:' . $p->id;`,
    );
    post = Number(out.match(/SEED:(\d+)/)![1]);
}

const region = (page: Page, name: string) => page.getByRole('region', { name, exact: true });

test('T-34 · compositor: lugar con búsqueda de Meta, id a mano si Meta la restringe y etiquetas de usuario con coordenadas', { tag: '@responsive' }, async ({ page }) => {
    const w = watch(page);
    seedPost();
    // Instagram encuentra lugares; Facebook responde que la búsqueda no está disponible para esta app (permisos de Meta).
    await page.route('**/connections/*/places?*', async (route) => {
        const igSearch = route.request().url().includes('q=madrid');
        if (igSearch) {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ places: [{ id: '106078429431815', name: 'Madrid', detail: 'Madrid, Spain' }, { id: '222', name: 'Café Central', detail: null }] }) });
        } else {
            await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Meta no permite buscar lugares con esta aplicación (hace falta la función «Page Public Metadata Access», aprobada en la revisión de Meta). Pega el id del lugar a mano.' }) });
        }
    });
    await page.goto(`/posts/${post}/edit`);
    await settle(page);

    const ig = region(page, 'Opciones de Instagram (e2e Instagram)');
    await expect(ig).toBeVisible();
    await ig.getByLabel('Ubicación (opcional)', { exact: true }).fill('madrid');
    await ig.getByRole('button', { name: 'Buscar lugar' }).click();
    await ig.getByRole('button', { name: /Madrid.*id 106078429431815/ }).click();
    await expect(ig.getByTestId(/^place-location_id-\d+-chosen$/)).toContainText('Madrid (id 106078429431815)');
    await expect(ig.getByLabel('O pega el id del lugar')).toHaveValue('106078429431815');

    // user_tags: se escribe el usuario y se pulsa sobre la imagen; las coordenadas quedan visibles y editables.
    const tags = ig.getByTestId('instagram-user-tags');
    await tags.getByLabel('Usuario de Instagram a etiquetar').fill('@Ana.Ruiz');
    const canvas = tags.getByTestId(/-canvas$/);
    const box = (await canvas.boundingBox())!;
    await canvas.click({ position: { x: box.width * 0.25, y: box.height * 0.75 } });
    await expect(tags.getByTestId('ig-tags-' + (await canvas.getAttribute('data-testid'))!.match(/\d+/)![0] + '-marker-ana.ruiz')).toBeVisible();
    await expect(tags.getByLabel('Posición x de ana.ruiz (0 a 1)')).toHaveValue(/^0\.2[45]\d*$/);
    await tags.getByLabel('Usuario de Instagram a etiquetar').fill('no es un usuario');
    await tags.getByRole('button', { name: 'Añadir en el centro' }).click();
    await expect(tags.getByRole('alert')).toContainText('usuario de Instagram válido');

    // Facebook: la búsqueda no está disponible; el motivo se explica y el id se pega a mano.
    const fb = region(page, 'Opciones de Facebook (e2e Facebook)');
    await fb.getByLabel('Lugar (opcional)', { exact: true }).fill('barcelona');
    await fb.getByRole('button', { name: 'Buscar lugar' }).click();
    await expect(fb.getByRole('alert')).toContainText('Page Public Metadata Access');
    await fb.getByLabel('O pega el id del lugar').fill('98765');
    await expect(fb.getByLabel('O pega el id del lugar')).toHaveValue('98765');

    await page.mouse.move(0, 0);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'compositor con lugar y etiquetas de usuario');

    const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/posts\/\d+$/.test(new URL(r.url()).pathname));
    await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
    await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
    await saved;
    await settle(page);

    const stored = artisan(String.raw`echo 'OPT:' . json_encode(App\Models\PostTarget::withoutUserScope()->where('post_id', ${post})->orderBy('id')->get()->map(fn ($t) => $t->options)->all());`);
    const options = JSON.parse(stored.match(/OPT:(.*)$/m)![1]);
    expect(options[0].location_id).toBe('106078429431815');
    expect(options[0].user_tags).toEqual([{ username: 'ana.ruiz', x: expect.closeTo(0.25, 1), y: expect.closeTo(0.75, 1) }]);
    expect(options[1].place).toBe('98765');

    await page.reload();
    await settle(page);
    await expect(region(page, 'Opciones de Instagram (e2e Instagram)').getByLabel('Posición x de ana.ruiz (0 a 1)')).toBeVisible();
    await expect(region(page, 'Opciones de Facebook (e2e Facebook)').getByLabel('O pega el id del lugar')).toHaveValue('98765');
    expect(w.errors.filter((e) => !/422/.test(e)), w.errors.join('\n')).toEqual([]);
});
