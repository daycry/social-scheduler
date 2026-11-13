import { expect, test, type Page, type Locator, type TestInfo } from '@playwright/test';
import fs from 'node:fs';
import crypto from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';
import downloadObserver from './isolated/datasets/download-observer.cjs';
import osEnvironment from './isolated/datasets/os-environment.cjs';
import { environment, runRoot } from './isolated/runtime';
import isolation from './isolated/datasets/isolation.cjs';
import { artisan, cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, stamp } from './helpers';

osEnvironment.applyTo(environment);

// Independent observers: no dataset-table/client/product codec imported.
function observedJson(source: string): any {
    let i = 0;
    const space = () => { while (/[ \t\r\n]/.test(source[i] ?? '') && i < source.length) i++; };
    const string = () => { const start = i++; while (i < source.length) { if (source[i++] === '\\') i++; else if (source[i - 1] === '"') return JSON.parse(source.slice(start, i)); } throw new Error('Unclosed JSON string'); };
    const value = (): any => {
        space(); const c = source[i];
        if (c === '"') return string();
        if (c === '[') { i++; const result: any[] = []; space(); if (source[i] === ']') { i++; return result; } while (true) { result.push(value()); space(); if (source[i++] === ']') return result; if (source[i - 1] !== ',') throw new Error('Invalid observed JSON array'); } }
        if (c === '{') { i++; const result: Record<string, any> = Object.create(null); space(); if (source[i] === '}') { i++; return result; } while (true) { space(); if (source[i] !== '"') throw new Error('Invalid JSON key'); const key = string(); if (Object.hasOwn(result, key)) throw new Error('Duplicate observed key'); space(); if (source[i++] !== ':') throw new Error('Missing colon'); result[key] = value(); space(); if (source[i++] === '}') return result; if (source[i - 1] !== ',') throw new Error('Invalid observed object'); } }
        if (source.startsWith('null', i)) { i += 4; return null; }
        const number = source.slice(i).match(/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/);
        if (!number) throw new Error('Unexpected observed JSON value'); i += number[0].length; return { numberLexeme: number[0] };
    };
    const result = value(); space(); if (i !== source.length) throw new Error('Trailing observed JSON'); return result;
}
function observedDelimited(source: string, separator = ',') {
    const rows: string[][] = []; let row: string[] = [], field = '', quoted = false, closed = false;
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        if (quoted) { if (c === '"' && source[i + 1] === '"') { field += '"'; i++; } else if (c === '"') { quoted = false; closed = true; } else field += c; }
        else if (c === separator) { row.push(field); field = ''; closed = false; }
        else if (c === '\r' && source[i + 1] === '\n') { row.push(field); rows.push(row); row = []; field = ''; closed = false; i++; }
        else if (closed || c === '\r' || c === '\n') throw new Error('Invalid observed CSV record');
        else if (c === '"') { expect(field).toBe(''); quoted = true; }
        else field += c;
    }
    expect(quoted).toBe(false); expect(closed).toBe(false); expect(field).toBe(''); expect(row).toEqual([]); return rows;
}
const longText = 'é'.repeat(9997) + '\n終\t';
const typedFixture = () => '{"columns":' + JSON.stringify(['Zero', 'One', 'Negative', 'Big', 'Empty', '=Formula', 'Multiline'].map((label, i) => ({ id: `c${i}`, label }))) + ',"rows":[{"id":"r0","values":[0.0,1.0,-0.0,9007199254740993,null," =SUM(1,2)",' + JSON.stringify(longText) + ']}]}';
const dialog = (page: Page) => page.getByRole('dialog', { name: 'Tablas de datos de marca', exact: true });
const button = (d: Locator, name: string) => d.getByRole('button', { name, exact: true });
const cell = (d: Locator, row: number, col: number) => d.getByRole('button', { name: new RegExp(`^Editar fila ${row}, columna ${col}:`) });
async function details(d: Locator, title: string) { const summary = d.locator('summary').filter({ hasText: title }); const parent = summary.locator('..'); if (!await parent.evaluate(el => (el as HTMLDetailsElement).open)) await summary.click(); }
async function tools(d: Locator) { await details(d, 'Herramientas de tabla'); }
async function web(page: Page, method: string, url: string, raw?: string) {
    return page.evaluate(async ({ method, url, raw }) => {
        const csrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf }, ...(raw === undefined ? {} : { body: raw }) });
        return { status: response.status, text: await response.text() };
    }, { method, url, raw });
}
function ownBrand(email: string, suffix: string) {
    const name = `e2e-composer-datasets-${suffix}`;
    return Number(artisan(`$u=App\\Models\\User::where('email','${email}')->firstOrFail();$b=App\\Models\\Brand::factory()->for($u)->create(['name'=>'${name}']);echo 'DATASET_BRAND:'.$b->id;`).match(/DATASET_BRAND:(\d+)/)![1]);
}
async function setup(page: Page, info: TestInfo) { const user = e2eUser(info.project.name); cleanComposerBrands(user.email); const brand = ownBrand(user.email, stamp()); await loginAs(page, user); await page.goto(`/studio?brand_id=${brand}`); await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await expect(dialog(page)).toBeVisible(); return { user, brand, d: dialog(page) }; }
async function edit(d: Locator, row: number, col: number, text: string, type = 'text') { await cell(d, row, col).click(); await d.getByRole('combobox', { name: 'Tipo de la celda', exact: true }).selectOption(type); if (type !== 'empty') await d.getByLabel('Valor de la celda', { exact: true }).fill(text); await button(d, 'Aplicar celda').click(); }
const writeResponse = (page: Page) => page.waitForResponse(r => r.request().method() === 'POST' && /\/studio\/datasets(?:\/\d+)?\/import$/.test(new URL(r.url()).pathname));
async function save(page: Page, d: Locator) { const pending = writeResponse(page); await button(d, 'Guardar tabla').click(); const response = await pending; expect([200, 201]).toContain(response.status()); await expect(d.getByRole('status').filter({ hasText: 'Sin cambios pendientes' })).toBeVisible(); return (await response.json()).dataset; }
async function imported(page: Page, d: Locator, name: string, format: string, content: string, header = true) { await details(d, 'Importar un archivo'); await d.getByLabel('Nombre de la importación', { exact: true }).fill(name); await d.getByRole('combobox', { name: 'Formato de importación', exact: true }).selectOption(format); if (format !== 'json') await d.getByLabel('La primera fila contiene encabezados', { exact: true }).setChecked(header); await d.getByLabel('Archivo de datos', { exact: true }).setInputFiles({ name: `fixture.${format}`, mimeType: format === 'json' ? 'application/json' : 'text/plain', buffer: Buffer.from(content) }); const pending = writeResponse(page); await button(d, 'Importar archivo').click(); const response = await pending; expect(response.status()).toBe(201); await expect(d.getByTestId('dataset-editor')).toBeVisible(); return (await response.json()).dataset; }
async function download(page: Page, d: Locator, info: TestInfo, name: string, format = 'json', mode = 'spreadsheet') { await d.getByRole('combobox', { name: 'Formato de descarga', exact: true }).selectOption(format); if (format !== 'json') await d.getByRole('combobox', { name: 'Modo de descarga', exact: true }).selectOption(mode); const target = info.outputPath(name); const { meta, bytes } = await downloadObserver.observe(page, d.getByRole('link', { name: 'Descargar tabla', exact: true }), { file: target, format, logFile: path.join(runRoot, 'dataset-downloads.jsonl') }); fs.appendFileSync(info.outputPath('download-observer.jsonl'), JSON.stringify({ name, kind: 'snapshot', format, mode, ...meta }) + '\n'); return bytes.toString('utf8'); }
async function draft(page: Page, d: Locator, info: TestInfo, name: string) { const pending = page.waitForEvent('download'); await button(d, 'Descargar borrador JSON').click(); const file = await pending; await file.saveAs(info.outputPath(name)); const bytes = fs.readFileSync(info.outputPath(name)); fs.appendFileSync(info.outputPath('download-observer.jsonl'), JSON.stringify({ name, kind: 'draft', format: 'json', bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex') }) + '\n'); return bytes.toString('utf8'); }
async function close(page: Page, discard = false) { await dialog(page).getByRole('button', { name: /^Cerrar tablas de datos/ }).click(); if (discard) await page.getByRole('alertdialog').getByRole('button', { name: 'Descartar cambios', exact: true }).click(); await expect(dialog(page)).not.toBeVisible(); }
async function capture(page: Page, info: TestInfo, name: string) { await expectNoOverflow(page); await page.screenshot({ path: info.outputPath(name), fullPage: true }); }
async function auditDialog(page: Page, info: TestInfo) {
    const result = await new AxeBuilder({ page }).include('dialog[aria-label="Tablas de datos de marca"]').analyze();
    fs.writeFileSync(info.outputPath('axe-dialog.json'), JSON.stringify(result, null, 2));
    expect(result.violations, 'Real axe dialog violations, including labels/roles/contrast').toEqual([]);
    // Axe cannot measure a cell that sits outside the scrolled grid ("Element midpoint exceeds the grid bounds", seen at 360 px:
    // the typed table scrolls horizontally inside its own container). That is a measurement error with no node, not a finding.
    // It is resolved narrowly: only that exact error of color-contrast, and each reported element is measured here from its
    // computed colours and must still reach the WCAG AA ratio. Any other incomplete result still fails.
    const unmeasurable = result.incomplete.filter(item => item.id === 'color-contrast' && item.nodes.length === 0 && /midpoint exceeds the grid bounds/i.test(String((item as any).message ?? '')));
    const rest = result.incomplete.filter(item => !unmeasurable.includes(item));
    expect(rest, 'Axe warnings/incomplete require resolution; no silent exclusion').toEqual([]);
    for (const item of unmeasurable) {
        const selector = (item as any).errorNode.selector[0] as string;
        const measured = await page.locator(selector).first().evaluate(el => {
            const channels = (value: string) => (value.match(/[\d.]+/g) ?? []).map(Number);
            const luminance = ([r, g, b]: number[]) => { const f = (c: number) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
            const style = getComputedStyle(el);
            const fg = channels(style.color);
            let node: Element | null = el, bg = [255, 255, 255];
            while (node) { const c = channels(getComputedStyle(node).backgroundColor); if (c.length >= 3 && (c.length === 3 || c[3] > 0.99)) { bg = c.slice(0, 3); break; } node = node.parentElement; }
            const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
            const size = Number.parseFloat(style.fontSize), bold = Number.parseInt(style.fontWeight, 10) >= 700;
            return { ratio: (hi + 0.05) / (lo + 0.05), needed: size >= 24 || (size >= 18.66 && bold) ? 3 : 4.5 };
        });
        expect(measured.ratio, `contrast of ${selector} measured from computed styles`).toBeGreaterThanOrEqual(measured.needed);
    }
}
test.beforeEach(async ({ page }) => {
    (page as any).__datasetHolds = [];
    (page as any).__datasetPolicy = isolation.state();
    await isolation.install(page, (page as any).__datasetPolicy);
});
test.afterEach(async ({ page }, info) => {
    for (const release of (page as any).__datasetHolds ?? []) { try { await release(); } catch { /* Release pending delivery even after a failing/closed test. */ } }
    try {
        fs.writeFileSync(info.outputPath('viewport.json'), JSON.stringify({ viewport: page.viewportSize(), project: info.project.name, case: info.title.split(' ')[0], title: info.title }));
        await expectNoOverflow(page);
        const network = isolation.assess((page as any).__datasetPolicy);
        fs.writeFileSync(info.outputPath('network-observer.json'), JSON.stringify(network, null, 2));
        expect(network.issues, 'No unasserted/unexpected HTTP, browser network or application errors').toEqual([]);
    } finally { cleanComposerBrands(e2eUser(info.project.name).email); }
});

async function delayedRealResponse(page: Page, expression: RegExp, method: string) {
    let release!: () => void, captured!: (value: any) => void, finished!: () => void, rejectCapture!: (error: Error) => void;
    const delivery = new Promise<void>(resolve => { release = resolve; });
    const real = new Promise<any>((resolve, reject) => { captured = resolve; rejectCapture = reject; });
    const done = new Promise<void>(resolve => { finished = resolve; });
    const observations: any[] = []; let handlerStarted = false;
    real.catch(() => {}); // Failure still propagates to the awaiting test, never an unhandled late rejection.
    await page.route(expression, async route => {
        handlerStarted = true;
        try {
            expect(route.request().method()).toBe(method); expect(new URL(route.request().url()).hostname).toBe('localhost');
            const response = await route.fetch({ timeout: 30_000 }); const bytes = await response.body();
            const item = { method, path: new URL(route.request().url()).pathname, status: response.status(), sha256: crypto.createHash('sha256').update(bytes).digest('hex'), response_bytes: bytes.length, delivered: false, cancelled_delivery: false };
            observations.push(item); captured(item); await delivery;
            try { await route.fulfill({ response }); item.delivered = true; } catch (error) { if (!/closed|cancell?ed|abort|not found|invalid interception/i.test(String(error))) throw error; item.cancelled_delivery = true; }
        } catch (error) { rejectCapture(error as Error); throw error; }
        finally { finished(); }
    }, { times: 1 });
    const unblock = async () => { release(); if (!handlerStarted) return; await done; await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))); };
    (page as any).__datasetHolds.push(unblock);
    return { real, observations, release: unblock, confirmCancellation: () => { const token = isolation.expectAbort((page as any).__datasetPolicy, method, observations[0].path); token.confirmed = true; } };
}

test('E2E27.01 Index and Editor create typed table undo save reopen and numeric lexemes', { tag: '@responsive' }, async ({ page }, info) => {
    const { brand, d } = await setup(page, info); await button(d, 'Nueva tabla').click(); await d.getByLabel('Nombre de la tabla', { exact: true }).fill('Tipos por UI');
    await edit(d, 1, 1, '0.0', 'number'); await edit(d, 1, 2, '-0.0', 'number'); await edit(d, 1, 3, '9007199254740993', 'number'); await tools(d); await button(d, 'Añadir columna').click(); await edit(d, 1, 4, '1.0', 'number'); await edit(d, 2, 1, 'literal\né'); await edit(d, 2, 2, '', 'empty'); await d.getByLabel('Nombre de columna 1', { exact: true }).fill('=Header'); await d.getByLabel('Nombre de columna 1', { exact: true }).press('Tab');
    await edit(d, 2, 3, 'undo target'); await button(d, 'Deshacer tabla').click(); await button(d, 'Rehacer tabla').click(); await cell(d, 2, 3).click(); await expect(d.getByLabel('Valor de la celda', { exact: true })).toHaveValue('undo target'); await button(d, 'Aplicar celda').click();
    const saved = await save(page, d); const text = await download(page, d, info, 'new.json'); const table = observedJson(text); expect(table.rows[0].values.map((x: any) => x?.numberLexeme)).toEqual(['0.0', '-0.0', '9007199254740993', '1.0']); expect(table.rows[1].values[0]).toBe('literal\né'); expect(table.rows[1].values[1]).toBeNull();
    await close(page); await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await d.getByTestId(`dataset-open-${saved.id}`).click(); expect(observedJson(await download(page, d, info, 'reopen.json'))).toEqual(table);
    await close(page); const design = await web(page, 'POST', `/brands/${brand}/studio/designs`, JSON.stringify({ name: 'Dataset entry', document: { schema_version: 1, width: 320, height: 180, pages: [{ id: 'p1', name: 'Page', duration: 5, background: '#ffffff', elements: [] }] } })); expect(design.status).toBe(201); await page.goto(`/studio/designs/${JSON.parse(design.text).design.id}/edit`); await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await d.getByTestId(`dataset-open-${saved.id}`).click(); expect(observedJson(await download(page, d, info, 'editor-entry.json'))).toEqual(table); await auditDialog(page, info); await capture(page, info, 'typed-editor.png');
});

test('E2E27.02 real CSV TSV JSON imports safe faithful downloads windowed cells and invalid file', { tag: '@responsive' }, async ({ page }, info) => {
    const { d, brand } = await setup(page, info); const labels = Array.from({ length: 10 }, (_, i) => i ? `Header${i}` : '=Header'); const matrix = Array.from({ length: 43 }, (_, r) => labels.map((_, c) => `R${r}C${c}`)); const csv = [labels, ...matrix].map(r => r.join(',') + '\r\n').join('');
    await imported(page, d, 'CSV43x10', 'csv', csv); expect(await d.getByTestId('dataset-editor').locator('[data-testid^="dataset-cell-"]').count()).toBeLessThanOrEqual(160); await button(d, 'Filas siguientes').click(); await button(d, 'Filas siguientes').click(); await button(d, 'Columnas siguientes').click(); await expect(cell(d, 43, 10)).toContainText('R42C9'); expect(observedDelimited(await download(page, d, info, 'safe.csv', 'csv'))).toEqual([labels, ...matrix].map(r => r.map(v => "'" + v))); expect(observedDelimited(await download(page, d, info, 'faithful.csv', 'csv', 'text'))).toEqual([labels, ...matrix]);
    await imported(page, d, 'TSV no header', 'tsv', ' =SUM(1,2)\tplain\r\nUnicodeé\tlast\r\n', false); const tsv = observedDelimited(await download(page, d, info, 'faithful.tsv', 'tsv', 'text'), '\t'); expect(tsv.slice(1)).toEqual([[' =SUM(1,2)', 'plain'], ['Unicodeé', 'last']]); expect(observedDelimited(await download(page, d, info, 'safe.tsv', 'tsv'), '\t')).toEqual(tsv.map(r => r.map(v => "'" + v)));
    const fixture = typedFixture(); await imported(page, d, 'JSON lossless', 'json', fixture); await cell(d, 1, 7).click(); await expect(d.getByLabel('Valor de la celda', { exact: true })).toHaveValue(longText); await button(d, 'Cancelar edición de celda').click(); expect(observedJson(await download(page, d, info, 'typed-import.json'))).toEqual(observedJson(fixture));
    await details(d, 'Importar un archivo'); await expect(d.getByText(/CSV y TSV: hasta 25 MB/)).toBeVisible(); await expect(d.getByText(/JSON tipado: hasta 52 MB/)).toBeVisible(); await expect(d.getByText(/Máximo 1.000 filas y 100 columnas/)).toBeVisible(); await d.getByLabel('Archivo de datos', { exact: true }).setInputFiles({ name: 'invalid.json', mimeType: 'application/json', buffer: Buffer.from('{"columns":{},"rows":[]}') }); const invalidError = isolation.expectHttp((page as any).__datasetPolicy, 'POST', `/brands/${brand}/studio/datasets/import`, 422); const invalidResponse = writeResponse(page); await button(d, 'Importar archivo').click(); expect((await invalidResponse).status()).toBe(422); invalidError.asserted = true; await expect(d.getByRole('alert')).toBeVisible(); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('JSON lossless'); expect(observedJson(await download(page, d, info, 'after-invalid.json'))).toEqual(observedJson(fixture)); await capture(page, info, 'imports.png');
});

test('E2E27.03 structural edits literal replace fill conversion atomic preview transpose IDs undo', { tag: '@responsive' }, async ({ page }, info) => {
    const { d } = await setup(page, info); await button(d, 'Nueva tabla').click(); await tools(d); await button(d, 'Añadir fila').click(); await button(d, 'Añadir columna').click(); await edit(d, 1, 1, '10'); await edit(d, 2, 1, 'bad$&'); await edit(d, 3, 1, '30'); await tools(d); await expect(d.getByText(/Se conservan hasta 30 pasos/)).toBeVisible(); await d.getByRole('combobox', { name: 'Columna seleccionada', exact: true }).selectOption({ label: 'Columna 1: Columna 1' }); await d.getByRole('combobox', { name: 'Tipo de conversión', exact: true }).selectOption('number');
    const before = observedJson(await draft(page, d, info, 'before-conversion.json')); await button(d, 'Previsualizar conversión').click(); await expect(d.getByRole('alert')).toContainText('Fila 2:'); await expect(button(d, 'Aplicar conversión')).toBeDisabled(); expect(observedJson(await draft(page, d, info, 'invalid-conversion.json'))).toEqual(before);
    await d.getByLabel('Texto a buscar', { exact: true }).fill('bad$&'); await d.getByLabel('Texto de reemplazo', { exact: true }).fill('20'); await button(d, 'Reemplazar texto').click(); await d.getByLabel('Valor para vacíos', { exact: true }).fill('0'); await d.getByRole('combobox', { name: 'Tipo del relleno', exact: true }).selectOption('text'); await d.getByLabel('Incluir textos vacíos', { exact: true }).check(); await button(d, 'Rellenar vacíos').click(); await button(d, 'Previsualizar conversión').click(); await button(d, 'Aplicar conversión').click(); await d.getByLabel('Nombre de columna 1', { exact: true }).fill('Cuenta$&'); await d.getByLabel('Nombre de columna 1', { exact: true }).press('Tab'); await save(page, d);
    const old = observedJson(await download(page, d, info, 'before-transpose.json')); expect(old.rows.map((r: any) => r.values[0].numberLexeme)).toEqual(['10', '20', '30', '0']);
    await tools(d); await d.getByRole('combobox', { name: 'Fila seleccionada', exact: true }).selectOption({ label: 'Fila 4' }); await button(d, 'Eliminar fila seleccionada').click(); expect(observedJson(await draft(page, d, info, 'removed-row.json')).rows).toEqual(old.rows.slice(0, 3)); await button(d, 'Deshacer tabla').click(); expect(observedJson(await draft(page, d, info, 'undo-remove-row.json'))).toEqual(old);
    await d.getByRole('combobox', { name: 'Columna seleccionada', exact: true }).selectOption({ index: 3 }); await button(d, 'Eliminar columna seleccionada').click(); const removedColumn = observedJson(await draft(page, d, info, 'removed-column.json')); expect(removedColumn.columns).toEqual(old.columns.slice(0, 3)); expect(removedColumn.rows.map((r: any) => r.values)).toEqual(old.rows.map((r: any) => r.values.slice(0, 3))); await button(d, 'Deshacer tabla').click(); expect(observedJson(await draft(page, d, info, 'undo-remove-column.json'))).toEqual(old);
 await button(d, 'Transponer tabla').click(); await button(d, 'Confirmar transposición').click(); await button(d, 'Deshacer tabla').click(); expect(observedJson(await draft(page, d, info, 'undo-transpose.json'))).toEqual(old); await button(d, 'Rehacer tabla').click(); await save(page, d); const next = observedJson(await download(page, d, info, 'transposed.json')); expect(next.columns.map((c: any) => c.label)).toEqual(['Columna', 'Fila 1', 'Fila 2', 'Fila 3', 'Fila 4']); expect(next.rows.map((r: any) => r.values[0])).toEqual(old.columns.map((c: any) => c.label)); expect(next.rows.map((r: any) => r.values.slice(1))).toEqual(old.columns.map((_: any, c: number) => old.rows.map((r: any) => r.values[c]))); expect(next.rows.every((r: any) => !old.rows.some((x: any) => x.id === r.id))).toBe(true); expect(next.columns.every((c: any) => !old.columns.some((x: any) => x.id === c.id))).toBe(true); await capture(page, info, 'structure.png');
    await button(d, 'Duplicar tabla').click(); const duplicate = observedJson(await draft(page, d, info, 'duplicate.json')); expect(duplicate.rows.map((r: any) => r.values)).toEqual(next.rows.map((r: any) => r.values)); expect(duplicate.columns.map((c: any) => c.label)).toEqual(next.columns.map((c: any) => c.label)); expect(duplicate.rows.every((r: any) => !next.rows.some((x: any) => x.id === r.id))).toBe(true); expect(duplicate.columns.every((c: any) => !next.columns.some((x: any) => x.id === c.id))).toBe(true); await save(page, d);
    const maxRows = JSON.stringify({ columns: [{ id: 'only', label: 'Only' }], rows: Array.from({ length: 1000 }, (_, i) => ({ id: `limit${i}`, values: [null] })) }); await imported(page, d, 'Limit1000', 'json', maxRows); await tools(d); await button(d, 'Añadir fila').click(); await expect(d.getByRole('alert')).toContainText(/Máximo 1000 filas/); expect(observedJson(await draft(page, d, info, 'limit-rows-unchanged.json'))).toEqual(observedJson(maxRows));
    const maxColumns = JSON.stringify({ columns: Array.from({ length: 100 }, (_, i) => ({ id: `limit${i}`, label: `C${i}` })), rows: [{ id: 'only', values: Array(100).fill(null) }] }); await imported(page, d, 'Limit100', 'json', maxColumns); await tools(d); await button(d, 'Añadir columna').click(); await expect(d.getByRole('alert')).toContainText(/Máximo 100 columnas/); expect(observedJson(await draft(page, d, info, 'limit-columns-unchanged.json'))).toEqual(observedJson(maxColumns)); await capture(page, info, 'limits.png');

});

test('E2E27.04 second writer409 preserves draft history pagination archive restore and brand isolation', { tag: '@responsive' }, async ({ page }, info) => {
    const { d, brand, user } = await setup(page, info); const fixture = typedFixture(); const saved = await imported(page, d, 'Conflict table', 'json', fixture); await edit(d, 1, 6, 'draft keep'); const server = await web(page, 'PUT', `/brands/${brand}/studio/datasets/${saved.id}`, `{"name":"Server version","revision":1,"table":${fixture}}`); expect(server.status).toBe(200); const conflictError = isolation.expectHttp((page as any).__datasetPolicy, 'POST', `/brands/${brand}/studio/datasets/${saved.id}/import`, 409); const pending = writeResponse(page); await button(d, 'Guardar tabla').click(); expect((await pending).status()).toBe(409); conflictError.asserted = true; await expect(d.getByRole('alert')).toContainText('La tabla tiene una revisión más reciente. Tu borrador se conserva.'); expect(observedJson(await draft(page, d, info, 'conflict-draft.json')).rows[0].values[5]).toBe('draft keep'); expect(observedJson(await download(page, d, info, 'pinned-r1.json'))).toEqual(observedJson(fixture));
    await button(d, 'Recargar versión guardada').click(); await page.getByRole('alertdialog').getByRole('button', { name: 'Seguir editando', exact: true }).click(); expect(observedJson(await draft(page, d, info, 'cancel-reload.json')).rows[0].values[5]).toBe('draft keep'); await button(d, 'Recargar versión guardada').click(); await page.getByRole('alertdialog').getByRole('button', { name: 'Descartar cambios', exact: true }).click(); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('Server version');
    for (let revision = 2; revision < 28; revision++) { const response = await web(page, 'PUT', `/brands/${brand}/studio/datasets/${saved.id}`, `{"name":"History${revision + 1}","revision":${revision},"table":${fixture}}`); expect(response.status).toBe(200); }
    await button(d, 'Historial de revisiones').click(); await button(d, 'Revisiones siguientes').click(); await button(d, 'Ver revisión 1').click(); await expect(d.getByText('Vista de revisión histórica', { exact: true })).toBeVisible(); expect(observedJson(await download(page, d, info, 'history-r1.json'))).toEqual(observedJson(fixture)); await button(d, 'Volver a la revisión actual').click(); await button(d, 'Archivar tabla').click(); await expect(button(d, 'Restaurar tabla')).toBeEnabled(); await button(d, 'Restaurar tabla').click(); await expect(button(d, 'Archivar tabla')).toBeEnabled(); expect(observedJson(await download(page, d, info, 'restored.json'))).toEqual(observedJson(fixture));
    
    for (let i = 0; i < 21; i++) { const response = await web(page, 'POST', `/brands/${brand}/studio/datasets`, JSON.stringify({ name: `Library $& ${i}`, table: { columns: [{ id: 'x', label: 'X' }], rows: [] } })); expect(response.status).toBe(201); }
    await d.getByLabel('Buscar tablas', { exact: true }).fill('Library $& 20'); const searchResponse = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === `/brands/${brand}/studio/datasets`); await button(d, 'Buscar').click(); expect((await searchResponse).status()).toBe(200); await expect(d.locator('[data-testid^="dataset-open-"]')).toHaveCount(1); await expect(d.getByRole('button', { name: 'Library $& 20', exact: true })).toBeVisible();
    await d.getByLabel('Buscar tablas', { exact: true }).fill(''); const allResponse = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === `/brands/${brand}/studio/datasets`); await button(d, 'Buscar').click(); expect((await allResponse).status()).toBe(200); await expect(button(d, 'Tablas siguientes')).toBeEnabled(); await button(d, 'Tablas siguientes').click(); await expect(d.getByRole('navigation', { name: 'Páginas de tablas' })).toContainText(/2\s*\/\s*2/); await expect(d.locator('[data-testid^="dataset-open-"]')).toHaveCount(2);
const other = ownBrand(user.email, `other-${stamp()}`); await close(page); await page.goto(`/studio?brand_id=${other}`); await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await expect(d.getByTestId(`dataset-open-${saved.id}`)).toHaveCount(0); const ownerError = isolation.expectHttp((page as any).__datasetPolicy, 'GET', `/brands/${other}/studio/datasets/${saved.id}`, 404); expect((await web(page, 'GET', `/brands/${other}/studio/datasets/${saved.id}`)).status).toBe(404); ownerError.asserted = true; await capture(page, info, 'brand-isolation.png');
});

