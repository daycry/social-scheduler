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
} from './helpers';

const operator = {
    name: 'e2e System operator',
    email: 'e2e-system-operator@example.test',
    password: 'E2e-System-Only-71!Aa',
};

test.describe('Estado del sistema', { tag: '@responsive' }, () => {
    test.afterEach(async ({}, info) => {
        cleanComposerBrands(operator.email);
        cleanComposerBrands(e2eUser(info.project.name).email);
    });

    test('E2E-01 · un operador ve el estado global, fechas conocidas y observaciones sin credenciales', async ({
        page,
    }, info) => {
        artisan(
            String.raw`$u=App\Models\User::firstOrNew(['email'=>'${operator.email}']); $u->forceFill(['name'=>'${operator.name}','password'=>Illuminate\Support\Facades\Hash::make('${operator.password}'),'email_verified_at'=>now()])->save();`,
        );
        cleanComposerBrands(operator.email);
        const brand = seedComposerBrand(operator.email, `system-status-${stamp()}`);
        const quotaOutput = artisan(String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            $a=App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->firstOrFail();
            $a->forceFill(['access_token'=>'SYSTEM-PRIVATE-UI-TOKEN','token_expires_at'=>now()->addDay(),'refresh_expires_at'=>null,'data_access_expires_at'=>now()->addDays(7)])->save();
            Illuminate\Support\Facades\Cache::forget(App\Support\HealthCheck::HEARTBEAT_KEY);
            (new App\Jobs\QueueProbeJob('system-ui-fixture'))->onQueue('media')->handle();
            $fb=App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','facebook')->firstOrFail();$fb->forceFill(['token_expires_at'=>null])->save();
            echo 'UNKNOWN:'.(count(App\Connectors\Network::cases())-1).' ';
            $single=collect(App\Connectors\Network::cases())->first(fn($n)=>!App\Models\SocialAccount::withoutUserScope()->where('network',$n)->where('status','!=',App\Models\SocialAccount::STATUS_DISCONNECTED)->exists());
            if(!$single)throw new RuntimeException('Fixture needs an unused network for singular-card coverage.');
            App\Models\SocialAccount::factory()->create(['user_id'=>$b->user_id,'brand_id'=>$b->id,'network'=>$single,'token_expires_at'=>null]);
            echo 'STATE:'.json_encode(['single'=>$single->label(),'instagram'=>App\Models\SocialAccount::withoutUserScope()->where('network','instagram')->where('status','!=',App\Models\SocialAccount::STATUS_DISCONNECTED)->count()]).' ';
            $quota=app(App\Connectors\Google\YouTubeQuota::class); $quota->charge(321); echo 'QUOTA:'.$quota->used().'/'.$quota->limit();`);
        const quota = quotaOutput.match(/QUOTA:(\d+)\/(\d+)/)!;
        const unknown = Number(quotaOutput.match(/UNKNOWN:(\d+)/)![1]);
        const state = JSON.parse(quotaOutput.match(/STATE:(\{.*?\})/)![1]) as { single: string; instagram: number };
        const before = artisan(String.raw`echo 'JOBS:'.Illuminate\Support\Facades\DB::table('jobs')->count();`);
        const errors = watch(page);
        await loginAs(page, operator);
        await page.goto('/profile');
        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await page.getByRole('link', { name: 'Estado del sistema', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Estado del sistema', exact: true })).toBeVisible();
        await expect(page.getByTestId('system-health-status')).toHaveText('Hay comprobaciones fallidas');
        await expect(page.getByRole('heading', { name: 'Cron · Fallo', exact: true })).toBeVisible();
        await expect(
            page.getByText(`Consumo local: ${quota[1]} / ${quota[2]} unidades`, { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('Sin lectura remota guardada. Cuota y fecha no disponibles.', { exact: true }),
        ).toHaveCount(unknown);
        await expect(
            page.getByRole('heading', {
                name: `Instagram · ${state.instagram} ${state.instagram === 1 ? 'cuenta' : 'cuentas'}`,
                exact: true,
            }),
        ).toBeVisible();
        const single = page
            .getByRole('listitem')
            .filter({ has: page.getByRole('heading', { name: `${state.single} · 1 cuenta`, exact: true }) });
        await expect(single.getByText('1 cuenta sin fecha de caducidad de acceso.', { exact: true })).toBeVisible();
        const media = page
            .getByRole('listitem')
            .filter({ has: page.getByRole('heading', { name: 'media', exact: true }) });
        await expect(media).toContainText('Última prueba procesada');
        await expect(page.getByText('Última actualización:', { exact: false })).toContainText(
            String(new Date().getUTCFullYear()),
        );
        await expect(page.getByRole('heading', { name: 'Caducidad de credenciales', exact: true })).toBeVisible();
        expect(await page.content()).not.toContain('SYSTEM-PRIVATE-UI-TOKEN');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Estado administrativo del sistema');
        await page.screenshot({ path: info.outputPath('system-status.png'), fullPage: true });
        await page.getByRole('link', { name: 'Actualizar estado', exact: true }).click();
        await expect(page.getByTestId('system-health-status')).toHaveText('Hay comprobaciones fallidas');
        const after = artisan(String.raw`echo 'JOBS:'.Illuminate\Support\Facades\DB::table('jobs')->count();`);
        expect(after).toBe(before);
        expect(errors.errors).toEqual([]);
    });

    test('E2E-01 · un usuario ordinario no ve el enlace y recibe 403 al acceder directamente', async ({
        page,
    }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        seedComposerBrand(user.email, `system-denied-${stamp()}`);
        await loginAs(page, user);
        await page.goto('/profile');
        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await expect(page.getByRole('link', { name: 'Estado del sistema', exact: true })).toHaveCount(0);
        const denied = await page.request.get('/system');
        expect(denied.status()).toBe(403);
        expect(await denied.text()).not.toContain('SYSTEM-PRIVATE-UI-TOKEN');
        await expectNoOverflow(page);
    });
});
