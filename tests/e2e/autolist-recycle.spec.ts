import { expect, test } from '@playwright/test';
import {
    artisan,
    axeSeriousCritical,
    cleanComposerBrands,
    e2eUser,
    expectNoOverflow,
    loginAs,
    seedComposerBrand,
    settle,
    stamp,
    watch,
    type ComposerBrand,
} from './helpers';

/**
 * T-92 · Reciclado inteligente de mejores posts (E2E-31). La marca E2E tiene cinco publicadas con métricas sembradas (sin red): solo una
 * cumple a la vez visualizaciones suficientes, madurez y el intervalo mínimo; las demás aparecen en «Fuera de las propuestas» con su motivo.
 * «Reciclar» crea un borrador (nada se publica) y la propuesta desaparece. Datos `e2e-composer-*` del usuario E2E.
 */
test.describe.configure({ mode: 'serial' });

/** Publicada hace `days` días en Instagram con su último snapshot (`views` null = sin snapshot); `recycledDraft` = ya hay un reciclado creado hoy. */
function seedPublished(
    brandId: number,
    text: string,
    days: number,
    views: number | null,
    interactions: number,
    recycledDraft = false,
) {
    artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); ` +
            String.raw`$u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$a = App\Models\SocialAccount::ownedBy($u->id)->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); ` +
            String.raw`$p = App\Models\Post::factory()->for($u)->for($b)->create(['text' => '${text}', 'scheduled_at' => null, 'status' => App\Enums\PostStatus::Published]); ` +
            String.raw`$t = App\Models\PostTarget::factory()->for($u)->for($p)->create(['social_account_id' => $a->id, 'network' => App\Connectors\Network::Facebook, 'content_type' => App\Enums\ContentType::FacebookText, 'status' => App\Enums\PostStatus::Published, 'published_at' => now()->subDays(${days})]); ` +
            (views === null
                ? ''
                : String.raw`App\Models\PostMetricSnapshot::withoutUserScope()->create(['user_id' => $u->id, 'post_target_id' => $t->id, 'social_account_id' => $a->id, 'network' => 'facebook', 'date' => now()->subDay()->toDateString(), 'metrics' => array_replace(App\Analytics\Metric::blankPost(), ['views' => ${views}, 'interactions' => ${interactions}])]); `) +
            (recycledDraft
                ? String.raw`App\Models\Post::factory()->for($u)->for($b)->create(['text' => '${text}', 'scheduled_at' => null, 'status' => App\Enums\PostStatus::Draft, 'recycle_family_id' => $p->id]);`
                : ''),
    );
}

test.describe('Reciclado de posts', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;

    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `recycle-${stamp()}-${info.project.name}`);
        seedPublished(brand.id, 'e2e reciclable con exito', 90, 1000, 150);
        seedPublished(brand.id, 'e2e con pocas visualizaciones', 90, 30, 10);
        seedPublished(brand.id, 'e2e sin metricas', 90, null, 0);
        seedPublished(brand.id, 'e2e publicado hoy mismo', 3, 1000, 400);
        seedPublished(brand.id, 'e2e ya reciclado hace poco', 120, 1000, 300, true);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => cleanComposerBrands(e2eUser(info.project.name).email));

    test('E2E-31 · propone solo posts con métricas suficientes fuera del intervalo y Reciclar crea un borrador', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto(`/autolists/recycle?brand_id=${brand.id}`);
        await settle(page);
        await expect(page.getByRole('heading', { name: 'Reciclado de posts', level: 1 })).toBeVisible();

        const proposals = page.getByRole('list', { name: 'Publicaciones propuestas para reciclar' });
        // Una propuesta = una acción «Reciclar» (cada tarjeta lleva además su lista de destinos, con sus propios elementos).
        await expect(proposals.getByRole('button', { name: /^Reciclar: / })).toHaveCount(1);
        await expect(proposals).toContainText('e2e reciclable con exito');
        await expect(proposals).toContainText('15 %');
        for (const text of ['pocas visualizaciones', 'sin metricas', 'publicado hoy mismo', 'ya reciclado hace poco']) {
            await expect(proposals).not.toContainText(text);
        }

        const excluded = page.getByRole('region', { name: 'Fuera de las propuestas' });
        await expect(excluded).toContainText('Pocas visualizaciones');
        await expect(excluded).toContainText('Sin métricas de interacciones');
        await expect(excluded).toContainText('métricas aún no han madurado');
        await expect(excluded).toContainText('menos del intervalo mínimo');

        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Reciclado de posts');
        await page.screenshot({ path: info.outputPath('reciclado-inicial.png'), fullPage: true });

        await page.getByRole('button', { name: 'Reciclar: e2e reciclable con exito' }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit/);
        await expect(page.locator('#text')).toHaveValue('e2e reciclable con exito');

        await page.goto(`/autolists/recycle?brand_id=${brand.id}`);
        await settle(page);
        await expect(page.getByText('Ahora mismo no hay publicaciones para reciclar.')).toBeVisible();
        await expect(proposals).toHaveCount(0);
        await expectNoOverflow(page);
        await page.screenshot({ path: info.outputPath('reciclado-final.png'), fullPage: true });
        expect(w.errors).toEqual([]);
    });
});
