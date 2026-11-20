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

test.describe('Objetivos y alertas', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `goals-${stamp()}`);
        await loginAs(page, user);
        await page.goto(`/brands/${brand.id}`);
        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await expect(page.getByRole('link', { name: 'Objetivos', exact: true })).toBeVisible();
        await page.getByRole('link', { name: 'Objetivos', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Objetivos y alertas', exact: true })).toBeVisible();
    });
    test.afterEach(async ({}, info) => cleanComposerBrands(e2eUser(info.project.name).email));

    test('E2E-18 · crea un hito de seguidores, recibe el aviso y permite editar y eliminar el objetivo', async ({
        page,
    }, info) => {
        const errors = watch(page);
        artisan(String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            $a=App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->firstOrFail();
            $day=min(Carbon\CarbonImmutable::instance(now())->setTimezone($b->timezone ?: 'UTC')->subDay()->toDateString(),App\Analytics\Days::closed($a->network)->toDateString());
            App\Models\AccountSnapshot::withoutUserScope()->create(['user_id'=>$b->user_id,'brand_id'=>$b->id,'social_account_id'=>$a->id,'network'=>$a->network,'date'=>$day,'metrics'=>array_replace(App\Analytics\Metric::blankAccount(),[App\Analytics\Metric::FOLLOWERS=>1200]),'collected_at'=>now()]);`);
        await page.getByLabel('Nombre del objetivo', { exact: true }).fill('Hito de seguidores e2e');
        await page.getByLabel('Tipo de objetivo', { exact: true }).selectOption('milestone');
        await page.getByLabel('Métrica', { exact: true }).selectOption('followers');
        await page.getByLabel('Cuenta', { exact: true }).selectOption({ label: 'Instagram: e2e Instagram' });
        await page.getByLabel('Umbral', { exact: true }).fill('1000');
        await page.getByRole('button', { name: 'Crear objetivo', exact: true }).click();
        const entry = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Hito de seguidores e2e', exact: true }) });
        await expect(entry).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: info.outputPath('goal-created.png'), fullPage: true });
        artisan(String.raw`(new App\Jobs\EvaluateGoals)->handle(app(App\Services\GoalService::class));`);
        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await page
            .getByRole('banner')
            .getByRole('link', { name: 'Avisos', exact: true })
            .filter({ hasText: 'Avisos' })
            .click();
        await expect(page.getByRole('heading', { name: 'Hito de seguidores e2e', exact: true })).toBeVisible();
        await expect(page.getByText('Se ha alcanzado el hito', { exact: false })).toBeVisible();
        await page.getByRole('link', { name: 'Ver objetivos', exact: true }).click();
        await expect(entry).toContainText('1200');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Objetivo evaluado');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: info.outputPath('goal-alert.png'), fullPage: true });
        await entry.getByRole('button', { name: 'Editar «Hito de seguidores e2e»', exact: true }).click();
        await expect(page.getByLabel('Nombre del objetivo', { exact: true })).toBeFocused();
        await page.getByLabel('Nombre del objetivo', { exact: true }).fill('Hito revisado e2e');
        await page.getByLabel('Umbral', { exact: true }).fill('1500');
        await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
        const revised = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Hito revisado e2e', exact: true }) });
        await expect(revised).toBeVisible();
        await revised.getByRole('button', { name: 'Eliminar «Hito revisado e2e»', exact: true }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Eliminar objetivo', exact: true }).click();
        await expect(revised).toHaveCount(0);
        expect(errors.errors).toEqual([]);
    });

    test('E2E-18 · la ausencia de métricas se muestra como desconocida y no provoca una caída ficticia', async ({
        page,
    }) => {
        const errors = watch(page);
        await page.getByLabel('Nombre del objetivo', { exact: true }).fill('Caída sin datos e2e');
        await page.getByLabel('Tipo de objetivo', { exact: true }).selectOption('drop');
        await page.getByLabel('Métrica', { exact: true }).selectOption('followers');
        await page.getByLabel('Cuenta', { exact: true }).selectOption({ label: 'Instagram: e2e Instagram' });
        await page.getByLabel('Umbral', { exact: true }).fill('25');
        await page.getByRole('button', { name: 'Crear objetivo', exact: true }).click();
        const entry = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Caída sin datos e2e', exact: true }) });
        await expect(entry).toBeVisible();
        artisan(String.raw`(new App\Jobs\EvaluateGoals)->handle(app(App\Services\GoalService::class));`);
        await page.reload();
        await expect(entry).toContainText('Sin datos suficientes');
        const count = artisan(
            String.raw`$g=App\Models\Goal::withoutUserScope()->where('brand_id',${brand.id})->sole(); echo 'ALERTS:'.App\Models\GoalAlertEvent::withoutUserScope()->where('goal_id',$g->id)->count();`,
        );
        expect(count).toContain('ALERTS:0');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Objetivo sin datos');
        expect(errors.errors).toEqual([]);
    });

    test('E2E-18 · identifica errores por campo y permite pausar una cuenta desconectada sin perderla', async ({
        page,
    }, info) => {
        const errors = watch(page);
        await page.getByLabel('Nombre del objetivo', { exact: true }).fill(' ');
        await page.getByRole('button', { name: 'Crear objetivo', exact: true }).click();
        await expect(page.getByLabel('Nombre del objetivo', { exact: true })).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByLabel('Nombre del objetivo', { exact: true })).toHaveAttribute(
            'aria-describedby',
            'goal-error-name',
        );
        await page.getByLabel('Nombre del objetivo', { exact: true }).fill('Regla desconectada e2e');
        await page.getByLabel('Cuenta', { exact: true }).selectOption({ label: 'Instagram: e2e Instagram' });
        await page.getByRole('button', { name: 'Crear objetivo', exact: true }).click();
        const entry = page.getByRole('article', { name: 'Regla desconectada e2e', exact: true });
        await expect(entry).toBeVisible();
        artisan(
            String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->update(['status'=>App\Models\SocialAccount::STATUS_RECONNECT]);$a=App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->firstOrFail();$day=min(Carbon\CarbonImmutable::instance(now())->setTimezone($b->timezone ?: 'UTC')->subDay()->toDateString(),App\Analytics\Days::closed($a->network)->toDateString());App\Models\AccountSnapshot::withoutUserScope()->create(['user_id'=>$b->user_id,'brand_id'=>$b->id,'social_account_id'=>$a->id,'network'=>$a->network,'date'=>$day,'metrics'=>array_replace(App\Analytics\Metric::blankAccount(),[App\Analytics\Metric::FOLLOWERS=>900]),'collected_at'=>now()]);(new App\Jobs\EvaluateGoals)->handle(app(App\Services\GoalService::class));`,
        );
        await page.reload();
        await expect(entry).toContainText('Cuenta pendiente de reconexión');
        await expect(entry).toContainText('Último valor: 900');
        artisan(
            String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->update(['status'=>App\Models\SocialAccount::STATUS_DISCONNECTED]);`,
        );
        await page.reload();
        await entry.getByRole('button', { name: 'Editar «Regla desconectada e2e»', exact: true }).click();
        await expect(page.getByLabel('Cuenta', { exact: true }).locator('option:checked')).toContainText(
            'Cuenta no disponible',
        );
        await page.getByLabel('Activo', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
        await expect(entry).toContainText('Pausado');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Objetivo con cuenta desconectada');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: info.outputPath('goal-disconnected-paused.png'), fullPage: true });
        expect(errors.errors).toEqual([]);
    });

    test('E2E-18 · la caducidad vencida se identifica con fecha exacta y zona de la marca', async ({ page }) => {
        const errors = watch(page);
        artisan(String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});$u=App\Models\User::findOrFail($b->user_id);
            $a=App\Models\SocialAccount::factory()->for($u)->for($b)->create(['network'=>App\Connectors\Network::LinkedIn,'refresh_token'=>null,'token_expires_at'=>now()->subDay(),'refresh_expires_at'=>null,'data_access_expires_at'=>null]);
            $g=app(App\Services\GoalService::class)->create($u,$b,['name'=>'Caducidad vencida e2e','kind'=>'token_expiry','threshold'=>3,'social_account_id'=>$a->id]);app(App\Services\GoalService::class)->evaluate($u,$g);`);
        await page.reload();
        const entry = page.getByRole('article', { name: 'Caducidad vencida e2e', exact: true });
        await expect(entry).toContainText('Caducó el');
        await expect(entry).toContainText('Europe/Madrid');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Objetivo con caducidad vencida');
        expect(errors.errors).toEqual([]);
    });
});
