---
design: design.md
test-plan: test-plan.md
generacion:
  inicio: 2026-10-19T15:28:30Z
  fin: 2026-10-19T15:34:45Z
  fuente: medido          # usage-meter.py close
  tokens_reales: { entrada: 18, salida: 56962, cache_creacion: 66893, cache_lectura: 898140 }
  eur: null               # precioTokens no fiable (no hay .claude/rates.json) - ejecuta rates-verify
  horas_ia: 0.41
  duracion: 25m
  ratio_usado: 300000     # default no calibrado
---

# 2026-10-19-social-scheduler

> Plan de implementación de Social Scheduler: aplicación web personal (Laravel 13 nativo en HestiaCP) para programar, publicar y analizar contenido en Instagram, Facebook, YouTube y TikTok, con informes y servidor MCP; arranca con un spike de viabilidad de plataformas y una puerta de decisión (G1).

| | |
|---|---|
| **Fecha** | 2026-10-19 |
| **Estado** | en-progreso |
| **Tipo** | Nueva Funcionalidad |
| **Prioridad** | Alta 🟠 |
| **Solicitante** | Usuario del proyecto (vía `/pm-cycle`) |
| **Responsable** | Usuario del proyecto |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | [`evaluation.md`](evaluation.md) — veredicto «go condicionado» |
| **Diseño** | [`design.md`](design.md) — opción elegida O1 (Laravel 13 monolito nativo en HestiaCP) · [`ADR-001`](../../knowledge/adr/ADR-001-stack-laravel-nativo-hestia.md) |
| **Test-plan** | [`test-plan.md`](test-plan.md) |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **606h** (505h base +20 %) | 0h | Media-Baja |
| Tiempo IA (ejecución) | **91.6h** (76.3h base +20 %) (+ 22.9h supervisión) | 0h | Baja |
| Coste total | **30.300 €** + tokens (⚠️ verificar precio) | 0 € | Media (parte humana) |
| Tokens IA | **23,00M** (in 20,91M / out 2,09M) ≈ 23,0M facturables | 0 | Baja |
| Multiplicador productividad | **×3,2** | — | — |
| Tareas | **43** | 0 hechas | — |

> Las horas humanas (lo que costaría a una persona) y las horas IA (plazo con agentes) son magnitudes distintas y **no se suman** (doctrina LES-007). Cifras heredadas de `evaluation.md` (606 h / 30.300 €); este plan solo las reparte en tareas.

---

## 🚦 Puerta de decisión G1 — spike de viabilidad de plataformas

Condición del «go condicionado» de la evaluación. **El primer bloque del plan (Fase 0, T-01…T-05, 10 h base) es el spike; el plan no avanza a la Fase 1 hasta que el usuario decide en T-05.**

| Qué se comprueba | Tarea | Resultado que decide |
|---|---|---|
| Meta en modo desarrollo: publicar IG/FB (post, reel, story) y leer insights de las cuentas propias; método de login de IG; `impressions` frente a `views` | T-02 | Si basta el modo desarrollo o hace falta App Review |
| TikTok sin auditoría: visibilidad alcanzable (`SELF_ONLY`), dominio verificado para PULL_FROM_URL | T-03 | Si la publicación automática en TikTok tiene valor sin auditoría |
| YouTube con proyecto no verificado: visibilidad, refresh token de 7 días en Testing, cuota (~1.600 u/subida sobre 10.000 u/día) | T-04 | Si el cupo cubre el volumen del usuario y si los vídeos quedan privados |

**Decisión del usuario por red (T-05):** A) publicar con la limitación; B) tramitar auditoría/verificación y esperar; C) solo calendario y recordatorio sin publicación automática; D) posponer la red. Las tareas **T-13, T-14, T-26, T-27, T-33 y T-34** (OAuth, publicación y analítica de YouTube/TikTok) no empiezan sin esa decisión y se cancelan o se redimensionan según la respuesta. Meta (IG + FB) es el núcleo del valor y sigue en cualquier caso.

---

## Estimación por fase

Horas **base** (sin colchón) y coste con el margen de contingencia del 20 % aplicado (`base × 1,2 × 50 €/h`), sin tokens.

