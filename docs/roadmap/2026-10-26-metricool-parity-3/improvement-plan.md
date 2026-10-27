---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-26T23:44:42Z
  fin: 2026-10-26T23:53:28Z
  fuente: medido
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-dbdca965f46b44e79c5554903662989d
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 18, salida: 84277, cache_creacion: 89165, cache_lectura: 872237 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.58
  duracion: 35m           # reloj: 9m
  ratio_usado: 300000   # default no calibrado   # ventana compartida con improvement-plan.md
---

# 2026-10-26-metricool-parity-3

> Paridad con Metricool, tercera ola: Bluesky y Threads, vistas de calendario, analítica entre marcas, CSV, hashtags, GIFs, lenguaje natural y notas del inbox.

| | |
|---|---|
| **Fecha** | 2026-10-27 |
| **Estado** | en-progreso |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Media |
| **Solicitante** | daycry (usuario del proyecto), vía `/pm-cycle` |
| **Responsable** | daycry |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 (C-01) + T1 (C-02) |
| **Plan de pruebas** | [`test-plan.md`](test-plan.md) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **295,2h** (246,0h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **39,9h** (+ 10,0h supervisión) | 0h | Baja |
| Coste total | **14.760 € + tokens** (tokens: ⚠️ verificar) | 0 € | Media |
| Tokens IA | **9,96M** (in 8,97M / out 996k) | 0 | Baja |
| Multiplicador productividad | **×5,9** | — | — |
| Tareas | **77** en 10 fases | 0 hechas | — |

> Las horas humanas (base del valor) y las horas IA (base del plazo) son magnitudes distintas y no se suman. Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).

---

## Estimación por fase

Las horas **heredan la evaluación** (`evaluation.md`): 246h base, 295,2h con margen. Las tareas transversales (Fase 10) se descuentan de las partidas de documentación, revisión y smoke de cada entrega, así que **no suman horas nuevas** (detalle en `tasks.md`).

| Fase | Estimado (h) | Tokens (in / out) | Coste € (con margen) |
|------|-------------|-------------------|---------|
| Fase 1 — C-04d · Notas internas del inbox | 14,0 base · 16,8 con margen | 510k / 57k | 840 |
| Fase 2 — C-03a · Vistas de calendario guardadas | 16,0 base · 19,2 con margen | 583k / 65k | 960 |
| Fase 3 — C-01 · Bluesky (contrato segregado y catálogo de redes) | 61,5 base · 73,8 con margen | 2,24M / 249k | 3.690 |
| Fase 4 — C-03b · Analítica entre marcas | 21,5 base · 25,8 con margen | 784k / 87k | 1.290 |
| Fase 5 — C-03c · Exportar a CSV | 18,0 base · 21,6 con margen | 656k / 73k | 1.080 |
| Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA) | 18,0 base · 21,6 con margen | 656k / 73k | 1.080 |
| Fase 7 — C-04c · Programar en lenguaje natural (IA existente) | 18,0 base · 21,6 con margen | 656k / 73k | 1.080 |
| Fase 8 — C-02 · Threads (al final, sin bloquear lo demás) | 50,5 base · 60,6 con margen | 1,84M / 205k | 3.030 |
| Fase 9 — C-04b · GIFs de Giphy | 18,5 base · 22,2 con margen | 674k / 75k | 1.110 |
| Fase 10 — Transversal y cierre | 10,0 base · 12,0 con margen | 364k / 40k | 600 |
| **Total** | **246,0h base · 295,2h con margen** | **8,97M / 996k** | **14.760 €** |

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
| Densidad tokens/hora humana | ≈ 40,5k tok por hora base (90/10 entrada/salida) | La misma que la evaluación y parity-2 |
| Supervisión humana | 25 % de las horas IA | Default |
| APIs de terceros | Bluesky, Threads y Giphy *beta* gratis; Anthropic según uso (D6) | No entran en el presupuesto de desarrollo |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 246,0h × 50 €/h | 12.300 € |
| Margen de contingencia | +20 % sobre desarrollo base (49,2h) | 2.460 € |
| Tokens IA (input) | 8,97M tok × precio | ⚠️ verificar |
| Tokens IA (output) | 996k tok × precio | ⚠️ verificar |
| **Total estimado (con margen)** | | **14.760 € + tokens** |

---

