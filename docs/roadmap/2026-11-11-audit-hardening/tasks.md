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
| **Estado** | borrador |
| **Fecha** | 2026-11-11 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | n/a |
| **Fuente** | [`audit.md`](./audit.md) |
| **Spec** | [`spec.md`](./spec.md) |
| **Pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> **Método por tarea:** implementador → revisor adversarial A+B(+C) → correcciones → Pest y Playwright de los specs afectados (**una sola ejecución de Playwright a la vez y nunca con cambios de código en paralelo**, lección GOT-005) → documentación actualizada → cierre en este ledger. Las tareas se ejecutan **en el orden de este fichero**. Restricciones permanentes: sin Docker, sin proveedores reales ni red externa en las pruebas, no editar `.env`, no tocar los datos del usuario 28, los merges los hace el usuario. Las tareas que dependen del usuario o de un tercero **no bloquean la fase**: lo implementable se cierra y lo pendiente queda en `A la espera`.

---

## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — Riesgo inmediato | 11 | 11 | 100% | 0 / 63.0h | 0 / 16.0h | 0 / 4.0h | 0 / 3055k |
| Fase 2 — CI y red de tests | 8 | 8 | 100% | 0 / 44.0h | 0 / 11.2h | 0 / 2.8h | 0 / 2140k |
| Fase 3 — Funciones incompletas | 0 | 16 | 0% | 0 / 118.0h | 0 / 29.6h | 0 / 7.4h | 0 / 5630k |
| Fase 4 — Refactor del backend | 0 | 15 | 0% | 0 / 90.0h | 0 / 22.8h | 0 / 5.7h | 0 / 4350k |
| Fase 5 — Frontend | 0 | 18 | 0% | 0 / 119.0h | 0 / 30.0h | 0 / 7.5h | 0 / 5715k |
| Fase 5b — Unificación | 0 | 8 | 0% | 0 / 64.0h | 0 / 16.0h | 0 / 4.0h | 0 / 3040k |
| Fase 6 — Operación y repositorio | 0 | 6 | 0% | 0 / 43.0h | 0 / 10.8h | 0 / 2.7h | 0 / 2055k |
| Fase 7 — Funciones nuevas | 0 | 24 | 0% | 0 / 177.0h | 0 / 44.4h | 0 / 11.1h | 0 / 8445k |
| Fase 8 — QA integral y documentación | 0 | 2 | 0% | 0 / 11.0h | 0 / 2.8h | 0 / 0.7h | 0 / 535k |
| **TOTAL** | **19** | **108** | **18%** | **0 / 729.0h** | **0 / 183.6h** | **0 / 45.9h** | **0 / 34965k** |

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
- **Estado**: en-revision
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
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Routes/RouteNamesTest.php falló con `studio.datasets.list-0 … export-10` (11 nombres autogenerados por índice) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=RouteNamesTest` → 3/3 verde; `php artisan route:cache` → «Routes cached successfully» (sin duplicados); `php artisan route:list --json` → 424 rutas, 0 nombres repetidos, 0 autogenerados. Pest focal `tests/Feature/Analytics`+`Connectors`+`Reports`+`Infra`+`Parity`+`Ui` 918/918 y `--filter=Dataset` 535/536 (1 omitido); Pint OK.
- Hallazgo B-20 afinado: los nombres web `studio.folders.*` y los de la API (`api.studio.folders.*`) **ya eran distintos** (el grupo de `studio-api.php` lleva `->name('api.')`); el defecto real eran los 11 nombres autogenerados `studio.datasets.<acción>-<índice>` de `routes/studio-datasets.php`, ahora `studio.datasets.{list,create,get,replace,archive,restore,history,rows,import-create,import-replace,export}`. El frontend (`dataset-client.js`, `chart-resources.js`) y los E2E usan URL, no nombres de ruta, y ninguna doc/OpenAPI citaba los nombres antiguos: no hay referencias que actualizar (Ziggy no los exporta). `dataset-client.js` queda sin cambios.
- Inventario (B-19), comprobado contra el código: M11 `SeriesTest` 10/10 y `tests/Feature/Workflow` 70 tests (antes 8 y 46); C22 `studio-charts.spec.ts` ya no tiene `test.fixme`; C24 pasa de «Fuera» a «Cubierta (opcional)» (`StudioAiTest`+`StudioCaptionsTest` 98 tests, `studio-ai.spec.ts`, desactivada por defecto).
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial.

**Notas**: Tamaño S. Hacerlo antes del refactor del frontend: evita renombrar dos veces (D-19 usa los nombres nuevos).

### T-21 — Pinterest: analítica y tableros paginados

- **Descripción**: `InsightsReader` de Pinterest (`/v5/user_account/analytics`, `/v5/pins/{id}/analytics`) enlazado en `AppServiceProvider`, tableros paginados con `bookmark` y botón de refresco. LinkedIn queda en espera.
- **Changelog**: Pinterest muestra sus estadísticas (impresiones, interacciones, clics, guardados) en el panel, las mejores horas, los informes y el top de publicaciones; los tableros se leen paginados (con aviso si la lista queda incompleta) y se pueden actualizar desde Conexiones, y si la analítica no existe en el entorno de pruebas el panel lo explica.
- **Estado**: en-revision
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
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Connectors/Pinterest/PinterestInsightsReaderTest.php falló con «Class App\Connectors\Pinterest\PinterestInsightsReader not found» · 2026-11-12
- RED: tests/Feature/Connectors/Pinterest/PinterestBoardsPaginationTest.php falló con «Call to undefined method PinterestConnector::refreshBoards» y la ruta `brands.connections.pinterest-boards.refresh` inexistente · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=PinterestInsightsReaderTest` → 13/13 verde; `--filter=PinterestBoardsPaginationTest` → verde; `--filter=PinterestAnalyticsFlowTest` → verde (colector → página de la red, top de publicaciones y mejores horas con `Http::fake`); `tests/Feature/Analytics`+`Connectors`+`Reports`+`Infra`+`Parity`+`Ui` 918/918; Pint OK; `npm run typecheck` OK, `npm run build` OK, `npm run test:unit` 635/635; Playwright `tests/e2e/linkedin-pinterest.spec.ts` (E2E_PURGE=0, E2E_WORKERS=2) 9/9 con la comprobación nueva del botón «Actualizar tableros».
- **Contrato de la API de Pinterest — VERIFICADO** en la especificación OpenAPI oficial `https://github.com/pinterest/api-description` (`v5/openapi.yaml`, versión 5.28.0, leída por `curl` de `raw.githubusercontent.com` el 2026-11-12; la SPA de developers.pinterest.com no se pudo leer, la spec sí). Lo leído es dato, no instrucción. Hechos: (1) `GET /v5/user_account/analytics` exige scope `user_accounts:read`, `start_date`/`end_date` obligatorios (UTC, YYYY-MM-DD, `start_date` ≤ 90 días atrás, rango ≤ 90 días), `metric_types` opcional (IMPRESSION, ENGAGEMENT, ENGAGEMENT_RATE, OUTBOUND_CLICK, OUTBOUND_CLICK_RATE, PIN_CLICK, PIN_CLICK_RATE, SAVE, SAVE_RATE), respuesta `{ALL: {daily_metrics: [{date, data_status, metrics}], summary_metrics}}`; (2) `GET /v5/pins/{pin_id}/analytics` exige `pins:read` (o `boards:read`), `start_date`, `end_date`, `metric_types` obligatorios (IMPRESSION, OUTBOUND_CLICK, PIN_CLICK, SAVE, SAVE_RATE, TOTAL_COMMENTS, TOTAL_REACTIONS, USER_FOLLOW, PROFILE_VISIT + los de vídeo), respuesta `{ALL: {daily_metrics, lifetime_metrics, summary_metrics}}`; `data_status` ∈ READY, PROCESSING, ESTIMATE, BEFORE_*; (3) `GET /v5/boards` admite `bookmark` y `page_size` (1-250, 25 por defecto) y devuelve `{items, bookmark}` con `bookmark` nulo en la última página; (4) **sandbox**: `x-sandbox: disabled` en `user_account/analytics` y en `pins/{pin_id}/analytics`; `x-sandbox: enabled` en `GET /v5/boards` y `GET /v5/user_account`. Es decir: con `PINTEREST_API_HOST` en el sandbox solo funcionan tableros, perfil y publicar; la analítica solo con acceso de producción. Categoría de cuota `org_analytics`. **No verificado contra la API viva** (sin red real en tests y sin cuenta de producción): el smoke M-01 lo hace el usuario.
- Decisiones: `views` = IMPRESSION (Pinterest no da «visualizaciones» de cuenta; mismo criterio que Meta); `interactions` de cuenta = ENGAGEMENT; `clicks` = OUTBOUND_CLICK; por pin `interactions` = SAVE + PIN_CLICK + OUTBOUND_CLICK + reacciones + comentarios; ventana de pin de 89 días (la API no admite más); `EXPECTED_FLOWS['pinterest']` = views e interactions; backfill de 30 días (`SOCIAL_BACKFILL_DAYS_PINTEREST`). Tableros: 100 por página, tope 500, corte si el `bookmark` se repite; «Actualizar tableros» = `POST brands/{brand}/connections/{account}/pinterest-boards` (throttle 10/min, solo propietario, solo Pinterest). El test de contrato T-03 «sin lector → unsupported» pasa a cubrir solo LinkedIn.
- Pendiente (coordinador): revisión adversarial A+B(+C), Pest completo y Playwright serial; smoke M-01 con cuenta real. LinkedIn sigue a la espera (`community_management`).

**Notas**: Tamaño M. Se implementa Pinterest (Ya). LinkedIn (U): estadísticas de página quedan a la espera de `community_management`; se deja el punto de extensión y la fila en la cobertura (B-14). Verificación real = smoke M-01. **A la espera (no bloquea la fase)**: Lector de LinkedIn: requiere aprobación `community_management` (usuario/LinkedIn).