| Fase | Origen (evaluación) | Tareas | Base (h) | Con margen (h) | Tokens (in / out) | Coste € (sin tokens) |
|------|---------------------|-------|---------|----------------|-------------------|----------------------|
| Fase 0 — Spike de viabilidad de plataformas (puerta G1) | C-02 (spike 8–12h) | 5 | 10 | 12 | 415k / 40k | 600 |
| Fase 1 — Núcleo: Laravel, auth, marcas, aislamiento y despliegue en Hestia | C-01 | 5 | 60 | 72 | 2,48M / 248k | 3.600 |
| Fase 2 — Conexiones OAuth por red | C-02 (sin el spike) | 6 | 60 | 72 | 2,48M / 247k | 3.600 |
| Fase 3 — Publicación base y Meta (IG + FB), calendario | C-03 parte 1 | 9 | 142 | 170.4 | 5,88M / 588k | 8.520 |
| Fase 4 — Publicación en YouTube (condicionada a G1) | C-03 parte 2 | 1 | 18 | 21.6 | 745k / 75k | 1.080 |
| Fase 5 — Publicación en TikTok (condicionada a G1) | C-03 parte 3 | 1 | 16 | 19.2 | 662k / 66k | 960 |
| Fase 6 — Servidor MCP: núcleo, API keys y herramientas de publicación | C-06a | 3 | 37 | 44.4 | 1,53M / 154k | 2.220 |
| Fase 7 — Analítica: snapshots, insights y dashboards | C-04 | 7 | 106 | 127.2 | 4,39M / 439k | 6.360 |
| Fase 8 — Reporting PDF/CSV | C-05 | 4 | 40 | 48 | 1,66M / 166k | 2.400 |
| Fase 9 — MCP estadísticas/informes y validación final | C-06b + cierre | 2 | 16 | 19.2 | 662k / 66k | 960 |
| **Total** | | **43** | **505h** | **606h** | **20,91M / 2,09M** | **30.300 €** |

**Herencia de la evaluación (sin re-estimar):** C-01 60 h → Fase 1 · C-02 70 h → Fase 0 (10 h) + Fase 2 (60 h) · C-03 180 h → Fases 3 (142 h), 4 (18 h) y 5 (16 h) · C-06 45 h → Fase 6 (37 h) + parte de Fase 9 (8 h) · C-04 110 h → Fase 7 (106 h) · C-05 40 h → Fase 8 · el resto (8 h de ejecución final T-43) sale de C-04 (−4 h, T-37) y C-03 (−4 h, compositor T-23 y calendario T-24). Total base 505 h = evaluación. **Diferencias declaradas:** solo reasignaciones internas; el spike (10 h) está dentro de C-02, no es coste adicional.

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Todos los importes en **EUR**.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default del fragmento compartido (no existe `.claude/rates.json`) |
| Modelo IA asumido | claude-opus-4-8 | Default del fragmento compartido |
| Precio input | ⚠️ verificar € / 1M tokens | Sin precio fiable; ejecutar la skill `rates-verify` |
| Precio output | ⚠️ verificar € / 1M tokens | Ídem |
| Tipo de cambio | 1 USD = 0.92 € | Default |
| Margen de contingencia | 20 % | Sobre horas base (humanas e IA) |
| Supervisión | 25 % de las horas IA | Default |
| Ratio tokens→hora IA | 300.000 tok/h ⚠️ no calibrado | No existe `docs/roadmap/CALIBRATION.md`; el histórico del plugin indica sobreestimación de horas IA |
| FTE | 160 h/mes | Default |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 505h × 50 €/h | 25.250 € |
| Margen de contingencia | +20 % sobre desarrollo base (101h) | 5.050 € |
| Tokens IA (input) | 20,91M tok × P_in | ⚠️ verificar |
| Tokens IA (output) | 2,09M tok × P_out | ⚠️ verificar |
| **Total estimado (con margen)** | | **30.300 € + tokens** |

> Costes externos no incluidos: VPS, dominio, almacenamiento R2 (decenas de €/mes en uso personal, ⚠️ verificar) y posibles trámites de auditoría de TikTok/Google (sin tarifa, pero con tiempo). El coste de proceso de spec, evaluación, diseño y plan (~250k tokens facturables) se mide en el bloque `generacion:` de cada documento.

---

## Previsión de tokens (por fase)