## Previsión de tokens (por fase)

Base: claude-opus-4-8 · precios de la tabla de supuestos (⚠️ verificar).

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — C-04d · Notas internas del inbox | 510k | 57k | 567k | ⚠️ verificar |
| Fase 2 — C-03a · Vistas de calendario guardadas | 583k | 65k | 648k | ⚠️ verificar |
| Fase 3 — C-01 · Bluesky (contrato segregado y catálogo de redes) | 2,24M | 249k | 2,49M | ⚠️ verificar |
| Fase 4 — C-03b · Analítica entre marcas | 784k | 87k | 871k | ⚠️ verificar |
| Fase 5 — C-03c · Exportar a CSV | 656k | 73k | 729k | ⚠️ verificar |
| Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA) | 656k | 73k | 729k | ⚠️ verificar |
| Fase 7 — C-04c · Programar en lenguaje natural (IA existente) | 656k | 73k | 729k | ⚠️ verificar |
| Fase 8 — C-02 · Threads (al final, sin bloquear lo demás) | 1,84M | 205k | 2,05M | ⚠️ verificar |
| Fase 9 — C-04b · GIFs de Giphy | 674k | 75k | 749k | ⚠️ verificar |
| Fase 10 — Transversal y cierre | 364k | 40k | 405k | ⚠️ verificar |
| **Total** | **8,97M** | **996k** | **9,96M** | ⚠️ verificar |

**Método de estimación:** horas base de la evaluación × 40,5k tokens por hora base, con reparto 90/10. Es un indicio, no un histórico calibrado.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 295,2 h (246,0 h base +20 %) |
| Horas IA (ejecución) | 39,9 h (33,2 h base +20 %) |
| Supervisión humana | 10,0 h |
| **Horas totales (IA + supervisión)** | **49,8 h** |
| Horas ahorradas | 245,4 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 1,53 |

> Las horas IA son una estimación aproximada (supuesto). En `metricool-parity-2` el ledger registró menos horas IA reales que las estimadas: es un indicio de que el plazo real será menor, no una calibración.

---

## Resumen ejecutivo

La tercera ola de paridad añade dos redes (Bluesky y Threads) y siete funciones del inventario de Metricool, en el orden que el usuario aprobó: notas del inbox, vistas de calendario guardadas, Bluesky, analítica entre marcas, exportar a CSV, hashtags desde el histórico propio, programar con lenguaje natural, Threads (al final y sin bloquear lo demás) y GIFs de Giphy. Son 77 tareas y 295,2h con margen (≈ 14.760 € + tokens).

Bluesky es la pieza arquitectónica: el contrato de conector deja de ser solo OAuth (contrato segregado) y `Network` pasa a ser el catálogo de redes, protegido por un test de superficies. Threads se añade después como conector propio, sin tocar Instagram.

### Objetivos

- Conectar, programar, publicar y medir Bluesky (texto, 1-4 imágenes y vídeo) y Threads (texto, imagen, vídeo y carrusel) con paridad web y MCP (CA-01..CA-10).
- Entregar las funciones de calendario y analítica: vistas guardadas, «Todas las marcas» y CSV (CA-11..CA-13).
- Entregar los extras del compositor y el inbox: hashtags, GIFs, lenguaje natural y notas (CA-14..CA-17).
- Cerrar con Pest, Playwright en los 3 proyectos y Pint en verde, y las guías de usuario escritas.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados: spec aprobada y decisiones D1-D6.
- [x] **Alcance** cerrado: completo, con el orden aprobado. Fuera: varias fechas en lenguaje natural, hashtags con IA, texto alternativo de imágenes y responder en Threads desde el inbox.
- [x] **Criterios de éxito / métricas**: CA-01..CA-17.
- [ ] **Accesos y credenciales**: app password de Bluesky, app de Meta para Threads (ID, secreto y *tester*) y clave de Giphy, a cargo del usuario (guías T-73, T-74, T-75). Bloquean solo los smokes (T-30, T-64) y no el código.
- [x] **Entornos**: SQLite en memoria para Pest; servidor propio de Playwright; `MEDIA_DISK=local` en producción.
- [x] **Stakeholders**: el usuario valida en la puerta del plan y en los smokes.
- [ ] **Dependencias externas**: Bluesky (verificadas en lo esencial), Threads (varios ⚠️, T-54), Giphy (ToS por confirmar) y Anthropic (ya integrada).
- [x] **Restricciones**: Laravel 13, Inertia/Vue 3, worker de 120 s, UI en español, MCP en 25 herramientas.
- [ ] **Tarifa/hora y supuestos de coste**: defaults; no existe `.claude/rates.json`.

