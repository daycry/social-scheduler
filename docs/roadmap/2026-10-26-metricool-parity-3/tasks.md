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
| Fase 3 — C-01 · Bluesky (contrato segregado y catálogo de redes) | 15 | 16 | 94% | 59,5 / 61,5h (estimado) | 8,07 / 8,34h (estimado) | 2,01 / 2,08h (estimado) | 2,41M / 2,49M (estimado) |
| Fase 4 — C-03b · Analítica entre marcas | 6 | 6 | 100% | 21,5 / 21,5h (estimado) | 2,91 / 2,91h (estimado) | 0,73 / 0,73h (estimado) | 871k / 871k (estimado) |
| Fase 5 — C-03c · Exportar a CSV | 6 | 6 | 100% | 18,0 / 18,0h (estimado) | 2,45 / 2,45h (estimado) | 0,61 / 0,61h (estimado) | 729k / 729k (estimado) |
| Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA) | 6 | 6 | 100% | 18,0 / 18,0h (estimado) | 2,44 / 2,44h (estimado) | 0,62 / 0,62h (estimado) | 727k / 729k (estimado) |
| Fase 7 — C-04c · Programar en lenguaje natural (IA existente) | 5 | 5 | 100% | 18,0 / 18,0h (estimado) | 2,44 / 2,44h (estimado) | 0,62 / 0,62h (estimado) | 728k / 729k (estimado) |
| Fase 8 — C-02 · Threads (al final, sin bloquear lo demás) | 10 | 11 | 91% | 48,5 / 50,5h (estimado) | 6,56 / 6,83h (estimado) | 1,63 / 1,7h (estimado) | 1,96M / 2,05M (estimado) |
| Fase 9 — C-04b · GIFs de Giphy | 7 | 7 | 100% | 18,5 / 18,5h (estimado) | 2,52 / 2,52h (estimado) | 0,63 / 0,63h (estimado) | 749k / 749k (estimado) |
| Fase 10 — Transversal y cierre | 5 | 6 | 83% | 7,0 / 10,0h (estimado) | 0,95 / 1,36h (estimado) | 0,25 / 0,35h (estimado) | 283k / 405k (estimado) |
| **TOTAL** | **74** | **77** | **96%** | **239,0 / 246,0h** | **32,4 / 33,21h** | **8,16 / 8,3h** | **9,67M / 9,96M** |

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

**Notas**: Patrón: filtros de `resources/js/Pages/Calendar/Index.vue` (la lista de redes lee el catálogo desde T-17) y menú de `Composer/SavedTextsMenu.vue`. Móvil 375/360 sin desborde. Nota de implementación: en la web la vista se elige con `saved_view=<id>` (el parámetro `view` ya es semana/mes/lista); props `views` y `active_view`. La vista por defecto se aplica cuando la URL no trae filtros elegidos: `brand_id` y `view` (los enlaces del menú) NO cuentan y mandan sobre los de la vista por defecto; red, estado, búsqueda y fecha sí (revisión P3 F1-2, B1). Una vista con marca borrada se abre sin esa marca.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui/CalendarViewsPageTest.php -> 5 passed; tests/Feature/Calendar sin regresión (cifra de entonces errónea, corregida tras la revisión: hoy 34 passed); npm run build OK

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
- **Archivos**: `tests/e2e/calendar-views.spec.ts`, `tests/e2e/helpers.ts`, `resources/js/Components/Calendar/ViewsMenu.vue`, `app/Http/Controllers/CalendarViewController.php`, `tests/Feature/Calendar/CalendarViewCrudTest.php`, `tests/e2e/composer.spec.ts` (revisión P3 F1-2: `guardarBorrador` espera la respuesta del guardado; corrige el E2E-09 intermitente)
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

