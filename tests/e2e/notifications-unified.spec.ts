import { expect, test } from '@playwright/test';
import { artisan, axeSeriousCritical, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp, watch } from './helpers';

/**
 * T-23 · Avisos unificados: los avisos del estudio y de los trabajos asíncronos (fallo, resumen y éxito) tienen título propio y enlace genérico
 * (`n.url`), igual que los de publicación; y Meta Ads comparte con el inbox el aviso de permisos. Los avisos se siembran por PHP en el usuario E2E
 * y se borran al terminar; no se toca ninguna red.
 */
test.describe.configure({ mode: 'serial' });

const NOW = String.raw`Illuminate\Support\Facades\Notification::sendNow($u, new App\Notifications`;

const seed = (email: string) =>
    artisan(
        String.raw`$u = App\Models\User::where('email', '${email}')->firstOrFail(); $u->notifications()->delete(); ` +
            String.raw`$u->notify(new App\Notifications\StudioDesignActivity('comment', 99991, 'Rebajas e2e', 'Ana ha comentado en «Rebajas e2e»')); ` +
            String.raw`$u->notify(new App\Notifications\StudioDesignActivity('revoked', 99991, 'Rebajas e2e', 'Ya no tienes acceso a «Rebajas e2e».')); ` +
            `${NOW}\\AsyncJobFailed('canva', 'Canva no ha podido completar un trabajo', 'No se pudo completar el trabajo con Canva.', '/brands/1/canva', 'Banner', 1)); ` +
            `${NOW}\\AsyncJobFailed('report', 'No se pudo enviar un informe programado', 'No se pudo generar el informe.', '/reports', 'Marca', 1)); ` +
            `${NOW}\\AsyncJobDone('studio_export', 'Exportación lista en MP4', 'Ya puedes descargarla desde el diseño.', '/studio/designs/99991/edit', 'Rebajas e2e', 1)); ` +
            `${NOW}\\AsyncJobFailureSummary('import', 3, 'importación', '/media', null)); ` +
            String.raw`$u->notifications()->create(['id' => (string) Illuminate\Support\Str::uuid(), 'type' => 'App\Notifications\PostMediaMissing', 'data' => ['type' => 'media_missing', 'post_id' => 4242, 'message' => 'Falta un archivo', 'url' => '/posts/4242/edit']]); echo 'SEEDED';`,
    );

const clean = (email: string) => artisan(String.raw`$u = App\Models\User::where('email', '${email}')->first(); if ($u) { $u->notifications()->delete(); } echo 'CLEAN';`);

test.afterEach(async ({}, info) => {
    clean(e2eUser(info.project.name).email);
});

test('E2E-03 · avisos del estudio y de trabajos asíncronos con título y enlace propios', { tag: '@responsive' }, async ({ page }, info) => {
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
    await expect(page.getByRole('heading', { name: 'Exportación lista en MP4' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Y 3 fallos más de importación en la última hora' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Aviso', exact: true })).toHaveCount(0);

    await expect(page.getByTestId('notice-link-studio_design_comment')).toHaveAttribute('href', /\/studio\/designs\/99991\/edit/);
    await expect(page.getByTestId('notice-link-studio_design_revoked')).toHaveCount(0); // sin acceso ya no hay a dónde ir
    await expect(page.getByTestId('notice-link-job_failed_canva')).toHaveText('Abrir Canva');
    await expect(page.getByTestId('notice-link-job_failed_report')).toHaveAttribute('href', /\/reports$/);
    await expect(page.getByTestId('notice-link-job_done_studio_export')).toHaveText('Abrir diseño');
    await expect(page.getByTestId('notice-link-job_failed_summary')).toHaveAttribute('href', /\/media$/);
    await expect(page.getByTestId('notice-link-media_missing')).toHaveText('Ver publicación');

    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Avisos unificados');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-03b · Meta Ads sin permiso usa el mismo aviso de permisos que el inbox, con los pasos y el enlace a Conexiones', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `ads-${stamp()}-${info.project.name}`);
    try {
        const w = watch(page);
        await loginAs(page, user);
        await page.goto(`/ads?brand_id=${brand.id}`);
        await settle(page);
        const notice = page.getByTestId('permission-notice');
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('META_EXTRA_SCOPES');
        await expect(notice).toContainText('ads_read');
        await expect(notice.getByRole('link', { name: 'Ir a Conexiones' })).toHaveAttribute('href', new RegExp(`/brands/${brand.id}/connections`));
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Meta Ads sin permiso');
        expect(w.errors, w.errors.join('\n')).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});