---

## Análisis de impacto

- **`app/Connectors/{Network,ConnectorRegistry}.php` y `Contracts/*`** — contrato segregado y catálogo de redes (T-15, T-16).
- **`app/Connectors/Bluesky/*` y `app/Connectors/Threads/*`** — conectores nuevos (T-19, T-23..T-26, T-55..T-59).
- **`app/Enums/ContentType.php`, `config/{media_specs,social,services}.php`** — tipos, reglas y topes (T-21, T-56).
- **`app/Services/{MediaValidator,AnalyticsService,AiAssistant,CalendarService,ReportService,InboxService}.php`** — validación por grafemas, métricas nuevas, lenguaje natural, filtros, CSV y notas.
- **`app/Services/{AggregateAnalytics,HashtagStats,CalendarViewService,InboxNoteService}.php`, `app/Support/{CsvWriter,TextLength,Hashtags}.php`** — servicios nuevos.
- **`app/Mcp/Tools/*` y `SchedulerServer.php`** — 3 herramientas nuevas (25 en total) y parámetros nuevos.
- **`resources/js/**`** — catálogo en `shell.networks`, `Calendar/Index.vue`, `Dashboard/*`, `Inbox/Index.vue`, compositor (hashtags, GIF, `ScheduleSection`) y `Brands/Connections.vue`.
- **`database/migrations`** — `credential_secret`, `calendar_views`, `inbox_comment_notes` (aditivas).
- **`tests/**` y `docs/**`** — Pest, Playwright y documentación.

---

## Cambios arquitectónicos

- **C-01 = O1** (`design.md`): `Connector` común, `OAuthConnector` y `CredentialConnector`; catálogo de redes en `Network` (`label`, `color`, `authKind`, `offered()`) leído por backend y frontend; `NetworkCoverageTest` convierte una red a medias en un fallo de CI. Bluesky pasa a OAuth de atproto cambiando solo su interfaz.
- **C-02 = T1**: conector `threads` propio que copia la máquina de estados de Instagram sin heredarla; `InstagramPublisher` no cambia. Solo se extraen las tablas de errores de `GraphClient` a `Meta/GraphErrors`.
- **Sesión de Bluesky** aislada en `BlueskySession` (app password cifrada en `credential_secret`, bloqueo por cuenta, `refresh_expires_at = null`).
- **Hashtags**: una sola extracción (`App\Support\Hashtags`) compartida por `Facets` y `HashtagStats`.
- Candidato de memoria: `docs/knowledge/candidates/pending/2026-10-27-conectores-contrato-segregado-y-catalogo-de-redes.md` (sha256 `ac5976ab…ee9db`), registrado en el bloque `curation-refs-v1` de `tasks.md`; lo decide el Curator.

---

## Archivos a crear/modificar

La lista completa por tarea está en el campo `Archivos` de cada tarea de `tasks.md`. Resumen:

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `app/Connectors/Contracts/{OAuthConnector,CredentialConnector}.php` | Crear | Contrato segregado (T-15) |
| `app/Connectors/Bluesky/*` | Crear | Conector, sesión, publicador, insights, `Facets`, `Tid` |
| `app/Connectors/Threads/*`, `app/Connectors/Meta/GraphErrors.php` | Crear | Conector de Threads |
| `app/Connectors/Network.php` | Modificar | Catálogo de redes |
| `app/Support/{TextLength,Hashtags,CsvWriter}.php` | Crear | Conteo, hashtags y CSV |
| `app/Services/{AggregateAnalytics,HashtagStats,CalendarViewService,InboxNoteService}.php` | Crear | Servicios de las funciones nuevas |
| `app/Mcp/Tools/{ListCalendarViews,SuggestHashtags,AddCommentNote}.php` | Crear | Herramientas MCP nuevas |
| `database/migrations/*` | Crear | `credential_secret`, `calendar_views`, `inbox_comment_notes` |
| `resources/js/Components/{Calendar/ViewsMenu,Composer/HashtagSuggestions,Composer/GifPicker,Inbox/CommentNotes}.vue`, `Pages/Dashboard/AllBrands.vue` | Crear | UI nueva |
| `tests/Feature/**`, `tests/Unit/**`, `tests/e2e/*.spec.ts` | Crear | Pest y Playwright |
| `docs/guias/{bluesky-app-password,threads-app-meta,giphy-api-key}.md`, `docs/modulos/*`, `docs/referencia/configuracion.md`, `.env.example`, `README.md` | Crear/Modificar | Guías, módulos, variables y recuento de herramientas |