**Estado**: en-progreso · **Estimado**: 61,5h · **Real**: 59,5h (estimado) · **Coste est.**: 3.690 € (con margen; base 3.075 €) · **Tokens est.**: 2,49M

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
- **Archivos**: `app/Connectors/Contracts/Connector.php`, `app/Connectors/Contracts/OAuthConnector.php`, `app/Connectors/Contracts/CredentialConnector.php`, `app/Connectors/Meta/MetaConnector.php`, `app/Connectors/Google/GoogleConnector.php`, `app/Connectors/TikTok/TikTokConnector.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `app/Connectors/Data/ConnectionCandidate.php`, `app/Models/SocialAccount.php`, `app/Services/SocialAccountService.php`, `database/migrations/*_add_credential_secret_to_social_accounts.php`, `tests/Feature/Connectors/ConnectorContractTest.php`, `tests/Support/FakeConnector.php`
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

**Notas**: Patrón: `app/Connectors/Contracts/Connector.php:20-54`, `app/Http/Controllers/OAuth/OAuthController.php:47,91`, `app/Models/SocialAccount.php:41-56` (cifrado y `hidden`), `app/Connectors/Data/ConnectionCandidate.php` (`?string $secret` fuera de `toPublicArray`) y `app/Services/SocialAccountService.php:92` (`disconnect` borra el secreto). **No cambia el comportamiento** de las 3 redes actuales. Migración aditiva. Alcance ampliado en la implementación: `tests/Support/FakeConnector.php` (el doble de test pasa a `OAuthConnector`).

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
- **Archivos**: `app/Connectors/Network.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `app/Services/AiAssistant.php`, `app/Http/Controllers/AiAssistantController.php`, `app/Mcp/Tools/GetStats.php`, `app/Mcp/Tools/GetCalendar.php`, `app/Mcp/Tools/GetBestTimes.php`, `tests/Unit/Networks/NetworkCatalogTest.php`, `app/Http/Controllers/BestTimesController.php`
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

**Notas**: Patrón: `app/Connectors/Network.php:15-38`, `app/Http/Middleware/HandleInertiaRequests.php:106` (`shell.networks`), `routes/web.php:56,58`, `AiAssistant.php:86`, `AiAssistantController.php:30` (`Rule::enum`), descripciones de `Mcp/Tools/{GetStats,GetCalendar,GetBestTimes}.php`. `EXPECTED_FLOWS` (`app/Analytics/Metric.php:63-68`) y `SUPPORTED` (`AnalyticsService.php:58-63`) se quedan donde están. Se añade el caso `Bluesky` con `authKind=credentials`. Alcance ampliado en la implementación: `app/Http/Controllers/BestTimesController.php` (la lista de redes de «mejores horas» sale del catálogo).

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
- **Archivos**: `resources/js/Components/NetworkIcon.vue`, `resources/js/Pages/Dashboard/Partials/NetworkTabs.vue`, `resources/js/Pages/Dashboard/Partials/format.js`, `resources/js/Pages/Calendar/Index.vue`, `resources/js/Pages/Welcome.vue`, `tests/Feature/Ui/ShellTest.php`, `resources/js/Composables/useNetworks.js`, `resources/js/Components/KpiCard.vue`, `resources/js/Pages/Dashboard/Network.vue`, `resources/js/Pages/Campaigns/Show.vue`
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

**Notas**: Patrón: `resources/js/Components/NetworkIcon.vue:28`, `Pages/Dashboard/Partials/{NetworkTabs.vue:20,format.js:5}`, `Pages/Calendar/Index.vue:36`, `Composables/composerOptions.js:43`; `NetworkChip.vue:13` ya lee `shell.networks`. `Welcome.vue` es pública: recibe la lista por prop. Alcance ampliado en la implementación: `resources/js/Composables/useNetworks.js` (composable que lee `shell.networks`); `resources/js/Components/KpiCard.vue` (color de red desde el catálogo); `resources/js/Pages/Dashboard/Network.vue` (color de red desde el catálogo); `resources/js/Pages/Campaigns/Show.vue` (sustituye `NETWORK_COLORS.total` por `TOTAL_COLOR`).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui tests/Unit/Networks -> 57 passed (719 assertions); npm run build -> compila sin errores; Playwright desktop calendar-views + pages (dashboards por red, consola limpia) -> 33 passed; E2E-02 (vista por defecto) falla solo en paralelo y pasa aislado (2 passed). Caracterización: pages.spec en verde antes de tocar.

**Corrección de la revisión P3 F3 (intento 1)**: A-11 — `Archivos` limpiado: se quita `resources/js/Composables/composerOptions.js`, que no se tocó (la lista de redes ya salía del catálogo). Estado: `en-progreso` → `completado`.

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
- **Changelog**: Las cuentas de Bluesky mantienen la sesión viva: se renueva sola y, si caduca, se recrea con la app password guardada cifrada.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,5h · real 3,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,47h · real 0,47h (estimado)
- **Supervisión**: est. 0,12h (≈25 % IA) · real 0,12h (estimado)
- **Previsión IA**: 128k in / 14k out tok · ⚠️ verificar €
- **Dependencias**: T-15
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyClient.php`, `app/Connectors/Bluesky/BlueskySession.php`, `app/Connectors/Bluesky/BlueskyConnector.php`, `config/social.php`, `tests/Feature/Connectors/Bluesky/BlueskySessionTest.php`, `app/Connectors/Bluesky/BlueskyException.php`, `tests/Support/BlueskyFake.php`, `app/Connectors/Bluesky/BlueskyHosts.php`, `tests/Feature/Connectors/Bluesky/BlueskySsrfTest.php`, `tests/Feature/Connections/CredentialConnectionTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskySessionTest.php` → en verde con `Http::fake` (sesión, `ExpiredToken` → recreación, `ReconnectRequiredException`)

**Criterios de aceptación**
- [x] La conexión guarda tokens cifrados y `credential_secret`, con `external_id` = DID.
- [x] Un `ExpiredToken` recrea la sesión sin cortar la cuenta.
- [x] Un test de regresión demuestra que `RefreshSocialAccount` no marca reconexión con `refresh_expires_at` nulo.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Cliente XRPC
- [x] Sesión con bloqueo y recuperación

**Notas**: Patrón: `app/Connectors/Meta/GraphClient.php:159` (cliente y traducción de errores) y `app/Jobs/RefreshSocialAccount.php:70,120`. `refresh_expires_at = null` para que `permanentReason()` (`:76-87`) no marque reconexión; `Cache::lock("bsky-session:{id}")`; `exp` del JWT leído sin verificar firma. Entryway en `config/social.php`. Alcance ampliado en la implementación: `app/Connectors/Bluesky/BlueskyException.php` (excepción XRPC con código y estado); `tests/Support/BlueskyFake.php` (ayudas compartidas de los tests de Bluesky (JWT, cuentas, respuestas simuladas)).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskySessionTest.php -> 12 passed (44 assertions) con Http::fake: alta con external_id=DID y secretos cifrados, ExpiredToken -> createSession, app password revocada -> ReconnectRequiredException, 5xx transitorio, bloqueo por cuenta, regresion de RefreshSocialAccount con refresh_expires_at nulo. Nota: codigo y tests se escribieron juntos (no se ejecuto un RED previo).

**Corrección de la revisión P3 F3 (intento 1)** (commit 5553242, `completado` de nuevo): C-1, C-10 y B-8.
- C-1 (CWE-918): `BlueskyHosts` + `UrlGuard` en TODA petición del cliente (`BlueskyClient::send`): https y 443, DNS validado, IP fijada con `CURLOPT_RESOLVE`, sin redirecciones (una 3xx es el error `RedirectBlocked`), revalidación en cada uso (también `meta.pds` en los jobs: `createSession`, `refreshSession`, `uploadBlob`, `createRecord`, `getRecord`, `getServiceAuth`). `did:web:` solo admite `did:web:<dominio>` (rechaza `%2F`, `%3A`, puertos y rutas); el documento DID debe traer el `id` pedido; entryway, PLC, AppView y video son hosts fijos con lista `social.bluesky.allowed_hosts` (`BLUESKY_ALLOWED_HOSTS`). El mensaje de error del servidor se sanea (sin control, 200 caracteres, el código solo si es un identificador) y un cuerpo no JSON nunca se refleja. La resolución del handle usa solo el entryway fijo: no hay `/.well-known/atproto-did` ni DNS TXT que proteger.
- RED real (C-1): `BlueskySsrfTest` contra el código anterior → 30 tests: 4 verdes, 25 failed y 1 error (probes `did:web:169.254.169.254%2Flatest%2Fmeta-data%3F`, `did:web:localhost%3A6379`, `127.0.0.1.nip.io`, 302 a `http://169.254.169.254/`, PDS con puerto, http, IP, DNS privado y mixto, rebinding del PDS guardado, sesión de otro DID). Tras el arreglo: 30/30. Incluye el test con el manejador cURL REAL contra 127.0.0.1 (patrón de `UrlSourceRealHandlerTest`, `BskyLoopbackGuard` solo en el test): la IP fijada llega y el 302 no se sigue (el servidor solo registra la petición inicial).
- C-10 (CWE-345): `open()` exige que el DID de la sesión sea el resuelto (`DidMismatch`) y al renovar una sesión de otro DID pide reconectar. B-8: `applyRefresh` corre dentro del mismo bloqueo; RED: `B-8: las credenciales renovadas se guardan DENTRO del bloqueo` fallaba antes (`false` no es `true`) y pasa.
- Tests existentes ajustados: `BlueskyFake::dns()` (DNS simulado, sin red) y el `id` del documento PLC en los fakes. **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. Archivos añadidos: `BlueskyHosts.php`, `BlueskySsrfTest.php`, `CredentialConnectionTest.php`.

### T-20 — Ruta, controlador y formulario de credenciales en Conexiones

- **Descripción**: `CredentialConnectionController@store` y formulario «handle + app password» en Conexiones. Requisito: CA-01, D1.
- **Changelog**: En Conexiones se puede conectar Bluesky con el handle y una app password, con errores claros y sin guardar la contraseña a la vista.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-15, T-16, T-19
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/CredentialConnectionController.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Brands/Connections.vue`, `tests/Feature/Connections/CredentialConnectionTest.php`, `app/Http/Controllers/ConnectionController.php`, `resources/js/Components/Connections/CredentialsForm.vue`
- **Cubre (tests)**: E2E-03
- **Verificación**: `php artisan test --compact tests/Feature/Connections/CredentialConnectionTest.php` → en verde (alta, *throttle*, credenciales inválidas, no filtra el secreto)

**Criterios de aceptación**
- [x] Con handle y app password válidos (API simulada) la cuenta aparece conectada.
- [x] Una contraseña inválida da un error claro sin guardar nada.
- [x] El secreto no aparece en props, logs ni auditoría.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Controlador y ruta
- [x] Formulario

**Notas**: Patrón: `app/Http/Controllers/ConnectionController.php:53`, `OAuthController` y `resources/js/Pages/Brands/Connections.vue`. `POST brands/{brand}/connections/{provider}/credentials` con *throttle* y CSRF; nunca se devuelve ni se registra la contraseña. Registro condicional de Bluesky en `AppServiceProvider.php:52-66`. Alcance ampliado en la implementación: `app/Http/Controllers/ConnectionController.php` (expone `auth_kind` y los campos del formulario de credenciales); `resources/js/Components/Connections/CredentialsForm.vue` (componente del formulario handle + app password).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connections tests/Feature/Connectors tests/Feature/Ui -> 332 passed (2190 assertions); CredentialConnectionTest (alta, contrasena invalida, throttle 429, secreto fuera de props/sesion/BD/logs, 404 ajena/OAuth, sin conector no se ofrece); npm run build compila. El registro del conector en AppServiceProvider se difiere a T-26 (cuando Bluesky tiene todas sus superficies y NetworkCoverageTest lo exige); los tests lo registran en el contenedor.

**Corrección de la revisión P3 F3 (intento 1)**: A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo.

### T-21 — Tipos, reglas de media y conteo de grafemas de Bluesky

- **Descripción**: `bluesky_post` y `bluesky_video` en `ContentType` y `media_specs`; `TextLength` (PHP y JS) con modos `chars`, `graphemes` y `threads`; topes de imagen (2 MB) y vídeo (50 MB) configurables con rechazo al guardar. Requisito: CA-02.
- **Changelog**: Bluesky admite publicaciones de hasta 300 caracteres (grafemas, con 4 imágenes) y vídeos MP4; un vídeo por encima del tope se rechaza al guardar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-16, T-18
- **Tipo**: backend
- **Archivos**: `app/Enums/ContentType.php`, `config/media_specs.php`, `config/social.php`, `app/Support/TextLength.php`, `resources/js/Composables/textLength.js`, `app/Services/MediaValidator.php`, `app/Http/Controllers/PostController.php`, `tests/Unit/MediaValidator/BlueskyRulesTest.php`, `tests/Unit/TextLengthTest.php`, `resources/js/Composables/useComposer.js`, `resources/js/Components/Composer/NetworkTabs.vue`, `tests/Feature/Posts/Compose/ComposeTest.php`, `tests/Fixtures/text-length-cases.json`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/TextLengthTest.php` → en verde (tabla de emojis compuestos)
  - `php artisan test --compact tests/Unit/MediaValidator/BlueskyRulesTest.php` → en verde (301 grafemas rechazado, 300 con emojis aceptado, vídeo > tope rechazado)

**Criterios de aceptación**
- [x] 301 grafemas se rechaza en compositor y `create_post` con el mismo mensaje; 300 con emojis compuestos se acepta.
- [x] Un vídeo mayor que `video_max_bytes` se rechaza al guardar con el mensaje claro.
- [x] `TextLength` PHP y JS dan lo mismo en la tabla de casos.

**Subtareas**
- [x] Test RED (CA-02) — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Tipos y reglas
- [x] `TextLength` PHP y JS
- [x] Contadores del compositor

**Notas**: Patrón: `app/Enums/ContentType.php`, `config/media_specs.php`, `app/Services/MediaValidator.php:118-140` (`mb_strlen`), `PostController.php:213-217` (`max_chars`), `resources/js/Composables/textCount.js` y `Composer/NetworkCounters.vue`. Espejo JS con `Intl.Segmenter`; tope de 3000 bytes del lexicon. Mensaje: «Bluesky solo admite vídeos de hasta 50 MB en esta instalación; este pesa X MB.» Alcance ampliado en la implementación: `resources/js/Composables/useComposer.js` (el contador por destino usa el modo de conteo del tipo); `resources/js/Components/Composer/NetworkTabs.vue` (el contador de la pestaña usa el modo del tipo); `tests/Feature/Posts/Compose/ComposeTest.php` (cubre el modo `count` en `limits`); `tests/Fixtures/text-length-cases.json` (tabla de casos compartida entre el test PHP y el espejo JS).

**Verificación ejecutada**: php artisan test --compact tests/Unit tests/Feature/Posts tests/Feature/Mcp tests/Feature/Publishing tests/Feature/Ui -> verde (Unit 290 passed; resto 548 passed); TextLengthTest (tabla de emojis compuestos, PHP y espejo JS ejecutado con node) y BlueskyRulesTest (301 grafemas rechazado, 300 con emojis aceptado, 3000 bytes, video > tope rechazado con mensaje claro); npm run build compila. Conteo de reglas 13 -> 15.

**Corrección de la revisión P3 F3 (intento 1)** (commit 090e0ef): A-6 — evidencia MCP del criterio de 301 grafemas. `tests/Feature/Mcp/Tools/McpBlueskyTest.php`, «A-6: create_post rechaza 301 grafemas en Bluesky y acepta 300 (cuentan grafemas, no code points)»: `create_post` con 301 `a` → error con «admite como maximo 300»; 300 × `e` + U+0301 (600 code points, 900 bytes) y 300 `b` → aceptados. Salida ejecutada: `{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":9}`. Es una prueba de evidencia sobre comportamiento que ya existía (no hay RED). A-11 — `Archivos` limpiado: se quita `NetworkCounters.vue`, que no se tocó. A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. Estado: `en-progreso` → `completado`.

### T-22 — Recompresión de imágenes (> tope) con GD

- **Descripción**: `ImageDownscaler` crea una copia derivada y cacheada cuando la imagen supera `image_max_bytes`; si no baja, falla el guardado. Requisito: CA-03.
- **Changelog**: Las imágenes de más de 2 MB se recomprimen en una copia para Bluesky (la original no se toca) y, si no se pueden reducir, se avisa al guardar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-21
- **Tipo**: backend
- **Archivos**: `app/Services/Media/ImageDownscaler.php`, `app/Services/MediaValidator.php`, `tests/Feature/Media/ImageDownscalerTest.php`, `config/media.php`, `deploy/hestia/README.md`, `docs/stack.md`, `tests/Support/downscale_probe.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/ImageDownscalerTest.php` → en verde (3 MB → ≤ tope, copia cacheada, imposible → error al validar)

**Criterios de aceptación**
- [x] Una imagen de 3 MB produce una copia derivada ≤ 2.000.000 bytes.
- [x] La copia se reutiliza por media y versión de regla.
- [x] Si no puede bajar del tope, el guardado falla con mensaje claro.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] `ImageDownscaler` con GD
- [x] Integración con la validación

**Notas**: Patrón: `app/Services/MediaStorage.php` (copias y disco) y `app/Services/MediaProbe.php`. Tope por defecto 2.000.000 bytes (decisión del usuario 2026-10-27), configurable; bajar a 1.000.000 si un PDS lo exige. La original no se toca; se avisa de la recompresión.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Media/ImageDownscalerTest.php -> 8 passed (30 assertions): JPEG de >3 MB -> copia <= 2.000.000 bytes, copia reutilizada por media y version de regla, PNG aplanado a JPEG, tope imposible -> error claro en MediaValidator, fichero invalido sin romper, aviso de recompresion. Suites Media, Unit y Posts en verde.

**Corrección de la revisión P3 F3 (intento 1)** (commits 9354498 y 9776417): B-2, D-2 y B-3.
- B-2: la recompresión sale de la petición web. `MediaValidator` (validación en vivo, guardado y MCP) ya NO llama a `ImageDownscaler::fit()`: solo mira tamaño y dimensiones con `tooLargeToShrink()` (tope de 50 MP y estimación `píxeles × 16 B` frente a la memoria libre) y avisa de la recompresión; lo que no se puede decodificar sin riesgo se rechaza con un mensaje. La copia la genera el job de publicación (`BlueskyPublisher::uploadImage`), que repite la comprobación de píxeles/memoria ANTES de cargar (`getimagesize` + estimación; ya no hay error fatal). Tests: una foto de 24 MP (6000×4000, fichero inexistente) da `[]` de errores y un aviso, sin cargar nada y con menos de 20 MB de pico; 12000×9000 → error «demasiado grande»; el publicador la rechaza con `archivo_grande` sin subir nada.
- D-2: `fitFile` genera la copia bajo `Cache::lock("downscale:{media}:{tope}")` (espera `media_specs.downscale_lock_wait`, 60 s) y reutiliza la copia que otra ejecución dejó hecha; con el lock tomado por otro proceso no compite con GD.
- B-3: la orientación EXIF (1–8) se lee y se aplica (`imageflip`/`imagerotate`) ANTES de reescalar; el `aspectRatio` sale de la copia ya girada y, para una original ≤ tope con EXIF 5–8, con los ejes intercambiados. Tests con Orientation=6 (copia vertical, mitad roja arriba, sin EXIF) en `ImageDownscalerTest` y `BlueskyPublisherTest`.
- RED real: `ImageDownscalerTest` + `BlueskyPublisherTest` contra el código anterior → 32 tests: 24 verdes, 5 failed y 3 errores (validación que recomprimía y daba error, lock, EXIF 6 sin giro, aspectRatio sin intercambiar, 12000×9000 aceptado). Tras el arreglo 14/14 y 18/18; el de 24 MP fija `memory_limit` holgado (9776417) para no depender de la memoria que acumule la suite. A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. A-11 — `Archivos` limpiado: se quita `app/Services/MediaStorage.php`, que no se tocó. Estado: `en-progreso` → `completado`.

**Corrección revisión P3 F6-9 (intento 3)**: C: el reductor llama a `allows(after: 2)` (reserva uploadBlob y createRecord), resta esas dos llamadas al timeout de ffmpeg y la espera del lock se limita al tiempo que queda del presupuesto; test nuevo en `ImageDownscalerTest`. Verificación: 21 passed.

### T-23 — `BlueskyPublisher`: texto, `Facets` e imágenes

- **Descripción**: Publicar texto y de 0 a 4 imágenes (`uploadBlob`, `aspectRatio`, `langs`) con *facets* en bytes UTF-8. Requisito: CA-03.
- **Changelog**: Se puede publicar en Bluesky texto con enlaces, hashtags y menciones, y hasta 4 imágenes (las grandes se recomprimen).
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real 6,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,81h · real 0,81h (estimado)
- **Supervisión**: est. 0,20h (≈25 % IA) · real 0,20h (estimado)
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-21, T-22
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Bluesky/Facets.php`, `app/Support/Hashtags.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Connectors/Bluesky/BlueskyPublisherTest.php`, `tests/Unit/Bluesky/FacetsTest.php`, `app/Connectors/Bluesky/BlueskyErrors.php`, `resources/js/Composables/textCount.js`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/Bluesky/FacetsTest.php` → en verde (índices en bytes, emojis, tags de 64 grafemas)
  - `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyPublisherTest.php` → en verde (4 *blobs* ≤ tope y registro con *facets*)

**Criterios de aceptación**
- [x] Se suben 4 *blobs* ≤ tope y el registro lleva los *facets* de enlaces y hashtags.
- [x] Los índices coinciden en bytes con emojis compuestos.
- [x] Una mención no resoluble queda como texto plano.

**Subtareas**
- [x] Test RED (CA-03) — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] `Facets` y `Hashtags`
- [x] Publicador de texto e imágenes

**Notas**: Patrón: `app/Connectors/Contracts/Publisher.php`, `app/Connectors/Meta/Instagram/InstagramPublisher.php` (errores y `PublishResult`) y `app/Connectors/Data/PublishRequest.php`. `App\Support\Hashtags` (extracción compartida con C-04a/T-43) alimenta `Facets`; `@menciones` solo si `resolveHandle` resuelve. Tests de tabla con emojis compuestos. Alcance ampliado en la implementación: `app/Connectors/Bluesky/BlueskyErrors.php` (traducción de errores XRPC a excepciones de la cola).

**Verificación ejecutada**: php artisan test --compact tests/Unit/Bluesky/FacetsTest.php -> 11 passed (indices en bytes, emojis compuestos, tags de 64 grafemas, menciones); php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyPublisherTest.php -> 15 passed: 4 blobs <= tope y registro con facets de enlace y tag, mencion no resoluble en texto plano, copia recomprimida, renovacion de token, errores traducidos. Tests y codigo escritos juntos (sin RED previo ejecutado).

**Corrección de la revisión P3 F3 (intento 1)** (commits 9354498 y 0abe244): B-3 en el publicador (`aspectRatio` con ejes intercambiados, ver T-22) y B-7 — la regex de hashtags admite `\p{M}` (marcas combinantes: NFD, índicos) en `Hashtags`, `MediaValidator::countHashtags` y `textCount.js` (la misma regla en los tres). Test «B-7» (NFD `#cafe` + U+0301, `#niño` descompuesto y `#भारत`): RED real, falla contra la regex anterior (1 failed) y pasa ahora; `Archivos` añade `textCount.js`. A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. Estado: `en-progreso` → `completado`.

### T-24 — Vídeo de Bluesky: `uploadVideo`, `getJobStatus` y límites

- **Descripción**: Publicador con `StatusPoller`: comprobación de `getUploadLimits` y del email verificado, `getServiceAuth`, subida en streaming y sondeo hasta el *blob*. Requisito: CA-05, D3.
- **Changelog**: Bluesky publica vídeos: comprueba el email verificado y el cupo diario, sube el MP4 y espera a que Bluesky lo procese.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real 6,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,81h · real 0,81h (estimado)
- **Supervisión**: est. 0,20h (≈25 % IA) · real 0,20h (estimado)
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyPublisher.php`, `app/Connectors/Bluesky/BlueskyClient.php`, `tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php`, `app/Connectors/Support/StepBudget.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php` → en verde (procesando → publicada con enlace `bsky.app`; `canUpload` falso y email sin verificar → error permanente)

**Criterios de aceptación**
- [x] El destino pasa a `procesando` y, al devolver `getJobStatus` el *blob*, a `publicada` con su enlace.
- [x] `FAILED` se traduce con su `failureCode`.
- [x] Email sin verificar o cupo agotado dan un mensaje claro.

**Subtareas**
- [x] Test RED (CA-05) — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Límites y *service auth*
- [x] Subida en streaming
- [x] `poll` y `createRecord`

**Notas**: Patrón: `app/Jobs/PollPublishStatus.php:86-128`, `app/Connectors/Support/{StepBudget,MediaChunkReader}.php` (85 s por paso; job 110 s) y `Contracts/StatusPoller.php`. ⚠️ `aud` de `getServiceAuth` sin verificar: se comprueba en el smoke (T-30). Con `presentation: gif` para los GIF de C-04b.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php -> 10 passed: procesando -> publicada con enlace bsky.app, rkey persistida con checkpoint antes de subir, subida en streaming con token de servicio (aud del PDS), FAILED traducido con su codigo, email sin verificar y canUpload falso -> permanente, cupo diario -> QuotaExceeded, already_exists reutiliza el trabajo. Suite Bluesky 37 passed. aud del service auth sigue sin verificar (T-30).

**Corrección de la revisión P3 F3 (intento 1)** (commit 3a500f1): B-9 — la subida del video respeta `StepBudget` y el timeout del job. El timeout de `uploadVideo` es `min(upload_timeout, presupuesto restante)` (mínimo 20 s) y, si `allows()` es falso o no quedan 20 s, el paso termina con `TransientPublishException` («Sin tiempo…») en lugar de agotar los 110 s del job. `StepBudget::remaining()` es nuevo. RED real: 2 tests en rojo contra el código anterior (timeout 80 con presupuesto de 30 s; no se lanzaba la excepción con 10 s) y 13/13 en verde ahora. A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. Estado: `en-progreso` → `completado`.

### T-25 — Idempotencia por `rkey` TID, `findPublished` y errores traducidos

- **Descripción**: TID generada y persistida con `checkpoint()` antes de `createRecord`; reintentos reutilizan la `rkey`; `findPublished` con `getRecord`. Requisito: CA-04.
- **Changelog**: Un reintento tras un corte de red ya no duplica la publicación en Bluesky: se comprueba si el registro existe antes de volver a crearlo.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/Tid.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`, `tests/Unit/Bluesky/TidTest.php`, `tests/Feature/Connectors/Bluesky/BlueskyIdempotencyTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Unit/Bluesky/TidTest.php` → en verde (13 caracteres base32-sortable, monótona)
  - `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyIdempotencyTest.php` → en verde (timeout en `createRecord` y reintento no duplica)

**Criterios de aceptación**
- [x] Tras agotar el tiempo en `createRecord`, el reintento encuentra el registro y no crea un segundo post.
- [x] La `rkey` se persiste antes de la llamada.

**Subtareas**
- [x] Test RED (CA-04) — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] TID
- [x] `findPublished` y errores

**Notas**: Patrón: `PublishRequest::checkpoint()` y `InstagramPublisher::findPublished`. ⚠️ error del PDS ante `rkey` repetida sin verificar: ante cualquier error de `createRecord` se hace `getRecord` antes de fallar.

**Verificación ejecutada**: php artisan test --compact tests/Unit/Bluesky/TidTest.php tests/Feature/Connectors/Bluesky/BlueskyIdempotencyTest.php -> verde (Unit/Bluesky + Feature/Connectors/Bluesky: 62 passed, 215 assertions): TID 13 caracteres base32-sortable y monotona; rkey persistida antes de createRecord; timeout con respuesta perdida -> getRecord lo encuentra y no se crea un segundo post; findPublished en el reintento; rkey reutilizada; errores traducidos (T-23). Tid.php y el uso de rkey se adelantaron a T-24 (video).

**Corrección de la revisión P3 F3 (intento 1)**: A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo.

### T-26 — `BlueskyInsights` y métricas (`POST_COUNT`)

- **Descripción**: Métricas de cuenta (seguidores y posts) y de post (me gusta, comentarios, compartidos) con `views` = `null`. Requisito: CA-06.
- **Changelog**: Las cuentas de Bluesky muestran seguidores y publicaciones, y cada post sus me gusta, comentarios y compartidos; las visualizaciones figuran como no disponibles.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-19, T-16
- **Tipo**: backend
- **Archivos**: `app/Connectors/Bluesky/BlueskyInsights.php`, `app/Analytics/Metric.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Connectors/Bluesky/BlueskyInsightsTest.php`, `tests/Feature/ProfileTest.php`, `app/Analytics/SnapshotCollector.php`, `app/Connectors/Contracts/BatchPostInsights.php`, `app/Services/PostService.php`, `resources/js/Components/Composer/NetworkPicker.vue`, `tests/Feature/Mcp/Tools/McpBlueskyTest.php`, `app/Mcp/Tools/ListAccounts.php` (fix de la revisión F3, 090e0ef: Bluesky apagado sale no disponible; antes declarado en T-72)
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyInsightsTest.php` → en verde (seguidores, posts, por post y `views` nulo)

**Criterios de aceptación**
- [x] Se guardan seguidores y, por post, likes, compartidos (reposts + citas) y comentarios.
- [x] `views` queda `null`, nunca 0.
- [x] `NetworkCoverageTest` (T-18) sigue en verde.

**Subtareas**
- [x] Test RED (CA-06) — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Lector de insights
- [x] Entradas de `Metric` y `SUPPORTED`

**Notas**: Patrón: `app/Connectors/Contracts/InsightsReader.php`, `app/Analytics/Metric.php:63-68` (`EXPECTED_FLOWS['bluesky'] = []`, como TikTok), `AnalyticsService.php:58-63`, `app/Analytics/SnapshotCollector.php`. `POST_COUNT` es un *stock* nuevo. Lectura por AppView público ⚠️ (`public.api.bsky.app`, sin verificar). `null` = no disponible, nunca 0. Alcance ampliado en la implementación: `tests/Feature/ProfileTest.php` (la aserción «sin credential» pasa a las props de passkeys: el catálogo comparte `auth_kind: credentials`).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connectors/Bluesky/BlueskyInsightsTest.php -> 12 passed (46 assertions): seguidores y posts, por post likes/comentarios/compartidos(reposts+citas), views null, lotes de 25 con uris repetido, errores. Bluesky registrado en AppServiceProvider (BLUESKY_ENABLED) y NetworkCoverageTest lo recorre via offered(): suite completa php artisan test --compact -> 2080 passed (12418 assertions).

**Corrección de la revisión P3 F3 (intento 1)** (commits 090e0ef y 4837ac6): B-4 y D-1.
- B-4: con `BLUESKY_ENABLED=false`, `Network::isAvailable()` apaga los tipos de Bluesky (`ContentType::isOffered()`): el compositor no ofrece tipos ni límites de Bluesky y deshabilita su cuenta (`available: false`, etiqueta «No disponible»), `list_accounts` devuelve `available: false` sin `content_types`, y `create_post` y la validación web rechazan el destino («Bluesky no esta disponible en esta instalacion.»). Las cuentas siguen conectadas. RED real: 3 de los 4 tests nuevos de `McpBlueskyTest` fallaban contra el código anterior (clave `available` inexistente, `contentTypes.bluesky` no vacío).
- D-1: `SnapshotCollector::collectPostsBatch` lee el bloque de 25 con UN `getPosts` (contrato nuevo `BatchPostInsights`, implementado por `BlueskyInsights`); un rechazo permanente deja el bloque en blanco y uno transitorio se propaga. RED real: el test de 25 publicaciones contaba 25 llamadas y ahora 1.
- A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo. Archivos añadidos: `SnapshotCollector.php`, `BatchPostInsights.php`, `PostService.php`, `NetworkPicker.vue`, `McpBlueskyTest.php`. Estado: `en-progreso` → `completado`.

### T-27 — Pest de integración de Bluesky (CA-01..CA-06) y regresión de las otras redes

- **Descripción**: Suite de extremo a extremo con `Http::fake`: conexión → programar → publicar (texto, imágenes, vídeo) → métricas. Más la regresión de Meta, Google y TikTok.
- **Changelog**: Pruebas de extremo a extremo de Bluesky (conexión, texto, imágenes, vídeo, reintentos y métricas) sin romper Meta, Google ni TikTok.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 8,0h · real 8,0h (estimado)
- **Tiempo IA (ejec.)**: est. 1,08h · real 1,08h (estimado)
- **Supervisión**: est. 0,27h (≈25 % IA) · real 0,27h (estimado)
- **Previsión IA**: 292k in / 32k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-24, T-25, T-26
- **Tipo**: test
- **Archivos**: `tests/Feature/Connectors/Bluesky/BlueskyFlowTest.php`, `tests/Feature/Publishing/BlueskyPublishingTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Snapshots` → todo en verde

**Criterios de aceptación**
- [x] CA-01..CA-06 cubiertos de extremo a extremo.
- [x] Sin regresión en los 3 conectores existentes.

**Subtareas**
- [x] Escribir los flujos
- [x] Cerrar huecos que revele la suite

**Notas**: Patrón: `tests/Feature/Connectors/`, `tests/Feature/Publishing/` y `tests/Feature/Snapshots/`. Sin redes reales.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Snapshots -> 479 passed (2286 assertions), incluye BlueskyFlowTest (CA-01..CA-06, 7 tests) y BlueskyPublishingTest (reintento con la misma rkey, reconexion, cupo diario de video, registro de las otras redes, BLUESKY_ENABLED=false). Sin redes reales: todo con Http::fake.

**Corrección de la revisión P3 F3 (intento 1)**: A-5 — **RED no registrado** (A-5): el test original de esta tarea se escribió junto al código y no se ejecutó en rojo antes; no se reconstruye un RED retrospectivo.

### T-28 — E2E de Bluesky: conexión y superficies con axe

- **Descripción**: Escenario Playwright: conectar Bluesky (API simulada vía semilla), ver su icono y filtros en calendario y dashboard. Requisito: CA-01 y parte de CA-10.
- **Changelog**: Comprobado en el navegador que Bluesky se puede conectar, y que aparece en calendario, panel y compositor de forma accesible y sin desbordes.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-20, T-17
- **Tipo**: test
- **Archivos**: `tests/e2e/bluesky.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-03
- **Verificación**: `npx playwright test tests/e2e/bluesky.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos (axe y sin desbordes)

**Criterios de aceptación**
- [x] Formulario de credenciales accesible y sin desborde.
- [x] Icono y filtros de Bluesky en calendario y dashboard.

**Subtareas**
- [x] Semillas
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/oauth.spec.ts`, `seedExtraAccount` (`helpers.ts:455`, ampliar a `bluesky`). La API real nunca se llama.

**Verificación ejecutada**: E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/bluesky.spec.ts --project=desktop|mobile|mobile-360 -> 4 passed en cada proyecto (12/12): formulario de credenciales (labels, password, required, cancelar), cuenta sembrada sin credenciales en la pagina, filtro y pagina de red en calendario y dashboard, contador de grafemas (6 / 300 con familia ZWJ) en el compositor; axe serio/critico y expectNoOverflow en cada pantalla. Regresion composer/pages/calendar-views en los 3 proyectos: 94 passed, con fallos intermitentes preexistentes (calendar-views E2E-02 por defecto, composer E2E-07 y E2E-09) que tambien fallan sobre el codigo de 5407825 (comprobado).

### T-29 — Documentación y revisión de robustez de Bluesky

- **Descripción**: Documentar el conector, los límites y la sesión; revisión de robustez (secretos, bloqueos, tiempos).
- **Changelog**: Documentado cómo funciona Bluesky: conexión y sesión, tipos y límites (300 grafemas, 2 MB, 50 MB), idempotencia y métricas.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-28
- **Tipo**: docs
- **Archivos**: `docs/modulos/conexiones.md`, `docs/modulos/publicacion.md`, `docs/modulos/analitica.md`, `docs/referencia/decisiones-por-red.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs listan tipos, límites (300 grafemas, 2 MB, 50 MB), sesión y métricas de Bluesky → coherente con T-15..T-26

**Criterios de aceptación**
- [x] Los límites del doc coinciden con `config/social.php` y `media_specs.php`.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/conexiones.md`, `publicacion.md`, `analitica.md` y `docs/referencia/decisiones-por-red.md`.

**Verificación ejecutada**: lectura: conexiones.md (contrato segregado, catálogo, sesión y renovación), publicacion.md (tipos, límites, facets, imágenes, vídeo, rkey, errores), analitica.md (POST_COUNT, métricas por post) y decisiones-por-red.md (decisiones y ⚠️ pendientes) -> coherentes con T-15..T-26; los límites (300 grafemas/3000 bytes, 2.000.000 B, 52.428.800 B, 180 s) coinciden con config/social.php y config/media_specs.php. Robustez revisada: la app password solo vive cifrada (hidden, borrada al desconectar, sin logs ni props: tests), bloqueo por cuenta 30 s con espera 10 s, subida 80 s dentro del paso de 85 s, PDS solo https y host público, nunca se publica a ciegas tras un fallo de red.

### T-30 — Smoke real de Bluesky con el usuario

- **Descripción**: El usuario conecta su cuenta con una app password y publica los tipos. Resuelve los ⚠️ de `aud` del *service auth*, `rkey` repetida y AppView.
- **Changelog**:
- **Estado**: en-progreso
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real —
- **Tiempo IA (ejec.)**: est. 0,27h · real —
- **Supervisión**: est. 0,07h (≈25 % IA) · real —
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-73
- **Tipo**: qa
- **Archivos**: `docs/roadmap/2026-10-26-metricool-parity-3/test-plan.md`, `docs/guias/bluesky-app-password.md`
- **Cubre (tests)**: M-01
- **Verificación**: lectura: M-01 del `test-plan.md` completado y anotado con los resultados reales → M-01 marcado

**Criterios de aceptación**
- [ ] Texto, 4 imágenes, vídeo y métricas verificados en la cuenta real.
- [ ] Los ⚠️ resueltos o convertidos en un riesgo documentado.

**Subtareas**
- [ ] Ejecutar M-01
- [ ] Anotar hallazgos

**Notas**: Orden: la guía T-73 se ejecuta antes. Es manual y con la cuenta real del usuario (M-01). No hay automatización.

**Estado del smoke (preparado, pendiente del usuario)**: la guía T-73 ya está escrita (`docs/guias/bluesky-app-password.md`) y los pasos de M-01 (preparación, 7 pasos y tabla de ⚠️ a rellenar: `aud` del service auth, `rkey` repetida, AppView, límites reales y estados de `getJobStatus`) están en `test-plan.md`. **No se marca completada**: el smoke es manual con la cuenta real del usuario y queda `en-progreso` hasta que lo ejecute y anote los resultados (criterios sin marcar a propósito).

## Fase 4 — C-03b · Analítica entre marcas

**Estado**: completado · **Estimado**: 21,5h · **Real**: — · **Coste est.**: 1.290 € (con margen; base 1.075 €) · **Tokens est.**: 871k

### T-31 — `AggregateAnalytics`: suma por métrica con `null`, tabla por marca y por red

- **Descripción**: Servicio que llama a `AnalyticsService::summary()` por marca y suma con las reglas de `null`, *stocks* frente a flujos, tabla por marca, desglose por red y serie. Requisito: CA-12.
- **Changelog**: «Todas las marcas» suma la analítica de las marcas del usuario por métrica, con «no disponible» si ninguna tiene dato.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real 6,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,81h · real 0,81h (estimado)
- **Supervisión**: est. 0,20h (≈25 % IA) · real 0,20h (estimado)
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **RED**: AggregateAnalyticsTest falló con «Target class [App\Services\AggregateAnalytics] does not exist» · 2026-10-27
- **Dependencias**: T-26
- **Tipo**: backend
- **Archivos**: `app/Services/AggregateAnalytics.php`, `tests/Feature/Analytics/AggregateAnalyticsTest.php`, `config/social.php`, `phpunit.xml`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Analytics/AggregateAnalyticsTest.php` → en verde (suma, `null`, *stocks*, una fila por marca)

**Criterios de aceptación**
- [x] Cada métrica es la suma de las marcas con dato y `null` si ninguna lo tiene.
- [x] Hay una fila por marca y el desglose por red.
- [x] Solo se agregan marcas del usuario.

**Subtareas**
- [x] Test RED (CA-12)
- [x] Servicio
- [x] Caché por periodo

**Notas**: Patrón: `app/Services/AnalyticsService.php` (`summary()`, `SUPPORTED`), `app/Analytics/{Calculator,Metric,Period,Days}.php`. Días UTC como los *snapshots* (rotulado); caché por periodo. «Acumulado» no es usuarios únicos. `null` si ninguna marca tiene dato; `POST_COUNT` como *stock*.

**Nota de cierre**: Declarados en Archivos config/social.php (clave all_brands_cache_minutes, caché por periodo) y phpunit.xml (0 en tests). Usa AnalyticsService::summary() por marca (consultas acotadas de la paridad 2; sin hidratar publicaciones).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Analytics/AggregateAnalyticsTest.php -> 5 passed (26 assertions)

### T-32 — Página «Todas las marcas» y selector en el dashboard

- **Descripción**: Entrada «Todas las marcas» con tarjetas, tabla por marca y gráfica. Requisito: CA-12.
- **Changelog**: Nueva página «Todas las marcas» con totales, gráfica por red y tabla por marca, accesible desde el resumen cuando hay más de una marca.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 6,0h · real 6,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,81h · real 0,81h (estimado)
- **Supervisión**: est. 0,20h (≈25 % IA) · real 0,20h (estimado)
- **Previsión IA**: 219k in / 24k out tok · ⚠️ verificar €
- **Dependencias**: T-31
- **Tipo**: frontend
- **Archivos**: `resources/js/Pages/Dashboard/AllBrands.vue`, `resources/js/Pages/Dashboard/Index.vue`, `app/Http/Controllers/DashboardController.php`, `routes/web.php`, `tests/Feature/Dashboard/AllBrandsPageTest.php`
- **Cubre (tests)**: E2E-04, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Dashboard/AllBrandsPageTest.php` → en verde (props y regresión del dashboard por marca)

**Criterios de aceptación**
- [x] La página muestra totales, tabla por marca y gráfica.
- [x] El dashboard por marca no cambia (regresión).

**Subtareas**
- [x] Ruta y controlador
- [x] Página y gráfica
- [x] Estados vacío y de error

**Notas**: Patrón: `resources/js/Pages/Dashboard/{Index,Network}.vue`, `Partials/{LineChart,PeriodFilter}.vue` y `app/Http/Controllers/DashboardController.php`. Rotular «acumulado» y «días UTC».

**Nota de cierre**: RED no registrado: el test de props se escribió junto al controlador, no antes. La ruta es /dashboard/todas-las-marcas (nombre dashboard.all), declarada antes de /dashboard/{network}. La entrada en el dashboard es un enlace «Todas las marcas» visible con más de una marca. Rotulado «días UTC» y «acumulado».

**Verificación ejecutada**: php artisan test --compact tests/Feature/Dashboard tests/Feature/Analytics -> 35 passed (319 assertions); AllBrandsPageTest incluido

### T-33 — MCP: `get_stats` sin marca

- **Descripción**: `get_stats` admite no recibir marca y devuelve los mismos totales que la UI. Requisito: CA-12.
- **Changelog**: La herramienta MCP get_stats, sin indicar marca, devuelve los totales de todas tus marcas con una fila por marca, igual que la web.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **RED**: McpStatsTest «get_stats sin marca» falló con «Cannot access offset of type string on string» (la herramienta pedía marca y devolvía error) · 2026-10-27
- **Dependencias**: T-31
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/GetStats.php`, `tests/Feature/Mcp/Tools/McpStatsTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp/Tools/McpStatsTest.php` → en verde (con y sin marca)

**Criterios de aceptación**
- [x] Sin marca devuelve los totales de `AggregateAnalytics`.
- [x] Con marca, el comportamiento anterior no cambia.

**Subtareas**
- [x] Test RED
- [x] Parámetro opcional

**Notas**: Patrón: `app/Mcp/Tools/GetStats.php` (descripción desde `Network::cases()`, T-16) y `tests/Feature/Mcp/Tools/McpStatsTest.php`.

**Nota de cierre**: Sin marca y con red concreta responde un error claro (una red exige marca). La respuesta sin marca lleva scope=all_brands y no brand_id.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Mcp -> 267 passed (1643 assertions); McpStatsTest con y sin marca. Herramientas MCP: siguen 24 (tope de 25 respetado)

### T-34 — Pest de integración CA-12

- **Descripción**: Dos marcas con *snapshots*: suma, `null` y fila por marca en web y MCP.
- **Changelog**: Cubierta de extremo a extremo la analítica «Todas las marcas»: misma suma en la web y en el MCP.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-32, T-33
- **Tipo**: test
- **Archivos**: `tests/Feature/Analytics/AllBrandsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Analytics tests/Feature/Dashboard` → todo en verde

**Criterios de aceptación**
- [x] CA-12 cubierto de extremo a extremo.

**Subtareas**
- [x] Escribir el test

**Notas**: Patrón: `tests/Feature/Dashboard/DashboardTest.php`.

**Nota de cierre**: Incluye una prueba de que las consultas no crecen con los snapshots ni las cuentas (sin N+1). RED no aplica: T-34 solo añade cobertura sobre T-31..T-33 ya hechas.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Analytics tests/Feature/Dashboard -> 38 passed (355 assertions)

### T-35 — E2E de «Todas las marcas» con axe

- **Descripción**: Escenario Playwright de la página agregada. Requisito: CA-12.
- **Changelog**: Probada en el navegador la página «Todas las marcas» (totales, tabla, gráfica, periodo y accesibilidad) en escritorio y móvil.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-32
- **Tipo**: test
- **Archivos**: `tests/e2e/all-brands.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-04
- **Verificación**: `npx playwright test tests/e2e/all-brands.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] Totales, tabla y gráfica visibles; 0 violaciones axe; sin desborde.

**Subtareas**
- [x] Semilla
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/pages.spec.ts` y helpers de semillas (`seedAnalyticsSnapshots`, nuevo).

**Nota de cierre**: RED no aplica: el spec se escribió con la página ya construida (T-32). Sembrado con seedAnalyticsSnapshots en helpers.ts.

**Verificación ejecutada**: npx playwright test tests/e2e/all-brands.spec.ts --project=desktop|mobile|mobile-360 (E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test, tras npm run build) -> 1 passed en cada proyecto, axe sin violaciones serious/critical y sin desborde; sin Untrusted Host

### T-36 — Documentación y revisión de robustez de C-03b

- **Descripción**: Documentar la analítica entre marcas y revisar robustez (rendimiento, zona horaria).
- **Changelog**: Documentada la analítica «Todas las marcas» (web y MCP): días UTC, acumulado, «no disponible» y caché.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-34, T-35
- **Tipo**: docs
- **Archivos**: `docs/modulos/analitica.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `analitica.md` explica días UTC, acumulado y `null` → coherente con T-31..T-33

**Criterios de aceptación**
- [x] La guía de usuario explica «Todas las marcas».

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/analitica.md` y `docs/modulos/mcp.md`.

**Nota de cierre**: Robustez revisada: consultas acotadas (AllBrandsFlowTest fija que no crecen con snapshots ni cuentas) y solo marcas del usuario. Hallazgo anotado en la doc: el periodo por defecto se calcula en UTC sin zona de marca; con fechas explícitas los totales coinciden. Con muchas marcas el coste crece con su número (2 consultas por marca), mitigado por la caché de 5 min.

**Verificación ejecutada**: lectura: analitica.md (sección «Todas las marcas»: días UTC, acumulado, null, caché, solo marcas propias), mcp.md (get_stats sin marca, 24 herramientas) y guia-de-usuario.md («Todas las marcas») coherentes con T-31..T-33

## Fase 5 — C-03c · Exportar a CSV

**Estado**: completado · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

### T-37 — Extraer `CsvWriter` de `ReportService::csv()` sin cambiar su salida

- **Descripción**: `App\Support\CsvWriter` (BOM, separador por *locale* y neutralización CWE-1236) extraído de `ReportService::csv()`. Requisito: CA-13.
- **Changelog**: Sin cambios visibles: el CSV de los informes sale igual, ahora con un escritor compartido que neutraliza fórmulas (=, +, -, @).
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: cambio de comportamiento. Primer oráculo: test de caracterización en verde antes de tocar (se conserva como regresión).
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Support/CsvWriter.php`, `app/Services/ReportService.php`, `tests/Feature/Reports/CsvRegressionTest.php`, `tests/Unit/CsvWriterTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Reports tests/Unit/CsvWriterTest.php` → en verde (salida idéntica byte a byte; `=`, `+`, `-`, `@` neutralizados)

**Criterios de aceptación**
- [x] El CSV de informes es idéntico antes y después.
- [x] Un texto que empieza por `=` sale neutralizado.

**Subtareas**
- [x] Caracterización del CSV actual
- [x] Extraer `CsvWriter`
- [x] Migrar `ReportService`

**Notas**: Patrón: `app/Services/ReportService.php:297-366` (`csv()` y `csvFormat()`; `reports.csv_locale`). Caracterización byte a byte del CSV de informes **antes** de tocar.

**Nota de cierre**: ReportService::cell() se conserva como delegado de CsvWriter::cell() (lo usan los tests existentes). CsvWriter expone BOM, line() y field() para escribir fila a fila (streaming en T-38/T-39); null sale como campo vacío, el texto siempre entre comillas.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Reports tests/Unit/CsvWriterTest.php -> 35 passed (1407 assertions); CsvRegressionTest (caracterización byte a byte) escrito y en verde ANTES de extraer CsvWriter

### T-38 — CSV de la analítica (marca y todas)

- **Descripción**: Descarga síncrona del CSV de la analítica, de una marca o de todas. Requisito: CA-13.
- **Changelog**: Se puede descargar en CSV la analítica de una marca o de todas (UTF-8 con BOM, separador «;», sin dato = celda vacía).
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **RED**: AnalyticsCsvTest falló con «Route [exports.analytics] not defined» · 2026-10-27
- **Dependencias**: T-37, T-31
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/ExportController.php`, `routes/web.php`, `tests/Feature/Reports/AnalyticsCsvTest.php`, `resources/js/Pages/Dashboard/AllBrands.vue`, `resources/js/Pages/Dashboard/Index.vue`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Reports/AnalyticsCsvTest.php` → en verde (BOM, `;`, `null` vacío, solo marcas propias)

**Criterios de aceptación**
- [x] Descarga UTF-8 con BOM y `;`.
- [x] Un `null` sale vacío.

**Subtareas**
- [x] Test RED
- [x] Controlador y ruta

**Notas**: Patrón: `app/Http/Controllers/ReportController.php`, `ExportController` (nuevo) y `CsvWriter`. Incluye Bluesky y Threads por `Network::cases()`; `null` sale vacío, nunca 0.

**Nota de cierre**: Ruta GET export/analytics.csv (nombre exports.analytics, throttle 30/min), en streaming con streamDownload. Se añade el botón «Exportar CSV» en Dashboard/Index.vue y AllBrands.vue (declarados en Archivos: sin ellos el CSV sería inalcanzable). Columnas: marca, red, metrica, tipo, valor, anterior, variacion_pct; con «Todas las marcas» añade una fila por marca y métrica.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Reports/AnalyticsCsvTest.php -> 3 passed (24 assertions)

### T-39 — CSV de publicaciones desde la vista Lista (filtros, vista guardada, métricas y tope)

- **Descripción**: «Exportar CSV» en la vista Lista con exactamente las publicaciones del filtro activo (o de la vista guardada) y métricas del último *snapshot*, con un tope de filas. Requisito: CA-13.
- **Changelog**: La vista Lista tiene «Exportar CSV»: descarga las publicaciones del filtro (o de la vista guardada) con las métricas del último dato y avisa si pasan de 5.000 filas.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **RED**: PostsCsvTest falló con «Route [exports.posts] not defined» · 2026-10-27
- **Dependencias**: T-37, T-12
- **Tipo**: backend
- **Archivos**: `app/Http/Controllers/ExportController.php`, `app/Services/CalendarService.php`, `resources/js/Pages/Calendar/Index.vue`, `tests/Feature/Calendar/PostsCsvTest.php`, `app/Http/Controllers/CalendarController.php`, `routes/web.php`, `config/social.php`
- **Cubre (tests)**: E2E-05
- **Verificación**: `php artisan test --compact tests/Feature/Calendar/PostsCsvTest.php` → en verde (filtro activo, vista guardada, tope, neutralización)

**Criterios de aceptación**
- [x] El CSV contiene exactamente las publicaciones del filtro.
- [x] Se respeta el tope y se avisa al superarlo.

**Subtareas**
- [x] Test RED (CA-13)
- [x] Exportador
- [x] Botón en la vista Lista

**Notas**: Patrón: `app/Services/CalendarService.php` (mismos filtros que la lista) y `CsvWriter`. Columnas: fecha, marca, redes, estado, texto, enlace y métricas. Tope configurable (supuesto 5.000 filas ⚠️).

**Nota de cierre**: Una fila por destino (red) de las publicaciones del filtro, con el mismo rango, marca, red, estado y búsqueda que la lista, más los borradores sin fecha de la marca (como la lista). Métricas del último snapshot vía AnalyticsService::withLatestSnapshot (acotada, sin N+1: lo fija un test de recuento de consultas); lectura en streaming con lazy(500) y tope social.export.max_rows (5.000, env SOCIAL_EXPORT_MAX_ROWS). El tope se anuncia en la cabecera X-Export-Truncated/X-Export-Total y en la propiedad export de la lista (aviso antes de descargar). A diferencia de la lista (500), el CSV no se limita a 500 publicaciones. La prop Inertia se llama export; en Vue se lee como csvInfo por ser palabra reservada.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Calendar tests/Feature/Ui tests/Feature/Reports -> 125 passed (2630 assertions); PostsCsvTest 4 passed

### T-40 — Pest de integración CA-13

- **Descripción**: Lista filtrada + exportación + neutralización de `=` en el texto.
- **Changelog**: Cubierto de extremo a extremo que el CSV de la lista tiene las mismas publicaciones que se ven con el filtro y que los textos con = salen neutralizados.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-38, T-39
- **Tipo**: test
- **Archivos**: `tests/Feature/Calendar/CsvFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Calendar tests/Feature/Reports` → todo en verde

**Criterios de aceptación**
- [x] CA-13 cubierto.

**Subtareas**
- [x] Escribir el test

**Notas**: Patrón: `tests/Feature/Reports/`.

**Nota de cierre**: RED no aplica: T-40 solo añade cobertura sobre T-38/T-39 ya hechas. Compara la lista (Inertia) con el CSV y la vista guardada con el filtro explícito.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Calendar tests/Feature/Reports -> 75 passed (1970 assertions)

### T-41 — E2E de la descarga de CSV

- **Descripción**: Escenario Playwright que descarga el CSV y comprueba BOM, separador y filas. Requisito: CA-13.
- **Changelog**: Probada en el navegador la descarga del CSV de la lista: BOM, separador «;», solo las publicaciones del filtro y fórmulas neutralizadas.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-39
- **Tipo**: test
- **Archivos**: `tests/e2e/csv-export.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-05
- **Verificación**: `npx playwright test tests/e2e/csv-export.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] El fichero descargado tiene BOM, `;` y las filas del filtro.
- [x] 0 violaciones axe en la vista Lista.

**Subtareas**
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/flows.spec.ts` (descargas) y semillas de `seedComposerPosts`.

**Nota de cierre**: RED no aplica: el spec se escribió con la exportación ya construida (T-39). La primera ejecución falló por mi parser de líneas del propio spec (dividía por «";"»), corregido con un splitCsv; no hubo fallos de entorno. Siembra seedCsvPosts en helpers.ts (declarado en Archivos).

**Verificación ejecutada**: npx playwright test tests/e2e/csv-export.spec.ts --project=desktop|mobile|mobile-360 (E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test, tras npm run build) -> 1 passed en cada proyecto, axe sin violaciones serious/critical y sin desborde; sin Untrusted Host

### T-42 — Documentación y revisión de robustez de C-03c

- **Descripción**: Documentar la exportación CSV y revisar robustez (CWE-1236, tope).
- **Changelog**: Documentada la exportación a CSV (columnas, tope de 5.000 filas, fórmulas neutralizadas y Excel) en los módulos y la guía de usuario.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-40, T-41
- **Tipo**: docs
- **Archivos**: `docs/modulos/informes.md`, `docs/modulos/calendario.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs explican columnas, tope y neutralización → coherente con T-37..T-39

**Criterios de aceptación**
- [x] La guía de usuario explica cómo exportar.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/informes.md` y `docs/modulos/calendario.md`.

**Nota de cierre**: Robustez revisada: neutralización cubierta por CsvWriterTest, CsvRegressionTest, PostsCsvTest y el E2E (incluye nombre de marca y texto); streaming con lazy(500) y consultas constantes (test de recuento); tope 5.000 con aviso previo; marca o vista ajena 404; nombre de fichero con Str::slug. Límite documentado: el CSV de publicaciones no se corta a 500 como la Lista en pantalla.

**Verificación ejecutada**: lectura: informes.md (sección «Exportaciones CSV síncronas»: rutas, CsvWriter, CWE-1236, streaming, columnas, tope, aislamiento), calendario.md (Exportar CSV) y guia-de-usuario.md («Exportar a CSV») coherentes con T-37..T-39

## Fase 6 — C-04a · Sugerencias de hashtags (histórico propio, sin IA)

**Estado**: completado · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

### T-43 — `HashtagStats`: extracción, cruce con *snapshots*, ranking y caché

- **Descripción**: Extrae los `#tags` de los destinos publicados de la marca (por red), los cruza con el último *snapshot* y ordena por mejora de interacciones sobre la media, con mínimo de 2 usos y caché de 1h. Requisito: CA-14, D5 (sin IA).
- **Changelog**: Nuevo servicio que sugiere hashtags a partir de tu propio historial: solo los usados al menos 2 veces, ordenados por cuánto mejoran las interacciones sobre tu media, sin usar IA.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **RED**: `php artisan test --compact tests/Feature/Hashtags/HashtagStatsTest.php` antes del servicio -> 9 errores (clase `App\Services\HashtagStats` inexistente) · 2026-10-27
- **Dependencias**: T-23
- **Tipo**: backend
- **Archivos**: `app/Services/HashtagStats.php`, `app/Support/Hashtags.php`, `tests/Feature/Hashtags/HashtagStatsTest.php`, `config/social.php`, `app/Services/BestTimesService.php`, `resources/js/Composables/textCount.js`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags/HashtagStatsTest.php` → en verde (Unicode, mínimo 2 usos, ranking, marca sin histórico)

**Criterios de aceptación**
- [x] Solo salen hashtags con ≥ 2 usos, ordenados por rendimiento.
- [x] Una marca sin histórico devuelve un estado vacío explícito.
- [x] Nunca se llama a la IA.

**Subtareas**
- [x] Test RED (CA-14)
- [x] Servicio y caché

**Notas**: Patrón: `App\Support\Hashtags` (creado en T-23, **la misma extracción que `Facets`**), `app/Services/AnalyticsService.php` y `app/Models/PostMetricSnapshot.php`. El histórico se congela a los 30 días (`recent_post_days`). Sin datos: «aún no hay datos suficientes».

**Nota de cierre**: Ranking por interacciones medias del hashtag (equivale a ordenar por mejora sobre la media de la marca, que es el mismo divisor). Solo cuentan destinos publicados con una lectura de métricas; el texto propio del destino manda sobre el del post; ventana `social.hashtags.days` (365); caché `social.hashtags.cache_minutes` (60). `BestTimesService::engagement()` pasa a público para reutilizar la misma definición de interacciones. Claves nuevas en `config/social.php` (`social.hashtags.*`), declaradas en el spec. Sin Http: el test usa `Http::preventStrayRequests()` y `assertNothingSent()`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Hashtags/HashtagStatsTest.php -> 9 passed (33 assertions): mínimo 2 usos, ranking, mejora +81,8 %, Unicode (Ñandú, devanagari), texto del destino, marca sin histórico, aislamiento por marca/usuario/ventana, red y caché, límite

### T-44 — Endpoint y chips de hashtags en el compositor

- **Descripción**: Menú con chips que se insertan en el cursor y respetan el máximo por red. Requisito: CA-14.
- **Changelog**: El compositor tiene un botón «Hashtags» con tus mejores etiquetas históricas: un clic las inserta en el cursor y avisa si superas el máximo de la red.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,5h · real 5,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,74h · real 0,74h (estimado)
- **Supervisión**: est. 0,18h (≈25 % IA) · real 0,18h (estimado)
- **Previsión IA**: 200k in / 22k out tok · ⚠️ verificar €
- **RED**: `php artisan test --compact tests/Feature/Hashtags/HashtagEndpointTest.php` antes del controlador -> 5 errores (ruta `brands.hashtags` inexistente) y 1 fallo · 2026-10-27
- **Dependencias**: T-43
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/HashtagController.php`, `routes/web.php`, `resources/js/Components/Composer/HashtagSuggestions.vue`, `resources/js/Components/Composer/EditorToolbar.vue`, `tests/Feature/Hashtags/HashtagEndpointTest.php`
- **Cubre (tests)**: E2E-06, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags/HashtagEndpointTest.php` → en verde (aislamiento por marca y por red)

**Criterios de aceptación**
- [x] Al pulsar un chip se inserta en el cursor.
- [x] Se avisa al superar el máximo por red.

**Subtareas**
- [x] Endpoint
- [x] Componente de chips
- [x] Máximo por red

**Notas**: Patrón: `resources/js/Components/Composer/{SavedTextsMenu,EditorToolbar,TextSection}.vue` (inserción en el cursor) y `useComposer.js`. Máximo por `text.max_hashtags` (IG 30; Threads 1 ⚠️ sin verificar; se avisa).

**Nota de cierre**: Ruta `GET brands/{brand}/hashtags` (throttle 60/min; marca ajena = 404 por el UserScope del binding; `network` opcional validado con el enum). Los máximos por tipo ya viajaban en `limits` (`max_hashtags`, Instagram 30), así que el componente los lee del contexto del compositor y avisa si algún destino supera su tope; `config/media_specs.php` no cambia: el máximo de Threads (1 ⚠️) se añadirá con la red en la Fase 8. Inserción con `insertIntoActive` (cursor del campo activo) y, si ninguno tuvo el foco, `@insert` como el menú de textos guardados.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Hashtags/HashtagEndpointTest.php -> 6 passed: sugerencias de la marca, marca ajena 404 sin filtrar datos, filtro por red y 422 si es inválida, estado vacío, 401 sin sesión, limits.instagram_image.max_hashtags=30 en el compositor; php artisan test --compact tests/Feature/Hashtags -> 15 passed (59 assertions); npm run build -> built

### T-45 — MCP: `suggest_hashtags`

- **Descripción**: Herramienta MCP `suggest_hashtags`. Requisito: C-04a de la evaluación.
- **Changelog**: Nueva herramienta MCP suggest_hashtags: devuelve los hashtags de tu histórico con más rendimiento para una marca (y red opcional), sin usar IA.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **RED**: `php artisan test --compact tests/Feature/Mcp/Tools/McpHashtagsTest.php` antes de la herramienta -> 5 fallos (herramienta desconocida y `tools/list` con 24) · 2026-10-27
- **Dependencias**: T-43
- **Tipo**: backend
- **Archivos**: `app/Mcp/Tools/SuggestHashtags.php`, `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpHashtagsTest.php`, `tests/Feature/Mcp/Tools/McpCalendarViewsTest.php`, `tests/Feature/Mcp/Tools/McpCommentNotesTest.php`, `tests/Feature/Mcp/Tools/McpImportMediaTest.php`, `tests/Feature/Mcp/Tools/McpNotesTest.php`, `tests/Feature/Mcp/Tools/McpToolsTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Mcp/Tools/McpHashtagsTest.php` → en verde
  - `php artisan test --compact tests/Feature/Mcp/McpAuthTest.php` → `tools/list` ofrece 25 herramientas

**Criterios de aceptación**
- [x] Marca ajena responde como inexistente.
- [x] `tools/list` sigue completo en una página.

**Subtareas**
- [x] Test RED
- [x] Herramienta

**Notas**: Patrón: `app/Mcp/Tools/GetBestTimes.php`; registro en `SchedulerServer.php` (pasa a **25**, el tope de página).

**Nota de cierre**: Ability `stats:read` (como `get_best_times`); marca por `brand`/`brand_id` con el mismo aislamiento (ajena = inexistente, sin filtrar). El servidor pasa a 25 herramientas, el tope de `defaultPaginationLength`; los cinco tests que contaban 24 pasan a 25. Instrucciones del servidor actualizadas. La documentación va en T-48.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Mcp tests/Feature/Parity/BestTimesTest.php tests/Feature/Isolation -> 313 passed (1.913 assertions), incluido McpHashtagsTest (5) y los cinco tools/list con 25 herramientas en una sola página (nextCursor null)

### T-46 — Pest de integración CA-14

- **Descripción**: Posts publicados con hashtags y métricas → sugerencias ordenadas y límites por red.
- **Changelog**: Cubierto con pruebas de integración que las sugerencias de hashtags salen igual en la web, el compositor y el MCP, ordenadas por rendimiento.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-44, T-45
- **Tipo**: test
- **Archivos**: `tests/Feature/Hashtags/HashtagsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Hashtags` → todo en verde

**Criterios de aceptación**
- [x] CA-14 cubierto.

**Subtareas**
- [x] Escribir el test

**Notas**: Patrón: `tests/Feature/SavedTexts/SavedTextsComposerPropsTest.php`.

**Nota de cierre**: RED no aplica: es un test de integración sobre T-43..T-45 ya construidos (clase *capacidad nueva* heredada del plan); se escribió después y pasó a la primera. Histórico sembrado: #verano 3 usos, #oferta 2, #nuevo 1 (no sale), con una media de 154 que da +/- por debajo (ordenado por rendimiento, no por usos).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Hashtags -> 18 passed (98 assertions): HashtagStatsTest 9, HashtagEndpointTest 6, HashtagsFlowTest 3 (web = MCP, máximo 30 en Instagram, estado vacío por red y aislamiento por marca); Http::assertNothingSent()

### T-47 — E2E de hashtags con axe

- **Descripción**: Escenario Playwright: abrir sugerencias e insertar. Requisito: CA-14.
- **Changelog**: Al insertar un hashtag sugerido se coloca con un espacio a cada lado, y el menú de hashtags se ve completo en el móvil.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-44
- **Tipo**: test
- **Archivos**: `tests/e2e/hashtags.spec.ts`, `tests/e2e/helpers.ts`, `resources/js/Composables/textCount.js`, `resources/js/Composables/useComposer.js`, `resources/js/Components/Composer/TextEditor.vue`, `resources/js/Components/Composer/FirstCommentField.vue`, `resources/js/Components/Composer/NetworkTabs.vue`, `resources/js/Components/Composer/TextSection.vue`, `resources/js/Components/Composer/HashtagSuggestions.vue`, `resources/js/Components/Composer/EditorToolbar.vue`
- **Cubre (tests)**: E2E-06
- **Verificación**: `npx playwright test tests/e2e/hashtags.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] Los chips se insertan en el cursor.
- [x] 0 violaciones axe; sin desborde.

**Subtareas**
- [x] Semilla de histórico
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts`.

**Nota de cierre**: RED no aplica: el spec se escribió con el menú ya construido (T-44). El primer intento falló en `mobile` y `mobile-360` por un defecto real: el menú colgaba del botón y en pantalla estrecha se salía por la derecha (los chips quedaban tapados por el textarea). Corregido anclándolo a la barra del editor (`inset-x-0` en móvil, `sm:w-96` desde `sm`). Además la inserción con `pad` (`padForInsert`) añade un espacio a cada lado solo si el hashtag pega con una palabra: se pasa por `insertIntoActive(text, {pad})` hasta `TextEditor` y `FirstCommentField`. Siembra `seedHashtagHistory` en helpers.ts (5 posts publicados con métricas; sin red ni worker).

**Verificación ejecutada**: npm run build; E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/hashtags.spec.ts -> 9 passed (3 escenarios x desktop, mobile, mobile-360): 2 chips ordenados (#oferta, #verano; #nuevo no sale), inserción en el cursor «Hola #oferta Mundo», contador, Escape devuelve el foco, estado vacío, aviso 31 de 30, axe sin violaciones serious/critical y sin desborde

### T-48 — Documentación y revisión de robustez de C-04a

- **Descripción**: Documentar las sugerencias de hashtags.
- **Changelog**: Documentadas las sugerencias de hashtags: salen de tu propio historial (mínimo 2 usos, máximo por red), sin IA, en el compositor y por MCP.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-46, T-47
- **Tipo**: docs
- **Archivos**: `docs/modulos/publicacion.md`, `docs/modulos/mcp.md`, `docs/usuario/guia-de-usuario.md`, `README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs explican fuente (histórico propio), mínimo y máximo por red → coherente con T-43..T-45

**Criterios de aceptación**
- [x] Queda claro que no usa IA.

**Subtareas**
- [x] Redactar los docs

**Notas**: Patrón: `docs/modulos/publicacion.md` y `docs/modulos/mcp.md`.

**Nota de cierre**: Robustez revisada: sin llamadas externas (Http::assertNothingSent en servicio, endpoint, flujo y MCP); aislamiento por usuario y marca (marca ajena 404 en la web y «no encontrada» en el MCP, sin filtrar nombres ni hashtags); red validada con el enum (422 / error MCP); caché de 60 min por usuario, marca y red; ventana de 365 días y tope de 15; texto de hashtag renderizado como texto en Vue (sin v-html). Límite conocido: tras publicar, las sugerencias tardan hasta 1 h en reflejar el post nuevo (caché).

**Verificación ejecutada**: lectura: publicacion.md (sección «Hashtags sugeridos»: fuente = histórico propio sin IA, mínimo 2 usos, ranking, caché, endpoint, cliente y máximo por red), mcp.md (25 herramientas, suggest_hashtags) y guia-de-usuario.md («Hashtags sugeridos») coherentes con T-43..T-45

## Fase 7 — C-04c · Programar en lenguaje natural (IA existente)

**Estado**: completado · **Estimado**: 18,0h · **Real**: — · **Coste est.**: 1.080 € (con margen; base 900 €) · **Tokens est.**: 729k

**Corrección revisión P3 F6-9 (intento 3)**: I: `README.md` añadido a `Archivos` (lo tocó el commit `6a43873`).

### T-49 — `AiAssistant::proposeSchedule()`: prompt, salida JSON, redacción y validación

- **Descripción**: Envía solo la frase redactada, la fecha actual, la zona de la marca y las redes de `Network::offered()`; valida fecha futura, dentro de 1 año, cambio de hora y redes conectadas. Requisito: CA-16, D6.
- **Changelog**: El asistente de IA puede traducir una frase como «el viernes a las 18 en Instagram y Bluesky» a una fecha y unas redes, que tú confirmas antes de aplicarlas.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **RED**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleTest.php` contra el `AiAssistant` anterior -> 34 tests, 0 verdes (26 fallos y 8 errores: `proposeSchedule()` inexistente) · 2026-10-27
- **Dependencias**: T-16
- **Tipo**: backend
- **Archivos**: `app/Services/AiAssistant.php`, `tests/Feature/Ai/ProposeScheduleTest.php`, `config/social.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleTest.php` → en verde (tabla de frases, zonas, cambio de hora, fechas pasadas)

**Criterios de aceptación**
- [x] «el viernes a las 18» da el viernes siguiente a las 18:00 en la zona de la marca.
- [x] Una fecha pasada o a más de 1 año se rechaza en el servidor.
- [x] La frase se redacta antes de enviarla.

**Subtareas**
- [x] Test RED (CA-16)
- [x] `proposeSchedule()`
- [x] Validación en servidor

**Notas**: Patrón: `app/Services/AiAssistant.php:86` (límites diarios y redacción de datos del *brief*) y `AiAssistantController.php:30`. Comparte el límite diario. Respeta `AI_ASSISTANT_ENABLED`. Una sola fecha (varias fechas quedan fuera).

**Nota de cierre**: Una sola fecha en la zona de la marca; nunca programa. `suggest()` y `proposeSchedule()` comparten ahora `send()` (clave, modelo, cabeceras, reembolso del cupo) y `consume()` (límite diario por usuario y global). Defensa de inyección de prompt: la frase redactada viaja como VALOR de un JSON (`ahora`, `zona_horaria`, `redes`, `frase`), el sistema la declara dato y la respuesta pasa un esquema estricto (solo `datetime` y `networks`, `YYYY-MM-DDTHH:MM[:SS]` sin zona, redes del enum, futura y como mucho a `social.ai.schedule_max_days`=365 días, hora existente en la zona; un bloque ```json es lo único tolerado alrededor). Hallazgo propio: `redact()` tomaba «15-11-2026» por un teléfono; la frase usa `redactPhrase()`, que conserva fechas. Redes: solo las ofrecidas por la instalación y con cuenta ACTIVA en la marca; el resto sale en `ignored_networks`. Cambio de hora: una hora inexistente (2027-03-28 02:30 Madrid) se rechaza; una repetida (2027-10-31 02:30) se resuelve a un único instante. Una respuesta ininteligible sí gasta cupo (la llamada se hizo); un fallo de conexión o HTTP lo devuelve; 529 cuenta como saturado.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ai tests/Feature/Parity/AiAssistantTest.php -> 44 passed (109 assertions): CA-16 (viernes 18:00 Madrid = 17:00Z), carga útil sin datos personales, inyección de prompt, 15 respuestas inválidas, fechas pasadas/>1 año/límite, cambio de hora, redes conectadas y apagadas, cupo compartido y reembolso; vendor/bin/pint -> passed

**Corrección revisión P3 F6-9 (intento 3)**: A: `redactPhrase` protege solo fechas «token completo» (sin dígito ni separador+dígito antes/después) y redacta todo lo demás con el patrón de teléfono de `redact()`; tabla de 13 casos (600-12-34-56, +34 600.12.34.56, 91-123-45-67, 912.345.678 → `[teléfono]`; 15-11-2026 18:30, 2026-11-15 18:00 en Instagram y fechas sueltas se conservan; más los de `6337d5d^`). H: `isRepeatedHour` usa `DateTimeZone::getTransitions` (test Lord_Howe, 30 min). Verificación: `php artisan test --compact tests/Feature/Ai/ProposeScheduleTest.php` -> 50 passed.

### T-50 — Endpoint y UI de «Escribe cuándo» en `ScheduleSection`

- **Descripción**: El campo muestra la propuesta y solo la aplica al confirmar; permite descartar. Requisito: CA-16.
- **Changelog**: En el compositor, «Escribe cuándo» entiende frases como «el viernes a las 18 en Instagram y Bluesky», muestra la propuesta y solo la aplica cuando pulsas Aplicar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **RED**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleEndpointTest.php` antes del endpoint -> 8 errores (ruta `ai.schedule` inexistente) · 2026-10-27
- **Dependencias**: T-49
- **Tipo**: fullstack
- **Archivos**: `app/Http/Controllers/AiAssistantController.php`, `routes/parity.php`, `resources/js/Components/Composer/ScheduleSection.vue`, `tests/Feature/Ai/ProposeScheduleEndpointTest.php`
- **Cubre (tests)**: E2E-07, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ai/ProposeScheduleEndpointTest.php` → en verde (límite diario, IA desactivada, validación)

**Criterios de aceptación**
- [x] La propuesta no se aplica sin confirmar.
- [x] Con la IA desactivada el campo no aparece.

**Subtareas**
- [x] Endpoint
- [x] UI de propuesta, aplicar y descartar

**Notas**: Patrón: `resources/js/Components/Composer/ScheduleSection.vue`, `DateTimePicker.vue` y `app/Http/Controllers/AiAssistantController.php`. Oculto si la IA está desactivada.

**Nota de cierre**: Ruta `POST ai/schedule` (throttle 10/min, como `ai/suggest`; el cupo diario es el mismo) en `routes/parity.php`, junto a las demás del asistente. La marca se resuelve con el UserScope (ajena = 404 y la IA no se llama); IA desactivada = 404; una propuesta no válida = 422 con el mensaje y el cupo restante. El servidor no crea nada: la UI (`ScheduleSection.vue`) pinta la propuesta (fecha con la zona de la marca y redes) y solo al pulsar «Aplicar» rellena `form.scheduled_at` y marca las cuentas ACTIVAS de esas redes con `toggleAccount`; «Descartar» la tira. El campo no se pinta si `ai.status` dice `enabled: false`. Enter propone sin enviar el formulario.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ai tests/Feature/Parity/AiAssistantTest.php -> 52 passed (150 assertions): ProposeScheduleEndpointTest (8): propone y no crea posts, 404 con IA desactivada y con marca ajena, validación 422, 401, límite diario compartido, respuesta inválida, 429 a la 11.ª; npm run build -> built

### T-51 — Pest con respuestas simuladas (CA-16): tabla de frases y zonas

- **Descripción**: Tabla de frases y zonas horarias con respuestas de IA simuladas.
- **Changelog**: Probado con 11 frases y 3 zonas horarias (Madrid, México y Tokio) que la fecha propuesta se interpreta en la zona de la marca y no en UTC.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 4,0h · real 4,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,54h · real 0,54h (estimado)
- **Supervisión**: est. 0,14h (≈25 % IA) · real 0,14h (estimado)
- **Previsión IA**: 146k in / 16k out tok · ⚠️ verificar €
- **Dependencias**: T-50
- **Tipo**: test
- **Archivos**: `tests/Feature/Ai/ProposeScheduleTableTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Ai` → todo en verde

**Criterios de aceptación**
- [x] CA-16 cubierto con ≥ 8 frases y 3 zonas.

**Subtareas**
- [x] Escribir la tabla

**Notas**: Patrón: tests de `AiAssistant` en `tests/Feature/Ai/` con `Http::fake` a la Messages API.

**Nota de cierre**: RED no aplica: tabla de verificación sobre T-49/T-50 ya construidos; pasó a la primera. 11 casos (≥ 8) en 3 zonas (Europe/Madrid con CET y CEST, America/Mexico_City, Asia/Tokyo) con la IA simulada: local, UTC, redes y la carga enviada (`ahora` en la zona de la marca, no en UTC). Más: la misma respuesta da 3 instantes distintos según la zona y una hora que ya pasó en Tokio se rechaza aunque en UTC sea futura.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ai -> 56 passed (181 assertions): ProposeScheduleTest 35, ProposeScheduleTableTest 13 (11 frases en 3 zonas + 2) y ProposeScheduleEndpointTest 8; vendor/bin/pint -> passed

### T-52 — E2E de programar en lenguaje natural con axe

- **Descripción**: Escenario Playwright con respuesta de IA simulada por el servidor de pruebas. Requisito: CA-16.
- **Changelog**: Comprobado de extremo a extremo, en escritorio y móvil, que «Escribe cuándo» muestra la propuesta y no cambia nada hasta que pulsas Aplicar.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-50
- **Tipo**: test
- **Archivos**: `tests/e2e/natural-schedule.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-07
- **Verificación**: `npx playwright test tests/e2e/natural-schedule.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] Propuesta visible y aplicada solo al confirmar.
- [x] 0 violaciones axe; sin desborde.

**Subtareas**
- [x] Fake de IA en el entorno e2e
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/composer.spec.ts`. La IA real no se llama (M-05 es manual).

**Nota de cierre**: RED no aplica: el spec se escribió con el campo ya construido (T-50). La IA se simula EN EL NAVEGADOR con `page.route` (`GET ai/status` y `POST ai/schedule`): no hay ninguna petición a Anthropic ni cambios en el servidor de pruebas (la IA real es la prueba manual M-05). Cinco escenarios: propuesta visible y sin aplicar (fecha y redes intactas) y luego aplicada; descartar; red sin conectar avisada; error 422 de la IA; IA desactivada (el campo no aparece). Una primera pasada completa dio 2 fallos de `loginAs` (`waitForURL` agotado en el primer test de desktop y mobile-360, antes de llegar al compositor); el test pasó solo y la segunda pasada completa dio 15/15, sin cambios de código: intermitencia del inicio de sesión del entorno, no de la función.

**Verificación ejecutada**: npm run build; E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/natural-schedule.spec.ts -> 15 passed en 1,2 min (5 escenarios x desktop, mobile, mobile-360): propuesta visible antes de aplicar (data-value vacío y redes sin marcar), al aplicar fecha y redes (Instagram y Bluesky, no Facebook), descartar, red ignorada, error, IA desactivada; axe sin violaciones serious/critical y sin desborde

### T-53 — Documentación y revisión de robustez de C-04c

- **Descripción**: Documentar el lenguaje natural, su coste y privacidad (la frase va a Anthropic, redactada).
- **Changelog**: Documentado «Escribe cuándo»: qué se envía a Anthropic, que solo propone una fecha, el límite diario compartido y la defensa frente a instrucciones escondidas en la frase.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-51, T-52
- **Tipo**: docs
- **Archivos**: `docs/modulos/asistente-ia.md`, `docs/usuario/guia-de-usuario.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: el doc explica límite diario compartido, privacidad y que solo hay una fecha → coherente con T-49..T-50

**Criterios de aceptación**
- [x] Queda documentado qué se envía a Anthropic.

**Subtareas**
- [x] Redactar el doc
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/asistente-ia.md`.

**Nota de cierre**: Robustez revisada: la frase va como valor JSON y la respuesta pasa un esquema estricto (probado con 15 respuestas inválidas, inyección y respuesta con claves de más); fecha futura y a ≤ 1 año en la zona de la marca, con cambio de hora; redes solo ofrecidas y conectadas; marca ajena = 404 sin llamar a la IA; IA desactivada = 404; throttle 10/min; cupo compartido y reembolsado en fallos; la IA nunca se llama en los tests (Http::preventStrayRequests y page.route). Hallazgo corregido: la redacción de teléfonos tomaba fechas como «15-11-2026» por un teléfono (`redactPhrase`). Límite conocido: la propuesta se interpreta con la hora del servidor al pedirla; si el usuario tarda mucho en confirmar, la fecha podría quedar en el pasado y el compositor ya la bloquea con su validación de fecha futura.

**Verificación ejecutada**: lectura: asistente-ia.md (sección «Programar en lenguaje natural»: qué se envía, esquema estricto, rango, redes, límite diario compartido, seguridad, sin MCP) y guia-de-usuario.md («Escribe cuándo (con IA)») coherentes con T-49..T-52

## Fase 8 — C-02 · Threads (al final, sin bloquear lo demás)

**Estado**: en-progreso · **Estimado**: 50,5h · **Real**: — · **Coste est.**: 3.030 € (con margen; base 2.525 €) · **Tokens est.**: 2,05M

### T-54 — Verificar los ⚠️ de la Threads API y fijar los límites

- **Descripción**: Comprobar con la documentación oficial (renderizada en el navegador) los límites no verificados: 500 caracteres y regla de emojis, carrusel 2-20, 250/24h, `/me` y campos, métricas y si basta el modo desarrollo. Requisito: D2 y `design.md` §1.
- **Changelog**: Verificados con la documentación oficial de Meta los límites de Threads: 500 caracteres (emojis por bytes), carrusel de 2 a 20, 250 publicaciones al día y las métricas disponibles.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: docs
- **Archivos**: `docs/referencia/decisiones-por-red.md`, `docs/roadmap/2026-10-26-metricool-parity-3/design.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: cada ⚠️ de Threads tiene fuente, valor y fecha, o sigue marcado con su plan de prueba → tabla completa

**Criterios de aceptación**
- [x] Los valores de `media_specs` y `social.threads` de T-55/T-56 salen de esta tabla.

**Subtareas**
- [x] Consultar la documentación
- [x] Anotar valores y fuentes

**Notas**: Resultado: tabla de verificación en `docs/referencia/decisiones-por-red.md`. Si algo no se puede verificar, queda en `config` y con un ⚠️ para el smoke (T-64).

**Verificación ejecutada**: lectura (2026-10-27, páginas de developers.facebook.com/docs/threads renderizadas en el navegador): cada ⚠️ de Threads tiene fuente, valor y fecha en la tabla de decisiones-por-red.md («Threads»). Verificados: 500 caracteres y emojis por bytes UTF-8, carrusel 2-20, 250/24 h y lectura de cuota, campos de /me, permalink, métricas de post y de cuenta (views de cuenta = visitas al perfil), hosts y OAuth, token largo y refresco, estados del contenedor y límites de media. Sin verificar y con plan de prueba en el smoke (T-64, M-02): interpretación de emojis compuestos, códigos de error propios de Threads, modo desarrollo en vivo y valores reales de views/shares del post («en desarrollo»). El diseño (§7) queda actualizado.

### T-55 — `ThreadsConnector`, `ThreadsClient` y `GraphErrors`

- **Descripción**: OAuth en `threads.com`, token corto → largo (60 días) y refresco; cliente propio con host `graph.threads.com`; tablas de errores extraídas de `GraphClient`. Requisito: CA-07, D2.
- **Changelog**: Se puede conectar una cuenta de Threads por OAuth: el token dura 60 días y se renueva solo antes de caducar. Si falta THREADS_APP_ID o THREADS_ENABLED=false, la red no aparece.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real 7,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,94h · real 0,94h (estimado)
- **Supervisión**: est. 0,23h (≈25 % IA) · real 0,23h (estimado)
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-15, T-54
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsConnector.php`, `app/Connectors/Threads/ThreadsClient.php`, `app/Connectors/Meta/GraphErrors.php`, `app/Connectors/Meta/GraphClient.php`, `config/services.php`, `config/social.php`, `app/Providers/AppServiceProvider.php`, `.env.example`, `tests/Feature/Connectors/Threads/ThreadsConnectorTest.php`, `app/Connectors/Network.php`, `app/Http/Controllers/ConnectionController.php`, `config/services.php`, `tests/Support/ThreadsFake.php`, `tests/Unit/Networks/NetworkCatalogTest.php`, `tests/Feature/Publishing/BlueskyPublishingTest.php`, `app/Http/Controllers/OAuth/OAuthController.php`, `tests/Feature/Connectors/Threads/ThreadsConnectorTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Connectors/Meta` → regresión de Meta en verde (caracterización previa)
  - `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsConnectorTest.php` → en verde (OAuth simulado, token largo con caducidad, refresco antes del margen)

**Criterios de aceptación**
- [x] La cuenta se guarda con token de larga duración y su caducidad; `RefreshTokens` la renueva antes del margen.
- [x] Sin `THREADS_APP_ID` la red no se ofrece (`offered()`).
- [x] `GraphClient` se comporta igual tras extraer `GraphErrors`.

**Subtareas**
- [x] Caracterización de `GraphClient`
- [x] Extraer `GraphErrors`
- [x] Conector y cliente
- [x] Registro condicional

**Notas**: Patrón: `app/Connectors/Meta/GraphClient.php:159` (se extrae solo `REAUTH_CODES` y `RATE_LIMIT_CODES` a `Meta/GraphErrors`, sin cambiar comportamiento) y `MetaConnector`. `refresh_token = null`; `refresh()` con `th_refresh_token`; hosts en `config/social.php`; ID/secreto propios en `config/services.php` (`THREADS_APP_ID`, `THREADS_APP_SECRET`). Registro condicional en `AppServiceProvider.php:52-66`.

**Test RED**: `php artisan test --compact tests/Feature/Connectors/Threads` antes del código -> 12 errores (`Target class [App\Connectors\Threads\ThreadsConnector] does not exist`). Caracterización previa de GraphClient: `tests/Feature/Connectors/Meta` -> 90 en verde antes y después de extraer `GraphErrors`.

**Alcance ampliado en la implementación**: `Network.php` (caso `Threads`, `isAvailable()` por `THREADS_ENABLED` y `THREADS_APP_ID`, `listing()` solo con redes disponibles), `ConnectionController.php` (no lista una red no disponible), `config/services.php` (`threads.client_id/secret`), `tests/Support/ThreadsFake.php` y los tests de catálogo y registro de Bluesky que cuentan proveedores. Decisiones: el token viaja como documenta Meta (cuerpo en POST, query en GET; la cabecera Bearer no está documentada para Threads); el plazo de procesado es el global de `publishing.poll_max_minutes` (no se añade clave propia); zona de día de las métricas `America/Los_Angeles` ⚠️ a confirmar en el smoke.

**Verificación ejecutada**: `tests/Feature/Connectors/Meta` -> 90 passed (602 assertions); `tests/Feature/Connectors/Threads/ThreadsConnectorTest.php` -> 12 passed (82 assertions); `tests/Feature/Connectors tests/Feature/Connections tests/Feature/Publishing tests/Feature/Mcp tests/Unit` -> verde tras ajustar 2 tests de conteo; pint -> passed.

**Corrección revisión F8 (commit c48be99, ejecutada en la revisión F8-2/F10, A-6)**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsConnectorTest.php` -> 14 passed (90 assertions), incluido el mensaje de cuenta que no es tester (`looksLikeNotTester`).


### T-56 — Tipos, reglas de media y conteo de Threads

- **Descripción**: `threads_text`, `threads_image`, `threads_video` y `threads_carousel` en `ContentType` y `media_specs`; modo de conteo `threads`. Requisito: CA-08.
- **Changelog**: Threads tiene sus cuatro tipos (texto, imagen, vídeo y carrusel) con sus límites: 500 caracteres contando los emojis por bytes, imagen de 8 MB, vídeo de 5 minutos y carrusel de 2 a 20.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-54, T-21
- **Tipo**: backend
- **Archivos**: `app/Enums/ContentType.php`, `config/media_specs.php`, `app/Support/TextLength.php`, `resources/js/Composables/textLength.js`, `tests/Unit/MediaValidator/ThreadsRulesTest.php`, `tests/Unit/MediaValidator/MediaValidatorTest.php`, `resources/js/Components/Composer/NetworkCounters.vue`, `app/Http/Controllers/PostController.php`, `tests/Fixtures/text-length-cases.json`, `tests/Unit/TextLengthTest.php`, `tests/Feature/Connectors/Threads/ThreadsSurfacesTest.php`, `docs/roadmap/2026-10-26-metricool-parity-3/spec.md`, `docs/referencia/decisiones-por-red.md`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Unit/MediaValidator` → en verde (conteo de reglas actualizado y reglas de Threads)

**Criterios de aceptación**
- [x] Las reglas de Threads validan igual en compositor y `create_post`.
- [x] El conteo de reglas del test sube con Threads.
- [x] El conteo de Threads cuenta las secuencias de emoji por bytes UTF-8 y el resto por code point, y PHP y JS dan lo mismo caso a caso (22 casos, incluidos devanagari, NFD y zalgo).

**Subtareas**
- [x] Test RED
- [x] Tipos y reglas
- [x] Modo `threads`

**Notas**: Patrón: lo hecho en T-21 (`TextLength`). Imagen JPEG/PNG de 8 MB; vídeo MP4/MOV, H.264/HEVC, 23-60 fps, hasta 300 s y 1 GB; carrusel de 2 a 20 (valores de T-54).

**Test RED**: `tests/Unit/MediaValidator/ThreadsRulesTest.php` antes del código -> 8 de 9 fallan (sin tipos `Threads*` en `ContentType` ni reglas). Los valores salen de la tabla de T-54. El modo de conteo `threads` (`TextLength` y `textLength.js`) ya existía desde T-21 con su tabla de casos compartida (`tests/Fixtures/text-length-cases.json`); aquí solo se actualiza su comentario con lo verificado.

**Decisiones**: sin `max_hashtags` (Threads solo admite una etiqueta de tema por post: no hay tope por texto) y sin `first_comment` (responder exige `threads_manage_replies`, que no se pide). Mínimo de duración de vídeo 0,1 s («más de 0 s»). Versión de `media_specs` 2026-10-27; el test de conteo pasa de 15 a 19 reglas.

**Verificación ejecutada**: `php artisan test --compact tests/Unit` -> 319 passed (861 assertions), incluidos `ThreadsRulesTest` (9) y `MediaValidatorTest` con 19 reglas; pint -> passed.

**Corrección revisión F8 (commit c48be99, ejecutada en la revisión F8-2/F10, A-6)**: `php artisan test --compact tests/Unit/TextLengthTest.php tests/Unit/MediaValidator` -> 61 passed (283 assertions); `tests/Feature/Connectors/Threads/ThreadsSurfacesTest.php tests/Unit/Networks/NetworkCoverageTest.php` -> 16 passed (139 assertions).

**Corrección revisión F8-2 (B-2/B-3, commit b0189b2)**: decisión del orquestador: el grafema/secuencia con emoji cuenta los bytes UTF-8 de toda la secuencia y fuera de los emojis se cuentan **code points** (no grafemas), sin depender de `\X` ni de `Intl.Segmenter`. Las secuencias de emoji se encuentran con la MISMA regex en `TextLength::EMOJI_SEQUENCE` (PHP) y `EMOJI_SEQUENCE` de `textLength.js` (banderas, keycap, pictograma + VS16/tono/etiquetas unido por ZWJ y modificadores sueltos). `text-length-cases.json` pasa de 15 a 22 casos (devanagari «क्षत्रिय» = 8 y «नमस्ते» = 6, NFD = 11, zalgo = 18, devanagari con emoji y banderas = 44, keycap = 15, `e` + tilde + emoji = 6; el caso previo «e + acento combinante» pasa de 1 a 2, a propósito). Comprobado ejecutando los dos lenguajes caso a caso: PHP = JS en los 22 (`TextLengthTest` -> 4 passed, 98 assertions, incluido el espejo JS); el modo `graphemes` también coincide en los 7 casos nuevos.

**Corrección revisión F8-2 (A-3, commit b0189b2)**: E2E `threads.spec.ts` «compositor: con 2 etiquetas en Threads aparece el aviso…» (1 etiqueta sin aviso; 2 etiquetas -> `counter-hashtags-advice` con «Threads solo admite 1 etiqueta de tema por publicación: se usa la primera.», sin desborde y con axe). Servidor propio de Playwright con `THREADS_APP_ID`/`THREADS_APP_SECRET` falsos como variables del proceso: 3 passed (escritorio, móvil y móvil-360).


### T-57 — `ThreadsPublisher`: contenedores, carrusel, sondeo y publicación

- **Descripción**: Máquina de estados de contenedor (`TEXT`/`IMAGE`/`VIDEO`/`CAROUSEL`) con `StatusPoller` y `threads_publish`; errores traducidos. Requisito: CA-08.
- **Changelog**: Las publicaciones de Threads (texto, imagen, vídeo y carrusel de hasta 20) se crean, se esperan hasta que Threads las procesa y se publican; si falla, el motivo sale en español.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 9,0h · real 9,0h (estimado)
- **Tiempo IA (ejec.)**: est. 1,22h · real 1,22h (estimado)
- **Supervisión**: est. 0,30h (≈25 % IA) · real 0,30h (estimado)
- **Previsión IA**: 328k in / 36k out tok · ⚠️ verificar €
- **Dependencias**: T-55, T-56
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `tests/Feature/Connectors/Threads/ThreadsPublisherTest.php`, `app/Providers/AppServiceProvider.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsPublisherTest.php` → en verde (carrusel de 3 = 3 hijos + 1 padre, estados `FINISHED`/`ERROR`/`EXPIRED`)

**Criterios de aceptación**
- [x] Un carrusel de 3 imágenes crea 3 hijos y 1 padre, publica el padre y queda `publicada`.
- [x] `ERROR`/`EXPIRED` dan un error permanente traducido.
- [x] `InstagramPublisher` no tiene ningún cambio en `git diff`.

**Subtareas**
- [x] Test RED (CA-08)
- [x] Texto, imagen y vídeo
- [x] Carrusel con `checkpoint()`
- [x] Sondeo y publicación

**Notas**: Patrón: `app/Connectors/Meta/Instagram/InstagramPublisher.php` (contenedores, carrusel con `progress`, `advanceCarousel`) **copiado, sin heredar ni modificar** (T1). `PollPublishStatus`/`StepBudget` sin cambios; media por URL pública firmada (`PublishRequestFactory`). ⚠️ *permalink* sin verificar.

**Test RED**: `tests/Feature/Connectors/Threads/ThreadsPublisherTest.php` antes del código -> 24 errores (la clase `ThreadsPublisher` no existe).

**Decisiones**: copia (sin heredar ni tocar) la máquina de estados de `InstagramPublisher`; `ERROR`/`EXPIRED` lanzan `PermanentPublishException` con el `error_message` traducido (`contenedor_error` / `contenedor_caducado`); un contenedor `PUBLISHED` se da por publicado con el ID del contenedor (`external_id_kind = container`) hasta que T-58 lo localice entre las publicaciones recientes. El token va en el cuerpo del POST, nunca en la URL. Alcance ampliado: `app/Providers/AppServiceProvider.php` registra el publicador junto al conector. El *permalink* se pide con `GET /{id}?fields=permalink` (verificado en T-54); si falta se omite, la publicación ya existe.

**Verificación ejecutada**: `ThreadsPublisherTest.php` -> 31 passed (118 assertions): carrusel de 3 = 3 hijos + 1 padre, FINISHED/ERROR/EXPIRED/IN_PROGRESS, reanudación sin recrear hijos, errores 190/4/429/5xx/permisos; `tests/Feature/Connectors tests/Feature/Publishing` -> 540 passed; `git diff cb869bd -- app/Connectors/Meta/Instagram` -> vacío (InstagramPublisher sin cambios); pint -> passed.

### T-58 — Idempotencia y cuota de publicación de Threads

- **Descripción**: `findPublished` sobre `/me/threads` recientes y `assertQuota()` con `threads_publishing_limit`. Requisito: CA-09.
- **Changelog**: Threads respeta su límite de 250 publicaciones al día (la publicación espera en vez de fallar) y un reintento tras un corte de red no duplica el post.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-57
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsPublisher.php`, `tests/Feature/Connectors/Threads/ThreadsQuotaTest.php`, `tests/Feature/Connectors/Threads/ThreadsPublisherTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsQuotaTest.php` → en verde (cuota agotada no llama a `threads_publish`; reintento no duplica)

**Criterios de aceptación**
- [x] Con el límite agotado se aplica `QuotaPolicy` sin publicar.
- [x] Un reintento tras timeout no crea un segundo post.

**Subtareas**
- [x] Test RED (CA-09)
- [x] Cuota
- [x] `findPublished`

**Notas**: Patrón: `InstagramPublisher::assertQuota` (`:446`, `content_publishing_limit`) y `app/Services/QuotaPolicy.php` (sin tocarla). `QuotaExceededException` aplica la política sin llamar a la API de publicación.

**Test RED**: `tests/Feature/Connectors/Threads/ThreadsQuotaTest.php` antes del código -> 6 fallos de 13 (sin consulta de cuota ni búsqueda de recientes).

**Decisiones**: `assertQuota()` consulta `threads_publishing_limit?fields=quota_usage,config` antes del primer contenedor (en un carrusel, solo si no hay progreso); si la consulta falla por un error pasajero se intenta publicar igual, pero una credencial inválida pide reconectar. Sin `config.quota_total` se usa `social.threads.publish_quota` (250). `findRecent` lista `/{user}/threads?fields=id,text,permalink,timestamp&limit=25` y filtra por texto y hora en servidor (no se usa `since` en la consulta: su formato no está verificado); las publicaciones sin texto no se pueden reconocer (limitación igual que en Instagram). Un contenedor `PUBLISHED` se resuelve con esa búsqueda y, si no aparece, se da por publicado con el ID del contenedor. `ThreadsPublisherTest` pasa a fingir la cuota con holgura en su `beforeEach`.

**Verificación ejecutada**: `tests/Feature/Connectors/Threads` -> 56 passed (238 assertions); `ThreadsQuotaTest.php` -> 13 passed: cuota agotada no llama a `threads` ni a `threads_publish`, un reintento tras timeout recupera el post existente.

### T-59 — `ThreadsInsights`: post, cuenta y rango

- **Descripción**: Métricas de post (`views`, likes, comentarios, compartidos) y de cuenta (`PROFILE_VIEWS`, likes, `clicks`, `followers_count`). Requisito: C-02 de la evaluación y CA-10.
- **Changelog**: Las estadísticas de Threads llegan al panel: visitas al perfil, me gusta, respuestas, compartidos, clics en enlaces y seguidores, y por publicación vistas e interacciones.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real 7,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,94h · real 0,94h (estimado)
- **Supervisión**: est. 0,23h (≈25 % IA) · real 0,23h (estimado)
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-55
- **Tipo**: backend
- **Archivos**: `app/Connectors/Threads/ThreadsInsights.php`, `app/Analytics/Metric.php`, `app/Services/AnalyticsService.php`, `tests/Feature/Connectors/Threads/ThreadsInsightsTest.php`, `app/Providers/AppServiceProvider.php`, `resources/js/Pages/Dashboard/Network.vue`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsInsightsTest.php` → en verde (mapa, `null` y rango)

**Criterios de aceptación**
- [x] Cada métrica se mapea según `design.md`.
- [x] `null` = no disponible.

**Subtareas**
- [x] Test RED
- [x] Lector de insights
- [x] Entradas de `Metric` y `SUPPORTED`

**Notas**: Patrón: `app/Connectors/Contracts/RangeInsightsReader.php`, `app/Connectors/Meta/Insights.php`, `Metric.php`. `views` de cuenta = visitas al perfil → `PROFILE_VIEWS`; `shares` del post «en desarrollo» ⚠️: `null` si no llega, nunca 0. Rango desde 2024-04-13.

**Test RED**: `tests/Feature/Connectors/Threads/ThreadsInsightsTest.php` antes del código -> 15 errores (la clase `ThreadsInsights` no existe).

**Decisiones**: `views` de cuenta = visitas al perfil -> `PROFILE_VIEWS` (`VIEWS` queda `null`, fuera de `SUPPORTED`); `reposts + quotes` -> `SHARES`; `clicks` llega por enlace y se suma en la nueva métrica de flujo `Metric::CLICKS` («Clics en enlaces», añadida a `ACCOUNT`, `LABELS` y al grupo Interacciones de `Dashboard/Network.vue`; no entra en `EXPECTED_FLOWS` porque sin enlaces pulsados no hay dato); `followers_count` solo para el último día cerrado (no admite `since/until`). El día se pide a las 00:00 de `America/Los_Angeles` con `since/until` Unix (⚠️ zona y formato a confirmar en el smoke) y se lee dia a dia (`maxRangeDays` = 0); antes del 13-04-2024 no se llama a la API. Si Threads rechaza el conjunto de métricas, se piden una a una y las rechazadas quedan `null`; en el post, `views` y `shares` («en desarrollo») también quedan `null` si no llegan. El ID de un contenedor (publicación no localizada) no se lee (`canReadPost`). Alcance ampliado: `AppServiceProvider.php` (registro del lector) y `Network.vue`.

**Verificación ejecutada**: `ThreadsInsightsTest.php` -> 15 passed (mapa, null y rango); `tests/Feature/Connectors tests/Feature/Analytics tests/Unit` -> 767 passed (2997 assertions); pint -> passed.

**Corrección revisión F8 (commit c48be99, ejecutada en la revisión F8-2/F10, A-6)**: `php artisan test --compact tests/Feature/Connectors/Threads/ThreadsInsightsTest.php` -> 19 passed (109 assertions) con los tests de la caché de rechazadas.

**Corrección revisión F8-2 (B-1, commit 50edb6d)**: la caché de métricas rechazadas lleva ahora la clave de la CUENTA (`external_id`) + tipo de lectura + conjunto. Si todas las métricas de un post fallan sin que ningún error hable de la métrica o de un permiso de la cuenta (post borrado o inexistente, código 100 de un post), el post se salta sin cachear nada; solo se cachea el rechazo cuando otra métrica de la misma petición sí responde o el error nombra la métrica / es de permisos. Tests nuevos: «un post borrado no apaga las métricas de otros posts ni de otras cuentas» (el escenario del revisor el reproductor del revisor: tras un post borrado, un post sano de otra cuenta trae `likes` y `views`) y «la métrica rechazada se recuerda por cuenta». el reproductor del revisor ahora: `post sano de OTRA cuenta despues: likes=7 views=7 calls=1`.


### T-60 — Superficies de Threads: icono, pestañas, MCP, IA e informe

- **Descripción**: Icono y resto de superficies; el catálogo de T-16/T-17 hace el resto. Requisito: CA-10.
- **Changelog**: Threads aparece en el compositor, el calendario, el panel, los informes y el asistente MCP con su icono; si se apaga o no está configurada, desaparece de todos esos sitios.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-17, T-18, T-59
- **Tipo**: fullstack
- **Archivos**: `resources/js/Components/NetworkIcon.vue`, `app/Connectors/Network.php`, `app/Services/ComposerPresetService.php`, `app/Services/ReportService.php`, `tests/Unit/Networks/NetworkCoverageTest.php`, `tests/Feature/Connectors/Threads/ThreadsSurfacesTest.php`, `app/Mcp/Tools/GetBestTimes.php`, `app/Mcp/Tools/SuggestHashtags.php`
- **Cubre (tests)**: E2E-08
- **Verificación**:
  - `php artisan test --compact tests/Unit/Networks/NetworkCoverageTest.php` → en verde con Threads
  - `php artisan test --compact tests/Feature/Reports` → en verde (informe PDF y CSV incluyen las dos redes)

**Criterios de aceptación**
- [x] Threads aparece en calendario, dashboard, informes y MCP.
- [x] El test de superficies pasa sin excepciones.

**Subtareas**
- [x] Icono
- [x] Presets e informes
- [x] Comprobar `NetworkCoverageTest`

**Notas**: Patrón: `NetworkIcon.vue`, `Network.php`, `ComposerPresetService.php:23-24`, informes PDF (`app/Services/Reports`, `ReportService`). El `NetworkCoverageTest` (T-18) es el oráculo.

**Test RED**: el caso nuevo de `NetworkCoverageTest` (Threads configurada) falló con `icono en NetworkIcon.vue` antes de añadir el glifo; `ThreadsSurfacesTest.php` pasa casi entero desde el catálogo de T-16/T-17 (esa era la apuesta de la opción O1) y fija el comportamiento de cada superficie.

**Decisiones**: único cambio de código de esta tarea, el icono (`NetworkIcon.vue`); `ComposerPresetService.php` no cambia (Threads no tiene interruptores recordables) y `ReportService.php` tampoco (recorre las redes conectadas y `AnalyticsService::SUPPORTED`). El informe omite `Visualizaciones` de Threads (no está en `SUPPORTED`) y el CSV agregado la deja vacía, nunca 0 ni las visitas al perfil. Cubre E2E-08 en la parte de servidor; el escenario Playwright es T-62.

**Verificación ejecutada**: `NetworkCoverageTest.php` -> 5 passed (todas las superficies de Threads, icono incluido); `ThreadsSurfacesTest.php` -> 11 passed: compositor, calendario, dashboard, MCP (500 caracteres y emojis por bytes), informe PDF/CSV, Conexiones, apagado por `THREADS_ENABLED`, sin `THREADS_APP_ID` y reprogramación; `tests/Feature/Reports` -> verde (46 con `tests/Unit/Networks`); pint -> passed.

**Corrección revisión F8 (commit c48be99, ejecutada en la revisión F8-2/F10, A-6)**: `php artisan test --compact tests/Feature/Connectors/Threads tests/Unit/TextLengthTest.php tests/Feature/Mcp` -> 380 passed (2369 assertions): superficies de Threads, MCP con el `enum` de redes solo con las disponibles (`Network::availableValues()`) y conteo de 500 caracteres con emojis por bytes.


### T-61 — Pest de integración de Threads (CA-07..CA-09)

- **Descripción**: Suite con `Http::fake`: OAuth → programar → publicar los 4 tipos → cuota → métricas.
- **Changelog**: Probado de punta a punta el flujo de Threads (conectar, programar, publicar texto, imagen, vídeo y carrusel, cuota y métricas) sin tocar la red real.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 7,0h · real 7,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,94h · real 0,94h (estimado)
- **Supervisión**: est. 0,23h (≈25 % IA) · real 0,23h (estimado)
- **Previsión IA**: 255k in / 28k out tok · ⚠️ verificar €
- **Dependencias**: T-58, T-59
- **Tipo**: test
- **Archivos**: `tests/Feature/Connectors/Threads/ThreadsFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Instagram` → todo en verde (sin regresión de Instagram)

**Criterios de aceptación**
- [x] CA-07, CA-08 y CA-09 cubiertos.
- [x] Instagram sin regresión.

**Subtareas**
- [x] Escribir los flujos

**Notas**: Patrón: `tests/Feature/Connectors/Meta/` y `tests/Feature/Instagram/`.

**Test RED**: no aplica de forma pura: es una suite de integración sobre T-55..T-60 ya implementados; se escribió contra el comportamiento esperado de CA-07..CA-09 y destapó dos supuestos de los propios tests (la hora de cierre del día en el cambio de hora del Pacífico y la cola síncrona que encadena los sondeos), no del código.

**Cobertura**: CA-07 (OAuth real por las rutas, token largo, renovación antes del margen), CA-08 (texto, imagen por `image_url` firmada, vídeo en proceso -> publicado, carrusel de 3 = 3 hijos + 1 padre, contenedor `ERROR` traducido), CA-09 (cuota agotada aplaza por `QuotaPolicy` sin crear contenedor; un timeout y su reintento no duplican) y métricas (cuenta con `views` nulo y visitas al perfil, publicación con vistas y compartidos). Todo con `Http::fake` y `Http::preventStrayRequests()`.

**Verificación ejecutada**: `ThreadsFlowTest.php` -> 10 passed (80 assertions); `tests/Feature/Connectors tests/Feature/Publishing tests/Feature/Instagram` -> 609 passed (2927 assertions): Instagram sin regresión; pint -> passed.

### T-62 — E2E de Threads: superficies con axe (CA-10)

- **Descripción**: Escenario Playwright con posts de Bluesky y Threads: iconos, filtros y métricas en calendario y dashboard. Requisito: CA-10.
- **Changelog**: Comprobado en el navegador (escritorio y móvil) que Threads se ve bien junto a Bluesky: Conexiones, calendario con su filtro, panel con visitas al perfil y compositor con el límite de 500.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,5h · real 2,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,34h · real 0,34h (estimado)
- **Supervisión**: est. 0,09h (≈25 % IA) · real 0,09h (estimado)
- **Previsión IA**: 91k in / 10k out tok · ⚠️ verificar €
- **Dependencias**: T-60
- **Tipo**: test
- **Archivos**: `tests/e2e/threads.spec.ts`, `tests/e2e/helpers.ts`
- **Cubre (tests)**: E2E-08
- **Verificación**: `npx playwright test tests/e2e/threads.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] Iconos, filtros y métricas de las dos redes visibles.
- [x] 0 violaciones axe; sin desborde.

**Subtareas**
- [x] Semillas
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/bluesky.spec.ts` (T-28).

**Primer oráculo**: el propio escenario Playwright (el código que cubre ya estaba en T-55..T-60); lo que destapó fue de la prueba, no del producto (el campo «Texto» choca con el desplegable del tipo, que también se llama «Texto»: se usa `#text`).

**Cobertura (E2E-08, 4 casos × 3 proyectos)**: Conexiones (tarjeta de Threads y su cuenta sembrada; el botón «Conectar» no se pulsa para no salir a threads.com), calendario (chip de cada red y filtro «Red» por Threads que oculta la de Bluesky), dashboard de Threads (120 visitas al perfil sumadas de 3 días, clics 33, me gusta 36; `views` y `reach` ni se listan; la publicación con sus 100 vistas) y resumen con ambas redes en la navegación, y compositor (`Hola` + cohete = 9/500; 126 cohetes = 504/500 y aviso; 500 letras = 500/500). Cada página con axe (serio/crítico) y `expectNoOverflow`; sin errores de consola.

**Entorno**: la red solo existe con `THREADS_APP_ID`: para la ejecución se añadió temporalmente un ID de mentira al `.env` local (sin commit; restaurado después). Los datos se siembran con factorías/servicios en marcas `e2e-composer-*` de los usuarios `e2e-sec-*`; el usuario 28 y las redes reales no se tocan.

**Verificación ejecutada**: `npm run build` y `E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/threads.spec.ts --project=desktop --project=mobile --project=mobile-360` -> 4 passed en cada proyecto (12 en total).

### T-63 — Documentación y revisión de robustez de Threads

- **Descripción**: Documentar el conector y revisar robustez (tokens de 60 días, cuota).
- **Changelog**: Documentado cómo funciona Threads: conexión y renovación del token de 60 días, tipos y límites verificados, cuota de 250 al día, idempotencia, métricas y el interruptor para apagarlo.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-61, T-62
- **Tipo**: docs
- **Archivos**: `docs/modulos/conexiones.md`, `docs/modulos/publicacion.md`, `docs/modulos/analitica.md`, `docs/referencia/decisiones-por-red.md`, `docs/referencia/configuracion.md`, `app/Connectors/Threads/ThreadsClient.php`, `config/social.php`, `.env.example`, `tests/Feature/Connectors/Threads/ThreadsPublisherTest.php`
- **Cubre (tests)**: —
- **Verificación**: lectura: los docs listan tipos, límites verificados en T-54, hosts configurables y métricas de Threads → coherente con T-55..T-59

**Criterios de aceptación**
- [x] Los valores coinciden con la config.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/conexiones.md`, `publicacion.md`, `analitica.md` y `docs/referencia/decisiones-por-red.md`.

**Nota de cierre**: Robustez revisada: tokens (token largo cifrado, sin `refresh_token`, renovación 7 días antes con el requisito de ≥ 24 h cumplido, 401/190 = reconexión y 5xx/429/red = transitorio, sin `requiere_reconexion` por un fallo pasajero); el token nunca se registra (los logs solo llevan estado y códigos y un corte de red se relanza sin el mensaje de cURL, que trae la URL); cuota (se consulta antes de gastar contenedor, agotada aplaza por `QuotaPolicy`, un carrusel retomado no la vuelve a gastar); idempotencia (contenedor guardado con `checkpoint`, búsqueda por texto y hora antes de reintentar, sin presupuesto no se publica a ciegas); `InstagramPublisher` y `GraphClient` sin cambios de comportamiento (`git diff` vacío en Instagram y 90 tests de Meta en verde). Hallazgo mitigado: Meta documenta el token en la query de los GET (queda en registros de proxy), a diferencia del resto de conectores; se añade `THREADS_TOKEN_HEADER` (por defecto `false`, `Authorization: Bearer`, con test) para pasarlo a la cabecera en cuanto el smoke (T-64) confirme que Threads lo acepta. Límites conocidos (documentados): una publicación sin texto no se puede reconocer al reintentar; la interpretación de los emojis compuestos en los 500 caracteres, los códigos de error propios, el modo desarrollo y la zona del día de las métricas quedan para el smoke.

**Alcance ampliado en la implementación**: `docs/referencia/configuracion.md` (variables nuevas), `ThreadsClient.php`, `config/social.php` y `.env.example` (el interruptor del token) y `ThreadsPublisherTest.php` (su test).

**Verificación ejecutada**: lectura: conexiones.md (sección Threads, piezas, scopes, margen 168 h), publicacion.md (tipos y límites 500/8 MB/300 s/1 GB/2-20, estados, carrusel, cuota 250, idempotencia, errores, interruptor), analitica.md (mapa de métricas con `profile_views` y `clicks`, `null`, histórico de 30 días) y decisiones-por-red.md (tabla de T-54, cómo funciona y pendientes del smoke) -> coherentes con T-55..T-59; los valores coinciden con `config/media_specs.php`, `config/social.php` y `.env.example`. `tests/Feature/Connectors/Threads` -> 94 passed (543 assertions).

### T-64 — Smoke real de Threads con el usuario

- **Descripción**: El usuario conecta su cuenta (app de Meta para Threads, *tester*) y publica los 4 tipos. Resuelve los ⚠️ restantes y confirma que no hace falta App Review.
- **Changelog**:
- **Estado**: en-progreso
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

**Estado del smoke (preparado, pendiente del usuario)**: la guía T-74 ya está escrita (`docs/guias/threads-app-meta.md`, enlazada desde `docs/README.md` y `docs/RAG-INDEX.md`) y todo el código de Threads (T-55..T-62) está en verde con `Http::fake`. **No se marca completada**: el smoke es manual con la app de Meta y la cuenta reales del usuario (M-02 del `test-plan.md`, que el implementer no puede editar: este bloque es la preparación y la tabla de ⚠️ de M-02, para volcarla en el plan).

**Preparación (la hace el usuario)**:
1. Seguir la guía T-74: caso de uso Threads, los **dos** pares de ID/secreto (usar el de **Threads**), `THREADS_APP_ID`/`THREADS_APP_SECRET` en `.env`, URI `<APP_URL>/connections/threads/callback` y la invitación de *tester* **aceptada** en Threads (Ajustes → Cuenta → Permisos del sitio web).
2. Tener a mano 4 imágenes JPEG o PNG (una de unos 7 MB), un MP4 H.264 de menos de 5 min y de 23 a 60 fps (y un MOV o HEVC si hay) y textos con emojis (paso 2).
3. La media debe ser **descargable desde internet** (`MEDIA_DISK` con URL firmada pública: ngrok o el almacenamiento real). Usar una marca propia de pruebas (no el usuario 28 ni datos de clientes). `php artisan migrate` aplicada y `npm run build` hecho; cola corriendo en su entorno (`queue:work`) y scheduler, o `php artisan schedule:run` a mano.

**Pasos de M-02**:
1. **Conectar.** Conexiones → Threads → «+ Conectar» → `threads.com` → aceptar los tres permisos. Esperado: «Conectada» y el nombre de usuario; `token_expires_at` a unos 60 días y `refresh_token` nulo (`php artisan tinker` → `App\Models\SocialAccount::latest('id')->first()`). Probar antes **cancelar** en `threads.com`: mensaje claro y nada guardado.
2. **Texto con emojis al límite.** Un texto que el contador marque `500 / 500` con emojis sueltos (cohete, corazón) y otro con secuencias compuestas (familia con unión de ancho cero, bandera, emoji con tono de piel); publicarlos. Esperado: Threads acepta 500 y rechaza 501. **Anotar para cada secuencia** cuántos caracteres cuenta Threads frente a nuestro contador (⚠️ de la regla de emojis).
3. **Imagen.** JPEG con texto y luego una PNG. Esperado: «Publicada» con enlace; Threads descarga la imagen de la URL firmada.
4. **Vídeo.** MP4 de menos de 5 min. Esperado: «Procesando» y luego «Publicada» (anotar cuánto tarda en `FINISHED`). Probar uno fuera de reglas que el validador deje pasar (por ejemplo de más de 60 fps) para ver el `error_message` real del contenedor.
5. **Carrusel.** 3 imágenes (y, si se puede, 2 imágenes + 1 vídeo). Esperado: **un** post con todas y un solo gasto de cuota.
6. **Cuota.** `app(App\Connectors\Threads\ThreadsClient::class)->get($cuenta->external_id.'/threads_publishing_limit', ['fields' => 'quota_usage,config'], $cuenta->access_token)`. Anotar `quota_usage`, `quota_total` y `quota_duration` (¿250 y 86400?).
7. **Métricas** (pasadas unas horas): `app(App\Analytics\SnapshotCollector::class)->collectAccount($cuenta, App\Analytics\Days::closed(App\Connectors\Network::Threads))` y `collectPosts($cuenta, 48)`. Esperado en el dashboard de Threads: visitas al perfil, me gusta, respuestas, compartidos, clics y seguidores; por publicación vistas e interacciones. Anotar qué llega y qué queda «No disponible».
8. **Renovación.** Con el token de más de 24 h: `app(App\Connectors\Threads\ThreadsConnector::class)->refresh($cuenta)`. Esperado: token nuevo de unos 60 días.
9. **App Review.** Con todo lo anterior hecho solo con tu cuenta como *tester*, confirmar que **no** hizo falta enviar la app a revisión. Si Meta la exigiera, dejar `THREADS_APP_ID` vacío (la red desaparece sin tocar Facebook ni Instagram) y anotarlo.

**⚠️ a resolver y anotar** (T-54 y diseño §7):

| ⚠️ | Qué comprobar | Resultado (rellenar) |
|---|---|---|
| Modo desarrollo | ¿Publicar y leer métricas funciona sin App Review con la cuenta como *tester*? | |
| Regla de emojis | Cuántos caracteres cuenta Threads por emoji suelto y por secuencia compuesta (ZWJ, banderas, tonos). Si no coincide con `TextLength::threads` y `textLength.js`, ajustar ambos y `tests/Fixtures/text-length-cases.json` | |
| Token en la petición | Los POST llevan `access_token` en el cuerpo y los GET en la query (como documenta Meta). ¿Acepta también `Authorization: Bearer`? Solo para decidir si se saca el token de la URL en los GET | |
| Códigos de error propios | Código y mensaje reales de cuota agotada, permiso no concedido, texto demasiado largo y más de 5 enlaces (`THREADS_API__LINK_LIMIT_EXCEEDED`); hoy se reutilizan los de Graph (`GraphErrors`) | |
| Permisos concedidos | `scopes` guardados (los pedidos) frente a los realmente concedidos | |
| IDs y *permalink* | ¿`threads_publish` devuelve el ID de la publicación y `GET /{id}?fields=permalink` el enlace? ¿Qué ID queda en un contenedor `PUBLISHED` sin localizar? | |
| Búsqueda de lo ya publicado | `GET /{user}/threads` devuelve el texto idéntico al enviado (con enlaces, saltos de línea y emojis) | |
| Día de las métricas | Zona real de `end_time` de las series diarias (`America/Los_Angeles`: 08:00 UTC en invierno) y el rango desde 2024-04-13 con `since`/`until` Unix | |
| Métricas «en desarrollo» | `views` y `shares` del post: ¿llegan con valores o error? | |

**Resultado (rellenar por el usuario)**: fecha · cuenta · cada paso OK/KO · enlaces de los posts · hallazgos.

## Fase 9 — C-04b · GIFs de Giphy

**Estado**: completado · **Estimado**: 18,5h · **Real**: — · **Coste est.**: 1.110 € (con margen; base 925 €) · **Tokens est.**: 749k

### T-65 — `GiphyClient` y *proxy* (búsqueda, tendencias, caché, límite y errores)

- **Descripción**: Proxy en el servidor (la clave no va al navegador) con búsqueda, tendencias, clasificación `pg-13`, caché y límite. Requisito: CA-15, D4.
- **Changelog**: La búsqueda de GIFs de Giphy pasa por el servidor (la clave no se expone), con caché, tope de llamadas por hora y mensajes claros.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: ninguna
- **Tipo**: backend
- **Archivos**: `app/Services/Media/GiphyClient.php`, `app/Services/Media/GiphyException.php`, `app/Http/Controllers/GifController.php`, `config/services.php`, `config/social.php`, `.env.example`, `routes/web.php`, `tests/Feature/Media/GiphyProxyTest.php`, `app/Providers/AppServiceProvider.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/GiphyProxyTest.php` → en verde (búsqueda, tendencias, caché, límite, error de la API; la clave no sale)

**Criterios de aceptación**
- [x] La clave nunca aparece en respuestas ni props.
- [x] Un error de Giphy se traduce a un mensaje claro.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Cliente y proxy

**Notas**: Patrón: `app/Services/Media/UrlSource.php` y `AiAssistant` (límites). Clave `GIPHY_API_KEY` en `config/services.php`; 100 llamadas/hora de la clave beta (cachear). Sin clave el botón no aparece.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Media/GiphyProxyTest.php -> 11 passed (59 assertions): búsqueda con clave, rating pg-13 y solo los campos necesarios; tendencias sin texto; la clave no sale en la respuesta ni en la página del compositor; caché (2 llamadas para 3 peticiones); cupo por hora agotado -> 429 «Has hecho muchas búsquedas de GIF; prueba en unos minutos.» sin llamar a Giphy; 429/5xx de Giphy y fallo de conexión traducidos sin la clave; resultados filtrados a hosts de Giphy; sin clave 404; throttle de 20/min por usuario.

**Corrección revisión P3 F6-9 (intento 3)**: D: tope por usuario y hora (`SOCIAL_GIPHY_PER_USER_HOURLY_LIMIT`, 30) además del global; F: 401/403 -> «La clave de Giphy no es válida…», `Log::warning` sin clave y cupo devuelto; G: `allowedMediaUrl` rechaza usuario O contraseña; J: limitador con nombre `giphy` con `SOCIAL_GIPHY_PER_USER_PER_MINUTE`; K: consulta normalizada (minúsculas, trim, espacios). Tests en `GiphyProxyTest`. Verificación: `php artisan test --compact tests/Feature/Media/Giphy*` -> 40 passed.

### T-66 — `GifPicker.vue`: búsqueda, cuadrícula, teclado, atribución y estados

- **Descripción**: Modal con búsqueda, cuadrícula, teclado, «Powered by GIPHY» y estados vacío y de error. Requisito: CA-15.
- **Changelog**: El compositor tiene un botón GIF (solo con la clave de Giphy) que abre un buscador accesible con «Powered by GIPHY» y añade el GIF elegido como vídeo.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 5,0h · real 5,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,68h · real 0,68h (estimado)
- **Supervisión**: est. 0,17h (≈25 % IA) · real 0,17h (estimado)
- **Previsión IA**: 182k in / 20k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: frontend
- **Archivos**: `resources/js/Components/Composer/GifPicker.vue`, `resources/js/Components/Composer/MediaSection.vue`, `resources/js/Composables/useComposer.js`, `resources/js/Pages/Posts/Compose.vue`, `app/Http/Controllers/PostController.php`, `tests/Feature/Ui/GifPickerPropsTest.php`
- **Cubre (tests)**: E2E-09, M-03, M-04
- **Verificación**: `php artisan test --compact tests/Feature/Ui/GifPickerPropsTest.php` → en verde (prop `giphy.enabled` según la clave)

**Criterios de aceptación**
- [x] El modal muestra «Powered by GIPHY».
- [x] Navegable por teclado con foco gestionado.

**Subtareas**
- [x] Modal
- [x] Cuadrícula y estados
- [x] Atribución

**Notas**: Patrón: `resources/js/Components/Composer/MediaSection.vue` y el selector de Drive (`useDrivePicker.js`). Atribución obligatoria y visible; foco atrapado y `Esc` cierra (axe).

**Verificación ejecutada**: php artisan test --compact tests/Feature/Ui/GifPickerPropsTest.php -> 3 passed (38 assertions): `giphy.enabled` false sin clave (null o vacía) y true con ella, la página solo lleva ese booleano y no contiene la clave, y el componente declara «Powered by GIPHY» y busca por la ruta `gifs.search`. E2E en T-70 (foco gestionado, Esc, teclado, axe).

**Corrección revisión P3 F6-9 (intento 3)**: L: ⚠️ RED NO REGISTRADO — no se anotó la evidencia `RED: <test> falló con <error>` antes del código de `GifPicker.vue`; no se reconstruye a posteriori (declarado, no inventado).

### T-67 — Importar el MP4 elegido con `ImportRemoteMedia` y lista permitida de hosts

- **Descripción**: Al elegir, se importa la versión MP4 con `ImportRemoteMedia` (hosts de Giphy permitidos y `UrlGuard`) y `source = giphy`. Requisito: CA-15.
- **Changelog**: El GIF elegido se descarga en segundo plano como MP4 solo desde los servidores de Giphy, pasando por las mismas protecciones que las importaciones por URL.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: backend
- **Archivos**: `app/Services/Media/GiphySource.php`, `app/Jobs/ImportRemoteMedia.php`, `app/Models/MediaAsset.php`, `app/Services/MediaUploadService.php`, `tests/Feature/Media/GiphyImportTest.php`, `app/Http/Controllers/GifController.php`, `app/Services/Media/GiphyClient.php`, `tests/Feature/Media/GiphyFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**:
  - `php artisan test --compact tests/Feature/Media/GiphyImportTest.php` → en verde (host permitido, SSRF bloqueado, `source=giphy`)
  - `php artisan test --compact tests/Feature/Media/ImportRemoteMediaTest.php` → sin regresión

**Criterios de aceptación**
- [x] Un host fuera de la lista se rechaza.
- [x] La media importada queda «listo» con `source=giphy`.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Lista permitida
- [x] Integración con el importador

**Notas**: Patrón: `app/Jobs/ImportRemoteMedia.php`, `app/Services/Media/{UrlGuard,UrlSource,RemoteMediaImporter}.php`, `tests/Feature/Media/ImportRemoteMediaTest.php` y `app/Models/MediaAsset.php` (`source`). Cola `media` y `MEDIA_DISK=local`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Media/GiphyImportTest.php -> 16 passed (77 assertions): MP4 de host de Giphy -> asset pendiente `source=giphy` en la cola `media`; 10 URLs fuera de la lista (otro dominio, subdominio/sufijo falso, IP interna, metadatos de nube, http, puerto, credenciales, no-MP4, file://) -> 422 sin crear nada ni pedir nada; redirección fuera de Giphy cortada ANTES de pedir el destino (SSRF) con el asset `invalido`; IP privada resuelta -> 422; descarga completa con ffmpeg -> `listo` (video/mp4, < 3 s); límite de 3 importaciones vivas. Suite tests/Feature/Media (incluye ImportRemoteMediaTest) sin regresión.

**Corrección revisión P3 F6-9 (intento 3)**: E: la importación extrae el id, pide el GIF a Giphy (`GiphyClient::mp4ById`, con comprobación de `rating` y cupo) y descarga la URL que Giphy devuelve, no la del cliente; G: tests de usuario/contraseña. Verificación: `GiphyImportTest` y `GiphyFlowTest` en verde.

### T-68 — Compatibilidad por red de los GIF (duración mínima y avisos)

- **Descripción**: Avisos de compatibilidad por red: los GIF de menos de 3 s no valen en Reels ni TikTok; en Bluesky entra como vídeo con `presentation: gif`; en Threads como `threads_video`. Requisito: CA-15.
- **Changelog**: Los GIF de menos de 3 segundos se rechazan en Reels de Instagram y Facebook y en TikTok con el mínimo exigido; Bluesky y Threads los aceptan como vídeo.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-67, T-24
- **Tipo**: backend
- **Archivos**: `tests/Feature/Media/GifCompatibilityTest.php`, `app/Connectors/Data/PublishMedia.php`, `app/Services/PublishRequestFactory.php`, `app/Connectors/Bluesky/BlueskyPublisher.php`, `tests/Feature/Connectors/Bluesky/BlueskyVideoTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media/GifCompatibilityTest.php` → en verde (aviso < 3 s, Bluesky y Threads aceptan)

**Criterios de aceptación**
- [x] Se avisa en Reels y TikTok si dura menos de 3 s.
- [x] En Bluesky el GIF respeta `video_max_bytes`.

**Subtareas**
- [x] Test RED — ⚠️ RED NO REGISTRADO: sin salida roja (no se reconstruye un RED retrospectivo)
- [x] Avisos por red

**Notas**: Patrón: `app/Services/MediaProbe.php` y `MediaValidator`. No hay ffmpeg para alargarlos: solo se avisa. Ninguna red acepta `image/gif`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Media/GifCompatibilityTest.php -> 11 passed (18 assertions): 2 s rechazado en InstagramReel, FacebookReel y TikTokVideo con «al menos 3 s»; 3,2 s válido; aceptado en BlueskyVideo, ThreadsVideo, YouTubeShort y FacebookVideo; Bluesky respeta `video_max_bytes`; `image/gif` no vale en ninguna red. No hizo falta tocar `MediaValidator`, `MediaProbe` ni `media_specs.php`: `min_duration: 3` ya existía y el MP4 de Giphy se valida como cualquier vídeo.

**Corrección revisión P3 F6-9 (intento 3)**: B: `PublishMedia` lleva `source`; el vídeo de Bluesky con `source=giphy` va con `presentation: gif` en `app.bsky.embed.video` (test con giphy/upload/null). ⚠️ El campo `presentation` del lexicon NO se ha podido verificar sin red (design.md:405 lo daba por verificado): comprobar en el smoke contra un PDS real. Verificación: `php artisan test --compact tests/Feature/Connectors/Bluesky` -> 108 passed.

**Alcance (revisión F8-2/F10)**: `app/Connectors/Data/PublishMedia.php` y `app/Services/PublishRequestFactory.php` están declarados en `Archivos` porque los tocó `d79b281` (entrada de GIF/vídeo corto en la petición de publicación).


### T-69 — Pest de integración CA-15

- **Descripción**: Buscar «gato», elegir un GIF y que se importe como media (API simulada).
- **Changelog**: Probado de punta a punta que buscar «gato» en el buscador de GIFs y elegir uno importa su MP4 como media de la publicación.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-66, T-67, T-68
- **Tipo**: test
- **Archivos**: `tests/Feature/Media/GiphyFlowTest.php`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Media` → todo en verde

**Criterios de aceptación**
- [x] CA-15 cubierto.

**Subtareas**
- [x] Escribir el test

**Notas**: Patrón: `tests/Feature/Media/ImportUrlEndpointTest.php`.

**Verificación ejecutada**: php artisan test --compact tests/Feature/Media -> suite en verde (GiphyFlowTest: buscar «gato» -> elegir -> importar el MP4 -> asset `listo` con `source=giphy`; solo se habló con api.giphy.com y media2.giphy.com; la prop `giphy.enabled`; otro usuario recibe 404). Suite completa: 2416/2416.

### T-70 — E2E del buscador de GIFs con axe

- **Descripción**: Escenario Playwright con Giphy simulado por el servidor de pruebas. Requisito: CA-15.
- **Changelog**: El buscador de GIFs queda probado en escritorio y móvil, con accesibilidad (axe) y Giphy simulado.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 3,0h · real 3,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,41h · real 0,41h (estimado)
- **Supervisión**: est. 0,10h (≈25 % IA) · real 0,10h (estimado)
- **Previsión IA**: 109k in / 12k out tok · ⚠️ verificar €
- **Dependencias**: T-66
- **Tipo**: test
- **Archivos**: `tests/e2e/gif-picker.spec.ts`
- **Cubre (tests)**: E2E-09
- **Verificación**: `npx playwright test tests/e2e/gif-picker.spec.ts --project=desktop --project=mobile --project=mobile-360` → verde en los 3 proyectos

**Criterios de aceptación**
- [x] Buscar, elegir e importar; atribución visible.
- [x] 0 violaciones axe; sin desborde.

**Subtareas**
- [x] Fake de Giphy en el entorno e2e
- [x] Spec E2E

**Notas**: Patrón: `tests/e2e/media-import.spec.ts`. Giphy real no se llama.

**Verificación ejecutada**: E2E_NO_SERVER=1 E2E_BASE_URL=https://metricool.test npx playwright test tests/e2e/gif-picker.spec.ts --project=desktop --project=mobile --project=mobile-360 -> 9 passed (3 escenarios x 3 proyectos): sin clave no hay botón ni peticiones; buscar, «Powered by GIPHY» visible, elegir con teclado, importar el MP4 (POST /media/import-gif) y devolver el foco al botón; Esc cierra; estado vacío y error de cupo anunciados con role=alert; axe sin violaciones serias/críticas y sin desborde. Giphy simulado con `page.route` (la clave no se escribe en `.env`).

### T-71 — Documentación y revisión de robustez de C-04b

- **Descripción**: Documentar el buscador de GIFs y revisar robustez (SSRF, cuota de la clave).
- **Changelog**: La guía y la documentación explican el buscador de GIFs, los GIF cortos en Reels y TikTok y la atribución a Giphy.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-69, T-70
- **Tipo**: docs
- **Archivos**: `docs/modulos/media.md`, `docs/usuario/guia-de-usuario.md`, `docs/referencia/configuracion.md`, `docs/guias/giphy-api-key.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `media.md` describe proxy, lista de hosts, avisos por red y atribución → coherente con T-65..T-68

**Criterios de aceptación**
- [x] La guía de usuario explica el GIF y sus limitaciones.

**Subtareas**
- [x] Redactar los docs
- [x] Revisión de robustez

**Notas**: Patrón: `docs/modulos/media.md`.

**Verificación ejecutada**: lectura: `docs/modulos/media.md` (sección «Buscador de GIFs de Giphy») describe proxy y cupo, lista cerrada de hosts (https, 443, `media*.giphy.com`/`i.giphy.com`, `.mp4`, comprobada en cada redirección y con `UrlGuard`), avisos por red y atribución -> coherente con T-65..T-68. Revisión de robustez: SSRF (lista cerrada + UrlGuard + redirecciones, tests de 10 URLs hostiles), cuota de la clave (caché + contador por hora + throttle por usuario, 429 traducido) y fugas de la clave (respuestas, props y errores de conexión sin ella) -> sin hallazgos abiertos.

## Fase 10 — Transversal y cierre

**Estado**: en-progreso · **Estimado**: 10,0h · **Real**: 4,5h (estimado) · **Coste est.**: 600 € (con margen; base 500 €) · **Tokens est.**: 405k

**Corrección revisión P3 F6-9 (intento 3)**: J: `media.md` y la guía describen los topes por usuario, la reconstrucción por id y que la ruta `.mp4` solo se exige a la URL inicial (en las redirecciones se comprueban esquema, puerto y host). Verificación: lectura -> coherente con el código.

### T-72 — Paridad MCP: Bluesky y Threads en `list_accounts` y `create_post`

- **Descripción**: `list_accounts` ofrece cuentas y tipos de Bluesky y Threads, y `create_post` los valida con el mismo `MediaValidator`. Verifica además que las vistas (T-11) y `get_stats` sin marca (T-33) tratan las dos redes. El CSV no tiene herramienta MCP (`generate_report` ya da CSV). Requisito: paridad web y MCP.
- **Changelog**: El servidor MCP describe las seis redes (Instagram, Facebook, YouTube, TikTok, Bluesky y Threads) y valida Bluesky y Threads con las mismas reglas que el compositor; sigue en 25 herramientas.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: capacidad nueva. Primer oráculo: test Pest que FALLA (RED) escrito antes del código, a partir del CA citado en la descripción.
- **Tiempo humano**: est. 2,0h · real 2,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,27h · real 0,27h (estimado)
- **Supervisión**: est. 0,07h (≈25 % IA) · real 0,07h (estimado)
- **Previsión IA**: 73k in / 8k out tok · ⚠️ verificar €
- **Dependencias**: T-27, T-61
- **Tipo**: backend
- **Archivos**: `app/Mcp/Servers/SchedulerServer.php`, `tests/Feature/Mcp/Tools/McpBlueskyThreadsTest.php`, `docs/modulos/mcp.md`
- **Cubre (tests)**: —
- **Verificación**: `php artisan test --compact tests/Feature/Mcp` → todo en verde (cuentas y tipos, mismos mensajes de error que la web, 25 herramientas)

**Criterios de aceptación**
- [x] `create_post` con 301 grafemas en Bluesky falla con el mismo mensaje que el compositor.
- [x] `tools/list` ofrece exactamente 25 herramientas.
- [x] El `#[Instructions]` del servidor menciona Bluesky y Threads.

**Subtareas**
- [x] Test RED
- [x] Ajustes de descripción y de `PostPayload`
- [x] Actualizar instrucciones

**Notas**: Patrón: `app/Mcp/Tools/{ListAccounts,CreatePost,PostPayload}.php` y `tests/Feature/Mcp/Tools/McpToolsTest.php`. El servidor queda en **25 herramientas** (22 + `add_comment_note`, `list_calendar_views`, `suggest_hashtags`), justo en el tope de `defaultPaginationLength = 25` de `SchedulerServer.php`: una 26.ª obligaría a subirlo. Actualizar el `#[Instructions]` del servidor.

**Decisiones**: sin código nuevo en `CreatePost`/`PostPayload` (declarados por el plan, sin tocar; `ListAccounts` ya lo cambiaron T-27/T-61) ni en `McpAuthTest`; el recuento y las instrucciones se prueban en el test nuevo. Alcance ampliado: `docs/modulos/mcp.md`, y la revisión P3 F8 (A3) hace que los `enum` de red solo listen las disponibles.

**Verificación ejecutada**: `php artisan test --compact tests/Feature/Mcp` -> 281/281 verdes (cuentas y tipos de las dos redes, mensaje de 250 banderas igual en MCP y web, `tools/list` = 25, instrucciones con las 6 redes). `ListAccounts`, `CreatePost` y `PostPayload` no cambian: ya trataban Bluesky y Threads por el catálogo `Network` (T-27, T-61), y el test lo demuestra.

**Corrección revisión F8-2 (A-5, commit 62fecb2)**: `Archivos` ajustado a lo que de verdad se tocó (`ListAccounts`, `GetBestTimes`, `SuggestHashtags` y `Network` no cambian en esta tarea). Evidencia del criterio de Bluesky con 301 grafemas: test «create_post con 301 grafemas en Bluesky falla con EXACTAMENTE el mismo mensaje que el compositor», que compara por igualdad el mensaje del MCP con el de `brands.posts.validate` (el MCP solo antepone «Datos no validos: targets.0: ») -> `McpBlueskyThreadsTest.php` 5 passed (36 assertions).


### T-73 — Guía para el usuario: app password de Bluesky

- **Descripción**: Guía paso a paso: crear una app password en Bluesky, email verificado para vídeo, qué se guarda y cómo revocarla. Requisito: D1, D3.
- **Changelog**: Nueva guía para crear la app password de Bluesky, conectarla, publicar vídeo (email verificado y cupo diario) y revocarla.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-20
- **Tipo**: docs
- **Archivos**: `docs/guias/bluesky-app-password.md`, `docs/README.md`, `docs/RAG-INDEX.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía cubre alta de la app password, email verificado, límite diario de vídeo y revocación → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [x] Un usuario nuevo puede conectar Bluesky solo con la guía.

**Subtareas**
- [x] Redactar la guía
- [x] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/google-cloud-picker-drive.md` y `docs/guias/tiktok-auditoria.md`. Se ejecuta **antes** del smoke T-30. Alcance ampliado en la implementación: `docs/RAG-INDEX.md` (índice de la documentación enlaza la guía).

**Verificación ejecutada**: lectura: docs/guias/bluesky-app-password.md cubre alta de la app password, conexión, errores, qué se guarda, email verificado, límite diario y revocación; enlazada desde docs/README.md, docs/RAG-INDEX.md, la guía de usuario y conexiones.md. Adelantada (Fase 10) porque el smoke M-01 la necesita.

### T-74 — Guía para el usuario: app de Meta para Threads (ID, secreto y tester)

- **Descripción**: Guía: añadir el caso de uso Threads en developers.facebook.com, anotar el ID y el secreto propios de Threads, registrar la URI de redirección e invitar a la cuenta como *tester*. Requisito: D2.
- **Changelog**: Nueva guía para crear la app de Meta de Threads: caso de uso, ID y secreto propios de Threads, URI de redirección, tester y qué hacer si Meta pidiera revisión.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-55
- **Tipo**: docs
- **Archivos**: `docs/guias/threads-app-meta.md`, `docs/README.md`, `docs/RAG-INDEX.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía lista permisos, URI de redirección, *tester* y las variables `THREADS_APP_ID`/`THREADS_APP_SECRET` → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [x] La guía indica qué hacer si Meta exigiera App Review.

**Subtareas**
- [x] Redactar la guía
- [x] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/login-social-google-meta.md`. Se ejecuta **antes** del smoke T-64. ⚠️ si la app puede combinarse con la actual: según T-54.

**Decisiones**: la guía responde a «¿puedo reutilizar la app de Meta actual?» con lo documentado: se puede añadir el caso de uso Threads a la misma app, pero Threads lleva su propio ID y secreto, su propio host (`threads.com`) y su propio canje, y mezclar permisos arriesga el «Invalid Scopes» de todo el login de Meta; si hay duda, una app aparte (el código no cambia). El `THREADS_ENABLED=false` y el ID vacío retiran la red sin tocar Facebook ni Instagram. Alcance ampliado: `docs/RAG-INDEX.md` (índice de la documentación enlaza la guía), como en T-73. Adelantada a esta fase porque el smoke M-02 (T-64) la necesita.

**Verificación ejecutada**: lectura: docs/guias/threads-app-meta.md lista los tres permisos (`threads_basic`, `threads_content_publish`, `threads_manage_insights`), la URI `<APP_URL>/connections/threads/callback`, la invitación de tester (con dónde se acepta), `THREADS_APP_ID`/`THREADS_APP_SECRET`/`THREADS_ENABLED`, los límites verificados en T-54 y la sección «¿Y si Meta exigiera App Review?»; enlazada desde docs/README.md y docs/RAG-INDEX.md; coincide con `config/social.php`, `config/services.php` y `.env.example`.

### T-75 — Guía para el usuario: clave de Giphy y atribución

- **Descripción**: Guía: crear la clave beta en developers.giphy.com, límites (100/h), cómo configurar `GIPHY_API_KEY` y la atribución «Powered by GIPHY»; revisar los ToS sobre republicar. Requisito: D4.
- **Changelog**: Nueva guía para activar el buscador de GIFs: crear la clave de Giphy, límites, atribución «Powered by GIPHY» obligatoria y advertencias sobre los términos de uso.
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,0h · real 1,0h (estimado)
- **Tiempo IA (ejec.)**: est. 0,14h · real 0,14h (estimado)
- **Supervisión**: est. 0,04h (≈25 % IA) · real 0,04h (estimado)
- **Previsión IA**: 36k in / 4k out tok · ⚠️ verificar €
- **Dependencias**: T-65
- **Tipo**: docs
- **Archivos**: `docs/guias/giphy-api-key.md`, `docs/README.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: la guía cubre clave, límites, atribución y la advertencia sobre ToS → completa; enlazada desde `docs/README.md`

**Criterios de aceptación**
- [x] Queda claro que la atribución es obligatoria.

**Subtareas**
- [x] Redactar la guía
- [x] Enlazar desde el índice

**Notas**: Patrón: `docs/guias/google-cloud-picker-drive.md`. ⚠️ los términos sobre descargar y republicar el MP4 los confirma el usuario.

**Verificación ejecutada**: lectura: docs/guias/giphy-api-key.md cubre clave (⚠️ pasos del panel sin verificar), límites (100/h de la clave y 90/h del tope), atribución obligatoria y advertencia sobre ToS (⚠️ no verificados, los confirma el usuario en M-03); enlazada desde docs/README.md. Sin red: los datos externos van marcados con ⚠️.


### T-76 — Documentación y despliegue: variables de `.env`, colas y recuento de herramientas

- **Descripción**: Documentar las variables nuevas y el despliegue: `THREADS_APP_ID`, `THREADS_APP_SECRET`, `GIPHY_API_KEY`, topes `social.bluesky.*` y tiempos de sondeo; colas (`media` para el GIF; vídeo de Bluesky en el worker de 120 s); recuento de herramientas del MCP.
- **Changelog**: Documentadas las variables nuevas de despliegue (Bluesky, Threads, Giphy, ffmpeg), las colas y las cifras reales del README (25 herramientas, tests y documentos).
- **Estado**: completado
- **Cerrada**: 2026-10-27
- **Clase**: docs/verificación (sin código nuevo). Primer oráculo: la lectura/comando de la Verificación, ejecutado al cerrar.
- **Tiempo humano**: est. 1,5h · real 1,5h (estimado)
- **Tiempo IA (ejec.)**: est. 0,20h · real 0,20h (estimado)
- **Supervisión**: est. 0,05h (≈25 % IA) · real 0,05h (estimado)
- **Previsión IA**: 55k in / 6k out tok · ⚠️ verificar €
- **Dependencias**: T-72, T-71, T-63
- **Tipo**: devops
- **Archivos**: `.env.example`, `.env.production.example`, `docs/referencia/configuracion.md`, `docs/modulos/despliegue.md`, `docs/modulos/mcp.md`, `README.md`, `docs/arquitectura.md`, `docs/README.md`, `docs/referencia/pendientes-manuales.md`
- **Cubre (tests)**: —
- **Verificación**: lectura: `grep -rn "22 herramientas" README.md docs` → sin resultados; cada variable de `.env.example` documentada en `configuracion.md`

**Criterios de aceptación**
- [x] Cada variable nueva está en `.env.example` y documentada.
- [x] Ninguna cifra de herramientas obsoleta.

**Subtareas**
- [x] Variables
- [x] Colas y despliegue
- [x] Recuento de herramientas

**Notas**: Patrón: `.env.example`, `docs/referencia/configuracion.md`, `docs/modulos/despliegue.md:113,147` (workers de 120 s) y `README.md:41` / `docs/modulos/mcp.md:116,136` (hoy dicen 22 herramientas → 25). `MEDIA_DISK=local`.

**Decisiones**: alcance ampliado con `.env.production.example` y `pendientes-manuales.md` (petición del usuario). Las colas no cambian: Bluesky y Threads usan `publish`/`poll`/`metrics`; el GIF, `media`.

**Verificación ejecutada**: `grep -rn "22 herramientas" README.md docs` (sin `roadmap/`) -> sin resultados; cada variable de Bluesky, Threads, Giphy, FFMPEG/FFPROBE de `.env.example` aparece en `configuracion.md` (comprobado con un bucle: 15/15). Cifras del README contadas: 307 ficheros PHP y ~30.600 líneas en `app/`, 101 componentes Vue, 47 migraciones, 233 ficheros de test (2.424 tests Pest en el pase completo), 495 tests Playwright en 21 specs, 75 documentos en `docs/`, 8 iniciativas. P3-M-01..M-05 añadidos a `pendientes-manuales.md`.

**Corrección revisión F8-2 (A-1, A-2, A-4, commit 354bf59)**: A-1: `mcp.md` y `arquitectura.md` dicen 25 herramientas (`grep -rnE "(14|24) herramientas|siguen siendo 24" README.md docs` fuera de roadmap/journal -> sin resultados). A-2: `configuracion.md` documenta `SOCIAL_GIPHY_PER_USER_HOURLY_LIMIT`, `SOCIAL_GIPHY_PER_USER_PER_MINUTE`, `SOCIAL_GIPHY_RATING`, `SOCIAL_GIPHY_LIMIT`, `SOCIAL_HASHTAGS_{MIN_USES,LIMIT,DAYS,CACHE_MINUTES}`, `SOCIAL_EXPORT_MAX_ROWS`, `SOCIAL_ALL_BRANDS_CACHE_MINUTES`, `SOCIAL_BACKFILL_DAYS_THREADS` y `AI_ASSISTANT_SCHEDULE_MAX_DAYS`; comprobado contra el diff de `config/` respecto a la rama base (todas las `env()` nuevas aparecen). A-4: definición de «documentos» en el README: ficheros `.md` bajo `docs/` (`git ls-files docs | grep -c '\.md$'` = 91); cifras de tests actualizadas al pase completo (2.456 Pest; 498 Playwright = 166 tests × 3 tamaños). `docs/arquitectura.md` añadido a `Archivos`.


### T-77 — Regresión final: Pest completo, Playwright en los 3 proyectos y Pint

- **Descripción**: Cierre de la iniciativa: Pest completo, Playwright en `desktop`, `mobile` y `mobile-360` con el servidor propio de Playwright (sin `E2E_NO_SERVER`) y Pint.
- **Changelog**: Regresión completa en verde antes de la PR: Pest, Playwright en escritorio y móvil, y Pint.
- **Estado**: en-progreso
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
- [x] Pest, Playwright y Pint en verde con las cifras anotadas en `Evidencia`.
- [ ] Todas las tareas anteriores `completado`.

**Subtareas**
- [x] Pest completo
- [x] Playwright en 3 proyectos
- [x] Pint
- [x] Anotar cifras

**Notas**: Patrón: T-50 de `2026-10-24-metricool-parity-2/tasks.md`. No se ejecutan otros servidores; `npm run test:e2e` levanta `php artisan serve` con `APP_ENV=e2e` (`playwright.config.ts`). Lo que falle se corrige en una tarea nueva, no se silencia.


## Revisión de dos lentes — intento 1: Fase 1-2 (T-01..T-14) — 1 Important, 9 Minor

Lentes A+B+C+D sobre `f4a5272..5407825`. Lente C sin hallazgos de IDOR: la nota, el comentario y la vista ajenos dan 404 en la web y responden «inexistente» en el MCP. Tampoco hay mass assignment, XSS ni abilities incorrectas. Lente D sin N+1. Tests: 330 de 330. MCP: 24 herramientas, con el tope de 25 respetado.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Important | La vista por defecto no se aplica nunca al entrar desde la navegación. Los enlaces del shell llevan `brand_id`/`view` y `hasAny()` los toma como filtros explícitos | T-10, T-14 | **Corregido (cccf16e)**: opción «solo cuentan los filtros elegidos»: `brand_id` y `view` ya no impiden la vista por defecto (la marca actual y la vista del enlace mandan sobre las de la vista); red, estado, búsqueda y fecha sí cuentan. Tests Pest `?brand_id=X` y `?brand_id=X&view=week` y E2E que entra por «Planificación» y «Calendario» | `CalendarController.php:47`, `navigation.js:31,34`, `CurrentBrand.php:59`. El E2E solo prueba `/calendar` |
| B2 | Minor | `get_calendar` acepta `view` = `"1.9"`, `" 1"` y `"1e0"` como id | T-11 | **Corregido (5677598)**: `ctype_digit`; `1.9`, ` 1`, `1e0`, `+1` y `0x1` dan error | `GetCalendar.php:49` |
| B3 | Minor | Con `view=<id>`, un `brand` inválido da error aunque mande la vista | T-11 | **Corregido (5677598)**: con `view=<id>` el `brand` suelto no se resuelve | `GetCalendar.php:58` |
| B4 | Minor | «Guardar vista» no muestra los errores de red, estado ni vista | T-10 | **Corregido (b8aeb4b)**: se muestran los errores de red, estado y vista (y `filters`) | `ViewsMenu.vue:128` |
| B5 | Minor | Se pueden poner notas a respuestas propias (`is_own`), que luego no se ven en ningún sitio | T-02, T-04 | **Corregido (0899367)**: `InboxNoteService::add` rechaza `is_own` (422 web, error MCP) | `InboxNoteController.php:18`, `AddCommentNote.php:44` |
| B6 | Minor | El contador (code points) no coincide con `maxlength` (UTF-16) cuando hay emojis | T-03 | **Corregido (6b4a4f4)**: sin `maxlength`; el contador cuenta code points, se pone en rojo al pasarse y deshabilita Guardar | `CommentNotes.vue:56,58` |
| C1 | Minor | Vistas sin throttle ni límite por usuario; borrar notas sin throttle (CWE-770, autoinfligido) | T-09, T-02 | **Corregido (63b5639)**: `throttle:60,1` en las 3 rutas de vistas y en borrar notas; tope de 50 vistas por usuario (error en `name`) | `routes/web.php:70-72`, `routes/parity.php:72` |
| A1 | Minor | La fila TOTAL del resumen no suma las fases cerradas | — | **No se sostiene**: la fila TOTAL ya suma las fases cerradas: 30/77 (39%), 90,5h humanas, 12,27h IA, 3,11h de supervisión y 3,66M tokens = Fase 1 + 2 + 3 + 10 (14+16+59,5+1; 1,89+2,17+8,07+0,14; 0,49+0,57+2,01+0,04; 567k+648k+2,41M+40k) | `tasks.md:58` |
| A2 | Minor | La cifra «33 passed» de T-10 no es posible | T-10 | **Anotado (T-10)**: la cifra era errónea; la suite `tests/Feature/Calendar` tiene hoy 34 tests (34 passed) y se dejó de citar la de entonces | `tasks.md:382` |

## Revisión de dos lentes — intento 1: Fase 3 (T-15..T-30, T-73) — 1 Critical, 6 Important, 6 Minor

Lentes A+B+C+D sobre `8630c77..68c8d67`. Se respeta O1: los tres conectores solo cambian de interfaz y hay catálogo y test de superficies. La app password va cifrada, se valida contra el PDS y nunca aparece en props, MCP, logs ni jobs. Los JWT van cifrados. No hay IDOR ni inyección en los facets. Los facets son correctos con emoji, ZWJ y URL. Los límites de 300 grafemas y 3000 bytes se cumplen, `views` vale null y el `rkey` es idempotente. Tests: 827/827.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| C-1 | Critical | El host del PDS no pasa por `UrlGuard`. (a) Un `did:web:` escrito como handle provoca un GET a cualquier host, puerto y ruta. (b) `assertPds` solo mira el nombre: sin DNS, con cualquier puerto y sin fijar la IP. (c) Se siguen las redirecciones, también a http. Ese PDS recibe después `uploadBlob` y `createRecord` desde el job (CWE-918) | T-19, T-29 | corregido (5553242) | `BlueskyClient.php:34-38,192-204,213`, `BlueskySession.php:37`; probes: `did:web:169.254.169.254%2F…`, `localhost%3A6379`, `127.0.0.1.nip.io`, 302 a la metadata |
| B-2 | Important | La recompresión con GD se ejecuta dentro de la petición web (validación en vivo, guardado y MCP). Una foto de 24 MP necesita 384 MB y 11 s: con `memory_limit=256M` da un error fatal que se traduce en 500 y el post no se puede guardar (CWE-400) | T-22 | corregido (9354498, 9776417) | `MediaValidator.php:188-192`, `ImageDownscaler.php:24,211-252`; medido |
| D-2 | Important | Además, esa recompresión es síncrona y sin lock: varias ejecuciones de GD en paralelo por usuario mientras no existe la copia | T-22 | corregido (9354498) | idem |
| B-3 | Important | La copia recomprimida pierde la orientación EXIF y una foto vertical sale girada 90° | T-22, T-23 | corregido (9354498) | `ImageDownscaler.php:218-261`; probe `p7.php` |
| B-4 | Important | `BLUESKY_ENABLED=false` no retira Bluesky del compositor ni del MCP: se puede programar y luego falla con `red_no_soportada` | T-26, T-27 | corregido (090e0ef) | `ContentType.php:119-122`, `PostController.php:191-210`, `ListAccounts.php:48-51` |
| A-5 | Important | Falta el oráculo RED que exige la Clase: T-19 y T-23 declaran que no lo ejecutaron, y T-20..T-27 marcan «Test RED» sin pegar la salida | T-19..T-27 | corregido (de4c1ff) | ledger |
| A-6 | Important | El criterio de T-21 (301 grafemas rechazados en `create_post`) no tiene evidencia en el MCP | T-21 | corregido (090e0ef) | `tasks.md:710,722` |
| B-7 | Minor | En los hashtags con marcas combinantes (NFD, índicos), la regex no incluye `\p{M}` y los corta | T-23 | corregido (0abe244) | `Hashtags.php:14` |
| B-8 | Minor | `applyRefresh` corre fuera del lock y genera sesiones de más | T-19 | corregido (5553242) | `BlueskySession.php:72-83,104-105` |
| B-9 | Minor | Vídeo: el timeout fijo de 80 s más 3 llamadas de 15 s puede superar los 110 s del job; no usa `StepBudget` | T-24 | corregido (3a500f1) | `BlueskyPublisher.php:129-145,231` |
| C-10 | Minor | No se comprueba que el DID de la sesión coincida con el DID resuelto: un PDS propio podría suplantar un handle en la marca del atacante (CWE-345) | T-19 | corregido (5553242) | `BlueskySession.php:41` |
| D-1 | Minor | Las métricas hacen un `getPosts` por post en lugar de lotes de 25 | T-26 | corregido (4837ac6) | `SnapshotCollector.php:205` |
| A-11 | Minor | `Archivos` declara ficheros que no se tocaron | T-17, T-21, T-22 | corregido (de4c1ff) | — |

## Revisión de dos lentes — intento 2: Fase 1-2 (T-01..T-14) — 0 Important

Lentes A+B+C+D sobre las correcciones `7a16da2..93ba4a7`. Quedan resueltos B1..B6 y C1. A1 se mantiene: el gap era real en `5407825` y lo arregló de paso `407ffb5` (T-29), así que lo exacto es «corregido en 407ffb5». Los arreglos de los E2E intermitentes esperan la condición real. **La revisión de la Fase 1-2 se cierra sin gaps Critical ni Important.**

## Revisión de dos lentes — intento 2: Fase 3 (T-15..T-30, T-73) — 1 Important, 5 Minor

Lentes A+B+C+D sobre las correcciones `8df0324..3408763`. **C-1 (SSRF) está cerrado.** El único `Http::` de Bluesky está en `BlueskyClient::send()`, que valida cada petición con `UrlGuard`: https:443, IP pública fijada, sin proxy y sin redirecciones. El `serviceEndpoint` de PLC también pasa por la guardia. `BLUESKY_ALLOWED_HOSTS` no se puede usar para saltarla. Los casos raros de `did:web` (IDN, punto final, IPs y `\n`) se rechazan. En la sonda, 25 hosts rechazados y 0 peticiones enviadas. También quedan resueltos C-10, D-2, B-3, B-4, B-7, B-9, D-1, A-5, A-6 y A-11. Tests: 679/679 y 162/162.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| N1 (B-2) | Important | Con los 256 MB de producción, la validación rechaza las fotos de más de unos 14 MP y más de 2 MB («demasiado grande para reducirla»): una foto normal de móvil no se puede guardar para Bluesky. El test lo esconde con `memory_limit=4G`, y la decisión usa la memoria del proceso web, no la del worker | T-22 | corregido (0afacd3) | `MediaValidator.php:192`, `ImageDownscaler.php:109`, `ImageDownscalerTest.php:122`; sonda `p_mem.php` |
| N2 | Minor | `reschedule` no comprueba `isAvailable()`: con Bluesky desactivado, un destino se puede reprogramar y vuelve a fallar | T-26 | corregido (e612cfe) | `PostService.php:315-339` |
| N3 | Minor | Si se agota la espera del lock de recompresión, se lanza `PermanentPublishException` cuando debería ser transitoria | T-22 | corregido (0afacd3) | `ImageDownscaler.php:86-88` |
| N4 | Minor | No se verifica el handle que devuelve el PDS: una marca propia podría mostrar el handle de otra persona (CWE-345) | T-19 | corregido (6d0a9bc) | `BlueskySession.php:46` |
| N5 | Minor | La orientación EXIF depende de `ext-exif`, que no se declara en `composer.json` ni en `deploy/hestia/README.md` | T-22 | corregido (0afacd3) | `ImageDownscaler.php:133` |
| N6 | Minor | T-19..T-26 siguen con `[x] Test RED` aunque la nota dice «RED no registrado» | T-19..T-26 | corregido (T-19, T-20, T-25 y T-26 marcadas «RED NO REGISTRADO»; ver nota) | ledger |

**Correcciones del intento 2 (Fase 3).**
- N1 (0afacd3): `ImageDownscaler` reduce con `ffmpeg` (`media.ffmpeg`, `FFMPEG_BINARY`; descodifica fuera de PHP y aplica la orientación EXIF con `autorotate`), copiando el original a un temporal por flujo. Sin ffmpeg recurre a GD con la comprobación de memoria, ahora en el worker y con el mensaje «instala ffmpeg o usa una imagen más pequeña». La validación web ya no mira `memory_get_usage()`: solo el tope de 50 MP (`exceedsPixelCap`). Cifras con `memory_limit=256M` real (proceso hijo, foto de 24 MP y 16,3 MB): copia de 1,93 MB a 4096×2730 y pico de memoria de PHP de 26 MB (GD pedía ~384 MB). El test del `memory_limit=4G` se quitó.
- N3: agotar la espera del lock lanza `TransientPublishException` (`media_ocupada`). N5: lector propio de la orientación EXIF en `ImageDownscaler::exifOrientation`, sin `ext-exif`; `deploy/hestia/README.md` y `docs/stack.md` declaran `ffmpeg`.
- N2 (e612cfe): `PostService::reschedule` rechaza los destinos de una red no disponible. N4 (6d0a9bc): `BlueskySession::open` solo muestra el handle del PDS si resuelve al mismo DID por el entryway fijo; si no, usa el DID.
- N6: T-19, T-20, T-25 y T-26 conservan el texto del criterio «Test RED» (lo exige `scope-check`: un criterio aprobado no se reescribe) y la casilla (la exige `ledger-lint`: una `[ ]` en una tarea completada es incoherencia dura), pero la línea declara «⚠️ RED NO REGISTRADO: sin salida roja». La nota de cada tarea ya lo decía; ahora también la propia línea.

## Revisión de dos lentes — intento 1: Fase 4-5 (T-31..T-42) — 0 Important, 7 Minor

Lentes A+B+C+D sobre `3408763..050d2fc`. **La revisión se cierra sin gaps Critical ni Important.**

- Lente C (seguridad): sin IDOR, sin fuga de caché entre usuarios (la clave incluye `user_id`) y sin inyección de cabeceras (`Str::slug`). Hay throttle de 30/min.
- Lente D (rendimiento): 20 marcas = 61 consultas y 399 ms en frío. El CSV de 6.000 destinos tarda 682 ms con +6,9 MB.
- Tests: 384/384.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A1 | Minor | La spec pide un aviso en la última fila del CSV cuando se supera el tope. Lo implementado avisa por cabeceras y en la Lista, pero el fichero no lo indica y no hay ninguna decisión registrada | T-39 | pendiente | `ExportController.php:208-211`, `spec.md:208` |
| A2 | Minor | T-32 no tiene línea RED | T-32 | pendiente (anotar) | ledger |
| A3 | Minor | El commit de T-36 (0e2805a) incluye `docs/knowledge/journal/**` y `.curation-lock`, que no deben commitearse nunca (norma del usuario) | T-36 | pendiente (commit correctivo que los saca del índice; recomendar *squash merge*) | `git show --stat 0e2805a` |
| B1 | Minor | La caché de 5 min no se invalida al crear o borrar una marca ni al llegar un snapshot | T-31 | pendiente | `AggregateAnalytics.php:370-372` |
| B2 | Minor | Los borradores sin fecha salen en la Lista pero no en el CSV, en contra de CA-13 | T-39 | pendiente | `CalendarService.php:571-579` |
| C1 | Minor | Los caracteres VT, FF, NEL, U+2028 y U+2029 se aplanan después de neutralizar, así que `\x0B=1+1` queda como ` =1+1` sin el apóstrofo (CWE-1236) | T-37 | pendiente | `CsvWriter.php:741,760` |
| D1 | Minor | `exportSummary()` corre en cada carga de la Lista con un join a snapshots que no necesita | T-39 | pendiente | `CalendarService.php:600` |

## Revisión de dos lentes — intento 3: Fase 3 (Bluesky) y intento 1: Fase 6-7 (T-43..T-53) — 1 Important, 10 Minor

Lentes A+B+C+D. Correcciones de la Fase 3: `0afacd3 6d0a9bc e612cfe 384dc15`. Rango de las Fases 6-7: `384dc15..7371665`. Resultados:
- **N1 (memoria):** cerrado. Una imagen de 48 MP con 256 MB se procesa con un pico de 27 MB.
- **ffmpeg:** sin inyección (`Process` con array, `tempnam`, decodificador forzado, `-protocol_whitelist file`).
- **Lector EXIF:** robusto. Fuzz de 30.000 mutaciones sin excepciones.
- **Fases 6-7:** sin IDOR. La inyección de prompt queda acotada por el esquema y por la confirmación. Solo se envía la frase redactada, con cupo y throttle.

Tests: 676/676.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Important | Con ffmpeg, la orientación EXIF solo se aplica mediante `autorotate`. Las versiones de las distros de Hestia (4.x–7.x) no la aplican a JPEG, así que la foto vertical sale girada (regresión de B-3) | T-22 | corregido (`a44b054`, T-22: fix revisión P3 #1 — orientación EXIF leída con el lector propio, `-noautorotate` y filtro `transpose`/`hflip`/`vflip` por valor 2-8; test de las 8 orientaciones con ffmpeg local, mismas esquinas y ejes que la vía GD) | `ImageDownscaler.php:306-331` |
| 2 | Minor | El presupuesto del reductor no está atado a `StepBudget` ni al job (hasta 4 imágenes × 150 s, frente a 110 s) | T-22 | corregido (`a44b054`, T-22: fix revisión P3 #2 — `fit()` recibe el `StepBudget`: `allows()` entre imágenes, error transitorio `sin_tiempo` y timeout de cada ffmpeg `min(60, remaining)`) | `ImageDownscaler.php:86,312,323,332` |
| 3 | Minor | N6 incompleto: T-21 sigue con `[x] Test RED` sin el aviso | T-21..T-24 | corregido (`cbb874a`, T-21: fix revisión P3 #3 — las 4 líneas «Test RED» declaran «⚠️ RED NO REGISTRADO») | ledger |
| 4 | Minor | Los hashtags no se normalizan a NFC: `#café` en NFC y en NFD cuentan como dos | T-43 | corregido (`af0ba6a`, T-43: fix revisión P3 #4 — `Hashtags::tags()` normaliza a NFC con `Normalizer`; JS: `hashtagKey` con `normalize('NFC')`) | `HashtagStats.php:72` |
| 5 | Minor | `redactPhrase` toma «fecha + hora» por un teléfono | T-49 | corregido (`6337d5d`, T-49: fix revisión P3 #5 — «fecha + hora» se protege antes de redactar teléfonos; tests con «el 15-11-2026 18:30» y «2026-11-15 18:00») | `AiAssistant.php:242-245` |
| 6 | Minor | `HashtagStats::latestMetrics` agrega en PHP con subconsulta por lote (10 s con 32k destinos en SQLite) | T-43 | corregido (`af0ba6a`, T-43: fix revisión P3 #6 — una sola consulta con `MAX(date)` por destino en SQL sobre la subconsulta de destinos; test: 1 consulta a `post_metric_snapshots`) | `HashtagStats.php:114-125` |
| 7 | Minor | El prompt no incluye el día de la semana de «ahora» | T-49 | corregido (`6337d5d`, T-49: fix revisión P3 #7 — el JSON del prompt lleva `dia_semana` de «ahora» y el sistema lo declara) | `AiAssistant.php:94` |
| 8 | Minor | Se acepta la hora repetida del cambio de hora (02:30 del 31-10) sin avisar, en contra de la spec | T-49 | corregido (`6337d5d`, T-49: fix revisión P3 #8 — decisión: la hora repetida del cambio de hora se RECHAZA con «Esa hora se repite en tu zona horaria… Elige otra hora»; no se adivina la vuelta; test actualizado) | `AiAssistant.php:192-198`, `spec.md:206` |
| 9 | Minor | `Archivos` desalineado en T-43, T-44, T-47 y T-50 | T-22, T-43, T-44, T-47, T-50 | corregido (este commit, T-43: fix revisión P3 #9 — `Archivos` alineado con `git show --stat`: T-43 +`textCount.js`; T-44 −`config/media_specs.php`; T-47 +`HashtagSuggestions.vue`/`EditorToolbar.vue`; T-50 −`routes/web.php` y T-22 +`BlueskyPublisher.php`) | `git show --stat` |
| 10 | Minor | El README dice 22 herramientas MCP; son 25 | T-48 | corregido (`6a43873`, T-48: fix revisión P3 #10 — 25 herramientas) | `README.md:41` |
| 11 | Minor | `config/social.php` tiene bytes Windows-1252 en los comentarios | T-49 | corregido (`6337d5d`, T-49: fix revisión P3 #11 — comentarios de `config/social.php` en UTF-8) | `config/social.php:326` |

> **Decisión del usuario (2026-10-27):** para el gap 1, corrección acotada y cierre de la Fase 3 sin otra ronda completa.

## Revisión de dos lentes — intento 1: Fase 8 (Threads, T-54..T-64, T-74) — 1 Important, 4 Minor

Lentes A+B+C+D sobre `cb869bd..a40fe3f`. Se respeta T1: `InstagramPublisher` no tiene diff y `GraphErrors` mueve las tablas tal cual. La Lente C no tiene hallazgos:
- `state`/CSRF, `redirect_uri` fija y token cifrado y oculto;
- el token que va en la query no se filtra en excepciones (sin mensaje ni `previous`);
- sin IDOR;
- `THREADS_ENABLED=false` también afecta a `reschedule`.

Contenedores, cuota de 250/24 h y métricas null/0 correctos; sin `sleep` (con `StepBudget`). Tests: 114/114 de Threads y 955/956 en total; el fallo restante es el intermitente de TikTok `MediaChunkReader`, ajeno a este cambio.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B1 | Important | El conteo de Threads solo suma los bytes de `Extended_Pictographic`. Los indicadores regionales, ZWJ, VS16 y tonos de piel cuentan 1, de modo que se aceptan textos que Threads podría rechazar: 250 banderas cuentan 500/500 pero ocupan 2.000 bytes | T-56 | corregido: el grafema que contiene un emoji (pictograma, bandera, ZWJ, VS16, tono de piel o keycap) cuenta los bytes UTF-8 del grafema entero, el resto 1; idéntico en PHP y JS y con casos compartidos (🇪🇸 8, familia 25, 👍🏽 8, ❤️ 6, 🏳️‍🌈 14, 漢字 2; 250 banderas = 2.000 > 500) | `TextLength.php:50-60`, `textLength.js` |
| D1 | Minor | Si Threads rechaza el conjunto de métricas, se piden una a una en cada lectura y no se recuerda cuál falló (7 peticiones por post en vez de 1) | T-59 | corregido: las métricas rechazadas se recuerdan en caché 6 h (por tipo de lectura y conjunto); la lectura siguiente pide solo las demás en 1 petición (test) | `ThreadsInsights.php:132-153` |
| A1 | Minor | CA-14 pide avisar del máximo de 1 etiqueta de tema en Threads; no hay `max_hashtags` ni aviso, y la spec no se actualizó | T-56 | corregido: `advise_hashtags => 1` en los 4 tipos de Threads (aviso ámbar en el compositor con más de un hashtag, sin bloquear); CA-14 de `spec.md` actualizado con la decisión | `media_specs.php:164-199` |
| A2 | Minor | No hay mensaje específico para una cuenta que no es *tester* (caso límite de la spec) | T-55 | corregido: `ThreadsClient::looksLikeNotTester` detecta el error del canje y el `error_description` del callback; mensaje con la pista de Ajustes → Permisos del sitio web (tests) | `OAuthController.php:80-84` |
| A3 | Minor | Con Threads apagada, los esquemas de `get_best_times`/`suggest_hashtags` siguen enumerando `threads` | T-60 | corregido: `Network::availableValues()` alimenta el `enum` de `get_best_times` y `suggest_hashtags`; con Threads apagada no se enumera | `GetBestTimes.php:35`, `SuggestHashtags.php:35` |

## Revisión de dos lentes — intento 2: Fase 6-7 y cierre de la Fase 3, más intento 1 de la Fase 9 (GIFs) — 2 Important, 10 Minor

Lentes A+B+C+D, todo leído en `8d106ec`.

**Fase 3, gap 1:** cerrado. Las 8 orientaciones salen bien con ffmpeg 9.0.2 y coinciden con GD. Queda cerrada la Fase 3 (decisión del usuario: corrección acotada); su resto pasa a Minor (C).

**Fases 6-7:** quedan corregidos los gaps 3, 4, 6, 7, 8, 10 y 11. El gap 5 introduce la regresión A.

**Fase 9 (GIFs), lente C:**
- La clave no sale del servidor: no aparece en respuestas, props, errores, logs ni en la URL del navegador.
- SSRF: se rechazan 23 URLs y 12 redirecciones maliciosas.
- Sin XSS ni IDOR.

Tests: 317/317 y 43/43.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| A | Important | Regresión del arreglo del gap 5: los teléfonos con guiones o puntos («600-12-34-56», «+34 600.12.34.56») ya no se redactan y se envían a la IA (CWE-359) | T-49 | `b1a6826` T-49: fix revisión P3 F6-9 — A | `AiAssistant.php:251-256`; comparación con `6337d5d^` |
| B | Important | El MP4 de Giphy no se publica en Bluesky con `presentation: gif`, aunque lo piden T-68 y el diseño, y la desviación no está declarada | T-68 | `d79b281` T-68: fix revisión P3 F6-9 — B (⚠️ `presentation` sin verificar en el lexicon, smoke) | `BlueskyPublisher.php:203-206`, `design.md:405` |
| C | Minor | Resto del gap 2 de la Fase 3: el reductor no reserva tiempo para `uploadBlob`/`createRecord` y la espera del lock (60 s) no se ata al presupuesto | T-22 | `55ad925` T-22: fix revisión P3 F6-9 — C | `ImageDownscaler.php:90,358-362` |
| D | Minor | El tope de 90 llamadas/h a Giphy es global: un usuario puede agotárselo a todos (CWE-770) | T-65 | `a1f8b36` T-65: fix revisión P3 F6-9 — D | `GiphyClient.php:130-138` |
| E | Minor | La importación se fía de la URL del cliente y no la reconstruye por id, así que el filtro `pg-13` se puede saltar (CWE-602) | T-67 | `f790dd2` T-67: fix revisión P3 F6-9 — E | `GifController.php:45-48` |
| F | Minor | Con una clave inválida (401/403) se muestra «Giphy no responde», no se registra y se gasta cupo | T-65 | `a1f8b36` T-65: fix revisión P3 F6-9 — F | `GiphyClient.php:107-109` |
| G | Minor | `allowedMediaUrl` usa `!isset(user, pass)`: solo rechaza si vienen los dos. Hoy no es explotable porque `UrlGuard` lo corta (CWE-20) | T-67 | `a1f8b36` / `f790dd2` T-65/T-67: fix revisión P3 F6-9 — G | `GiphyClient.php:43` |
| H | Minor | La detección de la hora repetida mira ±1 h y no detecta cambios de hora de 30 min (Lord_Howe) | T-49 | `b1a6826` T-49: fix revisión P3 F6-9 — H | `AiAssistant.php:270-275` |
| I | Minor | `Archivos` de T-48 no incluye `README.md` | T-48 | ledger: T-48 `Archivos` + `README.md` | `git show --stat 6a43873` |
| J | Minor | `SOCIAL_GIPHY_PER_USER_PER_MINUTE` no se usa; `media.md` dice que `.mp4` se comprueba en cada redirección y no es así | T-65, T-71 | `a1f8b36` / `7b975ee` T-65/T-71: fix revisión P3 F6-9 — J | `routes/web.php:142`, `GiphySource.php:23-28` |
| K | Minor | La caché de Giphy no normaliza mayúsculas: cada variante gasta una llamada real | T-65 | `a1f8b36` T-65: fix revisión P3 F6-9 — K | `GiphyClient.php:81` |
| L | Minor | T-66 no tiene RED registrado ni el aviso | T-66 | ledger: T-66 «⚠️ RED NO REGISTRADO» | ledger |

## Revisión de dos lentes — intento 2: Fase 8 (Threads) e intento 1: Fase 10 — 2 Important, 7 Minor

Lentes A, B, C y D sobre la corrección `c48be99` (Fase 8) y sobre `a4bc94c`, `b1a9e2d` y `285b9d5` (Fase 10). En B1, PHP y JS coinciden en los 15 casos del fixture. Quedan corregidos A1, A2 y A3. La Fase 10 no tiene defectos de código, y los `.env` de ejemplo son coherentes con la config y no contienen secretos. La Lente C no tiene hallazgos. Tests: 695/695.

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| B-1 | Important | La caché de métricas rechazadas es global (sin cuenta ni post): un post borrado deja en `null` las métricas de Threads de todos los posts y cuentas durante 6 h | T-59 | corregido: clave por cuenta + tipo de lectura + conjunto; el error de un post concreto se salta sin cachear; test del escenario `d1.php` (`50edb6d`) | `ThreadsInsights.php:140,168-170`; reproducido con `d1.php` |
| A-6 | Important | Las correcciones de la Fase 8 no tienen salida ejecutada en el ledger (T-55, T-56, T-59 y T-60) | T-55..T-60 | corregido: salida de los tests de F8 (c48be99) y de esta corrección pegada en T-55, T-56, T-59 y T-60 («Corrección revisión F8 (commit …)») | `tasks.md` |
| B-2 | Minor | Fuera de los emojis, la regla nueva cuenta 1 por grafema; con marcas combinantes (devanagari, NFD) es menos conservadora | T-56 | corregido: secuencias de emoji por bytes y el resto por code point; 22 casos, PHP = JS (`b0189b2`) | `TextLength.php:64` |
| B-3 | Minor | PHP (`\X`, Unicode 15) y JS (`Intl.Segmenter`) segmentan distinto el devanagari | T-56 | corregido: ya no se segmenta fuera de los emojis; PHP = JS con devanagari, NFD y zalgo (`b0189b2`) | `TextLength.php:58`, `textLength.js:20` |
| A-1 | Minor | Quedan recuentos de herramientas obsoletos: `mcp.md:112` dice 24 y `arquitectura.md:24` dice 14 | T-76 | corregido: 25 herramientas en `mcp.md` y `arquitectura.md` (`354bf59`) | — |
| A-2 | Minor | Variables nuevas sin documentar en `configuracion.md` (`SOCIAL_GIPHY_*`, `SOCIAL_HASHTAGS_*`, `SOCIAL_EXPORT_MAX_ROWS`, etc.) | T-76 | corregido: variables nuevas en `configuracion.md`, contrastadas con `config/*.php` (`354bf59`) | `config/social.php` |
| A-3 | Minor | El aviso de 1 etiqueta de Threads no tiene ningún test de UI | T-56 | corregido: E2E del aviso de 2 etiquetas en `threads.spec.ts` (`b0189b2`) | `NetworkCounters.vue:48-53` |
| A-4 | Minor | La cifra «75 documentos» del README no se puede reproducir con la definición anterior | T-76 | corregido: «documentos» = ficheros `.md` bajo `docs/` (91), escrito en el README (`354bf59`) | `README.md:42` |
| A-5 | Minor | El `Archivos` de T-72 declara ficheros que no tocó, y la evidencia de Bluesky 301 frente a la web no compara por igualdad | T-72 | corregido: `Archivos` de T-72 ajustados y test de igualdad del mensaje web/MCP (`62fecb2`) | `tasks.md` |

Fuera de rango: `scope-check` en HEAD falla por `PublishMedia.php` y `PublishRequestFactory.php` (`d79b281`), que hay que declarar en `Archivos` de T-68.

Fuera de rango: `PublishMedia.php` y `PublishRequestFactory.php` declarados en `Archivos` de T-68 (`scope-check` con exit 0). `SOCIAL_GIPHY_PER_USER_PER_MINUTE` se lee de verdad: `AppServiceProvider.php:134` (limitador `giphy`, usado en `routes/web.php:142` con `throttle:giphy`) y lo cubre el test J de `GiphyProxyTest.php`.

## Revisión de dos lentes — intento 3: Fase 6-9 y Fase 8 (Threads), cierre — 0 Important, 5 Minor

Lentes A+B+C+D sobre `d9d2953`. **Quedan cerrados todos los gaps corregidos** (A..L de las Fases 6-9; B-1, B-2/B-3 y A-1..A-6 de la Fase 8 y la Fase 10):
- `redactPhrase`: 49 casos.
- `presentation: gif` solo con `source=giphy`.
- Importación por id: path traversal, ids especiales y rating comprobados.
- Caché de Threads por cuenta.
- Conteo de Threads: PHP = JS en los 22 casos y en 35 de 37 casos adversos.

Tests: 917/917 + 252/252. **La revisión de la iniciativa se cierra sin gaps Critical ni Important.**

| # | Grado | Gap | Tarea | Corrección | Evidencia |
|---|---|---|---|---|---|
| 1 | Minor | La protección de fechas no comprueba que la fecha sea plausible ni mira si hay un prefijo `+NN`: «+49 30.12.3456» o «99-99-9999» llegan a la IA sin redactar (CWE-359). La hora sin fecha seguida de un teléfono pierde los minutos, igual que antes | T-49 | deuda aceptada (orquestador): menor y de baja probabilidad; queda anotado para una iteración posterior | `AiAssistant.php:253` |
| 2 | Minor | Un post concreto que devuelve `(#10)` en todas las métricas se cachea como fallo de la cuenta durante 6 h | T-59 | deuda aceptada (orquestador): se comprueba en el smoke M-02 si Threads devuelve #10 para un post retirado | `ThreadsInsights.php:167-195` |
| 3 | Minor | Diferencia previa entre PHP y JS en `trim` con NBSP/U+3000 en los extremos | T-56 | deuda aceptada (orquestador): ya existía antes de la iniciativa | `TextLength.php:33`, `textLength.js` |
| 4 | Minor | El badge del README seguía en 2424 | T-76 | corregido (commit de cierre del orquestador: badge 2456) | `README.md:7` |
| 5 | Minor | El ⚠️ de `presentation: gif` no se había llevado al smoke, y `design.md` decía «verificado» | T-68 | corregido (commit de cierre del orquestador: M-01, P3-M-01 y `design.md`) | — |

**Verificación ejecutada (2026-10-27, orquestador)**:
- `php artisan test --compact` → `{"result":"passed","tests":2456,"passed":2456}` sobre `9d0bf52`.
- `THREADS_APP_ID=… THREADS_APP_SECRET=… npx playwright test` (servidor propio de Playwright, sin `E2E_NO_SERVER`; desktop, mobile y mobile-360) → **496 passed, 0 failed, 2 skipped** (40,3 min) sobre `d9d2953`. Los cambios posteriores (`9d0bf52`) son solo documentación.
- `vendor/bin/pint --test` → sin diferencias.

**Pendiente**: el criterio «todas las tareas anteriores `completado`» depende de T-30 (smoke real de Bluesky) y T-64 (smoke real de Threads), que hace el usuario con sus cuentas. T-77 se cierra cuando estén hechos.

