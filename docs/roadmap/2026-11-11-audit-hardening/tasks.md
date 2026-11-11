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
| Fase 2 — CI y red de tests | 0 | 8 | 0% | 0 / 44.0h | 0 / 11.2h | 0 / 2.8h | 0 / 2140k |
| Fase 3 — Funciones incompletas | 0 | 16 | 0% | 0 / 118.0h | 0 / 29.6h | 0 / 7.4h | 0 / 5630k |
| Fase 4 — Refactor del backend | 0 | 15 | 0% | 0 / 90.0h | 0 / 22.8h | 0 / 5.7h | 0 / 4350k |
| Fase 5 — Frontend | 0 | 18 | 0% | 0 / 119.0h | 0 / 30.0h | 0 / 7.5h | 0 / 5715k |
| Fase 6 — Operación y repositorio | 0 | 6 | 0% | 0 / 43.0h | 0 / 10.8h | 0 / 2.7h | 0 / 2055k |
| Fase 7 — Funciones nuevas | 0 | 24 | 0% | 0 / 177.0h | 0 / 44.4h | 0 / 11.1h | 0 / 8445k |
| Fase 8 — QA integral y documentación | 0 | 2 | 0% | 0 / 11.0h | 0 / 2.8h | 0 / 0.7h | 0 / 535k |
| **TOTAL** | **11** | **100** | **11%** | **0 / 665.0h** | **0 / 167.6h** | **0 / 41.9h** | **0 / 31925k** |

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
- **Estado**: en-revision
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real 0.4h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-01
- **Tipo**: test
- **Archivos**: `package.json`, `tests/frontend/**`, `tests/e2e/global-teardown.ts`, `scripts/test/purge-testing.mjs`, `docs/guias/testing.md`, `scripts/test/run-node-tests.mjs`, `scripts/test/purge-testing.d.mts`, `docs/guias/desarrollo-local.md`, `README.md`
- **Cubre**: E-11, E-12
- **Cubre (tests)**: —
- **Verificación**: `npm run test:unit` → todos pasan · `node scripts/test/purge-testing.mjs --dry-run` → lista lo que borraría y no falla

**Criterios de aceptación**
- [x] `npm run test:unit` ejecuta los tests de Node sin flags manuales
- [x] El teardown conserva solo las últimas N ejecuciones y borra el resto de `storage/framework/testing`
- [x] `docs/guias/testing.md` documenta los scripts
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: `npm run test:unit` → 627 tests, 627 pass, 0 fail (62 ficheros; los 6 `*.browser.test.mjs` y `studio-captions-render-entry.mjs` excluidos, `--list` de `browser` da los 6). `node scripts/test/purge-testing.mjs --dry-run` sobre el repo real → «borraría: 1384 · conservado: 655 · fallos: 0», solo informa (no se borró nada). La purga real se probó únicamente con directorios temporales (`tests/frontend/purge-testing.test.mjs`, 3/3: N más recientes o jóvenes se conservan, la ejecución en curso y `.gitignore` a salvo, `--dry-run` no borra, raíz inexistente no falla).

**Decisiones**: Selector en `scripts/test/run-node-tests.mjs` (no globs de shell: Windows/Node 22 y exclusión limpia de `*.browser.test.mjs`). Purga como script Node invocado por `global-teardown.ts` (no artisan: no depende de PHP ni de BD, y corre aunque el teardown de usuarios falle) con respaldo `E2E_PURGE=0`; un fallo solo avisa. Regla: se conserva si es de las 5 más recientes O de <2 días (por mtime), sobre `testing/*` y `testing/t43/*`; ocultos y symlinks no se tocan. El bookends guard llama a `teardown()` y lo desactiva con `E2E_PURGE=0` (T-13). `docs/guias/testing.md` no existía: creada. `test:browser` no se ejecutó (necesita Chromium/ffmpeg; solo se verificó el listado).

**Ajuste tras revisión del coordinador**: alcance restringido a `t43/run-general-<uuid>` y `t43/run-<epoch>-<pid>`; se excluye toda entrada cuyo nombre aparece en un `.md` de `docs/` (evidencia citada, journal incluido); nada fuera de esos patrones. Test con árbol temporal mixto (runRoots, evidencia sin patrón, directorio citado, joven, en curso): `npm run test:unit` 630/630. `--dry-run` real: «borraría 268 · conservado 43» (antes 1384); no se borró nada.

**Notas**: Tamaño S. No tocar el directorio de otra ejecución en curso: la purga respeta un mínimo de antigüedad.

### T-13 — Puerto libre para E2E e integración de configs de Playwright

