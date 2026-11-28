import fs from 'node:fs';
import { PDFDocument } from 'pdf-lib';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

const browserErrors = new WeakMap<Page, string[]>();
test.beforeEach(async ({ page }, info) => {
    const errors: string[] = [];
    browserErrors.set(page, errors);
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => {
        if (message.type() !== 'error') return;
        // Only the endpoints exercised for an expected conflict may emit a 409.
        const endpoint = new URL(message.location().url || 'http://localhost').pathname;
        const expectedConflict = /status of 409/.test(message.text()) && (
            (info.title.includes('a stale revision') && /\/studio\/designs\/\d+$/.test(endpoint))
            || (info.title.includes('el conflicto al guardar una plantilla') && /\/studio\/designs\/\d+\/template$/.test(endpoint))
        );
        if (!expectedConflict) errors.push(`console: ${message.text()}`);
    });
});
test.afterEach(async ({ page }) => {
    await expectNoOverflow(page);
    expect(browserErrors.get(page) ?? []).toEqual([]);
});

test('Studio: fractional exports round to 21 pixels and register PNG JPEG WebP in the brand library', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-fractional-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        const creation = page.getByRole('dialog', { name: 'Nuevo diseño' });
        await creation.getByLabel('Ancho', { exact: true }).fill('41');
        await creation.getByLabel('Alto', { exact: true }).fill('41');
        await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        await dialog.getByLabel('Escala', { exact: true }).selectOption('0.5');
        await expect(dialog.getByText('21 × 21 px', { exact: true })).toBeVisible();
        for (const format of ['png', 'jpeg', 'webp']) {
            await dialog.getByLabel('Formato', { exact: true }).selectOption(format);
            const registration = page.waitForResponse(response => /\/studio\/designs\/\d+\/exports$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
            await dialog.getByRole('button', { name: 'Guardar en biblioteca', exact: true }).click();
            const response = await registration;
            expect(response.status()).toBe(201);
            const result = await response.json();
            expect(result.export.format).toBe(format);
            expect(result.export.media_ids).toHaveLength(1);
            await expect(dialog.getByRole('status')).toHaveText('Exportación guardada en la biblioteca de esta marca.');
        }
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: design persists, exports real PNG and starts a publication in its brand', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await expect(page.getByRole('heading', { name: 'Estudio creativo', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Nuevo diseño' });
        await dialog.getByLabel('Nombre', { exact: true }).fill('Lanzamiento de la marca');
        await dialog.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByLabel('Nombre del diseño', { exact: true })).toHaveValue('Lanzamiento de la marca');
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Hola, nueva colección');
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await page.reload();
        await expect(page.getByTestId('studio-layer')).toHaveCount(1);
        await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.screenshot({ path: info.outputPath('studio-editor.png'), fullPage: true });

        const fontsLoaded = page.waitForResponse(response =>
            /\/brands\/\d+\/(?:studio\/fonts|design-fonts)$/.test(new URL(response.url()).pathname)
                && response.request().method() === 'GET',
        );
        await page.getByRole('button', { name: 'Tipografías de marca', exact: true }).click();
        const fonts = page.getByRole('dialog', { name: 'Tipografías de marca', exact: true });
        await expect(fonts).toBeVisible();
        expect((await fontsLoaded).status()).toBe(200);
        await fonts.getByRole('button', { name: 'Cerrar tipografías de marca', exact: true }).click();

        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const exporter = page.getByRole('dialog', { name: 'Exportar diseño' });
        await exporter.getByLabel('Formato', { exact: true }).selectOption('png');
        const downloaded = page.waitForEvent('download');
        await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
        const file = await downloaded;
        const output = info.outputPath('studio-export.png');
        await file.saveAs(output);
        const bytes = fs.readFileSync(output);
        expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        expect(bytes.readUInt32BE(16)).toBe(1080);
        expect(bytes.readUInt32BE(20)).toBe(1080);
        await page.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();

        // La primera acción de esta instancia del editor abre la exportación y crea el borrador.
        await page.reload();
        await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
        await expect(page.locator('aside img')).toBeVisible();
    } finally {
        cleanComposerBrands(user.email);
    }
});


const studioDialogNames = [
    'ExportDialog', 'PresentationDialog', 'StudioChartsDialog', 'StudioCollabPanel',
    'StudioDatasetsDialog', 'StudioDialog', 'StudioFontsDialog', 'VariantsDialog',
];

