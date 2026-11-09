import fs from 'node:fs';
import { PDFDocument } from 'pdf-lib';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';

const browserErrors = new WeakMap<Page, string[]>();
test.beforeEach(async ({ page }) => {
    const errors: string[] = [];
    browserErrors.set(page, errors);
    page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
    page.on('console', message => { if (message.type() === 'error') errors.push(`console: ${message.text()}`); });
});
test.afterEach(async ({ page }) => {
    await expectNoOverflow(page);
    expect(browserErrors.get(page) ?? []).toEqual([]);
});

const save = async (page: Page) => {
    await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
    await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
};
const savedDocument = (page: Page) => page.evaluate(async () => {
    const id = location.pathname.match(/designs\/(\d+)/)![1];
    const response = await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } });
    return (await response.json()).design.document;
});
const download = async (page: Page, path: string) => {
    const waiting = page.waitForEvent('download');
    await page.getByRole('dialog', { name: 'Exportar diseño' }).getByRole('button', { name: 'Descargar', exact: true }).click();
    await (await waiting).saveAs(path);
    return fs.readFileSync(path);
};
const layer = (page: Page, index: number) => page.getByTestId('studio-layer').nth(index).getByRole('button').first();

test('Studio: whiteboard with a table, glued connectors and free drawing persists and exports PNG, SVG and PDF', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-board-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: /^Pizarra/ }).click();
        const creation = page.getByRole('dialog', { name: 'Nuevo diseño' });
        await expect(creation.getByLabel('Ancho', { exact: true })).toHaveValue('3200');
        await creation.getByLabel('Nombre', { exact: true }).fill('Pizarra de ventas');
        await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Añadir tabla', exact: true })).toBeEnabled();

        // Table: edit a cell, grow it by one row and one column.
        await page.getByRole('button', { name: 'Añadir tabla', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Celda fila 2 columna 2', { exact: true }).fill('180');
        await page.getByRole('button', { name: 'Añadir fila', exact: true }).click();
        await page.getByLabel('Celda fila 4 columna 1', { exact: true }).fill('Reservas con un texto largo que debe reflujar dentro de la celda sin recortarse');
        await page.getByRole('button', { name: 'Añadir columna', exact: true }).click();
        await page.getByLabel('Celda fila 1 columna 4', { exact: true }).fill('Marzo');

        // Two shapes joined by a connector that follows them.
        await page.getByRole('button', { name: 'Capas', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir rectángulo', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir elipse', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(3);
        // Layers are listed front first: 0 = ellipse, 1 = rectangle, 2 = table.
        await layer(page, 0).click();
        await page.locator('.upper-canvas').focus();
        for (let i = 0; i < 40; i++) await page.keyboard.press('Shift+ArrowRight');
        await layer(page, 0).click();
        await layer(page, 1).click({ modifiers: ['Control'] });
        await page.getByRole('button', { name: 'Añadir conector', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(4);
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await expect(page.getByText('Unido a dos elementos: sigue sus movimientos.')).toBeVisible();
        await page.getByLabel('Flecha del conector', { exact: true }).selectOption('both');
        // The new inspector sections (connector now, table below) pass axe without exclusions.
        const connectorAxe = await new AxeBuilder({ page }).include('[aria-label="Propiedades del elemento"]').analyze();
        expect(connectorAxe.violations).toEqual([]);

        await page.getByRole('button', { name: 'Capas', exact: true }).click();
        await layer(page, 3).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        const tableAxe = await new AxeBuilder({ page }).include('[aria-label="Propiedades del elemento"]').analyze();
        expect(tableAxe.violations).toEqual([]);
        await page.getByRole('button', { name: 'Capas', exact: true }).click();

        // Free drawing.
        await page.getByRole('button', { name: 'Capas', exact: true }).click();
        await page.getByRole('button', { name: 'Dibujo libre', exact: true }).click();
        await page.locator('.upper-canvas').scrollIntoViewIfNeeded();
        const box = (await page.locator('.upper-canvas').boundingBox())!;
        const view = page.viewportSize()!;
        // Stroke inside the part of the canvas that is actually visible (on phones it is taller than the screen).
        const canvas = { x: Math.max(box.x, 0), y: Math.max(box.y, 0), width: 0, height: 0 };
        canvas.width = Math.min(box.x + box.width, view.width) - canvas.x;
        canvas.height = Math.min(box.y + box.height, view.height) - canvas.y;
        const points = [[0.6, 0.7], [0.65, 0.75], [0.7, 0.8], [0.75, 0.75], [0.8, 0.7]].map(([fx, fy]) => ({ x: canvas.x + canvas.width * fx, y: canvas.y + canvas.height * fy }));
        if (info.project.name === 'desktop') {
            await page.mouse.move(points[0].x, points[0].y);
            await page.mouse.down();
            for (const point of points.slice(1)) await page.mouse.move(point.x, point.y, { steps: 6 });
            await page.mouse.up();
        } else {
            // Touch-emulated projects take a real touch stroke instead of mouse events.
            const cdp = await page.context().newCDPSession(page);
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [points[0]] });
            for (const point of points.slice(1)) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [point] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        }
        await page.getByRole('button', { name: 'Dibujo libre', exact: true }).click();
        await expect(page.getByTestId('studio-layer')).toHaveCount(5);
        await save(page);

        // Reopen: the saved document keeps table text, the glued connector and the drawing.
        await page.reload();
        await expect(page.getByTestId('studio-layer')).toHaveCount(5);
        const document = await savedDocument(page);
        const elements = document.pages[0].elements;
        const table = elements.find((element: any) => element.type === 'table');
        const connector = elements.find((element: any) => element.type === 'connector');
        expect(document.width).toBe(3200);
        expect(table.table.rows[1][1]).toBe('180');
        expect(table.table.rows[0]).toEqual(['Concepto', 'Enero', 'Febrero', 'Marzo']);
        expect(table.table.rows[3][0]).toContain('reflujar');
        expect(table.height).toBeGreaterThan(240);
        expect(connector).toMatchObject({ route: 'elbow', arrow: 'both' });
        expect(connector.from).toBe(elements.find((element: any) => element.type === 'rect').id);
        expect(connector.to).toBe(elements.find((element: any) => element.type === 'ellipse').id);
        expect(elements.filter((element: any) => element.type === 'path')).toHaveLength(1);

        // Moving a glued shape reroutes the connector.
        const before = JSON.stringify(connector.points) + connector.x + connector.y;
        await page.getByTestId('studio-layer').filter({ hasText: 'Elipse' }).getByRole('button').first().click();
        await page.locator('.upper-canvas').focus();
        for (let i = 0; i < 10; i++) await page.keyboard.press('Shift+ArrowDown');
        await save(page);
        const moved = (await savedDocument(page)).pages[0].elements.find((element: any) => element.type === 'connector');
        expect(JSON.stringify(moved.points) + moved.x + moved.y).not.toBe(before);

        // Exports: real PNG, SVG with the table text, and PDF.
        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        await dialog.getByLabel('Escala', { exact: true }).selectOption('0.5');
        await dialog.getByLabel('Formato', { exact: true }).selectOption('png');
        const png = await download(page, info.outputPath('board.png'));
        expect(png.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        expect(png.readUInt32BE(16)).toBe(1600);
        expect(png.readUInt32BE(20)).toBe(900);
        await dialog.getByLabel('Formato', { exact: true }).selectOption('svg');
        const svg = (await download(page, info.outputPath('board.svg'))).toString();
        expect(svg).toContain('<svg');
        expect(svg).toContain('Marzo');
        await dialog.getByLabel('Formato', { exact: true }).selectOption('pdf');
        const pdf = await PDFDocument.load(await download(page, info.outputPath('board.pdf')));
        expect(pdf.getPageCount()).toBe(1);
        expect(Math.round(pdf.getPage(0).getWidth() / pdf.getPage(0).getHeight() * 100)).toBe(178);
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: A4 document with reflowing text and a table reopens, exports PDF and PNG and can be presented', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-doc-${stamp()}-${info.project.name}`);
    const paragraph = 'Informe mensual de resultados. '.repeat(14);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: /^Documento A4/ }).click();
        const creation = page.getByRole('dialog', { name: 'Nuevo diseño' });
        await creation.getByLabel('Nombre', { exact: true }).fill('Informe de marca');
        await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();

        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill(paragraph);
        await page.getByRole('button', { name: 'Capas', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir tabla', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir página', exact: true }).click();
        await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
        await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
        await page.getByLabel('Contenido del texto', { exact: true }).fill('Anexo con datos');
        await save(page);

        // Reopen: wrapped text keeps its multi-line height, the table keeps its cells, two pages remain.
        await page.reload();
        const document = await savedDocument(page);
        expect(document.pages).toHaveLength(2);
        const [text, table] = document.pages[0].elements;
        expect(text.text).toBe(paragraph);
        expect(text.width).toBeLessThan(document.width);
        expect(text.height).toBeGreaterThan(text.fontSize * 3);
        expect(table.table.rows[1][0]).toBe('Ventas');
        expect(document.pages[1].elements[0].text).toBe('Anexo con datos');

        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        await dialog.getByLabel('Escala', { exact: true }).selectOption('0.5');
        await dialog.getByLabel('Formato', { exact: true }).selectOption('pdf');
        const pdf = await PDFDocument.load(await download(page, info.outputPath('document.pdf')));
        expect(pdf.getPageCount()).toBe(2);
        expect(Math.round(pdf.getPage(0).getHeight() / pdf.getPage(0).getWidth() * 100)).toBe(141);
        await dialog.getByLabel(/Página 1$/).uncheck();
        await dialog.getByLabel('Formato', { exact: true }).selectOption('png');
        const png = await download(page, info.outputPath('document.png'));
        expect(png.readUInt32BE(16)).toBe(1240);
        expect(png.readUInt32BE(20)).toBe(1754);
        await page.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();

        await page.getByRole('button', { name: 'Presentar', exact: true }).click();
        const presenter = page.getByRole('dialog', { name: 'Presentación', exact: true });
        await expect(presenter.getByRole('button', { name: 'Reproducir presentación', exact: true })).toBeEnabled();
        await presenter.getByRole('button', { name: 'Última diapositiva', exact: true }).click();
        await expect(presenter.getByRole('img', { name: 'Diapositiva de audiencia', exact: true })).toBeVisible();
        await presenter.getByRole('button', { name: 'Cerrar presentación', exact: true }).click();
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: a chart bound to a saved table is inserted, saved, reopened and exported', async ({ page }, info) => {
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-chart-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Añadir tabla', exact: true })).toBeEnabled();
        const table = { columns: [{ id: 'category', label: 'Mes' }, { id: 's0', label: 'Ventas' }], rows: [
            { id: 'r0', values: ['Enero', 10] }, { id: 'r1', values: ['Febrero', 25] }, { id: 'r2', values: ['Marzo', 18] }] };
        const dataset = await page.evaluate(async ({ brandId, table }) => {
            const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/datasets`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ name: 'Ventas mensuales', table }) });
            return { status: response.status, data: await response.json() };
        }, { brandId: brand.id, table });
        expect(dataset.status).toBe(201);

        await page.getByRole('button', { name: 'Añadir gráfico con datos', exact: true }).click();
        const datasets = page.getByRole('dialog', { name: 'Tablas de datos de marca', exact: true });
        await datasets.getByTestId(`dataset-open-${dataset.data.dataset.id}`).click();
        await datasets.getByRole('button', { name: 'Usar revisión guardada para gráfico', exact: true }).click();
        const chart = page.getByRole('dialog', { name: 'Diseñar gráfico', exact: true });
        await chart.getByRole('button', { name: /^Insertar gráfico$/ }).click();
        await expect(chart).not.toBeVisible();
        await save(page);
        await page.reload();
        await expect(page.getByTestId('studio-layer')).toHaveCount(1);
        const element = (await savedDocument(page)).pages[0].elements[0];
        expect(element.type).toBe('chart');
        expect(element.chart.binding.dataset.id).toBe(dataset.data.dataset.id);

        await page.getByRole('button', { name: 'Exportar', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
        await dialog.getByLabel('Formato', { exact: true }).selectOption('svg');
        const svg = (await download(page, info.outputPath('chart.svg'))).toString();
        expect(svg).toContain('<svg');
        expect(svg).toContain('Febrero');
    } finally { cleanComposerBrands(user.email); }
});

test('Studio: a chart bound to a saved table is exported on the server as SVG, PNG and PDF', async ({ page }, info) => {
    test.setTimeout(180_000);
    const user = e2eUser(info.project.name);
    cleanComposerBrands(user.email);
    const brand = seedComposerBrand(user.email, `studio-chart-server-${stamp()}-${info.project.name}`);
    try {
        await loginAs(page, user);
        await page.goto(`/studio?brand_id=${brand.id}`);
        await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
        await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
        await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
        await expect(page.getByRole('button', { name: 'Añadir tabla', exact: true })).toBeEnabled();
        const table = { columns: [{ id: 'category', label: 'Mes' }, { id: 's0', label: 'Ventas' }], rows: [
            { id: 'r0', values: ['Enero', 10] }, { id: 'r1', values: ['Febrero', 25] }, { id: 'r2', values: ['Marzo', 18] }] };
        const dataset = await page.evaluate(async ({ brandId, table }) => {
            const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/brands/${brandId}/studio/datasets`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ name: 'Ventas servidor', table }) });
            return { status: response.status, data: await response.json() };
        }, { brandId: brand.id, table });
        expect(dataset.status).toBe(201);
        await page.getByRole('button', { name: 'Añadir gráfico con datos', exact: true }).click();
        const datasets = page.getByRole('dialog', { name: 'Tablas de datos de marca', exact: true });
        await datasets.getByTestId(`dataset-open-${dataset.data.dataset.id}`).click();
        await datasets.getByRole('button', { name: 'Usar revisión guardada para gráfico', exact: true }).click();
        await page.getByRole('dialog', { name: 'Diseñar gráfico', exact: true }).getByRole('button', { name: /^Insertar gráfico$/ }).click();
        await save(page);

        // The real route: queue a render, run the job body in-process (no worker), then fetch the produced file.
        const design = await page.evaluate(async () => {
            const id = location.pathname.match(/designs\/(\d+)/)![1];
            const read = (await (await fetch(`/studio/designs/${id}`, { headers: { Accept: 'application/json' } })).json()).design;
            return { id, revision: read.revision, page: read.document.pages[0].id };
        });
        const queue = (format: string) => page.evaluate(async ({ design, format }) => {
            const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const response = await fetch(`/studio/designs/${design.id}/render`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ revision: design.revision, format, pages: [design.page], scale: 1 }) });
            return { status: response.status, export: (await response.json()).export };
        }, { design, format });
        const status = (id: number) => page.evaluate(async id => (await (await fetch(`/studio/exports/${id}`, { headers: { Accept: 'application/json' } })).json()).export, id);
        const fetched = async (url: string) => Buffer.from(await page.evaluate(async url => {
            const response = await fetch(url);
            const bytes = new Uint8Array(await response.arrayBuffer());
            let binary = '';
            bytes.forEach(byte => { binary += String.fromCharCode(byte); });
            return btoa(binary);
        }, url), 'base64');
        const files: Record<string, Buffer> = {};
        for (const format of ['svg', 'png', 'pdf']) {
            const created = await queue(format);
            expect(created.status).toBe(202);
            artisan(`app(App\\Services\\Studio\\StudioRenderer::class)->render(${created.export.id});`);
            const done = await status(created.export.id);
            expect(done.status, done.error ?? `render ${format}`).toBe('ready');
            files[format] = await fetched(done.download_url);
            fs.writeFileSync(info.outputPath(`server-chart.${format}`), files[format]);
        }
        expect(files.svg.toString()).toContain('<svg');
        expect(files.svg.toString()).toContain('Febrero');
        expect(files.png.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
        expect(files.png.length).toBeGreaterThan(2000);
        expect(files.pdf.subarray(0, 5).toString()).toBe('%PDF-');
        expect(files.pdf.length).toBeGreaterThan(1500);
        expect((await PDFDocument.load(files.pdf)).getPageCount()).toBe(1);
    } finally { cleanComposerBrands(user.email); }
});
