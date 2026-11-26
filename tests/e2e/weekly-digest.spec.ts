import { expect, test, type Page } from '@playwright/test';
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
} from './helpers';

/**
 * Resumen semanal (T-86, E2E-25): se activa en las preferencias de avisos, el job se ejecuta con `artisan` en un lunes por la mañana de la zona de la
 * marca y deja el aviso en la app; con la preferencia desactivada no genera nada. Usuarios `e2e-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

const label = `weekly-digest-${stamp()}`;

/** Lunes 30-11-2026 a las 09:00 locales (se completa con la zona de la marca principal del usuario): ya toca el resumen de la semana 23-29 nov. */
const MONDAY = '2026-11-30 09:00:00';

/** Deja al usuario con el resumen y el correo apagados, sin marca de envío ni avisos de pruebas anteriores. */
const resetDigest = (email: string) =>
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); $u->forceFill(['notify_weekly_digest' => false, 'weekly_digest_ai' => false, 'notify_manual_reminder' => true, 'notify_by_mail' => false, 'weekly_digest_sent_for' => null])->save(); $u->notifications()->delete(); echo 'RESET';`,
    );

/**
 * Ejecuta el job como lo haría el planificador un lunes a las 09:00 en la zona de la marca principal (la más antigua del usuario, que puede no ser
 * la sembrada aquí): el planificador solo reparte un job por usuario y un `queue:work` los procesa. `queue:work --stop-when-empty` termina el
 * proceso al acabar, así que va el último de su ejecución y el recuento se lee en otra. Devuelve cuántos resúmenes tiene el usuario.
 */
const runDigest = (email: string): number => {
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); Illuminate\Support\Carbon::setTestNow('${MONDAY} ' . app(App\Services\WeeklyDigest::class)->zoneFor($u)); (new App\Jobs\SendWeeklyDigest)->handle(app(App\Services\WeeklyDigest::class)); Illuminate\Support\Facades\Artisan::call('queue:work', ['--queue' => 'reports', '--stop-when-empty' => true, '--tries' => 1, '--timeout' => 100, '--sleep' => 0]);`,
    );
    const out = artisan(
        String.raw`echo 'DIGESTS:' . App\Models\User::where('email', '${email}')->firstOrFail()->notifications()->where('data->type', 'weekly_digest')->count();`,
    );
    const match = out.match(/DIGESTS:(\d+)/);
    if (!match) throw new Error(`No se pudo leer el recuento de resúmenes: ${out.slice(0, 600)}`);
    return Number(match[1]);
};

/** Datos de la semana 23-29 nov en la cuenta de Instagram de la marca sembrada: sin ellos el resumen diría «no hay datos». */
const seedWeek = (brandId: number) =>
    artisan(
        String.raw`$a = App\Models\SocialAccount::withoutUserScope()->where('brand_id', ${brandId})->where('network', 'instagram')->firstOrFail(); for ($d = Illuminate\Support\Carbon::parse('2026-11-23'); $d->lte(Illuminate\Support\Carbon::parse('2026-11-29')); $d->addDay()) { App\Models\AccountSnapshot::withoutUserScope()->create(['user_id' => $a->user_id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->id, 'network' => 'instagram', 'date' => $d->format('Y-m-d'), 'metrics' => array_merge(App\Analytics\Metric::blankAccount(), [App\Analytics\Metric::FOLLOWERS => 120, App\Analytics\Metric::VIEWS => 15]), 'collected_at' => now(), 'settled_at' => now()]); } echo 'SEEDED';`,
    );

async function savePreferences(page: Page) {
    await page.getByTestId('pref-save').click();
    await expect(page.getByText('Guardado.', { exact: true })).toBeVisible();
}

test.describe('Resumen semanal', { tag: '@responsive' }, () => {
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        resetDigest(user.email);
        seedWeek(seedComposerBrand(user.email, `${label}-${info.project.name}`).id);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        const user = e2eUser(info.project.name);
        resetDigest(user.email);
        cleanComposerBrands(user.email);
    });

    test('E2E-25 · activar el resumen semanal: el job deja el aviso en la app y, con la preferencia apagada, no genera nada', async ({
        page,
    }, info) => {
        const w = watch(page);
        const user = e2eUser(info.project.name);

        await page.goto('/profile/notifications');
        await settle(page);
        await expect(
            page.getByRole('heading', { level: 2, name: 'Qué avisos quieres recibir', exact: true }),
        ).toBeVisible();
        await expect(page.getByTestId('pref-weekly-digest')).not.toBeChecked();
        await expect(page.getByTestId('pref-manual-reminder')).toBeChecked();
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Preferencias de avisos: estado inicial');
        await page.screenshot({ path: info.outputPath('weekly-digest-initial.png'), fullPage: true });

        // Sin la preferencia, el job no genera nada.
        expect(runDigest(user.email)).toBe(0);

        await page.getByTestId('pref-weekly-digest').check();
        await savePreferences(page);
        await expect(page.getByTestId('pref-weekly-digest')).toBeChecked();

        expect(runDigest(user.email)).toBe(1);
        expect(runDigest(user.email), 'una segunda pasada de la misma semana no repite el resumen').toBe(1);

        await page.goto('/notifications');
        await settle(page);
        const notice = page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: /^Tu resumen semanal: / }) });
        await expect(notice).toHaveCount(1);
        await expect(notice).toContainText('Ya tienes las cifras de la semana de');
        await expect(notice.getByRole('link', { name: 'Abrir el panel', exact: true })).toHaveAttribute(
            'href',
            '/dashboard',
        );
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Avisos: resumen semanal');

        // Desactivarlo de nuevo: ni siquiera una semana nueva genera otro resumen.
        await page.goto('/profile/notifications');
        await settle(page);
        await page.getByTestId('pref-weekly-digest').uncheck();
        await savePreferences(page);
        artisan(
            String.raw`$u = App\Models\User::where('email', '${user.email}')->firstOrFail(); $u->notifications()->delete(); $u->forceFill(['weekly_digest_sent_for' => null])->save(); echo 'CLEARED';`,
        );
        expect(runDigest(user.email)).toBe(0);

        await page.goto('/notifications');
        await settle(page);
        await expect(page.getByRole('heading', { name: /^Tu resumen semanal: / })).toHaveCount(0);
        await page.screenshot({ path: info.outputPath('weekly-digest-final.png'), fullPage: true });
        expect(w.errors, 'errores de consola').toEqual([]);
    });
});