async function loadedResources(page: Page) {
    return page.evaluate(() => performance.getEntriesByType('resource').map(entry => new URL(entry.name).pathname));
}

test('Studio: undo, page ordering and portable JPEG/WebP/SVG/PDF exports retain the design', async ({ page }, info) => {
    const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
    const assetPath = (entry: string) => {
        expect(manifest[entry]?.file, entry).toBeTruthy();
        return '/build/' + manifest[entry].file;
    };
    const dialogAssets = studioDialogNames.map(name => assetPath(`resources/js/Components/Studio/${name}.vue`));
    const exportChunk = assetPath('resources/js/Components/Studio/ExportDialog.vue');
    const pdfChunk = assetPath('node_modules/pdf-lib/es/index.js');
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-pages-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Añadir rectángulo', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(2);
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(1);
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(2);
        await page.getByTestId('studio-layer').first().getByRole('button').first().click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Página original');
        await page.getByRole('button', { name: 'Duplicar página', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByRole('button', { name: 'Capas', exact: true }).click();
        await page.getByTestId('studio-layer').getByRole('button').first().click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Página duplicada');
        await page.getByRole('button', { name: '← Mover página', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        // Resource Timing acumula recursos de la navegacion Inertia anterior.
        await page.reload();
        await expect(page.getByRole('button', { name: 'Exportar', exact: true })).toBeEnabled();
        const initialResources = await loadedResources(page);
        expect(initialResources.filter(path => dialogAssets.includes(path) || path === pdfChunk)).toEqual([]);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        // Select one page for single-file signatures; PDF below includes both pages.
        const originalPage = dialog.getByLabel('2. Página 1', { exact: true });
        await originalPage.uncheck();
        for (const format of ['jpeg', 'webp', 'svg']) {
            await dialog.getByLabel('Formato', { exact: true }).selectOption(format);
            const download = page.waitForEvent('download');
            await dialog.getByRole('button', { name: 'Descargar', exact: true }).click();
            const file = await download;
            const output = info.outputPath(`studio-export.${format}`);
            await file.saveAs(output);
            const bytes = fs.readFileSync(output);
            if (format === 'jpeg') expect(bytes.subarray(0, 3).toString('hex')).toBe('ffd8ff');
            if (format === 'webp') { expect(bytes.subarray(0, 4).toString()).toBe('RIFF'); expect(bytes.subarray(8, 12).toString()).toBe('WEBP'); }
            if (format === 'svg') { expect(bytes.toString()).toContain('<svg'); expect(bytes.toString()).toContain('Página duplicada'); expect(bytes.toString()).not.toContain('/studio/assets/'); }
        }
        const rasterResources = await loadedResources(page);
        expect(rasterResources).toContain(exportChunk);
        expect(rasterResources).not.toContain(pdfChunk);
        await originalPage.check();
        await dialog.getByLabel('Formato', { exact: true }).selectOption('pdf');
        const download = page.waitForEvent('download');
        await dialog.getByRole('button', { name: 'Descargar', exact: true }).click();
        const file = await download;
        const output = info.outputPath('studio-pages.pdf');
        await file.saveAs(output);
        const pdf = await PDFDocument.load(fs.readFileSync(output));
        const pdfResources = await loadedResources(page);
        expect(pdfResources).toContain(pdfChunk);
        await info.attach('studio-loading-resources', {
            body: JSON.stringify({ initial: initialResources, afterRaster: rasterResources, afterPdf: pdfResources }),
            contentType: 'application/json',
        });
        expect(pdf.getPageCount()).toBe(2);
        expect(pdf.getPages().map(item => item.getWidth())).toEqual([810, 810]);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: a stale revision preserves local edits and can recover the server version', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-conflict-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const designUrl = new URL(page.url()).pathname.replace(/\/edit$/, '');
        const remoteStatus = await page.evaluate(async url => {
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '') };
            const current = await fetch(url, { headers }).then(response => response.json());
            const response = await fetch(url, { method: 'PATCH', headers, body: JSON.stringify({ ...current.design, name: 'Nombre desde otra sesión' }) });
            return response.status;
        }, designUrl);
        expect(remoteStatus).toBe(200);
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Mi cambio local conservado');
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Conflicto de revisión');
        await expect(page.getByLabel('Contenido del texto', { exact: true })).toHaveValue('Mi cambio local conservado');
        const download = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Descargar copia local', exact: true }).click();
        const localFile = await download;
        const output = info.outputPath('studio-local-conflict.json');
        await localFile.saveAs(output);
        expect(JSON.parse(fs.readFileSync(output, 'utf8')).document.pages[0].elements[0].text).toBe('Mi cambio local conservado');
        await page.getByRole('button', { name: 'Recuperar versión guardada', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await expect(page.getByLabel('Nombre del diseño', { exact: true })).toHaveValue('Nombre desde otra sesión');
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: folders are renamed, designs move between them and deleting a folder keeps its designs', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-folders-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toBeVisible();
        await page.goto(`/studio?brand_id=${brand.id}`);

        for (const name of ['Lanzamientos', 'Archivo']) {
            await page.getByRole('button', { name: 'Nueva carpeta', exact: true }).click();
            await page.getByLabel('Nombre de la carpeta', { exact: true }).fill(name);
            await page.getByRole('button', { name: 'Crear carpeta', exact: true }).click();
            await expect(page.locator('#studio-folder-filter').locator('option', { hasText: name })).toHaveCount(1);
        }

        // Renombrar con el teclado: Enter en el botón abre el campo con el foco; Esc cancela solo el renombrado (el diálogo sigue abierto) y
        // devuelve el foco al botón; guardar con Enter también lo devuelve.
        await page.getByTestId('folders-manage').click();
        const folders = page.getByRole('dialog', { name: 'Carpetas' });
        const renameButton = folders.getByRole('button', { name: 'Renombrar la carpeta Lanzamientos' });
        await renameButton.focus();
        await page.keyboard.press('Enter');
        await expect(folders.getByTestId('folder-rename-input')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(folders).toBeVisible();
        await expect(folders.getByTestId('folder-rename-input')).toHaveCount(0);
        await expect(renameButton).toBeFocused();
        await page.keyboard.press('Enter');
        await folders.getByTestId('folder-rename-input').fill('Campañas');
        await page.keyboard.press('Enter');
        await expect(folders.getByText('Campañas', { exact: true })).toBeVisible();
        await expect(folders.getByRole('button', { name: 'Renombrar la carpeta Campañas' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(folders).toBeHidden();

        // Mover el diseño a la carpeta renombrada y verlo al filtrar por ella.
        await page.locator('summary[aria-label^="Opciones de"]').click();
        await page.getByTestId('design-move').click();
        const move = page.getByRole('dialog', { name: 'Mover a carpeta' });
        await move.getByTestId('move-target').selectOption({ label: 'Campañas' });
        await move.getByTestId('move-confirm').click();
        await page.locator('#studio-folder-filter').selectOption({ label: 'Campañas' });
        await expect(page.locator('article')).toHaveCount(1);

        // Borrar la carpeta pide confirmación, no pierde el diseño y vuelve a «Todas las carpetas».
        await page.getByTestId('folders-manage').click();
        const deleteFolder = folders.getByRole('button', { name: 'Borrar la carpeta Campañas', exact: true });
        await deleteFolder.click();
        const confirmation = page.getByRole('alertdialog', { name: 'Borrar carpeta' });
        await expect(confirmation).toContainText('pasa a «Sin carpeta»');
        await page.keyboard.press('Escape');
        await expect(confirmation).toHaveCount(0);
        await expect(deleteFolder).toBeFocused();
        await deleteFolder.click();
        await confirmation.getByRole('button', { name: 'Borrar carpeta' }).click();
        await expect(page.locator('#studio-folder-filter')).toHaveValue('');
        await expect(page.locator('article')).toHaveCount(1);
        await expect(page.locator('#studio-folder-filter').locator('option', { hasText: 'Campañas' })).toHaveCount(0);
    } finally { cleanComposerBrands(user.email); }
});

test('T-58 · el conflicto al guardar una plantilla deja visibles las acciones de recuperación', { tag: '@responsive' }, async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-template-conflict-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await page.route('**/studio/designs/*/template', request => request.fulfill({
            status: 409, json: { message: 'El diseño cambió en otra sesión.' },
        }));

        await page.getByRole('button', { name: 'Guardar como plantilla', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Guardar como plantilla', exact: true });
        await dialog.getByRole('button', { name: 'Guardar plantilla', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Conflicto de revisión');
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('button', { name: 'Guardar como copia', exact: true })).toBeVisible();
    } finally {
        cleanComposerBrands(user.email);
    }
});
