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

const SECRET = 'e2e-webhook-signing-key-0123456789abcdef';

test.describe('Webhooks salientes', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `webhooks-${stamp()}`);
        artisan(String.raw`file_put_contents(storage_path('webhooks-on'), '1');`);
        await loginAs(page, user);
        await page.goto(`/brands/${brand.id}`);
        await page.getByRole('button', { name: 'Menú de usuario', exact: true }).click();
        await expect(page.getByRole('link', { name: 'Webhooks', exact: true })).toBeVisible();
        await page.getByRole('link', { name: 'Webhooks', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Webhooks salientes', exact: true })).toBeVisible();
    });
    test.afterEach(async ({}, info) => {
        try {
            artisan(String.raw`if(is_file(storage_path('webhooks-on')))unlink(storage_path('webhooks-on'));`);
            if (brand)
                artisan(String.raw`$ids=App\Models\WebhookDelivery::withoutUserScope()->whereIn('webhook_id',App\Models\Webhook::withoutUserScope()->where('brand_id',${brand.id})->select('id'))->pluck('id');
                foreach($ids as $id){Illuminate\Support\Facades\DB::table('jobs')->where('payload','like','%DeliverWebhook%')->whereRaw("payload like ? escape '|'",['%deliveryId\";i:'.$id.';%'])->delete();}`);
        } finally {
            cleanComposerBrands(e2eUser(info.project.name).email);
        }
    });

    test('E2E-19 · crea un receptor, publica con el conector simulado y recibe una firma válida', async ({
        page,
    }, info) => {
        const errors = watch(page);
        await page.getByLabel('Nombre del webhook', { exact: true }).fill('Receptor e2e');
        await page.getByLabel('URL del receptor', { exact: true }).fill('https://hooks.example.test/receiver');
        await page.getByRole('button', { name: 'Generar secreto', exact: true }).click();
        await expect(page.getByLabel('Secreto de firma', { exact: true })).toHaveValue(/^[a-f0-9]{64}$/);
        await expect(page.getByLabel('Secreto de firma', { exact: true })).toHaveAttribute('type', 'password');
        await page.getByLabel('Secreto de firma', { exact: true }).fill(SECRET);
        await page.getByRole('button', { name: 'Crear webhook', exact: true }).click();
        const card = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Receptor e2e', exact: true }) });
        await expect(card).toBeVisible();
        await expect(page.getByLabel('Secreto de firma', { exact: true })).toHaveValue('');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: info.outputPath('webhook-created.png'), fullPage: true });
        const result =
            artisan(String.raw`$b=App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});$u=App\Models\User::findOrFail($b->user_id);
            $hook=App\Models\Webhook::ownedBy($u->id)->where('brand_id',$b->id)->sole();
            $fb=App\Models\SocialAccount::ownedBy($u->id)->where('brand_id',$b->id)->where('network','facebook')->firstOrFail();
            $svc=app(App\Services\PostService::class);$p=$svc->create($u,$b,['text'=>'e2e webhook publication','schedule'=>false,'media'=>[],'targets'=>[['account_id'=>$fb->id,'content_type'=>'facebook_text']]]);
            $svc->publishNow($u,$p);$t=$p->fresh()->targets->sole();app()->call([new App\Jobs\PublishPostTarget($t->id),'handle']);
            $d=App\Models\WebhookDelivery::ownedBy($u->id)->where('webhook_id',$hook->id)->sole();app()->call([new App\Jobs\DeliverWebhook($d->id),'handle']);
            $lines=file(storage_path('webhook-receiver.ndjson'),FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);$receipts=array_map(fn($line)=>json_decode($line,true),$lines);
            $receipt=collect($receipts)->firstWhere('event_id',$d->webhook_event_id);
            echo 'DELIVERY:'.json_encode(['status'=>$d->fresh()->status,'target_status'=>$t->fresh()->status->value,'signature_valid'=>$receipt['signature_valid']??false]);`);
        expect(result).toContain('"status":"delivered"');
        expect(result).toContain('"target_status":"publicada"');
        expect(result).toContain('"signature_valid":true');
        await page.reload();
        await expect(card).toContainText('Entregada');
        await expect(card).toContainText('Intentos: 1');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Webhook entregado');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: info.outputPath('webhook-delivered.png'), fullPage: true });
        await card.getByRole('button', { name: 'Editar Receptor e2e', exact: true }).click();
        await expect(page.getByLabel('Nombre del webhook', { exact: true })).toBeFocused();
        await page.getByLabel('Nombre del webhook', { exact: true }).fill('Receptor revisado');
        await page.getByLabel('Activo', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
        const updated = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Receptor revisado', exact: true }) });
        await expect(updated).toContainText('Pausado');
        await updated.getByRole('button', { name: 'Eliminar Receptor revisado', exact: true }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Eliminar webhook', exact: true }).click();
        await expect(updated).toHaveCount(0);
        expect(errors.errors).toEqual([]);
    });

    test('E2E-19 · rechaza una URL privada y conserva el formulario para corregirla', async ({ page }) => {
        const errors = watch(page);
        await page.getByLabel('Nombre del webhook', { exact: true }).fill('Privado rechazado');
        await page.getByLabel('URL del receptor', { exact: true }).fill('https://127.0.0.1/private');
        await page.getByLabel('Secreto de firma', { exact: true }).fill(SECRET);
        await page.getByRole('button', { name: 'Crear webhook', exact: true }).click();
        await expect(
            page.getByRole('form', { name: 'Configuración del webhook', exact: true }).getByRole('alert'),
        ).toContainText('no permitida');
        await expect(page.getByLabel('Nombre del webhook', { exact: true })).toHaveValue('Privado rechazado');
        expect(
            artisan(
                String.raw`echo 'HOOKS:'.App\Models\Webhook::withoutUserScope()->where('brand_id',${brand.id})->count();`,
            ),
        ).toContain('HOOKS:0');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Webhook privado rechazado');
        expect(errors.errors).toEqual([]);
    });
});
