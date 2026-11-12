import { expect, test, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch } from './helpers';

/**
 * T-19 · aprobaciones, publicación manual, recurrencia, lotes y reintento. Datos `e2e-composer-*` del usuario E2E y un segundo usuario
 * desechable para el aprobador; nada se publica en ninguna red (destinos manuales, estados sembrados y cola sin worker).
 */
test.describe.configure({ mode: 'serial' });

const GUEST_PASSWORD = 'E2e-Approver-Disposable-Aa1!';
let brand: { id: number; name: string };

const php = (code: string) => artisan(code);

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `wf-${stamp()}-${info.project.name}`);
    watch(page);
});

/** Crea posts de Facebook en la marca E2E; devuelve sus ids. `mods` es PHP que recibe `$p` (el post creado). */
function seedPosts(email: string, specs: Array<{ text: string; days: number | null; extra?: string; target?: string }>): number[] {
    const items = specs
        .map((s) => `['${s.text}', ${s.days === null ? 'null' : s.days}, function ($p) { ${s.extra ?? ''} }, ${s.target ? `[${s.target}]` : '[]'}]`)
        .join(', ');
    const out = php(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); $b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); ` +
            String.raw`$fb = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'facebook')->firstOrFail(); $svc = app(App\Services\PostService::class); $ids = []; ` +
            String.raw`foreach ([${items}] as [$text, $days, $fn, $tx]) { ` +
            String.raw`$at = $days === null ? null : now()->addDays($days)->setTimezone('Europe/Madrid')->setTime(10, 0)->format('Y-m-d H:i'); ` +
            String.raw`$p = $svc->create($u, $b, array_merge(['text' => $text, 'schedule' => $days !== null, 'scheduled_at' => $at, 'media' => [], 'targets' => [['account_id' => $fb->id, 'content_type' => 'facebook_text']]], $tx)); ` +
            String.raw`$fn($p); $ids[] = $p->id; } echo 'IDS:' . implode(',', $ids);`,
    );
    return out.match(/IDS:([\d,]+)/)![1].split(',').map(Number);
}

async function newClient(browser: Browser, info: TestInfo): Promise<{ context: BrowserContext; page: Page }> {
    const use = info.project.use;
    const context = await browser.newContext({ baseURL: use.baseURL, viewport: use.viewport ?? undefined, isMobile: use.isMobile, hasTouch: use.hasTouch, locale: 'es-ES', timezoneId: 'Europe/Madrid' });
    const page = await context.newPage();
    watch(page);
    return { context, page };
}

const listUrl = () => `/calendar?view=list&brand_id=${brand.id}`;

test('aprobar y rechazar con dos usuarios: invitación uniforme, decisión del aprobador y estado en el calendario', { tag: '@responsive' }, async ({ browser, page }, info) => {
    test.setTimeout(180_000);
    const owner = e2eUser(info.project.name);
    const guest = { email: `e2e-approver-${info.project.name}-${stamp()}@example.test`, name: 'e2e Aprobadora', password: GUEST_PASSWORD };
    php(String.raw`$u = App\Models\User::firstOrNew(['email' => '${guest.email}']); $u->forceFill(['name' => '${guest.name}', 'password' => Illuminate\Support\Facades\Hash::make('${guest.password}'), 'email_verified_at' => now()])->save();`);
    const [a, b] = seedPosts(owner.email, [
        { text: 'e2e aprobar esta', days: null, target: "'requires_approval' => true" },
        { text: 'e2e rechazar esta', days: null, target: "'requires_approval' => true" },
    ]);
    const clients: BrowserContext[] = [];
    try {
        await loginAs(page, owner);

        // 1. La propietaria invita: la respuesta es la misma exista o no la cuenta.
        await page.goto('/approvals');
        const card = page.getByTestId(`brand-${brand.id}`);
        const MESSAGE = 'Si existe una cuenta con ese email, podra aprobar las publicaciones de esta marca.';
        await card.getByTestId('invite-email').fill('nadie@example.test');
        await card.getByTestId('invite-send').click();
        await expect(page.getByTestId('approvals-message')).toHaveText(MESSAGE);
        await expect(card.getByText('nadie@example.test')).toHaveCount(0);
        await card.getByTestId('invite-email').fill(guest.email);
        await card.getByTestId('invite-send').click();
        await expect(page.getByTestId('approvals-message')).toHaveText(MESSAGE);
        await expect(card.getByText(guest.email)).toBeVisible();
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'aprobaciones (propietaria)');

        // 2. La aprobadora ve las dos pendientes, aprueba una y rechaza la otra con comentario.
        const client = await newClient(browser, info);
        clients.push(client.context);
        await loginAs(client.page, guest);
        await client.page.goto('/approvals');
        await expect(client.page.getByTestId(`pending-${a}`)).toContainText('e2e aprobar esta');
        await expect(client.page.getByTestId(`pending-${b}`)).toBeVisible();
        await axeSeriousCritical(client.page, 'aprobaciones (aprobadora)');
        await expectNoOverflow(client.page);

        await client.page.getByTestId(`pending-${a}`).getByTestId('approve').click();
        await expect(client.page.getByTestId('approvals-message')).toHaveText('Publicación aprobada.');
        await expect(client.page.getByTestId(`pending-${a}`)).toHaveCount(0);

        await client.page.getByTestId(`pending-${b}`).getByTestId('reject-start').click();
        await expect(client.page.getByTestId('reject-confirm')).toBeDisabled(); // el comentario es obligatorio
        await client.page.getByTestId('reject-comment').fill('Falta el enlace de la campaña');
        await client.page.getByTestId('reject-confirm').click();
        await expect(client.page.getByTestId('approvals-message')).toHaveText('Publicación rechazada.');
        await expect(client.page.getByTestId(`pending-${b}`)).toHaveCount(0);

        // 3. La propietaria ve el estado en el calendario y los avisos en la base de datos.
        await page.goto(listUrl());
        await expect(page.getByTestId(`row-${a}`).getByTestId('approval-badge')).toHaveText('Aprobado');
        await expect(page.getByTestId(`row-${b}`).getByTestId('approval-badge')).toHaveText('Rechazado');
        await axeSeriousCritical(page, 'calendario lista con aprobaciones');
        await page.goto('/notifications');
        await expect(page.getByText(/ha rechazado tu publicacion: Falta el enlace de la campaña/)).toBeVisible();
        await expect(page.getByText(/ha aprobado tu publicacion/)).toBeVisible();

        // 4. Un post sin aprobar no se programa por ninguna vía: la regla vive en el servidor.
        const refused = php(String.raw`$u = App\Models\User::where('email', '${owner.email}')->firstOrFail(); try { app(App\Services\PostService::class)->schedule($u, App\Models\Post::withoutUserScope()->findOrFail(${b}), now()->addDay()); echo 'SCHEDULED'; } catch (App\Exceptions\PostValidationException $e) { echo 'REFUSED:' . $e->getMessage(); }`);
        expect(refused).toContain('REFUSED:');
    } finally {
        await Promise.all(clients.map((c) => c.close()));
        php(String.raw`$u = App\Models\User::where('email', '${guest.email}')->first(); if ($u) { Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $u->id)->delete(); $u->delete(); }`);
    }
});

test('destino manual: el aviso trae el contenido y se marca como publicado con una URL https', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(120_000);
    const owner = e2eUser(info.project.name);
    const [id] = seedPosts(owner.email, [{
        text: 'e2e texto para copiar a mano', days: 2, target: "'targets' => [['account_id' => $fb->id, 'content_type' => 'facebook_text', 'manual' => true]]",
        extra: String.raw`App\Models\Post::withoutUserScope()->whereKey($p->id)->update(['scheduled_at' => now()->subMinute()]);`,
    }]);
    // El despachador del minuto avisa de lo vencido (aquí se invoca el servicio: no hay scheduler en el entorno).
    php(String.raw`echo 'NOTIFIED:' . app(App\Services\ManualPublishService::class)->notifyDue();`);
    await loginAs(page, owner);

    // El aviso llega a Avisos y lleva a la pantalla de publicación manual.
    await page.goto('/notifications');
    await expect(page.getByText('Toca publicar a mano').first()).toBeVisible(); // puede haber un aviso por cada destino manual
    await page.getByTestId('manual-notice-link').first().click();
    await page.waitForURL(new RegExp(`/posts/${id}/manual`));
    await expect(page.getByTestId('manual-text')).toHaveValue('e2e texto para copiar a mano');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'publicación manual');

    // La URL tiene que ser https.
    await page.getByTestId('manual-url').fill('http://facebook.com/inseguro');
    await page.getByTestId('manual-mark').click();
    await expect(page.getByTestId('manual-error')).toContainText('https');
    await page.getByTestId('manual-url').fill('https://facebook.com/pagina/posts/42');
    await page.getByTestId('manual-mark').click();
    await expect(page.getByTestId('manual-done')).toContainText('https://facebook.com/pagina/posts/42');

    // El calendario lo refleja como publicado, con el enlace.
    await page.goto(listUrl());
    const row = page.getByTestId(`row-${id}`);
    await expect(row).toContainText('Publicada');
    await expect(row.getByTestId('manual-badge')).toBeVisible();
    await expect(row.getByRole('link', { name: 'Ver' }).first()).toHaveAttribute('href', 'https://facebook.com/pagina/posts/42');
});

test('serie semanal: se crea desde la pantalla y las ocurrencias aparecen en el calendario sin duplicarse', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(120_000);
    const owner = e2eUser(info.project.name);
    await loginAs(page, owner);
    await page.goto('/series');
    await expect(page.getByTestId('series-frequency')).toBeVisible();
    await axeSeriousCritical(page, 'series');
    await expectNoOverflow(page);

    // La marca recién sembrada es la última de la lista: se elige por nombre.
    await page.getByLabel('Marca', { exact: true }).selectOption({ label: brand.name });
    await page.getByTestId('series-frequency').selectOption('semanal');
    for (const d of [1, 3, 5]) await page.getByTestId(`weekday-${d}`).check();
    await page.getByTestId('series-time').fill('11:30');
    await page.getByTestId('series-count').fill('6');
    await page.getByText('e2e Facebook', { exact: false }).locator('input[type=checkbox]').check();
    await page.getByTestId('series-text').fill('e2e publicacion de la serie');
    await page.getByTestId('series-create').click();
    await expect(page.getByTestId('series-message')).toContainText('Serie creada con');

    // Ocurrencias en el calendario (semana actual y siguientes): al menos una con la insignia «Serie».
    await page.goto(`/calendar?view=list&brand_id=${brand.id}`);
    const badges = page.getByTestId('series-badge');
    await expect(badges.first()).toBeVisible();
    const first = await badges.count();
    expect(first).toBeGreaterThanOrEqual(2);
    await axeSeriousCritical(page, 'calendario con serie');
    await expectNoOverflow(page);

    // El job de generación es idempotente: dos pasadas no añaden nada.
    php(String.raw`$j = app(App\Jobs\GenerateSeriesOccurrences::class); $j(); $j();`);
    await page.reload();
    await expect(page.getByTestId('series-badge')).toHaveCount(first);

    // Cancelar la serie borra las futuras pendientes.
    await page.goto('/series');
    page.once('dialog', (d) => d.accept());
    await page.getByTestId('series-cancel').first().click();
    await expect(page.getByTestId('series-message')).toContainText('Serie cancelada');
    await page.goto(listUrl());
    await expect(page.getByTestId('series-badge')).toHaveCount(0);
});

test('lote: reprogramar y cancelar con un resultado parcial por elemento', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(120_000);
    const owner = e2eUser(info.project.name);
    const [p1, p2, p3, done] = seedPosts(owner.email, [
        { text: 'e2e lote uno', days: 2 }, { text: 'e2e lote dos', days: 3 }, { text: 'e2e lote tres', days: 4 },
        { text: 'e2e lote publicada', days: 2, extra: String.raw`$p->targets->each(fn ($t) => $t->forceFill(['status' => 'publicada', 'published_at' => now()])->save()); $p->syncStatus();` },
    ]);
    await loginAs(page, owner);
    await page.goto(listUrl());
    await expect(page.getByTestId(`row-${p1}`)).toBeVisible();

    for (const id of [p1, p2, p3, done]) await page.getByTestId(`select-${id}`).check();
    await expect(page.getByTestId('batch-count')).toHaveText('4 seleccionadas');
    await page.getByTestId('batch-action').selectOption('reschedule');
    await page.getByTestId('batch-days').fill('1');
    await page.getByTestId('batch-run').click();

    // Parcial: tres correctas y la publicada con su error; el lote no se detiene.
    const result = page.getByTestId('batch-result');
    await expect(result).toContainText('3 correctas, 1 con error');
    await expect(page.getByTestId(`batch-item-${p1}`)).toContainText('OK');
    await expect(page.getByTestId(`batch-item-${done}`)).toContainText('Error');
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'lote con resultado parcial');

    // Cancelar dos de ellas: vuelven a borrador.
    await page.getByRole('button', { name: 'Limpiar selección' }).click();
    for (const id of [p1, p2]) await page.getByTestId(`select-${id}`).check();
    await page.getByTestId('batch-action').selectOption('cancel');
    await page.getByTestId('batch-run').click();
    await expect(page.getByTestId('batch-result')).toContainText('2 correctas, 0 con error');
    await expect(page.getByTestId(`row-${p1}`)).toContainText('Borrador');
    await expect(page.getByTestId(`row-${p3}`)).toContainText('Programada');
});

test('reintento: un destino con error vuelve a programarse una sola vez', { tag: '@responsive' }, async ({ page }, info) => {
    test.setTimeout(120_000);
    const owner = e2eUser(info.project.name);
    const [id] = seedPosts(owner.email, [{
        text: 'e2e reintento', days: 2,
        extra: String.raw`App\Models\Post::withoutUserScope()->whereKey($p->id)->update(['scheduled_at' => now()->addMinutes(30)]); $p->targets->each(fn ($t) => $t->forceFill(['status' => 'fallida', 'attempts' => 4, 'error_code' => 'reintentos_agotados', 'error_message' => 'No se pudo publicar tras 4 intentos'])->save()); $p->syncStatus();`,
    }]);
    await loginAs(page, owner);
    await page.goto(listUrl());
    const row = page.getByTestId(`row-${id}`);
    await expect(row).toContainText('Con error');
    await row.getByTestId('retry').click();
    await expect(page.getByTestId('retry-notice')).toHaveText('Reintento enviado.');
    await expect(row).toContainText('Programada');
    await expect(row.getByTestId('retry')).toHaveCount(0); // ya no hay nada que reintentar: un segundo clic no publica dos veces
    const attempts = php(String.raw`echo 'ATTEMPTS:' . App\Models\PostTarget::withoutUserScope()->where('post_id', ${id})->value('attempts');`);
    expect(attempts).toContain('ATTEMPTS:0');
    await settle(page);
    await axeSeriousCritical(page, 'calendario tras reintentar');
    await expectNoOverflow(page);
});
