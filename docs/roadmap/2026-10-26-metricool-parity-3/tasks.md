---
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
verificacion: obligatoria   # cada T-XX lleva `- **Verificación**:`; lo exige ledger-lint (exit 1 si falta)
---

# Checklist de Tareas — Paridad con Metricool, tercera ola

| | |
|---|---|
| **Estado** | en-progreso |
| **Fecha** | 2026-10-27 |
| **Plan** | [`improvement-plan.md`](./improvement-plan.md) |
| **Diseño** | [`design.md`](./design.md) — opción O1 (C-01) + T1 (C-02) |
| **Plan de pruebas** | [`test-plan.md`](./test-plan.md) |

> **⚠️ Ledger canónico de progreso.** Este fichero es la **fuente única de verdad** del avance del plan. **Cualquier** implementador —el agente `implementer`, el chat principal, o un orquestador SDD externo— **debe** marcar aquí cada tarea (checkbox + estado) al completarla y actualizar el resumen. Los ledgers propios de otras herramientas son **espejo**, no fuente.

> Las horas de este fichero son **base** (sin el +20 % de contingencia); el plan muestra las cifras con margen. Orden fijo (el aprobado por el usuario): Fase 1 (C-04d notas del inbox) → Fase 2 (C-03a vistas) → Fase 3 (C-01 Bluesky) → Fase 4 (C-03b analítica entre marcas) → Fase 5 (C-03c CSV) → Fase 6 (C-04a hashtags) → Fase 7 (C-04c lenguaje natural) → Fase 8 (C-02 Threads, al final y sin bloquear lo demás) → Fase 9 (C-04b GIFs) → Fase 10 (transversal y cierre). Tokens a ≈ 40,5k por hora base (90 % entrada, 10 % salida) y horas IA = tokens / 300.000 (ratio no calibrado), como la evaluación.

> **Restricciones que valen para todas las tareas:**
>
> - **Alcance completo aprobado** (puerta go, 2026-10-26). D1: app password de Bluesky guardada cifrada · D2: el usuario configura la app de Meta para Threads (ID, secreto y *tester*) · D3: límites de vídeo de Bluesky aceptados · D4: clave de Giphy y atribución · D5: hashtags solo desde el histórico propio, sin IA · D6: lenguaje natural con la IA existente.
> - **C-01 = O1** (contrato segregado `Connector` + `OAuthConnector` + `CredentialConnector`, catálogo de redes en `Network` y test de superficies) y **C-02 = T1** (conector `threads` propio, sin tocar `InstagramPublisher`). Bluesky: imagen de 2 MB y vídeo de unos 50 MB, ambos configurables. `PublishPostTarget` y `PollPublishStatus` no se tocan.
> - **Migraciones solo aditivas** (T-01, T-08, T-15).
> - **Nunca redes reales:** Pest con `Http::fake`; Playwright solo con `e2e-sec-*` y marcas `e2e-`, nunca el usuario 28. Los smokes reales (T-30, T-64) son del usuario.
> - **Entorno:** Laravel 13, Inertia/Vue 3, Pest con SQLite en memoria, Playwright (`desktop`, `mobile`, `mobile-360`) con axe y `expectNoOverflow`; `MEDIA_DISK=local`; UI en español; no se ejecutan servidores ni workers (Playwright levanta el suyo en T-77).
> - **MCP:** el servidor pasa de 22 a **25 herramientas** (T-04, T-11, T-45), el tope de `defaultPaginationLength`. Una 26.ª exige subirlo.
> - **Clase y primer oráculo.** Cada tarea declara si es *capacidad nueva* (primer oráculo: RED escrito antes del código) o *cambio de comportamiento* (primer oráculo: caracterización en verde antes de tocar).
> - **Sin Jira:** no hay `.claude/jira.json`.


## Resumen de progreso

| Fase | Completadas | Total | Progreso | H. humanas (real/est) | H. IA ejec. (real/est) | Supervisión (real/est) | Tokens (real/est) |
|------|------------|-------|----------|-----------------------|------------------------|------------------------|-------------------|
| Fase 1 — C-04d · Notas internas del inbox | 7 | 7 | 100% | 14,0 / 14,0h (estimado) | 1,89 / 1,89h (estimado) | 0,49 / 0,49h (estimado) | 567k / 567k (estimado) |
| Fase 2 — C-03a · Vistas de calendario guardadas | 7 | 7 | 100% | 16,0 / 16,0h (estimado) | 2,17 / 2,17h (estimado) | 0,57 / 0,57h (estimado) | 648k / 648k (estimado) |
| Fase 3 — C-01 · Bluesky (contrato segregado y catálogo de redes) | 0 | 16 | 0% | 0 / 61,5h | 0 / 8,34h | 0 / 2,08h | 0 / 2,49M |
| Fase 4 — C-03b · Analítica entre marcas | 0 | 6 | 0% | 0 / 21,5h | 0 / 2,91h | 0 / 0,73h | 0 / 871k |
| Fase 5 — C-03c · Exportar a CSV | 0 | 6 | 0% | 0 / 18,0h | 0 / 2,45h | 0 / 0,61h | 0 / 729k |
| Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA) | 0 | 6 | 0% | 0 / 18,0h | 0 / 2,44h | 0 / 0,62h | 0 / 729k |
| Fase 7 — C-04c · Programar en lenguaje natural (IA existente) | 0 | 5 | 0% | 0 / 18,0h | 0 / 2,44h | 0 / 0,62h | 0 / 729k |
| Fase 8 — C-02 · Threads (al final, sin bloquear lo demás) | 0 | 11 | 0% | 0 / 50,5h | 0 / 6,83h | 0 / 1,7h | 0 / 2,05M |
| Fase 9 — C-04b · GIFs de Giphy | 0 | 7 | 0% | 0 / 18,5h | 0 / 2,52h | 0 / 0,63h | 0 / 749k |
| Fase 10 — Transversal y cierre | 0 | 6 | 0% | 0 / 10,0h | 0 / 1,36h | 0 / 0,35h | 0 / 405k |
| **TOTAL** | **14** | **77** | **18%** | **30,0 / 246,0h** | **0 / 33,21h** | **0 / 8,3h** | **0 / 9,96M** |

> **Horas → Jira.** El worklog que imputa `jira-sync` al completar cada tarea es **Tiempo IA (ejec.) + Supervisión** (real; o estimación si no hay real). No hay `.claude/jira.json`: no aplica.

> **Diferencias con la evaluación (declaradas).** Las horas por entrega son las de la evaluación (C-04d 14h, C-03a 16h, C-01 65h, C-03b 22h, C-03c 18h, C-04a 18,5h, C-04c 18,5h, C-02 54h, C-04b 20h = 246h base). Las tareas transversales de la Fase 10 **no suman horas nuevas**: salen de las partidas de documentación, revisión y smoke de cada entrega (10h en total). T-72 (paridad MCP, 2h) sale de C-01 (1h) y C-02 (1h); T-73 (1h) de C-01; T-74 (1,5h) de C-02; T-75 (1h) de C-04b; T-76 (1,5h) de C-01 (0,5h), C-02 (0,5h) y C-04b (0,5h); T-77 (regresión, 3h) de C-01 (1h), C-02 (0,5h), C-03b, C-04a y C-04c (0,5h cada una). Por eso C-01 muestra 61,5h, C-03b 21,5h, C-04a 18h, C-04c 18h, C-02 50,5h y C-04b 18,5h en sus fases.

## Conocimiento pendiente del Curator

Candidatos del `architect` pendientes de decisión. Se conservan la ruta y el hash de la propuesta original; el Curator devolverá el `operation_id` y el recibo al aplicarlos.

```curation-refs-v1
[{"candidate":"docs/knowledge/candidates/pending/2026-10-27-conectores-contrato-segregado-y-catalogo-de-redes.md","sha256":"ac5976ab419824bd1f5bdf87bbbab144187071eac258845770339b52f17ee9db"}]
```

---


## Fase 1 — C-04d · Notas internas del inbox

**Estado**: completado · **Estimado**: 14,0h · **Real**: — · **Coste est.**: 840 € (con margen; base 700 €) · **Tokens est.**: 567k

### T-01 — Migración, modelo y factoría de `InboxCommentNote`

- **Descripción**: Tabla `inbox_comment_notes` (`user_id`, `inbox_comment_id`, `body`) con `UserScope`. Requisito: CA-17 (la nota sobrevive al resync del inbox) y C-04d de la evaluación.
- **Changelog**: Los comentarios del inbox admiten notas internas que se conservan al resincronizar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_inbox_comment_notes_table.php`, `app/Models/InboxCommentNote.php`, `database/factories/InboxCommentNoteFactory.php`, `database/factories/InboxCommentFactory.php`, `app/Models/InboxComment.php`, `tests/Feature/Inbox/InboxCommentNoteModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Inbox/InboxCommentNoteModelTest.php` → todos en verde (aislamiento por usuario y relación con el comentario)

**Criterios de aceptación**
- [x] La migración es aditiva y reversible (`down()` borra solo `inbox_comment_notes`).
- [x] El modelo usa `UserScope`: otro usuario no ve notas ajenas.
- [x] Un *upsert* del comentario (mismo `external_id`) conserva la fila y, por tanto, sus notas.

**Subtareas**
- [x] Escribir el test RED de aislamiento y de *upsert*
- [x] Migración, modelo y factoría

**Notas**: Patrón: `app/Models/SavedText.php` y `app/Models/Concerns/BelongsToUser.php` (`UserScope`); modelo del comentario en `app/Models/InboxComment.php`. El inbox hace *upsert* de `inbox_comments`, por eso las notas van en tabla aparte con FK a la fila. Migraciones solo aditivas. Nota de implementación: `InboxComment` no tenía factoría; se añade `InboxCommentFactory` (+ `HasFactory` y la relación `notes()` en el modelo) y se declaran en `Archivos`. Migración `2026_11_17_090000_…` (ya migrada).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Inbox/InboxCommentNoteModelTest.php -> 4 passed (7 assertions)

### T-02 — `InboxNoteService`, request, controlador y rutas de notas

- **Descripción**: Alta y borrado de notas de un comentario, solo del dueño. Requisito: CA-17.
- **Changelog**: Se pueden añadir y borrar notas internas en los comentarios del inbox (hasta 2.000 caracteres).
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-01
- **Tipo**: backend
- **Archivos**: `app/Services/InboxNoteService.php`, `app/Http/Controllers/InboxNoteController.php`, `app/Http/Requests/InboxNoteRequest.php`, `routes/parity.php`, `tests/Feature/Inbox/InboxNoteCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Inbox/InboxNoteCrudTest.php` → todos en verde (alta, borrado, 404 ajeno, validación)

**Criterios de aceptación**
- [x] POST crea la nota y DELETE la borra, ambos solo para el dueño.
- [x] Un comentario ajeno responde 404 sin revelar su existencia.
- [x] La auditoría no guarda el cuerpo de la nota.

**Subtareas**
- [x] Test RED de CRUD y aislamiento
- [x] Servicio, request, controlador y rutas

**Notas**: Patrón: `app/Services/SavedTextService.php`, `app/Http/Controllers/SavedTextController.php`, `app/Http/Requests/SavedTextRequest.php` y rutas en `routes/web.php:101-103` (las del inbox viven en `routes/parity.php`, donde se añaden las de notas). Comentario ajeno = 404. Texto plano, tope 2.000 caracteres (supuesto, ⚠️ confirmar). La auditoría (`app/Services/AuditLog.php`) registra la acción, nunca el cuerpo.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Inbox/InboxNoteCrudTest.php -> 7 passed (24 assertions)

### T-03 — UI del inbox: hilo de notas, alta, borrado, insignia «con nota» y filtro

- **Descripción**: En `Inbox/Index.vue`, hilo de notas bajo cada comentario, formulario de alta, borrado, insignia «con nota» y filtro. Requisito: CA-17. UI en español.
- **Changelog**: El inbox muestra un hilo de notas internas bajo cada comentario, con insignia «con nota» y filtro «Solo con nota».
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real 4,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,54h · real 0,54h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real 0,14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Inbox/Index.vue`, `resources/js/Components/Inbox/CommentNotes.vue`, `app/Http/Controllers/InboxController.php`, `app/Services/InboxService.php`, `tests/Feature/Ui/InboxNotesPageTest.php`
- **Cubre (tests)**: E2E-01, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ui/InboxNotesPageTest.php` → props Inertia con `notes` y filtro `has_notes` en verde

**Criterios de aceptación**
- [x] El hilo muestra las notas en orden cronológico con alta y borrado.
- [x] El filtro «con nota» deja solo los comentarios con notas.
- [x] La insignia aparece solo si hay notas.

**Subtareas**
- [x] Props del controlador y filtro
- [x] Componente `CommentNotes.vue` y su integración
- [x] Estados vacío y de error

**Notas**: Patrón: `resources/js/Components/Composer/NotesPanel.vue` (notas de post) y filtros de `resources/js/Pages/Inbox/Index.vue`. `InboxController` añade `has_notes` al filtro. Accesible por teclado (axe) y sin desbordes a 360 px.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui/InboxNotesPageTest.php -> 3 passed (50 assertions); tests/Feature/Parity/InboxTest.php sin regresión; npm run build OK

### T-04 — MCP: notas en `list_comments` y herramienta `add_comment_note`

- **Descripción**: `list_comments` devuelve las notas y `add_comment_note` añade una (ability `inbox:write`). Requisito: CA-17.
- **Changelog**: Los asistentes MCP ven las notas internas en list_comments y pueden añadir una con add_comment_note.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-02
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/ListComments.php`, `app/Mcp/Tools/AddCommentNote.php`, `app/Mcp/Servers/SchedulerServer.php`, `app/Mcp/Abilities.php`, `tests/Feature/Mcp/Tools/McpCommentNotesTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpCommentNotesTest.php` → todos en verde (lista con notas, alta, ability y comentario ajeno)