Base: claude-opus-4-8 · precios de la tabla de supuestos (⚠️ verificar).

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 0 | 415k | 40k | 455k | ⚠️ verificar |
| Fase 1 | 2,48M | 248k | 2,73M | ⚠️ verificar |
| Fase 2 | 2,48M | 247k | 2,73M | ⚠️ verificar |
| Fase 3 | 5,88M | 588k | 6,47M | ⚠️ verificar |
| Fase 4 | 745k | 75k | 820k | ⚠️ verificar |
| Fase 5 | 662k | 66k | 728k | ⚠️ verificar |
| Fase 6 | 1,53M | 154k | 1,69M | ⚠️ verificar |
| Fase 7 | 4,39M | 439k | 4,83M | ⚠️ verificar |
| Fase 8 | 1,66M | 166k | 1,82M | ⚠️ verificar |
| Fase 9 | 662k | 66k | 728k | ⚠️ verificar |
| **Total** | **20,91M** | **2,09M** | **23,00M** | **⚠️ verificar** |

**Método de estimación:** heredado de la evaluación: ~41,4k tokens de entrada y ~4,1k de salida por hora humana base de código de producto (≈10 % salida), repartido por horas de tarea. Es supuesto sin calibrar. La Fase 0 consume pocos tokens porque es trabajo mayormente humano (consolas externas); se asigna un 8 % de horas IA frente a horas humanas.

---

## Productividad IA (humano vs. IA)

Cifras aproximadas; las horas IA son un supuesto (tokens ÷ 300.000 tok/h, sin calibrar).

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 606 h (505 h base +20 %) |
| Horas IA (ejecución) | 91.6 h (76.3 h base +20 %) |
| Supervisión humana (25 % de horas IA) | 22.9 h |
| Revisión, QA manual y trámites de plataforma (no delegable) | 73.2 h (61 h base +20 %) |
| **Horas totales (IA + supervisión + no delegable)** | **187.7 h** |
| Horas ahorradas | 418.3 h |
| **Ahorro** | **69,0 %** |
| **Multiplicador de productividad** | **×3,2** |
| FTE equivalentes *(opcional)* | 2.6 |

> Horas con el margen del 20 % ya aplicado. La fila «no delegable» (crear/configurar apps en Meta, Google y TikTok, App Review y auditorías, despliegue en el VPS y publicar de verdad los 13 tipos de contenido en cuentas reales) se presupuesta aparte según LES-001/LES-009: C-01 6 h · C-02 20 h · C-03 20 h · C-04 8 h · C-05 3 h · C-06 4 h (base). Las horas IA se calibrarán con `/retro`.

---

## Resumen ejecutivo

Se construye una aplicación web de uso personal, al estilo de Metricool, para conectar Instagram, Facebook, YouTube y TikTok, programar y publicar 13 tipos de contenido, guardar un histórico propio de métricas, generar informes PDF/CSV y exponer todo a un agente de IA mediante un servidor MCP. Se implementa como un monolito **Laravel 13 nativo en HestiaCP** (opción O1 del diseño) con cola en base de datos. El plan empieza por un **spike de 10 h** que valida qué permiten realmente las plataformas y se detiene en una **puerta de decisión (G1)** antes de invertir en la publicación automática de TikTok y YouTube, que es donde está el mayor riesgo externo.

### Objetivos

- Confirmar la viabilidad de Meta, TikTok y YouTube en 10 h antes de construir el resto, con decisión explícita del usuario por red (G1).
- Publicar automáticamente a la hora programada, sin duplicados, en las redes viables (CA-06, CA-07), con aislamiento estricto por usuario en web, API y MCP (CA-02, CA-12).
- Acumular snapshots diarios y ofrecer dashboards e informes PDF/CSV por marca y rango de fechas (CA-09, CA-10, CA-11).
- Desplegar en el VPS con HestiaCP con workers systemd y media en R2.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (spec aprobada, decisiones 1–9)
- [x] **Alcance** cerrado (spec §Alcance; fuera: billing, otras redes, inbox, editor, transcodificación, OAuth 2.1 del MCP)
- [x] **Criterios de éxito / métricas** acordados (CA-01…CA-12)
- [ ] **Accesos y credenciales** necesarios: SSH/root al VPS confirmado; **pendiente** crear apps de desarrollador (T-01)
- [ ] **Entornos** disponibles: local Laragon (`metricool.test`) y VPS Hestia; **pendiente** bucket R2 y dominio propio sobre él
- [x] **Stakeholders** identificados (el usuario valida las puertas)
- [ ] **Dependencias externas** mapeadas (Meta, Google, TikTok, R2); acceso real sin verificar hasta el spike
- [ ] **Restricciones** conocidas (HestiaCP, MariaDB ≥ 10.6 / MySQL ≥ 8.0, sin Redis en v1); sin deadline ni presupuesto declarados
- [ ] **Tarifa/hora y supuestos de coste** confirmados (defaults; sin `.claude/rates.json`)

