# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: studio.spec.ts >> Studio: design persists, exports real PNG and starts a publication in its brand
- Location: tests\e2e\studio.spec.ts:55:1

# Error details

```
Error: expect(locator).toHaveText(expected) failed

Locator:  getByTestId('studio-save-status')
Expected: "Guardado"
Received: "No se ha podido guardar"
Timeout:  10000ms

Call log:
  - Expect "toHaveText" getByTestId('studio-save-status') with timeout 10000ms
  - waiting for getByTestId('studio-save-status')
    - locator resolved to <span role="status" data-v-f99b3355="" class="text-amber-700" data-testid="studio-save-status">Guardando…</span>
    - unexpected value "Guardando…"
    23 × locator resolved to <span role="status" data-v-f99b3355="" class="text-amber-700" data-testid="studio-save-status">No se ha podido guardar</span>
       - unexpected value "No se ha podido guardar"

```

```yaml
- status: No se ha podido guardar
```

```
Error: expect(received).toEqual(expected) // deep equality

- Expected  - 1
+ Received  + 3

- Array []
+ Array [
+   "console: Failed to load resource: net::ERR_CONNECTION_REFUSED",
+ ]
```

# Test source

```ts
  1   | import fs from 'node:fs';
  2   | import { PDFDocument } from 'pdf-lib';
  3   | import { expect, test, type Page } from '@playwright/test';
  4   | import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp } from './helpers';
  5   | 
  6   | const browserErrors = new WeakMap<Page, string[]>();
  7   | test.beforeEach(async ({ page }, info) => {
  8   |     const errors: string[] = [];
  9   |     browserErrors.set(page, errors);
  10  |     page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  11  |     page.on('console', message => {
  12  |         if (message.type() !== 'error') return;
  13  |         // This test deliberately produces one handled optimistic-write conflict at this endpoint.
  14  |         const expectedConflict = info.title.includes('a stale revision')
  15  |             && /status of 409/.test(message.text())
  16  |             && /\/studio\/designs\/\d+$/.test(new URL(message.location().url || 'http://localhost').pathname);
  17  |         if (!expectedConflict) errors.push(`console: ${message.text()}`);
  18  |     });
  19  | });
  20  | test.afterEach(async ({ page }) => {
  21  |     await expectNoOverflow(page);
> 22  |     expect(browserErrors.get(page) ?? []).toEqual([]);
      |                                           ^ Error: expect(received).toEqual(expected) // deep equality
  23  | });
  24  | 
  25  | test('Studio: fractional exports round to 21 pixels and register PNG JPEG WebP in the brand library', async ({ page }, info) => {
  26  |     const user = e2eUser(info.project.name);
  27  |     cleanComposerBrands(user.email);
  28  |     const brand = seedComposerBrand(user.email, `studio-fractional-${stamp()}-${info.project.name}`);
  29  |     try {
  30  |         await loginAs(page, user);
  31  |         await page.goto(`/studio?brand_id=${brand.id}`);
  32  |         await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
  33  |         const creation = page.getByRole('dialog', { name: 'Nuevo diseño' });
  34  |         await creation.getByLabel('Ancho', { exact: true }).fill('41');
  35  |         await creation.getByLabel('Alto', { exact: true }).fill('41');
  36  |         await creation.getByRole('button', { name: 'Crear diseño', exact: true }).click();
  37  |         await page.getByRole('button', { name: 'Exportar', exact: true }).click();
  38  |         const dialog = page.getByRole('dialog', { name: 'Exportar diseño' });
  39  |         await dialog.getByLabel('Escala', { exact: true }).selectOption('0.5');
  40  |         await expect(dialog.getByText('21 × 21 px', { exact: true })).toBeVisible();
  41  |         for (const format of ['png', 'jpeg', 'webp']) {
  42  |             await dialog.getByLabel('Formato', { exact: true }).selectOption(format);
  43  |             const registration = page.waitForResponse(response => /\/studio\/designs\/\d+\/exports$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
  44  |             await dialog.getByRole('button', { name: 'Guardar en biblioteca', exact: true }).click();
  45  |             const response = await registration;
  46  |             expect(response.status()).toBe(201);
  47  |             const result = await response.json();
  48  |             expect(result.export.format).toBe(format);
  49  |             expect(result.export.media_ids).toHaveLength(1);
  50  |             await expect(dialog.getByRole('status')).toHaveText('Exportación guardada en la biblioteca de esta marca.');
  51  |         }
  52  |     } finally { cleanComposerBrands(user.email); }
  53  | });
  54  | 
  55  | test('Studio: design persists, exports real PNG and starts a publication in its brand', async ({ page }, info) => {
  56  |     const user = e2eUser(info.project.name);
  57  |     cleanComposerBrands(user.email);
  58  |     const brand = seedComposerBrand(user.email, `studio-${stamp()}-${info.project.name}`);
  59  |     try {
  60  |         await loginAs(page, user);
  61  |         await page.goto(`/studio?brand_id=${brand.id}`);
  62  |         await expect(page.getByRole('heading', { name: 'Estudio creativo', exact: true })).toBeVisible();
  63  |         await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
  64  |         const dialog = page.getByRole('dialog', { name: 'Nuevo diseño' });
  65  |         await dialog.getByLabel('Nombre', { exact: true }).fill('Lanzamiento de la marca');
  66  |         await dialog.getByRole('button', { name: 'Crear diseño', exact: true }).click();
  67  |         await expect(page).toHaveURL(/\/studio\/designs\/\d+\/edit$/);
  68  |         await expect(page.getByLabel('Nombre del diseño', { exact: true })).toHaveValue('Lanzamiento de la marca');
  69  |         await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
  70  |         await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
  71  |         await page.getByLabel('Contenido del texto', { exact: true }).fill('Hola, nueva colección');
  72  |         await page.getByRole('button', { name: 'Guardar ahora', exact: true }).click();
  73  |         await expect(page.getByTestId('studio-save-status')).toHaveText('Guardado');
  74  |         await page.reload();
  75  |         await expect(page.getByTestId('studio-layer')).toHaveCount(1);
  76  |         await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
  77  |         expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  78  |         await page.screenshot({ path: info.outputPath('studio-editor.png'), fullPage: true });
  79  | 
  80  |         await page.getByRole('button', { name: 'Exportar', exact: true }).click();
  81  |         const exporter = page.getByRole('dialog', { name: 'Exportar diseño' });
  82  |         await exporter.getByLabel('Formato', { exact: true }).selectOption('png');
  83  |         const downloaded = page.waitForEvent('download');
  84  |         await exporter.getByRole('button', { name: 'Descargar', exact: true }).click();
  85  |         const file = await downloaded;
  86  |         const output = info.outputPath('studio-export.png');
  87  |         await file.saveAs(output);
  88  |         const bytes = fs.readFileSync(output);
  89  |         expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
  90  |         expect(bytes.readUInt32BE(16)).toBe(1080);
  91  |         expect(bytes.readUInt32BE(20)).toBe(1080);
  92  |         await page.getByRole('button', { name: 'Cerrar exportación', exact: true }).click();
  93  | 
  94  |         await page.getByRole('button', { name: 'Usar en publicación', exact: true }).click();
  95  |         await expect(page).toHaveURL(/\/posts\/\d+\/edit$/);
  96  |         await expect(page.getByRole('button', { name: 'Quitar', exact: true })).toHaveCount(1);
  97  |         await expect(page.locator('aside img')).toBeVisible();
  98  |     } finally {
  99  |         cleanComposerBrands(user.email);
  100 |     }
  101 | });
  102 | 
  103 | test('Studio: undo, page ordering and portable JPEG/WebP/SVG/PDF exports retain the design', async ({ page }, info) => {
  104 |     const user = e2eUser(info.project.name);
  105 |     cleanComposerBrands(user.email);
  106 |     const brand = seedComposerBrand(user.email, `studio-pages-${stamp()}-${info.project.name}`);
  107 |     try {
  108 |         await loginAs(page, user);
  109 |         await page.goto(`/studio?brand_id=${brand.id}`);
  110 |         await page.getByRole('button', { name: 'Nuevo diseño', exact: true }).click();
  111 |         await page.getByRole('dialog', { name: 'Nuevo diseño' }).getByRole('button', { name: 'Crear diseño', exact: true }).click();
  112 |         await expect(page.getByRole('button', { name: 'Añadir texto', exact: true })).toBeEnabled();
  113 |         await page.getByRole('button', { name: 'Añadir rectángulo', exact: true }).click();
  114 |         await page.getByRole('button', { name: 'Añadir texto', exact: true }).click();
  115 |         await expect(page.getByTestId('studio-layer')).toHaveCount(2);
  116 |         await page.getByRole('button', { name: 'Deshacer', exact: true }).click();
  117 |         await expect(page.getByTestId('studio-layer')).toHaveCount(1);
  118 |         await page.getByRole('button', { name: 'Rehacer', exact: true }).click();
  119 |         await expect(page.getByTestId('studio-layer')).toHaveCount(2);
  120 |         await page.getByTestId('studio-layer').first().getByRole('button').first().click();
  121 |         await page.getByRole('button', { name: 'Propiedades', exact: true }).click();
  122 |         await page.getByLabel('Contenido del texto', { exact: true }).fill('Página original');
```