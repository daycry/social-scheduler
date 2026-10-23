---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-23T11:11:08Z
  fin: 2026-10-23T11:18:56Z
  fuente: medido
  tokens_reales: { entrada: 16, salida: 79261, cache_creacion: 85755, cache_lectura: 958619 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json): ejecuta rates-verify
  horas_ia: 0.55
  duracion: 33m
  ratio_usado: 300000   # ventana compartida con tasks.md
---

# 2026-10-23-post-composer

> Plan de implementación del nuevo compositor de publicaciones de Social Scheduler: editor a pantalla completa con contadores, panel de errores y barra de acciones con «Publicar ahora» (también en el calendario, la lista y el MCP), texto propio por red, opciones por red persistentes, notas internas y generador UTM.

| | |
|---|---|
| **Fecha** | 2026-10-23 |
| **Estado** | borrador |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Media |
| **Solicitante** | daycr (usuario del proyecto) |
| **Responsable** | daycr |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 ([ADR-005](../../knowledge/adr/ADR-005-texto-efectivo-y-publicar-ahora-sobre-el-nucleo-de-publicacion.md), `propuesta` hasta T-42) |
| **Plan de pruebas** | [`test-plan.md`](test-plan.md) |
| **Ledger** | [`tasks.md`](tasks.md) (42 tareas, fuente canónica de progreso) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **170,4h** (142,0h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **23,0h** (19,2h base +20 %) (+ 5,8h supervisión) | 0h | Baja |
| Coste total | **8.520 € + tokens** (tokens: ⚠️ verificar) | 0 € | Media |
| Tokens IA | **5,75M** (in 5,17M / out 0,57M) | 0 | Baja |
| Multiplicador productividad | **×5,9** | — | — |
| Tareas | **42** | 0 hechas | — |

> Las horas humanas (base del valor) y las horas IA (base del plazo) son magnitudes distintas y no se suman. Las horas IA salen de los tokens con el ratio por defecto, **no calibrado** (300.000 tok/h).

---

## Estimación por fase

| Fase | Estimado (h) | Tokens (in / out) | Coste € (con margen) |
|------|-------------|-------------------|---------|
| Fase 1 — C-01 · Editor a pantalla completa y contadores por red | 28,0h base · 33,6h con margen | 1.019k / 112k | 1.680 |
| Fase 2 — C-08 · Panel de errores, barra de acciones y «Publicar ahora» | 29,0h base · 34,8h con margen | 1.057k / 116k | 1.740 |
| Fase 3 — C-03 · Texto por red | 32,0h base · 38,4h con margen | 1.165k / 128k | 1.920 |
| Fase 4 — C-04 · Opciones por red persistentes (presets) | 27,0h base · 32,4h con margen | 984k / 108k | 1.620 |
| Fase 5 — C-09 notas y C-05a generador UTM | 14,0h base · 16,8h con margen | 510k / 56k | 840 |
| Fase 6 — C-11 · Cierre: E2E, axe, MCP, documentación y smoke | 12,0h base · 14,4h con margen | 437k / 48k | 720 |
| **Total** | **142,0h base · 170,4h con margen** | **5.172k / 568k** | **8.520 €** |

**Diferencias con la evaluación (declaradas).** Se heredan las horas por característica de la evaluación: C-01 28h, C-08 27h, C-03 32h, C-04 27h, C-09 10h, C-05a 4h y C-11 12h, que suman 140h base (168h con margen, el escenario «MVP + quick wins»). Se añaden **2h base** de «Publicar ahora» fuera del compositor (calendario, lista y herramienta MCP `publish_post_now`), previstas por el diseño (§4), con lo que el plan queda en **142,0h base (170,4h con margen, 8.520 €)**. Las cinco revisiones de robustez de fin de fase y la revisión final se absorben dentro de las partidas de pruebas y documentación de cada característica: no suman horas. La herramienta MCP `update_post_notes` ya estaba en la partida de notas.

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Todos los importes en **EUR**.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h ⚠️ verificar | Default compartido. No existe `.claude/rates.json` |
| Modelo IA asumido | claude-opus-4-8 ⚠️ verificar | Default de `estimation-defaults.md` |
| Precio input | ⚠️ verificar € / 1M tokens | Sin precio fiable: ejecutar la skill `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € ⚠️ verificar | Si el proveedor factura en USD |
| Margen de contingencia | 20 % | Sobre las horas base humanas e IA |
| Ratio tokens→hora-IA | 300.000 tok/h ⚠️ no calibrado | No existe `docs/roadmap/CALIBRATION.md`. Tokens facturables = entrada + creación de caché + salida |
| Supervisión humana | 25 % de las horas IA | Default. En UI se queda corta: el usuario tiene que mirar el compositor en el navegador al cerrar cada fase (M-02, M-03) y las cinco revisiones de robustez son tiempo humano |
| Tokens por tarea | ≈ 40,5k por hora base | La densidad de la evaluación (5,10M en 126h) y de `social-login-mcp-oauth`; reparto 90 % entrada / 10 % salida. Aquí hay más Vue, lo que tiende a subir la salida |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 142,0h × 50 €/h | 7.100 € |
| Margen de contingencia | +20 % sobre desarrollo base (28,4h) | 1.420 € |
| Tokens IA (input) | 5,17M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 0,57M tok × P_out | ⚠️ verificar |
| Coste de proceso (spec + evaluación + diseño/ADR-005 + plan) | ≈ 400k tok facturables estimados; cada artefacto lo mide en su `generacion:` | ⚠️ verificar |
| **Total estimado (con margen)** | | **8.520 € + tokens (⚠️ verificar)** |

---

## Previsión de tokens (por fase)

Estimación del consumo de tokens del modelo por fase. Base: claude-opus-4-8 ⚠️ verificar · precios pendientes de `rates-verify`.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 — C-01 | 1.019.000 | 112.000 | 1.131.000 | ⚠️ verificar |
| Fase 2 — C-08 | 1.057.000 | 116.000 | 1.173.000 | ⚠️ verificar |
| Fase 3 — C-03 | 1.165.000 | 128.000 | 1.293.000 | ⚠️ verificar |
| Fase 4 — C-04 | 984.000 | 108.000 | 1.092.000 | ⚠️ verificar |
| Fase 5 — C-09 notas y C-05a generador UTM | 510.000 | 56.000 | 566.000 | ⚠️ verificar |
| Fase 6 — C-11 | 437.000 | 48.000 | 485.000 | ⚠️ verificar |
| **Total** | **5.172.000** | **568.000** | **5.740.000** | **⚠️ verificar** |

**Método de estimación:** el de la evaluación (≈ 40,5k tokens facturables por hora humana base, salida ≈ 10 %), repartido por tarea en proporción a sus horas. Hay más lectura de código en las fases 2 y 3 (núcleo de publicación) y más generación de Vue en las fases 1, 4 y 5.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 170,4 h (142,0 h base +20 %) |
| Horas IA (ejecución) | 23,0 h (19,2 h base +20 %; 5,75M tok ÷ 300.000 tok/h, supuesto) |
| Supervisión humana | 5,8 h (25 % de las horas IA) |
| **Horas totales (IA + supervisión)** | **28,8 h** |
| Horas ahorradas | 141,6 h |
| **Ahorro** | **83,1 %** |
| **Multiplicador de productividad** | **×5,9** |
| FTE equivalentes *(opcional)* | 0,89 |

> Las horas IA son una estimación aproximada (supuesto sin calibrar). La supervisión real será mayor: en un rediseño de UI el usuario revisa el compositor en el navegador al cerrar cada fase, y el smoke real con cuatro redes (T-41, 4h) es tiempo humano que la IA no comprime.

---

## Resumen ejecutivo

Se rediseña el compositor de publicaciones para acercarlo al de Metricool, con identidad propia: una página a pantalla completa con un editor y contadores por red, un panel único de errores, una barra de acciones con «Publicar ahora» (también desde el calendario, la lista y el MCP), un texto propio por red, opciones por red que se recuerdan por marca, notas internas y un generador UTM. Es para un solo usuario con varias marcas.

El riesgo principal es publicar dos veces. El texto por red cambia lo que comparan Instagram y Facebook para reconocer lo ya publicado, y «Publicar ahora» convive con el despachador de cada minuto. Por eso el plan **no toca** `PublishPostTarget` ni los publicadores de Meta (ADR-005, O1) y fija las dos garantías con tests propios (T-15 y T-20) antes de construir encima. Cada fase termina con una revisión de robustez.

### Objetivos

- Un compositor a pantalla completa con contadores por red que coinciden con los del servidor (puntos de código) y un tope de 30 hashtags en Instagram validado en servidor y MCP.
- «Publicar ahora» desde el compositor, el calendario, la lista y el MCP, que **nunca** encola ni publica dos veces el mismo destino.
- Un texto propio por red que un reintento tras un timeout **no duplica** en Instagram ni en Facebook.
- Opciones por red (YouTube y TikTok) que se recuerdan por marca y red, sin perder el marcador de idempotencia de YouTube.
- Notas internas editables en cualquier post y un generador UTM.
- Paridad completa con el MCP en cada cambio de modelo; E2E con axe y sin desbordamiento a 375 y 360 px.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (spec aprobada, decisiones de diseño del 2026-10-23)
- [x] **Alcance** cerrado: C-01, C-08 (con Publicar ahora fuera del compositor), C-03, C-04, C-09, C-05a y C-11; aplazados C-02, C-05b, C-06, C-07 y C-10
- [x] **Criterios de éxito / métricas** acordados (CA-01 a CA-28, CA-32 y CA-33 de la spec)
- [x] **Accesos y credenciales** necesarios: entorno local con `php artisan serve` y `npm run test:e2e`; el smoke (T-41) necesita las cuentas reales del usuario
- [x] **Entornos** disponibles: local (`http://localhost:8080`, `APP_ENV=e2e`); producción en Hestia
- [x] **Stakeholders** identificados: el usuario valida cada fase en el navegador
- [x] **Dependencias externas** mapeadas: documentación vigente de YouTube, TikTok y Meta (T-26)
- [x] **Restricciones** conocidas: migraciones solo aditivas, no tocar al usuario id 28, `PublishPostTarget` intacto
- [ ] **Tarifa/hora y supuestos de coste** confirmados (50 €/h por defecto; sin `.claude/rates.json`)

---

## Análisis de impacto

- **`app/Services/PostService.php`** — `plan()` y `fill()` por texto efectivo y por destino; `schedule()` copia `text`; métodos nuevos `publishNow()` y `updateNotes()`.
- **`app/Services/PublishRequestFactory.php`** — `make()` y `makeReconcile()` usan el texto efectivo del destino.
- **`app/Jobs/DispatchDuePosts.php`** — extrae `dueTargets(?Post)` para reutilizarla en Publicar ahora. **`PublishPostTarget.php` no cambia.**
- **`app/Connectors/Google/YouTubePublisher.php`** y **`app/Connectors/TikTok/TikTokPublisher.php`** — opciones por red (`openSession`, `postInfo`).
- **`app/Models/{PostTarget,Post,ComposerPreset}.php`**, **`database/migrations`** — `post_targets.text`, `posts.notes` y `composer_presets`, todo aditivo.
- **`app/Http/Requests/PostRequest.php`**, **`PostController.php`**, **`routes/web.php`** — reglas nuevas, `publish_now`, `POST posts/{post}/publish-now` y `PATCH posts/{post}/notes`.
- **`app/Services/{LibraryService,CalendarService,MediaValidator}.php`**, **`config/media_specs.php`** — texto por destino en la biblioteca, primer texto efectivo e indicador de nota en el calendario, tope de hashtags.
- **`app/Mcp/Tools/*`** y **`SchedulerServer.php`** — `PostPayload`, `presentPost`, `CreatePost`, `UpdatePost`, y las herramientas nuevas `publish_post_now` y `update_post_notes`.
- **`resources/js/{Layouts,Composables,Components/Composer,Pages}`** — `ComposerLayout`, `useComposer`, `useLiveValidation` y 14 componentes nuevos; `Compose.vue` pasa a orquestador fino; acción en `Calendar/Index.vue`.
- **`tests/`**, **`docs/`** — Pest, E2E (`composer.spec.ts`, `flows.spec.ts`, `pages.spec.ts`), guía de usuario y módulos.

---

## Cambios arquitectónicos

- **O1 de ADR-005: texto efectivo resuelto al leer.** `PostTarget::effectiveText()` es la única fuente del texto de un destino. `findPublished` de Instagram y Facebook ya compara `$request->text`, que pasa a ser el efectivo con un solo cambio en la factoría. No se congela el texto enviado ni se añade un estado nuevo (descartadas O2 y O3 por tocar el núcleo y desbordar el presupuesto).
- **Doble despacho: defensas existentes, sin tocar.** `ShouldBeUnique` por destino y el `claim()` con `lockForUpdate` de `PublishPostTarget`. «Publicar ahora» despacha con `DB::afterCommit` (la cola `database` tiene `after_commit=false`) a través de la misma `dueTargets()` que el despachador del minuto.
- **Un post con N destinos.** `post_targets.text` nullable (`null` = vinculado). Al guardar no se crea un post por red.
- **Reglas en un solo sitio.** `PostRequest::rules()` y `PostService` son la fuente de la web y del MCP: ninguna regla queda solo en el cliente.
- **Los presets solo los escribe la web** (`ComposerPresetService::remember()`); el MCP no los toca (decisión del usuario).
- **Los tags de YouTube conservan el marcador.** `targetTag` va primero y se reserva su hueco.

---

## Archivos a crear/modificar

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `database/migrations/2026_11_09_090000_add_text_to_post_targets_table.php` | Crear | `post_targets.text` nullable (T-18) |
| `database/migrations/2026_11_10_090000_create_composer_presets_table.php` | Crear | `composer_presets` (T-29) |
| `database/migrations/2026_11_11_090000_add_notes_to_posts_table.php` | Crear | `posts.notes` (T-34) |
| `app/Models/ComposerPreset.php`, `app/Services/ComposerPresetService.php` | Crear | Presets por marca y red |
| `app/Mcp/Tools/PublishPostNow.php`, `app/Mcp/Tools/UpdatePostNotes.php` | Crear | Herramientas MCP nuevas |
| `app/Services/PostService.php`, `app/Services/PublishRequestFactory.php`, `app/Jobs/DispatchDuePosts.php` | Modificar | Texto efectivo, Publicar ahora y `dueTargets()` |
| `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/TikTok/TikTokPublisher.php` | Modificar | Opciones por red |
| `app/Http/Requests/PostRequest.php`, `app/Http/Controllers/{PostController,LibraryController}.php`, `routes/web.php` | Modificar | Reglas, rutas y props |
| `app/Services/{LibraryService,CalendarService,MediaValidator}.php`, `config/media_specs.php` | Modificar | Biblioteca, calendario y hashtags |
| `app/Mcp/Tools/{PostPayload,SchedulerTool,GetPost,CreatePost,UpdatePost}.php`, `app/Mcp/Servers/SchedulerServer.php` | Modificar | Paridad del MCP |
| `resources/js/Layouts/ComposerLayout.vue`, `resources/js/Composables/{useComposer,useLiveValidation,textCount,utm}.js` | Crear | Layout y composables |
| `resources/js/Components/Composer/*.vue` | Crear | 14 componentes del compositor |
| `resources/js/Pages/Posts/Compose.vue`, `resources/js/Pages/Calendar/Index.vue` | Modificar | Orquestador fino y acción «Publicar ahora» |
| `tests/Feature/{Publishing,Posts,Library,Calendar,Mcp}/**`, `tests/Unit/**` | Crear | Doble publicación, texto efectivo, presets, notas y paridad |
| `tests/e2e/composer.spec.ts`, `tests/e2e/{flows,pages,helpers}.ts` | Crear / modificar | E2E con axe y sin desbordamiento |
| `docs/usuario/guia-de-usuario.md`, `docs/modulos/{publicacion,mcp,calendario}.md` | Modificar | Documentación |
| `docs/roadmap/2026-10-23-post-composer/testing/*.md` | Crear | Verificación de campos (T-26) y smoke (T-41) |

---

## Dependencias y prerequisitos

- Orden fijo C-01 → C-08 → C-03 → C-04 → (C-09, C-05a) → C-11, el de la evaluación: C-01 es la base de la UI; C-08 da errores y Publicar ahora con poco riesgo; C-03 va antes que C-04 porque los presets y la UI usan el texto efectivo.
- T-12 (`publishNow()`) debe estar antes que T-13 y T-15; T-19 (`fill()` por destino) antes que T-20 y T-23.
- T-26 (verificar campos ⚠️) condiciona T-27, T-28 y T-30.
- ADR-005 sigue en `propuesta`: pasa a `aceptada` solo con el visto bueno del usuario (T-42).
- El smoke (T-41) necesita las cuentas reales del usuario y la cuota de YouTube (≈ 6 subidas al día).
- Sin dependencias externas nuevas (sin paquetes npm ni composer).

---

## Criterios de aceptación (global)

- [ ] CA-01 a CA-05, CA-08 a CA-17, CA-24 a CA-28, CA-32 y CA-33 de la spec cumplidos (los de C-02, C-05b, C-06, C-07 y C-10 quedan fuera)
- [ ] Un reintento con texto propio por destino no duplica en Instagram ni en Facebook: `--filter=TargetTextIdempotency` en verde (T-20)
- [ ] «Publicar ahora» junto con el despachador de cada minuto no encola ni publica dos veces: `--filter=PublishNowDoubleDispatch` en verde (T-15)
- [ ] `git diff --stat main -- app/Jobs/PublishPostTarget.php app/Connectors/Meta` vacío
- [ ] Paridad del MCP: texto por destino, opciones, notas, `publish_post_now` y `update_post_notes` con las mismas reglas que la web
- [ ] Las tres migraciones solo añaden; ningún dato del usuario id 28 se toca
- [ ] `php artisan test --compact`, `vendor/bin/pint --test`, `npm run build` y `npm run test:e2e` (desktop, mobile y mobile-360) en verde; axe sin violaciones serias ni críticas; sin desbordamiento a 375 y 360 px
- [ ] Cada fase cerrada con su revisión de robustez firmada (T-08, T-17, T-25, T-33, T-38 y T-42)

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| **Publicación duplicada** por la idempotencia de Instagram y Facebook, que compara la descripción, al introducir el texto por destino | Media | Crítico | `findPublished` y `reconcile.text` usan el texto efectivo (viejo en la reconciliación). T-20: reintento tras un timeout en cuatro escenarios por red, con prueba de mutación manual. Revisión T-25 |
| **Doble despacho**: «Publicar ahora» y `dispatch-due-posts` en el mismo minuto, o doble clic o doble petición | Media | Alto | Defensas existentes de `PublishPostTarget` sin tocar. T-15 ejecuta los dos despachos y los dos jobs y cuenta una sola publicación. Botón bloqueado mientras envía. Revisión T-17 |
| El job se encola antes del commit (`after_commit=false`) y el claim ve el estado viejo | Media | Medio | `DB::afterCommit` en `publishNow()`; test con `Queue::fake` (T-12) |
| `fill()` guarda el texto efectivo **nuevo** como viejo, o `schedule()` pierde el texto propio al programar un borrador | Media | Alto | Se calcula el viejo antes de escribir; tests de los tres casos (T-19) |
| Los tags del usuario en YouTube expulsan el marcador `targetTag` | Media | Alto | Marcador primero, hueco reservado, recorte; test con `Http::fake` (T-27) y revisión T-33 |
| Campos de API distintos de lo supuesto (`notifySubscribers`, etiqueta IA, `brand_*_toggle`) | Alta | Bajo | Verificación en la documentación vigente (T-26); lo no confirmado se oculta (CA-16) |
| Regresión del compositor al dividir `Compose.vue` | Media | Alto | T-01 primero y sin cambiar comportamiento; `ComposeTest.php` (13 tests) y E2E existentes en verde; revisión T-08 |
| `DateTimePicker` propio con problemas de accesibilidad | Media | Medio | `input datetime-local` nativo de respaldo; axe y teclado (T-10, T-16, M-03) |
| Paridad del MCP olvidada en algún cambio de modelo | Media | Medio | Una tarea de paridad por cambio (T-13, T-23, T-31, T-35) y regresión en T-40 |
| Una nota o un texto del usuario se pinta como HTML | Baja | Alto | Sin `v-html` en el compositor (comprobación por `grep` en T-08); revisión T-38 |
| Datos del usuario id 28 tocados por una prueba | Baja | Alto | Solo `e2e-sec-*` y marcas `e2e-`; comprobación por `grep` en T-42 |
| Ampliación de alcance (C-02, C-06, C-10 son atractivas) | Alta | Medio | Alcance cerrado en este plan; lo aplazado se decide al cerrar |
| El smoke depende de cuentas reales, de la cuota de YouTube y de TikTok en borrador | Media | Medio | T-41 puede quedar pendiente del usuario sin bloquear el resto |
| Dos destinos de la misma cuenta con el mismo texto efectivo se confunden en `findPublished` (problema previo) | Baja | Medio | Fuera de alcance; se documenta en `publicacion.md` (T-40) |

---

## Métricas de éxito

- 0 publicaciones duplicadas en los tests de reintento y de doble despacho, y en el smoke real (T-41).
- `git diff --stat main -- app/Jobs/PublishPostTarget.php app/Connectors/Meta` vacío al cerrar.
- Suite Pest y E2E en verde con los tres proyectos (desktop, mobile 375, mobile-360) y 0 violaciones serias o críticas de axe en compositor y calendario.
- El contador del cliente coincide con el del servidor en los casos con emojis compuestos.
- Una publicación por red con las opciones aprobadas registrada en `testing/smoke-real.md`.

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-23 | Creación del plan: 6 fases, 42 tareas, 142,0h base (170,4h con margen) | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre la rama `feature/post-composer`, marcando `tasks.md` como **ledger canónico** (checkbox + estado por tarea). Al terminar cada fase, revisión de dos lentes; al final, handoff a `qa` con el `test-plan.md` y cierre con `documenter`.
