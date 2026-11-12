import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { artisan, chooseContentType, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, settle, stamp } from './helpers';

test.describe.configure({ mode: 'serial' });

async function openReel(page: Page, project: string) {
    const user = e2eUser(project);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `audio-${stamp()}-${project}`);
    const out = artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); ` +
        String.raw`$u = App\Models\User::findOrFail($b->user_id); $a = App\Models\SocialAccount::withoutUserScope()->where('brand_id',$b->id)->where('network','instagram')->firstOrFail(); ` +
        String.raw`$m = App\Models\MediaAsset::factory()->video(duration:30)->for($u)->create(); ` +
        String.raw`$p = app(App\Services\PostService::class)->create($u,$b,['text'=>'Reel con música','schedule'=>false,'media'=>[$m->id],'targets'=>[['account_id'=>$a->id,'content_type'=>'instagram_reel']]]); echo 'POST:'.$p->id;`);
    await loginAs(page, user);
    await page.goto(`/posts/${out.match(/POST:(\d+)/)![1]}/edit`);
    await settle(page);
}

test.afterEach(async ({}, info) => cleanComposerBrands(e2eUser(info.project.name).email));

async function saveDraft(page: Page) {
    const saved = page.waitForResponse(r => r.request().method() === 'PUT' && /\/posts\/\d+$/.test(new URL(r.url()).pathname));
    await page.getByRole('button', { name: 'Más acciones de publicación' }).click();
    await page.getByRole('menuitem', { name: 'Guardar borrador', exact: true }).click();
    await saved;
    await settle(page);
}

test('Audio Reel: search select volumes save reopen and remove', { tag: '@responsive' }, async ({ page }, info) => {
    const queries: string[] = [];
    const track = { audio_id: '587784541076604', title: 'Canción de prueba', artist: 'Artista de prueba', audio_type: 'music', duration_ms: 30000, preview_url: null, artwork_url: null, platform_url: null };
    await page.route(/\/instagram-audio(?:\/[0-9]+)?(?:\?.*)?$/, async route => {
        const url = new URL(route.request().url());
        queries.push(url.searchParams.get('q') ?? '');
        await route.fulfill({ json: /\/\d+$/.test(url.pathname) ? { track } : { results: [track] } });
    });
    await openReel(page, info.project.name);
    await page.getByRole('button', { name: 'Añadir audio', exact: true }).click();
    await expect(page.getByLabel('Buscar canción o audio')).toBeFocused();
    await page.getByLabel('Buscar canción o audio').fill('Canción');
    await page.getByRole('button', { name: 'Buscar audio', exact: true }).click();
    await expect.poll(() => queries).toContain('Canción');
    await page.getByRole('button', { name: 'Elegir Canción de prueba', exact: true }).click();
    await expect(page.getByTestId('instagram-audio-picker')).toBeHidden();
    await expect(page.getByTestId('selected-instagram-audio')).toContainText('Canción de prueba');
    await page.getByLabel('Volumen de la canción').fill('80');
    await page.getByLabel('Volumen del vídeo original').fill('25');
    await expectNoOverflow(page);
    await page.mouse.move(0, 0);
    await page.screenshot({ path: info.outputPath('instagram-audio.png'), fullPage: true });
    const accessibility = await new AxeBuilder({ page }).include('[data-testid="instagram-audio-field"]').analyze();
    expect(accessibility.violations.filter(v => ['critical', 'serious'].includes(v.impact ?? ''))).toEqual([]);
    await saveDraft(page);
    await page.reload();
    await expect(page.getByTestId('selected-instagram-audio')).toContainText('Canción de prueba');
    await expect(page.getByLabel('Volumen de la canción')).toHaveValue('80');
    await expect(page.getByLabel('Volumen del vídeo original')).toHaveValue('25');
    await page.getByRole('button', { name: 'Quitar audio', exact: true }).click();
    await saveDraft(page);
    await page.reload();
    await expect(page.getByRole('button', { name: 'Añadir audio', exact: true })).toBeVisible();
});

test('Audio Reel: empty errors and closing preserve the existing choice', async ({ page }, info) => {
    let mode = 'error';
    await page.route(/\/instagram-audio(?:\/[0-9]+)?(?:\?.*)?$/, route => route.fulfill(mode === 'error'
        ? { status: 422, json: { message: 'Meta no está disponible ahora.' } }
        : { json: { results: [] } }));
    await openReel(page, info.project.name);
    const add = page.getByRole('button', { name: 'Añadir audio', exact: true });
    await add.click();
    await expect(page.getByTestId('instagram-audio-picker').getByRole('alert')).toHaveText('Meta no está disponible ahora.');
    mode = 'empty';
    await page.getByRole('button', { name: 'Buscar audio', exact: true }).click();
    await expect(page.getByText('No hay audios disponibles para esta búsqueda.')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('instagram-audio-picker')).toBeHidden();
    await expect(add).toBeFocused();
});

test('Audio Reel: typing searches and loading more preserves results with retry and filter reset', { tag: '@responsive' }, async ({ page }, info) => {
    const queries: { q: string; type: string; cursor: string | null }[] = [];
    const track = (id: string, title: string) => ({ audio_id: id, title, artist: 'Fixture', preview_url: null });
    let failNextPage = true;
    await page.route(/\/instagram-audio(?:\?.*)?$/, async route => {
        const params = new URL(route.request().url()).searchParams;
        const request = { q: params.get('q') ?? '', type: params.get('type') ?? '', cursor: params.get('cursor') };
        queries.push(request);
        if (request.cursor && failNextPage) {
            failNextPage = false;
            await route.fulfill({ status: 422, json: { message: 'Fallo temporal de la siguiente página.' } });
        } else if (request.cursor) {
            await route.fulfill({ json: { results: [track('11', 'Primera pista'), track('22', 'Segunda página')], next_cursor: null } });
        } else if (request.q === 'Artista') {
            await route.fulfill({ json: { results: [track('11', 'Primera pista')], next_cursor: 'opaque-page-2' } });
        } else {
            await route.fulfill({ json: { results: [track('33', 'Tendencia inicial')], next_cursor: null } });
        }
    });
    await openReel(page, info.project.name);
    await page.getByRole('button', { name: 'Añadir audio', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Elegir Tendencia inicial', exact: true })).toBeVisible();
    const input = page.getByLabel('Buscar canción o audio');
    await input.fill('Artista');
    // Search must work without requiring Enter or clicking the search button.
    await expect(page.getByRole('button', { name: 'Elegir Primera pista', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Elegir Tendencia inicial', exact: true })).toHaveCount(0);
    const more = page.getByRole('button', { name: 'Cargar más audios', exact: true });
    await more.click();
    await expect(page.getByTestId('instagram-audio-picker').getByRole('alert')).toContainText('Fallo temporal de la siguiente página.');
    await expect(page.getByRole('button', { name: 'Elegir Primera pista', exact: true })).toBeVisible();
    await more.click();
    await expect(page.getByRole('button', { name: 'Elegir Segunda página', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Elegir Primera pista', exact: true })).toHaveCount(1);
    await expect(more).toHaveCount(0);
    await expectNoOverflow(page);
    await page.screenshot({ path: info.outputPath('audio-search-pagination.png'), fullPage: true });
    expect(queries.filter(query => query.cursor).map(query => query.cursor)).toEqual(['opaque-page-2', 'opaque-page-2']);
    await page.getByRole('combobox', { name: /Tipo de audio/ }).selectOption('music');
    await expect.poll(() => queries.at(-1)).toEqual({ q: 'Artista', type: 'music', cursor: null });
    await expect(page.getByRole('button', { name: 'Elegir Segunda página', exact: true })).toHaveCount(0);
    await input.fill('');
    await expect(page.getByRole('button', { name: 'Elegir Tendencia inicial', exact: true })).toBeVisible();
    expect(queries.at(-1)).toEqual({ q: '', type: 'music', cursor: null });
});

test('Audio Reel: changing a query invalidates slow responses and closing cancels pending typing', async ({ page }, info) => {
    const queries: string[] = [];
    let releaseOld = () => {};
    const oldResponse = new Promise<void>(resolve => { releaseOld = resolve; });
    await page.route(/\/instagram-audio(?:\?.*)?$/, async route => {
        const query = new URL(route.request().url()).searchParams.get('q') ?? '';
        queries.push(query);
        if (query === 'Anterior') await oldResponse;
        await route.fulfill({ json: { results: [{ audio_id: query === 'Anterior' ? '11' : '22', title: query || 'Tendencia', artist: 'Fixture' }], next_cursor: null } }).catch(() => {});
    });
    await openReel(page, info.project.name);
    const add = page.getByRole('button', { name: 'Añadir audio', exact: true });
    await add.click();
    await expect(page.getByRole('button', { name: 'Elegir Tendencia', exact: true })).toBeVisible();
    const input = page.getByLabel('Buscar canción o audio');
    await input.fill('Anterior');
    await expect.poll(() => queries).toContain('Anterior');
    await input.fill('Actual');
    await expect(page.getByRole('button', { name: 'Elegir Actual', exact: true })).toBeVisible();
    releaseOld();
    await page.waitForTimeout(450);
    await expect(page.getByRole('button', { name: 'Elegir Anterior', exact: true })).toHaveCount(0);
    await input.fill('Cancelada');
    await page.keyboard.press('Escape');
    await expect(add).toBeFocused();
    await page.waitForTimeout(450);
    expect(queries).not.toContain('Cancelada');
});

test('Audio Reel: preview never autoplays defaults are visible and format change clears the track', async ({ page }, info) => {
    // Synthetic tone, not a downloaded song. All preview traffic remains intercepted by this test.
    const wav = Buffer.alloc(44 + 16000 * 2);
    wav.write('RIFF'); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
    wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
    wav.writeUInt32LE(8000, 24); wav.writeUInt32LE(16000, 28); wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
    wav.write('data', 36); wav.writeUInt32LE(wav.length - 44, 40);
    for (let i = 0; i < 16000; i++) wav.writeInt16LE(Math.round(4000 * Math.sin(2 * Math.PI * 440 * i / 8000)), 44 + i * 2);
    await page.route('https://audio.fbcdn.net/fixture.wav', route => route.fulfill({ contentType: 'audio/wav', body: wav }));
    await page.route(/\/instagram-audio(?:\/[0-9]+)?(?:\?.*)?$/, route => route.fulfill({ json: {
        track: { audio_id: '11', title: 'Tono de prueba', artist: 'Fixture', preview_url: 'https://audio.fbcdn.net/fixture.wav' },
    } }));
    await openReel(page, info.project.name);
    const postId = Number(page.url().match(/\/posts\/(\d+)/)![1]);
    artisan(String.raw`$t = App\Models\PostTarget::withoutUserScope()->where('post_id',${postId})->firstOrFail(); $t->forceFill(['options'=>['instagram_audio'=>['audio_id'=>'11']]])->save();`);
    await page.reload();
    await expect(page.getByLabel('Volumen de la canción')).toHaveValue('100');
    await expect(page.getByLabel('Volumen del vídeo original')).toHaveValue('100');
    const audio = page.getByLabel('Preescucha de la pista seleccionada');
    await expect(audio).toBeVisible();
    expect(await audio.evaluate(el => (el as HTMLAudioElement).paused)).toBe(true);
    await audio.evaluate(el => (el as HTMLAudioElement).play());
    await expect.poll(() => audio.evaluate(el => (el as HTMLAudioElement).currentTime)).toBeGreaterThan(0);
    await audio.evaluate(el => (el as HTMLAudioElement).pause());
    await chooseContentType(page, /^Tipo de contenido en Instagram/, 'Historia');
    await expect(page.getByTestId('instagram-audio-field')).toHaveCount(0);
    await chooseContentType(page, /^Tipo de contenido en Instagram/, 'Reel');
    await expect(page.getByRole('button', { name: 'Añadir audio', exact: true })).toBeVisible();
    await expect(page.getByTestId('selected-instagram-audio')).toHaveCount(0);
});