**Criterios de aceptación**
- [x] `list_comments` incluye `notes` por comentario.
- [x] `add_comment_note` exige `inbox:write` y rechaza comentarios ajenos.
- [x] `tools/list` ofrece 23 herramientas.

**Subtareas**
- [x] Test RED de la herramienta
- [x] Herramienta, ability y registro

**Notas**: Patrón: `app/Mcp/Tools/ReplyComment.php` y `UpdatePostNotes.php` (nunca se auditan los cuerpos); abilities en `app/Mcp/Abilities.php`; registro en `app/Mcp/Servers/SchedulerServer.php` (pasa a **23** herramientas; el tope de página es 25). Nota de implementación: los tres tests existentes que contaban 22 herramientas pasan a 23 y se declaran en `Archivos`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Mcp/Tools/McpCommentNotesTest.php -> 7 passed; tests/Feature/Mcp completo 252 passed

### T-05 — Pest de integración CA-17: la nota sobrevive al resync del inbox

- **Descripción**: Prueba de extremo a extremo de CA-17: alta de nota, resincronización con `SyncInboxComments` y comprobación de que la nota sigue ahí, también por MCP.
- **Changelog**: La nota interna de un comentario del inbox no se pierde ni se duplica al resincronizar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-02, T-04
- **Tipo**: test
- **Archivos**: `tests/Feature/Inbox/InboxNotesResyncTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Inbox/InboxNotesResyncTest.php` → en verde (nota intacta tras 2 sincronizaciones)

**Criterios de aceptación**
- [x] Dos resyncs seguidos no pierden ni duplican notas.
- [x] `list_comments` devuelve la nota tras el resync.

**Subtareas**
- [x] Escribir el test de integración
- [x] Ajustar el *upsert* si el test revela una pérdida

**Notas**: Patrón: `tests/Feature/Jobs/` para `app/Jobs/SyncInboxComments.php` y `Http::fake` de Graph; sin redes reales.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Inbox/InboxNotesResyncTest.php -> 2 passed (nota intacta tras 2 sincronizaciones; list_comments y add_comment_note)

### T-06 — E2E del inbox con notas y axe

- **Descripción**: Escenario Playwright de notas del inbox con axe y sin desbordes. Requisito: CA-17.
- **Changelog**: Cobertura E2E del flujo de notas del inbox (alta, insignia, filtro y borrado) con accesibilidad y móvil.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-03
- **Tipo**: test
- **Archivos**: `tests/e2e/inbox-notes.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-01
- **Verificación**:
  - `npx playwright test tests/e2e/inbox-notes.spec.ts --project=desktop` → verde en desktop
  - `npx playwright test tests/e2e/inbox-notes.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos (axe y sin desbordes)

**Criterios de aceptación**
- [x] Alta, borrado, insignia y filtro cubiertos.
- [x] 0 violaciones axe serious/critical y `expectNoOverflow` en 375 y 360 px.

**Subtareas**
- [x] Helper de semilla
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts` y helpers `loginAs`, `axeSeriousCritical`, `expectNoOverflow` (`tests/e2e/helpers.ts:538`); usuarios `e2e-sec-<proyecto>@example.test`, marcas `e2e-`. Nunca el usuario 28. Semilla `seedInboxComment` (nueva en `helpers.ts`).

**Verificación ejecutada**: npm run build; E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/inbox-notes.spec.ts --project=desktop --project=mobile --project=mobile-360 -> 3 passed (axe y sin desbordes)

### T-07 — Documentación y revisión de robustez de C-04d

- **Descripción**: Documentar las notas del inbox y revisar robustez (aislamiento, límites, auditoría).
- **Changelog**: Documentadas las notas internas del inbox para usuarios y para asistentes MCP.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-05, T-06
- **Tipo**: docs
- **Archivos**: `docs/modulos/inbox.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `docs/modulos/inbox.md` y `mcp.md` describen notas, insignia, filtro y `add_comment_note` → coherente con T-01..T-04

**Criterios de aceptación**
- [x] La guía de usuario explica las notas.
- [x] El módulo MCP lista `add_comment_note` con su ability.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez (aislamiento y límites)

**Notas**: Patrón: `docs/modulos/inbox.md` y `docs/modulos/mcp.md:116`. La cifra global de herramientas MCP se actualiza en T-76.

**Verificación ejecutada**: lectura: docs/modulos/inbox.md (notas, insignia, filtro, add_comment_note) y mcp.md (23 herramientas, add_comment_note con inbox:write) coherentes con T-01..T-04; guía de usuario con sección «Inbox de comentarios». Robustez revisada: UserScope (404 ajeno, tests T-02/T-04), tope 2.000 y vacío rechazados en web y MCP, cuerpo ausente de auditoría (tests), cascade al borrar el comentario.

## Fase 2 — C-03a · Vistas de calendario guardadas

**Estado**: completado · **Estimado**: 16,0h · **Real**: — · **Coste est.**: 960 € (con margen; base 800 €) · **Tokens est.**: 648k

### T-08 — Migración, modelo y factoría de `CalendarView`

- **Descripción**: Tabla `calendar_views` (`user_id`, `name`, `filters` JSON con marca, red, estado y vista, `is_default`). Requisito: CA-11.
- **Changelog**: Base de datos de las vistas guardadas del calendario: nombre, filtros y vista por defecto.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: db
- **Archivos**: `database/migrations/*_create_calendar_views_table.php`, `app/Models/CalendarView.php`, `database/factories/CalendarViewFactory.php`, `tests/Feature/Calendar/CalendarViewModelTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Calendar/CalendarViewModelTest.php` → en verde (aislamiento y casting de `filters`)

**Criterios de aceptación**
- [x] Migración aditiva y reversible.
- [x] `UserScope` aísla por usuario.
- [x] `filters` se castea a array.

**Subtareas**
- [x] Test RED
- [x] Migración, modelo y factoría

**Notas**: Patrón: `app/Models/SavedText.php` y `Concerns/BelongsToUser.php`. Filtros de un solo valor (supuesto de la evaluación; varios valores serían +4h). Migración aditiva.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Calendar/CalendarViewModelTest.php -> 4 passed (7 assertions); php artisan migrate aplicada

### T-09 — `CalendarViewService`, request, controlador y rutas CRUD

- **Descripción**: Alta, renombrado, borrado y vista por defecto; valida que marca y red sean del usuario. Requisito: CA-11.
- **Changelog**: Se pueden guardar, renombrar, borrar y fijar por defecto vistas del calendario con marca, red, estado y vista.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-08
- **Tipo**: backend
- **Archivos**: `app/Services/CalendarViewService.php`, `app/Http/Controllers/CalendarViewController.php`, `app/Http/Requests/CalendarViewRequest.php`, `routes/web.php`, `tests/Feature/Calendar/CalendarViewCrudTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Calendar/CalendarViewCrudTest.php` → en verde (CRUD, marca ajena rechazada, única por defecto)

**Criterios de aceptación**
- [x] CRUD funciona solo para el dueño.
- [x] Una marca o red que no es del usuario se rechaza.
- [x] Solo una vista por defecto por usuario.

**Subtareas**
- [x] Test RED
- [x] Servicio, request, controlador y rutas

**Notas**: Patrón: `app/Services/SavedTextService.php`, `app/Http/Controllers/SavedTextController.php` y `CalendarController.php`; `app/Services/CalendarService.php` interpreta los filtros.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Calendar/CalendarViewCrudTest.php -> 10 passed (46 assertions)

### T-10 — UI del calendario: selector de vistas, guardar, renombrar, borrar y vista por defecto

- **Descripción**: Selector de vistas en `Calendar/Index.vue` con «Guardar vista», renombrar, borrar y por defecto. Requisito: CA-11.
- **Changelog**: El calendario tiene un menú «Vistas»: guarda los filtros actuales, aplica una vista, la renombra, la borra o la fija por defecto.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real 4,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,54h · real 0,54h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real 0,14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Calendar/Index.vue`, `resources/js/Components/Calendar/ViewsMenu.vue`, `app/Http/Controllers/CalendarController.php`, `tests/Feature/Ui/CalendarViewsPageTest.php`
- **Cubre (tests)**: E2E-02, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ui/CalendarViewsPageTest.php` → props con `views` y aplicación de `view` en verde

**Criterios de aceptación**
- [x] Elegir una vista aplica marca, red, estado y vista.
- [x] Guardar, renombrar y borrar desde el menú.
- [x] La vista por defecto se aplica al abrir el calendario.

**Subtareas**
- [x] Props `views` y `view` en el controlador
- [x] Componente `ViewsMenu.vue`
- [x] Estados vacío y de error

**Notas**: Patrón: filtros de `resources/js/Pages/Calendar/Index.vue` (la lista de redes lee el catálogo desde T-17) y menú de `Composer/SavedTextsMenu.vue`. Móvil 375/360 sin desborde. Nota de implementación: en la web la vista se elige con `saved_view=<id>` (el parámetro `view` ya es semana/mes/lista); props `views` y `active_view`. La vista por defecto se aplica solo si la URL no trae ningún filtro. Una vista con marca borrada se abre sin esa marca.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui/CalendarViewsPageTest.php -> 5 passed; tests/Feature/Calendar sin regresión (33 passed); npm run build OK

### T-11 — MCP: `list_calendar_views` y parámetro `view` en `get_calendar`

