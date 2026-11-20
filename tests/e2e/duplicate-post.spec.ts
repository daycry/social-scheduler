import { expect, test } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

test.describe('Duplicar publicación', { tag: '@responsive' }, () => {
    let origin: ComposerBrand;
    let destination: ComposerBrand;
    let sourceId: number;
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        origin = seedComposerBrand(user.email, `duplicate-origin-${stamp()}`);
        destination = seedComposerBrand(user.email, `duplicate-destination-${stamp()}`);
        const output = artisan(String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${origin.id});
            $u=App\Models\User::findOrFail($b->user_id);
            $a=App\Models\SocialAccount::ownedBy($u->id)->where('brand_id',$b->id)->where('network','facebook')->firstOrFail();
            $p=App\Models\Post::factory()->for($u)->for($b)->create(['text'=>'Original para duplicar','status'=>App\Enums\PostStatus::Published,'scheduled_at'=>now()->subDay()]);
            $t=App\Models\PostTarget::factory()->for($u)->for($p)->create(['social_account_id'=>$a->id,'network'=>App\Connectors\Network::Facebook,'content_type'=>App\Enums\ContentType::FacebookText,'status'=>App\Enums\PostStatus::Published,'published_at'=>now()->subDay(),'external_id'=>'original-published']);
            App\Models\PostMetricSnapshot::withoutUserScope()->create(['user_id'=>$u->id,'post_target_id'=>$t->id,'social_account_id'=>$a->id,'network'=>'facebook','date'=>now()->subDay()->toDateString(),'metrics'=>array_replace(App\Analytics\Metric::blankPost(),[App\Analytics\Metric::INTERACTIONS=>42])]);
            echo 'POST:'.$p->id;`);
        sourceId = Number(output.match(/POST:(\d+)/)![1]);
        await loginAs(page, user);
    });
    test.afterEach(async ({}, info) => cleanComposerBrands(e2eUser(info.project.name).email));

    test('E2E-17 · duplica una publicada a otra marca y permite editar sin alterar el original', async ({
        page,
    }, info) => {
        const errors = watch(page);
        await page.goto(`/posts/${sourceId}/edit`);
        await expect(page.getByRole('button', { name: 'Duplicar', exact: true })).toBeEnabled();
        await page.screenshot({ path: info.outputPath('duplicate-original.png'), fullPage: true });
        await page.getByRole('button', { name: 'Duplicar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Duplicar publicación', exact: true });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Marca de destino', { exact: true }).selectOption(String(destination.id));
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Diálogo de duplicación');
        await dialog.getByRole('button', { name: 'Crear borrador duplicado', exact: true }).click();
        await page.waitForURL(
            (url) => /\/posts\/\d+\/edit$/.test(url.pathname) && !url.pathname.includes(`/posts/${sourceId}/`),
        );
        const copyId = Number(new URL(page.url()).pathname.match(/posts\/(\d+)/)![1]);
        await expect(page.locator('#text')).toHaveValue('Original para duplicar');
        await expect(
            page.getByRole('heading', { name: `Editar publicación · ${destination.name}`, exact: true }),
        ).toBeVisible();
        await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', '');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Borrador duplicado');
        await page.screenshot({ path: info.outputPath('duplicate-draft.png'), fullPage: true });
        await page.locator('#text').fill('Solo cambia el duplicado');
        await page.getByRole('button', { name: 'Más acciones de publicación', exact: true }).click();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'PUT' && new URL(response.url()).pathname === `/posts/${copyId}`,
            ),
            page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click(),
        ]);
        await expect(page.locator('#text')).toHaveValue('Solo cambia el duplicado');
        await expect(page.getByRole('button', { name: 'Duplicar', exact: true })).toBeEnabled();
        const state = artisan(
            String.raw`$s=App\Models\Post::withoutUserScope()->findOrFail(${sourceId}); $c=App\Models\Post::withoutUserScope()->findOrFail(${copyId}); $t=$c->targets()->sole(); echo 'STATE:'.json_encode(['original_text'=>$s->text,'original_status'=>$s->status->value,'copy_text'=>$c->text,'copy_status'=>$c->status->value,'copy_scheduled'=>$c->scheduled_at,'brand_id'=>$c->brand_id,'external_id'=>$t->external_id,'metrics'=>App\Models\PostMetricSnapshot::withoutUserScope()->where('post_target_id',$t->id)->count(),'original_metrics'=>App\Models\PostMetricSnapshot::withoutUserScope()->whereIn('post_target_id',$s->targets()->pluck('id'))->count()]);`,
        );
        expect(JSON.parse(state.match(/STATE:(\{.*\})/)![1])).toEqual({
            original_text: 'Original para duplicar',
            original_status: 'publicada',
            copy_text: 'Solo cambia el duplicado',
            copy_status: 'borrador',
            copy_scheduled: null,
            brand_id: destination.id,
            external_id: null,
            metrics: 0,
            original_metrics: 1,
        });
        expect(errors.errors).toEqual([]);
    });

    test('E2E-17 · cancelar devuelve el foco y los cambios pendientes impiden duplicar una versión antigua', async ({
        page,
    }) => {
        artisan(
            String.raw`$p=App\Models\Post::withoutUserScope()->findOrFail(${sourceId}); $p->forceFill(['status'=>App\Enums\PostStatus::Draft,'scheduled_at'=>null])->save(); $p->targets()->get()->each(fn($t)=>$t->forceFill(['status'=>App\Enums\PostStatus::Draft])->save());`,
        );
        await page.goto(`/posts/${sourceId}/edit`);
        const button = page.getByRole('button', { name: 'Duplicar', exact: true });
        await button.click();
        await page
            .getByRole('dialog', { name: 'Duplicar publicación', exact: true })
            .getByRole('button', { name: 'Cancelar', exact: true })
            .click();
        await expect(button).toBeFocused();
        await page.locator('#text').fill('Cambios aún sin guardar');
        await expect(button).toBeDisabled();
        await expect(page.getByText('Guarda los cambios antes de duplicar.', { exact: true })).toBeVisible();
        await expectNoOverflow(page);
        const output = artisan(
            String.raw`echo 'COUNT:'.App\Models\Post::withoutUserScope()->where('brand_id',${origin.id})->count();`,
        );
        expect(output).toContain('COUNT:1');
    });
});