- **Descripción**: Sustituir el puerto 8080 fijo por `E2E_PORT` o un puerto libre, e integrar en la batería (o borrar) las tres configs de Playwright huérfanas.
- **Changelog**: Los E2E ya no fallan si el puerto 8080 está ocupado: usan E2E_PORT o un puerto libre; la batería de T-43 tiene su script npm y se retiran dos configs de Playwright duplicadas.
- **Estado**: en-revision
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real 0.6h (estimado)
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-12
- **Tipo**: test
- **Archivos**: `tests/e2e/isolated/runtime.ts`, `tests/e2e/isolated/serve.cjs`, `playwright.config.ts`, `playwright.roadmap.config.ts`, `playwright.audio.config.ts`, `playwright.presets.config.ts`, `package.json`, `tsconfig.json`, `tests/e2e/isolated/bootstrap.php`, `tests/e2e/isolated/fakes.php`, `tests/e2e/isolated/bookends.guard.ts`, `tests/e2e/studio-boards.spec.ts`, `docs/guias/desarrollo-local.md`, `docs/guias/pruebas-t43.md`, `docs/stack.md`, `README.md`, `.env.example`
- **Cubre**: E-03, E-17
- **Cubre (tests)**: E2E-39
- **Verificación**: `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde con 8080 ocupado · `grep -rn "8080" tests/e2e/isolated` → sin coincidencias fijas

**Criterios de aceptación**
- [x] El runtime E2E arranca en un puerto libre (o `E2E_PORT`) y no falla si 8080 está ocupado
- [x] Cada config huérfana tiene proyecto/script en la batería o se elimina con motivo en el changelog
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [x] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [x] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Evidencia**: puerto 8080 comprobado libre antes de lanzar (`netstat`). `E2E_PORT=18765 npx playwright test tests/e2e/pages.spec.ts --workers=1 --project=desktop` → 34 passed (el `results.json` de la ejecución muestra `localhost:18765`). Con 8080 OCUPADO por un listener ficticio y sin `E2E_PORT` → 34 passed y el run usó `localhost:59224` (puerto libre elegido). Ambas con `E2E_PURGE=0` para no activar la purga sobre el repo real. `grep -rn 8080 tests/e2e/isolated` → solo el puerto preferido de `runtime.ts` y datos de `http-server.test.cjs`. `npx tsc --noEmit -p .` sin errores nuevos (los de `.cjs` sin tipos son previos). Un solo spec corto, no la batería ni Pest (instrucción del coordinador).

**Decisiones**: `runtime.ts` fija el puerto una vez (E2E_PORT validado 1024-65535, o 8080 si libre, o efímero del SO) y lo escribe en `process.env.E2E_PORT` para que los workers, que reevalúan el módulo, hereden el mismo; `APP_URL`/`WEBAUTHN_ORIGINS` salen de `baseURL`, el RP ID sigue siendo `localhost` (no lleva puerto). `serve.cjs`, `bootstrap.php` (regex `localhost:<puerto>`), `fakes.php` (`APP_URL`), `bookends.guard.ts` y `studio-boards.spec.ts` ya no fijan 8080. Quedan con 8080 a propósito: `record-passkey-vector.ts` y el vector de passkeys (se grabó contra ese origen; el test Pest comprueba el origen del vector) y los tests Pest/PHP que usan 8080 como dato.
Configs de Playwright (se comprobó referencias en docs, CI, scripts y `package.json`): **`playwright.roadmap.config.ts` se INTEGRA** — la citan `README.md`, `docs/guias/pruebas-t43.md` y `docs/stack.md` como la vía de la QA de T-43; añadido `npm run test:e2e:roadmap` y puerto configurable `E2E_ROADMAP_PORT` (defecto 8081). **`playwright.audio.config.ts` y `playwright.presets.config.ts` se ELIMINAN**: ningún doc vigente, CI ni script las usa (solo ledgers/informes históricos de 2026-10-31), `presets` solo reexporta `audio` con un `grep`, y `audio` solo filtra `instagram-audio`/`tiktok-audio`, specs que la config general ya ejecuta; la única diferencia (`TIKTOK_VERIFIED_DOMAIN`) ya la cubre `tiktok-audio.spec.ts` con su fichero-señal que el servidor lee en cada petición. Retiradas también de `tsconfig.json`. Para pasar solo esos specs: `npx playwright test tests/e2e/instagram-audio.spec.ts tests/e2e/tiktok-audio.spec.ts`.

**Notas**: Tamaño S. Una sola ejecución de Playwright a la vez (GOT-005).

### T-14 — E2E paralelizable: servidor y SQLite por worker; móvil solo en `@responsive`

- **Descripción**: Servidor `php -S`, base SQLite y puerto propios por worker; los proyectos de tamaño móvil/tablet solo ejecutan specs `@responsive`; habilitar `--shard`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-13
- **Tipo**: test
- **Archivos**: `playwright.config.ts`, `tests/e2e/isolated/serve.cjs`, `tests/e2e/isolated/runtime.ts`, `tests/e2e/helpers.ts`, `tests/e2e/global-setup.ts`, `docs/guias/testing.md`
- **Cubre**: E-02
- **Cubre (tests)**: E2E-39
- **Verificación**: `npx playwright test --workers=2 --grep @smoke` → verde y sin colisiones · `npx playwright test --list --project=mobile` → solo specs `@responsive`

**Criterios de aceptación**
- [ ] La batería funciona con `workers ≥ 2` sin interferencias entre workers
- [ ] Móvil/tablet solo corren specs etiquetadas `@responsive`
- [ ] La duración total baja de forma medible frente a los 1,5 h de partida (registrar antes/después)
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Cambio de infraestructura de tests: las demás tareas siguen ejecutándose en serie para no mezclar causas de fallo (GOT-005). Medir antes/después.

### T-15 — CI: concurrency, caché, jobs paralelos y pasos faltantes

- **Descripción**: Reestructurar la CI (`push: [main]` + PR, `concurrency`, `timeout-minutes`, caché de composer/npm, jobs paralelos) y añadir tests de Node, typecheck, `composer audit` y `npm audit`, lint de OpenAPI, `ledger-lint` y el resto de `tests/studio_runtime`; E2E troceado nocturno con `--shard`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-14, T-10
- **Tipo**: devops
- **Archivos**: `.github/workflows/ci.yml`, `.github/workflows/nightly.yml`, `package.json`, `docs/guias/testing.md`
- **Cubre**: E-15, E-01
- **Cubre (tests)**: —
- **Verificación**: `python3 -c "import yaml;[yaml.safe_load(open(f)) for f in ['.github/workflows/ci.yml','.github/workflows/nightly.yml']]"` → sin error · lectura: el nuevo `ci.yml` tiene `concurrency` y `timeout-minutes` en cada job

**Criterios de aceptación**
- [ ] La CI corre una sola vez por commit y cancela ejecuciones obsoletas
- [ ] La CI incluye tests de Node, typecheck, auditorías, lint de OpenAPI y ledger-lint
- [ ] Existe `nightly.yml` con E2E troceado y los tests `studio_runtime` que hoy no corren
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Sin red/Docker: se valida el YAML y los comandos en local; el primer verde real lo da el push del usuario. No fusionar: los merges los hace el usuario.

### T-16 — Tests deterministas y rápidos (servidor real, Bluesky 41 s)

- **Descripción**: Hacer determinista (o marcar `integration`) el test con servidor HTTP real y `usleep`, y bajar el coste de los tests de Bluesky (perfilado, DNS y reloj simulados, `--parallel`).
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Feature/Media/UrlSourceRealHandlerTest.php`, `tests/Feature/Connectors/Bluesky/**`, `tests/Pest.php`, `phpunit.xml`
- **Cubre**: E-04, E-10
- **Cubre (tests)**: —
- **Verificación**: `for i in 1 2 3 4 5; do php artisan test --compact --filter=UrlSourceRealHandlerTest || exit 1; done` → 5 verdes · `php artisan test --compact --filter=Bluesky --profile` → tiempo total < 10 s

