import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import { axeSeriousCritical, cleanComposerBrands, CSV_FORMULA, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, seedCsvPosts, settle, stamp, watch, type ComposerBrand } from './helpers';

/**
 * Exportar CSV desde la vista Lista (C-03c, E2E-05): el fichero descargado lleva BOM, `;` y exactamente las publicaciones del filtro,
 * con el texto de fórmula neutralizado. Usuarios `e2e-sec-*` y marcas `e2e-composer-*`: no toca datos reales.
 */
test.describe.configure({ mode: 'serial' });

/** Divide una línea CSV por `;` fuera de comillas. */
function splitCsv(line: string): string[] {
    const cells: string[] = [];
    let cur = '';
    let quoted = false;
    for (const ch of line) {
        if (ch === '"') quoted = !quoted;
        if (ch === ';' && !quoted) {
            cells.push(cur);
            cur = '';
        } else cur += ch;
    }
    cells.push(cur);
    return cells;
}

let brand: ComposerBrand;
let other: ComposerBrand;

test.beforeEach(async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    brand = seedComposerBrand(user.email, `csv-${stamp()}-${info.project.name}`);
    other = seedComposerBrand(user.email, `csv-otra-${stamp()}-${info.project.name}`);
    seedCsvPosts(user.email, brand.id);
    seedCsvPosts(user.email, other.id); // no debe salir al filtrar por la primera marca
    await loginAs(page, user);
});

test.afterEach(async ({}, info) => {
    cleanComposerBrands(e2eUser(info.project.name).email);
});

test('E2E-05 · exportar CSV de la lista: BOM, ; y las publicaciones del filtro con la fórmula neutralizada', async ({ page }) => {
    const w = watch(page);
    await page.goto('/calendar?view=list');
    await settle(page);
    await page.getByLabel('Marca', { exact: true }).selectOption(String(brand.id));
    await page.waitForURL(/brand_id=/);
    await settle(page);
    await page.getByLabel('Red', { exact: true }).selectOption('facebook');
    await page.waitForURL(/network=facebook/);
    await settle(page);

    await expect(page.getByTestId('export-truncated')).toHaveCount(0);
    await expectNoOverflow(page);
    await axeSeriousCritical(page, 'Lista con Exportar CSV');

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-posts').click()]);
    expect(download.suggestedFilename()).toMatch(/^publicaciones-\d{4}-\d{2}-\d{2}\.csv$/);
    const bytes = fs.readFileSync(await download.path());

    expect([...bytes.subarray(0, 3)], 'BOM UTF-8').toEqual([0xef, 0xbb, 0xbf]);
    const text = bytes.toString('utf8').slice(1);
    const lines = text.split('\n').filter(Boolean);
    expect(lines[0]).toBe('"fecha";"marca";"red";"cuenta";"tipo";"estado";"texto";"enlace";"visualizaciones";"alcance";"me_gusta";"comentarios";"compartidos";"guardados";"interacciones"');
    expect(lines, 'cabecera + las 2 publicaciones de la marca filtrada').toHaveLength(3);
    for (const line of lines.slice(1)) {
        expect(splitCsv(line), '15 columnas separadas por ;').toHaveLength(15);
        expect(line).toContain(`"${brand.name}"`);
    }
    // La fórmula sale neutralizada (empieza por ') y no hay ninguna celda de texto que empiece por =.
    expect(text).toContain(`"'${CSV_FORMULA.replace(/"/g, '""')}"`);
    expect(text).toContain('e2e csv texto normal');
    expect(text).not.toContain(other.name);
    expect(text).not.toMatch(/;"=|^"="/m);
    expect(w.errors, 'errores de consola').toEqual([]);
});
