---
design: n/a
test-plan: test-plan.md
generacion:
  inicio: 2026-11-11T08:09:29Z
  fin: 2026-11-11T08:18:42Z
  fuente: medido
  fuente_id: src-27942ecb8c8f8e0df6250f9955389a338ff8d0e3b5093fe5fd4071d2b3cb4a12
  ventana_id: win-78039e9ac4f442d4bc85fcf1a5f90ae6
  revision_medicion: 1
  medicion_persistida: true
  tokens_reales: { entrada: 24, salida: 90497, cache_creacion: 102127, cache_lectura: 1226571 }
  eur: null
  horas_ia: 0.64
  duracion: 38m
  ratio_usado: 300000
  # ventana compartida con tasks.md
---

# 2026-11-11-audit-hardening

> Dejar la aplicación perfectamente implementada, con todas las funciones operativas, y ampliarla: plan de 8 fases a partir de la auditoría integral.

| | |
|---|---|
| **Fecha** | 2026-11-11 |
| **Estado** | borrador |
| **Tipo** | Refactor / Bugfix / Infra / Nueva Funcionalidad |
| **Prioridad** | Alta |
| **Solicitante** | usuario |
| **Responsable** | usuario (merges) · agentes implementer/revisor/qa |
| **Spec** | [`spec.md`](spec.md) |
| **Evaluación** | n/a (la fuente es [`audit.md`](audit.md)) |
| **Diseño** | n/a |

---

## Cuadro de mando

| Métrica | Estimado | Real | Confianza |
|--------|---------|------|-----------|
| Tiempo humano | **874.8h** (729.0h base +20 %) | 0h | Media |
| Tiempo IA (ejecución) | **220.3h** (+ 55.1h supervisión) | 0h | Baja (ratio no calibrado) |
| Coste total | **43740 €** + tokens ⚠️ verificar | 0 € | Media |
| Tokens IA | **34965k** (in 27540k / out 7425k) | 0 | Baja |
| Multiplicador productividad | **×3.2** | — | — |
| Tareas | **108** | 0 hechas | — |

> Todas las cifras son **estimadas** (sin medición previa; no hay muestras calibradas en `CALIBRATION.md`). El coste de tokens no se calcula: el precio no está confirmado (`⚠️ verificar`, usar la skill `rates-verify`).

---

## Estimación por fase

| Fase | Tareas | Estimado base (h) | Tokens (in / out) | Coste € (base) |
|------|--------|-------------------|-------------------|---------|
| Fase 1 — Riesgo inmediato | 11 | 63.0 | 2400k / 655k |  3150 € + tokens ⚠️ |
| Fase 2 — CI y red de tests | 8 | 44.0 | 1680k / 460k |  2200 € + tokens ⚠️ |
| Fase 3 — Funciones incompletas | 16 | 118.0 | 4440k / 1190k |  5900 € + tokens ⚠️ |
| Fase 4 — Refactor del backend | 15 | 90.0 | 3420k / 930k |  4500 € + tokens ⚠️ |
| Fase 5 — Frontend | 18 | 119.0 | 4500k / 1215k |  5950 € + tokens ⚠️ |
| Fase 5b — Unificación | 8 | 64.0 | 2400k / 640k |  3200 € + tokens ⚠️ |
| Fase 6 — Operación y repositorio | 6 | 43.0 | 1620k / 435k |  2150 € + tokens ⚠️ |
| Fase 7 — Funciones nuevas | 24 | 177.0 | 6660k / 1785k |  8850 € + tokens ⚠️ |
| Fase 8 — QA integral y documentación | 2 | 11.0 | 420k / 115k |  550 € + tokens ⚠️ |
| **Total** | **108** | **729.0h** | **27540k / 7425k** | **36450 € + tokens ⚠️** |

---

## Presupuesto económico

**Coste = (horas × tarifa) + coste de tokens de IA.** Todos los importes en **EUR**; estimado, no medido.

### Supuestos (ajustables)

| Parámetro | Valor | Nota |
|-----------|-------|------|
| Tarifa de desarrollo | 50 €/h | Default del kit (no hay `.claude/rates.json`) |
| Modelo IA asumido | claude-opus-4-8 | Default del kit |
| Precio input / output | ⚠️ verificar | No confirmado: no se inventa; ejecutar `rates-verify` |
| Tipo de cambio | 1 USD = 0,92 € | Default del kit |
| Supervisión | ≈25 % de las horas IA | Default del kit |
| Margen de contingencia | 20 % | Sobre horas base (humanas e IA) |
| Ratio tokens→hora IA | 300.000 tok/h ⚠️ no calibrado | `CALIBRATION.md` sin muestras medidas |
| Tamaños | S = 3 h humanas / 0,8 h IA · M = 8 h humanas / 2 h IA | Las tareas L se parten en dos M |

### Desglose

| Concepto | Cálculo | Importe |
|----------|---------|---------|
| Desarrollo (humano, base) | 729.0h × 50 €/h | 36450 € |
| Margen de contingencia | +20 % sobre desarrollo base | 7290 € |
| Tokens IA (input) | 27540k tok × precio ⚠️ | ⚠️ verificar |
| Tokens IA (output) | 7425k tok × precio ⚠️ | ⚠️ verificar |
| **Total estimado (con margen)** | | **43740 € + tokens ⚠️** |

