# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: brand-reporting.spec.ts >> Ads and reports retain the selected brand across navigation and report creation
- Location: tests\e2e\brand-reporting.spec.ts:4:1

# Error details

```
Error: expect(locator).toHaveValue(expected) failed

Locator: getByLabel('Marca', { exact: true })
Expected: "3"
Timeout: 10000ms
Error: element(s) not found

Call log:
  - Expect "toHaveValue" getByLabel('Marca', { exact: true }) with timeout 10000ms
  - waiting for getByLabel('Marca', { exact: true })

```

```yaml
- link "Saltar al contenido":
  - /url: "#contenido"
- banner:
  - link "Social Scheduler, inicio":
    - /url: http://localhost:8080/dashboard?brand_id=3
    - text: Social Scheduler
  - navigation "Módulos":
    - link "Estudio":
      - /url: http://localhost:8080/studio?brand_id=3
    - link "Analítica":
      - /url: http://localhost:8080/dashboard?brand_id=3
    - link "Planificación":
      - /url: http://localhost:8080/calendar?brand_id=3
    - link "SmartLinks":
      - /url: http://localhost:8080/smartlinks?brand_id=3
    - link "Inbox":
      - /url: http://localhost:8080/inbox?brand_id=3
    - link "Informes":
      - /url: http://localhost:8080/reports?brand_id=3
    - link "Conexiones":
      - /url: http://localhost:8080/brands/3/connections
  - button "Cambiar de marca": e2e-composer-Z-brand-context-mw195n6n
  - link "Avisos":
    - /url: http://localhost:8080/notifications
  - button "Menú de usuario": ES
- complementary "Navegación del módulo":
  - paragraph: Informes
  - navigation:
    - link "Informes":
      - /url: http://localhost:8080/reports?brand_id=3
  - button "Contraer barra lateral": Contraer
- heading "Informes" [level=1]
- main:
  - text: Marca
  - combobox "Marca":
    - option "e2e-composer-A-brand-context-mw195mtm"
    - option "e2e-composer-Z-brand-context-mw195n6n" [selected]
  - text: Desde
  - textbox "Desde": 2026-10-04
  - text: Hasta
  - textbox "Hasta": 2026-11-02
  - text: Comparar con
  - combobox "Comparar con":
    - option "Sin comparación" [selected]
    - option "Periodo anterior"
    - option "Mismo periodo del año anterior"
  - group "Formato":
    - text: Formato
    - checkbox "PDF" [checked]
    - text: PDF
    - checkbox "CSV" [checked]
    - text: CSV
  - button "Generar informe"
  - heading "Informes recientes" [level=2]
  - paragraph: Aún no has generado informes.
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
> 22 |         await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
     |                                                                 ^ Error: expect(locator).toHaveValue(expected) failed
  23 |         await page.getByLabel('PDF', { exact: true }).uncheck();
  24 |         await page.getByRole('button', { name: 'Generar informe', exact: true }).click();
  25 |         await expect(page).toHaveURL(new RegExp(`/reports\\?brand_id=${z.id}$`));
  26 |         await expect(page.getByLabel('Marca', { exact: true })).toHaveValue(String(z.id));
  27 |         await expect(page.locator('[data-report]').first()).toContainText(z.name);
  28 |         await expectNoOverflow(page);
  29 |         expect(errors.errors).toEqual([]);
  30 |     } finally { cleanComposerBrands(user.email); }
  31 | });
  32 | 
```