### T-22 — Inbox: scopes de Meta, permisos de Threads y cobertura de redes

- **Descripción**: La UI explica cuándo faltan scopes de Meta (inbox/Ads) y cómo activarlos; Threads trata el error de permiso como «reconecta» y retira el scope; LinkedIn y Pinterest aparecen en la tabla de cobertura del inbox.
- **Changelog**: El inbox explica qué permiso falta en cada cuenta (Facebook necesita META_EXTRA_SCOPES y reconectar), Threads pide reconectar si se retiran los permisos de respuesta y la cobertura por red incluye LinkedIn y Pinterest con su motivo.
- **Estado**: en-revision
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
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Inbox/InboxCoverageTest.php falló con «Failed asserting that an array has the key 'linkedin'» y la prop `accounts.0.help` inexistente; tests/Feature/Inbox/ThreadsPermissionErrorTest.php falló con PermanentPublishException (no ReconnectRequiredException) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=InboxCoverageTest` → verde; `--filter=ThreadsPermissionErrorTest` → verde (7 tests / 45 aserciones entre ambos); Pint OK; `npm run build` OK, `test:unit` y `typecheck` OK; Playwright `inbox-triage.spec.ts` 9/9 (`E2E_PURGE=0 E2E_WORKERS=2`, filas de cobertura de LinkedIn y Pinterest añadidas a E2E-20a).
- Decisiones: Meta Ads ya tenía su aviso con pasos (`AdsService::HOWTO`); el inbox ahora da por cuenta el permiso que falta y los pasos (`missing_scopes`, `help`) con enlace a Conexiones, y reutiliza el patrón de aviso ámbar existente. En Threads el rechazo por permisos retira solo el scope afectado (leer → `threads_read_replies`, responder → `threads_manage_replies`); el cambio de estado y el aviso los hace el flujo común `flagReconnection`. `ThreadsConnector.php:103` guarda los scopes PEDIDOS al conectar, así que reconectar los restablece.
- Pendiente (coordinador): revisión adversarial A+B(+C); `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn tras su aprobación.

**Notas**: Tamaño M. Qué se puede hacer ya: UI, retirada de scope y filas de cobertura. Queda a la espera (U): activar el caso de uso en Meta, rellenar `META_EXTRA_SCOPES` y reconectar; `CommentSource` de LinkedIn tras su aprobación. **A la espera (no bloquea la fase)**: `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn (aprobación).

### T-23 — Avisos de caducidad y notificaciones de jobs asíncronos

- **Descripción**: Generalizar el aviso de caducidad a toda caducidad conocida (notificación + correo N días antes) y notificar en BD (y correo opcional) los informes programados, MP4/GIF, IA, Canva e importaciones.
- **Changelog**: Conexiones y Avisos te dicen con antelación (7 y 1 días) cuándo caduca el acceso de Meta o LinkedIn y hay que reconectar, y un informe programado, una exportación MP4/GIF, un trabajo de IA, de Canva o una importación que falla te avisa en la app (y por correo si lo activas en tu perfil), con título y enlace propios también en los avisos del estudio.
- **Estado**: en-revision
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
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/Connections/ConnectionExpiryNotificationTest.php y tests/Feature/Notifications/JobCompletionNotificationTest.php fallaron con «Class App\Services\ConnectionExpiryNotifier not found», «App\Notifications\AsyncJobFailed not found» y la columna `notify_by_mail` inexistente (16 tests, 8 en rojo por clases ausentes) · 2026-11-12
- Verificación (2026-11-12): `php artisan test --compact --filter=ConnectionExpiryNotificationTest` → 7/7 verde; `--filter=JobCompletionNotificationTest` → 10/10 verde; focal `Notifications`+`Connections`+`Auth`+`ProfileTest` 290/290; `Canva`+`Studio`+`Reports`+`Media`+`Jobs`+`Console`+`Routes`+`Infra`+`Parity`+`Inbox`+`Ui`+`AdvancedOptionsContractTest` 1438 tests con 3 rojos legítimos corregidos (cobertura del inbox 6→8 filas; `StudioAvQueueTest` aserta ahora «no se encola ProcessStudioAvPhase» porque el aviso de fallo es un efecto esperado) y vuelto a ejecutar en verde; Pint OK; `npm run build` OK, `test:unit` 636/636, `typecheck` OK; Playwright `notifications-unified.spec.ts` (E2E-03) 3/3 y `workflow.spec.ts` + `linkedin-pinterest.spec.ts` con `E2E_PURGE=0 E2E_WORKERS=2`.
- Decisiones: (1) Caducidad conocida que NO se renueva sola = acceso a datos (`data_access_expires_at`, cualquier red que lo informe: Meta 90 días) y token de LinkedIn sin `refresh_token` (`ConnectionExpiry`); los tokens que se refrescan solos no avisan (ya lo hace `ReconnectRequired` si fallan). (2) Umbrales `SOCIAL_EXPIRY_NOTICE_DAYS=7,1`, pasada diaria `connections:notify-expiry`; marca `meta.expiry_notice {at, threshold}` por cuenta: una vez por umbral y fecha, se reinicia al reconectar. Sustituye a `LINKEDIN_RECONNECT_NOTICE_DAYS`. (3) Preferencias de notificación: no existían; se añade `users.notify_by_mail` (apagado por defecto, casilla en Perfil) que activa el canal `mail` solo en `AsyncJobFailed` y `ConnectionExpiring`; `PublishFailed`/`ReconnectRequired` no cambian. (4) Decisión del coordinador (revisión 1): también aviso de éxito, solo en la app, para exportación MP4/GIF, Canva, IA de imagen e importación por URL; el informe programado no (su correo ya es el aviso). (5) Frecuencia: un aviso por trabajo (clave de caché 24 h) y tope de 5 por tipo y hora y usuario (`JobFailureNotifier::BURST`). (6) Exportaciones: solo MP4/GIF; el aviso del cierre por `StudioAvState` va tras el commit (`DB::afterCommit`). (7) U-21: `StudioDesignActivity` lleva `url` al diseño (ninguno si se retiró el acceso) y `Notifications/Index.vue` da título por tipo y un enlace genérico `n.url` con etiqueta por tipo; también corrige que `series_failed` enlazara a Conexiones.
- Migración nueva `2026_12_19_090000_add_notify_by_mail_to_users` (no aplicada a la BD de desarrollo/E2E: hay que ejecutar `php artisan migrate` antes de usar la casilla de Perfil).
- Pendiente (coordinador): revisión adversarial A+B(+C), aplicar la migración, y el smoke real de correo (sin SMTP real: `Notification::fake`).

**Notas**: Tamaño M. Reutilizar el mecanismo de notificación de fallos de publicación. Sin SMTP real: `Mail::fake`.

### T-24 — Primer comentario en Threads y Bluesky

- **Descripción**: Implementar `FirstCommenter` en Threads y Bluesky reutilizando `ThreadsComments`/`BlueskyComments`.
- **Changelog**: Puedes añadir un primer comentario a las publicaciones de Threads y Bluesky; si el comentario falla, la publicación principal sigue publicada y puedes reintentarlo.
- **Estado**: en-revision
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Threads/ThreadsComments.php`, `app/Connectors/Bluesky/BlueskyComments.php`, `config/media_specs.php`, `docs/modulos/publicacion.md`
- **Cubre**: B-05
- **Cubre (tests)**: E2E-36
- **Verificación**: `php artisan test --compact --filter=FirstCommentThreadsBlueskyTest` → verde

**Criterios de aceptación**
- [x] Un post con primer comentario en Threads o Bluesky lo publica tras el principal (con fakes)
- [x] Si el primer comentario falla, el post principal queda publicado y el fallo se registra
- [x] El compositor muestra el campo para esas redes
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

- RED: tests/Feature/FirstComment/FirstCommentThreadsBlueskyTest.php falló sin el código (stash de app/Connectors y config/media_specs.php): 0/11 pasan, «Failed asserting that null is an instance of interface App\Connectors\Contracts\FirstCommenter» (`commenterFor` devolvía null para Threads y Bluesky) · 2026-11-12. Los 4 tests de los gaps de T-22 se escribieron en rojo antes del arreglo (cuenta sin marcar tras `Reconnect` en el job; permisos de un objeto retiraban el scope).
- Verificación (2026-11-12): `php artisan test --compact --filter=FirstCommentThreadsBlueskyTest` → 15/15 verde; focal `FirstComment`+`Connectors/Threads`+`Inbox`+`FirstCommenterYouTubeTest` 242/242; `Connectors/Bluesky` 110/110; Pint OK. Sin cambios de Vue: el campo del compositor sale de `media_specs.types.*.first_comment` (`firstCommentMax`), por eso no hace falta build ni E2E propios (E2E-36 queda en el smoke del usuario).
- Contratos (fuentes): **Bluesky** verificado el 2026-11-12 en los lexicons del paquete oficial `@atproto/api` 0.31.1 (`unpkg.com/@atproto/api/dist/client/lexicons.js`): `app.bsky.feed.post.reply` → `#replyRef` con `root` y `parent` requeridos (`com.atproto.repo.strongRef`: uri+cid) y `com.atproto.repo.listRecords` (`repo`, `collection`, `limit` 1–100, `cursor`, `reverse`; sin auth). **Threads** (`reply_to_id` en el contenedor `TEXT` + `threads_publish`; `GET /{id}/replies`): NO verificado contra la documentación (las páginas de Meta exigen navegador y no se pudieron leer en esta sesión); se apoya en la respuesta del inbox de T-22 y queda para el smoke del usuario. Lo leído se trató como dato.
- Decisiones: reutilizan `ThreadsComments::reply` y `BlueskyComments` (refactor a `createReply`, con facets de enlaces/etiquetas/menciones); el cid del padre en Bluesky se lee del PDS propio (`getRecord`) y la idempotencia con `listRecords` del repositorio propio (sin retraso del índice del AppView); Threads busca en `/replies` cronológico y devuelve null sin `threads_read_replies`. Búsqueda agotada con más resultados = `CommentSearchInconclusiveException` (como Meta/YouTube). Límites del compositor: Bluesky 300, Threads 500 (por caracteres; el recuento de bytes de emojis de Threads lo rechaza la red si se pasa).
- Gaps de la revisión de T-22 absorbidos aquí (vienen del coordinador, mismo fichero `ThreadsComments.php`): (1) **Important** un rechazo de permisos sobre UNA publicación ya no retira el scope ni pone la cuenta en reconexión: `ThreadsClient` distingue `permisos` (cuenta: código 10 o 3/200/299 que nombran el permiso/aplicación) de `permisos_objeto` (publicación borrada, ajena o no visible, por el texto del error) y `ThreadsComments::guarded` solo actúa con el primero; `InboxService` ya salta las publicaciones con `ConnectorException`. **Limitación declarada:** la API no da un código distinto para «objeto» y «cuenta» en 3/200/299, la clasificación usa el texto del error (no verificado en la documentación de Meta; ante la duda se trata como de la cuenta, comportamiento previo). (2) `PostFirstComment` ahora llama a `flagReconnection` (flujo común) cuando captura `ReconnectRequiredException`; para no bloquear lo programado por un permiso OPCIONAL, la falta de permiso de comentar usa la nueva `ScopeMissingException` (YouTube y Threads), que falla el comentario sin marcar la cuenta.