**Criterios de aceptación**
- [ ] `UrlSourceRealHandlerTest` pasa 5 veces seguidas sin fallos
- [ ] Los tests de Bluesky bajan de 41 s a menos de 10 s
- [ ] `php artisan test --parallel` funciona o queda documentado por qué no
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. La causa de lentitud de Bluesky no está verificada: perfilar primero (`--profile`).

### T-17 — Tests de permisos: aprobaciones y colaboradores

- **Descripción**: Cubrir con tests de permisos y acceso cruzado `RequestPostApproval`, `api.approvals.pending`, `api.collaborators.index` y `workflow.collaborators.index`.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-02
- **Tipo**: test
- **Archivos**: `tests/Feature/Mcp/RequestPostApprovalTest.php`, `tests/Feature/Workflow/ApprovalsPendingApiTest.php`, `tests/Feature/Workflow/CollaboratorsIndexTest.php`, `routes/post-workflow-api.php`, `app/Mcp/Tools/RequestPostApproval.php`
- **Cubre**: E-09
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=RequestPostApprovalTest` → verde · `php artisan test --compact --filter=ApprovalsPendingApiTest` → verde · `php artisan test --compact --filter=CollaboratorsIndexTest` → verde

**Criterios de aceptación**
- [ ] Cada una de las cuatro rutas/herramientas tiene test de éxito, sin permiso y de acceso cruzado entre usuarios
- [ ] Un usuario no ve aprobaciones ni colaboradores de otro
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Si un test destapa un fallo real de permisos, se corrige en esta misma tarea y se anota.

### T-18 — E2E menos frágil: sin `waitForTimeout` ni `.first()` arbitrarios

- **Descripción**: Sustituir los 32 `waitForTimeout`, los `.first()` y los `getByText` frágiles por `expect.poll`, `getByRole` y `getByTestId`, añadiendo `data-testid` donde falten.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-14
- **Tipo**: test
- **Archivos**: `tests/e2e/a11y.spec.ts`, `tests/e2e/composer.spec.ts`, `tests/e2e/helpers.ts`, `tests/e2e/**/*.spec.ts`
- **Cubre**: E-16
- **Cubre (tests)**: E2E-39
- **Verificación**: `grep -rn "waitForTimeout" tests/e2e` → ≤ 3 coincidencias (justificadas) · `npx playwright test tests/e2e/composer.spec.ts tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Cero `waitForTimeout` en `tests/e2e` salvo los justificados con comentario
- [ ] Los selectores de los specs más frágiles usan rol o `data-testid`
- [ ] La batería sigue verde (sin cambio de comportamiento de la app)
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Los `data-testid` añadidos a `.vue` no cambian comportamiento. Una sola ejecución de Playwright a la vez (GOT-005).

