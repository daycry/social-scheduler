import { expect, test } from '@playwright/test';
import { artisan, chooseContentType, cleanComposerBrands, e2eUser, loginAs, seedComposerBrand, settle, stamp } from './helpers';

for (const mode of ['create', 'edit'] as const) {
    test(`Composer refresh: ${mode} retains rejected work and starts another publication cleanly`, async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        const brand = seedComposerBrand(user.email, `refresh-${mode}-${stamp()}-${info.project.name}`);
        try {
            const seeded = artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
                String.raw`$m = App\Models\MediaAsset::factory()->for($u)->create(['mime'=>'image/png','original_name'=>'foto.png']); Illuminate\Support\Facades\Storage::disk($m->disk)->put($m->path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGQAAAAASUVORK5CYII=')); echo 'ASSET:'.base64_encode(json_encode($m->toPublicArray()));`);
            const asset = JSON.parse(Buffer.from(seeded.match(/ASSET:([A-Za-z0-9+/=]+)/)![1], 'base64').toString());
            const postId = mode === 'edit' ? artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
                String.raw`$a = App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','facebook')->firstOrFail(); $p = app(App\Services\PostService::class)->create($u,$b,['text'=>'Borrador anterior','schedule'=>false,'targets'=>[['account_id'=>$a->id,'content_type'=>'facebook_text']]]); echo 'POST:'.$p->id;`).match(/POST:(\d+)/)![1] : null;
            await loginAs(page, user);
            await page.goto(postId ? `/posts/${postId}/edit` : `/brands/${brand.id}/posts/create`);
            await settle(page);
            if (mode === 'create') await page.getByRole('button', { name: /^Facebook: e2e Facebook/ }).click();
            await chooseContentType(page, /Tipo de contenido en Facebook/, 'Foto');
            await page.getByLabel('Texto', { exact: true }).fill('Contenido guardado con su adjunto.');

            await page.route('**/media', route => route.request().method() === 'POST'
                ? route.fulfill({ status: 201, json: { asset, upload: { mode: 'local', url: `/media/${asset.id}/upload` } } })
                : route.continue());
            await page.route(`**/media/${asset.id}/upload`, route => route.fulfill({ json: { asset } }));
            const validated = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/posts/validate') && r.request().postDataJSON().media?.includes(asset.id));
            await page.locator('input[type="file"]').setInputFiles({ name: asset.name, mimeType: asset.mime, buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGQAAAAASUVORK5CYII=', 'base64') });
            const preview = page.locator('aside img');
            await expect(preview).toHaveAttribute('src', /^blob:/);
            const temporaryPreview = await preview.getAttribute('src');
            const url = page.getByLabel('Desde URL', { exact: true });
            await url.fill('https://example.invalid/siguiente-imagen.jpg');
            // Finish debounced validation before changing the fixture behind the browser's back.
            await validated;
            await settle(page);

            const save = async () => {
                const response = page.waitForResponse(r => r.request().method() === (mode === 'create' ? 'POST' : 'PUT') && /\/(brands\/\d+\/posts|posts\/\d+)$/.test(new URL(r.url()).pathname));
                await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
                await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
                await response;
                await settle(page);
            };

            // A server-side validation failure must preserve the attachment and unfinished input.
            artisan(String.raw`App\Models\MediaAsset::withoutUserScope()->findOrFail(${asset.id})->forceFill(['status'=>'invalido','error'=>'Fixture invalidated'])->save();`);
            await save();
            await expect(page.getByTestId('errors-panel').getByTestId('error-item').first()).toBeVisible();
            await expect(url).toHaveValue('https://example.invalid/siguiente-imagen.jpg');
            await expect(preview).toHaveAttribute('src', temporaryPreview!);

            artisan(String.raw`App\Models\MediaAsset::withoutUserScope()->findOrFail(${asset.id})->forceFill(['status'=>'listo','error'=>null])->save();`);
            await save();
            await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
            await expect(url).toHaveValue(mode === 'create' ? '' : 'https://example.invalid/siguiente-imagen.jpg');
            await expect(preview).toHaveAttribute('src', mode === 'create' ? asset.preview_url : temporaryPreview!);
            await expect(page.getByLabel('Texto', { exact: true })).toHaveValue('Contenido guardado con su adjunto.');
            await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);

            await page.goto(`/brands/${brand.id}/posts/create`);
            await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(0);
            await expect(page.locator('aside img')).toHaveCount(0);
            await expect(page.getByLabel('Texto', { exact: true })).toHaveValue('');
        } finally {
            cleanComposerBrands(user.email);
        }
    });
}
