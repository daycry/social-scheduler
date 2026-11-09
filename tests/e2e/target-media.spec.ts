import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { artisan, axeSeriousCritical, chooseContentType, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch } from './helpers';
import { runRoot } from './isolated/runtime';

/**
 * T-17 · media por destino. Un post con media común (A, B) y una red (Bluesky) con la suya (C, A) y alt propio: guardar, reabrir, ver la
 * previsualización de cada red y publicar contra la red simulada, que registra ids, orden, alt y huella de los bytes de lo que recibe.
 * Datos `e2e-composer-*` del usuario E2E; nada llega a una red real.
 */
test.describe.configure({ mode: 'serial' });

type Seed = { post: number; ig: number; bsky: number; igTarget: number; bskyTarget: number; assets: Record<'A' | 'B' | 'C', { id: number; sha1: string }> };

const ALT_C = 'Un gato naranja en una ventana';
const ALT_A = 'Logo de la marca sobre fondo azul';

function seed(email: string, brandId: number): Seed {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$mk = function ($n, $r, $g, $bl) use ($u) { ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => $n . '.jpg', 'mime' => 'image/jpeg', 'path' => 'media/e2e/' . $n . '-' . uniqid() . '.jpg', 'width' => 400, 'height' => 400]); ` +
            String.raw`$im = imagecreatetruecolor(400, 400); imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $bl)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); return ['id' => $a->id, 'sha1' => sha1($bytes)]; }; ` +
            String.raw`$assets = ['A' => $mk('A', 200, 40, 40), 'B' => $mk('B', 40, 160, 60), 'C' => $mk('C', 40, 60, 200)]; ` +
            String.raw`$ig = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'instagram')->firstOrFail(); ` +
            String.raw`$bs = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'bluesky')->firstOrFail(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'Media por destino E2E', 'schedule' => false, 'media' => [$assets['A']['id'], $assets['B']['id']], 'targets' => [` +
            String.raw`['account_id' => $ig->id, 'content_type' => 'instagram_carousel'], ['account_id' => $bs->id, 'content_type' => 'bluesky_post']]]); ` +
            String.raw`echo 'SEED:' . json_encode(['post' => $p->id, 'ig' => $ig->id, 'bsky' => $bs->id, 'igTarget' => $p->targets->firstWhere('social_account_id', $ig->id)->id, 'bskyTarget' => $p->targets->firstWhere('social_account_id', $bs->id)->id, 'assets' => $assets]);`,
    );
    return JSON.parse(out.match(/SEED:(\{.*\})/)![1]);
}

const menuToggle = (page: Page) => page.getByRole('button', { name: 'Más acciones de publicación' });
async function menuAction(page: Page, name: string) {
    await menuToggle(page).click();
    await page.getByRole('menuitem', { name, exact: true }).click();
}

const panel = (page: Page) => page.getByTestId('target-media-panel');
const items = (page: Page) => panel(page).getByTestId('target-media-item');
const ids = async (locator: ReturnType<Page['getByTestId']>) => (await locator.evaluateAll((els) => els.map((e) => Number(e.getAttribute('data-media-id')))));

async function openBlueskyTab(page: Page, s: Seed) {
    const by = page.getByRole('button', { name: 'Editar por red' });
    if ((await by.getAttribute('aria-pressed')) !== 'true') await by.click();
    await page.getByTestId(`tab-${s.bsky}`).click();
    await expect(panel(page)).toBeVisible();
}

let brand: { id: number; name: string };
let s: Seed;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `tmedia-${stamp()}-${info.project.name}`);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
    s = seed(user.email, brand.id);
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

test('T-17 · media común más una red con media y alt propios: guardar, reabrir, previsualizar por red y publicar en simulado', async ({ page }) => {
    const w = watch(page);
    const { A, B, C } = s.assets;
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);

    // 1. Por defecto la red hereda la media común y el panel lo dice.
    await openBlueskyTab(page, s);
    await expect(page.getByTestId('target-media-inherited')).toBeVisible();
    await expect(page.getByTestId('preview-media-item')).toHaveCount(2);
    expect(await ids(page.getByTestId('preview-media-item'))).toEqual([A.id, B.id]);

    // 2. Personalizar: se parte de la común, se añade C, se sube al primer puesto y se quita B.
    await page.getByTestId('target-media-customize').click();
    await expect(page.getByTestId('target-media-own')).toBeVisible();
    expect(await ids(items(page))).toEqual([A.id, B.id]);
    await page.getByTestId('target-media-add').selectOption({ label: 'C.jpg' });
    await page.getByRole('button', { name: 'Subir el archivo 3 de Bluesky · e2e Bluesky' }).click();
    await page.getByRole('button', { name: 'Subir el archivo 2 de Bluesky · e2e Bluesky' }).click();
    await page.getByRole('button', { name: 'Quitar el archivo 3 de Bluesky · e2e Bluesky' }).click();
    expect(await ids(items(page))).toEqual([C.id, A.id]);

    await page.getByTestId(`target-media-alt-${C.id}`).fill(ALT_C);
    await page.getByTestId(`target-media-alt-${A.id}`).fill(ALT_A);

    // 3. La previsualización de cada red muestra SU media efectiva.
    expect(await ids(page.getByTestId('preview-media-item'))).toEqual([C.id, A.id]);
    await expect(page.getByTestId('preview-media-alt')).toHaveText([ALT_C, ALT_A]);
    await page.getByTestId(`tab-${s.ig}`).click();
    await expect(page.getByTestId('target-media-inherited')).toBeVisible();
    expect(await ids(page.getByTestId('preview-media-item'))).toEqual([A.id, B.id]);
    await expect(page.getByTestId('preview-media-alt')).toHaveCount(0);
    await page.getByTestId(`tab-${s.bsky}`).click();
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'compositor con media propia por red');

    // 4. Guardar y reabrir: la selección, el orden y los alt se conservan.
    const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && new URL(r.url()).pathname === `/posts/${s.post}`);
    await menuAction(page, 'Guardar borrador');
    await saved;
    await settle(page);
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);
    await openBlueskyTab(page, s);
    await expect(page.getByTestId('target-media-own')).toBeVisible();
    expect(await ids(items(page))).toEqual([C.id, A.id]);
    await expect(page.getByTestId(`target-media-alt-${C.id}`)).toHaveValue(ALT_C);
    await expect(page.getByTestId(`target-media-alt-${A.id}`)).toHaveValue(ALT_A);
    await page.getByTestId(`tab-${s.ig}`).click();
    await expect(page.getByTestId('target-media-inherited')).toBeVisible();

    // 5. Volver a la común descarta la propia (y se puede deshacer sin guardar).
    await page.getByTestId(`tab-${s.bsky}`).click();
    await page.getByTestId('target-media-reset').click();
    await expect(page.getByTestId('target-media-inherited')).toBeVisible();
    expect(await ids(page.getByTestId('preview-media-item'))).toEqual([A.id, B.id]);
    await page.reload();
    await settle(page);
    await openBlueskyTab(page, s);
    expect(await ids(items(page))).toEqual([C.id, A.id]);

    // 6. Publicar ahora: la red simulada recibe exactamente lo del destino.
    await menuAction(page, 'Publicar ahora');
    await page.waitForURL(/\/calendar/);
    artisan("Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'publish','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>100,'--sleep'=>0]);");
    const status = artisan(String.raw`echo 'ST:' . App\Models\PostTarget::withoutUserScope()->where('post_id', ${s.post})->get()->map(fn ($t) => $t->status->value . '/' . $t->error_message)->implode(',');`);
    expect(status, status).toMatch(/^ST:publicada\/,publicada\/$/m);

    const records = JSON.parse(fs.readFileSync(path.join(runRoot, 'externals.json'), 'utf8')).records;
    expect(records[String(s.bskyTarget)].media).toEqual([
        { id: C.id, alt: ALT_C, cover_id: null, sha1: C.sha1 },
        { id: A.id, alt: ALT_A, cover_id: null, sha1: A.sha1 },
    ]);
    expect(records[String(s.igTarget)].media).toEqual([
        { id: A.id, alt: null, cover_id: null, sha1: A.sha1 },
        { id: B.id, alt: null, cover_id: null, sha1: B.sha1 },
    ]);
    expect(w.errors.filter((e) => !/422/.test(e)), w.errors.join('\n')).toEqual([]);
});

test('T-17 · el error de la media propia nombra la red y bloquea Programar', async ({ page }) => {
    const { A, B } = s.assets;
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);
    // Instagram carrusel con una sola imagen propia: necesita al menos 2.
    const by = page.getByRole('button', { name: 'Editar por red' });
    await by.click();
    await page.getByTestId(`tab-${s.ig}`).click();
    await page.getByTestId('target-media-customize').click();
    await page.getByRole('button', { name: 'Quitar el archivo 2 de Instagram · e2e Instagram' }).click();
    await expect(page.getByTestId('errors-panel')).toContainText('Media propia de e2e Instagram (Instagram)', { timeout: 10_000 });
    await expect(page.getByRole('button', { name: 'Programar' })).toBeDisabled();
    expect(await ids(items(page))).toEqual([A.id]);
    expect(B.id).not.toBe(A.id);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'compositor con error de media propia');
});

test('T-17 · al cambiar el tipo se limpian el alt y la portada que el nuevo tipo no admite y no se envian', async ({ page }) => {
    const { A } = s.assets;
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);
    await page.getByRole('button', { name: 'Editar por red' }).click();
    await page.getByTestId(`tab-${s.ig}`).click();
    await page.getByTestId('target-media-customize').click();
    await page.getByTestId(`target-media-alt-${A.id}`).fill('Alt que dejara de admitirse');

    const validated = page.waitForRequest((r) => r.url().endsWith('/posts/validate') && r.postDataJSON().targets.some((t: any) => t.content_type === 'instagram_story'));
    await chooseContentType(page, s.ig, 'Historia');
    const sent = (await validated).postDataJSON().targets.find((t: any) => t.content_type === 'instagram_story');
    expect(sent.media.map((m: any) => m.alt ?? '')).toEqual(['', '']);
    expect(sent.media.every((m: any) => !m.cover_id)).toBe(true);
    await expect(page.getByTestId(`target-media-alt-${A.id}`)).toHaveCount(0);
    await expect(page.getByTestId('errors-panel')).not.toContainText('texto alternativo');
});