---

## Previsión de tokens (por fase)

Base: claude-opus-4-8 · precios por verificar.

| Fase | Input (tok) | Output (tok) | Total (tok) | Coste € |
|------|------------|-------------|-------------|---------|
| Fase 1 | 2400k | 655k | 3055k | ⚠️ verificar |
| Fase 2 | 1680k | 460k | 2140k | ⚠️ verificar |
| Fase 3 | 4440k | 1190k | 5630k | ⚠️ verificar |
| Fase 4 | 3420k | 930k | 4350k | ⚠️ verificar |
| Fase 5 | 4500k | 1215k | 5715k | ⚠️ verificar |
| Fase 5b | 2400k | 640k | 3040k | ⚠️ verificar |
| Fase 6 | 1620k | 435k | 2055k | ⚠️ verificar |
| Fase 7 | 6660k | 1785k | 8445k | ⚠️ verificar |
| Fase 8 | 420k | 115k | 535k | ⚠️ verificar |
| **Total** | **27540k** | **7425k** | **34965k** | **⚠️ verificar** |

**Método de estimación:** por tamaño de tarea: S = 120k in / 35k out; M = 300k in / 80k out (lectura de ficheros afectados + generación de código, tests y documentación + ronda de revisión adversarial). Incluye una vuelta de correcciones; no incluye reintentos por fallos de entorno.

---

## Productividad IA (humano vs. IA)

| KPI | Valor |
|-----|-------|
| Horas humanas estimadas | 874.8 h (729.0 h base +20 %) |
| Horas IA (ejecución) | 220.3 h (183.6 h base +20 %) |
| Supervisión humana | 55.1 h |
| **Horas totales (IA + supervisión)** | **275.4 h** |
| Horas ahorradas | 599.4 h |
| **Ahorro** | **68 %** |
| **Multiplicador de productividad** | **×3.2** |
| FTE equivalentes | 3.75 |

> Horas IA y supervisión son **supuestos**; la ejecución real incluye esperas de la batería E2E (serie, ~1,5 h por pasada completa hasta E-02), que no están en la estimación por tarea y que `T-QA` mide.

---

## Resumen ejecutivo

La auditoría integral de `feature/creative-social-suite` encontró 118 hallazgos (12 de riesgo inmediato, 20 funciones incompletas, 18 de refactor del backend, 21 de frontend, 20 de tests/CI/operación y 27 funciones nuevas). Este plan los ordena en 8 fases: primero el riesgo, luego la red de tests y CI, después completar funciones, refactorizar con esa red, operación y, al final, las funciones nuevas por valor/esfuerzo. Cada hallazgo está en una sola tarea o declarado fuera del plan con su motivo.

### Objetivos

- Cerrar los 12 riesgos inmediatos (A) y dejar la CI, las copias y los despliegues bajo control.
- Poder refactorizar sin miedo: E2E paralelizables, CI completa y tests deterministas antes de tocar el backend y el frontend.
- Dejar operativas las funciones hoy incompletas (B) sin depender de terceros cuando no es necesario.
- Entregar las funciones nuevas con mayor valor (F-01..F-20) y dejar documentadas las decisiones del usuario (F-21, F-26, F-27).

---

## Orden, dependencias y método

**Orden lineal:** las fases y las tareas se ejecutan en el orden de `tasks.md`. Las dependencias del ledger son el mínimo, no permiten paralelizar cambios de código (lección GOT-005: una sola ejecución de Playwright a la vez y nunca con cambios de código en paralelo).

| Fase | Por qué en este lugar | Puerta de salida |
|------|----------------------|------------------|
| 1 — Riesgo inmediato | Seguridad, pérdida de datos y fallos operativos primero; `E-07` y `A-01` abren la fase | Pest verde, `composer audit` limpio |
| 2 — CI y red de tests | Sin red no se refactoriza: E2E paralelizables, CI completa, tests deterministas | CI con todos los pasos y E2E estable |
| 3 — Funciones incompletas (B) | Aprovecha la red; completa lo que hoy está vacío o asimétrico | Pest + E2E de las funciones tocadas |
| 4 — Refactor backend (C) | Sin cambio de comportamiento; después de B para no refactorizar dos veces | Pest y E2E en verde antes y después de cada tarea |
| 5 — Frontend (D) | `D-13` (formato) primero; refactors grandes (D-11, D-12) al final | Build, typecheck, E2E antes y después |
| 5b — Unificación (U) | Convierte estudio y parte social en una sola aplicación; necesita los componentes comunes de la Fase 5 (T-53, T-56, T-57, T-60) | Build, E2E de unificación (E2E-41..E2E-47) y a11y en verde |
| 6 — Operación y repositorio | Cabeceras, rotación de claves, peso del repo, dependencias | Tests + doc verificada |
| 7 — Funciones nuevas (F) | Por valor/esfuerzo: F-01..F-04, luego F-05..F-12 (+F-16), después el resto | E2E de cada función |
| 8 — QA integral y documentación | Cierra con pasada completa y ficha de decisiones | `T-QA` en verde y ledger al 100 % |

