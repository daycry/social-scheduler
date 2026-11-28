import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import {
    animationsDone,
    artisan,
    enableTwoFactorDirect,
    expectNoOverflow,
    loginAs,
    loginUntilChallenge,
    seedAnalyticsSnapshots,
    seedCampaignPanel,
    seedComposerBrand,
    seedComposerPosts,
    seedInboxComment,
    stamp,
    totp,
} from './helpers';

async function auditAxe(page: Page, ...selectors: string[]) {
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await animationsDone(page);
    const axe = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
    for (const selector of selectors) axe.include(selector);
    const { violations } = await axe.analyze();
    const scheme = await page.locator('html').evaluate((root) => getComputedStyle(root).colorScheme);
    expect(
        violations.map(({ id, nodes }) => ({ id, targets: nodes.map((node) => node.target) })),
        `axe en ${new URL(page.url()).pathname} (${scheme})`,
    ).toEqual([]);
    await expectNoOverflow(page);
}

async function auditPage(page: Page, path: string, heading: string, scheme: 'light' | 'dark', content?: Locator) {
    await page.goto(path);
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    if (content) await expect(content).toBeVisible();
    await expectScheme(page, scheme);
    await auditAxe(page);
}

function seedHeatmap(brandId: number) {
    artisan(String.raw`
        $account = App\Models\SocialAccount::withoutUserScope()->where('brand_id', ${brandId})->where('network', 'instagram')->firstOrFail();
        $day = now()->subDays(4)->startOfDay();
        $interactions = [5, 25, 45, 65, 100];
        App\Models\Post::factory()->count(max(5, (int) config('social.best_times.min_posts')))->create([
            'user_id' => $account->user_id, 'brand_id' => ${brandId}, 'status' => App\Enums\PostStatus::Published,
            'scheduled_at' => null,
        ])->each(function ($post, $index) use ($account, $day, $interactions) {
            $slot = $index % count($interactions);
            $target = App\Models\PostTarget::factory()->create([
                'post_id' => $post->id, 'user_id' => $post->user_id, 'social_account_id' => $account->id,
                'status' => App\Enums\PostStatus::Published, 'published_at' => $day->copy()->setTime(10 + $slot, 0),
            ]);
            App\Models\PostMetricSnapshot::withoutUserScope()->create([
                'user_id' => $post->user_id, 'post_target_id' => $target->id, 'social_account_id' => $account->id,
                'network' => 'instagram', 'date' => $day->toDateString(), 'metrics' => ['interactions' => $interactions[$slot]],
            ]);
        });
    `);
}

async function auditSystemThemes(page: Page) {
    await page.emulateMedia({ colorScheme: 'light' });
    await expectScheme(page, 'light');
    await auditAxe(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectScheme(page, 'dark');
    await auditAxe(page);
}

async function auditGuestPages(page: Page, scheme: 'light' | 'dark') {
    await auditPage(page, '/login', 'Entra en tu cuenta', scheme);
    await page.getByRole('button', { name: 'Entrar con passkey' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'No se pudo iniciar la passkey.' })).toBeVisible();
    await auditAxe(page);

    await auditPage(page, '/register', 'Crea tu cuenta', scheme);
    await auditPage(page, '/forgot-password', 'Recupera tu contraseña', scheme);
    await auditPage(
        page,
        '/reset-password/e2e-theme?email=e2e-theme@example.test',
        'Elige una nueva contraseña',
        scheme,
    );
}

async function expectScheme(page: Page, scheme: 'light' | 'dark') {
    const root = page.locator('html');
    if (scheme === 'dark') await expect(root).toHaveClass(/\bdark\b/);
    else await expect(root).not.toHaveClass(/\bdark\b/);
    await expect(root).toHaveCSS('color-scheme', scheme);
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute(
        'content',
        scheme === 'dark' ? '#0a1020' : '#0f172a',
    );
}

async function saveTheme(page: Page, preference: 'light' | 'dark' | 'system') {
    await page.getByLabel('Tema', { exact: true }).selectOption(preference);
    await page.getByRole('button', { name: 'Guardar tema', exact: true }).click();
    await expect(page.getByTestId('flash').getByRole('status').filter({ hasText: 'Tema guardado.' })).toBeVisible();
}