### T-19 — Job nocturno del camino nativo MP4/GIF (ffmpeg + Chromium)

- **Descripción**: Job nocturno que instala ffmpeg y Chromium y ejecuta con `STUDIO_AV_NATIVE_TEST=1` los 9 tests hoy omitidos.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-15
- **Tipo**: devops
- **Archivos**: `.github/workflows/nightly.yml`, `tests/Feature/Studio/StudioAvProcessTest.php`, `tests/Feature/Studio/StudioAvQueueTest.php`, `docs/modulos/estudio-ia.md`, `docs/guias/testing.md`
- **Cubre**: E-08
- **Cubre (tests)**: —
- **Verificación**: lectura: `nightly.yml` define el job `native-av` con `STUDIO_AV_NATIVE_TEST=1` · `STUDIO_AV_NATIVE_TEST=1 php artisan test --compact --filter=StudioAv` → 0 omitidos (si ffmpeg/Chromium están instalados; si no, `skip` documentado)

**Criterios de aceptación**
- [ ] El job nocturno corre los 9 tests nativos sin `skip`
- [ ] El job publica los artefactos de fallo
- [ ] La doc explica cómo correrlos en local
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: workflow y doc. La primera ejecución real ocurre en GitHub tras el push del usuario.

---

## Fase 3 — Funciones incompletas

**Estado**: borrador · **Estimado**: 118.0h · **Real**: — · **Coste est.**: 5900 € (base) + tokens ⚠️ verificar · **Tokens est.**: 5630k

### T-20 — Inventario corregido y nombres de ruta únicos

- **Descripción**: Corregir las filas desfasadas del inventario (C24, M11, C22) y dar nombres únicos `api.studio.*` a las rutas API que repiten `studio.folders.*` y nombres de datasets autogenerados.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 3.0h · real —
- **Tiempo IA (ejec.)**: est. 0.8h · real —
- **Supervisión**: est. 0.20h (≈25 % IA) · real —
- **Previsión IA**: 120k in / 35k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-18
- **Tipo**: backend
- **Archivos**: `docs/roadmap/2026-11-03-creative-social-suite/reference-inventory.md`, `routes/studio.php`, `routes/studio-api.php`, `resources/js/Studio/dataset-client.js`, `tests/Feature/Routes/RouteNamesTest.php`
- **Cubre**: B-19, B-20
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact --filter=RouteNamesTest` → verde · `php artisan route:cache` → sin error de nombres duplicados

**Criterios de aceptación**
- [ ] `php artisan route:list --json` sin nombres duplicados
- [ ] Todas las llamadas del frontend a las rutas renombradas siguen funcionando
- [ ] Inventario con C24, M11 y C22 actualizados
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño S. Hacerlo antes del refactor del frontend: evita renombrar dos veces (D-19 usa los nombres nuevos).

### T-21 — Pinterest: analítica y tableros paginados

- **Descripción**: `InsightsReader` de Pinterest (`/v5/user_account/analytics`, `/v5/pins/{id}/analytics`) enlazado en `AppServiceProvider`, tableros paginados con `bookmark` y botón de refresco. LinkedIn queda en espera.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20, T-07
- **Tipo**: fullstack
- **Archivos**: `app/Connectors/Pinterest/PinterestInsightsReader.php`, `app/Connectors/Pinterest/PinterestConnector.php`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Brands/**`, `docs/modulos/analitica.md`, `docs/modulos/conexiones.md`
- **Cubre**: B-01, B-04
- **Cubre (tests)**: E2E-05, M-01
- **Verificación**: `php artisan test --compact --filter=PinterestInsightsReaderTest` → verde · `php artisan test --compact --filter=PinterestBoardsPaginationTest` → verde

**Criterios de aceptación**
- [ ] El panel, mejores horas e informes muestran datos de Pinterest (con fakes)
- [ ] Los tableros se obtienen paginados y hay botón de refresco
- [ ] `null` = no disponible, nunca 0
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Se implementa Pinterest (Ya). LinkedIn (U): estadísticas de página quedan a la espera de `community_management`; se deja el punto de extensión y la fila en la cobertura (B-14). Verificación real = smoke M-01. **A la espera (no bloquea la fase)**: Lector de LinkedIn: requiere aprobación `community_management` (usuario/LinkedIn).

