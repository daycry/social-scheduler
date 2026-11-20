import { expect, test, type Page } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, loginAs, seedComposerBrand, seedInboxComment, stamp, AUTH_FILE, AUTH_PAGES, axeSeriousCritical, expectNoOverflow, PUBLIC_PAGES, settle, watch } from './helpers';

/** (a) Recorrido (solo lectura: la sesión demo solo hace GET, nunca crea, edita ni borra datos) de todas las páginas: status < 400, sin errores de consola, sin desbordamiento y con un h1. */
async function check(page: Page, path: string) {
    const w = watch(page);
    const res = await page.goto(path);
    expect(res?.status(), `status de ${path}`).toBeLessThan(400);
    await settle(page);
    await expect(page.locator('h1').first(), `h1 en ${path}`).toBeVisible();
    await expectNoOverflow(page);
    expect(w.errors, `errores de consola en ${path}`).toEqual([]);
}

test.describe('páginas públicas', { tag: '@responsive' }, () => {
    for (const [name, path] of PUBLIC_PAGES) {
        test(name, async ({ page }) => check(page, path));
    }
});

test.describe('páginas autenticadas', { tag: '@responsive' }, () => {
    test.use({ storageState: AUTH_FILE });

    for (const [name, path] of AUTH_PAGES) {
        test(name, async ({ page }) => check(page, path));
    }

    test('conexiones de la marca, crear publicación y editar marca', async ({ page }) => {
        await page.goto('/brands');
        const href = await page.locator('a[href*="/connections"]').first().getAttribute('href');
        expect(href).toBeTruthy();
        const brandId = href!.match(/brands\/(\d+)\/connections/)![1];
        await check(page, `/brands/${brandId}/connections`);
        await check(page, `/brands/${brandId}/posts/create`);
        await axeSeriousCritical(page, 'compositor');
        // E2E-12: el panel de notas es parte de la página (siempre visible); se rellena en el cliente sin guardar nada y se vuelve a barrer.
        await page.getByTestId('notes-input').fill('Nota interna con salto de línea');
        await expect(page.getByTestId('notes-count')).toContainText('/ 5000');
        await axeSeriousCritical(page, 'compositor con notas');
        await check(page, `/brands/${brandId}/edit`);
    });

    test('axe en el calendario (solo lectura)', async ({ page }) => {
        for (const vista of ['week', 'month', 'list']) {
            await check(page, `/calendar?view=${vista}`);
            await axeSeriousCritical(page, `calendario (${vista})`);
        }
    });

    test('el shell enlaza los módulos nuevos', async ({ page }, info) => {
        test.skip(info.project.name.startsWith('mobile'), 'La barra lateral de escritorio no existe en móvil');
        await page.goto('/calendar');
        const side = page.getByRole('complementary', { name: 'Navegación del módulo' });
        for (const label of ['Calendario', 'Lista', 'Crear publicación', 'Mejores horas', 'Plantillas de publicación', 'Autolistas', 'Papelera de publicaciones']) {
            await expect(side.getByRole('link', { name: label, exact: true }), label).toBeVisible();
        }
        await page.goto('/dashboard');
        await expect(page.getByRole('complementary', { name: 'Navegación del módulo' }).getByRole('link', { name: 'Competidores' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Módulos' }).getByRole('link', { name: 'SmartLinks' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Módulos' }).getByRole('link', { name: /^Inbox/ })).toBeVisible();
        await expect(page.getByRole('complementary', { name: 'Navegación del módulo' }).getByRole('link', { name: 'Meta Ads' })).toBeVisible();
    });
});

