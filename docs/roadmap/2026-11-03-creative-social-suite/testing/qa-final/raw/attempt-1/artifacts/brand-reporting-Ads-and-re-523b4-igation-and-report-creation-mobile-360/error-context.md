# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: brand-reporting.spec.ts >> Ads and reports retain the selected brand across navigation and report creation
- Location: tests\e2e\brand-reporting.spec.ts:4:1

# Error details

```
Error: expect(received).toEqual(expected) // deep equality

- Expected  - 1
+ Received  + 4

- Array []
+ Array [
+   "console: Failed to load resource: net::ERR_CONNECTION_REFUSED",
+   "console: Failed to load resource: net::ERR_CONNECTION_REFUSED",
+ ]
```

# Page snapshot

```yaml
- generic [ref=f2e3]:
  - link "Saltar al contenido" [ref=f2e4] [cursor=pointer]:
    - /url: "#contenido"
  - banner [ref=f2e5]:
    - generic [ref=f2e6]:
      - button "Abrir menú" [ref=f2e7] [cursor=pointer]
      - link "Social Scheduler, inicio" [ref=f2e10] [cursor=pointer]:
        - /url: http://localhost:8080/dashboard?brand_id=17
      - generic [ref=f2e16]:
        - button "Cambiar de marca" [ref=f2e19] [cursor=pointer]:
          - generic [aria-hidden] [ref=f2e20]: E
          - generic [ref=f2e21]: e2e-composer-Z-brand-context-mw197ry5
        - link "Avisos" [ref=f2e24] [cursor=pointer]:
          - /url: http://localhost:8080/notifications
        - button "Menú de usuario" [ref=f2e29] [cursor=pointer]:
          - generic [ref=f2e30]: ES
  - generic [ref=f2e32]:
    - heading "Informes" [level=1] [ref=f2e35]
    - main [ref=f2e36]:
      - generic [ref=f2e38]:
        - generic [ref=f2e39]:
          - generic [ref=f2e40]:
            - generic [ref=f2e41]:
              - generic [ref=f2e42]: Marca
              - combobox "Marca" [ref=f2e43]:
                - option "e2e-composer-A-brand-context-mw197rln"
                - option "e2e-composer-Z-brand-context-mw197ry5" [selected]
            - generic [ref=f2e44]:
              - generic [ref=f2e45]: Desde
              - textbox "Desde" [ref=f2e46]: 2026-10-04
            - generic [ref=f2e47]:
              - generic [ref=f2e48]: Hasta
              - textbox "Hasta" [ref=f2e49]: 2026-11-02
            - generic [ref=f2e50]:
              - generic [ref=f2e51]: Comparar con
              - combobox "Comparar con" [ref=f2e52]:
                - option "Sin comparación" [selected]
                - option "Periodo anterior"
                - option "Mismo periodo del año anterior"
            - group "Formato" [ref=f2e53]:
              - generic [ref=f2e55]:
                - checkbox "PDF" [ref=f2e56]
                - text: PDF
              - generic [ref=f2e57]:
                - checkbox "CSV" [checked] [ref=f2e58]
                - text: CSV
            - button "Generar informe" [ref=f2e59] [cursor=pointer]
          - paragraph [ref=f2e60]: Informe en cola. Se generara en unos instantes.
        - generic [ref=f2e61]:
          - heading "Informes recientes" [level=2] [ref=f2e62]
          - list [ref=f2e63]:
            - listitem [ref=f2e64]:
              - generic [ref=f2e65]:
                - paragraph [ref=f2e66]: e2e-composer-Z-brand-context-mw197ry5 · CSV
                - paragraph [ref=f2e67]: 2026-10-04 - 2026-11-02
              - generic [ref=f2e68]: En cola
```

# Test source

```ts
  1  | import { expect, test } from '@playwright/test';
  2  | import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedComposerBrand, stamp, watch } from './helpers';
  3  | 
  4  | test('Ads and reports retain the selected brand across navigation and report creation', async ({ page }, info) => {
  5  |     const user = e2eUser(info.project.name);
  6  |     cleanComposerBrands(user.email);
  7  |     const a = seedComposerBrand(user.email, `A-brand-context-${stamp()}`);
  8  |     const z = seedComposerBrand(user.email, `Z-brand-context-${stamp()}`, 'America/Los_Angeles');
  9  |     const errors = watch(page);
  10 |     try {
  11 |         await loginAs(page, user);
  12 |         await page.goto(`/ads?brand_id=${a.id}&days=7`);
  13 |         await expect(page.getByRole('heading', { level: 1 })).toContainText(a.name);
  14 |         await page.getByRole('button', { name: 'Cambiar de marca', exact: true }).click();
  15 |         await page.getByRole('button', { name: z.name, exact: true }).click();
  16 |         await expect(page).toHaveURL(new RegExp(`brand_id=${z.id}(?:&|$)`));
  17 |         await expect(page.getByRole('heading', { level: 1 })).toContainText(z.name);
  18 |         await expectNoOverflow(page);
  19 | 
  20 |         // No explicit query: the current shell/session brand must survive into Reporting.
  21 |         await page.goto('/reports');
  22 |         await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
  23 |         await page.getByLabel('PDF', { exact: true }).uncheck();
  24 |         await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
  25 |         await expect(page).toHaveURL(new RegExp(`/reports\\?brand_id=${z.id}$`));
  26 |         await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
  27 |         await expect(page.locator('[data-report]').first()).toContainText(z.name);
  28 |         await expectNoOverflow(page);
> 29 |         expect(errors.errors).toEqual([]);
     |                               ^ Error: expect(received).toEqual(expected) // deep equality
  30 |     } finally { cleanComposerBrands(user.email); }
  31 | });
  32 | 
```