### T-22 — Inbox: scopes de Meta, permisos de Threads y cobertura de redes

- **Descripción**: La UI explica cuándo faltan scopes de Meta (inbox/Ads) y cómo activarlos; Threads trata el error de permiso como «reconecta» y retira el scope; LinkedIn y Pinterest aparecen en la tabla de cobertura del inbox.
- **Changelog**:
- **Estado**: borrador
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
- [ ] Sin scopes Meta, el inbox/Ads muestra un estado claro con el motivo y los pasos (no un panel vacío)
- [ ] Un error de permiso de Threads marca la cuenta como «reconectar» y retira el scope concedido
- [ ] La tabla de cobertura incluye LinkedIn y Pinterest con su motivo
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Qué se puede hacer ya: UI, retirada de scope y filas de cobertura. Queda a la espera (U): activar el caso de uso en Meta, rellenar `META_EXTRA_SCOPES` y reconectar; `CommentSource` de LinkedIn tras su aprobación. **A la espera (no bloquea la fase)**: `META_EXTRA_SCOPES` y reconexión (usuario); `CommentSource` de LinkedIn (aprobación).

### T-23 — Avisos de caducidad y notificaciones de jobs asíncronos

- **Descripción**: Generalizar el aviso de caducidad a toda caducidad conocida (notificación + correo N días antes) y notificar en BD (y correo opcional) los informes programados, MP4/GIF, IA, Canva e importaciones.
- **Changelog**:
- **Estado**: borrador
- **Tiempo humano**: est. 8.0h · real —
- **Tiempo IA (ejec.)**: est. 2.0h · real —
- **Supervisión**: est. 0.50h (≈25 % IA) · real —
- **Previsión IA**: 300k in / 80k out tok · ⚠️ verificar € (precio de tokens sin confirmar)
- **Dependencias**: T-20
- **Tipo**: backend
- **Archivos**: `app/Jobs/RefreshSocialAccount.php`, `app/Jobs/SendScheduledReport.php`, `app/Jobs/Studio/RunStudioAiJob.php`, `app/Jobs/RunCanvaJob.php`, `app/Jobs/ImportRemoteMedia.php`, `app/Notifications/**`, `app/Http/Controllers/ConnectionController.php`, `resources/js/Pages/Notifications/**`, `docs/modulos/conexiones.md`
- **Cubre**: B-03, B-13
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --compact --filter=ConnectionExpiryNotificationTest` → verde · `php artisan test --compact --filter=JobCompletionNotificationTest` → verde

**Criterios de aceptación**
- [ ] Meta (90 días) y LinkedIn avisan N días antes de caducar, una sola vez por umbral
- [ ] Informes, MP4/GIF, IA, Canva e importaciones generan notificación al terminar o fallar
- [ ] El correo es opcional por preferencia del usuario
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

**Notas**: Tamaño M. Reutilizar el mecanismo de notificación de fallos de publicación. Sin SMTP real: `Mail::fake`.

### T-24 — Primer comentario en Threads y Bluesky

- **Descripción**: Implementar `FirstCommenter` en Threads y Bluesky reutilizando `ThreadsComments`/`BlueskyComments`.
- **Changelog**:
- **Estado**: borrador
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
- [ ] Un post con primer comentario en Threads o Bluesky lo publica tras el principal (con fakes)
- [ ] Si el primer comentario falla, el post principal queda publicado y el fallo se registra
- [ ] El compositor muestra el campo para esas redes
- [ ] Revisión adversarial A+B(+C) sin hallazgos Critical/Important abiertos
- [ ] Pest verde y Playwright de los specs afectados en verde (una sola ejecución, serial)
- [ ] Documentación actualizada (módulo afectado en `docs/` y CHANGELOG si cambia algo visible)

**Subtareas**
- [ ] Implementar (implementer)
- [ ] Revisión adversarial A+B(+C)
- [ ] Corregir hallazgos de la revisión
- [ ] Pest y Playwright (serial, sin cambios de código en paralelo)
- [ ] Actualizar documentación y cerrar la tarea en este ledger

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
- **Cubre (tests)**: API-01
- **Verificación**: `php artisan test --compact --filter=ApiV1PostsTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [ ] Los endpoints respetan abilities de API key y propiedad (acceso cruzado → 404/403)
- [ ] La OpenAPI describe cada endpoint y pasa el lint
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
- **Cubre (tests)**: API-02
- **Verificación**: `php artisan test --compact --filter=ApiV1ResourcesTest` → verde · `npx @redocly/cli lint docs/api/openapi.yaml` → sin errores

