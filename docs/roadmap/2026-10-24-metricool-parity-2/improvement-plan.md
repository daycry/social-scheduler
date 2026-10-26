---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-24T14:24:58Z
  fin: 2026-10-24T14:34:55Z
  fuente: medido
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-6b00b1cb305a442dbe0216b444253a16
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 18, salida: 97737, cache_creacion: 100247, cache_lectura: 949955 }
  eur: null               # sin .claude/rates.json: ejecuta rates-verify
  horas_ia: 0.66
  duracion: 40m
  ratio_usado: 300000   # default no calibrado   # ventana compartida con tasks.md
---

# 2026-10-24-metricool-parity-2

> Plan de implementación de la segunda ola de paridad con Metricool: textos guardados, primer comentario (IG, FB y YT), cuadrícula de Instagram, campañas, y media desde una URL o desde Google Drive.

| | |
|---|---|
| **Fecha** | 2026-10-24 |
| **Estado** | completado |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Media |
| **Solicitante** | daycry (usuario del proyecto) |
| **Responsable** | daycry |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 (C-01b = A1, C-04 = B1) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **185,4h** (154,5h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **25,0h** (+ 6,3h supervisión) | 0h | Baja |
| Coste total | **9.270 € + tokens** (⚠️ verificar) | 0 € | Media |
| Tokens IA | **6,26M** (in 5,63M / out 626k) | 0 | Baja |
| Multiplicador productividad | **×5,9** | — | — |
| Tareas | **50** en 7 fases | 0 hechas | — |

> Cifras heredadas de `evaluation.md` por entrega, sin re-estimar. Las horas IA salen de los tokens con el ratio por defecto (300.000 tok/h), no calibrado: en `social-scheduler` el real medido quedó un 87–92 % por debajo. El plazo real lo marcará la revisión y el smoke del usuario, no la ejecución (LES-009).

---

## Estimación por fase

| Fase | Estimado (h, con margen) | Tokens (in / out) | Coste € (con margen) |
|------|-------------|-------------------|---------|
| Fase 1 — C-01a · Textos guardados | 19,8 (16,5 base) | 601k / 67k | 990 |
| Fase 2 — C-01b · Primer comentario (IG, FB y YT) | 42,6 (35,5 base) | 1,29M / 144k | 2.130 |
| Fase 3 — C-02 · Cuadrícula de Instagram | 28,2 (23,5 base) | 857k / 95k | 1.410 |
| Fase 4 — C-03 · Campañas | 43,8 (36,5 base) | 1,33M / 148k | 2.190 |
| Fase 5 — C-04a · Media desde URL | 29,4 (24,5 base) | 893k / 99k | 1.470 |
| Fase 6 — C-04b · Media desde Google Drive | 17,4 (14,5 base) | 529k / 59k | 870 |
| Fase 7 — Transversal y cierre | 4,2 (3,5 base) | 128k / 14k | 210 |
| **Total** | **185,4h** (154,5h base) | **5,63M / 626k** | **9.270 €** |

Por entrega (evaluación, sin cambios):

| Entrega | Nombre | Horas base | Horas con margen | Coste € | Tokens | Horas IA (margen) |
|---|---|---|---|---|---|---|
| C-01a | Textos guardados | 17,0h | 20,4h | 1.020 € | 0,69M | 2,8h |
| C-01b | Primer comentario (IG, FB y YT) | 36,0h | 43,2h | 2.160 € | 1,46M | 5,8h |
| C-02 | Cuadrícula de Instagram | 24,0h | 28,8h | 1.440 € | 0,97M | 3,9h |
| C-03 | Campañas | 37,0h | 44,4h | 2.220 € | 1,50M | 6,0h |
| C-04a | Media desde URL | 25,0h | 30,0h | 1.500 € | 1,01M | 4,1h |
| C-04b | Media desde Google Drive | 15,5h | 18,6h | 930 € | 0,63M | 2,5h |
| | **Total** | **154,5h** | **185,4h** | **9.270 €** | **6,26M** | **25,1h** |

> Las tareas transversales (T-48, T-49, T-50: 3,5h base) no suman horas: salen de las partidas de documentación y revisión de las entregas, como declara el ledger. Por eso las fases 1–6 suman 151h base y la fase 7 las 3,5h restantes.

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Todos los importes en **EUR**.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default compartido (`estimation-defaults.md`). No hay `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 | Base de la previsión de tokens |
| Precio input | ⚠️ verificar € / 1M tokens | Sin `rates.json`: ejecuta `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre las horas base (humanas e IA) |
| Ratio tokens→hora-IA | 300.000 tok/h | Default **no calibrado** |
| Densidad de tokens | ≈ 40.500 tok por hora base, reparto 90/10 entrada/salida | Heredada de la evaluación |
| Supervisión humana | 25 % de las horas IA | Default |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 154,5h × 50 €/h | 7.725 € |
| Margen de contingencia | +20 % sobre desarrollo base | 1.545 € |
| Tokens IA (input) | 5,63M tok × precio | ⚠️ verificar |
| Tokens IA (output) | 626k tok × precio | ⚠️ verificar |
| **Total estimado (con margen)** | | **9.270 € + tokens** |

---

## Previsión de tokens (por fase)

Base: claude-opus-4-8 · precios por verificar.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — C-01a · Textos guardados | 601k | 67k | 668k | ⚠️ verificar |
| Fase 2 — C-01b · Primer comentario (IG, FB y YT) | 1,29M | 144k | 1,44M | ⚠️ verificar |
| Fase 3 — C-02 · Cuadrícula de Instagram | 857k | 95k | 952k | ⚠️ verificar |
| Fase 4 — C-03 · Campañas | 1,33M | 148k | 1,48M | ⚠️ verificar |
| Fase 5 — C-04a · Media desde URL | 893k | 99k | 992k | ⚠️ verificar |
| Fase 6 — C-04b · Media desde Google Drive | 529k | 59k | 587k | ⚠️ verificar |
| Fase 7 — Transversal y cierre | 128k | 14k | 142k | ⚠️ verificar |
| **Total** | **5,63M** | **626k** | **6,26M** | ⚠️ verificar |

**Método de estimación:** horas base de la evaluación × 40.500 tok/h (90 % entrada, 10 % salida), la misma densidad que `post-composer`, `social-login-mcp-oauth` y `account-2fa-passkeys`. Es un indicio, no un histórico calibrado.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 185,4 h |
| Horas IA (ejecución) | 25,0 h (20,9 h base) |
| Supervisión humana | 6,3 h |
| **Horas totales (IA + supervisión)** | **31,3 h** |
| Horas ahorradas | 154,1 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,96 |

> Horas mostradas **con el margen de contingencia (+20 %)** ya aplicado. Las horas IA son una estimación aproximada (supuesto).

---

## Resumen ejecutivo

Se implementa la segunda ola de paridad con Metricool para uso personal, con el alcance completo aprobado: textos guardados, primer comentario en Instagram, Facebook y YouTube, cuadrícula de Instagram con lo programado, campañas con panel de métricas, y media importada desde una URL o desde Google Drive. Ninguna entrega necesita una red nueva ni revisión de Meta.

El riesgo se concentra en dos puntos: no duplicar el primer comentario ni degradar un destino ya publicado (C-01b, opción A1), y la importación remota, con defensa SSRF y descarga por tramos compatible con los workers de 120 s de Hestia (C-04, opción B1).

### Objetivos

- Entregar las seis entregas (C-01a, C-01b, C-02, C-03, C-04a y C-04b) con CA-01..CA-18 cubiertos por Pest, Playwright o smoke manual.
- Mantener la paridad web y MCP en `first_comment`, `campaign_id` y la importación por URL.
- Dejar el despliegue de Hestia (cola `media`) y la guía de Google Cloud listos para que el usuario los configure.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (spec aprobada, D1–D5 decididas)
- [x] **Alcance** cerrado: seis entregas; fuera: reordenar la cuadrícula, campañas entre marcas o en el PDF, enlaces públicos de Drive por URL, `list_saved_texts` en el MCP, redes nuevas
- [x] **Criterios de éxito / métricas** acordados (CA-01..CA-18)
- [ ] **Accesos y credenciales:** el usuario debe configurar Google Cloud (Picker API, Drive API, clave de API, `drive.file`) y reconectar YouTube (`youtube.force-ssl`); ver T-48
- [x] **Entornos** disponibles: local con Laragon, Pest en SQLite, Playwright con `e2e-sec-*`
- [x] **Stakeholders** identificados: un solo usuario
- [x] **Dependencias externas** mapeadas: Graph API v23.0, YouTube Data API v3, Google Picker y Drive API v3
- [x] **Restricciones** conocidas: `MEDIA_DISK=local`, workers de 120 s, UI en español
- [ ] **Tarifa/hora y supuestos de coste** confirmados: sin `.claude/rates.json`; precio de tokens por verificar

---

## Análisis de impacto

- **`app/Connectors/`** — `Contracts/FirstCommenter`, `comment()`/`findOwnComment()` en Meta y YouTube, y `Meta/Instagram/InstagramGrid` nuevo.
- **`app/Jobs/` y `app/Services/PublishOutcomes.php`, `PublishRecovery.php`** — `PostFirstComment` encolado desde `published()` y recuperación (paso 6); `PublishPostTarget` no cambia.
- **`app/Services/Media/`, `app/Jobs/ImportRemoteMedia.php`, `MediaController`** — pipeline único URL/Drive por pasadas, `UrlGuard` y endpoints.
- **`app/Services/{PostService,CampaignService,CampaignAnalytics,AnalyticsService,GridService,SavedTextService,FirstCommentService}.php`** — `plan()`/`fill()` con `first_comment` y `campaign_id`; extracción de la consulta de snapshots.
- **`app/Mcp/`** — `PostPayload`, `GetPost`, `ListCampaigns` e `ImportMediaFromUrl`.
- **`database/migrations/`** — `saved_texts`, `post_targets` (6 columnas), `campaigns` + `posts.campaign_id`, `media_assets` (`source`, `source_ref`, `import_state`); todas aditivas.
- **`resources/js/`** — `Pages/{SavedTexts,Instagram,Campaigns}`, `Components/Composer/*`, `Composables/useDrivePicker.js`, `useComposer.js`.
- **`deploy/hestia`, `docs/`** — cuarta línea de cron `ss-media`; guía de Google Cloud.

---

## Cambios arquitectónicos

- **C-01b = A1** ([design.md](design.md) §4): estado del comentario en columnas de `post_targets` y job propio con reclamo (`lockForUpdate`), idempotencia en tres capas y recuperación. El texto de un comentario `fallido` se puede editar antes de reintentar (`FirstCommentService::update()`); es la única edición admitida en un destino publicado.
- **C-04 = B1** (design §4): un solo pipeline `RemoteMediaImporter` con fuentes `UrlSource` y `DriveFileSource`, por pasadas de 80 s con `Range`/`If-Range`, punto de control en el `.part` del disco local y `UrlGuard` en cada pasada y salto.
- **Textos guardados por usuario con `brand_id` opcional** (D1) y **campañas por marca y por post** (D3).
- Candidatos de decisión pendientes del Curator, registrados en el bloque `curation-refs-v1` de `tasks.md`.

---

## Archivos a crear/modificar

Detalle por tarea en [`tasks.md`](tasks.md) (campo **Archivos**). Resumen:

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `database/migrations/*` (4 grupos) | Crear | `saved_texts`, comentario en `post_targets`, `campaigns` y `media_assets.source` |
| `app/Models/{SavedText,Campaign}.php`, `app/Enums/FirstCommentStatus.php` | Crear | Modelos y estado del comentario |
| `app/Jobs/{PostFirstComment,ImportRemoteMedia}.php` | Crear | Comentario tras publicar e importación por pasadas |
| `app/Services/Media/*`, `app/Services/{SavedText,Campaign,CampaignAnalytics,Grid,FirstComment}*.php` | Crear | Lógica de cada entrega |
| `app/Connectors/Contracts/FirstCommenter.php`, `Meta/Instagram/InstagramGrid.php` | Crear | Contrato de comentario y lector de la cuadrícula |
| `app/Mcp/Tools/{ListCampaigns,ImportMediaFromUrl}.php` | Crear | Herramientas MCP nuevas |
| `resources/js/Pages/{SavedTexts,Instagram,Campaigns}/*`, `useDrivePicker.js` | Crear | Páginas y composable de Drive |
| `app/Services/{PublishOutcomes,PublishRecovery,PostService,AnalyticsService,LibraryService}.php`, `PostRequest.php`, `MediaController.php` | Modificar | Enganches y campos nuevos |
| `app/Mcp/Tools/{PostPayload,GetPost}.php`, `SchedulerServer.php` | Modificar | Paridad MCP |
| `config/{social,media,media_specs,publishing,queue,services}.php` | Modificar | Scope, tope por red, cola `media` y claves del Picker |
| `resources/js/Components/Composer/*`, `AuthenticatedLayout.vue` | Modificar | Menú, campo, estado, selector, medios y navegación |
| `tests/Feature/*`, `tests/Unit/UrlGuardTest.php`, `tests/e2e/*.spec.ts` | Crear | Pest y Playwright |
| `deploy/hestia/*`, `docs/**` | Modificar/Crear | Cron `ss-media`, guía de Google Cloud y documentación |

---

## Dependencias y prerequisitos

- **Orden:** C-01a → C-01b → C-02 → C-03 → C-04a → C-04b → transversal. C-01b y C-03 comparten `PostRequest`, `PostService` y `PostPayload`: van en serie. C-04b depende del pipeline de C-04a.
- **Compositor mergeado** (PR #10) y rama `feature/metricool-parity-2`.
- **Acciones del usuario (D2, D4, D5):** reconectar YouTube con `youtube.force-ssl`; configurar Picker API, Drive API, clave de API restringida y `drive.file` en Google Cloud; añadir la línea de cron `ss-media` en Hestia. Sin ellas, C-01b-YT, C-04b y el worker de importación no funcionan en producción.

---

## Criterios de aceptación (global)

- [ ] CA-01..CA-03 (textos guardados), CA-04..CA-07 (primer comentario), CA-08..CA-10 (cuadrícula), CA-11..CA-13 (campañas) y CA-14..CA-18 (media remota) cubiertos, trazados en `test-plan.md`.
- [ ] `php artisan test --compact` y `npx playwright test` (desktop, mobile y mobile-360) en verde; `vendor/bin/pint --test` con exit 0.
- [ ] Ninguna llamada a redes reales en los tests; ningún dato del usuario real tocado por los E2E.
- [ ] Paridad MCP: `first_comment`, `campaign_id`, `list_campaigns` e `import_media_from_url`.

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Comentario duplicado tras un timeout | Baja | Alto | `findOwnComment()` antes de `comment()`, `ShouldBeUnique`, `lockForUpdate` y backoff de 60 s (T-11, T-18) |
| SSRF en la importación por URL | Media | Alto | `UrlGuard`, IP fijada y revisión propia (T-33, T-41) |
| Descarga larga frente a workers de 120 s | Alta | Medio | Pasadas de 80 s con `Range`, cola `media` propia (T-35, T-40) |
| Reconexión de YouTube invalida los tokens | Alta | Medio | D2 aceptada; guía en T-48; sin scope el control se desactiva (T-07) |
| Regresión del dashboard al extraer la consulta de snapshots | Media | Medio | Caracterización antes y después (T-29) |
| Choque de `PostRequest`/`PostService`/`PostPayload` entre C-01b y C-03 | Alta | Bajo | Serie obligatoria y caracterización en T-27/T-28 |
| El plazo lo marca la revisión del usuario | Alta | Bajo | Revisión de robustez presupuestada por entrega y smoke manual por olas |

---

## Métricas de éxito

- 18 criterios de aceptación cubiertos (CA-01..CA-18) por E2E-01..E2E-09, Pest o M-01..M-05.
- 0 comentarios duplicados en las pruebas de idempotencia y de recuperación.
- Un vídeo grande importado en varias pasadas, cada una por debajo de 120 s.
- Suites completas en verde y sin violaciones `serious`/`critical` de axe.

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-24 | Creación del plan | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre la rama `feature/metricool-parity-2`, marcando `tasks.md` como **ledger canónico**. Al terminar, handoff a `qa` y cierre con `documenter`.