**Método por tarea:** implementador → revisor adversarial A+B(+C) → correcciones → Pest y Playwright de los specs afectados → documentación actualizada como criterio de cierre.

**Restricciones permanentes:** sin Docker; sin proveedores reales ni red externa en las pruebas (`Http::fake`, fixtures, fakes de IA/SMTP/push); no editar `.env` (solo `.env.example`); no tocar los datos del usuario 28; los merges los hace el usuario; no se hace commit ni push desde el plan.

**Tareas que dependen del usuario o de terceros** (`U`, `V`, `[D]`): se implementa lo que se puede y lo pendiente queda anotado en `A la espera` de la tarea, **sin bloquear la fase**. Resumen en la sección «Pendiente de terceros» más abajo.

---

## Datos necesarios para un informe completo

- [x] **Requisitos funcionales** confirmados por el solicitante (objetivo: aplicación perfectamente implementada y ampliada)
- [x] **Alcance** cerrado (los 118 hallazgos de `audit.md`; ver trazabilidad)
- [x] **Criterios de éxito / métricas** acordados (ver `spec.md`)
- [ ] **Accesos y credenciales** necesarios (VPS, destino de copias, GitHub admin, cuentas reales de redes): a cargo del usuario, no bloquean
- [x] **Entornos** disponibles (local con SQLite y fakes)
- [x] **Stakeholders** identificados (usuario)
- [x] **Dependencias externas** mapeadas (sección «Pendiente de terceros»)
- [x] **Restricciones** conocidas (sección de restricciones permanentes)
- [ ] **Tarifa/hora y supuestos de coste** confirmados (default 50 €/h; precio de tokens ⚠️ verificar)

---

## Análisis de impacto

- **`app/Connectors/**`** — streaming Pinterest, analítica, primer comentario, `ContainerPublishFlow`, YouTube partido, conectores nuevos (Mastodon, Telegram).
- **`app/Services/**`** — `PostService` partido, poda y retención, inbox, API/MCP, funciones nuevas.
- **`app/Support/HealthCheck.php`, `routes/console.php`** — salud, sonda y poda programadas.
- **`resources/js/**`** — carga diferida, `http.js`, diálogos, flash, paginación, refactors del editor y páginas, tipos, tema oscuro.
- **`database/migrations`** — `post_target_media`, objetivos, webhooks, suscripciones push.
- **`deploy/**`, `.github/workflows/**`** — colas, copias, despliegue, protección de `main`, CI y nocturno.
- **`tests/**`, `playwright*.config.ts`** — guardia HTTP global, E2E paralelos, fixtures, tests nuevos.
- **`docs/**`** — un módulo por tarea, CHANGELOG, índices y ficha de decisiones.

---

## Cambios arquitectónicos

- Tabla `post_target_media` con FK `restrictOnDelete` en lugar de JSON sin integridad (A-12).
- `MassPrunable` + `config/retention.php` como política única de retención (A-08).
- `ContainerPublishFlow`, `UploadPassBudget`, `YouTubeClient` y fachada `PostService` (refactor sin cambio de comportamiento).
- Capacidades de conector (`CommentSource`, `HidesComments`) que derivan listas en lugar de repetirlas (C-07).
- `http.js`, `BaseDialog`/`ConfirmDialog` y `usePolling` como piezas comunes del frontend.
- Eventos de dominio + webhooks firmados como extensión sin tocar los conectores (F-06).

---

## Archivos a crear/modificar

La lista exacta por tarea está en el campo `Archivos` de cada tarea de `tasks.md` (alcance declarado: lo que no esté ahí es fuera de alcance). Resumen por zona:

| Zona | Acción | Propósito |
|------|--------|-----------|
| `app/Connectors/**`, `app/Services/**` | Modificar/Crear | Funciones B, refactors C, funciones F |
| `resources/js/**` | Modificar/Crear | Fase 5 y UI de funciones B/F |
| `database/migrations/**` | Crear | `post_target_media`, objetivos, webhooks, push |
| `deploy/**`, `.github/workflows/**` | Crear/Modificar | Colas, copias, despliegue, CI, nocturno |
| `tests/**`, `playwright*.config.ts` | Crear/Modificar | Red de tests y cobertura nueva |
| `docs/**`, `CHANGELOG*.md` | Modificar | Documentación como criterio de cierre |

---

## Dependencias y prerequisitos

- `audit.md` aprobada como fuente única.
- `E-07` y `A-01` antes que cualquier otra tarea; fase 2 antes de las fases 4 y 5.
- `A-09` antes de `F-04`; `B-19/B-20` antes de `D-19`; `D-05` antes de `D-04`; `D-13` antes de `D-11`/`D-12`.
- Sin Docker ni red externa: todo se comprueba con fakes; las verificaciones reales son los smokes M-xx del usuario.

### Pendiente de terceros (no bloquea ninguna fase)

