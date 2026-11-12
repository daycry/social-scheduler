import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp } from './helpers';

test('Presets: global and network panels keep values and reveal collapsed validation fields', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `presets-${stamp()}-${info.project.name}`);
    try {
        const draft = artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$m = App\Models\MediaAsset::factory()->video(duration:30)->for($u)->create(); $targets = App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->get()->map(fn($a)=>['account_id'=>$a->id,'content_type'=>$a->network === App\Connectors\Network::Instagram ? 'instagram_reel' : 'facebook_reel'])->all(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u,$b,['text'=>'Una nueva historia para compartir.','schedule'=>false,'media'=>[$m->id],'targets'=>$targets]); echo 'POST:'.$p->id;`);
        await loginAs(page, user);
        await page.goto(`/posts/${draft.match(/POST:(\d+)/)![1]}/edit`);
        await settle(page);
        const global = page.getByRole('region', { name: 'Ajustes globales', exact: true });
        await expect(global).toBeVisible();
        await page.getByLabel('Notas internas', { exact: true }).fill('Estas notas se conservan al plegar.');
        const globalSummary = global.locator('summary');
        await globalSummary.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByLabel('Notas internas', { exact: true })).toBeHidden();
        await page.keyboard.press('Space');
        await expect(page.getByLabel('Notas internas', { exact: true })).toHaveValue('Estas notas se conservan al plegar.');
        const instagram = page.getByRole('region', { name: 'Opciones de Instagram (e2e Instagram)', exact: true });
        const summary = instagram.locator('summary');
        await expect(summary).toContainText('Ajustes de Instagram');
        await expect(page.getByRole('region', { name: 'Opciones de Facebook (e2e Facebook)', exact: true }).locator('summary')).toContainText('Ajustes de Facebook');
        await instagram.getByRole('button', { name: 'Añadir colaborador', exact: true }).click();
        await instagram.getByLabel('Usuario de Instagram', { exact: true }).fill('@Artist.One');
        await instagram.getByRole('button', { name: 'Añadir usuario', exact: true }).click();
        await expect(instagram.getByRole('button', { name: 'Quitar colaborador artist.one' })).toBeVisible();
        await instagram.getByRole('switch', { name: 'Contenido generado con IA', exact: true }).check();
        await instagram.getByLabel('Compartir también en el feed').uncheck();
        const comment = instagram.getByLabel('Primer comentario (opcional)', { exact: true });
        await comment.fill('Comentario conservado.');
        await summary.click();
        await expect(comment).toBeHidden();
        await summary.click();
        await expect(comment).toHaveValue('Comentario conservado.');
        await expect(instagram.getByLabel('Compartir también en el feed')).not.toBeChecked();
        await expect(instagram.getByRole('button', { name: 'Quitar colaborador artist.one' })).toBeVisible();
        await expect(instagram.getByRole('switch', { name: 'Contenido generado con IA', exact: true })).toBeChecked();
        const facebookTitle = page.getByLabel('Título de Facebook (opcional)', { exact: true });
        await facebookTitle.fill('Título propio del Reel');
        const saved = page.waitForResponse(response => response.request().method() === 'PUT' && /\/posts\/\d+$/.test(new URL(response.url()).pathname));
        await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
        await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
        await saved;
        await settle(page);
        await page.reload();
        await expect(instagram.getByRole('button', { name: 'Quitar colaborador artist.one' })).toBeVisible();
        await expect(instagram.getByRole('switch', { name: 'Contenido generado con IA', exact: true })).toBeChecked();
        await expect(facebookTitle).toHaveValue('Título propio del Reel');
        await expect(comment).toHaveValue('Comentario conservado.');
        await expect(page.getByLabel('Notas internas', { exact: true })).toHaveValue('Estas notas se conservan al plegar.');
        await expect(instagram.getByLabel('Compartir también en el feed')).not.toBeChecked();
        await page.mouse.move(0, 0);
        await expectNoOverflow(page);
        await page.getByRole('button', { name: /Fecha y hora \(/ }).click();
        const calendar = page.getByRole('dialog', { name: 'Elegir fecha y hora' });
        const done = calendar.getByRole('button', { name: 'Listo', exact: true });
        await expect(done).toBeVisible();
        await done.scrollIntoViewIfNeeded();
        const receivesClicks = await done.evaluate(element => {
            const rect = element.getBoundingClientRect();
            const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            return element === hit || element.contains(hit);
        });
        expect(receivesClicks).toBe(true);
        await done.click();
        await page.getByTestId('composer-presets').screenshot({ path: info.outputPath('composer-presets.png'), style: 'header { visibility: hidden !important; }' });
        const a11y = await new AxeBuilder({ page }).include('[data-testid="composer-presets"]').analyze();
        expect(a11y.violations.filter(v => ['critical', 'serious'].includes(v.impact ?? ''))).toEqual([]);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('Presets: validation opens a collapsed global panel and focuses its date field', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `presets-error-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/brands/${brand.id}/posts/create?selector=nativo`);
        await settle(page);
        const global = page.getByRole('region', { name: 'Ajustes globales', exact: true });
        const date = page.locator('#scheduled_at');
        await date.fill('2000-01-01T10:00');
        await global.locator('summary').scrollIntoViewIfNeeded();
        await expectNoOverflow(page);
        await global.locator('summary').click();
        await expect(date).toBeHidden();
        await page.getByRole('button', { name: /Ir al control con el error: La fecha de publicacion debe ser futura/ }).click();
        await expect(date).toBeVisible();
        await expect(date).toBeFocused();
        await expectNoOverflow(page);
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('Presets: errors panel can collapse and retains correction links', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `presets-errors-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/brands/${brand.id}/posts/create?selector=nativo`);
        await settle(page);
        await page.locator('#scheduled_at').fill('2000-01-01T10:00');
        const errors = page.getByTestId('errors-panel');
        const summary = errors.locator('summary');
        await expect(summary).toContainText('Hay 1 error que corregir');
        await summary.click();
        await expect(errors.getByRole('button', { name: /Ir al control/ })).toBeHidden();
        await summary.focus();
        await page.keyboard.press('Enter');
        await expect(errors.getByRole('button', { name: /Ir al control/ })).toBeVisible();
        await expectNoOverflow(page);
        await page.screenshot({ path: info.outputPath('composer-errors.png') });
    } finally {
        cleanComposerBrands(user.email);
    }
});
