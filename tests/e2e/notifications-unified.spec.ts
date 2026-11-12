import { expect, test } from '@playwright/test';
import { artisan, axeSeriousCritical, e2eUser, expectNoOverflow, loginAs, settle, watch } from './helpers';

/**
 * T-23 · Avisos unificados: los avisos del estudio y de los trabajos asíncronos fallidos tienen título propio y enlace genérico (`n.url`),
 * igual que los de publicación. Los avisos se siembran por PHP en el usuario E2E y se borran al terminar; no se toca ninguna red.
 */
test.describe.configure({ mode: 'serial' });

const seed = (email: string) =>
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); $u->notifications()->delete(); ` +
            String.raw`$u->notify(new App\Notifications\StudioDesignActivity('comment', 99991, 'Rebajas e2e', 'Ana ha comentado en «Rebajas e2e»')); ` +
            String.raw`$u->notify(new App\Notifications\StudioDesignActivity('revoked', 99991, 'Rebajas e2e', 'Ya no tienes acceso a «Rebajas e2e».')); ` +
            String.raw`Illuminate\Support\Facades\Notification::sendNow($u, new App\Notifications\AsyncJobFailed('canva', 'Canva no ha podido completar un trabajo', 'No se pudo completar el trabajo con Canva.', '/brands/1/canva', 'Banner', 1)); ` +
            String.raw`Illuminate\Support\Facades\Notification::sendNow($u, new App\Notifications\AsyncJobFailed('report', 'No se pudo enviar un informe programado', 'No se pudo generar el informe.', '/reports', 'Marca', 1)); echo 'SEEDED';`,
    );

const clean = (email: string) => artisan(String.raw`$u = App\Models\User::where('email', '${email}')->first(); if ($u) { $u->notifications()->delete(); } echo 'CLEAN';`);

test.afterEach(async ({}, info) => {
    clean(e2eUser(info.project.name).email);
});

test('E2E-03 · avisos del estudio y de trabajos fallidos con título y enlace propios', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    expect(seed(user.email)).toContain('SEEDED');
    const w = watch(page);
    await loginAs(page, user);
    await page.goto('/notifications');
    await settle(page);

    await expect(page.getByRole('heading', { name: 'Comentario nuevo en un diseño' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Te han retirado el acceso a un diseño' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Canva no ha podido completar un trabajo' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'No se pudo enviar un informe programado' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Aviso', exact: true })).toHaveCount(0);

    await expect(page.getByTestId('notice-link-studio_design_comment')).toHaveAttribute('href', /\/studio\/designs\/99991\/edit/);
    await expect(page.getByTestId('notice-link-studio_design_revoked')).toHaveCount(0); // sin acceso ya no hay a dónde ir
    await expect(page.getByTestId('notice-link-job_failed_canva')).toHaveText('Abrir Canva');
    await expect(page.getByTestId('notice-link-job_failed_report')).toHaveAttribute('href', /\/reports$/);

    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Avisos unificados');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});
