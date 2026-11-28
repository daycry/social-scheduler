import { expect, test, type Page } from '@playwright/test';
import { artisan, axeSeriousCritical, e2eUser, expectNoOverflow, loginAs, watch } from './helpers';

async function preparePushFixture(page: Page, user: { email: string }) {
    const fixture = artisan(String.raw`
        $cfg=storage_path('push-e2e-openssl.cnf');file_put_contents($cfg,"[req]\ndistinguished_name=dn\n[dn]\nCN=fixture\n");
        $k=openssl_pkey_new(['config'=>$cfg,'private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        $ec=openssl_pkey_get_details($k)['ec'];$enc=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
        $public=$enc("\x04".str_pad($ec['x'],32,"\x00",STR_PAD_LEFT).str_pad($ec['y'],32,"\x00",STR_PAD_LEFT));
        file_put_contents(storage_path('push-on'),json_encode(['public'=>$public,'private'=>$enc(str_pad($ec['d'],32,"\x00",STR_PAD_LEFT))]));
        $u=App\Models\User::where('email','${user.email}')->firstOrFail();App\Models\PushSubscription::ownedBy($u->id)->delete();
        echo 'KEY:'.$public;
    `);
    const publicKey = fixture.match(/KEY:([A-Za-z0-9_-]+)/)![1];
    await page.addInitScript(
        ({ publicKey }) => {
            const calls: string[] = [];
            const key = Uint8Array.from(atob(publicKey.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
            const subscription = {
                options: { applicationServerKey: key.buffer },
                toJSON: () => ({
                    endpoint: 'https://push.example.test/browser',
                    keys: { p256dh: publicKey, auth: 'AgICAgICAgICAgICAgICAg' },
                }),
                unsubscribe: async () => {
                    calls.push('unsubscribe');
                    return new Promise<boolean>(() => {});
                },
            };
            let current: typeof subscription | null = null;
            const registration = {
                pushManager: {
                    getSubscription: async () => current,
                    subscribe: async (options: { userVisibleOnly: boolean }) => {
                        if (!options.userVisibleOnly) throw new Error('visible required');
                        calls.push('subscribe');
                        current = subscription;
                        return subscription;
                    },
                },
            };
            Object.defineProperty(window, 'Notification', {
                configurable: true,
                value: {
                    requestPermission: async () => {
                        calls.push('permission');
                        return 'granted';
                    },
                },
            });
            Object.defineProperty(window, 'PushManager', { configurable: true, value: function () {} });
            Object.defineProperty(navigator, 'serviceWorker', {
                configurable: true,
                value: {
                    register: async () => {
                        calls.push('register');
                        return registration;
                    },
                    ready: Promise.resolve(registration),
                },
            });
            Reflect.set(window, '__pushCalls', calls);
        },
        { publicKey },
    );
}

function clearPushFixture() {
    artisan(
        String.raw`foreach(['push-on','push-receiver.ndjson','push-e2e-openssl.cnf']as$f){if(is_file(storage_path($f)))unlink(storage_path($f));}`,
    );
}

test('E2E-20 · ajustes push explican la falta de claves sin pedir permiso y conservan accesibilidad @responsive', async ({
    page,
}, info) => {
    const worker = await page.request.get('/sw.js');
    expect(worker.status()).toBe(200);
    expect(worker.headers()['content-type']).toContain('javascript');
    expect(worker.headers()['set-cookie']).toBeUndefined();
    await loginAs(page, e2eUser(info.project.name));
    const errors = watch(page);
    await page.goto('/profile/notifications');
    await expect(page.getByRole('heading', { name: 'Notificaciones del navegador', exact: true })).toBeVisible();
    await expect(
        page.getByText('Las notificaciones push no están configuradas en este servidor.', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activar en este navegador', exact: true })).toBeDisabled();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Ajustes push sin claves');
    await page.screenshot({ path: info.outputPath('push-unconfigured.png'), fullPage: true });
    expect(errors.errors).toEqual([]);
});

test('E2E-20 · el permiso explícito suscribe, entrega con PushSender falso y permite revocar este navegador @responsive', async ({
    page,
}, info) => {
    const user = e2eUser(info.project.name);
    try {
        await preparePushFixture(page, user);
        await loginAs(page, user);
        await page.goto('/profile/notifications');
        const activate = page.getByRole('button', { name: 'Activar en este navegador', exact: true });
        await expect(activate).toBeEnabled();
        expect(await page.evaluate(() => Reflect.get(window, '__pushCalls'))).not.toContain('permission');
        await page.getByLabel('Nombre de este dispositivo', { exact: true }).fill('Navegador e2e');
        await activate.click();
        const card = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: 'Navegador e2e · Este navegador', exact: true }) });
        await expect(card).toBeVisible();
        const delivered =
            artisan(String.raw`$u=App\Models\User::where('email','${user.email}')->firstOrFail();$s=App\Models\PushSubscription::ownedBy($u->id)->sole();
            $u->notify(new App\Notifications\PostWorkflowActivity('approval_requested',null,null,'Private Brand','Private message'));
            $n=$u->notifications()->latest()->firstOrFail();app()->call([new App\Jobs\SendPushNotification($u->id,$s->id,$n->id),'handle']);
            echo 'PUSH:'.json_encode(['sent'=>$s->fresh()->last_sent_at!==null,'receipts'=>count(file(storage_path('push-receiver.ndjson'),FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))]);`);
        expect(delivered).toContain('"sent":true');
        expect(delivered).toContain('"receipts":1');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Dispositivo push suscrito');
        await page.screenshot({ path: info.outputPath('push-subscribed.png'), fullPage: true });
        await card.getByRole('button', { name: 'Revocar Navegador e2e', exact: true }).click();
        await page
            .getByRole('alertdialog', { name: 'Revocar dispositivo', exact: true })
            .getByRole('button', { name: 'Revocar', exact: true })
            .click();
        await expect(card).toHaveCount(0);
        await expect(activate).toBeEnabled();
        expect(await page.evaluate(() => Reflect.get(window, '__pushCalls'))).toContain('unsubscribe');
        expect(
            artisan(
                String.raw`$u=App\Models\User::where('email','${user.email}')->firstOrFail();echo 'COUNT:'.App\Models\PushSubscription::ownedBy($u->id)->count();`,
            ),
        ).toContain('COUNT:0');
    } finally {
        clearPushFixture();
    }
});

