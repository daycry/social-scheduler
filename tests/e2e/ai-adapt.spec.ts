import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { animationsDone, artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedExtraAccount, settle, stamp, watch, type ComposerBrand } from './helpers';
import { runRoot } from './isolated/runtime';

/**
 * Adaptar el texto a cada red con IA y generar el texto alternativo desde el compositor (T-82, E2E-21). La IA es FALSA dentro del servidor
 * desechable (`tests/e2e/isolated/fakes.php`): con la bandera `studio-ai-on` atiende a Anthropic sin red y el servidor valida de verdad cada
 * versión contra `media_specs`; con `studio-ai-text-off` el asistente no está configurado (la IA real es la prueba manual M-05).
 * Usuarios `e2e-sec-*` y marcas `e2e-composer-*`.
 */
test.describe.configure({ mode: 'serial' });

const flagOn = path.join(runRoot, 'studio-ai-on');
const flagTextOff = path.join(runRoot, 'studio-ai-text-off');

let brand: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `adapt-${stamp()}-${info.project.name}`);
    seedExtraAccount(brand.id, 'bluesky', 'e2e Bluesky');
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    fs.rmSync(flagOn, { force: true });
    fs.rmSync(flagTextOff, { force: true });
    cleanComposerBrands(e2eUser(info.project.name).email);
});

