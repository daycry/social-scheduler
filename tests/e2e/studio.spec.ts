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
        // This test deliberately produces one handled optimistic-write conflict at this endpoint.
        const expectedConflict = info.title.includes('a stale revision')
            && /status of 409/.test(message.text())
            && /\/studio\/designs\/\d+$/.test(new URL(message.location().url || 'http://localhost').pathname);
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

test('Studio: design persists, exports real PNG and starts a publication in its brand', async ({ page }, info) => {
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

        await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
        await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
        await expect(page.locator('aside img')).toBeVisible();
    } finally {
        cleanComposerBrands(user.email);
    }
});

test('Studio: undo, page ordering and portable JPEG/WebP/SVG/PDF exports retain the design', async ({ page }, info) => {
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
        await originalPage.check();
        await dialog.getByLabel('Formato', { exact: true }).selectOption('pdf');
        const download = page.waitForEvent('download');
        await dialog.getByRole('button', { name: 'Descargar', exact: true }).click();
        const file = await download;
        const output = info.outputPath('studio-pages.pdf');
        await file.saveAs(output);
        const pdf = await PDFDocument.load(fs.readFileSync(output));
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