---

## Dependencias y prerequisitos

- **Bluesky (Fase 3) antes que Threads (Fase 8):** paga la generalización de redes.
- **T-23 crea `App\Support\Hashtags`**, que reutiliza T-43 (hashtags). **T-12 (vistas)** antes de T-39 (CSV de la lista). **T-26 (Bluesky insights)** antes de T-31 (agregado).
- **Threads (C-02)** depende de D2 (app de Meta y *tester*) solo para el smoke T-64; el resto se programa con `Http::fake`. No bloquea las demás fases.
- **Giphy (C-04b)** depende de D4 (clave) y de la importación de parity-2.
- **Lenguaje natural (C-04c)** requiere `AI_ASSISTANT_ENABLED` y D6.
- Las guías T-73 y T-74 se ejecutan antes de los smokes T-30 y T-64 (aunque estén en la Fase 10).

---

## Criterios de aceptación (global)

- [ ] CA-01..CA-17 de la spec cubiertos por Pest y/o Playwright, con los trazados en `test-plan.md`.
- [ ] Meta, Google y TikTok sin regresión (caracterización previa en verde) e Instagram sin cambios.
- [ ] `tools/list` ofrece 25 herramientas y Bluesky/Threads funcionan en `list_accounts` y `create_post`.
- [ ] Pest completo, Playwright en `desktop`, `mobile` y `mobile-360` (servidor propio, sin `E2E_NO_SERVER`) y Pint en verde (T-77).
- [ ] Guías de usuario de Bluesky, Threads y Giphy publicadas y variables de `.env` documentadas.

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| Lista de redes olvidada al añadir una red | Alta | Medio | Catálogo en `Network` y `NetworkCoverageTest` (T-16, T-18) |
| Subida de vídeo de Bluesky no cabe en los 85 s del paso | Baja-Media | Medio | Tope de 50 MB configurable, rechazo al guardar, streaming; se prueba en el smoke (T-30) |
| Rotación concurrente del `refreshJwt` | Media | Medio | `Cache::lock` por cuenta y recreación con la app password (T-19) |
| Bluesky retira las app passwords | Baja | Alto | Sesión aislada; el cambio a OAuth de atproto es +24h |
| Threads: límites ⚠️ sin verificar o App Review exigida | Media | Medio | T-54 verifica; el código queda tras `THREADS_APP_ID`; Threads va al final |
| Términos de Giphy sobre republicar el MP4 | Media | Medio | El usuario los confirma (guía T-75); atribución obligatoria |
| El LLM interpreta mal la fecha | Media | Bajo | Validación en servidor, propuesta visible y tabla de frases (T-49..T-51) |
| MCP exactamente en el tope de 25 herramientas por página | Baja | Bajo | Test de 25 en T-45/T-72; subir `defaultPaginationLength` si se añade otra |
| Extraer `CsvWriter` cambia el CSV de informes | Baja | Medio | Test byte a byte antes y después (T-37) |

---

## Métricas de éxito

- Los 17 criterios de aceptación pasan y la regresión completa queda en verde (T-77).
- Una red nueva sin icono, métricas o reglas hace fallar `NetworkCoverageTest` en CI.
- El usuario publica en Bluesky y Threads con sus cuentas reales (smokes M-01 y M-02).
- Horas IA reales por debajo de las estimadas (indicio para calibrar `CALIBRATION.md`).

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-27 | Creación del plan (9 fases de entrega + transversal, 77 tareas) a partir de spec, evaluación (D1-D6) y diseño aprobado (O1+T1) | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre la rama `feature/metricool-parity-3`, marcando `tasks.md` como **ledger canónico** (checkbox + estado por tarea). Al terminar, handoff a `qa` (que ejecuta `test-plan.md`) y cierre con `documenter`.
