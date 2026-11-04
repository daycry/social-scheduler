# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: studio.spec.ts >> Studio: undo, page ordering and portable JPEG/WebP/SVG/PDF exports retain the design
- Location: tests\e2e\studio.spec.ts:103:1

# Error details

```
Error: expect(received).toEqual(expected) // deep equality

- Expected  - 1
+ Received  + 3

- Array []
+ Array [
+   "console: Failed to load resource: net::ERR_CONNECTION_REFUSED",
+ ]
```

# Page snapshot

```yaml
- generic [active] [ref=f1e1]:
  - generic [ref=f1e3]:
    - link "Saltar al contenido" [ref=f1e4] [cursor=pointer]:
      - /url: "#contenido"
    - banner [ref=f1e5]:
      - generic [ref=f1e6]:
        - button "Abrir menú" [ref=f1e7] [cursor=pointer]
        - link "Social Scheduler, inicio" [ref=f1e10] [cursor=pointer]:
          - /url: http://localhost:8080/dashboard?brand_id=21
        - generic [ref=f1e16]:
          - button "Cambiar de marca" [ref=f1e19] [cursor=pointer]:
            - generic [aria-hidden] [ref=f1e20]: E
            - generic [ref=f1e21]: e2e-composer-studio-pages-mw199h50-mobile-360
          - link "Avisos" [ref=f1e24] [cursor=pointer]:
            - /url: http://localhost:8080/notifications
          - button "Menú de usuario" [ref=f1e29] [cursor=pointer]:
            - generic [ref=f1e30]: ES
    - main [ref=f1e33]:
      - generic [ref=f1e34]:
        - generic [ref=f1e35]:
          - link "Volver al estudio" [ref=f1e36] [cursor=pointer]:
            - /url: http://localhost:8080/studio?brand_id=21
          - generic [ref=f1e39]:
            - generic [ref=f1e40]: Nombre del diseño
            - textbox "Nombre del diseño" [ref=f1e41]: Diseño sin título
            - generic [ref=f1e42]:
              - generic [ref=f1e43]: e2e-composer-studio-pages-mw199h50-mobile-360
              - status [ref=f1e44]: Guardado
              - generic [ref=f1e45]: Revisión 2
          - generic [ref=f1e46]:
            - button "Deshacer" [ref=f1e47] [cursor=pointer]: ↶
            - button "Rehacer" [disabled] [ref=f1e48]: ↷
            - button "Guardar ahora" [ref=f1e49] [cursor=pointer]
            - button "Exportar" [ref=f1e50] [cursor=pointer]
            - button "Usar en publicación" [ref=f1e51] [cursor=pointer]
        - generic [ref=f1e52]:
          - complementary "Herramientas de diseño" [ref=f1e53]:
            - generic [ref=f1e54]:
              - button "Elementos" [ref=f1e55] [cursor=pointer]
              - button "Recursos" [ref=f1e56] [cursor=pointer]
            - generic [ref=f1e57]:
              - button "Añadir texto" [ref=f1e58] [cursor=pointer]:
                - generic [aria-hidden] [ref=f1e59]: T
                - text: Añadir texto
              - generic [ref=f1e60]:
                - heading "Formas" [level=2] [ref=f1e61]
                - generic [ref=f1e62]:
                  - button "Añadir rectángulo" [ref=f1e63] [cursor=pointer]: ▭
                  - button "Añadir elipse" [ref=f1e64] [cursor=pointer]: ◯
                  - button "Añadir triángulo" [ref=f1e65] [cursor=pointer]: △
                  - button "Añadir línea" [ref=f1e66] [cursor=pointer]: ╱
                  - button "Añadir trazado" [ref=f1e67] [cursor=pointer]: 〰
              - button "Dibujo libre" [ref=f1e69] [cursor=pointer]
              - generic [ref=f1e70]:
                - heading "Colores de e2e-composer-studio-pages-mw199h50-mobile-360" [level=3] [ref=f1e71]
                - generic [ref=f1e72]:
                  - 'button "Color de marca #7c3aed" [disabled] [ref=f1e73]'
                  - 'button "Color de marca #111827" [disabled] [ref=f1e74]'
                  - 'button "Color de marca #ffffff" [disabled] [ref=f1e75]'
                - paragraph [ref=f1e76]: Selecciona un elemento para aplicarle un color.
          - main "Editor de diseño" [ref=f1e77]:
            - generic [ref=f1e78]:
              - generic [ref=f1e79]: Página 1 · copia
              - generic [ref=f1e80]:
                - button "Duplicar elemento" [disabled] [ref=f1e81]: Duplicar
                - button "Eliminar elemento" [disabled] [ref=f1e82]: Eliminar
                - button "Bloquear elemento" [disabled] [ref=f1e83]: Bloquear
            - generic "Lienzo de diseño. Usa el panel Capas para seleccionar y editar con teclado." [ref=f1e88]
            - generic [ref=f1e89]:
              - generic [ref=f1e90]: 1080 × 1080 px
              - generic [ref=f1e91]:
                - button "Reducir zoom" [ref=f1e92] [cursor=pointer]: −
                - generic [ref=f1e93]: Zoom
                - spinbutton "Zoom" [ref=f1e94]: "27"
                - generic [ref=f1e95]: "%"
                - button "Aumentar zoom" [ref=f1e96] [cursor=pointer]: +
                - button "Ajustar" [ref=f1e97] [cursor=pointer]
            - region "Páginas del diseño" [ref=f1e98]:
              - generic [ref=f1e99]:
                - heading "Páginas 2/100" [level=2] [ref=f1e100]
                - generic [ref=f1e101]:
                  - button "Añadir página" [ref=f1e102] [cursor=pointer]
                  - button "Duplicar página" [ref=f1e103] [cursor=pointer]
                  - button "Eliminar página" [ref=f1e104] [cursor=pointer]
              - generic [ref=f1e105]:
                - button "01 Página 1 · copia" [pressed] [ref=f1e106] [cursor=pointer]:
                  - generic [ref=f1e107]: "01"
                  - generic [ref=f1e108]: Página 1 · copia
                - button "02 Página 1" [ref=f1e109] [cursor=pointer]:
                  - generic [ref=f1e110]: "02"
                  - generic [ref=f1e111]: Página 1
              - generic [ref=f1e112]:
                - button "← Mover página" [disabled] [ref=f1e113]
                - button "Mover página →" [ref=f1e114] [cursor=pointer]
          - complementary "Panel de edición" [ref=f1e115]:
            - generic [ref=f1e116]:
              - button "Capas" [ref=f1e117] [cursor=pointer]
              - button "Propiedades" [ref=f1e118] [cursor=pointer]
              - button "Página" [ref=f1e119] [cursor=pointer]
            - region "Propiedades del elemento" [ref=f1e120]:
              - heading "Propiedades" [level=2] [ref=f1e121]
              - paragraph [ref=f1e122]: Selecciona un elemento en el lienzo o en Capas para editarlo.
  - dialog "Exportar diseño" [ref=f1e123]:
    - generic [ref=f1e124]:
      - banner [ref=f1e125]:
        - heading "Exportar diseño" [level=2] [ref=f1e126]
        - button "Cerrar exportar diseño" [ref=f1e127] [cursor=pointer]: ×
      - generic [ref=f1e128]:
        - paragraph [ref=f1e129]: Exporta las páginas elegidas. Los elementos de vídeo se incluyen como fotograma estático.
        - generic [ref=f1e130]:
          - text: Formato
          - combobox "Formato" [ref=f1e131]:
            - option "PNG"
            - option "JPG"
            - option "WebP"
            - option "SVG"
            - option "PDF" [selected]
        - group [ref=f1e132]:
          - generic [ref=f1e133]:
            - generic [ref=f1e134]:
              - text: Escala
              - combobox "Escala" [ref=f1e135]:
                - option "0,5 ×"
                - option "1 ×" [selected]
                - option "2 ×"
                - option "3 ×"
                - option "4 ×"
            - generic [ref=f1e136]:
              - text: Calidad
              - spinbutton "Calidad" [ref=f1e137]: "0.94"
          - paragraph [ref=f1e138]: 1080 × 1080 px · un fotograma por página en PDF
          - generic [ref=f1e139]:
            - checkbox "Fondo transparente" [ref=f1e140]
            - text: Fondo transparente
          - generic [ref=f1e141]:
            - heading "Páginas" [level=3] [ref=f1e142]
            - generic [ref=f1e143]:
              - generic [ref=f1e144]:
                - checkbox "1. Página 1 · copia" [checked] [ref=f1e145]
                - text: 1. Página 1 · copia
              - generic [ref=f1e146]:
                - checkbox "2. Página 1" [checked] [ref=f1e147]
                - text: 2. Página 1
        - status [ref=f1e148]: 1 archivo preparado.
        - generic [ref=f1e149]:
          - button "Cerrar exportación" [ref=f1e150] [cursor=pointer]
          - button "Descargar" [ref=f1e151] [cursor=pointer]
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