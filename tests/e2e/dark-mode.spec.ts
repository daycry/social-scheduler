import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type Route } from '@playwright/test';
import {
    animationsDone,
    artisan,
    enableTwoFactorDirect,
    expectNoOverflow,
    loginAs,
    loginUntilChallenge,
    seedComposerBrand,
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

async function auditPage(page: Page, path: string, heading: string, scheme: 'light' | 'dark') {
    await page.goto(path);
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    await expectScheme(page, scheme);
    await auditAxe(page);
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
