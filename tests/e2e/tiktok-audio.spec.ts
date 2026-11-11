import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { runRoot } from './isolated/runtime';
import { artisan, chooseContentType, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp } from './helpers';

async function openPhoto(page: Page, project: string) {
    const user = e2eUser(project);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `tt-audio-${stamp()}-${project}`);
    seedExtraAccount(brand.id, 'tiktok', 'e2e TikTok', true);
    const out = artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u=App\Models\User::findOrFail($b->user_id); ` +
        String.raw`$a=App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','tiktok')->firstOrFail(); ` +
        String.raw`$m=App\Models\MediaAsset::factory()->for($u)->create(); ` +
        String.raw`$p=app(App\Services\PostService::class)->create($u,$b,['text'=>'Foto con música','schedule'=>false,'media'=>[$m->id],'targets'=>[['account_id'=>$a->id,'content_type'=>'tiktok_photo']]]); echo 'POST:'.$p->id;`);
    await loginAs(page, user);
    await page.goto(`/posts/${out.match(/POST:(\d+)/)![1]}/edit`);
}

// Photos/carousels exist only with a verified TikTok domain. The disposable server reads this flag file on every request
// (tests/e2e/isolated/bootstrap.php); it is removed afterwards so the other specs keep seeing "Video only".
const domainFlag = path.join(runRoot, 'tiktok-domain-on');
test.beforeEach(() => fs.writeFileSync(domainFlag, 'fictitious verified domain'));
test.afterEach(async ({}, info) => { fs.rmSync(domainFlag, { force: true }); cleanComposerBrands(e2eUser(info.project.name).email); });

test('TikTok photo music: explicit choice persists and switching to video removes it', async ({ page }, info) => {
        await page.route('**/tiktok-creator', route => route.fulfill({ json: { nickname: 'Fixture TikTok', privacy_level_options: ['SELF_ONLY'], comment_disabled: false, duet_disabled: false, stitch_disabled: false } }));
        await openPhoto(page, info.project.name);
        await settle(page);
        const music = page.getByRole('switch', { name: 'Añadir música recomendada (TikTok elige la canción)', exact: true });
        await expect(music).not.toBeChecked();
        await music.check();
        await expect(page.getByText('Solo para fotos y carruseles con publicación directa.', { exact: false })).toBeVisible();
        await expectNoOverflow(page);
        await page.screenshot({ path: info.outputPath('tiktok-audio.png'), fullPage: true });
        const saved = page.waitForResponse(r => r.request().method() === 'PUT' && /\/posts\/\d+$/.test(new URL(r.url()).pathname));
        await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
        await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
        await saved;
        await settle(page);
        await page.reload();
        await expect(music).toBeChecked();
        await chooseContentType(page, /^Tipo de contenido en TikTok/, 'Video');
        await expect(music).toHaveCount(0);
        await expect(page.getByTestId('network-audio-note')).toContainText('El vídeo conserva su sonido');
});

test('TikTok creator failure stops automatic retries and the retry button can recover', async ({ page }, info) => {
    let calls = 0;
    let success = false;
    await page.route('**/tiktok-creator', route => {
        calls++;
        return route.fulfill(success
            ? { json: { nickname: 'Cuenta recuperada', privacy_level_options: ['SELF_ONLY'] } }
            : { status: 503, json: { message: 'TikTok no disponible ahora.' } });
    });
    await openPhoto(page, info.project.name);
    await expect(page.getByRole('alert').filter({ hasText: 'TikTok no disponible ahora.' })).toBeVisible();
    // Waiting for idle is meaningful here: the previous bug never reached idle because it kept retrying.
    await settle(page);
    expect(calls).toBe(1);
    success = true;
    await page.getByRole('button', { name: 'Reintentar consulta de TikTok', exact: true }).click();
    await expect(page.getByTestId('tiktok-creator')).toContainText('Cuenta recuperada');
    expect(calls).toBe(2);
});
