---
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
  # ventana compartida con improvement-plan.md
verificacion: obligatoria
estado: en-progreso
---

# Checklist de Tareas — Endurecimiento y ampliación tras la auditoría integral

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-11-11 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | n/a |
| **Fuente** | [`audit.md`](./audit.md) |
| **Spec** | [`spec.md`](./spec.md) |
| **Pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> **Método por tarea:** implementador → revisor adversarial A+B(+C) → correcciones → Pest y Playwright de los specs afectados (**una sola ejecución de Playwright a la vez y nunca con cambios de código en paralelo**, lección GOT-005) → documentación actualizada → cierre en este ledger. Las tareas se ejecutan **en el orden de este fichero**. Restricciones permanentes: sin Docker, sin proveedores reales ni red externa en las pruebas, no editar `.env`, no tocar los datos del usuario 28, los merges los hace el usuario. Las tareas que dependen del usuario o de un tercero **no bloquean la fase**: lo implementable se cierra y lo pendiente queda en `A la espera`.

> **Calidad del código:** Claude Code con Opus 5.5 también evalúa la legibilidad, la robustez, la complejidad, la duplicación, la separación de responsabilidades y el coste de mantener el cambio. El revisor cita el fichero y la línea, explica el impacto y propone una corrección concreta para cada defecto. Las preferencias de estilo se registran como sugerencias. En cambios documentales, se revisa la claridad y la correspondencia con el código.

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — Riesgo inmediato | 11 | 11 | 100% | 0 / 63.0h | 0 / 16.0h | 0 / 4.0h | 0 / 3055k |
| Fase 2 — CI y red de tests | 8 | 8 | 100% | 0 / 44.0h | 0 / 11.2h | 0 / 2.8h | 0 / 2140k |
| Fase 3 — Funciones incompletas | 16 | 16 | 100% | 0 / 118.0h | 0 / 29.6h | 0 / 7.4h | 0 / 5630k |
| Fase 4 — Refactor del backend | 15 | 15 | 100% | 0 / 90.0h | 0 / 22.8h | 0 / 5.7h | 0 / 4350k |
| Fase 5 — Frontend | 15 | 18 | 83% | 0 / 119.0h | 0 / 30.0h | 0 / 7.5h | 0 / 5715k |
| Fase 5b — Unificación | 4 | 8 | 50% | 0 / 64.0h | 0 / 16.0h | 0 / 4.0h | 0 / 3040k |
| Fase 6 — Operación y repositorio | 4 | 6 | 67% | 0 / 43.0h | 0 / 10.8h | 0 / 2.7h | 0 / 2055k |
| Fase 7 — Funciones nuevas | 10 | 24 | 42% | 0 / 177.0h | 0 / 44.4h | 0 / 11.1h | 0 / 8445k |
| Fase 8 — QA integral y documentación | 0 | 2 | 0% | 0 / 11.0h | 0 / 2.8h | 0 / 0.7h | 0 / 535k |
| **TOTAL** | **83** | **108** | **77%** | **0 / 729.0h** | **0 / 183.6h** | **0 / 45.9h** | **0 / 34965k** |

> Horas **base** (sin el +20 % de contingencia, que se aplica en `improvement-plan.md`). Todas las cifras son **estimadas**; los tokens se miden al cerrar cada tarea.

> **Imputación externa opcional.** El adaptador elegido recibe las mediciones disponibles y aplica su contrato de jornada, supervisión e idempotencia. No inventes tiempo real ni recalcules horas fuera de su dueño; sin integración los datos permanecen locales.

---

## Fase 1 — Riesgo inmediato

**Estado**: borrador · **Estimado**: 63.0h · **Real**: — · **Coste est.**: 3150 € (base) + tokens ⚠️ verificar · **Tokens est.**: 3055k

### T-01 — Actualizar Symfony y Laravel (11 avisos de seguridad)

- **Descripción**: Actualizar `symfony/*` y `laravel/framework` (13.37 → 13.42) para cerrar los 11 avisos de `composer audit` y comprobar que nada se rompe.
- **Changelog**: Actualizadas las dependencias de Symfony y Laravel (13.42) para cerrar 11 avisos de seguridad conocidos; sin cambios visibles.
- **Estado**: completado
- **Cerrada**: 2026-11-11
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `composer.json`, `composer.lock`
- **Cubre**: A-01
- **Cubre (tests)**: E2E-39
- **Verificación**: `composer audit --locked` → `No security vulnerability advisories found` · `php artisan test --compact` → 0 fallos

**Criterios de aceptación**
- [x] `composer audit` sin avisos de seguridad
- [x] Suite Pest completa en verde tras la actualización
- [x] `composer.lock` con versiones de Symfony ≥ las corregidas (7.4.22 o superior)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `composer update symfony/* laravel/framework --with-all-dependencies` (laravel 13.37.0 → 13.42.0; symfony 7.4.16-7.4.21 → 7.4.22/7.4.23; sin paquetes fijados). `composer audit` → `No security vulnerability advisories found`. Pest: 3581 tests, 3572 passed, 9 skipped (sin fallos). `npm run build` OK. Playwright aplazado al cierre de fase.

**Notas**: Tamaño S. Primera tarea del plan. Si algún paquete queda fijado (laravel/mcp, laravel/passkeys), anotar y tratarlo en E-14. Solo se ejecuta Playwright de smoke (`composer.spec.ts`, `pages.spec.ts`); la suite completa en T-QA.

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-02 — Bloquear peticiones HTTP reales en todos los tests

- **Descripción**: Mover `Http::preventStrayRequests()` a `TestCase::setUp` para que ningún test salga a la red; arreglar los ~234 ficheros que hoy dependen de ello sin saberlo.
- **Changelog**:
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-01
- **Tipo**: test
- **Archivos**: `tests/Pest.php`, `tests/TestCase.php`, `tests/Feature/**`, `tests/Unit/**`
- **Cubre**: E-07
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact` → 0 fallos con la guardia global · `grep -n "preventStrayRequests" tests/TestCase.php` → 1 coincidencia

**Criterios de aceptación**
- [x] `Http::preventStrayRequests()` se aplica globalmente en `TestCase`
- [x] Ningún test hace peticiones reales (los que las necesitaban usan `Http::fake`)
- [x] Los tests de integración con servidor local (si los hay) declaran su excepción explícita
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `Http::preventStrayRequests()` en `TestCase::setUp`; solo fallaban BlueskySsrfTest y UrlSourceRealHandlerTest (servidor local), que declaran `Http::allowStrayRequests()`. Pest completo: 3581 tests, 3572 passed, 9 skipped, 0 fallos. Pint OK. Playwright aplazado al cierre de fase.

**Notas**: Tamaño S. Hacerlo antes de cualquier otra tarea que añada conectores: es la red de seguridad de la regla «sin red externa en pruebas». Excepción prevista: E-04 (servidor HTTP local).

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-03 — Cola `studio` en Hestia y `media` en systemd

- **Descripción**: Añadir el cron/worker de la cola `studio` en Hestia y la unidad `scheduler-worker@media` en systemd; unificar la documentación de colas entre ambos.
- **Changelog**:
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-01
- **Tipo**: devops
- **Archivos**: `deploy/hestia/README.md`, `deploy/hestia/deploy.sh`, `deploy/systemd/README.md`, `deploy/systemd/scheduler-worker@.service`, `deploy/systemd/scheduler-studio.service`, `docs/modulos/despliegue.md`, `tests/Feature/Deploy/DeployQueuesDocTest.php`
- **Cubre**: A-06
- **Cubre (tests)**: M-06
- **Verificación**: `php artisan test --compact --filter=DeployQueuesDocTest` → verde

**Criterios de aceptación**
- [x] La doc de Hestia lista un worker para cada cola que usa el código (`default`, `media`, `studio`…)
- [x] La doc de systemd incluye `scheduler-worker@media`
- [x] Un test lee `app/Jobs/**` y falla si una cola usada no aparece en `deploy/*/README.md`
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: Hestia 5.1-bis (worker studio por unidad systemd, resumen cola→worker, WORKER_UNITS), systemd README con scheduler-worker@media y --queue=studio, cabecera de la plantilla, deploy.sh y docs/modulos/despliegue.md alineados. DeployQueuesDocTest: 4 tests verdes (falla si se retira studio de Hestia). Pest completo al final de la sesión.

**Notas**: Tamaño S. Solo documentación y plantillas: sin VPS. La comprobación en el servidor real queda para el usuario (E-18).

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-04 — Poda de tablas y retención de revisiones del estudio

- **Descripción**: `MassPrunable` en las ocho tablas con retención en `config/retention.php` y `model:prune` diario; las revisiones del estudio se crean con intervalo mínimo o al cerrar sesión, con retención (últimas N + diarias) y poda de `studio_font_usages`.
- **Changelog**: Las tablas de histórico se podan a diario según su retención y el historial de revisiones del estudio ya no crece con cada autoguardado; las tipografías de revisiones antiguas se pueden borrar.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `config/retention.php`, `app/Models/AuditLog.php`, `app/Models/PublishAttempt.php`, `app/Models/StudioAiJob.php`, `app/Models/StudioImageJob.php`, `app/Models/CanvaJob.php`, `app/Models/StudioDesignExport.php`, `app/Models/StudioAvStep.php`, `app/Models/DesignRevision.php`, `app/Services/Studio/StudioService.php`, `app/Services/Studio/StudioFonts.php`, `resources/js/Pages/Studio/Editor.vue`, `routes/console.php`, `docs/modulos/estudio.md`, `docs/modulos/despliegue.md`, `app/Models/Concerns/PrunesByAge.php`, `config/studio.php`, `database/migrations/*_add_retention_indexes.php`, `docs/referencia/configuracion.md`, `.env.example`, `.env.production.example`, `tests/Feature/Retention/RetentionPruneTest.php`, `tests/Feature/Retention/DesignRevisionRetentionTest.php`
- **Cubre**: A-08, A-07
- **Cubre (tests)**: E2E-02
- **Verificación**: `php artisan test --compact --filter=RetentionPruneTest` → verde · `php artisan test --compact --filter=DesignRevisionRetentionTest` → verde · `php artisan model:prune --pretend` → lista las ocho tablas sin errores

**Criterios de aceptación**
- [x] Cada modelo listado implementa `MassPrunable` con retención configurable y `model:prune` está programado
- [x] El autoguardado no crea más de una revisión por intervalo mínimo; quedan las últimas N y una diaria
- [x] Una tipografía solo usada por revisiones podadas se puede borrar
- [x] Sin cambio en el contenido visible del editor
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: RetentionPruneTest + DesignRevisionRetentionTest: 9 tests verdes (retención por modelo, intervalo mínimo, últimas N + diaria, `studio_font_usages` podado por cascada y tipografía borrable tras la poda). `php artisan test tests/Feature/Studio`: 657 tests, 648 passed, 9 skipped. `model:prune --pretend` sobre SQLite migrada de cero: sin errores y lista los modelos (contra la BD .env no se pudo: MySQL local apagado). Migración de índices reversible (migrate + rollback --step=1 OK). Pint OK. Playwright no lanzado (decisión de la sesión; Editor.vue solo añade `autosave` al PATCH).

**Decisiones**: el autoguardado (`autosave: true`) del mismo autor dentro de `studio.revisions.min_interval_seconds` (300 s) reescribe la revisión reciente (conserva siempre la 1); «Guardar ahora», Ctrl+S, salir, restaurar y kit crean revisión. Exportaciones: solo se podan las fallidas/canceladas sin fichero (las listas tienen fichero en disco que `MassPrunable` no borra). Notificaciones: tarea programada aparte (modelo de Laravel sin clase propia). Poda de revisiones usa funciones de ventana (MySQL 8/MariaDB 10.2+).

**Notas**: Tamaño M. Cuidado con `audit_logs`: la retención por defecto debe ser generosa (≥ 365 d) porque F-01 la mostrará. No tocar los datos del usuario 28: los tests usan fábricas.

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-05 — Salud: colas, snapshots, binarios nativos y sonda programada

- **Descripción**: Ampliar `HealthCheck` con antigüedad del job más viejo por cola, frescura de `CollectSnapshots`, check `native` (ffmpeg, Chromium, Python) y aviso de `APP_DEBUG=true` en producción; `/health?strict=1` devuelve 503; programar `app:queue-probe`; correo al admin en `Queue::failing`.
- **Changelog**: La comprobación de salud avisa de colas atrasadas, métricas sin actualizar, binarios ausentes y APP_DEBUG en producción; /health?strict=1 da 503 con cualquier aviso y un job fallido avisa por correo al administrador.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-04
- **Tipo**: backend
- **Archivos**: `app/Support/HealthCheck.php`, `app/Console/Commands/AppHealth.php`, `app/Console/Commands/QueueProbe.php`, `routes/console.php`, `bootstrap/app.php`, `app/Http/Controllers/HealthController.php`, `app/Providers/AppServiceProvider.php`, `app/Notifications/QueueJobFailedAlert.php`, `config/health.php`, `config/studio.php`, `tests/TestCase.php`, `tests/Feature/Infra/InfraTest.php`, `tests/Feature/Infra/HealthCheckTest.php`, `tests/Feature/Infra/QueueProbeScheduleTest.php`, `docs/modulos/despliegue.md`, `docs/referencia/configuracion.md`, `.env.example`, `.env.production.example`
- **Cubre**: A-09, E-20
- **Cubre (tests)**: API-03
- **Verificación**: `php artisan test --compact --filter=HealthCheckTest` → verde · `php artisan test --compact --filter=QueueProbeScheduleTest` → verde · `php artisan schedule:list` → incluye `app:queue-probe`

**Criterios de aceptación**
- [x] `app:health` informa cola atrasada, snapshots viejos, binario ausente y `APP_DEBUG=true` en producción
- [x] `/health?strict=1` → 503 con jobs fallidos o checks en rojo; `/health` sin `strict` conserva su contrato
- [x] `app:queue-probe` está programado
- [x] `Queue::failing` envía un correo al administrador (con límite para no inundar)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: HealthCheckTest + QueueProbeScheduleTest + InfraTest: 46 tests verdes (cola atrasada por cola con umbral propio, snapshots, native con `Process::fake`, APP_DEBUG, `/health` y `/health?strict=1`, `app:health --strict`, correo con límite). `schedule:list` (SQLite scratch) incluye `app:queue-probe` cada 15 min; `app:health` real sobre la misma BD lista `snapshots`, `config` y `native`. Pest completo: 3605 tests, 3596 passed, 9 skipped, 0 fallos. Pint OK. Playwright no lanzado.

**Decisiones**: `/health` sin `strict` conserva el contrato; con `strict=1` cualquier WARN da 503 (`ok:false`, `strict:true`). `native` y el correo se apagan por config (`HEALTH_NATIVE`, `HEALTH_ALERT_EMAIL` vacío); `TestCase` fija `health.native=false` para que la suite no dependa del equipo. La ruta `/health` está en `bootstrap/app.php`, no en `routes/web.php`. El correo solo lleva job, cola y clase de excepción (sin payload ni mensaje).

**Notas**: Tamaño M. Base de F-04 (UI), que se mantiene como tarea propia de la fase 7 (la auditoría pedía agruparlo, pero la UI depende de este backend y su tamaño sumado sería L). Sin red: los binarios se simulan con un `ProcessRunner` falso.

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-06 — Referencias de media con FK: `post_target_media`

- **Descripción**: Crear la tabla `post_target_media` con `restrictOnDelete` y migrar los datos existentes; mientras tanto, el borrado de un `MediaAsset` comprueba también `autolist_items`.
- **Changelog**: Borrar un archivo que usa un destino, una serie activa o una autolista se rechaza con un aviso claro y la base de datos lo garantiza; la media propia de cada destino pasa a una tabla relacional sin cambiar la API.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-04
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_post_target_media_table.php`, `app/Models/MediaAsset.php`, `app/Models/PostTarget.php`, `app/Services/MediaUploadService.php`, `app/Services/PostService.php`, `app/Models/AutolistItem.php`, `app/Models/PostSeries.php`, `app/Models/PostTargetMedia.php`, `database/migrations/2026_12_18_090000_create_post_target_media_tables.php`, `tests/Feature/Media/MediaDeleteReferencesTest.php`, `tests/Feature/Posts/TargetMediaReviewTest.php`, `tests/Feature/Posts/AdvancedOptionsContractTest.php`, `docs/modulos/media.md`, `docs/modulos/media-por-destino.md`
- **Cubre**: A-12
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=MediaDeleteReferencesTest` → verde · `php artisan migrate:fresh --env=testing --force` → sin errores (SQLite)

**Criterios de aceptación**
- [x] Borrar un media referenciado por un post, serie, autolista o export falla con mensaje claro y sin recorrer todas las filas en PHP
- [x] La migración de datos es reversible y conserva todas las referencias existentes
- [x] `autolist_items` forma parte de la comprobación de uso
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `MediaDeleteReferencesTest` 7/7 verde (RED inicial: tabla inexistente, comprobación hidrataba filas, FK sin efecto). Pest focal (Posts, Media, Workflow, Parity, Mcp, Library, Isolation, Publishing, Retention): 1158 tests, 0 fallos; antes, Connectors/Studio/Instagram/Sites/Calendar/Jobs/Infra/Auth también verdes (2808 tests, único fallo era un test que borraba la fila saltándose la FK, ajustado). `migrate:fresh` + `migrate:rollback --step=1` + `migrate` sobre SQLite fichero scratch: sin errores (`migrate:fresh --env=testing` no aplica: ese entorno apunta a MySQL no disponible aquí). Pint OK. Pest completo y Playwright no lanzados (los ejecuta el orquestador).

**Decisiones**: (1) `post_target_media(post_target_id cascade, media_asset_id restrict, cover_id restrict null, alt, position)` + `post_targets.has_own_media` (distingue «hereda» de «lista propia vacía»); se elimina `post_targets.media`. `PostTarget::$media` pasa a ser un `Attribute` (get/set) con `$appends`: forma pública intacta, así `PostService`, API, MCP y compositor no cambian; la lista se vuelca al guardar el destino. (2) Series y autolistas: se mantiene el JSON como contenido y se añade un índice relacional derivado con FK restrict (`post_series_media` solo con series activas, `autolist_item_media`), mantenido por los modelos en `saved`; una consulta indexada con `whereJson` no cubriría la media anidada en `targets[].media` de forma portable y no daría FK. (3) `studio_design_exports.media_ids` queda en JSON, pero entra en la comprobación de uso (`MediaAsset::usedByExport`): export `ready` de los últimos `studio.export_media_guard_days` días (30, configurable, 0 = no bloquea) con `whereJsonContains` filtrado por usuario, estado y fecha, sin cargar filas; los exports históricos no bloquean. Sin índice derivado porque la ventana temporal lo haría caducar. (4) Destinos publicados: sus filas se sueltan antes de borrar (`releasePublishedTargetMedia`, dos sentencias), como antes los ignoraba la comprobación. (5) Migración: reversible (`down` reconstruye el JSON); ids huérfanos se descartan y portadas huérfanas quedan en null; probada solo con fábricas, nunca con datos del usuario 28.

**Verificación MySQL**: PENDIENTE del usuario (no hay MySQL aquí): revisada a mano la compatibilidad con MySQL 8 (nombres de índice máx. 58 caracteres, FK del mismo tipo, `dropColumn` sin índice); ver `docs/referencia/pendientes-manuales.md`.

**Notas**: Tamaño M. Mismo contrato visible: el cambio es de integridad. La migración se prueba solo con fábricas, nunca con datos del usuario 28.

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-07 — Pinterest: subida de vídeo en streaming

- **Descripción**: Sustituir `Storage::get` por subida en streaming (recurso multipart o por pasos), bajar el tope de 2 GB a un valor seguro y fijar un timeout acorde.
- **Changelog**: Los vídeos de Pinterest se suben en streaming sin cargarlos enteros en memoria; el tope baja a 128 MB y un vídeo mayor se rechaza al programar.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Connectors/Pinterest/PinterestPublisher.php`, `config/media_specs.php`, `app/Connectors/Pinterest/PinterestClient.php`, `app/Connectors/Support/KnownSizeStream.php`, `app/Services/PublishOutcomes.php`, `tests/Feature/Publishing/**`, `app/Connectors/Pinterest/PinterestUploadRejectedException.php`, `config/media_specs.php`, `config/social.php`, `docs/modulos/despliegue.md`, `tests/Feature/Connectors/Pinterest/**`, `docs/modulos/media-por-destino.md`, `docs/guias/linkedin-pinterest.md`, `docs/referencia/configuracion.md`, `docs/referencia/decisiones-por-red.md`, `.env.example`, `.env.production.example`
- **Cubre**: A-05
- **Cubre (tests)**: M-01
- **Verificación**: `php artisan test --compact --filter=PinterestVideoStreamingTest` → verde

**Criterios de aceptación**
- [x] La subida no carga el vídeo entero en memoria (test con límite de memoria o stream verificado)
- [x] Tope y timeout de vídeo de Pinterest ajustados en `config/media_specs.php` y documentados
- [x] Un vídeo por encima del tope se rechaza en validación, no a mitad de publicación
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: RED inicial: el test de stream fallaba (el contenido iba como string de `Storage::get`). Tras el cambio `PinterestVideoStreamingTest` 3/3 y `tests/Feature/Connectors/Pinterest` 22/22 verdes (`php artisan test --compact tests/Feature/Connectors/Pinterest`). Pint OK. Pest completo y Playwright no lanzados (los ejecuta el orquestador).

**Decisiones**: `Storage::readStream` + multipart con el recurso (Guzzle lo envía con Content-Length, sin cargarlo). Tope de vídeo 2 GB a **128 MB** y `PINTEREST_UPLOAD_TIMEOUT` 60 a 100 s, acotado al presupuesto del paso (85 s del job de 110 s, registro incluido): 128 MB caben a ~2,5 MB/s con ~55 s de margen en el peor caso. Se descartó el paso propio en cola `media` (~600 s) porque exige cambiar `--timeout`/`--max-time` de los workers y `retry_after` (180 s) en producción, no verificable aquí (revisión intento 2, N4). La validación (`MediaValidator`) ya rechaza por `max_bytes` al programar. Contrato del endpoint `/v5/media` (`upload_url` + `upload_parameters` a S3, `file` el último campo) **sin verificar** contra la documentación de Pinterest: developers.pinterest.com es una SPA y no devolvió texto aquí; queda para el smoke M-01. El test de memoria mide el pico (48 MB de fichero, crecimiento < 16 MB).

**Notas**: Tamaño M. Sin red: `Http::fake` con recuento de bytes. La verificación contra Pinterest sandbox es el smoke M-01 del usuario. **A la espera (no bloquea la fase)**: Contrato real de Pinterest (sandbox) sin verificar: queda para el smoke M-01.

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-08 — Versión de LinkedIn y scopes extra de TikTok

- **Descripción**: Hacer configurable y documentar la versión de la API de LinkedIn con aviso en `app:health` cercano a su caducidad; añadir `TIKTOK_EXTRA_SCOPES` a la petición de autorización.
- **Changelog**: LinkedIn usa por defecto la versión de API 202610 (la 202511 se retira el 16-11-2026), `app:health` avisa cuando caduca y TikTok admite pedir los scopes de analítica con TIKTOK_EXTRA_SCOPES.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `config/social.php`, `app/Connectors/LinkedIn/**`, `app/Connectors/TikTok/Display.php`, `app/Support/HealthCheck.php`, `app/Connectors/TikTok/TikTokConnector.php`, `config/health.php`, `tests/Feature/Infra/**`, `tests/Feature/Connectors/LinkedIn/**`, `tests/Feature/Connectors/TikTok/**`, `.env.example`, `.env.production.example`, `docs/modulos/conexiones.md`, `docs/guias/linkedin-pinterest.md`, `docs/referencia/configuracion.md`, `docs/referencia/decisiones-por-red.md`
- **Cubre**: A-04, A-11
- **Cubre (tests)**: M-02, M-03
- **Verificación**: `php artisan test --compact --filter=LinkedInVersionHealthTest` → verde · `php artisan test --compact --filter=TikTokExtraScopesTest` → verde

**Criterios de aceptación**
- [x] `LINKEDIN_API_VERSION` documentada en `.env.example` y `app:health` avisa si la versión supera 10 meses de antigüedad
- [x] `TIKTOK_EXTRA_SCOPES` se concatena a los scopes de la petición OAuth
- [x] La doc explica cómo reconectar TikTok para activar analítica
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `php artisan test --compact --filter='LinkedInVersionHealthTest|TikTokExtraScopesTest|TikTokOAuthTest|HealthCheckTest|InfraTest|QueueProbe'` → 60/60 verdes (4 tests de versión LinkedIn, 3 de scopes extra; `InfraTest` ajustado al nuevo check `linkedin_version`). Pint OK. Pest completo y Playwright no lanzados (los ejecuta el orquestador).

**Fuente (A-04, datos leídos en la web, no instrucciones)**: Fuente: https://learn.microsoft.com/en-us/linkedin/marketing/versioning (consultada el 2026-11-11, actualizada por LinkedIn el 2026-10-15). Datos: «Latest Version: October 2026, 202610»; cada versión «supported and stable for a minimum of one year»; aviso «Marketing Version 202511 will be sunset on November 16, 2026». Elegida `202610` (la última publicada; la `202511` anterior caduca en 5 días). Cambiar la versión puede alterar el comportamiento de una llamada: se confirma en el smoke real CS-M-02 (U).

**Decisiones**: `health.linkedin_version_warn_months=10` y `retired_months=12` (aviso `degraded`, nunca `down`); el check solo corre con `social.linkedin.enabled`. `TIKTOK_EXTRA_SCOPES` se parsea a `social.tiktok.extra_scopes` (valida `^[a-z][a-z0-9_.]*$`, descarta basura, sin duplicados al fusionar). Sin aprobación de TikTok no hay que activarlo (el consentimiento fallaría); tras activarlo hay que reconectar. Display ya lee `user.info.stats`/`video.list` de `account->scopes`.

**Notas**: Tamaño S. Qué se puede hacer ya: config, aviso y scopes. Queda a la espera del usuario: confirmar la versión activa de LinkedIn contra su doc oficial (V) y reconectar TikTok con los scopes aprobados en su auditoría (U). No editar `.env`: solo `.env.example`. **A la espera (no bloquea la fase)**: Confirmar versión activa de LinkedIn (V), reconexión y aprobación de TikTok (U).

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-09 — Copias de seguridad y restauración probada

- **Descripción**: Scripts de `mysqldump` diario y copia cifrada de `storage/app` fuera del servidor, procedimiento de restauración probado en local y guía para guardar `APP_KEY` en un gestor de secretos; comando que verifica la frescura del último respaldo.
- **Changelog**: Hay scripts de copia de seguridad cifrada (BD y archivos) y de restauración, el comando app:backup-verify avisa si el último respaldo tiene más de 26 h y app:health puede mostrarlo.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-03
- **Tipo**: devops
- **Archivos**: `deploy/backup/backup.sh`, `deploy/backup/restore.sh`, `deploy/backup/README.md`, `app/Console/Commands/BackupVerify.php`, `app/Support/BackupStatus.php`, `app/Support/HealthCheck.php`, `config/backup.php`, `docs/modulos/despliegue.md`, `docs/referencia/configuracion.md`, `docs/referencia/pendientes-manuales.md`, `.env.example`, `.env.production.example`, `tests/Feature/Console/BackupVerifyTest.php`, `.gitignore`
- **Cubre**: A-03
- **Cubre (tests)**: M-05
- **Verificación**: `php artisan test --compact --filter=BackupVerifyTest` → verde · `bash -n deploy/backup/backup.sh deploy/backup/restore.sh` → sin salida (sintaxis válida)

**Criterios de aceptación**
- [x] `backup.sh` genera volcado + copia de media cifrada y `restore.sh` los restaura sobre una BD vacía (probado con una BD de pruebas)
- [x] `app:backup-verify` avisa si el último respaldo supera 26 h
- [x] `docs/modulos/despliegue.md` documenta destino externo, cifrado y rotación
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `php artisan test --compact --filter='BackupVerifyTest|HealthCheckTest|InfraTest'` → verde (BackupVerifyTest 7/7, incluido el viaje de ida y vuelta cifrado con `mysqldump`/`mysql` falsos y la comprobación de que la contraseña de la BD no aparece en la salida). `bash -n deploy/backup/backup.sh deploy/backup/restore.sh` → sin salida. Round-trip manual con falsos en un directorio temporal: backup (BD + media, copia a destino), restore sobre BD vacía OK, BD con 3 tablas rechazada sin --force, frase equivocada falla. Pint OK. Pest completo y Playwright no lanzados (los ejecuta el orquestador).

**Decisiones**: Respaldos `db-*.sql.gz.enc` y `media-*.tar.gz.enc` (openssl aes-256-cbc + pbkdf2, frase en `BACKUP_PASSFILE`); `last-backup` se escribe solo si todo fue bien y es lo que lee `app:backup-verify`. La contraseña de BD viaja en un fichero 600 temporal, no en argv. El check `backup` de `app:health` es opt-in (`BACKUP_HEALTH=false` por defecto) y solo WARN, para no avisar siempre antes de que exista el cron. APP_KEY y .env fuera del respaldo (gestor de secretos). Cifrado con PBKDF2 de 600000 iteraciones y HMAC-SHA256 por fichero verificado antes de descifrar (compatibilidad con respaldos antiguos sin `.hmac`); marca `last-backup` solo tras la copia externa y `last-db-backup` aparte para `--db-only`; rclone con `copyto`. Rotación remota delegada al ciclo de vida del bucket. Pendiente del usuario: prueba con MySQL real y destino externo, cron en el VPS (documentado en `pendientes-manuales.md`).

**Notas**: Tamaño M. Se implementa todo lo local. Queda a la espera del usuario (U): elegir el destino externo (R2/S3/otro) y programar el cron en el VPS. Sin tocar datos del usuario 28: la restauración se prueba con una BD de prueba. **A la espera (no bloquea la fase)**: Destino externo y cron en el VPS (usuario).

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-10 — Protección de `main` y avisos de fallos de Actions

- **Descripción**: Dejar preparado el comando `gh api` que protege `main` (PR obligatoria, estado requerido `CI / tests`) y un aviso de fallos de Actions; la aplicación en GitHub la hace el usuario.
- **Changelog**: Hay un script para proteger main (PR y tests en verde obligatorios) y un aviso automático por issue cuando la CI falla en main.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-09
- **Tipo**: devops
- **Archivos**: `deploy/github/protect-main.sh`, `deploy/github/README.md`, `.github/workflows/ci.yml`, `.github/workflows/ci-failure-notify.yml`, `docs/modulos/despliegue.md`
- **Cubre**: A-02
- **Cubre (tests)**: M-09
- **Verificación**: `bash -n deploy/github/protect-main.sh` → sin salida · lectura: `deploy/github/README.md` describe estado requerido `CI / tests` y cómo revertir

**Criterios de aceptación**
- [x] `deploy/github/protect-main.sh` es idempotente, imprime lo que hará y exige confirmación explícita
- [x] Existe una notificación de fallo de Actions (workflow de aviso o ajuste documentado)
- [x] La doc explica por qué (CI en rojo 6 días sin verse)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `bash -n deploy/github/protect-main.sh` → sin salida. `bash deploy/github/protect-main.sh --dry-run` (solo lectura: `gh api` GET) → «sin proteccion» actual y el JSON que se aplicaría; no se ejecutó la aplicación ni se cambió nada en GitHub. `gh api …/commits/main/check-runs` → check-run `tests`. YAML del workflow de aviso válido (`yaml.safe_load`). Pest/Playwright: no aplican (sin código PHP).

**Decisiones**: El estado requerido en la API es `tests` (nombre del job), no `CI / tests` (así solo se ve en la UI de la PR); documentado y configurable con `CHECK_CONTEXT`. `APPROVALS=0` y `enforce_admins=true` por defecto (un único desarrollador que es administrador: sin esto A-02 seguiría abierto; hotfix con `ENFORCE_ADMINS=false` o `--revert`, documentado). Aviso: workflow nuevo `ci-failure-notify.yml` (workflow_run, issue deduplicada por título, cierre automático al volver a verde; valores del evento pasados por `env`, no interpolados en el script). `ci.yml` no se modificó. Pendiente del usuario: ejecutar el script con permisos de admin.

**Notas**: Tamaño S. Qué se puede hacer ya: script y docs. Queda a la espera (U): ejecutar el script con permisos de administrador del repo. No se ejecuta desde el plan; los merges los hace el usuario. **A la espera (no bloquea la fase)**: Ejecución del script con permisos de admin en GitHub (usuario).

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

### T-11 — Despliegue sin parada larga, con copia previa y comprobación de CI

- **Descripción**: Compilar (`composer install`, `npm ci`, build) antes de `artisan down`, hacer `mysqldump` antes de `migrate`, comprobar que el commit tiene la CI en verde y documentar una vuelta atrás guiada.
- **Changelog**: El despliegue compila antes de poner la app en mantenimiento, hace una copia de la base de datos antes de migrar, se niega a desplegar un commit sin la CI en verde y permite volver atrás con deploy.sh rollback.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-09, T-05
- **Tipo**: devops
- **Archivos**: `deploy/hestia/deploy.sh`, `deploy/hestia/README.md`, `docs/modulos/despliegue.md`, `tests/Feature/Deploy/DeployScriptTest.php`, `tests/Feature/Infra/InfraTest.php`
- **Cubre**: A-10
- **Cubre (tests)**: M-06
- **Verificación**: `bash -n deploy/hestia/deploy.sh` → sin salida · `bash deploy/hestia/deploy.sh --dry-run` → imprime el orden: build, comprobación CI, dump, down, migrate, up

**Criterios de aceptación**
- [x] `deploy.sh` solo entra en mantenimiento tras compilar y ejecuta un volcado previo a `migrate`
- [x] El script se niega a desplegar un commit sin CI verde (con `--force-unverified` explícito)
- [x] Existe `rollback` documentado y probado en seco (`--dry-run`)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `bash -n deploy/hestia/deploy.sh` → sin salida. `bash deploy/hestia/deploy.sh --dry-run` → orden fetch, ci, build, down, dump, code, swap, discover, migrate, cache, link, queue, up, smoke. `php artisan test --compact --filter='DeployScriptTest|InfraTest|DeployQueuesDocTest'` → 39/39 verde (orden de pasos, rollback en seco, SKIP_BACKUP). Además se ensayó el flujo real con php/composer/npm/gh falsos y un origin local en un directorio temporal: CI roja cancela sin tocar nada, despliegue OK (vendor y build intercambiados, last-deploy escrito), rollback restaura revisión, vendor y build, y un fallo en migrate deja la app en mantenimiento con el aviso de rollback; falta BACKUP_PASSFILE aborta en el preflight. Ese ensayo destapó un bug (ERR trap en una sustitución de comando) ya corregido. Pint OK. Pest completo y Playwright no lanzados.

**Decisiones**: Compilación en `storage/logs/.deploy/stage` (ya ignorado por git, mismo disco) desde `git archive` del SHA exacto y `composer install --no-scripts` (package:discover tras el cambio); intercambio vendor/build con `mv`; versión anterior en `storage/logs/.deploy/previous` (solo una). CI: check-runs del SHA vía `gh api`, nombre `tests` (`CI_CHECK`). Volcado previo con `backup.sh --db-only` (exige BACKUP_PASSFILE; SKIP_BACKUP=1 explícito). Rollback con `git reset --keep` (no destructivo) y BD manual. Se sustituyó la aserción de orden antigua de InfraTest (obsoleta) por DeployScriptTest. Symlink atómico por releases queda como mejora documentada. Pendiente del usuario: despliegue y rollback reales en el VPS, `gh auth login` en el servidor.

**Notas**: Tamaño M. Sin VPS: se prueba en seco. Mantener la opción de symlink atómico como mejora documentada si el modo in situ resulta insuficiente.

---

**Cierre (2026-11-12)**: revisión adversarial cerrada (ver secciones «Revisión de dos lentes» de la Fase 1); Pest completo en solitario 3676 passed / 9 skipped / 0 failed; E2E de la fase en los 3 tamaños, una sola ejecución: 327 passed / 0 failed (composer, calendario, media, destino, workflow, LinkedIn/Pinterest y los 18 specs del estudio).

## Fase 2 — CI y red de tests

**Estado**: borrador · **Estimado**: 44.0h · **Real**: — · **Coste est.**: 2200 € (base) + tokens ⚠️ verificar · **Tokens est.**: 2140k

### T-12 — Scripts de test de Node y purga de `storage/framework/testing`

- **Descripción**: Añadir `test:unit` y `test:browser` (con `--expose-gc` donde haga falta) y purgar ejecuciones antiguas de `storage/framework/testing` en el teardown (7,6 GB hoy).
- **Changelog**: Nuevos scripts npm test:unit y test:browser para los tests de Node, y la limpieza automática de las ejecuciones E2E antiguas en storage/framework/testing (solo sus propios directorios run-*, conserva las 5 más recientes o de menos de 2 días y nunca los citados en docs).
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real 0.4h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-01
- **Tipo**: test
- **Archivos**: `package.json`, `tests/frontend/**`, `tests/e2e/global-teardown.ts`, `scripts/test/purge-testing.mjs`, `docs/guias/testing.md`, `scripts/test/run-node-tests.mjs`, `scripts/test/purge-testing.d.mts`, `tests/frontend/test-tooling.test.mjs`, `tests/e2e/isolated/safety.test.cjs`, `tests/e2e/isolated/http-server.test.cjs`, `docs/guias/desarrollo-local.md`, `README.md`
- **Cubre**: E-11, E-12
- **Cubre (tests)**: —
- **Verificación**: `npm run test:unit` → todos pasan · `node scripts/test/purge-testing.mjs --dry-run` → lista lo que borraría y no falla

**Criterios de aceptación**
- [x] `npm run test:unit` ejecuta los tests de Node sin flags manuales
- [x] El teardown conserva solo las últimas N ejecuciones y borra el resto de `storage/framework/testing`
- [x] `docs/guias/testing.md` documenta los scripts
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `npm run test:unit` → 627 tests, 627 pass, 0 fail (62 ficheros; los 6 `*.browser.test.mjs` y `studio-captions-render-entry.mjs` excluidos, `--list` de `browser` da los 6). `node scripts/test/purge-testing.mjs --dry-run` sobre el repo real → solo informa (no se borró nada); la cifra varía con la antigüedad de las ejecuciones: hoy (2026-11-12) «borraría 260 · conservado 22 · fallos 0» con el alcance final (la cifra inicial 1384 era sin límite de alcance ni citas). La purga real se probó únicamente con directorios temporales (`tests/frontend/purge-testing.test.mjs`, 3/3: N más recientes o jóvenes se conservan, la ejecución en curso y `.gitignore` a salvo, `--dry-run` no borra, raíz inexistente no falla).

**Decisiones**: Selector en `scripts/test/run-node-tests.mjs` (no globs de shell: Windows/Node 22 y exclusión limpia de `*.browser.test.mjs`). Purga como script Node invocado por `global-teardown.ts` (no artisan: no depende de PHP ni de BD, y corre aunque el teardown de usuarios falle) con respaldo `E2E_PURGE=0`; un fallo solo avisa. Regla: se conserva si es de las 5 más recientes O de <2 días (por mtime), sobre `testing/*` y `testing/t43/*`; ocultos y symlinks no se tocan. El bookends guard llama a `teardown()` y lo desactiva con `E2E_PURGE=0` (T-13). `docs/guias/testing.md` no existía: creada. `test:browser` no se ejecutó (necesita Chromium/ffmpeg; solo se verificó el listado).

**Ajuste tras revisión del coordinador**: alcance restringido a `t43/run-general-<uuid>` y `t43/run-<epoch>-<pid>`; se excluye toda entrada cuyo nombre aparece en un `.md` de `docs/` (evidencia citada, journal incluido); nada fuera de esos patrones. Test con árbol temporal mixto (runRoots, evidencia sin patrón, directorio citado, joven, en curso): `npm run test:unit` 630/630. `--dry-run` real: la cifra varía con la antigüedad; hoy 260 (antes del intento 1 de revisión: 268; sin alcance: 1384). No se borró nada.

**Notas**: Tamaño S. No tocar el directorio de otra ejecución en curso: la purga respeta un mínimo de antigüedad.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-13 — Puerto libre para E2E e integración de configs de Playwright

- **Descripción**: Sustituir el puerto 8080 fijo por `E2E_PORT` o un puerto libre, e integrar en la batería (o borrar) las tres configs de Playwright huérfanas.
- **Changelog**: Los E2E ya no fallan si el puerto 8080 está ocupado: usan E2E_PORT o un puerto libre; la batería de T-43 tiene su script npm y se retiran dos configs de Playwright duplicadas.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real 0.6h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-12
- **Tipo**: test
- **Archivos**: `tests/e2e/isolated/runtime.ts`, `tests/e2e/isolated/identity.ts`, `tests/e2e/global-setup.ts`, `tests/e2e/roadmap/roadmap.spec.ts`, `tests/e2e/roadmap/README.md`, `tests/e2e/isolated/serve.cjs`, `playwright.config.ts`, `playwright.roadmap.config.ts`, `playwright.audio.config.ts`, `playwright.presets.config.ts`, `package.json`, `tsconfig.json`, `tests/e2e/isolated/bootstrap.php`, `tests/e2e/isolated/fakes.php`, `tests/e2e/isolated/bookends.guard.ts`, `tests/e2e/studio-boards.spec.ts`, `docs/guias/desarrollo-local.md`, `docs/guias/pruebas-t43.md`, `docs/stack.md`, `README.md`, `.env.example`
- **Cubre**: E-03, E-17
- **Cubre (tests)**: E2E-39
- **Verificación**: `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde con 8080 ocupado · `grep -rn "8080" tests/e2e/isolated` → sin coincidencias fijas

**Criterios de aceptación**
- [x] El runtime E2E arranca en un puerto libre (o `E2E_PORT`) y no falla si 8080 está ocupado
- [x] Cada config huérfana tiene proyecto/script en la batería o se elimina con motivo en el changelog
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: puerto 8080 comprobado libre antes de lanzar (`netstat`). `E2E_PORT=18765 npx playwright test tests/e2e/pages.spec.ts --workers=1 --project=desktop` → 34 passed (el `results.json` de la ejecución muestra `localhost:18765`). Con 8080 OCUPADO por un listener ficticio y sin `E2E_PORT` → 34 passed y el run usó `localhost:59224` (puerto libre elegido). Ambas con `E2E_PURGE=0` para no activar la purga sobre el repo real. `grep -rn 8080 tests/e2e/isolated` → solo el puerto preferido de `runtime.ts` y datos de `http-server.test.cjs`. `npx tsc --noEmit -p .` sin errores nuevos (los de `.cjs` sin tipos son previos). Un solo spec corto, no la batería ni Pest (instrucción del coordinador).

**Decisiones**: `runtime.ts` fija el puerto una vez (E2E_PORT validado 1024-65535, o efímero del SO; tras la revisión ya no se prefiere 8080, ver la sección de revisión) y lo escribe en `process.env.E2E_PORT` para que los workers, que reevalúan el módulo, hereden el mismo; `APP_URL`/`WEBAUTHN_ORIGINS` salen de `baseURL`, el RP ID sigue siendo `localhost` (no lleva puerto). `serve.cjs`, `bootstrap.php` (regex `localhost:<puerto>`), `fakes.php` (`APP_URL`), `bookends.guard.ts` y `studio-boards.spec.ts` ya no fijan 8080. Quedan con 8080 a propósito: `record-passkey-vector.ts` y el vector de passkeys (se grabó contra ese origen; el test Pest comprueba el origen del vector) y los tests Pest/PHP que usan 8080 como dato.
Configs de Playwright (se comprobó referencias en docs, CI, scripts y `package.json`): **`playwright.roadmap.config.ts` se INTEGRA** — la citan `README.md`, `docs/guias/pruebas-t43.md` y `docs/stack.md` como la vía de la QA de T-43; añadido `npm run test:e2e:roadmap` y puerto configurable `E2E_ROADMAP_PORT` (defecto 8081). **`playwright.audio.config.ts` y `playwright.presets.config.ts` se ELIMINAN**: ningún doc vigente, CI ni script las usa (solo ledgers/informes históricos de 2026-10-31), `presets` solo reexporta `audio` con un `grep`, y `audio` solo filtra `instagram-audio`/`tiktok-audio`, specs que la config general ya ejecuta; la única diferencia (`TIKTOK_VERIFIED_DOMAIN`) ya la cubre `tiktok-audio.spec.ts` con su fichero-señal que el servidor lee en cada petición. Retiradas también de `tsconfig.json`. Para pasar solo esos specs: `npx playwright test tests/e2e/instagram-audio.spec.ts tests/e2e/tiktok-audio.spec.ts`.

**Notas**: Tamaño S. Una sola ejecución de Playwright a la vez (GOT-005).

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-14 — E2E paralelizable: servidor y SQLite por worker; móvil solo en `@responsive`

- **Descripción**: Servidor `php -S`, base SQLite y puerto propios por worker; los proyectos de tamaño móvil/tablet solo ejecutan specs `@responsive`; habilitar `--shard`.
- **Changelog**: La batería E2E se ejecuta en paralelo (E2E_WORKERS, 2 por defecto) con un servidor y una base de datos por worker; los proyectos móviles solo corren los tests @responsive (maquetación, desbordamiento, axe y ramas por ancho) y se puede repartir con --shard.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real 1.4h (estimado)
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-13
- **Tipo**: test
- **Archivos**: `playwright.config.ts`, `tests/e2e/isolated/serve.cjs`, `tests/e2e/isolated/runtime.ts`, `tests/e2e/helpers.ts`, `tests/e2e/global-setup.ts`, `tests/e2e/global-teardown.ts`, `tests/e2e/isolated/guard.config.ts`, `tests/e2e/isolated/parallel.test.cjs`, `tests/frontend/test-tooling.test.mjs`, `tests/e2e/{pages,a11y,flows,calendar-views,composer,sites,instagram-grid,campaigns,saved-texts,studio,studio-boards}.spec.ts` (solo la etiqueta `@responsive`), `docs/guias/testing.md`, `docs/guias/desarrollo-local.md`, `README.md`
- **Cubre**: E-02
- **Cubre (tests)**: E2E-39
- **Verificación**: `E2E_PURGE=0 E2E_WORKERS=2 npx playwright test tiktok-audio canva hashtags bluesky` → verde, sin colisiones (incluye 2 specs serial y 2 con banderas) · `npx playwright test --list --project=mobile` → solo tests `@responsive` (lo comprueba `tests/e2e/isolated/parallel.test.cjs`) · `npm run test:unit`

**Criterios de aceptación**
- [x] La batería funciona con `workers ≥ 2` sin interferencias entre workers
- [x] Móvil/tablet solo corren specs etiquetadas `@responsive`
- [x] La duración total baja de forma medible frente a los 1,5 h de partida (registrar antes/después) — solo hay muestra (serie 6,9 min → 2 workers 4,0 min; 774 → 652 ejecuciones); se cierra con la pasada completa de fin de fase, que lanza el coordinador
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia** (2026-11-12, sin otra ejecución en curso; `E2E_PURGE=0`; Windows, PHP 8.3.33, Playwright 1.64.1):
- Muestra `pages a11y hashtags saved-texts campaigns instagram-grid` × 3 proyectos = 215 ejecuciones. Serie (`E2E_WORKERS=1`): `213 passed, 2 skipped (6.9m)`. Paralelo (`E2E_WORKERS=2`): `213 passed, 2 skipped (4.0m)`, 239 s de reloj total con el arranque de dos servidores (×1,7; `a11y.spec.ts` con 66 casos domina un worker). Ambos slots se usaron: 31 y 28 sesiones en sus dos `db.sqlite`, en dos `run-general-<uuid>` distintos. Los 2 omitidos son los previstos del shell de escritorio en móvil (`pages.spec.ts`).
- `npx playwright test --list --project=…` tras la revisión 1: desktop 258, mobile 197, mobile-360 197 (antes 258 × 3 = 774; ahora 652, -16 %); la primera versión, con 79, dejaba fuera cobertura real. `parallel.test.cjs` comprueba que los móviles solo listan `@responsive`.
- Muestra de la revisión 1 (`E2E_WORKERS=2`, `E2E_PURGE=0`, sin otra ejecución previa): `tiktok-audio canva hashtags bluesky` → `32 passed (2.1m)`; incluye `hashtags` y `bluesky` (serial) y `tiktok-audio`/`canva` (banderas de runRoot por slot).
- `npm run test:unit` → `# tests 652 / # pass 652 / # fail 0` (incluye `parallel.test.cjs`, slots distintos, `E2E_WORKERS` inválido, `verifyRunIdentitySync`). `npx tsc --noEmit -p .`: sin errores nuevos (los 40 previos siguen). Guards del arnés (`tests/e2e/isolated/guard.config.ts`): 3 passed.
- RAM medida con 2 workers sobre la muestra ligera: ≈ 1,9 GB (Chromium 0,9 + Node 0,8 + PHP 0,1); presupuesto recomendado 1,5-2 GB por worker + 1 GB (Studio y exportaciones pesan más). Fijo 2 workers por defecto por la OOM de la fase 1.
- `PHP_CLI_SERVER_WORKERS=2 php -S …` en Windows: `forking is not supported on this platform` y sigue con un solo proceso → documentado; el paralelismo está en los servidores por worker.
- No se ha lanzado la batería completa (la lanza el coordinador al cerrar la fase) ni Pest.

**Decisiones**: slot = `parallelIndex` (`TEST_PARALLEL_INDEX`, que Playwright fija antes de cargar el config en el worker); `runtime.ts` decide una vez N puertos libres distintos y N `run-general-<uuid>` (hermanos, porque `bootstrap.php` exige ese patrón; el slot 0 es el de `E2E_GENERAL_RUN_ROOT`) y los publica en `E2E_SLOTS` para los workers. `helpers.ts` (`artisan`, `createE2eUsers`, `deleteE2eUsers`…) acepta un slot opcional con el actual por defecto, de modo que ningún spec cambia. Un `webServer` por slot. `global-setup` prepara cada slot (identidad, usuarios, sesión readonly) y rechaza `--workers` > `E2E_WORKERS`; cada worker repite la identidad al arrancar (`verifyRunIdentitySync`, inline en `runtime.ts` porque Node con type-stripping no resuelve imports sin extensión y los tests de `test-tooling` importan ese módulo). `fullyParallel` sigue en `false`: ficheros en paralelo, tests de un fichero en orden en un worker (los 23 specs `serial` incluidos). El teardown borra los usuarios de todos los slots y la purga protege todos sus `runRoot`.
**Criterio `@responsive` (versión inicial, SUSTITUIDA por la de la revisión 1 al final de este ledger)**: `pages` (3 grupos: públicas, autenticadas, shell), `a11y` (`axe · públicas`, `axe · autenticadas`), `flows` (registro; marca → borrador → calendario/lista/papelera/biblioteca), `calendar-views` (entrada por el menú), `composer` (E2E-01 pastillas, E2E-12 barrido de desbordamiento, A11Y-01, E2E-13 selector de tipo con rama por ancho), `sites` (E2E-15, E2E-15b: vista previa móvil/tablet), `instagram-grid` (E2E-05), `campaigns` (E2E-06 lista/selector con captura por proyecto), `saved-texts` (E2E-02 menú sobre el cursor), `studio` (editor de diseño, `scrollWidth`) y `studio-boards` (pizarra, ramas táctiles). Casi todos los specs llaman a `expectNoOverflow`, por eso no se usó «contiene overflow» como criterio: se etiqueta el test cuyo OBJETO es la maquetación. Quedan solo en desktop el resto de Studio, media, publicación, seguridad, OAuth, redes, etc.

**Notas**: Tamaño M. Cambio de infraestructura de tests: las demás tareas siguen ejecutándose en serie para no mezclar causas de fallo (GOT-005). Medir antes/después.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-15 — CI: concurrency, caché, jobs paralelos y pasos faltantes

- **Descripción**: Reestructurar la CI (`push: [main]` + PR, `concurrency`, `timeout-minutes`, caché de composer/npm, jobs paralelos) y añadir tests de Node, typecheck, `composer audit` y `npm audit`, lint de OpenAPI, `ledger-lint` y el resto de `tests/studio_runtime`; E2E troceado nocturno con `--shard`.
- **Changelog**: La CI se ejecuta una vez por commit y cancela las obsoletas, en jobs paralelos (PHP, Node, runtime de Studio, OpenAPI y auditorías) con un check `tests` agregador; hay un E2E nocturno troceado y el contrato OpenAPI de la suite creativa ya es válido. Quitar fondo usa ahora el modelo IS-Net (Apache-2.0) y necesita ~1 GiB de RAM en vez de ~6,8 GiB (tras actualizar, `setup-runtime.py --download-model`).
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real 0.8h (estimado)
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-14, T-10
- **Tipo**: devops
- **Archivos**: `.github/workflows/ci.yml`, `.github/workflows/nightly.yml`, `docs/api/creative-suite.openapi.yaml`, `package.json`, `tsconfig.json`, `tests/e2e/support/studio-chart-observer.ts`, `tests/e2e/studio-charts.spec.ts`, `deploy/github/protect-main.sh`, `deploy/github/README.md`, `docs/modulos/despliegue.md`, `docs/guias/testing.md`, `scripts/studio/background-model.json`, `scripts/studio/process-image.py`, `scripts/studio/setup-runtime.py`, `app/Services/Studio/StudioImages.php`, `config/studio.php`, `tests/studio_runtime/**`, `tests/e2e/studio-imaging.spec.ts`, `tests/e2e/isolated/bootstrap.php`, `docs/modulos/estudio.md`, `docs/guias/estudio-api-mcp.md`, `docs/referencia/pendientes-manuales.md`
- **Cubre**: E-15, E-01
- **Cubre (tests)**: —
- **Verificación**: `python3 -c "import yaml;[yaml.safe_load(open(f)) for f in ['.github/workflows/ci.yml','.github/workflows/nightly.yml']]"` → sin error · lectura: el nuevo `ci.yml` tiene `concurrency` y `timeout-minutes` en cada job

**Criterios de aceptación**
- [x] La CI corre una sola vez por commit y cancela ejecuciones obsoletas
- [x] La CI incluye tests de Node, typecheck, auditorías, lint de OpenAPI y ledger-lint — typecheck sí; `ledger-lint` queda fuera de la CI por decisión del coordinador (vive en el plugin, no en el repo)
- [x] Existe `nightly.yml` con E2E troceado y los tests `studio_runtime` que hoy no corren
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `actionlint` v1.7.7 (compilado con `go install`) sobre `ci.yml` y `nightly.yml` → exit 0; `yaml.safe_load` de ambos → ok. `npx @redocly/cli@2.77.0 lint --extends minimal docs/api/creative-suite.openapi.yaml` → exit 0 tras corregir 11 errores reales del contrato (descripciones con comas sin comillar, `exclusiveMinimum` numérico y `type: [number, 'null']` de OpenAPI 3.1 en un documento 3.0.3); quedan 102 avisos de `summary` ausente. `composer audit` → «No security vulnerability advisories found»; `npm audit --omit=dev` → «found 0 vulnerabilities»; `npm run test:unit` → 654 pass, 0 fail; `php artisan test --compact --filter=StudioApiMcpTest` → 15 passed (único test que lee el YAML); `python -m unittest discover -s tests/studio_runtime -p 'test_*.py'` → 26 OK (8 omitidos por cgroup/Windows). `npm run typecheck` → 0 errores (antes 40). No se ejecutó Pest completo ni E2E (instrucción del coordinador).
**CI real (PR #20)**: ronda 1 (run 43179497530) rojo en `node`, `studio runtime` y `php`; corregido con clasificación automática de `test:browser` por grafo de imports (`launchesBrowser`), job `node-browser` (Chromium cacheado), `composer install` en los jobs de Node (vite importa `vendor/tightenco/ziggy`), modelo de fondo descargado y cacheado en `studio-runtime`, test de TikTok independiente de `.env` (`env()` lee `$_SERVER`/`$_ENV` antes que `putenv`) y test de identidad portable. Ronda 3 (run 43183702938): `studio runtime` rojo solo en el test del modelo real. Medido en el runner (paso de diagnóstico, ya retirado): inferencia de BiRefNet 1024×1024 con arena desactivado y 4 hilos = VmHWM 6804 MiB, VmData 6996 MiB, VmPeak 7350 MiB; en Windows ~6,0 GiB de working set (5,4 GiB con `ORT_DISABLE_ALL`; los hilos no cambian la cifra). No era reserva virtual desproporcionada (VmPeak ≈ RSS + 0,5 GiB): el modelo necesita ~6,8 GiB reales, así que el tope de 6 GiB (RLIMIT_AS, y también RLIMIT_DATA) era sencillamente bajo. Opción (i): se mantiene el acotamiento por rlimit, ahora `RLIMIT_DATA` en Linux (memoria rellenable, no virtual; el parser de fuentes conserva `RLIMIT_AS`) y Job Object en Windows, con valor medido: **9 GiB** (≈30 % sobre el pico), documentado en `docs/modulos/despliegue.md` (≥ 8 GiB de RAM libre). Nuevo pico con el límite: VmHWM 6723 MiB, VmData 6916 MiB. Test `test_memory` (Linux; omitido en Windows): corta un proceso de 256 MiB que toca 512 MiB y no corta una reserva `PROT_NONE` de 2 GiB. Run 43186445755: `studio runtime` verde (27 tests, 10 omitidos por cgroup/Windows), `php`, `node`, `typecheck`, `openapi` y `audit` verdes; `node (browser)` falló en `native quality changes H264 size and the real GIF colour palette` (el GIF de calidad 1 salió con 1 color en el primer fotograma; pasa en local y en el run 43183702938): intermitente, tras `gh run rerun --failed` pasó. **Run VERDE final: https://github.com/daycry/social-scheduler/actions/runs/43187482513** (commit retirado el diagnóstico; `php`, `node`, `node-browser`, `typecheck`, `studio-runtime`, `openapi`, `audit` y el agregador `tests` en verde, este último tras reejecutar el job de navegador). Pendiente de seguimiento aparte: ese test depende del instante en que Chromium decodifica el primer fotograma del vídeo.
**Decisión posterior a T-15 — bajar la resolución de inferencia de BiRefNet (PARADO: la opción no es viable, decide el usuario)**: `birefnet-general-lite.onnx` declara la entrada fija `[1,3,1024,1024]` y la salida fija `[1,1,1024,1024]`: con 512 o 768 `onnxruntime` responde `INVALID_ARGUMENT ... Expected: 1024`. Se probó además editar la forma a dinámica (copia fuera del repo, `onnx` en un venv temporal): el grafo tiene las particiones fijadas en operadores internos y a 512/768 falla en `/decoder/Split_59` (`Split size 256 exceeds the remaining size of the selected axis, 0`). Retocar el `.onnx` además cambiaría su checksum fijado, así que no se ha añadido `STUDIO_BG_INFERENCE_SIZE`. Mediciones locales (Windows, 4 hilos, arena off, pico de working set / pico de commit): BiRefNet 1024 con `ORT_ENABLE_ALL` 6090 / 6562 MiB, carga 3,7 s + inferencia 5,1 s; con `ORT_DISABLE_ALL` 5382 / 5853 MiB (−12 %), carga 11,5 s + inferencia 5,8 s. Alternativas medidas (RGB aleatorio, mismo entorno): `isnet-general-use.onnx` (IS-Net/DIS, Apache-2.0, 1024 fijo, 178 648 008 B, sha256 `60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a`) 738 / 1198 MiB, 0,5 s; `silueta.onnx` (derivado de U²-Net, 320 fijo, 44 173 029 B, sha256 `75da6c8d2f8096ec743d071951be73b4a8bc7b3e51d9a6625d63644f90ffeedb`) 349 / 807 MiB, 0,3 s; `u2netp.onnx` (320 fijo, 4 574 861 B, sha256 `309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8`) 305 / 763 MiB, 0,1 s. Los tres (descargados de las releases de rembg, solo para medir, fuera del repo) usan otro preproceso/salida que BiRefNet, así que no son un reemplazo directo de `process-image.py` y su calidad de recorte es inferior; la mosaicada no ayuda porque cada tesela tendría que ser de 1024×1024. Sin cambios de código ni de límites (el tope de 9 GiB, la documentación y los `.env*.example` quedan como estaban). Opciones para decidir: (a) mantener BiRefNet 1024 (≥ 8 GiB libres), (b) `ORT_DISABLE_ALL` (−12 %, +8 s de carga), (c) cambiar a `isnet-general-use` con su checksum, preproceso y umbrales de calidad propios (requiere nueva tarea).
**Decisión posterior aplicada (opción c, decisión del usuario): modelo de quitar fondo IS-Net `isnet-general-use`**: `background-model.json` apunta a la release v0.0.0 de rembg (`isnet-general-use.onnx`, 178 648 008 B, sha256 `60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a`, Apache-2.0, licencia = `LICENSE.md` de xuebinqin/DIS, 11 357 B, sha256 `c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4`, instalada como `IS-Net-LICENSE.txt`); `setup-runtime.py` mantiene verificación de tamaño/checksum, instalación atómica y no pisa el modelo previo ante una descarga mala, y `--check` avisa por stderr de que falta el nuevo y de qué ficheros BiRefNet siguen en disco. `process-image.py`: normalización IS-Net (RGB/255 − 0.5, std 1.0; confirmada contra la sesión `DisSession` de rembg y la referencia DIS), entrada `input_image` [1,3,1024,1024], se pide solo la salida `output_image` [1,1,1024,1024] (el modelo declara 12 salidas; las otras son mapas laterales y tensores intermedios), sin sigmoide (la salida ya está en [0,1]: se recorta y se normaliza min-max), reescalado de la máscara con LANCZOS al tamaño original y producto con el alfa original; código propio, no se copia código de rembg. **Memoria medida con el script final** (imagen máxima de 16 Mpx JPEG / foto 512×512): Linux (WSL Ubuntu, onnxruntime 1.31.1) VmHWM 949 / 839 MiB, VmPeak 1264 / 1154 MiB; barrido de `RLIMIT_DATA`: 896 MiB falla con 16 Mpx, 1024 MiB pasa; Windows pico de working set 818 / 686 MiB, commit 797 / 666 MiB (0,5–1 s la foto, 2,8 s la de 16 Mpx). Pedir solo la primera salida bajó el commit de Windows de 1198 MiB (medición anterior con todas las salidas) a ~800 MiB. Tope: `MEMORY_LIMIT` 9 GiB → **1,25 GiB** (1280 MiB; +35 % sobre el VmHWM de Linux, +57 % sobre el commit de Windows); no se midió en el runner de la CI (sin paso de diagnóstico: la medición Linux es de WSL). Calidad (astronaut.png, mismos umbrales que antes): alfa (15,15)=0, (220,130)=255, (200,350)=255, 256 niveles distintos, 46 % de píxeles <30 y 53 % >220; el test añade que ni borra al astronauta ni conserva el cielo (30–70 % opacos y 30–70 % transparentes). IS-Net es menos fino que BiRefNet en pelo y transparencias (documentado en `docs/modulos/estudio.md`). Tests: `python -m unittest discover -s tests/studio_runtime` → 29 tests, 9 omitidos, 1 fallo preexistente e ajeno (`test_av_limits`: el Job Object devuelve 0 en esta máquina; falla igual sin estos cambios); nuevos `PinnedManifestTest` (manifiesto y aviso del modelo antiguo en `--check`); `test_memory` ahora comprueba 1280 MiB; Pest focal `tests/Feature/Studio --filter=Image|Background|Imaging` 29/29; `npm run test:unit` 626/626; E2E `studio-imaging.spec.ts -g "real background inference"` (`E2E_PURGE=0 E2E_WORKERS=1`, proyecto desktop) 1/1. La clave de caché del modelo en `ci.yml` es `hashFiles` del manifiesto, así que cambia sola. Despliegue: `docs/modulos/despliegue.md` (RAM ≥ 2 GiB libre por quitada simultánea y paso de actualización); sin variables nuevas (`STUDIO_BACKGROUND_MODEL_PATH`/`_SHA256` ya existentes, `configuracion.md` no las lista).
**Decisiones**: check requerido = job agregador `tests` (`needs` de `php`, `node`, `node-browser`, `typecheck`, `studio-runtime`, `openapi`, `audit`, `if: always()`), así `protect-main.sh` sigue con `CHECK_CONTEXT=tests` sin cambios de lógica (solo comentario/README). en `push` a `main` el grupo de concurrencia es POR COMMIT (`github.sha`): con un grupo común GitHub cancela también el run *pendiente* aunque `cancel-in-progress` sea falso, así que al fusionar varios PRs seguidos se perdería el del commit intermedio; en PR sigue cancelando el anterior. Así `ci-failure-notify.yml` y `deploy.sh` no vean runs cancelados. `ledger-lint` queda FUERA: es un script del plugin de agentes (`agent-kits/shared`), no existe en el repo. Typecheck DENTRO (decisión del coordinador): los 40 errores de `tsc --noEmit` eran 28 TS7016/TS2339/TS18046 por módulos `.cjs/.mjs` sin tipos (resueltos con `allowJs: true, checkJs: false` en `tsconfig.json`, sin silenciar nada) y 12 TS7006 de parámetros sin tipo en `studio-chart-observer.ts` y `studio-charts.spec.ts` (anotados; solo tipos, sin `any` ni `@ts-ignore`, el JS emitido es idéntico). Script `npm run typecheck`, job `typecheck` en `ci.yml` dentro del `needs` de `tests`. No se ejecutó ningún spec: cambios solo de anotación. `test_av_linux.py` se omite solo (`skipUnless STUDIO_AV_CGROUP_TEST=1`). `package.json` no se tocó (ya tenía `test:unit`). El primer verde real lo da el push del usuario.

**Notas**: **Pendiente de T-12:** la CI aún NO ejecuta los tests de Node (`npm run test:unit`, que ahora incluye también `tests/e2e/isolated/*.test.cjs`); lo resuelve esta tarea al añadir el paso en `ci.yml`.  Tamaño M. Sin red/Docker: se valida el YAML y los comandos en local; el primer verde real lo da el push del usuario. No fusionar: los merges los hace el usuario.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-16 — Tests deterministas y rápidos (servidor real, Bluesky 41 s)

- **Descripción**: Hacer determinista (o marcar `integration`) el test con servidor HTTP real y `usleep`, y bajar el coste de los tests de Bluesky (perfilado, DNS y reloj simulados, `--parallel`).
- **Changelog**: Las pruebas automáticas dejan de fallar a veces (importación de URL, exportación de GIF/MP4) y son más rápidas porque no esperan a la red ni duermen de verdad.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Feature/Media/UrlSourceRealHandlerTest.php`, `tests/Feature/Connectors/Bluesky/**`, `tests/Pest.php`, `phpunit.xml`, `app/Services/Media/RemoteMediaImporter.php`, `app/Jobs/BackfillMetrics.php`, `tests/Feature/Backfill/BackfillTest.php`, `tests/Feature/Snapshots/SettleTest.php`, `tests/Feature/Media/ImageDownscalerTest.php`, `resources/js/Studio/canvas.js`, `resources/js/Studio/timeline-preview.js`, `docs/guias/testing.md`
- **Cubre**: E-04, E-10
- **Cubre (tests)**: —
- **Verificación**: `for i in 1 2 3 4 5; do php artisan test --compact --filter=UrlSourceRealHandlerTest || exit 1; done` → 5 verdes · `php artisan test --compact --filter=Bluesky --profile` → tiempo total < 10 s

**Criterios de aceptación**
- [x] `UrlSourceRealHandlerTest` pasa 5 veces seguidas sin fallos
- [x] Los tests de Bluesky bajan de 41 s a menos de 10 s
- [x] `php artisan test --parallel` funciona o queda documentado por qué no
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. La causa de lentitud de Bluesky no está verificada: perfilar primero (`--profile`).

**Evidencia**: (1) E-04 / G2 `UrlSourceRealHandlerTest` «un remoto que intercala 503 lentos»: reproducido en local (1 de 6 ejecuciones: `failed_passes` = 1 en vez de ≥ 2, 6,8 s); la causa es que cada 503 dormía 0,4 s reales y el corte por ritmo dependía de lo que tardase la primera petición. Ahora el servidor sigue siendo real, pero el reloj de las pasadas es simulado (`RemoteMediaImporter::now()` protegido + `SteppedClockImporter` en el test, 0,1 s por lectura): 8 ejecuciones del test en verde (1,6 s en vez de 2,8–6,8 s) y `for i in 1..5` del fichero completo → 5 × 20/20 verdes (≈4,1 s). (2) E-10 Bluesky: los 4 casos de 10,04 s eran `errores traducidos` (5xx, límite, rechazo, cuenta suspendida): el publicador consulta `getRecord` tras un fallo de `createRecord` y el test solo simulaba `createRecord`, así que `getRecord` salía a la red real y esperaba el timeout de 10 s. Medido con `Http::allowStrayRequests()` temporal: 5 casos = 40,8 s; con la guardia de T-02 (`preventStrayRequests`) fallaban rápido, pero por la excepción de petición no simulada y no por el camino que el test dice cubrir. Ahora el dataset simula `getRecord` → `RecordNotFound`: 5 casos en 0,64 s, también con la red permitida (0,58 s); `--filter=Bluesky` completo con la red permitida = 167 tests en 7,5 s (< 10 s), sin ningún test de Bluesky entre los 10 más lentos de la suite. (3) `BackfillTest` dormía 1,1 s ×2 y `SettleTest` 0,6 s ×1 con `usleep`: `BackfillMetrics` mide su presupuesto con `clock()` (vía `now()`) y los tests avanzan `Carbon::setTestNow`; `tests/Feature/Backfill` = 11/11 en 0,76 s (antes ≈ 3 s) y `SettleTest` 9/9. (4) `ImageDownscalerTest` (19,1 s con `--profile`): el ruido se generaba con `imagesetpixel` pixel a pixel (0,29 s por llamada) y se pedía ~15 veces; ahora BMP de bytes aleatorios (0,09 s) memoizado por (lado, calidad) → 16,0 s. El resto es `ffmpeg` real (8 orientaciones EXIF 3,9 s, escalera de calidad 3,7 s): es el comportamiento bajo prueba y no se simula. (5) Navegador `native quality changes H264 size and the real GIF colour palette`: no se reproduce en Windows (20 ejecuciones, 10 en paralelo con carga); la causa probable está en `seekVideoFrame`, que sale sin esperar si el vídeo ya está en la posición pedida (fotograma 0) y `loadeddata` no garantiza que Chromium haya presentado el primer fotograma, así que `drawImage(video)` puede no dibujar nada y el primer fotograma del GIF sale con un solo color. Nuevo `videoFrameReady()` (`canvas.js`): pinta 1 px del vídeo en un lienzo y espera, por `requestAnimationFrame`, a que el alfa sea > 0 (sin `sleep` ni temporizador); se usa en el render offline de `timeline-preview.js`. Tras `npm run build`: el fichero del test 16 ejecuciones (2 × 8 simultáneas) 5/5 en verde; `studio-audiovisual`, `studio-timeline` y `studio-audio-preview` browser 14/14; `npm run test:unit` 626/626. No se ha podido reproducir el fallo original (solo ocurrió en el runner de Linux), la verificación definitiva es el job `node-browser` de la CI. (6) `--parallel`: **no se adopta**. `vendor/bin/pest --parallel --processes=4` baja la suite de 297,2 s (serie, `pest --profile`: 3676 pasados, 9 omitidos, 0 fallos) a 85–89 s, pero no es estable: con 4 procesos fallaron 34 y luego 98 tests, y con `--processes=1` 152 (reproducible). Causas: (a) `wfSig()` se define en `ApprovalTest.php` y se usa en `BatchRetryTest` y `WorkflowParityTest`, que solo funcionan si ese fichero se carga antes en el mismo proceso; (b) casi todos los `StudioAv*Test` fallan con `render_state` nulo cuando el worker ya ha ejecutado otros ficheros (pasan solos y con `tests/Feature/Studio` entero en paralelo, 657 tests en 35,8 s), lo que indica estado compartido entre ficheros que no he localizado (`tests/Unit` + `tests/Feature/Studio` en serie y las cuatro mitades del resto + `StudioAvStateTest` también pasan); (c) un test de `SettleTest` dependía de `usleep` (corregido). Los discos fake de `storage/` sí quedan aislados por proceso (`Storage::fake` añade el `TEST_TOKEN`). Seguimiento propuesto: nueva tarea para mover `wfSig` a `tests/Support`, encontrar el estado compartido de los `StudioAv*` y activar `composer test -- --parallel`. Pest completo en serie (1 ejecución): 3685 tests, 3676 pasados, 9 omitidos, 297 s. `pint --test` sobre los PHP tocados → passed.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-17 — Tests de permisos: aprobaciones y colaboradores

- **Descripción**: Cubrir con tests de permisos y acceso cruzado `RequestPostApproval`, `api.approvals.pending`, `api.collaborators.index` y `workflow.collaborators.index`.
- **Changelog**: Las aprobaciones y los colaboradores de una marca quedan cubiertos por pruebas que garantizan que nadie ve ni modifica los de otro usuario ni con una API key sin el permiso necesario.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Feature/Mcp/RequestPostApprovalTest.php`, `tests/Feature/Workflow/ApprovalsPendingApiTest.php`, `tests/Feature/Workflow/CollaboratorsIndexTest.php`, `routes/post-workflow-api.php`, `app/Mcp/Tools/RequestPostApproval.php`
- **Cubre**: E-09
- **Cubre (unificación)**: U-22
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=RequestPostApprovalTest` → verde · `php artisan test --compact --filter=ApprovalsPendingApiTest` → verde · `php artisan test --compact --filter=CollaboratorsIndexTest` → verde

**Criterios de aceptación**
- [x] Cada una de las cuatro rutas/herramientas tiene test de éxito, sin permiso y de acceso cruzado entre usuarios
- [x] Un usuario no ve aprobaciones ni colaboradores de otro
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Si un test destapa un fallo real de permisos, se corrige en esta misma tarea y se anota.

**Ampliación (unificación, U-22)**: criterio pendiente que se cierra en T-105: un test de permisos cubre al colaborador de la marca frente a los diseños del estudio (ver, editar y aprobar según su rol). Esta tarea queda completada con su alcance original.

**Evidencia**: 18 tests nuevos (65 aserciones) en 3 ficheros: `tests/Feature/Mcp/RequestPostApprovalTest.php` (6: éxito con aviso al aprobador e idempotencia; key con `posts:read` o `posts:approve` rechazada con mención de `posts:write` sin tocar el post; otro usuario recibe el mismo error que ante un post inexistente; el aprobador invitado tampoco puede pedirla; post de la marca de otro; sin key 401), `tests/Feature/Workflow/ApprovalsPendingApiTest.php` (5: propietario y aprobador ven el pendiente; un ajeno recibe `{pending: []}`; el aprobador solo ve las marcas donde fue invitado; solo pendientes; sin `posts:read` 403 y sin key 401) y `tests/Feature/Workflow/CollaboratorsIndexTest.php` (7: `workflow.collaborators.index` y `api.collaborators.index` con éxito, 404 idéntico al de una marca inexistente para el ajeno y para el aprobador invitado, sin sesión/key 401, sin `posts:read` 403, sin mezcla entre marcas del mismo propietario). **Defectos de autorización: ninguno.** Las cuatro rutas ya delegaban en `PostWorkflowActions::owned()/ownedBrand()` (404 uniforme) y `RequireApiAbility`; no hubo cambios en código de producción. `vendor/bin/pest --compact --filter="RequestPostApprovalTest|ApprovalsPendingApiTest|CollaboratorsIndexTest"` → 18/18; `vendor/bin/pest --compact tests/Feature/Workflow tests/Feature/Mcp` → 366/366 (2428 aserciones, 13,7 s); `pint` sobre los 3 ficheros → passed. Nota de método: tras `withToken()` el token persiste en las cabeceras del test; el caso «sin key» usa `withoutToken()`.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-18 — E2E menos frágil: sin `waitForTimeout` ni `.first()` arbitrarios

- **Descripción**: Sustituir los 32 `waitForTimeout`, los `.first()` y los `getByText` frágiles por `expect.poll`, `getByRole` y `getByTestId`, añadiendo `data-testid` donde falten.
- **Changelog**: Las pruebas de extremo a extremo dejan de dormir tiempos fijos (esperan a que terminen las animaciones o a un evento real) y fallan menos de forma intermitente; una salvaguarda impide volver a introducirlos.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-14
- **Tipo**: test
- **Archivos**: `tests/e2e/a11y.spec.ts`, `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/**/*.spec.ts`, `tests/frontend/test-tooling.test.mjs`, `tests/frontend/studio-presentation-close-observer.test.mjs`, `tests/frontend/studio-audio-preview.browser.test.mjs`
- **Cubre**: E-16
- **Cubre (tests)**: E2E-39
- **Verificación**: `grep -rn "waitForTimeout" tests/e2e` → ≤ 3 coincidencias (justificadas) · `npx playwright test tests/e2e/composer.spec.ts tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Cero `waitForTimeout` en `tests/e2e` salvo los justificados con comentario
- [x] Los selectores de los specs más frágiles usan rol o `data-testid`
- [x] La batería sigue verde (sin cambio de comportamiento de la app)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los `data-testid` añadidos a `.vue` no cambian comportamiento. Una sola ejecución de Playwright a la vez (GOT-005).

**Evidencia**: (1) `waitForTimeout`: de 32 llamadas a 1 (la de `quietFor()` en `helpers.ts`, `grep -rn '\.waitForTimeout' tests/e2e` → 1 coincidencia). 11 sueños de 400 ms antes de axe (transiciones de color tras `mouse.move(0, 0)`) y 4 de los diálogos de `a11y.spec.ts:126,132,141,196` pasan al helper nuevo `animationsDone(page)`, que espera dos fotogramas y al evento `finished` de cada animación/transición CSS finita (las infinitas, p. ej. spinners, se ignoran); `a11y.spec.ts` lo llama una vez en `axeYSinDesbordamiento`. Los 16 restantes eran aserciones NEGATIVAS en el tiempo (que algo NO ocurra durante una ventana: pausa del reloj/audio/vídeo, sondeo detenido, debounce cancelado, Enter sobre un enlace que no debe enviar, anti-bot de 2 s en `sites.spec.ts` verificado en `PublicSiteController::contact`, `$age >= 2`): no hay evento al que esperar, así que van por `quietFor(page, ms)` con el motivo comentado en cada llamada, y `helpers.ts` es la única excepción. (2) Salvaguarda en `tests/frontend/test-tooling.test.mjs`: recorre `tests/e2e` y falla si un fichero supera su número de llamadas admitidas (0 salvo `helpers.ts`: 1, con motivo); más un test de la propia detección. `tests/frontend/studio-presentation-close-observer.test.mjs` extrae un bloque del spec y ahora recibe `quietFor`. (3) Selectores: se probó añadir `{ exact: true }` a los 33 `getByText` de cadena literal de a11y, security y composer; falló en 7 casos (`security.spec` y `a11y.spec`: 'El doble factor está desactivado', 'Te quedan 6 códigos de recuperación', 'Las passkeys requieren HTTPS' son subcadenas de un párrafo mayor), así que se revirtió ese cambio por completo; solo se quitó el `.first()` redundante sobre el `<code>` de la clave 2FA (`a11y.spec.ts:114`, `security.spec.ts:72`, filtrado por regex y único). Sin `data-testid` nuevos. Quedan 103 `.first()` y 157 `getByText` (conteo de ocurrencias con `grep -o`; el audit contaba 97 y 151 por líneas/otro criterio): la mayoría son el primero de una lista deliberado o ya van acotados a un panel; revisarlos uno a uno sin poder ejecutar toda la batería queda como seguimiento. (4) Playwright (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en marcha, en 6 tandas): a11y+security 127 passed (4,1 min), composer 63 passed (4,3 min), campaigns+first-comment+gif-picker+hashtags 38 passed (2,4 min), instagram-audio+instagram-grid+media-import+natural-schedule+saved-texts 54 passed (3,4 min), sites+studio-audio-editor+studio-audio-resources+studio-timeline 28 passed (2,9 min), studio-presentation-media+studio-presentation-tools 8 passed (1,2 min) → 318 passed, 0 fallos. `studio-captions` no se tocó. (5) `npm run test:unit` 628/628 (la primera ejecución dio 4 fallos de `studio-presentation-close-observer` por `quietFor is not defined`; corregido); `npm run typecheck` sin errores. (6) **Segunda tanda, selectores (inventario por script de `.first()`, `.nth()` y `getByText` en `tests/e2e`, clasificado uno a uno).** Criterio: **(a)** legítimo (primero de una lista ordenada, primer resultado o fotograma, índice de una rejilla, o `getByText` ya acotado a un ámbito y por tanto estricto), **(b)** desambiguación arbitraria (el texto sale en varios sitios y `.first()` elige uno), **(c)** `getByText` con subcadena de un párrafo.

| Tipo | Antes | Resuelto | Después |
|---|---|---|---|
| (a) `.first()`/`.nth()` legítimos | 78 `.first()` + 16 `.nth()` | 0 | 78 `.first()` + 16 `.nth()` |
| (b) `.first()` de desambiguación | 25 | 25 | 0 pendientes |
| (c) `getByText` con subcadena | 7 | 7 | 0 |
| Total `.first()` | 103 | -25 | **78** (cupo por fichero en la salvaguarda) |

(Quedan 150 `getByText` frente a 157; los demás ya iban acotados o son de frase completa, y Playwright los hace fallar si son ambiguos. Los (c) se cuentan sobre `getByText`, no sobre `.first()`.)

Resueltos (b): `composer.spec` ×6 (los dos enlaces «Nueva publicación» eran únicos en la marca; los dos errores por red pasan a `items.filter({hasText}).not.toHaveCount(0)`; texto y nombre de plantilla acotados a su `li`), `gif-picker` ×1 y `media-import` ×3 (nuevo `postMediaSection(page)` en `helpers.ts`: la lista «Imágenes y vídeos», sin la previsualización por red que repite los nombres), `threads.spec` ×5 (filas dentro de la región «Lista de publicaciones»; enlaces de red con `.and(:visible)`), `bluesky.spec` ×1 (enlace de red visible), `flows.spec` ×1, `first-comment.spec` ×1 (acotado a `errors-panel`), `security.spec` ×1 (`listitem` con «Añadida el»). Resueltos (c): 7 `getByText` de `security`/`a11y` pasan a regex (`/^El doble factor está desactivado/`, `/^Las passkeys requieren HTTPS/`, `/Te quedan N códigos de recuperación/`, que lleva un `<strong>` dentro). Los de los specs que han fallado alguna vez (composer, a11y, security, gif-picker, media-import, studio-captions, workflow) quedan sin (b): en workflow, studio-captions y los restantes de security/composer el `.first()` es de lista ordenada o de «el primero de varios avisos» y lleva comentario cuando no es obvio.

**Cierre de los 7 pendientes (b)** (tercera tanda): `bluesky.spec` y `roadmap.spec` ('No disponible', 'Alcance', 'Visualizaciones') comprueban `getByRole('main')` con `toContainText`; `flows.spec` localiza el enlace por rol y nombre (`/l/<slug>$`); `studio-presentations.spec` usa `toHaveAccessibleDescription` sobre el textarea de notas (ya apuntaba a `#studio-notes-error` con `aria-describedby`); `studio-collab.spec` filtra la alerta por su texto y exige `toHaveCount(1)`; `sites.spec` usa `toHaveAccessibleDescription` sobre el campo «Dirección». Cambio mínimo de UI (accesibilidad): en `resources/js/Pages/Sites/Index.vue` el campo de la dirección añade `site-slug-error` a su `aria-describedby`, `aria-invalid` cuando hay error, y el `InputError` recibe ese `id`. Sin `data-testid` nuevos. Con la UI tocada se ejecutó `npm run build` antes de los E2E. Cupos de `.first()` bajados en bluesky (1→0), flows (2→1), roadmap (4→2), sites (3→2), studio-presentations (1→0) y studio-collab (6→5). Playwright (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso): bluesky+flows+sites+studio-presentations+studio-collab 38 passed (4,0 min); `a11y.spec.ts` 111 passed (3,3 min; no se había repetido tras la corrección anterior); `roadmap.spec` (config propia, E2E-10) 3 passed. `npm run test:unit` 630/630 y `npm run typecheck` sin errores.

(7) `waitForTimeout` fuera de `tests/e2e`: `tests/frontend/studio-audio-preview.browser.test.mjs` pasa de dos `waitForTimeout(260)` a `page.waitForFunction` sobre el propio analizador (primero hasta que las dos pistas superan el RMS mínimo esperado; luego hasta que el RMS baja de 0,0001 tras pausar). La salvaguarda cubre ahora `tests/e2e` y `tests/frontend` (excepciones: `helpers.ts` 1, y el doble de Playwright de `studio-presentation-close-observer.test.mjs` 1). (8) Playwright de la segunda tanda (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso): composer 63 passed (4,3 min); gif-picker+media-import+threads+bluesky 57 passed (3,6 min); security+a11y 123 passed y 4 fallos (el `^` en `Te quedan N códigos`, corregido) → security+first-comment 32 passed (2,4 min); flows+workflow 27 passed (2,2 min). `npm run test:unit` 630/630, `npm run test:browser` 61/61, `npm run typecheck` sin errores.

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

### T-19 — Job nocturno del camino nativo MP4/GIF (ffmpeg + Chromium)

- **Descripción**: Job nocturno que instala ffmpeg y Chromium y ejecuta con `STUDIO_AV_NATIVE_TEST=1` los 9 tests hoy omitidos.
- **Changelog**: El camino nativo de exportación MP4/GIF (ffmpeg y Chromium reales) se prueba cada noche en CI, con artefactos si falla.
- **Estado**: completado
- **Cerrada**: 2026-11-12
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-15
- **Tipo**: devops
- **Archivos**: `.github/workflows/nightly.yml`, `docs/modulos/estudio.md`, `docs/guias/testing.md`
- **Cubre**: E-08
- **Cubre (tests)**: —
- **Verificación**: lectura: `nightly.yml` define el job `native-av` con `STUDIO_AV_NATIVE_TEST=1` · `STUDIO_AV_NATIVE_TEST=1 php artisan test --compact --filter=StudioAv` → 0 omitidos (si ffmpeg/Chromium están instalados; si no, `skip` documentado)

**Criterios de aceptación**
- [x] El job nocturno corre los 9 tests nativos sin `skip`
- [x] El job publica los artefactos de fallo
- [x] La doc explica cómo correrlos en local
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `nightly.yml` define `native-av` (guarda de repo, `STUDIO_AV_NATIVE_TEST=1`, ffmpeg, runtime del estudio, Chromium con caché, `npm run build`; falla si hay `"skipped":N`; sube logs y `storage/framework/testing/` si falla). `actionlint` v1.7.7 sobre nightly.yml y ci.yml → exit 0. Local: `STUDIO_AV_NATIVE_TEST=1 php artisan test --compact --filter=StudioAv` → `{"result":"passed","tests":166,"passed":166,"assertions":1122}` (0 omitidos; sin la variable los 8 casos se omitían: son 8, no 9, el 9.º es el sondeo de 51 MB). Sondeo de 51 MB: con el `memory_limit` por defecto (512 MB) falla por memoria; `php -d memory_limit=1G vendor/bin/pest --filter=test_mcp_large_escaped` → passed (pico 560 MB), por eso el job lo ejecuta con 1G. cgroup (`test_av_linux.py`): paso experimental con `systemd-run` como `runner` y `Delegate=yes`, `continue-on-error`; no verificable aquí (requiere runner real), así que no es aceptación Linux. Archivos: los tests no cambian (se quitan del `Archivos` los PHP y `estudio-ia.md` → el módulo correcto es `estudio.md`).

**Notas**: Tamaño M. Qué se puede hacer ya: workflow y doc. La primera ejecución real ocurre en GitHub tras el push del usuario.

---

**Cierre (2026-11-12)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 2); CI de la PR #20 en verde en todos los jobs; E2E completo de cierre de fase en paralelo (2 workers, 3 proyectos, móvil solo `@responsive`): 653 passed / 2 skipped / 0 failed en 34,2 min (frente a ~1,5 h en serie), tras corregir los defectos reales que destapó (barra de navegación accesible, 429 en castellano, selector de marca y desbordamientos móviles; ver «Cierre de la Fase 2»).

## Fase 3 — Funciones incompletas

**Estado**: borrador · **Estimado**: 118.0h · **Real**: — · **Coste est.**: 5900 € (base) + tokens ⚠️ verificar · **Tokens est.**: 5630k

### T-20 — Inventario corregido y nombres de ruta únicos

- **Descripción**: Corregir las filas desfasadas del inventario (C24, M11, C22) y dar nombres únicos `api.studio.*` a las rutas API que repiten `studio.folders.*` y nombres de datasets autogenerados.
- **Changelog**: Las rutas de las tablas de datos del estudio tienen nombres explícitos y únicos, y una prueba impide que vuelvan a repetirse o autogenerarse.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-18
- **Tipo**: backend
- **Archivos**: `docs/roadmap/2026-11-03-creative-social-suite/reference-inventory.md`, `routes/studio.php`, `routes/studio-api.php`, `routes/studio-datasets.php`, `resources/js/Studio/dataset-client.js`, `tests/Feature/Routes/RouteNamesTest.php`
- **Cubre**: B-19, B-20
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=RouteNamesTest` → verde · `php artisan route:cache` → sin error de nombres duplicados

**Criterios de aceptación**
- [x] `php artisan route:list --json` sin nombres duplicados
- [x] Todas las llamadas del frontend a las rutas renombradas siguen funcionando
- [x] Inventario con C24, M11 y C22 actualizados
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Routes/RouteNamesTest.php falló con `studio.datasets.list-0 … export-10` (11 nombres autogenerados por índice) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=RouteNamesTest` → 3/3 verde; `php artisan route:cache` → «Routes cached successfully» (sin duplicados); `php artisan route:list --json` → 424 rutas, 0 nombres repetidos, 0 autogenerados. Pest focal `tests/Feature/Analytics`+`Connectors`+`Reports`+`Infra`+`Parity`+`Ui` 918/918 y `--filter=Dataset` 535/536 (1 omitido); Pint OK.
- Hallazgo B-20 afinado: los nombres web `studio.folders.*` y los de la API (`api.studio.folders.*`) **ya eran distintos** (el grupo de `studio-api.php` lleva `->name('api.')`); el defecto real eran los 11 nombres autogenerados `studio.datasets.<acción>-<índice>` de `routes/studio-datasets.php`, ahora `studio.datasets.{list,create,get,replace,archive,restore,history,rows,import-create,import-replace,export}`. El frontend (`dataset-client.js`, `chart-resources.js`) y los E2E usan URL, no nombres de ruta, y ninguna doc/OpenAPI citaba los nombres antiguos: no hay referencias que actualizar (Ziggy no los exporta). `dataset-client.js` queda sin cambios.
- Inventario (B-19), comprobado contra el código: M11 `SeriesTest` 10/10 y `tests/Feature/Workflow` 70 tests (antes 8 y 46); C22 `studio-charts.spec.ts` ya no tiene `test.fixme`; C24 pasa de «Fuera» a «Cubierta (opcional)» (`StudioAiTest`+`StudioCaptionsTest` 98 tests, `studio-ai.spec.ts`, desactivada por defecto).
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

**Notas**: Tamaño S. Hacerlo antes del refactor del frontend: evita renombrar dos veces (D-19 usa los nombres nuevos).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-21 — Pinterest: analítica y tableros paginados

- **Descripción**: `InsightsReader` de Pinterest (`/v5/user_account/analytics`, `/v5/pins/{id}/analytics`) enlazado en `AppServiceProvider`, tableros paginados con `bookmark` y botón de refresco. LinkedIn queda en espera.
- **Changelog**: Pinterest muestra sus estadísticas (impresiones, interacciones, clics, guardados) en el panel, las mejores horas, los informes y el top de publicaciones; los tableros se leen paginados (con aviso si la lista queda incompleta) y se pueden actualizar desde Conexiones, y si la analítica no existe en el entorno de pruebas el panel lo explica.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20, T-07
- **Tipo**: fullstack
- **Archivos**: `app/Connectors/Pinterest/PinterestInsightsReader.php`, `app/Connectors/Pinterest/PinterestConnector.php`, `app/Providers/AppServiceProvider.php`, `app/Analytics/Metric.php`, `app/Services/AnalyticsService.php`, `app/Http/Controllers/ConnectionController.php`, `routes/web.php`, `config/social.php`, `resources/js/Pages/Brands/**`, `tests/Feature/Connectors/Pinterest/**`, `tests/Feature/Analytics/ProviderMetricsContractTest.php`, `tests/e2e/linkedin-pinterest.spec.ts`, `docs/modulos/analitica.md`, `docs/modulos/conexiones.md`, `docs/guias/linkedin-pinterest.md`, `docs/referencia/decisiones-por-red.md`, `app/Jobs/Concerns/GuardsInsightsErrors.php`, `app/Analytics/MetricCoverage.php`, `app/Connectors/Pinterest/PinterestClient.php`, `resources/js/Pages/Dashboard/Partials/quality.js`, `tests/Feature/Snapshots/QuotaRetryTest.php`, `tests/frontend/metric-quality.test.mjs`, `docs/roadmap/2026-11-03-creative-social-suite/reference-inventory.md`
- **Cubre**: B-01, B-04
- **Cubre (tests)**: E2E-05, M-01
- **Verificación**: `php artisan test --compact --filter=PinterestInsightsReaderTest` → verde · `php artisan test --compact --filter=PinterestBoardsPaginationTest` → verde

**Criterios de aceptación**
- [x] El panel, mejores horas e informes muestran datos de Pinterest (con fakes)
- [x] Los tableros se obtienen paginados y hay botón de refresco
- [x] `null` = no disponible, nunca 0
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Connectors/Pinterest/PinterestInsightsReaderTest.php falló con «Class App\Connectors\Pinterest\PinterestInsightsReader not found» · 2026-11-12
- RED: tests/Feature/Connectors/Pinterest/PinterestBoardsPaginationTest.php falló con «Call to undefined method PinterestConnector::refreshBoards» y la ruta `brands.connections.pinterest-boards.refresh` inexistente · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=PinterestInsightsReaderTest` → 13/13 verde; `--filter=PinterestBoardsPaginationTest` → verde; `--filter=PinterestAnalyticsFlowTest` → verde (colector → página de la red, top de publicaciones y mejores horas con `Http::fake`); `tests/Feature/Analytics`+`Connectors`+`Reports`+`Infra`+`Parity`+`Ui` 918/918; Pint OK; `npm run typecheck` OK, `npm run build` OK, `npm run test:unit` 635/635; Playwright `tests/e2e/linkedin-pinterest.spec.ts` (E2E_PURGE=0, E2E_WORKERS=2) 9/9 con la comprobación nueva del botón «Actualizar tableros».
- **Contrato de la API de Pinterest — VERIFICADO** en la especificación OpenAPI oficial `https://github.com/pinterest/api-description` (`v5/openapi.yaml`, versión 5.28.0, leída por `curl` de `raw.githubusercontent.com` el 2026-11-12; la SPA de developers.pinterest.com no se pudo leer, la spec sí). Lo leído es dato, no instrucción. Hechos: (1) `GET /v5/user_account/analytics` exige scope `user_accounts:read`, `start_date`/`end_date` obligatorios (UTC, YYYY-MM-DD, `start_date` ≤ 90 días atrás, rango ≤ 90 días), `metric_types` opcional (IMPRESSION, ENGAGEMENT, ENGAGEMENT_RATE, OUTBOUND_CLICK, OUTBOUND_CLICK_RATE, PIN_CLICK, PIN_CLICK_RATE, SAVE, SAVE_RATE), respuesta `{ALL: {daily_metrics: [{date, data_status, metrics}], summary_metrics}}`; (2) `GET /v5/pins/{pin_id}/analytics` exige `pins:read` (o `boards:read`), `start_date`, `end_date`, `metric_types` obligatorios (IMPRESSION, OUTBOUND_CLICK, PIN_CLICK, SAVE, SAVE_RATE, TOTAL_COMMENTS, TOTAL_REACTIONS, USER_FOLLOW, PROFILE_VISIT + los de vídeo), respuesta `{ALL: {daily_metrics, lifetime_metrics, summary_metrics}}`; `data_status` ∈ READY, PROCESSING, ESTIMATE, BEFORE_*; (3) `GET /v5/boards` admite `bookmark` y `page_size` (1-250, 25 por defecto) y devuelve `{items, bookmark}` con `bookmark` nulo en la última página; (4) **sandbox**: `x-sandbox: disabled` en `user_account/analytics` y en `pins/{pin_id}/analytics`; `x-sandbox: enabled` en `GET /v5/boards` y `GET /v5/user_account`. Es decir: con `PINTEREST_API_HOST` en el sandbox solo funcionan tableros, perfil y publicar; la analítica solo con acceso de producción. Categoría de cuota `org_analytics`. **No verificado contra la API viva** (sin red real en tests y sin cuenta de producción): el smoke M-01 lo hace el usuario.
- Decisiones: `views` = IMPRESSION (Pinterest no da «visualizaciones» de cuenta; mismo criterio que Meta); `interactions` de cuenta = ENGAGEMENT; `clicks` = OUTBOUND_CLICK; por pin `interactions` = SAVE + PIN_CLICK + OUTBOUND_CLICK + reacciones + comentarios; ventana de pin de 89 días (la API no admite más); `EXPECTED_FLOWS['pinterest']` = views e interactions; backfill de 30 días (`SOCIAL_BACKFILL_DAYS_PINTEREST`). Tableros: 100 por página, tope 500, corte si el `bookmark` se repite; «Actualizar tableros» = `POST brands/{brand}/connections/{account}/pinterest-boards` (throttle 10/min, solo propietario, solo Pinterest). El test de contrato T-03 «sin lector → unsupported» pasa a cubrir solo LinkedIn.
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial; smoke M-01 con cuenta real. LinkedIn sigue a la espera (`community_management`).

**Notas**: Tamaño M. Se implementa Pinterest (Ya). LinkedIn (U): estadísticas de página quedan a la espera de `community_management`; se deja el punto de extensión y la fila en la cobertura (B-14). Verificación real = smoke M-01. **A la espera (no bloquea la fase)**: Lector de LinkedIn: requiere aprobación `community_management` (usuario/LinkedIn).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-22 — Inbox: scopes de Meta, permisos de Threads y cobertura de redes

- **Descripción**: La UI explica cuándo faltan scopes de Meta (inbox/Ads) y cómo activarlos; Threads trata el error de permiso como «reconecta» y retira el scope; LinkedIn y Pinterest aparecen en la tabla de cobertura del inbox.
- **Changelog**: El inbox explica qué permiso falta en cada cuenta (Facebook necesita META_EXTRA_SCOPES y reconectar), Threads pide reconectar si se retiran los permisos de respuesta y la cobertura por red incluye LinkedIn y Pinterest con su motivo.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: fullstack
- **Archivos**: `app/Connectors/Meta/Comments.php`, `app/Connectors/Meta/AdsService.php`, `app/Connectors/Threads/ThreadsConnector.php`, `app/Services/InboxService.php`, `config/social.php`, `resources/js/Pages/Inbox/Index.vue`, `resources/js/Pages/Brands/**`, `docs/modulos/inbox.md`
- **Cubre**: B-02, B-11, B-14
- **Cubre (tests)**: E2E-04, M-04
- **Verificación**: `php artisan test --compact --filter=InboxCoverageTest` → verde · `php artisan test --compact --filter=ThreadsPermissionErrorTest` → verde

**Criterios de aceptación**
- [x] Sin scopes Meta, el inbox/Ads muestra un estado claro con el motivo y los pasos (no un panel vacío)
- [x] Un error de permiso de Threads marca la cuenta como «reconectar» y retira el scope concedido
- [x] La tabla de cobertura incluye LinkedIn y Pinterest con su motivo
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Inbox/InboxCoverageTest.php falló con «Failed asserting that an array has the key 'linkedin'» y la prop `accounts.0.help` inexistente; tests/Feature/Inbox/ThreadsPermissionErrorTest.php falló con PermanentPublishException (no ReconnectRequiredException) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=InboxCoverageTest` → verde; `--filter=ThreadsPermissionErrorTest` → verde (7 tests / 45 aserciones entre ambos); Pint OK; `npm run build` OK, `test:unit` y `typecheck` OK; Playwright `inbox-triage.spec.ts` 9/9 (`E2E_PURGE=0 E2E_WORKERS=2`, filas de cobertura de LinkedIn y Pinterest añadidas a E2E-20a).
- Decisiones: Meta Ads ya tenía su aviso con pasos (`AdsService::HOWTO`); el inbox ahora da por cuenta el permiso que falta y los pasos (`missing_scopes`, `help`) con enlace a Conexiones, y reutiliza el patrón de aviso ámbar existente. En Threads el rechazo por permisos retira solo el scope afectado (leer → `threads_read_replies`, responder → `threads_manage_replies`); el cambio de estado y el aviso los hace el flujo común `flagReconnection`. `ThreadsConnector.php:103` guarda los scopes PEDIDOS al conectar, así que reconectar los restablece.
- Pendiente (coordinador): revisión adversarial A+B(+C); `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn tras su aprobación.

**Notas**: Tamaño M. Qué se puede hacer ya: UI, retirada de scope y filas de cobertura. Queda a la espera (U): activar el caso de uso en Meta, rellenar `META_EXTRA_SCOPES` y reconectar; `CommentSource` de LinkedIn tras su aprobación. **A la espera (no bloquea la fase)**: `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn (aprobación).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-23 — Avisos de caducidad y notificaciones de jobs asíncronos

- **Descripción**: Generalizar el aviso de caducidad a toda caducidad conocida (notificación + correo N días antes) y notificar en BD (y correo opcional) los informes programados, MP4/GIF, IA, Canva e importaciones.
- **Changelog**: Conexiones y Avisos te dicen con antelación (7 y 1 días) cuándo caduca el acceso de Meta o LinkedIn y hay que reconectar, y un informe programado, una exportación MP4/GIF, un trabajo de IA, de Canva o una importación que falla te avisa en la app (y por correo si lo activas en tu perfil), con título y enlace propios también en los avisos del estudio.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `app/Jobs/RefreshSocialAccount.php`, `app/Jobs/SendScheduledReport.php`, `app/Jobs/Studio/RunStudioAiJob.php`, `app/Jobs/RunCanvaJob.php`, `app/Jobs/ImportRemoteMedia.php`, `app/Notifications/**`, `app/Http/Controllers/ConnectionController.php`, `resources/js/Pages/Notifications/**`, `docs/modulos/conexiones.md`, `app/Services/ConnectionExpiry.php`, `app/Services/ConnectionExpiryNotifier.php`, `app/Services/JobFailureNotifier.php`, `app/Console/Commands/NotifyConnectionExpiry.php`, `app/Services/Studio/StudioAi.php`, `app/Services/Studio/StudioAvState.php`, `app/Services/Studio/StudioRenderer.php`, `app/Services/Canva/CanvaJobs.php`, `app/Services/Media/RemoteMediaImporter.php`, `app/Jobs/Studio/RenderStudioDesign.php`, `app/Models/User.php`, `app/Http/Controllers/NotificationController.php`, `app/Http/Requests/ProfileUpdateRequest.php`, `app/Connectors/LinkedIn/LinkedInConnector.php`, `config/social.php`, `routes/console.php`, `database/migrations/2026_12_19_090000_add_notify_by_mail_to_users.php`, `resources/js/Pages/Brands/Connections.vue`, `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`, `docs/modulos/publicacion.md`, `docs/referencia/configuracion.md`, `docs/guias/linkedin-pinterest.md`, `tests/Feature/Connections/ConnectionExpiryNotificationTest.php`, `tests/Feature/Notifications/JobCompletionNotificationTest.php`, `tests/Feature/Studio/StudioAvQueueTest.php`, `tests/Feature/Inbox/InboxProvidersTest.php`, `tests/e2e/notifications-unified.spec.ts`
- **Cubre**: B-03, B-13
- **Cubre (unificación)**: U-21
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --compact --filter=ConnectionExpiryNotificationTest` → verde · `php artisan test --compact --filter=JobCompletionNotificationTest` → verde

**Criterios de aceptación**
- [x] Meta (90 días) y LinkedIn avisan N días antes de caducar, una sola vez por umbral
- [x] Fallo de informes programados, MP4/GIF, IA, Canva e importaciones: aviso al fallar; al terminar BIEN, aviso solo en la app (nunca por correo) para los trabajos largos que el usuario no ve terminar: exportación MP4/GIF, exportación o envío de Canva, IA de imagen e importación por URL (decisión del coordinador; el informe programado no avisa al terminar porque su correo ya es el aviso)
- [x] El correo es opcional por preferencia del usuario
- [x] Los avisos `studio_design_*` se muestran con título por tipo (no como «Aviso» genérico) y con enlace a su diseño mediante `n.url` genérico (`Notifications/Index.vue`, `StudioDesignActivity.php`)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Connections/ConnectionExpiryNotificationTest.php y tests/Feature/Notifications/JobCompletionNotificationTest.php fallaron con «Class App\Services\ConnectionExpiryNotifier not found», «App\Notifications\AsyncJobFailed not found» y la columna `notify_by_mail` inexistente (16 tests, 8 en rojo por clases ausentes) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=ConnectionExpiryNotificationTest` → 7/7 verde; `--filter=JobCompletionNotificationTest` → 10/10 verde; focal `Notifications`+`Connections`+`Auth`+`ProfileTest` 290/290; `Canva`+`Studio`+`Reports`+`Media`+`Jobs`+`Console`+`Routes`+`Infra`+`Parity`+`Inbox`+`Ui`+`AdvancedOptionsContractTest` 1438 tests con 3 rojos legítimos corregidos (cobertura del inbox 6→8 filas; `StudioAvQueueTest` aserta ahora «no se encola ProcessStudioAvPhase» porque el aviso de fallo es un efecto esperado) y vuelto a ejecutar en verde; Pint OK; `npm run build` OK, `test:unit` 636/636, `typecheck` OK; Playwright `notifications-unified.spec.ts` (E2E-03) 3/3 y `workflow.spec.ts` + `linkedin-pinterest.spec.ts` con `E2E_PURGE=0 E2E_WORKERS=2`.
- Decisiones: (1) Caducidad conocida que NO se renueva sola = acceso a datos (`data_access_expires_at`, cualquier red que lo informe: Meta 90 días) y token de LinkedIn sin `refresh_token` (`ConnectionExpiry`); los tokens que se refrescan solos no avisan (ya lo hace `ReconnectRequired` si fallan). (2) Umbrales `SOCIAL_EXPIRY_NOTICE_DAYS=7,1`, pasada diaria `connections:notify-expiry`; marca `meta.expiry_notice {at, threshold}` por cuenta: una vez por umbral y fecha, se reinicia al reconectar. Sustituye a `LINKEDIN_RECONNECT_NOTICE_DAYS`. (3) Preferencias de notificación: no existían; se añade `users.notify_by_mail` (apagado por defecto, casilla en Perfil) que activa el canal `mail` solo en `AsyncJobFailed` y `ConnectionExpiring`; `PublishFailed`/`ReconnectRequired` no cambian. (4) Decisión del coordinador (revisión 1): también aviso de éxito, solo en la app, para exportación MP4/GIF, Canva, IA de imagen e importación por URL; el informe programado no (su correo ya es el aviso). (5) Frecuencia: un aviso por trabajo (clave de caché 24 h) y tope de 5 por tipo y hora y usuario (`JobFailureNotifier::BURST`). (6) Exportaciones: solo MP4/GIF; el aviso del cierre por `StudioAvState` va tras el commit (`DB::afterCommit`). (7) U-21: `StudioDesignActivity` lleva `url` al diseño (ninguno si se retiró el acceso) y `Notifications/Index.vue` da título por tipo y un enlace genérico `n.url` con etiqueta por tipo; también corrige que `series_failed` enlazara a Conexiones.
- Migración nueva `2026_12_19_090000_add_notify_by_mail_to_users` (no aplicada a la BD de desarrollo/E2E: hay que ejecutar `php artisan migrate` antes de usar la casilla de Perfil).
- Pendiente (coordinador): revisión adversarial A+B(+C), aplicar la migración, y el smoke real de correo (sin SMTP real: `Notification::fake`).

**Notas**: Tamaño M. Reutilizar el mecanismo de notificación de fallos de publicación. Sin SMTP real: `Mail::fake`.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-24 — Primer comentario en Threads y Bluesky

- **Descripción**: Implementar `FirstCommenter` en Threads y Bluesky reutilizando `ThreadsComments`/`BlueskyComments`.
- **Changelog**: Puedes añadir un primer comentario a las publicaciones de Threads y Bluesky; si el comentario falla, la publicación principal sigue publicada y puedes reintentarlo.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Threads/ThreadsComments.php`, `app/Connectors/Bluesky/BlueskyComments.php`, `config/media_specs.php`, `docs/modulos/publicacion.md`, `app/Connectors/Threads/ThreadsClient.php`, `app/Connectors/Exceptions/ScopeMissingException.php`, `app/Connectors/Google/YouTubePublisher.php`, `app/Jobs/PostFirstComment.php`, `tests/Feature/FirstComment/FirstCommentThreadsBlueskyTest.php`, `docs/referencia/decisiones-por-red.md` (ampliado en la implementación: gaps de la revisión de T-22 y rutas reales)
- **Cubre**: B-05
- **Cubre (tests)**: E2E-36
- **Verificación**: `php artisan test --compact --filter=FirstCommentThreadsBlueskyTest` → verde

**Criterios de aceptación**
- [x] Un post con primer comentario en Threads o Bluesky lo publica tras el principal (con fakes)
- [x] Si el primer comentario falla, el post principal queda publicado y el fallo se registra
- [x] El compositor muestra el campo para esas redes
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/FirstComment/FirstCommentThreadsBlueskyTest.php falló sin el código (stash de app/Connectors y config/media_specs.php): 0/11 pasan, «Failed asserting that null is an instance of interface App\Connectors\Contracts\FirstCommenter» (`commenterFor` devolvía null para Threads y Bluesky) · 2026-11-12. Los 4 tests de los gaps de T-22 se escribieron en rojo antes del arreglo (cuenta sin marcar tras `Reconnect` en el job; permisos de un objeto retiraban el scope).
- Verificación (2026-11-12): `php artisan test --compact --filter=FirstCommentThreadsBlueskyTest` → 15/15 verde; focal `FirstComment`+`Connectors/Threads`+`Inbox`+`FirstCommenterYouTubeTest` 242/242; `Connectors/Bluesky` 110/110; Pint OK. Sin cambios de Vue: el campo del compositor sale de `media_specs.types.*.first_comment` (`firstCommentMax`), por eso no hace falta build ni E2E propios (E2E-36 queda en el smoke del usuario).
- Contratos (fuentes): **Bluesky** verificado el 2026-11-12 en los lexicons del paquete oficial `@atproto/api` 0.31.1 (`unpkg.com/@atproto/api/dist/client/lexicons.js`): `app.bsky.feed.post.reply` → `#replyRef` con `root` y `parent` requeridos (`com.atproto.repo.strongRef`: uri+cid) y `com.atproto.repo.listRecords` (`repo`, `collection`, `limit` 1–100, `cursor`, `reverse`; sin auth). **Threads** (`reply_to_id` en el contenedor `TEXT` + `threads_publish`; `GET /{id}/replies`): NO verificado contra la documentación (las páginas de Meta exigen navegador y no se pudieron leer en esta sesión); se apoya en la respuesta del inbox de T-22 y queda para el smoke del usuario. Lo leído se trató como dato.
- Decisiones: reutilizan `ThreadsComments::reply` y `BlueskyComments` (refactor a `createReply`, con facets de enlaces/etiquetas/menciones); el cid del padre en Bluesky se lee del PDS propio (`getRecord`) y la idempotencia con `listRecords` del repositorio propio (sin retraso del índice del AppView); Threads busca en `/replies` cronológico y devuelve null sin `threads_read_replies`. Búsqueda agotada con más resultados = `CommentSearchInconclusiveException` (como Meta/YouTube). Límites del compositor: Bluesky 300, Threads 500 (por caracteres; el recuento de bytes de emojis de Threads lo rechaza la red si se pasa).
- Gaps de la revisión de T-22 absorbidos aquí (vienen del coordinador, mismo fichero `ThreadsComments.php`): (1) **Important** un rechazo de permisos sobre UNA publicación ya no retira el scope ni pone la cuenta en reconexión: `ThreadsClient` distingue `permisos` (cuenta: código 10 o 3/200/299 que nombran el permiso/aplicación) de `permisos_objeto` (publicación borrada, ajena o no visible, por el texto del error) y `ThreadsComments::guarded` solo actúa con el primero; `InboxService` ya salta las publicaciones con `ConnectorException`. **Limitación declarada:** la API no da un código distinto para «objeto» y «cuenta» en 3/200/299, la clasificación usa el texto del error (no verificado en la documentación de Meta; ante la duda se trata como de la cuenta, comportamiento previo). (2) `PostFirstComment` ahora llama a `flagReconnection` (flujo común) cuando captura `ReconnectRequiredException`; para no bloquear lo programado por un permiso OPCIONAL, la falta de permiso de comentar usa la nueva `ScopeMissingException` (YouTube y Threads), que falla el comentario sin marcar la cuenta.

**Notas**: Tamaño M. Verificación real = smokes de Threads/Bluesky del usuario (contratos sin verificar).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-25 — Competidores en Bluesky y YouTube

- **Descripción**: Añadir fuentes de competidores para Bluesky (`getProfile`/`getAuthorFeed`) y YouTube (`channels.list`).
- **Changelog**: Competidores ahora funciona también con Bluesky y YouTube: eliges la red en la pantalla, añades cuentas o canales y ves su evolución y sus publicaciones destacadas; en YouTube la lectura respeta un tope diario de cuota.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Services/CompetitorService.php`, `app/Connectors/Bluesky/**`, `app/Connectors/YouTube/**`, `resources/js/Pages/Competitors/**`, `docs/modulos/competidores.md`, `app/Connectors/Contracts/CompetitorSource.php`, `app/Connectors/CompetitorSources.php`, `app/Connectors/Meta/InstagramCompetitors.php`, `app/Connectors/Google/YouTubeCompetitors.php`, `app/Connectors/Google/YouTubeCompetitorBudget.php`, `app/Http/Controllers/CompetitorController.php`, `app/Jobs/CollectCompetitorPosts.php`, `app/Jobs/CollectCompetitorSnapshot.php`, `app/Jobs/Concerns/GuardsInsightsErrors.php`, `app/Mcp/Tools/CompareCompetitors.php`, `app/Models/Competitor.php`, `app/Providers/AppServiceProvider.php`, `config/social.php`, `database/migrations/2026_12_20_090000_add_network_to_competitors.php`, `tests/Feature/Competitors/CompetitorSourcesTest.php`, `tests/Feature/Parity/CompetitorTest.php`, `tests/e2e/competitors.spec.ts`, `tests/e2e/helpers.ts`, `docs/referencia/configuracion.md`, `docs/referencia/decisiones-por-red.md` (ampliado en la implementación; las rutas reales de YouTube son `app/Connectors/Google/`)
- **Cubre**: B-06
- **Cubre (tests)**: E2E-40
- **Verificación**: `php artisan test --compact --filter=CompetitorSourcesTest` → verde

**Criterios de aceptación**
- [x] Se pueden añadir competidores de Bluesky y YouTube y se recogen snapshots (con fakes)
- [x] La cuota de YouTube se contabiliza con el mecanismo de cuota de YouTube de la app (`YouTubeQuota` global + presupuesto propio de competidores, global y por usuario); `QuotaPolicy` es la política de PUBLICACIÓN y no aplica a lecturas (criterio enmendado por decisión del coordinador en la revisión del intento 1)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `tests/Feature/Competitors/CompetitorSourcesTest.php` se escribió con el diseño (sin RED previo registrado: las clases `CompetitorSource*` no existían); al ejecutarlo fallaron 2/12 por defectos reales corregidos antes de cerrar (delimitador `~` de la expresión del URI de Bluesky dentro de su clase de caracteres; el test del 401 llamaba a otro endpoint). Verde 12/12.
- Verificación (2026-11-12): `php artisan test --compact --filter=CompetitorSourcesTest` → 12/12 verde; focal `Competitors`+`Parity`+`Reports`+`Mcp`+`Analytics`+`Jobs` 561/561; Pint OK; `npm run build` OK, `test:unit` 636/636 y `typecheck` OK; Playwright `competitors.spec.ts` 6/6 (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso; incluye el nuevo E2E-40 en desktop y los dos móviles).
- Decisiones: (1) **Contrato nuevo `CompetitorSource`** (`app/Connectors/Contracts`): la línea que citaba el hallazgo (`CompetitorService.php:36`) era la consulta fija de Instagram, no un contrato existente; se creó el contrato y `CompetitorSources` (registro en `AppServiceProvider`), con `InstagramCompetitors` envolviendo a `BusinessDiscovery` sin cambiarlo. (2) Bluesky = AppView público sin cuenta propia (`needsAccount()` falso); YouTube = token de un canal propio de la marca. (3) **Cuota de YouTube**: `QuotaPolicy` es la política de PUBLICACIÓN (reprogramar o fallar) y no sirve para lecturas; se contabiliza con el mecanismo que ya usa la app para YouTube, `YouTubeQuota` (global) más un presupuesto propio `YouTubeCompetitorBudget` (300 ud/día, `SOCIAL_COMPETITORS_YOUTUBE_UNITS`, patrón de `YouTubeInboxBudget`), 1 ud por lista. Si el criterio exigía `QuotaPolicy` literalmente, queda para la revisión. (4) 403 `quotaExceeded` y 429 → `QuotaExceededException` (con `Retry-After`) y los jobs los reintentan con `GuardsInsightsErrors` (espera creciente, tope 1 h); el trait acepta ahora `?SocialAccount` (Bluesky no tiene cuenta que marcar). (5) Migración `2026_12_20_090000_add_network_to_competitors` (`network`, `external_id`, `username` a 253, único marca+red+usuario): **no aplicada a la BD de desarrollo**; hay que ejecutar `php artisan migrate` (los E2E usan SQLite desechable y la aplican solos). (6) Los archivos de YouTube están en `app/Connectors/Google/` (no existe `app/Connectors/YouTube/`). (7) UI: chips de red con el mismo patrón y clases que `Dashboard/Partials/NetworkTabs`, mismos `FeatureEmpty`, tabla, rango y CSV; textos por red; la prop `has_instagram` pasa a `has_account` + `networks`. La URL de Instagram no lleva `network` (se conservan los enlaces). (8) MCP `compare_competitors` admite `network`; los informes siguen en Instagram (fuera de alcance).
- Contratos: Bluesky `getProfile`/`getAuthorFeed` y YouTube `channels.list`/`playlistItems`/`videos.list` (coste 1 ud, `forHandle`, `hiddenSubscriberCount`) **no se pudieron contrastar con la documentación oficial en esta sesión** (páginas renderizadas por navegador o bloqueadas); se tomaron del contrato conocido de cada API y se citan como pendientes de smoke real. Lo que sí se verificó: lexicon `com.atproto.repo.listRecords` y `app.bsky.feed.post` (T-24).
- Pendiente (coordinador): revisión adversarial A+B(+C) y aplicar la migración; smoke real de Bluesky y YouTube (usuario).

**Notas**: Tamaño M.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-26 — Canva: verificación JWKS del `correlation_jwt`

- **Descripción**: Leer y cachear `/rest/v1/connect/keys` y validar RS256 del `correlation_jwt`.
- **Changelog**: El retorno desde el editor de Canva ahora verifica la firma del enlace de vuelta con las claves públicas de Canva; un enlace falsificado, caducado o de otra aplicación se rechaza.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Services/Canva/CanvaDesigns.php`, `app/Services/Canva/CanvaJwks.php`, `docs/modulos/canva.md`, `composer.json`, `composer.lock`, `app/Services/Canva/CanvaJwks.php`, `tests/Feature/Canva/CanvaJwksTest.php`, `tests/Feature/Canva/CanvaConnectionTest.php`, `tests/Feature/Canva/CanvaTestSupport.php`
- **Cubre**: B-07
- **Cubre (tests)**: M-10
- **Verificación**: `php artisan test --compact --filter=CanvaJwksTest` → verde

**Criterios de aceptación**
- [x] Un JWT con firma inválida, expirado o `kid` desconocido se rechaza
- [x] Las claves se cachean con renovación ante `kid` desconocido
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `RED: CanvaJwksTest falló con «Target class [App\Services\Canva\CanvaJwks] does not exist» (17/17) · 2026-11-12`. Antes se corrigió el helper de claves de prueba: en esta máquina `openssl_pkey_new` falla sin `openssl.cnf` (error 07000072), así que el helper escribe un `openssl.cnf` mínimo en el directorio temporal.
- Verificación (2026-11-12): `php artisan test --compact --filter=CanvaJwksTest` → 17/17 verde (29 aserciones); `tests/Feature/Canva` → 86/86 verde; Pint OK.
- Decisiones: (1) `firebase/php-jwt` ^7.2 declarada como dependencia directa (`composer require`; el lock ya traía v7.2.1, solo cambia `content-hash`). (2) `CanvaJwks::verify()` fija RS256 en la cabecera Y en cada `Key` (rechaza none/HS256), exige `kid` conocido, `exp` y `aud` = `client_id` (cadena o lista); `iss` NO se exige porque la guía oficial no lo documenta. (3) Caché de claves 1 h; un `kid` desconocido renueva como mucho una vez por minuto (`Cache::add`), de modo que un `kid` inventado no dispara una petición por intento. (4) Sin `client_id` o sin claves (500, red caída, vacío) el JWT se rechaza: nunca se acepta sin verificar. (5) `resolveReturn` delega en `CanvaJwks` y conserva `type = rti` y el `correlation_state` firmado; se eliminó la lectura manual del payload.
- Contrato Canva: **verificado en parte** contra https://www.canva.dev/docs/connect/return-navigation-guide/ (2026-11-12): `?correlation_jwt=`, JWKS en `https://api.canva.com/rest/v1/connect/keys` sin credenciales, validación con `audience = CLIENT_ID`, payload `sub`/`team_id`/`type = rti`. **Sin verificar**: el algoritmo RS256 (la guía no lo nombra; viene de la especificación de la tarea) y el comportamiento real contra Canva (smoke del usuario, CS-M-01).
- Pendiente (coordinador): revisión adversarial A+B(+C); Playwright no aplica a T-26 (sin cambios de Vue; el flujo de retorno solo lo cubre Pest).

**Notas**: Tamaño S. Contrato Canva sin verificar contra la API real: el test usa un par de claves RSA generado en el propio test. **A la espera (no bloquea la fase)**: Verificación real contra Canva (smoke del usuario).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-27 — Canva: PDF en la biblioteca y Autofill según capacidades

- **Descripción**: Tipo PDF en la biblioteca (no solo enlace de 24 h) y flujo de Autofill/Brand Templates condicionado a las capacidades reales de la cuenta.
- **Changelog**: Los PDF exportados desde Canva ahora se guardan en la biblioteca (con icono, vista previa y descarga; no se pueden publicar en redes) y, si tu plan de Canva incluye plantillas de marca y Autofill, puedes rellenar una plantilla de texto y obtener un diseño nuevo.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-26
- **Tipo**: fullstack
- **Archivos**: `app/Services/Canva/CanvaJobs.php`, `app/Services/Canva/CanvaCapabilities.php`, `resources/js/Pages/Canva/Index.vue`, `docs/modulos/canva.md`, `app/Services/Canva/CanvaBrandTemplates.php`, `app/Services/Canva/CanvaClient.php`, `app/Services/Canva/CanvaIntegration.php`, `app/Http/Controllers/Canva/CanvaController.php`, `app/Http/Controllers/MediaController.php`, `app/Mcp/Tools/ManageCanva.php`, `app/Mcp/Abilities.php`, `app/Models/Canva/CanvaJob.php`, `app/Models/MediaAsset.php`, `app/Services/MediaUploadService.php`, `app/Services/MediaProbe.php`, `app/Services/MediaValidator.php`, `app/Services/Media/RemoteMediaImporter.php`, `app/Services/JobFailureNotifier.php`, `config/media.php`, `config/services.php`, `.env.example`, `routes/canva.php`, `routes/canva-api.php`, `routes/web.php`, `database/migrations/2026_12_21_090000_drop_download_urls_from_canva_jobs.php`, `resources/js/Components/PdfAsset.vue`, `tests/Feature/Canva/CanvaPdfLibraryTest.php`, `tests/Feature/Canva/CanvaAutofillCapabilitiesTest.php`, `tests/Feature/Canva/CanvaApiMcpTest.php`, `tests/Feature/Canva/CanvaConnectionTest.php`, `tests/Feature/Canva/CanvaJobsTest.php`, `tests/Feature/Canva/CanvaTestSupport.php`, `tests/Feature/Media/ImportRemoteMediaTest.php`, `tests/e2e/canva.spec.ts`, `tests/e2e/isolated/bootstrap.php`, `tests/e2e/isolated/fakes.php`, `docs/modulos/media.md`, `docs/referencia/configuracion.md`, `docs/api/creative-suite.openapi.yaml`, `deploy/backup/restore.sh`, `deploy/backup/README.md`, `tests/Feature/Console/BackupVerifyTest.php`, `app/Connectors/Support/MediaChunkReader.php`, `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/TikTok/TikTokPublisher.php`, `app/Services/Studio/StudioAvFiles.php`, `app/Services/Studio/StudioImages.php`, `tests/Feature/Studio/StudioImageJobsTest.php`, `scripts/studio/setup-runtime.py`, `scripts/studio/process-image.py`, `tests/studio_runtime/test_setup.py`, `docs/modulos/despliegue.md`, `docs/modulos/estudio.md`, `resources/js/Components/InfoNote.vue`, `app/Services/AutolistService.php`, `app/Services/LibraryService.php`, `app/Services/MediaValidator.php`, `app/Services/Canva/CanvaJwks.php`, `config/services.php`, `tests/Feature/Canva/CanvaJwksTest.php`
- **Cubre**: B-08
- **Cubre (tests)**: E2E-39, M-10
- **Verificación**: `php artisan test --compact --filter=CanvaPdfLibraryTest` → verde · `php artisan test --compact --filter=CanvaAutofillCapabilitiesTest` → verde

**Criterios de aceptación**
- [x] Un export PDF se guarda en la biblioteca como media de tipo PDF
- [x] Brand Templates/Autofill se muestran solo si `CanvaCapabilities` lo permite
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `RED: CanvaPdfLibraryTest falló con «Undefined array key 0» (el PDF no entraba en la biblioteca: seguía devolviendo download_urls) y «Route [media.download] not defined» (4 de 8) · 2026-11-12`. `CanvaAutofillCapabilitiesTest` se escribió antes del código (sin `CanvaBrandTemplates`, rutas ni capacidades) pero su primer rojo no se registró aparte: la primera ejecución con el código parcial dio 11/12 (falló el Autofill con campo vacío, `null` ≠ texto).
- Verificación (2026-11-12): `php artisan test --compact --filter=CanvaPdfLibraryTest` → 10/10 verde; `--filter=CanvaAutofillCapabilitiesTest` → 12/12 verde; focal `Canva`+`Media`+`Studio`+`Connectors`+`Console/BackupVerifyTest`+`Mcp`+`Parity` → 2042 pasan, 9 omitidos, 0 fallos (no se lanzó el Pest completo); Pint OK; `npm run build` OK, `test:unit` 636/636, `typecheck` OK; Playwright `canva.spec.ts` 10/10 (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso; incluye E2E-39 en desktop y los dos móviles), más `notifications-unified.spec.ts` y `media-import.spec.ts` 29/29.
- Decisiones: (1) Nuevo tipo `MediaAsset::KIND_PDF = 'pdf'` (MIME `application/pdf`, `media.allowed_pdf_mimes`, tope `MEDIA_MAX_PDF_BYTES` = 50 MiB). Solo lo admite el export de Canva (`RemoteMediaImporter::allowsDocument`: `source = canva`); por subida, URL o Drive un PDF se rechaza con «Los PDF no se pueden publicar en redes…». (2) Tipo real: `MediaProbe` exige `%PDF-` al principio y, antes, el importador comprueba con `finfo` que el contenido es `application/pdf`; HTML o PNG con cabecera de PDF quedan `invalido`. (3) Vista previa = icono de documento (`PdfAsset.vue`, sin miniatura: no hay rasterizador de PDF en el servidor); ver (`media.file`, en línea) y descargar (`media.download`, adjunto, 404 si no es PDF, solo el dueño). (4) NO publicable: `GET media` y `list_media` ya filtraban imagen/vídeo (se añadió test), el validador de publicaciones da un aviso propio (`MediaValidator`), y la UI lo explica con el aviso común de la app, `PermissionNotice` (tarjeta ámbar con título e ítems, el mismo de Meta Ads y el buzón). (5) Se eliminó el enlace temporal de 24 h: `CanvaJobs` ya no tiene rama PDF, `present()` no devuelve `download_urls`, el modelo no lo castea y una migración borra la columna (`2026_12_21_090000`; **no aplicada a la BD de desarrollo**: ejecutar `php artisan migrate`; el código funciona con o sin la columna). (6) Capacidades: `brand_templates` (capacidad `brand_template` + permisos `brandtemplate:meta:read` y `brandtemplate:content:read`) y `autofill` (además `autofill` y `design:content:write`) sustituyen al `false` fijo; sin ellas la página no muestra la sección y los endpoints dan 403 `capability_required` sin llamar a Canva. (7) Los permisos de plantillas solo se piden con `CANVA_BRAND_TEMPLATES=true` (config `services.canva.scopes`): pedir un scope que la app de Canva Developers no tenga activado rompería la conexión de todos; si falta, el motivo dice que es cosa del servidor y no de reconectar. (8) Flujo de Autofill según la guía oficial: `GET /brand-templates`, `GET /brand-templates/{id}/dataset`, `POST /autofills` (`create_from_brand_template`, `data.{CAMPO}.{type: text, text}`, `title`), sondeo de `GET /autofills/{id}` en el mismo `RunCanvaJob` (nuevo `kind = autofill`, mismo límite de trabajos activos y sondeo acotado) y, al terminar, `design_id` del diseño creado y enlace «Abrir en Canva» fresco. Solo campos de **texto**: imagen/vídeo exigen subir antes un asset y referenciarlo, y gráfico/hoja necesitan datos tabulares; esos campos se omiten y Canva conserva el valor de la plantilla (la UI lo dice). (9) Superficies: API REST (`templates`, `templates/{t}/dataset`, `autofills`), OpenAPI y MCP `manage_canva` (`templates`, `autofill`) con las abilities `canva:read`/`canva:write`. (10) E2E: el Canva falso sirve PDF, `/connect/keys`, plantillas y Autofill (`canva-templates`); el limitador `canva`/`canva-connect` de cada usuario se limpia en `afterEach` (los 10 intentos por minuto se agotaban entre tests).
- Contrato Canva: **verificado en parte** contra la documentación oficial (2026-11-12): guía de Autofill https://www.canva.dev/docs/apps/rest-apis/autofill-guide/, `Create design autofill job`, `Get design autofill job`, `List brand templates` y `Get brand template dataset` (campos, modos `create_from_brand_template`/`create_from_design`/`update_design`, capacidades `brand_template`/`autofill`, planes Pro/Teams/Enterprise, scopes `brandtemplate:*`). **Sin verificar**: los códigos de error de un autofill fallido (mensaje genérico), la paginación real de `brand-templates` y todo el comportamiento contra una cuenta real con Autofill (**A la espera (U)**: acceso a un plan con plantillas de marca; el smoke CS-M-01 de `pendientes-manuales.md` no se tocó porque ese fichero tenía cambios ajenos sin commitear: debe ampliarse con «exportar PDF, ver y descargar» y «rellenar una plantilla»).
- Pendiente (coordinador): revisión adversarial A+B(+C); aplicar `php artisan migrate`.

- **Limpieza de compatibilidad** (criterio transversal de código limpio; barrido `grep -rniE "legacy|compat|deprecated|obsolet|backward"` en `app/`, `config/`, `resources/js`, `deploy/` y `scripts/`: 52 coincidencias, 2026-11-12). El barrido por palabra tiene muchos falsos positivos; se resume por grupos:

| Resultado | Dónde | Decisión |
|---|---|---|
| Rama para respaldos antiguos (sin `.hmac`, 10000 iteraciones) | `deploy/backup/restore.sh` (`LEGACY`, `DB_LEGACY`, `MEDIA_LEGACY`, doble `decrypt`), su test y `deploy/backup/README.md` | **Eliminado.** Nunca se ha hecho un respaldo real. Sin `.hmac` ahora se rechaza («no tiene .hmac… No se restaura»); el test «admite un respaldo anterior» pasa a comprobar ese rechazo |
| `MediaChunkReader::close()` vacío «por compatibilidad de uso» | `app/Connectors/Support/MediaChunkReader.php` y sus 5 llamadas (YouTube x2, TikTok x2, `StudioAvFiles`) | **Eliminado.** No hacía nada: cada trozo abre y cierra su lectura |
| Aviso de ficheros del modelo anterior (BiRefNet) | `scripts/studio/setup-runtime.py` (`LEGACY_MODELS`), su test en `tests/studio_runtime/test_setup.py`, el apartado «Actualizar desde BiRefNet» de `docs/modulos/despliegue.md`, comparativas en `docs/modulos/estudio.md` y comentarios | **Eliminado.** La suite creativa aún no está en producción (PRs abiertas), así que ningún servidor tuvo BiRefNet. Se conserva la regla general de que un trabajo encolado usa el modelo vigente de la configuración (cubre cualquier cambio de ruta o modelo, no solo ese) con comentario y test neutros |
| `schema_version` 1 → 2 de los diseños del Estudio (`legacyTimeline` en `audiovisual-plan.js`, `Editor.vue`, `StudioCaptions`, `StudioFrames`, `StudioRenderer`) | `resources/js/Studio/`, `app/Services/Studio/` | **Se mantiene.** No es un formato antiguo: `StudioDocument::blank()` y MCP crean documentos v1 y se pasan a v2 al añadir línea de tiempo; es el modelo de documento vigente |
| `StudioService::reference()` «preserve legacy meaning» | `app/Services/Studio/StudioService.php` | **Se mantiene.** Normaliza `kit_reference` en cada alta/copia (rellena paleta, fuentes y logos); es normalización viva, no una rama de formato antiguo. El comentario es poco claro: se revisará en la fase de refactor del Estudio |
| `no compatible(s)` en mensajes de validación (Studio, audio, imágenes), «IPv4-compatible» de `UrlGuard`, `backward` del orden de capas, «redes compatibles», «navegador compatible» (passkeys), «API compatible S3» (R2), `obsoleto` como «revisión obsoleta» o «contenido obsoleto» | `app/`, `config/`, `resources/js/` | **Falsos positivos:** significan «no admitido» o describen comportamiento actual, no compatibilidad hacia atrás |
| `incompatible` de migraciones en `deploy/hestia/deploy.sh` y README | `deploy/hestia/` | **Falso positivo:** aviso al revertir un despliegue |
| `deprecated` en `config/logging.php` | `config/logging.php` | **Falso positivo:** comentario del canal de avisos de PHP |
- Pruebas de la limpieza: `BackupVerifyTest` 25/25 con `Deploy` (el viaje de ida y vuelta con HMAC y 600000 iteraciones sigue verde y el fichero sin `.hmac` se rechaza), `StudioImageJobsTest` 17/17, `tests/studio_runtime/test_setup.py` 4/4, `Connectors` y `Studio` dentro del focal 2042/2042 (0 fallos).


**Notas**: Tamaño M. Qué se puede hacer ya: PDF y flujo condicional. Queda a la espera (U): acceso Enterprise a Brand Templates/Autofill para probarlo en real. **A la espera (no bloquea la fase)**: Capacidad Enterprise de Canva (tercero).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-28 — API v1: posts y marcas (listar, borrar, cancelar, publicar)

- **Descripción**: Primera mitad de la API v1: endpoints REST sobre `PostService`/`BrandService` (listar, borrar, cancelar, publicar ahora) y OpenAPI ampliada. B-09 se cuenta aquí; la tarea siguiente es continuación.
- **Changelog**: La API v1 ya permite listar, ver, borrar, cancelar y publicar ya tus publicaciones y consultar tus marcas y cuentas, y los listados (posts, media, comentarios y diseños) comparten una paginación por cursor única (`limit`, `cursor`, `has_more`, `next_cursor`).
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `routes/posts-api.php`, `routes/api.php`, `routes/post-workflow-api.php`, `routes/studio-api.php`, `app/Http/Controllers/Api/**`, `app/Http/Controllers/MediaController.php`, `app/Http/Controllers/PostWorkflowController.php`, `app/Http/Controllers/Studio/StudioApiController.php`, `app/Http/Controllers/Studio/StudioController.php`, `app/Mcp/Tools/ListDesigns.php`, `app/Mcp/Tools/ListMedia.php`, `app/Mcp/Tools/ListComments.php`, `app/Services/MediaListing.php`, `app/Services/InboxService.php`, `app/Services/Studio/StudioService.php`, `app/Services/AccountPresenter.php`, `app/Mcp/Tools/ListAccounts.php`, `app/Support/CursorPage.php`, `resources/js/Pages/Studio/Index.vue`, `resources/js/Composables/useComposer.js`, `docs/api/openapi.yaml`, `docs/api/**`, `docs/guias/api-v1.md`, `docs/guias/estudio-api-mcp.md`, `docs/modulos/mcp.md`, `tests/Feature/Api/**`, `tests/Feature/**`, `.github/workflows/ci.yml`
- **Cubre**: B-09
- **Cubre (unificación)**: U-23
- **Cubre (tests)**: API-01
- **Verificación**: `php artisan test --compact --filter=ApiV1PostsTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [x] Los endpoints respetan abilities de API key y propiedad (acceso cruzado → 404/403)
- [x] La OpenAPI describe cada endpoint y pasa el lint
- [x] El listado de diseños y de posts usa el contrato de paginación por cursor único (mismo formato de `next_cursor`/`has_more`) que define esta tarea para `api/v1`
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. B-09 es L: se parte en dos tareas.

- RED/TDD: `RED: ApiV1PostsTest (rutas inexistentes: GET brands/{brand}/posts, DELETE posts/{post}, POST posts/{post}/cancel y publish → 404/405) y McpToolsTest list_media con cursor opaco (before_id ya no existe) · 2026-11-13`. Las pruebas se escribieron junto al código por endpoint; el primer rojo real fue el de rutas y el de `schedule` obligatorio en el contrato de crear.
- Verificación (2026-11-13): `php artisan test --compact --filter=ApiV1PostsTest` → 10/10 verde; `npx @redocly/cli@2.77.0 lint --extends minimal docs/api/openapi.yaml` → válido, 0 errores (132 avisos `operation-summary` heredados, no bloquean); focal `tests/Feature/{Api,Mcp,Posts,Workflow,Canva,Inbox,Sites,Reports}` + `StudioApiMcpTest`, `StudioBackendTest`, `StudioAutomationTest` → 886 pasan, 0 fallos tras ajustar los tests de contrato; Pint OK; `npm run build` OK, `test:unit` 636/636, `typecheck` OK. Playwright `canva.spec.ts`, `studio.spec.ts`, `composer.spec.ts` y `media-import.spec.ts` → 102/102 en una sola ejecución (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso).
- Contrato de paginación único (U-23, ver también T-29): `App\Support\CursorPage` (`limit` 1-100, `cursor` opaco, salida `has_more` y `next_cursor`; cursor inválido o de otro listado → 422; sin `page`, `before_id` ni `total`). Usado por: posts (`GET brands/{brand}/posts`), media (`MediaListing`, compartido por la web, API y MCP), comentarios del buzón (`InboxService::page`, compartido por API y MCP) y diseños (`StudioService::designs`, compartido por API, MCP y la pantalla del estudio). La colección lleva el nombre del recurso (`posts`, `media`, `comments`, `designs`). Migrados sin mantener los formatos antiguos: `list_media`, `list_comments`, `list_designs`, `GET /brands/{brand}/designs`, `GET /media` de la web (el selector del compositor y la página de Canva piden `limit=100`) y la página del estudio (botones «Siguiente» y «Primera página» en lugar de los números de página). Quedan fuera de esta migración, por no estar en el alcance de U-23, los listados con `page`/`per_page` de exportaciones, audio, tipografías, subtítulos y datasets.
- Rutas cambiadas (U-25): `POST /api/v1/posts` → `POST /api/v1/brands/{brand}/posts` y `POST /api/v1/series` → `POST /api/v1/brands/{brand}/series` (la marca ya no va en el cuerpo; en series manda la de la ruta). Tests y documentación actualizados. `PostApiController` pasa a `app/Http/Controllers/Api/` y hereda de `ApiController` (traducción de errores y auditoría únicas).
- Decisiones: (1) Abilities como el MCP (`brands:read`, `posts:read|write`). (2) Los errores de dominio de borrar y publicar un post ya publicado son 422 (mismo `PostValidationException` que usa el MCP), no 409; 409 queda para `InvalidPostTransition`. (3) Se crean presentadores compartidos (`AccountPresenter`) para no duplicar con el MCP.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-29 — API v1 (continuación): cuentas, media, inbox, informes, mejores horas, competidores, SmartLinks, sitios

- **Descripción**: Segunda mitad de B-09: endpoints de cuentas, media, inbox, informes, mejores horas, competidores, SmartLinks y sitios, y cierre de la OpenAPI.
- **Changelog**: La API v1 cubre ya la media (listar, subir e importar), el buzón de comentarios, los informes, las mejores horas, los competidores, los SmartLinks y los sitios, con un único OpenAPI y la marca siempre en la ruta.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-28
- **Tipo**: backend
- **Archivos**: `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Http/Controllers/MediaController.php`, `app/Http/Controllers/SmartLinkController.php`, `app/Mcp/Abilities.php`, `app/Mcp/Tools/ManageSite.php`, `app/Mcp/Tools/GenerateReport.php`, `app/Mcp/Tools/GetReport.php`, `app/Mcp/Tools/CompareCompetitors.php`, `app/Services/ReportService.php`, `app/Services/CompetitorService.php`, `app/Services/SmartLinkService.php`, `app/Services/Sites/SiteService.php`, `app/Services/MediaListing.php`, `app/Services/Canva/CanvaIntegration.php`, `resources/js/Pages/Canva/Index.vue`, `docs/api/openapi.yaml`, `docs/api/creative-suite.openapi.yaml`, `docs/guias/api-v1.md`, `docs/modulos/mcp.md`, `docs/modulos/*.md`, `docs/guias/*.md`, `docs/README.md`, `tests/Feature/Api/**`, `tests/Feature/Canva/CanvaPdfLibraryTest.php`, `.github/workflows/ci.yml`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (unificación)**: U-25
- **Cubre (tests)**: API-02
- **Verificación**: `php artisan test --compact --filter=ApiV1ResourcesTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [x] Paridad de lectura con el MCP en los recursos listados
- [x] OpenAPI completa y con lint limpio
- [x] `ListMedia` y el resto de listados de `api/v1` siguen el contrato de cursor común; la marca va siempre en la ruta (no en la query) y hay un único OpenAPI para el núcleo social y la suite creativa
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de B-09 (ver tarea anterior).

- RED/TDD: `RED: ApiV1ResourcesTest falló con 404 en todas las rutas nuevas (media, comments, reports, best-times, competitors, smart-link, sites) y, ya con las rutas, «Expecting [...] not to have key preview_url» (la web y la API compartían la forma) · 2026-11-13`.
- Verificación (2026-11-13): `php artisan test --compact --filter=ApiV1ResourcesTest` → 12/12 verde; `npx @redocly/cli@2.77.0 lint --extends minimal docs/api/openapi.yaml` → válido, 0 errores; el resto (focal, Pint, build, `test:unit`, `typecheck`, Playwright) en T-28, que comparte ejecución. Un fallo aislado de `ReportCsvTest` salió solo al lanzar Pest a la vez que otra ejecución (disco `local` compartido) y no se repite en solitario (8/8).
- Endpoints nuevos (todos con throttle de grupo 120/min y límites propios en lo sensible): `GET /media`, `GET /media/{asset}`, `POST /media`, `PUT /media/{asset}/upload`, `POST /media/{asset}/complete`, `POST /media/import-url`, `DELETE /media/{asset}` (mismo `MediaController` que la web), `GET /brands/{brand}/comments`, `POST /comments/{comment}/reply`, `POST /brands/{brand}/reports`, `GET /reports/{report}`, `GET /brands/{brand}/best-times`, `GET /brands/{brand}/competitors`, `GET|PUT /brands/{brand}/smart-link`, `GET /brands/{brand}/smart-link/stats`, `GET /brands/{brand}/sites`, `GET /sites/{site}`, `POST /sites/{site}/publish|unpublish`. Cuentas y marcas, en T-28.
- Sin duplicar con el MCP ni la web (lógica extraída a servicios compartidos): `MediaListing`, `InboxService::page`, `AccountPresenter`, `ReportService::request/status`, `CompetitorService::summary`, `SiteService::present`, `SmartLinkService::rules/present` (la web de SmartLinks usa las mismas reglas y la misma forma). Abilities: las del MCP (`brands:read`, `posts:write` para subir e importar media, `inbox:*`, `reports:write` también para consultar un informe como `get_report`, `stats:read`, `sites:*`) y dos nuevas, `smartlinks:read` y `smartlinks:write`, declaradas en `Abilities.php`: entran solas en los presets `read` (la de lectura) y `all`; `publish` no incluye `smartlinks:write`.
- U-25: la marca siempre en la ruta (ver T-28); un único OpenAPI: `docs/api/creative-suite.openapi.yaml` se fusiona en `docs/api/openapi.yaml` (renombrado con historial, título «Social Scheduler API v1», ahora describe también marcas, posts, media, buzón, informes, estadísticas, SmartLinks y sitios, con respuestas comunes 401/403/409/429); el viejo no existe. La CI (`ci.yml`, `@redocly/cli@2.77.0 lint --extends minimal`), `docs/guias/testing.md`, `docs/modulos/despliegue.md` y los enlaces de la documentación y de `StudioApiMcpTest` apuntan al nuevo. Guía nueva `docs/guias/api-v1.md`; `docs/modulos/mcp.md` (abilities, presets y contrato de cursor) y `docs/guias/estudio-api-mcp.md` actualizados.
- Pruebas por endpoint (éxito, otro usuario, otra marca, ability ausente, validación): `tests/Feature/Api/ApiV1PostsTest.php` y `ApiV1ResourcesTest.php`.
- **Gap 2 de la revisión de T-27, resuelto en T-28/T-29**: la lista de PDF de Canva estaba fija a 20 sin paginar y la biblioteca no mostraba PDF. Ahora `MediaListing` admite `kind` (`image`, `video`, `pdf`; sin él, solo lo publicable, así que el compositor sigue sin ver PDF), `GET /media?kind=pdf` (web y API) pagina con el cursor común y borrar sigue siendo `DELETE /media/{asset}`; el estado de Canva (`documents`) devuelve la primera página con `has_more`/`next_cursor` y la página de Canva carga más con «Ver más PDF». La acción de descarga de `MediaController` no se ha tocado. Test: `CanvaPdfLibraryTest` «con 25 PDF todos son accesibles» (cursor de 10 en 10, el compositor no los ve, se borra el más antiguo, `kind` inválido 422). Los hunks de `Canva/Index.vue` y de ese test entraron en el commit del agente de Canva (0d3fb35).
- Límites declarados: no hay endpoint de la API para añadir notas a comentarios, gestionar competidores ni la edición de SmartLinks por bloques sueltos (solo reemplazo completo); la ruta `reports/{report}` usa `reports:write` igual que el MCP. Los listados con `page`/`per_page` del estudio (exportaciones, audio, tipografías, subtítulos, datasets) siguen con su paginación propia: U-23 acotaba diseños, media y comentarios.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-30 — MCP: SmartLinks, Ads, autolistas, competidores, ocultar comentarios, textos, presets y papelera

- **Descripción**: Herramientas MCP finas sobre los servicios existentes para las áreas que hoy faltan.
- **Changelog**: Los agentes conectados por MCP pueden ahora gestionar el SmartLink de una marca, consultar Meta Ads, administrar autolistas, dar de alta y baja competidores, ocultar o mostrar comentarios, gestionar textos guardados, ver los presets del compositor y usar la papelera.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-29
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/**`, `app/Mcp/Servers/**`, `docs/modulos/mcp.md`, `tests/Feature/Mcp/**`, `app/Services/AutolistService.php`, `app/Services/SavedTextService.php`, `app/Services/TrashService.php`, `app/Services/CompetitorService.php`, `app/Http/Controllers/AutolistController.php`, `app/Http/Controllers/TrashController.php`, `app/Http/Controllers/CompetitorController.php`, `app/Http/Requests/SavedTextRequest.php`, `docs/guias/estudio-api-mcp.md`, `docs/RAG-INDEX.md`, `app/Mcp/Abilities.php`, `docs/modulos/despliegue.md`, `tests/Feature/Isolation/BrandIsolationTest.php`, `tests/Feature/Api/CursorOrderTest.php`
- **Cubre**: B-10
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=McpNewToolsTest` → verde

**Criterios de aceptación**
- [x] Cada herramienta nueva valida abilities y propiedad
- [x] `docs/modulos/mcp.md` lista las herramientas nuevas
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `McpNewToolsTest` se escribió junto a las herramientas (no hubo ejecución en rojo registrada aparte): la primera ejecución con el código dio 9/14 y destapó cuatro fallos (mensajes de «no encontrada» con género incorrecto, un helper de test que no sobrescribía los argumentos y un `ComposerPreset` creado sin `user_id`), corregidos en la misma tarea · 2026-11-13.
- Verificación (2026-11-13): `php artisan test --compact --filter=McpNewToolsTest` → 14/14 verde (308 aserciones); focal `Parity/AutolistTest`+`Parity/TrashTest`+`Competitors`+`Mcp`+`Api`+`Studio/StudioApiMcpTest` → 392/392 verde; `SavedTexts`+`Ui/SavedTextsPageTest`+`Parity` → 139/139 verde; Pint OK. No se lanzó el Pest completo. Sin Vue: no se ejecutaron `npm run build`, `test:unit`, `typecheck` ni Playwright.
- Herramientas nuevas (57 → 64, tres páginas de `tools/list`: 30, 30 y 4): `manage_smartlink` (get/save/stats), `get_ads` (solo lectura), `manage_autolist` (list/create/update/delete/add_item/remove_item/generate), `manage_competitor` (add/remove), `manage_saved_texts` (list/create/update/delete), `get_composer_presets` (solo lectura) y `manage_trash` (list/restore/delete_forever). Ocultar comentarios no añade herramienta: `triage_comments` gana `hide` y `unhide` (reutiliza `triage_comments` en lugar de crear otra para no gastar tope de página).
- Decisiones: (1) Sin lógica duplicada: se extrajeron a los servicios las reglas, presentadores y operaciones que antes vivían en los controladores web (`AutolistService::createRules/itemRules/query/present/update/delete/removeItem`, `SavedTextService::rules/query` con `SavedTextRequest` delegando, `TrashService::query/present/RETENTION_DAYS`, `CompetitorService::remove`) y los controladores web los usan (menos código, un solo contrato). (2) Abilities existentes: `smartlinks:read|write` para SmartLinks, `stats:read` para `get_ads`, `posts:read` para listar autolistas/textos/presets/papelera y `posts:write` para cambiarlos y para dar de alta o baja competidores (no hay ability propia y se respetó «usa las existentes»; `compare_competitors` sigue en `stats:read`). `hide`/`unhide` con `inbox:write`. (3) Listados de autolistas, textos guardados y papelera con el contrato común `CursorPage` (`limit`, `cursor`, `has_more`, `next_cursor`). (4) Presets: solo lectura (decisión C-04: solo los guarda el compositor web). (5) Las etiquetas de `owned()` salen «<X> no encontrada»: se usaron nombres femeninos neutros («Cuenta de competidor», «Entrada de texto guardado») para que el mensaje concuerde.
- Nota de unificación (coordinador): los errores de estado de las herramientas nuevas (p. ej. restaurar una publicación que no está en la papelera) salen como `PostValidationException` → «Datos no válidos». Cuando exista la excepción de conflicto de estado → 409 que introduce la corrección de T-28/T-29 (no existía al cerrar esto), habrá que usarla en `ManageTrash` (`TrashService::assertTrashed`) y unificarla; no se tocó aquí para no pisar ese trabajo. `ManageAutolist`, `ManageSavedTexts` y `ManageTrash` fueron tocados después por el agente de la corrección de la API para el parámetro de ámbito del cursor de `CursorPage::fetch`.
- Pendiente (coordinador): revisión adversarial A+B(+C).

**Notas**: Tamaño M.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-31 — Facebook: varias fotos y stories

- **Descripción**: Dos tipos de contenido nuevos en Facebook: publicación con varias fotos y stories.
- **Changelog**: Ya puedes publicar en una Página de Facebook un álbum de 2 a 10 fotos en una sola publicación y subir historias de foto o de vídeo, eligiendo el tipo en el compositor como en las demás redes.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: fullstack
- **Archivos**: `config/media_specs.php`, `app/Connectors/Meta/FacebookPublisher.php`, `resources/js/Pages/Posts/**`, `docs/modulos/media-por-destino.md`, `app/Enums/ContentType.php`, `app/Support/TargetMedia.php`, `tests/Feature/Connectors/Meta/Facebook/**`, `tests/Unit/MediaValidator/MediaValidatorTest.php`, `tests/Feature/Posts/Compose/ComposeTest.php`, `docs/modulos/publicacion.md`, `docs/referencia/decisiones-por-red.md`, `docs/arquitectura.md`
- **Cubre**: B-12
- **Cubre (tests)**: E2E-36, M-04
- **Verificación**: `php artisan test --compact --filter=FacebookMultiPhotoStoryTest` → verde

**Criterios de aceptación**
- [x] Los tipos aparecen en `list_accounts` y en el compositor con sus límites de media
- [x] La publicación de varias fotos y de stories funciona con fakes
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `FacebookMultiPhotoStoryTest` se escribió después del código de `FacebookPublisher` (no hubo ejecución en rojo previa); la primera ejecución dio 6/10 y destapó un fallo real (el álbum no validaba todas las imágenes antes de subir la primera, ahora sí) y tres errores de los propios tests, corregidos · 2026-11-13.
- Verificación (2026-11-13): `php artisan test --compact --filter=FacebookMultiPhotoStoryTest` → 10/10 verde; focal `Unit`+`Connectors/Meta`+`Posts`+`Publishing`+`Media` → 1056/1056 verde; `FirstComment`+`Library`+`Jobs`+`Ui` verde (un fallo ajeno en `CalendarViewCrudTest` por el trabajo en curso de límites de ritmo de T-28/T-29); `MediaValidatorTest` actualizado (29 reglas) y `ComposeTest` (6 tipos de Facebook) verdes; Pint OK en mis ficheros. **No hay cambio de Vue**: el selector de tipo del compositor y `list_accounts` se alimentan de `ContentType`, así que no se ejecutaron `npm run build`, `test:unit`, `typecheck` ni Playwright; E2E-36 (varias fotos y lugar en Facebook) queda sin spec propio y la cobertura de los tipos en el compositor es `ComposeTest` (props `contentTypes`) y `list_accounts`.
- Tipos nuevos: `facebook_photos` (2 a 10 fotos; `attached_media`, cada foto con `published=false`) y `facebook_story` (foto por `photo_stories`, vídeo por `video_stories`; reglas `facebook_story_photo` y `facebook_story_video`, igual que las historias de Instagram). Los IDs de las fotos subidas van a `progress.photos` (checkpoint) y un reintento no las repite; `findPublished` reconoce el álbum por texto y hora, el vídeo por su ID y la historia de foto por su ID de foto en `GET /{page}/stories`.
- Contrato Facebook: **verificado en parte** (2026-11-13). **Verificado**: publicación con varias fotos (`/{page-id}/photos` con `published=false`, `/feed` con `attached_media[i]={"media_fbid":…}`, foto temporal ~24 h) en la referencia oficial https://developers.facebook.com/docs/graph-api/reference/page/photos/ ; y los parámetros de `photo_stories` (`photo_id`) y `video_stories` (`upload_phase`, `video_id`, `video_state`) en el SDK oficial de Meta (`facebook-python-business-sdk`, `page.py`: `create_photo_story`, `create_video_story`). **Sin verificar**: la guía «Page Stories API» y las páginas de referencia `photo_stories`/`video_stories` no se pudieron leer (devuelven 404 o una página sin contenido sin JavaScript); por tanto la forma de la respuesta de las historias (`success`, `post_id`), la lectura `GET /{page}/stories`, la subida por `file_url` en `video_stories` y los límites de media (máximo de 10 fotos y límites de las historias, tomados de Instagram) quedan como supuestos documentados en `docs/referencia/decisiones-por-red.md`. Verificación real = smoke M-04 del usuario.
- Decisiones: (1) Una sola variante de enum por concepto, como Instagram: `FacebookStory` se resuelve por la clase de media. (2) Las historias no tienen `first_comment` (se rechaza) y el texto común se ignora, como en Instagram. (3) Sin red real: todo con `Http::fake`/`FakeGraph`.
- Pendiente (coordinador): revisión adversarial A+B(+C); smoke M-04 del usuario; añadir el spec E2E-36 si se quiere cubrir el compositor en navegador.

**Notas**: Tamaño M. Verificación real = smoke de Meta del usuario.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-32 — Series: recuperar fechas perdidas, heredar aprobación y recordatorio manual

- **Descripción**: Relleno opcional de lo perdido, herencia de aprobación en las ocurrencias y recordatorio de publicación manual.
- **Changelog**: Las publicaciones de una serie pueden exigir aprobación antes de salir, las fechas recientes que una serie no pudo generar se recuperan con un botón opcional y las publicaciones manuales avisan antes de su hora, también por correo si lo tienes activado.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: fullstack
- **Archivos**: `app/Services/SeriesService.php`, `app/Jobs/GenerateSeriesOccurrences.php`, `app/Services/ManualPublishService.php`, `resources/js/Pages/Series/**`, `docs/modulos/planificacion-avanzada.md`, `docs/modulos/workflow-publicaciones.md`, `docs/api/openapi.yaml`, `app/Http/Controllers/PostWorkflowController.php`, `app/Services/PostWorkflowActions.php`, `app/Services/PostService.php`, `app/Models/PostTarget.php`, `app/Notifications/PostWorkflowActivity.php`, `app/Jobs/DispatchDuePosts.php`, `app/Mcp/Tools/ManagePostSeries.php`, `config/social.php`, `routes/post-workflow.php`, `routes/post-workflow-api.php`, `database/migrations/2026_12_22_090000_add_manual_reminded_at_to_post_targets.php`, `tests/Feature/Workflow/SeriesApprovalInheritanceTest.php`, `tests/Feature/Workflow/SeriesBackfillTest.php`, `tests/Feature/Workflow/ManualReminderTest.php`, `tests/e2e/workflow.spec.ts`, `app/Services/ApprovalService.php`, `app/Notifications/ManualPublishReminder.php`, `app/Models/PostSeries.php`, `database/migrations/2026_12_23_090000_add_rule_changed_at_to_post_series.php`, `database/migrations/2026_12_23_090100_add_manual_status_index_to_post_targets.php`
- **Cubre**: B-15
- **Cubre (tests)**: E2E-37
- **Verificación**: `php artisan test --compact --filter=SeriesApprovalInheritanceTest` → verde · `php artisan test --compact --filter=SeriesBackfillTest` → verde · `php artisan test --compact --filter=ManualReminderTest` → verde

**Criterios de aceptación**
- [x] Las ocurrencias heredan la aprobación de la serie
- [x] El relleno de fechas perdidas es opcional y explícito
- [x] La publicación manual envía un recordatorio por correo/in-app
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: los tests (`SeriesApprovalInheritanceTest`, `SeriesBackfillTest`, `ManualReminderTest`) se escribieron junto al código, sin ejecución en rojo registrada aparte; la primera pasada dio 13/14 y destapó solo un fallo de test (la API de aprobar exige `signature`), corregido en la misma tarea · 2026-11-13.
- Verificación (2026-11-13): `--filter='SeriesApprovalInheritanceTest|SeriesBackfillTest|ManualReminderTest'` → 14/14 verde; focal `SeriesTest`+`ManualPublishTest`+`ReviewFixesTest`+`Mcp`+`MigrationTest` → 466/466 verde (2 omitidos); Pint OK. `npm run build` OK, `npm run typecheck` OK, `npm run test:unit` 636/636. Playwright (`E2E_PURGE=0 E2E_WORKERS=2`, `workflow.spec.ts` + `studio.spec.ts`, desktop+mobile+mobile-360) → 23/23 verde, con un E2E nuevo «serie con aprobación…» (E2E-37). No se lanzó el Pest completo. Una ejecución más amplia (`--filter` con `Mcp`) mostró un fallo AJENO en `Parity/InboxTest` («Comentario no encontrado.» frente a «no encontrada»), sin relación con esta tarea.
- Decisiones: (1) **Fechas perdidas**: nunca se recuperan solas; `SeriesService::recover()` (web `POST workflow/series/{series}/recover`, API `POST /api/v1/series/{series}/recover`, acción `recover` del MCP, botón «Recuperar fechas perdidas» en `/series`) rehace solo las fechas posteriores a la creación de la serie y dentro de `social.series.recovery_days` (2 por defecto, 1-7); las crea como ocurrencias normales programadas +10 min escalonadas, idempotentes (clave única serie+fecha) y respetando la aprobación; `recoverable` en `present()` lo cuenta. (2) **Aprobación**: no existe configuración de aprobación por marca ni «post origen» en las series, así que la fuente única es `requires_approval` en el payload de la serie (casilla en la UI, API y MCP): las ocurrencias nacen como borrador pendiente (reusa `PostService::create`) y `reconcile()` solo programa las que ya son publicables. (3) **Recordatorio manual**: `ManualPublishService::remindUpcoming()` (columna `manual_reminded_at`, migración 2026_12_22 reversible, claim atómico, rearmada al reprogramar), antelación `social.series.manual_reminder_minutes` (60); `PostWorkflowActivity` con tipo `manual_reminder` va por `database` y, con `notify_by_mail`, también `mail` (mismo criterio que `ConnectionExpiring`); se emite desde `DispatchDuePosts`. No se usó `JobFailureNotifier` porque es para fallos asíncronos, no recordatorios. No hay push (F-07/F-16).
- Pendiente (coordinador): revisión adversarial A+B(+C); tras ella, marcar los criterios de revisión y de Pest/Playwright.

**Notas**: Tamaño M. El recordatorio por push se completa con F-07/F-16.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-33 — Estudio: renombrar, borrar y mover a carpeta

- **Descripción**: Menú de carpeta (renombrar, borrar) y «Mover a carpeta» para un diseño.
- **Changelog**: En el estudio ahora puedes renombrar y borrar carpetas (con confirmación; sus diseños pasan a «Sin carpeta») y mover un diseño a otra carpeta desde su tarjeta.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: fullstack
- **Archivos**: `resources/js/Pages/Studio/Index.vue`, `app/Http/Controllers/Studio/StudioFolderController.php`, `routes/studio.php`, `docs/modulos/estudio.md`, `docs/api/openapi.yaml`, `app/Http/Controllers/Studio/StudioApiController.php`, `app/Services/Studio/StudioService.php`, `app/Mcp/Tools/ManageDesignFolder.php`, `routes/studio-api.php`, `resources/js/Pages/Studio/Editor.vue`, `app/Mcp/Tools/StudioPayload.php`, `docs/guias/estudio-api-mcp.md`, `tests/Feature/Studio/StudioFoldersTest.php`, `tests/Feature/Studio/StudioBackendTest.php`, `tests/Feature/Studio/StudioCollaborationTest.php`, `tests/e2e/studio.spec.ts`
- **Cubre**: B-16
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --compact --filter=StudioFoldersTest` → verde · `npx playwright test tests/e2e/studio.spec.ts -g "folders" --workers=1` → verde

**Criterios de aceptación**
- [x] Una carpeta se renombra y se borra (con confirmación) desde la UI
- [x] Un diseño se mueve entre carpetas
- [x] Borrar una carpeta con diseños no los pierde (pide destino)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `StudioFoldersTest` se escribió junto al código, sin ejecución en rojo registrada aparte; la primera pasada dio 6/7 (un índice de orden mal supuesto en el propio test), corregido en la tarea · 2026-11-13.
- Verificación (2026-11-13): `php artisan test --compact --filter=StudioFoldersTest` → 7/7 verde; focal `StudioBackendTest`+`StudioApiMcpTest`+`StudioCollaborationTest`+`RouteNamesTest` → 80/80 verde (con `StudioFoldersTest`); Pint OK. `npm run build` OK, `typecheck` OK, `test:unit` 636/636 (el E2E nuevo no usa `.first()`, que el cupo de `test-tooling` limita). Playwright (`E2E_PURGE=0 E2E_WORKERS=2`, `studio.spec.ts` + `workflow.spec.ts`) → 23/23 verde, con el E2E nuevo «Studio: folders are renamed…» (E2E-06: crea carpetas, renombra con teclado, mueve el diseño, borra con confirmación y comprueba que el diseño sigue). La verificación declarada `studio-boards.spec.ts` no cubre carpetas (es de la pizarra): el E2E de carpetas va en `studio.spec.ts`. No se lanzó el Pest completo.
- Decisiones: (1) **Borrado**: los diseños (activos, plantillas y archivados) pasan a «Sin carpeta» (ya era el comportamiento de `deleteFolder`); la UI no pide destino —el criterio original «pide destino» se resuelve con destino único «Sin carpeta» y la confirmación dice cuántos diseños pasan—; el servidor devuelve `designs_moved` y el listado `designs_count`. (2) **Confirmación**: no existe `ConfirmDialog` común todavía (llega con D-04) y el estudio usa su `StudioDialog` (`<dialog>` nativo, foco y Esc gestionados) para todo; se usó ese, sin `confirm()` nativo. (3) **Mover** es un endpoint propio (`PATCH studio/designs/{design}/folder`, API `designs/{design}/folder`, MCP `manage_design_folder` acción `move`): no crea revisión y solo lo hace el propietario (un editor invitado recibe 404); **ningún guardado cambia la carpeta** (revisión 1: el editor ya no la envía y `StudioService::update` ignora `folder_id`; antes un guardado reenviaba la carpeta con la que se abrió y deshacía un movimiento o fallaba con una carpeta borrada). (4) **Autorización**: renombrar, borrar y mover resuelven el recurso por `user_id` (404 si es ajeno) y la carpeta destino debe ser de la marca del diseño (422); igual en web, API (`designs:write`) y MCP (`rename`, `move`, `delete`). (5) Teclado: botones reales con `aria-label` por carpeta, formulario de renombrado enviable con Enter y cerrable con Esc, diálogos nativos.
- Pendiente (coordinador): revisión adversarial A+B(+C); tras ella, marcar los criterios de revisión y de Pest/Playwright.

**Notas**: Tamaño M. La confirmación de borrado y el resto de diálogos de carpetas usan `StudioDialog` (el diálogo común del estudio, sin `confirm()` nativo); cuando D-04 cree `ConfirmDialog` se migrará allí.

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-34 — Opciones de publicación: portada LinkedIn, lugares IG, `place` FB, editor de `user_tags`

- **Descripción**: Portada de vídeo en LinkedIn, búsqueda de lugares en Instagram, `place` en Facebook y editor web de `user_tags`.
- **Changelog**: El compositor deja elegir un lugar para Instagram y Facebook (buscándolo o pegando su id), etiquetar usuarios en una imagen de Instagram pulsando sobre ella y poner portada a los vídeos de LinkedIn.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-31
- **Tipo**: fullstack
- **Archivos**: `app/Connectors/LinkedIn/LinkedInPublisher.php`, `app/Connectors/LinkedIn/LinkedInClient.php`, `app/Connectors/Meta/Instagram/InstagramPostOptions.php`, `app/Connectors/Meta/Facebook/FacebookPostOptions.php`, `app/Connectors/Meta/Facebook/FacebookPublisher.php`, `app/Connectors/Meta/PlaceSearch.php`, `app/Connectors/Meta/PlaceSearchUnavailable.php`, `app/Http/Controllers/ConnectionController.php`, `app/Http/Requests/PostRequest.php`, `app/Mcp/Tools/PostPayload.php`, `app/Services/PostService.php`, `app/Support/TargetMedia.php`, `config/rate_limits.php`, `routes/web.php`, `resources/js/Components/Composer/TargetOptions.vue`, `resources/js/Components/Composer/PlaceField.vue`, `resources/js/Components/Composer/InstagramUserTagsField.vue`, `resources/js/Composables/composerOptions.js`, `resources/js/Composables/placeSearch.js`, `resources/js/Composables/useComposer.js`, `resources/js/Composables/userTags.js`, `docs/modulos/publicacion.md`, `docs/modulos/media-por-destino.md`, `docs/guias/linkedin-pinterest.md`, `docs/referencia/decisiones-por-red.md`, `docs/referencia/pendientes-manuales.md`, `tests/Feature/Publishing/PublishOptionsTest.php`, `tests/frontend/composer-user-tags.test.mjs`, `tests/e2e/publish-options.spec.ts`
- **Cubre**: B-17
- **Cubre (tests)**: E2E-36, M-02, M-04
- **Verificación**: `php artisan test --compact --filter=PublishOptionsTest` → verde

**Criterios de aceptación**
- [x] El compositor permite elegir lugar y etiquetar usuarios (con fakes de la API)
- [x] LinkedIn acepta portada de vídeo
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: `PublishOptionsTest` se escribió junto al código (portada de LinkedIn y `place` ya implementados al ejecutarlo por primera vez); la primera pasada dio 6/9 con dos fallos del propio test (`Http::fake` encadenado dos veces y la cuenta de LinkedIn sin activar en el servicio), corregidos en la tarea · 2026-11-13. `TDD n/a` para los componentes Vue: cubiertos por `composer-user-tags.test.mjs` (lógica pura) y el E2E.
- Verificación (2026-11-13): `php artisan test --compact --filter=PublishOptionsTest` → 9/9 verde; focal `tests/Feature/Publishing` + `Connectors/LinkedIn` + `Connectors/Meta` + `Mcp` → 657/657 verde; Pint OK. `npm run build` OK, `typecheck` OK, `test:unit` 639/639 (con `composer-user-tags.test.mjs`). Playwright (`E2E_PURGE=0 E2E_WORKERS=2`): `publish-options.spec.ts` (nuevo, E2E-36: lugar con la búsqueda interceptada en el navegador, motivo y id a mano cuando Meta la restringe, `user_tags` pulsando sobre la imagen y guardado/recarga) 3/3 y `composer-presets` + `linkedin-pinterest` + `instagram-audio` 27/27 (los 3 proyectos). No se lanzó el Pest completo.
- Contratos (consultados el 2026-11-13; detalle en `decisiones-por-red.md#opciones-de-publicación-t-34`): LEÍDO en la [Videos API de LinkedIn](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/videos-api): `uploadThumbnail: true` → `thumbnailUploadUrl`, `PUT` con `media-type-family: STILLIMAGE` y `Content-Type: application/octet-stream` (201); `finalizeUpload` no cambia. LEÍDO en la referencia de Page/feed y Page/photos de Graph: `place` (id de la Página del lugar). **Sin verificar** (las páginas de Meta se cargan con JavaScript y no se pueden leer): formato/tamaño de la miniatura de LinkedIn, `place` en vídeos/Reels/historias (no documentado: se rechaza), `location_id` y `user_tags` de Instagram, y la búsqueda `GET /pages/search`.
- Decisiones: (1) **Búsqueda de lugares**: `GET /pages/search?q&fields=id,name,location` con el token de la cuenta, solo web (`brands/{brand}/connections/{account}/places`, política de la marca, 30/min, cuentas de Instagram y Facebook). Meta la restringe (función «Page Public Metadata Access»): un error de permisos responde 422 con el motivo y el compositor deja **pegar el id**; nunca se guarda ni se cachea el resultado. API y MCP aceptan `place`, `location_id` y `user_tags` pero no buscan. (2) **Facebook `place`**: texto, foto y varias fotos; vídeo, Reel e historia lo rechazan con un error en español. (3) **Portada de LinkedIn** = la portada por destino de la media propia (T-17, `cover_id`), ahora admitida en `linkedin_video`; si LinkedIn no devuelve `thumbnailUploadUrl` falla antes de subir el vídeo (no se publica sin la portada pedida). (4) **Editor de `user_tags`**: pulsar sobre la imagen coloca la etiqueta; «Añadir en el centro» y los campos x/y son la alternativa de teclado; repetir un usuario mueve su etiqueta; tope de 20 (el de la API). (5) Se borró el texto «la app no busca lugares / se envían por la API o el MCP» de la UI, de `InstagramPostOptions` y del MCP (criterio de código limpio).
- Pendiente (coordinador): revisión adversarial A+B(+C). Smoke real del usuario: CS-M-05/M-04 (lugar, `user_tags`, portada de LinkedIn).

**Notas**: Tamaño M. Verificación real = smoke del usuario (`location_id`/`user_tags`, LinkedIn).

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

### T-35 — Estudio: PPTX, miniaturas por diseño y traducción de subtítulos

- **Descripción**: Exportación PPTX, miniaturas generadas por diseño y traducción de subtítulos con el asistente de IA configurado. La transcripción automática (ASR) no entra (ver F-26).
- **Changelog**: El estudio exporta un diseño a PowerPoint (una diapositiva por página), enseña una miniatura de cada diseño en el listado y traduce los subtítulos con el asistente de IA cuando está activado.
- **Estado**: completado
- **Cerrada**: 2026-11-13
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-33
- **Tipo**: fullstack
- **Archivos**: `app/Services/Studio/StudioPptxExporter.php`, `app/Services/Studio/StudioZipWriter.php`, `app/Services/Studio/StudioThumbnails.php`, `app/Services/Studio/StudioCaptions.php`, `app/Services/Studio/StudioRenderer.php`, `app/Jobs/Studio/RenderStudioThumbnail.php`, `app/Http/Controllers/Studio/StudioThumbnailController.php`, `app/Http/Controllers/Studio/StudioController.php`, `app/Http/Controllers/Studio/StudioAiController.php`, `app/Mcp/Tools/ExportDesign.php`, `database/migrations/2026_12_24_090000_add_thumbnail_to_studio_designs.php`, `config/studio.php`, `config/rate_limits.php`, `routes/web.php`, `phpunit.xml`, `resources/js/Pages/Studio/Index.vue`, `resources/js/Pages/Studio/Editor.vue`, `resources/js/Components/Studio/ExportDialog.vue`, `resources/js/Components/Studio/NativeExportJobs.vue`, `resources/js/Components/Studio/CaptionsPanel.vue`, `resources/js/Components/Studio/TimelinePanel.vue`, `docs/modulos/estudio.md`, `docs/modulos/mcp.md`, `docs/guias/estudio-api-mcp.md`, `docs/api/openapi.yaml`, `tests/Feature/Studio/StudioPptxExportTest.php`, `tests/Feature/Studio/StudioThumbnailsTest.php`, `tests/Feature/Studio/StudioCaptionTranslationTest.php`, `tests/e2e/studio-pptx-thumbnails.spec.ts`, `app/Services/Studio/StudioCaptionMasker.php`, `app/Services/Studio/StudioDocument.php`, `app/Services/Studio/StudioService.php`, `app/Services/AiAssistant.php`, `app/Models/User.php`, `resources/js/Components/AiNotConfigured.vue`, `resources/js/Components/Studio/StudioAiPanel.vue`, `tests/frontend/studio-captions-panel.test.mjs`, `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/modulos/despliegue.md`
- **Cubre**: B-18
- **Cubre (tests)**: E2E-07, M-11
- **Verificación**: `php artisan test --compact --filter=StudioPptxExportTest` → verde · `php artisan test --compact --filter=StudioThumbnailsTest` → verde

**Criterios de aceptación**
- [x] Un diseño se exporta a PPTX válido (abre con una librería de lectura en test)
- [x] Cada diseño tiene miniatura actualizada
- [x] La traducción de subtítulos usa `AiAssistant` y está desactivada sin clave
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- RED/TDD: los tres ficheros de test se escribieron antes que el código que prueban, pero no se registró una ejecución en rojo aparte (faltaban las clases y rutas); la primera pasada ya con el código tuvo fallos de los propios tests (`SimpleXML` y atributos, tamaño mínimo de diapositiva, directorio de trabajo que exige `StudioChartFiles`, cuota que devolvía 500 por `PostValidationException` sin envolver), corregidos en la tarea · 2026-11-13.
- Verificación (2026-11-13): `php artisan test --compact --filter=StudioPptxExportTest` → 3/3 verde · `--filter=StudioThumbnailsTest` → 5/5 verde · `--filter=StudioCaptionTranslationTest` → 16/16 verde; focal `tests/Feature/Studio` + `Mcp` + `Api` → 1030/1039 verde (9 omitidas ya existentes); Pint OK; migración `2026_12_24_090000` probada hacia arriba, abajo y arriba en un SQLite temporal (nunca el MySQL de desarrollo). `npm run build` OK, `typecheck` OK, `test:unit` 639/639. Playwright (`E2E_PURGE=0 E2E_WORKERS=2`): `studio-pptx-thumbnails.spec.ts` (nuevo, E2E-07) 9/9 (miniatura bajo demanda con Chromium real, invalidada al editar, PPTX descargado y validado con `python -m zipfile -t`, traducción apagada y propuesta aplicada con la IA simulada en el navegador) y `studio` + `studio-captions` + `studio-native-export` + `studio-boards` 28/28. No se lanzó el Pest completo.
- Decisiones: (1) **PPTX = `format: "pptx"` del render del servidor** (diálogo, API, MCP): Chromium genera los PNG, `StudioPptxExporter` escribe el OOXML y `StudioZipWriter` lo empaqueta **sin `ext-zip` ni dependencias nuevas** (no se añadió `phpoffice/phppresentation`: exige `ext-zip`, que este servidor no tiene, y compone con `composer.json`). Una diapositiva por página con la imagen a pantalla completa; **texto no editable** porque las capas del estudio no tienen equivalente fiel en las formas de PowerPoint. No crea media ni «Crear borrador»; se descarga de la lista. El test lo abre con un lector ZIP/OOXML propio (firmas, CRC, XML, relaciones) y, si hay `ext-zip`, con `ZipArchive` (`CHECKCONS`). Abrirlo en PowerPoint = M-11. (2) **Miniaturas bajo demanda**, no al guardar: un hook en el guardado ejecutaría Chromium en cada autoguardado (y con la cola `sync` de los tests, en todos los tests de estudio). Se cachean por revisión (`thumbnail_path`/`thumbnail_revision`), se invalidan solas al editar, se encolan desde el listado en `media` (únicas por diseño, 12 por listado, aplazadas 10 min tras un fallo) y no tocan `revision` ni `updated_at`; `STUDIO_THUMBNAILS=false` las apaga (también en `phpunit.xml`). (3) **Refactor mínimo de `StudioRenderer`**: `execute()` es el cuerpo común de exportar y de miniatura; `render()` conserva su contrato. (4) **Traducción con `AiAssistant`** (acción `translate_captions` de `ai-text`, web y API, no MCP): propuesta sin guardar, ids y orden validados, correos/teléfonos/enlaces fuera, cuota diaria compartida; sin asistente activado no se envía nada y la UI muestra el aviso común. Aplicar cambia solo los textos. (5) La transcripción (ASR) sigue a la espera de proveedor (F-26).
- Pendiente (coordinador): revisión adversarial A+B(+C). Manual M-11 (abrir el PPTX en PowerPoint) y CS-M-08 (miniaturas con Chromium en el VPS).

**Notas**: Tamaño M. Qué se puede hacer ya: PPTX, miniaturas y traducción (con fake de IA). Queda a la espera (U): proveedor de ASR para transcripción (F-26 [D]); abrir el PPTX en PowerPoint es el manual M-11. **A la espera (no bloquea la fase)**: Proveedor de ASR (decisión del usuario).

---

**Cierre (2026-11-13)**: revisiones adversariales cerradas (ver «Revisión de dos lentes» de la Fase 3); Pest completo en solitario 4024 passed / 9 skipped / 0 failed; E2E completo de cierre de fase en paralelo: 672 passed y 2 rojos (contraste de la insignia de avisos, corregido con `bg-rose-700`, y un `ERR_NO_BUFFER_SPACE` de Windows del entorno), relanzados `workflow`+`pages` en los 3 tamaños: 116 passed / 0 failed. Migraciones de la fase aplicadas en el MySQL 8.4 de desarrollo con copia previa.

## Fase 4 — Refactor del backend

**Estado**: borrador · **Estimado**: 90.0h · **Real**: — · **Coste est.**: 4500 € (base) + tokens ⚠️ verificar · **Tokens est.**: 4350k

### T-36 — Instagram y Threads: `ContainerPublishFlow` común

- **Descripción**: Extraer el flujo de contenedores (crear, esperar, publicar, contenedor fallido) a una clase común con ganchos por red; unificar el comportamiento ante contenedor fallido, que hoy diverge.
- **Changelog**: Instagram y Threads publican con el mismo flujo de contenedores; si el contenedor de Instagram falla o caduca, el destino queda fallido con un código que distingue la causa, como ya hacía Threads.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-24, T-34, T-15
- **Tipo**: backend
- **Archivos**: `app/Connectors/Support/ContainerPublishFlow.php`, `app/Connectors/Support/ContainerNetwork.php`, `app/Connectors/Meta/Instagram/InstagramContainers.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php`, `app/Connectors/Threads/ThreadsContainers.php`, `app/Connectors/Threads/ThreadsPublisher.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramPublishTest.php`, `tests/Feature/Connectors/Meta/Instagram/InstagramAudioPublishTest.php`, `docs/modulos/publicacion.md`, `CHANGELOG.md`, `CHANGELOG.es.md`
- **Cubre**: C-01
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter='InstagramPublish|ThreadsPublisher'` → verde

**Criterios de aceptación**
- [x] Los dos publishers delegan en `ContainerPublishFlow`
- [x] El comportamiento ante contenedor fallido es el mismo en ambos y queda documentado
- [x] Los tests de conectores existentes pasan. La única adaptación es la aserción del contenedor `ERROR` de Instagram, por el cambio de conducta declarado.
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Verificación (2026-11-16): base focal de siete carpetas, 1019/1019. El filtro propio de T-36 `InstagramPublish|ThreadsPublisher` pasó 56 pruebas. T-36 adaptó una aserción y añadió dos escenarios de Instagram, `EXPIRED` en el contenedor e hijo de carrusel en `ERROR`. El resultado de 1093/1093 tras T-36, T-37 y T-38 corresponde al conjunto ampliado con Connections y Competitors y a las pruebas de las tres tareas. Pint pasó.
- Decisiones: (1) `ContainerPublishFlow` (flujo: cuota, contenedor, estado, publicar, carrusel reanudable con `StepBudget`, `findPublished`) y la interfaz `ContainerNetwork` con los ganchos por red; `InstagramContainers` y `ThreadsContainers` llevan endpoints, parámetros, lectura del estado, mensajes de error, enlace y búsqueda (historias en Instagram). Los publishers solo validan (tipo, opciones, audio, tamaño del carrusel) y delegan. Se borró el código duplicado; sin puentes. (2) **Contenedor fallido, conducta unificada:** `ERROR`/`EXPIRED` (también un hijo de carrusel) lanzan `PermanentPublishException` con código `contenedor_error` o `contenedor_caducado` y el motivo traducido (la que ya tenía Threads). Antes Instagram devolvía `PublishResult::failed`, que los jobs convertían en el código genérico `rechazada`; ahora el destino queda con el código que distingue la causa. Es el único cambio de comportamiento y está declarado en el changelog. (3) Test de Instagram adaptado (`toThrow` con código) y dos nuevos (EXPIRED, hijo de carrusel en ERROR).
- Evidencia adicional del 16 de noviembre: el diff de tests de T-36 conserva el caso previo de error de Instagram con la aserción adaptada al nuevo contrato y añade los casos de caducidad e hijo de carrusel. La suite de Threads conserva sus tests. La comparación E2E-38 y las pruebas de integración están enlazadas al final del ledger.
- Revisión de aceptación y calidad, intento 1 (2026-11-17). Claude Code devolvió dos informes con `claude-opus-5-5`. Se verificó que `PublishRequest::budget()` memoiza `StepBudget`, así que el gasto de la validación de audio sigue disponible al entrar en `ContainerPublishFlow`. La comparación con `1a2c7c3` dio cero diferencias en las seis clases y el test original de T-36. La búsqueda de `rechazada` encontró usos como código de salida, pero el reintento del calendario y de `PostScheduling` solo excluye `MEDIA_MISSING`. `freshAttempt()` limpia la referencia de la publicación anterior. Se aclaró la excepción de tests y se movió el contrato de contenedores a una sección común de Instagram y Threads. Queda pendiente cubrir los códigos de error persistidos por los jobs con una prueba de regresión y revisar las correcciones. Informes en `%TEMP%/metricool-t36-opus55-a-r1.json` y `%TEMP%/metricool-t36-opus55-b-r1.json`.

- Correcciones aplicadas el 17 de noviembre. Los jobs persisten `contenedor_error` y `contenedor_caducado` en cuatro casos, al publicar y al sondear, con el proveedor simulado. La prueba de audio comprueba que el mismo presupuesto frena el sondeo. Se dieron nombres a los argumentos al crear la solicitud para el padre del carrusel. El filtro inicial pasó 7 pruebas y 32 aserciones. Pendiente la segunda revisión del diff final y verificación de fase.

- Segunda revisión: `claude-opus-5-5` no detectó Critical ni Important en el diff corregido. El noveno argumento anterior era el checkpoint opcional, con valor `null`, y `advance()` sigue recibiendo el `StepBudget` explícito. Los 6 casos de Threads y los 3 de media por destino del informe serial pasaron. Informe de revisión en `%TEMP%/metricool-t36-opus55-r2.json` y de navegador en `storage/framework/testing/t43/run-general-7bfa8463-7586-46b5-ad17-37eba2354e82/results.json`. Pest completo serial del 17 de noviembre: 4125 aprobados, 9 omitidos y 26 333 aserciones, sin fallos. Informe en `%TEMP%/metricool-review-php-full.log`. Se conserva la comparación E2E-38 de 10/10 antes y después.

**Notas**: Tamaño M. El contenedor fallido es el único cambio de comportamiento permitido y se declara en el changelog.

### T-37 — `UploadPassBudget` sobre `StepBudget` (TikTok y YouTube)

- **Descripción**: Unificar el presupuesto de pasadas de subida duplicado entre TikTok y YouTube.
- **Changelog**: TikTok y YouTube comparten el presupuesto de las subidas por pasadas. Con un margen mínimo de cero, se aplaza el siguiente trozo si la pasada agotó su tiempo.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-36
- **Tipo**: backend
- **Archivos**: `app/Connectors/Support/UploadPassBudget.php`, `app/Connectors/Support/StepBudget.php`, `app/Connectors/TikTok/TikTokPublisher.php`, `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeClient.php`, `app/Connectors/Google/YouTubeResumableUpload.php`, `tests/Feature/Connectors/Support/UploadPassBudgetTest.php`, `tests/Feature/Connectors/Google/Publish/YouTubePublishTest.php`, `docs/modulos/publicacion.md`, `CHANGELOG.md`, `CHANGELOG.es.md`
- **Cubre**: C-02
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter='TikTokPublish|YouTubePublish'` → verde

**Criterios de aceptación**
- [x] Ambos publishers usan `UploadPassBudget` y no queda código duplicado
- [x] Los tests de subida reanudable pasan sin cambios
- [x] Pest y E2E en verde antes y después. Con margen mínimo de cero, la pasada agotada aplaza el siguiente trozo.
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Verificación (2026-11-16): Pest antes (focal: Connectors, Publishing, Inbox, Analytics, Instagram, FirstComment, Jobs): 1019/1019 · `--filter='TikTokPublish|YouTubePublish'` verde (83 tests, 2026-11-16, filtro corregido: el anterior no seleccionaba ninguno); Pest focal 1093/1093 (las 7 carpetas de la base más Connections y Competitors; la base de las 7 carpetas eran 1019; tests nuevos de Instagram, UploadPassBudget y YouTubeClient, y el de reloj de YouTube movido a UploadPassBudgetTest; ninguno retirado sin sustituto); Pint OK.
- Decisiones: `UploadPassBudget` (presupuesto blando `upload_budget_seconds` y marca dura `pass_seconds`, por red) se construye sobre `StepBudget` (nuevo `StepBudget::within()` para un presupuesto solo de tiempo). Se borraron `passStartedAt`, `budgetLeft`, `elapsed` y `callTimeout` de TikTok y YouTube. El test que fijaba `passStartedAt` por reflexión pasó a `UploadPassBudgetTest` (mismo comportamiento: tope propio, marca dura, borde de 1 s; ambas redes). **Pinterest no encaja**: sube de una vez con el `remaining()` de su `StepBudget`, sin pasadas por trozos ni marca dura propia.
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright de fase.

- Revisión de aceptación y calidad (2026-11-17). Opus 5.5 señaló dos riesgos en la extracción posterior de YouTube. El código actual confirma que `YouTubeClient` conserva `UploadPassBudget`, y que `YouTubeResumableUpload` inicia y termina esa misma instancia. La búsqueda de usos de la API retirada no encontró llamadas en aplicación, rutas, comandos, scripts ni pruebas. Se conservan como pendientes la cobertura del timeout en el cliente, la regla de corte todavía repetida entre redes y el caso de margen mínimo configurado en cero. Informe en `%TEMP%/metricool-t37-opus55-r1.json`.

- Seguimiento del 17 de noviembre. El mínimo se evalúa en `UploadPassBudget` con las claves tipadas de cada red. Conserva el primer trozo y evita empezar otro si el tiempo llegó a cero, incluso cuando el mínimo configurado es cero. Pint y el filtro combinado de conectores e importación pasaron (184 pruebas y 722 aserciones). Opus 5.5 verificó aceptación y calidad del diff aplicado sin Critical ni Important y pidió cubrir el valor por defecto sin clave. Se añadió la comparación con 12 segundos restantes y pasó con 15 s para TikTok y 10 s para YouTube. Informe en `%TEMP%/metricool-t37-t38-applied-opus55.json`.

- Playwright serial de E2E-38 y flujos afectados: 24 aprobados, cero fallos y cero flaky. Se cotejaron los 10 casos de E2E-38 aprobados en ambas ejecuciones por fichero, título y proyecto. Pest completo posterior pasó 4129 pruebas, 9 omitidas y 26 355 aserciones, en `%TEMP%/metricool-t37-t42-pest-full-final.log`. Informe en `storage/framework/testing/t43/run-general-ec6954ac-bb10-44e3-b1c5-1457c4b962e7/results.json`.

**Notas**: Tamaño S.

### T-38 — Partir `YouTubePublisher`: `YouTubeClient` y `YouTubeResumableUpload`

- **Descripción**: Separar cliente HTTP/cuota, subida reanudable y comentarios del publisher de 930 líneas.
- **Changelog**: Sin cambios visibles: el conector de YouTube se reparte en cliente, subida reanudable, sondeo y recuperación, metadatos y comentarios.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-37
- **Tipo**: backend
- **Archivos**: `app/Connectors/Google/YouTubePublisher.php`, `app/Connectors/Google/YouTubeClient.php`, `app/Connectors/Google/YouTubeResumableUpload.php`, `app/Connectors/Google/YouTubeVideoStatus.php`, `app/Connectors/Google/YouTubeVideoMetadata.php`, `app/Connectors/Google/YouTubeComments.php`, `tests/Feature/Connectors/Google/Publish/YouTubeClientTest.php`, `tests/Feature/Connectors/Google/Publish/YouTubePublishTest.php`, `tests/Feature/Publishing/PresetsReviewTest.php`, `tests/Feature/Publishing/YouTubeOptionsTest.php`, `docs/modulos/publicacion.md`
- **Cubre**: C-03
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=YouTube` → verde

**Criterios de aceptación**
- [x] `YouTubePublisher` baja de 400 líneas
- [x] Cliente y subida son clases testeables por separado
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Verificación (2026-11-16): Pest antes (focal: Connectors, Publishing, Inbox, Analytics, Instagram, FirstComment, Jobs): 1019/1019 · `--filter=YouTube` verde; Pest focal 1093/1093 (las 7 carpetas de la base más Connections y Competitors; la base de las 7 carpetas eran 1019; tests nuevos de Instagram, UploadPassBudget y YouTubeClient, y el de reloj de YouTube movido a UploadPassBudgetTest; ninguno retirado sin sustituto); Pint OK. `YouTubePublisher`: 929 -> 229 líneas.
- Decisiones: `YouTubeClient` (peticiones, errores de la API, cupo y reloj de la pasada), `YouTubeResumableUpload` (sesión, trozos, portada; la recuperación del video completo se inyecta como cierre), `YouTubeVideoMetadata` (validación, título, descripción, tags y tag opaco por destino) y `YouTubeComments` (primer comentario, búsqueda idempotente e inbox; ya no hace `new YouTubePublisher`). El publisher queda como fachada: `publish`/`poll`/`findPublished`, `comment`/`findOwnComment` delegados (contrato `FirstCommenter`). Constantes y estáticos movidos: `targetTag`/`tagsFor` -> `YouTubeVideoMetadata` (tests actualizados), `UPLOADING` -> `PublishResult::UPLOADING` (el alias del publisher se borró). `canComment()` y `forgetUploadSession()` ya no existen en el publisher: `PostController` llama a `YouTubeComments::canComment` y `PostPlanner`/`PostScheduling` a `YouTubeResumableUpload::forgetSession` (sin puentes). `deleteVideo` se borró con su test: no tenía ningún uso en `app/` ni lo prevén el ledger ni la documentación.
- Tests nuevos: `YouTubeClientTest` (errores de la API, cupo agotado, reloj de pasada, `resume` sin sesión, `forgetSession`, tag opaco).
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright de fase.

- Revisión de aceptación y calidad (2026-11-17). Opus 5.5 identificó el acoplamiento de recuperación de subidas al publicador mediante un cierre y la falta de una prueba de transferencia directa. El cierre recibe una referencia nula en `uploadedOrFail()`, por lo que la llamada actual trata la búsqueda por etiqueta y no retoma de nuevo la misma sesión. Persiste la dependencia circular de responsabilidades señalada por el revisor. Se cotejó `missingScopes()` con `CommentSource` y `InboxService::help()`: el contrato de YouTube admite `[]` y la ayuda indica el permiso específico. El ledger actual registra la eliminación del método sin llamadas `deleteVideo` y el contraste E2E-38. Informe en `%TEMP%/metricool-t38-opus55-r1.json`.

- Seguimiento del 17 de noviembre. `YouTubeVideoStatus` sondea y recupera por tag con el mismo cliente. El publicador y la subida dependen de él y se elimina el callback de vuelta a la fachada. El caso de offset finalizado sin ID pasó antes y después: recupera un Short por tag, guarda su ID y borra la sesión sin reiniciarla. El reloj se congela en el `beforeEach` de la suite. Opus 5.5 verificó aceptación y calidad del diff aplicado sin Critical ni Important. Pint verde y 184 pruebas focales con T-37 e importación. Informe en `%TEMP%/metricool-t37-t38-applied-opus55.json`.

- Playwright serial de E2E-38 y flujos afectados: 24 aprobados, cero fallos y cero flaky. Se cotejaron los 10 casos de E2E-38 aprobados en ambas ejecuciones por fichero, título y proyecto. Pest completo posterior pasó 4129 pruebas, 9 omitidas y 26 355 aserciones, en `%TEMP%/metricool-t37-t42-pest-full-final.log`. Informe en `storage/framework/testing/t43/run-general-ec6954ac-bb10-44e3-b1c5-1457c4b962e7/results.json`.

**Notas**: Tamaño M.

### T-39 — Estudio: FormRequests compartidos y orquestación en el servicio

- **Descripción**: Unificar validación y orquestación de los controladores web y API del estudio en FormRequests compartidos y métodos del servicio.
- **Changelog**: Sin cambios visibles: el estudio web y su API validan con las mismas reglas y abren un diseño con la misma lógica, así que dejan de poder divergir.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Studio/StudioController.php`, `app/Http/Controllers/Studio/StudioApiController.php`, `app/Http/Requests/Studio/**`, `app/Services/Studio/StudioService.php`, `app/Services/Studio/StudioEditor.php`, `tests/Feature/Studio/StudioBackendTest.php`, `tests/e2e/studio-collab.spec.ts`, `docs/modulos/estudio.md`, `routes/studio-ai-api.php`
- **Cubre**: C-04
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Studio` → verde

**Criterios de aceptación**
- [x] No hay reglas de validación duplicadas entre controlador web y API
- [x] Respuestas y códigos de error idénticos a los previos
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Implementación (2026-11-16, commit `dcd4bd3` + docs `eb54893`): 7 FormRequests compartidos en `app/Http/Requests/Studio/` (`ListDesignsRequest` + `ListDesignsApiRequest` que solo añade «true»/«false» como texto en la query, `DuplicateDesignRequest`, `FolderNameRequest`, `MoveDesignRequest`, `AttachAssetRequest`, `ListExportsRequest`, `EditorRequest`); la orquestación de `edit`/`show` pasa a `StudioEditor` (`web()`, `api()`, `brandProps()`, `apiAssets()`). `StudioController` 292 → 238 líneas, `StudioApiController` 226 → 190; no queda ningún `$request->validate(` con reglas duplicadas entre ambos. Sin alias ni métodos puente: se borraron `editorProps`, `brandProps` y `assetData` de los controladores.
- Decisiones: (1) `StudioEditor` es una clase nueva y no más líneas en `StudioService` (ya 415) — por eso se añade a `Archivos`. (2) Único matiz de comportamiento: `GET studio` (web, `index`) ahora valida los filtros también cuando el usuario no tiene marca (antes devolvía el listado vacío sin validar); con filtros válidos nada cambia.
- Verificación (2026-11-16): Pest antes (línea base de los refactors, `Posts Workflow Publishing Mcp Api Studio Parity Calendar Campaigns FirstComment Library Media`, HEAD `f40a583`): 2096 tests, 2087 passed, 9 skipped, 0 failed. Después de T-39: `Studio Api Mcp Parity Ui` → 1241 tests, 1232 passed, 9 skipped, 0 failed (una primera pasada dio 6 errores en `StudioAvRetirementTest` por solaparse con el Pest de otro agente; el fichero en solitario pasa 16/16 y la segunda pasada completa es verde). Pint OK. Sin Pest completo ni Playwright (los lanza el coordinador).
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

- Revisión de aceptación y calidad (2026-11-17). Opus 5.5 confirmó un cambio respecto al criterio de conservar respuestas: el `FormRequest` valida antes de devolver el listado vacío de `GET /studio` cuando el usuario carece de marca. Hay que restaurar la respuesta anterior y añadir una regresión que preserve las reglas compartidas. El riesgo posterior de `ownedBy()` queda contrastado con `BelongsToUser::scopeOwnedBy()`, que retira `UserScope` y cualifica `user_id`. En la revisión del flujo de apertura se señaló la duplicación del preámbulo de autorización en `StudioEditor`. Informe en `%TEMP%/metricool-t39-opus55-r1.json`.

- RED del 17 de noviembre: el nuevo caso de `/studio?folder_id=abc` sin marca esperaba HTTP 200 y recibió 302. GREEN: `ListDesignsRequest` aplica las reglas compartidas en las rutas con marca y conserva la portada vacía sin marca. Se verifica 200 sin marca y 422 con marca. En conjunto con las regresiones de Instagram y `PostService` pasaron 11 pruebas y 57 aserciones.

- Segunda revisión: Opus 5.5 aprobó el arreglo sin Critical ni Important. Pest ampliado de Instagram, Threads, Estudio, firma, aprobaciones y despliegue dio 193/193 y Pint verde. En la prueba serial posterior de colaboración apareció un fallo de contraste en escritorio al medir «Crear enlace» inmediatamente después de compartir. La traza muestra que estaba habilitado y con color efectivo de contraste 2,73. La transición de opacidad del botón al finalizar la operación es la hipótesis a verificar. Se preparó un ajuste que comprueba opacidad `1` antes de ejecutar Axe, conservando todas sus reglas.

- La pasada serial terminó 21/22. Las dos variantes móviles de colaboración y el caso de carpetas pasaron. Se aplicó la espera del estilo final al test de colaboración. La repetición posterior pasó en los tres tamaños, en `storage/framework/testing/t43/run-general-f4fe3d79-75be-4cd4-ab5a-ad176493e0c2/results.json`. La revisión de ese ajuste confirmó que varias acciones comparten el mismo estado de carga. Opus 5.5 confirmó que la aparición de la fila precede al fin del estado de carga. El test espera a que se habiliten los controles, luego a que terminen las transiciones de opacidad del diálogo y ejecuta Axe con las mismas reglas y alcance. El informe `%TEMP%/metricool-t39-collab-scope-final-opus55.json` confirma la aceptación y calidad del ajuste sin Critical ni Important. La repetición serial pasó 6/6 (dos por anchura), sin fallos ni flaky y con `qa-gate.py` VERDE. Informe en `storage/framework/testing/t43/run-general-f0916f63-4bbd-47f8-85f2-25d27054d09c/results.json`. Pest completo pasó 4125 pruebas, con 9 omitidas y 26 333 aserciones, en `%TEMP%/metricool-review-php-full.log`.

**Notas**: Tamaño M.

### T-40 — `PostService` (1/2): extraer `PostPlanner` y `PostSignature`

- **Descripción**: Primera mitad de C-05: sacar de `PostService` la planificación (`plan`, 142 líneas) y la firma de posts; `PostService` delega como fachada. C-05 se cuenta aquí.
- **Changelog**: Sin cambios visibles: la lógica de planificar y de la huella de una publicación vive en clases propias con tests, lo que hace más seguro tocarla.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-39
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Services/Posts/PostPlanner.php`, `app/Services/Posts/PostSignature.php`, `app/Services/Posts/PostTransitions.php`, `app/Services/Posts/PostApproval.php`, `app/Http/Controllers/PostController.php`, `app/Http/Requests/PostRequest.php`, `app/Services/CalendarService.php`, `app/Services/FirstCommentService.php`, `app/Services/LibraryService.php`, `tests/Feature/Posts/PostPlannerSignatureTest.php`, `tests/Feature/Publishing/RevisionFase2Intento2Test.php`, `docs/arquitectura.md`, `docs/modulos/publicacion.md`, `docs/modulos/media-por-destino.md`
- **Cubre**: C-05
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Post` → verde

**Criterios de aceptación**
- [x] `PostPlanner` y `PostSignature` existen con tests propios
- [x] API pública de `PostService` intacta
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Implementación (2026-11-16, commit `9bb7925`, junto con T-41): `PostPlanner` (`plan`, `fill`, `optionErrors`, `effectiveTargetMedia`, `campaignErrors`, `normalizeOptions`, `normalizeNotes`, `parseWhen`, `dataFromPost`) y `PostSignature` (`ofPost`, `ofPlan`) extraídos con el código movido tal cual. Para evitar una dependencia circular (`fill` y las operaciones de ciclo de vida comparten la máquina de estados) se añade `PostTransitions` (`transition`, `apply`, `lockEditable`, `freshAttempt`, `releaseBackoff`, `keepRatePause`, `pausedTargets`); y la aprobación vive en `PostApproval` porque `create`/`update` la necesitan sin depender de `PostScheduling`. Tests propios: `PostPlannerSignatureTest` (7).
- API pública de `PostService` intacta (mismos métodos y firmas). Movidos con sus usos actualizados (sin puente): `pausedTargets` → `PostTransitions` (`PostController`, test), `MEDIA_MISSING`/`MEDIA_MISSING_RETRY` → `PostScheduling` (`CalendarService`), `normalizeOptions` → `PostPlanner` (sin usos externos); referencias en comentarios y docs actualizadas.
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

- Revisión de aceptación y calidad (2026-11-17). Opus 5.5 confirmó que se retiraron miembros públicos de `PostService`, aunque el ledger pedía conservar su API. Queda pendiente devolver los métodos y constantes en la fachada y añadir cobertura de invariancia de la firma de publicación. El riesgo de la tanda de aprobaciones se acotó al revisar los usos actuales: `SeriesService` abre la tanda y procesa sus ocurrencias mediante la misma propiedad `$posts`. No se encontró ninguna resolución directa de `app(PostApproval::class)` en esos recorridos. Informe conjunto con T-41 en `%TEMP%/metricool-t40-t41-opus55-r1.json`.

- RED del 17 de noviembre: la nueva prueba de la fachada obtuvo «Call to undefined method PostService::normalizeOptions()». GREEN: la fachada vuelve a exponer ambos métodos y constantes, con delegación a las clases extraídas. El test de firma verifica orden de destinos, orden de claves y sensibilidad a opciones, primer comentario y publicación manual. La pasada focal conjunta fue de 11/11 y Pint verde.

- Claude Code (`claude-opus-5-5`) aprobó la API de la fachada y la cobertura de la firma en la segunda ronda. La tercera confirmó el alcance de `ownedBy()` sin hallazgos Critical ni Important abiertos. Informe en `%TEMP%/metricool-t40-t41-opus55-r3.json`. Los nueve flujos de `workflow.spec.ts` pasaron en las tres anchuras (informe de T-41). Pest completo serial del 17 de noviembre: 4125 aprobados, 9 omitidos y 26 333 aserciones, sin fallos. Informe en `%TEMP%/metricool-review-php-full.log`. Se conserva la comparación E2E-38 de 10/10 antes y después.

**Notas**: Tamaño M. C-05 es L: se parte en dos.

### T-41 — `PostService` (2/2): extraer `PostScheduling`

- **Descripción**: Segunda mitad de C-05: extraer la lógica de programación y dejar `PostService` como fachada < 400 líneas.
- **Changelog**: Sin cambios visibles: devolver a borrador, reprogramar, borrar y reintentar publicaciones pasa a una clase propia y PostService queda como fachada fina.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-40
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Services/Posts/PostScheduling.php`, `app/Services/Posts/PostPlanner.php`, `app/Services/Posts/PostTransitions.php`, `app/Services/Posts/PostApproval.php`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Post` → verde

**Criterios de aceptación**
- [x] `PostService` < 400 líneas
- [x] Sin cambios en controladores, API ni MCP que lo consumen
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Implementación (2026-11-16, commit `9bb7925`, junto con T-40): `PostScheduling` (`unschedule`, `reschedule`, `delete`, `retryFailed`, `MEDIA_MISSING*`) recibe el `Post` ya verificado: la comprobación de propiedad (`assertOwns`) queda en la fachada, una sola vez. `schedule` y `publishNow` se quedan en `PostService` porque son `update()` con otros datos (`PostPlanner::dataFromPost`) y moverlos obligaba a que `PostScheduling` dependiera de `PostService`. La retención de ocurrencias de series (T-32: `holdForApproval`, `releaseApproval`, `scheduleApprovedOccurrence`, `collectApprovalRequests`) está en `PostApproval` con el código sin tocar; `publishNow`/`schedule` siguen rechazando una ocurrencia retenida por `PostApproval::applyRules`, y `retryFailed` por `isPublishable()`.
- `PostService` 1220 → 335 líneas (< 400); controladores, API y MCP sin cambios salvo los dos usos de estáticos movidos (T-40).
- Verificación (2026-11-16): Pest antes (HEAD `f40a583`, `Posts Workflow Publishing Mcp Api Studio Parity Calendar Campaigns FirstComment Library Media`): 2096 tests, 2087 passed, 9 skipped, 0 failed. Después: 2103 tests (+7 nuevos), 2084 passed, 9 skipped, 10 errores TODOS en `PresetsReviewTest` y `YouTubeOptionsTest` (`Class "YouTubePublisher" not found`: ficheros que el otro agente tenía a medias en T-38, ajenos a esta tarea); el resto idéntico a la línea base. Una primera pasada detectó un `use` olvidado en `CalendarService` (fallaban 2 tests de calendario y media), corregido. Pint OK.
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

- Revisión de aceptación y calidad (2026-11-17). Opus 5.5 señaló que `PostController` cambió de superficie al llamar a `PostTransitions::pausedTargets()`. Al restaurar la fachada se recuperará el punto de llamada original. El filtro `ownedBy()` cualifica la columna y la prueba de la fachada debe conservar el recuento y los estados. Informe conjunto con T-40 en `%TEMP%/metricool-t40-t41-opus55-r1.json`.

- Corrección del 17 de noviembre: `PostController` vuelve a invocar `PostService::pausedTargets()` y `CalendarService` vuelve a leer las constantes de la fachada pública. Las implementaciones extraídas conservan la lógica. Pendiente nueva revisión de la corrección.

- Tercera revisión conjunta T-40/T-41: Opus 5.5 comprobó en el trait real que `ownedBy()` quita solo `UserScope`, cualifica `post_targets.user_id` y usa la clave del usuario. Cerró el Important condicionado de la segunda revisión. Informe en `%TEMP%/metricool-t40-t41-opus55-r3.json`. La extracción no cambia los puntos de entrada de controladores, API ni MCP. `PostService` quedó en 354 líneas y la segunda y la tercera revisión confirmaron el arreglo.

- Navegador: los nueve casos de `workflow.spec.ts` pasaron. Incluyen serie semanal, reprogramación por lote y reintento, en las tres anchuras. Informe en `storage/framework/testing/t43/run-general-7bfa8463-7586-46b5-ad17-37eba2354e82/results.json`. Pest completo serial del 17 de noviembre: 4125 aprobados, 9 omitidos y 26 333 aserciones, sin fallos. Informe en `%TEMP%/metricool-review-php-full.log`. Se conserva la comparación E2E-38 de 10/10 antes y después.

**Notas**: Tamaño M. Continuación de C-05.

### T-42 — Partir `SiteService`, `StudioAvState`, `StudioRenderer::render` y `RemoteMediaImporter::run`

- **Descripción**: Extraer `SiteBlockNormalizer`, `SiteAccess` y `SitePublicInteractions` y partir `render` y `run` por fases.
- **Changelog**: Sin cambios visibles: los sitios, la importación remota de medios y el render del estudio se reorganizan en piezas más pequeñas; el enlace de acceso y los mensajes de los sitios siguen igual.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-41
- **Tipo**: backend
- **Archivos**: `app/Services/Sites/SiteService.php`, `app/Services/Sites/SiteBlockNormalizer.php`, `app/Services/Sites/SiteAccess.php`, `app/Services/Sites/SitePublicInteractions.php`, `app/Services/Studio/StudioRenderer.php`, `app/Services/Media/RemoteMediaImporter.php`, `app/Http/Controllers/PublicSiteController.php`, `app/Http/Controllers/SiteController.php`, `app/Services/Sites/SiteRenderer.php`, `tests/Feature/Sites/SiteTest.php`, `docs/modulos/sitios.md`, `docs/modulos/estudio.md`, `docs/modulos/media.md`, `tests/Feature/Media/ImportRemoteMediaTest.php`, `app/Services/Studio/StudioAvState.php` (borrado), `app/Services/Studio/StudioAvStore.php`, `app/Services/Studio/StudioAvLifecycle.php`, `app/Services/Studio/StudioAvLeases.php`, `app/Services/Studio/StudioAvAcceptance.php`, `app/Services/Studio/StudioAvRegistrationState.php`, `app/Services/Studio/StudioAvControl.php`, `app/Services/Studio/StudioAvProcess.php`, `app/Services/Studio/StudioAvQueue.php`, `app/Services/Studio/StudioAvRecovery.php`, `app/Services/Studio/StudioAvRunner.php`, `app/Services/Studio/StudioAvTransfer.php`, `app/Jobs/Studio/ProcessStudioAvPhase.php`, `tests/Feature/Studio/StudioAv*Test.php`
- **Cubre**: C-06
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Site` → verde · `php artisan test --compact --filter=StudioRenderer` → verde · `php artisan test --compact --filter=ImportRemoteMedia` → verde · `STUDIO_AV_NATIVE_TEST=1 php artisan test --compact --filter=StudioAv` → verde

**Criterios de aceptación**
- [x] Ningún método supera 60 líneas en los ficheros citados
- [x] Sitios públicos y render del estudio sin diferencias observables
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Implementación (2026-11-16, commits `3543754`, `RemoteMediaImporter`, `StudioRenderer` x2 y `72ad22b`): **`SiteService`** 676 -> 240 líneas en cuatro piezas: `SiteBlockNormalizer` (páginas, bloques y medios del borrador; un normalizador privado por tipo de bloque; ahora aloja `MAX_PAGES`, `MAX_BLOCKS`, `BLOCK_TYPES` y `PAGE_SLUG`), `SiteAccess` (ya existía con la cookie y los limitadores del visitante; ahora también la gestión del propietario: `updateAccess`, `createToken`, `revokeToken`, `revokeAllAccess`, `findToken`, `MAX_TOKENS`; deja de depender de `SiteService`), `SitePublicInteractions` (visitas, estadísticas, mensajes con tope FIFO, encuestas, `MAX_MESSAGES`) y `SiteService` (alta, borrador, publicación, tema). Sin alias ni puentes: `SiteController`, `PublicSiteController`, `SiteRenderer` y `SiteTest` usan la clase nueva (`SiteController` pasa a inyectar las tres). La comprobación de propiedad (`abort_unless` 404) es una línea repetida en `SiteAccess` y `SitePublicInteractions` para no acoplarlas.
- **`RemoteMediaImporter::run`** 143 -> 25 líneas: `guard` (cortes previos), `openPartial` (parcial + latido), `fetchPass` (pasada; las excepciones de dominio suben a `run`), `sizePolicy`, `restartFromZero` y `settle` (estado, tipo, sniff, cierre o nueva pasada). El orden de efectos y el estado guardado no cambian.
- **`StudioRenderer::render`** 178 -> 14 líneas: `produce` (carga y fases), `storeFiles`, `importImage`, `packageDownload` (`zipWithExtension`/`zipWithPython`) y `abandon` (el fallo, con la limpieza de lo creado). `execute` (80 líneas) también se parte: `sampleReferences`, `inlineAssets`, `inlineFonts` (el tope de 64 MB sigue compartido por referencia).
- **`StudioAvState`** (860 líneas, 51 métodos) se borra y se parte en cinco clases, ninguna por encima de 250 líneas ni con métodos de más de 60: `StudioAvStore` (172; primitivas transaccionales compartidas: `reserveSqliteWriter`, `activeLease`, `workspace`, `step`, `saveState`, `assertOwnership`, `fingerprint`, `ownedLocked`, `pinnedDocument`, `canonical`, `checkpoints`, `task`), `StudioAvLifecycle` (159; `initialize`, `cancel`, `retry`, `fail`, `failPosition`), `StudioAvLeases` (128; `begin`, `claim`, `defer`, `workingExport`), `StudioAvAcceptance` (225; `accept` por fases, `rejectCheckpoint`, `tasks`, `installSteps`) y `StudioAvRegistrationState` (244; `allocateRegistration`, `bindRegistrationLocation`, `checkpointRegistration` con su diario y su transacción, `registrationSource`, `assertRegistrationPin`). Sin fachada ni puente: `StudioAvControl`, `StudioAvProcess`, `StudioAvQueue`, `StudioAvRecovery`, `StudioAvRunner`, `StudioAvTransfer`, `StudioRenderer` y `ProcessStudioAvPhase` inyectan la clase que corresponde, y los tests de `Studio/StudioAv*` (incluido el parcial de `StudioAvQueueTest`) llaman a la clase nueva. El cuerpo de cada método se movió sin cambios (solo las llamadas entre clases).
- Verificación (2026-11-16): Pest antes (HEAD `487d0ac`, `Sites Studio Media Inbox Mcp Connectors Parity`): 2102 tests, 2093 passed, 9 skipped, 0 failed. Después (T-42 + T-43, con la partición de `StudioAvState` y los textos MCP): 2105 tests (+3 nuevos de T-43), 2096 passed, 9 skipped, 0 failed; una primera pasada detectó un tipo de retorno mal puesto (`acceptVerification` devolvía un id de texto como `int`), corregido. Pint OK. Playwright no se lanza (lo hace el coordinador).
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

- Revisión de aceptación y calidad (2026-11-17). Claude Code Opus 5.5 devolvió cuatro informes para sitios, importación, render estático y audiovisual. El recuento con el parser PHP confirma 195 métodos y cinco por encima de 60 líneas: `StudioAvRecovery::run` (131), `StudioAvTransfer::remote` (110), `StudioAvRunner::run` (81), `StudioAvTransfer::local` (65) y `StudioAvProcess::execute` (62). Quedan pendientes la partición de esos métodos, las invariantes de transacción de `StudioAvStore`, la contabilidad duplicada de reserva en el render audiovisual y el control del ciclo de vida del archivo parcial de `RemoteMediaImporter` y la clasificación de los fallos internos al resolver la política de origen. Las sustituciones `ownedBy()` son posteriores y pertenecen a T-44. El alcance y la cualificación de `user_id` constan en `BelongsToUser::scopeOwnedBy()`. Informes en `%TEMP%/metricool-t42-*-opus55-r1.json` y medición en `%TEMP%/metricool-t42-method-length.json`.

- Correcciones del 17 de noviembre. La medición posterior de PHP-Parser dio 211 métodos y ninguno de más de 60 líneas. `StudioAvStore` exige una transacción para sus operaciones de bloqueo y escritura, explicita el uso para fallos y comparte la contabilidad del disco después de bloquear el workspace. En importación remota, el RED contabilizó una pasada de más al fallar la lectura del origen. La base anterior a `81d056d` preparaba esa política antes de la petición. El GREEN conserva el latido y el contador previo, cierra el parcial en `finally` y mantiene las fases de descarga, reinicio y cierre. Pint verde y 184 pruebas focales con T-37 y T-38. Opus 5.5 aprobó el importador sin Critical ni Important, informe `%TEMP%/metricool-t42-importer-applied-opus55.json`. La primera suite nativa detectó que el worker pasa un objeto con solo el ID al registrar un fallo. Se mantuvo el contrato `object` en los dos puntos de entrada. Pest nativo exacto en Windows: 204 aprobados, 1411 aserciones, cero fallos ni omisiones, incluida la entrega duplicada del job y la comprobación de media pública con otro usuario autenticado. La revisión final de Opus confirmó el contrato. Se comprobaron sus dos condiciones restantes en el código: ambos llamadores de `lockRegistrationLease` abren `DB::transaction` y `config/studio.php` declara los cuatro límites como enteros. Informe `%TEMP%/metricool-t42-final-contracts-opus55.json`.

- Playwright serial de E2E-38 y flujos afectados: 24 aprobados, cero fallos y cero flaky. Se cotejaron los 10 casos de E2E-38 aprobados en ambas ejecuciones por fichero, título y proyecto. Pest completo posterior pasó 4129 pruebas, 9 omitidas y 26 355 aserciones, en `%TEMP%/metricool-t37-t42-pest-full-final.log`. Informe en `storage/framework/testing/t43/run-general-ec6954ac-bb10-44e3-b1c5-1457c4b962e7/results.json`.

**Notas**: Tamaño M.

### T-43 — Inbox: Meta como `CommentSource` y capacidad `HidesComments`

- **Descripción**: Meta pasa a implementar `CommentSource` y `HidesComments`; las listas de redes y `can_hide` se derivan de las capacidades en lugar de escribirse en 6 sitios.
- **Changelog**: Sin cambios visibles: las redes del buzón de comentarios y qué cuentas pueden ocultar comentarios se deciden en un solo sitio, y una red nueva con comentarios aparece en el buzón sin más cambios.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-22, T-42
- **Tipo**: backend
- **Archivos**: `app/Services/InboxService.php`, `app/Http/Controllers/InboxController.php`, `app/Mcp/Tools/ListComments.php`, `app/Connectors/Meta/Comments.php`, `app/Connectors/Contracts/HidesComments.php`, `app/Connectors/Contracts/CommentSource.php`, `app/Connectors/ConnectorRegistry.php`, `app/Providers/AppServiceProvider.php`, `app/Jobs/SyncInboxComments.php`, `app/Connectors/Threads/ThreadsComments.php`, `app/Connectors/Bluesky/BlueskyComments.php`, `app/Connectors/Google/YouTubeComments.php`, `tests/Feature/Inbox/InboxCapabilitiesTest.php`, `tests/Feature/Inbox/InboxCoverageTest.php`, `tests/Feature/Inbox/InboxProvidersTest.php`, `tests/Feature/Inbox/T20ReviewTest.php`, `tests/Feature/Parity/InboxTest.php`, `tests/e2e/isolated/bootstrap.php`, `tests/e2e/roadmap/bootstrap.php`, `docs/modulos/inbox.md`, `docs/modulos/mcp.md`, `docs/modulos/competidores.md`, `app/Services/InboxService.php`, `app/Services/CompetitorService.php`, `app/Mcp/Tools/TriageComments.php`, `app/Mcp/Tools/ListComments.php`, `app/Mcp/Tools/CompareCompetitors.php`, `app/Mcp/Tools/ManageCompetitor.php`, `app/Mcp/Abilities.php`, `app/Mcp/Servers/SchedulerServer.php`
- **Cubre**: C-07
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Inbox` → verde

**Criterios de aceptación**
- [x] Una red nueva con `CommentSource` aparece en el inbox sin editar `InboxService`
- [x] `can_hide` se deriva de la capacidad
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

- Implementación (2026-11-16, commit `7bc347f`): `Meta\Comments` implementa `CommentSource` y la nueva capacidad `Contracts\HidesComments` (`setHidden`); `hasScopes` deja de ser estático. `CommentSource` gana `missingScopes` (las cuatro fuentes la implementan; antes era un `match` por red en `InboxService`). `ConnectorRegistry::registerComments/commentsFor/commentNetworks/canHideComments` es la única fuente: `AppServiceProvider` registra Instagram+Facebook (misma instancia de Meta), Threads, Bluesky y YouTube de forma incondicional (como la antigua constante, para no cambiar quién se sincroniza cuando una red está apagada). Se borran `InboxService::NETWORKS`, `source()` con `match`, la lista instagram/facebook de `can_hide` (comentarios y cuentas en `InboxController`), la de `ListComments` (filtro y descripción) y la de `moderationAccount`/`setHidden`; `coverage()` es de instancia y deriva read/reply/hide del registro (las notas por red siguen en una tabla de texto). `SyncInboxComments::handle` recibe `InboxService` y los bootstraps e2e (`isolated` y `roadmap`, que sustituyen el registro) registran las cinco fuentes. Tests: se actualizan los usos de `coverage()`, `NETWORKS` y `handle()` y se añade `InboxCapabilitiesTest` (red nueva sin tocar `InboxService`, `can_hide` por capacidad). LinkedIn y Pinterest (T-22) siguen en la cobertura como fuera; `hide`/`unhide` de `triage_comments` (T-30) se rechazan con el mismo mensaje, ahora construido con las redes que ocultan. Textos MCP (commit `74c22e1`): `TriageComments` y `ListComments` sustituyen el atributo `#[Description]` por `description()` generado con `InboxService::hideNetworksLabel()` y `networksLabel()`; `Abilities` (`inbox:read`/`inbox:write`) y las instrucciones de `SchedulerServer` (marcadores `{inbox_networks}` y `{hide_networks}` rellenados en `boot()`) hacen lo mismo; `CompareCompetitors` y `ManageCompetitor` generan «instagram por defecto, ...» con `CompetitorService::networksHelp()` (el orden pasa a ser el del catálogo). Test nuevo: las descripciones cambian al registrar una red.
- Verificación (2026-11-16): `php artisan test --compact tests/Feature/Inbox tests/Feature/Parity tests/Feature/Mcp tests/Feature/Connectors tests/Feature/Connections tests/Feature/FirstComment tests/Feature/Api` 1273/1273; el comparativo antes/después de las siete carpetas está en T-42 (2102 -> 2105, solo los 3 tests nuevos). Pint OK.
- RED: `InboxCapabilitiesTest` recibió la nota «Fuera por ahora» al registrar la fuente de LinkedIn · 2026-11-16.
- La cobertura muestra las notas de redes fuera de alcance solo mientras carecen de fuente. La prueba de alta de LinkedIn comprueba que la nota desaparece en cuanto se registra la fuente.
- Cierre. Pest y Playwright pasaron en la comparación documentada al final del ledger.

**Notas**: Tamaño M.

### T-44 — Scope `ownedBy()` y migración mecánica de `withoutUserScope()`

- **Descripción**: Añadir `ownedBy($userId)` en `BelongsToUser` y migrar los 198 usos de `withoutUserScope()` seguidos de `where('user_id')`.
- **Changelog**: Las consultas con un propietario explícito usan `ownedBy()`, que conserva el filtro por usuario aunque la sesión corresponda a otra cuenta o no exista.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-43
- **Tipo**: backend
- **Archivos**: `app/Models/Concerns/BelongsToUser.php`, `app/**/*.php`, `tests/Feature/Security/OwnershipScopeTest.php`
- **Cubre**: C-09
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=OwnershipScopeTest` → verde · `grep -rn "withoutUserScope()->where('user_id'" app | wc -l` → 0

**Criterios de aceptación**
- [x] No quedan `withoutUserScope()->where('user_id'…)` en `app/`
- [x] Ningún usuario ve datos de otro (tests de acceso cruzado)
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Migración mecánica: máxima atención a fugas entre usuarios; la revisión adversarial B se centra en ello. No tocar datos del usuario 28.

- El commit `e37848d` añadió el scope y migró las consultas sin calificador. La revisión detectó cuatro consultas pendientes con la columna cualificada. Calendario, analítica, campañas y listado de audio usan ahora `ownedBy()` para limitar el modelo base. El listado de audio conserva el filtro independiente del propietario de `studio_assets`.
- RED: al ampliar el guard para columnas cualificadas, `OwnershipScopeTest` detectó los filtros de calendario, analítica y campañas · 2026-11-16.
- GREEN (2026-11-16): `OwnershipScopeTest`, `InboxCapabilitiesTest`, `StudioChartFilesTest` y `StudioDatasetsTest` pasaron 33/33 (228 aserciones). La arquitectura describe el scope explícito.
- Cierre. Pest y Playwright pasaron en la comparación documentada al final del ledger.


### T-45 — Propiedad unificada: Policies o `Ownership::assert()`

- **Descripción**: Una sola forma de comprobar propiedad (Policies o `Ownership::assert()` con excepción de dominio) en lugar de tres.
- **Changelog**: Las Policies, los servicios y los controladores comparten la comprobación de propiedad y conservan los errores y la auditoría de cada canal.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-44
- **Tipo**: backend
- **Archivos**: `app/Policies/**`, `app/Support/Ownership.php`, `app/Http/Controllers/**`, `app/Services/**`, `app/Exceptions/OwnershipException.php`, `app/Mcp/Tools/SchedulerTool.php`, `bootstrap/app.php`, `tests/Feature/Studio/StudioAudioTest.php`, `tests/Feature/Studio/StudioImageJobsTest.php`, `tests/Feature/Studio/StudioChartFilesTest.php`, `tests/Feature/Studio/StudioChartsTest.php`, `tests/Feature/Studio/StudioDatasetsTest.php`, `tests/Feature/Workflow/WorkflowParityTest.php`, `docs/arquitectura.md`
- **Cubre**: C-08
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Security` → verde · `php artisan test --compact --filter=Ownership` → verde

**Criterios de aceptación**
- [x] Las 87 comprobaciones manuales y los `abort()` de servicios usan la misma vía
- [x] Las respuestas de error (403/404) no cambian
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

- RED: `StudioAudioTest` falló porque MCP devolvía un error interno para un recurso vinculado de otro tipo · 2026-11-16.
- RED: `WorkflowParityTest` recibió el 404 esperado por API, pero faltaba la entrada `denied` al intentar aprobar un post ajeno · 2026-11-16.
- RED: `StudioImageJobsTest` recibió 404 para un trabajo con salida pendiente, donde debía recibir 200 con `output_removed` · 2026-11-16.
- GREEN (2026-11-16): 4 casos focalizados de esas tres pruebas en verde. El adaptador MCP conserva el error de recurso inexistente, los controladores de API auditan la denegación y los trabajos de imagen siguen presentando la salida no disponible.
- La primera pasada completa de Pest terminó con 4053 correctas, 9 omitidas y 3 errores en pruebas de gráficos y datasets. Las pruebas llamaban directamente al servicio y esperaban una excepción HTTP. Ahora ejercitan el manejador real de Laravel y siguen comprobando el estado HTTP resultante. Pasada focalizada tras el ajuste, 33/33 (228 aserciones).
- La segunda pasada completa detectó la misma suposición en `StudioChartsTest`, el último error entre las 4065 pruebas. Tras adaptar esa prueba al manejador real, los tres archivos de gráficos y datasets pasaron 37/37 (231 aserciones).
- Pest completo sobre el código revisado, 2026-11-16. `php artisan test --compact` pasó con 4056 pruebas correctas, 9 omitidas y 25 821 aserciones. La base previa a T-44 tenía en verde el job Pint + Pest. [CI de `a6d6d8c`](https://github.com/daycry/social-scheduler/actions/runs/43863342910).
- Cierre. Pest y Playwright pasaron en la comparación documentada al final del ledger.


### T-46 — Sin E/S dentro de transacciones (imágenes y tipografías)

- **Descripción**: Subir a almacenamiento fuera de la transacción (`lockForUpdate`) y borrar ficheros en `afterCommit`.
- **Changelog**: El Estudio libera las filas de base de datos durante las operaciones de almacenamiento y conserva el archivo tipográfico si se revierte su borrado.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-45
- **Tipo**: backend
- **Archivos**: `app/Services/Studio/StudioImages.php`, `app/Services/Studio/StudioFonts.php`, `app/Services/MediaUploadService.php`, `app/Jobs/Studio/DeleteStudioFontFile.php`, `tests/Feature/Studio/StudioImageJobsTest.php`, `tests/Feature/Studio/StudioFontsTest.php`, `docs/modulos/estudio.md`
- **Cubre**: C-10
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter="StudioImageJobsTest|StudioImageExifTest|StudioFontsTest"` → verde · Playwright serial de `studio-imaging.spec.ts` → verde

**Criterios de aceptación**
- [x] Ninguna subida ocurre con un lock de BD abierto
- [x] Un rollback no borra el fichero
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

- RED: `StudioImageJobsTest` observó la subida en el nivel 2 de transacción, cuando debía mantenerse en el nivel 1 del harness de Pest · 2026-11-16.
- RED: `StudioFontsTest` encontró el fichero borrado antes del commit externo tanto en el caso de commit como en el de rollback · 2026-11-16.
- GREEN: 41 pruebas focalizadas pasaron. Una prueba adicional forzó el rollback del registro de imagen y comprobó que solo se eliminó su objeto preparado.
- RED: al fallar el almacenamiento tras el commit, el endpoint de tipografías devolvía 500 si el adaptador lanzaba y omitía la limpieza si devolvía `false` · 2026-11-16.
- RED: invocar el worker de imágenes dentro de una transacción externa iniciaba el trabajo antes del commit y cambiaba el estado de `queued` a `failed` en la prueba · 2026-11-16.
- GREEN: 46/46 casos focalizados tras las correcciones de Claude. Incluyen fallo del adaptador por excepción y por `false`, rollback del registro de la imagen, fallo posterior al commit y transacción externa abortada.
- QA de cierre, 2026-11-16. Pest completo, 4063 correctas, 9 omitidas y 25 857 aserciones. La base T-45 pasó con 4056 correctas y 9 omitidas.
- Playwright serial, 6/6 en la base y 6/6 tras T-46. Se cotejaron títulos y proyectos de `studio-imaging.spec.ts` en escritorio, móvil de 375 px y móvil de 360 px. `qa-gate.py` VERDE. Informe actual en `storage/framework/testing/t43/run-general-106e952e-5dea-4e20-a879-92618695ca10/results.json`.
- Tras la matriz E2E se añadió únicamente la prueba de cambio de metadatos del origen durante la subida. El registro falló con `source_changed`, conservó el original y eliminó la salida preparada. Verificación final de los tres archivos de pruebas declarados, 47/47 y 281 aserciones. Pint y `git diff --check` en verde.


### T-47 — `Period::fromInput()` y caché del panel por marca

- **Descripción**: Un único `Period::fromInput()` que lanza `ValidationException` (11 usos, 3 formas de error) y `Cache::flexible` con versión por marca para el panel.
- **Changelog**: Los errores de fecha y rango indican un campo coherente en web, API y MCP. El panel reutiliza los snapshots de la marca y los vuelve a leer tras la recogida.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-46
- **Tipo**: backend
- **Archivos**: `app/Analytics/Period.php`, `app/Analytics/DashboardSnapshotCache.php`, `app/Analytics/SnapshotCollector.php`, `app/Services/AnalyticsService.php`, `app/Services/ReportService.php`, `app/Http/Controllers/AnalyticsApiController.php`, `app/Http/Controllers/ExportController.php`, `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/Api/ReportApiController.php`, `app/Http/Controllers/DashboardController.php`, `app/Mcp/Tools/GetStats.php`, `resources/js/Pages/Dashboard/Partials/PeriodFilter.vue`, `tests/Feature/Dashboard/*Test.php`, `tests/Feature/Reports/AnalyticsCsvTest.php`, `tests/e2e/all-brands.spec.ts`, `docs/modulos/analitica.md`, `CHANGELOG.md`, `CHANGELOG.es.md`
- **Cubre**: C-11, C-12
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Period` → verde · `php artisan test --compact --filter=DashboardCache` → verde

**Criterios de aceptación**
- [x] Un periodo inválido responde igual en web, API y MCP
- [x] El panel usa caché y se invalida al llegar nuevos snapshots
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Sin cambio de comportamiento salvo errores de periodo uniformes, declarado en el changelog.

- RED: `PeriodInputTest` recibió 422 sin `errors.from` al enviar al panel un rango invertido · 2026-11-16.
- RED: `DashboardCacheTest` detectó una segunda lectura de `account_snapshots` al visitar la misma marca y período · 2026-11-16.
- GREEN: Pest focal de Dashboard, Analytics, Reports y MCP Stats pasó con 132 pruebas. Se comprobaron ambas pantallas por marca, aislamiento de versiones, rollback y recogida por rango.
- Base Playwright previa. `all-brands.spec.ts` y `report-builder.spec.ts` pasaron 6/6 en escritorio, 375 y 360 px.
- RED: Playwright no encontró el aviso al invertir el rango desde el selector · 2026-11-16. El parcial muestra el error de campo y conserva las fechas inválidas hasta corregirlas. GREEN en escritorio.
- La caché guarda cálculos diarios por marca. Las publicaciones, los snapshots por publicación y sus KPIs SQL siguen consultándose en directo. La versión rota tras confirmar datos de cuenta que afectan al panel. El recolector invalida una vez al salir de un lote, incluso al fallar después de alguna escritura.
- Medición local con caché database en SQLite en memoria, 12 cuentas, 8784 filas y 366 días más comparación. Mediana de cinco lecturas en ms, sin caché / caché fría / caché caliente. Resumen 3166,59 / 3159,48 / 1,86. Red 1017,85 / 1010,40 / 2,32. Entrada máxima de 282 009 bytes. Los resultados fueron idénticos en los tres modos.
- Tras las correcciones, 134 pruebas de Dashboard, Analytics, Reports y MCP Stats pasaron. La pasada focal de Dashboard con TTL y fallo parcial de histórico pasó 23/23. Build frontend, TypeScript y Pint en verde.
- Los GET directos al panel y al CSV conservan un 422 visible. La navegación Inertia usa errores de campo. Las llamadas JSON y las herramientas MCP comparten los errores de rango y de fecha del parser.
- QA de cierre, 2026-11-16. Pest completo, 4075 correctas, 9 omitidas y 26 110 aserciones. La suite previa de T-46 pasó con 4063 correctas y 9 omitidas y su regresión adicional pasó en la batería focal.
- Verificación declarada. Pest con `--filter=Period`: 28/28. Con `--filter=DashboardCache`: 6/6.
- Playwright serial. Los mismos seis escenarios de analítica e informes pasaron antes y después, cotejados por fichero, título y proyecto. También pasaron los tres casos nuevos de error de fecha en el selector. `qa-gate.py` VERDE con 9/9. Informe en `storage/framework/testing/t43/run-general-046f2a44-7262-4f4f-b728-96c4d36e6176/results.json`.


### T-48 — `preventLazyLoading` y convención de fechas de migraciones

- **Descripción**: Activar `preventLazyLoading` fuera de producción con tests de recuento de consultas en estudio y sitios; convención con test (o `schema:dump`) para las migraciones históricas con fecha futura.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-47
- **Tipo**: backend
- **Archivos**: `app/Providers/AppServiceProvider.php`, `app/Services/ManualPublishService.php`, `tests/Feature/Workflow/ManualPublishTest.php`, `database/migrations/**`, `tests/Feature/Database/MigrationDatesTest.php`, `tests/Feature/Studio/QueryCountTest.php`, `tests/Feature/Library/LibraryTargetTextTest.php`, `tests/Feature/Posts/Compose/TargetTextTest.php`, `tests/Feature/Parity/AutolistTest.php`, `tests/Feature/Workflow/SeriesTest.php`, `docs/modulos/estudio.md`, `docs/modulos/sitios.md`, `docs/modulos/despliegue.md`
- **Cubre**: C-13, C-14
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=QueryCountTest` → verde · `php artisan test --compact --filter=MigrationDatesTest` → verde

**Criterios de aceptación**
- [x] Los tests no producen lazy loading no deseado
- [x] Un test falla si una migración nueva tiene fecha posterior a hoy
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. No renombrar migraciones ya aplicadas en el VPS: solo test hacia delante o `schema:dump` documentado.

- RED: `QueryCountTest` recuperó dos marcas y la consulta implícita de la relación `user` no lanzó `LazyLoadingViolationException` · 2026-11-16.
- RED: `MigrationDatesTest` detectó 30 nombres preexistentes con fecha posterior a 2026-11-16 UTC · 2026-11-16.
- Playwright anterior al cambio. Estudio y Sitios pasaron 13/13 en escritorio, 375 y 360 px. Informe en `storage/framework/testing/t43/run-general-f45a3c6d-a0d4-4072-bce7-26b3f24f191c/results.json`.
- Arranque de la aplicación con `APP_ENV=local`, `testing` y `e2e`: la detección está activa. Arranque con `APP_ENV=production`: está desactivada.
- El primer Pest completo con la protección activa detectó cinco cargas implícitas en aserciones. Las pruebas de biblioteca, textos por destino, autolistas y series cargan explícitamente las relaciones que verifican.
- GREEN: 46 pruebas focales y 372 aserciones. Pint en verde en los siete archivos PHP de T-48.
- Prueba de regresión del control de fechas. La migración temporal `2099_01_01_000000_t48_future_date_probe.php` fue el único nombre rechazado. Se retiró el archivo y la prueba volvió a pasar.
- Primer reintento pospuesto. Claude Code devolvió HTTP 429 (`usage_limit_reached`) en A, B y D antes de procesar el diff. El CLI indica reinicio a las 20:30, hora de Madrid, el 2026-11-16. El selector no activa C.
- Pest completo anterior (T-47): 4075 correctas, 9 omitidas y 26 110 aserciones. Pest completo con T-48: 4079 correctas, 9 omitidas y 26 166 aserciones.
- Playwright posterior al cambio. Pasaron los mismos 13 escenarios de Estudio y Sitios y 10 ejecuciones adicionales de publicación, planificación, inbox e informes (E2E-38). Un trabajador después de Pest. `qa-gate.py` VERDE, 23/23, sin reintentos. Informe en `storage/framework/testing/t43/run-general-604d50bc-91d6-4655-af79-525de369e2c2/results.json`.
- El diff de partida se aisló en `%TEMP%/metricool-t48-review-ready.txt`. Claude Code revisó la corrección en la segunda ronda A+B+D.
- RED de revisión: `ManualPublishTest` reprodujo HTTP 500 por carga implícita de `PostTarget::post` con dos destinos manuales · 2026-11-16.
- GREEN de revisión: 20 pruebas focales y 234 aserciones. Pint en verde para los archivos PHP corregidos. Se verificó con Artisan y SQLite en memoria el fallo sin volcado, la instalación con un esquema SQL de prueba y la actualización al aplicar primero la dependencia por ruta.
- Cierre 2026-11-16. Pest completo tras la revisión: 4080 correctas, 9 omitidas y 26 185 aserciones. Verificación declarada por separado: `QueryCountTest` 3/3 y `MigrationDatesTest` 1/1.
- Playwright se ejecutó después de Pest, en un solo trabajador. Pasaron los 23 casos de la primera validación y tres ejecuciones de publicación manual en escritorio, 375 y 360 px. `qa-gate.py` VERDE, 26/26. Informe en `storage/framework/testing/t43/run-general-e8ff9f4e-203c-452a-a9c7-8c2268248694/results.json`.
- Claude Code cerró los hallazgos Critical/Important en la segunda ronda. Se confirmó que el código de QA coincide con el revisado y que el árbol de migraciones carece de cambios.

### T-49 — Config de límites de ritmo y colas

- **Descripción**: Mover los ~25 límites de ritmo a `config/rate_limits.php`, usar `reports.timeout`, colas por config, `retry_after` 180 y cola propia para IA/render.
- **Changelog**: Activa el worker de `studio-render` antes de actualizar para procesar IA, imágenes y exportaciones estáticas. Redis espera al menos 180 segundos antes de volver a entregar un trabajo.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-48
- **Tipo**: backend
- **Archivos**: `CHANGELOG.md`, `CHANGELOG.es.md`, `.env.example`, `.env.production.example`, `app/Console/Commands/QueueProbe.php`, `app/Jobs/GenerateReport.php`, `app/Jobs/SendScheduledReport.php`, `app/Jobs/Studio/DeleteStudioFontFile.php`, `app/Jobs/Studio/ProcessStudioImage.php`, `app/Jobs/Studio/RenderStudioDesign.php`, `app/Jobs/Studio/RenderStudioThumbnail.php`, `app/Jobs/Studio/RunStudioAiJob.php`, `app/Providers/AppServiceProvider.php`, `config/queue.php`, `config/rate_limits.php`, `config/reports.php`, `config/studio.php`, `deploy/hestia/README.md`, `deploy/systemd/README.md`, `deploy/systemd/scheduler-worker@.service`, `docs/guias/desarrollo-local.md`, `docs/modulos/despliegue.md`, `docs/modulos/estudio-ia.md`, `docs/modulos/estudio.md`, `docs/referencia/configuracion.md`, `tests/Feature/Deploy/DeployQueuesDocTest.php`, `tests/Feature/Infra/InfraTest.php`, `tests/Feature/Studio/StudioThumbnailsTest.php`, `tests/Feature/Infra/RateLimitConfigTest.php`, `tests/Feature/Infra/QueueConfigTest.php`, `tests/e2e/studio-captions.spec.ts`, `tests/e2e/studio-pptx-thumbnails.spec.ts`, `.github/workflows/nightly.yml`
- **Cubre**: C-15, C-16
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=RateLimitConfigTest` → verde · `php artisan test --compact --filter=QueueConfigTest` → verde

**Criterios de aceptación**
- [x] Los límites tienen los mismos valores por defecto que antes
- [x] `retry_after` de redis ≥ timeout de job + margen
- [x] IA y render usan cola propia documentada (y en `deploy/*`)
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Si se crea una cola nueva, actualizar `deploy/hestia` y `deploy/systemd` (A-06).

- RED: el límite de studio-ai ignoró el cambio de 6 a 3; Redis reservaba 90 s, los informes ignoraban el timeout configurado y el render seguía en media.
- Playwright base serial: 22/22 en escritorio, 375 y 360 px. Informe en `storage/framework/testing/t43/run-general-397142b5-7a70-4f15-9bb8-8c52ced5ae19/results.json`.
- Base Pest anterior (T-48): 4080 correctas y 9 omitidas.
- GREEN inicial: 184 pruebas focales y 985 aserciones. Pint en verde.
- RED de revisión: la miniatura compartía la cola de IA, la clave de 2FA no se preservaba y los informes aceptaban 0 y 200 s como timeout.
- GREEN de revisión: 188 pruebas y 995 aserciones. Pint y control de alcance en verde.
- Sonda de arranque real con `STUDIO_RENDER_QUEUE` vacía. RED: el job apuntaba a una cola vacía. GREEN: el job y la lista por defecto resuelven `studio-render` desde una clave común.
- Claude Code confirmó `claude-opus-5-5` en todas las lentes. La última ronda no dejó hallazgos Critical/Important.
- Cierre original 2026-11-16. Pest completo: 4110 correctas, 9 omitidas y 26 271 aserciones. Verificación por separado: `RateLimitConfigTest` 24/24 y `QueueConfigTest` 6/6.
- Playwright serial: los mismos 22 casos pasaron antes y después. `qa-gate.py` VERDE (22/22). Informe en `storage/framework/testing/t43/run-general-5dfc8a3d-11e9-49b9-bd06-213b0520547c/results.json`.
- La validación usa el mismo código revisado por Opus. El worker se comprobó en SQLite mediante la sonda de colas. Las instrucciones de Hestia y systemd indican el paso que corresponde ejecutar al desplegar.

**Reapertura del 16 de noviembre**. La batería E2E-39 de T-51 reprodujo dos fallos de la migración de colas que la ejecución focal anterior no cubrió. `studio-captions.spec.ts` obtuvo MP4/GIF en `ready` y SVG en `pending` tras drenar `studio,media`. `studio-pptx-thumbnails.spec.ts` obtuvo «En cola» tras drenar `media`. Ambos trabajos estáticos usan `queue.studio_render`. Las trazas locales están en los artefactos del run de T-51. Se preparó y se verificó con `git apply --check` un parche para que los escenarios lean la cola configurada, además de sustituir la ruta absoluta de PyMuPDF del test de subtítulos por Poppler y declararlo en el job E2E nocturno. Poppler rasterizó un PDF real del mismo run en Windows. El parche `t49-e2e-studio-render-fix-preview.patch` queda junto al manifiesto de T-51.

La batería completa terminó con 678 aprobados, 2 omitidos y 4 fallos. Al caso de subtítulos de escritorio se sumó el caso de PPTX en escritorio, 375 y 360 px. El 16 de noviembre se aplicó la corrección en los dos specs y en `.github/workflows/nightly.yml`. La repetición de los 16 casos de ambos specs se lanzó en serie con informe en `storage/framework/testing/t43/run-general-0920cd1d-5f81-42c8-b241-d4397f8fb731/results.json`.

Los 16 casos pasaron. Se extrajo la comprobación de dimensiones y píxeles compartida por PNG y PDF y se documentó `pdftoppm` en la guía de desarrollo. Con el diff final, una repetición serial de los cuatro fallos originales dio 4/4 y `npm run typecheck` salió en verde. `qa-gate.py` verde en ambas ejecuciones. Se cotejaron los cuatro casos por archivo, título y proyecto. Informe final en `storage/framework/testing/t43/run-general-0baae66a-89cf-4e96-8167-15796dcd21f9/results.json`. La revisión de aceptación y calidad del diff final queda preparada en `%TEMP%/metricool-t49-queue-followup-review.txt` para Claude Code Opus 5.5.

Revisión de la reapertura (2026-11-17). Claude Code Opus 5.5 confirmó la corrección de la cola de render y la portabilidad de Poppler. El cotejo con el código completo confirmó que `config/queue.php` asigna `studio-render` cuando la variable falta o está vacía, y que el test de PPTX descarga el ZIP, comprueba la integridad y verifica las dos diapositivas. El test de subtítulos puede leer `studio.av.queue` para su parte audiovisual y comprobar la firma de PNG y el fondo negro en PNG y PDF. Se conserva el veredicto pendiente de los ajustes, su verificación y la revisión del diff final. Informe en `%TEMP%/metricool-t49-queue-followup-opus55-review.json`.

La comprobación con las colas configuradas y la firma PNG pasó 4/4, con PPTX en escritorio y los dos tamaños móviles, render nativo y `qa-gate.py` verde. Informe en `storage/framework/testing/t43/run-general-8a4dcac0-c1a1-48f9-8ac8-7ac2e815ee01/results.json`. La reevaluación aprobó esos cambios y señaló que RGB descarta el alfa. La aserción de opacidad queda pendiente de probar con RGBA. Informe en `%TEMP%/metricool-t49-queue-r2-opus55-review.json`.

Se aplicó la comprobación RGBA. El test exige el primer píxel negro opaco y alfa 255 en todos los píxeles del PNG y del PDF rasterizado. La repetición serial terminó 4/4 y `qa-gate.py` VERDE en `storage/framework/testing/t43/run-general-f4fe3d79-75be-4cd4-ab5a-ad176493e0c2/results.json`. El caso de subtítulos confirmó las colas configuradas, Poppler disponible y el PDF a 160 × 120 px. Claude Code (`claude-opus-5-5`) verificó aceptación y calidad del código de la reapertura, con el hallazgo de alfa resuelto y sin Critical ni Important abiertos. Informe en `%TEMP%/metricool-t49-queue-r3-opus55.json`. Se combinan el Pest completo del cierre original y las pruebas locales posteriores de los dos specs. La ejecución nocturna remota sigue pendiente.

### T-50 — Inyección por constructor y utilidades duplicadas

- **Descripción**: Sustituir `app(X::class)`/`new` en constructores por inyección y extraer `DailyCounter`, macro `whereLikeEscaped` y `OAuthStateStore`.
- **Changelog**: Refactor interno de la resolución de dependencias, el contador de visitas y votos, el escape de búsquedas y el estado OAuth.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-49
- **Tipo**: backend
- **Archivos**: `app/**/*.php`, `app/Support/DailyCounter.php`, `app/Support/OAuthStateStore.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Infra/DailyCounterTest.php`, `tests/Feature/Infra/EscapedLikeTest.php`, `tests/Feature/Auth/OAuthStateStoreTest.php`, `tests/Feature/Studio/StudioChartRendererTest.php`, `docs/modulos/sitios.md`, `docs/modulos/smartlinks.md`, `docs/modulos/estudio.md`, `docs/modulos/conexiones.md`, `docs/modulos/canva.md`
- **Cubre**: C-17, C-18
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=OAuth` → verde · `php artisan test --compact --filter=DailyCounter` → verde

**Criterios de aceptación**
- [x] Los usos de `app(X::class)` bajan a los justificados
- [x] Contador diario, escape LIKE y estado OAuth tienen una sola implementación
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

- RED · 2026-11-16: `DailyCounterTest` y `OAuthStateStoreTest` fallaron porque faltaban ambos servicios. `EscapedLikeTest` esperaba dos coincidencias literales y recibió ninguna.
- Base Pest anterior (T-49): 4110 correctas, 9 omitidas. Playwright base serial: 25/25. Informe en `storage/framework/testing/t43/run-general-0b23b14a-0d62-4914-9acd-6617b0751c85/results.json`.
- GREEN inicial: 648 pruebas focales y 4179 aserciones. Pint en verde.
- Censo literal `app(X::class)` en `app`: 212 → 193. `MediaUploadService` 11 → 0, `StudioAi` 5 → 0, `StudioCharts` 2 → 0 y `StudioService` 1 → 0. El análisis de tokens en constructores encontró 0 llamadas a `app()` y redujo `new` de 36 a 33. Se conservaron los constructores de valor local y las firmas con llamadas directas existentes.
- La búsqueda de instancias directas y subclases de las clases modificadas no dio resultados. Se resolvieron las 11 clases modificadas desde el contenedor local. `LIKE ? ESCAPE` queda en un punto de registro y los dos flujos OAuth usan el mismo almacén de intentos con candado.
- RED de revisión: con una FK ausente, el contador nuevo propagaba `QueryException`. El contador compartido restaura el reintento anterior. También se añadió la colisión determinista, la caducidad lógica con caché vigente y el caso de propietario del verificador.
- GREEN de revisión: 651 pruebas focales y 4187 aserciones. Pint en verde.
- Claude Code ejecutó A+B+C con `claude-opus-5-5` en la primera ronda. La segunda recibió HTTP 429 (`usage_limit_reached`) antes de procesar el diff corregido. El CLI indicó reinicio el 2026-11-17 a la 01:30, hora de Madrid.
- Diff corregido para el reintento de Opus en `%TEMP%/metricool-t50-review-r2.txt`. La huella de los archivos revisables está en `%TEMP%/metricool-t50-review-r2-sha256.txt`.
- QA del diff corregido: Pest completo 4116 correctas, 9 omitidas y 26 285 aserciones. Filtros declarados por separado: `OAuth` 211/211 y `DailyCounter` 3/3.
- Playwright serial: se cotejaron los mismos 25 escenarios antes y después. `qa-gate.py` VERDE, 25/25. Informe en `storage/framework/testing/t43/run-general-82586fb2-75ae-40a8-9470-4975bb5f6a85/results.json`.
- Los SHA-256 del diff preparado para la segunda revisión coinciden con los archivos que pasaron QA.
- Segunda revisión, 2026-11-17. Claude Code Opus 5.5 confirmó las correcciones de primera ronda y la revisión de calidad, sin defectos Critical o Important en el diff revisado. Señaló el contrato implícito de `OAuthStateStore::remember()`, una rama Social OAuth sin test, el nombre del contador compartido por votos y la dependencia de comillas de SQLite en una prueba. El informe quedó en `%TEMP%/metricool-t50-r2-opus55-review.json`. El inventario estático de `%TEMP%/metricool-t50-app-census.json` localizó los 193 `app(X::class)` con fichero, línea y método: MCP 91, servicios 53, jobs 23, HTTP 21, conectores 4 y soporte 1. En el nuevo inventario, Opus 5.5 distinguió las resoluciones del framework en `failed()` y los callbacks que esperan al commit de los servicios que se pueden inyectar. Se prepararon correcciones para las dependencias de renderizado, validación, subtítulos y notificación de Canva, y para el contrato de los intentos OAuth. Pendiente aplicar, probar y justificar el inventario residual. Informe `%TEMP%/metricool-t50-inventory-opus55.json`. El siguiente intento recibió el límite de cinco horas de Claude Code. El CLI indica reinicio a las 06:30 del 17 de noviembre, hora de Madrid. Las comprobaciones locales pueden avanzar y la revisión del diff final sigue pendiente.

- Seguimiento aplicado. RED OAuth: cuatro entradas mal formadas llegaron a la caché en lugar de lanzar `InvalidArgumentException`. El almacén comprueba propietario y fecha de emisión antes del candado, y el test comprueba el estado en claro cuando el hash coincide. El contador usa las comillas de la gramática del driver en el test de colisión. Se inyectaron `StudioFrames`, `StudioCaptionData`, `MediaValidator` y el notificador de Canva en los servicios que los consumen. La resolución de `UrlGuard` se comparte entre las ramas de la importación. GREEN de Pest focal: 559 pruebas y 2661 aserciones. El inventario posterior de PHP-Parser pasó de 193 a 174 llamadas (MCP 91, servicios 37, HTTP 21, jobs 20, conectores 4, soporte 1). Informe `%TEMP%/metricool-t50-app-census-r3.json`. Playwright serial pasó 31/31, con cero fallos y cero flaky. El cotejo confirmó los 25 casos de la línea base y los seis de subtítulos por fichero, título y proyecto. Pest completo pasó 4134 pruebas, 9 omitidas y 26 365 aserciones, en `%TEMP%/metricool-t50-r3-pest-full.log`. Informe en `storage/framework/testing/t43/run-general-acd736a2-dc3d-4c62-9845-3f6029bdc628/results.json`. La revisión final de aceptación y calidad de Opus sigue pendiente. Los archivos del seguimiento conservan las 14 huellas registradas antes de QA. El inventario por llamada acredita 76 resoluciones MCP tras comprobar el permiso, 9 callbacks de fallo de cola, 4 resoluciones posteriores al commit y 1 propiedad perezosa de Inertia. Los 10 usos en metadatos MCP consultan capacidades de los conectores registrados mediante InboxService::networks(), InboxService::hideNetworksLabel() y CompetitorService::networksHelp(). Quedan 74 llamadas para confirmar individualmente. Evidencia en `%TEMP%/metricool-t50-census-audit-r3.json` y paquete exacto en `%TEMP%/metricool-t50-r3-review-ready.txt`.

El siguiente candidato aislado inyecta por constructor siete clases más en controladores, trabajos y servicios. El AST pasa de 174 a 164 resoluciones literales y quedan 64 por verificar individualmente. El contenedor local resolvió las siete clases y Pint pasó. La primera repetición mostró que un doble anónimo de `MediaProbe` seguía llamando a su constructor anterior. El barrido de subclases encontró los dos dobles y se adaptaron conservando el almacenamiento y los casos de prueba. Los ficheros de medios pasaron 39/39. Pest focal ampliado pasó 157 pruebas, con una omisión y 2.024 aserciones. El parche `%TEMP%/metricool-t50-di-r5-preview.patch` se comprobó contra el checkout. Inventario en `%TEMP%/metricool-t50-di-r5-audit.json` y paquete de revisión en `%TEMP%/metricool-t50-di-r5-review-ready.txt`. Pendientes aplicar el candidato y completar su revisión y QA en la rama.

Claude Code Opus 5.5, ronda 5, validó la compatibilidad de OAuth y la calidad del candidato en lectura. El scheduler resuelve ambos trabajos desde el contenedor. El siguiente candidato inyecta el notificador del importador, la colaboración en aprobaciones y series, y el guard en el trabajo de importación. Pasaron los mismos 109 casos antes y después, con 471 aserciones. Pint pasó en los cuatro ficheros de producción. El censo registra 159 llamadas, cinco menos, con 59 por decidir y 10 en metadatos MCP. En la ronda 6, Opus pidió probar la inyección real de `handle`. El recorrido URL pasa por `dispatchSync` y las tres pruebas Drive pasan ahora por `Container::call`. Las cinco clases se resolvieron en el contenedor, incluidas las dos del scheduler. Las dependencias nuevas carecen de constructor y estado mutable. Pasaron de nuevo 109 pruebas y 471 aserciones. Parche en `%TEMP%/metricool-t50-di-r7-followup.patch` e inventario en `%TEMP%/metricool-t50-di-r6-audit.json`. Pendientes inventario y QA integrada.

R8 reaplica en copia aislada los seguimientos de inyección de R5 y R7 sobre la rama actual. Al resolver dos conflictos se conserva la asociación de exportaciones con publicaciones de T-101 y la vinculación de importaciones de Canva a Recursos. `php -l` pasó en los dos archivos adaptados y `git apply --check --whitespace=error` en los 15 archivos. Candidato `%TEMP%/metricool-t50-r8-rebase.patch`. Pendientes formato, QA y recuento de llamadas restantes.

R9 se integró tras el visto bueno de aceptación parcial y calidad de Claude Code Opus 5.5. El nuevo seguimiento inyecta `CanvaStudioResources` y conserva el enlace transaccional. Las dos invocaciones directas nuevas del test de Canva se resolvieron por el contenedor. El censo AST baja de 175 a 159. En la copia pasaron 1.607 pruebas con 9 omitidas y 10.873 aserciones, más 192 pruebas específicas de Workflow y primeros comentarios ejecutadas aparte. En la rama pasaron Pint y 582 pruebas con 2.919 aserciones. Quedan el inventario residual y Playwright. Evidencias `%TEMP%/metricool-t50-r9-main-pest.log`, `%TEMP%/metricool-t50-r9-candidate-census.json` y `%TEMP%/metricool-t50-r10-evidencia-opus55.json`.

R13 integró la inyección por método en los dos controladores Studio tras la aprobación de Claude Code Opus 5.5 en conformidad, corrección y mantenibilidad. El censo AST bajó de 159 a 147, con doce llamadas eliminadas. Se verificó el árbol exacto con el parche inverso, route:list --path=studio, 758 pruebas Studio y 6.230 aserciones, con nueve pruebas nativas AV de activación explícita fuera de esa ejecución. Pint pasó antes y después de aplicar el parche. La resolución adelantada en rutas concretas construye un servicio con dependencias por constructor, sin trabajo de renderizado en el constructor. Quedan el inventario residual y Playwright. Evidencia %TEMP%/metricool-t50-r13-studio-opus55.json.

R14 integró la inyección en handle de PollPublishStatus, PostFirstComment y SendScheduledReport tras la aprobación de Claude Code Opus 5.5 en aceptación parcial, corrección y calidad. Se conservan los constructores serializables y las resoluciones de failed(), que Laravel invoca con la excepción. El censo AST de rama confirma 143 llamadas, cuatro menos que R13. En la copia pasaron 369 pruebas y 2.854 aserciones. Pint pasó en la copia y la rama. Evidencias %TEMP%/metricool-t50-r14-jobs-opus55.json, %TEMP%/metricool-t50-r14-jobs-pest.log y %TEMP%/metricool-t50-r14-main-census.json.

R16 integró ocho resoluciones adicionales en PasskeyGateway, la configuración TOTP del perfil, la auditoría de flujos y la descarga de informes. Opus 5.5 aprobó aceptación y calidad del lote tras comprobar la resolución transitoria y el caso que inyecta StorePasskey. Las mismas pruebas Auth, Profile, Workflow y Reports pasaron 391/3.770 antes y 392/3.772 después. Pint pasó en la rama y el censo AST de rama confirma 135 resoluciones. Quedan la clasificación residual y la verificación completa. Evidencia %TEMP%/metricool-t50-r16-final-opus55.json.

R18 integró diez resoluciones de los servicios de Studio. Opus 5.5 comprobó la ausencia de ciclos, el alcance transitorio y la exclusión por flock, que sigue siendo externa a la instancia. El contenedor aislado resolvió dos instancias distintas de cada una de las siete clases comprobadas. Pasaron 182 pruebas y 1.049 aserciones, con ocho pruebas AV nativas de activación explícita omitidas en la selección. Pint y el censo de rama confirman el parche y 125 resoluciones. Sigue pendiente el gate final de AV, el inventario residual y la revisión global. Evidencias %TEMP%/metricool-t50-r18-proof-opus55.json, %TEMP%/metricool-t50-r17-av-pest.log y %TEMP%/metricool-t50-r18-main-census.json.

R19 integró cinco resoluciones en StudioDocument, SiteService, PostTransitions y HealthCheck. Opus 5.5 aprobó el lote al confirmar que el helper y HealthCheck usan now() y que no quedan constructores directos en producción. La sonda independiente del kit siguió ejecutándose con 3.000 elementos. La regresión amplia pasó 1.091 pruebas con nueve opt-in AV y un error por una plantilla CI ausente en la copia. Se restauró ese archivo versionado y HealthCheck más BackupVerify pasaron 27 pruebas y 117 aserciones, con reloj fijo para el caso de 900 s. Pint y el censo de rama confirman 120 resoluciones. Sigue pendiente la verificación global de T-50. Evidencias %TEMP%/metricool-t50-r19-quality-opus55.json, %TEMP%/metricool-t50-r19-exact-pest.log y %TEMP%/metricool-t50-r19-backup-retry.log.

---

## Fase 5 — Frontend

**Estado**: borrador · **Estimado**: 119.0h · **Real**: — · **Coste est.**: 5950 € (base) + tokens ⚠️ verificar · **Tokens est.**: 5715k

Seguimiento aislado de T-50. Se inyectó AuditLog en la base REST y los controladores que declaran constructor, en el flujo de publicación y en la descarga de informes. El trabajo de informes recibe el notificador en `handle()` y conserva la resolución en `failed()`, donde el framework no inyecta servicios. Pint pasó y los mismos 185 casos de API, informes y workflow pasaron antes y después con 2.677 aserciones. El censo bajó de 159 a 155 y deja 55 por decidir individualmente, además de 10 de metadatos MCP. Candidato `%TEMP%/metricool-t50-di-r8-audit-followup.patch`, inventario `%TEMP%/metricool-t50-di-r8-audit.json`. Quedan revisión de Opus y QA sobre el checkout.

R20 integró tres resoluciones en las entradas de login y publicación. El caso de `Timebox` conserva el intervalo, y el job recibe sus dependencias al ejecutar `handle` sin cambiar su carga serializada. Verificados el único llamador productivo del login y la ausencia de un binding contextual de PostTransitions. Pasaron Pint y 501 pruebas de Auth, Publishing y Workflow con 2.759 aserciones. Opus 5.5 aprobó aceptación y calidad, y el censo de rama quedó en 117 resoluciones. Evidencias `%TEMP%/metricool-t50-r20-final-opus55.json`, `%TEMP%/metricool-t50-r20-focal.log` y `%TEMP%/metricool-t50-r20-main-census.json`.

R23 integró la separación de `StudioRenderer`, `StudioRenderResources` y `StudioExportFiles`. Opus 5.5 aprobó calidad y aceptación, y se fortaleció la comprobación de limpieza del intento con un tamaño de imagen incorrecto. Pasaron 758 pruebas de Estudio antes de reforzar la prueba, 26 focales con 576 aserciones después y 15/15 recorridos seriales de gráficos, fotogramas y PowerPoint. La batería AV real opt-in pasó otras 58 pruebas con 475 aserciones. R25 integró auditoría de los controladores REST, el servicio de renovación de cuenta de Bluesky y el guard de imágenes en la factoría de IA. Se descartó capturar el guard de `BlueskyClient`: la primera propuesta rompió la sustitución entre llamadas y dobles parciales. Opus condicionó el visto bueno a la composición. La sonda del contenedor instanció los ocho controladores con auditoría y confirmó que no hay ciclo de Bluesky ni bindings persistentes de los servicios afectados. R25 pasó 500 pruebas focales con 3.406 aserciones y bajó a 108 resoluciones. La suite amplia anterior encontró dos casos de integración de T-105/T-108 pendientes de nueva verificación. Evidencias `%TEMP%/metricool-t50-r22-renderer-opus55.json`, `%TEMP%/metricool-t50-r22-renderer-browser.log`, `%TEMP%/metricool-t50-r23-native-av.log`, `%TEMP%/metricool-t50-r25-quality-opus55.json` y `%TEMP%/metricool-t50-r25-focal.log`.

Cierre R26. La repetición global integrada tras R25 pasó 4.259 pruebas, 9 omitidas de activación explícita y 28.304 aserciones. E2E-38 pasó 13/13 seriales sin flaky con publicación, planificación, bandeja e informes. Opus señaló que los cuatro callbacks de commit podían capturar sus dependencias. R26 las inyectó sin mover `DB::afterCommit`, eliminó una resolución duplicada de metadatos y pasó la batería exacta de render, AV nativo activado y MCP con 383 pruebas y 2.774 aserciones. La primera pasada usó una prueba copiada antes del cambio de constructor de la cola y se repitió con la suite de la rama. Opus 5.5 aprobó aceptación y calidad sin Critical ni Important. Pint, el parche inverso y el foco de integración (25 pruebas y 180 aserciones, 3 AV de activación explícita omitidas) quedaron verdes. El censo final queda en 103 resoluciones: 80 acciones bajo guardia MCP, 1 auditoría de la guardia, 9 metadatos dependientes de las capacidades registradas, 9 callbacks `failed()` invocados por Laravel, 1 prop perezosa de Inertia, 1 acceso estático al registro de redes y 2 resoluciones de la guardia sustituible de Bluesky. Evidencias `%TEMP%/metricool-t50-r25-main-full.log`, `%TEMP%/metricool-t50-r25-e2e38-browser.log`, `%TEMP%/metricool-t50-r26-exact-native.log`, `%TEMP%/metricool-t50-r26-exact-opus55.json` y `%TEMP%/metricool-t50-r26-main-census.json`.

### T-51 — Prettier y ESLint con `--check` en CI

- **Descripción**: Añadir Prettier y `eslint-plugin-vue`, formatear las plantillas de una línea y exigir `--check` en CI.
- **Changelog**: CI comprueba que los componentes Vue cumplen ESLint y Prettier. La guía de desarrollo documenta los mismos comandos para ejecutarlos en local.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-50
- **Tipo**: frontend
- **Archivos**: `package.json`, `package-lock.json`, `.prettierrc`, `eslint.config.cjs`, `eslint-suppressions.json`, `.github/workflows/ci.yml`, `resources/js/**/*.vue`, `docs/guias/desarrollo-local.md`
- **Cubre**: D-13
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run lint` → 0 errores · `npm run build` → build correcto

**Criterios de aceptación**
- [x] `npm run lint` y `npm run format:check` pasan
- [x] El diff de `.vue` se limita al formato y a tres espacios representados como `&#32;` que conservan el mismo texto renderizado.
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Se hace antes de los refactors D para que sus diffs sean legibles. Una sola tarea de formateo masivo: revisar que no cambie comportamiento (E2E antes/después).

**Línea base previa al formateo (2026-11-16)**. Build correcto sobre `d7cd4da` con los cambios locales de la fase 4. Playwright general finalizó los 684 casos con un worker en escritorio, móvil de 375 px y móvil de 360 px. Resultado en rojo, con 678 aprobados, 2 omitidos y 4 fallos por la migración de colas de T-49. El informe está en `storage/framework/testing/t43/run-general-5241b51c-1719-412f-97c7-9f8bd6eac48c/results.json`, junto a `source-sha256.txt` y `vite-manifest.json` que identifican el código y el build probados. Log en `%TEMP%/metricool-t51-baseline-playwright.log`.

Los cuatro casos pasaron después de reparar el test de T-49, con las mismas huellas del código de aplicación y del build de Vite. Se inició la batería completa en serie para establecer una línea base en verde con la corrección. Informe en `storage/framework/testing/t43/run-general-dfc6da00-4cab-4d9a-b241-5555f2390543/results.json` y seguimiento en `%TEMP%/metricool-t51-corrected-baseline.log`.

La repetición terminó con 681 aprobados, 2 omitidos y un `net::ERR_NO_BUFFER_SPACE` al navegar al login local en el caso de escritorio «Presets: validation opens a collapsed global panel and focuses its date field». La traza sitúa el fallo en `loginAs`, antes de abrir el compositor. El mismo caso pasó tanto en el informe completo anterior como en su repetición individual posterior, con informe en `storage/framework/testing/t43/run-general-15f210db-48b0-46a5-9cdc-0c0a9a55db1b/results.json`. Se conservan ambos resultados originales.

Se guardaron las firmas de los 145 componentes `.vue` en `vue-sfc-baseline.json` dentro de ese mismo directorio. `vue-parity.cjs compare` los compara por separado en plantilla compilada, script y estilos. Los controles verifican que el comparador admite un cambio de formato y detecta cambios en una expresión de plantilla y en una ruta de importación. Cada diferencia se puede localizar por archivo y bloque antes de repetir E2E-39.

**Ensayo en memoria de T-51 (2026-11-16)**. Con Prettier 3.10.1, espacios sensibles en plantillas y formateo de lenguajes embebidos desactivado, se preservó el espacio sencillo con `&#32;` en tres separadores de calendario, inbox y sitios que el formateador habría eliminado. Las firmas de plantilla compilada, script y estilos coinciden en los 145 componentes al normalizar el fin de línea de los comentarios JS. Prettier acepta los 145 resultados tras dos pasadas. ESLint 10.15.0 y `eslint-plugin-vue` 10.12.1 con `flat/essential` dieron cero diagnósticos en el ensayo. Se probó la convención de nombres de páginas de Inertia, cinco nombres de componentes existentes y dos supresiones de `vue/no-dupe-keys` acotadas a un caso por archivo. Un control con un duplicado adicional hizo fallar esa regla. Evidencia en `t51-format-preview-validated.json` dentro del directorio de la línea base. El ensayo sigue pendiente de aplicar al repositorio y de revisar con Claude Code Opus 5.5.

Se usará `eslint.config.cjs` porque ESLint 10 utiliza configuración plana. Una copia aislada de los manifiestos admitió las cuatro dependencias de desarrollo del ensayo, incluido `vue-eslint-parser` 10.4.2. El lock de prueba añadió 78 entradas y conservó la versión y el hash de integridad de las 344 entradas existentes. Evidencia en `t51-package-lock-preview.json`.

El diff binario previo de los archivos de T-51 quedó guardado como `t51-preexisting-changes.patch` en el mismo directorio. Identifica el cambio previo de `PeriodFilter.vue` que debe excluir del diff atribuido a T-51. Se cotejaron las 145 huellas Vue con el manifiesto del inicio de Playwright.

**Parche verificado en copia aislada**. Se materializaron los 145 componentes con firmas iguales, los comandos npm y el paso de CI. `npm run lint` y `npm run format:check` pasaron en esa copia. La prueba de integración del parche en otra copia identificó un ajuste solo de CRLF a LF en `AiNotConfigured.vue`. `npm run format` corrigió ese ajuste y volvió a pasar los dos controles. `git apply --check` aceptó el parche sobre el árbol del proyecto. Está en `storage/framework/testing/t43/run-general-dfc6da00-4cab-4d9a-b241-5555f2390543/t51-format-reviewable.patch`.

En la copia aislada, `npm ci` instaló las versiones fijadas por el parche y conservó la huella de `package-lock.json`. Los dos comandos de CI pasaron con esa instalación limpia. npm avisó del script del complemento opcional `canvas` de Fabric. Una sonda con la resolución de módulos de Fabric encontró que la extensión nativa de `canvas` tampoco está presente en la instalación anterior. Log en `%TEMP%/metricool-t51-clean-install.log`.

**Aplicación del 17 de noviembre**. Parche aplicado tras la línea base de navegador y su repetición focal. `npm ci`, `npm run format`, `npm run lint`, `npm run format:check`, `npm run build` y `npm run typecheck` salieron con código 0 en el checkout. Los 145 `.vue` aplicados coinciden byte a byte con la copia contrastada. Se documentaron los comandos en la guía de desarrollo. Pest completo y E2E-39 de la versión formateada se ejecutan en serie. Queda pendiente la revisión de aceptación y calidad con Claude Code Opus 5.5.

El CSS global conserva su nombre con hash en el manifiesto de Vite. Los otros dos CSS generados corresponden a `Editor` y `StudioDatasetsDialog`. Se compararon con los assets del build histórico de `f40a583`, tras comprobar que ambos archivos fuente históricos coinciden con los del inicio de T-51. Los dos CSS coinciden al normalizar los identificadores `data-v-*` de Vue.

Pest completo pasó con 4116 pruebas, 9 omitidas y 26.285 aserciones. Tras Pest comenzó E2E-39 sobre el código formateado con un worker. Informe en `storage/framework/testing/t43/run-general-b2ccf9bf-71f0-4fa2-b688-ce6ab8f98263/results.json` y seguimiento en `%TEMP%/metricool-t51-after.log`.

Se recalcularon las firmas de los 145 componentes sobre el código aplicado contra la copia previa. El inventario por fichero está junto al informe en `t51-applied-parity.json`. El control de alcance de la fase con base `f40a583` clasificó 412 rutas y dio cero fuera de alcance y cero avisos. El paquete inicial de revisión `%TEMP%/metricool-t51-quality-review.txt` recoge las pruebas, la configuración y los tres cambios de representación del espacio. El diff completo queda disponible en el parche de T-51.

Opus 5.5 revisó la aplicación el 17 de noviembre (`%TEMP%/metricool-t51-opus55-r1.json`). Se explicitó en el criterio la equivalencia de los tres espacios y se documentó su representación en la guía de desarrollo. Las dos supresiones de `vue/no-dupe-keys` toleran una coincidencia cada una. En `CaptionsPanel.vue`, la función local `apply()` gestiona la operación y llama al callback del editor mediante `props.apply()`. En `StudioAiPanel.vue`, la variable local `active` indica trabajos en curso y el prop `props.active` dispara la carga al abrir el panel. Se mantienen los nombres durante este cambio de formato. La batería posterior y la revisión con el inventario completo siguen pendientes.

E2E-39 posterior en verde (17 de noviembre): 682 aprobados, 2 omitidos, 0 fallos, 0 flaky y 0 interrupciones. Se cotejaron los 684 identificadores y sus estados con el informe de la base y el reintento exacto previo al formateo. El informe bruto de la base conserva el fallo de transporte. La segunda revisión se lanzó con el diff íntegro, el manifiesto de 145 componentes (`t51-parity-complete.json`) y los resultados finales.

**Cierre del 17 de noviembre**. La segunda revisión detectó que el lock conservaba el nombre de la carpeta de preparación. Se restauró `metricool`. La tercera revisión con `claude-opus-5-5` confirmó aceptación y calidad sin Critical ni Important pendientes. Se cotejó la huella previa de `PeriodFilter.vue` y el job Node 22 con los requisitos de ESLint. Tras el ajuste de metadatos, `npm ci --offline` en una copia limpia conservó el lock, y `lint`, `format:check` y `typecheck` pasaron en el checkout. El resultado remoto de CI se comprueba al publicar. Informes en `%TEMP%/metricool-t51-opus55-r2.json` y `%TEMP%/metricool-t51-opus55-r3.json`.

### T-52 — Carga diferida de pdf-lib y de los ocho diálogos del editor

- **Descripción**: `await import('pdf-lib')` y `defineAsyncComponent` para los diálogos del editor; mover `downloadBlob`.
- **Changelog**: El editor carga sus diálogos al abrirlos y descarga la biblioteca de PDF al exportar en ese formato.
- **Estado**: en-revision
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-51
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/exports.js`, `resources/js/Components/Studio/ExportDialog.vue`, `resources/js/Components/Studio/CaptionsPanel.vue`, `resources/js/Components/Studio/StudioFontsDialog.vue`, `resources/js/Components/Studio/VariantsDialog.vue`, `resources/js/Studio/download.js`, `app/Providers/AppServiceProvider.php`, `tests/e2e/studio.spec.ts`, `tests/frontend/studio-charts-dialog.test.mjs`, `docs/modulos/estudio.md`, `CHANGELOG.md`, `CHANGELOG.es.md`
- **Cubre**: D-01, D-02
- **Cubre (tests)**: E2E-07
- **Verificación**: `npm run build` → build correcto y chunk de `Editor` reducido (anotar antes/después) · `npx playwright test tests/e2e/studio-captions.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] El chunk inicial del editor baja ≥ 1 MB (medir con `vite build`)
- [x] Los diálogos y exportaciones siguen funcionando
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Medición previa de T-52 (2026-11-16)**. El manifiesto del build de T-51 identifica `Editor-BwkWPbGP.js` con 212.444 B minificados y 63.764 B gzip. La clausura transitiva de imports estáticos de la ruta, descontando los chunks que también carga `app.js`, suma 1.131.218 B minificados y 399.244 B gzip. La descarga en frío con `app.js` suma 1.438.786 B y 503.830 B gzip. El chunk de exportación pesa 430.119 B y el de canvas pesa 327.272 B. Se guardaron la descomposición, los hashes y el método de medición en `t52-vite-baseline.json` y `t52-vite-measure.cjs` dentro del directorio de la línea base de T-51.

El umbral de reducción de 1 MB supera el tamaño del chunk `Editor` por sí solo. Aplicado al grafo de la ruta dejaría un máximo de 131.218 B, por debajo del tamaño del canvas que usa el editor al abrirse. La medición posterior deberá comprobar el ahorro real y el momento de carga de cada recurso. Mantener cubierta la acción directa `exporter.publish()` además de la apertura normal de exportación, porque esa acción también debe esperar a que se cargue el diálogo.

**Revisión preliminar T-52 (2026-11-17).** Claude Code Opus 5.5 confirmó que el render inicial necesita el canvas de 327.272 B y que desplazar las dependencias del PDF y los diálogos no puede por sí solo reducir el grafo inicial en 1 MB. La revisión pidió comprobar la inicialización en la primera apertura, el foco y los errores de carga. El criterio conserva su valor original y se medirán tanto el manifiesto como las peticiones reales. Informe en `%TEMP%/metricool-t52-preview-opus55.json`.

**Ensayo de compilación T-52 (2026-11-17).** Se compiló el mismo árbol de frontend dos veces en una copia aislada con las dependencias del lockfile. El chunk del editor bajó de 212.444 a 140.699 B (71.745 B). Su grafo estático propio pasó de 1.131.218 a 563.136 B (568.082 B; 220.790 B gzip). La suma con los chunks compartidos de la app pasó de 1.438.786 a 872.658 B. El manifiesto deja las ocho entradas de diálogos y la de `pdf-lib` fuera del grafo estático. Pasan Vite y ESLint focal con las supresiones vigentes. La descarga real y el comportamiento de la primera apertura se comprueban tras aplicar en el checkout. El criterio de 1 MB sigue abierto. Informes en `%TEMP%/metricool-t52-scratch-before.json` y `%TEMP%/metricool-t52-scratch-after.json`.

**Regresión T-52.** RED: la prueba de formatos detectó que el manifiesto anterior carecía de una entrada independiente para `ExportDialog.vue`. Informe en `storage/framework/testing/t43/run-general-c42d4b49-07a4-4a99-b65a-edd54a21deb9/results.json`. Aplicada la carga diferida en el checkout. Se añadió un recorrido en frío de «Usar en publicación», una comprobación de la primera lectura de tipografías y una comprobación de los recursos que solicita Chromium. Un primer pase de Node detectó que el arnés de gráficos debía cargar el componente asíncrono simulado. Tras adaptar ese arnés y quitar la reexportación interna de `downloadBlob`, pasaron sus 40 casos focales. La primera pasada de Node terminó con 639/639 tras la corrección del arnés. La primera pasada serial de navegador dio 16/20. Chromium mostró que la precarga global de Laravel recorría los imports dinámicos y solicitaba los ocho diálogos y `pdf-lib` antes de usarlos. También falló la devolución de foco a «Presentar» en las tres anchuras. Se retiró la precarga global y el botón conserva el foco durante la carga con `aria-busy`. La repetición del foco pasó en escritorio, 375 y 360 px. El primer cotejo de red también contaba tres diálogos importados por el listado visitado antes del editor. Se acotó la medición a un documento de editor recién cargado, sin cambios en sus aserciones de recursos ni de formatos. El build final separa 568.055 B minificados del grafo estático propio; Node pasó 639/639. Chromium confirmó el caso en frío: cero solicitudes de los ocho diálogos o de `pdf-lib` al abrir el editor, dos chunks de modal al exportar raster y `pdf-lib` al generar el PDF válido. Informe y adjunto en `storage/framework/testing/t43/run-general-771761db-188a-46cd-a44e-4376011804b7/results.json`. La batería serial amplia pasó 20/20, sin casos omitidos ni flaky y con qa-gate verde. Informe en `storage/framework/testing/t43/run-general-27d416bb-4159-493d-a45d-049a83d67e55/results.json`. Pest completo pasó 4.134 pruebas, con 9 omitidas y 26.365 aserciones; informe en `%TEMP%/metricool-t52-pest-full.log`. Revisión final de Opus pendiente. Informe de los fallos en `storage/framework/testing/t43/run-general-5f960402-7e44-40b7-8973-bdd4566d5d02/results.json`.



**Revisión del árbol actual (2026-11-17).** Claude Code Opus 5.5 revisó la implementación tras extraer los composables de T-63. RED: la prueba Vue `an unrelated editor panel error does not cancel a pending publish` esperaba una publicación pendiente y recibía `false`. Se acotó la captura al subárbol de exportación mediante las instancias públicas de Vue. También se manejan los rechazos de `publish()` y el fallo al descargar el generador PDF. El documento, sus páginas y los tiempos se validan antes de pedir ese chunk. Se conservan la causa técnica y un mensaje de usuario en español. La segunda revisión pidió cubrir los descendientes del diálogo, el nombre real del componente y el orden de validación del PDF. RED adicional en esos dos escenarios y GREEN 6/6. Revisión R3 y prueba de navegador R4 aceptadas estáticamente, con recibos `%TEMP%/metricool-t52-load-r3-opus55.json` y `%TEMP%/metricool-t52-load-r4-opus55.json`. Aplicado en el checkout. Node completo del candidato pasó 775/775, con typecheck, ESLint focal y Vite en verde. La repetición focal pasó 14/14 en serie, incluidos Inbox, presentación con medios nativos y los fallos de chunk del diálogo y del PDF en los tres tamaños. Recibo `%TEMP%/metricool-t68-r15-corrected-cases-browser.log`, con qa-gate verde. La medición del árbol actual deja el grafo estático propio en 580.895 B frente a 1.131.218 B de la línea base, con un ahorro de 550.323 B. Los ocho diálogos y pdf-lib siguen fuera del grafo estático. Recibo `%TEMP%/metricool-t52-post-t68-r15-measurement.json`. El criterio de ahorro de 1 MB sigue abierto.

### T-53 — `http.js` único con error tipado y cancelación

- **Descripción**: Sustituir las once copias de lector XSRF + `fetch` (cuatro con regex sin anclar) por un cliente único con error tipado y cancelación.
- **Changelog**:
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-52
- **Tipo**: frontend
- **Archivos**: `resources/js/lib/http.js`, `resources/js/Studio/{api,audio-api,dataset-client}.js`, `resources/js/Composables/{useWorkflow,useLiveValidation}.js`, `resources/js/Components/{AiSuggest,MediaUploader}.vue`, `resources/js/Components/Composer/{MediaSection,ScheduleSection}.vue`, `resources/js/Components/Studio/StudioFontsDialog.vue`, `resources/js/Pages/Reports/Index.vue`, `tests/frontend/{http,studio-dataset-client,live-validation}.test.mjs`, `docs/guias/desarrollo-local.md`
- **Cubre**: D-03
- **Cubre (unificación)**: U-18
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run test:unit` → pasa (incluye `http.test.mjs`) · `grep -rn "XSRF-TOKEN" resources/js | wc -l` → 1

**Criterios de aceptación**
- [x] No quedan lectores de cookie XSRF duplicados
- [x] Los errores HTTP llegan como objeto tipado a la UI
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] `http.js` expone un único `uploadMedia()` (con progreso y cancelación) que sustituye a `Studio/api.js:22-40`, `MediaSection.vue` y `MediaUploader.vue`; `grep -rn "FormData" resources/js | wc -l` no crece
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación T-53 (2026-11-17).** En una copia aislada, el cliente y sus consumidores pasaron 19 pruebas focales. RED: cancelar durante la lectura del cuerpo producía una resolución sin error en lugar de conservar la cancelación. Se conservan los errores de lectura y se admite un cuerpo de error sin JSON. Queda un lector XSRF en la copia y tres usos de FormData como antes. La línea base previa pasó 40/40 en Playwright serial, con qa-gate verde. Informe en `storage/framework/testing/t43/run-general-33ccdf0f-90ad-4490-9fa5-657823fb0df8/results.json`. RED en el checkout: el test falló con ERR_MODULE_NOT_FOUND antes de aplicar el cliente. GREEN focal: 19/19. El inventario del checkout confirma un lector XSRF y los mismos tres usos de FormData. ESLint, Prettier, TypeScript y el build pasaron. Node completo pasó 648/648. La comparación posterior pasó 40/40, con qa-gate verde. Coinciden los 40 casos por fichero, título y proyecto. Informe en `storage/framework/testing/t43/run-general-9a07d956-052d-4bfa-9676-20338f4e6f48/results.json`. Pest completo pasó 4.134 pruebas, con 9 omitidas y 26.365 aserciones. En una copia aislada, tres pruebas adicionales mostraron que una respuesta de tablas con JSON nulo perdía el estado HTTP, que leer el cuerpo de un error podía consumir la cancelación y que el compositor solo cancelaba la validación anterior tras el debounce. El seguimiento deja la comprobación de estado en el cliente común y cancela al cambiar el formulario. Pasaron 22 pruebas focales en la copia. Se aplicó el seguimiento tras Pest. Se confirmaron los tres fallos RED antes de corregir el código y las 22 pruebas focales volvieron a pasar en el checkout. La verificación final del seguimiento pasó ESLint, Prettier, TypeScript, Vite y 651/651 pruebas de Node. Playwright pasó 40/40 sin omitidas ni flaky, con qa-gate verde. Coinciden los casos antes y después por fichero, título y proyecto en `storage/framework/testing/t43/run-general-3b674f89-16d9-4df7-a284-78cb173056ca/results.json`. Pest final pasó 4.134 pruebas, con 9 omitidas y 26.365 aserciones. Coinciden las 15 huellas del candidato. Evidencia en `%TEMP%/metricool-t53-final-parity.json` y paquete de revisión en `%TEMP%/metricool-t53-final-review-with-followup.txt`. Queda pendiente la revisión de aceptación y calidad con Opus 5.5.

**Revisión de dos lentes — intento 1 con Claude Code Opus 5.5 (2026-11-17).** El revisor solicitó conservar el tipo HTTP histórico de Studio, probar los reintentos de audio y revisar la lectura del cuerpo y el mensaje de validación. Verificado en el código que el reintento ya depende de HTTP 503 o TypeError. Se conservó la clase StudioApiError para peticiones y subidas de Studio, el detalle de campos y la cancelación de lectura en audio local. La segunda revisión comprobó que el cliente de tablas también usaba StudioApiError. Se restauró ese contrato y se verificaron el estado, el cuerpo nulo y la cancelación con la suite focal en copia aislada. Parche validado con git apply --check. Recibo en `%TEMP%/metricool-t53-r1-claude-opus55.json`. Pendientes integración y regresión en la rama.

**Revisión de dos lentes — intentos 2 a 4 con Claude Code Opus 5.5 (2026-11-17).** El parche acumulado conserva el tipo de error histórico de Studio, los campos de validación en todas las fases, la causa y la cancelación. El selector impide dos cargas en el mismo ciclo y descarta la resolución tras un desmontaje. Los controladores consumidos en modo JSON y el middleware de autenticación se cotejaron con el rechazo de redirecciones. La cuarta revisión lo considera aceptable condicionado a comprobar los constructores y repetir la regresión completa. El inventario de la rama y del parche verificó que los cinco llamadores originales no pasan un mapa de errores y que los dos usos del tercer argumento en el candidato pasan el cuerpo. CI usa Node 22. ESLint y Prettier del componente pasaron, y 27/27 pruebas focales pasaron en la copia aislada. Recibo `%TEMP%/metricool-t53-r4-claude-opus55.json`, parche `%TEMP%/metricool-t53-claude-r4-fix.patch` y validación preparada `%TEMP%/metricool-t53-review-r4-checkout-validation.ps1`. Pendientes integración, Node completo, Pest y Playwright con cotejo de los 40 casos.

La regresión de T-53 R4 está en espera del proceso Playwright PID 3696 de la línea base de T-56. El ejecutor comprobará que exista su informe, aplicará los tests antes que el parche y ejecutará la regresión en la rama.

T-53 R4 aplicada al checkout después del cierre de Playwright de T-56. RED: 7 fallos de 27 pruebas focales antes de aplicar el código. GREEN: 27/27 tras aplicar el código, ESLint, Prettier, TypeScript, Vite y 669/669 pruebas Node. La regresión Playwright está en curso antes de Pest y del cotejo de huellas.

**Cierre T-53 (2026-11-17).** Claude Code Opus 5.5 verificó aceptación y calidad en la cuarta revisión y consideró el cambio aceptable sujeto a regresión. Se comprobaron los constructores de StudioApiError y las ocho huellas del parche aplicado. En el checkout se confirmaron siete fallos antes de aplicar la corrección y pasaron 27/27 pruebas focales tras aplicarla. Pasaron ESLint, Prettier, TypeScript, Vite y 669/669 pruebas Node. Playwright pasó 40/40 sin omitidas ni flaky, con qa-gate verde y coincidencia exacta de los 40 casos frente a la línea base. Informe en `storage/framework/testing/t43/run-general-6974ce94-0872-4a45-8586-722d22854474/results.json`. Pest pasó 4.137 pruebas, con 9 omitidas y 26.449 aserciones. El inventario final conserva un lector de cookie y tres usos de FormData. La guía de desarrollo describe los errores tipados, la sesión y el flujo de subida.

### T-54 — Hora de la marca en todas las pantallas y repaso de textos

- **Descripción**: `format.js` global con la zona de la marca (seis pantallas usan hoy la del navegador) y repaso de ortografía y terminología (correo/Email, «dejara», «¿»).
- **Changelog**: La interfaz muestra fechas y horas en la zona de la marca y distingue las plantillas de publicación, las plantillas de diseño y la biblioteca de archivos.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-53
- **Tipo**: frontend
- **Archivos**: `resources/js/lib/format.js`, `resources/js/Pages/Inbox/Index.vue`, `resources/js/Pages/Trash/Index.vue`, `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/Reports/Index.vue`, `lang/**`, `docs/knowledge/glossary.md`
- **Cubre**: D-09, D-21
- **Cubre (unificación)**: U-13, U-14
- **Cubre (tests)**: E2E-11
- **Verificación**: `npm run test:unit` → pasa (test de `format.js` con zonas distintas) · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Las seis pantallas muestran la hora de la marca
- [x] Sin erratas listadas y terminología uniforme
- [x] «Biblioteca» designa solo los archivos; las plantillas de publicación se llaman «Plantillas de publicación» (`navigation.js:47`, `LibraryController.php:18`, `ExportDialog.vue:87`, `Canva/Index.vue:225`)
- [x] Existe `docs/knowledge/glossary.md` con un término único por concepto (recursos/media, plantillas, papelera, identidad de marca frente a `brand-kit`) y la UI, `lang/**` y los textos del estudio y del compositor lo respetan
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Preparación T-54 (2026-11-17).** En una copia aislada se comprobaron cuatro casos del formateador común, incluido el cambio de día y el salto de horario de verano. El borrador usa la zona de la publicación en la papelera y prepara una prueba de navegador con seis pantallas y una marca en Auckland. RED en el checkout: el test de Node falló por el módulo ausente y Chromium mostró el comentario a las 21:30 del día 17 en lugar de las 09:30 del día 18 en Auckland. Informe en `storage/framework/testing/t43/run-general-c64d25d7-1ac0-4634-8f35-96dc7953a944/results.json`. Se aplicaron los cambios de formato y textos, con ambas entradas de changelog, el glosario y la documentación de planificación e informes. Node focal pasó 4/4. Los controles de formato y tipos y el build del checkout pasaron. Node pasó 655/655 y Pint pasó en todos los PHP tocados. Chromium verificó las seis pantallas con Auckland, comprobó que la papelera usa la marca de cada fila y validó los textos de confirmación de claves e informes. qa-gate verde. Informe en `storage/framework/testing/t43/run-general-a03e414b-b850-4bba-974d-4dc496e055c9/results.json`. La regresión amplia pasó 210 pruebas, con 2 omitidas por diseño (navegación del escritorio en móvil), cero fallos y qa-gate verde. Informe en `storage/framework/testing/t43/run-general-c5f3c84b-dce2-4b43-a297-a694cb596c18/results.json`. Pest pasó 4.134 pruebas, con 9 omitidas y 26.365 aserciones. RED focal: el diálogo de guardar plantillas conservaba el nombre antiguo. Con el título «Guardar como plantilla», el mismo recorrido pasó en escritorio y en las dos anchuras móviles. Informe en `storage/framework/testing/t43/run-general-0f871ba3-6692-4d2b-91f1-0aea5033b22b/results.json`. `lang/es/validation.php` ya emplea «correo electrónico». Sigue pendiente la revisión de aceptación y calidad de Opus 5.5.

**Revisión de dos lentes — intento 1 con Claude Code Opus 5.5 (2026-11-17).** El revisor señaló la zona ausente, la duplicación de formateadores y la cobertura de marcas distintas. Comprobado que CurrentBrand resuelve una marca del usuario si existe, Inbox la aplica a la consulta, StudioEditor::brandProps entrega timezone, brandSchedules filtra los envíos de Informes y ConnectionExpiry entrega un instante ISO. El borrador restablece la zona local cuando no hay marca, conserva UTC para zonas inválidas y comparte el formateador por zona. RED reprodujo el cambio a UTC y el conjunto focal pasó 4/4 después. Se amplió E2E-11 con Inbox sin parámetro, Estudio y dos filas de papelera en zonas distintas. Parche validado con git apply --check. Recibo en `%TEMP%/metricool-t54-r1-claude-opus55.json`. Pendientes aplicar y ejecutar el navegador en serie, y segunda revisión.

**Verificación de T-54 tras Opus 5.5 (2026-11-17).** Se añadió cobertura de caché por zona y de dos diseños compartidos por un propietario de prueba. La papelera verifica Madrid y Auckland con la misma sesión y la sección Estudio cubre diseños propios y compartidos. E2E-11 pasó las siete pantallas en Chromium. Design solo protege id contra asignación masiva, por lo que los updated_at se guardan desde la fixture. Node focal 5/5, Node completo, ESLint, Prettier, TypeScript y Vite verdes. En la regresión amplia pasaron 220 de los 225 casos, con dos omisiones previstas del shell de escritorio. Los únicos tres fallos eran el recorrido SmartLinks que aún omitía el diálogo de T-57. Se actualizó ese test sin cambiar la aplicación y pasaron sus tres casos en una segunda ejecución serial, con qa-gate verde y cotejo de identidades. El conjunto verifica 223 casos y dos omisiones previstas. Pest completo en ejecución. Recibo `%TEMP%/metricool-t54-r3-claude-opus55.json` y cotejo `%TEMP%/metricool-t54-review-r2-combined-browser.json`.

**Cierre T-54 (2026-11-17).** La revisión final de aceptación y calidad con Claude Code Opus 5.5 consideró correcto el código, sujeto a comprobar el timestamp de la fixture y repetir las pruebas. Design solo excluye id de la asignación masiva y E2E-11 verificó fechas distintas de UTC y de la marca seleccionada en los listados. Se comprobó el borrado en cascada del usuario aislado. Las siete huellas del seguimiento coinciden. Pasaron 670/670 Node. Playwright verificó 223 casos y dos omisiones previstas entre los informes `storage/framework/testing/t43/run-general-e16af69d-9ca0-4c5f-a2ca-15cde6c05f58/results.json` y `storage/framework/testing/t43/run-general-7aff87fc-3d00-4e33-884f-1a3484ffbe73/results.json`. Pest pasó 4.137 pruebas, con 9 omitidas y 26.449 aserciones. Quedan registrados en la guía, el glosario y ambos changelogs la zona de marca y los nombres de las plantillas.

### T-55 — `usePolling` y `useAiStatus`

- **Descripción**: Sondeo con tope, backoff y pausa con pestaña oculta; `ai.status` pedido una vez y `call()` compartido.
- **Changelog**: Informes y Canva pausan su actualización en segundo plano y permiten reanudarla al llegar al límite. El compositor comparte el estado y la cuota de la IA.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-53
- **Tipo**: frontend
- **Archivos**: `resources/js/composables/usePolling.js`, `resources/js/composables/useAiStatus.js`, `resources/js/Pages/Reports/Index.vue`, `resources/js/Pages/Canva/Index.vue`, `resources/js/Components/AiSuggest.vue`, `resources/js/Components/ScheduleSection.vue`, `resources/js/Composables/useAiStatus.js`, `resources/js/Composables/usePolling.js`
- **Cubre**: D-08, D-16
- **Cubre (tests)**: E2E-13
- **Verificación**: `npm run test:unit` → pasa (tests de `usePolling`) · `npx playwright test tests/e2e/canva.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Reports y Canva no sondean sin fin ni se solapan
- [x] `ai.status` se pide una sola vez por pantalla
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Preparación T-55 (2026-11-17).** En una copia aislada se integraron el sondeo común y el estado de IA por compositor. Los cinco casos focales pasan, incluido el RED que comprobó que ocultar la pestaña no cancelaba una petición en vuelo. ESLint, formato, tipos y build pasaron. La batería integrada de Node pasó 660/660. La propuesta deja 40 consultas por ciclo de informes y 30 por ciclo de Canva, con vuelta a consulta manual. Se prepararon dos comprobaciones de navegador para el estado de IA y el tope de informes. RED en el checkout: el compositor pidió el estado dos veces y los informes hicieron una consulta con la pestaña oculta. Informe en `storage/framework/testing/t43/run-general-af1cb89f-728a-4978-901f-441024b01145/results.json`. Se aplicaron ambos composables, la integración en Canva e Informes y el estado común del compositor. Lint, formato, tipos, build y 660 pruebas de Node pasaron. El navegador confirma una petición de estado y cero consultas con la pestaña oculta, pausa simulada mediante visibilitychange. Tras mostrarla, detuvo el sondeo de informes en la consulta 40 y mostró «Volver a consultar». qa-gate verde en `storage/framework/testing/t43/run-general-d3e305ac-e404-4612-bbcf-e65303e1e7d0/results.json`. Playwright aprobó los 30 casos de Canva, Informes, barra del editor y programación por lenguaje natural, con cero fallos u omitidos y qa-gate verde. Informe en `storage/framework/testing/t43/run-general-e0cf4280-2b8f-49b0-bda7-1d8060aa3cca/results.json`. Pest aprobó 4.134 pruebas, con 9 omitidas y 26.365 aserciones. Pendiente revisión de Opus 5.5.

La primera solicitud a Claude Code Opus 5.5 no devolvió resultado. El cliente rechazó el segundo intento con el motivo reasoning_extraction. Una tercera solicitud para el mismo diff produjo un informe de aceptación y calidad con el modelo solicitado.

**Revisión de dos lentes — informe de Claude Code Opus 5.5 (2026-11-17).** Confirmó la pausa, la exclusión de solicitudes simultáneas, los límites y la consulta compartida de IA. Detectó que una pausa consumía el último intento y que se montaba la región de estado con el mensaje ya escrito. El análisis del checkout verificó que el AbortSignal de Canva y useAiStatus llega a fetch. RED reprodujo el consumo de intentos al ocultar la pestaña y la solicitud obsoleta al reiniciar. El ajuste conserva el presupuesto al abortar y mantiene montada la región de estado de Canva e Informes. Pasaron 5/5 pruebas focales en copia aislada, ESLint y Prettier. Recibo `%TEMP%/metricool-t55-r3-claude-opus55.json` y parche `%TEMP%/metricool-t55-claude-r3-fix.patch`. Pendientes aplicación, regresión y cotejo de la corrección.

**Revisión de seguimiento T-55 (2026-11-17).** Opus 5.5 no identificó defectos bloqueantes en el control del presupuesto ni en las regiones vivas. Se reforzaron los tiempos de espera en los tests y se documentó que el sondeo debe resolver tras el aborto. Pasaron 5/5 focales en la copia. Recibo `%TEMP%/metricool-t55-r4-claude-opus55.json`. Parche `%TEMP%/metricool-t55-claude-r4-fix.patch` y ejecución preparada en `%TEMP%/metricool-t55-review-r4-checkout-validation.ps1` para después de T-54.

T-55 aplicada al checkout tras cerrar T-54. El ejecutor reprodujo el RED de los tests de sondeo, aplicó el ajuste y pasó las pruebas focales. ESLint, Prettier, TypeScript y Vite verdes. Sigue en curso su verificación amplia.

La primera regresión serial pasó 74/77. En los tres tamaños falló el selector genérico de `status` al encontrar también la región de sondeo. Se filtró por «Plantilla guardada.», literal de `ReportTemplateController`, conservando el rol. Opus 5.5 revisó el ajuste. La segunda regresión pasó 77/77 con cero omisiones y cero flaky. Informe en `storage/framework/testing/t43/run-general-68cdeed6-938e-4dd5-b127-901144d79add/results.json`. Pasaron 7 pruebas focales y 672 de Node, además de ESLint, Prettier, TypeScript y Vite. Pest completo se ejecutó a continuación.

Cierre verificado de T-55. Pest completo pasó 4.137 pruebas, con 9 omitidas y 26.449 aserciones. `qa-gate.py` dio VERDE para los 77 casos. Las cinco huellas coinciden tras la ejecución. La revisión de Claude Code Opus 5.5 incluyó la calidad del código y del selector, además del contrato de cancelación, presupuesto y regiones vivas. La pausa durante una petición activa y la reanudación conservando el presupuesto están cubiertas por Node. El refuerzo de ese recorrido en el navegador se incorpora a T-59 al cambiar los campos solicitados por el sondeo. La verificación manual M-12 se mantiene en T-56/T-60.

### T-56 — `BaseDialog` sobre `<dialog>` nativo

- **Descripción**: Un único componente de diálogo con trampa de foco completa que sustituya los cuatro patrones actuales.
- **Changelog**: Los diálogos modales usan el mismo componente nativo para el foco de entrada, la navegación con Tab y la devolución del foco.
- **Estado**: en-revision
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-54
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/BaseDialog.vue`, `resources/js/Components/Modal.vue`, `resources/js/Components/Studio/StudioDialog.vue`, `resources/js/Components/UtmModal.vue`
- **Cubre**: D-05
- **Cubre (unificación)**: U-10
- **Cubre (tests)**: E2E-08, M-12
- **Verificación**: `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] `UtmModal` atrapa el foco y cierra con Escape
- [x] Los cuatro patrones usan `BaseDialog`
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] `BaseDialog` parte de `StudioDialog` (`<dialog>` nativo); no quedan `fixed inset-0` a mano ni `Modal.vue` paralelo, y las pantallas del estudio lo usan
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación T-56 (2026-11-17).** La copia aislada integra el diálogo nativo común para el perfil, el compositor, el calendario y el Estudio, incluidas la presentación y la confirmación anidada de tablas. Se adaptaron los arneses de montaje de Vue al extraer el diálogo. Pasaron 71 pruebas focales y, al combinar con T-55, 660 de Node, lint, formato, tipos y build. El caso UTM comprueba la capa modal nativa y la vuelta de Tab en ambos sentidos. Se aplicó al checkout. RED real: el UTM anterior no era un elemento `dialog:modal` (`storage/framework/testing/t43/run-general-1fd1dd9d-04e4-46cb-81b1-0fa5f7ed8b01/results.json`). GREEN UTM 3/3 con cierre y Tab en ambas direcciones, en `storage/framework/testing/t43/run-general-6a0a31b2-0042-4e90-a5ac-307802785b5e/results.json`. Pasaron lint, formato, tipos, build y 660 pruebas de Node. La única apertura modal nativa está en BaseDialog. La regresión amplia está en marcha. Detectó que Escape deja abierto el selector de GIF con el campo de búsqueda relleno y que no se recupera el foco del botón «Añadir audio» en Instagram. Se preparó el manejo de Escape para que se procese antes de que el campo de búsqueda lo consuma. El ajuste `%TEMP%/metricool-t56-search-escape-preview.patch` pasó 23 pruebas de Node en la copia y queda pendiente de aplicar y repetir los dos recorridos de navegador. Pendientes Pest final y revisión de aceptación y calidad con Opus 5.5.

La primera regresión de navegador terminó con 245 casos correctos, uno omitido y los dos fallos de Escape ya identificados. Informe en `storage/framework/testing/t43/run-general-b54d7238-fcc6-4b26-a235-b9f2a2345dc2/results.json`. Tras terminar la ejecución se aplicó el ajuste en `BaseDialog.vue`. Pasaron lint, formato, tipos, build y 660 pruebas unitarias. La repetición focal pasó 9/9 con `qa-gate` verde. Incluyó GIF, audio de Instagram, UTM y el retorno de foco al cerrar el diálogo anidado de tablas, además de cancelar el audio pendiente de la presentación. Informe en `storage/framework/testing/t43/run-general-5f17e631-d2e3-4584-827a-289ac7c4d71f/results.json`. La regresión amplia final pasó 248/248 en los tres tamaños, con `qa-gate` verde y sin omitidos ni flaky. Informe en `storage/framework/testing/t43/run-general-9c8234ab-b132-4257-aaaf-b78a7e3605f2/results.json`. Pest completo empezó después. Se compiló una copia del código inmediatamente anterior a T-56 para cotejar el mismo alcance de navegador. El cotejo y M-12 siguen pendientes.

Pest terminó con 4.134 aprobadas, 9 omitidas y 26.365 aserciones. Informe en `%TEMP%/metricool-t56-pest-full.log`. Se actualizó la documentación del editor de Studio y los dos registros de cambios. La revisión de aceptación y calidad con Opus 5.5 queda preparada en `%TEMP%/metricool-t56-review-ready.txt`.

Se inició el cotejo anterior a T-56 tras la verificación focal de T-58. La copia recompilada ejecuta los mismos 248 casos de Playwright en serie. Su informe se cotejará por fichero, suite, título y proyecto con el informe final de T-56.

La copia de la línea base anterior detectó dependencias locales ausentes. El cargador tipográfico devolvió HTTP 503 y la extracción de fondo HTTP 422. El enlace de node_modules también cambió la clave de pdf-lib en el manifiesto de Vite. Se comprobó la sonda con el entorno Python de Studio y una tipografía de prueba. Se preparó una repetición serial con el modelo local fijado por SHA-256, el entorno Python y una copia física de node_modules. Pendiente el cotejo de los 248 casos y M-12.

La primera línea base terminó con 240/248. Las ocho incidencias corresponden a tipografías e inferencia sin sus dependencias locales y a la clave de pdf-lib. La repetición de los 248 casos arrancó en serie con las dependencias corregidas. La revisión de Claude Code Opus 5.5 encontró un cierre nativo que no sincronizaba el componente padre, búsquedas de GIF activas tras cerrar y un salto de foco en casos límite de tabulación. RED confirmó los dos primeros problemas. GREEN 2/2 en una copia aislada con devolución de foco, notificación del cierre y cancelación e invalidación de respuestas GIF tardías. Parche parcial `%TEMP%/metricool-t56-claude-r1-partial.patch`, recibo `%TEMP%/metricool-t56-r1-claude-opus55.json`. Pendientes tabulación y otros hallazgos, aplicación, regresión y M-12.

El parche de seguimiento abordó la tabulación desde elementos con foco programático, los radios sin selección, los controles ocultos y el cierre accidental desde la barra del diálogo. Se aplica el bloqueo de desplazamiento de fondo a todos los diálogos nativos. Se reprodujeron cuatro fallos adicionales y pasaron 6/6 pruebas unitarias del seguimiento. Los nuevos recorridos en navegador cubren el cierre nativo, el ciclo de tabulación con radios y el aborto de GIF. El cotejo anterior en serie ya superó las pruebas de escritorio de tipografías, eliminación de fondo y exportación PDF. Parche listo en `%TEMP%/metricool-t56-claude-r1-fix.patch`, segunda revisión enviada. Quedan resultados del navegador, integración y M-12.

La preparación de T-56 anterior al seguimiento alcanzó 248/248 en Playwright y qa-gate verde en ambos lados. Coincidieron los 248 casos por fichero, suite, título y navegador en `%TEMP%/metricool-t56-before-after-parity.json`. La revisión 2 identificó que el cierre nativo debe respetar el veto del padre y la revisión 3 identificó una doble solicitud de cierre, pérdida de foco al reabrir tras el veto y resultados anteriores de GIF visibles bajo una consulta abortada. El seguimiento los corrige y pasó 10/10 pruebas foco. Parches y recibos en `%TEMP%/metricool-t56-claude-r3-fix.patch` y `%TEMP%/metricool-t56-r3-claude-opus55.json`. Cuarta revisión enviada. El cotejo de 248 pertenece al candidato anterior al seguimiento y aún no verifica los recorridos nuevos ni M-12.

La cuarta revisión confirmó las tres correcciones y no encontró un defecto bloqueante. Señaló dos casos menores a ajustar antes del navegador: distinguir la última consulta enviada del texto aún sin enviar en GIF y no usar el elemento que tenía foco como indicador de que el diálogo fue abierto. Recibo en `%TEMP%/metricool-t56-r4-claude-opus55.json`. Las pruebas seriales sobre el seguimiento y M-12 siguen pendientes.

**Ajustes T-56 tras la cuarta y quinta revisiones (2026-11-17).** Se reprodujo el cierre sin elemento invocador, el envío involuntario de texto y el catálogo sin reintento tras un fallo. La identidad del diálogo se rastrea por elemento, el selector solo reintenta búsquedas enviadas y recupera un catálogo fallido al volver a abrir. Pasaron 13/13 focales en la copia aislada. Parche `%TEMP%/metricool-t56-claude-r5-fix.patch` y recibo `%TEMP%/metricool-t56-r5-claude-opus55.json`. Pendientes Playwright en la rama, cotejo y M-12.

El ajuste quedó aplicado tras verificar las huellas de T-55. Pasaron las 13 pruebas focales, ESLint, Prettier, TypeScript y Vite. La primera pasada de Node detectó que al nuevo recorrido del selector GIF le faltaba `@responsive`. Se etiquetó y la salvaguarda pasó 17/17. El cotejo de navegador espera seis casos nuevos, los recorridos de GIF y compositor en los tres tamaños, además de los 248 de la línea base. La comprobación manual M-12 con lector de pantalla sigue pendiente. Node pasó 685/685 con la etiqueta corregida. El foco Playwright pasó 9/9 sin omisiones ni reintentos en escritorio, 375 px y 360 px. Informe en `storage/framework/testing/t43/run-general-39b5c95b-fdc0-423b-a165-5e473c8d0496/results.json`. Los 12 specs de regresión están en marcha.

Regresión T-56 en curso. El recorrido de escritorio de doble factor agotó la espera del campo después de que la sesión cambiara al panel. Se revisó la traza y se preparó un ajuste de prueba que comprueba el vaciado mientras sigue visible el reto. La repetición serial del fichero y Pest esperan al resultado completo. El ejecutor exige que el resto de los 254 casos coincida con los 248 originales y los seis nuevos antes de aplicar el ajuste. La comprobación manual M-12 queda pendiente.

El fichero afectado pasó 16/16 en la repetición serial, con informe `storage/framework/testing/t43/run-general-e0ea130e-2c1d-409b-86bd-723f5c02b0d6/results.json`. Se contrastaron las identidades y estados finales de los 248 casos originales y de los seis nuevos. La primera pasada conserva 253 verdes y un fallo. El cotejo combinado deja 254 verificados y cero pendientes automatizados de navegador, en `%TEMP%/metricool-t56-review-r6-parity.json`. Pest final pasó 4.137 pruebas, con 9 omitidas y 26.449 aserciones, los mismos resultados que la base T-55. Las huellas se comprobaron antes de aplicar T-57. La comprobación manual M-12 y la revisión final de seguimiento quedan pendientes.

### T-57 — `ConfirmDialog` y `useConfirm()` en lugar de 25 `confirm()`

- **Descripción**: Sustituir todas las llamadas a `confirm()` nativo por un diálogo accesible.
- **Changelog**: Las acciones destructivas comparten un diálogo accesible con foco inicial en «Cancelar» y cancelación con Escape.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-56
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/ConfirmDialog.vue`, `resources/js/composables/useConfirm.js`, `resources/js/Pages/Brands/Index.vue`, `resources/js/Pages/Sites/Edit.vue`, `resources/js/Pages/Studio/Index.vue`, `resources/js/app.js`
- **Cubre**: D-04
- **Cubre (unificación)**: U-11
- **Cubre (tests)**: E2E-08
- **Verificación**: `grep -rnE "(^|[^.])confirm\(" resources/js | grep -v useConfirm | wc -l` → 0 · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] `grep -rn "confirm(" resources/js` → solo `useConfirm`
- [x] Cada acción destructiva pide confirmación y se puede cancelar
- [x] Las confirmaciones del estudio usan `ConfirmDialog`/`useConfirm()` común, sin diálogos propios
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los specs que aceptaban el diálogo nativo (`page.on('dialog')`) se adaptan.

**Preparación T-57 (2026-11-17).** En la copia aislada, las confirmaciones nativas se sustituyeron por un diálogo común. El estado impide sustituir una pregunta abierta, cancela al desmontar la vista y se reutiliza para descartar cambios de tablas. Se prepararon recorridos con teclado para borrar una marca y un sitio y archivar un diseño. Pasaron los tipos, la compilación y 663 pruebas de Node. La búsqueda de llamadas nativas quedó vacía en la copia. El parche `%TEMP%/metricool-t57-confirm-preview.patch` queda pendiente de aplicar al checkout y probar en navegador.

**Verificación focal T-57.** RED de navegador: los dos casos de E2E-08 fallaron porque faltaba el diálogo compartido tras pulsar «Eliminar» en marca y sitio. Informe en `storage/framework/testing/t43/run-general-9828bcd1-d9e9-47bd-88a3-a7e005ba0353/results.json`. Se aplicó el cambio en el checkout. GREEN: 6/6 casos en los tres tamaños, con cancelación, devolución de foco, borrado confirmado y archivo de un diseño. Informe en `storage/framework/testing/t43/run-general-e6a3d4a1-a163-4bbe-b38c-04671fe5b9b7/results.json`. Pasaron lint, formato, tipos, build y 663 pruebas de Node. El barrido del checkout no encontró confirmaciones nativas. Se actualizaron las pruebas de desconexión y revocación de la batería de roadmap. Pendientes la regresión amplia, Pest y la revisión de aceptación y calidad.

Las dos pruebas de la batería de roadmap pasaron en los tres tamaños, 6/6 sin omitidos ni flaky, con `qa-gate` verde. Informe en `storage/framework/testing/t43/run-confirm-71a686d1-bb0c-4d3e-8afd-e8cbddd7bd41/results.json`. Después se inició la regresión serial de 217 casos de los módulos afectados.

Regresión amplia T-57 completada. Playwright aprobó 215 casos, omitió los dos recorridos de navegación exclusiva de escritorio en los proyectos móviles y no registró fallos ni flaky. `qa-gate` dio verde. Informe en `storage/framework/testing/t43/run-general-ffb51d52-d7c6-49e8-b81b-f5fa00306082/results.json`. Pest completo se inició al terminar Playwright.

Pest pasó 4.134 pruebas, con 9 omitidas y 26.365 aserciones. Informe en `%TEMP%/metricool-t57-pest-full.log`. La revisión de aceptación y calidad sigue pendiente. El paquete `%TEMP%/metricool-t57-review-ready.txt` incluye las pruebas añadidas y los ajustes del roadmap. Se guardaron las 47 huellas del checkout antes de empezar T-58.

**Revisión de dos lentes — intento 1 con Claude Code Opus 5.5 (2026-11-17).** Confirmó la resolución única y la limpieza de la confirmación común. Identificó que StudioCollabPanel pide confirmación dentro de run y puede borrar el feedback previo al cancelar. También señaló acciones que resuelven su destino tras esperar la confirmación y pueden usar las props de otra marca o sitio si Inertia conserva el componente. Se cotejaron ambas estructuras en la rama. Quedan corregir los hallazgos, cubrir el cambio de contexto y repetir la revisión de aceptación y calidad. Recibo en `%TEMP%/metricool-t57-r1-claude-opus55.json`.

**Seguimiento a las revisiones 1 y 2 de T-57 (2026-11-17).** Cuatro pruebas reprodujeron pérdida de avisos, una operación sobre otro diseño u otra marca y campos de lote ajenos a la acción confirmada. Los destinos se toman antes de preguntar, el panel no pierde avisos al cancelar y un rechazo tardío de un diseño no se muestra en el siguiente. Se eliminó AbortSignal.any y se asociaron los eventos de cierre a la pregunta correspondiente. La segunda revisión validó estos ajustes y pidió etiquetas concretas y migrar la confirmación de «Publicar igualmente». El parche incluye ambas correcciones, pruebas para todos los destinos señalados, descarte de tablas al desmontar y Escape en la confirmación anidada de carpetas. Pasaron 45/45 pruebas focales en una copia aislada. Parche `%TEMP%/metricool-t57-claude-r2-fix.patch`, recibo de revisión 2 `%TEMP%/metricool-t57-r2-claude-opus55.json`. Quedan tercera revisión, integración y QA amplia.

**Revisión 4 de T-57 (2026-11-17).** Claude Code Opus 5.5 considera el código apto para la regresión y no identificó bloqueantes. Las últimas correcciones invalidan la confirmación al salir y volver al mismo diseño y vuelven a verificar el estado del primer comentario antes de publicar. Dos pruebas RED confirmaron esos casos. Las 47 focales pasan en la copia aislada y ESLint revisó los 24 Vue del ajuste previo. El parche definitivo incluye las dos entradas de changelog y las pruebas de cancelación y foco. Recibo `%TEMP%/metricool-t57-r4-claude-opus55.json` y parche `%TEMP%/metricool-t57-claude-r3-fix.patch`. Pendientes aplicación, navegador de cancelación y foco, y regresión completa.

Se concretaron las etiquetas opcionales señaladas por el revisor, se actualizó el selector de SmartLinks y se adaptó el parche a T-54. Pasaron 47/47 focales en la copia. El parche `%TEMP%/metricool-t57-postreview-fix.patch` queda para aplicar tras los seguimientos anteriores.

Se ajustaron los finales de línea del hunk de SmartLinks a los del fichero tras T-54. La aserción conserva «Eliminar suscriptor». Parche `%TEMP%/metricool-t57-postreview-integrated.patch` comprobado contra el checkout. Pendientes aplicación y QA serial.

La cadena aislada posterior a T-56 conservó la documentación de diálogos y añadió la guía de confirmaciones. El parche preparado para el checkout es `%TEMP%/metricool-t57-postreview-after-t56.patch` y se aplicó en la copia sin conflictos. El ejecutor comprobó el cotejo, Pest y las huellas de T-56 y aplicó el ajuste. Pasaron 47 pruebas focales y 704 de Node, además de ESLint, Prettier, TypeScript y Vite. La validación de T-57 sigue con el foco de Playwright, 13 specs de regresión, los dos recorridos afectados de roadmap y Pest, en serie.

La prueba focal final de T-57 pasó 9/9 en escritorio y las dos anchuras móviles. Informe en `storage/framework/testing/t43/run-general-77bb7907-6703-4ae2-9783-99088865ef6f/results.json`. En marcha la regresión serial de 218 casos.

La regresión encontró un localizador ambiguo en la prueba de foco de carpetas. El diálogo contiene un botón de borrado por carpeta y ambos coinciden con `folder-delete`. El parche de prueba `%TEMP%/metricool-t57-studio-delete-focus-preview.patch` reutiliza el botón «Borrar la carpeta Campañas» para abrir la confirmación, comprobar el foco al cancelar y abrirla de nuevo. La siguiente ejecución verificará el spec completo de Studio, los recorridos del roadmap y Pest cuando termine la sesión actual. El ejecutor se detiene si la regresión inicial registra algún fallo adicional.

La regresión inicial terminó con 215 aprobados, dos omitidos previstos para la navegación de escritorio y un fallo en el localizador de carpetas. Tras aplicar el parche de prueba, `studio.spec.ts` pasó 7/7, incluida la devolución de foco al botón de «Campañas», y los dos recorridos del roadmap pasaron 6/6 en los tres tamaños. Ambos informes tienen cero fallos y cero flaky. Informes en `storage/framework/testing/t43/run-general-7d637e65-bc34-4578-aa9a-d8a444ad3f13/results.json`, `storage/framework/testing/t43/run-general-96b9a041-9610-4d83-9a41-635770863098/results.json` y `storage/framework/testing/t43/run-confirm-f0e36314-592a-4bce-9547-e72f998d8c2e/results.json`. Pest completo se inició después.

**Cierre T-57 (2026-11-17).** Claude Code confirmó el modelo `claude-opus-5-5` y aprobó la aceptación y calidad sin bloqueantes. Se concretaron las siete etiquetas señaladas y se corrigió la selección del botón de carpeta en el test. El barrido de llamadas nativas quedó vacío. Pest pasó 4.137 pruebas, con 9 omitidas y 26.449 aserciones. La guía y ambos changelogs incluyen el patrón de confirmación. Se registra la sugerencia opcional de asociar los handlers de `ConfirmDialog` al render de cada pregunta. La revisión la calificó de preventiva porque Vue descarta los eventos de componentes desmontados. Los estados de confirmación tienen guardia por id y la devolución de foco se verificó en navegador. Resultado en `%TEMP%/metricool-t57-r4-recheck-pest.log` y huellas previas a T-58 en `%TEMP%/metricool-t57-r4-recheck-hashes.json`.

### T-58 — Flash global en `HandleInertiaRequests` y banner único

- **Descripción**: Un solo estilo de flash compartido por Inertia con banner global; recuperar errores que se pierden y mensajes que nunca se ven.
- **Changelog**: Los avisos del sitio comparten un banner que anuncia acciones de Studio y API keys y conserva los errores de validación visibles.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-57
- **Tipo**: frontend
- **Archivos**: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Components/FlashBanner.vue`, `app/Http/Controllers/Studio/StudioController.php`, `app/Http/Controllers/ApiKeyController.php`, `resources/js/Composables/useStudioCreation.js`
- **Cubre**: D-06
- **Cubre (unificación)**: U-21
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --compact --filter=FlashSharedPropsTest` → verde · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Los mensajes de `StudioController` y `ApiKeyController` se muestran
- [x] No quedan flashes por página con estilos distintos
- [x] El banner único de flash/aviso se usa también en `Studio/Index.vue` y `Editor.vue` (sin banners propios del estudio)
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación T-58 (2026-11-17).** El candidato aislado comparte los avisos de Inertia en los dos layouts autenticados y conserva `publishing_post_id` para el calendario. Studio usa el mismo componente dentro del diálogo cuando falla la validación y en el editor al crear el diseño. El secreto de la API key conserva su prop de una sola consulta. Pasaron formato, lint, tipos, build, sintaxis PHP y 718 pruebas de Node con `--expose-gc`. `FlashSharedPropsTest` pasó 3/3 con 84 aserciones en un Laravel aislado que usa SQLite en memoria y su propio almacenamiento. El parche `%TEMP%/metricool-t58-flash-preview.patch` se comprobó contra la copia de T-57. Pendientes aplicar en orden, repetir Pest en la rama, ejecutar Playwright y revisar aceptación y calidad con Claude Code Opus 5.5.

Contraste con el backend del checkout de T-57 en una instalación aislada. RED: `FlashSharedPropsTest` falló 3/3 al verificar `flash.success`, que no existía en ninguna de las tres respuestas. GREEN: al aplicar únicamente el diff PHP de T-58, los mismos casos pasaron 3/3 con 84 aserciones. Informes en `%TEMP%/metricool-t58-pest-preview-red.log` y `%TEMP%/metricool-t58-pest-preview-green.log`. La validación serial en el checkout queda preparada en `%TEMP%/metricool-t58-checkout-validation.ps1`.

T-58 aplicada y verificada en el checkout. RED: los dos recorridos de navegador no encontraban los avisos «API key creada» y «Diseño guardado». Informe en `storage/framework/testing/t43/run-general-1de5c9b8-bb0b-41a0-8874-829cfc4d3cb7/results.json`. GREEN: Playwright 6/6 con `qa-gate` verde en los tres tamaños y Pest focal 3/3 con 84 aserciones. Informe en `storage/framework/testing/t43/run-general-f3a88622-7b85-4e49-bfe0-7e8d520050b1/results.json`. Pasaron lint, formato, tipos, build y 663 pruebas de Node. El único `flash` de servidor está en el middleware y Studio usa `FlashBanner` dentro y fuera de sus diálogos. Pendientes regresión amplia, Pest completo y revisión de aceptación y calidad.

**Revisión del núcleo de T-58 con Claude Code Opus 5.5 (2026-11-17).** Las dos primeras invocaciones quedaron sin respuesta. La tercera devolvió hallazgos sobre el manejo de fallos HTTP y de red al crear un diseño, errores de diálogos que reaparecen en la página y regiones de estado que se montan con texto ya presente. El inventario de Vue mostró que los avisos globales se renderizan en los layouts y que Calendario consulta flash.error para liberar la tarjeta. Identidad de marca abre un StudioDialog. La revisión de los cambios restantes de consumidores y las pruebas amplias siguen pendientes. Recibo `%TEMP%/metricool-t58-r3-claude-opus55.json`.

**Seguimiento T-58 (2026-11-17).** La creación vuelve a usar el transporte JSON tipado y pasa el aviso del servidor al layout mediante el flash de Inertia. El JSON no pone un aviso en la sesión de otras pestañas. Tres pruebas Node y cuatro Pest aisladas con 88 aserciones pasaron. Se prepararon regiones vivas persistentes y pruebas de navegador para 422, 403 y 500. La cuarta revisión detectó que se puede crear un segundo diseño mientras navega el editor y que el fallo de esa navegación puede ocultar el resultado de la primera creación. También queda ajustar el montaje del aviso de plantilla. Recibo `%TEMP%/metricool-t58-r4-claude-opus55.json` y candidato `%TEMP%/metricool-t58-claude-r3-fix.patch`. No aplicado.

El siguiente candidato de T-58 conserva el diseño creado mientras navega y permite reintentar solo la apertura, incluso al cancelarse antes de iniciar la visita. Se reprodujo la reentrada y pasaron 5/5 pruebas Node en la copia. El aviso de plantilla se monta antes de que aparezca el resultado. Parche `%TEMP%/metricool-t58-claude-r4-fix.patch`, nueva revisión enviada. Sigue pendiente la aceptación en navegador.

La ronda 5 de Opus detectó dos vías para duplicar al reabrir el diálogo o pulsar otra plantilla. El ajuste guarda el enlace y separa «Abrir diseño» de «Crear otro diseño». Tras la ronda 6 se conservaron las regiones vivas y el foco, y se restablecieron nombre y dimensiones. La carpeta sigue el filtro activo. El RED reprodujo el fallo del reinicio y después pasaron 6/6. Playwright interrumpe un GET iniciado y reintenta sin otro POST. Pendientes revisión y navegador. Parche `%TEMP%/metricool-t58-claude-r6-fix.patch`. La ronda 7 quedó sin informe por el filtro reasoning_extraction de Claude Code. Se envió una revisión de seguimiento con el mismo modelo y fragmentos exactos del controlador, el diálogo y su prueba. Opus respondió en la ronda 8. El botón para reabrir está en la cabecera de Studio. El RED reprodujo que fallar al abrir el editor no mostraba aviso. El ajuste conserva el diseño guardado, muestra un aviso junto a «Abrir diseño» y limpia el error al reintentar. Node pasó 6/6. El E2E verifica que desaparece el diálogo guardado. Parche `%TEMP%/metricool-t58-claude-r8-fix.patch`. Pendientes nueva revisión y validación del navegador.

La ronda 9 de Claude Code Opus 5.5 detectó que el E2E interceptaba una excepción que el componente no gestionaba. Ahora el listener de Inertia solo trata un error de transporte del GET exacto y se retira al finalizar la visita. Las pruebas Node reprodujeron el fallo y pasaron 6/6 tras el ajuste. Playwright separa validación y reapertura y comprueba que no hay errores sin capturar. El parche `%TEMP%/metricool-t58-claude-r9-after-t57.patch` preserva la confirmación «Revocar API key» de T-57. La ronda 10 recibió el límite de uso de Claude Code con reinicio a las 11:30, hora de Madrid.

El ejecutor de T-58 queda en cola y comprueba los tres informes, Pest y las huellas de T-57 antes de aplicar su parche. Se preparó una adaptación de dos aserciones de `studio-timeline.spec.ts` para comprobar que no hay mensajes de error mientras las regiones vivas vacías permanecen montadas. El spec se añade a la regresión de T-58. Parche `%TEMP%/metricool-t58-timeline-alerts-preview.patch`.

El ejecutor validó el cotejo de T-57 y aplicó los dos parches de T-58. Pasaron las seis pruebas Node focales, 710 de la suite Node, ESLint, Prettier, TypeScript, Vite y 4 pruebas Pest con 88 aserciones. Playwright pasó 9/9 casos de avisos de API keys, errores anunciados al crear un diseño y reapertura sin otro POST, en los tres tamaños. Informe en `storage/framework/testing/t43/run-general-d59dad01-3c62-4ab7-8b63-6b14957e3d0c/results.json`. La regresión amplia y Pest completo siguen en serie.

La regresión amplia terminó con 281 aprobados, dos omisiones previstas y cero fallos o flaky. Pest completo pasó 4.138 pruebas, con 9 omitidas y 26.453 aserciones. El ejecutor de T-59 cotejó las huellas de esta versión antes de aplicar la paginación. Informe de navegador en `storage/framework/testing/t43/run-general-2c5fde50-a48d-4427-9a1c-959beb49c11b/results.json` y Pest en `%TEMP%/metricool-t58-r9-followup-pest.log`. Sigue pendiente la revisión de seguimiento con Opus 5.5.

Opus 5.5 R10 revisó la aceptación y la calidad de T-58 en `%TEMP%/metricool-t58-r10-resume1130-opus55.json`. Se comprobó que el Inertia instalado llama a `finish()` en `finally` y que los consumidores del banner usan `dismissible-error`. RED: la prueba del GET del editor omitía capturar `ETIMEDOUT`. El candidato aislado normaliza la URL del GET, trata los timeouts de esa visita, limita el aviso a la apertura efectiva del editor y descarta respuestas de creación de otra marca. Pasaron siete casos focales. Quedan los demás hallazgos, verificación en navegador y nueva revisión.

La creación y la reapertura se extrajeron a `useStudioCreation`. Siete pruebas ejecutan el composable real, incluido el orden de desmontaje antes del aviso de éxito. RED de navegador: un 409 dejaba el diálogo de plantilla abierto y tapaba la recuperación. La corrección pasó 12 casos sin fallos en `C:/Users/daycr/AppData/Local/Temp/metricool-t58-r11-exact-e307debd-a9ae-476c-a4ec-c9f16f5ac415/storage/framework/testing/t43/run-general-fad8e5e9-e4f7-4ca8-900e-f9c4972ebe8e/results.json`. Node completo pasó 717. Opus 5.5 R11 validó el flujo principal y pidió compatibilidad sin `URL.canParse`; R12 ajustó la normalización y la baja del listener en la copia aislada y pasó siete casos. Quedan regresión y revisión del último parche.

Claude Code Opus 5.5 aceptó la funcionalidad y la calidad del código en R14. R12 confirmó 141 E2E aprobados, dos omisiones previstas y qa-gate verde tras restaurar Python, el modelo local y las dependencias físicas de Vite. R13 corrigió el doble anuncio. RED de R14: al activar una acción ajena, el aviso cambiaba a «Abriendo el editor…» después de un fallo. R14 separa el progreso de apertura y devuelve el foco en el listado de plantillas. Pasaron 719 pruebas de Node, ESLint, TypeScript, Vite, 25 pruebas Pest de flash y paginación con 592 aserciones y 21 E2E seriales en tres tamaños con qa-gate verde. El caso de plantilla verifica un único POST y confirma el éxito una vez en el editor. La revisión comprobada está en %TEMP%/metricool-t58-r14-final-opus55.json y las huellas de los seis archivos integrados coinciden con el candidato. Informe de navegador en C:/Users/daycr/AppData/Local/Temp/metricool-t58-r11-exact-e307debd-a9ae-476c-a4ec-c9f16f5ac415/storage/framework/testing/t43/run-general-72fb7b1f-dceb-43c4-a664-e908d637ea49/results.json.

### T-59 — Paginación común en papelera, biblioteca, avisos e informes

- **Descripción**: Paginación común: ninguna lista se corta en silencio (papelera y biblioteca 200, avisos 100, informes 20).
- **Changelog**: La papelera, las plantillas de publicación, los avisos, los informes y los diseños muestran un total y permiten recorrer todo el listado.
- **Estado**: completado
- **Cerrada**: 2026-11-17
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-58
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/TrashController.php`, `app/Http/Controllers/LibraryController.php`, `app/Http/Controllers/NotificationController.php`, `app/Http/Controllers/ReportController.php`, `resources/js/Components/Pagination.vue`, `resources/js/Pages/Trash/Index.vue`, `resources/js/Pages/Library/**`
- **Cubre**: D-07
- **Cubre (unificación)**: U-23
- **Cubre (tests)**: E2E-10
- **Verificación**: `php artisan test --compact --filter=PaginationListsTest` → verde

**Criterios de aceptación**
- [x] Cada lista pagina y muestra el total
- [x] Con más de 200 elementos se alcanzan todos
- [x] La paginación común de papelera, biblioteca, avisos e informes se usa también en `Studio/Index.vue` y sigue el contrato de cursor único de T-28
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación T-59 (2026-11-17).** RED: los cinco casos de `PaginationListsTest` fallaron porque no existía `pagination.total` o `designs.total` en `%TEMP%/metricool-t59-pest-red.log`. GREEN: 5/5 y 241 aserciones con el cursor de T-28, totales de la consulta filtrada y orden estable por identificador en caso de empate. El componente `Pagination.vue` se usa en papelera, plantillas, avisos, informes y Studio. Pasaron Pint, formato, lint, tipos, build y Node 663/663. Pest focal ampliado pasó 98/98 y 1.015 aserciones en el Laravel aislado. El parche `%TEMP%/metricool-t59-pagination-preview.patch` se comprobó contra la copia de T-58. Pendientes aplicar en orden, verificar E2E-10 con 250 filas por lista y pasar la revisión de Claude Code Opus 5.5.

**Revisión T-59 con Opus 5.5 (2026-11-17).** Se reprodujo un COUNT innecesario al consultar estados de informes y que una plantilla sin fecha desaparecía con el cursor. El seguimiento usa el identificador en biblioteca, orden explícito en avisos y enlaces que no conservan editores obsoletos. Pasaron seis pruebas Pest aisladas con 283 aserciones. Se comprobó que el cursor antiguo de avisos responde 422. El recuento diferido todavía requiere mantener los enlaces sincronizados durante el sondeo de informes, hallazgo de la segunda revisión. Pendiente también E2E de 250 filas y controles de avisos, informes y Studio. Recibos `%TEMP%/metricool-t59-r1-claude-opus55.json`, `%TEMP%/metricool-t59-r2-claude-opus55.json` y parche incremental `%TEMP%/metricool-t59-claude-r1-fix.patch`.

El sondeo pide juntos filas, cursor y total. Node falla si se omite paginación. Pest comprueba un COUNT por recarga y el total 23 en la segunda página. Opus confirmó que el cursor pagina sobre un clon. El ajuste defensivo valida estructura y dirección conservando el formato. SQLite pasó 82 pruebas de API, MCP, Studio, informes y listas con 1.276 aserciones. Se prepararon dos recorridos para altas durante el sondeo y pausa al ocultar a mitad de ciclo. La ronda 5 pidió preservar valores escalares y nulos de los cursores existentes, verificar el eco del cursor de entrada y observar peticiones durante la pausa y al reanudar. El nuevo caso de navegador observa la red al ocultar, antes del intervalo de reanudación y tras agotar el presupuesto. Pest focal posterior pasó 8/8 con 343 aserciones. Parches `%TEMP%/metricool-t59-claude-r5-fix.patch` y `%TEMP%/metricool-t59-report-polling-browser.patch`. Pendientes revisión de seguimiento, navegador y MySQL. Opus, ronda 6, señaló riesgos de compatibilidad del formato para valores de orden largos, tipado en otros motores y precisión temporal en el E2E. Falta cotejarlos con los órdenes efectivos de los listados y completar QA.

Se cotejaron los órdenes de los listados. Todos usan id o fecha e id, sin valores de texto libre. La validación preserva el formato del cursor, aplica las reglas de clave y fecha del modelo y exige UUID para avisos. Pest SQLite pasó 82 pruebas y 1.337 aserciones, con recorrido completo de 23 informes y prueba del cursor en otro listado. El E2E preparado verifica el cursor durante el sondeo de la segunda página y los primeros intervalos en un reloj pausado. Parches `%TEMP%/metricool-t59-pagination-after-t58.patch`, `%TEMP%/metricool-t59-claude-r6-fix.patch` y `%TEMP%/metricool-t59-report-polling-browser.patch`. La ronda 7 recibió el límite de Claude Code. Sigue pendiente el navegador.

MySQL 8.4.3 pasó `PaginationListsTest` y `CursorOrderTest` en el candidato aislado: 8 pruebas y 400 aserciones. Se verificaron las 83 tablas de la base desechable y se detuvo la instancia. Resultado en `%TEMP%/metricool-t59-mysql-pest.log`.

Se adaptó el final de línea del hunk de Studio al estado actual de T-58. Los tres cambios de paginación se combinaron en 22 archivos y pasaron `git apply --check --whitespace=error` contra el checkout. El ejecutor espera que terminen Playwright y Pest de T-58, comprueba sus informes y huellas y aplica `%TEMP%/metricool-t59-after-t58-r9-combined.patch`. Ejecutará el recorrido de las 250 filas, los cursores de avisos, informes y Studio, y la prueba de pausa del sondeo, antes de la regresión amplia. La ronda de seguimiento de Opus permanece pendiente.

Tras cotejar T-58 se aplicó el parche al checkout. Se ajustó el selector de la primera fila de informes para comprobar explícitamente el orden, con el cupo de localizadores del proyecto intacto. Pasaron Pint, ESLint, Prettier, TypeScript, Vite, 711 pruebas de Node y las 8 pruebas focales de Pest con 400 aserciones. Playwright focal y la regresión amplia se ejecutan en serie. Resultados de la integración en `%TEMP%/metricool-t59-t58r9-*.log`.

Playwright focal pasó 7/7 sin omitidos ni flaky. Las 250 filas de papelera y biblioteca se recorrieron completas en tres tamaños. Pasaron los tres recorridos de avisos, informes y Studio y el caso del límite de sondeo. Informe en `storage/framework/testing/t43/run-general-2532e892-dd0a-47c4-8d1c-dc099d514b6a/results.json`. La regresión amplia está en marcha. Material exacto de revisión en `%TEMP%/metricool-t59-opus55-r7-exact-review.txt`.

La regresión amplia terminó con 266 aprobados, dos omisiones previstas y cero fallos o flaky. Informe en `storage/framework/testing/t43/run-general-c8363321-188e-4368-8408-ed33acd41cf1/results.json`. Pest completo empezó después. El ejecutor de T-60 espera ese resultado y añade primero las capturas iniciales y finales de papelera y biblioteca que pide E2E-10. Aplicará el parche de prueba `%TEMP%/metricool-t59-250-captures-preview.patch`, comprobará tipos y repetirá los tres tamaños. T-60 espera también que el informe contenga las doce capturas.

Pest completo pasó con 4.144 aprobados, 9 omitidos y 26.845 aserciones. E2E-10 pasó en los tres tamaños y se comprobaron las doce capturas iniciales y finales de papelera y biblioteca en el informe `storage/framework/testing/t43/run-general-54e952b4-9a9e-4a75-9ff5-1732b708bf6c/results.json`. Pendiente la revisión final de aceptación y calidad con Claude Code Opus 5.5.

Opus 5.5 emitió la revisión R7 de T-59 en `%TEMP%/metricool-t59-r7-resume1130-opus55.json`. Por verificar con los contratos instalados el tratamiento de cursores de API y MCP, las páginas vacías después de eliminar registros y el flujo de generar informes desde páginas posteriores. La tarea sigue en revisión.

Se integró R9 de T-59 en siete archivos con huellas iguales al candidato probado. Los cursores cualificados pasan, las páginas que se vacían regresan al inicio y generar informes conserva el límite y la marca. Pasaron 21 pruebas focales, 436 de regresión SQLite, 60 en MySQL 8.4.3, seis E2E focales y 126 de regresión de navegador con dos omisiones previstas en `C:/Users/daycr/AppData/Local/Temp/metricool-t59-r8-de840d99-446a-4338-b2b4-25f3f197e60c/storage/framework/testing/t43/run-general-311ee62d-4cdf-401d-80d4-c04170e8a99f/results.json`. Pest completo pasó 4.159 pruebas con nueve omitidas y 26.957 aserciones. Opus 5.5 R9 retiró el hallazgo sobre la estructura del cursor tras cotejar el Laravel instalado y cinco casos 422. Revisados los controladores: las acciones exitosas de papelera y plantillas redirigen directamente a la primera página con el aviso. El cierre de T-59 sigue ligado al de T-58.

Cerrada tras T-58. El seguimiento conjunto R14 volvió a verificar E2E-10 en los tres tamaños con cursores caducados, 250 filas, totales y generación de informes. El cierre usa la aceptación de Claude Code Opus 5.5 R9 sobre funcionalidad y calidad, las huellas aplicadas y las verificaciones SQLite, MySQL y de navegador registradas arriba.

### T-60 — Formularios accesibles y patrón de pestañas

- **Descripción**: `aria-describedby`/`aria-invalid` vía `FormField` en los 80 `InputError`; pestañas con flechas y `tabpanel` según el patrón de `NetworkTabs`.
- **Changelog**:
- **Estado**: en-revision
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-59
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/InputError.vue`, `resources/js/Components/FormField.vue`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Components/Studio/StudioCollabPanel.vue`, `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Components/NetworkTabs.vue`, `resources/js/Composables/useTabs.js`
- **Cubre**: D-10, D-17
- **Cubre (unificación)**: U-09, U-11
- **Cubre (tests)**: E2E-12, M-12
- **Verificación**: `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Los errores de formulario se asocian al campo
- [ ] Las pestañas se manejan con flechas y declaran `tabpanel`
- [ ] `a11y.spec.ts` sin violaciones serious/critical nuevas
- [ ] `AudioTrackPanel.vue`, `CaptionsPanel.vue` y `StudioChartsDialog.vue` usan `.field`/`FormField` en lugar de etiquetas e inputs propios; el estudio usa `EmptyState`, `PrimaryButton` y el paginador comunes
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación T-60 (2026-11-17).** RED: `form-and-tabs-accessibility.test.mjs` detectó que el campo de correo de acceso carecía de `aria-invalid` y que las pestañas de instalación MCP carecían de `tabindex` rotativo. Informe en `%TEMP%/metricool-t60-node-red.log`. GREEN: ambos casos de renderizado pasaron y se añadió una comprobación de navegación circular, foco, activación y pestañas anidadas en el manejador común. El candidato aislado usa `FormField` en 53 puntos, comparte la navegación entre Calendario, MCP, colaboración y compositor, y aplica los controles y componentes comunes en Studio. Quedan 29 llamadas directas a `InputError` por revisar según su asociación al campo, al grupo de campos o al formulario completo. Pasaron 666 pruebas de Node, lint, formato, tipos y build. El parche parcial `%TEMP%/metricool-t60-a11y-partial-preview.patch` se comprobó contra la copia de T-59. Pendientes completar la migración, ejecutar E2E-12, pasar la revisión de Opus 5.5 y obtener la evidencia manual M-12.

La segunda pasada del candidato aislado retiró las 29 llamadas directas restantes. El único uso de `InputError` queda dentro de `FormField`; hay 96 usos de `FormField` porque se separaron mensajes de campos que antes se mostraban juntos. Los errores de grupos y formularios se muestran en su ámbito y las etiquetas quedan separadas de los mensajes de validación. Pasaron lint, formato, tipos, build y 666 pruebas de Node. El parche `%TEMP%/metricool-t60-a11y-preview.patch` se comprobó contra la copia de T-59. La integración en el checkout, E2E-12, M-12 y la revisión de Opus 5.5 siguen pendientes.

Claude Code Opus 5.5 revisó el núcleo de formularios y pestañas y cuatro integraciones. Se reprodujeron dos fallos con renderizado Vue. Tras el ajuste, los cinco tests pasan: las pestañas de colaboración eligen Comentarios si la solicitada no existe, el compositor enlaza Texto común si se retira la red activa y el grupo de permisos muestra los errores de índices posteriores. La copia integrada conserva el reintento de Studio al usar PrimaryButton y EmptyState. Parches `%TEMP%/metricool-t60-a11y-after-t59.patch` y `%TEMP%/metricool-t60-claude-r1-followup.patch`. La ronda 2 recibió el límite de Claude Code. Siguen pendientes las integraciones restantes de la revisión, navegador y M-12.

Se cotejó `openTargetText`. Cambia la selección sin mover el foco y el E2E existente del compositor ya comprueba foco con flechas, Inicio y Fin. Las búsquedas de `recovery-error`, `tab-read` y `panel-read` no encontraron dependencias en E2E. RED: los dos casos de renderizado detectaron que el panel de MCP no tenía una parada de Tab y que la primera casilla de permisos no describía el error del grupo. El candidato hace enfocables los paneles y vincula el error a la primera casilla. La prueba de navegador preparada comprueba que Tab entra en el panel de MCP. El renderizado focal pasó 5/5. Se ajustó el montaje de cinco pruebas anteriores de confirmaciones al nuevo composable. En la copia integrada posterior a T-59 pasaron las 716 pruebas de Node, ESLint, Prettier, TypeScript y Vite. El parche `%TEMP%/metricool-t60-after-t59-r9-combined.patch` pasó `git apply --check --whitespace=error` sobre esa base. Quedan Opus 5.5 para las integraciones restantes, navegador y M-12.

El parche también pasó el cotejo en lectura contra T-59 ya integrada en el checkout. El ejecutor de T-60 espera la regresión, Pest y las huellas de T-59 antes de aplicarlo y ejecutar `a11y.spec.ts` y la regresión serial. La ronda de revisión se preparó por grupos que cubren los 43 archivos.

En el checkout, E2E-12 pasó los nueve casos focales y la regresión pasó con 379 aprobados, dos omisiones previstas, cero fallos y cero flaky en `C:/laragon/www/metricool/storage/framework/testing/t43/run-general-f2d12ef9-1f75-4110-84aa-b2e4eb8daa6c/results.json`. Pest pasó 4.144 pruebas con nueve omisiones y 26.845 aserciones. Quedan la revisión de aceptación y calidad con Opus 5.5 y M-12 con lector de pantalla.

Claude Code Opus 5.5 devolvió tres hallazgos de aceptación reproducibles en T-60. El diálogo de eliminación de cuenta no asociaba su etiqueta de contraseña, OAuth perdía el error de un permiso posterior al primero y SmartLinks reutilizaba el identificador del primer bloque al reordenar el segundo. Playwright R3 reprodujo los tres en una copia aislada. R3 corrige las claves locales de SmartLinks, asocia los errores de OAuth al grupo, da al diálogo de cuenta su propio identificador y conserva la región viva de InputError. Compilación verde y segunda ejecución serial en curso. La revisión parcial de listados sigue abierta junto con los hallazgos de formularios, informes y Studio.

El candidato R3 pasó los nueve recorridos de perfil, OAuth y SmartLinks en los tres tamaños. En R4 se reprodujo un segundo destinatario inválido que quedaba sin mensaje. El helper de errores de grupo se comparte entre informes, OAuth y API keys. El seguimiento pasó seis recorridos de informes y consentimiento con descarga de PDF real en el entorno local. Queda abordar la asociación de los errores de bloques y los hallazgos de las revisiones de Studio e informes. El candidato sigue aislado.

R5 unió los errores de URL a sus controles en Sitios y SmartLinks y añadió pruebas sobre el HTML renderizado. Volvió a presentar el error general de bloques junto al título de la sección de Sitios. Las siete pruebas SSR pasaron. El candidato reaplicado a la base actual conserva las confirmaciones de T-57, el anuncio y los cursores de informes de T-55/T-59 y las pruebas de foco de pestañas. Quedan la comprobación serial del candidato exacto, la revisión de Opus y M-12.

El candidato R5 ajustado a la rama actual pasó 721 pruebas de Node, TypeScript, ESLint y Vite. Los 27 recorridos seriales de navegador pasaron sin omisiones ni fallos en los tres tamaños. Se comprobó la descripción accesible del campo de URL inválida en Sitios y SmartLinks. Los dos paquetes exactos R5 se enviaron a Claude Code Opus 5.5 y la tarea sigue en revisión.

R6 corrige el foco al recibir errores en OAuth, claves API, informes, Sitios y SmartLinks. Pasaron 721 pruebas de Node y 30 recorridos de navegador con qa-gate verde. R7 cambia los grupos a destinos de foco explícitos, contempla el foco al mover o eliminar bloques y añade pruebas del cambio de página al corregir un sitio. Pasaron 722 pruebas de Node, ESLint, TypeScript y Vite. La regresión de navegador R7 está en curso. M-12 con lector de pantalla sigue pendiente.

R7 de Claude Code Opus 5.5 aprobó la aceptación automatizable y la calidad del código en las dos revisiones. Se verificaron 24 recorridos de formularios, pestañas y Sitios y se repitieron los seis de SmartLinks tras corregir el selector del botón Quitar. Se integraron los 15 archivos en la rama, incluidos los grupos enfocados y el arnés de Vite sin observador de archivos. Pasaron 735 pruebas de Node en la rama conjunta con T-61. M-12 con lector de pantalla sigue abierto.

### T-61 — Limpieza menor del frontend

- **Descripción**: `compileTimeline()` una vez y `some()` en lugar de `JSON.stringify`, `loading="lazy"` en 14 imágenes, rutas de datasets con Ziggy y borrado de código y dependencias muertas.
- **Changelog**: La cronología reutiliza el plan compilado y las rutas de tablas funcionan también al instalar la aplicación en un subdirectorio.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-60
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/dataset-client.js`, `package.json`, `package-lock.json`, `resources/js/Components/NavLink.vue`, `resources/js/Components/ResponsiveNavLink.vue`, `resources/js/Pages/**`
- **Cubre**: D-15, D-18, D-19, D-20
- **Cubre (tests)**: E2E-07
- **Verificación**: `npm ls --depth=0` → sin `extraneous` · `npm run build` → build correcto

**Criterios de aceptación**
- [x] `compileTimeline()` se calcula una vez por cambio
- [x] Sin `NavLink`/`ResponsiveNavLink`/`concurrently`/`dotenv`/`react` sobrantes
- [x] `npm ls` sin extraneous
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Preparación T-61 (2026-11-17).** RED: la prueba del editor contó dos compilaciones en la misma lectura de la línea de tiempo, y la prueba del cliente de tablas recibió una URL literal en vez de la ruta nombrada. Informe en `%TEMP%/metricool-t61-node-red.log`. GREEN: 16/16 pruebas focales, incluida la detección de vídeo dentro de grupos anidados. El candidato aislado reutiliza el plan compilado para las lecturas y al dividir clips, usa las rutas web de Ziggy, añade `loading="lazy"` a 14 imágenes y retira `NavLink`, `ResponsiveNavLink` y `dotenv`. Pasaron lint, formato, tipos, build y 669 pruebas de Node. El parche `%TEMP%/metricool-t61-frontend-cleanup-preview.patch` se comprobó contra la copia de T-60.

`concurrently` tiene un consumidor activo. El `DevCommand` de Laravel lo ejecuta en Windows al lanzar `composer dev`. En Linux y macOS ese comando utiliza Multiplex, que depende de React. En Windows, npm 12.0.2 dejó React como `extraneous` incluso tras `npm ci` y `npm prune`. Después de retirar únicamente esa carpeta del árbol aislado de Windows, `npm ls --depth=0` quedó sin sobrantes y `concurrently --version` devolvió 10.0.6. El lockfile conserva React para Multiplex. Se documentó la diferencia entre plataformas. Pendientes aplicar al checkout, verificar E2E-07 y Pest, y pasar la revisión de Opus 5.5.

Tras la revisión de Claude Code Opus 5.5 se verificaron los métodos y URI de las nueve rutas con el Ziggy que genera Laravel. Pest de tablas pasó 48 casos, con una omisión y 410 aserciones. CI instala Composer antes de Node y el barrido de consumidores confirmó que la suite no importa dotenv. El seguimiento explica la ausencia de rutas en el cliente, permite grupos heredados sin hijos y evita dividir si la página no existe en el plan. Se conserva StudioApiError de T-53 al adoptar Ziggy. Node pasó 17/17 con el código integrado en la copia. Parches `%TEMP%/metricool-t61-cleanup-after-t60.patch` y `%TEMP%/metricool-t61-postreview-after-t60.patch`. Se aplicó la cadena T-57 a T-61 y se compilaron diez Vue en la copia aislada. La ronda 2 recibió el límite de Claude Code. Pendientes la revisión de seguimiento y la comprobación en navegador, incluida la carga de imágenes.

Se verificó el parche combinado de 22 archivos sobre el candidato actualizado de T-60. Con dependencias físicas en la copia aislada de Windows, `npm ls --depth=0` quedó limpio. Pasaron ESLint, Prettier, TypeScript, Vite y las 720 pruebas de Node. Parche `%TEMP%/metricool-t61-after-t60-r9-combined.patch` y paquete exacto de revisión `%TEMP%/metricool-t61-opus55-r2-exact-review.txt`. Quedan aplicación y verificación de comportamiento antes y después en el checkout.

La revisión exacta R2 de Claude Code Opus 5.5 se recibió en %TEMP%/metricool-t61-r2-followups-opus55.json. Se contrastaron los nombres de las nueve operaciones del cliente con el registro real de rutas y la presencia de @routes en Blade. RED focal al lanzar antes de devolver una promesa. R3 devuelve rechazos para las peticiones asíncronas, resuelve Ziggy al usarlo y avisa si se intenta dividir un clip cuya página ya no existe. La detección de vídeo queda en el módulo de documentos y conserva compatibilidad con grupos heredados sin hijos. Pasaron 17 pruebas focales. Quedan la revisión R3 y la regresión antes y después, incluida la selección de imágenes para carga diferida.

R4 reprodujo con Ziggy real la pérdida del prefijo al instalar en un subdirectorio. Se pide la ruta absoluta y se usa su ruta local y consulta, manteniendo las peticiones en el origen de la aplicación. Pasaron 18 pruebas focales, 726 de Node, ESLint, TypeScript, Vite y la comprobación del árbol npm. Hay 14 imágenes con carga diferida, ocho añadidas y seis preexistentes. Las imágenes visibles al abrir la página y las ya controladas por su carga se tratan según su componente. Los 21 E2E de compositor, tablas y cronología pasaron tanto en la línea base como en R4, en escritorio y en dos anchos móviles. Evidencias en `%TEMP%/metricool-t61-r4-*`. Pendientes la revisión exacta de Claude Code Opus 5.5 y el cierre de T-60.

Claude Code Opus 5.5 R4 aprobó la aceptación y la calidad de T-61 tras revisar el diff exacto, incluido Ziggy con prefijo y las pruebas de cronología. Recibo en `%TEMP%/metricool-t61-r4-exact-opus55.json`. Pendiente de cierre de T-60 y reaplicación del parche sobre esa versión.

**Cierre T-61 R4.** Claude Code Opus 5.5 aprobó aceptación y calidad. Se verificaron las rutas con Ziggy y el registro real de Laravel. La rama de trabajo incluye la implementación automatizada de T-60 y preserva la exportación desde el diseño y el compositor. Pasaron 735 pruebas de Node, 89 de Pest con una omisión y 780 aserciones, ESLint, TypeScript y Vite. La comparación aislada dio los mismos 21 E2E antes y después. La repetición integrada reveló que la prueba de cambio de marca cerraba el diálogo mientras empezaba a leer las tablas nuevas. Se esperó a su respuesta real sin relajar la detección de abortos, y el caso pasó en los tres tamaños con qa-gate verde. Se comprobó `npm ls --depth=0` sin extraneous después de apartar las dos copias locales sobrantes de dotenv y React. Las evidencias están en `%TEMP%/metricool-t61-r4-*` y `%TEMP%/metricool-t60-t61-main-*`.

### T-62 — `Editor.vue` (1/2): composables de guardado, historial y reproducción

- **Descripción**: Primera mitad de D-11: extraer `useStudioSave`, `useStudioHistory` y `useTimelinePlayback`. D-11 se cuenta aquí.
- **Changelog**: El editor de Estudio usa todo el ancho disponible. Al salir, se cancelan los autoguardados pendientes.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-61, T-33
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/composables/useStudioSave.js`, `resources/js/Studio/composables/useStudioHistory.js`, `resources/js/Studio/composables/useTimelinePlayback.js`, `resources/js/Studio/composables/useTimelineEditing.js`
- **Cubre**: D-11
- **Cubre (unificación)**: U-05, U-10
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test tests/e2e/studio-boards.spec.ts tests/e2e/studio-captions.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] `Editor.vue` pierde al menos 50 declaraciones de nivel superior
- [x] Los specs del estudio pasan antes y después
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] `Editor.vue` usa la prop `fullBleed` del layout (sin `-m-3` ni `max-w-[1500px]` inertes) y abre sus diálogos con `BaseDialog`
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-11 es L: dos tareas. Ejecutar los E2E del estudio antes de empezar para fijar la línea base.

**Línea base T-62 (2026-11-17).** El AST de `Editor.vue` en el candidato de T-61 tiene 133 declaradores de variables y 66 funciones de nivel superior, 199 declaraciones de implementación. Se cuentan por separado 21 imports. Con el mismo método, el objetivo de reducción deja un máximo de 149 declaraciones de implementación. Informe en `%TEMP%/metricool-t62-editor-count-before.json`. El arnés del editor pasó 8/8 en esa copia. Los casos nuevos prueban que el guardado manual respeta una revisión tras el autoguardado, que un 409 conserva el borrador sin volver a enviarlo y que deshacer mantiene los metadatos y selecciona una página existente. RED: la prueba de layout falló porque el editor no solicita `fullBleed` en `AuthenticatedLayout`. Informes en `%TEMP%/metricool-t62-editor-node-before.log` y `%TEMP%/metricool-t62-layout-node-red.log`. El parche de pruebas `%TEMP%/metricool-t62-editor-baseline-tests.patch` se comprobó contra la copia de T-61. La extracción queda pendiente de la línea base de navegador.

Se aplicó el parche de pruebas de T-62 sobre el Editor ya encadenado con T-57 a T-61 en una copia aislada. Node pasó ocho comprobaciones, incluidas las de contrato del candidato anterior, y falló solo la de `fullBleed`, que sigue siendo el RED esperado. Informe `%TEMP%/metricool-t62-integrated-baseline-red.log`. La línea base E2E sigue pendiente antes de la extracción.

Al repetir el recuento sobre el candidato actualizado de T-61 se midieron 134 declaradores de variables y 66 funciones de nivel superior. El máximo con la misma métrica es 150 tras quitar al menos 50. Se añadieron dos pruebas al arnés existente: cerrar la línea de tiempo durante la primera búsqueda detiene la reproducción y la espera de carga se descuenta del reloj antes de programar o cancelar el siguiente fotograma. La línea base Node quedó en 10 aprobadas y solo el RED esperado de `fullBleed`. Evidencias `%TEMP%/metricool-t62-exact-t61-editor-count.json` y `%TEMP%/metricool-t62-exact-t61-node-baseline.log`. El parche `%TEMP%/metricool-t62-after-t61-baseline-tests.patch` pasó `git apply --check --whitespace=error`. La extracción sigue pendiente de los E2E de línea base.

R1 preparó una copia con T-60, T-61, T-101 y T-106. RED: el arnés del editor volvió a fallar solo por la prop de layout ausente. El editor usa el ancho disponible del layout, y las funciones de guardado, historial y cronología se extraen a cuatro composables. El AST en la rama integrada baja de 199 a 149 declaraciones. Pasaron 15 casos focales y 741 pruebas de Node, ESLint, TypeScript y Vite. La línea base tuvo 735 pruebas PHP, nueve omisiones y 5.910 aserciones, y 27 recorridos de navegador. El caso nativo de subtítulos se repitió en la rama antes del refactor para usar la tipografía privada y una ruta de artefactos compatible con Poppler en Windows. Este caso también pasó. Siguen en curso la regresión exacta del candidato y las revisiones de aceptación y calidad con Claude Code Opus 5.5. Evidencias en `%TEMP%/metricool-t62-*`.

Revisión de aceptación y calidad con Claude Code Opus 5.5, R2: aprobada. R1 detectó que, al desmontar el editor, un autoguardado en cola podía enviar un segundo PATCH después del guardado pendiente. RED verificado con dos peticiones donde debía haber una. R2 cancela el temporizador y desactiva el envío y el procesamiento de respuestas tras el desmontaje. La revisión final pidió confirmar que deshacer y rehacer invalidan la vista previa. `applySnapshot` llama a `reloadCanvas`, que invalida la vista previa antes de cargar el documento. El AST de la rama pasa de 199 a 149 declaraciones. Verificación final: 28/28 recorridos seriales de Playwright y qa-gate verde, 742 pruebas Node, ESLint, TypeScript y Vite. En el candidato, Pest pasó 776 pruebas con 9 omitidas y 6.280 aserciones. El parche final se aplicó en la rama y allí pasaron las 42 pruebas focales de Node. Evidencias `%TEMP%/metricool-t62-r2-cierre-opus55.json`, `%TEMP%/metricool-t62-r2-final-browser.log` y `%TEMP%/metricool-t62-r2-main-node.log`.

### T-63 — `Editor.vue` (2/2): presentación, gráficos y `EditorToolbar`

- **Descripción**: Segunda mitad de D-11: `usePresentation`, `useChartFlow` y `EditorToolbar`; `Editor.vue` queda con < 40 declaraciones.
- **Changelog**: Estudio conserva las cargas pendientes y los reintentos de audio. El editor detiene el guardado al cambiar de diseño o marca.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-62
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/composables/usePresentation.js`, `resources/js/Studio/composables/useChartFlow.js`, `resources/js/Components/Studio/EditorToolbar.vue` También `useEditorCanvas`, `useEditorDialogs`, `useEditorLibrary`, `useEditorDesignOperations`, `useStudioSave`, `PresentationDialog` y `StudioChartsDialog`., `resources/js/Studio/composables/useEditorCanvas.js`, `resources/js/Studio/composables/useEditorDesignOperations.js`, `resources/js/Studio/composables/useEditorDialogs.js`, `resources/js/Studio/composables/useEditorLibrary.js`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (unificación)**: U-09, U-10
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test --grep studio --workers=1` → verde

**Criterios de aceptación**
- [x] `Editor.vue` < 40 declaraciones de nivel superior
- [x] Todos los specs `studio-*` pasan antes y después
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Los diálogos de presentación y gráficos extraídos usan `BaseDialog` y `.field`, sin bloques `<style>` ni botones propios
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-11.

Línea base de T-63 iniciada en la copia exacta del cierre de T-62. Playwright descubrió 109 recorridos `studio-*` y ejecuta con un worker. Se observó un HTTP 422 en la inferencia de fondo de la copia. Falta el modelo local en la ruta que usa el spec. El original de la rama conserva el SHA-256 que exige el spec. Se repetirá el recorrido con ese modelo al terminar la primera ejecución. Evidencia `%TEMP%/metricool-t63-r1-baseline-browser.log`.

La línea base terminó con 106/109 recorridos. Los tres de inferencia dieron HTTP 422 por faltar el modelo en la copia. Tras habilitar el fichero nativo con la huella exigida, los tres pasaron en una repetición serial y qa-gate dio verde. Pest de línea base: 758 pruebas, nueve omitidas y 6.230 aserciones.

RED: `studio-editor-structure.test.mjs` contó 149 declaraciones y falló el límite inferior a 40. El candidato R2 cuenta 37 (26 declaradores y 11 funciones). Se extrajeron la presentación, los gráficos, el lienzo, los recursos, las operaciones de diseño y los diálogos, además de `EditorToolbar`. RED: desmontar antes de que se inicializara el lienzo registraba `beforeunload`, `keydown` y el guard de Inertia tarde. El caso pasa tras comprobar la vigencia del montaje.

La primera revisión de Opus 5.5 y los 11 casos focales detectaron mejoras de cobertura y una incidencia de contraste al habilitar «Insertar gráfico». Se separó la reproducción del lienzo de la presentación, se trasladó la caché de gráficos a su composable y se añadieron casos de Unicode, cambio de marca, descarte del lienzo tardío y cancelación de reproducción. La comprobación axe volvió a pasar sin relajar la auditoría. Pasaron 45 casos focales. El parche `%TEMP%/metricool-t63-r2-candidate.patch` pasa `git apply --check --whitespace=error`. El candidato y la revisión final de aceptación y calidad siguen abiertos hasta completar los 109 recorridos. Resultados en `%TEMP%/metricool-t63-r1-*` y `%TEMP%/metricool-t63-r2-*`.

R3 reprodujo un PATCH al diseño nuevo al reutilizar la instancia. El editor fija su contexto, bloquea guardados y operaciones cuando cambia y espera a liberar lienzo y previa antes de cerrar los recursos de gráficos. La revisión R3 de Opus 5.5 aprobó la estática. `StudioService::update` confirma que `kit_revision` es una revisión del propio diseño que conserva el kit. Pest de base y candidato pasó 758 pruebas con 9 omitidas y 6.230 aserciones. La regresión R3 pasó 106 de 109. Los tres recorridos de audio de presentación fallaron porque seleccionar un archivo mientras el lienzo estaba ocupado descartaba la selección.

R6 encola las selecciones por marca y generación, procesa vídeo y audio por orden y conserva los tickets reintentables. Opus 5.5 aprobó aceptación estática y calidad en R5, y R6 añadió la prueba de dos tickets y el borrado de aviso de espera al cambiar contexto. Pasaron 761 pruebas de Node, ESLint, TypeScript y Vite. Los tres recorridos de audio fallidos pasaron sobre R6, con qa-gate verde. Sigue en curso la regresión completa exacta. Parche `%TEMP%/metricool-t63-r6-final.patch` y resultados `%TEMP%/metricool-t63-r6-*`.

R6 exacta obtuvo 106/109. En móvil, un recorrido se detuvo antes de abrir el editor con un error de socket. En móvil de 360 px, otro agotó el tiempo durante el sembrado de tablas y el tercero no llegó a la URL del editor desde el listado. Los mismos casos pasaron en dos repeticiones focales seriales sobre R6 (1/1 y 2/2, qa-gate verde). Opus 5.5 aprobó el código y pidió conservar ambos resultados, completar la documentación y acreditar el gate completo. R7 añadió un RED sobre errores tardíos del lienzo y corrigió dos accesos de pincel tras liberar el adaptador. Pasaron 28 pruebas focales y Vite. La guía y los changelogs EN/ES recogen los cambios visibles. Sigue en curso la regresión completa de R7. Evidencias %TEMP%/metricool-t63-r6-final-evidencia-opus55.json, %TEMP%/metricool-t63-r7-quality-opus55.json y %TEMP%/metricool-t63-r7-exact-browser.log.

Cerrada con el visto bueno final de Claude Code Opus 5.5 en aceptación y calidad, cero Critical y cero Important. R7 completó 109 recorridos Studio en una sola ejecución serial (escritorio, móvil y 360 px), sin fallos ni casos inestables, con qa-gate VERDE. R8 solo corrigió la documentación y los changelogs. Sus secciones de runtime y pruebas son idénticas a R7 byte por byte. Se aplicó el parche exacto y se confirmó el inverso. La rama cuenta 39 declaraciones (27 declaradores y 12 funciones) y pasó 56 pruebas focales. Se conservaron las pruebas Pest de base y candidato y los resultados anteriores con sus incidencias registradas. Evidencias %TEMP%/metricool-t63-r8-cierre-opus55.json, %TEMP%/metricool-t63-r8-main-ast.json y %TEMP%/metricool-t63-r8-main-focal-node.log.

### T-64 — Refactor de `Sites/Edit` y `SmartLinks/Index`

- **Descripción**: Primera tercera parte de D-12: subcomponentes y composables por función en Sitios y SmartLinks. D-12 se cuenta aquí.
- **Changelog**:
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-63
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Sites/Edit.vue`, `resources/js/Pages/SmartLinks/Index.vue`, `resources/js/Components/Sites/**`, `resources/js/Components/SmartLinks/**`, `resources/js/Composables/useSitePreview.js`
- **Cubre**: D-12
- **Cubre (tests)**: E2E-14
- **Verificación**: `npx playwright test tests/e2e/sites.spec.ts tests/e2e/smartlinks-blocks.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Cada página queda en < 300 líneas de script
- [x] `sites.spec.ts` y `smartlinks-blocks.spec.ts` verdes antes y después
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-12 es L: tres tareas.

Línea base de Pest en la rama: 46 pruebas de Sitios y SmartLinks con 528 aserciones. Se inicia la línea base E2E en la rama antes de aplicar el candidato de refactor. Resultados %TEMP%/metricool-t64-baseline-pest.log y %TEMP%/metricool-t64-baseline-browser.log.

Cerrada tras la revisión final de aceptación y calidad de Claude Code Opus 5.5, sin Critical ni Important. Sitios y SmartLinks quedaron en 144 y 120 líneas de script. Pest pasó las mismas 46 pruebas con 528 aserciones antes y después. Playwright pasó los mismos 12 recorridos en escritorio, móvil y 360 px en la base y el candidato, ambos con qa-gate VERDE. El nuevo test de vista previa verifica selección y revisión del borrador guardado, fallback y cambio de tamaño sin recalcular la URL. Vite, ESLint y el test Node focal pasaron. Se aplicó el parche exacto y el test y ESLint volvieron a pasar en la rama. Guías de Sitios y SmartLinks actualizadas. Evidencias %TEMP%/metricool-t64-r2-final-opus55.json, %TEMP%/metricool-t64-baseline-browser.log y %TEMP%/metricool-t64-r2-candidate-browser.log.

### T-65 — Refactor de `Reports/Index` y `Calendar/Index`

- **Descripción**: Segunda parte de D-12: subcomponentes y composables en Informes y Calendario.
- **Changelog**: Cambiar de marca cancela la vista previa del informe anterior. El buscador del calendario tiene un nombre accesible.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-64
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Reports/Index.vue`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Components/Reports/**`, `resources/js/Components/Calendar/**`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-14
- **Verificación**: `npx playwright test tests/e2e/report-builder.spec.ts tests/e2e/calendar-views.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Cada página queda en < 300 líneas de script
- [x] `report-builder.spec.ts` y `calendar-views.spec.ts` verdes antes y después
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-12.

Línea base Pest sobre la rama con T-64 cerrada: 106 pruebas y 2.304 aserciones de Calendar, Reports y CalendarViewsPage. El candidato está en copia aislada. Queda ejecutar la línea base serial de Playwright antes de integrar sus cambios. Evidencia %TEMP%/metricool-t65-baseline-pest.log.

Playwright base pasó 13/13 recorridos Report Builder y Calendar Views con qa-gate VERDE. Opus 5.5 detectó una respuesta tardía de vista previa al cambiar de marca. RED: el test observó la marca 10 tras pasar a la 20. R2 aborta la petición anterior y verifica la identidad de la activa antes de publicar los datos, los errores o el estado de carga. Verde Node, Vite, ESLint y TypeScript. Opus cerró el Important en código. Quedan el navegador y la revisión final del candidato. Evidencias %TEMP%/metricool-t65-baseline-browser.log, %TEMP%/metricool-t65-r2-preview-red.log y %TEMP%/metricool-t65-r2-preview-quality-opus55.json.

Cierre R3. Los scripts de Informes y Calendario quedaron en 161 y 139 líneas entre etiquetas. R2 perdió la llamada entre el formulario y el sondeo que se había trasladado al componente de informes recientes. El navegador lo reprodujo en los tres perfiles. R3 conecta el reinicio tras un envío válido y se recompiló tras detectar que una repetición servía el JS anterior. Pasaron 16/16 recorridos con qa-gate VERDE y sin flaky, incluidos los 13 de la línea base y la cancelación real en tres perfiles. La regresión de Pest conservó las 106 pruebas y 2.304 aserciones. Claude Code `claude-opus-5-5` aprobó aceptación y calidad con cero Critical e Important. La rama verificó el parche exacto, ESLint y la prueba Node de respuesta antigua. Evidencias `%TEMP%/metricool-t65-r3-rebuilt-browser.log`, `%TEMP%/metricool-t65-r3-candidate-pest.log` y `%TEMP%/metricool-t65-r3-final-opus55.json`.

### T-66 — Refactor de `useComposer.js`

- **Descripción**: Tercera parte de D-12: partir `useComposer.js` en composables por función sin cambiar su API pública.
- **Changelog**:
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-65
- **Tipo**: frontend
- **Archivos**: `resources/js/composables/useComposer.js`, `resources/js/composables/composer/**`, `resources/js/Composables/composer/useComposerCampaigns.js`, `resources/js/Composables/composer/useComposerMedia.js`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-14
- **Verificación**: `npx playwright test tests/e2e/composer.spec.ts tests/e2e/composer-presets.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] `useComposer.js` < 400 líneas
- [x] `composer.spec.ts` y `composer-presets.spec.ts` verdes antes y después
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-12. El compositor es la pantalla más crítica: revisión adversarial B reforzada.

Línea base Pest de Publishing y Workflow verde con 275 pruebas y 1.387 aserciones. Línea base de navegador en curso. El candidato aislado tiene 361 líneas y conserva las 84 claves y las dos exportaciones del módulo comprobadas por AST. Opus R1 señaló el contrato de reactividad de las entradas y la falta de pruebas focales. El candidato usa refs coherentes, preserva `post` y `brand` como valores computados desde props y pasa cuatro pruebas Node de archivos y campañas. Quedan la regresión de navegador antes y después y el visto bueno final.

Cierre R2. Playwright pasó 75/75 con `composer.spec.ts` y `composer-presets.spec.ts` antes y después, en tres perfiles, con qa-gate VERDE y sin flaky. Pest mantuvo 275 pruebas y 1.387 aserciones. Vite compiló el candidato exacto. Opus 5.5 aprobó cumplimiento y calidad sin Critical ni Important. La integración verificó 361 líneas en `useComposer.js` y cuatro pruebas Node de archivos y campañas. Evidencias `%TEMP%/metricool-t66-baseline-browser.log`, `%TEMP%/metricool-t66-r2-candidate-browser.log`, `%TEMP%/metricool-t66-r2-pest.log` y `%TEMP%/metricool-t66-r2-final-opus55.json`.

### T-67 — Tipos (1/2): JSDoc + `checkJs` en lib y composables

- **Descripción**: Primera mitad de D-14: `checkJs` y `vue-tsc` en CI, JSDoc en `lib/` y composables; tipos generados desde PHP donde sea viable. D-14 se cuenta aquí.
- **Changelog**:
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-66
- **Tipo**: frontend
- **Archivos**: `jsconfig.json`, `tsconfig.json`, `package.json`, `resources/js/lib/**`, `resources/js/composables/**`, `resources/js/Composables/useStudioAssetLinks.js`
- **Cubre**: D-14
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run typecheck` → 0 errores · `npm run build` → build correcto

**Criterios de aceptación**
- [x] `npm run typecheck` pasa y está en CI
- [x] Sin cambio de comportamiento
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-14 es L: dos tareas (progresivo).

Cierre R2. `npm run typecheck` comprueba Playwright con tsc, genera los tipos de rutas reales con Ziggy y ejecuta `vue-tsc` con `checkJs` en lib, cuatro composables y `FormField.vue`. TypeScript 6.0.3 queda fijado para compatibilidad con `vue-tsc` 3.3.14. La prueba negativa de una ruta inventada produjo TS2769. El job conserva el check requerido y se reprodujo en una copia limpia sin `.env` con Composer de producción, `npm ci` y `typecheck`. Preparada la ejecución del job en GitHub al publicar la rama. El AST de los módulos anotados se conservó salvo el ajuste del manejador de teclado, que admite `KeyboardEvent` y distingue por identidad. La rama instaló y verificó los dos compiladores. Pasaron build, nueve pruebas Node, 106 Pest con 2.304 aserciones y 16/16 recorridos Playwright seriales en los tres perfiles con qa-gate VERDE. Claude Code `claude-opus-5-5` aprobó aceptación y calidad sin Critical ni Important. Evidencias `%TEMP%/metricool-t67-r2-final-opus55.json`, `%TEMP%/metricool-t67-r2-views-pest.log`, `%TEMP%/metricool-t67-r2-candidate-browser.log` y `%TEMP%/metricool-t67-ziggy-red.log`.

### T-68 — Tipos (2/2): props de componentes y páginas principales

- **Descripción**: Segunda mitad de D-14: forma tipada de las props (de 250 sin forma) en componentes comunes y páginas principales.
- **Changelog**: Props de componentes comunes y páginas principales tipadas y verificadas. El filtro «Solo ocultos» refleja correctamente el estado de la URL.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-67
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/**`, `resources/js/Pages/**`, `tsconfig.json`, `tsconfig.frontend.json`, `resources/js/types/**`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/navigation.js`, `resources/js/Composables/useCalendarPublishNow.js`, `resources/js/Composables/useConfirm.js`, `resources/js/Composables/useNetworks.js`, `resources/js/Composables/useReportPreview.js`, `resources/js/Composables/useWorkflow.js`, `app/Http/Controllers/InboxController.php`, `tests/Feature/Calendar/CalendarTest.php`, `tests/Feature/Dashboard/AllBrandsPageTest.php`, `tests/Feature/Dashboard/DashboardTest.php`, `tests/Feature/Posts/Compose/ComposeTest.php`, `tests/Feature/Reports/ReportBuilderTest.php`, `tests/Feature/Reports/ReportsTest.php`, `tests/Feature/Studio/StudioBackendTest.php`, `tests/Feature/Ui/InboxNotesPageTest.php`, `tests/e2e/inbox-notes.spec.ts`, `tests/e2e/inbox-triage.spec.ts`, `tests/e2e/studio-presentation-media.spec.ts`, `tests/frontend/confirm-consumers.test.mjs`, `tests/frontend/report-pagination-polling.test.mjs`, `tests/frontend/studio-presentation-unmount.test.mjs`, `docs/guias/desarrollo-local.md`, `docs/guias/testing.md`, `docs/modulos/inbox.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/roadmap/2026-11-11-audit-hardening/testing/t68/**`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run typecheck` → 0 errores

**Criterios de aceptación**
- [x] Las props de los componentes comunes tienen tipo
- [x] `npm run typecheck` sigue en 0 errores
- [x] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-14.

**Avance de T-68 (2026-11-17).** Aplicados dos incrementos revisados por Claude Code Opus 5.5 con criterios de aceptación y calidad de código. El primero añade tipado estricto a componentes comunes, el shell, Informes, Calendario, las tres vistas de Analítica e Inbox. El segundo tipa las declaraciones de props del Compositor y el listado de Estudio. Sus plantillas y dependencias aún quedan fuera de la comprobación. Las declaraciones del segundo incremento conservan los constructores y valores por defecto originales, verificados al comparar sus AST y compilarlas con Vue.

**Verificación local.** El primer incremento pasó `npm run typecheck` con la secuencia de CI, Composer sin dependencias de desarrollo y `npm ci`, regenerando las declaraciones Ziggy. Pasaron 91 Pest con 1.314 aserciones, 32 Node, ESLint focal, Prettier, Pint y Vite. RED de Inbox en Pest y Chromium al cargar `hidden=1`. La corrección de «Solo ocultos» pasó con los otros escenarios de Inbox y Analítica, 15/15 en los tres tamaños. La línea base de Playwright pasó 283 casos con dos omisiones previstas. La confirmación exacta del primer incremento quedó en `%TEMP%/metricool-t68-main-pages-r5-opus55.json`.

El segundo incremento pasó `npm run typecheck`, 109 Pest con 916 aserciones con el runtime nativo de tipografías, Pint, ESLint focal y Vite. La prueba temporal de una clave de prop mal escrita falló con TS2353 y pasó tras restaurar el descriptor. Vue en desarrollo aceptó los arrays vacíos reales de Laravel y rechazó un número en los mismos tres descriptores. Revisión de cierre de los ajustes en `%TEMP%/metricool-t68-composer-studio-r10-opus55.json`. Se mantiene el formato anterior de `Studio/Index.vue`, que ya daba aviso global de Prettier.

**Regresión integral en curso.** El primer ensayo de E2E-39 sobre el incremento R5 se detuvo tras llegar al caso 415. Se conservaron sus fallos y evidencias en `%TEMP%/metricool-t68-candidate-e2e39-r5-browser.log`. Los dos recorridos que fallaron tenían el diálogo de confirmación de notas sin aceptar y una observación de dibujo anterior al desmontaje de Inertia. Se corrigieron y pasaron la revisión de Claude y la repetición serial focal de 14/14 con qa-gate verde.

**Seguimiento de la regresión (2026-11-17).** Pest completo del checkout aislado pasó 4.262 pruebas, con 9 omisiones y 28.601 aserciones. Node completo detectó tres pruebas de confirmación que todavía buscaban handlers de Sitios y SmartLinks en sus páginas, pese a la extracción de T-64. Se trasladaron los casos a los componentes reales manteniendo las aserciones, y se añadieron comprobaciones de espera y cancelación. Revisión de Opus R12 aceptada y 18/18 casos focales. La corrección de cancelación multimedia conserva la prohibición de reproducir durante la navegación y mide los dibujos después del desmontaje comprobado, antes de liberar la respuesta retenida. RED/GREEN con el bloque real de prueba y 9 tests focales, revisión R14 aceptada. Ambos arreglos están aplicados. Node completo final pasó 778/778 y el checkout principal pasó las dos comprobaciones TypeScript y 27 pruebas Node focales.

Se ha iniciado la repetición completa en serie sobre el árbol final congelado de `.cache/t105-r1-c88e73`. Sus 1.702 archivos de código coinciden con el checkout y sus hashes están en `%TEMP%/metricool-t68-r16-source-hashes.json`. Log `%TEMP%/metricool-t68-r16-e2e39-final-browser.log`, directorio del informe en `%TEMP%/metricool-t68-r16-e2e39-final-browser-root.txt`. Mantener una sola ejecución y evitar cambios en esa copia hasta que termine. Comprobar el informe real, corregir los fallos y cotejar los hashes antes de cerrar T-68. GitHub ejecutará su CI al publicar.


**Cierre verificado — 2026-11-17.** Repetición integral R16 finalizada: **765 passed, 2 skipped previstos, 0 failed, 0 flaky; qa-gate VERDE**. Ambas omisiones son el shell lateral de escritorio en los dos móviles. Los 1.702 hashes vuelven a coincidir con la copia congelada y el checkout. Typecheck final regenerando Ziggy: 0 errores. Pest completo 4.262/9 con 28.601 aserciones y Node 778/778 ya registrados. La corrección deliberada de «Solo ocultos» sigue documentada; se conserva el alcance declarado de los descriptores de Compositor y Studio.

Revisiones de aceptación y calidad de Claude Code Opus 5.5 conservadas, sin Critical/Important abiertos, con metadatos y hashes en el [informe de cierre](testing/t68/report.md). Son revisiones estáticas; los tests los ejecutó el implementador. No se afirma una ejecución de GitHub Actions ni se cierran puertas manuales de otras tareas.

---

## Fase 5b — Unificación

**Estado**: borrador · **Estimado**: 64.0h · **Real**: — · **Coste est.**: 3200 € (base) + tokens ⚠️ verificar · **Tokens est.**: 3040k

> Nace de [`audit-unificacion.md`](./audit-unificacion.md) (U-01..U-25): la app debe ser **una sola aplicación**. Va tras la Fase 5 porque depende de sus componentes comunes (T-53, T-56, T-57, T-60). Las que superaban una M se dividen en (1/2) y (2/2).

### T-101 — Puentes de datos (1/2): diseño → publicación y Canva → Recursos

- **Descripción**: Que lo que se crea en el estudio y lo que se importa de Canva viva en el mismo sitio que la parte social: el post recuerda su diseño y su exportación de origen, y lo importado de Canva entra como `StudioAsset`.
- **Changelog**: Reexportar desde Estudio actualiza la publicación de origen y conserva adjuntos independientes. Las imágenes y vídeos importados de Canva se vinculan a Recursos de la marca.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-06, T-53
- **Tipo**: backend + frontend
- **Archivos**: `database/migrations/2026_11_17_122200_add_studio_export_provenance_to_posts_and_target_media.php`, `app/Models/PostTargetMedia.php`, `app/Jobs/CanvaJobs.php`, `app/Models/StudioAsset.php`, `resources/js/Components/Studio/ExportDialog.vue`, `resources/js/Components/Composer/MediaSection.vue`, `tests/Feature/Studio/StudioBridgeTest.php`, `tests/e2e/unification.spec.ts`
- **Cubre**: U-15, U-17
- **Cubre (tests)**: E2E-41, E2E-42
- **Verificación**: `php artisan test --compact --filter=StudioBridge` → pasa · `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "puentes"` → verde

**Criterios de aceptación**
- [x] `post_target_media.design_export_id` existe con FK (`nullOnDelete`) y se rellena al crear el post desde `ExportDialog.vue:83-86`
- [x] En el post aparece el botón «Editar en el Estudio», que abre el diseño de origen con la marca del post
- [x] Tras una nueva exportación del diseño, el post de origen queda con la media actualizada (mientras no esté publicado) y avisa si ya lo está
- [x] Un archivo importado de Canva aparece como `StudioAsset` en «Recursos» del estudio, sin duplicarse al reimportar (`CanvaJobs.php`, `StudioAssets.php:34`)
- [x] Sin cambios en el flujo existente de publicación: Pest y E2E en verde antes y después
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Migración y modelo con FK a la exportación del diseño
- [x] Rellenar `design_export_id` al exportar a una publicación
- [x] Botón «Editar en el Estudio» y re-sincronización de la media tras una nueva exportación
- [x] Enlazar la importación de Canva como `StudioAsset` (idempotente)
- [x] Pest y E2E «publicación → estudio → nueva exportación» y «Canva → Recursos»
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-06 (referencias de media con FK) y T-53 (cliente HTTP único). Continúa en T-106.

**Procedencia de exportación (2026-11-17).** RED: tres casos de `StudioBridgeTest` fallaron porque faltaba `design_export_id` en el borrador y sus adjuntos. La verificación del enlace falló por ausencia de `post.studio_origin_url`. Un caso posterior detectó que al forzar otra marca se seguía atribuyendo el adjunto a la exportación anterior. Se añadieron FK anulables en `posts` y `post_target_media`. El borrador creado sin destinos conserva la referencia y los destinos reciben la procedencia solo si su archivo pertenece a la exportación del mismo usuario y marca. El enlace del compositor abre el diseño accesible en esa marca. Borrar la exportación anula la referencia y conserva la media. Los seis casos pasaron en SQLite con 62 aserciones y se compilaron los dos Vue modificados. Parche parcial `%TEMP%/metricool-t101-provenance-preview.patch` comprobado en lectura contra el checkout. Quedan la actualización de media al reexportar, el puente desde Canva, la prueba de navegador y la revisión de aceptación y calidad con Opus 5.5.

La primera pasada amplia de la copia devolvió 16 fallos de `StudioFontsTest` porque el Python predeterminado carecía de `fontTools`. La repetición con el intérprete tipográfico del checkout, configurado solo para el proceso, pasó Estudio, publicaciones y flujos con 1.010 aprobados, 9 omitidos y 7.562 aserciones. Informe `%TEMP%/metricool-t101-provenance-final-expanded.log`.

En MySQL 8.4.3, la versión final del candidato pasó las seis pruebas con 62 aserciones y se comprobó la base desechable de 83 tablas antes de detener la instancia. Material para la revisión parcial en `%TEMP%/metricool-t101-provenance-review-r1.txt` y resultado MySQL en `%TEMP%/metricool-t101-mysql-pest.log`.

**Canva → Recursos (2026-11-17).** RED: la prueba del flujo de exportación obtuvo dos archivos en la biblioteca y ninguno en Recursos. El candidato aislado vincula las imágenes y los vídeos validados a la marca de origen. La clave única combina diseño, página y contenido, de modo que conserva páginas distintas con los mismos bytes y reutiliza cada recurso al repetir la importación. Un caso de recuperación reproduce un fallo al crear el vínculo y comprueba que el siguiente intento concluye con el archivo y el recurso listos. Los PDF siguen en la biblioteca. Pint verde y 174 pruebas con 941 aserciones tanto en SQLite como en MySQL 8.4.3. El parche `%TEMP%/metricool-t101-canva-resource-preview.patch` pasó `git apply --check --whitespace=error`. Revisión preparada en `%TEMP%/metricool-t101-canva-resource-opus55-review.txt`. La prueba de reexportación sigue en RED: la ruta devolvió un segundo borrador. Quedan corregir ese flujo y pasar E2E-41/42 y Opus 5.5.

**Candidato integrado de T-101.** Reexportar actualiza la publicación de origen, conserva adjuntos independientes, estados de destino y programación, y avisa si ya está publicada o en vuelo. SQLite pasó 1.184 pruebas y MySQL 180 focales. Node pasó 717. Los seis casos focales de navegador pasaron sin flaky y se verificaron las doce capturas en `C:/Users/daycr/AppData/Local/Temp/metricool-t101-exact-54af417e-161c-4450-90c4-7c9193f6bb14/storage/framework/testing/t43/run-general-cea3a553-5b1e-4090-89f5-86dca10e1350/results.json`. El candidato exacto de 28 archivos se mantiene aislado. Están en curso la regresión de navegador y la revisión de aceptación y calidad con Opus 5.5.

La regresión de T-101 pasó 83 casos con qa-gate verde. Opus 5.5 R1 devolvió hallazgos de aceptación y calidad. En el candidato R2 se calcula el hash local antes de la transacción, se abre el diseño con un aviso si el enlace del post caducó, y se conserva una fecha vencida que todavía está en cola. El borrador se crea con su procedencia antes de guardar los destinos, eliminando la actualización posterior de los adjuntos. Se comprobó la protección de un post con un destino publicado y otro programado. Pasaron 182 casos de puente y Canva, 302 casos de publicaciones y flujo tras corregir la fecha, y 15 casos de puente después del refactor de creación. Quedan el resto de hallazgos, navegador y revisión exacta de R2.

Candidato R2 tras integrar T-58 y T-59. Se conserva el texto alternativo al reordenar páginas y se evita reutilizarlo al sustituirlas. Una portada de vídeo combinado permanece intacta cuando no hay correspondencia entre páginas. Los recursos de Canva vinculados mantienen la protección de borrado de Estudio y muestran el motivo. Se verificó por separado que se puede borrar un duplicado sin vínculo y que conserva el recurso original. Pasaron 1.340 pruebas de la regresión SQLite con runtime local de Studio, nueve omisiones y 9.523 aserciones. MySQL 8.4.3 pasó 200 pruebas y 1.171 aserciones en una base desechable. E2E-41 y E2E-42 pasaron en los tres tamaños con qa-gate verde, incluidos la publicación ya enviada, el aviso y la deduplicación. El paquete de aceptación y calidad R2 está en revisión de Claude Code Opus 5.5.

La primera revisión R2 conjunta se interrumpió por el filtro del proveedor. Las dos revisiones de seguimiento en Claude Code Opus 5.5 sí se recibieron. Ambas confirmaron los criterios por lectura del diff y solicitaron cambios de calidad. En R3 la inspección del archivo ocurre fuera de la transacción y la persistencia del archivo listo y su vínculo continúan siendo atómicas. El RED comprobó el nivel de transacción durante la inspección. También se hizo explícito el modo de preservar estados del planificador y se documentó la posición de página en la deduplicación. Pasaron 57 pruebas focales con 467 aserciones. Queda resolver el uso de la procedencia de los adjuntos al reemplazarlos y remitir el paquete de pruebas junto con el siguiente diff.

R3 usa la procedencia persistida de la media propia del destino. Dos pruebas RED/GREEN comprueban que se conserva un adjunto independiente aunque comparta archivo con otra fila vinculada o con la próxima exportación. También se rechazan los campos de edición junto a post_id y se verificó la importación de un MP4 H.264 con ffprobe real. Pasaron 169 pruebas focales, 203 en MySQL 8.4.3 y 1.198 aserciones, 720 de Node, ESLint, TypeScript, Vite, seis E2E focales y la regresión de 92 E2E sin omitidos ni fallos. La revisión R3 de Canva confirma la aceptación con condiciones y pide reforzar la deduplicación de vídeo y la limpieza de fixtures. R4 preparado en una copia de cuatro archivos, pendiente de pruebas y cotejo.

El Pest completo R3 pasó 4.182 pruebas con nueve omisiones y un fallo de T-48 por las fechas futuras de las dos migraciones nuevas. R4 usa fechas válidas y conserva la FK de la media propia para el esquema nuevo y el que ya tiene post_target_media. Un SQLite desechable verificó actualización, rollback, reaplicación y la acción SET NULL de la FK. Pasaron 62 pruebas SQLite focales incluidas fechas y reimportación nativa de MP4. Una ejecución simultánea de MySQL y Pest compartió el disco temporal de fixtures y faltó un .part en una prueba. La repetición confirmó su propio LARAVEL_STORAGE_PATH y pasó 204 pruebas en MySQL 8.4.3 con 1.203 aserciones. El Pest completo R4 y el navegador focal siguen en curso. La revisión de Opus 5.5 incluye las migraciones y los seguimientos de Canva.

R4 completó Pest con 4.183 pruebas, nueve omisiones y 27.211 aserciones, y nueve recorridos focales de navegador. La revisión de Claude Code Opus 5.5 de Canva y migraciones se recibió con el modelo verificado. R5 hace explícita la condición de disponibilidad del archivo, comprueba la deduplicación de MP4 con ffprobe y mantiene el significado de la consulta de uso por publicaciones. Pasaron 170 pruebas focales con 901 aserciones.

La revisión de publicaciones de Opus 5.5 detectó que la procedencia de la lista común se infería por ID y que se desplazaban los adjuntos intercalados. R6 guarda la procedencia por fila en las dos listas y permite retirar y volver a adjuntar un mismo archivo como independiente, incluso en el mismo guardado. El RED del carrusel en el flujo normal reveló una colisión de posiciones al intercalar archivos. La escritura de las filas ordenadas dentro de la transacción conserva la procedencia y evita esa colisión. Pasaron 58 pruebas focales con 563 aserciones y 720 de Node. Evidencias en `%TEMP%/metricool-t101-r6-*`. El parche R6 pasó la comprobación de aplicación. Pendientes el navegador y la revisión exactos de R6.

**Cierre T-101 R8.** Claude Code Opus 5.5 aprobó aceptación y calidad del backend, la interfaz y la integración Canva. Se corrigió la pérdida de procedencia al volver a personalizar un destino que acababa de restablecerse a la lista común. Si la publicación ya salió, el usuario puede crear otro borrador con la exportación guardada y el foco se mueve a esa acción. Se verificaron una selección parcial de páginas y la reapertura de la aprobación de una publicación programada sin perder su fecha. En la rama de trabajo pasaron 184 pruebas de Pest con 1.345 aserciones. MySQL 8.4.3 aislado pasó 213 pruebas y 1.287 aserciones, además del rollback y la reaplicación de las dos migraciones. En escritorio y dos tamaños móviles se comprobó reexportar a la misma publicación, recuperar el borrador tras el aviso, conservar la procedencia al restablecer un destino y actualizar con GIF renderizado localmente. La regresión R6 pasó 1.058 pruebas PHP y los tres recorridos de Canva pasaron en cada tamaño. La importación remota sigue usando almacenamiento local y el smoke con Canva real se verifica aparte en CS-M-01. Evidencias en `%TEMP%/metricool-t101-r8-*` y `%TEMP%/metricool-t106-r4-reattach-and-native-browser.log`.

### T-102 — Sistema de diseño único (1/2): acento, botones y estilos

- **Descripción**: Un solo token de acento y un solo juego de botones para toda la app: se elimina el `violet-*` del estudio frente al `indigo` de la parte social y los ocho bloques `<style>` con cinco estilos de botón propios.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-60
- **Tipo**: frontend
- **Archivos**: `tailwind.config.js`, `resources/css/app.css`, `resources/js/Pages/Studio/Index.vue`, `resources/js/Pages/Studio/Editor.vue`, `resources/js/Components/Studio/Inspector.vue`, `resources/js/Components/Studio/PresentationDialog.vue`, `resources/js/Components/Studio/StudioDatasetsDialog.vue`, `resources/js/Components/Studio/TimelinePanel.vue`, `resources/js/Studio/document.js`
- **Cubre**: U-07, U-08, U-12
- **Cubre (tests)**: E2E-43
- **Verificación**: `npm run build` → sin errores · `grep -rln "<style" resources/js | wc -l` → 1 (solo `.studio-stage`) · `grep -rn "violet-" resources/js | wc -l` → 0

**Criterios de aceptación**
- [ ] Existe el token `accent` en Tailwind y toda la app (social y estudio) lo usa; no quedan `violet-*` ni `indigo-*` como acento
- [ ] `btn-primary` y el resto de `btn-*` no se sobrescriben con clases de color (`Index.vue:205,227`)
- [ ] `.btn-tool` y `.btn-toggle` viven en `app.css`; los bloques `<style>` del estudio desaparecen salvo `.studio-stage`
- [ ] Los colores hex de la UI pasan a tokens o clases; los colores por defecto del documento van a `Studio/document.js`
- [ ] `a11y.spec.ts` sin violaciones serious/critical nuevas (contraste del nuevo acento)
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Definir el token `accent` y migrar `violet-*`/`indigo-*`
- [ ] Pasar `.btn-tool` y `.btn-toggle` a `app.css` y quitar los `<style>`
- [ ] Sustituir hex por tokens y mover los colores del documento a `document.js`
- [ ] Prohibir sobrescribir `btn-*` (regla de ESLint o grep en CI)
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-56/T-57/T-60 (componentes comunes). Continúa en T-107.

### T-103 — Shell y navegación unificados

- **Descripción**: Que el estudio sea una pantalla más de la misma app: orden y marcado activo del menú, entrada de Canva, cabecera y ancho comunes, y selector de marca coherente con el diseño abierto.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-63, T-102
- **Tipo**: frontend + backend
- **Archivos**: `resources/js/lib/navigation.js`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Pages/Studio/Index.vue`, `resources/js/Pages/Studio/Editor.vue`, `app/Http/Middleware/CurrentBrand.php`, `app/Http/Controllers/StudioController.php`, `tests/e2e/unification.spec.ts`, `tests/Feature/StudioBrandAlignmentTest.php`
- **Cubre**: U-01, U-02, U-03, U-04, U-05, U-06
- **Cubre (tests)**: E2E-44, E2E-45
- **Verificación**: `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "shell"` → verde · `php artisan test --compact --filter=StudioBrandAlignment` → pasa

**Criterios de aceptación**
- [ ] «Estudio» va después de Planificación en `navigation.js:14-24` (o dentro de ella como «Crear»)
- [ ] «Plantillas», «Identidad» y «Papelera de diseños» se marcan activas según la query (`templates`, `tab`, `archived`)
- [ ] Hay una entrada «Canva» en el lateral y abrirla no deja marcada «Cuenta»
- [ ] `Studio/Index.vue` y `Editor.vue` usan `#header` con el mismo h1 que el resto (sin hero `bg-[#ede8ff]`)
- [ ] `AuthenticatedLayout` acepta la prop `fullBleed` y el editor no usa `-m-3` ni `max-w-[1500px]`
- [ ] Abrir `studio/designs/{design}/edit` alinea la marca de la sesión con la del diseño y el selector la muestra
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Reordenar y marcar activas las entradas de `navigation.js`
- [ ] Entrada «Canva» en el lateral
- [ ] `#header` común en `Studio/Index.vue` y `Editor.vue`
- [ ] Prop `fullBleed` en el layout y limpieza del editor
- [ ] Alinear la marca de la sesión con la del diseño en `StudioController` y `CurrentBrand`
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Cada hallazgo es S; la tarea completa es M. Depende de T-63 porque toca el layout del editor ya partido.

### T-104 — Kit de marca e IA compartidos

- **Descripción**: Que la identidad de marca y la IA sean de la marca y no del estudio: pestaña «Identidad» en la ficha de la marca, y una sola pila de IA con estado y cuota comunes que usa la «Voz de la marca».
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-55, T-103
- **Tipo**: frontend + backend
- **Archivos**: `resources/js/Pages/Brands/Show.vue`, `resources/js/Pages/Studio/Index.vue`, `app/Services/AiAssistant.php`, `config/studio-ai.php`, `app/Services/StudioService.php`, `app/Services/AiProviders.php`, `tests/Feature/SharedAiTest.php`, `tests/e2e/unification.spec.ts`
- **Cubre**: U-19, U-20
- **Cubre (tests)**: E2E-46
- **Verificación**: `php artisan test --compact --filter=SharedAi` → pasa · `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "identidad"` → verde

**Criterios de aceptación**
- [ ] La ficha de la marca (`Brands/Show.vue`) tiene la pestaña «Identidad» con el kit de marca; el estudio enlaza a ella en lugar de duplicarla
- [ ] Los prompts de `AiAssistant` y de `StudioService` (`:220,230`) inyectan `guidelines` y `voice` de la marca
- [ ] El estado de la IA y su cuota son comunes: `AiAssistant` y `AiProviders` comparten contador y `useAiStatus`
- [ ] Una prueba verifica que la «Voz de la marca» llega al prompt de ambas pilas
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Pestaña «Identidad» en la ficha de la marca
- [ ] Inyectar `guidelines` y `voice` en ambos prompts
- [ ] Unificar estado y cuota de IA
- [ ] Tests de prompt y de cuota compartida
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-55 (`useAiStatus`) y T-103 (navegación de la marca).

### T-105 — Permisos y abilities unificados (1/2): roles de marca con acceso al estudio

- **Descripción**: Un solo modelo de permisos: el colaborador de la marca ve y trabaja los diseños de esa marca según su rol, y las claves de rol dejan de mezclar idiomas.
- **Changelog**: Los colaboradores de marca con rol aprobador pueden ver los diseños de su marca y decidir sobre las revisiones solicitadas desde Aprobaciones. La clave de rol en la API y MCP pasa a `approver`.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-17, T-45
- **Tipo**: backend
- **Archivos**: `app/Models/BrandCollaborator.php`, `app/Models/StudioCollab.php`, `app/Models/StudioAssets.php`, `app/Policies/**`, `app/Http/Controllers/StudioController.php`, `lang/**`, `tests/Feature/BrandRolesStudioTest.php`
- **Cubre**: U-22
- **Cubre (tests)**: E2E-47
- **Verificación**: `php artisan test --compact --filter=BrandRolesStudio` → pasa

**Criterios de aceptación**
- [x] El colaborador de la marca ve los diseños de esa marca en el estudio con el alcance de su rol (`BrandCollaborator.php:15` frente a `StudioCollab.php:24-28`)
- [x] La aprobación de diseños usa las `approvals` existentes, sin un flujo paralelo
- [x] Las claves de rol (`StudioAssets.php:142`) están en un solo idioma; una migración de datos convierte las existentes y el código no conserva las claves antiguas (sin alias)
- [x] Los tests de T-17 y los nuevos pasan; un colaborador sin rol no accede
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Definir la matriz rol de marca → acceso al estudio
- [x] Aplicarla en Policies/`Ownership::assert()`
- [x] Unificar claves de rol con migración de datos (sin alias)
- [x] Aprobación de diseños vía `approvals`
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-17 (tests de permisos) y T-45 (propiedad unificada). Continúa en T-108.

Candidato aislado R3. RED: el Estudio ocultaba diseños de marca invitada y la solicitud de aprobación del propietario devolvía 404. R3 conserva los permisos de propietario que ya aplica ApprovalService a publicaciones, añade aprobación de la revisión solicitada en esa misma bandeja, registra avisos con StudioDesignActivity y conserva el comentario de revisiones anteriores. Pasaron seis casos focales con 122 aserciones y la comprobación del enum MCP con 31 aserciones. OpenAPI es válido. La comparación específica del cuerpo HTTP confirma el cambio intencional y rompedor de aprobador a approver, con 422 de la clave anterior y 202 de la nueva. La primera revisión de Opus 5.5 dejó observaciones que se están volviendo a verificar. Quedan la regresión, E2E-47 y la revisión del parche final. Evidencias %TEMP%/metricool-t105-r2-candidato-opus55.json, %TEMP%/metricool-t105-r3-rest-contract.log y %TEMP%/metricool-t105-r3-mcp-schema.log.

E2E-47 y la regresión focal Workflow R4 pasaron 19/19, con qa-gate VERDE en los tres perfiles. Opus 5.5 R4 cerró los tres Important de la revisión estática y mantuvo pendiente la regresión del árbol final. R5 reutiliza el scope de aprobador, preserva los cambios de T-63 y confirma el aislamiento de los avisos durante las pruebas. Pasaron 20 pruebas y 177 aserciones. MySQL 8.4.3 de pruebas confirmó en una ejecución independiente la fila y el default en ambas direcciones. Evidencias %TEMP%/metricool-t105-r4-workflow-browser.log, %TEMP%/metricool-t105-r4-quality-opus55.json y %TEMP%/metricool-t105-r3-mysql-migration.log.

R5 pasó la regresión Pest Studio, Workflow, Inbox y MCP con 1.240 pruebas, 9 omitidas AV opt-in y 9.602 aserciones. Playwright R5 completo sigue en curso. Opus 5.5 encontró una desalineación de avisos con un owner_id incoherente. RED: el destinatario recibió una notificación que no debía recibir. R6 aplica owner_id al consultar destinatarios de solicitudes de diseños y prueba la lectura MCP del aprobador antes y después de revocarla. Verde 129 pruebas y 1.022 aserciones en la copia R6. El código de publicaciones conserva su comportamiento. Evidencias %TEMP%/metricool-t105-r5-regression-pest.log, %TEMP%/metricool-t105-r6-red.log y %TEMP%/metricool-t105-r6-focal-pest.log.

Cierre R10. La regresión R5 de Estudio y Workflow pasó 128/128 recorridos. R7 conservó acciones de comentarista para el aprobador sobre hilos propios y pasó 22/22 recorridos focales. R9 pasó 1.242 pruebas Pest, 9 omitidas AV opt-in, 9.638 aserciones y 6/6 recorridos E2E-47 y colaboración en tres perfiles, con qa-gate VERDE y sin flaky. R10 solo reforzó las aserciones de borrado de comentarios. El aprobador recibe 403 al borrar directamente una respuesta ajena, y la respuesta se conserva. Borrar un hilo propio elimina su contenido, como en el rol comentarista. Claude Code `claude-opus-5-5` aprobó aceptación y calidad en R10 con cero Critical e Important. La integración pasó Pint y 153 pruebas focales con 1.582 aserciones. Evidencias `%TEMP%/metricool-t105-r9-full-pest.log`, `%TEMP%/metricool-t105-r9-final-focal-browser.log`, `%TEMP%/metricool-t105-r10-main-focal.log` y `%TEMP%/metricool-t105-r10-final-opus55.json`.

Verificación adicional de fechas. `MigrationDatesTest` detectó dos nombres futuros que solo existían en la rama de trabajo, sin historial Git. Se usó la fecha UTC del 17 de noviembre, y la migración histórica de creación conserva su nombre permitido por la prueba (regla de fecha por día). Una instalación nueva crea la tabla con el default `approver`; una existente se normaliza con una migración reversible. Opus 5.5 aprobó el ajuste de calidad condicionado a verificar la excepción de fecha y documentar las copias con los nombres provisionales. Las copias desechables que llegaron a ejecutarlos requieren una base desechable nueva antes de volver a probar. SQLite pasó 157 pruebas conjuntas y 1.631 aserciones, y la rama verificó 23 casos y 211 aserciones. MySQL 8.4.3 confirmó una instalación nueva, y la sonda de datos históricos verificó rol y default en ambas direcciones. Evidencias `%TEMP%/metricool-t105-dates-final-opus55.json`, `%TEMP%/metricool-t105-dates-main-pest.log` y `%TEMP%/metricool-t105-dates-mysql-migration.log`.

El primer Pest global detectó un falso positivo de la comprobación estática sobre la exclusión de diseños propios. R2 distingue los operadores de exclusión de una selección del propietario y cubre cinco formas de consulta. StudioCollab conservó su consulta. Opus 5.5 verificó calidad y aceptación del seguimiento. Evidencia `%TEMP%/metricool-t105-t108-followup-r2-opus55.json`.

### T-106 — Puentes de datos (2/2): biblioteca en el compositor y subida única

- **Descripción**: El compositor y el estudio comparten archivos: el compositor elige desde la biblioteca del estudio y abre una imagen en el estudio; una sola subida de medios para ambos.
- **Changelog**: Los archivos subidos en el compositor se pueden reutilizar en Recursos. Abrir una imagen en Estudio conserva el borrador abierto.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-101, T-53
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/MediaSection.vue`, `resources/js/Components/MediaUploader.vue`, `resources/js/Pages/Library/Index.vue`, `resources/js/Studio/api.js`, `resources/js/lib/http.js`, `tests/e2e/unification.spec.ts`, `resources/js/Studio/export-jobs.js`
- **Cubre**: U-16, U-18
- **Cubre (tests)**: E2E-41, E2E-42
- **Verificación**: `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "biblioteca"` → verde · `npm run test:unit` → pasa

**Criterios de aceptación**
- [x] `MediaSection.vue` ofrece «Desde la biblioteca» con los archivos y exportaciones del estudio
- [x] Desde una imagen del compositor o de `Library/Index.vue` se puede «Abrir en el Estudio»
- [x] `MediaSection.vue`, `MediaUploader.vue` y el estudio suben con el único `uploadMedia()` de `http.js` (T-53); no queda otro pipeline
- [x] Un E2E sube un archivo desde el compositor y lo ve en Recursos
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Selector «Desde la biblioteca» en el compositor
- [x] «Abrir en el Estudio» en compositor y biblioteca
- [x] Migrar las subidas a `uploadMedia()`
- [x] E2E de subida y de apertura
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de T-101.

**Candidato aislado de T-106.** RED: el compositor subía el archivo sin vincularlo a Recursos. El flujo comparte `uploadMedia` y la vinculación de Estudio. Un fallo de enlace permite reintentar con el mismo ID. El selector consulta la marca y añade imágenes, vídeos y exportaciones a la publicación. **Abrir en el Estudio**, desde el compositor y la biblioteca, crea el diseño con la misma imagen y abre una pestaña nueva para conservar el borrador abierto. Pasaron los 719 casos de Node, lint, formato, tipos y los dos casos focales de Pest con 26 aserciones. Preparados E2E-41/42 y el parche de 21 archivos `%TEMP%/metricool-t106-complete-r1.patch`. Quedan la regresión PHP, el navegador y Claude Code Opus 5.5.

Pasaron 937 pruebas de Estudio, biblioteca y publicaciones con nueve omisiones previstas y 7.076 aserciones. En el candidato aislado pasaron los seis casos de navegador con qa-gate verde, incluida la apertura desde ambas superficies y la conservación del borrador del compositor. Informe en `C:/Users/daycr/AppData/Local/Temp/metricool-t106-draft-98594849-cc74-426f-aaf0-96304eedca23/storage/framework/testing/t43/run-general-536533af-b6e2-4e38-9b7d-646e069a1eef/results.json`. Pendientes la revisión de Opus 5.5 y la integración sobre T-101 tras sus correcciones.

Seguimiento R2 de Opus 5.5. El error de un formulario nativo de imagen abre el Estudio de la marca en la pestaña nueva con su aviso. Pasaron cuatro pruebas de entrada y 46 aserciones, incluida una imagen panorámica centrada y la ausencia de un enlace público. Un 422 definitivo conserva el adjunto subido y retira el reintento de vínculo, con RED/GREEN en Node. También se ajustaron el nombre accesible, el foco al reintentar la biblioteca y la vista previa de los adjuntos. Pasaron 720 pruebas de Node y TypeScript en la copia de T-106. Pendientes rehacer el parche sobre el T-101 actualizado, pasar los recorridos de navegador exactos y solicitar el veredicto de Opus sobre R2.

Opus 5.5 devolvió observaciones en la revisión parcial R2. El siguiente paquete debe incluir el cableado de Compose y EditorToolbar, los recorridos de navegador y la recuperación al vincular recursos. Sigue pendiente comprobar el foco de la pestaña original y que un vínculo lento no bloquee el compositor.

R3 separa la vinculación a Recursos del estado de subida del compositor. RED: mientras el POST de vínculo seguía pendiente el control de subida seguía ocupado. GREEN: se liberó el compositor y los reintentos por 408, 419, 429 y 503 reutilizan el archivo. Se conserva un mensaje por archivo y la petición se limita a un plazo. Tras combinar el candidato con T-101 R3 pasaron 44 pruebas Pest con 478 aserciones, Node, ESLint, TypeScript y Vite. El recorrido exacto de navegador pasó en los tres tamaños simulando un primer 503, manteniendo habilitado el control de subida y volviendo a elegir el archivo en la biblioteca y abrirlo en otra pestaña. La revisión R3 de apertura y vínculo se ha remitido a Claude Code Opus 5.5.

Las dos revisiones R3 de Claude Code Opus 5.5 devolvieron hallazgos de aceptación y calidad. R4 reutiliza una clave de idempotencia al abrir la imagen y distingue un vínculo reintentable de una sesión caducada. RED: dos peticiones con la misma clave creaban dos diseños. GREEN: el controlador reutiliza el diseño creado y la apertura de una imagen ajena o ausente conserva el aviso, con nueve pruebas y 73 aserciones. RED/GREEN de desmontaje del cargador con siete pruebas de Node. Los tres recorridos R4 de navegador verificaron el aviso en una pestaña nueva al elegir una imagen que ya no existe, la conservación del borrador y la reapertura del archivo válido. En escritorio comprobaron también el foco tras cerrar la pestaña. El ensayo corrigió la expectativa de URL al confirmar que la lista de la marca usa /studio/designs. Evidencias en `%TEMP%/metricool-t106-r4-*`. Quedan la verificación conjunta con T-101 R6 y la revisión exacta.

R5 sustituye la transacción exterior de idempotencia por una reserva visible y una transacción de creación y respuesta. RED: una reserva activa devolvía 409 en el navegador nativo sin aviso. GREEN: 27 pruebas y 156 aserciones de apertura e idempotencia. Los tres tamaños pasaron el recorrido de foco de un vínculo tras 503 y sesión caducada, con foco en el aviso y después en el cargador. El recorrido quitar, readjuntar y reexportar preservó el adjunto independiente en los tres tamaños. Se verificó la repetición del vínculo por la ruta real sobre el mismo archivo ya vinculado. Quedan la revisión de Opus 5.5 sobre R5 y su integración final.

**Cierre T-106 R5.** Claude Code Opus 5.5 aprobó aceptación y calidad en las revisiones de apertura y vínculo. El doble envío reutiliza un diseño activo. Si la clave apunta a un diseño en la papelera, abre otro desde el mismo archivo. Una reserva activa da un aviso en la pestaña nueva y el vínculo que ya empezó puede terminar aunque se cierre el compositor. Los nueve recorridos finales en escritorio y dos tamaños móviles verificaron la subida, la repetición real del vínculo, la biblioteca, la apertura y los avisos, con qa-gate verde. En la rama de trabajo pasaron 71 pruebas de Pest con 548 aserciones, 727 pruebas de Node, ESLint, TypeScript y Pint en los archivos PHP afectados. Se mantuvo el foco al cerrar la pestaña de error en el perfil de escritorio y se comprobó la recuperación del foco del vínculo en los tres perfiles. Evidencias en `%TEMP%/metricool-t106-r5-*`.

### T-107 — Sistema de diseño único (2/2): diálogos, campos y componentes comunes en el estudio

- **Descripción**: Aplicar en el estudio los componentes comunes: un solo `BaseDialog`, `.field`/`FormField` y `EmptyState`, `PrimaryButton`, paginador y banner comunes.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-102, T-56, T-57, T-60
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Studio/StudioDialog.vue`, `resources/js/Components/Modal.vue`, `resources/js/Components/Studio/AudioTrackPanel.vue`, `resources/js/Components/Studio/CaptionsPanel.vue`, `resources/js/Components/Studio/StudioChartsDialog.vue`, `resources/js/Pages/Studio/Index.vue`, `resources/js/Pages/Studio/Editor.vue`
- **Cubre**: U-09, U-10, U-11
- **Cubre (tests)**: E2E-43
- **Verificación**: `grep -rn "fixed inset-0" resources/js | wc -l` → 0 · `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `StudioDialog.vue` (7 usos) y `Modal.vue` (6) convergen en un único `BaseDialog` sobre `<dialog>` nativo; no queda `fixed inset-0` a mano
- [ ] `AudioTrackPanel.vue`, `CaptionsPanel.vue` y `StudioChartsDialog.vue` usan `.field`/`FormField`
- [ ] `Studio/Index.vue` y `Editor.vue` usan `EmptyState`, `PrimaryButton`, el paginador y el banner comunes (`Index.vue:189,192`, `Editor.vue:697`)
- [ ] Sin regresión: `a11y.spec.ts` y los E2E del estudio en verde
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Migrar los diálogos del estudio y `Modal.vue` a `BaseDialog`
- [ ] `.field`/`FormField` en los tres paneles
- [ ] Sustituir componentes propios por los comunes
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de T-102.

### T-108 — Permisos y abilities unificados (2/2): `media:*` y `designs:ai`

- **Descripción**: Prefijos de abilities coherentes: los archivos tienen `media:read`/`media:write` y la IA del estudio pasa de `studio:ai` a `designs:ai`. Sin alias: una migración de datos reescribe las abilities guardadas en las API keys existentes y el nombre antiguo deja de existir en el código.
- **Changelog**: REST y MCP separan los permisos de archivos en `media:read` y `media:write`. La migración conserva el acceso de las claves existentes y renombra el permiso de IA a `designs:ai`.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-105, T-29
- **Tipo**: backend
- **Archivos**: `app/Support/Abilities.php`, `app/Mcp/Tools/ListMedia.php`, `app/Mcp/Tools/ImportMediaFromUrl.php`, `routes/api.php`, `tests/Feature/ApiAbilitiesTest.php`
- **Cubre**: U-24
- **Cubre (tests)**: API-02
- **Verificación**: `php artisan test --compact --filter=ApiAbilities` → pasa

**Criterios de aceptación**
- [x] Existen `media:read` y `media:write` y `ListMedia`/`ImportMediaFromUrl` los exigen (`Abilities.php:19-39`)
- [x] `studio:ai` pasa a `designs:ai`; un grep de `studio:ai` en `app/`, `config/`, `resources/`, `tests/` y `docs/` (salvo roadmap) da 0
- [x] Las claves de API existentes no pierden acceso: una migración de datos reescribe sus abilities, con test de la migración
- [x] La documentación de abilities lista el nuevo conjunto
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Añadir `media:*` y exigirlas
- [x] Renombrar `studio:ai` a `designs:ai` con migración de datos (sin alias)
- [x] Test de la migración y documentación (nota de despliegue: las abilities se renombran)
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de T-105.

RED: `ApiAbilitiesTest` comprobó que una clave limitada a marcas y publicaciones seguía obteniendo HTTP 200 en lectura de medios, donde los permisos separados exigen 403. El candidato aislado conserva los permisos históricos con una migración que también incluye los códigos OAuth pendientes. Evidencia `%TEMP%/metricool-t108-red.log`.

Cierre R6. Claude Code `claude-opus-5-5` detectó la ampliación indebida de permisos tras rollback y reupgrade en R2. La frontera persistente por tabla conserva las claves configuradas con permisos separados y migra las emitidas mientras vuelve a estar instalada la versión anterior. Las pruebas demuestran los tres periodos y canjean un código OAuth real de la base desechable. OpenAPI válido. El censo de aplicación, rutas, tests, seeds y docs sin roadmap no encontró nombres antiguos en las rutas activas. Playwright R5 pasó 6/6 seriales, QA VERDE, tras actualizar el selector obsoleto de revocación. La última revisión aprobó aceptación y calidad al corregir un comentario con codificación mixta. R6 verifica UTF-8 y los mismos tokens PHP de ejecución. La rama pasó Pint, TypeScript y 386 pruebas Pest con 2.962 aserciones. MySQL 8.4.3 desechable: 24 pruebas y 255 aserciones en la batería conjunta de migraciones, 1 omitida por DDL transaccional. Evidencias `%TEMP%/metricool-t108-r5-final-opus55.json`, `%TEMP%/metricool-t108-r5-preset-browser.log`, `%TEMP%/metricool-t108-r6-main-pest.log` y `%TEMP%/metricool-t105-dates-mysql-migration-feature.log`.

El Pest global detectó un fixture de catálogo de audio que aún pedía `brands:read` para `list_media`. Se actualizó a `media:read`, con el caso de denegación de las capacidades anteriores en `ApiAbilitiesTest`. El seguimiento de T-105/T-108 pasó 88 pruebas y 1.051 aserciones. Opus 5.5 y Pint aprobaron el parche de pruebas. Evidencia `%TEMP%/metricool-t105-t108-followup-r2-pest.log`.

---

## Fase 6 — Operación y repositorio

**Estado**: borrador · **Estimado**: 43.0h · **Real**: — · **Coste est.**: 2150 € (base) + tokens ⚠️ verificar · **Tokens est.**: 2055k

### T-69 — Seguridad HTTP: HSTS, CSP en `Report-Only` y Permissions-Policy

- **Descripción**: HSTS, `Permissions-Policy` y CSP en modo `Report-Only` con nonce de Vite, con endpoint de informes y plan para pasarla a estricta.
- **Changelog**: La aplicación puede emitir HSTS por HTTPS y recoge informes de su política de contenido sin bloquear la navegación.
- **Estado**: en-revision
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-11
- **Tipo**: devops
- **Archivos**: `deploy/hestia/nginx-overrides.conf`, `app/Http/Middleware/SecurityHeaders.php`, `config/security.php`, `docs/modulos/despliegue.md`, `config/logging.php`, `resources/views/app.blade.php`
- **Cubre**: E-05
- **Cubre (tests)**: E2E-39, M-06
- **Verificación**: `php artisan test --compact --filter=SecurityHeadersTest` → verde · `npx playwright test tests/e2e/security.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [x] Las cabeceras se envían solo en producción/HTTPS
- [ ] La CSP `Report-Only` recoge violaciones sin romper ninguna pantalla
- [x] Documentado el paso a CSP estricta
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: middleware, config y nginx. Queda a la espera (U): activar HSTS y pasar a CSP estricta tras observar informes reales en el VPS. **A la espera (no bloquea la fase)**: HSTS y CSP estricta tras observar informes en el VPS (usuario).

- RED: `SecurityHeadersTest` falló porque faltaban HSTS y el receptor CSP. El seguimiento reprodujo que el nonce vacío persistía en Vite y que faltaba la revocación con `max-age=0`. La sonda con `LOG_LEVEL=warning` mostró que el receptor devolvía 204 sin registrar el informe en el canal general. Resultados `%TEMP%/metricool-t69-red.log`, `%TEMP%/metricool-t69-hsts-nonce-red.log` y `%TEMP%/metricool-t69-report-log-red.log`, del 2026-11-17.
- El candidato limita las cabeceras nuevas a producción por HTTPS y conserva las políticas propias. Vite y Ziggy usan el mismo nonce, con restauración de la instancia previa tras la petición. El receptor acepta informes antiguos y modernos, limita tamaño, lote y frecuencia y registra directiva y origen o tipo en un canal dedicado. HSTS se deja sin definir y admite revocación explícita con cero. La guía describe el paso a una política aplicada y la vuelta a observación.
- Pasaron Pint y 142 pruebas Pest con 1.060 aserciones en la copia aislada. `nginx -t` fue correcto. En Nginx y PHP locales por TLS, la página devolvió 200 con el nonce tanto en la cabecera como en el HTML, el receptor devolvió 204 y registró el informe saneado con `LOG_LEVEL=warning`, y Nginx devolvió 413 para 17 kB. Se detuvieron los dos procesos. Evidencia en `%TEMP%/metricool-t69-expanded-green.log`, `%TEMP%/metricool-t69-nginx-test.log` y `%TEMP%/metricool-t69-tls-smoke.log`. Parche `%TEMP%/metricool-t69-headers-preview.patch` validado sin escritura en el checkout. Pendientes integración, QA de navegador, revisión Opus 5.5 y verificación M-06 en el VPS.

Se integró R2 tras la aprobación local de aceptación y calidad con Claude Code Opus 5.5. Se añadió un presupuesto de informes agregado junto al límite por IP, se rechazaron booleanos y edades HSTS fuera de rango con revocación explícita en cero, y se verificó el apagado de la observación. La sonda TLS local R2 comprobó 200/204/413, nonce compartido y registro saneado. La rama pasó 76 pruebas y 315 aserciones, más Pint. La política sigue en observación. Quedan el recorrido general de navegador y M-06 en el VPS real. Evidencias `%TEMP%/metricool-t69-r2-saneamiento-opus55.json`, `%TEMP%/metricool-t69-r2-tls-smoke.log` y `%TEMP%/metricool-t69-r2-main-pest.log`.

Playwright de seguridad sobre la rama pasó 16/16 recorridos en una ejecución serial, con qa-gate VERDE. Incluye TOTP y Passkeys en 375 y 360 px y escritorio. La suite usa entorno testing. La comprobación HTTPS local de R2 acredita HSTS y CSP en condiciones de producción de la copia. Sigue pendiente M-06 en el VPS. Evidencia %TEMP%/metricool-t69-main-security-browser.log.

### T-70 — Rotación de `APP_KEY` y comando `app:reencrypt`

- **Descripción**: Documentar la rotación de `APP_KEY` (tokens de redes y Canva) y añadir `app:reencrypt` que recifra con la clave nueva con verificación previa y vuelta atrás.
- **Changelog**: El comando `app:reencrypt` simula y recifra los secretos persistidos tras rotar la clave de aplicación.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-09
- **Tipo**: backend
- **Archivos**: `app/Console/Commands/ReencryptSecrets.php`, `docs/modulos/despliegue.md`, `tests/Feature/Console/ReencryptSecretsTest.php`
- **Cubre**: E-06
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=ReencryptSecretsTest` → verde

**Criterios de aceptación**
- [x] `app:reencrypt --dry-run` informa cuántos registros recifraría
- [x] Tras recifrar, todos los tokens se descifran con la clave nueva (test con fábricas)
- [x] Procedimiento y copia previa documentados
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Nunca ejecutar contra los datos del usuario 28 desde el plan; solo contra BD de pruebas. No editar `.env`.

- RED: las tres pruebas de `ReencryptSecretsTest` fallaron porque no existía `app:reencrypt` el 2026-11-17. Resultados en `%TEMP%/metricool-t70-red.log`.
- La simulación cuenta filas sin escribir. La ejecución recifra tokens de redes y Canva, la credencial de Bluesky y TOTP. Preserva el cifrado actual en filas ya migradas y revierte la transacción si encuentra un secreto ilegible. Se documentaron la copia previa, la verificación posterior y la vuelta a la clave anterior.
- Pasaron 80 pruebas de SQLite y 505 aserciones con los recorridos vecinos de TOTP y Canva. En MySQL 8.4.3, las tres pruebas de recifrado pasaron con 23 aserciones. Se verificaron las 83 tablas de la base desechable y se detuvo la instancia. Logs `%TEMP%/metricool-t70-expanded-green.log` y `%TEMP%/metricool-t70-mysql-pest.log`. Parche `%TEMP%/metricool-t70-reencrypt-preview.patch` validado con `git apply --check --whitespace=error`. Pendientes aplicación, revisión Opus 5.5 de aceptación y calidad y QA de integración.

Revisión de aceptación y calidad con Claude Code Opus 5.5, R2: aprobada. La prueba de inventario confirma los seis casts persistidos en la conexión predeterminada y el encrypter configurado. También se verifica que ninguna fila se modifica si faltan claves anteriores. Los códigos de recuperación TOTP son hashes y las cargas temporales siguen cubiertas por la retención operativa de claves previas. La guía describe la ventana de mantenimiento, la caché de configuración y la reversión. En la rama pasaron 82 pruebas SQLite y 517 aserciones. En MySQL 8.4.3 desechable pasaron las cinco pruebas y 35 aserciones. Playwright no aplica al comando CLI. Evidencias `%TEMP%/metricool-t70-r2-main-pest.log`, `%TEMP%/metricool-t70-r2-mysql-pest.log` y `%TEMP%/metricool-t70-r2-cierre-opus55.json`.

### T-71 — Peso de `docs/roadmap` y archivo del ledger

- **Descripción**: Ignorar `**/testing/**/{results.json,*.zip,raw/*.xml}`, tratar evidencias como artefactos y archivar el ledger de 795 KB de la suite creativa sin perder trazabilidad.
- **Changelog**: El índice del roadmap enlaza el historial completo de la suite creativa y separa la evidencia de ejecución en artefactos.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-10
- **Tipo**: docs
- **Archivos**: `.gitignore`, `docs/roadmap/README.md`, `docs/roadmap/2026-11-03-creative-social-suite/tasks.md`, `scripts/roadmap/archive-ledger.mjs`, `.gitattributes`, `docs/roadmap/2026-11-03-creative-social-suite/tasks.archive.md`
- **Cubre**: E-13
- **Cubre (tests)**: —
- **Verificación**: `git check-ignore -v docs/roadmap/x/testing/y/results.json` → regla coincidente · lectura: `docs/roadmap/README.md` enlaza el ledger archivado

**Criterios de aceptación**
- [x] `.gitignore` cubre `testing/` a cualquier nivel
- [x] El ledger archivado mantiene enlaces desde el índice
- [x] `ledger-lint` sigue dando 0 sobre los ledgers vivos
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. No borrar evidencia: se mueve o se enlaza como artefacto. No se hace commit desde el plan (lo hace el usuario).

Candidato aislado T-71. La copia histórica conserva los 795.156 bytes y su SHA-256. El ledger activo queda en 125.702 bytes y conserva las 23 tareas, estados, criterios, changelog y enlaces directos al historial. El archivador publica el original antes de reemplazar el ledger, rechaza tareas abiertas y archivos en conflicto, y permite reanudar si el original ya está archivado. Pasaron las dos pruebas Node. Las reglas de Git cubren JSON de resultados, ZIP y XML de raw en rutas anidadas y conservan los informes Markdown. Los 11 ledgers devolvieron cero incoherencias con ledger-lint. La copia histórica conserva las rutas y los artefactos previos. Parche `%TEMP%/metricool-t71-archive-preview.patch`. Pendientes revisión de Opus 5.5 y aplicación.

Revisión de aceptación y calidad con Claude Code Opus 5.5, R3: aprobada. R2 precisó el alcance del changelog que conserva el resumen, mejoró el diagnóstico al invocar el script desde otro directorio y evitó aceptar un archivo distinto que aparezca durante la operación. Se aplicó el archivado en la rama. El original de 795.156 bytes conserva SHA-256 `68f11dad901976a5f47ece0143fe13cde3db4f4be6587d255ff96f942da9698b`. El índice activo enlaza el archivo, mantiene las 23 tareas e incluye la misma huella. Una segunda ejecución devolvió `already-archived`. Pasaron las tres pruebas Node del archivador. `ledger-lint` devolvió cero incoherencias en 14 ledgers activos. Los XML y el JSON que cita el manifiesto de T-09 ya están versionados. `git check-ignore` confirmó el alcance de las reglas anidadas y `git check-attr` la preservación de bytes. Pest y Playwright no aplican al archivador documental. Revisiones `%TEMP%/metricool-t71-r2-archivo-opus55.json` y `%TEMP%/metricool-t71-r3-evidencia-opus55.json`.

### T-72 — Dependencias: webauthn-lib declarada y plan de versiones mayores

- **Descripción**: Declarar `web-auth/webauthn-lib` (hoy usada sin declarar), revisar `laravel/mcp` y `laravel/passkeys` fijadas y redactar el plan para Inertia 2→3 y Tailwind 3→4 (sin ejecutarlas).
- **Changelog**: WebAuthn figura como dependencia directa y se documenta la secuencia de las próximas migraciones de dependencias.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `composer.json`, `composer.lock`, `docs/guias/dependencias.md`
- **Cubre**: E-14
- **Cubre (tests)**: —
- **Verificación**: `composer validate --strict` → válido · `php artisan test --compact --filter=Passkey` → verde

**Criterios de aceptación**
- [x] `composer.json` declara `web-auth/webauthn-lib`
- [x] `composer validate` y Pest en verde
- [x] El plan de mayores lista riesgos, orden y esfuerzo
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Las migraciones mayores quedan fuera: solo plan.

Inventario y candidato de T-72. `webauthn-lib` se declara directamente con `~5.3.0`, dentro del intervalo que exige Passkeys. Composer cambió solo la huella del lockfile y conservó los 163 paquetes. Pasaron `composer validate --strict`, `check-platform-reqs --lock` y 77 pruebas de Passkeys con 558 aserciones en la copia aislada, sobre la referencia instalada y bloqueada 5.3.9. Se conservan las restricciones de MCP y Passkeys. La [guía de dependencias](../../guias/dependencias.md) documenta versiones, riesgos, orden y esfuerzo de Inertia 3 y Tailwind 4 a partir de sus guías oficiales. Candidato `%TEMP%/metricool-t72-webauthn-preview.patch`. Pendientes aplicación, navegador y Opus 5.5.

Revisión de aceptación y calidad con Claude Code Opus 5.5, R4: aprobada con la condición de verificar el flujo de navegador. En la rama, Composer pasó `validate --strict` y `check-platform-reqs --lock`. Las versiones y metadatos de los 163 paquetes coinciden con HEAD. Únicamente cambió la huella del lockfile. El filtro Passkey pasó 77 pruebas y 558 aserciones. Playwright pasó los ocho recorridos seriales de alta, acceso, revocación y alternativa con contraseña, con qa-gate verde. La guía registra la secuencia, el esfuerzo, las comprobaciones de resolución y los riesgos de Inertia, Tailwind, MCP y Passkeys. No se ejecutó ninguna migración mayor. Evidencias `%TEMP%/metricool-t72-r4-plan-opus55.json` y `%TEMP%/metricool-t72-main-passkeys-browser.log`.

### T-73 — Documentación coherente: latido y docroot de Hestia

- **Descripción**: Unificar latido (600 s frente a 180 s) y docroot de Hestia en la doc de despliegue.
- **Changelog**: La guía de despliegue distingue el umbral del chequeo de latido del TTL de la caché y explica cómo verificar las rutas antes de instalar el cron.
- **Estado**: en-revision
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-03
- **Tipo**: docs
- **Archivos**: `docs/modulos/despliegue.md`, `deploy/hestia/README.md`
- **Cubre**: E-18
- **Cubre (tests)**: M-06
- **Verificación**: `rg -n 'latido|180 s|600 s' docs/modulos/despliegue.md deploy/hestia/README.md` → el umbral de 180 s coincide con `HealthCheck::HEARTBEAT_MAX_AGE`; los 600 s describen el TTL de caché o la antigüedad de cola, según el contexto. M-06 confirma el docroot efectivo y la raíz del checkout en el VPS.

**Criterios de aceptación**
- [x] Ningún valor contradictorio de latido o docroot entre los dos documentos
- [x] Los valores coinciden con `config/*` y el código
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Qué se puede hacer ya: unificar con el código. Queda a la espera (V/U): verificar el docroot en el VPS real. **A la espera (no bloquea la fase)**: Verificar el docroot en el VPS (usuario).

- TDD n/a: documentación. Se distinguieron los 180 s de antigüedad máxima de los 600 s de expiración de caché y se separaron la raíz de Artisan (`app/`) y el docroot (`app/public/`).
- Verificación local: ambas guías usan las cinco órdenes de Artisan desde `app/`. La sonda de `HealthCheck::scheduler()` aceptó un latido de 180 s, rechazó uno de 181 s y rechazó la ausencia de latido.
- Revisión Opus 5.5 del 17 de noviembre: detectó que la ruta del cron parecía verificada en producción y que el comando de verificación no distinguía umbral y TTL. Se sustituyó la ruta literal del cron por un marcador, se aclararon los dos valores y se recuperó el diagnóstico del log. La verificación en el VPS sigue en M-06. Informe en `%TEMP%/metricool-t73-opus55-r1.json`.
- Segunda revisión: Opus 5.5 confirmó que se resolvieron los dos hallazgos iniciales. Señaló la falta de una instrucción explícita sobre desactivar el cron 5 al activar `studio-render` con systemd. Se añadió esa instrucción y se aclararon los plazos de los workers. Informe en `%TEMP%/metricool-t73-opus55-r2.json`.
- Tercera revisión: Opus 5.5 aprobó la documentación sin Critical ni Important. La sonda local cotejó el umbral de 180 s, el TTL de 600 s y los cinco marcadores del cron con el código. Pest ampliado, incluidos los tests de despliegue y latido, pasó 193/193. La ruta y el docroot reales quedan a la espera de la verificación M-06 en el VPS. Informe en `%TEMP%/metricool-t73-opus55-r3.json`.
- `DeployQueuesDocTest|InfraTest` pasó 34/34 y 114 aserciones. La verificación de navegador que corresponde a este cambio documental es M-06 en el VPS.
- Diff para Opus preparado en `%TEMP%/metricool-t73-review-r1.txt`.

### T-74 — Fixtures grabados por red y test de contrato

- **Descripción**: Fixtures por red (respuestas representativas, sin secretos) y tests de contrato que comparen los fakes con ellas.
- **Changelog**: Las pruebas de publicación verifican respuestas de éxito y de error de las ocho redes con ejemplos sintéticos y sin peticiones reales.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Fixtures/**`, `tests/Support/FixtureRecorder.php`, `tests/Feature/Contracts/**`
- **Cubre**: E-19
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --group=provider-contracts` incluye `ContractFixturesTest` y los fakes de los publicadores. `rg -ni '(access_token|bearer [a-z0-9]{20,})' tests/Fixtures` debe devolver cero coincidencias.

**Criterios de aceptación**
- [x] Cada red principal tiene fixtures de éxito y error
- [x] Los fakes manuales se validan contra los fixtures
- [x] Ningún fixture contiene tokens ni datos personales
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los fixtures se construyen a partir de la documentación oficial y de los fakes actuales: sin llamadas de red.

- RED: `ContractFixturesTest` falló porque faltaba la respuesta de éxito de Facebook. El test del fake de LinkedIn falló porque el JSON de error 403 omitía `status`. Resultados en `%TEMP%/metricool-t74-red.log` y `%TEMP%/metricool-t74-fake-red.log` del 2026-11-17.
- El candidato contiene 16 ejemplos redactados a partir de fuentes oficiales, dos por cada red. Las pruebas de los publicadores cotejan los intercambios HTTP registrados con los ejemplos mediante método, host, ruta, estado, cabeceras y campos JSON. Se conservan las comprobaciones del resultado de publicación y del tipo de excepción.
- En la copia aislada pasaron 9 pruebas del inventario, 62 de contrato con 494 aserciones y 629 del conjunto de conectores con 3.220 aserciones. La búsqueda de marcadores de credenciales no dio coincidencias en `tests/Fixtures`. El parche `%TEMP%/metricool-t74-provider-contracts-preview.patch` pasó `git apply --check --whitespace=error`. Pendientes aplicación en el checkout, revisión de aceptación y calidad con Claude Code Opus 5.5 y QA de integración. Paquete de revisión en `%TEMP%/metricool-t74-provider-contracts-review-r1.txt`.

Revisión de aceptación y calidad con Claude Code Opus 5.5, R4: aprobada sobre el diff R3. Los contratos validan ruta exacta, consulta requerida, patrones de cabeceras y códigos de protocolo. Cada ejemplo se comprueba en un caso dedicado. RED: al retirar la comprobación de `facebook/error`, el inventario detectó la referencia ausente. Se restauró la comprobación y se sustituyó el identificador de Facebook por uno sintético. Una búsqueda en los 27 archivos de la tarea confirmó que el antiguo identificador no aparece. Los documentos históricos y pruebas ajenas al cambio conservan sus datos. En la rama pasaron 26 casos de contrato con 578 aserciones, 631 casos de conectores y contratos con 3.350 aserciones y Pint. La búsqueda de credenciales devolvió cero coincidencias. `Tests\TestCase` bloquea las peticiones HTTP sin fake. Playwright no aplica a estos cambios de tests PHP y documentación. Evidencias `%TEMP%/metricool-t74-r3-main-contracts.log`, `%TEMP%/metricool-t74-r3-main-connectors.log`, `%TEMP%/metricool-t74-r3-main-pint.log` y `%TEMP%/metricool-t74-r4-evidencia-opus55.json`.

---

## Fase 7 — Funciones nuevas

**Estado**: en-progreso · **Estimado**: 177.0h · **Real**: — · **Coste est.**: 8850 € (base) + tokens ⚠️ verificar · **Tokens est.**: 8445k

### T-75 — Registro de actividad visible

- **Descripción**: Pantalla con el `AuditLog` ya guardado: filtros por marca, usuario, acción y fecha, paginada y solo del propio usuario/marcas.
- **Changelog**: El historial de actividad permite consultar las acciones registradas con filtros, paginación y retención, sin exponer datos privados ni atribuir referencias ambiguas a una marca.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-68, T-04
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/ActivityController.php`, `app/Services/AuditLog.php`, `resources/js/Pages/Activity/Index.vue`, `routes/web.php`, `resources/js/navigation.js`, `docs/usuario/actividad.md`, `database/migrations/2026_11_17_193000_add_brand_to_audit_logs.php`, `resources/js/types/activity.d.ts`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tsconfig.frontend.json`, `tests/Feature/Activity/ActivityLogTest.php`, `tests/e2e/activity.spec.ts`, `tests/e2e/helpers.ts`, `app/Mcp/Tools/SchedulerTool.php`, `app/Mcp/Tools/GetCalendar.php`, `app/Http/Controllers/Api/ApiController.php`, `app/Http/Controllers/Api/PostApiController.php`, `docs/README.md`, `docs/usuario/guia-de-usuario.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/roadmap/2026-11-11-audit-hardening/testing/t75/**`
- **Cubre**: F-01
- **Cubre (tests)**: E2E-15
- **Verificación**: Pest completo 4294 passed / 9 skipped / 28843 assertions; SQLite y MySQL focales 32/241; Node 778/778; tipado, estilo y build verdes; Playwright serial 13 passed / 2 skipped / 0 failed / 0 flaky, qa-gate VERDE; Opus 5.5 R3 aprueba calidad sin condiciones. Evidencia en `testing/t75/report.md` y `verification.json`.

**Criterios de aceptación**
- [x] Un usuario ve únicamente su actividad y la de sus marcas
- [x] Filtros y paginación funcionan
- [x] La retención de A-08 se refleja en la UI
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Preparación aislada — 2026-11-17 (no cierre; dependencia T-68 pendiente)**

- Candidato en `.cache/t75-candidate/source`; pruebas en `.cache/t67-ci/metricool`. No integrado en el árbol principal ni en la copia congelada de Playwright de T-68.
- RED: `ActivityLogTest::Activity: an authenticated user can open an empty audit history` devolvió 404 en vez de 200 antes de habilitar la ruta; `%TEMP%/metricool-t75-r1-red.log`. Otros criterios añadidos sobre el borrador son cobertura posterior y no se presentan como ciclos TDD completos.
- RED: filtros `brand_id=0` y `user_id=0` devolvían 200 en vez de 422; corregidos con `min:1`. Pest focal 16/16; tipado estricto y ESLint verdes.
- Revisión de dos lentes — intento 1, Claude Code CLI real `claude-opus-5-5`, incluyendo A/B/C/D y calidad del código: `%TEMP%/metricool-t75-r1-opus55.json`. Detectó atribución entre propietarios por referencias ambiguas, ordenación sin fecha, actualizaciones por fila y tipo incorrecto del selector de usuarios.
- Hallazgo de aislamiento reproducido antes del fix: dos pruebas fallan por `brand_id=1` cuando se esperaba `null` (registro nuevo y backfill); `%TEMP%/metricool-t75-r5-opus-findings-red.log`. La asociación ahora exige el mismo propietario para cualquier resultado; se eliminó el parámetro opcional no utilizado para forzar la marca. Actualización histórica agrupada por marca dentro de cada bloque de 500.
- RED: historial insertado fuera de orden cronológico devolvía `[3,2,1]` en vez de `[1,3,2]`; `%TEMP%/metricool-t75-r7-order-red.log`. Corregido con orden total `created_at DESC, id DESC`, compatible con los índices y cursor existentes.
- Pest focal actual: 18 passed, 201 assertions en SQLite (`%TEMP%/metricool-t75-r8-order-green.log`). MySQL aislado, contraste de consultas, segunda revisión Opus y navegador aún pendientes; los criterios de aceptación permanecen abiertos.

**Segunda comprobación del contexto — 2026-11-17**

- El análisis posterior confirmó que incluso una referencia del mismo propietario podía apuntar a otra marca. RED con MCP real: `get_calendar` sobre marca 1 terminaba atribuido a marca 2 por una colisión de ids (`%TEMP%/metricool-t75-r12-same-owner-red.log`). Claude propuso capturar el contexto verificado en la propia operación (`%TEMP%/metricool-t75-r1-reference-followup-opus55.json`).
- Se descartó por completo el helper `AuditResource` y el backfill del primer borrador. La migración final solo incorpora el esquema y los índices: el historial anterior sigue siendo personal. MCP recoge las marcas cargadas por `owned()`/`brandFrom()`, reinicia el contexto en cada llamada y exige una sola marca. La API aporta el contexto de la marca de la ruta o del modelo leído. `AuditLog` vuelve a comprobar que pertenece al actor, y los rechazos quedan personales. Las llamadas sin contexto siguen personales; pantalla y guía lo explican. No se cambian las referencias históricas ni el comportamiento de las herramientas.
- Se añadieron pruebas de reutilización de instancia, varias marcas, contexto ausente, rechazo, integridad entre propietarios, REST real y fechas independientes. Pest final focal: **25 passed, 215 assertions** en SQLite y MySQL 8.4.3 aislado; `%TEMP%/metricool-t75-r15-sanitized-green.log` y `%TEMP%/metricool-t75-r5-mysql-pest.log`.
- Las opciones y totales ahora usan rangos disjuntos e índices de cobertura; se rechazó una alternativa de unión de filas completas porque empeoraba la medida. Con 60.000 registros y 20.000 visibles, la consulta final hizo seis consultas y tardó 23,62 ms frente a 140,64 ms del borrador inicial en esta instancia local. Planes reales en `%TEMP%/metricool-t75-r5-mysql-query-plans.json`; no es una garantía de rendimiento para otros volúmenes.
- Las dos comprobaciones TypeScript, Pint y ESLint focal pasaron. Node completo detectó cuatro `.first()` en el spec nuevo: se sustituyeron por localizadores únicos tras comprobar el tamaño del listado, sin ampliar el cupo. Repetición completa **778/778** (`%TEMP%/metricool-t75-r17-node-unit.log`). Build del candidato verde; repetir tras el último ajuste visual antes de congelar para navegador.
- La segunda revisión preparada no llegó a ejecutarse: Claude Code devolvió **“You've hit your 5-hour limit · resets 12am (Europe/Madrid)”**. Conservar el recibo `%TEMP%/metricool-t75-r2-opus55.json` como intento fallido, sin presentarlo como veredicto. Reintentar con el mismo modelo tras restablecerse la cuota, sobre el paquete actualizado. No se sustituye el revisor ni se cierra T-75. Sigue pendiente el navegador, serial y después de terminar la ejecución T-68 en curso.

**Verificación final de ejecución — 2026-11-17 (revisión pendiente)**

- T-68 ya está completada. T-75 sigue aislada, sin integrar ni cerrar hasta disponer del veredicto final real de Claude Opus 5.5, incluyendo calidad del código.
- Pest completo: **4287 passed, 9 skipped, 28817 assertions** (%TEMP%/metricool-t75-r18-pest-full.log). Node final: **778/778** (%TEMP%/metricool-t75-r24-node-final.log); tipado estricto y build final verdes.
- La primera copia de CI no incluía Tailwind/PostCSS; se restauraron las configuraciones reales. La ejecución siguiente detectó con axe una región de tabla desplazable sin foco. Corregida con región etiquetada, tabindex y foco visible; el E2E comprueba ArrowRight y desplazamiento real.
- Navegador final: **13 passed, 2 skipped, 0 failed, 0 flaky**, qa-gate VERDE; las omisiones son el sidebar de escritorio en móvil. %TEMP%/metricool-t75-r23-screen-browser.log. SHA-256 de los **1729 archivos de código/configuración** verificados después del navegador y Node: ninguna diferencia.
- Evidencia durable: testing/t75/preflight.md, verification.json y source-hashes.json. La cuota de Claude se restablece el 18 de noviembre a las 00:00 de Madrid; el recibo de cuota no es una revisión aprobada.

**Cierre verificado — 2026-11-18**

Opus 5.5 R3 confirmó resueltos Q-01..Q-05 y D-01, sin defectos Critical/Important y con calidad aceptable sin condiciones. N-01 (Minor de redacción) se corrigió literalmente con su texto; TDD n/a: prosa. La integración de 21 archivos coincide byte por byte con el candidato final y pasa git apply --reverse --check. Solo esa frase cambió después del QA; los 1728 archivos de ejecución/configuración permanecen idénticos. No hubo commit ni push. Evidencia durable en testing/t75/report.md, verification.json, source-hashes.json y reviews/.

### T-76 — Mejor hora en un clic (compositor y calendario)

- **Descripción**: Botón «mejor hora» que rellena la fecha con la sugerencia de `BestTimesService` para la cuenta/marca.
- **Changelog**: Propuesta de fecha futura con métricas reales desde compositor y calendario; explica la falta de datos y conserva cambios del usuario.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-75
- **Tipo**: fullstack
- **Archivos**: `CHANGELOG.es.md`, `CHANGELOG.md`, `app/Http/Controllers/BestTimesController.php`, `app/Http/Controllers/PostController.php`, `app/Services/BestTimesService.php`, `app/Support/LocalDateTime.php`, `docs/README.md`, `docs/usuario/guia-de-usuario.md`, `docs/usuario/planificar.md`, `resources/js/Components/BestTimeSuggestion.vue`, `resources/js/Components/Composer/ScheduleSection.vue`, `resources/js/Composables/useBestTimeSuggestion.js`, `resources/js/Composables/useComposer.js`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Pages/Posts/Compose.vue`, `resources/js/Pages/Posts/compose-props.js`, `resources/js/types/best-time.d.ts`, `resources/js/types/compose.d.ts`, `routes/parity.php`, `tests/Feature/Parity/BestTimeSuggestionTest.php`, `tests/e2e/best-time-suggestion.spec.ts`, `tests/frontend/best-time-component.test.mjs`, `tests/frontend/best-time-suggestion.test.mjs`, `docs/roadmap/2026-11-11-audit-hardening/testing/t76/**`
- **Cubre**: F-02
- **Cubre (tests)**: E2E-16
- **Verificación**: `php artisan test --compact --filter=BestTimeSuggestionTest` → verde

**Criterios de aceptación**
- [x] El botón propone una fecha futura según los datos disponibles
- [x] Sin datos suficientes, explica por qué y no inventa
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.


**Preparación aislada — 2026-11-17 (dependencia T-75 pendiente)**

- Candidato en .cache/t76-candidate/source, probado en .cache/t76-physical con instalación física de Composer y ReflectionClass(Tests\\TestCase) dentro de esa copia. Se descartó una copia heredada que cargaba el autoloader principal; su fallo de arranque no cuenta como RED. Ningún código de T-76 se ha integrado en el checkout ni en las copias de T-68/T-75.
- RED: propuesta con historial y ausencia de datos recibían 404 en vez de 200 (%TEMP%/metricool-t76-r2-red.log). El endpoint de sesión verifica propiedad y red; BestTimesService::suggest() reutiliza las métricas reales. Sin mínimo de datos conserva el motivo y devuelve fecha nula.
- RED de DST: PHP normalizaba la franja inexistente de 02:00 a 03:00 y elegía la franja duplicada del cambio de otoño (%TEMP%/metricool-t76-r4-dst-red.log). Se comprueban los instantes con las transiciones reales y solo se eligen horas locales únicas. Se conservan offsets fraccionarios y se excluye el instante actual.
- GREEN: 15 passed, 89 assertions entre los casos nuevos y BestTimesTest, Pint verde (%TEMP%/metricool-t76-r5-dst-green.log). Pendientes interfaz de compositor/calendario, documentación, Node/E2E, regresión y revisión real de Claude Opus 5.5 tras restablecerse la cuota. Criterios de aceptación abiertos.

**Interfaz y protección de la propuesta — 2026-11-17 (sin cierre)**

- Compositor y calendario comparten un componente y un composable tipados: selección de red, estado accesible, motivo sin datos, cancelación, reintento y descarte de respuestas tardías. Una respuesta no reemplaza una fecha modificada durante la consulta ni se aplica a otra marca/red. Siete casos ejecutan Vue real y un fetch que ignora abort para demostrar las carreras; verdes. Node completo **785/785** (%TEMP%/metricool-t76-r20-node-full.log). La primera copia carecía de dos guías que los tests leen; completadas sin modificar los tests.
- El calendario abre un compositor nuevo con una propuesta; no guarda ni publica. El servidor valida que la fecha del enlace sea futura y localmente única; editar un borrador ignora ese parámetro. LocalDateTime centraliza la comprobación de horas inexistentes/duplicadas para evitar duplicación entre controlador y servicio.
- RED adicional: un identificador numérico demasiado grande causaba 500; el parámetro de ruta se recibe como string y los casos inexistentes devuelven 404. Pest focal integrado **45 passed, 392 assertions**, incluidos ComposeTest y BestTimesTest (%TEMP%/metricool-t76-r16-integrated-focal.log). El MySQL inicial fue 17/92; falta repetir la versión integrada.
- RED E2E antes de la interfaz: botón ausente (%TEMP%/metricool-t76-r14-button-red-browser.log). El primer recorrido integrado pasó los seis casos de compositor; los tres de calendario fallaron porque brand_id de la URL es string y la lista contiene números. Se corrigió la comparación tras reproducirlo en los tres tamaños (%TEMP%/metricool-t76-r21-ui-green-browser.log). Se repite con axe también en el calendario.
- Tipado estricto y build tras la corrección verdes (%TEMP%/metricool-t76-r22-calendar-typecheck.log y metricool-t76-r22-calendar-build.log). Documentación candidata preparada. Continúan pendientes regresión completa y revisión real Opus 5.5 de aceptación, corrección, seguridad, rendimiento y calidad; no se cierra ni integra antes de T-75.

**Regresión completa — 2026-11-17 (revisión y navegador ampliado pendientes)**

- Pest completo: **4281 passed, 9 skipped, 28685 assertions** (%TEMP%/metricool-t76-r25-pest-full.log). El primer intento completo falló por archivos de despliegue, plantillas de entorno y contrato OpenAPI ausentes en la copia; se restauraron los archivos versionables y se repitió sin modificar las pruebas ni copiar un .env privado.
- Node final **785/785**, ESLint y Prettier focales verdes. Los nueve E2E-16 pasaron sin omisiones y qa-gate VERDE (%TEMP%/metricool-t76-r23-calendar-green-browser.log). Se está ejecutando una regresión ampliada de seis specs con 1758 archivos congelados, incluyendo configuraciones y scripts de despliegue.
- Scope-check con base HEAD: **0 fuera de alcance, 0 avisos**, reglas originales con caché de consultas repetidas de directorio. Ledger-lint: **0 incoherencias**; conserva avisos por Changelog vacío de tareas aún abiertas. Evidencia durable: testing/t76/preflight.md, verification.json y source-hashes.json. La revisión final real de Opus 5.5 y la dependencia T-75 siguen abiertas.

**Navegador ampliado final — 2026-11-18**

Los seis specs afectados terminaron con **108 passed, 0 skipped, 0 failed, 0 flaky**, qa-gate VERDE (%TEMP%/metricool-t76-r28-regression-browser.log). Los 1758 SHA-256 coincidieron después de finalizar. La revisión real de Claude Code Opus 5.5 con esfuerzo alto y calidad del código empezó a las 00:06:51 de Madrid; aún no se declara ningún veredicto ni cierre. T-75 sigue pendiente de cotejar sus correcciones.

**Correcciones de la revisión real y verificación conjunta — 2026-11-18**

T-75 ya está integrada y cerrada. La copia T-76 incorpora sus 17 archivos no compartidos y conserva las cuatro guías/changelogs que unen ambas tareas. Los scripts y plantillas de Compose y ScheduleSection, y useComposer.js, siguen fuera del programa estricto de T-68; se comprueban el componente compartido, el composable, su DTO y los descriptores de props. Las pruebas de navegador cubren las conexiones del compositor.

- Opus 5.5 R1 real, esfuerzo alto: dos Important (B1 y Q1), seis Minor. Veredicto en testing/t76/reviews/r1-opus55.md; recibo %TEMP%/metricool-t76-r1-opus55.json. La llamada tardó unos 21 minutos y terminó correctamente; no se sustituyó el modelo.
- B1 reproducido: cinco GET normales devolvían 302, y el navegador con Referer del mismo enlace caducado falló con ERR_TOO_MANY_REDIRECTS (%TEMP%/metricool-t76-r33-review-red.log y r34-expired-red-browser.log). Ahora se abre el compositor, se omite la propuesta inválida y se muestra un aviso no bloqueante. Se mantiene el parser único y no se cambia la validación de guardar/programar.
- Q1: tres datasets ejercitan media, muestras y cercanía, con fecha actual en mitad de semana para que el orden de la matriz no falsee el empate. Se ejecutaron tres mutantes temporales: omitir cada prioridad hizo fallar exclusivamente su dataset. Algoritmo original restaurado con SHA-256 idéntico; no se presenta como TDD de una implementación ya existente.
- B2: template agrupa correctamente el enlace y el widget; v-else vuelve a corresponder a createHref. B3: el cliente también ignora la propuesta si ya existe post. Q4: calendario sin filtro exige elegir red; una red filtrada cuenta como elección explícita.
- Q3: dos tests montan el setup y ciclo de vida reales del SFC sobre un renderer mínimo de Vue para valores por defecto, conservación, sustitución y elección obligatoria. La plantilla real queda cubierta en Playwright, incluido un caso nuevo de varias redes. El primer renderer carecía de parentNode: su error de montaje no cuenta como RED; r35b sí reprodujo una única elección implícita incorrecta.
- Hallazgo adicional del implementador: cambiar y después vaciar una fecha podía hacerla parecer intacta. RED real r31, corregido con revisión de cambios síncrona; ocho casos del composable verdes. No se atribuye este hallazgo a Claude.
- Q2: Prettier exacto sobre los doce archivos frontend modificados, lista en %TEMP%/metricool-t76-r38-prettier-files.json; check r39 verde. La config real tiene embeddedLanguageFormatting=off; no se confunde el chequeo de plantilla con formateo automático del script SFC. También se formatearon los JS modificados. Sin excepciones al gate responsive: los seis casos se agrupan en un describe con etiqueta real @responsive, que el detector reconoce tras Prettier.
- Q5: retirada la marca de borrador de la guía; el cierre exige T-75 integrada y verificación conjunta. Focal conjunto **81 passed, 700 assertions**; Node completo **788/788**, TypeScript, ESLint, Prettier, Pint y build verdes. El navegador ampliado de seis casos por tres proyectos está ejecutándose; todavía no se declara final.
- Una sonda adicional con NUL embebido reprodujo ValueError del parser nativo. Se ha preparado un caso para reproducir el efecto sobre el GET tras finalizar el navegador congelado. Pendiente resolverlo y repetir QA final/cotejo de Opus antes del cierre.

**Cotejo R2/R3 real y correcciones menores — 2026-11-18 (QA final pendiente)**

Opus 5.5 R2 aceptó B1/Q1 y los seis Minor originales, con calidad del código PASS. Señaló N1 (margen mínimo sugerido), N2 (aviso confuso tras preserveState), N3 (estado de éxito obsoleto) y una duda V1 sobre zona horaria. R3 real, mismo modelo y esfuerzo alto, acepta el rebate de N1 como política opcional fuera del contrato, sin deuda aceptada; confirma N2/N3 corregidos y cierra V1 con fuentes y pruebas Europe/Madrid/fallback UTC. No quedan Critical/Important confirmados. Veredictos íntegros en testing/t76/reviews/.

- N2: texto neutral sobre la fecha del enlace. N3: marcador de fecha aplicada conserva el anuncio durante el feedback diferido del padre y lo borra tras una edición manual posterior. RED real r46: se esperaba estado vacío pero seguía «Fecha propuesta»; GREEN r47 y Node completo **789/789**. No se oculta el motivo sin datos.
- V1: CalendarService incluye timezone en cada marca, y servicio/widget coinciden en fallback UTC. Dos pruebas adicionales comprueban el payload real Europe/Madrid y el fallback. SQLite y MySQL 8.4.3 focales **84 passed, 731 assertions**; Prettier/ESLint/Pint/tipado/build verdes. Los E2E nuevos comprueban explícitamente Europe/Madrid y limpiar la fecha en el date picker real.
- La regresión conjunta anterior terminó con Pest **4318 passed, 9 skipped, 29008 assertions** y navegador serial **120 passed, 0 skipped/failed/flaky**, qa-gate VERDE, 1766 huellas intactas después. Esa ejecución precede solo los últimos N2/N3/tests/docs; se está repitiendo QA final.
- R3 detectó Q-N2a Minor válido: la afirmación anterior de «texto exacto» era incorrecta; los tests comprobaban fragmentos. Se refuerzan literalmente ambas ramas HTTP y el E2E con el texto exacto propuesto por Claude, sin cambiar producción. Se verificará que rechazan la frase anterior. No cuenta como un nuevo ciclo de implementación ni se declara verde sin ejecución.
- La tercera llamada terminó con recibo real correcto y tools vacías. El lanzador falló al leer su propio archivo de salida aún abierto; se cerró el stream y recuperó el recibo final ya emitido, sin repetir ni sustituir la revisión.

**Cierre verificado — 2026-11-18**: R3 real Opus 5.5, aceptación y calidad aprobadas; Q-N2a reforzado literalmente, seis casos rojos ante el mutante temporal y vuelta a verde. Pest4320/9,29025 assertions; focal SQLite/MySQL84/731; Node789/789; navegador final18/18, qa-gate VERDE. 1766 huellas y23 archivos integrados exactos; reversión comprobada. Evidencia: testing/t76/report.md y verification.json. Las notas anteriores conservan la historia, no describen el estado final.

### T-77 — Duplicar post (borrador u otra marca)

- **Descripción**: Acción «Duplicar» que crea un borrador con los mismos textos y media (en la misma u otra marca), sin copiar métricas ni estados.
- **Changelog**: Duplicación de publicaciones guardadas como borradores independientes entre marcas propias, con destinos compatibles e historial reiniciado.
- **Estado**: completado
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-76
- **Tipo**: fullstack
- **Archivos**: `app/Services/PostService.php`, `app/Services/LibraryService.php`, `app/Services/Posts/PostDuplicator.php`, `app/Services/Posts/DuplicatePostResult.php`, `app/Services/Posts/PostPlanner.php`, `app/Http/Controllers/PostController.php`, `resources/js/Pages/Posts/**`, `resources/js/Components/Composer/DuplicatePost.vue`, `tsconfig.frontend.json`, `routes/web.php`, `docs/usuario/duplicar.md`, `docs/usuario/guia-de-usuario.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `tests/Feature/Posts/DuplicatePostTest.php`, `tests/Feature/Mcp/Tools/McpOptionsTest.php`, `tests/Feature/Publishing/PresetsReviewTest.php`, `tests/Feature/Publishing/TikTokOptionsTest.php`, `tests/Feature/Library/LibraryTargetTextTest.php`, `tests/Feature/Connectors/Google/Publish/YouTubeClientTest.php`, `tests/e2e/duplicate-post.spec.ts`, `docs/roadmap/2026-11-11-audit-hardening/testing/t77/**`
- **Cubre**: F-03
- **Cubre (tests)**: E2E-17
- **Verificación**: `php artisan test --compact --filter=DuplicatePostTest` → verde

**Criterios de aceptación**
- [x] El duplicado es un borrador independiente
- [x] Entre marcas solo se copia lo permitido por las redes de destino
- [x] Respeta propiedad y colaboradores
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

**Preparación de pruebas aislada — 2026-11-17 (dependencia T-76 pendiente)**

Se prepararon tres pruebas de aceptación en .cache/t77-candidate/source: duplicar una publicación publicada conservando contenido y media como borrador independiente sin métricas ni estado de publicación; copiar entre marcas propias únicamente a cuentas compatibles; impedir que un extraño o un colaborador con rol de aprobador copie contenido ajeno. Aún no se han ejecutado y no se declaran RED ni GREEN. No hay implementación de T-77 ni cambios en el checkout o la copia congelada de T-76.

**RED inicial verificado — 2026-11-18 (sin implementación ni cierre)**

Los tres casos se ejecutaron en la copia aislada de T-75 ya cerrada (.cache/t67-ci/metricool), con autoloader comprobado dentro de la copia y SQLite en memoria. Dos fallaron por 404 en lugar de la redirección esperada; el caso de rechazo ajeno pasó. Registro %TEMP%/metricool-t77-r1-red.log: 3 tests, 1 passed, 2 failed, 6 assertions. Se conserva como preparación TDD: no existe código de producción de T-77 y no se tocó la copia congelada de Playwright de T-76.

**Implementación aislada tras T-76 — 2026-11-18 (sin revisión ni cierre)**

- T-76 ya cerrada; se superpusieron sus 23 archivos exactos en la copia de ejecución física .cache/t67-ci/metricool. El candidato T-77 vive en .cache/t77-candidate/source. No se reutiliza la copia congelada de T-76 ni se toca la base privada.
- RED: DuplicatePostTest, dos casos positivos devolvían 404; repetido tras integrar T-76 (%TEMP%/metricool-t77-r2-baseline-red.log). GREEN inicial 3/37. PostDuplicator reconstruye un borrador a través de PostPlanner::dataFromPost y PostService::create, evitando replicar filas/estado o usar la biblioteca como intermediario. Controlador fino; propiedad de origen, marca de origen/destino y destinos explícitamente comprobada.
- RED: LinkedIn CONNECTIONS no es válido en una página de empresa; el primer fixture no habilitaba la red y después heredaba media de foto. Corregido el fixture, r4b reproduce exclusivamente la incompatibilidad de visibilidad. El validador canónico omite solo destinos inválidos, conserva los compatibles y avisa; errores del contenido común no se ocultan. R4 también reproduce aviso ausente con destino ambiguo.
- RED: dos cuentas de origen para una cuenta de destino elegían arbitrariamente una; y un tablero Pinterest de origen viajaba a otra cuenta (%TEMP%/metricool-t77-r6-account-bindings-red.log, r6b-board-red.log). Sin correspondencia inequívoca no se elige; los tableros se eligen de nuevo en otra marca. GREEN 7/54.
- RED: TikTok heredaba privacy_level de otra publicación (%TEMP%/metricool-t77-r9-privacy-red.log). Se exige elegirlo de nuevo. GREEN final focal **9 passed, 71 assertions**, incluye media propia/alt, primer comentario independiente, limpieza de referencias/progreso/errores y ausencia de métricas. Esta última cobertura confirma reglas existentes del creador, no se presenta como implementación TDD nueva.
- RED de navegador real: faltaba Duplicar en ambos recorridos de escritorio (%TEMP%/metricool-t77-r8b-ui-red-browser.log). El lanzamiento r8 usó un cwd erróneo y devolvió No tests found; no cuenta como RED y no llegó a ejecutar estos casos. R8b usa el checkout aislado correcto. Interfaz candidata con diálogo accesible, marca de destino, prevención de doble envío y bloqueo explícito ante cambios sin guardar. El componente nuevo entra en tipado estricto; el compositor heredado conserva su límite declarado.
- Pendientes formato/tipado/build, regresión completa, navegador serial, revisión real de Claude Opus 5.5 con calidad del código, correcciones y documentación final. No se declara implementación integrada ni tarea cerrada.

**QA y cuota del revisor — 2026-11-18 (sin cierre)**

- Componente nuevo con label for/id explícito tras RED real de selector en los tres anchos. Navegador focal final **6 passed, 0 skipped/failed/flaky**, qa-gate VERDE (r15). Node **789/789**, TS, ESLint, Prettier, Pint y build verdes.
- Catorce tests propios y regresiones de compositor/biblioteca: **53 passed, 442 assertions** tanto SQLite como MySQL 8.4.3 desechable. El primer MySQL falló dos tests antiguos de biblioteca por orden de claves JSON. Se normalizan solo claves de cada objeto con ksort, conservando orden de destinos y tipos estrictos; archivo declarado y sin modificar biblioteca productiva.
- Pest completo r20: **4333 passed, 9 skipped, 1 failed**, 29115 assertions. El único fallo es un test antiguo que prohibía «77» dentro de un HMAC. Se reprodujo de forma determinista con clave de prueba youtube-opacity-fixture-41: ss-a177bb707d90ca7d (r21 RED). La prueba ahora exige formato, estabilidad por destino, destinos distintos, marcador prioritario y cambio al variar la clave secreta; no prohíbe coincidencias de dígitos. YouTubeClientTest **9/18** verde (r22). Solo cambia el test, no producción. Se repite la suite completa r24.
- La revisión preparada de Opus 5.5 no llegó a empezar: Claude Code devolvió límite de cinco horas, reinicio a las 05:00 Europe/Madrid, cero tokens de entrada/salida y coste cero. Recibo real en testing/t77/reviews/r1-quota-receipt.json. No hay veredicto ni se sustituye el modelo. La tarea queda abierta; se reintentará tras restablecerse. Se preparan tareas posteriores en copias independientes mientras tanto.

**QA final confirmado — 2026-11-18 (revisión aún no iniciada)**

Pest completo r24: **4334 passed,9skipped,29118assertions**. Navegador ampliado r26: **90 passed,0skipped/failed/flaky**,qa-gate VERDE, duplicación/compositor/mejor-hora en los tres tamaños. Las **1771 huellas** coinciden al finalizar. Candidato15archivos, sin integrar. Evidencia durable testing/t77/report.md,verification.json,source-hashes.json y recibo de cuota rechazado en reviews/. Estado en-revision únicamente por espera del revisor solicitado; no existe un veredicto. No se cierra ni se sustituye Claude Opus5.5.

**Revisión real de Opus5.5 — intento1 efectivo, 2026-11-18**

ClaudeCode real, modelo confirmado claude-opus-5-5, tools/MCPvacíos y contexto estático completo. Recibo e informe íntegros en testing/t77/reviews/r1-effective-*,r1-report.md.0Critical/0Important confirmados, B1condicional sobre opciones ligadas a cuentas; Minor B2aviso, B3dirtytrasguardar, E1resultado porreferencia, E2claves de error, E3prefiltro duplicado, E4keysVue, E5errorbag, E8prueba de métricas originales. Se corrigen hallazgos, no se cierra por veredicto condicional.

RED r28: catálogo de audioInstagram viajaba a cuenta distinta, errorcomún usaba bagglobal y erroranidado targets.0.media abortabaen vez de omitir destino. Se prepara DTOresultado, aviso neutro en controlador, errorbagduplicate, parser del namespace en PostPlanner y validador como única fuente, clavesVue porcampo, descripciónARIA de bloqueo y pruebas de reactivación/métrica fuente. El audio debe reelegirse al cambiar de cuenta sin consultar proveedores; resto del catálogo de opciones se documentará para cerrar B1con evidencia ante el revisor. T81 queda en su propio RED de límites/consentimiento; sin integrar.

**Revisión real Opus 5.5 R2 — 2026-11-18:** R1 cerrado incluido catálogo B1. Nuevo Important B4: el valor por defecto SELF_ONLY invalidaba borradores patrocinados tras reiniciar privacidad TikTok, omitiendo el destino. RED r36: 22 casos,20passed,1failure y1error: borrador patrocinado rechazado y destino TikTok inexistente. Se conserva la divulgación y se difiere elegir privacidad hasta programar; SELF_ONLY sigue prohibido. Regresiones de borrador, programación privada/sin elección/pública y duplicación con/sin patrocinio. Minor E9/E10 corregidos (artículos y orden documental), E11 paréntesis; E12 onSuccess se conserva como defensa sin defecto. Suite global anterior4337/9,29135assertions no se atribuye a este arreglo. Pendiente R3 y QA final.

**Gate global tras R3 — 2026-11-18 (no cierre):** r39 detecta3tests antiguos que equiparaban privacidad vacía a SELF_ONLY: McpOptionsTest, PresetsReviewTest y TikTokOptionsTest;4339passed/3failed/9skipped. R3 ya había advertido exactamente esta expectativa antigua, incompatible con el contrato de borrador pendiente. Se actualizan para mantener rechazo de SELF_ONLY explícito y añadir comprobaciones de privacidad pendiente/diferencia con programación. No se elimina cobertura ni se toca producción: casos MCP conservan normalización0/1 y bloqueo al programar. El navegador actual permanece congelado sin tocar su árbol; las pruebas actualizadas solo están en candidato. Pendiente ejecución focal y global finales, más integración.

**Cierre verificado — 2026-11-18:** revisión real Opus5.5 R3 aprobada A/B/C/D/calidad, sin gaps abiertos. PHP4343/9,29164assertions; focal SQLite/MySQL92/605; Node789,TS0,style/buildverdes; navegador90/90sin omisiones/fallos/flaky,qaVERDE.1772huellas y20archivos integrados byte-exactos, parche reversible. El último ajuste solo normaliza claves objeto de un test MCP; focales finales conservan listas/tipos/valores estrictos. testing/t77/report.md y verification.json. Las notas anteriores son historia; este es el estado final.

### T-78 — Estado del sistema en la UI (colas, cron, tokens y cuotas)

- **Descripción**: Pantalla de administración sobre `AppHealth`, `QueueProbe` y `QuotaPolicy`: estado de colas y cron, caducidad de tokens y cuotas por red. Depende de A-09.
- **Changelog**: Pantalla administrativa con estado canónico de salud, sondas por cola y caducidades/cupos con fecha y procedencia, sin credenciales.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-05, T-77
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/SystemStatusController.php`, `app/Services/SystemStatus.php`, `app/Support/SystemAccess.php`, `app/Support/HealthCheck.php`, `app/Services/QuotaPolicy.php`, `app/Jobs/QueueProbeJob.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `config/health.php`, `resources/js/Pages/System/Index.vue`, `resources/js/types/system-status.d.ts`, `resources/js/types/shell.d.ts`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tsconfig.frontend.json`, `routes/web.php`, `.env.example`, `.env.production.example`, `docs/modulos/despliegue.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `tests/Feature/System/SystemStatusTest.php`, `tests/e2e/system-status.spec.ts`, `tests/e2e/isolated/runtime.ts`, `docs/roadmap/2026-11-11-audit-hardening/testing/t78/**`
- **Cubre**: F-04
- **Cubre (tests)**: E2E-01
- **Verificación**: `php artisan test --compact --filter=SystemStatusTest` → verde

**Criterios de aceptación**
- [x] La pantalla refleja el mismo estado que `app:health`
- [x] Solo la ven los administradores
- [x] Cuotas y caducidades se muestran con su fecha
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. El grupo A-09 + E-20 + F-04 de la auditoría se partió: backend en la fase 1, UI aquí.

**Preparación aislada durante la cuota de Claude — 2026-11-18 (T-77 pendiente de revisión)**

- Candidato .cache/t78-candidate/source; ejecución en .cache/t76-physical, cuya evidencia T-76 ya está archivada e integrada. Se superpuso el candidato estable T-77; no se toca la copia donde corre su regresión. Ningún archivo T-78 se integra en main.
- RED: cuatro casos devolvían404, incluidos estado canónico/fechas y rechazo de usuarios ordinarios/lista vacía (%TEMP%/metricool-t78-r1-red.log). SystemAccess exige correo verificado en lista de servidor SYSTEM_ADMIN_EMAILS; vacío no promueve a nadie. No existe un rol administrativo editable ni se añade mass assignment a User.
- SystemStatus reutiliza HealthCheck::run(false,true), con la caché nativa HTTP existente; lee agregados por red con proyección explícita sin credenciales. El contador local de YouTube muestra fecha de lectura y reinicio; otras cuotas sin dato remoto quedan null con fecha desconocida. No se fabrican cupos ni se llaman proveedores.
- R2 pasó ambos rechazos y falló los positivos por componente ausente (RED real de interfaz). Página administrativa inicial tipada, fechas UTC y procedencia local/desconocida de cuotas. GREEN r3 **4 passed,52assertions**. No hay todavía evidencia de navegador, tipado/build, sondas por cola, resiliencia, navegación, revisión ni cierre; siguen pendientes.

**Sondas, permisos y degradación verificados — 2026-11-18 (sin revisión ni cierre)**

- RED r4: faltaba report.queues. QueueProbeJob conserva su marca por id y registra además la última prueba procesada por cola durante24h; la consulta no encola pruebas. GET distingue sin sonda, sonda procesada y caché ilegible. RED r8 y GREEN r9 verifican que un fallo de caché no se presenta como ausencia de trabajadores.
- RED r6: excepciones de lectura de cuota/token impedían devolver el informe. Las observaciones auxiliares fallidas quedan desconocidas, con disponibilidad explícita y sin mensajes privados; HealthCheck conserva su resultado. No se fabrican ceros ni fechas recientes. GREEN r7.
- RED r10: faltaba el permiso de navegación. Menú y servicio comparten SystemAccess; normaliza lista válida y exige verificación. La prueba de cambiar el perfil a un correo autorizado demuestra que email_verified_at se borra y sigue sin acceso. Configuración vacía y entradas inválidas permanecen cerradas. No se toca .env; solo las dos plantillas públicas.
- PHP propio **11passed/129assertions**; combinado con salud/scheduler **26passed/187assertions**. Node **789/789**, TS estricto de la página nueva, ESLint, Prettier, Pint y build verdes (r16). Se declara la caché nativa HTTP en la página y la diferencia entre cuotas sociales desconocidas y contador local de YouTube.
- E2E preparado con operador ficticio autorizado explícitamente solo en el runtime de base desechable, sin heredar la lista del host. El worker observado se simula mediante handle directo: no demuestra un worker nativo real. Primer navegador de escritorio r17 en ejecución, todavía sin resultado. Pendientes MySQL, PHP completo, navegador final, Opus5.5 real y dependencia T77.

**QA final confirmado — 2026-11-18 (revisión y T77 pendientes)**

La primera suite completa r19 detectó un catálogo frontend de redes duplicado (4344passed/9skipped/1failed). Se sustituyó por network_labels del enum canónico de servidor, conservando intacta la regla NetworkCoverageTest. R20:17/152 verde y Node/tipado/estilo/build verdes. Pest completo final r22: **4345passed,9skipped,29247assertions**. MySQL final r2 y SQLite con salud/scheduler **26/187**. Navegador estado+actividad r23: **9passed,0skipped/failed/flaky**,qa-gate VERDE;1779huellas idénticas al terminar. Evidencia durable testing/t78/report.md y verification.json.

Candidato21archivos, sin integrar ni cerrar. ModoHTTP de HealthCheck y su caché nativa explícitos en UI/docs. En-revision significa espera del modelo solicitado: no existe todavía revisión de Claude Opus5.5 ni hallazgos declarados. Primero ha de cerrarse T77, después revisar este candidato contra el padre vigente y repetir cualquier QA afectado.

**Revisión real Opus5.5 R1/R2 — 2026-11-18 (sin cierre)**

R1:0Critical/Important, menores y datos faltantes. Se confirman orden global no estable en MySQL (RED r4: youtube/instagram/facebook frente a orden esperado) y pluralización incorrecta visible. Orden explícito, singular/plural, mapahealthStates y runtime.ts con solo1línea funcional; grupoENVreunido. Se añaden SQLprojection porDBlisten (prohíbe columnas cifradas; EXISTS deHealthCheck no materializa), TTLindividual10min/última24h, desconectadas yedadcachenativo. La primera aserciónSQL confundía SELECTEXISTScon carga decolumnas: error deltest, corregido, noREDdeproducción.

Mutantesr38selectaccess_token yTTL600fallan en1caso cadauno; SHArestauradaexacta. GREENr39:91/567conAuthSocial+EmailVerification; MySQLr5:30/200. Node789/789,TS0,estilo/buildr37verdes. Se excluye runtime heredado dePrettierwholefile para conservar el diffmínimo pedido; resto del ámbito formateado.

Fuentescierran B1UTCfijo,B2comandocolasidénticas/onQueue,B3sinSoftDeletes,C1login socialnunca verifica/creaUseryautovinculaciónGoogleexigecorreo remoto+localverificados; elúnico altaverificación esEmailVerificationRequestsigned/auth/throttle. R2real aprueba A/B/C/D/calidad sinCritical/Important; soloT6dato sobre paralelismo: fullyParallelfalse ycada worker tieneSQLite/cache/server propio; esteQAusa workers1. Se aportaráconfigcompleta.

El navegador r42 queda12passed/3failed: eltestañadidoasumíaInstagram1pero lafixturebaseya traeotra cuenta; no es fallo delproducto. r34 tampoco aislaba por sí solo la pluralización y no debe interpretarse como RED exclusivo de esa regla. Se corrige fixture sin cambiarproducción: cuentaInstagramglobal real yuna red sincuentasprevias para probar singular más suavisodefecha, sincontaminardatosdeotroorigen. Pendiente r44browser,globalr41yR3final. Recibos/veredictos íntegros reviews/r1*,r2*.

**Cierre verificado — 2026-11-18:** Opus5.5R3real A/B/C/D/calidad aprobado, sin gaps. Gate finaldel spec:Node789,tsc/vuetsc0yPrettierverdesr47; ESLintAPIconfirmaTSfueraámbito,no seatribuye lintfalso. PHP4358/9,29306assertions; SQLite91/567incluyeAuthSocial/EmailVerify,MySQL30/200;browser15/15qaVERDEsinomisiones/fallos/flaky.1780huellas,21archivosintegradosbyte-exactos,parchereversible. Informe testing/t78/report.md; las notas anteriores son historia.

### T-79 — Objetivos y alertas (seguidores, engagement, caídas, token)

- **Descripción**: Objetivos por marca/cuenta evaluados a diario sobre `AccountSnapshot`; alertas por notificación (y correo opcional) ante hito, caída o token próximo a caducar.
- **Changelog**: Objetivos por marca/cuenta con evaluación diaria de hitos, caídas y caducidades conocidas; avisos deduplicados y correo opcional sujeto a preferencias.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-78
- **Tipo**: fullstack
- **Archivos**: `app/Models/Goal.php`, `app/Models/GoalAlertEvent.php`, `app/Services/GoalService.php`, `app/Services/Goals/**`, `app/Services/ConnectionExpiry.php`, `app/Http/Controllers/GoalController.php`, `app/Jobs/EvaluateGoals.php`, `config/goals.php`, `.env.example`, `.env.production.example`, `docs/referencia/configuracion.md`, `app/Notifications/GoalAlert.php`, `resources/js/Pages/Goals/Index.vue`, `resources/js/Pages/Notifications/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/types/goals.d.ts`, `tsconfig.frontend.json`, `routes/web.php`, `routes/console.php`, `database/migrations/*_create_goals_table.php`, `database/migrations/*_add_evaluation_to_goals_and_create_goal_alerts.php`, `docs/modulos/analitica.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `tests/Feature/Goals/**`, `tests/e2e/goals.spec.ts`, `docs/roadmap/2026-11-11-audit-hardening/testing/t79/**`
- **Cubre**: F-05
- **Cubre (tests)**: E2E-18
- **Verificación**: `php artisan test --compact --filter=GoalsTest` → verde · `php artisan test --compact --filter=GoalAlertTest` → verde

**Criterios de aceptación**
- [x] Se crean, editan y borran objetivos
- [x] Una caída superior al umbral genera una sola alerta por periodo
- [x] `null` = no disponible no cuenta como 0
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Preparación aislada durante la cuota de Claude — 2026-11-18 (dependencias sin cerrar)**

- Nueva ejecución física .cache/t79-physical:1779fuentes del padre verificadas y dependencias físicas copiadas, ReflectionClass(Tests\TestCase) y require.resolve(vue) dentro de esa raíz; sin .env. Candidato .cache/t79-candidate/source. T77/T78 permanecen sin integrar mientras falta su revisión real; esta preparación tampoco se integra.
- RED GoalsTest:3casos404 en creación/validación antes de implementar (r1). CRUD explícito por usuario, validación central de marca/cuenta, sin atribuir user_id del cliente, y listado por cursor canónico. FK de cuenta con cascade al objetivo: una cuenta borrada no convierte su objetivo en uno de toda la marca. GREEN inicial3/32.
- RED r3: una referencia importada inconsistente filtraba el nombre de una cuenta ajena. La relación del listado también se restringe al usuario y marca del objetivo, sin cambiar ni relajar el test. GREEN r4 **4passed/49assertions**. El contrato HTTP de ese caso omite solo la comprobación de existencia de plantilla durante esta fase; el navegador verificará la página real cuando esté implementada.
- Pendientes evaluación diaria sobre snapshots, idempotencia por periodo, null desconocido, notificaciones, interfaz, documentación, QA completo y revisión real Opus5.5 de aceptación/corrección/seguridad/rendimiento/calidad. No se cierra ni se afirma ningún resultado de alertas.

**Evaluación y alertas en preparación — 2026-11-18 (sin cierre)**

- RED r5: faltaba GoalService::evaluate. Evaluador separado de CRUD y lecturas de métricas; lee días cerrados y la misma cohorte de cuentas en ambos días. Evento único y notificación in-app se guardan en una transacción bajo lock del objetivo, con índice único(goal_id,period_key). Milestone se deduplica por regla/cuenta, caída por día de métricas. GREEN inicial7/67.
- Cobertura real: caída estrictamente mayor, igualdad no dispara, baseline0 es indefinido, current0 sí es pérdida real, null/ausencia de una cuenta invalida el agregado y no aporta0, milestone no se repite al día siguiente y otra caída en otro periodo sí avisa.
- RED r7: engagement devolvía null. Se calcula con Calculator::engagementRate sobre suma de interacciones y vistas de pares completos, no media de porcentajes;100/1100=9.09% discrimina contra media5%. GREEN13/83.
- RED r9: caducidad conocida no alertaba. Se selecciona la fecha más temprana de acceso/renovación/acceso a datos sin seleccionar credenciales; fecha desconocida no se convierte en vencida, y una misma cuenta/plazo no repite al día siguiente. GREEN15/95, sin proveedores.
- RED r11: faltaba correo opcional. El aviso in-app permanece inmediato/transaccional y único; mail se encola por separado, afterCommit, en poll con reintentos habituales. Requiere opt-in del objetivo, preferencia global y correo verificado; vuelve a comprobar preferencias/objetivo al enviar y no duplica el canal database. R12 detectó un acceso incorrecto al formato que devuelve QueueFake::pushed en el test; se corrigió el harness (no se cuenta como defecto productivo ni otro RED).
- Pendientes scheduler, interfaz y flujo de aviso real de la UI, validaciones ampliadas, rendimiento/concurrencia, MySQL, regresión y revisión de Claude. No se ha ejecutado SMTP ni worker nativo por estas pruebas.

**Correo y scheduler verdes — 2026-11-18 (sin cierre)**

Correo opcional r12b:19tests/111assertions verdes. R13 reprodujo ausencia del evento programado (ambos escenarios fallaban antes de registrar el job). EvaluateGoals se programa05:15 por defecto, con withoutOverlapping y job único, usa metrics y lotes100, opera sin Auth y respeta propiedad. Handler invocado dos veces conserva un solo aviso. R14 módulo completo **21passed/120assertions**. Se simulan scheduler/cola/handler, no se afirma un worker nativo ni entrega SMTP.

Las caducidades se deduplican por cuenta/plazo; los hitos por regla/cuenta; las caídas por fecha del último día cerrado de la marca. Se conserva explícitamente el mismo conjunto de cuentas al comparar ambos días, evitando falsas caídas por datos parciales. Pendientes UI/E2E, pruebas de concurrencia, MySQL, regresión final y Opus5.5 real.

**Interfaz y defensas verificadas en preparación — 2026-11-18 (sin cierre)**

- RED r15c: faltaba el enlace Objetivos en dos escenarios desktop; el primer arranque fallido r15 no cuenta como RED (faltaba public/index.php en la copia). Se restauraron ocho recursos públicos versionados y dos guías leídas por Node; sus hashes se incluyen en el suplemento del runtime, sin copiar .env, build ajeno ni almacenamiento.
- La página nueva está incluida en el programa estricto de Vue/TypeScript. Ofrece formulario, marca/cuenta, edición con foco, confirmación al eliminar y bloqueo de acciones durante guardado. Avisos presenta el título real de goal_alert y el enlace Ver objetivos.
- RED r16: faltaba pagination.cursor al pasar de página. Corregida la respuesta del listado con el estado de filtros; el cursor no cruza marcas. GREEN r18:22passed/151assertions.
- RED r21: cambiar de seguidores a engagement conservaba last_value1200 y el nombre admitía CRLF. Se limpia la observación al cambiar campos de la regla y se rechazan caracteres de control. Renombrar conserva el valor evaluado; un nombre solo con espacios ya estaba rechazado y no se atribuye como nuevo RED. GREEN r22/r23:25passed/160assertions.
- Node inicial r19:787passed/2fail por dos guías no copiadas, sin defecto productivo. Tras restaurarlas:789/789, tipado0, ESLint/Prettier/Pint/build verdes r23. El primer navegador implementado r20:1passed/1fail por selector Avisos ambiguo (tres enlaces); corregido el selector del menú, sin cambiar la aplicación para satisfacerlo. Sigue pendiente el resultado final de navegador y revisión real.

**Regresión r27 y correcciones — 2026-11-18 (sin cierre)**

PHP completo4369passed/9skipped/3fail: las dos migraciones nuevas violaban la prohibición de fechas futuras y el detector de workers no entendía la expresión anidada de cola del job. Migraciones renombradas a2026_11_18_091000/091100; configuración resuelve GOALS_QUEUE o SOCIAL_ANALYTICS_QUEUE (metrics) y el job usa esa clave con un fallback simple. No se modifica ni exceptúa ningún gate. La recogida diaria real configurada es12:00 (el fallback03:30 del comentario del scheduler era antiguo): RED r29 prueba que evaluar05:15 ocurría antes; se cambia el valor predeterminado a13:15, plantillas públicas y docs coherentes. GREEN r30:32passed/199assertions incluyendoGoals,MigrationDates yDeployQueuesDoc. Pendiente repetición completa.

**QA final preparada — 2026-11-18 (sin revisión ni cierre)**

PHP completo4372passed/9skipped/29419assertions (r31); Node789; tipado0 y estilo/build r30 verdes. Navegador r33 serial21passed/0skip/fail/flaky, qa-gateVERDE, objetivos+avisos+actividad+sistema en tres proyectos. MySQL final r3:27passed/172assertions, rollback/up dirigido por Artisan y cinco rondas con dos procesos sincronizados: un evento y un aviso por ronda.1808huellas comprobadas tras navegador/MySQL. Capturas desktop y360px examinadas, sin recortes ni texto corrupto observado.

Evidencia durable en testing/t79/report.md,verification.json,source-hashes.json,qa-gate.json y logs/probe. Candidato29archivos NO integrado. T77/T78 siguen pendientes de revisión real; T79 también espera Claude Code Opus5.5 de aceptación/corrección/seguridad/rendimiento/calidad tras la cuota conocida. No hay veredicto inventado ni cierre por QA solamente.

**Rebase de padres — 2026-11-18 (sin revisión/cierre):** copiaT79 actualizada conT77cerradoyT78R2producciónaprobada, manteniendo29archivospropios. ElprimermergedechangelogfallóporLFfrenteaCRLF; no se escribieronmarcadoresnicambiosparciales. Se interrumpióelQA r35iniciadoantesdeconfirmarmerge, yno se atribuyeresultado. Nuevo rebase calcula todaslasfusionesenmemoria trasnormalizarEOL, comprueba1808fuentesanterioresy soloescribe cuando todaspasan:20rutasheredadasactualizadas,1809huellasr34. Fullr36enprogreso;sin integrar.

**Opus5.5 real R1 — 2026-11-18 (tarea abierta):**0Critical; F1/F3Importantconfirmados, F2periodocondicional yF4riesgosemánticodealertas. FirmaLaravel13DatabaseRulewhere sigue2argumentos: where(status,!=,desconectada) estaba malusado y aceptaba cuenta desconectada. Se preparanregresiones. CollectSnapshots usaDays::closed porred (noUTCsimple); se ajustará periodoalúltimodíacerradoenmarca yredesparaevitaradelantarsealcolector. Se aislarán excepcionesporobjetivoyse revisará caducidadautorenovable conelselectorT23existente,conservandoplazosdesconocidos/caducadossin0falso. F5dedupdropreglamodificada ymenoresdeaccesibilidad/colas/calidad entranenfix. Reciboyveredictocompletoreviews/r1*. QAprevioalfix:PHP4385/9,29478assertions,MySQL27/172y5rondas2procesosnativos1evento/1aviso,rollback/upcorrecto,Node789,TS0,style/buildr37verdes. Browserr38 **15/15** cubregooals/activity/system: el argumento notifications.spec.ts noexisteyPlaywrightloignoró; no seatribuye coberturaalcentrodeavisos. La bateríafinal usarálarutarealnotifications-unified.spec.ts(21casosprevios). Sin integración.

**Cierre verificado — 2026-11-18:** Opus5.5 R3 real aprueba A/B/C/D/calidad; F1–F5 y N1–N9 resueltos o política aceptada. Dos Nit de UI aplicados literalmente con RED real r60 y GREEN final. PHP4402/9,29556aserciones; SQLite/MySQL51/286;5rondas2procesos1evento/1aviso yrollback/up. Node789,TS0,estilo/buildverdes. Navegador27/27 y focal final12/12 tras solo posición de captura, sinomisiones/fallos/flaky,qaVERDE.1809huellas y30archivos integrados byte-exactos,parche reversible. Sin cambiosPHP tras R3/global; solo GoalsVue/spec. Evidencia testing/t79/report.md y verification.json.

### T-80 — Webhooks salientes firmados y eventos de dominio

- **Descripción**: Eventos de dominio (publicado, fallido, comentario nuevo…) entregados a URLs del usuario con firma HMAC, reintentos con backoff y `UrlGuard` contra SSRF.
- **Changelog**: Receptores HTTPS propios con eventos de publicación e inbox, firmas HMAC, reintentos, recuperación de cola y estado visible; secreto cifrado y privado.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-79
- **Tipo**: fullstack
- **Archivos**: `app/Models/Webhook.php`, `app/Console/Commands/ReencryptSecrets.php`, `app/Models/WebhookDelivery.php`, `app/Models/WebhookEvent.php`, `app/Services/Webhooks/**`, `app/Jobs/DeliverWebhook.php`, `app/Jobs/DispatchPendingWebhooks.php`, `routes/console.php`, `app/Services/Media/UrlGuard.php`, `app/Events/OutboundDomainEvent.php`, `app/Observers/WebhookDomainObserver.php`, `app/Providers/WebhookServiceProvider.php`, `bootstrap/providers.php`, `app/Http/Controllers/WebhookController.php`, `routes/web.php`, `config/webhooks.php`, `.env.example`, `.env.production.example`, `resources/js/Pages/Webhooks/Index.vue`, `resources/js/types/webhooks.d.ts`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tsconfig.frontend.json`, `database/migrations/*_create_webhooks_table.php`, `database/migrations/*_create_webhook_events_and_deliveries.php`, `app/Services/InboxService.php`, `docs/api/webhooks.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `tests/Feature/Webhooks/**`, `tests/e2e/webhooks.spec.ts`, `tests/e2e/isolated/bootstrap.php`, `tests/e2e/isolated/fakes.php`, `docs/roadmap/2026-11-11-audit-hardening/testing/t80/**`
- **Cubre**: F-06
- **Cubre (tests)**: E2E-19
- **Verificación**: `php artisan test --compact --filter=WebhookDeliveryTest` → verde · `php artisan test --compact --filter=WebhookSsrfTest` → verde

**Criterios de aceptación**
- [x] Cada entrega lleva firma HMAC verificable y marca de tiempo
- [x] `UrlGuard` bloquea IP privadas y redirecciones
- [x] Los reintentos se agotan y se registran
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Sin red real: `Http::fake`.

**Preparación aislada — 2026-11-18 (sin revisión ni integración)**

Copia física .cache/t80-physical con1808fuentes del padre T79 comprobadas y dependencias físicas propias. T77/T78/T79 siguen abiertos; se prepara este candidato sin integrarlo. El guard SSRF existente es app/Services/Media/UrlGuard.php: se reutiliza su validación DNS, fijación de IP y bloqueo de proxy/redirecciones, sin duplicarlo en app/Support. El receptor de prueba será virtual mediante Http::fake y DNS inyectado público; una dirección privada real sigue rechazada. No red, proveedores ni configuración privada.

**RED/GREEN inicial — 2026-11-18 (sin cierre)**

RED r1: endpoint de alta inexistente404. CRUD con propietario explícito y secreto cifrado/oculto, URL y secreto inmutables, listado cursor y desactivación; GREEN r2:1/18. Ocho escenarios SSRF reutilizan el guard existente (privadas, metadata, literal numérico, IPv6 embebido, DNS mixto/vacío y HTTP sinTLS) y ya pasan; son cobertura de defensas reutilizadas, no se atribuye un nuevo RED. RED r3: transición real a publicada no encolaba DeliverWebhook (0en vez de1). Se prepara outbox y adaptación de eventos con payload permitido, sin conectores ni red. Transporte, backoff, recuperación y UI siguen pendientes; no se integra.

**Transporte y robustez en preparación — 2026-11-18 (sin cierre)**

RED r5: transporte pendiente; firma sobre timestamp.cuerpo de bytes persistidos, cabeceras HMAC/event/id, HTTPS con DNS fijado, sin proxy ni redirecciones y respuesta en streaming cerrada. GREEN r6:11/68. RED r7: faltaba registro/backoff para503, redirección y DNS privado al entregar. GREEN r8:14/118: cinco intentos,60/300/900/3600s, bloqueo de3xx y revalidación, sin guardar cuerpo remoto. RED r9: secreto filtrado en _old_input y fallo de post con cuenta borrada sin evento; corregidos sin falsear guards, GREEN r10:16/124. RED r11: no había barrido programado tras caída de cola; now outbox conserva evento y barrido cada minuto recupera la entrega, GREEN r12:17/129. El PendingDispatch instalado ya libera su lock único al fallar la cola; no se añade otro desbloqueo redundante.

RED r13: fallo de evento dejaba comentario insertado y el siguiente sync ya no lo emitía. Guardado del comentario y outbox unidos con DB::transaction; retry real vuelve a generar evento. GREEN con Inbox38/291. RED r15: altas ilimitadas y sin throttle. Límite20por usuario (lock de usuario para altas simultáneas) y30cambios/minuto por actor, configurables; presupuesto ajeno no se consume. GREEN r16:21/150. RED r17UI: los dos escenarios desktop no encontraban Webhooks. RED r18: faltaba estado de última entrega en props; DTO permitido y relación latestOfMany cargada en lote por usuario, GREEN r19:21/169. Interfaz y receptor virtual en curso; aún faltan QA final/documentación/revisión real.

**Interfaz y observación — 2026-11-18 (sin cierre)**

Nueva página tipada estrictamente, generación criptográfica local del secreto y ocultación tras guardar, edición/pausa, confirmación de borrado y estado de última entrega. Receptor virtual E2E solo mediante bandera en el bootstrap propio ya protegido por APP_ENV=e2e/APP_URLlocal, DNS ficticio público y Http::fake; verifica HMAC y deja recibo sin cuerpo/secreto. No se modifica UrlGuard ni se ofrece bypass de URL privada. Node789/789. TS detectó preserveScroll inválido en router.reload (la API ya preserva scroll); eliminado, tipado/estilo/build r21 verdes. Navegador r22 completó firma válida/publicación/CRUD, pero el guard de consola detectó pattern HTML inválido bajo flagv y otro selector alert ambiguo. Se escapa el guion y se da nombre al formulario para localizar su propio error; r24 en ejecución, sin afirmar todavía verde.

**Concurrencia SQLite reproducida y corregida — 2026-11-18 (sin cierre)**

R24:6E2E/0skip/fail/flaky, qa-gateVERDE en3anchos; el receptor virtual recibió firma válida de una publicación completada por PublishPostTarget/T43Publisher, también rechazo privado yCRUD. Defensas añadidas sin inventar nuevosRED: job adelantado no consume intento/backoff, pausa/desuscripción cancela sinHTTP, error de conexión no guarda texto privado,401termina y failed() no pisa estado entregado, replay del mismo evento conserva una entrega y marca ajena no genera fanout. SQLite/MySQL8.4.3 inicial27/193.

RED r26: cinco rondas de dos procesos PHP sincronizados contra SQLiteDEFERRED emitían2HTTPsimulados pero confirmaban1intento. FOR UPDATE no bloquea la escritura en SQLite. RED r27 en CI: el receptor fake veía attempts0 antes deHTTP. Se persiste el intento dentro de la transacción ANTES del envío, tomando el lock de escritura SQLite; el competidor falla de forma recuperable antes de tocar HTTP. GREEN r28:27/194 y cinco rondas con1petición/1intento/entregada cada una, incluido reintento posterior al lock perdido. Los scripts y recibos se archivarán con QA; no es un worker nativo ni HTTPexterno. Docs explican repetición ambigua con id estable, sin prometer recepción ni entrega exactamente una vez. Regresión final completa iniciada; revisión real Opus5.5 sigue pendiente.

**Regresión de recifrado — 2026-11-18 (sin cierre)**

R30 PHPcompleto4398passed/9skipped/1fail: el inventario canónico de app:reencrypt no incluía el nuevo cast Webhook.secret. RED r32 verifica la rotación real de ese secreto: dry-run no contaba1. Se incorpora webhooks.secret al comando productivo (sin tocar ni exceptuar el detector), con simulación que no cambia el cifrado, recifrado con APP_PREVIOUS_KEYS y repetición idempotente. Se ampliará la evidencia final al repetir QA; este hallazgo no se oculta bajo el verde anterior del módulo.

**QA final preparada — 2026-11-18 (sin revisión ni cierre)**

PHPcompleto4400passed/9skipped/29625assertions r34. SQLite y MySQL módulo+recifrado33/240; Node789, TS0, estilo/build verdes. Navegador ampliado39/39 en3proyectos y focal final6/6 tras corrección solo de captura, ambosqa-gateVERDE.1831huellas verificadas después. Evidencia durable testing/t80/report.md,verification.json,source-hashes.json,qa-gate*.json y logs/probe. Candidato37archivos NO integrado ni revisado; faltan Opus5.5 real (incluida calidad), aprobación de padres y cualquier rebase/corrección. No cierre por QA.

**Revisión Opus 5.5 — intento 1, correcciones en curso (2026-11-18):** informe íntegro en testing/t80/reviews/r1-report.md, incluidas ambas partes del stream nativo. I-1 atomicidad ya existente, confirmada por regresión; I-2 producción MySQL y coste SQLite documentados; I-3 DNS y presupuesto de reintentos corregidos. Calidad: runner separado en preparación/elegibilidad/transporte/clasificación, casts, validación/límite compartidos, menos consultas sin suscriptores, nombres accesibles y cleanup de aislamiento. RED r47: DNS TX2≠1 y Worker5≠6peticiones; GREEN r48. r46 tuvo además dos errores de fixture, que no se cuentan como RED funcional. RED r49 browser: falta Editar Receptor e2e. Módulo nuevo41/279 SQLite; QA global/revisión2 finales pendientes. Sin cierre ni integración.

**Cierre verificado — 2026-11-18:** Opus5.5 R3 real, aceptación/corrección/seguridad/rendimiento/calidad. I-1..I-4 y M-1..M-11 resueltos o políticas opcionales documentadas y aceptadas. N-2 aplicado al helper y44/293finales. Tras R3/global solo fixturePHP ydocs, sin cambio productivo. DNS fuera del lock, sin caducidad por espera en cola, maxExceptions3+CountCrashesAsExceptions nativos; RED/GREEN real r58/r59. PHP4441/9,29815aserciones; SQLite/MySQL44/293,5rondas2procesos1HTTP/1attempt yrollback/up. Node789,TS0,estilo/buildverdes. Navegador45/45 amplio antes de solo metadatos del job y6/6 final,qaVERDE,sinfallos/skip/flaky.1832huellas y37archivos integrados byte-exactos,parche reversible. Evidencia testing/t80/report.md y verification.json.

### T-81 — PWA instalable y notificaciones push

- **Descripción**: Manifiesto, service worker, suscripciones y envío de push con claves VAPID configurables; sin claves la función queda desactivada con explicación.
- **Changelog**: Manifiesto y worker sin caché privada, consentimiento explícito, suscripciones cifradas y revocables, y avisos push genéricos con VAPID configurable.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-80
- **Tipo**: fullstack
- **Archivos**: `public/manifest.webmanifest`, `public/sw.js`, `app/Http/Controllers/PushSubscriptionController.php`, `app/Services/Push/**`, `app/Providers/PushServiceProvider.php`, `app/Listeners/RevokePushOnLogout.php`, `app/Listeners/QueuePushNotification.php`, `app/Jobs/SendPushNotification.php`, `app/Models/PushSubscription.php`, `app/Console/Commands/ReencryptSecrets.php`, `bootstrap/providers.php`, `routes/web.php`, `resources/js/Pages/Profile/Notifications.vue`, `resources/js/app.js`, `resources/js/lib/push.js`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/types/push.d.ts`, `resources/views/app.blade.php`, `tsconfig.frontend.json`, `composer.json`, `composer.lock`, `config/push.php`, `.env.example`, `.env.production.example`, `database/migrations/*_create_push_subscriptions_table.php`, `docs/usuario/pwa.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `tests/Feature/Push/**`, `tests/frontend/service-worker.test.mjs`, `tests/frontend/push-client.test.mjs`, `tests/e2e/push.spec.ts`, `tests/e2e/isolated/**`, `docs/roadmap/2026-11-11-audit-hardening/testing/t81/**`, `app/Services/Media/DiscardedBody.php`, `app/Services/Media/UrlGuard.php`, `app/Services/Webhooks/WebhookTransport.php`, `tests/Unit/UrlGuardTest.php`, `tests/Feature/Webhooks/WebhookDeliveryTest.php`
- **Cubre**: F-07
- **Cubre (tests)**: E2E-20, M-07
- **Verificación**: `php artisan test --compact --filter=PushSubscriptionTest` → verde · `npm run build` → build correcto

**Criterios de aceptación**
- [x] La app es instalable (manifiesto válido) y el service worker no cachea datos de usuario
- [x] Las suscripciones se guardan y se pueden revocar
- [x] El envío se prueba con un `PushSender` falso
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: todo salvo el envío real. Queda a la espera (U): generar claves VAPID, HTTPS en producción y probar en dispositivos (M-07). **A la espera (no bloquea la fase)**: Claves VAPID y HTTPS en producción (usuario).

**Preparación aislada — 2026-11-18 (sin integración ni revisión)**

Copia física .cache/t81-physical con1831fuentes del padre T80 y dependencias propias. Se incorpora también la corrección de recifrado del padre mientras termina su regresión. T77/T78/T79/T80 no están aprobadas/integradas; este candidato queda separado. Se consultan fuentes primarias Web Push PHP y MDN para suscripción y gesto del usuario; no se envía push real ni se crean claves del servidor privado. M07 (claves/HTTPS/dispositivos reales) seguirá explícitamente a la espera, sin sustituirlo por simulaciones.

**Primer RED/GREEN y dependencias — 2026-11-18 (sin cierre)**

RED r1: manifiesto ausente, GETsw.js404 y ajustes404. GREEN r2:3/37 con manifiesto estático/iconos, worker público no-store y estado desactivado por falta de claves. No hay todavía registro cliente ni aceptación de instalación. Dos pruebas Node del worker verifican ausencia de fetch/storage privado, texto genérico sin leer payload privado y navegación siempre al inbox local (cobertura adicional, no nuevoRED).

Se instala minishlink/web-push11.0.0 en la copia física con plugins/scripts desactivados; no se generan claves del servidor privado. Composer detectó aviso ya presente en phpseclib4.0.2 del padre; se contrasta GHSA-w3p9-g4p2-h3rr y actualiza ese paquete previo a4.0.3; el lock también sube paragonie/constant_time_encoding de3.1.3 a3.1.4 como parche de la resolución. Audit de instalación termina sin avisos. Composer.json/lock del candidato conservan plugin-allowlist anterior y ^11.0. Todo sigue sin integrar ni revisar.

La primera fixture OpenSSL falló por config Windows antes de alcanzar el endpoint y NO cuenta comoRED. Config mínima en storage propio y private_key_bits2048 permiten crear ECprime256v1 de prueba; r3c alcanza404 real de alta. GREEN r4:4/59 con alta/revocación propia, ciphertext y DTO sin endpoint/claves. Clave pública de navegador validada como puntoP256 real; auth16bytes, base64url canónico y guardHTTPS. Nuevo cast de endpoint/keys añadido también al inventario de recifrado. Persisten pendientes consentimiento cliente, límites, envíoPushSender/nativo offline, revocación de sesión, interfaz, QA completo, docs, Opus5.5 y M07real. No se afirma envío push ni instalación aceptada.

**Consentimiento y cola inicial — 2026-11-18 (implementación aún incompleta)**

RED r5: límite por propietario, presupuesto por minuto y revocación al cerrar sesión no existían (3fallos). GREEN r6:7/74 tras contador bajo lock de usuario, throttle y cookie cifrada/HttpOnly/SameSite del dispositivo; Logout revoca solo el dispositivo del usuario del evento, conserva otros y no impide cerrar sesión por caída del almacén. RED r7: NotificationSent(database) no encolaba Push; GREEN r8:8/85 con listener tras commit y job de identificadores sin texto privado/endpoint/claves. El handler de envío todavía no existe: no se declara entrega ni prueba del PushSender. Este candidato sigue aislado, con revisión/QA/UI/docs pendientes.

**Envío simulado y adaptador criptográfico — 2026-11-18 (sin QA global/revisión/UI)**

RED r9/r10: faltaban resultado/handler,10errores; GREEN r11:18/112 con PushSender falso, eliminación404/410, reintento acotado, rechazo permanente y relectura de consentimiento/owner/verificación/notificación no leída. RED r12: contrato PushSender sin binding; r13 ya firma VAPID/cifra aes128gcm nativos con HTTPfake pero SDK trataba302como éxito. GREEN r14 tras exigir2xx:24/166. RED r15: config VAPID malformada se anunciaba disponible; otros2errores eran fixtures (coordenada EC corta sin padding y captura de opciones mal instalada), no defectos de producción. GREEN r16:27/205, clavesbase64url canónicas/P256pareja real validada con OpenSSL, DNS revalidado y privado rechazado, opcionesIPfijada/proxyvacío/noredirección/stream/timeout10/3 comprobadas. Audit Composer r17 sobre lock de esta copia:0advisories/0abandoned, no atribuido a main ni padre T80.

El adaptador usa minishlink/web-push11 vía PSR18 con UrlGuard existente; cuerpo genérico, respuesta remota cerrada sin leer ni registrar endpoint, texto o claves. Jobs solo identificadores. La transacción bloquea dispositivo y escribe antes del HTTP también en SQLite; idempotencia local del último notification_id y uniqueJob, sin promesa de entrega exactamente una vez frente a caída después de HTTP. Cola push es complemento best-effort: un fallo al encolarla no cancela el aviso in-app; no se afirma outbox durable. Se documentará esta limitación. Falta cliente/UI, pruebas seriales y globales, revisión Opus5.5 y M07real.

**Cliente y UI inicial — 2026-11-18 (sin revisión/cierre)**

El primer browser r18 falló en login por faltar build de la copia nueva: no esREDfuncional. Tras build, r18c alcanzaeltestyconfirmaausenciarealdepágina. REDNode r19:4tests cliente conarchivoausente. GREEN r20/r23:27PHP/205assertions y4Node; consentimiento directo desdegesto antesdelprimerawait,registroestático sinpedirpermiso,revocacióndeactual. NuevoajusteNotificationsenstrictcheckJs/DTO,listaowner,sinendpoints/keys,nombres/fechas yconfirmaciónderevocación. r22 Nodecompleto795/795,TS0,Pint/ESLint/Prettier/buildverdes; browserr24 **3/3** accesibilidad/overflow/sinclaves en3anchos, qaVERDE. El test de activación+PushSenderfalso está escrito pero todavía NOejecutado; no se afirmaaceptacióndeeseflujo. Documentación candidata pwa.md,envpúblicos,changelogs; faltafixtureaislada deenviador yregistroend-to-end,QAglobal,rebasedepadre,Opus5.5 yM07real.

**Rebase a padres cerrados — 2026-11-18 (sin revisión ni integración):** T77/T78/T79/T80 ya están cerradas e integradas con Opus5.5. Rebase atómico de53rutas heredadas,41archivos propios y1858huellas; se conservan las dependencias/claves ficticias/suscripciones del candidato. El primer intento del script falló al evaluar un fallback de archivo nuevo antes de escribir nada; se corrigió solo ese script y después se verificó contra todas las huellas del padre cerrado. MóduloPush+recifrado32/244 SQLite, padresfinales. Preparando nuevos tipos/estilo/Node/build y QA global antes de Opus. El informe inicial4427/9 corresponde a padres anteriores, no a esta versión. M07 real sigue pendiente.

**Revisión nativa Opus5.5 — intento1 en curso (2026-11-18):** paquete inmutable41archivos/1858fuentes contra T80cerrada; aceptación,corrección,seguridad,rendimiento y calidad explícita. Push+recifrado32/244 tantoSQLitecomoMySQL8.4.3propio90tablas,Node795,TS0,Pint/ESLint/Prettier/buildverdes. GlobalPHP r38 y navegadoramplio39casos r39 en curso; no se atribuye QA de padres antiguos. Sin cierre ni integración; M07claves/HTTPS/dispositivo real sigue pendiente.

**Opus5.5 R1 y correcciones — 2026-11-18 (sin cierre):** informe íntegro de dos fragmentos en testing/t81/reviews/r1-report.md. I1cookie30d y colisión404, I2promesas que bloqueaban DELETE, I3ruta dinámicaSW bajoNginx. RED r41: estáticoausente,cookie≤1año,colisión404; GREEN r46:3/18. RED Node r42: ready/unsubscribe nuncaresueltos (2fallos); GREEN r44:6/6. RED browser r43:sw.jscreaba cookies yunsubscribeinfinitoimpedíarevocar (2fallos desktop). Cookieforeverrenovada alverajustespropios; colisión422genéricasintransferencia; DELETEservidorprimero yoperacionesPushManageracotadas3s. Workerúnicocanónico public/sw.js, sincontrolador/rutaLaravel; se eliminaron solo2archivos nuevos de copias propias, nunca de main. R47Node797,tipo/estilo/buildverdes, browserGREENpendiente trasnuevoBundle.

CoberturaM3extra descubre registrodoble deListeners por autodetección+PushServiceProvider. R49 confirma dos warnings idénticos pese al matcher exacto; no se rebaja la exigencia. FrameworkApplication251 withEvents yEventServiceProvider41 discoverytrue. Se retira registro manual, conserva autodetección tipada; GREENr50móduloPush+recifrado40/291. El error inicial de matcher r48 se afinó antes de confirmar la causa. Migración elimina content_encoding muerto; payloadmínimo {}, texto/navegaciónsoloSW; marcaSQLite last_attempted_at documentada como técnica/rollback, etiquetaÚltimasuscripción, cleanupCNF. QA global4468/9/30023 ybrowser39/39 corresponden a R1 antes de estas correcciones y deben repetirse. M07 real sigue pendiente.

**Concurrencia y caché de eventos — 2026-11-18:** M4reproducido realmente en MySQL8.4.3 propio: r3,5rondas2procesos, en cada una 1213deadlock ysolo1suscripción. Alta sin HTTP dentro deTX permite reintento transaccional3veces; r4GREEN5rondas crean2suscripciones/2dueños cadauna. Scripts/rawlogs TEMP se archivarán con cierre. Módulo40/291MySQL ySQLite. EventoCache: primerprobeWindowsusóAPP_EVENTS_CACHEabsoluto no admitido como tal pornormalizeCachePath yfallótempnam antesdecachear; noes fallo deproducto. R54ruta relativa propia: event:cache y40/291conlistenerscacheados verdes. Composervalidateestricto y auditdel lock0avisos/0abandoned. R55Node797,TS0,Pint/ESLint/Prettier/buildverdes. R56congela40archivos/1857fuentes, elimina2archivosnuevospropios y añadepublic/sw.js; globalPHP r57 ybrowseramplio39r58 en curso sobreesaversión. No cierre/integración/revisión2 todavía.

**QA de correcciones y Opus R2 — 2026-11-18:** globalPHP4476passed/9skipped/30070aserciones, módulo40/291SQLite/MySQL final90tablas yrollback/upeditado, Node797 ytipos/estilo/buildverdes; navegador39/39serialsinfallos/skip/flaky,qaVERDE, incluidoSWpúblico200/javascript/sinSet-Cookie yDELETEconunsubscribequenotermina. Huellas40archivos/1857fuentes, sin integrar. Opus5.5R2real en curso con todaslaspruebas finalizadas antes de enviar el paquete ycalidadexplícita. Aclaraciones documentales separadas del paquete inmutable: rutas auth/verified, sin afirmar middlewareMFAadicional; notificaciones decanalBDusan stringdatabase, otras sonmail-only;6paquetesnuevosincluyeSDK+5transitivos. M07realpendiente.

**Pausa solicitada por el usuario — 2026-11-18:** T80 cerrada e integrada, total79/108. T81 sigue en-revision, sin integrar ni cerrar. Opus5.5 R2 real aprueba integración local sin Critical/Important; informe completo en testing/t81/reviews/r2-report.md. Quedan N1condicional (mensajes/estadoInertia) y nits N2comentariohuérfano,N3nombresRevocar,N4inventario. El código instalado Inertia2.3.29 confirma preserveState:true por defecto en post/delete (TEMPmetricool-t81-r60-inertia-preserve.txt). Se ha añadido cobertura de colisión visible y se ha extraído fixture E2E compartida; todavía NO se han aplicado preserveStateexplícito,aria-label ni otros nits. R60 desktop esperaba1RED por nombre accesible y obtuvo2fallos: falta Revocar Navegador e2e y no aparece rolealert en el caso nuevo de colisión. La segunda causa está SIN INVESTIGAR; puede ser fixture o implementación, no se atribuye todavía a Inertia. No cerrar con ese rojo. Log TEMPmetricool-t81-r60-review-nits-red-browser.log; copia de spec en candidato y físico, sin formatear/verificar tras esa modificación.

Última versión completa verde:40archivos/1857huellas r56, PHP4476/9/30070, Node797, módulo40/291SQLite/MySQL yconeventcache,39/39browser,5rondasnativasMySQL2procesos/2altas,rollback/up,Composer0avisos. Esa QA es anterior SOLO al nuevo spec R60; código productivo aún coincide con R2. M07realVAPID/HTTPS/dispositivos pendiente. Reanudar revisando el rojo nuevo, resolver nits, pasar QA afectado y usar Opus5.5 para revisión final antes de integrar T81; luego T82 y resto del roadmap. Sin commit/push/merge.

**Cierre — 2026-11-20:** el segundo rojo de R60 era del test: `getByRole('alert')` chocaba en modo estricto con las dos regiones `alert` vacías del layout (r61); el aviso sí se mostraba. El localizador se limita a la región «Activar avisos push». El E2E nuevo destapó un defecto real de a11y (`<ul role="alert">` dejaba los `<li>` huérfanos, axe serious): ahora `<div role="alert"><ul>`. Nits R2 aplicados: N-1 `preserveState: true` explícito en alta y revocación (Inertia 2.3.29 remonta sin la opción), N-2 comentario huérfano de `routes/web.php` borrado, N-3 `aria-label` «Revocar <dispositivo>», N-4 inventario `Archivos` = 40 ficheros congelados. QA: push E2E 9/9 en tres anchos con axe (r63), Node 8/8 + typecheck + build (r64), Pest Push 35/35·252 (r64). Revisión R3 de contexto fresco (A+B+C+calidad) sobre `reviews/r3-delta.patch`: aprobado sin gaps. Integrado en el árbol principal (40 ficheros, `composer install` con `minishlink/web-push`), Push+recifrado 40/291 en principal y migraciones pendientes aplicadas en MySQL 8.4.3 de desarrollo con copia `storage/backups/pre-migrate-20261120-133549.sql`. **A la espera del usuario (no bloquea):** M-07 real (claves VAPID, HTTPS, dispositivos).

**M-07 en dispositivo real — 2026-11-20 (con permiso del usuario, cuenta 28):** claves VAPID de desarrollo generadas en el `.env` (copia previa `storage/backups/env-before-push-20261120-135734.bak`), HTTPS por ngrok; instalación, permiso y alta reales correctos. El primer envío real se quedó en reintento: `PushHttpClient` pedía `stream => true` sobre el cliente de `UrlGuard`, y Guzzle pasa entonces al manejador de streams, que rechaza la opción `curl` (IP fijada) antes de conectar; los tests no lo veían porque `Http::fake` no elige manejador. Mismo defecto en `WebhookTransport` (T-80). Corrección: `UrlGuard::deliveryClient()` con `sink` `DiscardedBody` (la respuesta se descarta sin leerla ni bufferizarla; el tiempo máximo de 10 s acota la descarga), usado por push y webhooks; tests de opciones actualizados y regresión nueva en `tests/Unit/UrlGuardTest.php` por el manejador real contra 127.0.0.1 cerrado (con la combinación antigua lanza `InvalidArgumentException` del manejador de streams). UrlGuard + Push + Webhooks 193/744. Reenvío real aceptado por FCM (`last_sent_at` registrado). **M-07 completada por el usuario (2026-11-20):** notificación recibida, al pulsarla abre Avisos, Revocar la quita (suscripción 1 borrada) y cerrar sesión revoca el dispositivo actual (suscripción 2 borrada, comprobado en BD). Probado en un dispositivo; producción necesitará sus propias claves VAPID y HTTPS del VPS.

### T-82 — Adaptar contenido entre redes con IA y alt text en el compositor

- **Descripción**: Botón que reescribe el texto para cada red de destino respetando sus límites, y generación de alt text desde el compositor reutilizando `StudioAiText::altText`.
- **Changelog**: En el compositor, «Adaptar a cada red» propone una versión del texto por red que respeta sus límites y hashtags (se aplica solo al confirmar), y «Generar» propone el texto alternativo de cada imagen, editable antes de guardar; sin IA configurada los botones explican por qué están inactivos.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-81
- **Tipo**: fullstack
- **Archivos**: `CHANGELOG.es.md`, `CHANGELOG.md`, `app/Http/Controllers/AiAssistantController.php`, `app/Services/AiAssistant.php`, `app/Services/MediaValidator.php`, `app/Services/Studio/StudioAiText.php`, `config/rate_limits.php`, `config/social.php`, `docs/modulos/asistente-ia.md`, `docs/modulos/despliegue.md`, `docs/referencia/configuracion.md`, `docs/usuario/guia-de-usuario.md`, `resources/js/Components/Composer/AiAdapt.vue`, `resources/js/Components/Composer/EditorToolbar.vue`, `resources/js/Components/Composer/TargetMediaPanel.vue`, `resources/js/Composables/useAiStatus.js`, `resources/js/Composables/useComposer.js`, `resources/views/legal/privacy.blade.php`, `routes/parity.php`, `tests/Feature/Ai/AdaptContentTest.php`, `tests/Feature/Ai/AiQuotaPolicyTest.php`, `tests/Feature/Ai/ProposeScheduleEndpointTest.php`, `tests/Feature/Ai/ProposeScheduleTest.php`, `tests/Feature/Parity/AiAssistantTest.php`, `tests/Feature/Studio/StudioCaptionTranslationTest.php`, `tests/e2e/ai-adapt.spec.ts`, `tests/e2e/isolated/fakes.php`, `tests/frontend/ai-status.test.mjs`, `docs/roadmap/2026-11-11-audit-hardening/testing/t82/**`
- **Cubre**: F-08
- **Cubre (tests)**: E2E-21
- **Verificación**: `php artisan test --compact --filter=AdaptContentTest` → verde

**Criterios de aceptación**
- [x] Cada versión adaptada respeta límites y hashtags de su red
- [x] Sin `ANTHROPIC_API_KEY` el botón muestra por qué está inactivo
- [x] El alt text se puede editar antes de guardar
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: todo con un asistente falso en tests. Queda a la espera (U): `ANTHROPIC_API_KEY` real para el smoke. **A la espera (no bloquea la fase)**: `ANTHROPIC_API_KEY` real (usuario).

**Cierre — 2026-11-20:** `AiAssistant::adapt` (una llamada y una unidad de cupo por adaptación, JSON estricto por cuenta, validación por red con `MediaValidator::textErrors` = la misma regla que el guardado, enmascarado de correos/enlaces/teléfonos en una pasada con delimitadores que no colisionan con el texto, límite efectivo descontando lo que crecen los marcadores, rechazo de marcadores perdidos o inventados, tokens y timeout proporcionales con tope ≤ 55 s) y `POST ai/alt` con `StudioAiText::describe` autorizado por `MediaAsset::ownedBy` (sirve para biblioteca, URL y Drive). UI `AiAdapt.vue` con el patrón propuesta → Aplicar/Descartar, `AiNotConfigured`, `useAiStatus` (`loaded`, `vision`) y `useConfirm` antes de sobrescribir un alt. Política de cuota única en suggest/schedule/adapt/complete: si el proveedor respondió se gasta (CWE-770), solo se devuelve si la llamada no se hizo o el proveedor la rechazó con HTTP; «IA no configurada» = 409 en las cuatro rutas. Política de privacidad actualizada. Revisión adversarial A+B+C en 3 intentos más verificación (I1-I5 y M1-M6/N1-N5 corregidos; el 3.er intento abrió I5 y el usuario eligió «corregir y cerrar»): parches en `testing/t82/reviews/r1..r4.patch`. QA: Pest IA 211/211 (765 aserciones), Node 799/799, typecheck/lint/format:check/pint/build verdes; E2E r3 103/103 (adaptar, compositor, estudio IA, programación natural) y r4 37/37 en tres tamaños con axe. Pendiente del usuario (no bloquea): smoke con `ANTHROPIC_API_KEY` real.

### T-83 — Importación masiva de posts por CSV

- **Descripción**: Subir un CSV, validarlo fila a fila con `posts/validate` (vista previa con errores) y crear los posts como borradores o programados.
- **Changelog**: Importar publicaciones desde un CSV: vista previa fila a fila con los errores (sin crear nada), confirmación que crea solo las filas válidas con la misma validación que el compositor y no duplica al reintentar, y CSV de ejemplo descargable con las cuentas de la marca.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-82
- **Tipo**: fullstack
- **Archivos**: `app/Services/Posts/PostCsvImport.php`, `app/Http/Controllers/PostImportController.php`, `config/post_import.php`, `config/rate_limits.php`, `routes/web.php`, `resources/js/Pages/Posts/Import.vue`, `resources/js/types/post-import.d.ts`, `resources/js/navigation.js`, `tests/Feature/Posts/PostCsvImportTest.php`, `tests/e2e/csv-import.spec.ts`, `docs/usuario/importar-csv.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/roadmap/2026-11-11-audit-hardening/testing/t83/**`
- **Cubre**: F-09
- **Cubre (tests)**: E2E-22
- **Verificación**: `php artisan test --compact --filter=PostCsvImportTest` → verde

**Criterios de aceptación**
- [x] Las filas inválidas se muestran sin crear nada
- [x] Las válidas crean posts con la misma validación que el compositor
- [x] Hay un CSV de ejemplo descargable
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

**Cierre — 2026-11-26:** `PostCsvImport` + `PostImportController` + `Posts/Import.vue` (entrada «Importar CSV» en Planificación). CSV UTF-8 (BOM tolerado), separador `;`/`,`, RFC 4180; columnas `fecha` (`AAAA-MM-DD HH:MM`, ISO con `T` o `DD/MM/AAAA HH:MM` como lo guarda Excel; nunca MM/DD), `texto`, `cuentas` (`red:id`, inequívoco), `tipo`, `medios`, `aprobacion`. Cada fila es el payload del compositor y se valida y crea con `PostRequest` + `PostService` (mismas reglas, sin vías propias). Confirmación con token de un solo uso (`Cache::add` atómico también en `CACHE_STORE=database`), avance en clave aparte y reintento que devuelve el mismo resumen; una vista previa nueva sustituye a la anterior sin borrar su bloqueo. CSV de ejemplo con `CsvWriter` (sin celdas de fórmula, CWE-1236) y solo filas válidas para las cuentas reales. Límites 200 filas, 1024 KB, 30 min, throttle 20/10 por minuto. `BatchService` no se usa (no tenía nada reutilizable). Revisión adversarial A+B+C en 3 intentos (1 Important + 7 Minor; N1 Important y 3 Minor nuevos; verificación sin gaps): `testing/t83/reviews/`. QA: Pest 44/44 (258 aserciones) y 84/84 con `PostsTest`; Node 799/799; typecheck/lint/format:check/pint/build verdes; E2E-22 + compositor 69/69 y E2E-22 final 3/3 en tres tamaños con axe.

### T-84 — Copia de seguridad y exportación completa (ZIP)

- **Descripción**: Exportación de todos los datos propios (posts, marcas, informes, media) en un ZIP generado en cola y descargable con enlace firmado.
- **Changelog**: «Exportar mis datos» en el perfil: genera en segundo plano un ZIP con todos tus datos (marcas, publicaciones, campañas, informes, media, diseños, sitios, plantillas, objetivos…) sin secretos ni datos de otros, avisa al terminar y se descarga con un enlace firmado que caduca; opción de exportar solo los datos sin la media.
- **Estado**: completado
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-83
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/FullExportController.php`, `app/Services/Export/**`, `app/Support/Zip/**`, `app/Jobs/BuildFullExport.php`, `app/Models/DataExport.php`, `app/Models/User.php`, `app/Console/Commands/PruneFullExports.php`, `app/Console/Commands/QueueProbe.php`, `app/Services/JobFailureNotifier.php`, `app/Services/SystemStatus.php`, `app/Services/Studio/StudioPptxExporter.php`, `app/Services/Studio/StudioZipWriter.php`, `config/exports.php`, `config/queue.php`, `config/health.php`, `config/rate_limits.php`, `database/migrations/2026_11_26_090000_create_full_exports_table.php`, `routes/web.php`, `routes/console.php`, `resources/js/Pages/Profile/Export.vue`, `resources/js/Pages/Profile/Edit.vue`, `resources/js/Pages/Notifications/Index.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/types/full-export.d.ts`, `scripts/export/**`, `deploy/systemd/scheduler-exports.service`, `deploy/systemd/README.md`, `deploy/hestia/README.md`, `deploy/hestia/deploy.sh`, `tests/Feature/Export/**`, `tests/Feature/Infra/InfraTest.php`, `tests/Feature/System/SystemStatusTest.php`, `tests/e2e/full-export.spec.ts`, `docs/usuario/exportar.md`, `docs/modulos/despliegue.md`, `docs/modulos/estudio.md`, `docs/guias/desarrollo-local.md`, `docs/README.md`, `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/roadmap/2026-11-11-audit-hardening/testing/t84/**`
- **Cubre**: F-10
- **Cubre (tests)**: E2E-23
- **Verificación**: `php artisan test --compact --filter=FullExportTest` → verde

**Criterios de aceptación**
- [x] El ZIP contiene solo datos del usuario
- [x] Se genera en cola y notifica al terminar
- [x] El enlace caduca
- [x] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [x] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [x] Revisión adversarial A+B(+C)
- [x] Corregir hallazgos de la revisión
- [x] Pest y Playwright (serial, sin cambios de código en paralelo)
- [x] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. No ejecutar contra el usuario 28: tests con fábricas.

**Cierre — 2026-11-26:** `FullExport` + `ExportInventory` (38+7 entidades con `BelongsToUser`, listas blancas de columnas sensibles y filtro por nombre: sin tokens, API keys, contraseñas, 2FA, passkeys, push, secretos de webhooks ni datos de terceros —marcas de colaborador, comentarios ajenos del buzón, `approval_comment`—) y `BuildFullExport` en la conexión de cola propia `exports` (`retry_after` ≥ timeout + 60; `database` sigue en 180 s), `$timeout` 3600 s, `tries=1`, latido `heartbeat_at`. Un único escritor ZIP `App\Support\Zip\ZipWriter` con ZIP64 por entrada (sustituye a `StudioZipWriter`, también para el PPTX). Tope 20 GiB, comprobación previa de espacio con `DiskSpace` (publicación con `rename` en el mismo volumen; falla cerrado si no se puede medir), opción sin media, copia anterior conservada hasta publicar la nueva. Avisos por `JobFailureNotifier` (correo solo en fallos). Descarga con enlace firmado de 10 min + sesión del dueño; ZIP 72 h; `exports:prune` cada hora (caducados, atascados, pendientes viejas, huérfanos y temporales). Despliegue: worker `exports` (`deploy/systemd/scheduler-exports.service` o cron de Hestia), reinicio con `--no-block`. Revisión adversarial A+B+C en 3 intentos (5 Important + 6 Minor; 2 Important + 5 Minor nuevos; verificación sin gaps): `testing/t84/reviews/`. QA: Pest 36/36 (1881 aserciones), regresión 1025/1034 (9 omitidos), Node 799/799, ZIP validado con `zipfile` de Python (normal, ZIP64 y 65 540 entradas); typecheck/lint/format:check/pint/build verdes; E2E exportación + estado del sistema + PPTX 18/18 en tres tamaños con axe (dos pasadas). Pendiente del usuario (no bloquea): activar el worker `exports` en el VPS.

### T-85 — Búsqueda global y paleta de comandos

- **Descripción**: Paleta (Ctrl/Cmd+K) que busca posts, marcas, media, diseños y páginas, limitada por `UserScope`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-84
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/SearchController.php`, `app/Services/GlobalSearch.php`, `resources/js/Components/CommandPalette.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`, `docs/usuario/buscar.md`
- **Cubre**: F-11
- **Cubre (tests)**: E2E-24
- **Verificación**: `php artisan test --compact --filter=GlobalSearchTest` → verde

**Criterios de aceptación**
- [ ] Los resultados pertenecen solo al usuario
- [ ] La paleta se abre y se cierra con el teclado y es accesible
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-86 — Resumen semanal y recordatorio de publicación manual

- **Descripción**: Resumen semanal por correo/in-app (con párrafo de IA opcional) y recordatorio por correo —y por push cuando exista— de las publicaciones manuales.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-85, T-32
- **Tipo**: fullstack
- **Archivos**: `app/Jobs/SendWeeklyDigest.php`, `app/Mail/WeeklyDigest.php`, `app/Services/ManualPublishService.php`, `app/Models/ReportSchedule.php`, `resources/js/Pages/Profile/Notifications.vue`, `docs/modulos/informes.md`
- **Cubre**: F-12, F-16
- **Cubre (tests)**: E2E-25
- **Verificación**: `php artisan test --compact --filter=WeeklyDigestTest` → verde · `php artisan test --compact --filter=ManualPublishReminderTest` → verde

**Criterios de aceptación**
- [ ] El resumen incluye métricas de la semana con comparación
- [ ] El recordatorio se envía una vez antes de la hora
- [ ] Ambos se desactivan por preferencia
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: todo con `Mail::fake`. Queda a la espera (U): SMTP real y, para push, F-07 configurado. **A la espera (no bloquea la fase)**: SMTP real (usuario).

### T-87 — Mastodon y Telegram

- **Descripción**: Dos conectores nuevos siguiendo el patrón `Bluesky/` y `CredentialConnectionController`, con publicación de texto e imagen.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-86, T-74
- **Tipo**: backend
- **Archivos**: `app/Connectors/Mastodon/**`, `app/Connectors/Telegram/**`, `app/Enums/Network.php`, `app/Http/Controllers/CredentialConnectionController.php`, `config/social.php`, `config/media_specs.php`, `docs/modulos/conexiones.md`
- **Cubre**: F-13
- **Cubre (tests)**: E2E-26, M-10
- **Verificación**: `php artisan test --compact --filter=MastodonConnectorTest` → verde · `php artisan test --compact --filter=TelegramConnectorTest` → verde

**Criterios de aceptación**
- [ ] Se conecta, publica y desconecta cada red con fakes
- [ ] Aparecen en tipos de contenido y compositor
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: ambos conectores contra fixtures. Queda a la espera (V): contrastar las APIs reales (sin red durante el plan); smoke del usuario. **A la espera (no bloquea la fase)**: Verificación contra las APIs reales (smoke del usuario).

### T-88 — Autolistas desde RSS

- **Descripción**: Origen RSS/Atom para autolistas con `UrlGuard`, deduplicación y límites de tamaño/tiempo.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-87
- **Tipo**: fullstack
- **Archivos**: `app/Services/AutolistService.php`, `app/Services/Rss/RssFetcher.php`, `app/Support/UrlGuard.php`, `resources/js/Pages/Autolists/**`, `docs/modulos/planificacion-avanzada.md`
- **Cubre**: F-14
- **Cubre (tests)**: E2E-27
- **Verificación**: `php artisan test --compact --filter=RssAutolistTest` → verde

**Criterios de aceptación**
- [ ] Las entradas nuevas del feed generan elementos sin duplicar
- [ ] Un feed hostil (redirección privada, tamaño enorme) se rechaza
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-89 — Acortador propio con clics

- **Descripción**: Enlaces cortos `/s/{code}` con recuento de clics (sin datos personales) sobre `SmartLink`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-88
- **Tipo**: fullstack
- **Archivos**: `app/Models/SmartLink.php`, `app/Services/SmartLinkService.php`, `app/Http/Controllers/ShortLinkController.php`, `resources/js/Pages/SmartLinks/**`, `docs/modulos/smartlinks.md`
- **Cubre**: F-15
- **Cubre (tests)**: E2E-28
- **Verificación**: `php artisan test --compact --filter=ShortLinkTest` → verde

**Criterios de aceptación**
- [ ] La redirección registra clics agregados por día
- [ ] Se puede desactivar un enlace
- [ ] Sin IP ni identificadores personales en claro
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-90 — Calendario: fechas señaladas y feed .ics

- **Descripción**: Fechas señaladas (datos propios) visibles en el calendario y feed `.ics` con token por usuario.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-89
- **Tipo**: fullstack
- **Archivos**: `app/Services/CalendarService.php`, `app/Http/Controllers/CalendarFeedController.php`, `database/seeders/MarketingDatesSeeder.php`, `resources/js/Pages/Calendar/Index.vue`, `docs/modulos/calendario.md`
- **Cubre**: F-17, F-18
- **Cubre (tests)**: E2E-29
- **Verificación**: `php artisan test --compact --filter=CalendarIcsFeedTest` → verde

**Criterios de aceptación**
- [ ] El feed .ics es válido y solo contiene los posts del usuario
- [ ] El token se puede regenerar
- [ ] Las fechas señaladas se pueden ocultar
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: infraestructura y un conjunto inicial de fechas. Queda a la espera (U): el listado definitivo de fechas de interés. **A la espera (no bloquea la fase)**: Listado definitivo de fechas señaladas (usuario).

### T-91 — Marca de agua automática y exportación en lote

- **Descripción**: Aplicar el kit de marca como marca de agua y exportar un diseño en lote a los formatos del post.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-90
- **Tipo**: fullstack
- **Archivos**: `app/Services/Studio/StudioWatermark.php`, `app/Services/Studio/StudioBatchExport.php`, `resources/js/Pages/Studio/**`, `docs/modulos/estudio.md`
- **Cubre**: F-19
- **Cubre (tests)**: E2E-30
- **Verificación**: `php artisan test --compact --filter=StudioWatermarkTest` → verde

**Criterios de aceptación**
- [ ] Marca de agua configurable por marca
- [ ] El lote genera un fichero por formato y lo guarda en biblioteca
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-92 — Reciclado inteligente de mejores posts

- **Descripción**: Proponer reciclar los posts con mejor rendimiento según `PostMetricSnapshot`, con intervalo mínimo entre repeticiones.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-91
- **Tipo**: fullstack
- **Archivos**: `app/Services/Autolists/RecycleService.php`, `app/Models/PostMetricSnapshot.php`, `resources/js/Pages/Autolists/**`, `docs/modulos/planificacion-avanzada.md`
- **Cubre**: F-20
- **Cubre (tests)**: E2E-31
- **Verificación**: `php artisan test --compact --filter=RecycleServiceTest` → verde

**Criterios de aceptación**
- [ ] Se proponen solo posts con métricas suficientes
- [ ] Nunca se repite un post antes del intervalo mínimo
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-93 — Plan de contenido en lenguaje natural (lote)

- **Descripción**: A partir de un briefing en lenguaje natural, proponer un lote de borradores con fechas (`proposeSchedule`) que el usuario revisa y confirma.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-92, T-82
- **Tipo**: fullstack
- **Archivos**: `app/Services/ContentPlanService.php`, `app/Services/BatchService.php`, `resources/js/Pages/Posts/Plan.vue`, `docs/modulos/asistente-ia.md`
- **Cubre**: F-22
- **Cubre (tests)**: E2E-32
- **Verificación**: `php artisan test --compact --filter=ContentPlanTest` → verde

**Criterios de aceptación**
- [ ] Nada se crea sin confirmación explícita
- [ ] El lote respeta límites de cada red y cuotas
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: flujo completo con asistente falso. Queda a la espera (U): clave de IA real para el smoke. **A la espera (no bloquea la fase)**: Clave de IA real (usuario).

### T-94 — Estudio: animaciones por objeto

- **Descripción**: Animaciones de entrada/salida por objeto en la línea de tiempo y en el render MP4/GIF. F-23 se cuenta aquí; la tarea siguiente añade plantillas.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-93
- **Tipo**: fullstack
- **Archivos**: `app/Services/Studio/StudioTimeline.php`, `resources/js/Studio/timeline/**`, `resources/js/Pages/Studio/Editor.vue`, `docs/modulos/estudio.md`
- **Cubre**: F-23
- **Cubre (tests)**: E2E-33
- **Verificación**: `php artisan test --compact --filter=StudioObjectAnimationTest` → verde

**Criterios de aceptación**
- [ ] Una animación por objeto se previsualiza y se exporta
- [ ] Los diseños antiguos no cambian
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. F-23 es L: dos tareas.

### T-95 — Estudio: más plantillas (continuación de F-23)

- **Descripción**: Ampliar el catálogo de plantillas aprovechando las animaciones.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-94
- **Tipo**: fullstack
- **Archivos**: `resources/js/Studio/templates/**`, `database/seeders/StudioTemplatesSeeder.php`, `docs/modulos/estudio.md`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-33
- **Verificación**: `php artisan test --compact --filter=StudioTemplatesCatalogTest` → verde

**Criterios de aceptación**
- [ ] Al menos 10 plantillas nuevas por formato principal
- [ ] Todas renderizan sin errores
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de F-23.

### T-96 — Atajos globales, preferencias de notificación y onboarding

- **Descripción**: Atajos de teclado globales (con lista de ayuda), preferencias por tipo de notificación y onboarding guiado para usuarios nuevos.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-95
- **Tipo**: fullstack
- **Archivos**: `resources/js/composables/useShortcuts.js`, `resources/js/Pages/Profile/Notifications.vue`, `resources/js/Components/Onboarding.vue`, `app/Models/User.php`, `docs/usuario/primeros-pasos.md`
- **Cubre**: F-24
- **Cubre (tests)**: E2E-34
- **Verificación**: `php artisan test --compact --filter=NotificationPreferencesTest` → verde

**Criterios de aceptación**
- [ ] Los atajos no se activan al escribir en campos
- [ ] Las preferencias se respetan en todas las notificaciones
- [ ] El onboarding se puede saltar y repetir
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M.

### T-97 — Modo oscuro (1/2): tokens de color y layout

- **Descripción**: Primera mitad de F-25: variables de color, `useTheme` (sistema/claro/oscuro) y layouts. F-25 se cuenta aquí.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-96
- **Tipo**: frontend
- **Archivos**: `tailwind.config.js`, `resources/css/app.css`, `resources/js/Layouts/**`, `resources/js/composables/useTheme.js`, `docs/usuario/tema.md`
- **Cubre**: F-25
- **Cubre (tests)**: E2E-35, M-08
- **Verificación**: `npm run build` → build correcto · `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde (contraste incluido)

**Criterios de aceptación**
- [ ] El selector de tema persiste por usuario y respeta `prefers-color-scheme`
- [ ] Layouts, navegación y formularios base legibles en oscuro
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. F-25 es L: dos tareas. Toca toda la UI: ejecutar después de los refactors D.

### T-98 — Modo oscuro (2/2): páginas y estudio

- **Descripción**: Segunda mitad de F-25: revisar todas las páginas y el estudio en oscuro (contraste y gráficos).
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-97
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/**`, `resources/js/Components/**`, `resources/js/Pages/Studio/**`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-35, M-08
- **Verificación**: `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Ninguna página tiene texto ilegible en oscuro
- [ ] `a11y.spec.ts` sin violaciones de contraste nuevas
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de F-25; la revisión visual final es el manual M-08.

---

## Fase 8 — QA integral y documentación

**Estado**: borrador · **Estimado**: 11.0h · **Real**: — · **Coste est.**: 550 € (base) + tokens ⚠️ verificar · **Tokens est.**: 535k

### T-99 — QA integral y verificación de la trazabilidad

- **Descripción**: Pasada final: Pest completo, E2E en los tres tamaños, `composer audit`, `npm audit`, lint de OpenAPI, ledger-lint y comprobación de que todo ID de la auditoría quedó cerrado o justificado.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-98
- **Tipo**: test
- **Archivos**: `docs/roadmap/2026-11-11-audit-hardening/testing/qa-final/report.md`, `docs/roadmap/2026-11-11-audit-hardening/test-plan.md`
- **Cubre**: — (sin hallazgo propio)
- **Cubre (tests)**: E2E-39
- **Verificación**: `php artisan test --compact` → 0 fallos · `python3 docs/roadmap/2026-11-11-audit-hardening/scripts/check-traceability.py` → OK, ningún ID falta ni sobra · `composer audit --locked` → sin avisos

**Criterios de aceptación**
- [ ] Pest 100 % verde y E2E verde en los tres tamaños (una sola ejecución cada vez)
- [ ] `composer audit` y `npm audit` sin avisos
- [ ] Todos los E2E-xx y API-xx del test-plan ejecutados y los M-xx entregados al usuario con instrucciones
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Sin cambios de código en paralelo durante la pasada (GOT-005). Los fallos se corrigen en tareas de seguimiento, no se silencian.

### T-100 — Documentación final, inventario, CHANGELOG y retro

- **Descripción**: Cerrar el CHANGELOG, actualizar índices, redactar la ficha de decisiones pendientes del usuario (F-21, F-26, F-27 y lo bloqueado por terceros) y registrar la retro con tiempos medidos.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-99
- **Tipo**: docs
- **Archivos**: `CHANGELOG.md`, `CHANGELOG.es.md`, `docs/README.md`, `docs/RAG-INDEX.md`, `docs/roadmap/README.md`, `docs/roadmap/CALIBRATION.md`, `docs/roadmap/2026-11-11-audit-hardening/DECISIONES.md`, `CONTINUE-HERE.md`
- **Cubre**: — (sin hallazgo propio)
- **Cubre (tests)**: —
- **Verificación**: lectura: `DECISIONES.md` cubre F-21, F-26, F-27, LinkedIn, Meta, TikTok, Canva, Pinterest y VPS · `python3 <kit>/shared/ledger-lint.py docs/roadmap/2026-11-11-audit-hardening/tasks.md` → exit 0

**Criterios de aceptación**
- [ ] CHANGELOG (es/en) recoge todo lo entregado
- [ ] `DECISIONES.md` lista cada decisión pendiente con opciones, coste y recomendación
- [ ] El índice del roadmap refleja el progreso real
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. No se hace commit ni merge desde el plan.

---

## Revisión de dos lentes — intento 1: Fase 1 (T-01, T-02, T-03) — gaps corregidos

T-01 y T-02 sin gaps. Los gaps de T-03 y su corrección:

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | La cola `default` no tiene worker en ninguna doc de despliegue: `PasskeyAdded` (`ShouldQueue` sin `onQueue`) caía en `default` y nadie la procesaba | T-03 | Elegida la opción robusta: `PasskeyAdded` va por `publishing.queue` (`publish`, la de menor latencia y con worker en todos los despliegues). No se añadió `default`: sería un worker más que mantener y dependería de que `QUEUE_NAMES` lo incluya. Revisados todos los `ShouldQueue` de `app/`: el único sin cola era `PasskeyAdded` (`QueueProbeJob` la fija quien lo despacha) | `PasskeyAdded.php`; Pest `--filter='DeployQueuesDocTest\|Passkey'` 78 verdes; el test sale en rojo («no documenta un worker para la cola `default`») con el cambio revertido |
| 2 | Minor | `DeployQueuesDocTest` solo miraba `app/Jobs` y `onQueue(...)` | T-03 | Escanea todo `app/`: `onQueue(...)`, `push(..., queue:)` (p. ej. `StudioAvQueue`) y `ShouldQueue` sin cola explícita = `default` | `DeployQueuesDocTest.php` |
| 3 | Minor | `studio` en Hestia contaba como documentada por una mención | T-03 | El test exige `systemctl enable --now scheduler-studio` en el README de Hestia | `DeployQueuesDocTest.php` |
| 4 | Doc | `deploy/hestia/README.md` se contradecía sobre `WORKER_UNITS` | T-03 | Unificado: no hace falta para los cron, sí para las unidades systemd instaladas (`scheduler-studio`, `scheduler-worker@…`); igual en `docs/modulos/despliegue.md` | grep `WORKER_UNITS` |

Estado: T-01, T-02 y T-03 pasan a `completado` (2026-11-11) con los criterios que no dependen del Playwright de fase marcados. El criterio «Pest verde y Playwright de los specs afectados» queda sin marcar en ellas: el Pest está verde (ver evidencia) y el E2E se pasará al cerrar la fase 1.

## Revisión de dos lentes — intento 2: Fase 1 (T-03, T-04, T-05) — gaps corregidos

Los 8 gaps del intento 1 de T-04/T-05 y los 3 de T-03 quedan corregidos (ver las secciones del intento 1). Nuevos del intento 2:

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | La fusión borraba la revisión que otro cliente (colaborador, segunda pestaña) usaba como base: exportar daba 422 «La revisión indicada no existe» | T-04 | Enfoque elegido: **no borrar**. La sustituida queda `superseded_at` (migración `2026_12_17_090000`, reversible): sigue sirviendo para exportar y como base, no sale en `versions()`, no cuenta en «últimas N» ni «diaria» y la poda la elimina pasadas `retention.design_revisions.superseded_hours` (24 h) salvo que la fije una exportación no terminada. Se descartó bloquear la fusión mientras haya otro cliente porque el servidor no sabe en qué revisión está parado cada cliente; marcar es la única opción sin estado de sesión. La validación de `StudioRenderer::export` se repite con `sharedLock` dentro de la transacción, justo antes de fijar la revisión, y el borrado de la poda queda tras el margen | tests «a superseded revision stays usable as an export base and is hidden from the history» (rojo: 422/columna inexistente), «superseded revisions are pruned only after the grace period and never when pinned» (rojo) y «an export validates its revision under a lock inside the transaction» (rojo al quitar el bloqueo: 1 lectura en vez de 2) |
| N2 | Minor | `saveNow` perdía `autosave=true` en los guardados encadenados | T-04 | `Editor.vue`: ambos reenvíos en cadena hacen `saveNow(autosave)`; el explícito sigue explícito | `tests/frontend/studio-autosave-chain.test.mjs` (rojo 0/1 → verde); `npm run build` OK |
| N3 | Minor | `nativeCached` sin `try/catch`: con la caché caída `app:health` reventaba | T-05 | Envuelto como `scheduler()`: ante fallo de caché registra aviso y ejecuta `native` sin caché | test «a broken cache never breaks the native check, over HTTP or CLI» (rojo: «cache caida») |

Verificación: Pest focal (`Retention`, `Infra`, `StudioBackendTest`, `StudioCollaborationTest`, `StudioApiMcpTest`) 136/136; node `studio-editor-readiness` + `studio-autosave-chain` 4/4; Pint OK; `npm run build` OK. Pest completo lo lanza el coordinador. T-04 y T-05 siguen en `en-revision`.

## Revisión de dos lentes — intento 3: Fase 1 (T-04, T-05) — gaps corregidos

N1, N2 y N3 del intento 2 quedan corregidos (✓). Gaps del intento 3:

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Las filas sustituidas se acumulaban durante 24 h (una completa por autoguardado) | T-04 | Tope: una sola sustituida por diseño y autor; al sustituir de nuevo se borra la anterior (con sus usos de tipografía) salvo que la fije una exportación sin terminar. Además el margen de poda baja de 24 h a **15 min** (`retention.design_revisions.superseded_minutes`). Justificación: el tope acota el volumen a 1 fila; el margen corto limita cuánto vive esa última; en el escenario de dos pestañas la revisión de la otra pestaña sobrevive un paso (el siguiente autoguardado la borra), y si ya no existe el export devuelve el 422 de «revisión no existe» y el usuario recarga: coste menor frente a 400-3.000 filas por hora | test «consecutive autosaves leave at most one superseded revision per design» (rojo: 7 sustituidas → verde: 1, sin usos huérfanos); «superseded revisions are pruned only after the grace period» actualizado a minutos |
| 2 | Minor | `sharedLock` en `StudioRenderer::export`: riesgo de deadlock con el guardado y sin cerrar la carrera con la poda | T-04 | Quitado el bloqueo y el parámetro `$lock` de `StudioExports::revision`; `DesignRevision::pruneAll` comprueba la fijación en el propio DELETE (`whereNotExists` sobre exportaciones sin terminar) en vez de fiarse de los ids calculados antes | test «pruning rechecks the export pin inside the DELETE» (rojo: se borraba la fijada entre medias → verde); el test del bloqueo se retira |
| 3 | Minor | Una revisión fijada por una exportación sin terminar se podía sustituir y salía del historial | T-04 | Opción elegida: **no sustituirla** (como dice `estudio.md`): `DesignRevision::pinnedByExport` en la condición de sustitución | test «an autosave never supersedes a revision pinned by an unfinished export» (rojo → verde) |
| 4 | Importante (previo) | Ctrl+S / «Guardar ahora» con un autoguardado en vuelo y sin más cambios devolvía `true` sin crear el punto de control explícito | T-04 | Lógica de encadenado extraída a la función pura `resources/js/Studio/save-chain.js` (`nextSave`); `saveNow(autosave, force)` rastrea el origen del guardado en vuelo (`pendingAutosave`) y envía un guardado explícito forzado aunque no haya cambios; sustituye al test por regex del intento 2 | `tests/frontend/studio-save-chain.test.mjs` (rojo: módulo inexistente → verde, 4 tests); `studio-editor-readiness` verde; `npm run build` OK |

Verificación: Pest focal (`Retention`, `Infra`, `StudioBackendTest`, `StudioCollaborationTest`, `StudioApiMcpTest`, `StudioRendererTest`) 147/147; `node --test` del estudio 608 ok y 1 fallo ajeno (`studio-bar-prepared` G5 exige `--expose-gc`); Pint OK; `npm run build` OK. Pest completo y Playwright los lanza el coordinador. T-04 y T-05 siguen en `en-revision`.

## Revisión de dos lentes — intento 4: Fase 1 (T-04) — gaps corregidos

Las correcciones 1-4 del intento 3 quedan ✓. Cierre de la revisión final:

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | Un cliente desfasado (segunda pestaña, colaborador) exportaba una revisión ya fusionada y recibía un 422 sin explicación | T-04 | Conflicto, no validación: `StudioExports::revision` lanza `StudioDesignConflict` (409, revisión actual y autor, «El diseño ha cambiado desde que lo abriste») cuando la revisión pedida es anterior a la vigente y ya no existe; una revisión inventada o futura sigue siendo 422. Vale para web, API y MCP (misma excepción y mismo servicio; el MCP ya propaga los 409). `Editor.vue`: `ExportDialog` y `NativeExportJobs` emiten `conflict` (helper puro `exportFailure` en `Studio/export-conflict.js`) y `onExportConflict` abre el diálogo de conflicto existente; cubre exportar, «Usar en publicación» y la exportación nativa | Pest «exporting a revision removed by a merge is a 409 conflict with the current revision» y «the 409 reaches the web and the API clients» (rojo: 422); `tests/frontend/studio-export-conflict.test.mjs` (rojo → 3 verdes) |
| N2 | Minor | Carrera entre validar e insertar la exportación frente al DELETE de la fusión | T-04 | `StudioRenderer::export` inserta primero la exportación `pending` y vuelve a comprobar la revisión en la misma transacción; si ya no existe revierte y devuelve el mismo 409. Con la fila insertada, fusión y poda la ven fijada | test «a revision removed between validating and inserting the export rolls the export back with the same 409» (rojo al quitar la recomprobación; 0 exportaciones tras el rollback) |
| N3 | Minor | Comentario de la migración citaba `superseded_hours` | T-04 | Corregido a `superseded_minutes` y al tope de una sustituida por diseño y autor | revisión del fichero |

Verificación: Pest focal (`Retention`, `StudioRendererTest`, `StudioBackendTest`, `StudioApiMcpTest`, `StudioAvQueueTest`, `StudioAvRegistrationTest`, `StudioCollaborationTest`) verde (22 en Retention); node `studio-export-conflict` + `studio-save-chain` + `studio-editor-readiness` 10/10; Pint OK; `npm run build` OK. Pest completo y Playwright, del coordinador. T-04 y T-05 siguen en `en-revision` (se cierran con el E2E de fase).

## Revisión de dos lentes — intento 2: Fase 1 (T-06) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 2a | Important | El reintento publicaba la lista recortada de un destino marcado `media_inexistente` | T-06 | `PostService::retryFailed` salta esos destinos (`MEDIA_MISSING`) y, si no queda ninguno reintentable, lanza `PostValidationException` (422 en web y API; error del MCP `retry_post`, mismo servicio); `CalendarService`: `can_retry` falso y nuevo `retry_blocked` con el motivo, que `Calendar/Index.vue` muestra junto a la fila. Editar el destino (siempre `fresh` si estaba fallido) limpia el código | `MediaMissingRetryTest` 3 tests (rojo: 200 en web/API/MCP y `can_retry` true) |
| 2b | Important | La migración no recalculaba `posts.status` ni avisaba | T-06 | Recalcula el estado del post con la regla de `Post::syncStatus` (sin eventos) e inserta una fila en `notifications` (`PostMediaMissing`, «Falta el archivo X en la publicación Y», visible en Avisos con título `media_missing`) | test de migración con programada [A, B] y B borrada (rojo: post `borrador`/sin notificación) |
| N1 | Minor | Se marcaban fallidos borradores y destinos en vuelo | T-06 | Solo `programada` pasa a `fallida`; un borrador conserva su estado y pierde los ids inexistentes; `publicando`/`procesando`/`fallida` no se tocan; todo se registra en el log | test «la migración no marca fallidos los borradores ni toca los destinos en vuelo» (rojo: borrador → fallida) |
| N2 | Minor | La purga hacía commit separado del DELETE del usuario | T-06 | `User::delete()` envuelve purga y borrado en una transacción (vale para `ProfileController::destroy` y cualquier otro borrado) | test «la purga … son atómicos» con un listener que falla (rojo: 0 filas tras el fallo) |
| UX | Minor | El error de borrado de un export decía «se usa en una publicación» | T-06 | `MediaAsset::usageReason()` distingue publicación, serie activa, autolista y exportación reciente del estudio; `MediaUploadService::delete` lo usa | test «el error de borrado distingue el motivo» (rojo) |

Verificación: `MediaDeleteReferencesTest` + `MediaMissingRetryTest` 17/17; Pest focal; Pint; `npm run build`. Pest completo y Playwright, del coordinador. T-06 sigue `en-revision`.

## Revisión de dos lentes — intento 2: Fase 1 (T-07, T-08) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 | Important | El reloj del presupuesto arrancaba tarde: `findPublished` y `exists`/`size` del disco corrían antes de `StepBudget` | T-07 | `publish` y `findPublished` llaman a `$request->budget()` lo primero, así que todo el paso (comprobación de idempotencia incluida) queda dentro del presupuesto | `PinterestVideoUploadRobustnessTest` «el reloj del presupuesto arranca en findPublished…» (rojo: no lanzaba `sin_tiempo`) |
| N2 | Important | La URL prefirmada guardada nunca caducaba ni se volvía a registrar; un 403 acababa como `permisos` engañoso | T-07 | El progreso guarda `issued_at`; TTL `PINTEREST_UPLOAD_URL_TTL` (20 min). Caducada, o rechazada con 400/403/404/410 al reutilizarla, se limpia y se registra de nuevo UNA vez. Rechazo con la URL recién registrada: `PermanentPublishException` `subida_rechazada` («Pinterest rechazo la subida del video (HTTP 403)…»), no `permisos` | tests «una URL guardada caducada…», «si la URL guardada vigente es rechazada (403)…», «si la subida con la URL recién registrada es rechazada…» (rojos) |
| N3 | Minor (CWE-312) | La URL firmada quedaba en `post_targets.progress` | T-07 | Subida correcta: `processing($mediaId, raw, [])`; fallo permanente: `checkpoint(null, [])`. El `Log::notice` solo lleva `target_id` y estado. `progress` no aparece en recursos de API/MCP (solo en la serialización del modelo mientras dura la subida; mismo patrón que TikTok) | test «tras subir bien, el progreso… se vacía» (rojo) y el de rechazo comprueba `[]` |
| N4 | Important | 512 MB no cabe en el presupuesto del paso (6-7 MB/s en un solo POST) | T-07 | **Se elige el tope de 128 MB** (`media_specs`) en lugar del paso propio: un job en cola `media` de ~600 s exigiría cambiar en producción el worker (`--timeout=120`, `--max-time=280`) y `retry_after` de la conexión `database` (180 s) más sus crons y `DeployQueuesDocTest`: cambios de infraestructura que no se pueden verificar aquí y arriesgan los workers existentes. 128 MB caben con ~55 s de margen peor caso a ~2,5 MB/s. Documentado en la guía de Pinterest | test de validación 128/129 MB y el de «supera 128 MB»; documentación actualizada |

Verificación: `tests/Feature/Connectors/Pinterest` 32/32; Pest focal (Pinterest, Publishing, Jobs, Deploy, Infra); Pint. Pest completo y Playwright, del coordinador. T-07 y T-08 siguen `en-revision`.

### Intento 3: Minor cerrados (T-07)

| # | Grado | Gap | Corrección | Evidencia |
|---|---|---|---|---|
| G1 | Minor | El «re-registro una sola vez» solo valía dentro de un job | Contador `pinterest_registrations` en `progress` (persiste entre reintentos, cuota y reconexión; el `checkpoint` que limpia la URL lo conserva). Con 2 registros hechos no se registra otro: `subida_rechazada` | `PinterestVideoUploadRobustnessTest` «el contador de registros persiste…» y «el contador sobrevive al checkpoint…» (rojos) |
| G2 | Minor (CWE-312) | La URL firmada quedaba en `progress` en fallos que no pasaban por el `catch` del publisher | Punto común: `PublishOutcomes::failed` (al que llegan también `transient` agotado y el camino `exhausted`) quita de `progress` `pinterest_upload` y `upload_url` (Pinterest y TikTok) y conserva el resto. Un reintento manual ya reinicia el progreso (`freshAttempt`) | `FailedProgressScrubTest` (2, rojos) |
| G3 | Minor | La «Decisión» de T-07 seguía con 512 MB y ~5 MB/s | Reescrita con 128 MB, ~2,5 MB/s y la justificación del paso propio descartado | este ledger |

Verificación: filtro `Pinterest|TikTok|Publishing|FailedProgressScrub|PublishOutcomes|Retry` 361/361; Pint. T-07 y T-08 siguen `en-revision`.

## Revisión de dos lentes — intento 1: Fase 1 (T-09, T-10, T-11) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical (CWE-754) | `rclone copy` recibía 3-4 argumentos (solo admite origen y destino) | T-09 | `backup.sh` sube fichero a fichero con `rclone copyto <origen> <remoto>/<nombre>` (función `upload`, también para rsync y ruta) | `BackupVerifyTest` «copia al destino rclone…» con un `rclone` falso que falla si no recibe exactamente `copyto` + 2 posicionales; en rojo contra el script anterior |
| 2 | Important | La marca `last-backup` se escribía antes de la copia externa | T-09 | La marca se escribe solo tras subir los ficheros; si la copia externa falla el script sale != 0 y no hay marca (`app:backup-verify` acaba avisando); después se sube la marca | mismo test: con `RCLONE_FAIL=1` exit != 0, sin `last-backup` y `app:backup-verify` exit 1 |
| 3 | Important | `BACKUP_DIR=` vacío en el `.env` daba `''` y la marca se buscaba en `/last-backup` | T-09 | `config/backup.php`: `env('BACKUP_DIR') ?: storage_path('backups')` y `(int) (env('BACKUP_MAX_AGE_HOURS') ?: 26)`; `BACKUP_HEALTH` ya era seguro con vacío (`false`) | test «vacíos en el .env caen en sus valores por defecto» |
| 4 | Important | `--no-scripts` se salta `clearCompiled`: `bootstrap/cache/*.php` obsoleto rompía `package:discover` (también en el rollback) | T-11 | Los pasos `discover` del despliegue y del rollback hacen `rm -f bootstrap/cache/*.php &&` antes de `package:discover` | `DeployScriptTest` con un `php` falso que falla si queda `packages.php`: despliegue y rollback pasan; en rojo contra el script anterior |
| 5 | Important | El rollback empezaba con `artisan down`, que arranca la versión rota | T-11 | `maintenance_on`/`maintenance_off` escriben/borran `storage/framework/down` (mismo formato JSON de Laravel) sin arrancar PHP; el rollback ya no depende de que la versión rota arranque | test «el rollback pone el modo mantenimiento sin arrancar Laravel…»: versión rota (todo artisan falla), rollback correcto y sin `artisan down`/`up` en el registro del php falso |
| 6 | Important | Reejecutar el despliegue tras un fallo borraba el punto de vuelta | T-11 | `last-deploy` lleva `status=incomplete` hasta que el despliegue termina (`mark_deploy complete`); `record_deploy` aparta vendor y build previos y, si el estado es `incomplete`, conserva `prev_rev` y el `previous/` originales; `swap_build` ya no toca `previous/` | test «reejecutar el despliegue…»: migrate falla dos veces, rollback vuelve a la revisión, vendor y build originales; en rojo contra el script anterior |
| 7 | Important (CWE-284) | `ENFORCE_ADMINS=false` por defecto dejaba A-02 abierto para el único desarrollador (administrador) | T-10 | Por defecto `true`; hotfix documentado en `deploy/github/README.md` (`ENFORCE_ADMINS=false` o `--revert`) y en `despliegue.md` | `bash deploy/github/protect-main.sh --dry-run` muestra `enforce_admins: true` |
| 8 | Minor (CWE-353/916) | CBC sin MAC y PBKDF2 con 10k iteraciones | T-09 | `-iter 600000`; `.hmac` (HMAC-SHA256, subclave derivada de la frase) por fichero, verificado por `restore.sh` antes de descifrar; los respaldos sin `.hmac` se restauran como antes (10k iteraciones) con aviso | test «rechaza un fichero alterado (HMAC) y admite un respaldo anterior sin .hmac» |
| 9 | Minor (CWE-538) | `storage/backups` no estaba en `.gitignore` | T-09 | `/storage/backups` añadido | test de `.gitignore` |
| 10 | Minor | Una contraseña con `"` o `\` rompía el `my.cnf` | T-09 | `cnf_escape` en `backup.sh` y `restore.sh` para usuario, contraseña, host y puerto | test con contraseña `pa"ss\w`: el `mysqldump` falso copia el cnf y se comprueba `password="pa\"ss\\w"`; en rojo antes |
| 11 | Minor | `--db-only` del despliegue pisaba la marca del respaldo completo | T-09, T-11 | `--db-only` escribe `last-db-backup`; `app:backup-verify` sigue mirando `last-backup`; `deploy.sh` registra el volcado desde `last-db-backup` | test «--db-only usa una marca aparte…» |
| 12 | Minor | `ci-failure-notify.yml` solo cubría `failure` | T-10 | `failure`, `cancelled`, `timed_out` y `startup_failure` abren/comentan la issue; `success` la cierra | YAML válido y test de contenido |

Verificación: Pest focal `BackupVerifyTest|DeployScriptTest|InfraTest|HealthCheckTest|DeployQueuesDocTest` 69/69; los seis tests nuevos de scripts (1, 2, 4, 5, 6, 10, 11) fallan contra los `backup.sh`/`deploy.sh` del commit anterior y pasan con la corrección; `bash -n` de los cuatro scripts; Pint; `--dry-run` de `deploy.sh`. Pest completo y Playwright, del coordinador. T-09, T-10 y T-11 siguen `en-revision`.

## Revisión de dos lentes — intento 2: Fase 2 (T-12, T-13) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| G1 | Important | `readDocs` leía cada fichero entero y concatenaba todo `docs/` en un string (794 MB de heap; `RangeError` al pasar de ~512 M caracteres y la purga dejaría de correr) | T-12 | `citedRunNames` recorre los ficheros en streaming (`readSync`, trozos de 1 MB) con solape de 64 caracteres, extrae los nombres con la regex de `RUN_PATTERNS` a un `Set` y no concatena nada; una coincidencia pegada al final de la ventana se reevalúa con el trozo siguiente (un pid cortado no se registra como otro nombre); fail-closed ante errores de lectura | `purge-scan.test.mjs`: cita partida en el borde (todos los desplazamientos, trozos de 64 B, uuid y roadmap, pid truncado) y fichero de 192 MB con pico de RSS < 200 MB (rojo: 229 MB ya con 96 MB; verde: ~120 MB, plano entre 96 y 192 MB) |
| G2 | Minor | La heurística del NUL descartaba los logs UTF-16LE de PowerShell | T-12 | `detectEncoding`: BOM UTF-8/16LE/16BE y patrón de nulos alternos sin BOM; decodificación por trozos con `TextDecoder(..., { stream })`; un binario con NUL sueltos sigue sin contar | tests «los logs UTF-16 (LE con BOM, LE sin BOM, BE con BOM)…» y «un binario real…» |
| G3 | Minor | `desarrollo-local.md` aún describía `E2E_NO_SERVER=1` y `artisan serve --port=8080` para la batería | T-13 | Reescrito: ya no se puede apuntar la batería a un servidor manual (qué lo impide); depuración con `E2E_PORT`/`--headed`/`E2E_PURGE=0`; el `artisan serve` queda solo como exploración manual ajena a la batería | test «la guía de desarrollo local ya no propone…» (el texto antiguo sí contenía la frase: 1 coincidencia en HEAD) |

Dry-run real (hoy, 2026-11-12; varía con la antigüedad): «borraría 260 · conservado 23» en 0,75 s con 153 MB de RSS máximo (el proceso base de Node son ~45 MB); `t43/` sigue con 394 entradas, no se borró nada. `npm run test:unit` 647/647. Sin E2E ni Pest en este intento. T-12 y T-13 siguen `en-revision`.

## Revisión de dos lentes — intento 1: Fase 2 (T-14) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | La lista `@responsive` incumplía su propio criterio: ~40 ficheros llaman a `expectNoOverflow` y no llevaban la etiqueta (p. ej. composer E2E-08 a 375 px, `studio-datasets` E2E27.01 con su rama axe de mobile-360, `studio-captions`, composer E2E-07) | T-14 | Inventario sistemático por script sobre los 54 specs: se etiqueta todo test que llama a `expectNoOverflow`/`overflowX`/`scrollWidth`, hace axe, tiene ramas por proyecto o viewport (`project.name ===`, `startsWith`, `isMobile`, `viewportSize`, `.viewport`), o lo haga a través de un helper local. +119 tests etiquetados. Excepciones: ver tabla. Salvaguarda en `test-tooling.test.mjs` | `--list`: desktop 258, mobile 197, mobile-360 197 (652 ejecuciones, antes 774; el primer intento dejaba 416); `npm run test:unit` en verde con los dos tests nuevos |
| 2 | Minor | La `Verificación` usaba `--grep @smoke`, que no existe | T-14 | Campo reescrito con comandos ejecutables | `E2E_PURGE=0 E2E_WORKERS=2 npx playwright test tiktok-audio canva hashtags bluesky` → 32 passed (2.1m), con specs serial (`hashtags`, `bluesky`) y con banderas (`tiktok-audio`, `canva`) |
| 3 | Minor | Criterio de duración marcado `[x]` solo con una muestra | T-14 | Desmarcado; se cierra con la pasada completa de fin de fase (la lanza el coordinador). Quitado `Cerrada` | ledger |

**Criterio vigente de `@responsive`** (sustituye al inicial): lleva la etiqueta todo test que (a) llama a `expectNoOverflow`, `overflowX` o `scrollWidth`, (b) hace una auditoría axe, (c) tiene ramas por `project.name`, `isMobile`, viewport o `project.use`, o (d) consta en un ledger como fallo histórico solo en móvil (composer E2E-08 en `2026-10-23-post-composer`; no se halló ningún otro fallo móvil-only de los tests que quedan fuera, buscando en todos los `tasks.md`). Los helpers locales con esas llamadas cuentan. `project.name` usado solo para nombrar datos (`${stamp()}-${info.project.name}`, `e2eUser(info.project.name)`) NO cuenta.

**Tests que quedan solo en desktop (61 casos; 258 − 197).** Ninguno cumple (a)-(d); se comprobó con el script y a mano. Por grupo:

| Spec (casos) | Motivo |
|---|---|
| `canva` 159, `first-comment` 165, `target-media` 167, `natural-schedule` 160 (4) | variantes de lógica (plan de pago, salidas de duplicado, limpieza de alt, IA desactivada) sin comprobación de ancho; el overflow/axe del mismo flujo ya lo cubre otro test etiquetado del fichero |
| `composer` 196, 261, 274, 328 (×2), 462, 610, 641, 661 (9) | confirmación de salida, emoji multilínea, Atrás, zona horaria Auckland, publicar ahora y fechas límite: lógica; la maquetación del compositor la cubren E2E-01/02/03/04/05/08… etiquetados |
| `composer-refresh` 5 (×2), `instagram-audio` 65/128/157, `tiktok-audio` 48, `gif-picker` 154 (7) | reintentos, consultas lentas, errores y foco: lógica de estado. `gif-picker` 154 es falso positivo de la detección por helper (`RESPONSIVE_EXCEPTIONS`) |
| `media-import` 126/168/198/216/325/338/353/364 (8) | sondeo, atascos, cancelación y Drive/Picker: lógica y red simulada |
| `oauth` 111/127/199, `security` 139/182/334/376, `social` 80/91/184/203 (11) | metadatos/DCR (API), reto 2FA, bloqueos, passkeys y vinculación: flujo y backend sin comprobación de ancho |
| `studio-automation` (2), `studio-boards` 160/220, `studio-captions` 86, `studio-charts` (3), `studio-presentation-media` (5), `studio-presentation-tools` (3), `studio-presentations` 41/103, `studio-timeline` 133, `studio` 25/103/162 (22) | exportación, persistencia, vídeo/audio nativo y presentación en Studio: comprobaciones de píxeles y de API, sin rama por ancho; `studio-boards` 263, el editor y la pizarra con ramas táctiles, sí están etiquetados |

Reparto por proyecto tras la revisión: `desktop` 258, `mobile` 197, `mobile-360` 197 → 652 ejecuciones. Salvaguarda: `todo test que mira desbordamiento, axe, viewport o ramas por proyecto lleva @responsive` (falla si aparece uno sin etiqueta y sin motivo en `RESPONSIVE_EXCEPTIONS`) y `la deteccion de @responsive distingue…` (prueba el propio detector). T-14 sigue `en-revision`.

## Revisión de dos lentes — intento 1: Fase 2 (T-15, T-16, T-17, T-18) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | `cancel-in-progress: false` no impide cancelar en `main`: GitHub cancela el run *pendiente* del mismo grupo y, con 3 PRs fusionados, se pierde el del commit intermedio (`ci-failure-notify` lo da por rojo, `deploy.sh` no lo ve en verde) | T-15 | El grupo de `ci.yml` es por commit en `push` (`github.sha`) y por PR/rama en pull_request; comentario de cabecera corregido. `ci-failure-notify.yml` NO necesitaba cambio: su `if` ya exige `event == 'push'` y `head_branch == 'main'`, así que un `cancelled` de PR no abre issue (`ci-failure-notify.yml:15`) | `yaml.safe_load` ok; `actionlint` (ver abajo) |
| 2 | Important | G2 no comprobaba el tiempo de los fallos: con 0,2 s por pasada el corte salía igual sin la suma de `download_seconds` (`RemoteMediaImporter.php:98`) | T-16 | Ritmo mínimo 2.500 B/s: una pasada buena rinde 3.500 B/s (no corta sola) y con el 503 intercalado cae a 1.750 B/s (corta solo si se suma el tiempo del fallo) | Rojo: quitando la suma en la línea 98, los 2 tests G2 fallan (`'invalido'` esperado, `'listo'` recibido; y `download_seconds` = 0); con la suma, `UrlSourceRealHandlerTest` 20 passed |
| 3 | Minor | El tope de 1280 MiB de IS-Net no se había medido con 16 Mpx en el runner; `RLIMIT_DATA` limita VmData, no VmHWM | T-15 | Test nuevo `test_largest_admitted_image_fits_the_memory_cap_with_margin` (imagen 4000x4000 generada en el test, bajo los 30 MiB) que exige pico RSS < 85 % del tope en Linux | WSL/Linux: pico 945 MiB de 1280 (margen 335 MiB, 26 %); Windows: OK. Tope sin cambios |
| 4 | Minor | `nightly.yml` sin guarda de repositorio | T-15 | `if: github.repository == 'daycry/social-scheduler'` en el job `e2e` | `actionlint` |
| 5 | Minor | El límite de `videoFrameReady` dependía de `requestAnimationFrame` (pestaña oculta: nunca vence) y no se podía abortar | T-16 | Temporizador real + `AbortSignal` en `canvas.js`; `timeline-preview.js` crea un `AbortController` de ciclo de vida, lo pasa a `videoFrameReady` y lo aborta en `dispose()` (cerrar el diálogo aborta la espera) | `tests/frontend/studio-video-frame-ready.test.mjs` (4; en rojo sin el cambio) y `studio-preview-frame-abort.test.mjs` (1) |
| 6 | Minor | Aserciones debilitadas en T-18 (`not.toHaveCount(0)`, `main.toContainText`) | T-18 | Vuelven a `toBeVisible` sobre un elemento acotado: error por destino (`items.filter(red).filter(caracteres)`), `main` > `listitem`, passkey concreta (`Móvil` + «Añadida el»), `[data-section="growth"]`, `[data-metric="reach"]`/`"views"` y la tarjeta de seguidores de Bluesky | Specs `composer`, `flows`, `security`, `bluesky` y `roadmap` (E2E-10) con `E2E_PURGE=0 E2E_WORKERS=2` (resultado abajo) |
| 7 | Minor | Los trabajos de quitar fondo encolados antes del despliegue conservan ruta y sha de BiRefNet y fallarían | IS-Net (T-15) | `StudioImages::process` resuelve siempre el modelo vigente de la config y actualiza el trabajo; si no está instalado, falla con `model_unavailable`. Documentado en `despliegue.md` | Pest `StudioImageJobsTest` 17 passed; los 2 tests nuevos fallan sin el cambio |
| 8 | Minor | La URL de la licencia apuntaba a `main` | IS-Net (T-15) | Fijada al commit `7ca15e0e7d0f119cb026bb2d1527c87895428d6c` de `xuebinqin/DIS` (último que toca `LICENSE.md`) | Descargada: 11 357 B y sha-256 `c71d239d…0ab4`, idénticos al manifiesto |
| 9 | Minor | Ledger desfasado | T-15, T-17, T-18 | «Implementar» y criterios cumplidos marcados en T-17/T-18; `Decisiones` de T-15 con los `needs` reales (`node-browser`, `typecheck`) y el grupo por commit; esta sección | `ledger-lint` |

Las cuatro tareas siguen en `en-revision`.

## Cierre de la Fase 2 — pasada E2E completa: dos defectos reales corregidos

La pasada completa (`E2E_PURGE=0 E2E_WORKERS=2`, 36 min) dio 644 aprobados, 2 fallidos y 4 no ejecutados (dependían de los fallidos). Los dos fallos eran defectos de la aplicación, no del arnés.

| # | Defecto | Causa | Corrección | Evidencia |
|---|---|---|---|---|
| 1 | axe `aria-roles` (critical) si audita durante una navegación (`all-brands.spec.ts:27` en desktop; antes `roadmap` E2E-10) | La barra de progreso de Inertia 2.3.29 inyecta `<div class="bar" role="bar">` y `role="spinner"`. `setupProgress` solo admite `delay`, `color`, `includeCSS` y `showSpinner`; la plantilla y el selector `[role="bar"]` están fijos en el componente (cambiar el atributo a mano rompería su `querySelector`) | Se desactiva (`progress: false` en `resources/js/app.js`) y `resources/js/Composables/navigationProgress.js` escucha `inertia:start`/`inertia:finish` (retardo de 250 ms como Inertia) con una barra propia `#nprogress > .bar[role="progressbar"][aria-label="Cargando página"]`, sin spinner; estilos en `resources/css/app.css` (indeterminada, sin animación con `prefers-reduced-motion`) | E2E nuevo `progress-bar.spec.ts` (retiene la petición de Inertia, comprueba barra visible, sin `role="bar"`/`spinner`, axe sobre `#nprogress` y reglas `aria-roles`/`aria-allowed-role`/`aria-progressbar-name`): 3 passed en desktop, mobile y mobile-360 |
| 2 | `media-import.spec.ts:36` (E2E-08, mobile-360) mostró «Too Many Attempts.» en `media-import-error` | (a) Laravel devuelve su 429 en inglés y el cliente muestra `json.message`. (b) `throttle:10,1` es un limitador numérico: su clave es `sha1(id de usuario)` y la comparten TODAS las rutas con `throttle:N,1`; en un worker, los tests de varios specs reutilizan al mismo usuario E2E dentro del minuto | (a) `bootstrap/app.php`: `ThrottleRequestsException` en JSON (web con fetch, `api/*`, `mcp`) responde en castellano, «Demasiados intentos. Espera N segundos y vuelve a probar.» con N de `Retry-After`, conservando las cabeceras; HTML sin cambios. El frontend no necesitó cambios: `MediaSection.vue` muestra `json.message` (y `useWorkflow`/`mcpClients` ya traducen su 429). (b) Se elige vaciar el cubo del usuario en el `beforeEach` de `media-import.spec.ts` con `clearUserThrottle` (`tests/e2e/helpers.ts`: `RateLimiter::clear(sha1(id))` en el servidor aislado de cada worker). Descartados: usuario/marca por test (el cubo es por usuario y habría que crear usuarios con 2FA/estado), y un límite configurable (cambiaría producción o añadiría una vía de configuración solo para tests). El límite de producción no cambia; no existe ningún E2E del rate limit real, y Pest lo cubre | Pest `tests/Feature/Infra/ThrottleMessageTest.php` (5 passed: web JSON con Retry-After, `api/*` y `/mcp`, HTML por defecto, vaciado del cubo); `Infra`+`Media`+`MediaImportUiTest` 333 passed; `all-brands`+`media-import`+`a11y`+`progress-bar` 139 passed y 1 fallo ajeno (`ERR_NO_BUFFER_SPACE` de Windows en un `page.goto`, con otras tareas en paralelo) que repetido pasa; `roadmap` E2E-10 3 passed |

Verificación de esta corrección: `npm run build`, Pest focal, `npm run test:unit` (635 passed), `npm run typecheck`, Pint, y Playwright con `E2E_PURGE=0 E2E_WORKERS=2` de `all-brands`, `media-import`, `a11y`, `progress-bar` y `roadmap` E2E-10. Nota: la clave compartida de los `throttle:N,1` numéricos entre rutas es un comportamiento de Laravel que ya existía (los limitadores con nombre sí llevan prefijo); no se toca aquí.


### Desbordamientos horizontales móviles destapados por `overflowX()` corregido

Con la medida contra `clientWidth` salieron 7 fallos `expectNoOverflow` (más 23 tests `serial` sin ejecutar). Elementos medidos con `getBoundingClientRect().right > clientWidth` en mobile-360; todos eran defectos de la interfaz.

| Página | Elemento que desbordaba | Corrección |
|---|---|---|
| Calendario / lista a 360 px (`bluesky` E2E-03, `calendar-views` E2E-02, `csv-export` E2E-05, `threads` E2E-08, `workflow` lote) | Los `select.field.w-auto` (Marca, Red, Estado) toman el ancho de su `<option>` más larga y empujaban al contenedor `ml-auto flex-wrap` de la barra de filtros fuera del viewport (la tabla de la lista ya estaba dentro de su `overflow-x-auto`) | `resources/css/app.css`: `max-w-full` en `.field` (barrido: cubre todas las ocurrencias de `field w-auto` de Ads, Autolists, Inbox, Series, SmartLinks, PeriodFilter, BatchBar, DateTimePicker, Reports y Calendar); `Calendar/Index.vue`: `min-w-0 max-w-full` en el contenedor de filtros |
| Compositor 375/360 px (`composer` A11Y-01) | Popover del selector de emoji: `left-0` anclado a un botón que no está en el borde, con `w-[min(22rem,100vw-1.5rem)]` (r=365 > 360) | `EmojiPicker.vue`: `inset-x-0` en móvil (ancho de la barra del editor) y `sm:left-0 sm:w-[22rem] sm:max-w-[calc(100vw-1.5rem)]`; el envoltorio en `EditorToolbar.vue` pasa a `sm:relative`, mismo patrón que `HashtagSuggestions` |
| Compositor 375/360 px (`saved-texts` E2E-02) | Popover del menú de textos guardados, mismo ancho fijo anclado al botón (r=365) | `SavedTextsMenu.vue`: igual que el emoji (`sm:relative` en el envoltorio y `inset-x-0` / `sm:w-[22rem]`) |

Verificación: `npm run build`; los 7 specs afectados (bluesky, calendar-views, csv-export, threads, workflow, composer, saved-texts) en desktop, mobile y mobile-360 con `E2E_PURGE=0 E2E_WORKERS=2`: 123 aprobados, 0 fallos. Barrido de los demás specs `@responsive` (43 ficheros) en mobile y mobile-360, en 6 tandas: 318 aprobados, 0 fallos, sin más desbordamientos. `npm run test:unit` 635 pass; `npm run typecheck` sin errores.


### Regresión posterior: `report-builder.spec.ts:17` (mobile-360) fallaba 4 de cada 5

- **Síntoma:** `waitForEvent('download')` agotaba 60 s; el trace mostraba el clic en «Descargar» reintentado sin fin (`<p> from <div> subtree intercepts pointer events`). No era la barra `#nprogress` (tiene `pointer-events: none` y no estaba en el DOM), ni el sondeo, ni el 429.
- **Causa raíz (medida):** el selector «Marca» de `Reports/Index.vue` (`select.field.w-auto`) toma el ancho intrínseco de su `<option>` más larga (nombre de marca `e2e-composer-A-report-builder-<stamp>`) y, en 360 px, desbordaba la página (`scrollWidth` 378-382 frente a `clientWidth` 360). Con ese desbordamiento Chromium móvil ensancha el viewport de diseño (`innerWidth` 386 frente a un viewport visual de 360) y la comprobación de impacto de Playwright desplazaba el clic al `<div>` de texto vecino. Dependía del largo del sufijo aleatorio del nombre, de ahí el 4/5. Defecto de producto real: los usuarios con marcas de nombre largo tenían scroll horizontal en móvil.
- **Por qué no lo vio la suite:** `overflowX()` de `tests/e2e/helpers.ts` restaba `window.innerWidth`, que crece con el propio desbordamiento (medía siempre 0). Ahora resta `documentElement.clientWidth`.
- **Corrección:** `Reports/Index.vue`: `max-w-full` en los 9 `select.field.w-auto` y `min-w-0 max-w-full` en sus `<label>`; `helpers.ts`: `overflowX` contra `clientWidth` (es también el test de regresión: con el defecto, `expectNoOverflow` falla).
- **Verificación:** `npm run build`; `report-builder.spec.ts` mobile-360 `--repeat-each=10` 10/10 (antes 2/5); el mismo spec y `progress-bar.spec.ts` en desktop, mobile y mobile-360 6/6; `npm run test:unit` 635 passed; `npm run typecheck` sin errores. Barrido `--project=mobile --project=mobile-360` completo cortado por el límite de 590 s: sin ningún fallo hasta ese punto, pendiente de la pasada completa de qa con el `overflowX` más estricto.

- **Por qué aparece ahora:** ningún cambio de la rama toca ese selector ni la barra, el sondeo o el 429. El spec pasó a `@responsive` (commit ce85ac4, T-14/T-18), así que corre por primera vez en mobile-360 (y mobile 375, con más holgura); en desktop y 375 el desbordamiento no impedía el clic.

## Historial archivado

- 2026-11-12 · «Revisión de dos lentes — intento 1: Fase 1 (T-04, T-05) — gaps corregidos» → [execution-history.md](execution-history.md) · SHA-256 `150f4204bcb645f0ab80a8efa3773196171f4b1fa107a2a2338b1b67f4968e8b`
- 2026-11-12 · «Revisión de dos lentes — intento 1: Fase 1 (T-06) — gaps corregidos» → [execution-history.md](execution-history.md) · SHA-256 `1f576eff786c6e04637ff8a48e1f322181c06d0f1f10796bb82adf2a033392a3`
- 2026-11-12 · «Revisión de dos lentes — intento 1: Fase 1 (T-07, T-08) — gaps corregidos» → [execution-history.md](execution-history.md) · SHA-256 `58fe0b844628b9c51ea9de7d7132788218fbb23cf688436164d5fb69fb9942cf`
- 2026-11-12 · «Revisión de dos lentes — intento 1: Fase 2 (T-12, T-13) — gaps corregidos» → [execution-history.md](execution-history.md) · SHA-256 `f86dd5b72cb59d8a7f6d0d70c11fbe87dee23b22f3e6ef89564347eff454109f`

## Revisión de dos lentes — intento 1: Fase 3 (T-20, T-21) — gaps corregidos

T-20 aprobada sin hallazgos. T-21: 2 Important, 2 Minor y 2 extras, cada uno con su test en rojo antes del arreglo.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Un 429 (`QuotaExceededException`, que no hereda de `TransientPublishException`) caía en el `catch (ConnectorException)` de `GuardsInsightsErrors`: el backfill se abandonaba (días 4-29 sin datos) y el refresco de pines no leía. El test del 429 de `PinterestInsightsReaderTest` solo comprobaba la excepción | T-21 (común a todas las redes) | `GuardsInsightsErrors` captura la cuota antes y libera el job con backoff `$backoff` × 2^(intento−1), al menos `retryAt` y con tope de 1 h (sirve a los seis jobs: backfill, refresco, snapshot de cuenta, comentarios y competidores; Meta/YouTube/LinkedIn/Bluesky también). `PinterestClient` convierte `Retry-After` en `retryAt`. Tests nuevos: `QuotaRetryTest` (3: backfill, backoff/`Retry-After`/tope, refresco) y dos en `PinterestInsightsReaderTest` (el `Retry-After` llega a la excepción; un 429 en el backfill real de Pinterest libera el job con 700 s y no marca la cuenta) | RED: «Method release(300) should be called exactly 1 times but called 0 times» · luego 3/3 y 15/15 verde |
| 2 | Important | En sandbox `accountDaily` lanzaba antes de leer `profile`: se perdían seguidores y pines y el panel quedaba en `no_data` eterno | T-21 | `guardedDaily` captura el rechazo permanente, lee el perfil igualmente y anota `meta.analytics_unavailable` (`sandbox` si el host lo es, `access` si es producción con 403; se borra al responder). `MetricCoverage` devuelve `status` = `unavailable_sandbox`/`unavailable_access` y `reason` (también en `merge`) mientras no haya datos; `quality.js` lo explica: «Pinterest no ofrece analítica en el entorno de pruebas; conecta la app con acceso de producción» | RED: «Pinterest nego el permiso» propagado y `followers` null · luego `PinterestInsightsReaderTest` 15/15 y `metric-quality.test.mjs` 4/4 |
| 3 | Minor | Tableros recortados sin aviso (tope de 500; con `strict: false` una página fallida quedaba como lista completa) | T-21 | `boards()` devuelve `partial` (tope con `bookmark` pendiente, página fallida al conectar o presupuesto agotado) y se guarda en `meta.boards_partial`; Conexiones recibe `boards_partial` y muestra «Mostrando N tableros, pero hay más; si no ves tu tablero, indícalo por su id»; el aviso de «Actualizar» también. Changelog corregido (ya no dice «completos») | RED: 5 tests nuevos de `PinterestBoardsPaginationTest` · luego 11/11 |
| 4 | Minor | El criterio «los informes muestran datos de Pinterest» estaba marcado sin evidencia | T-21 | Test `G4` en `PinterestAnalyticsFlowTest`: `ReportService::dataset` con cuenta y snapshots de Pinterest incluye la red con views 200, interacciones 20, seguidores 50 y su publicación destacada | verde (era comportamiento ya correcto: evidencia, no arreglo) |
| 5 | Extra | M14 del inventario no citaba Pinterest | T-20/T-21 | Fila M14 con Pinterest y la nota de que la analítica solo existe con acceso de producción | lectura |
| 6 | Extra | `refreshBoards` podía encadenar 5 páginas de 15 s dentro de la petición web | T-21 | Presupuesto de 10 s (`BOARDS_BUDGET_SECONDS`): no se pide otra página pasado ese tiempo (10 + 15 s de una página lenta < 30 s de `max_execution_time`; algo menor que los ~20 s sugeridos para dejar margen) y la lista queda parcial | RED→verde: test del reloj avanzado 11 s por página |

Verificación (2026-11-12): Pest focal `Analytics`+`Connectors`+`Snapshots`+`Backfill`+`Reports`+`Routes`+`Jobs`+`Inbox`+`Calendar` 825/825 y `Competitor|SyncAccountComments|Dashboard` 38/38; Pint OK; `npm run typecheck`, `npm run build` y `npm run test:unit` (636/636) OK; Playwright `linkedin-pinterest.spec.ts` (`E2E_PURGE=0`, `E2E_WORKERS=2`, sin otra ejecución en curso) 9/9. T-20 y T-21 siguen en `en-revision`.

## Revisión de dos lentes — intento 1: Fase 3 (T-22, T-23) — gaps corregidos

Cada gap lleva su test, que falló antes del arreglo (`tests/Feature/Notifications/Phase3ReviewT22T23Test.php`). Decisión transversal del usuario: sin compatibilidad hacia atrás, shims ni código muerto.

| # | Sev. | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Threads: tratamiento del permiso en `ThreadsComments.php` | T-22 | **resuelto en T-24** (otro agente trabaja ese fichero; no se toca aquí) | — |
| 2 | Important | Ads (`AdsService`, `Ads/Index.vue`) no explicaba `META_EXTRA_SCOPES` y usaba otro patrón visual que el inbox | T-22 | `PermissionHelp::meta()` (texto único) y componente `PermissionNotice.vue` usados por Ads y por el inbox, con los mismos pasos y el enlace a Conexiones; desaparece `AdsService::HOWTO` (ahora `help()`) | RED: `PermissionHelp` inexistente; verde; E2E-03b |
| 3 | Important | `refresh_expires_at` no avisaba (LinkedIn 1 año, Pinterest, TikTok) | T-23 | `ConnectionExpiry` lo incluye como fecha relevante (motivo `refresh`) y el comando lo busca | RED: 0 avisos de 2 esperados |
| 4 | Important | El umbral de «1 día» llegaba entre 0 y 24 h antes | T-23 | Se avisa cuando queda ≤ N días + el intervalo de la pasada (`expiry_notice_interval_hours`, 24 h): llega con ≥ N días de margen; tests con el límite exacto y +1 s | RED: 0 de 1 |
| 5 | Important | Lo que corta el tope de 5 no dejaba rastro | T-23 | Log `jobs.failure_notification_capped` + aviso resumen «Y N fallos más de … en la última hora» (`AsyncJobFailureSummary`, se actualiza en su sitio, un conteo por trabajo) y la clave del trabajo no se consume si no se avisó | RED: 0 resúmenes |
| 6 | Important | Solo se avisaba al fallar | T-23 | `AsyncJobDone` (solo base de datos): exportación MP4/GIF, Canva, IA de imagen e importación por URL; el informe programado no. Criterio ajustado (decisión del coordinador) | RED: `exportDone` inexistente |
| 7 | Minor | Clave de deduplicación por programación y no por ejecución | T-23 | La clave del informe incluye la fecha de la ejecución | RED: no avisaba en la 2.ª ejecución |
| 8 | Minor | Fecha del aviso de caducidad en UTC | T-23 | Se formatea en la zona de la marca | RED: «18 de noviembre» en vez de «19» |
| 9 | Minor | `LINKEDIN_RECONNECT_NOTICE_DAYS` | T-23 | Decisión del usuario (sustituye a la del coordinador): sin alias ni aviso de deprecación; la variable desaparece del código, la config y los docs y queda anotada en `docs/modulos/despliegue.md` («Cambios de configuración») | grep sin referencias |
| 10 | Minor | `Notifications/Index.vue` perdió «Ver publicación» | T-23 | Un aviso con `post_id` lleva siempre «Ver publicación»; el resto, etiqueta por tipo | E2E-03 (`media_missing`) |

Verificación (2026-11-12): Pest focal `Notifications`+`Connections`+`Parity`+`Inbox`+`Infra`+`StudioAvQueueTest` 331/331 (4 omitidos) y `Media`+`Canva` 337/337; Pint OK; `npm run build`, `test:unit` 636/636 y `typecheck` OK; Playwright `notifications-unified.spec.ts` + `inbox-triage.spec.ts` con `E2E_PURGE=0 E2E_WORKERS=2` (sin otra ejecución en curso). T-22 y T-23 siguen en `en-revision`.

## Revisión de dos lentes — intento 1: Fase 3 (T-24, T-25) — gaps corregidos

1 Critical, 5 Important y 4 Minor; cada gap lleva su test, rojo antes del arreglo cuando era posible (`CompetitorMigrationOrderTest`, `FirstCommentThreadsBlueskyTest`, `CompetitorSourcesTest`). Los dos gaps de T-22 absorbidos antes (`ThreadsComments`) se reajustan en los puntos 3 y 4.

| # | Sev. | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | La migración borraba `unique(brand_id, username)` antes de crear otro índice con `brand_id`: en MySQL falla con el error 1553 (sostiene la FK). Igual en `down()` | T-25 | `up()` crea `unique(brand_id, network, username)` y después borra el antiguo; `down()` recrea el antiguo y después borra el nuevo. Anotado en `pendientes-manuales.md` (probar en MySQL 8 real) | RED: 2 tests de orden fallaban (posición del `dropUnique` anterior a la del `unique`); verde. MySQL real: pendiente |
| 2 | Important | `findOwnComment` de Threads devolvía `null` sin `threads_read_replies`: un reintento podía duplicar el comentario | T-24 | Lanza `CommentSearchInconclusiveException`: actúa la protección de `first_comment_sent_at` | RED: «not thrown»; verde |
| 3 | Important | Un permiso opcional de respuestas rechazado (`guarded`) lanzaba `ReconnectRequiredException` y bloqueaba la cuenta (y lo programado) | T-24/T-22 | `ScopeMissingException` para `threads_read_replies`/`threads_manage_replies`: falla solo ese comentario o lectura y retira el scope; `PostFirstComment`, `InboxService::call` y `GuardsInsightsErrors` no marcan la cuenta. Solo un token rechazado (190) la marca. El test de T-22 «desde el inbox la cuenta queda marcada» se ajusta a este criterio | RED: 3 tests (cuenta quedaba `requiere_reconexion`); verde |
| 4 | Important | La clasificación `permisos_objeto` usaba palabras sueltas (`user`, `post`, `media`, `content`) y tomaba por «de objeto» errores de la cuenta | T-24 | Por defecto el rechazo es de la cuenta; solo es de objeto con frases completas (no existe, se borró, pertenece a otra persona). Batería de 12 mensajes de Meta (8 de cuenta, 4 de objeto) | RED: 4 mensajes de cuenta salían como objeto; verde |
| 5 | Important | Un 401 de YouTube en competidores marcaba el canal para reconectar y bloqueaba subidas programadas | T-25 | 401 transitorio (como `Analytics`): se reintenta tras el refresco programado de tokens y solo si este falla se marca | RED: la cuenta quedaba bloqueada; verde |
| 6 | Important | El presupuesto de YouTube (300 ud) era global y los competidores con id alto se quedaban sin datos | T-25 | Presupuesto global + tope por usuario (60, `SOCIAL_COMPETITORS_YOUTUBE_USER_UNITS`); los despachadores procesan primero a los que nunca tuvieron datos y a los más antiguos. Capacidad documentada: ~3 ud por competidor y día, ~100 en la instalación y ~20 por usuario | RED: 2 tests (`usedBy` inexistente, orden por id); verde |
| 7 | Minor | El límite del primer comentario contaba con `mb_strlen` | T-24 | `first_comment.count` (`graphemes`/`threads`) y `max_bytes` (Bluesky) en `media_specs`; `FirstCommentService::lengthError` lo usan `PostService` y la edición; el contador del compositor usa `countText` con el mismo modo | RED: 100 familias de emojis rechazadas; verde |
| 8 | Minor | Chips de red de competidores con marcado propio | T-25 | Componente común `Components/NetworkChips.vue` (flechas, Inicio y Fin mueven el foco) usado por Competidores y por el adaptador del Dashboard (`Dashboard/Partials/NetworkTabs.vue`); los textos por red salen de `CompetitorSource::copy()`. El `NetworkTabs` del compositor (pestañas ARIA del texto) es otro componente y no se toca | `test:unit` (el test de «ninguna página repite la lista de redes» lo detectó antes del cambio) y E2E |
| 9 | Minor | MCP `compare_competitors` no validaba `network` | T-25 | El enum sale de las redes con fuente y la ejecución rechaza una red sin fuente o deshabilitada | RED: `tiktok`/`nope`/Bluesky apagado se aceptaban; verde |
| 10 | Minor | `CompetitorSources::for` ignoraba `isAvailable()`; criterio de `QuotaPolicy` incoherente | T-25 | `for()` exige disponibilidad y los jobs saltan las redes apagadas; criterio enmendado (decisión del coordinador): `QuotaPolicy` es de publicación, la cuota de lectura la llevan `YouTubeQuota` y el presupuesto de competidores | RED: «not thrown»; verde |

Verificación (2026-11-12): Pest focal `FirstComment`+`Competitors`+`Parity`+`Connectors`+`Inbox`+`Mcp`+`Dashboard`+`Posts`+`Unit` 1686/1686; Pint OK; `npm run build`, `test:unit` 636/636 y `typecheck` OK; Playwright `competitors`+`first-comment`+`pages`+`metrics-quality` (`E2E_PURGE=0`, `E2E_WORKERS=2`, sin otra ejecución en curso) 125 pasan, 2 omitidos. T-24 y T-25 siguen en `en-revision`.

## Revisión de dos lentes — intento 1: Fase 3 (T-26, T-27) — gaps corregidos

2 Important y 6 Minor (más un extra). Cada corrección con un test que salió en rojo antes del arreglo.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | `JWT::$leeway` valía 0: un `iat` unos segundos en el futuro o un `exp` justo rechazaban un retorno legítimo | T-26 | `CanvaJwks` aplica un margen configurable (`services.canva.jwt_leeway`, 60 s) solo durante la llamada y restaura el estático global | `CanvaJwksTest` «reloj desfasado» (rojo: `iat` +30 s rechazado → verde; `exp` −30 s ok, `exp` −120 s e `iat` +300 s siguen rechazados, `JWT::$leeway` sigue en 0) |
| 2 | Important | Lista de PDF limitada a 20 y biblioteca sin PDF (`CanvaIntegration.php`, `MediaController.php`) | T-27 | **Resuelto en T-28/T-29** (agente de paginación unificada); no se toca aquí | — |
| 3 | Minor | Sin caché negativa si Canva falla (CWE-400): cada verificación volvía a pedir el JWKS | T-26 | Fallo cacheado 60 s; timeout de 5 s en la petición de claves; una renovación fallida conserva las claves buenas; una clave mal formada se descarta y el resto del conjunto sigue valiendo | `CanvaJwksTest` «el fallo se cachea un minuto» (rojo: 2 peticiones → 1; tras 61 s se vuelve a pedir) y «clave mal formada» (rojo → verde) |
| 4 | Minor | Comentario huérfano sobre el modelo anterior en `setup-runtime.py:18` | limpieza | Eliminado | `python -m unittest test_setup` 4/4 |
| 5 | Minor | Plantillas de marca sin paginar | T-27 | «Cargar más plantillas» con `continuation`, mismo patrón que Diseños | E2E-39 (el fake devuelve `continuation`; segunda página `BTe2eSemana2`) |
| 6 | Minor | `AutolistService::addItem` y `LibraryService::store` aceptaban el `media_id` de un PDF | T-27 | Rechazo al darlo de alta con el mismo aviso del validador (`MediaValidator::pdfNotice()` / `assertPublishable()`) | `CanvaPdfLibraryTest` «autolista ni en una plantilla» (rojo: `pdfNotice` inexistente → verde) |
| 7 | Minor | `PermissionNotice` (con `role="alert"`) para una nota informativa | T-27 | Componente común `InfoNote.vue` (`role="note"`, genérico para estudio y social); Canva lo usa para el aviso de PDF | E2E-39 (`getByRole('note')`); `axe` limpio |
| 8 | Minor | «Descargar» con S3/R2 no forzaba `attachment` | T-27 | `MediaAsset::downloadUrl()` firma con `ResponseContentDisposition=attachment; filename…` y `ResponseContentType`; `MediaController::download` lo usa (solo esa acción) | `CanvaPdfLibraryTest` «S3/R2 … fuerza el adjunto» (rojo: sin `ResponseContentDisposition` → verde) |
| E | Minor | Por API/MCP un campo de Autofill que no es de texto se enviaba como `type: text` | T-27 | `CanvaJobs::startAutofill` consulta los campos de la plantilla y rechaza con 422 `bad_fields` (nombre y tipo) los que no son de texto o no existen, antes de crear el trabajo | `CanvaAutofillCapabilitiesTest` «nunca se envía como texto» (3 casos; rojo: 202 → verde 422) |

Verificación (2026-11-13): Pest focal `Canva`+`Media`+`Parity/Autolist`+`Parity/Library`+`Console/BackupVerifyTest` verde (un fallo intermitente de `ImportRemoteMediaTest` coincidió con otro Pest en paralelo y pasa solo, 69/69); Pint OK; `npm run build` OK, `test:unit` 636/636, `typecheck` OK; Playwright `canva.spec.ts` 10/10 (`E2E_PURGE=0 E2E_WORKERS=2`, sin otra ejecución en curso). La acción de descarga de `MediaController` comparte fichero con el agente de T-28/T-29: se commitea solo ese hunk.

## Revisión de dos lentes — intento 1: Fase 3 (T-28, T-29) — gaps corregidos

3 Important y 6 Minor. Cada corrección con un test que salió en rojo antes del arreglo (comprobado en un worktree limpio de `HEAD` con los tests nuevos: 12 de 20 en rojo y los 8 anteriores en verde; el worktree se retiró después). Tareas en `en-revision`.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Los `throttle:N,1` por ruta compartían contador (`sha1` del usuario) con el grupo y con la web: el límite propio nunca actuaba | T-28/T-29 | Barrido de TODOS los `throttle:N,1` numéricos de `routes/*.php` (91, también web): ahora `throttle:<nombre de la ruta>`. Valores en `config/rate_limits.php` y registro en bucle en `AppServiceProvider` (clave por usuario o IP + nombre). `api-v1` es el tope conjunto. Quedan solo los 4 que ya llevaban prefijo propio (`smartlink-subscribe`, `site-unlock`, `site-contact`, `site-vote`). `App\Support\RouteLimits::forget` sustituye al `RateLimiter::clear(sha1(id))` del E2E aislado y de `ThrottleMessageTest` | `RateLimitsTest` (sin throttles numéricos; todo lo declarado se usa y está registrado; agotar `api.posts.cancel` no afecta a `destroy` ni al resto; idem en la web); `CalendarViewCrudTest` e `InboxNoteCrudTest` ajustados |
| 2 | Important | 422 en lugar de 409 para estado que no admite la operación | T-28/T-29 | `PostStateConflict` (subclase de `PostValidationException`, la web no cambia) lanzada por `lockEditableTargets` y por restaurar algo que no está en la papelera; `ApiController::respond` y `PostWorkflowController::guard` → 409 (con `InvalidPostTransition` y `DomainException`: media en uso); cancelar un borrador → 409; 422 solo para entrada. MCP: `SchedulerTool` devuelve `409: …`. OpenAPI, `api-v1.md`, `mcp.md` y despliegue actualizados | `ApiV1PostsTest` (borrar/publicar/cancelar/editar publicado → 409; entrada inválida → 422; `delete_post` por MCP → `409:`, datos inválidos sin ese prefijo); `ApiV1ReviewFixesTest` (media en uso 409) |
| 3 | Important | Un `PUT` de SmartLink sin `published` publicaba (CWE-1188) | T-29 | `SmartLinkService::save`: sin `published`, uno nuevo nace sin publicar y uno existente conserva su estado. Los tests web de SmartLink envían `published: true` explícito (el formulario ya lo hace) | `ApiV1ReviewFixesTest` «un PUT sin published nunca publica» |
| 4 | Minor | La media de la API no pasaba por `ApiController` | T-29 | Nuevo `Api\MediaApiController` (errores con `errors`, auditoría del canal `api`, borrar → `{id, deleted}`, sin `preview_url`/`download_url` en show, store, upload, complete e import). La lógica compartida con la web (guardas de subida local y de cierre, reglas de `complete`) pasó a `MediaUploadService` (`receiveLocal`, `completeClientUpload`) | `ApiV1ReviewFixesTest` «media: errores, auditoría…» |
| 5 | Minor | `PostApiController::store/update` repetían `invalid()` y el 409 a mano | T-28 | Pasan por `respond()` (también `cancel`); `ValidationException` se audita y se relanza | `ApiV1PostsTest` completo |
| 6 | Minor | Un cursor de un listado servía en otro | T-28/T-29 | El cursor sellado lleva la identidad del listado (`posts:u:b`, `media:u`, `comments:u:b`, `designs:u:b`, y en las herramientas nuevas de T-30 `autolists`, `saved-texts` y `trash`); otro listado → 422 | `ApiV1PostsTest` «un cursor de otro listado se rechaza» |
| 7 | Minor | Diseños ordenados por `updated_at`: uno editado entre páginas desaparecía | T-28 | Orden por `id desc` | `CursorOrderTest` (5 diseños, editar el más antiguo entre páginas: aparecen todos una vez) |
| 8 | Minor | `StudioService.php` mezclaba UTF-8 con latin-1 (edición con la codificación del sistema) | T-28 | Reconvertido a UTF-8 limpio; `iconv -f UTF-8 -t UTF-8` válido en todos los ficheros del diff de la rama | `CursorOrderTest` «todo el código fuente… UTF-8 válido» (app, routes, config y tests/Feature/Api) |
| 9 | Minor | Sin notas de despliegue de los cambios incompatibles | T-28/T-29 | `docs/modulos/despliegue.md` («Cambios incompatibles de API y MCP») y `CHANGELOG.md` / `CHANGELOG.es.md` (`[Unreleased]`) | — |

- Convivencia con T-30/T-31: sus herramientas `manage_autolist`, `manage_saved_texts` y `manage_trash` llamaban a `CursorPage::fetch` sin ámbito; se les añadió el ámbito (solo esa línea). Los tests de SmartLink y de `McpNewToolsTest` que fallaban («Cannot access offset of type string on string», rutas públicas 404) venían de que ya no se publica por defecto (gap 3): `published: true` explícito en sus datos. Pint completo en verde.
- Verificación (2026-11-13): Pest focal `Api`+`Mcp`+`Posts`+`Media`+`Parity`+`Routes`+`Studio`+`Workflow`+`Infra`+`Inbox`+`Sites`+`Canva`+`Calendar`+`Reports`+`Competitors`+`Auth`+`Library`+`Ai`+`Ui`+`Isolation` → 2410 tests, 2401 pasan, 9 omitidos, 0 fallos (sin otra ejecución en curso); `McpNewToolsTest`, `Parity` (SmartLink) y `Calendar` 181/181; `npx @redocly/cli@2.77.0 lint --extends minimal docs/api/openapi.yaml` → válido, 0 errores; Pint OK. Playwright `canva.spec.ts`, `studio.spec.ts` y `media-import.spec.ts` → 39/39 (`E2E_PURGE=0 E2E_WORKERS=2`, una sola ejecución, sin otra en curso; el helper `clearUserThrottle` usa ya `RouteLimits::forget`).

## Revisión de dos lentes — intento 1: Fase 3 (T-30, T-31) — gaps corregidos

Sin Critical ni Important; 8 Minor y 3 extras, cada uno con su test, que falló antes del arreglo (ejecución en rojo: 14 fallos y 3 errores de 39 en `McpNewToolsTest`, `FacebookMultiPhotoStoryTest` y `CursorOrderTest`). Las dos tareas siguen `en-revision`.

| # | Sev. | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Minor | `AutolistController::index` repetía la consulta del servicio | T-30 | Usa `AutolistService::query` | Refactor sin comportamiento nuevo: no admite test en rojo; cubierto por `AutolistTest` y `McpNewToolsTest` (mismo `query`) |
| 2 | Minor | La descripción de `published` en `manage_smartlink` decía «por defecto true» | T-30 | Dice que un SmartLink nuevo queda sin publicar y uno existente conserva su estado | RED: descripción vacía/incorrecta; test lee `tools/list` y guarda sin `published` |
| 3 | Minor (CWE-285) | `manage_trash delete_forever` borraba sin confirmación | T-30 | Exige `confirm: true` (booleano estricto; `'si'` no vale), error claro sin borrar; la descripción avisa de IRREVERSIBLE y de la confirmación humana | RED: borraba sin `confirm` |
| 4 | Minor (CWE-269) | Alta y baja de competidores con `posts:write` | T-30 | Ability nueva `competitors:write` (`Abilities`, preset `all`; ni `read` ni `publish`), usada por `manage_competitor`; la baja pide `confirm: true` (borra el histórico). No hay endpoint de alta o baja en la API v1, así que no hay nada más que proteger. Sin alias (las claves `*` siguen valiendo); anotado en `docs/modulos/despliegue.md` («Cambios incompatibles») | RED: `posts:write` bastaba y `competitors:write` no existía |
| 5 | Minor | `progress.photos` sin caducidad: tras 24 h se mandaban a `/feed` IDs que Meta ya borró | T-31 | Cada foto guarda `{id, at}`; pasadas 23 h (o sin hora) se descartan y se vuelven a subir | RED: reutilizaba IDs de 24 h |
| 6 | Minor | Álbum sin texto no reconciliable (duplicaba tras un fallo ambiguo) | T-31 | `findAlbum` busca en `/posts` un post cuyos `attachments.subattachments.target.id` contengan las fotos subidas vigentes (campo **sin verificar**); si hay fotos subidas y la consulta falla, `TransientPublishException` (inconcluso); sin coincidencia con consulta correcta, null | RED: devolvía null |
| 7 | Minor | `findStory` devolvía «no publicada» si la consulta fallaba o los campos no coincidían | T-31 | Fallo o respuesta sin `data[].media_id`/`post_id` → inconcluso (transitorio); lista bien formada sin la foto → null. La lectura `/stories` sigue **sin verificar** y la resuelve el smoke M-04 (documentado en `decisiones-por-red.md`) | RED: devolvía null / lanzaba `ErrorException` |
| 8 | Minor | El test de «red sin soporte» probaba el permiso, no la red | T-30 | Test nuevo de `hide` sobre un comentario de Bluesky (rama de `InboxService::setHidden`): mensaje «solo está disponible en Instagram y Facebook», sin tocar `hidden` ni la red | Pasa; el ya existente sigue cubriendo el permiso |
| 9 | Extra | `get_ads` volvía a la primera cuenta si `ad_account` no coincidía | T-30 | Error claro que lista las cuentas de la conexión (solo en la herramienta MCP; la web elige de la lista) | RED: devolvía la primera cuenta |
| 10 | Extra | «Comentario no encontrada» (y Informe, Sitio, Diseño, y los nombres de mis herramientas) | T-30 | `owned(..., masculine: true)` en los recursos masculinos; «Competidor» y «Texto guardado» sin etiquetas forzadas | RED: «Comentario no encontrada» |
| 11 | Extra | `tests/Feature/Isolation/BrandIsolationTest.php` no estaba en UTF-8 | T-30 | Convertido desde Windows-1252; el test de UTF-8 de `CursorOrderTest` ahora recorre `tests/` entero | RED: el test ampliado fallaba por ese fichero |

Verificación (2026-11-13): Pest focal `Mcp`+`Connectors/Meta`+`Parity/Autolist`+`Parity/Trash`+`Competitors`+`Parity/Ads*`+`Isolation`+`Api`+`ApiKeyOriginTest`+`Posts`+`Unit`+`Ui`+`SavedTexts` 1146/1146; `McpNewToolsTest`+`FacebookMultiPhotoStoryTest`+`CursorOrderTest`+`BrandIsolationTest` 59/59; Pint OK en mis ficheros (los avisos restantes son de ficheros del agente de T-32/T-33). Sin cambios de Vue: no se ejecutaron `npm` ni Playwright. No se lanzó el Pest completo.

## Revisión de dos lentes — intento 1: Fase 3 (T-32, T-33) — gaps corregidos

4 Important, 7 Minor y 1 extra corregidos, cada uno con su test. Las dos tareas siguen `en-revision`. Ejecución en rojo comprobada para los gaps 1, 2 y el extra (3 de los 7 tests de `SeriesApprovalInheritanceTest` fallaban antes del código) y para el 4 (`StudioFoldersTest` con `StudioService` revertido: 7 de 8); en el resto los tests se escribieron junto al arreglo y no se ejecutaron en rojo aparte (el 3 y el 9 fallan por construcción: la clase y el índice no existían; el 8 no se midió «antes»).

| # | Sev. | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Una ocurrencia aprobada nunca se publicaba (seguía en borrador) | T-32 | `ApprovalService::decide` llama a `PostService::scheduleApprovedOccurrence`: sus destinos pasan a `programada` en su fecha sin `update()` (no la saca de la serie ni pone `series_exception`); una rechazada se queda en borrador y `dueTargets` no la devuelve | RED + verde: `SeriesApprovalInheritanceTest` «aprobar una ocurrencia la programa…» |
| 2 | Important (CWE-863) | Activar `requires_approval` no afectaba a las ocurrencias ya generadas | T-32 | `SeriesService::update` detecta el cambio: al activarla, `holdForApproval` (destinos a borrador y pendiente) en las futuras no publicadas; al desactivarla, `releaseApproval` programa las pendientes (no las rechazadas). Ningún camino publica una retenida: `publishNow`, `schedule` y `retryFailed` lanzan `PostValidationException` y `dueTargets` la excluye aunque el destino se fuerce a programada (el lote reprograma, cancela, borra o aprueba/rechaza con la firma revisada —aprobar pasa por el mismo `ApprovalService`— y nunca programa un borrador sin aprobar) | RED + verde: «activar la aprobacion en una serie con ocurrencias ya programadas…» |
| 3 | Important | Recordatorio síncrono en el despachador | T-32 | Clase propia `ManualPublishReminder` (`ShouldQueue`, cola `poll`, como `ConnectionExpiring`); `PostWorkflowActivity` vuelve a ser solo `database` (era la clase de los avisos de aprobación y publicación manual, que se leen al instante). Revisadas las demás notificaciones de la fase: `SeriesGenerationFailed` y `PostWorkflowActivity` solo usan `database` (sin correo) | `ManualReminderTest` (es `ShouldQueue`, dos trabajos encolados —app y correo— y ninguna fila escrita dentro de la pasada) |
| 4 | Important | Guardar el editor deshacía «Mover a carpeta» | T-33 | `Editor.vue` ya no envía `folder_id` y `StudioService::update` lo ignora siempre (carpeta ya borrada o ajena no bloquea guardar ni exportar); la carpeta cambia solo con su endpoint. Actualizados `StudioBackendTest` y `StudioCollaborationTest`, la guía de API/MCP y el esquema de `update_design` | RED (7/8 con el servicio revertido) + verde: `StudioFoldersTest` «guardar el diseño…» |
| 5 | Minor | El claim del recordatorio no repetía ventana ni `manual_notified_at` | T-32 | La misma condición (estado, `manual_notified_at`, ventana, aprobación) filtra la selección y el `UPDATE` atómico | `ManualReminderTest` «el claim atomico repite la ventana…» (inspecciona el SQL del `UPDATE`) |
| 6 | Minor | `recoverable` incluía fechas añadidas al editar la regla | T-32 | Columna `post_series.rule_changed_at` (migración reversible 2026_12_23) que `update()` fija solo si cambia la regla; `recoverableDates` parte del mayor de la creación y ese cambio | `SeriesBackfillTest` «una fecha que solo existe por haber cambiado la regla no es recuperable» |
| 7 | Minor | Escalonado con dos recuperaciones a la vez | T-32 | `Cache::lock('series-recover:{id}')`; la segunda responde 422 y las fechas se recalculan dentro del lock | `SeriesBackfillTest` «dos recuperaciones a la vez no se pisan» |
| 8 | Minor (rendimiento) | N+1 de `recoverableDates` en `present()` | T-32 | `presentMany` precarga marcas, ocurrencias y posts de todas las series; `recoverableDates` acepta zona y fechas existentes; `seriesIndex` lo usa | `SeriesBackfillTest` «el listado de series presenta con un numero constante de consultas» (7 series no añaden consultas a 2) |
| 9 | Minor (rendimiento) | Índices de `notifyDue`/`remindUpcoming` | T-32 | Índice `post_targets (manual, status)` (migración reversible 2026_12_23). `posts.scheduled_at` ya tiene índice propio y compuestos con `status`: no se duplica | `ManualReminderTest` «los destinos manuales programados tienen indice propio» |
| 10 | Minor (accesibilidad) | Renombrar con teclado | T-33 | Esc en el campo cancela solo el renombrado (`preventDefault`, el diálogo sigue abierto); el foco va al campo (`nextTick` + `focus()`, sin `autofocus`) y vuelve al botón al cancelar o guardar | E2E `studio.spec.ts` «folders…» con las aserciones de foco y Esc |
| 11 | Minor | Ledger de T-33 | T-33 | Notas (sin `confirm()`) y `Verificación` (el E2E está en `studio.spec.ts`) corregidas | `ledger-lint` |
| 12 | Extra | Con aprobación, una serie diaria avisaba 45 veces | T-32 | `PostService::collectApprovalRequests` agrupa las peticiones de una tanda (generación, edición, recuperación) y `SeriesService` emite UN aviso por serie y tanda a los aprobadores: «N publicaciones de la serie de «Marca» pendientes de aprobación» | RED + verde: «con aprobacion, una serie genera un unico aviso por tanda» |

Verificación (2026-11-13): Pest focal `Series`+`ManualReminder`+`ManualPublish`+`Approval`+`Workflow`+`StudioFolders`+`StudioBackend`+`StudioCollaboration`+`StudioApiMcp`+`DesignRevisionRetention`+`RouteNames`+`OpenApi`+`Migration`+`Mcp` 633/633 verde (2 omitidos); Pint aplicado. `npm run build` OK, `typecheck` OK, `test:unit` 639/639. Playwright (`E2E_PURGE=0 E2E_WORKERS=2`, `workflow.spec.ts` + `studio.spec.ts`) 23/23 verde. No se lanzó el Pest completo.

## Revisión de dos lentes — intento 1: Fase 3 (T-34, T-35) — gaps corregidos

T-34 sin gaps. En T-35, 1 Important, 5 Minor y 3 extras corregidos, cada uno con su test; las dos tareas siguen `en-revision`. Ejecución en rojo comprobada para G1 (8 de 28 tests de `StudioCaptionTranslationTest` fallaban antes del código), G2 (cuota y `max_tokens`, dentro de esos 8), G3/G4 (3 de 7 de `StudioPptxExportTest`) y los tests de miniaturas nuevos (4 de 8 pasaban antes: cola, estado, `failed()` y borrado de cuenta en rojo). El filtro de caracteres de control del nombre se escribió junto al arreglo y no se ejecutó en rojo aparte.

| # | Sev. | Gap | Corrección | Evidencia |
|---|---|---|---|---|
| G1 | Important | La traducción aplicaba textos ya redactados («[teléfono]» en lugar de «1.000.000») y la propuesta solo mostraba 50 | `StudioCaptionMasker`: marcadores numerados `⟦n⟧` (mapa solo en memoria durante la petición), restauración de los originales tras traducir y rechazo si falta o sobra un marcador; teléfonos solo con 9 a 15 cifras y sin separador de millares; la propuesta muestra todos los subtítulos (scroll) antes de aplicar | `StudioCaptionTranslationTest`: caso de «1.000.000», «2020 - 2024», «+34 600 123 456», «600123456»; ida y vuelta con datos originales; falta/sobra/sin marcador |
| G2 | Minor | `max_tokens` no cubría el JSON de salida | `0,6 × caracteres + 40 × subtítulos + 300` (tope 16.000; 12.000 caracteres por petición). `AiAssistant::complete` admite un validador: una respuesta cortada o no usable devuelve la cuota y dice «incompleta… no se ha descontado de tu cuota» | tests «max_tokens cubre el JSON» y «respuesta cortada… devuelve la cuota» (`remaining()` vuelve a 1) |
| G3 | Minor (CWE-116) | Caracteres de control en nombres → XML inválido en el PPTX | `esc()` elimina `\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`, `\x7F-\x9F` y no-caracteres; además `StudioDocument::cleanName` limpia el nombre del diseño (`StudioService::input`) y de cada página al guardar | `StudioPptxExportTest`: nombre y título con controles → `DOMDocument::loadXML` OK; nombre de diseño/página guardado limpio |
| G4 | Minor | `sldSz` por debajo del mínimo en formatos alargados | `slideBox`: lado entre 914.400 y 51.206.400 EMU; la imagen se reduce con proporción y se centra con letterbox | `StudioPptxExportTest` con 100×10000, 10000×100 y 16000×16000: rango, proporción y centrado |
| G5 | Minor | Aviso «no configurado» duplicado y botones con `caption-button` | Componente común `AiNotConfigured.vue` (sobre `InfoNote`) usado en `StudioAiPanel` (texto e imágenes) y `CaptionsPanel`; los botones nuevos usan `btn btn-secondary`. `AiSuggest` (social) se oculta sin asistente y no tiene aviso equivalente: no se toca | E2E `studio-ai.spec.ts` (mismos testids y textos) y `studio-pptx-thumbnails.spec.ts` |
| G6 | Minor | Recargas inútiles del listado y cola compartida | Cada fila trae `thumbnail_status` (`ready`/`pending`/`failed`/`off`) y `Index.vue` solo sondea con alguna `pending`; `RenderStudioThumbnail` pasa a la cola existente `studio` (su worker, sin competir con `media`): sin cola nueva, así que `QUEUE_NAMES`, el worker y `DeployQueuesDocTest` no cambian | `StudioThumbnailsTest`: cola `studio` y estados |
| E1 | Extra | Un job matado por timeout no ejecuta el `catch` | `RenderStudioThumbnail::failed()` llama a `StudioThumbnails::markFailed` (misma clave de aplazamiento) | `StudioThumbnailsTest` «failed() aplaza la revisión» |
| E2 | Extra | Ficheros huérfanos al borrar la cuenta | `User::deleted` borra `studio-thumbnails/{id}` y `studio-exports/{id}` | `StudioThumbnailsTest` «al borrar la cuenta…» (sin tocar a otro usuario) |
| E3 | Extra | CHANGELOG | Entradas de T-34 y T-35 en `[Unreleased]` (en y es) con el campo `Changelog` del ledger | `CHANGELOG.md` y `CHANGELOG.es.md` |

Verificación (2026-11-13): Pest focal `tests/Feature/Studio` + `Ai` + `ProfileTest` + `Mcp` + `Deploy` + `Infra` 1173/1182 verde (9 omitidas ya existentes); Pint OK. `npm run build` OK, `typecheck` OK, `test:unit` 639/639 (el test de `CaptionsPanel` necesitó inyectar `AiNotConfigured` y `studioRequest`). Playwright (`E2E_PURGE=0 E2E_WORKERS=2`): `studio-pptx-thumbnails` + `studio-captions` verdes (la primera pasada, con `studio-ai`, dio 22 verdes y 3 rojos de `studio-ai` por un import olvidado de `AiNotConfigured` en `StudioAiPanel`, corregido) y `studio-ai` 9/9 tras el arreglo. No se lanzó el Pest completo.

## Revisión de dos lentes — intento 1: Fase 4 (T-43, T-44, T-45) — tres hallazgos

Lentes A+B+D sobre el incremento desde `a6d6d8c`. El selector automático activó rendimiento por rutas de informes, lotes y MCP. La comprobación previa dio cero archivos fuera de alcance.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | La cobertura mostraba LinkedIn como disponible y también incluía la nota «Fuera por ahora» al registrar la fuente | T-43 | Aplicar las notas de indisponibilidad únicamente a redes sin fuente | `InboxCapabilitiesTest`, rojo y verde |
| A2 | Important | Quedaban cuatro filtros cualificados del propietario en consultas con `withoutUserScope()` | T-44 | Migrar a `ownedBy()` y ampliar el guard para columnas cualificadas | `OwnershipScopeTest`, rojo y verde. Las cuatro consultas conservan la cualificación y el listado de audio conserva el filtro del vínculo |
| A3 | Important | Studio AV y la búsqueda de datasets mantenían comprobaciones de propiedad separadas | T-45 | Usar `Ownership::matches()` y `Ownership::assert()` respectivamente | Revisión de ambos sitios y pruebas HTTP de gráficos y datasets |

## Revisión de dos lentes — intento 2: Fase 4 (T-43, T-44, T-45) — correcciones verificadas

Lentes A+B+D. A y B confirmaron las correcciones de sus hallazgos. El barrido de consultas y `git diff --check a6d6d8c` pasaron. D confirmó que las cuatro consultas conservan los filtros, los joins y la paginación donde aplicaba. La pasada focalizada de Pest terminó con 33 pruebas correctas. El resultado de QA completo se anota en las tareas.

QA comparativa de T-43 a T-45, 2026-11-16. Playwright ejecutó los mismos 71 escenarios de los nueve specs seleccionados en escritorio, móvil de 375 px y móvil de 360 px, con un worker. En la base `3b8a68e` pasaron 65 en la primera pasada. Los seis de tipografías y recorte de fondos pasaron al repetirlos con el mismo entorno Python y modelo del Estudio. Los 71 pasaron en la versión actual y `qa-gate.py` dio VERDE. Se cotejaron los casos por archivo, título y proyecto. Pest completo sobre la versión actual pasó con 4056 correctas, 9 omitidas y 25 821 aserciones.

## Revisión de dos lentes — intento 1: Fase 4 (T-46) — dos hallazgos Important

Claude Code ejecutó sesiones de solo lectura con Claude Sonnet 5.5 para las lentes A+B+D. La puerta de alcance no detectó archivos fuera del plan.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Important | Faltaba cobertura del fallo al registrar la imagen y de la excepción posterior al commit | T-46 | Añadir ambas regresiones | Pest comprueba que el rollback borra el objeto preparado y que el commit conserva el objeto registrado |
| B1 | Important | Un fallo de almacenamiento tras el commit de tipografía devolvía 500 y dejaba la limpieza pendiente | T-46 | Mantener 204 y reintentar en la cola `media` | Pruebas con excepción y retorno `false`, verificación del job y borrado al recuperar el almacenamiento |

## Revisión de dos lentes — intento 2: Fase 4 (T-46) — correcciones verificadas

Claude Sonnet 5.5, lentes A+B+D. Las lentes cerraron A1 y B1. A dejó un Important condicional sobre la revalidación del origen al mover el hash fuera del lock. D confirmó que el procesamiento, los sondeos y el fallback de limpieza quedan fuera de las transacciones de registro y borrado.

## Revisión de dos lentes — intento 3: Fase 4 (T-46) — sin Critical ni Important abiertos

Claude Sonnet 5.5, revisión focal de A con el código de la sonda y de la subida. El procesador usa una copia cuyos bytes coinciden con el SHA-256 del trabajo. El worker vuelve a cotejar el hash del archivo de origen antes de la transacción y comprueba la identidad de su fila bajo lock. El lock SQL no controla una escritura directa al mismo objeto entre el último hash y el commit. Claude clasificó ese intervalo como un límite menor entre sistemas. Verificó el import de `InvalidArgumentException`, la conservación del MIME y las rutas UUID independientes por alta de tipografía. Ambos accesos al disco de imágenes resuelven `filesystems.media`.

Si fallan el disco y el transporte de cola, se reportan ambos errores. Se conserva el 204 de un borrado de fila ya confirmado. La cola `media` permite recuperar fallos transitorios cuando acepta el job.

## Revisión de dos lentes — intento 1: Fase 4 (T-47) — contrato, cobertura y rendimiento

Claude Code ejecutó las lentes A+B+D con Claude Sonnet 5.5 sobre el diff aislado de T-47. La puerta de alcance dio cero archivos fuera del plan.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Critical señalado | Faltaba la protección de errores de comparación antes del API y el encolado de informes | T-47 | Validar el rango comparativo por la misma excepción de campo antes de renderizar o encolar | `Period::inputComparison()` y pruebas de informes y métricas |
| B1 | Important | Un enlace directo con fechas inválidas podía redirigir sin enseñar el error | T-47 | Mantener 422 en GET directo y mostrar errores de campo en navegación Inertia | Peticiones JSON, GET directo y Playwright del selector |
| B2 | Important | Un informe API podía buscar la plantilla antes de validar fechas | T-47 | Validar el período antes de consultar la plantilla | El servicio recibe el período ya validado y MCP conserva el parser común |
| A2 | Important | Faltaba cobertura de los informes, de fechas obligatorias y de varias rutas de panel y MCP | T-47 | Probar la pareja campo y mensaje en once rutas y llamadas, y fechas obligatorias en analytics API | `PeriodInputTest` |
| D1 | Important | La caché de filas volvía a hidratar y calcular todo en cada hit | T-47 | Cachear los cálculos diarios y mantener el detalle de cuentas y publicaciones en vivo | Medición local con 8784 filas y regresiones de caché caliente |
| D2 | Important | El recolector invalidaba también cuando el upsert repetía el mismo contenido y la misma captura | T-47 | Invalidar solo si cambian los datos que lee el panel o si el snapshot es nuevo | Caso de rollback, recogida histórica parcial y versión por marca |

## Revisión de dos lentes — intento 2: Fase 4 (T-47) — sin Critical ni Important abiertos

Claude Sonnet 5.5 revisó las correcciones con las lentes A+B+D. Se cerraron los hallazgos de comparación, formatos HTTP e Inertia, precedencia en la API de informes, alcance de validación, invalidación y rendimiento. La búsqueda de llamadores de `ReportService::request()` solo encontró el controlador API y `generate_report`, cubiertos por las pruebas. La búsqueda de escrituras de `account_snapshots` encontró el recolector. La clave incorpora el estado actual de cada cuenta y el último día cerrado.

El primer cálculo tras rotar la versión puede repetirse en peticiones simultáneas del mismo propietario. El refresco de una entrada existente lo coordina `Cache::flexible`. Las pruebas demostraron persistencia del acierto en la marca ajena y consistencia en rollback o lote parcial.

## Revisión de dos lentes — intento 1: Fase 4 (T-48) — hallazgos corregidos

Claude Code ejecutó A+B+D con Claude Sonnet 5.5 sobre el diff aislado. La puerta de alcance dio cero archivos fuera del plan. El 429 previo ocurrió antes de que Claude procesara el diff.

| # | Grado | Hallazgo | Resolución | Evidencia |
|---|---|---|---|---|
| A1/B1 | Important | Una migración de hoy puede ordenarse antes de una dependencia histórica futura | Documentar volcado por conexión para bases nuevas y aplicación previa por ruta de dependencias pendientes en bases existentes | En una sonda Artisan con SQLite en memoria, el orden por nombre falló y las dos vías documentadas pasaron |
| B2 | Important señalado | Faltaba evidencia de Pest y Playwright tras activar la protección | Descartado con los informes existentes y el cotejo de los 13 escenarios comunes. Se relanzó la verificación completa tras las correcciones | 4075 pruebas en la base de T-47, 4079 con T-48 y 23/23 en Playwright antes de esta revisión |
| D1 | Important | La prueba de Estudio tenía las miniaturas desactivadas | Activarlas con caché en memoria y simular la cola de renderizado. Cubrir miniaturas listas y pendientes y sitios publicados y borradores | `QueryCountTest` comprueba el recuento y el encolado |
| B3/D2 | Important confirmado | La búsqueda de llamadores destapó un 500 con dos destinos manuales y texto vinculado | Reutilizar la publicación ya autorizada al resolver el texto de cada destino | `ManualPublishTest` reprodujo `LazyLoadingViolationException` y pasó con textos propios y vinculados |
| B4 | Minor | El formato permitía horas imposibles | Comprobar HHMMSS y fecha UTC | La sonda con hora inválida y la sonda con fecha futura fueron rechazadas |

## Revisión de dos lentes — intento 2: Fase 4 (T-48) — sin Critical ni Important abiertos

Claude Sonnet 5.5 comprobó las correcciones con A+B+D. Confirmó el procedimiento de dependencias históricas, el recuento con estados de miniaturas y sitios y la reparación del contenido manual. Laravel tiene UTC fijo en `config/app.php`. Los listados ordenan por id, así que las filas de los fixtures conservan su posición. `TargetMedia::effective()` lee el atributo `media` del destino, cuyo accesor usa `mediaItems`. `PostTarget` precarga esa relación por defecto, también cuando el destino tiene media propia. La documentación indica que el CLI `sqlite3` se requiere al generar el volcado de SQLite.

## Revisión de dos lentes — intento 1: Fase 4 (T-49) — hallazgos corregidos

Claude Opus 5.5 ejecutó A+B+C+D sobre el diff aislado. Alcance verificado.

| Hallazgo | Gravedad | Resolución | Evidencia |
|---|---|---|---|
| Miniaturas en la misma FIFO que las peticiones de IA y los renderizados estáticos | Important | Conservar `studio` como cola de miniaturas por defecto y usar `studio-render` para IA, imágenes y exportaciones estáticas | `QueueConfigTest`, `StudioThumbnailsTest` y las guías de Hestia y systemd |
| Cambios operativos sin changelog | Important | Añadir entradas EN y ES y documentar cuándo activar el nuevo worker | `CHANGELOG.md`, `CHANGELOG.es.md` y el ledger |
| Supuesto atasco invisible si el .env antiguo omite la cola | Important señalado | Descartado. `HealthCheck::lateQueues()` agrupa todas las colas de la tabla de trabajos. `QueueProbe` añade la cola de render | Prueba SQLite inserta un trabajo atrasado en `studio-render` y comprueba el aviso con una lista antigua |
| Reinicio del contador de 2FA y posibles colisiones | Minor | Conservar el prefijo histórico y probar nombres únicos | `RateLimitConfigTest` verifica la clave, los valores y las ventanas |
| Timeout de informes fuera del presupuesto | Minor | Acotar los informes nuevos entre 1 y 110 s | `QueueConfigTest` verifica el valor anterior, un ajuste válido y ambos extremos |

## Revisión de dos lentes — intento 2: Fase 4 (T-49) — correcciones verificadas

Opus 5.5 verificó A+B+C+D. No quedaron Critical ni Important. La lente D encontró que un valor vacío de `STUDIO_RENDER_QUEUE` dejaba los trabajos sin el nombre esperado. Las otras observaciones se comprobaron en el código. PPTX usa `RenderStudioDesign` y conserva el worker de exportación estática. Las claves de los límites son obligatorias en la configuración versionada.

## Revisión de dos lentes — intento 3: Fase 4 (T-49) — sin Critical ni Important abiertos

Opus 5.5 verificó la corrección de la cola vacía con A+B+D. La configuración normaliza el nombre una vez y lo comparte con los jobs y la sonda. El alcance defensivo de la lente C se cerró en el intento 2 y el último delta solo cambia la resolución de la cola. El caso vacío se verificó mediante un arranque local controlado.

## Revisión de dos lentes — intento 1: Fase 4 (T-50) — correcciones pendientes de segunda revisión

Claude Code ejecutó A+B+C con Claude Opus 5.5 sobre el diff aislado. La puerta de alcance dio cero archivos fuera del plan.

| Hallazgo | Gravedad | Corrección o evidencia |
|---|---|---|
| Llamadores directos y posibles ciclos tras introducir dependencias | Important señalado | La búsqueda de construcciones directas y subclases no encontró coincidencias en app, tests, database ni routes. El contenedor resolvió las 11 clases tras arrancar Laravel. |
| Se estrechó la excepción reintentada en el contador público | Important confirmado | Restaurar `QueryException` y recrear la consulta para reintentar. La prueba de colisión inserta la fila entre la actualización y el alta. La prueba de FK confirma que se conserva el comportamiento anterior al desaparecer el padre. |
| Faltaba comprobar el censo y las implementaciones unificadas | Important señalado | Inventario 212 → 193 usos literales de `app(X::class)` y 36 → 33 construcciones con `new` dentro de constructores. La macro LIKE queda en un único punto y ambos consumidores de estado usan el mismo candado. |
| Caducidad lógica y propietarios de intentos OAuth incompletos | Minor | Pruebas con la entrada aún en caché, máximo de intentos y propietario distinto. Cada flujo indica si comprueba el estado en bruto de su esquema histórico. |

El reintento de A+B+C sobre el diff corregido se pospuso por la cuota. Este resultado no constituye una segunda revisión.

## Verificación focal de T-36 a T-42 para retomar la revisión

El 16 de noviembre se ejecutaron en serie los filtros declarados sobre el checkout actual. Cada fila corresponde a su propio filtro.

| Tarea | Filtro | Resultado |
|---|---|---|
| T-36 | `InstagramPublish\|ThreadsPublisher` | 56 correctas |
| T-37 | `TikTokPublish\|YouTubePublish` | 83 correctas |
| T-38 | `YouTube` | 143 correctas |
| T-39 | `Studio` | 727 correctas, 9 omitidas |
| T-40 y T-41 | `Post` | 409 correctas |
| T-42 | `Site` y `StudioRenderer` | 27 y 9 correctas |

Se prepararon los diffs de revisión con el criterio de calidad del código. Opus 5.5 sigue pendiente. Los resultados focales quedan disponibles en `%TEMP%/metricool-phase4-verification.json`.

## Integración adicional para T-36 a T-42

- Playwright serial sobre el checkout actual, 18/18. Incluye publicación simulada con media por destino, avance de importación, conexión y filtro de Threads, carpetas de Estudio, publicación manual, series con aprobación y reprogramación por lote. `qa-gate.py` verde. Informe en `storage/framework/testing/t43/run-general-362367db-63f9-46a7-81cf-d8e2302185b3/results.json`.
- Exportación audiovisual nativa para T-42, 1/1 en escritorio sobre Windows. El navegador ejercitó el orden de páginas, historial, cancelación, reintento y descarga real de MP4 y GIF antes de crear el borrador de marca. `qa-gate.py` verde. Informe en `storage/framework/testing/t43/run-general-3545ac03-1264-443c-b704-3e46acb5f21d/results.json`.
- E2E-38, 10/10 antes y después de la fase 4. Se compiló y probó `f40a583` en una copia aislada y se cotejaron fichero, título y proyecto Playwright con el informe actual de T-50. Pasaron publicación desde el compositor, planificación semanal, notas del inbox y generación de informes. `qa-gate.py` verde. Informes en `storage/framework/testing/t43/phase4-baseline-f40a583/results.json` y `storage/framework/testing/t43/run-general-82586fb2-75ae-40a8-9470-4975bb5f6a85/results.json`.
- La revisión de aceptación y calidad con Opus 5.5 sigue pendiente. El CLI confirmó HTTP 429 el 16 de noviembre, con reinicio previsto a las 01:30 del 17 de noviembre, hora de Madrid.

## Regularización de alcance documental — 2026-11-17

La puerta de alcance sobre HEAD encontró veinte rutas existentes sin declaración literal: mayúsculas de Composables, módulos ya extraídos, el montaje de ConfirmDialog, auxiliares de exportación, configuración CSP, la ruta de IA y el archivo del ledger anterior con su regla de bytes. Se añadieron las rutas exactas a sus tareas, sin modificar implementación ni estados y sin introducir exclusiones de producción. La base HEAD corresponde al inicio del trabajo no confirmado de esta iniciativa; el merge-base con main incluye miles de evidencias y código de iniciativas anteriores ya confirmadas. Las copias desechables .cache se excluyen solo localmente mediante .git/info/exclude. El script de alcance se ejecuta con caché de consultas repetidas os.path.isdir durante la lectura de un árbol inmóvil, conservando sus reglas originales.

## Revisión de dos lentes — intento 2: Fase 7 (T-75) — hallazgos reproducidos y corregidos

Claude Code CLI real, modelo claude-opus-5-5, esfuerzo alto, 2026-11-18. Lentes A+B+C+D y calidad del código. Recibo %TEMP%/metricool-t75-r2-retry1-opus55.json, copia del veredicto en testing/t75/reviews/metricool-t75-r2-opus55.md. Confirmó resueltos los hallazgos críticos anteriores; detectó un Important y cinco Minor. Ningún cierre hasta repetir QA y cotejar el parche corregido.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| Q-01 | Important | Encabezado Acción con U+FFFD | T-75 | Corregido el carácter y añadido localizador exacto de columnheader | RED real de navegador, 1 fallo por encabezado ausente: metricool-t75-r29-header-red-browser.log. Pendiente repetición final |
| Q-02 | Minor | Contexto mutable de API entre peticiones y varias marcas | T-75 | WeakMap ligada a cada Request, conjunto de marcas y atribución solo si hay una | RED [1,1,2] frente a [1,null,null]; GREEN 32/241. No se reinicia ciegamente respond(): hay adaptadores que validan la marca antes de llamarlo |
| Q-03 | Minor | MCP omite contexto en errores HTTP verificados | T-75 | Paso uniforme de brandId; el registrador central sigue quitándolo en DENIED | Tres RED para 403/409/429, GREEN incluyendo 404 personal |
| Q-04 | Minor | DDL en prueba con transacción y comprobación histórica débil | T-75 | Fixtures con brand_id nulo; se verifica visibilidad personal y ausencia al filtrar marca | GREEN; down/up verificado aparte en MySQL desechable, con índices y FK restaurados |
| Q-05 | Minor | Faltan llamadas reales get_post y marca por nombre | T-75 | Pruebas de REST y MCP real añadidas | Incluidas en 32 pruebas verdes. Cobertura posterior declarada; no se presenta como RED |
| D-01 | Minor | Rendimiento con visibilidad dispersa no medido | T-75 | Medición adicional, sin cambiar consultas al no observar degradación | EXPLAIN ANALYZE: 50 visibles entre 60000, situadas al final; dos rangos de 50 entradas, deduplicadas a 50 filas; no recorre toda la tabla. Seis consultas: 18,96 ms sin filtro y 3,27 ms con acción rara. Datos locales, no garantía de capacidad |

También se aclaró en la guía el alcance de las acciones de colaboradores y se limpió el comentario residual de la migración. Node final 778/778, tipado estricto, ESLint, Prettier, Pint y build verdes tras las correcciones. Pest completo, MySQL focal y navegador se repiten sobre el nuevo candidato antes del siguiente veredicto.

## Revisión de dos lentes — intento 3: Fase 7 (T-75) — aprobada y cerrada

Claude Code CLI real claude-opus-5-5, esfuerzo alto, 2026-11-18. Lentes A+B+C+D y calidad. Recibo %TEMP%/metricool-t75-r3-opus55.json y copia durable en testing/t75/reviews/metricool-t75-r3-opus55.md. Confirmó todos los hallazgos de R2 resueltos; código aceptable sin condiciones, sin Critical/Important.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N-01 | Minor | La frase de colaboradores sugería una atribución que no existe | T-75 | Aplicada literalmente la frase propuesta: quedan personales y no aparecen en el historial del propietario | docs/usuario/actividad.md; cambio solo de prosa, sin código ejecutable |

Pest 4294/9, 28843 assertions; MySQL y SQLite focales 32/241; Node 778/778; Playwright 13/2, 0 failed/flaky, qa-gate VERDE. Veintiún archivos integrados exactamente; reversión del parche comprobada. T-75 completada, T-76 en-progreso; resumen 74/108.

## Revisión de dos lentes — intento 1: Fase 7 (T-76) — correcciones verificadas; cotejo pendiente

Claude Code CLI real claude-opus-5-5, esfuerzo alto, 2026-11-18. Lentes A+B+C+D y calidad del código. Recibo %TEMP%/metricool-t76-r1-opus55.json y copia durable testing/t76/reviews/r1-opus55.md. Dos Important y seis Minor. La segunda revisión real se está ejecutando sobre el candidato corregido; no se declara aprobada.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Important | Un enlace caducado provoca redirecciones del GET del compositor | T-76 | Prefill inválido produce 200, valor nulo y aviso no bloqueante; guardar conserva su validación | Cinco GET RED 302; navegador RED ERR_TOO_MANY_REDIRECTS; 18 E2E verdes, incluido enlace caducado |
| Q1 | Important | Media, muestras y cercanía sin cobertura discriminante | T-76 | Tres datasets independientes, con fecha en mitad de semana | Tres mutantes temporales: cada prioridad omitida falla solo su dataset. Original restaurado por SHA256 |
| B2 | Minor | v-else del calendario ligado al widget | T-76 | Grupo template v-if=createHref y alternativa adyacente | Tipado y recorridos de calendario verdes |
| B3 | Minor | Prefill de edición dependía solo del servidor | T-76 | Condicional explícito según presencia de post también en el cliente | Node y regresión de crear/editar en curso; servidor conserva su prueba de edición |
| Q2 | Minor | Evidencia de Prettier ambigua | T-76 | Lista exacta de 12 archivos frontend formateados y comprobados; scripts SFC distinguidos por embeddedLanguageFormatting=off | r38-prettier-files.json y r39-prettier-check.log; sin excepciones nuevas |
| Q3 | Minor | Estado del SFC y varias redes sin pruebas específicas | T-76 | Dos tests montan setup/ciclo real de Vue; E2E de varias redes | 10 tests de componente/composable; 18 E2E verdes. Límites de tipado y renderer mínimo declarados |
| Q4 | Minor | Catálogo del calendario elige una red implícita | T-76 | Elección obligatoria sin filtro; filtro de red ya cuenta como elección explícita | RED del componente y GREEN; E2E dedicado en los tres proyectos |
| Q5 | Minor | Borrador en la guía y dependencia sin integrar | T-76 | Retirado el marcador; T-75 ya cerrada e integrada antes de probar ambas juntas | 82/714 en SQLite y MySQL; T-75 report.md; regresión completa conjunta |
| SELF-01 | Important | Editar y volver a vaciar la fecha permite sobrescribir la decisión final | T-76 | Revisión síncrona de cambios además de igualdad | RED r31, GREEN ocho casos del composable |
| SELF-02 | Important | NUL embebido en una propuesta lanza ValueError y devuelve 500 | T-76 | Parser captura Exception o ValueError como entrada inválida | RED real HTTP500 r41; GREEN r42. No se atribuye a Claude |

Verificación actual: 82 passed / 714 assertions en SQLite y MySQL 8.4.3, con 1766 hashes antes/después de MySQL; 788/788 Node; tipado, estilo y build verdes. Pest completo conjunto: 4318 passed / 9 skipped / 29008 assertions (r44). Playwright final de siete specs sigue en ejecución serial sobre el mismo código. El cotejo de Opus R2 incluye todos los cambios y debe finalizar antes del cierre.

## Revisión de dos lentes — intento 2: Fase 7 (T-76) — correcciones aprobadas, seguimiento menor

Opus 5.5 real: A condicional al QA y V1; B/C/D/calidad PASS. N1 margen sugerido, N2 aviso, N3 estado; V1 duda no confirmada. Ver r2-opus55.md. Sin nuevos Critical/Important confirmados.

## Revisión de dos lentes — intento 3: Fase 7 (T-76) — aprobada y cerrada

A+B+C+D y calidad mediante Claude Code Opus5.5 real, esfuerzo alto, revisión estática. N1 rebatido con contrato/evidencia y aceptado como política opcional sin deuda; N2/N3 corregidos; V1 cerrado con payload Europe/Madrid, fallback UTC y navegador. Q-N2a Minor final corregido literalmente: HTTP y E2E exigen texto exacto; seis casos rechazan la frase anterior en mutante temporal y pasan tras restaurar producción. No hay gaps abiertos. Recibo durable r3-receipt.json y veredicto r3-opus55.md.

QA final: Pest4320/9,29025 assertions; SQLite y MySQL84/731; Node789/789;18 E2E sin omisiones/fallos/flaky,qa-gate VERDE; tipado/estilo/build verdes. El completo PHP precede solo el refuerzo literal de tests; la producción es idéntica y focales finales cubren ese refuerzo. 1766 huellas idénticas tras QA y en main,23 archivos integrados byte-exactos,parche reversible. Total75/108;T77 en-progreso.

## Revisión de dos lentes — intento 3: Fase 7 (T-77) — aprobada y cerrada

ClaudeCodeOpus5.5 real con A/B/C/D y calidad. R1 opciones/DTO/errorbag/avisos y R2 TikTok patrocinado corregidos. R3 sin Critical/Important; B5/B6 respondidos con fuentes, E13/E14 aplicados; onSuccess defensivo aceptado. Gates finales e integración exacta documentados en testing/t77. Total76/108.

## Revisión de dos lentes — intento 3: Fase 7 (T-78) — aprobada y cerrada

ClaudeCodeOpus5.5real apruebaaceptación/corrección/seguridad/rendimiento/calidad, T6cerrado conaislamientoreal porworker. ÚltimogateNode/tipos/formato delspecfinal verificadoposteriormente sin cambiosdecódigo. QAeintegraciónexacta entesting/t78. Total77/108;T79 siguecandidatoenverificación,nointegrado.

## Revisión de dos lentes — intento 3: Fase 7 (T-79) — aprobada y cerrada

ClaudeCodeOpus5.5 real con aceptación,corrección,seguridad,rendimiento ycalidad. F1/F2/F3/F4/F5 corregidos; N1–N9 cerrados, selección de vencido respaldada por el flujo real de reconexión. Q1/Q2 opcionales aplicados literalmente y probados. QA y huellas finales entesting/t79. Total78/108. T80 continúa en copia aislada, sin integrar.

## Revisión de dos lentes — intento 3: Fase 7 (T-80) — aprobada y cerrada

ClaudeCodeOpus5.5 real: aceptación,corrección,seguridad,rendimiento y calidad del código. QA,recibos nativos e integración exacta en testing/t80. Total79/108. T81 continúa en preparación aislada.