**Criterios de aceptación**
- [ ] Paridad de lectura con el MCP en los recursos listados
- [ ] OpenAPI completa y con lint limpio
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
- **Cubre (tests)**: E2E-39
- **Verificación**: `npm run test:unit` → pasa (incluye `http.test.mjs`) · `grep -rn "XSRF-TOKEN" resources/js | wc -l` → 1

**Criterios de aceptación**
- [ ] No quedan lectores de cookie XSRF duplicados
- [ ] Los errores HTTP llegan como objeto tipado a la UI
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
- **Archivos**: `resources/js/lib/format.js`, `resources/js/Pages/Inbox/Index.vue`, `resources/js/Pages/Trash/Index.vue`, `resources/js/Pages/ApiKeys/Index.vue`, `resources/js/Pages/Reports/Index.vue`, `lang/**`
- **Cubre**: D-09, D-21
- **Cubre (tests)**: E2E-11
- **Verificación**: `npm run test:unit` → pasa (test de `format.js` con zonas distintas) · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Las seis pantallas muestran la hora de la marca
- [ ] Sin erratas listadas y terminología uniforme
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
- **Cubre (tests)**: E2E-08, M-12
- **Verificación**: `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `UtmModal` atrapa el foco y cierra con Escape
- [ ] Los cuatro patrones usan `BaseDialog`
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
- **Cubre (tests)**: E2E-08
- **Verificación**: `grep -rnE "(^|[^.])confirm\(" resources/js | grep -v useConfirm | wc -l` → 0 · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `grep -rn "confirm(" resources/js` → solo `useConfirm`
- [ ] Cada acción destructiva pide confirmación y se puede cancelar
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
- **Cubre (tests)**: E2E-09
- **Verificación**: `php artisan test --compact --filter=FlashSharedPropsTest` → verde · `npx playwright test tests/e2e/pages.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Los mensajes de `StudioController` y `ApiKeyController` se muestran
- [ ] No quedan flashes por página con estilos distintos
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
- **Cubre (tests)**: E2E-10
- **Verificación**: `php artisan test --compact --filter=PaginationListsTest` → verde

**Criterios de aceptación**
- [ ] Cada lista pagina y muestra el total
- [ ] Con más de 200 elementos se alcanzan todos
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
- **Cubre (tests)**: E2E-12, M-12
- **Verificación**: `npx playwright test tests/e2e/a11y.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] Los errores de formulario se asocian al campo
- [ ] Las pestañas se manejan con flechas y declaran `tabpanel`
- [ ] `a11y.spec.ts` sin violaciones serious/critical nuevas
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
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test tests/e2e/studio-boards.spec.ts tests/e2e/studio-captions.spec.ts --workers=1` → verde

