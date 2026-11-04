# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: metrics-quality.spec.ts >> Metrics: zero activity retains partial coverage, stale dates and suppressed unequal comparison
- Location: tests\e2e\metrics-quality.spec.ts:4:1

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
- generic [ref=f2e3]:
  - link "Saltar al contenido" [ref=f2e4] [cursor=pointer]:
    - /url: "#contenido"
  - banner [ref=f2e5]:
    - generic [ref=f2e6]:
      - button "Abrir menú" [ref=f2e7] [cursor=pointer]
      - link "Social Scheduler, inicio" [ref=f2e10] [cursor=pointer]:
        - /url: http://localhost:8080/dashboard?brand_id=11
      - generic [ref=f2e16]:
        - button "Cambiar de marca" [ref=f2e19] [cursor=pointer]:
          - generic [aria-hidden] [ref=f2e20]: E
          - generic [ref=f2e21]: e2e-composer-metric-quality-mw1971uy-mobile
        - link "Avisos" [ref=f2e24] [cursor=pointer]:
          - /url: http://localhost:8080/notifications
        - button "Menú de usuario" [ref=f2e29] [cursor=pointer]:
          - generic [ref=f2e30]: ES
  - generic [ref=f2e32]:
    - heading "Instagram · e2e-composer-metric-quality-mw1971uy-mobile" [level=1] [ref=f2e35]
    - main [ref=f2e36]:
      - generic [ref=f2e37]:
        - generic [ref=f2e38]:
          - generic [ref=f2e39]:
            - generic [ref=f2e40]: Periodo principal
            - generic [ref=f2e41]:
              - textbox "Desde" [ref=f2e42]: 2026-10-24
              - generic [aria-hidden] [ref=f2e43]: –
              - textbox "Hasta" [ref=f2e44]: 2026-11-02
          - group "Periodos rápidos" [ref=f2e45]:
            - button "7 días" [ref=f2e46] [cursor=pointer]
            - button "30 días" [ref=f2e47] [cursor=pointer]
            - button "90 días" [ref=f2e48] [cursor=pointer]
          - generic [ref=f2e49]:
            - generic [ref=f2e50]: Comparar con
            - combobox "Comparar con" [ref=f2e51]:
              - option "Sin comparación"
              - option "Periodo anterior" [selected]
              - option "Mismo periodo del año anterior"
        - navigation "Redes" [ref=f2e52]:
          - link "Resumen" [ref=f2e53] [cursor=pointer]:
            - /url: http://localhost:8080/dashboard?brand_id=11&from=2026-10-24&to=2026-11-02&compare=previous
          - link "Instagram" [ref=f2e54] [cursor=pointer]:
            - /url: http://localhost:8080/dashboard/instagram?brand_id=11&from=2026-10-24&to=2026-11-02&compare=previous
          - link "Facebook" [ref=f2e60] [cursor=pointer]:
            - /url: http://localhost:8080/dashboard/facebook?brand_id=11&from=2026-10-24&to=2026-11-02&compare=previous
          - link "Conectar YouTube" [ref=f2e64] [cursor=pointer]:
            - /url: http://localhost:8080/brands/11/connections
            - text: YouTube
            - generic [aria-hidden] [ref=f2e69]: +
          - link "Conectar TikTok" [ref=f2e70] [cursor=pointer]:
            - /url: http://localhost:8080/brands/11/connections
            - text: TikTok
            - generic [aria-hidden] [ref=f2e74]: +
          - link "Conectar Bluesky" [ref=f2e75] [cursor=pointer]:
            - /url: http://localhost:8080/brands/11/connections
            - text: Bluesky
            - generic [aria-hidden] [ref=f2e79]: +
          - link "Conectar Threads" [ref=f2e80] [cursor=pointer]:
            - /url: http://localhost:8080/brands/11/connections
            - text: Threads
            - generic [aria-hidden] [ref=f2e84]: +
        - generic [ref=f2e85]:
          - heading "Crecimiento" [level=2] [ref=f2e86]
          - generic [ref=f2e87]:
            - paragraph [ref=f2e88]: Datos parciales
            - paragraph [ref=f2e89]: "3 de 10 observaciones de cuenta y día · 7 sin dato · Último día con datos: 01/11/2026 · Faltan datos hasta el 02/11/2026"
          - generic [ref=f2e90]:
            - generic [ref=f2e91]:
              - paragraph [ref=f2e92]: Seguidores (último dato conocido)
              - paragraph [ref=f2e93]: "50"
            - generic [ref=f2e94]:
              - paragraph [ref=f2e95]: Seguidores (primer dato conocido)
              - paragraph [ref=f2e96]: "50"
            - generic [ref=f2e97]:
              - paragraph [ref=f2e98]: Crecimiento
              - paragraph [ref=f2e99]: "0"
            - generic [ref=f2e100]:
              - paragraph [ref=f2e101]: Crecimiento %
              - paragraph [ref=f2e102]: 0 %
          - paragraph [ref=f2e103]: "Periodo de comparación: No disponible (No disponible)"
          - img "Gráfico de evolución" [ref=f2e107]:
            - generic [ref=f2e108]: "0"
            - generic [ref=f2e110]: "25"
            - generic [ref=f2e112]: "50"
            - generic [ref=f2e114]: 24/10
            - generic [ref=f2e115]: 26/10
            - generic [ref=f2e116]: 28/10
            - generic [ref=f2e117]: 30/10
            - generic [ref=f2e118]: 01/11
            - generic [ref=f2e119]: 02/11
          - generic [ref=f2e120]:
            - paragraph [ref=f2e121]: Ganados / perdidos por dia
            - generic [ref=f2e122]: No disponible en este periodo
        - generic [ref=f2e124]:
          - heading "Visibilidad" [level=2] [ref=f2e125]
          - generic [ref=f2e126]:
            - generic [ref=f2e127]:
              - paragraph [ref=f2e129]: Visualizaciones
              - paragraph [ref=f2e130]: "0"
              - generic [ref=f2e131]:
                - paragraph [ref=f2e132]: Datos parciales
                - paragraph [ref=f2e133]: "3 de 10 observaciones de cuenta y día · 7 sin dato · Último día con datos: 01/11/2026 · Faltan datos hasta el 02/11/2026"
                - paragraph [ref=f2e134]: "Comparación omitida: faltan observaciones equivalentes en los dos periodos."
              - img "Gráfico de evolución" [ref=f2e137]:
                - generic [ref=f2e138]: "0"
                - generic [ref=f2e140]: 0,5
                - generic [ref=f2e142]: "1"
                - generic [ref=f2e144]: 24/10
                - generic [ref=f2e145]: 26/10
                - generic [ref=f2e146]: 28/10
                - generic [ref=f2e147]: 30/10
                - generic [ref=f2e148]: 01/11
                - generic [ref=f2e149]: 02/11
            - generic [ref=f2e150]:
              - paragraph [ref=f2e152]: Alcance
              - paragraph [ref=f2e153]: No disponible
              - generic [ref=f2e154]:
                - paragraph [ref=f2e155]: Sin datos recogidos
                - paragraph [ref=f2e156]: 0 de 10 observaciones de cuenta y día · 10 sin dato · Faltan datos hasta el 02/11/2026 · No se han recogido valores para este periodo; no equivale a cero actividad
                - paragraph [ref=f2e157]: "Comparación omitida: faltan observaciones equivalentes en los dos periodos."
              - generic [ref=f2e158]: No disponible en este periodo
            - generic [ref=f2e160]:
              - paragraph [ref=f2e162]: Visitas al perfil
              - paragraph [ref=f2e163]: No disponible
              - generic [ref=f2e164]:
                - paragraph [ref=f2e165]: Sin datos recogidos
                - paragraph [ref=f2e166]: 0 de 10 observaciones de cuenta y día · 10 sin dato · Faltan datos hasta el 02/11/2026 · No se han recogido valores para este periodo; no equivale a cero actividad
                - paragraph [ref=f2e167]: "Comparación omitida: faltan observaciones equivalentes en los dos periodos."
              - generic [ref=f2e168]: No disponible en este periodo
        - generic [ref=f2e170]:
          - heading "Interacciones" [level=2] [ref=f2e171]
          - generic [ref=f2e172]:
            - generic [ref=f2e173]:
              - paragraph [ref=f2e175]: Interacciones
              - paragraph [ref=f2e176]: No disponible
              - generic [ref=f2e177]:
                - paragraph [ref=f2e178]: Sin datos recogidos
                - paragraph [ref=f2e179]: 0 de 10 observaciones de cuenta y día · 10 sin dato · Faltan datos hasta el 02/11/2026 · No se han recogido valores para este periodo; no equivale a cero actividad
                - paragraph [ref=f2e180]: "Comparación omitida: faltan observaciones equivalentes en los dos periodos."
              - generic [ref=f2e181]: No disponible en este periodo
            - generic [ref=f2e183]:
              - paragraph [ref=f2e185]: Cuentas que interactuaron
              - paragraph [ref=f2e186]: No disponible
              - generic [ref=f2e187]:
                - paragraph [ref=f2e188]: Sin datos recogidos
                - paragraph [ref=f2e189]: 0 de 10 observaciones de cuenta y día · 10 sin dato · Faltan datos hasta el 02/11/2026 · No se han recogido valores para este periodo; no equivale a cero actividad
                - paragraph [ref=f2e190]: "Comparación omitida: faltan observaciones equivalentes en los dos periodos."
              - generic [ref=f2e191]: No disponible en este periodo
        - generic [ref=f2e193]:
          - heading "KPIs" [level=2] [ref=f2e194]
          - generic [ref=f2e195]:
            - generic [ref=f2e196]:
              - term [ref=f2e197]: Interacciones por día con datos
              - definition [ref=f2e198]: No disponible
              - paragraph [ref=f2e199]: 0 de 10 días
            - generic [ref=f2e200]:
              - term [ref=f2e201]: Visualizaciones por día con datos
              - definition [ref=f2e202]: "0"
              - paragraph [ref=f2e203]: 3 de 10 días
            - generic [ref=f2e204]:
              - term [ref=f2e205]: Engagement (cuenta)
              - definition [ref=f2e206]: No disponible
            - generic [ref=f2e207]:
              - term [ref=f2e208]: Visualizaciones por publicacion
              - definition [ref=f2e209]: No disponible
            - generic [ref=f2e210]:
              - term [ref=f2e211]: Interacciones por publicacion
              - definition [ref=f2e212]: No disponible
            - generic [ref=f2e213]:
              - term [ref=f2e214]: Engagement por publicacion
              - definition [ref=f2e215]: No disponible
          - paragraph [ref=f2e216]: Engagement = interacciones / visualizaciones, usando cuentas y días con ambas métricas. Las medias por publicación solo incluyen las publicadas desde esta aplicación con datos.
        - generic [ref=f2e217]:
          - heading "Publicaciones del periodo (0)" [level=2] [ref=f2e218]
          - paragraph [ref=f2e219]: No hay publicaciones de esta red en el periodo.