test('E2E-11 · hora de la marca en siete pantallas y listados con distintas zonas', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const other = seedComposerBrand(user.email, `time-madrid-${stamp()}`, 'Europe/Madrid');
    const brand = seedComposerBrand(user.email, `time-auckland-${stamp()}`, 'Pacific/Auckland');
    const sharedOwnerEmail = `qa-clock-${stamp()}@example.test`;
    const instant = '2026-11-17T20:30:00Z';
    try {
        seedInboxComment(brand.id, 'e2e comentario con fecha de marca');
        const seeded = artisan(String.raw`
            $brand = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            $at = Carbon\CarbonImmutable::parse('${instant}');
            App\Models\InboxComment::withoutUserScope()->where('brand_id', $brand->id)->update(['commented_at' => $at]);
            App\Models\Post::factory()->create([
                'brand_id' => $brand->id,
                'text' => 'e2e publicación en papelera de Auckland',
                'deleted_at' => $at,
            ]);
            $madrid = App\Models\Brand::withoutUserScope()->findOrFail(${other.id});
            App\Models\Post::factory()->create([
                'brand_id' => $madrid->id,
                'text' => 'e2e publicación en papelera de Madrid',
                'deleted_at' => $at,
            ]);
            App\Models\Studio\Design::create([
                'user_id' => $brand->user_id, 'brand_id' => $brand->id, 'name' => 'e2e diseño de zona horaria',
                'document' => App\Services\Studio\StudioDocument::blank(),
                'width' => 1080, 'height' => 1080, 'page_count' => 1, 'revision' => 1, 'updated_at' => $at,
            ]);
            $owner = App\Models\User::factory()->create(['email' => '${sharedOwnerEmail}']);
            foreach (['Madrid' => 'Europe/Madrid', 'Auckland' => 'Pacific/Auckland'] as $city => $zone) {
                $sharedBrand = App\Models\Brand::factory()->for($owner)->create(['timezone' => $zone]);
                $design = App\Models\Studio\Design::create([
                    'user_id' => $owner->id, 'brand_id' => $sharedBrand->id, 'name' => 'QA compartido ' . $city,
                    'document' => App\Services\Studio\StudioDocument::blank(),
                    'width' => 1080, 'height' => 1080, 'page_count' => 1, 'revision' => 1, 'updated_at' => $at,
                ]);
                App\Models\Studio\DesignShare::create([
                    'design_id' => $design->id, 'owner_id' => $owner->id, 'user_id' => $brand->user_id, 'role' => 'reader',
                ]);
            }
            $list = new App\Models\Autolist([
                'name' => 'e2e autolista de zona horaria',
                'targets' => [], 'slots' => [], 'last_generated_at' => $at,
            ]);
            $list->forceFill(['user_id' => $brand->user_id, 'brand_id' => $brand->id])->save();
            $template = App\Models\ReportTemplate::create([
                'user_id' => $brand->user_id, 'brand_id' => $brand->id,
                'name' => 'e2e plantilla de zona horaria', 'blocks' => [], 'range_days' => 30, 'compare' => 'none',
            ]);
            App\Models\ReportSchedule::create([
                'user_id' => $brand->user_id, 'brand_id' => $brand->id,
                'report_template_id' => $template->id, 'frequency' => 'weekly',
                'recipients' => ['qa@example.test'], 'next_run_at' => $at,
            ]);
            $competitor = new App\Models\Competitor(['username' => 'e2e_competidor_zona', 'external_id' => 'e2e-comp-zona']);
            $competitor->forceFill(['user_id' => $brand->user_id, 'brand_id' => $brand->id])->save();
            $item = new App\Models\CompetitorPost([
                'competitor_id' => $competitor->id, 'external_id' => 'e2e-comp-post-zona',
                'caption' => 'e2e publicación del competidor', 'posted_at' => $at, 'likes_count' => 11,
            ]);
            $item->forceFill(['user_id' => $brand->user_id])->save();
            echo 'COMPETITOR:' . $competitor->id;
            $user = App\Models\User::findOrFail($brand->user_id);
            $user->createToken('e2e clave de zona horaria', [], $at->addDays(2))->accessToken
                ->forceFill(['last_used_at' => $at])->save();
        `);
        await loginAs(page, user);
        await page.goto(`/inbox?brand_id=${brand.id}`);
        await expect(page.getByText('e2e comentario con fecha de marca', { exact: true })).toBeVisible();
        await expect(page.getByText(/18 nov 2026, 0?9:30/)).toBeVisible();
        // Al quitar brand_id, la sesión conserva la marca de Auckland.
        await page.goto('/inbox');
        await expect(page.getByText('e2e comentario con fecha de marca', { exact: true })).toBeVisible();
        await expect(page.getByText(/18 nov 2026, 0?9:30/)).toBeVisible();

        await page.goto(`/studio?brand_id=${brand.id}`);
        const design = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'e2e diseño de zona horaria' }) });
        await expect(design).toContainText('18 nov');
        const shared = page.getByRole('region', { name: 'Compartidos conmigo' });
        await expect(shared.getByRole('listitem').filter({ hasText: 'QA compartido Auckland' })).toContainText('18 nov');
        await expect(shared.getByRole('listitem').filter({ hasText: 'QA compartido Madrid' })).toContainText('17 nov');

        await page.goto(`/trash?brand_id=${other.id}`);
        const post = page.getByRole('listitem').filter({ hasText: 'e2e publicación en papelera de Auckland' });
        await expect(post).toContainText('borrada el 18/11/2026');
        const madridPost = page.getByRole('listitem').filter({ hasText: 'e2e publicación en papelera de Madrid' });
        await expect(madridPost).toContainText('borrada el 17/11/2026');

        await page.goto(`/api-keys?brand_id=${brand.id}`);
        const key = page.getByRole('listitem').filter({ hasText: 'e2e clave de zona horaria' });
        await expect(key).toContainText('último uso: 18/11/2026, 09:30:00');
        await key.getByRole('button', { name: 'Revocar e2e clave de zona horaria' }).click();
        const keyConfirmation = page.getByTestId('confirm-dialog');
        await expect(keyConfirmation).toContainText('¿Revocar la API key «e2e clave de zona horaria»? El agente que la use dejará de funcionar.');
        await keyConfirmation.getByRole('button', { name: 'Cancelar' }).click();

        await page.goto(`/autolists?brand_id=${brand.id}`);
        await expect(page.getByText(/última generación:\s*18 nov 2026, 0?9:30/)).toBeVisible();

        await page.goto(`/reports?brand_id=${brand.id}`);
        await expect(page.getByRole('heading', { name: 'Informes programados por correo' })).toBeVisible();
        await expect(page.getByText(/Próximo envío: 18 nov 2026, 0?9:30/)).toBeVisible();
        await page.getByLabel('Plantilla guardada').selectOption({ label: 'e2e plantilla de zona horaria' });
        await page.getByRole('button', { name: 'Eliminar plantilla' }).click();
        const templateConfirmation = page.getByTestId('confirm-dialog');
        await expect(templateConfirmation).toContainText('¿Eliminar la plantilla «e2e plantilla de zona horaria»? También se borran sus envíos programados.');
        await templateConfirmation.getByRole('button', { name: 'Cancelar' }).click();

        const competitorId = seeded.match(/COMPETITOR:(\d+)/)?.[1];
        expect(competitorId).toBeTruthy();
        await page.goto(`/competitors?brand_id=${brand.id}&competitor=${competitorId}&from=2026-11-17&to=2026-11-18`);
        await expect(page.getByText('e2e publicación del competidor', { exact: true })).toBeVisible();
        await expect(page.getByText(/18 nov 2026 · 11 me gusta/)).toBeVisible();
    } finally {
        artisan(String.raw`App\Models\User::query()->where('email', '${sharedOwnerEmail}')->first()?->delete();`);
        cleanComposerBrands(user.email);
    }
});

