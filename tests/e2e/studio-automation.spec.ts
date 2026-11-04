import fs from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

const browserErrors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
    const errors: string[] = [];
    browserErrors.set(page, errors);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
});
test.afterEach(async ({ page }) => {
    await expectNoOverflow(page);
    expect(browserErrors.get(page) ?? []).toEqual([]);
});

async function createDesign(page: Page, brandId: number, name: string) {
    await page.goto(`/studio?brand_id=${brandId}`);
    await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
    const creation = page.getByRole('dialog', { name: 'Nuevo diseño', exact: true });
    await creation.getByLabel('Nombre', { exact: true }).fill(name);
    await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
    await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
    await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
}

async function readDesign(page: Page, id: number) {
    return page.evaluate(async designId => {
        const response = await fetch(`/studio/designs/${designId}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Design read failed: ${response.status}`);
        return (await response.json()).design;
    }, id);
}

test('Studio automation: own template substitutes literal fields in an independent branded design and exports', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-template-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await createDesign(page, brand.id, 'Original de campaña');
        const originalId = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Hola, {{nombre}}');
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await page.getByRole('button', { name: 'Guardar como plantilla', exact: true }).click();
        const templateDialog = page.getByRole('dialog', { name: 'Guardar como plantilla', exact: true });
        await templateDialog.getByLabel('Nombre de la plantilla', { exact: true }).fill('Saludo reutilizable');
        const saved = page.waitForResponse(response => /\/studio\/designs\/\d+\/template$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await templateDialog.getByRole('button', { name: 'Guardar plantilla', exact: true }).click();
        const savedResponse = await saved;
        expect(savedResponse.status()).toBe(201);
        const ownTemplate = (await savedResponse.json()).design;
        expect(ownTemplate.id).not.toBe(originalId);
        expect(ownTemplate.is_template).toBe(true);
        expect((await readDesign(page, originalId)).is_template).toBe(false);

        await page.goto(`/studio?brand_id=${brand.id}&templates=1`);
        const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Saludo reutilizable', exact: true }) });
        await card.getByRole('button', { name: 'Usar plantilla', exact: true }).click();
        const instantiate = page.getByRole('dialog', { name: 'Usar plantilla', exact: true });
        await instantiate.getByLabel('Nombre del nuevo diseño', { exact: true }).fill('Saludo de noviembre');
        const literal = 'Aipocalipsis $& {{otro}}';
        await instantiate.getByLabel('Campo nombre', { exact: true }).fill(literal);
        await instantiate.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page.getByLabel('Nombre del diseño', { exact: true })).toHaveValue('Saludo de noviembre');
        const instanceId = Number(page.url().match(/designs\/(\d+)/)![1]);
        expect(instanceId).not.toBe(originalId);
        expect(instanceId).not.toBe(ownTemplate.id);
        expect((await readDesign(page, instanceId)).document.pages[0].elements[0].text).toBe(`Hola, ${literal}`);
        await page.getByTestId('studio-layer').first().getByRole('button').first().click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Solo cambia esta copia');
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        expect((await readDesign(page, ownTemplate.id)).document.pages[0].elements[0].text).toBe('Hola, {{nombre}}');
        await page.reload();
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const exporter = page.getByRole('dialog', { name: 'Exportar diseño' });
        const download = page.waitForEvent('download');
        await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
        const artifact = await download;
        const output = info.outputPath('template-instance.png');
        await artifact.saveAs(output);
        const bytes = fs.readFileSync(output);
        expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        expect(bytes.readUInt32BE(16)).toBe(1080);
        expect(bytes.readUInt32BE(20)).toBe(1080);
        await exporter.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.screenshot({ path: info.outputPath('template-instance-editor.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});

test('Studio automation: brand styles, proportional resize and independent variants persist and export', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-variants-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await createDesign(page, brand.id, 'Campaña adaptable');
        const originalId = Number(page.url().match(/designs\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const unbranded = await readDesign(page, originalId);
        expect(unbranded.document.pages[0].elements[0].fontFamily).toBe('Arial');
        await page.getByRole('button', { name: 'Recursos', exact: true }).click();
        const logoBytes = await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 48; canvas.height = 48;
            const context = canvas.getContext('2d')!;
            context.fillStyle = '#224466'; context.fillRect(0, 0, 48, 48);
            context.fillStyle = '#cc5500'; context.fillRect(12, 12, 24, 24);
            return canvas.toDataURL('image/png').split(',')[1];
        });
        const logoRegistered = page.waitForResponse(response => /\/brands\/\d+\/studio\/assets$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await page.getByLabel('Subir recursos de marca', { exact: true }).setInputFiles({ name: 'logo-marca.png', mimeType: 'image/png', buffer: Buffer.from(logoBytes, 'base64') });
        const registered = await logoRegistered;
        expect(registered.status()).toBe(201);
        const logo = (await registered.json()).asset;
        await expect(page.getByText('Recursos añadidos a esta marca.', { exact: true })).toBeVisible();
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Identidad de marca' }).click();
        const kit = page.getByRole('dialog', { name: 'Identidad de marca', exact: true });
        await expect(kit.getByRole('group', { name: 'Logotipos de marca', exact: true })).toBeVisible();
        await kit.getByLabel('Paleta de colores').fill('#224466, #cc5500, #fff8ed');
        await kit.getByLabel('Tipografías').fill('Georgia');
        await kit.getByLabel('Logo de marca logo-marca.png', { exact: true }).check();
        const updatedKit = page.waitForResponse(response => /\/brands\/\d+\/studio\/brand-kit$/.test(new URL(response.url()).pathname) && response.request().method() === 'PUT');
        await kit.getByRole('button', { name: 'Guardar identidad', exact: true }).click();
        const kitResponse = await updatedKit;
        expect(kitResponse.status()).toBe(200);
        expect((await kitResponse.json()).kit.logos).toEqual([logo.id]);
        await expect(kit).not.toBeVisible();
        await page.goto(`/studio/designs/${originalId}/edit`);
        const apply = page.waitForResponse(response => /\/studio\/designs\/\d+\/apply-kit$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Aplicar identidad de marca', exact: true }).click();
        expect((await apply).status()).toBe(200);
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        expect((await readDesign(page, originalId)).document.pages[0].elements[0].fontFamily).toBe('Georgia');

        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        const undone = await readDesign(page, originalId);
        expect(undone.document.pages[0].elements[0].fontFamily).toBe('Arial');
        expect(undone.kit_reference).toEqual(unbranded.kit_reference);
        await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        expect((await readDesign(page, originalId)).document.pages[0].elements[0].fontFamily).toBe('Georgia');
        await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
        await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await page.getByRole('button', { name: 'Aplicar identidad de marca', exact: true }).click();
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await expect.poll(async () => (await readDesign(page, originalId)).document.pages[0].elements[0].fontFamily).toBe('Georgia');

        await page.getByRole('button', { name: 'Página', exact: true }).click();
        await page.getByLabel('Ancho del diseño', { exact: true }).fill('540');
        await page.getByLabel('Alto del diseño', { exact: true }).fill('1080');
        await page.getByLabel('Adaptación al redimensionar', { exact: true }).selectOption('contain');
        const resized = page.waitForResponse(response => /\/studio\/designs\/\d+\/resize$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Aplicar tamaño', exact: true }).click();
        expect((await resized).status()).toBe(200);
        await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
        await page.reload();
        expect((await readDesign(page, originalId)).document.width).toBe(540);

        await page.getByRole('button', { name: 'Crear variantes', exact: true }).click();
        const variants = page.getByRole('dialog', { name: 'Crear variantes', exact: true });
        await variants.getByLabel('Historia / Reel', { exact: true }).check();
        await variants.getByLabel('Presentación / vídeo', { exact: true }).check();
        await variants.getByLabel('Adaptación del contenido', { exact: true }).selectOption('contain');
        const created = page.waitForResponse(response => /\/studio\/designs\/\d+\/variants$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
        await variants.getByRole('button', { name: 'Crear variantes', exact: true }).click();
        const response = await created;
        expect(response.status()).toBe(201);
        const copies = (await response.json()).designs;
        expect(copies).toHaveLength(2);
        expect(copies.map((design: { document: { width: number; height: number } }) => [design.document.width, design.document.height])).toEqual([[1080, 1920], [1920, 1080]]);
        expect(new Set(copies.map((design: { id: number }) => design.id)).size).toBe(2);
        const original = await readDesign(page, originalId);
        expect([original.document.width, original.document.height]).toEqual([540, 1080]);
        const portrait = copies[0];
        await variants.getByRole('link', { name: portrait.name, exact: true }).click();
        await expect(page.getByLabel('Nombre del diseño', { exact: true })).toHaveValue(portrait.name);
        await page.reload();
        const persisted = await readDesign(page, portrait.id);
        expect([persisted.document.width, persisted.document.height]).toEqual([1080, 1920]);
        expect(persisted.document.pages[0].elements[0].id).not.toBe(original.document.pages[0].elements[0].id);
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const exporter = page.getByRole('dialog', { name: 'Exportar diseño' });
        const download = page.waitForEvent('download');
        await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
        const file = await download;
        const output = info.outputPath('portrait-variant.png');
        await file.saveAs(output);
        const bytes = fs.readFileSync(output);
        expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        expect([bytes.readUInt32BE(16), bytes.readUInt32BE(20)]).toEqual([1080, 1920]);
        await exporter.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
        await page.screenshot({ path: info.outputPath('portrait-variant-editor.png'), fullPage: true });
    } finally { cleanComposerBrands(user.email); }
});