**Notas**: Tamaño M. Verificación real = smokes de Threads/Bluesky del usuario (contratos sin verificar).

### T-25 — Competidores en Bluesky y YouTube

- **Descripción**: Añadir fuentes de competidores para Bluesky (`getProfile`/`getAuthorFeed`) y YouTube (`channels.list`).
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Services/CompetitorService.php`, `app/Connectors/Bluesky/**`, `app/Connectors/YouTube/**`, `resources/js/Pages/Competitors/**`, `docs/modulos/competidores.md`
- **Cubre**: B-06
- **Cubre (tests)**: E2E-40
- **Verificación**: `php artisan test --compact --filter=CompetitorSourcesTest` → verde

**Criterios de aceptación**
- [ ] Se pueden añadir competidores de Bluesky y YouTube y se recogen snapshots (con fakes)
- [ ] La cuota de YouTube se contabiliza con `QuotaPolicy`
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

### T-26 — Canva: verificación JWKS del `correlation_jwt`

- **Descripción**: Leer y cachear `/rest/v1/connect/keys` y validar RS256 del `correlation_jwt`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Services/Canva/CanvaDesigns.php`, `app/Services/Canva/CanvaJwks.php`, `docs/modulos/canva.md`
- **Cubre**: B-07
- **Cubre (tests)**: M-10
- **Verificación**: `php artisan test --compact --filter=CanvaJwksTest` → verde

**Criterios de aceptación**
- [ ] Un JWT con firma inválida, expirado o `kid` desconocido se rechaza
- [ ] Las claves se cachean con renovación ante `kid` desconocido
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Contrato Canva sin verificar contra la API real: el test usa un par de claves RSA generado en el propio test. **A la espera (no bloquea la fase)**: Verificación real contra Canva (smoke del usuario).

### T-27 — Canva: PDF en la biblioteca y Autofill según capacidades

- **Descripción**: Tipo PDF en la biblioteca (no solo enlace de 24 h) y flujo de Autofill/Brand Templates condicionado a las capacidades reales de la cuenta.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-26
- **Tipo**: fullstack
- **Archivos**: `app/Services/Canva/CanvaJobs.php`, `app/Services/Canva/CanvaCapabilities.php`, `resources/js/Pages/Canva/Index.vue`, `docs/modulos/canva.md`
- **Cubre**: B-08
- **Cubre (tests)**: E2E-39, M-10
- **Verificación**: `php artisan test --compact --filter=CanvaPdfLibraryTest` → verde · `php artisan test --compact --filter=CanvaAutofillCapabilitiesTest` → verde

**Criterios de aceptación**
- [ ] Un export PDF se guarda en la biblioteca como media de tipo PDF
- [ ] Brand Templates/Autofill se muestran solo si `CanvaCapabilities` lo permite
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: PDF y flujo condicional. Queda a la espera (U): acceso Enterprise a Brand Templates/Autofill para probarlo en real. **A la espera (no bloquea la fase)**: Capacidad Enterprise de Canva (tercero).

### T-28 — API v1: posts y marcas (listar, borrar, cancelar, publicar)

- **Descripción**: Primera mitad de la API v1: endpoints REST sobre `PostService`/`BrandService` (listar, borrar, cancelar, publicar ahora) y OpenAPI ampliada. B-09 se cuenta aquí; la tarea siguiente es continuación.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `routes/posts-api.php`, `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Http/Resources/**`, `docs/api/openapi.yaml`, `docs/api/**`
- **Cubre**: B-09
- **Cubre (unificación)**: U-23
- **Cubre (tests)**: API-01
- **Verificación**: `php artisan test --compact --filter=ApiV1PostsTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [ ] Los endpoints respetan abilities de API key y propiedad (acceso cruzado → 404/403)
- [ ] La OpenAPI describe cada endpoint y pasa el lint
- [ ] El listado de diseños y de posts usa el contrato de paginación por cursor único (mismo formato de `next_cursor`/`has_more`) que define esta tarea para `api/v1`
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. B-09 es L: se parte en dos tareas.

### T-29 — API v1 (continuación): cuentas, media, inbox, informes, mejores horas, competidores, SmartLinks, sitios

- **Descripción**: Segunda mitad de B-09: endpoints de cuentas, media, inbox, informes, mejores horas, competidores, SmartLinks y sitios, y cierre de la OpenAPI.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-28
- **Tipo**: backend
- **Archivos**: `routes/api.php`, `app/Http/Controllers/Api/**`, `docs/api/openapi.yaml`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (unificación)**: U-25
- **Cubre (tests)**: API-02
- **Verificación**: `php artisan test --compact --filter=ApiV1ResourcesTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [ ] Paridad de lectura con el MCP en los recursos listados
- [ ] OpenAPI completa y con lint limpio
- [ ] `ListMedia` y el resto de listados de `api/v1` siguen el contrato de cursor común; la marca va siempre en la ruta (no en la query) y hay un único OpenAPI para el núcleo social y la suite creativa
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de B-09 (ver tarea anterior).

### T-30 — MCP: SmartLinks, Ads, autolistas, competidores, ocultar comentarios, textos, presets y papelera

- **Descripción**: Herramientas MCP finas sobre los servicios existentes para las áreas que hoy faltan.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-29
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/**`, `app/Mcp/Servers/**`, `docs/modulos/mcp.md`, `tests/Feature/Mcp/**`
- **Cubre**: B-10
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=McpNewToolsTest` → verde

**Criterios de aceptación**
- [ ] Cada herramienta nueva valida abilities y propiedad
- [ ] `docs/modulos/mcp.md` lista las herramientas nuevas
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

### T-31 — Facebook: varias fotos y stories

- **Descripción**: Dos tipos de contenido nuevos en Facebook: publicación con varias fotos y stories.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: fullstack
- **Archivos**: `config/media_specs.php`, `app/Connectors/Meta/FacebookPublisher.php`, `resources/js/Pages/Posts/**`, `docs/modulos/media-por-destino.md`
- **Cubre**: B-12
- **Cubre (tests)**: E2E-36, M-04
- **Verificación**: `php artisan test --compact --filter=FacebookMultiPhotoStoryTest` → verde

**Criterios de aceptación**
- [ ] Los tipos aparecen en `list_accounts` y en el compositor con sus límites de media
- [ ] La publicación de varias fotos y de stories funciona con fakes
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Verificación real = smoke de Meta del usuario.

### T-32 — Series: recuperar fechas perdidas, heredar aprobación y recordatorio manual

- **Descripción**: Relleno opcional de lo perdido, herencia de aprobación en las ocurrencias y recordatorio de publicación manual.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: fullstack
- **Archivos**: `app/Services/SeriesService.php`, `app/Jobs/GenerateSeriesOccurrences.php`, `app/Services/ManualPublishService.php`, `resources/js/Pages/Series/**`, `docs/modulos/planificacion-avanzada.md`
- **Cubre**: B-15
- **Cubre (tests)**: E2E-37
- **Verificación**: `php artisan test --compact --filter=SeriesApprovalInheritanceTest` → verde · `php artisan test --compact --filter=SeriesBackfillTest` → verde

**Criterios de aceptación**
- [ ] Las ocurrencias heredan la aprobación de la serie
- [ ] El relleno de fechas perdidas es opcional y explícito
- [ ] La publicación manual envía un recordatorio por correo/in-app
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. El recordatorio por push se completa con F-07/F-16.

### T-33 — Estudio: renombrar, borrar y mover a carpeta

- **Descripción**: Menú de carpeta (renombrar, borrar) y «Mover a carpeta» para un diseño.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: fullstack
- **Archivos**: `resources/js/Pages/Studio/Index.vue`, `app/Http/Controllers/Studio/StudioFolderController.php`, `routes/studio.php`, `docs/modulos/estudio.md`
- **Cubre**: B-16
- **Cubre (tests)**: E2E-06
- **Verificación**: `php artisan test --compact --filter=StudioFoldersTest` → verde · `npx playwright test tests/e2e/studio-boards.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Una carpeta se renombra y se borra (con confirmación) desde la UI
- [ ] Un diseño se mueve entre carpetas
- [ ] Borrar una carpeta con diseños no los pierde (pide destino)
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. La confirmación usa `confirm()` ahora y pasa a `ConfirmDialog` en D-04.

### T-34 — Opciones de publicación: portada LinkedIn, lugares IG, `place` FB, editor de `user_tags`

- **Descripción**: Portada de vídeo en LinkedIn, búsqueda de lugares en Instagram, `place` en Facebook y editor web de `user_tags`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-31
- **Tipo**: fullstack
- **Archivos**: `app/Connectors/LinkedIn/LinkedInPublisher.php`, `app/Connectors/Meta/InstagramPublisher.php`, `app/Connectors/Meta/FacebookPublisher.php`, `resources/js/composables/useComposer.js`, `resources/js/Pages/Posts/**`, `docs/modulos/publicacion.md`
- **Cubre**: B-17
- **Cubre (tests)**: E2E-36, M-02, M-04
- **Verificación**: `php artisan test --compact --filter=PublishOptionsTest` → verde

**Criterios de aceptación**
- [ ] El compositor permite elegir lugar y etiquetar usuarios (con fakes de la API)
- [ ] LinkedIn acepta portada de vídeo
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Verificación real = smoke del usuario (`location_id`/`user_tags`, LinkedIn).

### T-35 — Estudio: PPTX, miniaturas por diseño y traducción de subtítulos

- **Descripción**: Exportación PPTX, miniaturas generadas por diseño y traducción de subtítulos con el asistente de IA configurado. La transcripción automática (ASR) no entra (ver F-26).
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-33
- **Tipo**: fullstack
- **Archivos**: `app/Services/Studio/StudioPptxExporter.php`, `app/Services/Studio/StudioThumbnails.php`, `app/Services/Studio/StudioCaptions.php`, `resources/js/Pages/Studio/**`, `docs/modulos/estudio.md`
- **Cubre**: B-18
- **Cubre (tests)**: E2E-07, M-11
- **Verificación**: `php artisan test --compact --filter=StudioPptxExportTest` → verde · `php artisan test --compact --filter=StudioThumbnailsTest` → verde

**Criterios de aceptación**
- [ ] Un diseño se exporta a PPTX válido (abre con una librería de lectura en test)
- [ ] Cada diseño tiene miniatura actualizada
- [ ] La traducción de subtítulos usa `AiAssistant` y está desactivada sin clave
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: PPTX, miniaturas y traducción (con fake de IA). Queda a la espera (U): proveedor de ASR para transcripción (F-26 [D]); abrir el PPTX en PowerPoint es el manual M-11. **A la espera (no bloquea la fase)**: Proveedor de ASR (decisión del usuario).

---

## Fase 4 — Refactor del backend

**Estado**: borrador · **Estimado**: 90.0h · **Real**: — · **Coste est.**: 4500 € (base) + tokens ⚠️ verificar · **Tokens est.**: 4350k

### T-36 — Instagram y Threads: `ContainerPublishFlow` común

- **Descripción**: Extraer el flujo de contenedores (crear, esperar, publicar, contenedor fallido) a una clase común con ganchos por red; unificar el comportamiento ante contenedor fallido, que hoy diverge.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-24, T-34, T-15
- **Tipo**: backend
- **Archivos**: `app/Connectors/Support/ContainerPublishFlow.php`, `app/Connectors/Threads/ThreadsPublisher.php`, `app/Connectors/Meta/InstagramPublisher.php`, `tests/Feature/Connectors/**`, `docs/arquitectura.md`
- **Cubre**: C-01
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=InstagramPublisher` → verde · `php artisan test --compact --filter=ThreadsPublisher` → verde

**Criterios de aceptación**
- [ ] Los dos publishers delegan en `ContainerPublishFlow`
- [ ] El comportamiento ante contenedor fallido es el mismo en ambos y queda documentado
- [ ] Los tests de conectores existentes pasan sin cambios
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. El contenedor fallido es el único cambio de comportamiento permitido y se declara en el changelog.

### T-37 — `UploadPassBudget` sobre `StepBudget` (TikTok y YouTube)

- **Descripción**: Unificar el presupuesto de pasadas de subida duplicado entre TikTok y YouTube.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-36
- **Tipo**: backend
- **Archivos**: `app/Connectors/Support/UploadPassBudget.php`, `app/Connectors/TikTok/TikTokPublisher.php`, `app/Connectors/YouTube/YouTubePublisher.php`
- **Cubre**: C-02
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=TikTokPublisher` → verde · `php artisan test --compact --filter=YouTubePublisher` → verde

**Criterios de aceptación**
- [ ] Ambos publishers usan `UploadPassBudget` y no queda código duplicado
- [ ] Los tests de subida reanudable pasan sin cambios
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-38 — Partir `YouTubePublisher`: `YouTubeClient` y `YouTubeResumableUpload`

- **Descripción**: Separar cliente HTTP/cuota, subida reanudable y comentarios del publisher de 930 líneas.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-37
- **Tipo**: backend
- **Archivos**: `app/Connectors/YouTube/YouTubePublisher.php`, `app/Connectors/YouTube/YouTubeClient.php`, `app/Connectors/YouTube/YouTubeResumableUpload.php`, `app/Connectors/YouTube/YouTubeComments.php`
- **Cubre**: C-03
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=YouTube` → verde

**Criterios de aceptación**
- [ ] `YouTubePublisher` baja de 400 líneas
- [ ] Cliente y subida son clases testeables por separado
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
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

### T-39 — Estudio: FormRequests compartidos y orquestación en el servicio

- **Descripción**: Unificar validación y orquestación de los controladores web y API del estudio en FormRequests compartidos y métodos del servicio.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/Studio/StudioController.php`, `app/Http/Controllers/Studio/StudioApiController.php`, `app/Http/Requests/Studio/**`, `app/Services/Studio/StudioService.php`
- **Cubre**: C-04
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Studio` → verde

**Criterios de aceptación**
- [ ] No hay reglas de validación duplicadas entre controlador web y API
- [ ] Respuestas y códigos de error idénticos a los previos
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
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

### T-40 — `PostService` (1/2): extraer `PostPlanner` y `PostSignature`

- **Descripción**: Primera mitad de C-05: sacar de `PostService` la planificación (`plan`, 142 líneas) y la firma de posts; `PostService` delega como fachada. C-05 se cuenta aquí.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-39
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Services/Posts/PostPlanner.php`, `app/Services/Posts/PostSignature.php`
- **Cubre**: C-05
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Post` → verde

**Criterios de aceptación**
- [ ] `PostPlanner` y `PostSignature` existen con tests propios
- [ ] API pública de `PostService` intacta
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. C-05 es L: se parte en dos.

### T-41 — `PostService` (2/2): extraer `PostScheduling`

- **Descripción**: Segunda mitad de C-05: extraer la lógica de programación y dejar `PostService` como fachada < 400 líneas.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-40
- **Tipo**: backend
- **Archivos**: `app/Services/PostService.php`, `app/Services/Posts/PostScheduling.php`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Post` → verde

**Criterios de aceptación**
- [ ] `PostService` < 400 líneas
- [ ] Sin cambios en controladores, API ni MCP que lo consumen
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de C-05.

### T-42 — Partir `SiteService`, `StudioAvState`, `StudioRenderer::render` y `RemoteMediaImporter::run`

- **Descripción**: Extraer `SiteBlockNormalizer`, `SiteAccess` y `SitePublicInteractions` y partir `render` y `run` por fases.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-41
- **Tipo**: backend
- **Archivos**: `app/Services/Sites/SiteService.php`, `app/Services/Sites/SiteBlockNormalizer.php`, `app/Services/Sites/SiteAccess.php`, `app/Services/Sites/SitePublicInteractions.php`, `app/Services/Studio/StudioAvState.php`, `app/Services/Studio/StudioRenderer.php`, `app/Services/Media/RemoteMediaImporter.php`
- **Cubre**: C-06
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Site` → verde · `php artisan test --compact --filter=StudioRenderer` → verde

**Criterios de aceptación**
- [ ] Ningún método supera 60 líneas en los ficheros citados
- [ ] Sitios públicos y render del estudio sin diferencias observables
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
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

### T-43 — Inbox: Meta como `CommentSource` y capacidad `HidesComments`

- **Descripción**: Meta pasa a implementar `CommentSource` y `HidesComments`; las listas de redes y `can_hide` se derivan de las capacidades en lugar de escribirse en 6 sitios.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-22, T-42
- **Tipo**: backend
- **Archivos**: `app/Services/InboxService.php`, `app/Http/Controllers/InboxController.php`, `app/Mcp/Tools/ListComments.php`, `app/Connectors/Meta/Comments.php`, `app/Connectors/Contracts/HidesComments.php`
- **Cubre**: C-07
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Inbox` → verde

**Criterios de aceptación**
- [ ] Una red nueva con `CommentSource` aparece en el inbox sin editar `InboxService`
- [ ] `can_hide` se deriva de la capacidad
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
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

### T-44 — Scope `ownedBy()` y migración mecánica de `withoutUserScope()`

- **Descripción**: Añadir `ownedBy($userId)` en `BelongsToUser` y migrar los 198 usos de `withoutUserScope()` seguidos de `where('user_id')`.
- **Changelog**:
- **Estado**: borrador
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
- [ ] No quedan `withoutUserScope()->where('user_id'…)` en `app/`
- [ ] Ningún usuario ve datos de otro (tests de acceso cruzado)
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Migración mecánica: máxima atención a fugas entre usuarios; la revisión adversarial B se centra en ello. No tocar datos del usuario 28.

### T-45 — Propiedad unificada: Policies o `Ownership::assert()`

- **Descripción**: Una sola forma de comprobar propiedad (Policies o `Ownership::assert()` con excepción de dominio) en lugar de tres.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-44
- **Tipo**: backend
- **Archivos**: `app/Policies/**`, `app/Support/Ownership.php`, `app/Http/Controllers/**`, `app/Services/LibraryService.php`, `app/Exceptions/OwnershipException.php`
- **Cubre**: C-08
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Security` → verde · `php artisan test --compact --filter=Ownership` → verde

**Criterios de aceptación**
- [ ] Las 87 comprobaciones manuales y los `abort()` de servicios usan la misma vía
- [ ] Las respuestas de error (403/404) no cambian
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
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

### T-46 — Sin E/S dentro de transacciones (imágenes y tipografías)

- **Descripción**: Subir a almacenamiento fuera de la transacción (`lockForUpdate`) y borrar ficheros en `afterCommit`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-45
- **Tipo**: backend
- **Archivos**: `app/Services/Studio/StudioImages.php`, `app/Services/Studio/StudioFonts.php`
- **Cubre**: C-10
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=StudioImages` → verde · `php artisan test --compact --filter=StudioFonts` → verde

**Criterios de aceptación**
- [ ] Ninguna subida ocurre con un lock de BD abierto
- [ ] Un rollback no borra el fichero
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-47 — `Period::fromInput()` y caché del panel por marca

- **Descripción**: Un único `Period::fromInput()` que lanza `ValidationException` (11 usos, 3 formas de error) y `Cache::flexible` con versión por marca para el panel.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-46
- **Tipo**: backend
- **Archivos**: `app/Support/Period.php`, `app/Http/Controllers/AnalyticsApiController.php`, `app/Http/Controllers/ExportController.php`, `app/Mcp/Tools/GetStats.php`, `app/Http/Controllers/DashboardController.php`
- **Cubre**: C-11, C-12
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=Period` → verde · `php artisan test --compact --filter=DashboardCache` → verde

**Criterios de aceptación**
- [ ] Un periodo inválido responde igual en web, API y MCP
- [ ] El panel usa caché y se invalida al llegar nuevos snapshots
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Sin cambio de comportamiento salvo errores de periodo uniformes, declarado en el changelog.

### T-48 — `preventLazyLoading` y convención de fechas de migraciones

- **Descripción**: Activar `preventLazyLoading` fuera de producción con tests de recuento de consultas en estudio y sitios; convención con test (o `schema:dump`) para las 27 migraciones con fecha futura.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-47
- **Tipo**: backend
- **Archivos**: `app/Providers/AppServiceProvider.php`, `database/migrations/**`, `tests/Feature/Database/MigrationDatesTest.php`, `tests/Feature/Studio/QueryCountTest.php`
- **Cubre**: C-13, C-14
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=QueryCountTest` → verde · `php artisan test --compact --filter=MigrationDatesTest` → verde

**Criterios de aceptación**
- [ ] Los tests no producen lazy loading no deseado
- [ ] Un test falla si una migración nueva tiene fecha posterior a hoy
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. No renombrar migraciones ya aplicadas en el VPS: solo test hacia delante o `schema:dump` documentado.

### T-49 — Config de límites de ritmo y colas

- **Descripción**: Mover los ~25 límites de ritmo a `config/rate_limits.php`, usar `reports.timeout`, colas por config, `retry_after` 180 y cola propia para IA/render.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-48
- **Tipo**: backend
- **Archivos**: `config/rate_limits.php`, `config/reports.php`, `config/queue.php`, `app/Providers/AppServiceProvider.php`, `app/Jobs/Studio/*.php`, `docs/modulos/despliegue.md`
- **Cubre**: C-15, C-16
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=RateLimitConfigTest` → verde · `php artisan test --compact --filter=QueueConfigTest` → verde

**Criterios de aceptación**
- [ ] Los límites tienen los mismos valores por defecto que antes
- [ ] `retry_after` de redis ≥ timeout de job + margen
- [ ] IA y render usan cola propia documentada (y en `deploy/*`)
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Si se crea una cola nueva, actualizar `deploy/hestia` y `deploy/systemd` (A-06).

### T-50 — Inyección por constructor y utilidades duplicadas

- **Descripción**: Sustituir `app(X::class)`/`new` en constructores por inyección y extraer `DailyCounter`, macro `whereLikeEscaped` y `OAuthStateStore`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-49
- **Tipo**: backend
- **Archivos**: `app/**/*.php`, `app/Support/DailyCounter.php`, `app/Support/OAuthStateStore.php`, `app/Providers/AppServiceProvider.php`
- **Cubre**: C-17, C-18
- **Cubre (tests)**: E2E-38
- **Verificación**: `php artisan test --compact --filter=OAuth` → verde · `php artisan test --compact --filter=DailyCounter` → verde

**Criterios de aceptación**
- [ ] Los usos de `app(X::class)` bajan a los justificados
- [ ] Contador diario, escape LIKE y estado OAuth tienen una sola implementación
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

---

## Fase 5 — Frontend

**Estado**: borrador · **Estimado**: 119.0h · **Real**: — · **Coste est.**: 5950 € (base) + tokens ⚠️ verificar · **Tokens est.**: 5715k

### T-51 — Prettier y ESLint con `--check` en CI

- **Descripción**: Añadir Prettier y `eslint-plugin-vue`, formatear las plantillas de una línea y exigir `--check` en CI.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-50
- **Tipo**: frontend
- **Archivos**: `package.json`, `.prettierrc`, `.eslintrc.cjs`, `.github/workflows/ci.yml`, `resources/js/**/*.vue`
- **Cubre**: D-13
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run lint` → 0 errores · `npm run build` → build correcto