| Tarea | Qué se implementa ya | Qué queda a la espera |
|-------|----------------------|-----------------------|
| T-07 (A-05) | Todo lo implementable con fakes/config/doc | Contrato real de Pinterest (sandbox) sin verificar: queda para el smoke M-01. |
| T-08 (A-04, A-11) | Todo lo implementable con fakes/config/doc | Confirmar versión activa de LinkedIn (V), reconexión y aprobación de TikTok (U). |
| T-09 (A-03) | Todo lo implementable con fakes/config/doc | Destino externo y cron en el VPS (usuario). |
| T-10 (A-02) | Todo lo implementable con fakes/config/doc | Ejecución del script con permisos de admin en GitHub (usuario). |
| T-21 (B-01, B-04) | Todo lo implementable con fakes/config/doc | Lector de LinkedIn: requiere aprobación `community_management` (usuario/LinkedIn). |
| T-22 (B-02, B-11, B-14) | Todo lo implementable con fakes/config/doc | `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn (aprobación). |
| T-26 (B-07) | Todo lo implementable con fakes/config/doc | Verificación real contra Canva (smoke del usuario). |
| T-27 (B-08) | Todo lo implementable con fakes/config/doc | Capacidad Enterprise de Canva (tercero). |
| T-35 (B-18) | Todo lo implementable con fakes/config/doc | Proveedor de ASR (decisión del usuario). |
| T-69 (E-05) | Todo lo implementable con fakes/config/doc | HSTS y CSP estricta tras observar informes en el VPS (usuario). |
| T-73 (E-18) | Todo lo implementable con fakes/config/doc | Verificar el docroot en el VPS (usuario). |
| T-81 (F-07) | Todo lo implementable con fakes/config/doc | Claves VAPID y HTTPS en producción (usuario). |
| T-82 (F-08) | Todo lo implementable con fakes/config/doc | `ANTHROPIC_API_KEY` real (usuario). |
| T-86 (F-12, F-16) | Todo lo implementable con fakes/config/doc | SMTP real (usuario). |
| T-87 (F-13) | Todo lo implementable con fakes/config/doc | Verificación contra las APIs reales (smoke del usuario). |
| T-90 (F-17, F-18) | Todo lo implementable con fakes/config/doc | Listado definitivo de fechas señaladas (usuario). |
| T-93 (F-22) | Todo lo implementable con fakes/config/doc | Clave de IA real (usuario). |

### Fuera de este plan / decisión del usuario

| ID | Motivo |
|----|--------|
| F-21 | Resumen y borradores del inbox con IA [D]: exige enviar comentarios de terceros a un proveedor de IA. Es una decisión de privacidad y política del usuario; no se implementa hasta que la tome. Se deja documentada la ficha de decisión en `DECISIONES.md` (T de documentación). |
| F-26 | ASR de subtítulos [D] y Flows [D]: requieren elegir proveedor de ASR y decidir la política de Flows (coste/privacidad). El tercer elemento, PPTX, ya se entrega dentro de B-18. Ficha en `DECISIONES.md`. |
| F-27 | X [D] (pago por uso), Google Business Profile [D] (aprobación incierta) y Twitch [D] (solo métricas): dependen de coste y aprobación externa; sin decisión no hay nada que implementar sin riesgo. Ficha en `DECISIONES.md`. |

La ficha de decisión de cada uno (opciones, coste y recomendación) se redacta en `DECISIONES.md` dentro de la tarea de documentación final; no se implementa nada hasta que el usuario decida.

---

## Criterios de aceptación (global)

- [ ] Cada tarea cerrada en `tasks.md` con su `Verificación` ejecutada y su `Changelog` rellenado
- [ ] `php artisan test --compact` y la batería E2E (tres tamaños) en verde al terminar; `composer audit` y `npm audit` sin avisos
- [ ] `python3 docs/roadmap/2026-11-11-audit-hardening/scripts/check-traceability.py` → OK (ningún ID falta ni sobra)
- [ ] Ningún refactor cambió comportamiento (Pest y E2E en verde antes y después)
- [ ] La documentación de cada módulo tocado y el CHANGELOG están al día
- [ ] Las decisiones pendientes están en `DECISIONES.md`
- [ ] **Criterio transversal de unidad:** ninguna pantalla nueva o tocada puede introducir componentes, estilos, diálogos ni términos propios del estudio o de la parte social si ya existe uno común; la revisión adversarial lo trata como un gap (`audit-unificacion.md`)
- [ ] `check-traceability.py` también comprueba U-01..U-25 contra `audit-unificacion.md`

---

## Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| El refactor (C, D-11, D-12) rompe comportamiento | Media | Alto | Fase 2 antes; E2E antes/después por tarea; tareas L partidas |
| Tests concurrentes de Playwright dan rojos falsos (GOT-005) | Alta | Medio | Una sola ejecución a la vez, sin cambios de código en paralelo |
| `E-07` destapa muchos tests que usan red sin saberlo | Media | Medio | Tarea S aislada y temprana; arreglar con `Http::fake` |
| La actualización de Laravel/Symfony (A-01) cambia comportamiento | Baja | Alto | Pest completo + smokes antes de seguir |
| Contratos de proveedores sin verificar (LinkedIn, Pinterest, Threads, Canva…) | Alta | Medio | Fakes + fixtures; smokes M-xx del usuario; no se declara «verificado» |
| Migración de `post_target_media` pierde referencias | Baja | Alto | Migración reversible, probada con fábricas, nunca con datos del usuario 28 |
| La estimación es optimista (ratio no calibrado, tareas M grandes) | Media | Medio | Margen +20 %; `usage-meter` por tarea y `/retro` al cerrar |
| Peso de `storage/framework/testing` y roadmap agota disco | Media | Medio | E-12 y E-13 pronto en las fases 2 y 6 |

---

## Métricas de éxito

- 0 avisos en `composer audit` y `npm audit`; `main` protegida y CI en verde por commit.
- Duración de la batería E2E reducida de forma medible frente a 1,5 h (E-02).
- 0 `confirm()` nativos, 0 `waitForTimeout` injustificados, chunk del editor ≥ 1 MB menor.
- 118/118 hallazgos trazados (cerrados, parciales por terceros o fuera con motivo).
- Tablas con retención y `app:health` que detecta cola atrasada, binarios ausentes y `APP_DEBUG` en producción.

---

## Trazabilidad hallazgo → tarea

Cada ID de `audit.md` aparece exactamente una vez. Comprobación automática: `python3 docs/roadmap/2026-11-11-audit-hardening/scripts/check-traceability.py`.

| ID | Hallazgo | Tarea | Fase | Estado de cobertura |
|---|---|---|---|---|
| A-01 | `composer audit` da 11 avisos de seguridad en Symfony (http-foundation | T-01 | 1 | Implementable ya |
| A-02 | `main` no está protegida: se puede fusionar con la CI en rojo (estuvo  | T-10 | 1 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| A-03 | No hay copias de seguridad ni prueba de restauración (BD, `storage/app | T-09 | 1 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| A-04 | Versión de la API de LinkedIn `202511` por defecto: LinkedIn retira ca | T-08 | 1 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| A-05 | El vídeo de Pinterest se carga entero en memoria (`Storage::get`) con  | T-07 | 1 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| A-06 | En Hestia ningún worker consume la cola `studio`: MP4/GIF no se proces | T-03 | 1 | Implementable ya |
| A-07 | Cada autoguardado del estudio (1,2 s) crea una `DesignRevision` comple | T-04 | 1 | Implementable ya |
| A-08 | Tablas sin poda: `audit_logs`, `publish_attempts`, `notifications`, `s | T-04 | 1 | Implementable ya |
| A-09 | La comprobación de salud no ve: worker caído por cola, snapshots atras | T-05 | 1 | Implementable ya |
| A-10 | Despliegue in situ: mantenimiento durante `composer install` + `npm ci | T-11 | 1 | Implementable ya |
| A-11 | Analítica de TikTok siempre vacía: los scopes `user.info.stats` y `vid | T-08 | 1 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| A-12 | Referencias a media en JSON sin FK (`post_targets.media`, `post_series | T-06 | 1 | Implementable ya |
| B-01 | LinkedIn y Pinterest sin lector de analítica: panel, mejores horas, ha | T-21 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-02 | Inbox de Facebook y Ads apagados por defecto (`META_EXTRA_SCOPES` vací | T-22 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-03 | Aviso de caducidad de conexión solo para LinkedIn y solo en Conexiones | T-23 | 3 | Implementable ya |
| B-04 | Tableros de Pinterest: solo los 25 primeros, una vez, sin refresco | T-21 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-05 | Primer comentario no implementado en Threads ni Bluesky | T-24 | 3 | Implementable ya |
| B-06 | Competidores solo en Instagram | T-25 | 3 | Implementable ya |
| B-07 | Canva: sin verificación JWKS del `correlation_jwt` | T-26 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-08 | Canva: PDF solo como enlace de 24 h; Brand Templates y Autofill fijado | T-27 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-09 | API v1 muy por detrás del MCP: faltan listar/borrar/cancelar/publicar  | T-28 | 3 | Implementable ya |
| B-10 | MCP sin herramientas para SmartLinks, Ads, autolistas, alta/baja de co | T-30 | 3 | Implementable ya |
| B-11 | Threads guarda los scopes pedidos como concedidos: si el usuario quita | T-22 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-12 | Facebook sin publicación de varias fotos ni stories | T-31 | 3 | Implementable ya |
| B-13 | Solo los fallos de publicación notifican; informes programados, MP4/GI | T-23 | 3 | Implementable ya |
| B-14 | LinkedIn y Pinterest no aparecen en la tabla de cobertura del inbox | T-22 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-15 | Series: fechas perdidas sin recuperar, ocurrencias sin heredar aprobac | T-32 | 3 | Implementable ya |
| B-16 | Carpetas del estudio: no se renombran ni borran (la ruta existe) ni se | T-33 | 3 | Implementable ya |
| B-17 | Opciones de publicación: sin portada de vídeo en LinkedIn, sin búsqued | T-34 | 3 | Implementable ya |
| B-18 | Estudio: sin PPTX, sin transcripción/traducción de subtítulos, sin min | T-35 | 3 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| B-19 | Inventario desfasado (C24, M11, C22) | T-20 | 3 | Implementable ya |
| B-20 | Rutas con nombre repetido entre web y API (`studio.folders.*`) y nombr | T-20 | 3 | Implementable ya |
| C-01 | Instagram y Threads duplican ~14 métodos y ya divergen ante un contene | T-36 | 4 | Implementable ya |
| C-02 | TikTok y YouTube duplican el presupuesto de subida | T-37 | 4 | Implementable ya |
| C-03 | `YouTubePublisher` (930 líneas) hace de cliente, subida, comentarios y | T-38 | 4 | Implementable ya |
| C-04 | Controladores web y API del estudio con la misma validación y orquesta | T-39 | 4 | Implementable ya |
| C-05 | `PostService` (1095 líneas; `plan` de 142) | T-40 | 4 | Implementable ya |
| C-06 | `SiteService` (661), `StudioAvState` (803), `StudioRenderer::render` ( | T-42 | 4 | Implementable ya |
| C-07 | Inbox: Meta no implementa `CommentSource`; redes y `can_hide` escritos | T-43 | 4 | Implementable ya |
| C-08 | Propiedad comprobada de tres formas (12 Policies frente a 87 comprobac | T-45 | 4 | Implementable ya |
| C-09 | 458 `withoutUserScope()`, 198 seguidos de `where('user_id')` | T-44 | 4 | Implementable ya |
| C-10 | Transacción con E/S: `lockForUpdate` mientras sube a almacenamiento; b | T-46 | 4 | Implementable ya |
| C-11 | `Period::make` repetido 11 veces con tres formas de error | T-47 | 4 | Implementable ya |
| C-12 | Panel por marca sin caché (los datos cambian una vez al día) | T-47 | 4 | Implementable ya |
| C-13 | Sin `preventLazyLoading` ni tests de recuento de consultas en estudio  | T-48 | 4 | Implementable ya |
| C-14 | 27 migraciones con fecha futura (hasta `2026_12_10`) | T-48 | 4 | Implementable ya |
| C-15 | Config muerta o mágica: `reports.timeout` sin uso; ~25 límites de ritm | T-49 | 4 | Implementable ya |
| C-16 | Colas fijas en código (`onQueue('media')`); `retry_after` de redis 90  | T-49 | 4 | Implementable ya |
| C-17 | 73 `app(X::class)` y 17 `= new` en constructores | T-50 | 4 | Implementable ya |
| C-18 | Duplicación menor: contador diario, escape LIKE, bloqueo del estado OA | T-50 | 4 | Implementable ya |
| D-01 | pdf-lib (430 KB) se carga al abrir el editor | T-52 | 5 | Implementable ya |
| D-02 | Ocho diálogos del editor con import estático (~1,1 MB al abrir) | T-52 | 5 | Implementable ya |
| D-03 | Once copias de lector XSRF + `fetch`; cuatro con regex sin anclar | T-53 | 5 | Implementable ya |
| D-04 | 25 `confirm()` nativos en acciones destructivas | T-57 | 5 | Implementable ya |
| D-05 | Cuatro patrones de diálogo; trampa de foco incompleta en `UtmModal` | T-56 | 5 | Implementable ya |
| D-06 | Flash por página con dos estilos; errores que se pierden y mensajes qu | T-58 | 5 | Implementable ya |
| D-07 | Listas cortadas sin avisar (papelera y biblioteca 200, avisos 100, inf | T-59 | 5 | Implementable ya |
| D-08 | Sondeos sin tope ni backoff (Reports) o que se solapan (Canva) | T-55 | 5 | Implementable ya |
| D-09 | Seis pantallas muestran la hora del navegador y no la de la marca | T-54 | 5 | Implementable ya |
| D-10 | Errores de formulario sin `aria-describedby`/`aria-invalid` (80 `Input | T-60 | 5 | Implementable ya |
| D-11 | `Editor.vue` con 139 declaraciones de nivel superior | T-62 | 5 | Implementable ya |
| D-12 | Páginas con 3-4 funciones (`Sites/Edit`, `Reports/Index`, `Calendar/In | T-64 | 5 | Implementable ya |
| D-13 | Plantillas en una línea (hasta 3.376 caracteres); sin ESLint ni Pretti | T-51 | 5 | Implementable ya |
| D-14 | Sin tipos: 0 `.ts` en `resources/js`, 250 props sin forma, sin `vue-ts | T-67 | 5 | Implementable ya |
| D-15 | `compileTimeline()` dos veces y `JSON.stringify` por cambio | T-61 | 5 | Implementable ya |
| D-16 | `ai.status` pedido dos veces y `call()` copiado | T-55 | 5 | Implementable ya |
| D-17 | `role="tab"` sin flechas ni `tabpanel` | T-60 | 5 | Implementable ya |
| D-18 | 14 `<img>` de rejilla sin `loading="lazy"` | T-61 | 5 | Implementable ya |
| D-19 | URL de datasets construidas a mano | T-61 | 5 | Implementable ya |
| D-20 | Código muerto: `NavLink`, `ResponsiveNavLink`, `concurrently`, `dotenv | T-61 | 5 | Implementable ya |
| D-21 | Ortografía y terminología («dejara», «¿», «Email»/«correo») | T-54 | 5 | Implementable ya |
| E-01 | Fuera de la CI: E2E, tests de Node, typecheck, auditorías, lint de Ope | T-15 | 2 | Implementable ya |
| E-02 | E2E en serie (1,5 h): `workers: 1`, un `php -S`, tres proyectos repite | T-14 | 2 | Implementable ya |
| E-03 | Puerto 8080 fijo | T-13 | 2 | Implementable ya |
| E-04 | Test intermitente con servidor HTTP real y `usleep` | T-16 | 2 | Implementable ya |
| E-05 | Sin HSTS; sin CSP global ni Permissions-Policy en la app Inertia | T-69 | 6 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| E-06 | Rotación de `APP_KEY` sin documentar para tokens de redes y Canva; sin | T-70 | 6 | Implementable ya |
| E-07 | `Http::preventStrayRequests` solo en 73 de 307 ficheros | T-02 | 1 | Implementable ya |
| E-08 | 9 tests omitidos: el camino nativo MP4/GIF nunca corre en CI | T-19 | 2 | Implementable ya |
| E-09 | Sin tests: `RequestPostApproval`, `api.approvals.pending`, `api.collab | T-17 | 2 | Implementable ya |
| E-10 | Tests lentos (Bluesky 41 s, 10 s por caso; causa sin verificar) | T-16 | 2 | Implementable ya |
| E-11 | Tests de Node sin script; uno exige `--expose-gc` | T-12 | 2 | Implementable ya |
| E-12 | `storage/framework/testing` ocupa 7,6 GB | T-12 | 2 | Implementable ya |
| E-13 | `docs/roadmap` pesa 211 MB (208 de `testing/`); `.gitignore` solo cubr | T-71 | 6 | Implementable ya |
| E-14 | Dependencias: `laravel/mcp` y `laravel/passkeys` fijadas; `web-auth/we | T-72 | 6 | Implementable ya |
| E-15 | CI doble por commit, sin `concurrency`, `timeout-minutes` ni caché de  | T-15 | 2 | Implementable ya |
| E-16 | E2E frágil: 32 `waitForTimeout`, 97 `.first()`, 151 `getByText` | T-18 | 2 | Implementable ya |
| E-17 | Tres configs de Playwright fuera de la batería (roadmap/T-43, audio, p | T-13 | 2 | Implementable ya |
| E-18 | Doc contradictoria: latido 600 s frente a 180 s; docroot de Hestia | T-73 | 6 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| E-19 | Fakes HTTP escritos a mano, sin fixtures grabados | T-74 | 6 | Implementable ya |
| E-20 | `app:health` no avisa de `APP_DEBUG=true` en producción | T-05 | 1 | Implementable ya |
| F-01 | Registro de actividad visible | T-75 | 7 | Implementable ya |
| F-02 | Mejor hora en un clic en compositor y calendario | T-76 | 7 | Implementable ya |
| F-03 | Duplicar post (borrador / otra marca) | T-77 | 7 | Implementable ya |
| F-04 | Estado del sistema en la UI (colas, cron, tokens, cuotas) | T-78 | 7 | Implementable ya |
| F-05 | Objetivos y alertas (seguidores, engagement, caídas, token) | T-79 | 7 | Implementable ya |
| F-06 | Webhooks salientes firmados + eventos de dominio | T-80 | 7 | Implementable ya |
| F-07 | PWA instalable + push | T-81 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-08 | Adaptar contenido entre redes con IA + alt text en el compositor | T-82 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-09 | Importación masiva de posts por CSV | T-83 | 7 | Implementable ya |
| F-10 | Copia de seguridad y exportación completa (ZIP) | T-84 | 7 | Implementable ya |
| F-11 | Búsqueda global / paleta de comandos | T-85 | 7 | Implementable ya |
| F-12 | Resumen semanal por correo/in-app (IA opcional) | T-86 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-13 | Mastodon y Telegram | T-87 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-14 | Autolistas desde RSS | T-88 | 7 | Implementable ya |
| F-15 | Acortador propio con clics | T-89 | 7 | Implementable ya |
| F-16 | Recordatorio de publicación manual por correo/push | T-86 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-17 | Calendario de fechas señaladas | T-90 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-18 | Feed .ics del calendario | T-90 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-19 | Marca de agua automática y exportación en lote a los formatos del post | T-91 | 7 | Implementable ya |
| F-20 | Reciclado inteligente de mejores posts | T-92 | 7 | Implementable ya |
| F-21 | Resumen y borradores del inbox con IA **[D]** | — | — | Fuera de este plan / decisión del usuario |
| F-22 | Plan de contenido en lenguaje natural (lote) | T-93 | 7 | Parcial: queda a la espera de terceros (ver Notas de la tarea) |
| F-23 | Animaciones por objeto; más plantillas | T-94 | 7 | Implementable ya |
| F-24 | Atajos globales, preferencias de notificación, onboarding guiado | T-96 | 7 | Implementable ya |
| F-25 | Modo oscuro | T-97 | 7 | Implementable ya |
| F-26 | ASR de subtítulos **[D]**, PPTX, Flows **[D]** | — | — | Fuera de este plan / decisión del usuario |
| F-27 | X **[D]** (pago por uso), Google Business Profile **[D]** (aprobación  | — | — | Fuera de este plan / decisión del usuario |

---

## Trazabilidad unificación → tarea

Cada ID de `audit-unificacion.md` aparece exactamente una vez como dueño (columna Tarea). Las tareas que solo lo amplían lo declaran en `Cubre (unificación)` de `tasks.md`. Comprobación automática: el mismo `check-traceability.py`.

| ID | Hallazgo | Tarea | Fase | Estado de cobertura |
|---|---|---|---|---|
| U-01 | «Estudio» es el primer módulo del menú, delante de Analítica y Planificación | T-103 | 5b | Implementable ya |
| U-02 | «Plantillas», «Identidad» y «Papelera de diseños» nunca se marcan activas | T-103 | 5b | Implementable ya |
| U-03 | Canva no está en el menú y al abrirlo cae en «Cuenta» | T-103 | 5b | Implementable ya |
| U-04 | Estudio y editor sin `#header`: hero propio | T-103 | 5b | Implementable ya |
| U-05 | El editor compensa el layout con `-m-3`; sin prop `fullBleed` | T-103 | 5b | Implementable ya |
| U-06 | `studio/designs/{design}/edit` no fija la marca de la sesión | T-103 | 5b | Implementable ya |
| U-07 | Dos colores de acento (`violet` frente a `indigo`); `btn-*` sobrescrito | T-102 | 5b | Implementable ya |
| U-08 | Ocho bloques `<style>` y cinco estilos de botón propios del estudio | T-102 | 5b | Implementable ya |
| U-09 | Etiquetas e inputs propios en lugar de `.field` | T-107 | 5b | Implementable ya |
| U-10 | Tres patrones de diálogo (`StudioDialog`, `Modal`, `fixed inset-0`) | T-107 | 5b | Implementable ya |
| U-11 | El estudio no usa `EmptyState`, `PrimaryButton`, paginador ni banner comunes | T-107 | 5b | Implementable ya |
| U-12 | 88 colores hex fuera de los tokens | T-102 | 5b | Implementable ya |
| U-13 | «Biblioteca» significa dos cosas | T-54 | 5 | Implementable ya |
| U-14 | Mismos conceptos con nombres distintos; sin glosario | T-54 | 5 | Implementable ya |
| U-15 | El post no guarda su diseño ni su exportación de origen | T-101 | 5b | Implementable ya |
| U-16 | El compositor no elige archivos del estudio ni abre una imagen en él | T-106 | 5b | Implementable ya |
| U-17 | Lo importado de Canva no aparece en «Recursos» | T-101 | 5b | Implementable ya |
| U-18 | Dos pipelines de subida de medios (amplía T-53) | T-106 | 5b | Implementable ya |
| U-19 | La identidad de marca solo existe dentro del estudio | T-104 | 5b | Implementable ya |
| U-20 | Dos pilas de IA con estado y cuota separados; «Voz de la marca» sin uso | T-104 | 5b | Implementable ya |
| U-21 | Avisos `studio_design_*` genéricos y sin enlace | T-23 | 3 | Implementable ya |
| U-22 | Dos modelos de permisos; el colaborador de la marca no ve sus diseños | T-105 | 5b | Implementable ya |
| U-23 | Tres estilos de paginación en API/MCP | T-28 | 3 | Implementable ya |
| U-24 | Prefijos de abilities mezclados; archivos sin ability propia | T-108 | 5b | Implementable ya |
| U-25 | El estudio más expuesto que el núcleo social; marca a veces en la query | T-29 | 3 | Implementable ya |

---

## Changelog del plan

| Fecha | Cambio | Autor |
|-------|--------|-------|
| 2026-11-11 | Creación del plan a partir de `audit.md` | planner |
| 2026-11-12 | Se incorpora `audit-unificacion.md` (U-01..U-25): Fase 5b con T-101..T-108 (las que superaban una M se parten en 1/2 y 2/2), ampliación de T-17, T-23, T-28, T-29, T-53, T-54, T-56..T-60, T-62, T-63 y criterio transversal de unidad | planner |
| 2026-11-11 | Se parte A-09/E-20/F-04: backend en fase 1 y UI (F-04) en fase 7; las L (B-09, C-05, D-11, D-12, D-14, F-23, F-25) se parten en tareas M con continuaciones sin ID propio | planner |

---

## Siguiente paso

Con el **OK del plan** del usuario, `implementer` ejecuta `tasks.md` en orden, marcando cada tarea como ledger canónico; `qa` cierra con `test-plan.md`. Opcional: volcado del plan a Jira (no realizado).