- **Descripción**: Herramienta `list_calendar_views` y `view` en `get_calendar` con el mismo resultado que la UI. Requisito: CA-11.
- **Changelog**: Los asistentes MCP pueden listar las vistas guardadas del calendario y pedir get_calendar con view=<id>.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-09
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/ListCalendarViews.php`, `app/Mcp/Tools/GetCalendar.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpCalendarViewsTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`, `tests/Feature/Mcp/Tools/McpCommentNotesTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpCalendarViewsTest.php` → en verde (`view` equivale a los filtros guardados; vista ajena no existe)

**Criterios de aceptación**
- [x] `get_calendar view=<id>` devuelve lo mismo que los filtros equivalentes.
- [x] Una vista de otro usuario responde como inexistente.

**Subtareas**
- [x] Test RED
- [x] Herramienta y parámetro

**Notas**: Patrón: `app/Mcp/Tools/GetCalendar.php` y `ListCampaigns.php`; registro en `SchedulerServer.php` (pasa a **24**). Nota de implementación: `view` ya era week/month/list; ahora acepta además el id numérico de una vista guardada (el esquema deja de ser enum). Los tests que contaban 23 herramientas pasan a 24.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Mcp -> 258 passed (McpCalendarViewsTest 6; tools/list = 24)

### T-12 — Pest de integración CA-11 (vistas guardadas)

- **Descripción**: Prueba que guardar «Pendientes IG» y elegirla desde otro filtro aplica los tres filtros, en web y MCP.
- **Changelog**: Cobertura de extremo a extremo de las vistas guardadas del calendario en web y MCP.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-10, T-11
- **Tipo**: test
- **Archivos**: `tests/Feature/Calendar/CalendarViewsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Calendar/CalendarViewsFlowTest.php` → en verde

**Criterios de aceptación**
- [x] Los tres filtros se aplican al elegir la vista.
- [x] La regresión de `tests/Feature/Calendar/CalendarTest.php` sigue en verde.

**Subtareas**
- [x] Escribir el test de flujo

**Notas**: Patrón: `tests/Feature/Calendar/CalendarTest.php`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Calendar -> 31 passed (CalendarViewsFlowTest 3; CalendarTest sin regresión)

### T-13 — E2E de vistas guardadas con axe

- **Descripción**: Escenario Playwright: guardar, aplicar, renombrar y borrar una vista. Requisito: CA-11.
- **Changelog**: Cobertura E2E del flujo de vistas guardadas del calendario (guardar, aplicar, renombrar, borrar y por defecto) con accesibilidad y móvil.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-10
- **Tipo**: test
- **Archivos**: `tests/e2e/calendar-views.spec.ts`, `tests/e2e/helpers.ts`, `resources/js/Components/Calendar/ViewsMenu.vue`, `app/Http/Controllers/CalendarViewController.php`, `tests/Feature/Calendar/CalendarViewCrudTest.php`
- **Cubre (tests)**: E2E-02
- **Verificación**: `npx playwright test tests/e2e/calendar-views.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos (axe y sin desbordes)

**Criterios de aceptación**
- [x] Flujo completo cubierto.
- [x] 0 violaciones axe serious/critical y sin desborde.

**Subtareas**
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts` y `seedComposerPosts` (`helpers.ts:519`). Nota de implementación: el E2E destapó dos fallos que se corrigen aquí: el panel de vistas desbordaba en móvil (ahora ocupa el ancho) y borrar la vista abierta redirigía a `saved_view=<id>` inexistente (404); ahora vuelve al calendario sin ella.

**Verificación ejecutada**: npm run build; E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/calendar-views.spec.ts --project=desktop --project=mobile --project=mobile-360 -> 6 passed (axe y sin desbordes); tests/Feature/Calendar 32 passed

### T-14 — Documentación y revisión de robustez de C-03a

- **Descripción**: Documentar las vistas guardadas y revisar robustez.
- **Changelog**: Documentadas las vistas guardadas del calendario para usuarios y para asistentes MCP.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-12, T-13
- **Tipo**: docs
- **Archivos**: `docs/modulos/calendario.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `calendario.md` y `mcp.md` describen vistas, `list_calendar_views` y `view` → coherente con T-08..T-11

