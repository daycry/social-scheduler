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

function seedSuggestionHistory(brandId: number, network = 'instagram') {
    artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId});
        $u = App\Models\User::findOrFail($b->user_id);
        $account = App\Models\SocialAccount::ownedBy($u->id)->where('brand_id', $b->id)->where('network', '${network}')->firstOrFail();
        $at = Carbon\CarbonImmutable::instance(now())->setTimezone($b->timezone)->subWeeks(2)->startOfWeek()->setTime(10, 15)->utc();
        for ($i = 0; $i < max(10, (int) config('social.best_times.min_posts')); $i++) {
            $p = App\Models\Post::factory()->for($u)->for($b)->create(['status' => App\Enums\PostStatus::Published]);
            $t = App\Models\PostTarget::factory()->for($u)->for($p)->create([
                'social_account_id' => $account->id, 'network' => App\Connectors\Network::from('${network}'),
                'status' => App\Enums\PostStatus::Published, 'published_at' => $at,
            ]);
            App\Models\PostMetricSnapshot::withoutUserScope()->create([
                'user_id' => $u->id, 'post_target_id' => $t->id, 'social_account_id' => $account->id,
                'network' => '${network}', 'date' => $at->toDateString(),
                'metrics' => array_replace(App\Analytics\Metric::blankPost(), [App\Analytics\Metric::INTERACTIONS => 20]),
            ]);
        }`);
}

test.describe('Mejor hora', { tag: '@responsive' }, () => {
    let brand: ComposerBrand;
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `best-time-${stamp()}`);
        await loginAs(page, user);
    });
    test.afterEach(async ({}, info) => cleanComposerBrands(e2eUser(info.project.name).email));

    test('E2E-16 · compositor: Mejor hora rellena una fecha futura desde las métricas', async ({ page }, info) => {
        const errors = watch(page);
        seedSuggestionHistory(brand.id);
        await page.goto(`/brands/${brand.id}/posts/create`);
        await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
        await page.locator('#text').fill('Conservar el texto al proponer fecha');
        const button = page
            .getByTestId('best-time-suggestion')
            .getByRole('button', { name: 'Mejor hora', exact: true });
        await expect(button).toBeVisible();
        const [response] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    new URL(r.url()).pathname === `/brands/${brand.id}/best-time-suggestion` &&
                    r.request().method() === 'GET',
            ),
            button.click(),
        ]);
        const proposal = await response.json();
        expect(proposal.available).toBe(true);
        expect(proposal.timezone).toBe('Europe/Madrid');
        expect(Date.parse(proposal.scheduled_at_utc)).toBeGreaterThan(Date.now());
        await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', proposal.scheduled_at);
        const status = page.getByTestId('best-time-suggestion').getByRole('status');
        await expect(status).toContainText('Fecha propuesta');
        await expect(page.locator('#text')).toHaveValue('Conservar el texto al proponer fecha');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Mejor hora en el compositor');
        await page.screenshot({ path: info.outputPath('best-time-composer.png'), fullPage: true });
        await page.locator('#scheduled_at').click();
        await page
            .getByRole('dialog', { name: 'Elegir fecha y hora', exact: true })
            .getByRole('button', { name: 'Quitar fecha', exact: true })
            .click();
        await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', '');
        await expect(status).toBeEmpty();
        expect(errors.errors).toEqual([]);
    });

    test('E2E-16 · sin datos explica el motivo y conserva la fecha del usuario', async ({ page }) => {
        const errors = watch(page);
        await page.goto(`/brands/${brand.id}/posts/create?selector=nativo`);
        await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
        await page.locator('#scheduled_at').fill('2031-01-15T10:30');
        const suggestion = page.getByTestId('best-time-suggestion');
        await suggestion.getByRole('button', { name: 'Mejor hora', exact: true }).click();
        await expect(suggestion.getByRole('status')).not.toBeEmpty();
        await expect(page.locator('#scheduled_at')).toHaveValue('2031-01-15T10:30');
        await expectNoOverflow(page);
        expect(errors.errors).toEqual([]);
    });

    test('E2E-16 · calendario: abre un compositor nuevo con la propuesta y no guarda una publicación', async ({
        page,
    }, info) => {
        const errors = watch(page);
        const saves: string[] = [];
        page.on('request', (request) => {
            if (
                ['POST', 'PUT', 'PATCH'].includes(request.method()) &&
                /\/(brands\/\d+\/posts|posts\/\d+)$/.test(new URL(request.url()).pathname)
            ) {
                saves.push(request.url());
            }
        });
        seedSuggestionHistory(brand.id);
        await page.goto(`/calendar?brand_id=${brand.id}&network=instagram`);
        const button = page
            .getByTestId('best-time-suggestion')
            .getByRole('button', { name: 'Mejor hora', exact: true });
        await expect(button).toBeVisible();
        await axeSeriousCritical(page, 'Mejor hora en el calendario');
        const [response] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    new URL(r.url()).pathname === `/brands/${brand.id}/best-time-suggestion` &&
                    r.request().method() === 'GET',
            ),
            button.click(),
        ]);
        const proposal = await response.json();
        expect(proposal.available).toBe(true);
        expect(proposal.timezone).toBe('Europe/Madrid');
        await page.waitForURL((url) => url.pathname === `/brands/${brand.id}/posts/create`);
        await expect(page.locator('#scheduled_at')).toHaveAttribute('data-value', proposal.scheduled_at);
        expect(saves).toEqual([]);
        await expectNoOverflow(page);
        await page.screenshot({ path: info.outputPath('best-time-calendar-to-composer.png'), fullPage: true });
        expect(errors.errors).toEqual([]);
    });

    test('E2E-16 · una propuesta caducada abre el compositor con aviso y permite elegir otra fecha', async ({
        page,
    }) => {
        const errors = watch(page);
        const url = new URL(`/brands/${brand.id}/posts/create?selector=nativo&best_time=2000-01-01T10%3A00`, page.url())
            .href;
        await page.goto(url, { referer: url });
        await expect(page.getByRole('heading', { name: /^Nueva publicación/ })).toBeVisible();
        await expect(page.getByTestId('best-time-notice')).toHaveText(
            'La fecha sugerida del enlace no es válida. Elige otra fecha o consulta Mejor hora.',
        );
        await expect(page.locator('#scheduled_at')).toHaveValue('');
        await page.locator('#scheduled_at').fill('2031-01-15T10:30');
        await expect(page.locator('#scheduled_at')).toHaveValue('2031-01-15T10:30');
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Compositor con propuesta caducada');
        expect(errors.errors).toEqual([]);
    });

    test('E2E-16 · varias redes: la elección explícita y las cuentas seleccionadas cambian la consulta', async ({
        page,
    }) => {
        seedSuggestionHistory(brand.id);
        seedSuggestionHistory(brand.id, 'facebook');
        await page.goto(`/brands/${brand.id}/posts/create`);
        const widget = page.getByTestId('best-time-suggestion');
        const choice = widget.getByLabel('Red para la mejor hora', { exact: true });
        await expect(choice).toBeVisible();
        await choice.selectOption('facebook');
        await axeSeriousCritical(page, 'Mejor hora con varias redes');
        const [facebook] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    new URL(r.url()).pathname === `/brands/${brand.id}/best-time-suggestion` &&
                    new URL(r.url()).searchParams.get('network') === 'facebook',
            ),
            widget.getByRole('button', { name: 'Mejor hora', exact: true }).click(),
        ]);
        expect((await facebook.json()).network).toBe('facebook');
        await page.getByRole('button', { name: /^Instagram: e2e Instagram/ }).click();
        await expect(choice).toHaveCount(0);
        const [instagram] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    new URL(r.url()).pathname === `/brands/${brand.id}/best-time-suggestion` &&
                    new URL(r.url()).searchParams.get('network') === 'instagram',
            ),
            widget.getByRole('button', { name: 'Mejor hora', exact: true }).click(),
        ]);
        expect((await instagram.json()).network).toBe('instagram');
        await expectNoOverflow(page);
    });

    test('E2E-16 · sin filtro de calendario se exige una red elegida por el usuario', async ({ page }) => {
        seedSuggestionHistory(brand.id);
        await page.goto(`/calendar?brand_id=${brand.id}`);
        const widget = page.getByTestId('best-time-suggestion');
        const button = widget.getByRole('button', { name: 'Mejor hora', exact: true });
        await expect(button).toBeDisabled();
        await widget.getByLabel('Red para la mejor hora', { exact: true }).selectOption('instagram');
        await expect(button).toBeEnabled();
        await button.click();
        await page.waitForURL((url) => url.pathname === `/brands/${brand.id}/posts/create`);
        await expect(page.locator('#scheduled_at')).not.toHaveAttribute('data-value', '');
    });
});