test.describe('E2E-35 · tema del layout y controles comunes', { tag: '@responsive' }, () => {
    test.use({ colorScheme: 'light' });

    test('persiste por cuenta y aplica el oscuro incluso antes de cargar Vue', async ({ page }, info) => {
        const id = `theme-${stamp()}-${info.project.name}`;
        const user = { name: 'Apariencia E2E', email: `e2e-${id}@example.test`, password: 'E2e-Theme-Disposable-7!' };
        const email = JSON.stringify(user.email);
        artisan(
            String.raw`App\Models\User::factory()->create(['email' => ${email}, 'name' => 'Apariencia E2E', 'password' => ${JSON.stringify(user.password)}]);`,
        );
        try {
            const brand = seedComposerBrand(user.email, id);
            const sharedToken = artisan(String.raw`
                $u = App\Models\User::where('email', ${email})->firstOrFail();
                $doc = App\Services\Studio\StudioDocument::blank(320, 240);
                $d = App\Models\Studio\Design::unguarded(fn () => App\Models\Studio\Design::create([
                    'user_id' => $u->id, 'brand_id' => ${brand.id}, 'name' => 'Revisión E2E',
                    'document' => $doc, 'width' => 320, 'height' => 240, 'page_count' => 1, 'revision' => 1,
                ]));
                [, $token] = app(App\Services\Studio\StudioCollab::class)->createLink($u, $d, 'reader');
                echo $token;
            `).trim();
            await loginAs(page, user);
            await page.goto('/profile');
            await expect(page.getByLabel('Tema', { exact: true })).toHaveValue('system');
            await expectScheme(page, 'light');
            await page.screenshot({ path: info.outputPath('tema-inicial.png') });
            await saveTheme(page, 'dark');
            await expectScheme(page, 'dark');
            await auditAxe(page, '[data-flash-kind="success"]');

            const withoutBundle = (route: Route) =>
                route.request().resourceType() === 'script' ? route.abort() : route.continue();
            await page.route('**/*', withoutBundle);
            try {
                await page.reload();
                await expectScheme(page, 'dark');
                await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(15, 23, 42)');
                await expect(page.getByLabel('Tema', { exact: true })).toHaveCount(0);
            } finally {
                await page.unroute('**/*', withoutBundle);
            }
            await page.reload();
            await expect(page.getByLabel('Tema', { exact: true })).toHaveValue('dark');
            await page.screenshot({ path: info.outputPath('tema-oscuro-guardado.png') });

            for (const scheme of ['dark', 'light'] as const) {
                await page.goto('/profile');
                if (scheme === 'light') await saveTheme(page, 'light');
                await expectScheme(page, scheme);
                await auditAxe(page);
                const header = 'header.sticky';
                for (const name of ['Abrir menú', 'Cambiar de marca', 'Menú de usuario']) {
                    const trigger = page.getByRole('button', { name, exact: true });
                    if (name === 'Abrir menú' && !(await trigger.isVisible())) continue;
                    await trigger.click();
                    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
                    await auditAxe(page, header);
                    if (name === 'Abrir menú') await trigger.click();
                    else await page.keyboard.press('Escape');
                    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
                }

                await page.getByRole('button', { name: 'Ayuda y atajos', exact: true }).click();
                const help = page.getByRole('alertdialog', { name: 'Ayuda y atajos', exact: true });
                await expect(help.getByRole('checkbox')).toBeVisible();
                await auditAxe(page, 'dialog[aria-labelledby="global-help-title"]');
                await help.getByRole('button', { name: 'Cerrar ayuda' }).click();

                await auditPage(page, '/profile/notifications', 'Notificaciones del navegador', scheme);
                await auditPage(page, '/profile/export', 'Exportar mis datos', scheme);
                await auditPage(page, '/confirm-password', 'Confirma tu contraseña', scheme);

                await page.goto('/brands');
                await page.getByRole('button', { name: 'Eliminar', exact: true }).click();
                const confirm = page.getByTestId('confirm-dialog');
                await expect(confirm).toBeVisible();
                await auditAxe(page, '[data-testid="confirm-dialog"]');
                await confirm.getByRole('button', { name: 'Cancelar', exact: true }).click();

                await page.goto(`/brands/${brand.id}/posts/create`);
                await expect(page.getByTestId('composer-close')).toBeVisible();
                await auditAxe(page, header);

                await page.goto(`/d/${sharedToken}`);
                await expect(page.getByTestId('studio-review')).toBeVisible();
                await auditAxe(page, 'header:has(+ #contenido-compartido)');
            }

            await page.goto('/profile');
            await saveTheme(page, 'system');
            await page.emulateMedia({ colorScheme: 'dark' });
            await expectScheme(page, 'dark');
            await page.reload();
            await expect(page.getByLabel('Tema', { exact: true })).toHaveValue('system');
            await expectScheme(page, 'dark');
            await page.emulateMedia({ colorScheme: 'light' });
            await expectScheme(page, 'light');
        } finally {
            artisan(String.raw`App\Models\User::where('email', ${email})->get()->each->delete();`);
        }
    });

    test('el invitado conserva su elección y las páginas de acceso pasan axe con ambas paletas', async ({ page }) => {
        await page.addInitScript(() => {
            PublicKeyCredential.isConditionalMediationAvailable = async () => false;
        });
        await page.route('**/passkeys/login/options', (route) =>
            route.fulfill({ status: 503, json: { message: 'No se pudo iniciar la passkey.' } }),
        );
        await page.goto('/login');
        const theme = page.getByLabel('Tema', { exact: true });
        await expect(theme).toHaveValue('system');
        await auditGuestPages(page, 'light');

        await theme.selectOption('dark');
        await page.reload();
        await expect(theme).toHaveValue('dark');
        await auditGuestPages(page, 'dark');

        await page.goto('/login');
        await page.getByLabel('Correo electrónico').fill('e2e-tema-inexistente@example.test');
        await page.getByLabel('Contraseña', { exact: true }).fill('No-existe-28#');
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
        const email = page.getByLabel('Correo electrónico');
        await expect(email).toHaveAccessibleDescription(/.+/);
        await auditAxe(page);
    });

    test('el reto 2FA y la verificación de correo pasan axe con ambas paletas', async ({ page }, info) => {
        const user = {
            name: 'Verificación E2E',
            email: `e2e-theme-verification-${stamp()}-${info.project.name}@example.test`,
            password: 'E2e-Theme-Disposable-7!',
        };
        const email = JSON.stringify(user.email);
        artisan(String.raw`App\Models\User::factory()->unverified()->create([
            'email' => ${email}, 'name' => ${JSON.stringify(user.name)}, 'password' => ${JSON.stringify(user.password)},
        ]);`);
        try {
            const { secret } = enableTwoFactorDirect(user.email);
            await loginUntilChallenge(page, user);
            await expect(page.getByRole('heading', { name: 'Verificación en dos pasos' })).toBeVisible();
            await auditSystemThemes(page);

            await page.getByRole('button', { name: 'Usar un código de recuperación' }).click();
            await expect(page.getByLabel('Código de recuperación')).toBeVisible();
            await auditSystemThemes(page);

            await page.reload();
            await page.getByLabel('Código de verificación').fill(totp(secret));
            await page.getByRole('button', { name: 'Verificar', exact: true }).click();
            await expect(page).toHaveURL(/\/verify-email$/);
            await expect(page.getByRole('heading', { name: 'Verifica tu correo' })).toBeVisible();
            await auditSystemThemes(page);
        } finally {
            artisan(String.raw`App\Models\User::where('email', ${email})->get()->each->delete();`);
        }
    });
});