**Criterios de aceptación**
- [x] La guía de usuario explica cómo guardar y aplicar una vista.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/calendario.md`, `docs/modulos/mcp.md`.

**Verificación ejecutada**: lectura: calendario.md y mcp.md describen vistas, list_calendar_views y view=<id> (24 herramientas), coherentes con T-08..T-11; guía de usuario con «Vistas guardadas». Robustez: UserScope (404 ajeno), filtros validados, vista con marca borrada degrada sin romper, única por defecto, borrado de la vista abierta sin 404 (tests y E2E).

## Fase 3 — C-01 · Bluesky (contrato segregado y catálogo de redes)

**Estado**: borrador · **Estimado**: 61,5h · **Real**: — · **Coste est.**: 3.690 € (con margen; base 3.075 €) · **Tokens est.**: 2,49M

### T-15 — Contrato segregado: `OAuthConnector`, `CredentialConnector` y `credential_secret`

- **Descripción**: `Connector` conserva lo común; nacen `OAuthConnector` y `CredentialConnector`; Meta, Google y TikTok pasan a `OAuthConnector`; `OAuthController` comprueba `instanceof`; columna cifrada `credential_secret`. Requisito: `design.md` §4 (O1) y CA-01.
- **Changelog**: Preparado el contrato de conexión para redes con contraseña de aplicación: el secreto se guarda cifrado y nunca se muestra.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en verde antes de tocar (se conserva como regresión).
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Connectors/Contracts/Connector.php`, `app/Connectors/Contracts/OAuthConnector.php`, `app/Connectors/Contracts/CredentialConnector.php`, `app/Connectors/Meta/MetaConnector.php`, `app/Connectors/Google/GoogleConnector.php`, `app/Connectors/TikTok/TikTokConnector.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `app/Connectors/Data/ConnectionCandidate.php`, `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php`, `database/migrations/*_add_credential_secret_to_social_accounts.php`, `tests/Feature/Connectors/ConnectorContractTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Connectors tests/Feature/Auth` → regresión de Meta, Google y TikTok en verde (caracterización previa)
  - `php artisan test --compact tests/Feature/Connectors/ConnectorContractTest.php` → en verde (secreto cifrado, oculto y borrado al desconectar)

**Criterios de aceptación**
- [x] Los 3 conectores actuales solo cambian `implements Connector` por `implements OAuthConnector`.
- [x] `credential_secret` es `encrypted`, `hidden` y se borra en `disconnect`.
- [x] `ConnectionCandidate::toPublicArray` nunca incluye el secreto.

**Subtareas**
- [x] Caracterización en verde de OAuth y refresco
- [x] Interfaces y migración
- [x] `OAuthController` con `instanceof`
- [x] `SocialAccountService` guarda y borra el secreto

**Notas**: Patrón: `app/Connectors/Contracts/Connector.php:20-54`, `app/Http/Controllers/OAuth/OAuthController.php:47,91`, `app/Models/SocialAccount.php:41-56` (cifrado y `hidden`), `app/Connectors/Data/ConnectionCandidate.php` (`?string $secret` fuera de `toPublicArray`) y `app/Services/SocialAccountService.php:92` (`disconnect` borra el secreto). **No cambia el comportamiento** de las 3 redes actuales. Migración aditiva.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connectors tests/Feature/Auth tests/Feature/Connections -> 478 passed (2738 assertions), incluye ConnectorContractTest (secreto cifrado, oculto y borrado al desconectar; 404 OAuth para CredentialConnector); php artisan migrate aplicada. Caracterización previa: 256 passed antes de tocar.

### T-16 — Catálogo de redes en `Network` y `shell.networks`

- **Descripción**: `Network` pasa a catálogo (`label`, `shortLabel`, `color`, `authKind`, `providers()` derivado y `offered()`) y las listas fijas del backend lo leen. Requisito: `design.md` §2 O1.
- **Changelog**: Las redes tienen un catálogo único (nombre, color, proveedor y forma de conexión) que alimenta rutas, IA, MCP y la interfaz.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en verde antes de tocar (se conserva como regresión).
- **Tiempo humano**: est. 4,0h · real 4,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,54h · real 0,54h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real 0,14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Connectors/Network.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `app/Services/AiAssistant.php`, `app/Http/Controllers/AiAssistantController.php`, `app/Mcp/Tools/GetStats.php`, `app/Mcp/Tools/GetCalendar.php`, `app/Mcp/Tools/GetBestTimes.php`, `tests/Unit/Networks/NetworkCatalogTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/Networks/NetworkCatalogTest.php` → en verde (catálogo completo y `offered()` filtra por registro)
  - `php artisan test --compact tests/Feature/Ui/ShellTest.php` → en verde

**Criterios de aceptación**
- [x] `shell.networks` incluye etiqueta, color, proveedor y `authKind`.
- [x] Las rutas usan `Network::providers()` y la IA `Rule::enum(Network::class)`.
- [x] Las descripciones del MCP salen de `Network::cases()`.

**Subtareas**
- [x] Test de caracterización
- [x] Catálogo en el enum
- [x] Migrar listas backend

**Notas**: Patrón: `app/Connectors/Network.php:15-38`, `app/Http/Middleware/HandleInertiaRequests.php:106` (`shell.networks`), `routes/web.php:56,58`, `AiAssistant.php:86`, `AiAssistantController.php:30` (`Rule::enum`), descripciones de `Mcp/Tools/{GetStats,GetCalendar,GetBestTimes}.php`. `EXPECTED_FLOWS` (`app/Analytics/Metric.php:63-68`) y `SUPPORTED` (`AnalyticsService.php:58-63`) se quedan donde están. Se añade el caso `Bluesky` con `authKind=credentials`.

**Verificación ejecutada**: php artisan test --compact tests/Unit tests/Feature/Ui tests/Feature/Connections tests/Feature/Mcp -> 597 passed (2990 assertions), incluye NetworkCatalogTest y ShellTest (shell.networks con color, short_label, provider y auth_kind). Caracterización previa: suite completa 1971 verde antes de tocar.

### T-17 — Listas del frontend leen el catálogo y el icono de Bluesky

- **Descripción**: `NetworkTabs.vue`, `Calendar/Index.vue`, `format.js`, `composerOptions.js` y `Welcome.vue` dejan sus listas fijas; icono de Bluesky.
- **Changelog**: Calendario, panel, portada y pestañas de red leen las redes del catálogo y ya hay icono de Bluesky.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en verde antes de tocar (se conserva como regresión).
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-16
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/NetworkIcon.vue`, `resources/js/Pages/Dashboard/Partials/NetworkTabs.vue`, `resources/js/Pages/Dashboard/Partials/format.js`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Composables/composerOptions.js`, `resources/js/Pages/Welcome.vue`, `tests/Feature/Ui/ShellTest.php`
- **Cubre (tests)**: M-04
- **Verificación**:
  - `php artisan test --compact tests/Feature/Ui/ShellTest.php` → en verde
  - `npm run build` → compila sin errores

**Criterios de aceptación**
- [x] Ninguna lista fija de redes queda fuera de `NetworkIcon.vue`.
- [x] El icono de Bluesky se ve y es accesible (nombre).

**Subtareas**
- [x] Migrar cada lista
- [x] Icono de Bluesky

**Notas**: Patrón: `resources/js/Components/NetworkIcon.vue:28`, `Pages/Dashboard/Partials/{NetworkTabs.vue:20,format.js:5}`, `Pages/Calendar/Index.vue:36`, `Composables/composerOptions.js:43`; `NetworkChip.vue:13` ya lee `shell.networks`. `Welcome.vue` es pública: recibe la lista por prop.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui tests/Unit/Networks -> 57 passed (719 assertions); npm run build -> compila sin errores; Playwright desktop calendar-views + pages (dashboards por red, consola limpia) -> 33 passed; E2E-02 (vista por defecto) falla solo en paralelo y pasa aislado (2 passed). Caracterización: pages.spec en verde antes de tocar.

### T-18 — Test de superficies de red y conteo de reglas de media

- **Descripción**: `NetworkCoverageTest` recorre `Network::cases()` y exige tipos de contenido, `EXPECTED_FLOWS`, `SUPPORTED`, icono, color y etiqueta; actualiza el conteo de reglas. Requisito: `design.md` §2 (red a medias = fallo de CI).
- **Changelog**: Una red nueva ya no puede salir a medias: un test de integridad exige tipos, métricas, zona, icono, color y etiqueta.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-17
- **Tipo**: test
- **Archivos**: `tests/Unit/Networks/NetworkCoverageTest.php`, `tests/Unit/MediaValidator/MediaValidatorTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/Networks/NetworkCoverageTest.php` → en verde para las redes registradas (el RED falla con Bluesky sin tipos)

**Criterios de aceptación**
- [x] Una red añadida sin una superficie hace fallar el test.
- [x] El conteo de reglas se actualiza al añadir Bluesky (T-21) y Threads (T-56).

**Subtareas**
- [x] Escribir el test
- [x] Ajustar el conteo de reglas con cada red

**Notas**: Patrón: `tests/Unit/MediaValidator/MediaValidatorTest.php:35` (hoy espera 13 reglas). El test hace *grep* de literales de red en `resources/js` fuera de `NetworkIcon.vue`.

**Verificación ejecutada**: php artisan test --compact tests/Unit/Networks tests/Unit -> 278 passed (653 assertions). RED comprobado: ContentType::forNetwork(Bluesky) = [] y sin EXPECTED_FLOWS, por lo que surfaceGaps(bluesky) devolvería huecos; el test recorre Network::offered() (Bluesky entra al registrarse, T-26). Conteo de reglas de media derivado de ContentType::cases() (13 hoy).

### T-19 — `BlueskyClient` y `BlueskySession` (sesión, refresco y bloqueo)

- **Descripción**: Resolver handle → DID → PDS, `createSession`, `refreshSession` y recreación con la app password, con bloqueo por cuenta. Requisito: D1, CA-01 y `design.md` §2.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real —
- **Tiempo IA (ejec.)**: est. 0,47h · real —
- **Supervisión**: est. 0,12h (≈25 % IA) · real —
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyClient.php`, `app/Connectors/Bluesky/BlueskySession.php`, `app/Connectors/Bluesky/BlueskyConnector.php`, `config/social.php`, `tests/Feature/Connectors/Bluesky/BlueskySessionTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskySessionTest.php` → en verde con `Http::fake` (sesión, `ExpiredToken` → recreación, `ReconnectRequiredException`)

**Criterios de aceptación**
- [ ] La conexión guarda tokens cifrados y `credential_secret`, con `external_id` = DID.
- [ ] Un `ExpiredToken` recrea la sesión sin cortar la cuenta.
- [ ] Un test de regresión demuestra que `RefreshSocialAccount` no marca reconexión con `refresh_expires_at` nulo.

**Subtareas**
- [ ] Test RED
- [ ] Cliente XRPC
- [ ] Sesión con bloqueo y recuperación

**Notas**: Patrón: `app/Connectors/Meta/GraphClient.php:159` (cliente y traducción de errores) y `app/Jobs/RefreshSocialAccount.php:70,120`. `refresh_expires_at = null` para que `permanentReason()` (`:76-87`) no marque reconexión; `Cache::lock("bsky-session:{id}")`; `exp` del JWT leído sin verificar firma. Entryway en `config/social.php`.

### T-20 — Ruta, controlador y formulario de credenciales en Conexiones

- **Descripción**: `CredentialConnectionController@store` y formulario «handle + app password» en Conexiones. Requisito: CA-01, D1.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-15, T-16, T-19
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/CredentialConnectionController.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Brands/Connections.vue`, `tests/Feature/Connections/CredentialConnectionTest.php`
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --compact tests/Feature/Connections/CredentialConnectionTest.php` → en verde (alta, *throttle*, credenciales inválidas, no filtra el secreto)

**Criterios de aceptación**
- [ ] Con handle y app password válidos (API simulada) la cuenta aparece conectada.
- [ ] Una contraseña inválida da un error claro sin guardar nada.
- [ ] El secreto no aparece en props, logs ni auditoría.

**Subtareas**
- [ ] Test RED
- [ ] Controlador y ruta
- [ ] Formulario

**Notas**: Patrón: `app/Http/Controllers/ConnectionController.php:53`, `OAuthController` y `resources/js/Pages/Brands/Connections.vue`. `POST brands/{brand}/connections/{provider}/credentials` con *throttle* y CSRF; nunca se devuelve ni se registra la contraseña. Registro condicional de Bluesky en `AppServiceProvider.php:52-66`.

### T-21 — Tipos, reglas de media y conteo de grafemas de Bluesky

- **Descripción**: `bluesky_post` y `bluesky_video` en `ContentType` y `media_specs`; `TextLength` (PHP y JS) con modos `chars`, `graphemes` y `threads`; topes de imagen (2 MB) y vídeo (50 MB) configurables con rechazo al guardar. Requisito: CA-02.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-18
- **Tipo**: backend
- **Archivos**: `app/Enums/ContentType.php`, `config/media_specs.php`, `config/social.php`, `app/Support/TextLength.php`, `resources/js/Composables/textLength.js`, `app/Services/MediaValidator.php`, `app/Http/Controllers/PostController.php`, `resources/js/Components/Composer/NetworkCounters.vue`, `tests/Unit/MediaValidator/BlueskyRulesTest.php`, `tests/Unit/TextLengthTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/TextLengthTest.php` → en verde (tabla de emojis compuestos)
  - `php artisan test --compact tests/Unit/MediaValidator/BlueskyRulesTest.php` → en verde (301 grafemas rechazado, 300 con emojis aceptado, vídeo > tope rechazado)

**Criterios de aceptación**
- [ ] 301 grafemas se rechaza en compositor y `create_post` con el mismo mensaje; 300 con emojis compuestos se acepta.
- [ ] Un vídeo mayor que `video_max_bytes` se rechaza al guardar con el mensaje claro.
- [ ] `TextLength` PHP y JS dan lo mismo en la tabla de casos.

**Subtareas**
- [ ] Test RED (CA-02)
- [ ] Tipos y reglas
- [ ] `TextLength` PHP y JS
- [ ] Contadores del compositor

**Notas**: Patrón: `app/Enums/ContentType.php`, `config/media_specs.php`, `app/Services/MediaValidator.php:118-140` (`mb_strlen`), `PostController.php:213-217` (`max_chars`), `resources/js/Composables/textCount.js` y `Composer/NetworkCounters.vue`. Espejo JS con `Intl.Segmenter`; tope de 3000 bytes del lexicon. Mensaje: «Bluesky solo admite vídeos de hasta 50 MB en esta instalación; este pesa X MB.»

### T-22 — Recompresión de imágenes (> tope) con GD

- **Descripción**: `ImageDownscaler` crea una copia derivada y cacheada cuando la imagen supera `image_max_bytes`; si no baja, falla el guardado. Requisito: CA-03.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-21
- **Tipo**: backend
- **Archivos**: `app/Services/Media/ImageDownscaler.php`, `app/Services/MediaStorage.php`, `app/Services/MediaValidator.php`, `tests/Feature/Media/ImageDownscalerTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/ImageDownscalerTest.php` → en verde (3 MB → ≤ tope, copia cacheada, imposible → error al validar)

**Criterios de aceptación**
- [ ] Una imagen de 3 MB produce una copia derivada ≤ 2.000.000 bytes.
- [ ] La copia se reutiliza por media y versión de regla.
- [ ] Si no puede bajar del tope, el guardado falla con mensaje claro.

**Subtareas**
- [ ] Test RED
- [ ] `ImageDownscaler` con GD
- [ ] Integración con la validación

**Notas**: Patrón: `app/Services/MediaStorage.php` (copias y disco) y `app/Services/MediaProbe.php`. Tope por defecto 2.000.000 bytes (decisión del usuario 2026-10-27), configurable; bajar a 1.000.000 si un PDS lo exige. La original no se toca; se avisa de la recompresión.

### T-23 — `BlueskyPublisher`: texto, `Facets` e imágenes

- **Descripción**: Publicar texto y de 0 a 4 imágenes (`uploadBlob`, `aspectRatio`, `langs`) con *facets* en bytes UTF-8. Requisito: CA-03.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-21, T-22
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Bluesky/Facets.php`, `app/Support/Hashtags.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Connectors/Bluesky/BlueskyPublisherTest.php`, `tests/Unit/Bluesky/FacetsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/Bluesky/FacetsTest.php` → en verde (índices en bytes, emojis, tags de 64 grafemas)
  - `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyPublisherTest.php` → en verde (4 *blobs* ≤ tope y registro con *facets*)

**Criterios de aceptación**
- [ ] Se suben 4 *blobs* ≤ tope y el registro lleva los *facets* de enlaces y hashtags.
- [ ] Los índices coinciden en bytes con emojis compuestos.
- [ ] Una mención no resoluble queda como texto plano.

**Subtareas**
- [ ] Test RED (CA-03)
- [ ] `Facets` y `Hashtags`
- [ ] Publicador de texto e imágenes

**Notas**: Patrón: `app/Connectors/Contracts/Publisher.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php` (errores y `PublishResult`) y `app/Connectors/Data/PublishRequest.php`. `App\Support\Hashtags` (extracción compartida con C-04a/T-43) alimenta `Facets`; `@menciones` solo si `resolveHandle` resuelve. Tests de tabla con emojis compuestos.

### T-24 — Vídeo de Bluesky: `uploadVideo`, `getJobStatus` y límites

- **Descripción**: Publicador con `StatusPoller`: comprobación de `getUploadLimits` y del email verificado, `getServiceAuth`, subida en streaming y sondeo hasta el *blob*. Requisito: CA-05, D3.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Bluesky/BlueskyClient.php`, `tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php` → en verde (procesando → publicada con enlace `bsky.app`; `canUpload` falso y email sin verificar → error permanente)

**Criterios de aceptación**
- [ ] El destino pasa a `procesando` y, al devolver `getJobStatus` el *blob*, a `publicada` con su enlace.
- [ ] `FAILED` se traduce con su `failureCode`.
- [ ] Email sin verificar o cupo agotado dan un mensaje claro.

**Subtareas**
- [ ] Test RED (CA-05)
- [ ] Límites y *service auth*
- [ ] Subida en streaming
- [ ] `poll` y `createRecord`

**Notas**: Patrón: `app/Jobs/PollPublishStatus.php:86-128`, `app/Connectors/Support/{StepBudget,MediaChunkReader}.php` (85 s por paso; job 110 s) y `Contracts/StatusPoller.php`. ⚠️ `aud` de `getServiceAuth` sin verificar: se comprueba en el smoke (T-30). Con `presentation: gif` para los GIF de C-04b.

### T-25 — Idempotencia por `rkey` TID, `findPublished` y errores traducidos

- **Descripción**: TID generada y persistida con `checkpoint()` antes de `createRecord`; reintentos reutilizan la `rkey`; `findPublished` con `getRecord`. Requisito: CA-04.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/Tid.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`, `tests/Unit/Bluesky/TidTest.php`, `tests/Feature/Connectors/Bluesky/BlueskyIdempotencyTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/Bluesky/TidTest.php` → en verde (13 caracteres base32-sortable, monótona)
  - `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyIdempotencyTest.php` → en verde (timeout en `createRecord` y reintento no duplica)

**Criterios de aceptación**
- [ ] Tras agotar el tiempo en `createRecord`, el reintento encuentra el registro y no crea un segundo post.
- [ ] La `rkey` se persiste antes de la llamada.

**Subtareas**
- [ ] Test RED (CA-04)
- [ ] TID
- [ ] `findPublished` y errores

**Notas**: Patrón: `PublishRequest::checkpoint()` y `InstagramPublisher::findPublished`. ⚠️ error del PDS ante `rkey` repetida sin verificar: ante cualquier error de `createRecord` se hace `getRecord` antes de fallar.

### T-26 — `BlueskyInsights` y métricas (`POST_COUNT`)

- **Descripción**: Métricas de cuenta (seguidores y posts) y de post (me gusta, comentarios, compartidos) con `views` = `null`. Requisito: CA-06.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-16
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyInsights.php`, `app/Analytics/Metric.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Connectors/Bluesky/BlueskyInsightsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyInsightsTest.php` → en verde (seguidores, posts, por post y `views` nulo)

**Criterios de aceptación**
- [ ] Se guardan seguidores y, por post, likes, compartidos (reposts + citas) y comentarios.
- [ ] `views` queda `null`, nunca 0.
- [ ] `NetworkCoverageTest` (T-18) sigue en verde.

**Subtareas**
- [ ] Test RED (CA-06)
- [ ] Lector de insights
- [ ] Entradas de `Metric` y `SUPPORTED`

**Notas**: Patrón: `app/Connectors/Contracts/InsightsReader.php`, `app/Analytics/Metric.php:63-68` (`EXPECTED_FLOWS['bluesky'] = []`, como TikTok), `AnalyticsService.php:58-63`, `app/Analytics/SnapshotCollector.php`. `POST_COUNT` es un *stock* nuevo. Lectura por AppView público ⚠️ (`public.api.bsky.app`, sin verificar). `null` = no disponible, nunca 0.

### T-27 — Pest de integración de Bluesky (CA-01..CA-06) y regresión de las otras redes

- **Descripción**: Suite de extremo a extremo con `Http::fake`: conexión → programar → publicar (texto, imágenes, vídeo) → métricas. Más la regresión de Meta, Google y TikTok.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 8,0h · real —
- **Tiempo IA (ejec.)**: est. 1,08h · real —
- **Supervisión**: est. 0,27h (≈25 % IA) · real —
- **Previsión IA**: 292k in / 32k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-24, T-25, T-26
- **Tipo**: test
- **Archivos**: `tests/Feature/Connectors/Bluesky/BlueskyFlowTest.php`, `tests/Feature/Publishing/BlueskyPublishingTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Snapshots` → todo en verde

**Criterios de aceptación**
- [ ] CA-01..CA-06 cubiertos de extremo a extremo.
- [ ] Sin regresión en los 3 conectores existentes.

**Subtareas**
- [ ] Escribir los flujos
- [ ] Cerrar huecos que revele la suite

**Notas**: Patrón: `tests/Feature/Connectors/`, `tests/Feature/Publishing/` y `tests/Feature/Snapshots/`. Sin redes reales.

### T-28 — E2E de Bluesky: conexión y superficies con axe

- **Descripción**: Escenario Playwright: conectar Bluesky (API simulada vía semilla), ver su icono y filtros en calendario y dashboard. Requisito: CA-01 y parte de CA-10.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-17
- **Tipo**: test
- **Archivos**: `tests/e2e/bluesky.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-03
- **Verificación**: `npx playwright test tests/e2e/bluesky.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos (axe y sin desbordes)

**Criterios de aceptación**
- [ ] Formulario de credenciales accesible y sin desborde.
- [ ] Icono y filtros de Bluesky en calendario y dashboard.

**Subtareas**
- [ ] Semillas
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/oauth.spec.ts`, `seedExtraAccount` (`helpers.ts:455`, ampliar a `bluesky`). La API real nunca se llama.

### T-29 — Documentación y revisión de robustez de Bluesky

- **Descripción**: Documentar el conector, los límites y la sesión; revisión de robustez (secretos, bloqueos, tiempos).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-28
- **Tipo**: docs
- **Archivos**: `docs/modulos/conexiones.md`, `docs/modulos/publicacion.md`, `docs/modulos/analitica.md`, `docs/referencia/decisiones-por-red.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs listan tipos, límites (300 grafemas, 2 MB, 50 MB), sesión y métricas de Bluesky → coherente con T-15..T-26

**Criterios de aceptación**
- [ ] Los límites del doc coinciden con `config/social.php` y `media_specs.php`.

**Subtareas**
- [ ] Redactar los docs
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/conexiones.md`, `publicacion.md`, `analitica.md` y `docs/referencia/decisiones-por-red.md`.

### T-30 — Smoke real de Bluesky con el usuario

- **Descripción**: El usuario conecta su cuenta con una app password y publica los tipos. Resuelve los ⚠️ de `aud` del *service auth*, `rkey` repetida y AppView.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-73
- **Tipo**: qa
- **Archivos**: `docs/roadmap/2026-10-26-metricool-parity-3/test-plan.md`
- **Cubre (tests)**: M-01
- **Verificación**: lectura: M-01 del `test-plan.md` completado y anotado con los resultados reales → M-01 marcado

**Criterios de aceptación**
- [ ] Texto, 4 imágenes, vídeo y métricas verificados en la cuenta real.
- [ ] Los ⚠️ resueltos o convertidos en un riesgo documentado.

**Subtareas**
- [ ] Ejecutar M-01
- [ ] Anotar hallazgos

**Notas**: Orden: la guía T-73 se ejecuta antes. Es manual y con la cuenta real del usuario (M-01). No hay automatización.


## Fase 4 — C-03b · Analítica entre marcas

**Estado**: borrador · **Estimado**: 21,5h · **Real**: — · **Coste est.**: 1.290 € (con margen; base 1.075 €) · **Tokens est.**: 871k

### T-31 — `AggregateAnalytics`: suma por métrica con `null`, tabla por marca y por red

- **Descripción**: Servicio que llama a `AnalyticsService::summary()` por marca y suma con las reglas de `null`, *stocks* frente a flujos, tabla por marca, desglose por red y serie. Requisito: CA-12.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-26
- **Tipo**: backend
- **Archivos**: `app/Services/AggregateAnalytics.php`, `tests/Feature/Analytics/AggregateAnalyticsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Analytics/AggregateAnalyticsTest.php` → en verde (suma, `null`, *stocks*, una fila por marca)

**Criterios de aceptación**
- [ ] Cada métrica es la suma de las marcas con dato y `null` si ninguna lo tiene.
- [ ] Hay una fila por marca y el desglose por red.
- [ ] Solo se agregan marcas del usuario.

**Subtareas**
- [ ] Test RED (CA-12)
- [ ] Servicio
- [ ] Caché por periodo

**Notas**: Patrón: `app/Services/AnalyticsService.php` (`summary()`, `SUPPORTED`), `app/Analytics/{Calculator,Metric,Period,Days}.php`. Días UTC como los *snapshots* (rotulado); caché por periodo. «Acumulado» no es usuarios únicos. `null` si ninguna marca tiene dato; `POST_COUNT` como *stock*.

### T-32 — Página «Todas las marcas» y selector en el dashboard

- **Descripción**: Entrada «Todas las marcas» con tarjetas, tabla por marca y gráfica. Requisito: CA-12.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real —
- **Tiempo IA (ejec.)**: est. 0,81h · real —
- **Supervisión**: est. 0,20h (≈25 % IA) · real —
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-31
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Dashboard/AllBrands.vue`, `resources/js/Pages/Dashboard/Index.vue`, `app/Http/Controllers/DashboardController.php`, `routes/web.php`, `tests/Feature/Dashboard/AllBrandsPageTest.php`
- **Cubre (tests)**: E2E-04, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Dashboard/AllBrandsPageTest.php` → en verde (props y regresión del dashboard por marca)

**Criterios de aceptación**
- [ ] La página muestra totales, tabla por marca y gráfica.
- [ ] El dashboard por marca no cambia (regresión).

**Subtareas**
- [ ] Ruta y controlador
- [ ] Página y gráfica
- [ ] Estados vacío y de error

**Notas**: Patrón: `resources/js/Pages/Dashboard/{Index,Network}.vue`, `Partials/{LineChart,PeriodFilter}.vue` y `app/Http/Controllers/DashboardController.php`. Rotular «acumulado» y «días UTC».

### T-33 — MCP: `get_stats` sin marca

- **Descripción**: `get_stats` admite no recibir marca y devuelve los mismos totales que la UI. Requisito: CA-12.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-31
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/GetStats.php`, `tests/Feature/Mcp/Tools/McpStatsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpStatsTest.php` → en verde (con y sin marca)

**Criterios de aceptación**
- [ ] Sin marca devuelve los totales de `AggregateAnalytics`.
- [ ] Con marca, el comportamiento anterior no cambia.

**Subtareas**
- [ ] Test RED
- [ ] Parámetro opcional

**Notas**: Patrón: `app/Mcp/Tools/GetStats.php` (descripción desde `Network::cases()`, T-16) y `tests/Feature/Mcp/Tools/McpStatsTest.php`.

### T-34 — Pest de integración CA-12

- **Descripción**: Dos marcas con *snapshots*: suma, `null` y fila por marca en web y MCP.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-32, T-33
- **Tipo**: test
- **Archivos**: `tests/Feature/Analytics/AllBrandsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Analytics tests/Feature/Dashboard` → todo en verde

**Criterios de aceptación**
- [ ] CA-12 cubierto de extremo a extremo.

**Subtareas**
- [ ] Escribir el test

**Notas**: Patrón: `tests/Feature/Dashboard/DashboardTest.php`.

### T-35 — E2E de «Todas las marcas» con axe

- **Descripción**: Escenario Playwright de la página agregada. Requisito: CA-12.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: test
- **Archivos**: `tests/e2e/all-brands.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-04
- **Verificación**: `npx playwright test tests/e2e/all-brands.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] Totales, tabla y gráfica visibles; 0 violaciones axe; sin desborde.

**Subtareas**
- [ ] Semilla
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/pages.spec.ts` y helpers de semillas (`seedAnalyticsSnapshots`, nuevo).

### T-36 — Documentación y revisión de robustez de C-03b

- **Descripción**: Documentar la analítica entre marcas y revisar robustez (rendimiento, zona horaria).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-34, T-35
- **Tipo**: docs
- **Archivos**: `docs/modulos/analitica.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `analitica.md` explica días UTC, acumulado y `null` → coherente con T-31..T-33

**Criterios de aceptación**
- [ ] La guía de usuario explica «Todas las marcas».

**Subtareas**
- [ ] Redactar los docs
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/analitica.md` y `docs/modulos/mcp.md`.


## Fase 5 — C-03c · Exportar a CSV

**Estado**: borrador · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

### T-37 — Extraer `CsvWriter` de `ReportService::csv()` sin cambiar su salida

- **Descripción**: `App\Support\CsvWriter` (BOM, separador por *locale* y neutralización CWE-1236) extraído de `ReportService::csv()`. Requisito: CA-13.
- **Changelog**:
- **Estado**: borrador
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en verde antes de tocar (se conserva como regresión).
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Support/CsvWriter.php`, `app/Services/ReportService.php`, `tests/Feature/Reports/CsvRegressionTest.php`, `tests/Unit/CsvWriterTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Reports tests/Unit/CsvWriterTest.php` → en verde (salida idéntica byte a byte; `=`, `+`, `-`, `@` neutralizados)

**Criterios de aceptación**
- [ ] El CSV de informes es idéntico antes y después.
- [ ] Un texto que empieza por `=` sale neutralizado.

**Subtareas**
- [ ] Caracterización del CSV actual
- [ ] Extraer `CsvWriter`
- [ ] Migrar `ReportService`

**Notas**: Patrón: `app/Services/ReportService.php:297-366` (`csv()` y `csvFormat()`; `reports.csv_locale`). Caracterización byte a byte del CSV de informes **antes** de tocar.

### T-38 — CSV de la analítica (marca y todas)

- **Descripción**: Descarga síncrona del CSV de la analítica, de una marca o de todas. Requisito: CA-13.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-37, T-31
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/ExportController.php`, `routes/web.php`, `tests/Feature/Reports/AnalyticsCsvTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Reports/AnalyticsCsvTest.php` → en verde (BOM, `;`, `null` vacío, solo marcas propias)

**Criterios de aceptación**
- [ ] Descarga UTF-8 con BOM y `;`.
- [ ] Un `null` sale vacío.

**Subtareas**
- [ ] Test RED
- [ ] Controlador y ruta

**Notas**: Patrón: `app/Http/Controllers/ReportController.php`, `ExportController` (nuevo) y `CsvWriter`. Incluye Bluesky y Threads por `Network::cases()`; `null` sale vacío, nunca 0.

### T-39 — CSV de publicaciones desde la vista Lista (filtros, vista guardada, métricas y tope)

- **Descripción**: «Exportar CSV» en la vista Lista con exactamente las publicaciones del filtro activo (o de la vista guardada) y métricas del último *snapshot*, con un tope de filas. Requisito: CA-13.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-37, T-12
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/ExportController.php`, `app/Services/CalendarService.php`, `resources/js/Pages/Calendar/Index.vue`, `tests/Feature/Calendar/PostsCsvTest.php`
- **Cubre (tests)**: E2E-05
- **Verificación**: `php artisan test --compact tests/Feature/Calendar/PostsCsvTest.php` → en verde (filtro activo, vista guardada, tope, neutralización)

**Criterios de aceptación**
- [ ] El CSV contiene exactamente las publicaciones del filtro.
- [ ] Se respeta el tope y se avisa al superarlo.

**Subtareas**
- [ ] Test RED (CA-13)
- [ ] Exportador
- [ ] Botón en la vista Lista

**Notas**: Patrón: `app/Services/CalendarService.php` (mismos filtros que la lista) y `CsvWriter`. Columnas: fecha, marca, redes, estado, texto, enlace y métricas. Tope configurable (supuesto 5.000 filas ⚠️).

### T-40 — Pest de integración CA-13

- **Descripción**: Lista filtrada + exportación + neutralización de `=` en el texto.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-38, T-39
- **Tipo**: test
- **Archivos**: `tests/Feature/Calendar/CsvFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Calendar tests/Feature/Reports` → todo en verde

**Criterios de aceptación**
- [ ] CA-13 cubierto.

**Subtareas**
- [ ] Escribir el test

**Notas**: Patrón: `tests/Feature/Reports/`.

### T-41 — E2E de la descarga de CSV

- **Descripción**: Escenario Playwright que descarga el CSV y comprueba BOM, separador y filas. Requisito: CA-13.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: test
- **Archivos**: `tests/e2e/csv-export.spec.ts`
- **Cubre (tests)**: E2E-05
- **Verificación**: `npx playwright test tests/e2e/csv-export.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] El fichero descargado tiene BOM, `;` y las filas del filtro.
- [ ] 0 violaciones axe en la vista Lista.

**Subtareas**
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/flows.spec.ts` (descargas) y semillas de `seedComposerPosts`.

### T-42 — Documentación y revisión de robustez de C-03c

- **Descripción**: Documentar la exportación CSV y revisar robustez (CWE-1236, tope).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-40, T-41
- **Tipo**: docs
- **Archivos**: `docs/modulos/informes.md`, `docs/modulos/calendario.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs explican columnas, tope y neutralización → coherente con T-37..T-39

**Criterios de aceptación**
- [ ] La guía de usuario explica cómo exportar.

**Subtareas**
- [ ] Redactar los docs
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/informes.md` y `docs/modulos/calendario.md`.


## Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA)

**Estado**: borrador · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

### T-43 — `HashtagStats`: extracción, cruce con *snapshots*, ranking y caché

- **Descripción**: Extrae los `#tags` de los destinos publicados de la marca (por red), los cruza con el último *snapshot* y ordena por mejora de interacciones sobre la media, con mínimo de 2 usos y caché de 1h. Requisito: CA-14, D5 (sin IA).
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Services/HashtagStats.php`, `app/Support/Hashtags.php`, `tests/Feature/Hashtags/HashtagStatsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags/HashtagStatsTest.php` → en verde (Unicode, mínimo 2 usos, ranking, marca sin histórico)

**Criterios de aceptación**
- [ ] Solo salen hashtags con ≥ 2 usos, ordenados por rendimiento.
- [ ] Una marca sin histórico devuelve un estado vacío explícito.
- [ ] Nunca se llama a la IA.

**Subtareas**
- [ ] Test RED (CA-14)
- [ ] Servicio y caché

**Notas**: Patrón: `App\Support\Hashtags` (creado en T-23, **la misma extracción que `Facets`**), `app/Services/AnalyticsService.php` y `app/Models/PostMetricSnapshot.php`. El histórico se congela a los 30 días (`recent_post_days`). Sin datos: «aún no hay datos suficientes».

### T-44 — Endpoint y chips de hashtags en el compositor

- **Descripción**: Menú con chips que se insertan en el cursor y respetan el máximo por red. Requisito: CA-14.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,5h · real —
- **Tiempo IA (ejec.)**: est. 0,74h · real —
- **Supervisión**: est. 0,18h (≈25 % IA) · real —
- **Previsión IA**: 200k in / 22k out tok · ⚠️ verificar €
- **Dependencias**: T-43
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/HashtagController.php`, `routes/web.php`, `resources/js/Components/Composer/HashtagSuggestions.vue`, `resources/js/Components/Composer/EditorToolbar.vue`, `config/media_specs.php`, `tests/Feature/Hashtags/HashtagEndpointTest.php`
- **Cubre (tests)**: E2E-06, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags/HashtagEndpointTest.php` → en verde (aislamiento por marca y por red)

**Criterios de aceptación**
- [ ] Al pulsar un chip se inserta en el cursor.
- [ ] Se avisa al superar el máximo por red.

**Subtareas**
- [ ] Endpoint
- [ ] Componente de chips
- [ ] Máximo por red

**Notas**: Patrón: `resources/js/Components/Composer/{SavedTextsMenu,EditorToolbar,TextSection}.vue` (inserción en el cursor) y `useComposer.js`. Máximo por `text.max_hashtags` (IG 30; Threads 1 ⚠️ sin verificar; se avisa).

### T-45 — MCP: `suggest_hashtags`

- **Descripción**: Herramienta MCP `suggest_hashtags`. Requisito: C-04a de la evaluación.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-43
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/SuggestHashtags.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpHashtagsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpHashtagsTest.php` → en verde
  - `php artisan test --compact tests/Feature/Mcp/McpAuthTest.php` → `tools/list` ofrece 25 herramientas

**Criterios de aceptación**
- [ ] Marca ajena responde como inexistente.
- [ ] `tools/list` sigue completo en una página.

**Subtareas**
- [ ] Test RED
- [ ] Herramienta

**Notas**: Patrón: `app/Mcp/Tools/GetBestTimes.php`; registro en `SchedulerServer.php` (pasa a **25**, el tope de página).

### T-46 — Pest de integración CA-14

- **Descripción**: Posts publicados con hashtags y métricas → sugerencias ordenadas y límites por red.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-44, T-45
- **Tipo**: test
- **Archivos**: `tests/Feature/Hashtags/HashtagsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags` → todo en verde

**Criterios de aceptación**
- [ ] CA-14 cubierto.

**Subtareas**
- [ ] Escribir el test

**Notas**: Patrón: `tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php`.

### T-47 — E2E de hashtags con axe

- **Descripción**: Escenario Playwright: abrir sugerencias e insertar. Requisito: CA-14.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-44
- **Tipo**: test
- **Archivos**: `tests/e2e/hashtags.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-06
- **Verificación**: `npx playwright test tests/e2e/hashtags.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] Los chips se insertan en el cursor.
- [ ] 0 violaciones axe; sin desborde.

**Subtareas**
- [ ] Semilla de histórico
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts`.

### T-48 — Documentación y revisión de robustez de C-04a

- **Descripción**: Documentar las sugerencias de hashtags.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,04h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-46, T-47
- **Tipo**: docs
- **Archivos**: `docs/modulos/publicacion.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs explican fuente (histórico propio), mínimo y máximo por red → coherente con T-43..T-45

**Criterios de aceptación**
- [ ] Queda claro que no usa IA.

**Subtareas**
- [ ] Redactar los docs

**Notas**: Patrón: `docs/modulos/publicacion.md` y `docs/modulos/mcp.md`.


## Fase 7 — C-04c · Programar en lenguaje natural (IA existente)

**Estado**: borrador · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

### T-49 — `AiAssistant::proposeSchedule()`: prompt, salida JSON, redacción y validación

- **Descripción**: Envía solo la frase redactada, la fecha actual, la zona de la marca y las redes de `Network::offered()`; valida fecha futura, dentro de 1 año, cambio de hora y redes conectadas. Requisito: CA-16, D6.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-16
- **Tipo**: backend
- **Archivos**: `app/Services/AiAssistant.php`, `tests/Feature/Ai/ProposeScheduleTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleTest.php` → en verde (tabla de frases, zonas, cambio de hora, fechas pasadas)

**Criterios de aceptación**
- [ ] «el viernes a las 18» da el viernes siguiente a las 18:00 en la zona de la marca.
- [ ] Una fecha pasada o a más de 1 año se rechaza en el servidor.
- [ ] La frase se redacta antes de enviarla.

**Subtareas**
- [ ] Test RED (CA-16)
- [ ] `proposeSchedule()`
- [ ] Validación en servidor

**Notas**: Patrón: `app/Services/AiAssistant.php:86` (límites diarios y redacción de datos del *brief*) y `AiAssistantController.php:30`. Comparte el límite diario. Respeta `AI_ASSISTANT_ENABLED`. Una sola fecha (varias fechas quedan fuera).

### T-50 — Endpoint y UI de «Escribe cuándo» en `ScheduleSection`

- **Descripción**: El campo muestra la propuesta y solo la aplica al confirmar; permite descartar. Requisito: CA-16.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-49
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/AiAssistantController.php`, `routes/web.php`, `resources/js/Components/Composer/ScheduleSection.vue`, `tests/Feature/Ai/ProposeScheduleEndpointTest.php`
- **Cubre (tests)**: E2E-07, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleEndpointTest.php` → en verde (límite diario, IA desactivada, validación)

**Criterios de aceptación**
- [ ] La propuesta no se aplica sin confirmar.
- [ ] Con la IA desactivada el campo no aparece.

**Subtareas**
- [ ] Endpoint
- [ ] UI de propuesta, aplicar y descartar

**Notas**: Patrón: `resources/js/Components/Composer/ScheduleSection.vue`, `DateTimePicker.vue` y `app/Http/Controllers/AiAssistantController.php`. Oculto si la IA está desactivada.

### T-51 — Pest con respuestas simuladas (CA-16): tabla de frases y zonas

- **Descripción**: Tabla de frases y zonas horarias con respuestas de IA simuladas.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real —
- **Tiempo IA (ejec.)**: est. 0,54h · real —
- **Supervisión**: est. 0,14h (≈25 % IA) · real —
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-50
- **Tipo**: test
- **Archivos**: `tests/Feature/Ai/ProposeScheduleTableTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Ai` → todo en verde

**Criterios de aceptación**
- [ ] CA-16 cubierto con ≥ 8 frases y 3 zonas.

**Subtareas**
- [ ] Escribir la tabla

**Notas**: Patrón: tests de `AiAssistant` en `tests/Feature/Ai/` con `Http::fake` a la Messages API.

### T-52 — E2E de programar en lenguaje natural con axe

- **Descripción**: Escenario Playwright con respuesta de IA simulada por el servidor de pruebas. Requisito: CA-16.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-50
- **Tipo**: test
- **Archivos**: `tests/e2e/natural-schedule.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test tests/e2e/natural-schedule.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] Propuesta visible y aplicada solo al confirmar.
- [ ] 0 violaciones axe; sin desborde.

**Subtareas**
- [ ] Fake de IA en el entorno e2e
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts`. La IA real no se llama (M-05 es manual).

### T-53 — Documentación y revisión de robustez de C-04c

- **Descripción**: Documentar el lenguaje natural, su coste y privacidad (la frase va a Anthropic, redactada).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-51, T-52
- **Tipo**: docs
- **Archivos**: `docs/modulos/asistente-ia.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: el doc explica límite diario compartido, privacidad y que solo hay una fecha → coherente con T-49..T-50

**Criterios de aceptación**
- [ ] Queda documentado qué se envía a Anthropic.

**Subtareas**
- [ ] Redactar el doc
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/asistente-ia.md`.


## Fase 8 — C-02 · Threads (al final, sin bloquear lo demás)

**Estado**: borrador · **Estimado**: 50,5h · **Real**: — · **Coste est.**: 3.030 € (con margen; base 2.525 €) · **Tokens est.**: 2,05M

### T-54 — Verificar los ⚠️ de la Threads API y fijar los límites

- **Descripción**: Comprobar con la documentación oficial (renderizada en el navegador) los límites no verificados: 500 caracteres y regla de emojis, carrusel 2-20, 250/24h, `/me` y campos, métricas y si basta el modo desarrollo. Requisito: D2 y `design.md` §1.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: docs
- **Archivos**: `docs/referencia/decisiones-por-red.md`, `docs/roadmap/2026-10-26-metricool-parity-3/design.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: cada ⚠️ de Threads tiene fuente, valor y fecha, o sigue marcado con su plan de prueba → tabla completa

**Criterios de aceptación**
- [ ] Los valores de `media_specs` y `social.threads` de T-55/T-56 salen de esta tabla.

**Subtareas**
- [ ] Consultar la documentación
- [ ] Anotar valores y fuentes

**Notas**: Resultado: tabla de verificación en `docs/referencia/decisiones-por-red.md`. Si algo no se puede verificar, queda en `config` y con un ⚠️ para el smoke (T-64).

### T-55 — `ThreadsConnector`, `ThreadsClient` y `GraphErrors`

- **Descripción**: OAuth en `threads.com`, token corto → largo (60 días) y refresco; cliente propio con host `graph.threads.com`; tablas de errores extraídas de `GraphClient`. Requisito: CA-07, D2.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0,94h · real —
- **Supervisión**: est. 0,23h (≈25 % IA) · real —
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-15, T-54
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsConnector.php`, `app/Connectors/Threads/ThreadsClient.php`, `app/Connectors/Meta/GraphErrors.php`, `app/Connectors/Meta/GraphClient.php`, `config/services.php`, `config/social.php`, `app/Providers/AppServiceProvider.php`, `.env.example`, `tests/Feature/Connectors/Threads/ThreadsConnectorTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Connectors/Meta` → regresión de Meta en verde (caracterización previa)
  - `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsConnectorTest.php` → en verde (OAuth simulado, token largo con caducidad, refresco antes del margen)

**Criterios de aceptación**
- [ ] La cuenta se guarda con token de larga duración y su caducidad; `RefreshTokens` la renueva antes del margen.
- [ ] Sin `THREADS_APP_ID` la red no se ofrece (`offered()`).
- [ ] `GraphClient` se comporta igual tras extraer `GraphErrors`.

**Subtareas**
- [ ] Caracterización de `GraphClient`
- [ ] Extraer `GraphErrors`
- [ ] Conector y cliente
- [ ] Registro condicional

**Notas**: Patrón: `app/Connectors/Meta/GraphClient.php:159` (se extrae solo `REAUTH_CODES` y `RATE_LIMIT_CODES` a `Meta/GraphErrors`, sin cambiar comportamiento) y `MetaConnector`. `refresh_token = null`; `refresh()` con `th_refresh_token`; hosts en `config/social.php`; ID/secreto propios en `config/services.php` (`THREADS_APP_ID`, `THREADS_APP_SECRET`). Registro condicional en `AppServiceProvider.php:52-66`.

### T-56 — Tipos, reglas de media y conteo de Threads

- **Descripción**: `threads_text`, `threads_image`, `threads_video` y `threads_carousel` en `ContentType` y `media_specs`; modo de conteo `threads`. Requisito: CA-08.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-54, T-21
- **Tipo**: backend
- **Archivos**: `app/Enums/ContentType.php`, `config/media_specs.php`, `app/Support/TextLength.php`, `resources/js/Composables/textLength.js`, `tests/Unit/MediaValidator/ThreadsRulesTest.php`, `tests/Unit/MediaValidator/MediaValidatorTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/MediaValidator` → en verde (conteo de reglas actualizado y reglas de Threads)

**Criterios de aceptación**
- [ ] Las reglas de Threads validan igual en compositor y `create_post`.
- [ ] El conteo de reglas del test sube con Threads.

**Subtareas**
- [ ] Test RED
- [ ] Tipos y reglas
- [ ] Modo `threads`

**Notas**: Patrón: lo hecho en T-21 (`TextLength`). Imagen JPEG/PNG de 8 MB; vídeo MP4/MOV, H.264/HEVC, 23-60 fps, hasta 300 s y 1 GB; carrusel de 2 a 20 (valores de T-54).

### T-57 — `ThreadsPublisher`: contenedores, carrusel, sondeo y publicación

- **Descripción**: Máquina de estados de contenedor (`TEXT`/`IMAGE`/`VIDEO`/`CAROUSEL`) con `StatusPoller` y `threads_publish`; errores traducidos. Requisito: CA-08.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 9,0h · real —
- **Tiempo IA (ejec.)**: est. 1,22h · real —
- **Supervisión**: est. 0,30h (≈25 % IA) · real —
- **Previsión IA**: 328k in / 36k out tok · ⚠️ verificar €
- **Dependencias**: T-55, T-56
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `tests/Feature/Connectors/Threads/ThreadsPublisherTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsPublisherTest.php` → en verde (carrusel de 3 = 3 hijos + 1 padre, estados `FINISHED`/`ERROR`/`EXPIRED`)

**Criterios de aceptación**
- [ ] Un carrusel de 3 imágenes crea 3 hijos y 1 padre, publica el padre y queda `publicada`.
- [ ] `ERROR`/`EXPIRED` dan un error permanente traducido.
- [ ] `InstagramPublisher` no tiene ningún cambio en `git diff`.

**Subtareas**
- [ ] Test RED (CA-08)
- [ ] Texto, imagen y vídeo
- [ ] Carrusel con `checkpoint()`
- [ ] Sondeo y publicación

**Notas**: Patrón: `app/Connectors/Meta/Instagram/InstagramPublisher.php` (contenedores, carrusel con `progress`, `advanceCarousel`) **copiado, sin heredar ni modificar** (T1). `PollPublishStatus`/`StepBudget` sin cambios; media por URL pública firmada (`PublishRequestFactory`). ⚠️ *permalink* sin verificar.

### T-58 — Idempotencia y cuota de publicación de Threads

- **Descripción**: `findPublished` sobre `/me/threads` recientes y `assertQuota()` con `threads_publishing_limit`. Requisito: CA-09.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-57
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `tests/Feature/Connectors/Threads/ThreadsQuotaTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsQuotaTest.php` → en verde (cuota agotada no llama a `threads_publish`; reintento no duplica)

**Criterios de aceptación**
- [ ] Con el límite agotado se aplica `QuotaPolicy` sin publicar.
- [ ] Un reintento tras timeout no crea un segundo post.

**Subtareas**
- [ ] Test RED (CA-09)
- [ ] Cuota
- [ ] `findPublished`

**Notas**: Patrón: `InstagramPublisher::assertQuota` (`:446`, `content_publishing_limit`) y `app/Services/QuotaPolicy.php` (sin tocarla). `QuotaExceededException` aplica la política sin llamar a la API de publicación.

### T-59 — `ThreadsInsights`: post, cuenta y rango

- **Descripción**: Métricas de post (`views`, likes, comentarios, compartidos) y de cuenta (`PROFILE_VIEWS`, likes, `clicks`, `followers_count`). Requisito: C-02 de la evaluación y CA-10.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0,94h · real —
- **Supervisión**: est. 0,23h (≈25 % IA) · real —
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-55
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsInsights.php`, `app/Analytics/Metric.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Connectors/Threads/ThreadsInsightsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsInsightsTest.php` → en verde (mapa, `null` y rango)

**Criterios de aceptación**
- [ ] Cada métrica se mapea según `design.md`.
- [ ] `null` = no disponible.

**Subtareas**
- [ ] Test RED
- [ ] Lector de insights
- [ ] Entradas de `Metric` y `SUPPORTED`

**Notas**: Patrón: `app/Connectors/Contracts/RangeInsightsReader.php`, `app/Connectors/Meta/Insights.php`, `Metric.php`. `views` de cuenta = visitas al perfil → `PROFILE_VIEWS`; `shares` del post «en desarrollo» ⚠️: `null` si no llega, nunca 0. Rango desde 2024-04-13.

### T-60 — Superficies de Threads: icono, pestañas, MCP, IA e informe

- **Descripción**: Icono y resto de superficies; el catálogo de T-16/T-17 hace el resto. Requisito: CA-10.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-17, T-18, T-59
- **Tipo**: fullstack
- **Archivos**: `resources/js/Components/NetworkIcon.vue`, `app/Connectors/Network.php`, `app/Services/ComposerPresetService.php`, `app/Services/ReportService.php`, `tests/Unit/Networks/NetworkCoverageTest.php`
- **Cubre (tests)**: E2E-08
- **Verificación**:
  - `php artisan test --compact tests/Unit/Networks/NetworkCoverageTest.php` → en verde con Threads
  - `php artisan test --compact tests/Feature/Reports` → en verde (informe PDF y CSV incluyen las dos redes)

**Criterios de aceptación**
- [ ] Threads aparece en calendario, dashboard, informes y MCP.
- [ ] El test de superficies pasa sin excepciones.

**Subtareas**
- [ ] Icono
- [ ] Presets e informes
- [ ] Comprobar `NetworkCoverageTest`

**Notas**: Patrón: `NetworkIcon.vue`, `Network.php`, `ComposerPresetService.php:23-24`, informes PDF (`app/Services/Reports`, `ReportService`). El `NetworkCoverageTest` (T-18) es el oráculo.

### T-61 — Pest de integración de Threads (CA-07..CA-09)

- **Descripción**: Suite con `Http::fake`: OAuth → programar → publicar los 4 tipos → cuota → métricas.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real —
- **Tiempo IA (ejec.)**: est. 0,94h · real —
- **Supervisión**: est. 0,23h (≈25 % IA) · real —
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-58, T-59
- **Tipo**: test
- **Archivos**: `tests/Feature/Connectors/Threads/ThreadsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Instagram` → todo en verde (sin regresión de Instagram)

**Criterios de aceptación**
- [ ] CA-07, CA-08 y CA-09 cubiertos.
- [ ] Instagram sin regresión.

**Subtareas**
- [ ] Escribir los flujos

**Notas**: Patrón: `tests/Feature/Connectors/Meta/` y `tests/Feature/Instagram/`.

### T-62 — E2E de Threads: superficies con axe (CA-10)

- **Descripción**: Escenario Playwright con posts de Bluesky y Threads: iconos, filtros y métricas en calendario y dashboard. Requisito: CA-10.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real —
- **Tiempo IA (ejec.)**: est. 0,34h · real —
- **Supervisión**: est. 0,09h (≈25 % IA) · real —
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-60
- **Tipo**: test
- **Archivos**: `tests/e2e/threads.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-08
- **Verificación**: `npx playwright test tests/e2e/threads.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] Iconos, filtros y métricas de las dos redes visibles.
- [ ] 0 violaciones axe; sin desborde.

**Subtareas**
- [ ] Semillas
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/bluesky.spec.ts` (T-28).

### T-63 — Documentación y revisión de robustez de Threads

- **Descripción**: Documentar el conector y revisar robustez (tokens de 60 días, cuota).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,04h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-61, T-62
- **Tipo**: docs
- **Archivos**: `docs/modulos/conexiones.md`, `docs/modulos/publicacion.md`, `docs/modulos/analitica.md`, `docs/referencia/decisiones-por-red.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs listan tipos, límites verificados en T-54, hosts configurables y métricas de Threads → coherente con T-55..T-59

**Criterios de aceptación**
- [ ] Los valores coinciden con la config.

**Subtareas**
- [ ] Redactar los docs
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/conexiones.md`, `publicacion.md`, `analitica.md` y `docs/referencia/decisiones-por-red.md`.

### T-64 — Smoke real de Threads con el usuario

- **Descripción**: El usuario conecta su cuenta (app de Meta para Threads, *tester*) y publica los 4 tipos. Resuelve los ⚠️ restantes y confirma que no hace falta App Review.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-61, T-74
- **Tipo**: qa
- **Archivos**: `docs/roadmap/2026-10-26-metricool-parity-3/test-plan.md`
- **Cubre (tests)**: M-02
- **Verificación**: lectura: M-02 del `test-plan.md` completado y anotado con los resultados reales → M-02 marcado

**Criterios de aceptación**
- [ ] OAuth, texto, imagen, vídeo y carrusel verificados.
- [ ] Métricas y límites reales anotados.

**Subtareas**
- [ ] Ejecutar M-02
- [ ] Anotar hallazgos

**Notas**: Orden: la guía T-74 se ejecuta antes. Manual (M-02). Si Meta exigiera revisión, el código queda tras `THREADS_APP_ID` vacío.


## Fase 9 — C-04b · GIFs de Giphy

**Estado**: borrador · **Estimado**: 18,5h · **Real**: — · **Coste est.**: 1.110 € (con margen; base 925 €) · **Tokens est.**: 749k

### T-65 — `GiphyClient` y *proxy* (búsqueda, tendencias, caché, límite y errores)

- **Descripción**: Proxy en el servidor (la clave no va al navegador) con búsqueda, tendencias, clasificación `pg-13`, caché y límite. Requisito: CA-15, D4.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/Media/GiphyClient.php`, `app/Http/Controllers/GifController.php`, `config/services.php`, `.env.example`, `routes/web.php`, `tests/Feature/Media/GiphyProxyTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/GiphyProxyTest.php` → en verde (búsqueda, tendencias, caché, límite, error de la API; la clave no sale)

**Criterios de aceptación**
- [ ] La clave nunca aparece en respuestas ni props.
- [ ] Un error de Giphy se traduce a un mensaje claro.

**Subtareas**
- [ ] Test RED
- [ ] Cliente y proxy

**Notas**: Patrón: `app/Services/Media/UrlSource.php` y `AiAssistant` (límites). Clave `GIPHY_API_KEY` en `config/services.php`; 100 llamadas/hora de la clave beta (cachear). Sin clave el botón no aparece.

### T-66 — `GifPicker.vue`: búsqueda, cuadrícula, teclado, atribución y estados

- **Descripción**: Modal con búsqueda, cuadrícula, teclado, «Powered by GIPHY» y estados vacío y de error. Requisito: CA-15.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real —
- **Tiempo IA (ejec.)**: est. 0,68h · real —
- **Supervisión**: est. 0,17h (≈25 % IA) · real —
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/GifPicker.vue`, `resources/js/Components/Composer/MediaSection.vue`, `tests/Feature/Ui/GifPickerPropsTest.php`
- **Cubre (tests)**: E2E-09, M-03, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ui/GifPickerPropsTest.php` → en verde (prop `giphy.enabled` según la clave)

**Criterios de aceptación**
- [ ] El modal muestra «Powered by GIPHY».
- [ ] Navegable por teclado con foco gestionado.

**Subtareas**
- [ ] Modal
- [ ] Cuadrícula y estados
- [ ] Atribución

**Notas**: Patrón: `resources/js/Components/Composer/MediaSection.vue` y el selector de Drive (`useDrivePicker.js`). Atribución obligatoria y visible; foco atrapado y `Esc` cierra (axe).

### T-67 — Importar el MP4 elegido con `ImportRemoteMedia` y lista permitida de hosts

- **Descripción**: Al elegir, se importa la versión MP4 con `ImportRemoteMedia` (hosts de Giphy permitidos y `UrlGuard`) y `source = giphy`. Requisito: CA-15.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: backend
- **Archivos**: `app/Jobs/ImportRemoteMedia.php`, `app/Services/Media/UrlGuard.php`, `app/Models/MediaAsset.php`, `config/social.php`, `tests/Feature/Media/GiphyImportTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/GiphyImportTest.php` → en verde (host permitido, SSRF bloqueado, `source=giphy`)
  - `php artisan test --compact tests/Feature/Media/ImportRemoteMediaTest.php` → sin regresión

**Criterios de aceptación**
- [ ] Un host fuera de la lista se rechaza.
- [ ] La media importada queda «listo» con `source=giphy`.

**Subtareas**
- [ ] Test RED
- [ ] Lista permitida
- [ ] Integración con el importador

**Notas**: Patrón: `app/Jobs/ImportRemoteMedia.php`, `app/Services/Media/{UrlGuard,UrlSource,RemoteMediaImporter}.php`, `tests/Feature/Media/ImportRemoteMediaTest.php` y `app/Models/MediaAsset.php` (`source`). Cola `media` y `MEDIA_DISK=local`.

### T-68 — Compatibilidad por red de los GIF (duración mínima y avisos)

- **Descripción**: Avisos de compatibilidad por red: los GIF de menos de 3 s no valen en Reels ni TikTok; en Bluesky entra como vídeo con `presentation: gif`; en Threads como `threads_video`. Requisito: CA-15.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-67, T-24
- **Tipo**: backend
- **Archivos**: `app/Services/MediaValidator.php`, `app/Services/MediaProbe.php`, `config/media_specs.php`, `tests/Feature/Media/GifCompatibilityTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/GifCompatibilityTest.php` → en verde (aviso < 3 s, Bluesky y Threads aceptan)

**Criterios de aceptación**
- [ ] Se avisa en Reels y TikTok si dura menos de 3 s.
- [ ] En Bluesky el GIF respeta `video_max_bytes`.

**Subtareas**
- [ ] Test RED
- [ ] Avisos por red

**Notas**: Patrón: `app/Services/MediaProbe.php` y `MediaValidator`. No hay ffmpeg para alargarlos: solo se avisa. Ninguna red acepta `image/gif`.

### T-69 — Pest de integración CA-15

- **Descripción**: Buscar «gato», elegir un GIF y que se importe como media (API simulada).
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-66, T-67, T-68
- **Tipo**: test
- **Archivos**: `tests/Feature/Media/GiphyFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media` → todo en verde

**Criterios de aceptación**
- [ ] CA-15 cubierto.

**Subtareas**
- [ ] Escribir el test

**Notas**: Patrón: `tests/Feature/Media/ImportUrlEndpointTest.php`.

### T-70 — E2E del buscador de GIFs con axe

- **Descripción**: Escenario Playwright con Giphy simulado por el servidor de pruebas. Requisito: CA-15.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-66
- **Tipo**: test
- **Archivos**: `tests/e2e/gif-picker.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-09
- **Verificación**: `npx playwright test tests/e2e/gif-picker.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [ ] Buscar, elegir e importar; atribución visible.
- [ ] 0 violaciones axe; sin desborde.

**Subtareas**
- [ ] Fake de Giphy en el entorno e2e
- [ ] Spec E2E

**Notas**: Patrón: `tests/e2e/media-import.spec.ts`. Giphy real no se llama.

### T-71 — Documentación y revisión de robustez de C-04b

- **Descripción**: Documentar el buscador de GIFs y revisar robustez (SSRF, cuota de la clave).
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,04h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-69, T-70
- **Tipo**: docs
- **Archivos**: `docs/modulos/media.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `media.md` describe proxy, lista de hosts, avisos por red y atribución → coherente con T-65..T-68

**Criterios de aceptación**
- [ ] La guía de usuario explica el GIF y sus limitaciones.

**Subtareas**
- [ ] Redactar los docs
- [ ] Revisión de robustez

**Notas**: Patrón: `docs/modulos/media.md`.


## Fase 10 — Transversal y cierre

**Estado**: borrador · **Estimado**: 10,0h · **Real**: — · **Coste est.**: 600 € (con margen; base 500 €) · **Tokens est.**: 405k

### T-72 — Paridad MCP: Bluesky y Threads en `list_accounts` y `create_post`

- **Descripción**: `list_accounts` ofrece cuentas y tipos de Bluesky y Threads, y `create_post` los valida con el mismo `MediaValidator`. Verifica además que las vistas (T-11) y `get_stats` sin marca (T-33) tratan las dos redes. El CSV no tiene herramienta MCP (`generate_report` ya da CSV). Requisito: paridad web y MCP.
- **Changelog**:
- **Estado**: borrador
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-61
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/ListAccounts.php`, `app/Mcp/Tools/CreatePost.php`, `app/Mcp/Tools/PostPayload.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpBlueskyThreadsTest.php`, `tests/Feature/Mcp/McpAuthTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp` → todo en verde (cuentas y tipos, mismos mensajes de error que la web, 25 herramientas)

**Criterios de aceptación**
- [ ] `create_post` con 301 grafemas en Bluesky falla con el mismo mensaje que el compositor.
- [ ] `tools/list` ofrece exactamente 25 herramientas.
- [ ] El `#[Instructions]` del servidor menciona Bluesky y Threads.

**Subtareas**
- [ ] Test RED
- [ ] Ajustes de descripción y de `PostPayload`
- [ ] Actualizar instrucciones

**Notas**: Patrón: `app/Mcp/Tools/{ListAccounts,CreatePost,PostPayload}.php` y `tests/Feature/Mcp/Tools/McpToolsTest.php`. El servidor queda en **25 herramientas** (22 + `add_comment_note`, `list_calendar_views`, `suggest_hashtags`), justo en el tope de `defaultPaginationLength = 25` de `SchedulerServer.php`: una 26.ª obligaría a subirlo. Actualizar el `#[Instructions]` del servidor.

### T-73 — Guía para el usuario: app password de Bluesky

- **Descripción**: Guía paso a paso: crear una app password en Bluesky, email verificado para vídeo, qué se guarda y cómo revocarla. Requisito: D1, D3.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,04h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: docs
- **Archivos**: `docs/guias/bluesky-app-password.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía cubre alta de la app password, email verificado, límite diario de vídeo y revocación → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [ ] Un usuario nuevo puede conectar Bluesky solo con la guía.

**Subtareas**
- [ ] Redactar la guía
- [ ] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/google-cloud-picker-drive.md` y `docs/guias/tiktok-auditoria.md`. Se ejecuta **antes** del smoke T-30.

### T-74 — Guía para el usuario: app de Meta para Threads (ID, secreto y tester)

- **Descripción**: Guía: añadir el caso de uso Threads en developers.facebook.com, anotar el ID y el secreto propios de Threads, registrar la URI de redirección e invitar a la cuenta como *tester*. Requisito: D2.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-55
- **Tipo**: docs
- **Archivos**: `docs/guias/threads-app-meta.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía lista permisos, URI de redirección, *tester* y las variables `THREADS_APP_ID`/`THREADS_APP_SECRET` → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [ ] La guía indica qué hacer si Meta exigiera App Review.

**Subtareas**
- [ ] Redactar la guía
- [ ] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/login-social-google-meta.md`. Se ejecuta **antes** del smoke T-64. ⚠️ si la app puede combinarse con la actual: según T-54.

### T-75 — Guía para el usuario: clave de Giphy y atribución

- **Descripción**: Guía: crear la clave beta en developers.giphy.com, límites (100/h), cómo configurar `GIPHY_API_KEY` y la atribución «Powered by GIPHY»; revisar los ToS sobre republicar. Requisito: D4.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real —
- **Tiempo IA (ejec.)**: est. 0,14h · real —
- **Supervisión**: est. 0,04h (≈25 % IA) · real —
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: docs
- **Archivos**: `docs/guias/giphy-api-key.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía cubre clave, límites, atribución y la advertencia sobre ToS → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [ ] Queda claro que la atribución es obligatoria.

**Subtareas**
- [ ] Redactar la guía
- [ ] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/google-cloud-picker-drive.md`. ⚠️ los términos sobre descargar y republicar el MP4 los confirma el usuario.

### T-76 — Documentación y despliegue: variables de `.env`, colas y recuento de herramientas

- **Descripción**: Documentar las variables nuevas y el despliegue: `THREADS_APP_ID`, `THREADS_APP_SECRET`, `GIPHY_API_KEY`, topes `social.bluesky.*` y tiempos de sondeo; colas (`media` para el GIF; vídeo de Bluesky en el worker de 120 s); recuento de herramientas del MCP.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real —
- **Tiempo IA (ejec.)**: est. 0,20h · real —
- **Supervisión**: est. 0,05h (≈25 % IA) · real —
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-72, T-71, T-63
- **Tipo**: devops
- **Archivos**: `.env.example`, `docs/referencia/configuracion.md`, `docs/modulos/despliegue.md`, `docs/modulos/mcp.md`, `README.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `grep -rn "22 herramientas" README.md docs` → sin resultados; cada variable de `.env.example` documentada en `configuracion.md`

**Criterios de aceptación**
- [ ] Cada variable nueva está en `.env.example` y documentada.
- [ ] Ninguna cifra de herramientas obsoleta.

**Subtareas**
- [ ] Variables
- [ ] Colas y despliegue
- [ ] Recuento de herramientas

**Notas**: Patrón: `.env.example`, `docs/referencia/configuracion.md`, `docs/modulos/despliegue.md:113,147` (workers de 120 s) y `README.md:41` / `docs/modulos/mcp.md:116,136` (hoy dicen 22 herramientas → 25). `MEDIA_DISK=local`.

### T-77 — Regresión final: Pest completo, Playwright en los 3 proyectos y Pint

- **Descripción**: Cierre de la iniciativa: Pest completo, Playwright en `desktop`, `mobile` y `mobile-360` con el servidor propio de Playwright (sin `E2E_NO_SERVER`) y Pint.
- **Changelog**:
- **Estado**: borrador
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 3,0h · real —
- **Tiempo IA (ejec.)**: est. 0,41h · real —
- **Supervisión**: est. 0,10h (≈25 % IA) · real —
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-76, T-30, T-64
- **Tipo**: test
- **Archivos**: `docs/roadmap/2026-10-26-metricool-parity-3/tasks.md`, `docs/roadmap/2026-10-26-metricool-parity-3/spec.md`, `docs/roadmap/2026-10-26-metricool-parity-3/evaluation.md`, `docs/roadmap/2026-10-26-metricool-parity-3/design.md`, `docs/roadmap/2026-10-26-metricool-parity-3/improvement-plan.md`, `docs/roadmap/2026-10-26-metricool-parity-3/test-plan.md`, `docs/roadmap/README.md` (orquestador: artefactos de la cadena spec→evaluación→diseño→plan, commits 851ab32 y f4a5272)
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact` → toda la suite en verde
  - `npx playwright test` → todos los escenarios en verde en `desktop`, `mobile` y `mobile-360` (sin `E2E_NO_SERVER`)
  - `vendor/bin/pint --test` → sin diferencias

**Criterios de aceptación**
- [ ] Pest, Playwright y Pint en verde con las cifras anotadas en `Evidencia`.
- [ ] Todas las tareas anteriores `completado`.

**Subtareas**
- [ ] Pest completo
- [ ] Playwright en 3 proyectos
- [ ] Pint
- [ ] Anotar cifras

**Notas**: Patrón: T-50 de `2026-10-24-metricool-parity-2/tasks.md`. No se ejecutan otros servidores; `npm run test:e2e` levanta `php artisan serve` con `APP_ENV=e2e` (`playwright.config.ts`). Lo que falle se corrige en una tarea nueva, no se silencia.