---

## Análisis de impacto

Proyecto **greenfield**: todo es nuevo. Rutas según la convención de Laravel 13 (design.md §5).

- **`app/Models/`** — dominio (`User`, `Brand`, `SocialAccount`, `Post`, `PostTarget`, `MediaAsset`, `PublishAttempt`, snapshots, `Report`) y trait `Concerns/BelongsToUser`
- **`app/Services/`** — capa de servicios única para web y MCP; toda consulta pasa por el usuario autenticado
- **`app/Connectors/{Meta,Google,TikTok}/`** — OAuth, publicación, polling e insights tras interfaz común `Contracts/`
- **`app/Jobs/ y routes/console.php`** — publicación, polling, refresco de tokens, snapshots, informes y dispatcher
- **`app/Mcp/ y routes/ai.php`** — servidor MCP con `auth:sanctum` y herramientas finas
- **`resources/js/Pages/`** — Inertia + Vue: marcas, conexiones, compositor, calendario, dashboards, informes, API keys
- **`database/migrations/`** — esquema MariaDB/MySQL + `jobs`, `failed_jobs`, `personal_access_tokens`
- **`deploy/hestia/ y deploy/systemd/`** — despliegue en Hestia, cron y workers systemd
- **`spike/`** — scripts desechables del spike (Fase 0), fuera del código de producción
- **`tests/Feature/Isolation/`** — aislamiento web, API y MCP desde la Fase 1

---

## Cambios arquitectónicos

- **Opción O1 (design.md, ADR-001):** monolito Laravel 13 servido por el PHP-FPM de Hestia; este plan no rediseña la arquitectura, la ejecuta.
- **Cola en MariaDB/MySQL** (driver `database`, requiere `SKIP LOCKED`: MariaDB ≥ 10.6 / MySQL ≥ 8.0) en vez de Redis; Horizon queda como ampliación.
- **Aislamiento solo de aplicación** (sin RLS en MariaDB/MySQL): trait `BelongsToUser` + Policies + suite de aislamiento desde T-08.
- **Media en R2** con subida prefirmada navegador→R2; el binario no pasa por PHP-FPM; dominio propio sobre el bucket para TikTok.
- **Workers como unidades systemd** (`--max-time`, `--max-jobs`, `Restart=always`); plan B con cron de Hestia y `--stop-when-empty`.
- **MCP** con `laravel/mcp` + Sanctum (API key personal); OAuth 2.1 vía Passport queda fuera de v1.
- **Spike antes que construcción:** los scripts de `spike/` no migran a `app/`; solo se reutilizan los hallazgos.

**Preguntas abiertas del diseño que se cierran en el plan:** PDF (Chromium o DomPDF) en T-39 · frontend Inertia+Vue por defecto en T-07 · conector TikTok con cliente propio en T-14.

---

## Archivos a crear/modificar

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `spike/ , docs/roadmap/2026-10-19-social-scheduler/spike-report.md` | Crear | Spike de viabilidad y su informe (Fase 0, puerta G1) |
| `composer.json, app/, config/, routes/, bootstrap/` | Crear | Esqueleto Laravel 13 (T-06) |
| `app/Models/, app/Services/, app/Policies/` | Crear | Dominio y capa de servicios con aislamiento |
| `app/Connectors/` | Crear | Conectores Meta, Google y TikTok |
| `app/Jobs/, routes/console.php` | Crear | Cola, scheduler y workers |
| `app/Mcp/, routes/ai.php` | Crear | Servidor MCP |
| `resources/js/Pages/` | Crear | Pantallas Inertia |
| `database/migrations/` | Crear | Esquema |
| `deploy/hestia/, deploy/systemd/` | Crear | Despliegue |
| `tests/` | Crear | Unit, Feature, aislamiento y E2E (Playwright) |
| `docs/roadmap/2026-10-19-social-scheduler/{spec,evaluation,design}.md` | Modificar | Enlaces `plan:` hacia este plan |
| `docs/roadmap/README.md` | Modificar | Fila del plan en el índice |