test('E2E27.05 delayed real reads writes close selection brand discard keyboard and focus', { tag: '@responsive' }, async ({ page }, info) => {
    const { d, brand, user } = await setup(page, info); const fixture = typedFixture();
    const first = await imported(page, d, 'Late A', 'json', fixture); const second = await imported(page, d, 'Late B', 'json', fixture); const observations: any[] = [];
    const read = await delayedRealResponse(page, new RegExp(`/brands/${brand}/studio/datasets/${first.id}/table(?:\\?|$)`), 'GET');
    await d.getByTestId(`dataset-open-${first.id}`).click(); expect((await read.real).status).toBe(200); await expect(d.getByRole('status').filter({ hasText: 'Cargando tabla' }).first()).toBeVisible(); await close(page); read.confirmCancellation();
    await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await d.getByTestId(`dataset-open-${second.id}`).click(); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('Late B'); await read.release(); observations.push(...read.observations); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('Late B'); expect(observedJson(await download(page, d, info, 'late-read-B.json'))).toEqual(observedJson(fixture));
    await d.getByTestId(`dataset-open-${first.id}`).click(); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('Late A'); await edit(d, 1, 6, 'write committed late');
    const write = await delayedRealResponse(page, new RegExp(`/brands/${brand}/studio/datasets/${first.id}/import$`), 'POST'); await button(d, 'Guardar tabla').click(); expect((await write.real).status).toBe(200); await close(page, true);
    await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await expect(d.getByTestId(`dataset-open-${second.id}`)).toBeDisabled(); await expect(d.getByTestId('dataset-editor')).toHaveCount(0);
    const navigation = page.url(); await write.release(); observations.push(...write.observations); await expect(d.getByTestId(`dataset-open-${second.id}`)).toBeEnabled(); await expect(d.getByTestId('dataset-editor')).toHaveCount(0); expect(page.url()).toBe(navigation); await expect(d.getByRole('status').filter({ hasText: /Revisión \d+ guardada/ })).toHaveCount(0);
    await d.getByTestId(`dataset-open-${second.id}`).click(); await expect(d.getByLabel('Nombre de la tabla', { exact: true })).toHaveValue('Late B'); expect(observedJson(await download(page, d, info, 'late-write-B.json'))).toEqual(observedJson(fixture)); const committed = await web(page, 'GET', `/brands/${brand}/studio/datasets/${first.id}/table?revision=2`); expect(committed.status).toBe(200); expect(observedJson(committed.text).rows[0].values[5]).toBe('write committed late');
    await edit(d, 1, 6, 'draft cancellation'); await d.getByRole('button', { name: 'Cerrar tablas de datos', exact: true }).click(); await page.getByRole('alertdialog').getByRole('button', { name: 'Seguir editando', exact: true }).click(); expect(observedJson(await draft(page, d, info, 'cancel-close.json')).rows[0].values[5]).toBe('draft cancellation'); await close(page, true);
    await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); const other = ownBrand(user.email, `late-brand-${stamp()}`); const brandRead = await delayedRealResponse(page, new RegExp(`/brands/${brand}/studio/datasets/${first.id}/table(?:\\?|$)`), 'GET'); await d.getByTestId(`dataset-open-${first.id}`).click(); expect((await brandRead.real).status).toBe(200);
    await page.goto(`/studio?brand_id=${other}`); brandRead.confirmCancellation(); await page.getByRole('button', { name: 'Tablas de datos', exact: true }).click(); await brandRead.release(); observations.push(...brandRead.observations); await expect(d.getByTestId(`dataset-open-${first.id}`)).toHaveCount(0); await expect(d.getByTestId('dataset-editor')).toHaveCount(0); expect(page.url()).toContain(`brand_id=${other}`);
    await button(d, 'Nueva tabla').click(); const table = d.getByRole('table', { name: 'Datos de la tabla', exact: true }); await expect(table).toBeVisible(); expect(await table.locator('thead th[scope="col"]').count()).toBe(4); expect(await table.locator('tbody th[scope="row"]').count()).toBe(3);
    const target = cell(d, 1, 1); await target.focus(); await target.press('Enter'); const type = d.getByRole('combobox', { name: 'Tipo de la celda', exact: true }); await expect(type).toBeFocused(); expect(await type.evaluate(el => parseFloat(getComputedStyle(el).outlineWidth))).toBeGreaterThan(0); await type.selectOption('text'); await type.press('Tab'); const textarea = d.getByLabel('Valor de la celda', { exact: true }); await expect(textarea).toBeFocused(); await page.keyboard.type('keyboard value'); await textarea.press('Tab'); await expect(button(d, 'Aplicar celda')).toBeFocused(); await button(d, 'Aplicar celda').press('Enter'); await expect(cell(d, 1, 1)).toContainText('keyboard value');
    expect(await d.locator('input,textarea,select,[data-testid^="dataset-cell-"]').count()).toBeLessThan(400); const box = await d.boundingBox(); expect(box!.x).toBeGreaterThanOrEqual(0); expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width); await capture(page, info, 'late-close-brand-keyboard.png'); fs.writeFileSync(info.outputPath('delayed-real-responses.json'), JSON.stringify(observations, null, 2)); await close(page, true);
});

export { observedJson, observedDelimited };