async function axeNow(page: Page, label: string) {
    await settle(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await animationsDone(page);
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(bad, `${label}: ${JSON.stringify(bad.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) })))}`).toEqual([]);
}

const pill = (page: Page, name: RegExp) => page.getByRole('button', { name });
const instagram = /^Instagram: e2e Instagram/;
const bluesky = /^Bluesky: e2e Bluesky/;
const adaptButton = (page: Page) => page.getByRole('button', { name: 'Adaptar a cada red' });
const open = async (page: Page) => {
    await page.goto(`/brands/${brand.id}/posts/create`);
    await settle(page);
};

test('E2E-21 · sin clave el botón está inactivo y explica por qué', { tag: '@responsive' }, async ({ page }) => {
    fs.writeFileSync(flagTextOff, 'asistente de texto apagado');
    const w = watch(page);
    await open(page);
    await pill(page, instagram).click();
    await page.getByLabel('Texto', { exact: true }).fill('Hoy estrenamos pan de masa madre.');

    await expect(adaptButton(page)).toBeDisabled();
    await expect(page.getByTestId('ai-adapt-unconfigured')).toContainText('no configurado');
    await expect(adaptButton(page)).toHaveAccessibleDescription(/no configurado/);
    await page.screenshot({ path: 'test-results/ai-adapt-sin-clave.png' });
    await expectNoOverflow(page);
    await axeNow(page, 'adaptar sin clave');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-21 · con IA, «Adaptar» muestra una versión por red dentro de sus límites y «Aplicar» rellena el texto de cada red', { tag: '@responsive' }, async ({ page }) => {
    fs.writeFileSync(flagOn, 'proveedores de IA falsos');
    const w = watch(page);
    await open(page);
    await pill(page, instagram).click();
    await pill(page, bluesky).click();

    // Sin texto el botón está inactivo y dice qué falta.
    await expect(adaptButton(page)).toBeDisabled();
    await expect(page.getByTestId('ai-adapt')).toContainText('Escribe el texto común');

    await page.getByLabel('Texto', { exact: true }).fill('Hoy estrenamos pan de masa madre en la panadería.');
    await expect(adaptButton(page)).toBeEnabled();
    await page.screenshot({ path: 'test-results/ai-adapt-inicial.png' });
    await adaptButton(page).click();

    // Una versión por red, dentro de su límite, y NADA aplicado todavía.
    const proposal = page.getByTestId('ai-adapt-proposal');
    await expect(proposal).toBeVisible();
    const versions = proposal.getByTestId('ai-adapt-version');
    await expect(versions).toHaveCount(2);
    await expect(versions.nth(0)).toContainText('Adaptado a instagram');
    await expect(versions.nth(1)).toContainText('Adaptado a bluesky');
    await expect(proposal.getByTestId('ai-adapt-rejected')).toHaveCount(0);
    await expect(versions.nth(0).getByTestId('ai-adapt-count')).toHaveText(/^\d+ \/ 2\.200$/);
    await expect(versions.nth(1).getByTestId('ai-adapt-count')).toHaveText(/^\d+ \/ 300$/);
    await expect(page.getByRole('button', { name: 'Editar por red' })).toHaveAttribute('aria-pressed', 'false');
    await expectNoOverflow(page);
    await axeNow(page, 'versiones propuestas');

    // Aplicar: cada red queda con su texto propio (las pestañas se abren solas).
    await proposal.getByTestId('ai-adapt-apply').click();
    await expect(proposal).toHaveCount(0);
    await expect(page.getByTestId('ai-adapt-applied')).toContainText('Aplicado a 2 redes');
    await expect(page.getByRole('button', { name: 'Editar por red' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('tab', { name: /Instagram/ }).click();
    await expect(page.getByLabel('Texto de Instagram · e2e Instagram')).toHaveValue(/^Adaptado a instagram: Hoy estrenamos/);
    await page.getByRole('tab', { name: /Bluesky/ }).click();
    await expect(page.getByLabel('Texto de Bluesky · e2e Bluesky')).toHaveValue(/^Adaptado a bluesky: Hoy estrenamos/);
    await expect(page.getByTestId('network-counters').getByTestId('counter-chars').filter({ hasText: 'supera el máximo' })).toHaveCount(0);
    await page.screenshot({ path: 'test-results/ai-adapt-aplicado.png' });
    await expectNoOverflow(page);
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-21 · una versión que no cabe se rechaza con su motivo y descartar no cambia nada', { tag: '@responsive' }, async ({ page }) => {
    fs.writeFileSync(flagOn, 'proveedores de IA falsos');
    await open(page);
    await pill(page, instagram).click();
    await pill(page, bluesky).click();
    await page.getByLabel('Texto', { exact: true }).fill('Promoción e2e-excede de verano.');
    await adaptButton(page).click();

    const proposal = page.getByTestId('ai-adapt-proposal');
    await expect(proposal.getByTestId('ai-adapt-version').nth(0)).toContainText('Adaptado a instagram');
    await expect(proposal.getByTestId('ai-adapt-rejected')).toHaveCount(1);
    await expect(proposal.getByTestId('ai-adapt-rejected')).toContainText('301 caracteres');
    await expectNoOverflow(page);

    await proposal.getByTestId('ai-adapt-discard').click();
    await expect(proposal).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Editar por red' })).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByLabel('Texto', { exact: true })).toHaveValue('Promoción e2e-excede de verano.');
});

/** Post con una imagen común de la marca (ya vinculada a sus recursos, como lo hace la subida desde el compositor) y destino Bluesky. */
function seedPostWithImage(brandId: number): { post: number; media: number; bsky: number } {
    const out = artisan(
        String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brandId}); $u = App\Models\User::findOrFail($b->user_id); ` +
            String.raw`$a = App\Models\MediaAsset::factory()->for($u)->create(['original_name' => 'alt.jpg', 'mime' => 'image/jpeg', 'path' => 'media/e2e/alt-' . uniqid() . '.jpg', 'width' => 400, 'height' => 400]); ` +
            String.raw`$im = imagecreatetruecolor(400, 400); imagefill($im, 0, 0, imagecolorallocate($im, 240, 220, 40)); ob_start(); imagejpeg($im); $bytes = ob_get_clean(); ` +
            String.raw`Illuminate\Support\Facades\Storage::disk($a->disk)->put($a->path, $bytes); $a->forceFill(['size' => strlen($bytes)])->save(); ` +
            String.raw`app(App\Services\Studio\StudioAssets::class)->attach($u, $b, $a->id); ` +
            String.raw`$bs = App\Models\SocialAccount::withoutUserScope()->where('brand_id', $b->id)->where('network', 'bluesky')->firstOrFail(); ` +
            String.raw`$p = app(App\Services\PostService::class)->create($u, $b, ['text' => 'Alt text E2E', 'schedule' => false, 'media' => [$a->id], 'targets' => [['account_id' => $bs->id, 'content_type' => 'bluesky_post']]]); ` +
            String.raw`echo 'SEED:' . json_encode(['post' => $p->id, 'media' => $a->id, 'bsky' => $bs->id]);`,
    );
    return JSON.parse(out.match(/SEED:(\{.*\})/)![1]);
}

test('E2E-21 · el alt text generado se puede editar antes de guardar', { tag: '@responsive' }, async ({ page }) => {
    fs.writeFileSync(flagOn, 'proveedores de IA falsos');
    const w = watch(page);
    const s = seedPostWithImage(brand.id);
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);

    await page.getByRole('button', { name: 'Editar por red' }).click();
    await page.getByTestId(`tab-${s.bsky}`).click();
    await page.getByTestId('target-media-customize').click();

    const alt = page.getByTestId(`target-media-alt-${s.media}`);
    await expect(alt).toHaveValue('');
    await page.getByTestId(`target-media-alt-generate-${s.media}`).click();
    await expect(alt).toHaveValue('Un círculo amarillo sobre un fondo violeta liso');
    await expect(page.getByTestId('target-media-alt-unconfigured')).toHaveCount(0);

    // El campo sigue siendo editable y lo que se guarda es lo editado.
    await alt.fill('Un círculo amarillo, editado a mano');
    await expect(alt).toHaveValue('Un círculo amarillo, editado a mano');

    // Un alt ya escrito no se sobrescribe sin confirmar: cancelar lo conserva.
    await page.getByTestId(`target-media-alt-generate-${s.media}`).click();
    const dialog = page.getByRole('alertdialog', { name: 'Reemplazar texto alternativo', exact: true });
    await expect(dialog).toContainText('¿Reemplazarlo por el generado?');
    await dialog.getByRole('button', { name: 'Cancelar' }).click();
    await expect(alt).toHaveValue('Un círculo amarillo, editado a mano');
    await page.screenshot({ path: 'test-results/ai-adapt-alt.png' });
    await expectNoOverflow(page);
    await axeNow(page, 'alt text generado');
    expect(w.errors, w.errors.join('\n')).toEqual([]);
});

test('E2E-21 · sin clave «Generar» alt text queda inactivo y explica por qué', { tag: '@responsive' }, async ({ page }) => {
    fs.writeFileSync(flagTextOff, 'asistente de texto apagado');
    const s = seedPostWithImage(brand.id);
    await page.goto(`/posts/${s.post}/edit`);
    await settle(page);

    await page.getByRole('button', { name: 'Editar por red' }).click();
    await page.getByTestId(`tab-${s.bsky}`).click();
    await page.getByTestId('target-media-customize').click();

    await expect(page.getByTestId(`target-media-alt-generate-${s.media}`)).toBeDisabled();
    await expect(page.getByTestId('target-media-alt-unconfigured')).toContainText('no configurado');
    await expect(page.getByTestId(`target-media-alt-${s.media}`)).toBeEditable();
    await expectNoOverflow(page);
});