test('E2E-20 · la colisión de otro propietario muestra el mensaje genérico y conserva su suscripción @responsive', async ({
    page,
}, info) => {
    const user = e2eUser(info.project.name);
    let foreignId: number | null = null;
    try {
        await preparePushFixture(page, user);
        const seed =
            artisan(String.raw`$u=App\Models\User::factory()->create(['email'=>'push-foreign-'.Illuminate\Support\Str::uuid().'@example.test']);
            $f=json_decode(file_get_contents(storage_path('push-on')),true);
            app(App\Services\Push\PushSubscriptions::class)->subscribe($u,['endpoint'=>'https://push.example.test/browser','keys'=>['p256dh'=>$f['public'],'auth'=>'AgICAgICAgICAgICAgICAg'],'device_name'=>'Dispositivo ajeno']);
            echo 'FOREIGN:'.$u->id;`);
        foreignId = Number(seed.match(/FOREIGN:(\d+)/)![1]);
        await loginAs(page, user);
        const errors = watch(page);
        await page.goto('/profile/notifications');
        const activate = page.getByRole('button', { name: 'Activar en este navegador', exact: true });
        await activate.click();
        await expect(
            page.getByRole('region', { name: 'Activar avisos push', exact: true }).getByRole('alert'),
        ).toContainText('No se pudo vincular este navegador.');
        await expect(activate).toBeEnabled();
        expect(
            artisan(
                String.raw`$u=App\Models\User::where('email','${user.email}')->firstOrFail();echo 'OWN:'.App\Models\PushSubscription::ownedBy($u->id)->count();echo 'FOREIGN:'.App\Models\PushSubscription::ownedBy(${foreignId})->count();`,
            ),
        ).toContain('OWN:0FOREIGN:1');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Colisión push sin transferencia');
        expect(errors.errors).toEqual([]);
    } finally {
        try {
            if (foreignId !== null) artisan(String.raw`App\Models\User::findOrFail(${foreignId})->delete();`);
        } finally {
            clearPushFixture();
        }
    }
});