---

## Dependencias y prerequisitos

- **Puerta G1 (T-05):** todo el plan queda bloqueado hasta la decisión del usuario sobre TikTok/YouTube.
- Cuentas de desarrollador en Meta, Google Cloud y TikTok; cuentas IG Business/Creator vinculadas a una página de FB; canal de YouTube y cuenta de TikTok propios.
- Dominio con HTTPS (Hestia) para redirect URIs; bucket R2 con dominio propio.
- Acceso SSH/root al VPS (confirmado) con MariaDB ≥ 10.6 / MySQL ≥ 8.0 (verificar en T-06).
- Orden por dependencias: F0 → F1 → F2 → F3 → (F4, F5 según G1) → F6 → F7 → F8 → F9. T-31 (snapshots) puede adelantarse tras F2.

---

## Criterios de aceptación (global)

- [ ] `spike-report.md` existe con veredicto por plataforma y la decisión G1 del usuario antes de empezar la Fase 1 (T-05).
- [ ] CA-01…CA-12 de la spec verificados por los E2E-01…E2E-12 del `test-plan.md` (`npx playwright test` verde).
- [ ] `php artisan test` verde, incluida la suite `tests/Feature/Isolation/` sobre web, API y MCP.
- [ ] Aplicación desplegada en el VPS con HTTPS, workers systemd y scheduler activos (M-04); media en R2.
- [ ] Tokens OAuth cifrados en reposo (verificado en BD) en T-11.
- [ ] Los 13 tipos de contenido publicados de verdad en cuentas de prueba, salvo los pospuestos por G1 (M-01).

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| TikTok sin auditoría solo permite publicación privada | Alta | Alto | Spike T-03 y puerta G1 antes de invertir en T-14/T-27 |
| YouTube: cuota (~6 subidas/día), vídeos privados en proyecto no verificado, refresh de 7 días en Testing | Alta | Medio-Alto | Spike T-04; opciones B/C/D en G1 |
| Meta App Review o permisos de insights no concedidos | Media | Alto | Spike T-02; operar en modo desarrollo con cuentas con rol en la app (⚠️ verificar) |
| Duplicados o pérdidas de publicación por timeout o caída del VPS | Media | Alto | Estado `publicando` persistido, unicidad por `post_target_id`, consulta del estado externo, recuperación de vencidas (T-20) |
| Fallo de aislamiento entre usuarios | Baja | Alto | Suite de aislamiento desde T-08, reutilizada en MCP (T-30) |
| `laravel/mcp` es 1.0 reciente | Media | Medio | Fijar versión menor; herramientas finas; plan B con proceso aparte (design.md §6) |
| Workers PHP con fugas o código obsoleto tras deploy | Media | Medio | `--max-time`, `--max-jobs`, `queue:restart` en deploy |
| Métricas retiradas o histórico corto (impresiones) | Alta | Medio | Normalización desacoplada, «no disponible», snapshots desde T-31 |
| Chromium headless consume RAM | Media | Bajo | Concurrencia 1 en cola `reports`; alternativa DomPDF (T-39) |
| Pérdida del acceso root al VPS | Baja | Medio | Plan B documentado en T-10 |

---

## Métricas de éxito

- Tasa de publicaciones programadas que terminan `publicada` sin duplicados ≥ 99 % en el primer mes de uso (medida con `publish_attempts`).
- Un snapshot diario por cuenta conectada durante 30 días seguidos, base de CA-10.
- 0 accesos cruzados entre usuarios en la suite de aislamiento y en las pruebas manuales.
- Desviación del esfuerzo respecto a las 505 h base ≤ 20 % (margen de contingencia).

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-10-19 | Creación del plan (`borrador`): 10 fases, 43 tareas, spike + puerta G1 como primer bloque | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario (puerta de control), el agente **`implementer`** lo ejecuta fase a fase sobre una rama, marcando `tasks.md` como **ledger canónico**. Empieza por la **Fase 0 (spike)** y se detiene en la **puerta G1** (T-05) para recoger la decisión del usuario sobre TikTok y YouTube. Al terminar, handoff a `qa` (ejecuta `test-plan.md`) y cierre con `documenter`. Jira: posible volcado con `jira-sync` (opt-in, no ofrecido de forma interactiva en esta pasada).
