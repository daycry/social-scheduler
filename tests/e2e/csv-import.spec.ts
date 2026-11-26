import { expect, test } from '@playwright/test';
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
    type ComposerBrand,
} from './helpers';

/**
 * Importación de publicaciones por CSV (T-83, E2E-22): la vista previa lista las filas inválidas sin crearlas y, al confirmar,
 * solo las válidas aparecen como publicaciones. Usuarios `e2e-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

let brand: ComposerBrand;
const label = `csv-import-${stamp()}`;

/** CSV del test con el identificador `red:id` de la cuenta de Facebook de la marca sembrada. */
const buildCsv = (facebook: string) =>
    [
        'fecha;texto;cuentas;tipo;medios;aprobacion',
        `2031-03-10 10:00;E2E import programada;${facebook};;;`,
        `;"E2E import borrador\ncon dos líneas, y una coma";${facebook};;;`,
        `10/03/2020 10:00:00;E2E import en el pasado;${facebook};;;`,
        ';E2E import cuenta inexistente;facebook:999999999;;;',
    ].join('\n');

/** Identificador `red:id` de la cuenta de Facebook de la marca. */
const facebookId = (brandId: number) =>
    `facebook:${artisan(
        String.raw`echo 'ACC:' . App\Models\SocialAccount::withoutUserScope()->where('brand_id', ${brandId})->where('network', 'facebook')->value('id');`,
    ).match(/ACC:(\d+)/)![1]}`;

/** Publicaciones de la marca cuyo texto empieza por «E2E import». */
const created = (brandId: number) =>
    Number(
        artisan(
            String.raw`echo 'COUNT:' . App\Models\Post::withoutUserScope()->where('brand_id', ${brandId})->where('text', 'like', 'E2E import%')->count();`,
        ).match(/COUNT:(\d+)/)![1],
    );

test.describe('Importar CSV', { tag: '@responsive' }, () => {
    test.beforeEach(async ({ page }, info) => {
        const user = e2eUser(info.project.name);
        cleanComposerBrands(user.email);
        brand = seedComposerBrand(user.email, `${label}-${info.project.name}`);
        await loginAs(page, user);
    });

    test.afterEach(async ({}, info) => {
        cleanComposerBrands(e2eUser(info.project.name).email);
    });

    test('E2E-22 · importar CSV: las filas inválidas se listan sin crearse y al confirmar las válidas aparecen como publicaciones', async ({
        page,
    }, info) => {
        const w = watch(page);
        await page.goto(`/brands/${brand.id}/posts/import`);
        await settle(page);
        await expect(
            page.getByRole('heading', { name: `Importar publicaciones por CSV · ${brand.name}`, exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: 'Descargar CSV de ejemplo', exact: true })).toBeVisible();
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Importar CSV: estado inicial');
        await page.screenshot({ path: info.outputPath('csv-import-initial.png'), fullPage: true });

        await page.getByLabel('Fichero CSV', { exact: true }).setInputFiles({
            name: 'publicaciones.csv',
            mimeType: 'text/csv',
            buffer: Buffer.from(buildCsv(facebookId(brand.id)), 'utf8'),
        });
        await page.getByRole('button', { name: 'Analizar fichero', exact: true }).click();

        await expect(page.getByTestId('import-summary')).toContainText('4 filas: 2 válidas y 2 con errores');
        const table = page.getByTestId('import-preview-table');
        await expect(table.getByTestId('import-row-2')).toContainText('Válida');
        await expect(table.getByTestId('import-row-3')).toContainText('Válida');
        await expect(table.getByTestId('import-row-4')).toContainText('Con errores (no se creará)');
        await expect(table.getByTestId('import-row-5')).toContainText('Con errores (no se creará)');
        await expect(table.getByTestId('import-row-5')).toContainText(
            'No hay ninguna cuenta «facebook:999999999» en esta marca.',
        );
        expect(created(brand.id), 'la vista previa no crea nada').toBe(0);
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Importar CSV: vista previa');

        await page.getByTestId('import-confirm').click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toContainText('Se crearán 2 publicaciones y se omitirán 2 filas con errores');
        await dialog.getByRole('button', { name: 'Crear publicaciones', exact: true }).click();

        await expect(page.getByTestId('import-outcome')).toContainText('2 publicaciones creadas y 2 filas omitidas.');
        const result = page.getByTestId('import-result-table');
        await expect(
            result.getByTestId('import-result-2').getByRole('link', { name: 'Creada: abrir publicación' }),
        ).toBeVisible();
        await expect(
            result.getByTestId('import-result-3').getByRole('link', { name: 'Creada: abrir publicación' }),
        ).toBeVisible();
        await expect(result.getByTestId('import-result-4')).toContainText('Omitida');
        await expect(result.getByTestId('import-result-5')).toContainText('Omitida');
        expect(created(brand.id), 'solo las 2 filas válidas se crean').toBe(2);
        await expectNoOverflow(page);
        await axeSeriousCritical(page, 'Importar CSV: resultado');
        await page.screenshot({ path: info.outputPath('csv-import-final.png'), fullPage: true });

        // Las publicaciones creadas se abren en el compositor con el texto importado (incluido el salto de línea).
        await result.getByTestId('import-result-3').getByRole('link', { name: 'Creada: abrir publicación' }).click();
        await page.waitForURL(/\/posts\/\d+\/edit$/);
        await expect(page.locator('#text')).toHaveValue('E2E import borrador\ncon dos líneas, y una coma');
        expect(w.errors, 'errores de consola').toEqual([]);
    });
});