```

# Test source

```ts
  1  | import { expect, test } from '@playwright/test';
  2  | import { cleanComposerBrands, e2eUser, expectNoOverflow, loginAs, seedAnalyticsSnapshots, seedComposerBrand, stamp, watch } from './helpers';
  3  | 
  4  | test('Metrics: zero activity retains partial coverage, stale dates and suppressed unequal comparison', async ({ page }, info) => {
  5  |     const user = e2eUser(info.project.name);
  6  |     cleanComposerBrands(user.email);
  7  |     const brand = seedComposerBrand(user.email, `metric-quality-${stamp()}-${info.project.name}`);
  8  |     const period = seedAnalyticsSnapshots(brand.id, 0, 50, 3);
  9  |     const errors = watch(page);
  10 |     try {
  11 |         await loginAs(page, user);
  12 |         const query = new URLSearchParams({ brand_id: String(brand.id), from: period.from, to: period.to, compare: 'previous' });
  13 |         await page.goto(`/dashboard?${query}`);
  14 |         const views = page.locator('[data-card="views"]');
  15 |         await expect(views.getByText('Datos parciales', { exact: true }).first()).toBeVisible();
  16 |         await expect(views.getByText(/3 de 20 observaciones/)).toBeVisible();
  17 |         await expect(views.getByText(/Último día con datos:/).first()).toBeVisible();
  18 |         await expect(views.getByText(/Comparación omitida:/).first()).toBeVisible();
  19 |         await expectNoOverflow(page);
  20 |         await page.goto(`/dashboard/instagram?${query}`);
  21 |         await expect(page.locator('[data-metric="views"]').getByText(/3 de 10 observaciones/)).toBeVisible();
  22 |         await expect(page.getByText('Visualizaciones por día con datos', { exact: true })).toBeVisible();
  23 |         await expect(page.locator('[data-section="kpis"]').getByText('3 de 10 días').first()).toBeVisible();
  24 |         await expectNoOverflow(page);
  25 |         await page.screenshot({ path: info.outputPath('metrics-quality.png'), fullPage: true });
> 26 |         expect(errors.errors).toEqual([]);
     |                               ^ Error: expect(received).toEqual(expected) // deep equality
  27 |     } finally { cleanComposerBrands(user.email); }
  28 | });
  29 | 
```