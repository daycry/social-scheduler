import { expect, test } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

test.describe('Marca de agua y exportación en lote', { tag: '@responsive' }, () => {
    // E2E-30 (T-91): marca de agua del kit de marca y exportación en lote, un archivo por formato en la biblioteca.
    test('Studio watermark and batch export: configure the brand mark, export three formats and find one watermarked file per format', async ({
        page,
    }, info) => {
        test.setTimeout(180_000);
        const user = e2eUser(info.project.name),
            errors: string[] = [];
        cleanComposerBrands(user.email);
        const brand = seedComposerBrand(user.email, `studio-watermark-${stamp()}-${info.project.name}`);
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(message.text());
        });
        try {
            // Un logotipo rojo de 20x20 ya registrado en la biblioteca de la marca.
            artisan(String.raw`$b = App\Models\Brand::withoutUserScope()->findOrFail(${brand.id}); $u = App\Models\User::findOrFail($b->user_id);
                $image = imagecreatetruecolor(20, 20); imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0)); ob_start(); imagepng($image); $png = ob_get_clean();
                $path = 'media/'.$u->id.'/watermark-logo.png'; Illuminate\Support\Facades\Storage::disk(config('filesystems.media'))->put($path, $png);
                $m = new App\Models\MediaAsset(['disk' => config('filesystems.media'), 'path' => $path, 'original_name' => 'logo-rojo.png', 'mime' => 'image/png', 'kind' => 'image', 'declared_size' => strlen($png), 'size' => strlen($png), 'width' => 20, 'height' => 20, 'status' => 'listo']);
                $m->user_id = $u->id; $m->save(); app(App\Services\Studio\StudioAssets::class)->attach($u, $b, $m->id);`);
            await loginAs(page, user);
            await page.goto(`/studio?brand_id=${brand.id}&tab=brand`);
            const kit = page.getByRole('dialog', { name: 'Identidad de marca', exact: true });
            await kit.getByLabel('Logo de marca logo-rojo.png', { exact: true }).check();
            await kit.getByLabel('Aplicar marca de agua automáticamente', { exact: true }).check();
            await kit
                .getByLabel('Logotipo de la marca de agua', { exact: true })
                .selectOption({ label: 'logo-rojo.png' });
            await kit.getByLabel('Posición de la marca de agua', { exact: true }).selectOption('bottom-right');
            await kit.getByLabel(/^Opacidad/).fill('100');
            await kit.getByLabel(/^Tamaño/).fill('20');
            await kit.getByLabel(/^Margen/).fill('3');
            const saved = page.waitForResponse(
                (response) =>
                    response.url().endsWith(`/brands/${brand.id}/studio/brand-kit`) &&
                    response.request().method() === 'PUT',
            );
            await kit.getByRole('button', { name: 'Guardar identidad', exact: true }).click();
            expect((await saved).status()).toBe(200);
            await expectNoOverflow(page);

            const design = await page.evaluate(async (brandId) => {
                const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
                const response = await fetch(`/brands/${brandId}/studio/designs`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf },
                    body: JSON.stringify({
                        name: 'Lote con marca',
                        document: {
                            schema_version: 1,
                            width: 200,
                            height: 200,
                            pages: [{ id: 'one', name: 'Una', background: '#0000ff', elements: [] }],
                        },
                    }),
                });
                if (!response.ok) throw new Error(await response.text());
                return (await response.json()).design;
            }, brand.id);
            await page.goto(`/studio/designs/${design.id}/edit`);
            await page.getByRole('button', { name: 'Exportar en lote', exact: true }).click();
            const dialog = page.getByRole('dialog', { name: 'Exportar en lote', exact: true });
            await expect(dialog.getByLabel('Aplicar la marca de agua de la marca', { exact: true })).toBeChecked();
            await expect(dialog.getByLabel('Formato Publicación cuadrada', { exact: true })).toBeChecked();
            await dialog.getByLabel('Ajuste al formato', { exact: true }).selectOption('cover');
            await page.screenshot({ path: info.outputPath('batch-before.png'), fullPage: true });
            const created = page.waitForResponse(
                (response) =>
                    response.url().endsWith(`/studio/designs/${design.id}/batch-export`) &&
                    response.request().method() === 'POST',
            );
            await dialog.getByRole('button', { name: 'Exportar en lote', exact: true }).click();
            const first = await created;
            expect(first.status()).toBe(202);
            expect(first.request().postDataJSON()).toMatchObject({ format: 'png', mode: 'cover', watermark: true });
            expect((await first.json()).exports).toHaveLength(3);
            await expect(
                dialog.getByRole('region', { name: 'Estado del lote', exact: true }).getByRole('status'),
            ).toContainText('0 de 3 formatos listos');

            artisan(
                "Illuminate\\Support\\Facades\\Artisan::call('queue:work',['connection'=>'database','--queue'=>'studio-render','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>110,'--sleep'=>0]);",
            );
            await expect(
                dialog.getByRole('region', { name: 'Estado del lote', exact: true }).getByRole('status'),
            ).toContainText('3 de 3 formatos listos', { timeout: 20_000 });
            await page.screenshot({ path: info.outputPath('batch-after.png'), fullPage: true });

            // Un archivo por formato en la biblioteca, con sus dimensiones y la marca de agua en la esquina inferior derecha.
            const files = JSON.parse(
                artisan(String.raw`$rows = [];
                foreach (App\Models\Studio\DesignExport::withoutUserScope()->where('design_id', ${Number(design.id)})->orderBy('id')->get() as $export) {
                    $media = App\Models\MediaAsset::withoutUserScope()->findOrFail($export->media_ids[0]);
                    $image = imagecreatefromstring(Illuminate\Support\Facades\Storage::disk($media->disk)->get($media->path));
                    $corner = imagecolorat($image, $media->width - 32 - 100, $media->height - 32 - 100); $top = imagecolorat($image, 4, 4);
                    $rows[] = ['w' => $media->width, 'h' => $media->height, 'status' => $export->status, 'corner' => [($corner >> 16) & 255, $corner & 255], 'top' => [($top >> 16) & 255, $top & 255],
                        'library' => App\Models\Studio\StudioAsset::withoutUserScope()->where('media_asset_id', $media->id)->exists()];
                }
                echo json_encode($rows);`),
            );
            expect(files.map((file: { w: number; h: number }) => [file.w, file.h])).toEqual([
                [1080, 1080],
                [1080, 1350],
                [1080, 1920],
            ]);
            for (const file of files) {
                expect(file).toMatchObject({ status: 'ready', library: true });
                expect(file.corner[0]).toBeGreaterThan(200); // rojo del logotipo
                expect(file.top[0]).toBeLessThan(60);
                expect(file.top[1]).toBeGreaterThan(200); // el resto sigue azul
            }

            // Repetir el lote no duplica nada.
            const again = page.waitForResponse(
                (response) =>
                    response.url().endsWith(`/studio/designs/${design.id}/batch-export`) &&
                    response.request().method() === 'POST',
            );
            await dialog.getByRole('button', { name: 'Exportar de nuevo', exact: true }).click();
            expect(await (await again).json()).toMatchObject({ created: 0, reused: 3 });
            // El diálogo Exportar avisa de la marca de agua y la imagen se prepara en el servidor mientras esté marcada.
            await dialog.getByRole('button', { name: 'Cerrar exportación en lote', exact: true }).click();
            await page.getByRole('button', { name: 'Exportar', exact: true }).click();
            const single = page.getByRole('dialog', { name: 'Exportar diseño', exact: true });
            await expect(single.getByLabel('Aplicar la marca de agua de la marca', { exact: true })).toBeChecked();
            await expect(single.getByText('La imagen llevará la marca de agua.', { exact: false })).toBeVisible();
            await expect(single.getByRole('button', { name: 'Preparar PNG', exact: true })).toBeVisible();
            await single.getByLabel('Aplicar la marca de agua de la marca', { exact: true }).uncheck();
            await expect(single.getByText('La imagen no llevará marca de agua.', { exact: true })).toBeVisible();
            await expect(single.getByRole('button', { name: 'Guardar en biblioteca', exact: true })).toBeVisible();
            await expectNoOverflow(page);
            expect(errors).toEqual([]);
        } finally {
            cleanComposerBrands(user.email);
        }
    });
});