test('E2E-08 · eliminar una marca se confirma por teclado @responsive', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const brand = seedComposerBrand(user.email, `e2e-native-confirm-${stamp()}`);
    const nativeDialogs: string[] = [];
    page.on('dialog', async dialog => {
        if (dialog.type() === 'confirm') nativeDialogs.push(dialog.message());
        await dialog.dismiss();
    });
    try {
        await loginAs(page, user);
        await page.goto('/brands');
        const row = page.getByRole('listitem').filter({ hasText: brand.name });
        const remove = row.getByRole('button', { name: 'Eliminar' });
        await remove.click();
        const dialog = page.getByRole('alertdialog', { name: 'Confirmar acción' });
        await expect(dialog).toContainText(`¿Eliminar la marca «${brand.name}»?`);
        await expect(dialog.getByRole('button', { name: 'Cancelar' })).toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Eliminar marca' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(remove).toBeFocused();
        await expect(row).toBeVisible();

        await remove.click();
        await expect(dialog.getByRole('button', { name: 'Cancelar' })).toBeFocused();
        await page.keyboard.press('Tab');
        await page.keyboard.press('Enter');
        await expect(row).toHaveCount(0);
        expect(nativeDialogs).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('E2E-08 · sitio y diseño usan la confirmación compartida @responsive', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    const brand = seedComposerBrand(user.email, `e2e-confirmation-${stamp()}`);
    const slug = `e2e-prompt-${stamp()}`.toLowerCase();
    const browserConfirms: string[] = [];
    page.on('dialog', async dialog => {
        if (dialog.type() === 'confirm') browserConfirms.push(dialog.message());
        await dialog.dismiss();
    });
    try {
        const seeded = artisan(String.raw`
            $brand = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id});
            $user = App\Models\User::findOrFail($brand->user_id);
            $site = app(App\Services\Sites\SiteService::class)->create($user, $brand, 'Sitio de la prueba', '${slug}');
            app(App\Services\Studio\StudioService::class)->create($user, $brand, [
                'name' => 'Diseño de la prueba',
                'document' => App\Services\Studio\StudioDocument::blank(1080, 1080),
            ]);
            echo 'SITE:' . $site->id;
        `);
        const siteId = seeded.match(/SITE:(\d+)/)?.[1];
        expect(siteId).toBeTruthy();
        await loginAs(page, user);

        await page.goto(`/sites/${siteId}`);
        const removeSite = page.getByRole('button', { name: 'Eliminar sitio' });
        await removeSite.click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('Su dirección pública dejará de existir.');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(removeSite).toBeFocused();
        await removeSite.click();
        await dialog.getByRole('button', { name: 'Eliminar sitio' }).press('Enter');
        await expect(page).toHaveURL(url => url.pathname === '/sites');

        await page.goto(`/studio?brand_id=${brand.id}`);
        const options = page.getByLabel('Opciones de Diseño de la prueba');
        await options.click();
        const archiveDesign = page.getByRole('button', { name: 'Mover a papelera', exact: true });
        await archiveDesign.click();
        await expect(dialog).toContainText('Podrás restaurarlo después.');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(archiveDesign).toBeFocused();
        await archiveDesign.click();
        await dialog.getByRole('button', { name: 'Mover a papelera', exact: true }).press('Enter');
        await expect(page.getByRole('heading', { name: 'Diseño de la prueba' })).toHaveCount(0);
        expect(browserConfirms).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('E2E-09 · los avisos de API keys se muestran al crear y revocar @responsive', async ({ page }, info) => {
    await loginAs(page, e2eUser(info.project.name));
    const name = `e2e-flash-${stamp()}-${info.project.name}`;
    await page.goto('/api-keys');
    await page.getByLabel(/Nombre \(p\. ej\./).fill(name);
    await page.getByRole('button', { name: 'Solo lectura' }).click();
    await page.getByRole('button', { name: 'Crear API key' }).click();

    const banner = page.getByTestId('flash');
    await expect(banner.locator('[data-flash-kind="success"] [role="status"]')).toHaveText('API key creada.');
    await expect(page.getByTestId('new-key')).toBeVisible();

    const row = page.getByRole('listitem').filter({ hasText: name });
    await row.getByRole('button', { name: 'Revocar' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Revocar API key', exact: true }).click();
    await expect(banner.locator('[data-flash-kind="success"] [role="status"]')).toHaveText('API key revocada.');
    await expect(row).toHaveCount(0);
    await expect(page.getByTestId('new-key')).toHaveCount(0);
});

test('E2E-09 · Studio anuncia los errores al crear un diseño @responsive', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-flash-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Nuevo diseño' });
        const create = dialog.getByRole('button', { name: 'Crear diseño', exact: true });
        const live = dialog.locator('[data-flash-kind="error"] [role="alert"]');
        await expect(live).toHaveText('');
        const firstLive = await live.elementHandle();
        await page.route(`**/brands/${brand.id}/studio/designs`, async route => {
            const body = route.request().postDataJSON();
            await route.continue({ postData: { ...body, name: '', document: {} } });
        }, { times: 1 });
        const validation = page.waitForResponse(response => response.status() === 422
            && new URL(response.url()).pathname === `/brands/${brand.id}/studio/designs`);
        await create.click();
        const { errors } = await (await validation).json();
        expect(Object.keys(errors)).toEqual(expect.arrayContaining(['name', 'document']));
        await expect(live).toBeVisible();
        await expect(live).toHaveText(Object.values(errors).flat().join(' '));
        expect(await firstLive?.evaluate(element => element.isConnected)).toBe(true);

        for (const [status, message] of [[403, 'No tienes acceso a esta marca.'], [500, 'El servicio de diseños no está disponible.']] as const) {
            await page.route(`**/brands/${brand.id}/studio/designs`, route => route.fulfill({ status, json: { message } }), { times: 1 });
            await create.click();
            await expect(dialog).toBeVisible();
            await expect(live).toHaveText(message);
            await expect(create).toBeEnabled();
        }

    } finally {
        cleanComposerBrands(user.email);
    }
});

test('E2E-09 · Studio reintenta abrir el diseño sin crearlo de nuevo @responsive', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-retry-${stamp()}-${info.project.name}`);
    const unexpectedErrors: string[] = [];
    page.on('pageerror', error => unexpectedErrors.push(error.message));
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Nuevo diseño' });
        const create = dialog.getByRole('button', { name: 'Crear diseño', exact: true });
        let interruptEditor!: () => void;
        const pendingEditor = new Promise<void>(resolve => { interruptEditor = resolve; });
        await page.route('**/studio/designs/*/edit', async route => {
            await pendingEditor;
            await route.abort();
        }, { times: 1 });
        let creations = 0;
        page.on('request', request => {
            if (request.method() === 'POST' && new URL(request.url()).pathname === `/brands/${brand.id}/studio/designs`) creations++;
        });
        const editorRequest = page.waitForRequest(request => /\/studio\/designs\/\d+\/edit$/.test(new URL(request.url()).pathname));
        await create.click();
        await editorRequest;
        const saved = page.getByRole('dialog', { name: 'Diseño creado', exact: true });
        const openSaved = saved.getByRole('button', { name: 'Abrir diseño', exact: true });
        try {
            await expect(openSaved).toBeDisabled();
            await expect(openSaved).toBeFocused();
            await expect(saved.getByLabel('Nombre', { exact: true })).toHaveCount(0);
            await expect(saved.locator('[data-flash-kind="info"] [role="status"]')).toHaveText('Abriendo el editor…');
            await expect(saved.getByText('Diseño creado.', { exact: true })).toHaveCount(0);
        } finally {
            interruptEditor();
        }
        await expect(openSaved).toBeEnabled();
        await expect(openSaved).toBeFocused();
        await expect(saved.getByRole('alert').filter({ hasText: /no se pudo abrir el editor/i })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(saved).toHaveCount(0);
        await page.getByRole('button', { name: 'Ver diseño creado', exact: true }).click();
        await expect(saved).toBeVisible();
        await expect(saved.getByRole('button', { name: 'Crear otro diseño', exact: true })).toBeEnabled();
        await openSaved.click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByTestId('flash').locator('[data-flash-kind="success"] [role="status"]')).toHaveText('Diseño creado.');
        expect(creations).toBe(1);
        await expect(saved).toHaveCount(0);
        expect(unexpectedErrors).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('E2E-09 · Studio recupera el foco al fallar la apertura de una plantilla en línea @responsive', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-inline-retry-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        let abortEditor!: () => void;
        const pendingEditor = new Promise<void>(resolve => { abortEditor = resolve; });
        await page.route('**/studio/designs/*/edit', async route => {
            await pendingEditor;
            await route.abort();
        }, { times: 1 });
        let creations = 0;
        page.on('request', request => {
            if (request.method() === 'POST' && new URL(request.url()).pathname === `/brands/${brand.id}/studio/designs`) creations++;
        });
        const request = page.waitForRequest(request => /\/studio\/designs\/\d+\/edit$/.test(new URL(request.url()).pathname));
        try {
            await page.getByRole('region', { name: 'Una idea para empezar' }).getByRole('button', { name: /^Aurora Lanzamientos/ }).click();
            await request;
            await expect(page.getByText('Abriendo el editor…', { exact: true })).toBeVisible();
            await expect(page.getByText('Diseño creado.', { exact: true })).toHaveCount(0);
        } finally {
            abortEditor();
        }

        const open = page.getByRole('button', { name: 'Abrir diseño', exact: true });
        await expect(page.getByRole('alert').filter({ hasText: /no se pudo abrir el editor/i })).toBeVisible();
        await expect(page.getByText('El diseño está guardado.', { exact: true })).toBeVisible();
        await expect(open).toBeFocused();
        await open.click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByText('Diseño creado.', { exact: true })).toHaveCount(1);
        expect(creations).toBe(1);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('E2E-10 · la papelera y las plantillas muestran y recorren 250 elementos @responsive', async ({ page }, info) => {
    const label = `e2e-pagination-${stamp()}-${info.project.name}`;
    const user = { email: `${label}@example.test`, name: label, password: `Fixture-${stamp()}-Aa1!` };
    const seeded = artisan(String.raw`
        $u = App\Models\User::factory()->create([
            'email' => '${user.email}', 'name' => '${user.name}',
            'password' => Illuminate\Support\Facades\Hash::make('${user.password}'),
        ]);
        $b = App\Models\Brand::factory()->for($u)->create();
        $now = now();
        App\Models\Post::factory()->for($u)->for($b)->count(250)->sequence(
            fn ($row) => ['text' => 'Publicación paginada '.($row->index + 1), 'deleted_at' => $now],
        )->create();
        foreach (array_chunk(range(1, 250), 100) as $numbers) {
            App\Models\PostTemplate::query()->insert(array_map(fn ($n) => [
                'user_id' => $u->id, 'brand_id' => $b->id, 'name' => 'Plantilla paginada '.$n,
                'created_at' => $now, 'updated_at' => $now,
            ], $numbers));
        }
        echo 'BRAND:'.$b->id;
    `);
    const brandId = Number(seeded.match(/BRAND:(\d+)/)?.[1]);
    expect(brandId).toBeGreaterThan(0);

    try {
        await loginAs(page, user);
        for (const [path, rowName, evidence] of [
            ['/trash', 'Publicación paginada', 'papelera'],
            [`/library?brand_id=${brandId}`, 'Plantilla paginada', 'biblioteca'],
        ]) {
            await page.goto(path);
            const nav = page.getByTestId('pagination');
            const next = nav.getByRole('link', { name: 'Siguiente' });
            const first = nav.getByRole('link', { name: 'Primera página' });
            const rows = page.getByText(new RegExp(`^${rowName} \\d+$`));
            const seen: string[] = [];

            for (let remaining = 250; remaining > 0; remaining -= 50) {
                await expect(nav).toContainText('250 en total');
                await expect(rows).toHaveCount(50);
                await expect(page.getByText(`${rowName} ${remaining}`, { exact: true })).toBeVisible();
                if (remaining === 250) {
                    await info.attach(`${evidence}-primera-pagina`, { body: await page.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
                }
                seen.push(...await rows.allTextContents());
                if (remaining > 50) await next.click();
            }

            expect(seen).toEqual(Array.from({ length: 250 }, (_, i) => `${rowName} ${250 - i}`));
            await expect(next).toHaveCount(0);
            await expectNoOverflow(page);
            await info.attach(`${evidence}-ultima-pagina`, { body: await page.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
            const stalePage = page.url();
            await first.click();
            await expect(page.getByText(`${rowName} 250`, { exact: true })).toBeVisible();
            await expect(first).toHaveCount(0);

            const query = evidence === 'papelera'
                ? String.raw`App\Models\Post::withoutUserScope()->onlyTrashed()->where('brand_id', ${brandId})`
                : String.raw`App\Models\PostTemplate::withoutUserScope()->where('brand_id', ${brandId})`;
            const remove = evidence === 'papelera' ? 'forceDelete' : 'delete';
            expect(artisan(`$page = ${query}; $ids = (clone $page)->oldest('id')->limit(50)->pluck('id'); echo 'DELETED:' . $page->whereIn('id', $ids)->${remove}();`))
                .toContain('DELETED:50');
            await page.goto(stalePage);
            await expect(page).toHaveURL(url => url.searchParams.get('cursor') === null);
            await expect(nav).toContainText('200 en total');
            await expect(page.getByText(`${rowName} 250`, { exact: true })).toBeVisible();
        }
    } finally {
        artisan(String.raw`App\Models\User::where('email', '${user.email}')->first()?->delete();`);
    }
});

test('E2E-10 · avisos, informes y Studio enlazan sus páginas con un total exacto @responsive', async ({ page }) => {
    const user = { email: `e2e-pagination-${stamp()}@example.test`, name: 'QA paginación', password: 'LocalE2E-pagination-fixture-2026!' };
    try {
        const fixture = artisan(String.raw`
            $user = App\Models\User::factory()->create([
                'email' => '${user.email}', 'name' => '${user.name}',
                'password' => Illuminate\Support\Facades\Hash::make('${user.password}'),
            ]);
            $brand = App\Models\Brand::factory()->for($user)->create();
            foreach ([1, 2, 3] as $i) {
                $at = now()->subDays(4 - $i);
                $user->notifications()->create([
                    'id' => (string) Illuminate\Support\Str::uuid(), 'type' => 'qa-page',
                    'data' => ['message' => 'QA aviso ' . $i], 'created_at' => $at, 'updated_at' => $at,
                ]);
                App\Models\Report::factory()->for($user)->for($brand)->create([
                    'status' => $i >= 2 ? App\Models\Report::STATUS_PENDING : App\Models\Report::STATUS_FAILED,
                ]);
                App\Models\Studio\Design::create([
                    'user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'QA diseño ' . $i,
                    'document' => App\Services\Studio\StudioDocument::blank(),
                    'width' => 1080, 'height' => 1080, 'page_count' => 1, 'revision' => 1, 'updated_at' => $at,
                ]);
            }
            echo 'PAGE_BRAND:' . $brand->id;
        `);
        const brandId = fixture.match(/PAGE_BRAND:(\d+)/)?.[1];
        expect(brandId).toBeTruthy();
        await loginAs(page, user);

        await page.goto('/notifications?limit=2');
        const pagination = page.getByTestId('pagination');
        await expect(pagination).toContainText('3 en total');
        await expect(page.getByText('QA aviso 3', { exact: true })).toBeVisible();
        await expect(page.getByText('QA aviso 1', { exact: true })).toHaveCount(0);
        await pagination.getByRole('link', { name: 'Siguiente', exact: true }).click();
        await expect(pagination).toContainText('3 en total');
        await expect(page.getByText('QA aviso 1', { exact: true })).toBeVisible();
        await expect(pagination.getByRole('link', { name: 'Siguiente', exact: true })).toHaveCount(0);

        await page.goto(`/reports?brand_id=${brandId}&limit=2`);
        await expect(pagination).toContainText('3 en total');
        const reportRows = page.locator('[data-report]');
        const firstReport = reportRows.nth(0);
        await expect(reportRows).toHaveCount(2);
        const previousSecond = await reportRows.nth(1).getAttribute('data-report');
        expect(previousSecond).toBeTruthy();

        const added = artisan(String.raw`
            $brand = App\Models\Brand::withoutUserScope()->findOrFail(${brandId});
            $user = App\Models\User::findOrFail($brand->user_id);
            $report = App\Models\Report::factory()->for($user)->for($brand)->create(['status' => App\Models\Report::STATUS_FAILED]);
            echo 'POLL_REPORT:' . $report->id;
        `).match(/POLL_REPORT:(\d+)/)?.[1];
        expect(added).toBeTruthy();
        await expect(firstReport).toHaveAttribute('data-report', added!, { timeout: 12_000 });
        await expect(pagination).toContainText('4 en total');

        const from = page.getByLabel('Desde', { exact: true });
        const originalDate = await from.inputValue();
        await from.fill('2020-01-01');
        await pagination.getByRole('link', { name: 'Siguiente', exact: true }).click();
        await expect(pagination).toContainText('4 en total');
        await expect(reportRows).toHaveCount(2);
        await expect(firstReport).toHaveAttribute('data-report', previousSecond!);
        await expect(from).toHaveValue(originalDate);
        const pageTwo = new URL(page.url());
        const pageTwoCursor = pageTwo.searchParams.get('cursor');
        expect(pageTwoCursor).toBeTruthy();
        const refreshed = await page.waitForResponse(response => {
            const target = new URL(response.url());
            const partial = response.request().headers()['x-inertia-partial-data'];
            return target.pathname === '/reports' && target.searchParams.get('cursor') === pageTwoCursor
                && partial?.split(',').sort().join(',') === 'pagination,polling,reports';
        });
        expect(refreshed.ok()).toBe(true);
        await expect(page).toHaveURL(pageTwo.href);
        await expect(firstReport).toHaveAttribute('data-report', previousSecond!);
        await expect(pagination).toContainText('4 en total');

        await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
        await expect(page).toHaveURL(url => url.pathname === '/reports'
            && url.searchParams.get('cursor') === null && url.searchParams.get('limit') === '2'
            && url.searchParams.get('brand_id') === brandId);
        await expect(reportRows).toHaveCount(2);
        await expect(firstReport).toContainText('En cola');
        await expect(pagination).toContainText('6 en total');

        await page.goto(`/studio?brand_id=${brandId}&limit=2&query=QA`);
        await expect(pagination).toContainText('3 en total');
        await expect(page.getByRole('heading', { name: 'QA diseño 3', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'QA diseño 1', exact: true })).toHaveCount(0);
        await pagination.getByRole('link', { name: 'Siguiente', exact: true }).click();
        await expect(page).toHaveURL(url => url.searchParams.get('query') === 'QA' && Boolean(url.searchParams.get('cursor')));
        await expect(page.getByRole('heading', { name: 'QA diseño 1', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'QA diseño 3', exact: true })).toHaveCount(0);
        await expect(pagination).toContainText('3 en total');
        await pagination.getByRole('link', { name: 'Primera página', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'QA diseño 3', exact: true })).toBeVisible();
    } finally {
        artisan(String.raw`
            $user = App\Models\User::query()->where('email', '${user.email}')->first();
            if ($user) { $user->notifications()->delete(); $user->delete(); }
        `);
    }
});