**Criterios de aceptación**
- [ ] `npm run lint` y `npm run format:check` pasan
- [ ] El formateo es el único cambio del diff de `.vue`
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Se hace antes de los refactors D para que sus diffs sean legibles. Una sola tarea de formateo masivo: revisar que no cambie comportamiento (E2E antes/después).

### T-52 — Carga diferida de pdf-lib y de los ocho diálogos del editor

- **Descripción**: `await import('pdf-lib')` y `defineAsyncComponent` para los diálogos del editor; mover `downloadBlob`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-51
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/exports.js`, `resources/js/Components/Studio/ExportDialog.vue`, `resources/js/Components/Studio/CaptionsPanel.vue`
- **Cubre**: D-01, D-02
- **Cubre (tests)**: E2E-07
- **Verificación**: `npm run build` → build correcto y chunk de `Editor` reducido (anotar antes/después) · `npx playwright test tests/e2e/studio-captions.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] El chunk inicial del editor baja ≥ 1 MB (medir con `vite build`)
- [ ] Los diálogos y exportaciones siguen funcionando
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-53 — `http.js` único con error tipado y cancelación

- **Descripción**: Sustituir las once copias de lector XSRF + `fetch` (cuatro con regex sin anclar) por un cliente único con error tipado y cancelación.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-52
- **Tipo**: frontend
- **Archivos**: `resources/js/lib/http.js`, `resources/js/Studio/api.js`, `resources/js/composables/useWorkflow.js`, `resources/js/Components/AiSuggest.vue`, `tests/frontend/http.test.mjs`
- **Cubre**: D-03
- **Cubre (unificación)**: U-18
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run test:unit` → pasa (incluye `http.test.mjs`) · `grep -rn "XSRF-TOKEN" resources/js | wc -l` → 1

**Criterios de aceptación**
- [ ] No quedan lectores de cookie XSRF duplicados
- [ ] Los errores HTTP llegan como objeto tipado a la UI
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] `http.js` expone un único `uploadMedia()` (con progreso y cancelación) que sustituye a `Studio/api.js:22-40`, `MediaSection.vue` y `MediaUploader.vue`; `grep -rn "FormData" resources/js | wc -l` no crece
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

### T-54 — Hora de la marca en todas las pantallas y repaso de textos

- **Descripción**: `format.js` global con la zona de la marca (seis pantallas usan hoy la del navegador) y repaso de ortografía y terminología (correo/Email, «dejara», «¿»).
- **Changelog**:
- **Estado**: borrador
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
- [ ] Las seis pantallas muestran la hora de la marca
- [ ] Sin erratas listadas y terminología uniforme
- [ ] «Biblioteca» designa solo los archivos; las plantillas de publicación se llaman «Plantillas de publicación» (`navigation.js:47`, `LibraryController.php:18`, `ExportDialog.vue:87`, `Canva/Index.vue:225`)
- [ ] Existe `docs/knowledge/glossary.md` con un término único por concepto (recursos/media, plantillas, papelera, identidad de marca frente a `brand-kit`) y la UI, `lang/**` y los textos del estudio y del compositor lo respetan
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-55 — `usePolling` y `useAiStatus`

- **Descripción**: Sondeo con tope, backoff y pausa con pestaña oculta; `ai.status` pedido una vez y `call()` compartido.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-53
- **Tipo**: frontend
- **Archivos**: `resources/js/composables/usePolling.js`, `resources/js/composables/useAiStatus.js`, `resources/js/Pages/Reports/Index.vue`, `resources/js/Pages/Canva/Index.vue`, `resources/js/Components/AiSuggest.vue`, `resources/js/Components/ScheduleSection.vue`
- **Cubre**: D-08, D-16
- **Cubre (tests)**: E2E-13
- **Verificación**: `npm run test:unit` → pasa (tests de `usePolling`) · `npx playwright test tests/e2e/canva.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Reports y Canva no sondean sin fin ni se solapan
- [ ] `ai.status` se pide una sola vez por pantalla
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-56 — `BaseDialog` sobre `<dialog>` nativo

- **Descripción**: Un único componente de diálogo con trampa de foco completa que sustituya los cuatro patrones actuales.
- **Changelog**:
- **Estado**: borrador
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
- [ ] `UtmModal` atrapa el foco y cierra con Escape
- [ ] Los cuatro patrones usan `BaseDialog`
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] `BaseDialog` parte de `StudioDialog` (`<dialog>` nativo); no quedan `fixed inset-0` a mano ni `Modal.vue` paralelo, y las pantallas del estudio lo usan
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

### T-57 — `ConfirmDialog` y `useConfirm()` en lugar de 25 `confirm()`

- **Descripción**: Sustituir todas las llamadas a `confirm()` nativo por un diálogo accesible.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-56
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/ConfirmDialog.vue`, `resources/js/composables/useConfirm.js`, `resources/js/Pages/Brands/Index.vue`, `resources/js/Pages/Sites/Edit.vue`, `resources/js/Pages/Studio/Index.vue`
- **Cubre**: D-04
- **Cubre (unificación)**: U-11
- **Cubre (tests)**: E2E-08
- **Verificación**: `grep -rnE "(^|[^.])confirm\(" resources/js | grep -v useConfirm | wc -l` → 0 · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `grep -rn "confirm(" resources/js` → solo `useConfirm`
- [ ] Cada acción destructiva pide confirmación y se puede cancelar
- [ ] Las confirmaciones del estudio usan `ConfirmDialog`/`useConfirm()` común, sin diálogos propios
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los specs que aceptaban el diálogo nativo (`page.on('dialog')`) se adaptan.

### T-58 — Flash global en `HandleInertiaRequests` y banner único

- **Descripción**: Un solo estilo de flash compartido por Inertia con banner global; recuperar errores que se pierden y mensajes que nunca se ven.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-57
- **Tipo**: frontend
- **Archivos**: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Components/FlashBanner.vue`, `app/Http/Controllers/Studio/StudioController.php`, `app/Http/Controllers/ApiKeyController.php`
- **Cubre**: D-06
- **Cubre (unificación)**: U-21
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --compact --filter=FlashSharedPropsTest` → verde · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Los mensajes de `StudioController` y `ApiKeyController` se muestran
- [ ] No quedan flashes por página con estilos distintos
- [ ] El banner único de flash/aviso se usa también en `Studio/Index.vue` y `Editor.vue` (sin banners propios del estudio)
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

### T-59 — Paginación común en papelera, biblioteca, avisos e informes

- **Descripción**: Paginación común: ninguna lista se corta en silencio (papelera y biblioteca 200, avisos 100, informes 20).
- **Changelog**:
- **Estado**: borrador
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
- [ ] Cada lista pagina y muestra el total
- [ ] Con más de 200 elementos se alcanzan todos
- [ ] La paginación común de papelera, biblioteca, avisos e informes se usa también en `Studio/Index.vue` y sigue el contrato de cursor único de T-28
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

### T-60 — Formularios accesibles y patrón de pestañas

- **Descripción**: `aria-describedby`/`aria-invalid` vía `FormField` en los 80 `InputError`; pestañas con flechas y `tabpanel` según el patrón de `NetworkTabs`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-59
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/InputError.vue`, `resources/js/Components/FormField.vue`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Components/Studio/StudioCollabPanel.vue`, `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Components/NetworkTabs.vue`
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

### T-61 — Limpieza menor del frontend

- **Descripción**: `compileTimeline()` una vez y `some()` en lugar de `JSON.stringify`, `loading="lazy"` en 14 imágenes, rutas de datasets con Ziggy y borrado de código y dependencias muertas.
- **Changelog**:
- **Estado**: borrador
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
- [ ] `compileTimeline()` se calcula una vez por cambio
- [ ] Sin `NavLink`/`ResponsiveNavLink`/`concurrently`/`dotenv`/`react` sobrantes
- [ ] `npm ls` sin extraneous
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-62 — `Editor.vue` (1/2): composables de guardado, historial y reproducción

- **Descripción**: Primera mitad de D-11: extraer `useStudioSave`, `useStudioHistory` y `useTimelinePlayback`. D-11 se cuenta aquí.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-61, T-33
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/composables/useStudioSave.js`, `resources/js/Studio/composables/useStudioHistory.js`, `resources/js/Studio/composables/useTimelinePlayback.js`
- **Cubre**: D-11
- **Cubre (unificación)**: U-05, U-10
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test tests/e2e/studio-boards.spec.ts tests/e2e/studio-captions.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `Editor.vue` pierde al menos 50 declaraciones de nivel superior
- [ ] Los specs del estudio pasan antes y después
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] `Editor.vue` usa la prop `fullBleed` del layout (sin `-m-3` ni `max-w-[1500px]` inertes) y abre sus diálogos con `BaseDialog`
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-11 es L: dos tareas. Ejecutar los E2E del estudio antes de empezar para fijar la línea base.

### T-63 — `Editor.vue` (2/2): presentación, gráficos y `EditorToolbar`

- **Descripción**: Segunda mitad de D-11: `usePresentation`, `useChartFlow` y `EditorToolbar`; `Editor.vue` queda con < 40 declaraciones.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-62
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Studio/Editor.vue`, `resources/js/Studio/composables/usePresentation.js`, `resources/js/Studio/composables/useChartFlow.js`, `resources/js/Components/Studio/EditorToolbar.vue`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (unificación)**: U-09, U-10
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test --grep studio --workers=1` → verde

**Criterios de aceptación**
- [ ] `Editor.vue` < 40 declaraciones de nivel superior
- [ ] Todos los specs `studio-*` pasan antes y después
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Los diálogos de presentación y gráficos extraídos usan `BaseDialog` y `.field`, sin bloques `<style>` ni botones propios
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-11.

### T-64 — Refactor de `Sites/Edit` y `SmartLinks/Index`

- **Descripción**: Primera tercera parte de D-12: subcomponentes y composables por función en Sitios y SmartLinks. D-12 se cuenta aquí.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-63
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Sites/Edit.vue`, `resources/js/Pages/SmartLinks/Index.vue`, `resources/js/Components/Sites/**`, `resources/js/Components/SmartLinks/**`
- **Cubre**: D-12
- **Cubre (tests)**: E2E-14
- **Verificación**: `npx playwright test tests/e2e/sites.spec.ts tests/e2e/smartlinks-blocks.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Cada página queda en < 300 líneas de script
- [ ] `sites.spec.ts` y `smartlinks-blocks.spec.ts` verdes antes y después
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-12 es L: tres tareas.

### T-65 — Refactor de `Reports/Index` y `Calendar/Index`

- **Descripción**: Segunda parte de D-12: subcomponentes y composables en Informes y Calendario.
- **Changelog**:
- **Estado**: borrador
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
- [ ] Cada página queda en < 300 líneas de script
- [ ] `report-builder.spec.ts` y `calendar-views.spec.ts` verdes antes y después
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-12.

### T-66 — Refactor de `useComposer.js`

- **Descripción**: Tercera parte de D-12: partir `useComposer.js` en composables por función sin cambiar su API pública.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-65
- **Tipo**: frontend
- **Archivos**: `resources/js/composables/useComposer.js`, `resources/js/composables/composer/**`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-14
- **Verificación**: `npx playwright test tests/e2e/composer.spec.ts tests/e2e/composer-presets.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `useComposer.js` < 400 líneas
- [ ] `composer.spec.ts` y `composer-presets.spec.ts` verdes antes y después
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-12. El compositor es la pantalla más crítica: revisión adversarial B reforzada.

### T-67 — Tipos (1/2): JSDoc + `checkJs` en lib y composables

- **Descripción**: Primera mitad de D-14: `checkJs` y `vue-tsc` en CI, JSDoc en `lib/` y composables; tipos generados desde PHP donde sea viable. D-14 se cuenta aquí.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-66
- **Tipo**: frontend
- **Archivos**: `jsconfig.json`, `tsconfig.json`, `package.json`, `resources/js/lib/**`, `resources/js/composables/**`
- **Cubre**: D-14
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run typecheck` → 0 errores · `npm run build` → build correcto

**Criterios de aceptación**
- [ ] `npm run typecheck` pasa y está en CI
- [ ] Sin cambio de comportamiento
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. D-14 es L: dos tareas (progresivo).

### T-68 — Tipos (2/2): props de componentes y páginas principales

- **Descripción**: Segunda mitad de D-14: forma tipada de las props (de 250 sin forma) en componentes comunes y páginas principales.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-67
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/**`, `resources/js/Pages/**`, `tsconfig.json`
- **Cubre**: — (continuación de la tarea anterior; el ID se cuenta allí)
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run typecheck` → 0 errores

**Criterios de aceptación**
- [ ] Las props de los componentes comunes tienen tipo
- [ ] `npm run typecheck` sigue en 0 errores
- [ ] Sin cambio de comportamiento: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de D-14.

---

## Fase 5b — Unificación

**Estado**: borrador · **Estimado**: 64.0h · **Real**: — · **Coste est.**: 3200 € (base) + tokens ⚠️ verificar · **Tokens est.**: 3040k

> Nace de [`audit-unificacion.md`](./audit-unificacion.md) (U-01..U-25): la app debe ser **una sola aplicación**. Va tras la Fase 5 porque depende de sus componentes comunes (T-53, T-56, T-57, T-60). Las que superaban una M se dividen en (1/2) y (2/2).

### T-101 — Puentes de datos (1/2): diseño → publicación y Canva → Recursos

- **Descripción**: Que lo que se crea en el estudio y lo que se importa de Canva viva en el mismo sitio que la parte social: el post recuerda su diseño y su exportación de origen, y lo importado de Canva entra como `StudioAsset`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-06, T-53
- **Tipo**: backend + frontend
- **Archivos**: `database/migrations/*_add_design_export_id_to_post_target_media.php`, `app/Models/PostTargetMedia.php`, `app/Jobs/CanvaJobs.php`, `app/Models/StudioAsset.php`, `resources/js/Components/Studio/ExportDialog.vue`, `resources/js/Components/Composer/MediaSection.vue`, `tests/Feature/StudioBridgeTest.php`, `tests/e2e/unification.spec.ts`
- **Cubre**: U-15, U-17
- **Cubre (tests)**: E2E-41, E2E-42
- **Verificación**: `php artisan test --compact --filter=StudioBridge` → pasa · `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "puentes"` → verde

**Criterios de aceptación**
- [ ] `post_target_media.design_export_id` existe con FK (`nullOnDelete`) y se rellena al crear el post desde `ExportDialog.vue:83-86`
- [ ] En el post aparece el botón «Editar en el Estudio», que abre el diseño de origen con la marca del post
- [ ] Tras una nueva exportación del diseño, el post de origen queda con la media actualizada (mientras no esté publicado) y avisa si ya lo está
- [ ] Un archivo importado de Canva aparece como `StudioAsset` en «Recursos» del estudio, sin duplicarse al reimportar (`CanvaJobs.php`, `StudioAssets.php:34`)
- [ ] Sin cambios en el flujo existente de publicación: Pest y E2E en verde antes y después
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Migración y modelo con FK a la exportación del diseño
- [ ] Rellenar `design_export_id` al exportar a una publicación
- [ ] Botón «Editar en el Estudio» y re-sincronización de la media tras una nueva exportación
- [ ] Enlazar la importación de Canva como `StudioAsset` (idempotente)
- [ ] Pest y E2E «publicación → estudio → nueva exportación» y «Canva → Recursos»
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-06 (referencias de media con FK) y T-53 (cliente HTTP único). Continúa en T-106.

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
- **Changelog**:
- **Estado**: borrador
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
- [ ] El colaborador de la marca ve los diseños de esa marca en el estudio con el alcance de su rol (`BrandCollaborator.php:15` frente a `StudioCollab.php:24-28`)
- [ ] La aprobación de diseños usa las `approvals` existentes, sin un flujo paralelo
- [ ] Las claves de rol (`StudioAssets.php:142`) están en un solo idioma, con migración o alias para los datos existentes
- [ ] Los tests de T-17 y los nuevos pasan; un colaborador sin rol no accede
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Definir la matriz rol de marca → acceso al estudio
- [ ] Aplicarla en Policies/`Ownership::assert()`
- [ ] Unificar claves de rol con alias de compatibilidad
- [ ] Aprobación de diseños vía `approvals`
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Depende de T-17 (tests de permisos) y T-45 (propiedad unificada). Continúa en T-108.

### T-106 — Puentes de datos (2/2): biblioteca en el compositor y subida única

- **Descripción**: El compositor y el estudio comparten archivos: el compositor elige desde la biblioteca del estudio y abre una imagen en el estudio; una sola subida de medios para ambos.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-101, T-53
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/MediaSection.vue`, `resources/js/Components/MediaUploader.vue`, `resources/js/Pages/Library/Index.vue`, `resources/js/Studio/api.js`, `resources/js/lib/http.js`, `tests/e2e/unification.spec.ts`
- **Cubre**: U-16, U-18
- **Cubre (tests)**: E2E-41, E2E-42
- **Verificación**: `npx playwright test tests/e2e/unification.spec.ts --workers=1 -g "biblioteca"` → verde · `npm run test:unit` → pasa

**Criterios de aceptación**
- [ ] `MediaSection.vue` ofrece «Desde la biblioteca» con los archivos y exportaciones del estudio
- [ ] Desde una imagen del compositor o de `Library/Index.vue` se puede «Abrir en el Estudio»
- [ ] `MediaSection.vue`, `MediaUploader.vue` y el estudio suben con el único `uploadMedia()` de `http.js` (T-53); no queda otro pipeline
- [ ] Un E2E sube un archivo desde el compositor y lo ve en Recursos
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Selector «Desde la biblioteca» en el compositor
- [ ] «Abrir en el Estudio» en compositor y biblioteca
- [ ] Migrar las subidas a `uploadMedia()`
- [ ] E2E de subida y de apertura
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de T-101.

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

- **Descripción**: Prefijos de abilities coherentes: los archivos tienen `media:read`/`media:write` y la IA del estudio pasa de `studio:ai` a `designs:ai`, con alias de compatibilidad.
- **Changelog**:
- **Estado**: borrador
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
- [ ] Existen `media:read` y `media:write` y `ListMedia`/`ImportMediaFromUrl` los exigen (`Abilities.php:19-39`)
- [ ] `studio:ai` pasa a `designs:ai` y el nombre antiguo sigue funcionando como alias
- [ ] Las claves de API existentes no pierden acceso (migración o alias) y hay test
- [ ] La documentación de abilities lista el nuevo conjunto
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Añadir `media:*` y exigirlas
- [ ] Renombrar `studio:ai` a `designs:ai` con alias
- [ ] Tests de compatibilidad y documentación
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Continuación de T-105.

---

## Fase 6 — Operación y repositorio

**Estado**: borrador · **Estimado**: 43.0h · **Real**: — · **Coste est.**: 2150 € (base) + tokens ⚠️ verificar · **Tokens est.**: 2055k

### T-69 — Seguridad HTTP: HSTS, CSP en `Report-Only` y Permissions-Policy

- **Descripción**: HSTS, `Permissions-Policy` y CSP en modo `Report-Only` con nonce de Vite, con endpoint de informes y plan para pasarla a estricta.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-11
- **Tipo**: devops
- **Archivos**: `deploy/hestia/nginx-overrides.conf`, `app/Http/Middleware/SecurityHeaders.php`, `config/security.php`, `docs/modulos/despliegue.md`
- **Cubre**: E-05
- **Cubre (tests)**: E2E-39, M-06
- **Verificación**: `php artisan test --compact --filter=SecurityHeadersTest` → verde · `npx playwright test tests/e2e/security.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Las cabeceras se envían solo en producción/HTTPS
- [ ] La CSP `Report-Only` recoge violaciones sin romper ninguna pantalla
- [ ] Documentado el paso a CSP estricta
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: middleware, config y nginx. Queda a la espera (U): activar HSTS y pasar a CSP estricta tras observar informes reales en el VPS. **A la espera (no bloquea la fase)**: HSTS y CSP estricta tras observar informes en el VPS (usuario).

### T-70 — Rotación de `APP_KEY` y comando `app:reencrypt`

- **Descripción**: Documentar la rotación de `APP_KEY` (tokens de redes y Canva) y añadir `app:reencrypt` que recifra con la clave nueva con verificación previa y vuelta atrás.
- **Changelog**:
- **Estado**: borrador
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
- [ ] `app:reencrypt --dry-run` informa cuántos registros recifraría
- [ ] Tras recifrar, todos los tokens se descifran con la clave nueva (test con fábricas)
- [ ] Procedimiento y copia previa documentados
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Nunca ejecutar contra los datos del usuario 28 desde el plan; solo contra BD de pruebas. No editar `.env`.

### T-71 — Peso de `docs/roadmap` y archivo del ledger

- **Descripción**: Ignorar `**/testing/**/{results.json,*.zip,raw/*.xml}`, tratar evidencias como artefactos y archivar el ledger de 795 KB de la suite creativa sin perder trazabilidad.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-10
- **Tipo**: docs
- **Archivos**: `.gitignore`, `docs/roadmap/README.md`, `docs/roadmap/2026-11-03-creative-social-suite/tasks.md`, `scripts/roadmap/archive-ledger.mjs`
- **Cubre**: E-13
- **Cubre (tests)**: —
- **Verificación**: `git check-ignore -v docs/roadmap/x/testing/y/results.json` → regla coincidente · lectura: `docs/roadmap/README.md` enlaza el ledger archivado

**Criterios de aceptación**
- [ ] `.gitignore` cubre `testing/` a cualquier nivel
- [ ] El ledger archivado mantiene enlaces desde el índice
- [ ] `ledger-lint` sigue dando 0 sobre los ledgers vivos
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. No borrar evidencia: se mueve o se enlaza como artefacto. No se hace commit desde el plan (lo hace el usuario).

### T-72 — Dependencias: webauthn-lib declarada y plan de versiones mayores

- **Descripción**: Declarar `web-auth/webauthn-lib` (hoy usada sin declarar), revisar `laravel/mcp` y `laravel/passkeys` fijadas y redactar el plan para Inertia 2→3 y Tailwind 3→4 (sin ejecutarlas).
- **Changelog**:
- **Estado**: borrador
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
- [ ] `composer.json` declara `web-auth/webauthn-lib`
- [ ] `composer validate` y Pest en verde
- [ ] El plan de mayores lista riesgos, orden y esfuerzo
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Las migraciones mayores quedan fuera: solo plan.

### T-73 — Documentación coherente: latido y docroot de Hestia

- **Descripción**: Unificar latido (600 s frente a 180 s) y docroot de Hestia en la doc de despliegue.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-03
- **Tipo**: docs
- **Archivos**: `docs/modulos/despliegue.md`, `deploy/hestia/README.md`
- **Cubre**: E-18
- **Cubre (tests)**: M-06
- **Verificación**: `grep -nE "600 ?s|180 ?s" docs/modulos/despliegue.md deploy/hestia/README.md` → un único valor coherente

**Criterios de aceptación**
- [ ] Ningún valor contradictorio de latido o docroot entre los dos documentos
- [ ] Los valores coinciden con `config/*` y el código
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Qué se puede hacer ya: unificar con el código. Queda a la espera (V/U): verificar el docroot en el VPS real. **A la espera (no bloquea la fase)**: Verificar el docroot en el VPS (usuario).

### T-74 — Fixtures grabados por red y test de contrato

- **Descripción**: Fixtures por red (respuestas representativas, sin secretos) y tests de contrato que comparen los fakes con ellas.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Fixtures/**`, `tests/Support/FixtureRecorder.php`, `tests/Feature/Contracts/**`
- **Cubre**: E-19
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=ContractFixturesTest` → verde · `grep -rniE "(access_token|bearer [a-z0-9]{20,})" tests/Fixtures | wc -l` → 0

**Criterios de aceptación**
- [ ] Cada red principal tiene fixtures de éxito y error
- [ ] Los fakes manuales se validan contra los fixtures
- [ ] Ningún fixture contiene tokens ni datos personales
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los fixtures se construyen a partir de la documentación oficial y de los fakes actuales: sin llamadas de red.

---

## Fase 7 — Funciones nuevas

**Estado**: borrador · **Estimado**: 177.0h · **Real**: — · **Coste est.**: 8850 € (base) + tokens ⚠️ verificar · **Tokens est.**: 8445k

### T-75 — Registro de actividad visible

- **Descripción**: Pantalla con el `AuditLog` ya guardado: filtros por marca, usuario, acción y fecha, paginada y solo del propio usuario/marcas.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-68, T-04
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/ActivityController.php`, `app/Services/AuditLog.php`, `resources/js/Pages/Activity/Index.vue`, `routes/web.php`, `resources/js/navigation.js`, `docs/usuario/actividad.md`
- **Cubre**: F-01
- **Cubre (tests)**: E2E-15
- **Verificación**: `php artisan test --compact --filter=ActivityLogTest` → verde

**Criterios de aceptación**
- [ ] Un usuario ve únicamente su actividad y la de sus marcas
- [ ] Filtros y paginación funcionan
- [ ] La retención de A-08 se refleja en la UI
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-76 — Mejor hora en un clic (compositor y calendario)

- **Descripción**: Botón «mejor hora» que rellena la fecha con la sugerencia de `BestTimesService` para la cuenta/marca.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-75
- **Tipo**: fullstack
- **Archivos**: `app/Services/BestTimesService.php`, `resources/js/Components/ScheduleSection.vue`, `resources/js/Pages/Calendar/Index.vue`, `docs/usuario/planificar.md`
- **Cubre**: F-02
- **Cubre (tests)**: E2E-16
- **Verificación**: `php artisan test --compact --filter=BestTimeSuggestionTest` → verde

**Criterios de aceptación**
- [ ] El botón propone una fecha futura según los datos disponibles
- [ ] Sin datos suficientes, explica por qué y no inventa
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-77 — Duplicar post (borrador u otra marca)

- **Descripción**: Acción «Duplicar» que crea un borrador con los mismos textos y media (en la misma u otra marca), sin copiar métricas ni estados.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-76
- **Tipo**: fullstack
- **Archivos**: `app/Services/PostService.php`, `app/Services/LibraryService.php`, `app/Http/Controllers/PostController.php`, `resources/js/Pages/Posts/**`, `routes/web.php`, `docs/usuario/duplicar.md`
- **Cubre**: F-03
- **Cubre (tests)**: E2E-17
- **Verificación**: `php artisan test --compact --filter=DuplicatePostTest` → verde

**Criterios de aceptación**
- [ ] El duplicado es un borrador independiente
- [ ] Entre marcas solo se copia lo permitido por las redes de destino
- [ ] Respeta propiedad y colaboradores
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S.

### T-78 — Estado del sistema en la UI (colas, cron, tokens y cuotas)

- **Descripción**: Pantalla de administración sobre `AppHealth`, `QueueProbe` y `QuotaPolicy`: estado de colas y cron, caducidad de tokens y cuotas por red. Depende de A-09.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-05, T-77
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/SystemStatusController.php`, `app/Support/HealthCheck.php`, `app/Services/QuotaPolicy.php`, `resources/js/Pages/System/Index.vue`, `routes/web.php`, `docs/modulos/despliegue.md`
- **Cubre**: F-04
- **Cubre (tests)**: E2E-01
- **Verificación**: `php artisan test --compact --filter=SystemStatusTest` → verde

**Criterios de aceptación**
- [ ] La pantalla refleja el mismo estado que `app:health`
- [ ] Solo la ven los administradores
- [ ] Cuotas y caducidades se muestran con su fecha
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. El grupo A-09 + E-20 + F-04 de la auditoría se partió: backend en la fase 1, UI aquí.

### T-79 — Objetivos y alertas (seguidores, engagement, caídas, token)

- **Descripción**: Objetivos por marca/cuenta evaluados a diario sobre `AccountSnapshot`; alertas por notificación (y correo opcional) ante hito, caída o token próximo a caducar.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-78
- **Tipo**: fullstack
- **Archivos**: `app/Models/Goal.php`, `app/Services/GoalService.php`, `app/Jobs/EvaluateGoals.php`, `app/Notifications/GoalAlert.php`, `resources/js/Pages/Goals/Index.vue`, `database/migrations/*_create_goals_table.php`, `docs/modulos/analitica.md`
- **Cubre**: F-05
- **Cubre (tests)**: E2E-18
- **Verificación**: `php artisan test --compact --filter=GoalsTest` → verde · `php artisan test --compact --filter=GoalAlertTest` → verde

**Criterios de aceptación**
- [ ] Se crean, editan y borran objetivos
- [ ] Una caída superior al umbral genera una sola alerta por periodo
- [ ] `null` = no disponible no cuenta como 0
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

### T-80 — Webhooks salientes firmados y eventos de dominio

- **Descripción**: Eventos de dominio (publicado, fallido, comentario nuevo…) entregados a URLs del usuario con firma HMAC, reintentos con backoff y `UrlGuard` contra SSRF.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-79
- **Tipo**: fullstack
- **Archivos**: `app/Models/Webhook.php`, `app/Services/Webhooks/WebhookDispatcher.php`, `app/Jobs/DeliverWebhook.php`, `app/Support/UrlGuard.php`, `resources/js/Pages/Webhooks/Index.vue`, `database/migrations/*_create_webhooks_table.php`, `docs/api/webhooks.md`
- **Cubre**: F-06
- **Cubre (tests)**: E2E-19
- **Verificación**: `php artisan test --compact --filter=WebhookDeliveryTest` → verde · `php artisan test --compact --filter=WebhookSsrfTest` → verde

**Criterios de aceptación**
- [ ] Cada entrega lleva firma HMAC verificable y marca de tiempo
- [ ] `UrlGuard` bloquea IP privadas y redirecciones
- [ ] Los reintentos se agotan y se registran
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Sin red real: `Http::fake`.

### T-81 — PWA instalable y notificaciones push

- **Descripción**: Manifiesto, service worker, suscripciones y envío de push con claves VAPID configurables; sin claves la función queda desactivada con explicación.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-80
- **Tipo**: fullstack
- **Archivos**: `public/manifest.webmanifest`, `resources/js/sw.js`, `app/Services/Push/PushSender.php`, `app/Models/PushSubscription.php`, `resources/js/Pages/Profile/Notifications.vue`, `config/push.php`, `docs/usuario/pwa.md`
- **Cubre**: F-07
- **Cubre (tests)**: E2E-20, M-07
- **Verificación**: `php artisan test --compact --filter=PushSubscriptionTest` → verde · `npm run build` → build correcto

**Criterios de aceptación**
- [ ] La app es instalable (manifiesto válido) y el service worker no cachea datos de usuario
- [ ] Las suscripciones se guardan y se pueden revocar
- [ ] El envío se prueba con un `PushSender` falso
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: todo salvo el envío real. Queda a la espera (U): generar claves VAPID, HTTPS en producción y probar en dispositivos (M-07). **A la espera (no bloquea la fase)**: Claves VAPID y HTTPS en producción (usuario).

### T-82 — Adaptar contenido entre redes con IA y alt text en el compositor

- **Descripción**: Botón que reescribe el texto para cada red de destino respetando sus límites, y generación de alt text desde el compositor reutilizando `StudioAiText::altText`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-81
- **Tipo**: fullstack
- **Archivos**: `app/Services/AiAssistant.php`, `app/Services/Studio/StudioAiText.php`, `resources/js/composables/useComposer.js`, `resources/js/Components/AiSuggest.vue`, `docs/modulos/asistente-ia.md`
- **Cubre**: F-08
- **Cubre (tests)**: E2E-21
- **Verificación**: `php artisan test --compact --filter=AdaptContentTest` → verde

**Criterios de aceptación**
- [ ] Cada versión adaptada respeta límites y hashtags de su red
- [ ] Sin `ANTHROPIC_API_KEY` el botón muestra por qué está inactivo
- [ ] El alt text se puede editar antes de guardar
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: todo con un asistente falso en tests. Queda a la espera (U): `ANTHROPIC_API_KEY` real para el smoke. **A la espera (no bloquea la fase)**: `ANTHROPIC_API_KEY` real (usuario).

### T-83 — Importación masiva de posts por CSV

- **Descripción**: Subir un CSV, validarlo fila a fila con `posts/validate` (vista previa con errores) y crear los posts como borradores o programados.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-82
- **Tipo**: fullstack
- **Archivos**: `app/Services/BatchService.php`, `app/Http/Controllers/PostImportController.php`, `resources/js/Pages/Posts/Import.vue`, `docs/usuario/importar-csv.md`
- **Cubre**: F-09
- **Cubre (tests)**: E2E-22
- **Verificación**: `php artisan test --compact --filter=PostCsvImportTest` → verde

**Criterios de aceptación**
- [ ] Las filas inválidas se muestran sin crear nada
- [ ] Las válidas crean posts con la misma validación que el compositor
- [ ] Hay un CSV de ejemplo descargable
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

### T-84 — Copia de seguridad y exportación completa (ZIP)

- **Descripción**: Exportación de todos los datos propios (posts, marcas, informes, media) en un ZIP generado en cola y descargable con enlace firmado.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-83
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/ExportController.php`, `app/Services/Export/FullExport.php`, `app/Jobs/BuildFullExport.php`, `resources/js/Pages/Profile/Export.vue`, `docs/usuario/exportar.md`
- **Cubre**: F-10
- **Cubre (tests)**: E2E-23
- **Verificación**: `php artisan test --compact --filter=FullExportTest` → verde

**Criterios de aceptación**
- [ ] El ZIP contiene solo datos del usuario
- [ ] Se genera en cola y notifica al terminar
- [ ] El enlace caduca
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. No ejecutar contra el usuario 28: tests con fábricas.

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