**Criterios de aceptación**
- [ ] `Editor.vue` pierde al menos 50 declaraciones de nivel superior
- [ ] Los specs del estudio pasan antes y después
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
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test --grep studio --workers=1` → verde

**Criterios de aceptación**
- [ ] `Editor.vue` < 40 declaraciones de nivel superior
- [ ] Todos los specs `studio-*` pasan antes y después
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

## Revisión de dos lentes — intento 1: Fase 1 (T-04, T-05) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | La fusión de autoguardados nunca se activaba desde la UI: el editor envía `kit_revision` en cada PATCH y la condición lo excluía | T-04 | `$autosave` ya no depende de `kit_revision`; un autoguardado con una revisión de kit ya podada conserva el kit actual en vez de fallar | `DesignRevisionRetentionTest` «the real editor payload (with kit_revision) coalesces autosaves through HTTP»: rojo `[1,2,3,4]` → verde `[1,4]` |
| 2 | Critical (latente) | La fusión reescribía el número de una revisión fijada por una exportación | T-04 | Los números no se reutilizan: la nueva revisión es una fila nueva y solo se borra la anterior de autoguardado si no la fija una exportación no terminada (`DesignRevision::pinnedByExport`) ni es la `kit_revision` del editor | test «does not reuse the number of a revision pinned by an unfinished export»: rojo (la fila 2 desaparecía) → verde |
| 3 | Important | La fusión sobrescribía puntos de control explícitos | T-04 | Columna `autosave` en `studio_design_revisions` (migración `2026_12_16_090000`, reversible); solo se sustituye una fila de autoguardado del mismo autor; Guardar ahora, Ctrl+S, restaurar y kit nunca | test «an autosave never rewrites an explicit checkpoint»: rojo `[1,3]` → verde `[1,2,3]` |
| 4 | Important | Falso positivo de snapshots (cuentas no activas y recién conectadas) | T-05 | Solo cuentas `activa` (como `CollectSnapshots`) con antigüedad ≥ 24 h + umbral | test «snapshots ignore inactive accounts and give a new account a grace period» |
| 5 | Important | Falso positivo de Chromium bajo PHP-FPM (`clear_env`) | T-05 | Por HTTP solo se comprueba con `STUDIO_CHROMIUM_PATH`; la comprobación completa queda en la CLI | test «native is cached ... Chromium is not guessed outside the CLI» |
| 6 | Minor | `native` lanzaba procesos en cada petición HTTP | T-05 | `/health` cachea `native` `HEALTH_NATIVE_CACHE_SECONDS` (300 s); la CLI siempre comprueba y refresca | mismo test: 3 procesos en dos `run(http)` |
| 7 | Minor | La poda no excluía revisiones fijadas por exportaciones no terminadas | T-04 | `prunable()` excluye con `NOT EXISTS` las fijadas por exportaciones con estado distinto de `ready` | test «pruning keeps revisions pinned by unfinished exports»: rojo → verde |
| 8 | Minor | Poda sin lotes eficientes | T-04 | `DesignRevision::pruneAll` calcula una vez los ids a podar y borra por lotes; notificaciones por lotes (`App\Support\Retention`) con índice `notifications_read_at_prune` | tests «computes the keep set once» (1 consulta de ventana, rojo 4) y «notifications prune deletes in batches» |

Estado: T-04 y T-05 siguen en `en-revision` hasta la re-revisión. Pest focal 57/57; Pest completo y Pint: ver el resumen de la entrega.

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

## Revisión de dos lentes — intento 1: Fase 1 (T-06) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | El criterio exigía bloquear el borrado de media usada por un export y estaba marcado sin implementarlo | T-06 | `MediaAsset::usedByExport()` (parte de `usedByPosts`): export `ready` de los últimos `studio.export_media_guard_days` días (30, `STUDIO_EXPORT_MEDIA_GUARD_DAYS`, 0 = no bloquea), con `whereJsonContains` filtrado por usuario, estado y fecha, sin cargar filas; los exports históricos o no listos no bloquean. Documentado en `media-por-destino.md` y `configuracion.md` | test «la media de un export listo reciente bloquea…» (rojo: no lanzaba `DomainException`) |
| 2 | Important | La migración descartaba en silencio ids y portadas huérfanos también en destinos sin publicar | T-06 | Un destino no publicado con huérfanos pasa a `fallida` (`error_code` `media_inexistente`, mensaje «Falta el archivo X…») y se registra en el log con los ids; las portadas huérfanas se anotan igual; solo en destinos publicados se descarta. Nota: un reintento de ese destino usaría la lista sin el archivo perdido, por eso el mensaje pide editarlo | test «la migración no descarta en silencio…» con programada [A, B] y B borrada (rojo: seguía `programada`) |
| 3 | Minor | Índice de series/autolistas en otra transacción y descarte silencioso de ids inexistentes | T-06 | `SeriesService::create/update` y `AutolistService::addItem` guardan en `DB::transaction`; el hook del modelo lanza `PostValidationException` (media inexistente o de otro usuario) y la transacción revierte | test «una serie o un elemento de autolista con media inexistente…» (rojo: no lanzaba; 0 filas tras el error) |
| 4 | Minor | La migración hacía un UPDATE por fila | T-06 | `whereIn(...)->update` para `has_own_media`, inserciones por lotes y existencia en bloque por chunk en destinos, series y autolistas (solo los destinos rotos, raros, se actualizan uno a uno por su mensaje) | tests de migración (ida, huérfanos y vuelta) verdes |
| 5 | Minor | Sin verificación en MySQL | T-06 | Revisión a mano: nombres de índice ≤ 58 caracteres, FK del mismo tipo que `id`, `dropColumn` de JSON sin índice. Verificación real anotada como pendiente del usuario en el ledger y en `docs/referencia/pendientes-manuales.md` | documentación |
| 6 | Important | Borrar una cuenta en MySQL puede chocar con las FK `restrict` por el orden de las cascadas | T-06 | `User::deleting` vacía explícitamente `post_media`, `post_target_media`, `post_series_media` y `autolist_item_media` de la media del usuario (y las portadas) antes de borrarlo; lo usa `ProfileController::destroy` y cualquier otro borrado | test «borrar un usuario con posts, media por destino, series y autolistas…» (verde en SQLite, que no reproduce el orden de InnoDB; la comprobación en MySQL queda en pendientes manuales) |

Verificación: `MediaDeleteReferencesTest` 11/11; Pest focal (Posts, Media, Workflow, Parity, Studio, Sites, Auth, Profile, Mcp, Library, Retention, Isolation) y Pint; Pest completo y Playwright, del coordinador. T-06 sigue `en-revision`.

## Revisión de dos lentes — intento 2: Fase 1 (T-06) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 2a | Important | El reintento publicaba la lista recortada de un destino marcado `media_inexistente` | T-06 | `PostService::retryFailed` salta esos destinos (`MEDIA_MISSING`) y, si no queda ninguno reintentable, lanza `PostValidationException` (422 en web y API; error del MCP `retry_post`, mismo servicio); `CalendarService`: `can_retry` falso y nuevo `retry_blocked` con el motivo, que `Calendar/Index.vue` muestra junto a la fila. Editar el destino (siempre `fresh` si estaba fallido) limpia el código | `MediaMissingRetryTest` 3 tests (rojo: 200 en web/API/MCP y `can_retry` true) |
| 2b | Important | La migración no recalculaba `posts.status` ni avisaba | T-06 | Recalcula el estado del post con la regla de `Post::syncStatus` (sin eventos) e inserta una fila en `notifications` (`PostMediaMissing`, «Falta el archivo X en la publicación Y», visible en Avisos con título `media_missing`) | test de migración con programada [A, B] y B borrada (rojo: post `borrador`/sin notificación) |
| N1 | Minor | Se marcaban fallidos borradores y destinos en vuelo | T-06 | Solo `programada` pasa a `fallida`; un borrador conserva su estado y pierde los ids inexistentes; `publicando`/`procesando`/`fallida` no se tocan; todo se registra en el log | test «la migración no marca fallidos los borradores ni toca los destinos en vuelo» (rojo: borrador → fallida) |
| N2 | Minor | La purga hacía commit separado del DELETE del usuario | T-06 | `User::delete()` envuelve purga y borrado en una transacción (vale para `ProfileController::destroy` y cualquier otro borrado) | test «la purga … son atómicos» con un listener que falla (rojo: 0 filas tras el fallo) |
| UX | Minor | El error de borrado de un export decía «se usa en una publicación» | T-06 | `MediaAsset::usageReason()` distingue publicación, serie activa, autolista y exportación reciente del estudio; `MediaUploadService::delete` lo usa | test «el error de borrado distingue el motivo» (rojo) |

Verificación: `MediaDeleteReferencesTest` + `MediaMissingRetryTest` 17/17; Pest focal; Pint; `npm run build`. Pest completo y Playwright, del coordinador. T-06 sigue `en-revision`.

## Revisión de dos lentes — intento 1: Fase 1 (T-07, T-08) — gaps corregidos

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Critical | Con disco R2/S3 el vídeo se enviaba truncado: `readStream` devuelve el socket HTTP, `fstat` da 0 y el multipart medía solo sus cabeceras | T-07 | Tamaño real por `Filesystem::size` y stream decorado con tamaño fijo (`KnownSizeStream`): Guzzle declara bien el `Content-Length` (S3 no admite `chunked`) y envía el vídeo completo, sin cargarlo en memoria | `PinterestVideoUploadRobustnessTest` «un stream remoto sin tamano conocido envia el video completo»: stream con `fstat` 0 y cuerpo leído de la petición; rojo antes del arreglo |
| 2 | Important | El paso podía superar el job: registrar (15 s) + subir (100 s) > 110 s; la recuperación registraba otro media | T-07 | La subida usa `min(upload_timeout, StepBudget::remaining())`; sin 30 s (`http_timeout` + `upload_min_seconds`) de presupuesto lanza `TransientPublishException` (`sin_tiempo`) antes de registrar; la URL prefirmada se guarda en el progreso y un reintento reutiliza el media registrado | tests «la subida acota su timeout…» (rojo: 100), «sin presupuesto suficiente… sin registrar» y «un reintento reutiliza el media ya registrado» |
| 3 | Minor | El test de memoria no era fiable y se perdió la aserción de bytes | T-07 | `memory_reset_peak_usage()` y medición solo del envío; `PinterestConnectorTest` vuelve a comprobar `VIDEOBYTES` leyendo el cuerpo durante la petición | `PinterestVideoStreamingTest`, `PinterestConnectorTest` |
| 4 | Minor | Vídeos ya programados de de más del tope | T-07 | Se revalida al publicar (tamaño del asset y real del disco): `PermanentPublishException` `video_grande` «El video supera 512 MB para Pinterest.», sin registrar medias | test «un video ya programado por encima del tope falla de forma permanente…» (rojo) |
| 5 | Minor | Una versión futura de `LINKEDIN_API_VERSION` no avisaba | T-08 | Antigüedad negativa ⇒ aviso «es futura (aun no existe)» | `LinkedInVersionHealthTest` «una version futura… avisa» (rojo → verde) |
| 6 | Minor | Documentación de TikTok y de `app:health` incompleta | T-08 | Un scope no aprobado bloquea conectar y reconectar TODAS las cuentas de TikTok; se revierte vaciando la variable y `php artisan config:cache`; `linkedin_version` en `docs/modulos/despliegue.md`; Pinterest: presupuesto del paso y reintento | `decisiones-por-red.md`, `configuracion.md`, `despliegue.md`, `linkedin-pinterest.md` |

Verificación: filtro `Pinterest|LinkedIn|TikTok|HealthCheckTest|InfraTest|QueueProbe` en verde; Pint. Pest completo y Playwright, del coordinador. T-07 y T-08 siguen `en-revision`.

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