test.describe('E2E-35 · páginas principales y Estudio', { tag: '@responsive' }, () => {
    for (const scheme of ['light', 'dark'] as const) {
        test(`contraste y desbordamiento en ${scheme}`, async ({ page }, info) => {
            test.slow();
            const id = `theme-pages-${stamp()}-${info.project.name}`;
            const user = {
                name: 'Páginas E2E',
                email: `e2e-${id}@example.test`,
                password: 'E2e-Theme-Pages-Disposable-7!',
            };
            const email = JSON.stringify(user.email);
            artisan(String.raw`App\Models\User::factory()->create([
                'email' => ${email}, 'name' => 'Páginas E2E', 'password' => ${JSON.stringify(user.password)},
                'theme' => '${scheme}',
            ]);`);
            try {
                const brand = seedComposerBrand(user.email, id);
                const { scheduledDate } = seedComposerPosts(user.email, brand.id);
                const { from, to } = seedAnalyticsSnapshots(brand.id, 120, 85);
                const { campaignId } = seedCampaignPanel(user.email, brand.id);
                seedHeatmap(brand.id);
                seedInboxComment(brand.id, 'Comentario E2E de contraste');
                const designId = Number(
                    artisan(String.raw`
                    $u = App\Models\User::where('email', ${email})->firstOrFail();
                    $doc = App\Services\Studio\StudioDocument::blank(320, 240);
                    $doc['pages'][0]['background'] = '#345678';
                    $d = App\Models\Studio\Design::unguarded(fn () => App\Models\Studio\Design::create([
                        'user_id' => $u->id, 'brand_id' => ${brand.id}, 'name' => 'Diseño E2E de contraste',
                        'document' => $doc, 'width' => 320, 'height' => 240, 'page_count' => 1, 'revision' => 1,
                    ]));
                    echo $d->id;
                `).trim(),
                );
                expect(designId).toBeGreaterThan(0);
                await loginAs(page, user);

                const period = `brand_id=${brand.id}&from=${from}&to=${to}`;
                const calendar = `brand_id=${brand.id}&date=${scheduledDate}`;
                const heatmap = page.getByRole('table', { name: 'Mapa de calor de engagement medio por día y hora' });
                // Una serie plana puede tener una caja de altura cero aunque el SVG esté visible.
                const seriesLine = page.locator('polyline[points]:not([points=""])');
                const instagramViews = page
                    .locator('[data-metric="views"]')
                    .getByRole('img', { name: 'Gráfico de evolución', exact: true })
                    .filter({ has: seriesLine });
                const campaignViews = page
                    .getByRole('region', { name: 'Evolución diaria' })
                    .locator('.card')
                    .filter({
                        has: page.getByRole('heading', { name: 'Visualizaciones leídas por día' }),
                    })
                    .getByRole('img', { name: 'Gráfico de evolución', exact: true })
                    .filter({ has: seriesLine.or(page.locator('circle[cx][cy]')) });
                const pages: [path: string, heading: string, content?: Locator][] = [
                    [`/dashboard?${period}`, `Resumen · ${brand.name}`],
                    [`/calendar?view=week&${calendar}`, 'Calendario'],
                    [`/calendar?view=list&${calendar}`, 'Calendario'],
                    [`/dashboard/instagram?${period}`, `Instagram · ${brand.name}`, instagramViews],
                    [
                        `/best-times?brand_id=${brand.id}&network=instagram`,
                        'Mejores horas para publicar',
                        heatmap.getByRole('cell', { name: '100', exact: true }),
                    ],
                    [`/campaigns/${campaignId}`, 'e2e-camp-panel', campaignViews],
                    ['/inbox', 'Inbox de comentarios'],
                    ['/reports', 'Informes'],
                    ['/autolists', 'Autolistas'],
                    ['/autolists/recycle', 'Reciclado de posts'],
                    ['/content-plan', 'Plan de contenido'],
                    ['/smartlinks', 'SmartLinks'],
                    [`/brands/${brand.id}/connections`, `Conexiones · ${brand.name}`],
                    ['/brands', 'Marcas'],
                    [`/studio?brand_id=${brand.id}`, 'Estudio creativo'],
                    ['/settings/saved-texts', 'Textos guardados'],
                    ['/trash', 'Papelera de publicaciones'],
                    ['/notifications', 'Avisos'],
                ];
                for (const [path, heading, content] of pages) {
                    await test.step(path, () => auditPage(page, path, heading, scheme, content));
                }

                await test.step('Compositor', async () => {
                    await page.goto(`/brands/${brand.id}/posts/create`);
                    await expect(page.getByTestId('composer-close')).toBeVisible();
                    await expectScheme(page, scheme);
                    await auditAxe(page);
                });

                await test.step('Editor, lienzo y diálogo de exportación', async () => {
                    await page.goto(`/studio/designs/${designId}/edit`);
                    await expect(page.getByLabel('Nombre del diseño')).toHaveValue('Diseño E2E de contraste');
                    await expectScheme(page, scheme);
                    const editor = page.getByRole('region', { name: 'Editor de diseño', exact: true });
                    const canvas = editor.locator('canvas.lower-canvas');
                    await expect(canvas).toBeVisible();
                    const exportButton = page.getByRole('button', { name: 'Exportar', exact: true });
                    await expect(exportButton).toBeEnabled();
                    const pixel = await canvas.evaluate((element: HTMLCanvasElement) => {
                        const { width, height } = element;
                        const context = element.getContext('2d');
                        return Array.from(
                            context!.getImageData(Math.floor(width / 2), Math.floor(height / 2), 1, 1).data,
                        );
                    });
                    expect(pixel).toEqual([52, 86, 120, 255]);
                    await auditAxe(page);
                    await exportButton.click();
                    await expect(page.getByRole('dialog', { name: 'Exportar diseño', exact: true })).toBeVisible();
                    await auditAxe(page);
                });
            } finally {
                artisan(String.raw`App\Models\User::where('email', ${email})->get()->each->delete();`);
            }
        });
    }
});
